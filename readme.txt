=== XPayr Crypto Gateway for WooCommerce ===
Contributors: xpayr
Tags: crypto, cryptocurrency, payments, woocommerce, blockchain
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in your WooCommerce store via XPayr hosted checkout sessions.

== Description ==

XPayr Crypto Gateway for WooCommerce allows merchants to accept cryptocurrency payments directly in their WooCommerce stores. Customers select the crypto payment method at checkout and are redirected to a secure, hosted XPayr payment page to complete the transaction.

= Key Features =

* **Hosted Checkout** — Redirect customers to a secure XPayr payment page (`/pay/ps_xxx`). No sensitive wallet data touches your server.
* **Automatic Order Sync** — Order status is automatically updated when payment is confirmed, failed, or expired.
* **Webhook Support** — Receive real-time IPN callbacks from XPayr with HMAC-SHA256 signature verification.
* **Thank You Page Sync** — Optionally poll the XPayr API when the customer returns to verify payment status immediately.
* **Multi-Network Support** — Select from available blockchain networks (e.g. BSC, Base, Ethereum) and currencies (USDC, USDT) directly in WooCommerce settings.
* **WooCommerce Blocks Compatible** — Works with both the classic checkout shortcode and the new Gutenberg-based Checkout block.
* **HPOS Compatible** — Fully compatible with WooCommerce High-Performance Order Storage (custom order tables).
* **Auto Webhook Registration** — Optionally auto-register the WooCommerce callback URL on XPayr and sync the webhook secret.
* **Configurable Order Status** — Choose whether completed payments set orders to "Completed" or "Processing."

= Third-Party Service =

This plugin connects to the **XPayr API** (`https://xpayr.com/api/v1` or your configured endpoint) to:

1. Create payment sessions when a customer places an order.
2. Retrieve payment session status on the Thank You page.
3. Receive webhook (IPN) callbacks for real-time order status updates.
4. Fetch the available network and currency catalog for the settings page.
5. Auto-register webhook callback URLs.

No data is sent to XPayr until the store administrator configures and enables the gateway. Customer billing email and order metadata are transmitted as part of the payment session creation.

* **XPayr Website:** [https://xpayr.com](https://xpayr.com)
* **Terms of Service:** [https://xpayr.com/terms](https://xpayr.com/terms)
* **Privacy Policy:** [https://xpayr.com/privacy](https://xpayr.com/privacy)

== Installation ==

1. Upload the `xpayr-crypto-gateway-for-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress Admin > Plugins.
3. Go to WooCommerce > Settings > Payments > XPayr.
4. Enable the payment method and configure:
   - **API Base URL** (default: `https://xpayr.com/api/v1`)
   - **Secret API Key** (`sk_test_...` or `sk_live_...`)
   - **Network** and **Currency** defaults
5. Save changes. The gateway will appear at checkout.

== Frequently Asked Questions ==

= What cryptocurrencies are supported? =

XPayr supports multiple stablecoins (USDC, USDT) across various blockchain networks including BSC, Base, and Ethereum. The available options depend on your XPayr merchant account configuration.

= Do I need an XPayr account? =

Yes. You need to register at [xpayr.com](https://xpayr.com) and obtain a Secret API Key to use this plugin.

= Is this plugin free? =

The plugin itself is free and open source (GPLv2). XPayr may charge transaction fees as part of their payment processing service. Please review the [XPayr Terms of Service](https://xpayr.com/terms) for details.

= How does webhook verification work? =

XPayr signs webhook payloads with HMAC-SHA256 using a shared secret. The plugin verifies this signature before processing any webhook event to prevent unauthorized status changes.

= Does this work with WooCommerce Blocks checkout? =

Yes. The plugin registers as a block-compatible payment method and works with both the classic shortcode checkout and the Gutenberg-based Checkout block.

= What happens if the customer closes the payment page? =

The order will remain in "Pending" status. If the payment session expires on XPayr, a webhook callback will update the order to "Cancelled." You can also enable Thank You page sync for additional verification.

== Screenshots ==

1. XPayr gateway settings page in WooCommerce.
2. Crypto payment option displayed at checkout.
3. XPayr hosted payment page.

== Changelog ==

= 0.2.1 =
* Renamed plugin to "XPayr Crypto Gateway for WooCommerce" to comply with trademark guidelines.
* Added `Requires Plugins: woocommerce` declaration.
* Applied strict prefixing (`XPAYRCGFW_`) to all globals, classes, options, and constants.

= 0.2.0 =
* Added WooCommerce Blocks (Gutenberg checkout) compatibility.
* Added HPOS (High-Performance Order Storage) compatibility declaration.
* Added automatic webhook registration and secret sync.
* Added configurable order completion status (Completed vs Processing).
* Added plugin action links (Configure) and row meta links (FAQ, API docs, Support).
* Improved network and currency catalog loading from XPayr API.
* Added fallback catalog for when API is unavailable.
* Enhanced error handling and debug logging.

= 0.1.0 =
* Initial release.
* Create XPayr payment sessions from WooCommerce checkout.
* Redirect customer to hosted XPayr checkout page.
* Store session_id, invoice_id, and payment_url in order meta.
* Optional order status sync on Thank You page.
* Basic webhook (IPN) processing.

== Upgrade Notices ==

= 0.2.0 =
Adds WooCommerce Blocks and HPOS compatibility. Recommended update for all users.
