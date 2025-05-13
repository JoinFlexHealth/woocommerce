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
use Flex\Resource\CheckoutSessionStatus;
use Flex\Resource\ResourceAction;

/**
 * Flex Order Controller
 */
class OrderController extends Controller {

	/**
	 * Rest API Init.
	 */
	public static function rest_api_init() {
		$controller = new self();

		register_rest_route(
			route_namespace: self::NAMESPACE,
			route: '/orders/(?P<id>[\d]+)/complete',
			args: array(
				'callback'            => array( $controller, 'complete' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required' => true,
					),
				),
			),
		);
	}

	/**
	 * Optimistically update the order status when the customer is redirected back to
	 * WooCommerce.
	 *
	 * @param \WP_REST_Request $request The Request.
	 */
	public function complete( \WP_REST_Request $request ) {
		$id    = $request->get_param( 'id' );
		$order = wc_get_order( $id );

		// If an order isn't returned, then redirect to the homepage since there is nothing we can really do.
		if ( false === $order ) {
			return new \WP_REST_Response(
				status: 307,
				headers: array(
					'Location' => get_home_url(),
				),
			);
		}

		// If the order is in a 'pending' state and the nonce is valid, then attempt to update the order.
		if ( OrderStatus::PENDING === $order->get_status() && false !== wp_verify_nonce( $request->get_param( '_wpnonce' ), 'wp_rest' ) ) {
			$checkout_session = CheckoutSession::from_wc( $order );
			$checkout_session->exec( ResourceAction::REFRESH );

			if ( CheckoutSessionStatus::COMPLETE === $checkout_session->status() ) {
				$order->payment_complete();
			}
		}

		return new \WP_REST_Response(
			status: 307,
			headers: array(
				'Location' => $order->get_checkout_order_received_url(),
			),
		);
	}
}
