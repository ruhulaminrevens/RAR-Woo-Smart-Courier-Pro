<?php
/**
 * Background work: periodic status sync (safety net when webhooks fail) and
 * optional auto-dispatch when an order reaches a chosen status.
 *
 * Works with Hostinger system cron (recommended) or WP-Cron.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Cron {

	const HOOK_SYNC = 'rwsc_sync_statuses';
	const HOOK_AUTO = 'rwsc_auto_send_order';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK_SYNC, array( __CLASS__, 'sync' ) );
		add_action( self::HOOK_AUTO, array( 'RWSC_Pathao_Orders', 'auto_send' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_queue_auto_send' ), 20, 3 );
	}

	public static function schedules( $schedules ) {
		$schedules['rwsc_30min'] = array(
			'interval' => 1800,
			'display'  => __( 'Every 30 minutes', 'rar-woo-smart-courier' ),
		);
		return $schedules;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK_SYNC ) ) {
			wp_schedule_event( time() + 300, 'rwsc_30min', self::HOOK_SYNC );
		}
	}

	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK_SYNC );
		wp_clear_scheduled_hook( self::HOOK_AUTO );
	}

	public static function maybe_queue_auto_send( $order_id, $from, $to ) {
		$target = (string) RWSC_Api_Settings::get( 'auto_send_status' );
		if ( '' === $target || 'wc-' . $to !== $target ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || '' !== RWSC_Couriers::sent_via( $order ) || '' === RWSC_Couriers::pick_for_auto( $order ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_AUTO, array( (int) $order_id ), 'rar-woo-smart-courier' ) ) {
				return;
			}
			as_enqueue_async_action( self::HOOK_AUTO, array( (int) $order_id ), 'rar-woo-smart-courier' );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_AUTO, array( (int) $order_id ) ) ) {
			wp_schedule_single_event( time() + 20, self::HOOK_AUTO, array( (int) $order_id ) );
		}
	}

	/**
	 * Pull status for open consignments, oldest first, within a time budget.
	 */
	public static function sync() {
		if ( ! RWSC_Api_Settings::yes( 'sync_enabled' ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		update_option( 'rwsc_last_sync_run', time(), false );
		$deadline = microtime( true ) + 25;
		if ( ! RWSC_Api_Settings::is_configured() ) {
			RWSC_Couriers::sync_all( $deadline );
			return;
		}

		$days  = (int) RWSC_Api_Settings::get( 'sync_max_age_days', 45 );
		$batch = (int) RWSC_Api_Settings::get( 'sync_batch', 25 );
		$base = array(
			'limit'        => $batch,
			'return'       => 'ids',
			'type'         => 'shop_order',
			'status'       => array_keys( wc_get_order_statuses() ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
		);
		$core = array(
			array(
				'key'     => RWSC_Pathao_Orders::META_ID,
				'value'   => array( '', RWSC_Pathao_Orders::EMPTY_FLAG ),
				'compare' => 'NOT IN',
			),
			array(
				'key'     => 'ptc_final',
				'compare' => 'NOT EXISTS',
			),
		);
		// Two flat queries (never synced, then stale) behave identically on HPOS and legacy storage.
		$never = RWSC_Pathao_Orders::query( array_merge( $base, array( 'meta_query' => array_merge( array( 'relation' => 'AND' ), $core, array( array( 'key' => 'ptc_last_sync', 'compare' => 'NOT EXISTS' ) ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$ids   = $never;
		if ( count( $ids ) < $batch ) {
			$stale = RWSC_Pathao_Orders::query(
				array_merge(
					$base,
					array(
						'limit'      => $batch - count( $ids ),
						'meta_query' => array_merge( array( 'relation' => 'AND' ), $core, array( array( 'key' => 'ptc_last_sync', 'value' => time() - 1800, 'type' => 'NUMERIC', 'compare' => '<' ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				)
			);
			$ids   = array_values( array_unique( array_merge( $ids, $stale ) ) );
		}

		$done = 0;
		foreach ( $ids as $id ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$res = RWSC_Pathao_Orders::refresh( $order, 'sync' );
			if ( is_wp_error( $res ) ) {
				$order->update_meta_data( 'ptc_last_sync', time() ); // Do not hammer a failing consignment.
				$order->save_meta_data();
				if ( in_array( $res->get_error_code(), array( 'rwsc_no_credentials', 'rwsc_token_busy' ), true ) || 0 === strpos( (string) $res->get_error_code(), 'rwsc_transport' ) ) {
					RWSC_Logger::error( 'Sync aborted: ' . $res->get_error_message() );
					break;
				}
			}
			++$done;
		}
		RWSC_Couriers::sync_all( $deadline );
		RWSC_Logger::debug( 'Sync finished', array( 'checked' => $done, 'candidates' => count( $ids ) ) );
	}
}
