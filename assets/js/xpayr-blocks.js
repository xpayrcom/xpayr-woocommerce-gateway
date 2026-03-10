/**
 * XPayr WooCommerce Blocks – Frontend Payment Method Registration
 *
 * Registers the XPayr payment method with the WooCommerce Blocks
 * payment registry so it appears in the Gutenberg-based Checkout block.
 *
 * This script is enqueued only on pages that contain the Cart or
 * Checkout block, via XPAYRCGFW_Blocks_Support::get_payment_method_script_handles().
 */

/* global wc */
(function () {
    'use strict';

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;
    var createElement = window.wp.element.createElement;
    var decodeEntities = window.wp.htmlEntities.decodeEntities;

    /**
     * Retrieve settings passed from XPAYRCGFW_Blocks_Support::get_payment_method_data().
     * Falls back to safe defaults if the setting is missing.
     */
    var xpayrData = getSetting('xpayrcgfw_gateway_data', {});

    var title = decodeEntities(xpayrData.title || 'Pay with Crypto (XPayr)');
    var description = decodeEntities(xpayrData.description || 'Secure on-chain crypto checkout via XPayr.');
    var iconUrl = xpayrData.icon || 'https://xpayr.com/images/xpayr.png';

    /**
     * XPayr payment method label component.
     * Displays the gateway title alongside the XPayr logo icon.
     *
     * @param {Object} props - React-like props provided by WooCommerce Blocks.
     * @param {Object} props.components - Helper UI components from WooCommerce Blocks.
     * @returns {*} React element.
     */
    function XPayrLabel(props) {
        var PaymentMethodLabel = props.components.PaymentMethodLabel;

        return createElement(
            PaymentMethodLabel,
            {
                text: title,
                icon: createElement('img', {
                    src: iconUrl,
                    alt: 'XPayr',
                    style: { height: '24px', marginLeft: '8px', verticalAlign: 'middle' },
                }),
            }
        );
    }

    /**
     * XPayr payment method content component.
     * Displayed below the payment method title in the checkout payment section.
     *
     * @returns {*} React element showing the description text.
     */
    function XPayrContent() {
        return createElement(
            'p',
            { style: { margin: '8px 0 0', fontSize: '0.9em', color: '#555' } },
            description
        );
    }

    /**
     * Register the XPayr payment method with WooCommerce Blocks.
     *
     * ariaLabel    – Accessibility label for screen readers.
     * canMakePayment – Always returns true; availability is controlled server-side
     *                  via XPAYRCGFW_Blocks_Support::is_active().
     * content      – Component shown when this payment method is expanded.
     * edit         – Same component shown inside the block editor preview.
     * label        – Title/icon shown in the collapsed payment method row.
     * name         – Must match the gateway ID registered in XPAYRCGFW_Gateway_XPayr::$id.
     * supports     – Declares which WooCommerce features this method supports.
     */
    registerPaymentMethod({
        name: 'xpayrcgfw_gateway',
        ariaLabel: title,
        canMakePayment: function () { return true; },
        content: createElement(XPayrContent, null),
        edit: createElement(XPayrContent, null),
        label: createElement(XPayrLabel, null),
        supports: {
            features: xpayrData.supports || ['products'],
        },
    });

})();
