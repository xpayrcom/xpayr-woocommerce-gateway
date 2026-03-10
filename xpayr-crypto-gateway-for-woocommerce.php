<?php
/**
 * Plugin Name: XPayr Crypto Gateway for WooCommerce
 * Plugin URI: https://xpayr.com/plugins
 * Description: Accept crypto payments via XPayr payment sessions.
 * Version: 0.2.1
 * Author: XPayr
 * Author URI: https://xpayr.com
 * Text Domain: xpayr-crypto-gateway-for-woocommerce
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin-wide constants.
 * XPAYRCGFW_PLUGIN_FILE – absolute path to this file, used for asset URL resolution.
 * XPAYRCGFW_VERSION     – plugin version string, used as script/style cache-buster.
 */
if (!defined('XPAYRCGFW_PLUGIN_FILE')) {
    define('XPAYRCGFW_PLUGIN_FILE', __FILE__);
}
if (!defined('XPAYRCGFW_VERSION')) {
    define('XPAYRCGFW_VERSION', '0.2.1');
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * Without this declaration, WooCommerce shows an "incompatible plugin" warning
 * when HPOS (custom order tables) is enabled in WooCommerce > Settings > Advanced.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Declare compatibility with WooCommerce Cart and Checkout Blocks.
 * Classic payment gateways do not support blocks natively;
 * declaring false prevents a secondary incompatibility warning.
 */
/**
 * Declare full compatibility with WooCommerce Cart and Checkout Blocks.
 * This suppresses the "Incompatible with block-based checkout" warning
 * and enables the Blocks payment method registration below.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

/**
 * Register XPayr as a WooCommerce Blocks-compatible payment method.
 * Loads the block support class and registers it via the
 * woocommerce_blocks_payment_method_type_registration action so that
 * the gateway appears in the Gutenberg-based Checkout block.
 */
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(XPAYRCGFW_PLUGIN_FILE) . 'includes/class-xpayrcgfw-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry) {
            $registry->register(new XPAYRCGFW_Blocks_Support());
        }
    );
});

if (!function_exists('xpayrcgfw_is_woocommerce_active')) {
    function xpayrcgfw_is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce') && class_exists('WC_Payment_Gateway');
    }
}

if (!function_exists('xpayrcgfw_get_gateway_icon_url')) {
    /**
     * Resolve gateway icon URL from plugin assets (filterable).
     */
    function xpayrcgfw_get_gateway_icon_url(): string
    {
        $default_icon = plugin_dir_url(XPAYRCGFW_PLUGIN_FILE) . 'assets/images/xpayr.png';
        return (string) apply_filters('xpayrcgfw_gateway_icon', $default_icon);
    }
}

