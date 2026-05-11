<?php
/**
 * Flex Payment Method
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

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
	 * {@inheritdoc}
	 */
	public function initialize(): void {
		$settings       = get_option( 'woocommerce_flex_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
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
		$plugin_file = PLUGIN_FILE;
		$plugin      = get_plugin_data( $plugin_file );

		wp_register_script(
			'flex',
			plugins_url( '/build/index.js', $plugin_file ),
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
	 *
	 * @return array{ supports: string[] }
	 */
	public function get_payment_method_data() {
		return array(
			'supports' => $this->get_supported_features(),
		);
	}
}
