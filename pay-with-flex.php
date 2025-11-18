<?php
/**
 * Plugin Name:      Flex HSA/FSA Payments
 * Description:      Accept HSA/FSA payments directly in the checkout flow.
 * Version:          3.1.16
 * Plugin URI:       https://wordpress.org/plugins/pay-with-flex/
 * Author:           Flex
 * Author URI:       https://withflex.com/
 * License:          GPL-3.0-or-later
 * Requires PHP:     8.1
 * Requires Plugins: woocommerce
 * Text Domain:      pay-with-flex
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Enums\ProductType;
use Flex\Controller\OrderController;
use Flex\Controller\WebhookController;
use Flex\Exception\FlexException;
use Flex\PaymentGateway;
use Flex\Resource\CheckoutSession\LineItem;
use Flex\Resource\Coupon;
use Flex\Resource\Price;
use Flex\Resource\Product;
use Flex\Resource\ResourceAction;
use Flex\Resource\Webhook;
use Sentry\ClientBuilder;
use Sentry\Context\OsContext;
use Sentry\Context\RuntimeContext;
use Sentry\Event;
use Sentry\Integration\RequestFetcher;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Util\PHPVersion;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

const PLUGIN_FILE = __FILE__;

/**
 * Flex Payment Gateway.
 *
 * If WooCommerce has initialized the payment gateways, return that instance, if not, return a new instance.
 */
function payment_gateway(): PaymentGateway {
	if ( did_action( 'wc_payment_gateways_initialized' ) ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways['flex'] ) && $gateways['flex'] instanceof PaymentGateway ) {
			return $gateways['flex'];
		}
	}

	return new PaymentGateway( actions: false );
}

/**
 * Returns a Sentry client for this plugin.
 *
 * Sentry's default integrations do not register because they only register on the global Sentry instance which we do
 * not want to override. Therefore, only exceptions that explicitly use `captureException` will be logged.
 */
