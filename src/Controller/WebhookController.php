<?php
/**
 * Flex Order Controller
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Controller;

use Automattic\WooCommerce\Enums\OrderStatus;
use Flex\Exception\FlexException;
use Flex\Resource\CheckoutSession\CheckoutSession;
use Flex\Resource\Refund;
use Flex\Resource\WebhookEvent;
use Flex\Resource\Webhook;
use Sentry\Breadcrumb;

use function Flex\payment_gateway;
use function Flex\sentry;

/**
 * Flex Webhook Controller
 */
class WebhookController extends Controller {

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

		sentry()->addBreadcrumb(
			new Breadcrumb(
				category: 'event',
				level: Breadcrumb::LEVEL_INFO,
				type: Breadcrumb::TYPE_DEFAULT,
				metadata: $data,
			)
		);

		if ( ! isset( $data['event_type'] ) ) {
			$error = new FlexException( 'Webhook event missing event_type' );
			$this->logger->error( $error->getMessage(), $context );
			sentry()->captureException( $error );
			return new \WP_REST_Response(
				data: array(
					'error' => 'Webhook Event missing event_type',
				),
				status: 422
			);
		}

		$context['event_type'] = $data['event_type'];

		$type = WebhookEvent::tryFrom( $data['event_type'] );

		if ( null === $type ) {
			$error = new FlexException( 'Cannot handle event type' );
			$this->logger->error( $error->getMessage(), $context );
			sentry()->captureException( $error );
			return new \WP_REST_Response(
				data: array(
					'error' => 'Cannot handle webhook of type ' . esc_html( $data['event_type'] ),
				),
				status: 422
			);
		}

		if ( WebhookEvent::CHECKOUT_SESSION_COMPLETED === $type ) {
			if ( ! isset( $data['object']['checkout_session'] ) || ! is_array( $data['object']['checkout_session'] ) ) {
				$error = new FlexException( 'Event missing checkout session' );
				$this->logger->error( $error->getMessage(), $context );
				sentry()->captureException( $error );
				return new \WP_REST_Response(
					data: array(
						'error' => 'Event missing checkout session',
					),
					status: 422
				);
			}

			$received = CheckoutSession::from_flex( $data['object']['checkout_session'] );

			$context['checkout_session_id'] = $received->id();

			$order = $received->wc();

			if ( null === $order ) {
				$error = new FlexException( 'WooCommerce order does not exist for the given checkout_session_id' );
				$this->logger->error( $error->getMessage(), $context );
				sentry()->captureException( $error );
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
		} elseif ( WebhookEvent::REFUND_UPDATED === $type ) {
			if ( ! isset( $data['object']['refund'] ) || ! is_array( $data['object']['refund'] ) ) {
				$error = new FlexException( 'Event missing refund' );
				$this->logger->error( $error->getMessage(), $context );
				sentry()->captureException( $error );
				return new \WP_REST_Response(
					data: array(
						'error' => 'Event missing refund',
					),
					status: 422
				);
			}

			$refund = Refund::from_flex( $data['object']['refund'] );

			if ( $refund->status()?->failure() ) {
				$wc_refund = $refund->wc();
				if ( null === $wc_refund ) {
					$error = new FlexException( 'WooCommerce refund does not exist for the given refund_id' );
					$this->logger->error( $error->getMessage(), $context );
					sentry()->captureException( $error );
					return new \WP_REST_Response(
						data: array(
							'error' => 'WooCommerce refund does not exist for the given refund_id',
						),
						status: 422
					);
				}

				$order = wc_get_order( $wc_refund->get_parent_id() );

				if ( false === $order ) {
					$error = new FlexException( 'WooCommerce order does not exist for the given refund_id' );
					$this->logger->error( $error->getMessage(), $context );
					sentry()->captureException( $error );
					return new \WP_REST_Response(
						data: array(
							'error' => 'WooCommerce order does not exist for the given refund_id',
						),
						status: 422
					);
				}

				$note = sprintf(
						// translators: %1$d: refund id.
						// translators: %2$s: amount of the refund.
						// translators: %3$s: status of the refund.
						// translators: %4$s: Flex Refund ID.
					__( 'Refund %1$d in the amount of %2$s resulted in a status of %3$s in Flex (%4$s) and has been deleted.', 'pay-with-flex' ),
					$wc_refund->get_id(),
					$wc_refund->get_formatted_refund_amount(),
					$refund->status()->value,
					$refund->id(),
				);

				if ( $wc_refund->delete() ) {
					$order->add_order_note( $note );
				}
			}
		}

		$this->logger->debug(
			'[Flex] Webhook Handle Complete',
			$context,
		);

		return new \WP_REST_Response();
	}
}
