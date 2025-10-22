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
use Flex\Resource\CheckoutSession\CheckoutSession;
use Flex\Resource\CheckoutSession\Refund\Refund;
use Flex\Resource\ResourceAction;
use Flex\Resource\Webhook;
use Sentry\Breadcrumb;
use Sentry\State\Scope;

/**
 * Flex Payment Gateway.
 */
class PaymentGateway extends \WC_Payment_Gateway {

	protected const ENABLED = 'enabled';
	protected const API_KEY = 'api_key';

	/**
	 * {@inheritdoc}
	 *
	 * @var string
	 */
	public $id = 'flex';

	/**
	 * {@inheritdoc}
	 *
	 * @var bool
	 */
	public $has_fields = false;

	/**
	 * {@inheritdoc}
	 *
	 * @var array
	 */
	public $supports = array( 'products', 'refunds' );

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
		$this->logger = wc_get_logger();

		$this->init_settings();

		if ( did_action( 'init' ) ) {
			$this->init();
		}

		if ( $actions ) {
			// Translation cannot be used until after `init`.
			if ( ! did_action( 'init' ) ) {
				add_action( 'init', array( $this, 'init' ) );
			}
			// @phpstan-ignore return.void
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			add_action( 'admin_notices', array( $this, 'display_errors' ), 9999 );
		}
	}

	/**
	 * Initialize the Payment Gateway.
	 */
	public function init() {
		$this->title              = __( 'Flex | Pay with HSA/FSA', 'pay-with-flex' );
		$this->method_title       = __( 'Flex', 'pay-with-flex' );
		$this->method_description = __( 'Accept HSA/FSA payments directly in the checkout flow.', 'pay-with-flex' );
		$this->init_form_fields();
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

		sentry()->configureScope(
			function ( Scope $scope ) use ( $order_id ): void {
				$scope->addBreadcrumb(
					new Breadcrumb(
						level: Breadcrumb::LEVEL_INFO,
						type: Breadcrumb::TYPE_DEFAULT,
						category: 'payment',
						message: 'Payment',
						metadata: array(
							'order_id' => $order_id,
						),
					)
				);
			},
		);

		$order = wc_get_order( $order_id );

		sentry()->configureScope(
			function ( Scope $scope ) use ( $order ): void {
				$scope->setContext(
					'Order',
					array_merge(
						$order->get_base_data(),
						array(
							'line_items' => array_map( fn( $item ) => $item->get_data(), $order->get_items() ),
							'total'      => $order->get_total(),
						)
					)
				);
			},
		);

		try {
			// Ensure the Webhooks are up to date.
			$webhook = Webhook::from_wc( $this );
			$webhook->exec( $webhook->needs() );

			$checkout_session = CheckoutSession::from_wc( $order );

			// Forcibly apply any required updates.
			$checkout_session->exec( $checkout_session->needs() );

			sentry()->configureScope(
				function ( Scope $scope ) use ( $checkout_session ): void {
					$scope->setTags(
						array(
							'checkout_session'           => $checkout_session->id(),
							'checkout_session.test_mode' => wc_bool_to_string( $checkout_session->test_mode() ),
						)
					);

					$scope->setContext( 'Checkout Session', $checkout_session->jsonSerialize() );
				},
			);

			if ( $checkout_session->amount_total() !== CheckoutSession::currency_to_unit_amount( $order->get_total() ) ) {
				throw new FlexException(
					message: 'Flex Checkout Session amount_total does not equal WooCommerce Order total.',
					context: array(
						'checkout_session_id' => $checkout_session->id(),
						'order_id'            => $order->get_id(),
						'order_total'         => $order->get_total(),
					),
				);
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
				array_merge(
					$previous->getContext(),
					array(
						'order_id' => $order_id,
					),
				),
			);

			sentry()->captureException(
				new \Exception(
					message: 'Payment processing failure',
					previous: $previous,
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
	 * @param  int        $order_id Order ID.
	 * @param  float|null $amount Refund amount.
	 * @param  string     $reason Refund reason.
	 * @return bool|\WP_Error True or false based on success, or a WP_Error object.
	 * @throws FlexException If we can intuit the refund from the amount and reason.
	 * @throws \Exception When a FlexException is caught.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		sentry()->configureScope(
			function ( Scope $scope ) use ( $order_id, $amount, $reason ): void {
				$scope->addBreadcrumb(
					new Breadcrumb(
						level: Breadcrumb::LEVEL_INFO,
						type: Breadcrumb::TYPE_DEFAULT,
						category: 'refund',
						message: 'Refund',
						metadata: array(
							'order_id' => $order_id,
							'amount'   => $amount,
							'reason'   => $reason,
						),
					)
				);
			},
		);

		try {
			$order = wc_get_order( $order_id );

			sentry()->configureScope(
				function ( Scope $scope ) use ( $order ): void {
					$scope->setContext(
						'Order',
						$order->get_base_data(),
					);
				},
			);

			$checkout_session = CheckoutSession::from_wc( $order );

			sentry()->configureScope(
				function ( Scope $scope ) use ( $checkout_session ): void {
					$scope->setTags(
						array(
							'checkout_session'           => $checkout_session->id(),
							'checkout_session.test_mode' => wc_bool_to_string( $checkout_session->test_mode() ),
						)
					);

					$scope->setContext( 'Checkout Session', $checkout_session->jsonSerialize() );
				},
			);

			$refunds = $order->get_refunds();

			/**
			 * Retrieve the first refund from the list.
			 *
			 * @var \WC_Order_Refund|false
			 */
			$refund = reset( $refunds );

			if ( false === $refund ) {
				throw new FlexException( 'Unable to retrieve refund' );
			}

			sentry()->configureScope(
				function ( Scope $scope ) use ( $refund ): void {
					$scope->setContext(
						'Refund',
						$refund->get_base_data()
					);
				},
			);

			if ( $refund->get_amount() !== $amount || $refund->get_reason() !== $reason ) {
				throw new FlexException( 'Refund amount or reason does not match retrieved refund.' );
			}

			// Ensure the Webhooks are up to date.
			$webhook = Webhook::from_wc( $this );
			$webhook->exec( $webhook->needs() );

			Refund::from_wc( $refund )->exec( ResourceAction::CREATE );

		} catch ( FlexException $previous ) {
			$this->logger->error(
				$previous->getMessage(),
				array_merge(
					$previous->getContext(),
					array(
						'refund_id' => $order_id,
					),
				),
			);

			sentry()->captureException(
				new \Exception(
					message: 'Refund processing failure',
					previous: $previous,
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
				message: "We're sorry, there was a problem while attempting to processes the refund with Flex. Please try again later.",
				previous: $previous, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			);
		}

		return true;
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
	 * {@inheritdoc}
	 *
	 * @param string $key Field key.
	 * @param array  $field Field array.
	 * @param array  $post_data Posted data.
	 * @return string
	 * @throws \Exception If the payment method is being enabled, but the API key is not present or is invalid.
	 */
	public function get_field_value( $key, $field, $post_data = array() ) {
		$value = parent::get_field_value( $key, $field, $post_data );
		if ( self::ENABLED !== $key ) {
			return $value;
		}

		// If the payment method is not being enabled, then nothing needs to be done.
		if ( 'no' === $value ) {
			return $value;
		}

		$api_key = '';
		if ( defined( 'FLEX_API_KEY' ) && is_string( \FLEX_API_KEY ) ) {
			$api_key = \FLEX_API_KEY;
		} elseif ( defined( 'WC_FLEX_API_KEY' ) && is_string( \WC_FLEX_API_KEY ) ) {
			$api_key = \WC_FLEX_API_KEY;
		} else {
			// Retrieve the api key from the form.
			try {
				$api_key = $this->get_field_value( self::API_KEY, $this->get_form_fields()[ self::API_KEY ], $post_data );
			} catch ( \Throwable $previous ) {
				throw new \Exception(
					message: 'Payment method cannot be enabled without an API Key',
					previous: $previous, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				);
			}
		}

		if ( empty( $api_key ) ) {
			throw new \Exception( 'Payment method cannot be enabled without an API Key' );
		}

		return $value;
	}

	/**
	 * Initializes settings form fields.
	 */
	public function init_form_fields() {
		$has_defined_key   = ( defined( 'FLEX_API_KEY' ) && is_string( \FLEX_API_KEY ) ) || ( defined( 'WC_FLEX_API_KEY' ) && is_string( \WC_FLEX_API_KEY ) );
		$this->form_fields = array(
			self::ENABLED => array(
				'title'   => __( 'Enable/Disable', 'pay-with-flex' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Flex', 'pay-with-flex' ),
				'default' => 'no',
			),
			self::API_KEY => array(
				'title'             => __( 'API Key', 'pay-with-flex' ),
				'type'              => 'text',
				'placeholder'       => $has_defined_key ? '(hidden)' : '',
				'disabled'          => $has_defined_key,
				'description'       => __( 'An API Key may be obtained from the', 'pay-with-flex' ) . ' <a href="https://dashboard.withflex.com/apikeys" target="_blank">' . __( 'Flex Dashboard', 'pay-with-flex' ) . '</a>.',
				'desc_tip'          => __( 'Alternatively, set the FLEX_API_KEY constant in wp-config.php which is more secure.', 'pay-with-flex' ),
				'sanitize_callback' => function ( $value ) {
					// If the value is already defined in a constant, clear out what is present to prevent it from being leaked.
					if ( defined( 'FLEX_API_KEY' ) || defined( 'WC_FLEX_API_KEY' ) ) {
						return '';
					}

					$clean = $this->validate_text_field( self::API_KEY, $value );

					// If the API key field is empty, let the user continue.
					if ( '' === $clean ) {
						return $clean;
					}

					if ( ! str_starts_with( $clean, 'fsk_' ) ) {
						throw new \Exception( 'API Key must start with fsk_' );
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
		if ( defined( 'FLEX_API_KEY' ) && is_string( \FLEX_API_KEY ) ) {
			return \FLEX_API_KEY;
		}

		if ( defined( 'WC_FLEX_API_KEY' ) && is_string( \WC_FLEX_API_KEY ) ) {
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

	/**
	 * {@inheritdoc}
	 *
	 * Remove Flex from checkout if the currency is not USD.
	 */
	public function is_available(): bool {
		$order_id = absint( get_query_var( 'order-pay' ) );
		$currency = null;

		// Gets currency from "pay for order" page.
		if ( 0 < $order_id ) {
			$order    = wc_get_order( $order_id );
			$currency = $order->get_currency();
		} else {
			// Get currency from the cart/session.
			$currency = get_woocommerce_currency();
		}

		if ( 'USD' !== $currency ) {
			return false;
		}

		return parent::is_available();
	}
}
