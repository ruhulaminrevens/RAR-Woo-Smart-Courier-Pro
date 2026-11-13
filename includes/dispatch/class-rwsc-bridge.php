<?php
/**
 * Bridge between the API dispatch engine (Pathao / RedX / SteadFast / Paperfly /
 * Sundarban) and the Smart Courier shipment model (dispatch board, labels,
 * manifests, reports, tracking shortcode). Every booking or status change made
 * by the engine is mirrored into the `_rwsc_*` shipment meta, so the board,
 * dashboard and customer tracking always agree with the courier's real state.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Bridge {

	/** @var bool Re-entrancy guard. */
	private static $busy = false;

	public static function init() {
		add_action( 'rpc_courier_sent', array( __CLASS__, 'on_courier_event' ), 20, 2 );
		add_action( 'rpc_courier_status_changed', array( __CLASS__, 'on_courier_event' ), 20, 2 );
		add_action( 'rpc_status_changed', array( __CLASS__, 'on_pathao_status' ), 20, 1 );
		add_action( 'pathao_after_send_order', array( __CLASS__, 'on_pathao_sent' ), 20, 3 );
		add_action( 'rwsc_dispatch_backfill_batch', array( __CLASS__, 'run_backfill' ) );
	}

	/** Engine group/slug => shipment status. */
	public static function map_status( $group, $slug = '' ) {
		switch ( (string) $group ) {
			case 'delivered':
				return 'delivered';
			case 'returned':
				return 'returned';
			case 'attention':
				return 'hold';
			case 'transit':
				return in_array( (string) $slug, array( 'picked', 'picked_up', 'pickup_done' ), true ) ? 'picked' : 'in_transit';
			default:
				return 'booked';
		}
	}

	/** @param string $slug Courier slug. @param WC_Order $order Order. */
	public static function on_courier_event( $slug, $order ) {
		unset( $slug );
		if ( $order instanceof WC_Order ) {
			self::sync( $order );
		}
	}

	public static function on_pathao_status( $order ) {
		if ( $order instanceof WC_Order ) {
			self::sync( $order );
		}
	}

	public static function on_pathao_sent( $res, $payload, $order ) {
		unset( $res, $payload );
		if ( $order instanceof WC_Order ) {
			self::sync( $order );
		}
	}

	/**
	 * Mirror the engine's parcel state into the shipment model.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $args   notify (null = follow the Pro setting, false = silent).
	 * @return bool True when the shipment changed.
	 */
	public static function sync( WC_Order $order, array $args = array() ) {
		if ( self::$busy || ! class_exists( 'RWSC_Shipments' ) ) {
			return false;
		}
		$info = RWSC_Couriers::parcel_info( $order );
		if ( ! $info || '' === (string) $info['id'] || '-' === (string) $info['id'] ) {
			return false;
		}
		$changes = array(
			'courier'  => $info['courier'],
			'tracking' => $info['id'],
			'status'   => self::map_status( $info['group'], RWSC_Pathao_Status::slug( $info['raw'] ) ),
		);
		// A manual shipment-board edit to "delivered/returned" must not be undone by a stale sync.
		$cur = (string) $order->get_meta( '_rwsc_status', true );
		if ( in_array( $cur, array( 'delivered', 'returned' ), true ) && $cur !== $changes['status'] ) {
			// Never silently reverse an already terminal board state.
			unset( $changes['status'] );
		}
		$before     = RWSC_Shipments::get( $order );
		self::$busy = true;
		try {
			$r = RWSC_Shipments::update( $order, $changes, wp_parse_args( $args, array( 'notify' => null, 'save' => true, 'source' => 'api' ) ) );
		} finally {
			self::$busy = false;
		}
		if ( is_wp_error( $r ) ) {
			RWSC_Logger::debug( 'Bridge sync failed: ' . $r->get_error_message(), array( 'order' => $order->get_id() ) );
			return false;
		}
		foreach ( array( 'courier', 'tracking', 'status' ) as $k ) {
			if ( ( $before[ $k ] ?? '' ) !== ( $r[ $k ] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Push existing engine parcels (e.g. imported from RAR Courier Suite) into the
	 * shipment model. Silent: customers get no notification for old parcels.
	 *
	 * @param int $limit Orders per run.
	 * @return array array( scanned, synced )
	 */
	/**
	 * Resume the legacy parcel import in bounded pages. Never declare completion
	 * after an arbitrary first 300 orders; stores may have years of parcels.
	 * Cursor persists per courier so PHP timeouts do not restart the scan.
	 *
	 * @return array scanned, synced, complete
	 */
	public static function backfill( $limit = 300 ) {
		$size     = 100;
		$pages    = max( 1, min( 10, (int) ceil( (int) $limit / $size ) ) );
		$state    = get_option( 'rwsc_dispatch_backfill_cursor', array() );
		$index    = max( 0, (int) ( $state['courier'] ?? 0 ) );
		$page     = max( 1, (int) ( $state['page'] ?? 1 ) );
		$slugs    = array_values( RWSC_Couriers::slugs() );
		$scanned  = 0;
		$synced   = 0;
		$run      = 0;

		while ( $index < count( $slugs ) && $run < $pages ) {
			$key = RWSC_Couriers::id_key( $slugs[ $index ] );
			if ( '' === $key ) {
				++$index;
				$page = 1;
				continue;
			}
			$ids = wc_get_orders( array(
				'limit'        => $size,
				'paged'        => $page,
				'return'       => 'ids',
				'type'         => 'shop_order',
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => '!=',
				'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			if ( ! is_array( $ids ) ) {
				// Failed queries must never advance the cursor.
				break;
			}
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				++$scanned;
				if ( '' !== (string) $order->get_meta( '_rwsc_tracking', true )
					&& '' !== (string) $order->get_meta( '_rwsc_status', true ) ) {
					continue;
				}
				if ( self::sync( $order, array( 'notify' => false ) ) ) {
					++$synced;
				}
			}
			++$run;
			if ( count( $ids ) < $size ) {
				++$index;
				$page = 1;
			} else {
				++$page;
			}
		}

		$complete = $index >= count( $slugs );
		if ( $complete ) {
			delete_option( 'rwsc_dispatch_backfill_cursor' );
			update_option( 'rwsc_dispatch_backfill_done', time(), false );
		} else {
			update_option( 'rwsc_dispatch_backfill_cursor', array( 'courier' => $index, 'page' => $page ), false );
			if ( ! wp_next_scheduled( 'rwsc_dispatch_backfill_batch' ) ) {
				wp_schedule_single_event( time() + 60, 'rwsc_dispatch_backfill_batch' );
			}
		}
		return array( 'scanned' => $scanned, 'synced' => $synced, 'complete' => $complete );
	}

	/** Background continuation when WP-Cron runs. */
	public static function run_backfill() {
		if ( ! get_option( 'rwsc_dispatch_backfill_done' ) ) {
			self::backfill( 300 );
		}
	}
}