function sentry(): HubInterface {
	static $hub = null;

	if ( null === $hub ) {
		$data = array();
		if ( function_exists( 'get_plugin_data' ) ) {
			$data = get_plugin_data(
				plugin_file: __FILE__,
				translate: false
			);
		}

		$client = ClientBuilder::create(
			array(
				'dsn'                  => 'https://7d4678d6fe3174eb2a6817500256e5d3@o4505602776694784.ingest.us.sentry.io/4509358008958976',
				'environment'          => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null,
				'release'              => $data['Version'] ?? null,
				'in_app_include'       => array( __DIR__ ),
				'default_integrations' => false,
				// Exclude any events that are not "in app" as defined above.
				'before_send'          => function ( Event $event ): ?Event {
					// Allow users to opt-out of telemetry.
					if ( defined( 'FLEX_TELEMETRY' ) && false === \FLEX_TELEMETRY ) {
						return null;
					}

					$trace = $event->getStacktrace();
					$exceptions = $event->getExceptions();

					// There is no stack trace so record the event.
					// We may need to extend the `Event` class and use `captureEvent` in place of `captureMessage`.
					if ( null === $trace && empty( $exceptions ) ) {
						return $event;
					}

					$trace = $event->getStacktrace();
					if ( null !== $trace ) {
						foreach ( $trace->getFrames() as $frame ) {
							if ( $frame->isInApp() ) {
								return $event;
							}
						}
					}

					foreach ( $exceptions as $exception ) {
						$trace = $exception->getStacktrace();
						if ( null === $trace ) {
							continue;
						}

						foreach ( $trace->getFrames() as $frame ) {
							if ( $frame->isInApp() ) {
								return $event;
							}
						}
					}

					return null;
				},
			)
		)->getClient();

		$hub = new Hub( $client );

		$hub->configureScope(
			function ( Scope $scope ) {

				if ( function_exists( 'get_bloginfo' ) ) {
					$scope->setTag( 'site', get_bloginfo( 'name' ) );
				}

				if ( function_exists( 'home_url' ) ) {
					$scope->setTag( 'site.url', home_url() );
				}

				$scope->addEventProcessor(
					function ( Event $event ) {
						/**
						 * Add the request information.
						 *
						 * @see https://github.com/getsentry/sentry-php/blob/4.11.1/src/Integration/RequestIntegration.php#L120-L166
						 */
						if ( empty( $event->getRequest() ) ) {
							$request = ( new RequestFetcher() )->fetchRequest();
							if ( null !== $request ) {
								$headers = array();

								$header_names = array( 'host', 'user-agent', 'referer', 'origin' );
								foreach ( $header_names as $name ) {
									$values = $request->getHeader( $name );
									if ( empty( $values ) ) {
										continue;
									}

									$headers[ $name ] = $values;
								}

								$request_data = array(
									'url'     => (string) $request->getUri(),
									'method'  => $request->getMethod(),
									'headers' => $headers,
								);

								if ( $request->getUri()->getQuery() ) {
									$request_data['query_string'] = $request->getUri()->getQuery();
								}

								$event->setRequest( $request_data );
							}
						}

						/**
						 * Add the module information.
						 *
						 * @see https://github.com/getsentry/sentry-php/blob/4.11.1/src/Integration/ModulesIntegration.php#L36
						 */
						if ( empty( $event->getModules() ) ) {

							$modules = array();

							if ( function_exists( 'wp_get_wp_version' ) ) {
								$modules['wordpress'] = wp_get_wp_version();
							}

							if ( function_exists( 'get_plugins' ) && function_exists( 'is_plugin_active' ) ) {
								foreach ( get_plugins() as $plugin => $info ) {
									if ( ! is_plugin_active( $plugin ) ) {
										continue;
									}

									$modules[ $plugin ] = $info['Version'];
								}
							}

							if ( function_exists( 'wp_get_theme' ) ) {
								$theme                               = wp_get_theme();
								$modules[ $theme->get_stylesheet() ] = $theme->version;
							}

							$event->setModules( $modules );
						}

						/**
						 * Add the runtime information.
						 *
						 * @see https://github.com/getsentry/sentry-php/blob/4.11.1/src/Integration/EnvironmentIntegration.php#L38-L53
						 */
						if ( null === $event->getRuntimeContext() ) {
							$event->setRuntimeContext(
								new RuntimeContext(
									name: 'php',
									version: PHPVersion::parseVersion(),
									sapi: \PHP_SAPI
								),
							);
						}

						/**
						 * Add the OS information
						 *
						 * @see https://github.com/getsentry/sentry-php/blob/4.11.1/src/Integration/EnvironmentIntegration.php#L55-L82
						 */
						if ( null === $event->getOsContext() && \function_exists( 'php_uname' ) ) {
							$event->setOsContext(
								new OsContext(
									name: php_uname( 's' ),
									version: php_uname( 'r' ),
									build: php_uname( 'v' ),
									kernelVersion: php_uname( 'a' ),
									machineType: php_uname( 'm' ),
								),
							);
						}

						return $event;
					}
				);
			}
		);
	}

	return $hub;
}

/**
 * Add payment gateway tags.
 *
 * @param \WC_Payment_Gateways $gateways The payment gateways that were initialzed by WooCommerce.
 */
function payment_gateways_initialized( \WC_Payment_Gateways $gateways ) {
	$flex = $gateways->payment_gateways()['flex'] ?? null;

	if ( ! $flex ) {
		return;
	}

	sentry()->configureScope(
		fn ( $scope ) => $scope->setTag( 'flex.test_mode', wc_bool_to_string( $flex->is_in_test_mode() ) )
	);
}
add_action(
	hook_name: 'wc_payment_gateways_initialized',
	callback: __NAMESPACE__ . '\payment_gateways_initialized'
);

/**
 * Activate the plugin.
 */
function activate() {
	sentry()->captureMessage(
		message: 'Plugin activated',
		level: Severity::info(),
	);

	// Update the webhooks and sync the products if an API key is available.
	payment_method_enabled();
}
register_activation_hook(
	file: __FILE__,
	callback: __NAMESPACE__ . '\activate'
);

