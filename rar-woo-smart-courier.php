<?php
/**
 * Plugin Name:       RAR Woo Smart Courier
 * Plugin URI:        https://github.com/ruhulaminrevens/RAR-Woo-Smart-Courier-Pro
 * Description:       All-in-one courier suite for WooCommerce (Bangladesh): smart checkout courier selection, zone + weight pricing, ETA, plus live API booking and tracking for Pathao, RedX, SteadFast, Paperfly and Sundarban — dispatch board, webhooks, status sync, labels, manifests and reports.
 * Version:           3.1.2
 * Author:            Ruhul Amin
 * Author URI:        https://github.com/ruhulaminrevens
 * Text Domain:       rar-woo-smart-courier
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RWSC_VERSION', '3.1.2' );
define( 'RWSC_FILE', __FILE__ );
define( 'RWSC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RWSC_URL', plugin_dir_url( __FILE__ ) );

require_once RWSC_DIR . 'includes/class-rwsc-settings.php';
require_once RWSC_DIR . 'includes/class-rwsc-locations.php';
require_once RWSC_DIR . 'includes/class-rwsc-engine.php';
require_once RWSC_DIR . 'includes/class-rwsc-shipments.php';
require_once RWSC_DIR . 'includes/class-rwsc-reports.php';
require_once RWSC_DIR . 'includes/class-rwsc-orders-admin.php';
require_once RWSC_DIR . 'includes/class-rwsc-admin.php';
require_once RWSC_DIR . 'includes/class-rwsc-ajax.php';
require_once RWSC_DIR . 'includes/class-rwsc-print.php';
require_once RWSC_DIR . 'includes/class-rwsc-frontend.php';
require_once RWSC_DIR . 'includes/dispatch/class-rwsc-dispatch.php';
require_once RWSC_DIR . 'includes/dispatch/class-rwsc-dispatch-admin.php';
require_once RWSC_DIR . 'includes/class-rwsc-plugin.php';
require_once RWSC_DIR . 'includes/functions.php';

/*
 * Declare compatibility with WooCommerce High-Performance Order Storage and
 * the Cart / Checkout blocks.
 */
add_action(
    'before_woocommerce_init',
    static function () {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RWSC_FILE, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', RWSC_FILE, true );
        }
    }
);

register_activation_hook( RWSC_FILE, array( 'RAR_Woo_Smart_Courier', 'activate' ) );
register_deactivation_hook( RWSC_FILE, array( 'RAR_Woo_Smart_Courier', 'deactivate' ) );

RAR_Woo_Smart_Courier::instance();
