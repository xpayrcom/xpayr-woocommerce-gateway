<?php
/**
 * XPayr WooCommerce Blocks Payment Method Integration.
 *
 * Registers the XPayr gateway as a compatible payment method for
 * the WooCommerce Cart and Checkout Blocks (Gutenberg-based checkout).
 *
 * @package XPayr_WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class XPAYRCGFW_Blocks_Support
 *
 * Extends AbstractPaymentMethodType to register XPayr as a block-compatible
 * payment method. Provides gateway settings to the frontend via script data,
 * and enqueues the block frontend JavaScript.
 */
final class XPAYRCGFW_Blocks_Support extends AbstractPaymentMethodType
{
    /**
     * Payment method name / ID. Must match the gateway ID.
     *
     * @var string
     */
    protected $name = 'xpayrcgfw_gateway';

    /**
     * The underlying classic gateway instance, used to read settings.
     *
     * @var XPAYRCGFW_Gateway_XPayr|null
     */
    private $gateway = null;

    /**
     * Initialize the block support class.
     * Loads the classic gateway instance from WooCommerce payment gateways.
     *
     * @return void
     */
    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_xpayrcgfw_gateway_settings', []);

        $gateways = WC()->payment_gateways()->payment_gateways();
        if (isset($gateways['xpayrcgfw_gateway'])) {
            $this->gateway = $gateways['xpayrcgfw_gateway'];
        }
    }

    /**
     * Returns whether the payment method is active (enabled and configured).
     *
     * @return bool
     */
    public function is_active(): bool
    {
        if ($this->gateway instanceof XPAYRCGFW_Gateway_XPayr) {
            return $this->gateway->is_available();
        }

        return !empty($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    /**
     * Returns the paths to the script files for this payment method block.
     *
     * @return array<string> List of script handles registered via wp_register_script.
     */
    public function get_payment_method_script_handles(): array
    {
        $script_path = plugin_dir_url(XPAYRCGFW_PLUGIN_FILE) . 'assets/js/xpayr-blocks.js';
        $script_path_min = plugin_dir_url(XPAYRCGFW_PLUGIN_FILE) . 'assets/js/xpayr-blocks.min.js';

        // Use minified version if available, else fall back to full.
        $src = file_exists(plugin_dir_path(XPAYRCGFW_PLUGIN_FILE) . 'assets/js/xpayr-blocks.min.js')
            ? $script_path_min
            : $script_path;

        wp_register_script(
            'xpayr-blocks-integration',
            $src,
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            XPAYRCGFW_VERSION,
            true
        );

        return ['xpayr-blocks-integration'];
    }

    /**
     * Returns data that will be exposed to the frontend block via getSetting().
     * This data is serialized into the page and accessible from JavaScript.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $title = !empty($this->settings['title']) ? $this->settings['title'] : __('Pay with Crypto (XPayr)', 'xpayr-crypto-gateway-for-woocommerce');
        $description = !empty($this->settings['description']) ? $this->settings['description'] : __('Secure on-chain crypto checkout via XPayr.', 'xpayr-crypto-gateway-for-woocommerce');
        $icon_url = function_exists('xpayrcgfw_get_gateway_icon_url')
            ? xpayrcgfw_get_gateway_icon_url()
            : plugin_dir_url(XPAYRCGFW_PLUGIN_FILE) . 'assets/images/xpayr.png';

        return [
            'title' => $title,
            'description' => $description,
            'icon' => $icon_url,
            'supports' => ['products'],
        ];
    }
}
