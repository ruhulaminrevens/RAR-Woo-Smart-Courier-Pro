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
	 * The reference customers track by. SteadFast links use the tracking code, not the consignment id.
	 */
	public static function tracking_value( WC_Order $order, array $info ) {
		if ( 'steadfast' === $info['courier'] ) {
			$ref = (string) $order->get_meta( 'sfc_ref', true );
			if ( '' !== $ref ) {
				return $ref;
			}
		}
		return (string) $info['id'];
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
			'tracking' => self::tracking_value( $order, $info ),
			'status'   => self::map_status( $info['group'], RWSC_Pathao_Status::slug( $info['raw'] ) ),
		);
		// The courier that really holds the parcel wins over the checkout choice. It is set directly
		// (no re-quote), so an unusual address or weight can never block the tracking/status mirror.
		if ( (string) $order->get_meta( '_rwsc_courier', true ) !== $info['courier'] ) {
			$name = RWSC_Settings::courier_name( $info['courier'] );
			$order->update_meta_data( '_rwsc_courier', $info['courier'] );
			$order->update_meta_data( '_rwsc_selected_courier', '' !== $name ? $name : RWSC_Couriers::label( $info['courier'] ) );
			/* translators: %s: courier name */
			RWSC_Shipments::log( $order, (string) $order->get_meta( '_rwsc_status', true ), sprintf( __( 'Courier set to %s', 'rar-woo-smart-courier' ), RWSC_Couriers::label( $info['courier'] ) ) );
		}
		// A manual shipment-board edit to "delivered/returned" must not be undone by a stale sync.
		$cur = (string) $order->get_meta( '_rwsc_status', true );
		if ( in_array( $cur, array( 'delivered', 'returned' ), true ) && ! $info['final'] ) {
			unset( $changes['status'] );
		}
		$before     = RWSC_Shipments::get( $order );
		self::$busy = true;
		$r          = RWSC_Shipments::update( $order, $changes, wp_parse_args( $args, array( 'notify' => null, 'save' => true ) ) );
		self::$busy = false;
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
	 * @param int $limit     Orders per page.
	 * @param int $max_pages Pages per courier (bounded, 20 s time budget).
	 * @return array array( scanned, synced )
	 */
	public static function backfill( $limit = 300, $max_pages = 6 ) {
		$scanned  = 0;
		$synced   = 0;
		$seen     = array();
		$deadline = microtime( true ) + 20;
		foreach ( RWSC_Couriers::slugs() as $slug ) {
			$key = RWSC_Couriers::id_key( $slug );
			if ( '' === $key ) {
				continue;
			}
			for ( $page = 1; $page <= (int) $max_pages && microtime( true ) < $deadline; $page++ ) {
				$ids = wc_get_orders(
					array(
						'limit'        => (int) $limit,
						'paged'        => $page,
						'return'       => 'ids',
						'type'         => 'shop_order',
						'orderby'      => 'date',
						'order'        => 'DESC',
						'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_compare' => '!=',
						'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				if ( empty( $ids ) ) {
					break;
				}
				foreach ( (array) $ids as $id ) {
					if ( isset( $seen[ $id ] ) ) {
						continue;
					}
					$seen[ $id ] = true;
					$order       = wc_get_order( $id );
					if ( ! $order ) {
						continue;
					}
					++$scanned;
					if ( '' !== (string) $order->get_meta( '_rwsc_tracking', true ) && '' !== (string) $order->get_meta( '_rwsc_status', true ) ) {
						continue;
					}
					if ( self::sync( $order, array( 'notify' => false ) ) ) {
						++$synced;
					}
				}
				if ( count( $ids ) < (int) $limit ) {
					break;
				}
			}
		}
		return array( 'scanned' => $scanned, 'synced' => $synced );
	}
}
