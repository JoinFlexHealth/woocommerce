<?php
/**
 * Tests for the plugin's declared WooCommerce compatibility.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

/**
 * Guards the compatibility WooCommerce cannot infer on its own.
 *
 * Two separate mechanisms, both opt-in and both silent when absent:
 *
 * 1. High-Performance Order Storage. A plugin that does not call
 *    `FeaturesUtil::declare_compatibility()` is listed as *incompatible* on the
 *    Order Data Storage screen, and WooCommerce warns the merchant away from
 *    HPOS -- which is the default for new stores. The plugin reaches orders only
 *    through CRUD (`wc_get_order()`, `$order->get_meta()`,
 *    `$order->update_meta_data()`), never `get_post_meta()` or `$wpdb`, so the
 *    declaration reflects reality rather than papering over raw post access.
 *
 * 2. The supported WooCommerce range. The plugin imports
 *    `Automattic\WooCommerce\Enums\ProductType`, which does not exist before
 *    WooCommerce 9.7, so on anything older it fatals the moment it loads. The
 *    `WC requires at least` header is what stops that install from happening.
 */
class WooCommerceCompatibilityTest extends \WP_UnitTestCase {

	/**
	 * The compatibility declaration must be hooked on `before_woocommerce_init`.
	 *
	 * WooCommerce reads the feature declarations during its own init, so a
	 * declaration made later is ignored without any error.
	 */
	public function test_compatibility_is_declared_before_woocommerce_init(): void {
		self::assertNotFalse(
			has_action( 'before_woocommerce_init', 'Flex\declare_wc_compatibility' ),
			'The WooCommerce feature compatibility declaration is not hooked on before_woocommerce_init, so WooCommerce will never see it.',
		);
	}

	/**
	 * Both WooCommerce version headers must be present.
	 *
	 * Without them WooCommerce cannot tell whether the plugin supports the running
	 * version, and the admin shows an "untested with your version" notice.
	 *
	 * @param string $header The plugin header to assert on.
	 *
	 * @dataProvider wc_version_header_provider
	 */
	public function test_wc_version_headers_are_declared( string $header ): void {
		self::assertMatchesRegularExpression(
			'/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*\S+/m',
			self::plugin_file_header(),
			"The plugin header does not declare \"{$header}\".",
		);
	}

	/**
	 * The WooCommerce version headers the plugin must declare.
	 *
	 * @return array<string, array{string}>
	 */
	public static function wc_version_header_provider(): array {
		return array(
			'WC requires at least' => array( 'WC requires at least' ),
			'WC tested up to'      => array( 'WC tested up to' ),
		);
	}

	/**
	 * The declared minimum must not drop below the version that introduced the
	 * WooCommerce enums the plugin imports.
	 *
	 * `Automattic\WooCommerce\Enums\ProductType` first shipped in WooCommerce
	 * 9.7.0 (`OrderStatus` landed earlier, in 9.5.0, so `ProductType` is the
	 * binding constraint). Both are imported unconditionally at the top of
	 * `pay-with-flex.php`, so an older WooCommerce is a fatal on load, not a
	 * degraded experience.
	 */
	public function test_declared_minimum_covers_the_imported_enums(): void {
		$matched = preg_match(
			'/^\s*\*\s*WC requires at least:\s*(\d+\.\d+)/m',
			self::plugin_file_header(),
			$matches
		);

		self::assertSame( 1, $matched, 'Could not read the declared WooCommerce minimum from the plugin header.' );

		self::assertTrue(
			version_compare( $matches[1], '9.7', '>=' ),
			"The plugin declares WooCommerce {$matches[1]} as its minimum, but Automattic\\WooCommerce\\Enums\\ProductType first shipped in 9.7.",
		);
	}

	/**
	 * Returns the plugin entry point's docblock header.
	 */
	private static function plugin_file_header(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own header off disk; WP_Filesystem is not bootstrapped in tests.
		$contents = file_get_contents( dirname( __DIR__ ) . '/pay-with-flex.php' );

		self::assertIsString( $contents, 'Could not read pay-with-flex.php.' );

		return substr( $contents, 0, 2048 );
	}
}
