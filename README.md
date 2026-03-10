# XPayr Crypto Gateway for WooCommerce

Official WooCommerce integration for XPayr.

This plugin lets WooCommerce merchants accept cryptocurrency payments through XPayr hosted checkout sessions, with webhook-driven order updates and compatibility with modern WooCommerce checkout architecture.

## Highlights
- Hosted checkout flow via XPayr (`/pay/ps_xxx`)
- Webhook signature verification (`X-XPayr-Signature`, HMAC SHA-256)
- Automatic order status sync for paid/failed/expired sessions
- WooCommerce Blocks compatibility (Gutenberg Checkout)
- HPOS compatibility (custom order tables)
- Dynamic network and currency catalog from XPayr API
- Optional auto webhook registration + secret sync

## Requirements
- WordPress 6.0+
- WooCommerce 6.0+
- PHP 7.4+

## Installation
1. Copy `xpayr-crypto-gateway-for-woocommerce` into `/wp-content/plugins/`.
2. Activate plugin from **WordPress Admin > Plugins**.
3. Open **WooCommerce > Settings > Payments > XPayr**.
4. Configure:
- `API Base URL` (default: `https://xpayr.com/api/v1`)
- `Secret API Key` (`sk_test_...` or `sk_live_...`)
- default `Network` and `Currency`
5. Save and test checkout.

## Webhook
Recommended callback:
`https://<your-store-domain>/?wc-api=xpayrcgfw_gateway_xpayr`

Webhook events update WooCommerce orders according to payment session state.

## Security Notes
- Keep secret keys and webhook secrets out of source control.
- Reject unsigned or invalid-signature webhook payloads.
- Use HTTPS for checkout and webhook endpoints.

## Additional Docs
- WordPress plugin directory style docs: [`readme.txt`](./readme.txt)

## Support
- Website: https://xpayr.com
- API docs: https://xpayr.com/doc-api
