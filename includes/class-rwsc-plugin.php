<?php
/**
 * Plugin bootstrap / orchestrator.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_Woo_Smart_Courier {

    const VERSION    = RWSC_VERSION;
    const OPTION_KEY = 'rwsc_settings';
    const PREFIX     = 'rwsc_';

    /** @var RAR_Woo_Smart_Courier|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
    }

    public function boot() {
        load_plugin_textdomain( 'rar-woo-smart-courier', false, dirname( plugin_basename( RWSC_FILE ) ) . '/languages' );

        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
            add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
            return;
        }

        self::maybe_upgrade();
        // Orders can only be queried once WooCommerce has registered its post types / order tables.
        add_action( 'wp_loaded', array( __CLASS__, 'maybe_backfill_dispatch' ) );

        RWSC_Engine::init();
        RWSC_Shipments::init();
        RWSC_Orders_Admin::init();
        RWSC_Admin::init();
        RWSC_Ajax::init();
        RWSC_Print::init();
        RWSC_Frontend::init();

        // API courier dispatch (Pathao / RedX / SteadFast / Paperfly / Sundarban).
        RWSC_Dispatch::load();
        RWSC_Dispatch::boot();
        if ( is_admin() && ! RWSC_Dispatch::suite_active() ) {
            RWSC_Dispatch_Admin::init();
        }

        foreach ( array( 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_order_status_changed', 'rwsc_shipment_updated', 'rwsc_settings_saved' ) as $hook ) {
            add_action( $hook, array( 'RWSC_Reports', 'bust' ) );
        }

        add_filter( 'plugin_action_links_' . plugin_basename( RWSC_FILE ), array( $this, 'action_links' ) );
    }

    public static function activate() {
        // Existing (v1.x) settings are migrated first; a fresh install starts from defaults.
        RWSC_Settings::migrate();
        if ( false === get_option( RWSC_Settings::OPTION, false ) ) {
            add_option( RWSC_Settings::OPTION, array( 'version' => RWSC_VERSION ), '', false );
        }
        RWSC_Dispatch::load();
        RWSC_Dispatch::activate();
    }

    public static function deactivate() {
        RWSC_Dispatch::load();
        RWSC_Dispatch::deactivate();
    }

    public static function maybe_upgrade() {
        RWSC_Settings::migrate(); // Idempotent: returns at once when settings are already v2.
        $stored = (string) get_option( 'rwsc_version', '' );
        if ( RWSC_VERSION === $stored ) {
            return;
        }
        if ( '' === $stored || version_compare( $stored, '2.0.0', '<' ) ) {
            delete_option( 'rwsc_backfill_done' );
        }
        if ( '' === $stored || version_compare( $stored, '3.0.1', '<' ) ) {
            // v3: dispatch engine arrives. Pull in RAR Courier Suite settings and mirror its parcels once.
            delete_option( 'rwsc_dispatch_backfill_done' );
        }
        update_option( 'rwsc_version', RWSC_VERSION, false );
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
    }

    /** One-time: mirror existing API parcels (e.g. from RAR Courier Suite) into the shipment board. */
    public static function maybe_backfill_dispatch() {
        if ( ! is_admin() || get_option( 'rwsc_dispatch_backfill_done' ) || ! class_exists( 'RWSC_Dispatch' ) ) {
            return;
        }
        RWSC_Dispatch::load();
        RWSC_Api_Settings::maybe_import_suite();
        RWSC_Bridge::backfill( 300 );
        update_option( 'rwsc_dispatch_backfill_done', time(), false );
    }

    public function missing_wc_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>RAR Woo Smart Courier</strong> ' . esc_html__( 'needs WooCommerce to be installed and active.', 'rar-woo-smart-courier' ) . '</p></div>';
    }

    public function action_links( $links ) {
        $mine = array(
            '<a href="' . esc_url( admin_url( 'admin.php?page=rwsc-dashboard' ) ) . '">' . esc_html__( 'Dashboard', 'rar-woo-smart-courier' ) . '</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=rar-woo-smart-courier' ) ) . '">' . esc_html__( 'Settings', 'rar-woo-smart-courier' ) . '</a>',
        );
        return array_merge( $mine, $links );
    }
}
