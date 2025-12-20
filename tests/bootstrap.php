<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Flex
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Set the WordPress test library path to wp-phpunit.
$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

// Get configuration from environment variables.
// Default paths are relative to woocommerce project structure for local development.
$_plugin_dir  = dirname( __DIR__ );
$_woo_root    = dirname( $_plugin_dir, 2 );
$_wp_core_dir = getenv( 'WP_CORE_DIR' ) ? getenv( 'WP_CORE_DIR' ) : $_woo_root . '/web/wp';
$_db_name     = getenv( 'DB_NAME' ) ? getenv( 'DB_NAME' ) : 'wordpress_test';
$_db_user     = getenv( 'DB_USER' ) ? getenv( 'DB_USER' ) : 'root';
$_db_pass     = getenv( 'DB_PASS' ) ? getenv( 'DB_PASS' ) : '';
$_db_host     = getenv( 'DB_HOST' ) ? getenv( 'DB_HOST' ) : 'localhost';

// Create tmp directory in plugin root (git-ignored).
// This is required because wp-phpunit runs install.php as a separate process
// that loads ONLY the config file, not this bootstrap.
$_tmp_dir = dirname( __DIR__ ) . '/tmp';
if ( ! is_dir( $_tmp_dir ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Bootstrap runs before WP loads.
	mkdir( $_tmp_dir, 0755, true );
}
$_config_path    = $_tmp_dir . '/wp-tests-config.php';
$_config_content = <<<PHP
<?php
define( 'ABSPATH', '{$_wp_core_dir}/' );
define( 'DB_NAME', '{$_db_name}' );
define( 'DB_USER', '{$_db_user}' );
define( 'DB_PASSWORD', '{$_db_pass}' );
define( 'DB_HOST', '{$_db_host}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
\$table_prefix = 'wptests_';
PHP;

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Bootstrap runs before WP loads.
if ( false === file_put_contents( $_config_path, $_config_content ) ) {
	echo "Error: Could not write wp-tests-config.php to {$_config_path}" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Point wp-phpunit to our config file in temp directory.
define( 'WP_TESTS_CONFIG_FILE_PATH', $_config_path );

// Configure PHPUnit Polyfills.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Run composer install." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	// Load WooCommerce first (required for wc_* functions).
	$wc_dir = getenv( 'WC_DIR' );
	if ( ! $wc_dir ) {
		// Default path relative to woocommerce project structure.
		$wc_dir = dirname( __DIR__, 3 ) . '/web/wp-content/plugins/woocommerce';
	}

	if ( file_exists( $wc_dir . '/woocommerce.php' ) ) {
		require_once $wc_dir . '/woocommerce.php';
	} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "WooCommerce not found at {$wc_dir}. Set WC_DIR environment variable to WooCommerce plugin path." . PHP_EOL;
		exit( 1 );
	}

	WC_Install::create_tables();
	// Loading the plugin creates a circular dependency with the composer autoloader.
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
