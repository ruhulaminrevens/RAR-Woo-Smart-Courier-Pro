<?php
/**
 * Dispatch module loader. Boots the API courier engine (Pathao, RedX, SteadFast,
 * Paperfly, Sundarban) inside Smart Courier — unless the stand-alone RAR Courier
 * Suite plugin is still active, in which case it keeps full ownership of webhooks,
 * cron and order screens (two copies must never register the same routes).
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Dispatch {

	/** True while RAR Courier Suite is active alongside this plugin. */
	public static function suite_active() {
		return class_exists( 'RPC_Plugin' ) || defined( 'RPC_VERSION' );
	}

	public static function load() {
		$dir   = RWSC_DIR . 'includes/dispatch/';
		$files = array(
			'class-rwsc-api-settings', 'class-rwsc-logger', 'class-rwsc-lock', 'class-rwsc-pathao-status', 'class-rwsc-pathao-api',
			'class-rwsc-pathao-locations', 'class-rwsc-order-data', 'class-rwsc-pathao-orders', 'class-rwsc-courier-base',
			'class-rwsc-redx', 'class-rwsc-steadfast', 'class-rwsc-paperfly', 'class-rwsc-sundarban', 'class-rwsc-couriers',
			'class-rwsc-webhook', 'class-rwsc-cron', 'class-rwsc-dispatch-ajax', 'class-rwsc-dispatch-orders-ui',
			'class-rwsc-bridge',
		);
		foreach ( $files as $f ) {
			require_once $dir . $f . '.php';
		}
	}

	public static function boot() {
		// The bridge listens to the engine's hooks, which the stand-alone Suite fires too.
		RWSC_Bridge::init();
		if ( self::suite_active() ) {
			add_action( 'admin_notices', array( __CLASS__, 'suite_notice' ) );
			return;
		}
		RWSC_Api_Settings::maybe_import_suite();
		RWSC_Webhook::init();
		RWSC_SteadFast::init();
		RWSC_Cron::init();
		if ( is_admin() ) {
			RWSC_Dispatch_Ajax::init();
			RWSC_Dispatch_Orders_UI::init();
		}
	}

	public static function suite_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>RAR Woo Smart Courier:</strong> ' . esc_html__( 'RAR Courier Suite is still active, so its courier dispatch keeps running and the built-in dispatch module is paused. Everything it offers is now included here — deactivate and delete RAR Courier Suite (your settings are imported automatically; leave its "Delete data on uninstall" option off).', 'rar-woo-smart-courier' ) . '</p></div>';
	}

	public static function activate() {
		add_filter( 'cron_schedules', array( 'RWSC_Cron', 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		if ( ! self::suite_active() && ! wp_next_scheduled( RWSC_Cron::HOOK_SYNC ) ) {
			wp_schedule_event( time() + 300, 'rwsc_30min', RWSC_Cron::HOOK_SYNC );
		}
	}

	public static function deactivate() {
		if ( class_exists( 'RWSC_Cron' ) ) {
			RWSC_Cron::clear();
		}
	}
}
