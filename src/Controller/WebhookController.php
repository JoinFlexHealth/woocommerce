<?php
/**
 * Flex Order Controller
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Controller;

use Automattic\WooCommerce\Enums\OrderStatus;
use Flex\Resource\CheckoutSession;
use Flex\Resource\Webhook;

use function Flex\payment_gateway;

/**
 * Flex Webhook Controller
 */
class WebhookController extends Controller {

	protected const CHECKOUT_SESSION_COMPLETED = 'checkout.session.completed';

	/**
	 * Constructs a new Webhook Controller
	 *
	 * @param \WC_Logger_Interface $logger Logger.
	 */
	public function __construct( protected \WC_Logger_Interface $logger ) {}

	/**
	 * Rest API Init.
	 *
	 * @todo Add a different route for test mode.... or maybe don't add them for test mode?
	 */
	public static function rest_api_init() {
		$controller = new self(
			logger: wc_get_logger(),
		);

		register_rest_route(
			route_namespace: self::NAMESPACE,
			route: '/webhooks',
			args: array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $controller, 'handle' ),
				'permission_callback' => array( $controller, 'permission_callback' ),
			),
		);

		register_rest_route(
			route_namespace: self::NAMESPACE,
			route: '/test/webhooks',
			args: array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $controller, 'handle' ),
				'permission_callback' => array( $controller, 'permission_callback_test' ),
			),
		);
	}

	/**
	 * Check the {@link https://docs.withflex.com/webhooks/verifying-webhooks webhook signature} to ensure that the
	 * request originated from Flex.
	 *
	 * @param \WP_REST_Request $request The Request.
	 * @param bool             $test_mode Whether the request is a test mode request or not.
	 */
	public function permission_callback( \WP_REST_Request $request, bool $test_mode = false ): bool {
		$webhook = Webhook::from_wc( payment_gateway(), $test_mode );
		$content = $request->get_header( 'flex-event-id' ) . '.' . $request->get_header( 'flex-timestamp' ) . '.' . $request->get_body();

		$result = hash_equals(
			hash_hmac( 'sha256', $content, $webhook->secret(), true ),
			base64_decode( $request->get_header( 'flex-signature' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		);

		if ( false === $result ) {
			$this->logger->notice(
				'[Flex] Webhook Permission Check Failure',
				array(
					'event_id'   => $request->get_header( 'flex-event-id' ),
					'timestamp'  => $request->get_header( 'flex-timestamp' ),
					'signature'  => $request->get_header( 'flex-signature' ),
					'test_mode'  => $test_mode,
					'webhook_id' => $webhook->id(),
				),
			);
		}

		return $result;
	}

	/**
	 * Checks the permissions of the test webhooks.
	 *
	 * @param \WP_REST_Request $request The Request.
	 */
	public function permission_callback_test( \WP_REST_Request $request ): bool {
		return $this->permission_callback( $request, true );
	}

	/**
	 * Handle the incoming Flex webhook.
	 *
	 * @param \WP_REST_Request $request The Request.
	 */
	public function handle( \WP_REST_Request $request ) {
		$context = array(
			'event_id'  => $request->get_header( 'flex-event-id' ),
			'timestamp' => $request->get_header( 'flex-timestamp' ),
		);

		$this->logger->debug(
			'[Flex] Webhook Handle Start',
			$context,
		);

		$data = $request->get_json_params();

		if ( ! isset( $data['event_type'] ) ) {
			$this->logger->error(
				'[Flex] Webhook Event missing event_type',
				$context,
			);
			return new \WP_REST_Response(
				data: array(
					'error' => 'Webhook Event missing event_type',
				),
				status: 422
			);
		}

		if ( self::CHECKOUT_SESSION_COMPLETED !== $data['event_type'] ) {
			$context['event_type'] = $data['event_type'];

			$this->logger->error(
				'[Flex] Cannot handle event type',
				$context,
			);
			return new \WP_REST_Response(
				data: array(
					'error' => 'Cannot handle webhook of type ' . esc_html( $data['event_type'] ),
				),
				status: 422
			);
		}

		if ( ! isset( $data['object']['checkout_session'] ) ) {
			$this->logger->error(
				'[Flex] Webhook Event missing checkout session',
				$context,
			);
			return new \WP_REST_Response(
				data: array(
					'error' => 'Cannot handle webhook of type ' . esc_html( $data['event_type'] ),
				),
				status: 422
			);
		}

		$received = CheckoutSession::from_flex( $data['object']['checkout_session'] );

		$context['checkout_session_id'] = $received->id();

		$order = $received->wc();

		if ( null === $order ) {
			$this->logger->error(
				'[Flex] WooCommerce order does not exist for the given checkout_session_id',
				$context,
			);
			return new \WP_REST_Response(
				data: array(
					'error' => 'WooCommerce order does not exist for the given checkout_session_id',
				),
				status: 422
			);
		}

		$context['order_id'] = $order->get_id();

		// If the order has not yet been marked as complete, do so.
		if ( OrderStatus::PENDING === $order->get_status() ) {
			$order->payment_complete( $received->id() );
		}

		$received->apply_to( $order );
		$order->save();

		$this->logger->debug(
			'[Flex] Webhook Handle Complete',
			$context,
		);

		return new \WP_REST_Response();
	}
}
