<?php
/**
 * Plugin Name:      Pay with Flex
 * Description:      Accept HSA/FSA payments directly in the checkout flow.
 * Version:          2.0.0
 * Plugin URI:       https://wordpress.org/plugins/pay-with-flex/
 * Author:           Flex
 * Author URI:       https://withflex.com/
 * License:          GPL-3.0-or-later
 * Requires PHP:     8.1
 * Requires Plugins: woocommerce
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex;

use Automattic\WooCommerce\Enums\ProductType;
use Flex\Controller\OrderController;
use Flex\Controller\WebhookController;
use Flex\Exception\FlexException;
use Flex\PaymentGateway;
use Flex\Resource\LineItem;
use Flex\Resource\Price;
use Flex\Resource\Product;
use Flex\Resource\Webhook;

/**
 * Add the autoloader and action schedular.
 */
require_once plugin_dir_path( __FILE__ ) . '/vendor/autoload_packages.php';

/**
 * Flex Payment Gateway
 */
function payment_gateway(): PaymentGateway {
	return WC()->payment_gateways()->payment_gateways()['flex'] ?? new PaymentGateway( actions: false );
}

/**
 * Enqueue an async action with an exponential back-off.
 *
 * @param string $hook The hook for {@link as_enqueue_async_action}.
 * @param array  $args The args for {@link as_enqueue_async_action}.
 * @param string $group The group for {@link as_enqueue_async_action}.
 * @param int    $retries The number of retries that have been attempted.
 */
function flex_enqueue_async_action( string $hook, array $args = array(), string $group = '', int $retries = 0 ): void {
	// After 10 retries, something is seriously wrong.
	if ( $retries >= 10 ) {
		return;
	}

	if ( 0 === $retries ) {
		as_enqueue_async_action(
			hook: $hook,
			args: $args,
			group: $group,
			unique: true
		);
	} else {
		as_schedule_single_action(
			timestamp: time() + ( 2 ** $retries ),
			hook: $hook,
			args: $args,
			group: $group,
			// We do not call unique here because then it cannot be called within the context of a handler for a retry,
			// which defeats the purpose.
			unique: false,
		);
	}
}

/**
 * Update Product in Flex.
 *
 * @param int $product_id The id of the product.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws \Exception If the product fails to be updated.
 */
function flex_update_product_async( int $product_id, int $retries = 0 ): void {
	flex_enqueue_async_action(
		hook: 'flex_update_product',
		args: array( $product_id, $retries ),
		group: "product-$product_id",
		retries: $retries,
	);
}

/**
 * Update Price in Flex.
 *
 * @param int $product_id The id of the product.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws \Exception If the product fails to be updated.
 */
function flex_update_price_async( int $product_id, int $retries = 0 ): void {
	flex_enqueue_async_action(
		hook: 'flex_update_price',
		args: array( $product_id, $retries ),
		group: "product-$product_id",
		retries: $retries,
	);
}

/**
 * Update Product in Flex.
 *
 * @param int $product_id The id of the product.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws FlexException If the API key is not set.
 * @throws \Throwable Any caught exceptions.
 */
