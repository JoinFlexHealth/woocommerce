<?php
/**
 * Flex Payment Gateway
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex;

use Automattic\WooCommerce\Enums\OrderStatus;
use Flex\Exception\FlexException;
use Flex\Resource\CheckoutSession;
use Flex\Resource\Webhook;

/**
 * Flex Payment Gateway.
 */
class PaymentGateway extends \WC_Payment_Gateway {

	protected const API_KEY = 'api_key';

	/**
	 * Logger.
	 *
	 * @var \WC_Logger_Interface
	 */
	protected \WC_Logger_Interface $logger;

	/**
	 * Constructs a new Flex payment gateway.
	 *
	 * @param bool $actions Register the action handlers.
	 */
	public function __construct( bool $actions = true ) {
		$this->id                 = 'flex';
		$this->title              = __( 'Flex | Pay with HSA/FSA', 'pay-with-flex' );
		$this->method_title       = __( 'Flex', 'pay-with-flex' );
		$this->method_description = __( 'Accept HSA/FSA payments directly in the checkout flow.', 'pay-with-flex' );
		$this->has_fields         = false;
		$this->logger             = wc_get_logger();

		$this->init_form_fields();
		$this->init_settings();

		if ( $actions ) {
			// @phpstan-ignore return.void
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}
	}

	/**
	 * Register this class as a Payment Gateway.
	 *
	 * @param string[] $methods An array of Payment method classes.
	 */
	public static function wc_payment_gateways( array $methods = array() ): array {
		return array( ...$methods, self::class );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $order_id The id of the order.
	 * @throws FlexException When WP_DEBUG & WP_DEBUG_DISPLAY are enabled.
	 * @throws \Exception When something goes wrong.
	 */
	public function process_payment( $order_id ) {
		$this->logger->debug(
			'[Flex] Process Payment Start',
			array(
				'order_id' => $order_id,
			)
		);

		try {
			// Ensure the Webhooks are up to date.
			$webhook = Webhook::from_wc( $this );
			$webhook->exec( $webhook->needs() );

			$order            = wc_get_order( $order_id );
			$checkout_session = CheckoutSession::from_wc( $order );

			// Forcibly apply any required updates.
			$checkout_session->exec( $checkout_session->needs() );

			if ( $checkout_session->amount_total() !== CheckoutSession::currency_to_unit_amount( $order->get_total() ) ) {
				throw new FlexException( 'Flex Checkout Session amount_total does not equal WooCommerce Order total.' );
			}

			$order->set_status( OrderStatus::PENDING, __( 'Awaiting Flex Payment', 'pay-with-flex' ) );
			$order->save();

			$this->logger->debug(
				'[Flex] Process Payment Complete',
				array(
					'order_id'            => $order_id,
					'checkout_session_id' => $checkout_session->id(),
				)
			);

			return array(
				'result'   => 'success',
				'redirect' => $checkout_session->redirect_url(),
				'order_id' => $order_id,
			);
		} catch ( FlexException $previous ) {
			$this->logger->error(
				$previous->getMessage(),
				array(
					'order_id' => $order_id,
				)
			);

			if ( true === \WP_DEBUG ) {

				// Throw the underlying error message which will be displayed the user.
				if ( true === \WP_DEBUG_DISPLAY ) {
					throw $previous;
				}

				// Log the underlying error message.
				error_log( $previous->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			throw new \Exception(
				message: "We're sorry, there was a problem while attempting to processes your payment with Flex. Please try again later.",
				previous: $previous, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			);
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param mixed $order the order object.
	 */
	public function get_transaction_url( $order ) {
		if ( ! ( $order instanceof \WC_Order ) ) {
			return parent::get_transaction_url( $order );
		}

		$checkout_session = CheckoutSession::from_wc( $order );

		if ( null === $checkout_session->id() ) {
			return parent::get_transaction_url( $order );
		}

		$dashboard = defined( 'FLEX_DASHBOARD_URL' ) ? \FLEX_DASHBOARD_URL : 'https://dashboard.withflex.com';

		return $dashboard . ( $checkout_session->test_mode() ? '/test' : '' ) . '/orders/' . $checkout_session->id();
	}

	/**
	 * Initializes settings form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'pay-with-flex' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Flex', 'pay-with-flex' ),
				'default' => 'no',
			),
			self::API_KEY => array(
				'title'             => __( 'API Key', 'pay-with-flex' ),
				'type'              => 'text',
				'placeholder'       => defined( 'FLEX_API_KEY' ) || defined( 'WC_FLEX_API_KEY' ) ? '(hidden)' : '',
				'disabled'          => defined( 'FLEX_API_KEY' ) || defined( 'WC_FLEX_API_KEY' ),
				'description'       => __( 'An API Key may be obtained from the', 'pay-with-flex' ) . ' <a href="https://dashboard.withflex.com/apikeys" target="_blank">' . __( 'Flex Dashboard', 'pay-with-flex' ) . '</a>.',
				'desc_tip'          => __( 'Alternatively, set the FLEX_API_KEY constant in wp-config.php which is more secure.', 'pay-with-flex' ),
				'sanitize_callback' => function ( $value ) {
					// If the value is already defined in a constant, clear out what is present to prevent it from being leaked.
					if ( defined( 'FLEX_API_KEY' ) || defined( 'WC_FLEX_API_KEY' ) ) {
						return '';
					}

					$clean = $this->validate_text_field( self::API_KEY, $value );

					if ( ! str_starts_with( $clean, 'fsk_' ) ) {
						throw new FlexException( 'API Key must start with fsk_' );
					}

					return $clean;
				},
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function needs_setup() {
		if ( empty( $this->api_key() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Update multiple options.
	 *
	 * @param array<string, mixed> $options The options to update.
	 */
	public function update_options( array $options ): bool {
		if ( empty( $this->settings ) ) {
			$this->init_settings();
		}

		$this->settings = array_merge( $this->settings, $options );

		return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
	}

	/**
	 * Remove options from the array
	 *
	 * @param string[] $keys The keys to remove.
	 */
	public function remove_options( array $keys ): bool {
		$settings = $this->settings;

		foreach ( $keys as $key ) {
			unset( $settings[ $key ] );
		}

		$this->settings = $settings;

		return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
	}

	/**
	 * Returns the Flex API key.
	 */
	public function api_key(): ?string {
		if ( defined( 'FLEX_API_KEY' ) ) {
			return \FLEX_API_KEY;
		}

		if ( defined( 'WC_FLEX_API_KEY' ) ) {
			return \WC_FLEX_API_KEY;
		}

		return $this->get_option( self::API_KEY );
	}

	/**
	 * Determines if we are currently in test mode or not.
	 */
	public function is_in_test_mode(): bool {
		$api_key = $this->api_key();
		if ( null === $api_key ) {
			return false;
		}

		return str_starts_with( $api_key, 'fsk_test_' );
	}
}
