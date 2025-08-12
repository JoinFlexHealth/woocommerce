=== Flex HSA/FSA Payments ===
Contributors: withflex, davidbarratt
Tags: hsa, fsa, payments, woocommerce
Requires at least: 6.8
Tested up to: 6.8
Stable tag: 3.1.5
Requires PHP: 8.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

This is the official plugin for accepting payments via the Flex payment gateway on a WooCommerce store.

== Description ==

Flex integrates with WooCommerce to enable merchants to accept Health Savings Account (HSA) and Flexible Spending Account (FSA) payments. Our plugin helps you tap into the growing $150 billion HSA/FSA market and makes it easy to offer compliant, seamless payment options to your customers.

## Features:
- Accept HSA/FSA payments directly in your WooCommerce checkout flow
- Product sync and eligibility assistance
- IIAS support for products deemed "always eligible"
- Letter of Medical Necessity (LOMN) directly in checkout for products deemed "dual use"

## Flex partners have seen:
- 20% increase in average order volume
- 17% increase in revenue
- Access to a $150 billion and growing HSA/FSA market

## How to Get Started:
Visit [withflex.com](https://www.withflex.com) to learn more or contact us at <hello@withflex.com> to get started.

== Frequently Asked Questions ==

= What are HSAs and FSAs? =

Health savings accounts (HSAs) and flexible spending accounts (FSAs) are tax advantaged accounts that can be used for health related purchases.

= What can HSA or FSA dollars be spent on? =

Traditionally, health expenses are limited to spending at healthcare facilities (think doctor’s office, dentist office etc) or on a specific list of items, usually available at a pharmacy like CVS or Walgreens.

Flex works with wellness partners to enable you to spend your HSA/FSA money with them as well. Our partners include sleep, fitness, and meditation apps, nutrition programs, in person gyms, wearables and even medical tourism! Reach out to support@withflex.com if you’re interested in learning more!

= What is a Letter of Medical Necessity? =

A Letter of Medical Necessity is a note from a licensed healthcare provider stating that a product is needed to treat or manage a medical condition. Some products require this documentation to qualify for HSA or FSA payment, as required by the IRS.

Flex makes this process simple by offering asynchronous telehealth visits directly at checkout. If a Letter is needed, customers can complete a quick consultation and receive approval to pay with HSA/FSA. Within 24 hours of purchase, Flex will email an itemized receipt and any necessary documentation.

== Screenshots ==

1. Accept HSA/FSA Payments: https://www.withflex.com/
2. Understand HSA/FSA payments as a growth channel in the Flex dashboard.
3. Customers are redirected to Flex to checkout securely using their HSA/FSA card.
4. Flex promotes your brand to engaged buyers looking to spend the $150B in HSA/FSA accounts.

== Changelog ==

= 3.1.5 =
* Fixed an incompatibility with the Cloudflare plugin.

= 3.1.4 =
* Fixed an exception that prevented the plugin from being activated.
* Changed telemetry metadata to only include data that is already available.

= 3.1.3 =
* Fixed an exception that prevented the plugin from being activated.
* Upgraded Sentry to 4.14.2.
* Upgraded Jetpack Autoloader to 5.0.9.

= 3.1.2 =
* Changed the plugin activation/deactivation behavior. Activating the plugin will now register the webhooks and attempt perform a product sync if the API key is available. Deactivating the plugin will delete the webhooks.
* Fixed an edge case were discounts were not applied if the Price did not already exist.
* Removed error reporting when an order is not found when receiving a webhook event. This error exclusively gets thrown by merchants with more than one environment.
* Removed the Flex payment method when the cart or order currency is not United States Dollar (USD).
* Fixed the validation and error message display on the payment gateway settings page.
* Changed the webhook verification to verify new and legacy webhooks.

= 3.1.1 =
* Fixed a bug that would cause the [WooCommerce Stripe Payment Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/) to crash.
* Changed the Product descriptions in Flex by removing the HTML before saving them.
* Changed the Price descriptions in Flex by removing the HTML before saving them.

= 3.1.0 =
* Added support for [Coupons](https://woocommerce.com/document/coupon-management/).
* Added support for Sale Price.
* Fixed a bug that prevented product sync when the payment method was enabled for the very first time.

= 3.0.0 =
* Added support for processing [refunds](https://woocommerce.com/document/woocommerce-refunds/#automatic-refunds) from within WooCommerce.
* Added the `FLEX_TELEMETRY` constant which allows users to opt-out of telemetry by setting the constant to `false`.
* Fixed `PHP Notice: Function _load_textdomain_just_in_time was called incorrectly.`

= 2.2.0 =
* Added support for [WooCommerce block-based checkout](https://woocommerce.com/checkout-blocks/).

= 2.1.3 =
* Fixed a critical error with PHP < 8.4

= 2.1.2 =
* Added additional context (checkout_session_id) to exceptions.
* Added exception reporting. When an exception is thrown within the plugin, the exception is reported to Flex for investigation.

= 2.1.1 =
* Removing the `assets` directory which is not required to be included in the plugin.

= 2.1.0 =
* Added the `cancel_url` to the checkout session which allows customers to return to the WooCommerce checkout.
* Changed the default setting of `enabled` to `no`. This makes it more clear that the API Key must be provided in order to enable the payment method.

= 2.0.0 =
* Renamed plugin to "Pay with Flex" (`pay-with-flex`).
* Renamed `WC_FLEX_API_KEY` to `FLEX_API_KEY`.
* Removed `WooCommerce` from the PHP namespaces.
* Renamed method `from_woo_commerce` to `from_wc`.

= 1.0.0 =
* Fixed a critical error where a method was incorrectly named.
* Taxes & Shipping are now correctly passed from WooCommerce to Flex.
* Webhook Handler. The plugin registers a Flex Webhook when the payment method is enabled.

= 1.0.0-beta.1 =
* API Key handling via the admin or with the `WC_FLEX_API_KEY` constant.
* Test mode based on the API Key that is provided
* Flex Product & Price sync.
* Checkout Session creation and redirection back to WooCommerce in classic checkout.

== Upgrade Notice ==

= 1.0.0 =
This version fixes a critical error and implements a webhook handler to prevent orders from failing to be marked as
payment complete.
