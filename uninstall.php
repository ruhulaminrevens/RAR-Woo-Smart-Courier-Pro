<?php
/**
 * RAR Woo Smart Courier uninstall handler.
 *
 * Settings (rwsc_settings) and order shipment data (_rwsc_* order meta) are
 * intentionally preserved so that temporarily removing or reinstalling the
 * plugin never loses courier, tracking or delivery history. Only short-lived
 * caches are removed.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

foreach ( array( 'rwsc_dash_1', 'rwsc_dash_7', 'rwsc_dash_30', 'rwsc_dash_90', 'rwsc_ready_count', 'rwsc_no_weight', 'rwsc_backfill_lock' ) as $rwsc_transient ) {
    delete_transient( $rwsc_transient );
}
