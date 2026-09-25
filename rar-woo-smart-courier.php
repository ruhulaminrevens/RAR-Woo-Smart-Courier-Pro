<?php
/**
 * Plugin Name:       RAR Woo Smart Courier
 * Plugin URI:        https://github.com/ruhulaminrevens/RAR-Woo-Smart-Courier-Pro
 * Description:       Smart multi-courier shipping for WooCommerce (Bangladesh): zone + weight pricing, ETA and delivery-date estimates, recommendation badges, shipment tracking, dispatch board, courier dashboard, labels, manifests and CSV export.
 * Version:           2.0.0
 * Author:            Ruhul Amin
 * Author URI:        https://github.com/ruhulaminrevens
 * Text Domain:       rar-woo-smart-courier
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:   10.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RWSC_VERSION', '2.0.0' );
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

RAR_Woo_Smart_Courier::instance();