/**
 * Deactivate the plugin.
 */
function deactivate() {
	sentry()->captureMessage(
		message: 'Plugin deactivated',
		level: Severity::warning(),
	);

	$gateway = payment_gateway();

	// Refresh the settings from the database so we are working with the latest version.
	$gateway->init_settings();

	// If there is no API Key available, there is nothing more that can be done.
	if ( empty( $gateway->api_key() ) ) {
		return;
	}

	// Delete the webhook immediately.
	Webhook::from_wc( $gateway )->exec( ResourceAction::DELETE );
}
register_deactivation_hook(
	file: __FILE__,
	callback: __NAMESPACE__ . '\deactivate'
);

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
 * Update Coupon in Flex.
 *
 * @param int $product_id The id of the product.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws \Exception If the product fails to be updated.
 */
function flex_update_coupon_async( int $product_id, int $retries = 0 ): void {
	flex_enqueue_async_action(
		hook: 'flex_update_coupon',
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
		$flex_product->exec( $flex_product->needs() );
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
		$price->exec( $price->needs() );
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
 * Update Coupon in Flex.
 *
 * @param int $product_id The id of the coupon.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws FlexException If the API key is not set.
 * @throws \Throwable Any caught exceptions.
 */
function flex_update_coupon( int $product_id, int $retries = 0 ): void {
	try {
		$gateway = payment_gateway();
		if ( empty( $gateway->api_key() ) ) {
			throw new FlexException( 'API Key is not set' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$coupon = Coupon::from_wc( $product );
		$coupon->exec( $coupon->needs() );
	} catch ( \Throwable $previous ) {
		flex_update_coupon_async( $product_id, $retries + 1 );
		throw $previous;
	}
}
add_action(
	hook_name: 'flex_update_coupon',
	callback: __NAMESPACE__ . '\flex_update_coupon',
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

	$coupon = Coupon::from_wc( $product );
	if ( $coupon->can( $coupon->needs() ) ) {
		flex_update_coupon_async( $product_id );
		return;
	}

	$variation_ids = $product->get_children();
	foreach ( $variation_ids as $variation_id ) {
		$variation = wc_get_product( $variation_id );

		$price = Price::from_wc( $variation );
		if ( $price->can( $price->needs() ) ) {
			flex_update_price_async( $variation_id );
			continue;
		}

		$coupon = Coupon::from_wc( $variation );
		if ( $coupon->can( $coupon->needs() ) ) {
			flex_update_coupon_async( $variation_id );
			continue;
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
		flex_update_price_async( $product_id );
		return;
	}

	$coupon = Coupon::from_wc( $product );
	if ( $coupon->can( $coupon->needs() ) ) {
		flex_update_coupon_async( $product_id );
		return;
	}
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
 * Enqueues a page of products to be synced.
 *
 * @param int $page The page number to enqueue.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws \Exception If the product fails to be updated.
 */
function flex_product_sync_spawn( int $page, int $retries = 0 ): void {
	flex_enqueue_async_action(
		hook: 'flex_product_sync',
		args: array( $page, $retries ),
		group: 'page-' . $page,
		retries: $retries,
	);
}

/**
 * Syncs a page of products with Flex.
 *
 * @param int $page The page number to sync.
 * @param int $retries The number of retries that have been attempted.
 *
 * @throws FlexException If the API key is not set.
 * @throws \Throwable Any caught exceptions.
 */
function flex_product_sync( int $page, int $retries = 0 ): void {
	try {
		$gateway = payment_gateway();
		if ( empty( $gateway->api_key() ) ) {
			throw new FlexException( 'API Key is not set' );
		}

		/**
		 * Fetch all of the products we support.
		 *
		 * @var \WC_Product[]
		 */
		$products = wc_get_products(
			array(
				'page' => $page,
				'type' => array_merge( Product::WC_TYPES, Price::WC_TYPES ),
			)
		);

		// Enqueue all of them to be updated.
		foreach ( $products as $product ) {
			wc_update_product( $product->get_id(), $product );
		}
	} catch ( \Throwable $previous ) {
		flex_product_sync_spawn( $page, $retries + 1 );
		throw $previous;
	}
}
add_action(
	hook_name: 'flex_product_sync',
	callback: __NAMESPACE__ . '\flex_product_sync',
);

/**
 * React to the payment method being enabled.
 */
function payment_method_enabled(): void {
	$gateway = payment_gateway();

	// Refresh the settings from the database so we are working with the latest version.
	$gateway->init_settings();

		// If no API key is present, then there is nothing to do.
	if ( empty( $gateway->api_key() ) ) {
		return;
	}

	// Check to see if the webhook needs to be updated.
	$webhook = Webhook::from_wc( $gateway );
	if ( $webhook->can( $webhook->needs() ) ) {
		flex_update_webhook_async();
	}

	$result = wc_get_products(
		array(
			'type'     => array_merge( Product::WC_TYPES, Price::WC_TYPES ),
			'paginate' => true,
		)
	);

	for ( $i = 1; $i <= $result->max_num_pages; $i++ ) {
		flex_product_sync_spawn( $i );
	}
}

/**
 * React to the payment method being disabled.
 */
function payment_method_disabled(): void {
	sentry()->captureMessage(
		message: 'Payment method disabled',
		level: Severity::warning(),
	);
	$gateway = payment_gateway();

	// Refresh the settings from the database so we are working with the latest version.
	$gateway->init_settings();

	// Check to see if the webhook needs to be updated.
	$webhook = Webhook::from_wc( $gateway );
	if ( $webhook->can( $webhook->needs() ) ) {
		flex_update_webhook_async();
	}
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

	if ( 'yes' === $value['enabled'] && ( null === $old_value || ! isset( $old_value['enabled'] ) || 'no' === $old_value['enabled'] ) ) {
		sentry()->captureMessage(
			message: 'Payment method enabled',
			level: Severity::info(),
		);
		payment_method_enabled();
	} elseif ( 'no' === $value['enabled'] && 'yes' === $old_value['enabled'] ) {
		payment_method_disabled();
	}
}
add_action(
	hook_name: 'update_option_woocommerce_flex_settings',
	callback: __NAMESPACE__ . '\update_option_wc_flex_settings',
	accepted_args: 2,
);

/**
 * Adding the WooCommerce option for the first time.
 *
 * @param string $option The name of the option.
 * @param mixed  $value The value of the option.
 */
function add_option_wc_flex_settings( string $option, mixed $value ): void {
	if ( ! is_array( $value ) ) {
		return;
	}

	if ( ! isset( $value['enabled'] ) ) {
		return;
	}

	if ( 'yes' === $value['enabled'] ) {
		sentry()->captureMessage(
			message: 'Payment method enabled',
			level: Severity::info(),
		);
		payment_method_enabled();
	}
}
add_action(
	hook_name: 'add_option_woocommerce_flex_settings',
	callback: __NAMESPACE__ . '\add_option_wc_flex_settings',
	accepted_args: 2,
);

/**
 * Register the Payment Gateway.
 *
 * @param string[] $methods An array of Payment method classes.
 */
function wc_payment_gateways( array $methods = array() ): array {
	return array( ...$methods, PaymentGateway::class );
}
add_filter(
	hook_name: 'woocommerce_payment_gateways',
	callback: __NAMESPACE__ . '\wc_payment_gateways',
);

/**
 * Register the payment method.
 *
 * @param PaymentMethodRegistry $payment_method_registry The WooCommerce payment method registry.
 */
function wc_blocks_payment_method_type_registration( PaymentMethodRegistry $payment_method_registry ) {
	$payment_method_registry->register( new PaymentMethod() );
}
add_filter(
	hook_name: 'woocommerce_blocks_payment_method_type_registration',
	callback: __NAMESPACE__ . '\wc_blocks_payment_method_type_registration',
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