function flex_update_product( int $product_id, int $retries = 0 ): void {
	try {
		$gateway = payment_gateway();
		if ( empty( $gateway->api_key() ) ) {
			throw new FlexException( 'API Key is not set' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$flex_product = Product::from_wc( $product );

		$action = $flex_product->needs();

		// It's possible that the product no longer needs updating (i.e. if the changes have been reverted).
		if ( ! $flex_product->can( $action ) ) {
			return;
		}

		$flex_product->exec( $action );
	} catch ( \Throwable $previous ) {
		flex_update_product_async( $product_id, $retries + 1 );
		throw $previous;
	}
}
add_action(
	hook_name: 'flex_update_product',
	callback: __NAMESPACE__ . '\flex_update_product',
	accepted_args: 2,
);

/**
 * Update Price in Flex.
 *
 * @param int $product_id The id of the product.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws FlexException If the API key is not set.
 * @throws \Throwable Any caught exceptions.
 */
function flex_update_price( int $product_id, int $retries = 0 ): void {
	try {
		$gateway = payment_gateway();
		if ( empty( $gateway->api_key() ) ) {
			throw new FlexException( 'API Key is not set' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$price = Price::from_wc( $product );

		$action = $price->needs();

		// It's possible that the price no longer needs updating (i.e. if the changes have been reverted).
		if ( ! $price->can( $action ) ) {
			return;
		}

		$price->exec( $action );
	} catch ( \Throwable $previous ) {
		flex_update_price_async( $product_id, $retries + 1 );
		throw $previous;
	}
}
add_action(
	hook_name: 'flex_update_price',
	callback: __NAMESPACE__ . '\flex_update_price',
	accepted_args: 2,
);

/**
 * Listens to product update and enqueues an update in Flex if needed.
 *
 * @param int         $product_id The id of the product.
 * @param \WC_Product $product The product object.
 *
 * @throws \Exception If the product update was not enqueued.
 */
function wc_update_product( int $product_id, \WC_Product $product ): void {
	$gateway = payment_gateway();
	if ( empty( $gateway->api_key() ) ) {
		return;
	}

	$flex_product = Product::from_wc( $product );
	if ( $flex_product->can( $flex_product->needs() ) ) {
		flex_update_product_async( $product_id );
		return;
	}

	$price = Price::from_wc( $product );
	if ( $price->can( $price->needs() ) ) {
		flex_update_price_async( $product_id );
		return;
	}

	$variation_ids = $product->get_children();
	foreach ( $variation_ids as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		$price     = Price::from_wc( $variation );

		if ( $price->can( $price->needs() ) ) {
			flex_update_price_async( $variation_id );
		}
	}
}
add_action(
	hook_name: 'woocommerce_update_product',
	callback: __NAMESPACE__ . '\wc_update_product',
	accepted_args: 2,
);
add_action(
	hook_name: 'woocommerce_new_product',
	callback: __NAMESPACE__ . '\wc_update_product',
	accepted_args: 2,
);

/**
 * Listens to product update and enqueues an update in Flex if needed.
 *
 * @param int         $product_id The id of the product.
 * @param \WC_Product $product The product object.
 *
 * @throws \Exception If the product update was not enqueued.
 */
function wc_update_product_variation( int $product_id, \WC_Product $product ): void {
	$gateway = payment_gateway();
	if ( empty( $gateway->api_key() ) ) {
		return;
	}

	$price = Price::from_wc( $product );
	if ( $price->can( $price->needs() ) ) {
		return;
	}

	flex_update_price_async( $product_id );
}
add_action(
	hook_name: 'woocommerce_update_product_variation',
	callback: __NAMESPACE__ . '\wc_update_product_variation',
	accepted_args: 2,
);
add_action(
	hook_name: 'woocommerce_new_product_variation',
	callback: __NAMESPACE__ . '\wc_update_product_variation',
	accepted_args: 2,
);

/**
 * Listens to post updates and enqueues an update in Flex if needed.
 *
 * @param int $post_id The id of the post.
 *
 * @throws \Exception If the product update was not enqueued.
 */
function post_update( int $post_id ): void {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return;
	}

	if ( 'product' === $post->post_type || 'product_variation' === $post->post_type ) {
		$product = wc_get_product( $post );
		if ( ! $product ) {
			return;
		}

		if ( ProductType::VARIATION === $product->get_type() ) {
			wc_update_product_variation( $product->get_id(), $product );
		} else {
			wc_update_product( $product->get_id(), $product );
		}
	}
}
add_action(
	hook_name: 'wp_trash_post',
	callback: __NAMESPACE__ . '\post_update',
);
add_action(
	hook_name: 'untrash_post',
	callback: __NAMESPACE__ . '\post_update',
);

/**
 * Updates the Flex webhook
 *
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws FlexException If the API key is not set.
 * @throws \Throwable Any caught exceptions.
 */
function flex_update_webhook( int $retries = 0 ): void {
	try {
		$gateway = payment_gateway();
		if ( empty( $gateway->api_key() ) ) {
			throw new FlexException( 'API Key is not set' );
		}

		$webhook = Webhook::from_wc( $gateway );

		$action = $webhook->needs();

		// It's possible that the webhook no longer needs updating (i.e. if the changes have been reverted).
		if ( ! $webhook->can( $action ) ) {
			return;
		}

		$webhook->exec( $action );

	} catch ( \Throwable $previous ) {
		flex_update_webhook_async( $retries + 1 );
		throw $previous;
	}
}
add_action(
	hook_name: 'flex_update_webhook',
	callback: __NAMESPACE__ . '\flex_update_webhook',
);

/**
 * Updates the Flex webhook
 *
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws \Exception If the product fails to be updated.
 */
function flex_update_webhook_async( int $retries = 0 ): void {
	// If this is the first attempt, remove the existing ones.
	if ( 0 === $retries ) {
		as_unschedule_all_actions(
			hook: 'flex_update_webhook',
		);
	}

	flex_enqueue_async_action(
		hook: 'flex_update_webhook',
		args: array( $retries ),
		retries: $retries,
	);
}

/**
 * Listen to settings changes for Flex gateway.
 *
 * @param mixed $old_value The previous value.
 * @param mixed $value The new value.
 */
function update_option_wc_flex_settings( mixed $old_value, mixed $value ): void {
	if ( ! is_array( $value ) ) {
		return;
	}

	if ( ! isset( $value['enabled'] ) ) {
		return;
	}

	$gateway = payment_gateway();

	// Refresh the settings from the database so we are working with the latest version.
	$gateway->init_settings();

	// Payment method activation.
	if ( 'yes' === $value['enabled'] && ( null === $old_value || ! isset( $old_value['enabled'] ) || 'no' === $old_value['enabled'] ) ) {
		// If no API key is present, then there is nothing to do.
		if ( empty( $gateway->api_key() ) ) {
			return;
		}

		// Check to see if the webhook needs to be updated.
		$webhook = Webhook::from_wc( $gateway );
		if ( $webhook->can( $webhook->needs() ) ) {
			flex_update_webhook_async();
		}

		for ( $i = 1; ; $i++ ) {
			/**
			 * Fetch all of the products we support.
			 *
			 * @var \WC_Product[]
			 */
			$products = wc_get_products(
				array(
					'paged' => $i,
					'type'  => array_merge( Product::WC_TYPES, Price::WC_TYPES ),
				)
			);

			if ( empty( $products ) ) {
				break;
			}

			// Enqueue all of them to be updated.
			foreach ( $products as $product ) {
				wc_update_product( $product->get_id(), $product );
			}
		}
	} elseif ( 'no' === $value['enabled'] && 'yes' === $old_value['enabled'] ) { // Payment method deactivation.
		// Check to see if the webhook needs to be updated.
		$webhook = Webhook::from_wc( $gateway );
		if ( $webhook->can( $webhook->needs() ) ) {
			flex_update_webhook_async();
		}
	}
}
add_action(
	hook_name: 'update_option_woocommerce_flex_settings',
	callback: __NAMESPACE__ . '\update_option_wc_flex_settings',
	accepted_args: 2,
);

/**
 * Add the Payment Gateway.
 *
 * @param string[] $methods An array of Payment method classes.
 */
add_filter(
	hook_name: 'woocommerce_payment_gateways',
	callback: array( PaymentGateway::class, 'wc_payment_gateways' ),
);

/**
 * Register Routes.
 */
add_action(
	hook_name: 'rest_api_init',
	callback: array( OrderController::class, 'rest_api_init' ),
);
add_action(
	hook_name: 'rest_api_init',
	callback: array( WebhookController::class, 'rest_api_init' ),
);


/**
 * Format the Line Item meta.
 */
add_filter(
	hook_name: 'woocommerce_attribute_label',
	callback: array( LineItem::class, 'wc_attribute_label' ),
	accepted_args: 2,
);
