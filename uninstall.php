<?php
/**
 * RAR Woo Smart Courier uninstall handler.
 *
 * Settings (rwsc_settings, rwsc_api) and order data (_rwsc_* shipment meta and
 * ptc_* / rdx_* / sfc_* / pf_* / sdb_* parcel meta) are intentionally preserved so that
 * removing or reinstalling the plugin never loses courier credentials, tracking
 * or delivery history. Only caches, schedules and — when the admin ticked
 * "Delete data on uninstall" under Courier APIs → General — the API settings
 * are removed. Order meta is never deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

foreach ( array( 'rwsc_dash_1', 'rwsc_dash_7', 'rwsc_dash_30', 'rwsc_dash_90', 'rwsc_ready_count', 'rwsc_no_weight', 'rwsc_backfill_lock', 'rwsc_stats', 'rwsc_suite_summary' ) as $rwsc_transient ) {
    delete_transient( $rwsc_transient );
}

wp_clear_scheduled_hook( 'rwsc_sync_statuses' );
wp_clear_scheduled_hook( 'rwsc_auto_send_order' );
wp_clear_scheduled_hook( 'rwsc_dispatch_backfill_batch' );

$rwsc_api = get_option( 'rwsc_api', array() );
if ( is_array( $rwsc_api ) && isset( $rwsc_api['delete_data_on_uninstall'] ) && 'yes' === $rwsc_api['delete_data_on_uninstall'] ) {
    foreach ( array( 'rwsc_api', 'rwsc_pathao_token', 'rwsc_suite_imported', 'rwsc_dispatch_backfill_done', 'rwsc_dispatch_backfill_cursor', 'rwsc_last_webhook', 'rwsc_last_webhook_sf', 'rwsc_last_sync_run' ) as $rwsc_option ) {
        delete_option( $rwsc_option );
    }
}
