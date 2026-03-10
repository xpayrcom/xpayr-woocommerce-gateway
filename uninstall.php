<?php
/**
 * WooCommerce XPayr Gateway — Uninstall Script.
 *
 * WordPress calls this file automatically when the plugin is deleted
 * via the admin panel (not deactivated, but fully deleted).
 *
 * This script removes all plugin-level database options and any
 * transient/scheduled data created by the gateway, leaving the
 * installation clean after removal.
 */

// Prevent direct access and ensure this is called by WordPress.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove gateway settings stored in wp_options by WooCommerce.
 * WooCommerce serialises all gateway fields under a single option key.
 */
delete_option('woocommerce_xpayr_gateway_settings');

/**
 * Remove any transient values the gateway may have stored
 * (e.g. cached network catalog from the XPayr API).
 */
delete_transient('xpayr_network_catalog');

/**
 * Remove WooCommerce-specific scheduled hooks added by the plugin.
 * This prevents stale cron events from lingering after uninstall.
 */
wp_clear_scheduled_hook('xpayr_sync_pending_orders');
