<?php
/**
 * Tests for plugin lifecycle telemetry.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Severity;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

use function Flex\activate;
use function Flex\deactivate;
use function Flex\flex_enqueue_async_action;
use function Flex\sentry;

/**
 * Activation, deactivation, enablement and disablement are reported to Sentry because the
 * "WooCommerce Enabled" / "WooCommerce Disabled" alert rules match their exact messages and
 * post to the onboarding/offboarding Slack channels. They carry `flex.event_kind:lifecycle`
 * so bug triage can exclude them without a blanket info-level filter.
 */
class LifecycleTelemetryTest extends \WP_UnitTestCase {

	/**
	 * Events the stub transport received.
	 *
	 * @var Event[]
	 */
	private static array $events = array();

	/**
	 * Woo log entries as [level, message].
	 *
	 * @var array<array{string, string}>
	 */
	private static array $logs = array();

	/**
	 * Returns the recording logger. A mock rather than a WC_Logger subclass, so wc_get_logger()'s
	 * static cache drops it once the filter is removed.
	 *
	 * @var ?\Closure(): \WC_Logger_Interface
	 */
	private ?\Closure $logger_filter = null;

	/**
	 * The plugin's real Sentry client, restored after each test.
	 *
	 * @var ?ClientInterface
	 */
	private ?ClientInterface $original_client = null;

	/**
	 * Stub the Sentry transport and the Woo logger.
	 */
	public function set_up(): void {
		parent::set_up();

		// Start disabled with no API key so the enable/disable handlers stop before any Flex API call.
		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'no',
				'api_key' => '',
			)
		);

		$this->original_client = sentry()->getClient();
		sentry()->bindClient(
			ClientBuilder::create( array() )->setTransport(
				new class() implements TransportInterface {
					// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
					public function send( Event $event ): Result {
						LifecycleTelemetryTest::record( $event );
						return new Result( ResultStatus::success(), $event );
					}

					// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
					public function close( ?int $timeout = null ): Result {
						return new Result( ResultStatus::success() );
					}
				}
			)->getClient()
		);

		$logger = $this->createMock( \WC_Logger_Interface::class );
		$logger->method( 'log' )->willReturnCallback(
			function ( string $level, string $message ): void {
				self::$logs[] = array( $level, $message );
			}
		);
		$this->logger_filter = fn (): \WC_Logger_Interface => $logger;
		add_filter( 'woocommerce_logging_class', $this->logger_filter );

		self::$events = array();
		self::$logs   = array();
	}

	/**
	 * Restore the real Sentry client and logger.
	 */
	public function tear_down(): void {
		if ( null !== $this->logger_filter ) {
			remove_filter( 'woocommerce_logging_class', $this->logger_filter );
		}
		if ( null !== $this->original_client ) {
			sentry()->bindClient( $this->original_client );
		}
		parent::tear_down();
	}

	/**
	 * Record an event sent through the stub transport.
	 *
	 * @param Event $event The event.
	 */
	public static function record( Event $event ): void {
		self::$events[] = $event;
	}

	/**
	 * Asserts exactly one event was sent and returns it.
	 */
	private static function sole_event(): Event {
		self::assertCount( 1, self::$events );
		return self::$events[0];
	}

	/**
	 * Asserts exactly one event was sent and it is a tagged lifecycle event.
	 *
	 * @param string   $message The expected message.
	 * @param Severity $level   The expected level.
	 */
	private static function assert_single_lifecycle_event( string $message, Severity $level ): void {
		$event = self::sole_event();
		self::assertSame( $message, $event->getMessage(), 'Sentry alert rules match this message verbatim.' );
		self::assertTrue( $level->isEqualTo( $event->getLevel() ?? Severity::fatal() ) );
		self::assertSame( 'lifecycle', $event->getTags()['flex.event_kind'] ?? null );
		self::assertContains( array( (string) $level, "[Flex] {$message}." ), self::$logs );
	}

	/**
	 * Activation reports an info lifecycle event and a Woo log line.
	 */
	public function test_activate_reports_tagged_lifecycle_event(): void {
		activate();

		self::assert_single_lifecycle_event( 'Plugin activated', Severity::info() );
	}

	/**
	 * Repeated activation reports once per call and leaves gateway settings untouched.
	 */
	public function test_repeated_activation_does_not_alter_gateway_state(): void {
		$before = get_option( 'woocommerce_flex_settings' );

		activate();
		activate();

		self::assertCount( 2, self::$events );
		self::assertSame( $before, get_option( 'woocommerce_flex_settings' ) );
	}

	/**
	 * Turning the gateway on reports an info lifecycle event.
	 */
	public function test_enabling_reports_tagged_lifecycle_event(): void {
		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'yes',
				'api_key' => '',
			)
		);

		self::assert_single_lifecycle_event( 'Payment method enabled', Severity::info() );
	}

	/**
	 * First-time settings save with the gateway on reports an info lifecycle event.
	 */
	public function test_first_enable_reports_tagged_lifecycle_event(): void {
		delete_option( 'woocommerce_flex_settings' );
		self::$events = array();
		self::$logs   = array();

		add_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'yes',
				'api_key' => '',
			)
		);

		self::assert_single_lifecycle_event( 'Payment method enabled', Severity::info() );
	}

	/**
	 * A settings save that does not change `enabled` is not a lifecycle transition.
	 */
	public function test_settings_update_while_enabled_reports_nothing(): void {
		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'yes',
				'api_key' => '',
			)
		);
		self::$events = array();

		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'yes',
				'api_key' => '',
				'title'   => 'HSA/FSA',
			)
		);

		self::assertSame( array(), self::$events );
	}

	/**
	 * Turning the gateway off keeps its warning-level availability signal.
	 */
	public function test_disabling_reports_tagged_warning(): void {
		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'yes',
				'api_key' => '',
			)
		);
		self::$events = array();
		self::$logs   = array();

		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'no',
				'api_key' => '',
			)
		);

		self::assert_single_lifecycle_event( 'Payment method disabled', Severity::warning() );
	}

	/**
	 * Deactivation keeps its warning-level availability signal.
	 */
	public function test_deactivate_reports_tagged_warning(): void {
		deactivate();

		self::assert_single_lifecycle_event( 'Plugin deactivated', Severity::warning() );
	}

	/**
	 * The lifecycle tag is scoped to the lifecycle event; a genuine error reported afterwards
	 * stays untagged so the triage rule still routes it.
	 */
	public function test_genuine_error_after_lifecycle_event_is_untagged(): void {
		activate();
		self::$events = array();

		flex_enqueue_async_action( 'flex_update_product', array( 1 ), 'product-1', 10 );

		$event = self::sole_event();
		self::assertTrue( Severity::error()->isEqualTo( $event->getLevel() ?? Severity::fatal() ) );
		self::assertArrayNotHasKey( 'flex.event_kind', $event->getTags() );
	}
}
