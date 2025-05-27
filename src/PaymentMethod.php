<?php
/**
 * Flex Payment Method
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

/**
 * Flex Payment Method.
 */
class PaymentMethod extends AbstractPaymentMethodType {

	/**
	 * {@inheritdoc}
	 *
	 * @var string
	 */
	protected $name = 'flex';

	/**
	 * Register the payment method.
	 *
	 * @param PaymentMethodRegistry $payment_method_registry The WooCommerce payment method registry.
	 */
	public static function wc_blocks_payment_method_type_registration( PaymentMethodRegistry $payment_method_registry ) {
		$payment_method_registry->register( new self() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_flex_settings', array() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_active() {
		return $this->get_setting( 'enabled' ) === 'yes' ? true : false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_payment_method_script_handles() {
		$plugin = get_plugin_data( PLUGIN_FILE );

		wp_register_script(
			'flex',
			plugins_url( '/build/index.js', PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wp-i18n' ),
			$plugin['Version'],
			true,
		);

		wp_set_script_translations( 'flex', 'pay-with-flex' );

		return array( 'flex' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_payment_method_data() {
		return array(
			'supports' => $this->get_supported_features(),
		);
	}
}