add_action('plugins_loaded', function () {
    if (!xpayrcgfw_is_woocommerce_active()) {
        return;
    }

    class XPAYRCGFW_Gateway_XPayr extends WC_Payment_Gateway
    {
        private WC_Logger $logger;
        private string $api_base_url = '';
        private string $secret_key = '';
        private string $network = '';
        private string $currency = '';
        private string $invoice_prefix = '';
        private bool $debug_mode = false;
        private bool $sync_on_thankyou = true;
        private string $complete_order_status = 'completed';
        private string $webhook_secret = '';
        private bool $webhook_auto_sync = true;

        public function __construct()
        {
            $this->id = 'xpayrcgfw_gateway';
            $this->method_title = __('XPayr Crypto', 'xpayr-crypto-gateway-for-woocommerce');
            $this->method_description = __('Accept cryptocurrency payments through XPayr.', 'xpayr-crypto-gateway-for-woocommerce');
            $this->title = __('Pay with Crypto (XPayr)', 'xpayr-crypto-gateway-for-woocommerce');
            $this->description = __('Secure on-chain crypto checkout via XPayr.', 'xpayr-crypto-gateway-for-woocommerce');
            $this->has_fields = false;
            $this->supports = ['products'];
            $this->icon = xpayrcgfw_get_gateway_icon_url();
            $this->logger = wc_get_logger();

            $this->init_form_fields();
            $this->init_settings();
            $this->refresh_runtime_settings();

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'on_thankyou_page']);
            add_action('woocommerce_api_xpayrcgfw_gateway_xpayr', [$this, 'handle_webhook']);
        }

        public function init_form_fields(): void
        {
            $dynamic = $this->load_dynamic_catalog_from_saved_settings();
            $networkOptions = $dynamic['network_options'];
            $currencyOptions = $dynamic['currency_options'];

            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable XPayr Gateway', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => __('Title', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Shown at checkout.', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => __('Pay with Crypto (XPayr)', 'xpayr-crypto-gateway-for-woocommerce'),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Description', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Shown under payment method title.', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => __('Secure on-chain crypto checkout via XPayr.', 'xpayr-crypto-gateway-for-woocommerce'),
                ],
                'api_base_url' => [
                    'title' => __('API Base URL', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Example: https://xpayr.com/api/v1 (or your staging endpoint).', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => 'https://xpayr.com/api/v1',
                    'desc_tip' => true,
                ],
                'secret_key' => [
                    'title' => __('Secret API Key', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'password',
                    'description' => __('Use your XPayr secret key (sk_test_... or sk_live_...).', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => '',
                ],
                'network' => [
                    'title' => __('Network', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'select',
                    'default' => 'bsc-testnet',
                    'options' => $networkOptions,
                    'description' => __('Loaded from XPayr API network catalog.', 'xpayr-crypto-gateway-for-woocommerce'),
                    'desc_tip' => true,
                ],
                'currency' => [
                    'title' => __('Currency', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'select',
                    'default' => 'USDC',
                    'options' => $currencyOptions,
                    'description' => __('Loaded from XPayr API currency catalog.', 'xpayr-crypto-gateway-for-woocommerce'),
                    'desc_tip' => true,
                ],
                'invoice_prefix' => [
                    'title' => __('Invoice Prefix', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'text',
                    'default' => 'WC-',
                    'description' => __('Prefix used for order reference metadata.', 'xpayr-crypto-gateway-for-woocommerce'),
                ],
                'sync_on_thankyou' => [
                    'title' => __('Sync on Thank You Page', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Check XPayr session status when user returns from checkout', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => 'yes',
                ],
                'complete_order_status' => [
                    'title' => __('Order Status after Completed Payment', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'select',
                    'default' => 'completed',
                    'options' => [
                        'completed' => __('Completed', 'xpayr-crypto-gateway-for-woocommerce'),
                        'processing' => __('Processing', 'xpayr-crypto-gateway-for-woocommerce'),
                    ],
                ],
                'webhook_auto_sync' => [
                    'title' => __('Webhook Sync', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Auto-register WooCommerce callback URL on XPayr and sync secret', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => 'yes',
                ],
                'webhook_secret' => [
                    'title' => __('Webhook Secret', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'password',
                    'description' => __('Synced from XPayr panel when Webhook Sync is enabled. Can also be pasted manually.', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => '',
                ],
                'webhook_callback' => [
                    'title' => __('Webhook Callback URL', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'title',
                    'description' => '<code>' . esc_html($this->get_webhook_callback_url()) . '</code>',
                ],
                'debug_mode' => [
                    'title' => __('Debug Log', 'xpayr-crypto-gateway-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable debug logging', 'xpayr-crypto-gateway-for-woocommerce'),
                    'default' => 'no',
                ],
            ];
        }

        public function process_admin_options()
        {
            $saved = parent::process_admin_options();
            $this->init_settings();
            $this->refresh_runtime_settings();

            if ($this->webhook_auto_sync) {
                $result = $this->sync_webhook_with_panel_secret();
                if (!empty($result['success'])) {
                    WC_Admin_Settings::add_message(__('XPayr webhook synced successfully.', 'xpayr-crypto-gateway-for-woocommerce'));
                } else {
                    $message = !empty($result['message']) ? (string) $result['message'] : __('Webhook sync failed.', 'xpayr-crypto-gateway-for-woocommerce');
                    WC_Admin_Settings::add_error($message);
                }
            }

            return $saved;
        }

        public function process_payment($order_id): array
        {
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                wc_add_notice(__('Order not found.', 'xpayr-crypto-gateway-for-woocommerce'), 'error');
                return ['result' => 'fail'];
            }

            if ($this->secret_key === '' || $this->api_base_url === '') {
                wc_add_notice(__('XPayr API settings are incomplete. Please contact store admin.', 'xpayr-crypto-gateway-for-woocommerce'), 'error');
                return ['result' => 'fail'];
            }

            $payload = [
                'amount' => number_format((float) $order->get_total(), 2, '.', ''),
                'currency' => $this->currency,
                'network' => $this->network,
                'order_id' => $this->invoice_prefix . $order->get_order_number(),
                /* translators: 1: Order number, 2: Store name */
                'description' => sprintf(__('Order %1$s from %2$s', 'xpayr-crypto-gateway-for-woocommerce'), $order->get_order_number(), get_bloginfo('name')),
                'success_url' => $this->get_return_url($order),
                'cancel_url' => $order->get_cancel_order_url_raw(),
                'ipn_callback_url' => add_query_arg('wc-api', 'XPAYRCGFW_Gateway_XPayr', home_url('/')),
                'metadata' => [
                    'source' => 'woocommerce',
                    'order_id' => $this->invoice_prefix . $order->get_order_number(),
                    'woocommerce_order_id' => $order->get_id(),
                    'customer_email' => $order->get_billing_email(),
                ],
            ];

            $response = wp_remote_post($this->api_base_url . '/payments', [
                'timeout' => 25,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secret_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                $this->log('API create session WP_Error: ' . $response->get_error_message(), 'error');
                wc_add_notice(__('Could not create XPayr payment session. Please try again.', 'xpayr-crypto-gateway-for-woocommerce'), 'error');
                return ['result' => 'fail'];
            }

            $httpCode = (int) wp_remote_retrieve_response_code($response);
            $bodyRaw = (string) wp_remote_retrieve_body($response);
            $data = json_decode($bodyRaw, true);

            if ($httpCode < 200 || $httpCode >= 300 || !is_array($data) || empty($data['payment_url']) || empty($data['id'])) {
                $errorMessage = __('Could not create XPayr payment session.', 'xpayr-crypto-gateway-for-woocommerce');
                if (is_array($data) && !empty($data['error']['message'])) {
                    $errorMessage .= ' ' . sanitize_text_field((string) $data['error']['message']);
                }
                $this->log('API create session failed [' . $httpCode . ']: ' . $bodyRaw, 'error');
                wc_add_notice($errorMessage, 'error');
                return ['result' => 'fail'];
            }

            $sessionId = sanitize_text_field((string) $data['id']);
            $paymentUrl = esc_url_raw((string) $data['payment_url']);
            $invoiceId = !empty($data['invoice_id']) ? sanitize_text_field((string) $data['invoice_id']) : '';

            $order->update_meta_data('_xpayr_session_id', $sessionId);
            $order->update_meta_data('_xpayr_payment_url', $paymentUrl);
            if ($invoiceId !== '') {
                $order->update_meta_data('_xpayr_invoice_id', $invoiceId);
            }

            $order->update_status('pending', __('Waiting for XPayr payment confirmation.', 'xpayr-crypto-gateway-for-woocommerce'));
            /* translators: %s: Session ID */
            $order->add_order_note(sprintf(__('XPayr session created: %s', 'xpayr-crypto-gateway-for-woocommerce'), $sessionId));
            $order->save();

            wc_reduce_stock_levels($order->get_id());
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            return [
                'result' => 'success',
                'redirect' => $paymentUrl,
            ];
        }

        public function on_thankyou_page(int $order_id): void
        {
            if (!$this->sync_on_thankyou) {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                return;
            }

            $sessionId = (string) $order->get_meta('_xpayr_session_id', true);
            if ($sessionId === '') {
                return;
            }

            $this->sync_order_status_from_api($order, $sessionId);
        }

        public function handle_webhook(): void
        {
            $raw = file_get_contents('php://input');
            $payload = json_decode((string) $raw, true);

            if (!is_array($payload)) {
                status_header(400);
                echo 'invalid payload';
                exit;
            }

            if ($this->webhook_secret !== '') {
                $headerSig = isset($_SERVER['HTTP_X_XPAYR_SIGNATURE'])
                    ? isset($_SERVER['HTTP_X_XPAYR_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_XPAYR_SIGNATURE'])) : ''
                    : '';
                $headerSig = trim((string) $headerSig);
                $prefix = 'sha256=';
                if (substr($headerSig, 0, strlen($prefix)) === $prefix) {
                    $headerSig = substr($headerSig, strlen($prefix));
                }

                $expected = hash_hmac('sha256', (string) $raw, $this->webhook_secret);
                if ($headerSig === '' || !hash_equals($expected, $headerSig)) {
                    $this->log('Webhook signature mismatch', 'warning');
                    status_header(401);
                    echo 'invalid signature';
                    exit;
                }
            }

            $eventType = (string) ($payload['type'] ?? '');
            $sessionId = (string) ($payload['data']['session_id'] ?? '');
            $order = null;

            if ($sessionId !== '') {
                $order = $this->find_order_by_session_id($sessionId);
            }
            if (!$order instanceof WC_Order) {
                $order = $this->find_order_by_payload_metadata($payload);
            }
            if (!$order instanceof WC_Order) {
                status_header(404);
                echo 'order not found';
                exit;
            }

            switch ($eventType) {
                case 'payment.completed':
                    $this->mark_order_paid($order, __('Payment confirmed by XPayr webhook.', 'xpayr-crypto-gateway-for-woocommerce'));
                    break;
                case 'payment.failed':
                    $order->update_status('failed', __('Payment failed on XPayr checkout.', 'xpayr-crypto-gateway-for-woocommerce'));
                    break;
                case 'payment.expired':
                    $order->update_status('cancelled', __('Payment session expired on XPayr checkout.', 'xpayr-crypto-gateway-for-woocommerce'));
                    break;
                case 'payment.pending':
                case 'payment.processing':
                    /* translators: %s: Event type */
                    $order->add_order_note(sprintf(__('XPayr update: %s', 'xpayr-crypto-gateway-for-woocommerce'), $eventType));
                    break;
                default:
                    /* translators: %s: Event type */
                    $order->add_order_note(sprintf(__('XPayr webhook received: %s', 'xpayr-crypto-gateway-for-woocommerce'), $eventType));
                    break;
            }

            status_header(200);
            echo 'ok';
            exit;
        }

        private function sync_order_status_from_api(WC_Order $order, string $sessionId): void
        {
            $response = wp_remote_get($this->api_base_url . '/payments/' . rawurlencode($sessionId), [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secret_key,
                    'Accept' => 'application/json',
                ],
            ]);

            if (is_wp_error($response)) {
                $this->log('API sync WP_Error: ' . $response->get_error_message(), 'error');
                return;
            }

            $httpCode = (int) wp_remote_retrieve_response_code($response);
            $bodyRaw = (string) wp_remote_retrieve_body($response);
            $data = json_decode($bodyRaw, true);
            if ($httpCode < 200 || $httpCode >= 300 || !is_array($data)) {
                $this->log('API sync failed [' . $httpCode . ']: ' . $bodyRaw, 'warning');
                return;
            }

            $status = strtolower((string) ($data['status'] ?? ''));
            if (in_array($status, ['completed', 'confirmed'], true)) {
                $this->mark_order_paid($order, __('Payment confirmed by XPayr API sync.', 'xpayr-crypto-gateway-for-woocommerce'));
            } elseif ($status === 'failed') {
                if (!$order->has_status(['completed', 'processing'])) {
                    $order->update_status('failed', __('Payment failed on XPayr.', 'xpayr-crypto-gateway-for-woocommerce'));
                }
            } elseif ($status === 'expired') {
                if (!$order->has_status(['completed', 'processing'])) {
                    $order->update_status('cancelled', __('Payment session expired on XPayr.', 'xpayr-crypto-gateway-for-woocommerce'));
                }
            } else {
                if (!$order->has_status(['completed', 'processing'])) {
                    /* translators: %s: API Status */
                    $order->add_order_note(sprintf(__('XPayr current status: %s', 'xpayr-crypto-gateway-for-woocommerce'), $status));
                }
            }
        }

        private function mark_order_paid(WC_Order $order, string $note): void
        {
            if ($order->has_status(['completed', 'processing'])) {
                return;
            }

            if ($this->complete_order_status === 'processing') {
                $order->update_status('processing', $note);
            } else {
                $order->payment_complete();
                $order->add_order_note($note);
            }
        }

        private function find_order_by_session_id(string $sessionId): ?WC_Order
        {
            $orders = wc_get_orders([
                'limit' => 1,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query' => [
                    [
                        'key' => '_xpayr_session_id',
                        'value' => $sessionId,
                    ]
                ],
                'type' => 'shop_order',
                'return' => 'objects',
            ]);

            if (!empty($orders) && $orders[0] instanceof WC_Order) {
                return $orders[0];
            }

            return null;
        }

        private function find_order_by_payload_metadata(array $payload): ?WC_Order
        {
            $meta = $payload['data']['metadata'] ?? [];
            if (!is_array($meta)) {
                return null;
            }

            if (!empty($meta['woocommerce_order_id'])) {
                $order = wc_get_order((int) $meta['woocommerce_order_id']);
                if ($order instanceof WC_Order) {
                    return $order;
                }
            }

            if (!empty($meta['order_id'])) {
                $orderRef = (string) $meta['order_id'];
                if (preg_match('/(\d+)$/', $orderRef, $m)) {
                    $order = wc_get_order((int) $m[1]);
                    if ($order instanceof WC_Order) {
                        return $order;
                    }
                }
            }

            return null;
        }

        private function sync_webhook_with_panel_secret(): array
        {
            if ($this->api_base_url === '' || $this->secret_key === '') {
                return ['success' => false, 'message' => __('Webhook sync skipped: API base URL or secret key missing.', 'xpayr-crypto-gateway-for-woocommerce')];
            }

            $callbackUrl = $this->get_webhook_callback_url();
            $response = wp_remote_post($this->api_base_url . '/webhooks', [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secret_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode(['url' => $callbackUrl]),
            ]);

            if (is_wp_error($response)) {
                $this->log('Webhook sync WP_Error: ' . $response->get_error_message(), 'error');
                return ['success' => false, 'message' => __('Webhook sync request failed.', 'xpayr-crypto-gateway-for-woocommerce')];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['secret'])) {
                $this->log('Webhook sync failed [' . $code . ']: ' . $body, 'warning');
                return ['success' => false, 'message' => __('Webhook sync failed: could not get secret from XPayr.', 'xpayr-crypto-gateway-for-woocommerce')];
            }

            $newSecret = (string) $data['secret'];
            $settings = get_option('woocommerce_' . $this->id . '_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
            $settings['webhook_secret'] = $newSecret;
            update_option('woocommerce_' . $this->id . '_settings', $settings);

            $this->webhook_secret = $newSecret;
            $this->settings['webhook_secret'] = $newSecret;

            return ['success' => true];
        }

        private function load_dynamic_catalog_from_saved_settings(): array
        {
            $apiBase = rtrim((string) $this->get_saved_setting('api_base_url', 'https://xpayr.com/api/v1'), '/');
            $secret = trim((string) $this->get_saved_setting('secret_key', ''));
            $selectedNetwork = (string) $this->get_saved_setting('network', 'bsc-testnet');

            $catalog = $this->fetch_network_catalog($apiBase, $secret);
            if (empty($catalog)) {
                $catalog = $this->build_fallback_catalog();
            }

            $networkOptions = [];
            $allCurrencySet = [];
            $networkCurrencyMap = [];

            foreach ($catalog as $network) {
                $networkKey = (string) ($network['network_key'] ?? '');
                $networkName = (string) ($network['network_name'] ?? $networkKey);
                if ($networkKey === '') {
                    continue;
                }
                $suffix = !empty($network['is_testnet']) ? ' (Testnet)' : '';
                $networkOptions[$networkKey] = $networkName . $suffix;
                $networkCurrencyMap[$networkKey] = [];

                $currencies = $network['currencies'] ?? [];
                if (!is_array($currencies)) {
                    $currencies = [];
                }
                foreach ($currencies as $c) {
                    $symbol = strtoupper((string) ($c['symbol'] ?? ''));
                    if ($symbol === '') {
                        continue;
                    }
                    $networkCurrencyMap[$networkKey][$symbol] = $symbol;
                    $allCurrencySet[$symbol] = $symbol;
                }
            }

            $currencyOptions = [];
            if (!empty($networkCurrencyMap[$selectedNetwork])) {
                $currencyOptions = $networkCurrencyMap[$selectedNetwork];
            } elseif (!empty($allCurrencySet)) {
                $currencyOptions = $allCurrencySet;
            }

            if (empty($networkOptions)) {
                $networkOptions = ['bsc-testnet' => 'BSC Testnet'];
            }
            if (empty($currencyOptions)) {
                $currencyOptions = ['USDC' => 'USDC', 'USDT' => 'USDT'];
            }

            return [
                'network_options' => $networkOptions,
                'currency_options' => $currencyOptions,
            ];
        }

        private function fetch_network_catalog(string $apiBase, string $secret): array
        {
            if ($apiBase === '' || $secret === '') {
                return [];
            }

            $response = wp_remote_get($apiBase . '/me/networks', [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Accept' => 'application/json',
                ],
            ]);

            if (is_wp_error($response)) {
                return [];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($code < 200 || $code >= 300 || !is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
                return [];
            }

            return $data['data'];
        }

        private function build_fallback_catalog(): array
        {
            return [
                [
                    'network_key' => 'bsc-testnet',
                    'network_name' => 'BSC Testnet',
                    'is_testnet' => true,
                    'currencies' => [
                        ['symbol' => 'USDC'],
                        ['symbol' => 'USDT'],
                    ],
                ],
                [
                    'network_key' => 'base-sepolia',
                    'network_name' => 'Base Sepolia',
                    'is_testnet' => true,
                    'currencies' => [
                        ['symbol' => 'USDC'],
                        ['symbol' => 'USDT'],
                    ],
                ],
            ];
        }

        private function get_saved_setting(string $key, $default = '')
        {
            $settings = get_option('woocommerce_' . $this->id . '_settings', []);
            if (!is_array($settings)) {
                return $default;
            }
            return $settings[$key] ?? $default;
        }

        private function refresh_runtime_settings(): void
        {
            $this->title = (string) $this->get_option('title', $this->title);
            $this->description = (string) $this->get_option('description', $this->description);
            $this->api_base_url = rtrim((string) $this->get_option('api_base_url', 'https://xpayr.com/api/v1'), '/');
            $this->secret_key = trim((string) $this->get_option('secret_key', ''));
            $this->network = trim((string) $this->get_option('network', 'bsc-testnet'));
            $this->currency = strtoupper(trim((string) $this->get_option('currency', 'USDC')));
            $this->invoice_prefix = trim((string) $this->get_option('invoice_prefix', 'WC-'));
            $this->debug_mode = $this->get_option('debug_mode', 'no') === 'yes';
            $this->sync_on_thankyou = $this->get_option('sync_on_thankyou', 'yes') === 'yes';
            $this->complete_order_status = (string) $this->get_option('complete_order_status', 'completed');
            $this->webhook_secret = trim((string) $this->get_option('webhook_secret', ''));
            $this->webhook_auto_sync = $this->get_option('webhook_auto_sync', 'yes') === 'yes';
        }

        private function get_webhook_callback_url(): string
        {
            return add_query_arg('wc-api', 'XPAYRCGFW_Gateway_XPayr', home_url('/'));
        }

        private function log(string $message, string $level = 'info'): void
        {
            if (!$this->debug_mode && $level === 'info') {
                return;
            }
            $this->logger->log($level, $message, ['source' => 'xpayr-gateway']);
        }

        /**
         * Determine whether this gateway should appear at checkout.
         * The gateway is hidden when the Secret API Key or API Base URL is empty,
         * preventing customers from selecting an unconfigured payment method.
         *
         * @return bool
         */
        public function is_available(): bool
        {
            // Parent check: gateway must be enabled in settings.
            if (!parent::is_available()) {
                return false;
            }

            // Both API credentials must be configured.
            if ($this->secret_key === '' || $this->api_base_url === '') {
                return false;
            }

            return true;
        }

    }
});

add_filter('woocommerce_payment_gateways', function (array $gateways): array {
    if (!in_array('XPAYRCGFW_Gateway_XPayr', $gateways, true)) {
        $gateways[] = 'XPAYRCGFW_Gateway_XPayr';
    }
    return $gateways;
});

/**
 * Add a "Configure" shortcut to the plugin action links column
 * (the row of links shown to the left of Deactivate in the plugins list).
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=xpayrcgfw_gateway');
    $configure = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Configure', 'xpayr-crypto-gateway-for-woocommerce') . '</a>';
    array_unshift($links, $configure);
    return $links;
});

/**
 * Add informational links to the plugin description row
 * (the meta row below the plugin name / description in the plugins list).
 * These links appear alongside "Version x.x.x | By XPayr".
 *
 * All URLs are derived from the configured api_base_url so they
 * automatically point to the correct environment:
 *   https://3web3.shop/api/v1  →  https://3web3.shop/...
 *   https://xpayr.com/api/v1   →  https://xpayr.com/...
 */
add_filter('plugin_row_meta', function (array $meta, string $plugin_file): array {
    if (plugin_basename(__FILE__) !== $plugin_file) {
        return $meta;
    }

    // Remove the "Visit plugin site" link that WordPress auto-generates from
    // the Plugin URI header field. We add our own "View details" link instead.
    foreach ($meta as $key => $item) {
        if (strpos((string) $item, 'Visit plugin site') !== false) {
            unset($meta[$key]);
        }
    }

    // Derive site base URL from the stored api_base_url setting.
    // No hardcoded fallback — if the gateway is not configured, no links are shown.
    $settings = (array) get_option('woocommerce_xpayrcgfw_gateway_settings', []);
    $raw_api_url = trim((string) ($settings['api_base_url'] ?? ''));

    if (empty($raw_api_url)) {
        return array_values($meta);
    }

    $parsed = wp_parse_url(rtrim($raw_api_url, '/'));
    $scheme = !empty($parsed['scheme']) ? $parsed['scheme'] : 'https';
    $host = !empty($parsed['host']) ? $parsed['host'] : '';

    if (empty($host)) {
        return array_values($meta);
    }

    $site_base = $scheme . '://' . $host;

    $extra = [
        // Plugin landing page.
        '<a href="' . esc_url($site_base . '/plugins/woocommerce') . '" target="_blank" rel="noopener">'
        . esc_html__('View details', 'xpayr-crypto-gateway-for-woocommerce') . '</a>',

        // General FAQ page.
        '<a href="' . esc_url($site_base . '/faqs') . '" target="_blank" rel="noopener">'
        . esc_html__('FAQ', 'xpayr-crypto-gateway-for-woocommerce') . '</a>',

        // Full API reference documentation.
        '<a href="' . esc_url($site_base . '/doc-api/') . '" target="_blank" rel="noopener">'
        . esc_html__('API docs', 'xpayr-crypto-gateway-for-woocommerce') . '</a>',

        // Merchant support portal.
        '<a href="' . esc_url($site_base . '/merchant/support') . '" target="_blank" rel="noopener">'
        . esc_html__('Support', 'xpayr-crypto-gateway-for-woocommerce') . '</a>',
    ];

    return array_values(array_merge($meta, $extra));
}, 10, 2);

