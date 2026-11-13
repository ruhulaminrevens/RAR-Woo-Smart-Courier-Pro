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

	/** Stand-alone vendor integrations register overlapping order/REST handlers. */
	public static function legacy_dispatch_active() {
		return defined( 'PTC_PLUGIN_FILE' ) || defined( 'REDX_TRACKING_BASENAME' ) || defined( 'STDF_PLUGIN_FILE' );
	}

	public static function conflicting_dispatch_active() {
		return self::suite_active() || self::legacy_dispatch_active();
	}

	public static function load() {
		$dir   = RWSC_DIR . 'includes/dispatch/';
		$files = array(
			'class-rwsc-api-settings', 'class-rwsc-logger', 'class-rwsc-lock', 'class-rwsc-pathao-status', 'class-rwsc-pathao-api',
			'class-rwsc-pathao-locations', 'class-rwsc-order-data', 'class-rwsc-pathao-orders', 'class-rwsc-courier-base',
			'class-rwsc-redx', 'class-rwsc-steadfast', 'class-rwsc-paperfly', 'class-rwsc-sundarban', 'class-rwsc-couriers',
			'class-rwsc-webhook', 'class-rwsc-cron', 'class-rwsc-dispatch-ajax', 'class-rwsc-dispatch-orders-ui',
			'class-rwsc-dispatch-frontend', 'class-rwsc-bridge',
		);
		foreach ( $files as $f ) {
			require_once $dir . $f . '.php';
		}
	}

	public static function boot() {
		// The bridge listens to the engine's hooks, which the stand-alone Suite fires too.
		RWSC_Bridge::init();
		if ( self::conflicting_dispatch_active() ) {
			add_action( 'admin_notices', array( __CLASS__, 'suite_notice' ) );
			return;
		}
		RWSC_Api_Settings::maybe_import_suite();
		RWSC_Webhook::init();
		RWSC_SteadFast::init();
		RWSC_Cron::init();
		RWSC_Dispatch_Frontend::init();
		if ( is_admin() ) {
			RWSC_Dispatch_Ajax::init();
			RWSC_Dispatch_Orders_UI::init();
		}
	}

	public static function suite_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>RAR Woo Smart Courier:</strong> ' . esc_html__(
			self::suite_active()
				? 'RAR Courier Suite is active. The built-in courier API dispatch is paused to avoid duplicates. Verify all migrated settings/parcels and stage a cutover before uninstalling Suite; keep its Delete data on uninstall option OFF.'
				: 'A stand-alone Pathao, RedX or SteadFast integration is active. Built-in API dispatch is paused to prevent duplicate booking buttons and webhook handlers. Audit legacy parcels and deactivate overlapping integrations before enabling dispatch.',
			'rar-woo-smart-courier'
		) . '</p></div>';
	}

	public static function activate() {
		// Activation runs before init() has registered custom cron intervals.
		// Register the interval here, otherwise wp_schedule_event may return false.
		add_filter( 'cron_schedules', array( 'RWSC_Cron', 'schedules' ) );
		if ( ! self::conflicting_dispatch_active() && ! wp_next_scheduled( RWSC_Cron::HOOK_SYNC ) ) {
			wp_schedule_event( time() + 300, 'rwsc_30min', RWSC_Cron::HOOK_SYNC );
		}
	}

	public static function deactivate() {
		if ( class_exists( 'RWSC_Cron' ) ) {
			RWSC_Cron::clear();
		}
		wp_clear_scheduled_hook( 'rwsc_dispatch_backfill_batch' );
	}
}
