<?php
/**
 * Pathao status registry: normalisation, labels, tones, final states.
 * Pathao's own status string is stored untouched in the `ptc_status` meta
 * (same as the original plugin); slugs are derived on read.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Pathao_Status {

	/** slug => array( label, tone, final, group ) */
	private static function map() {
		return array(
			'pending'                   => array( 'Pending', 'info', false, 'pending' ),
			'order_created'             => array( 'Order created', 'info', false, 'pending' ),
			'order_updated'             => array( 'Order updated', 'info', false, 'pending' ),
			'pickup_requested'          => array( 'Pickup requested', 'info', false, 'pending' ),
			'assigned_for_pickup'       => array( 'Assigned for pickup', 'info', false, 'pending' ),
			'picked'                    => array( 'Picked up', 'info', false, 'transit' ),
			'pickup_failed'             => array( 'Pickup failed', 'danger', false, 'attention' ),
			'pickup_cancelled'          => array( 'Pickup cancelled', 'danger', true, 'attention' ),
			'at_the_sorting_hub'        => array( 'At sorting hub', 'info', false, 'transit' ),
			'in_transit'                => array( 'In transit', 'info', false, 'transit' ),
			'received_at_last_mile_hub' => array( 'At last-mile hub', 'info', false, 'transit' ),
			'assigned_for_delivery'     => array( 'Out for delivery', 'info', false, 'transit' ),
			'delivered'                 => array( 'Delivered', 'success', true, 'delivered' ),
			'partial_delivery'          => array( 'Partially delivered', 'warning', true, 'delivered' ),
			'returned'                  => array( 'Returned', 'danger', true, 'returned' ),
			'delivery_failed'           => array( 'Delivery failed', 'danger', false, 'attention' ),
			'on_hold'                   => array( 'On hold', 'warning', false, 'attention' ),
			'paid_return'               => array( 'Paid return', 'warning', true, 'returned' ),
			'exchanged'                 => array( 'Exchanged', 'warning', true, 'returned' ),
			'payment_invoice'           => array( 'Payment invoiced', 'success', false, 'delivered' ),
			'queued'                    => array( 'Queued (bulk)', 'neutral', false, 'pending' ),
		);
	}

	private static function aliases() {
		return array(
			'return'    => 'returned',
			'exchange'  => 'exchanged',
			'paid'      => 'payment_invoice',
			'picked_up' => 'picked',
		);
	}

	/**
	 * "Pickup Requested", "Pickup_Requested", "order.pickup-requested" => "pickup_requested".
	 */
	public static function slug( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$raw = preg_replace( '/^order\./', '', $raw );
		$raw = preg_replace( '/[^a-z0-9]+/', '_', $raw );
		$raw = trim( $raw, '_' );
		$al  = self::aliases();
		return $al[ $raw ] ?? $raw;
	}

	public static function info( $raw ) {
		$slug = self::slug( $raw );
		$map  = self::map();
		if ( isset( $map[ $slug ] ) ) {
			$m = $map[ $slug ];
			return array(
				'slug'  => $slug,
				'label' => $m[0],
				'tone'  => $m[1],
				'final' => $m[2],
				'group' => $m[3],
			);
		}
		return array(
			'slug'  => $slug,
			'label' => '' === $slug ? '—' : ucfirst( str_replace( '_', ' ', $slug ) ),
			'tone'  => 'neutral',
			'final' => false,
			'group' => 'pending',
		);
	}

	public static function groups() {
		return array(
			'all'       => __( 'All sent', 'rar-woo-smart-courier' ),
			'unsent'    => __( 'Not sent yet', 'rar-woo-smart-courier' ),
			'pending'   => __( 'Pending / pickup', 'rar-woo-smart-courier' ),
			'transit'   => __( 'In transit', 'rar-woo-smart-courier' ),
			'delivered' => __( 'Delivered', 'rar-woo-smart-courier' ),
			'returned'  => __( 'Returned', 'rar-woo-smart-courier' ),
			'attention' => __( 'Needs attention', 'rar-woo-smart-courier' ),
		);
	}

	/**
	 * Raw status strings (all spellings we may have stored) belonging to a group.
	 */
	public static function raw_values_for_group( $group ) {
		$out = array();
		foreach ( self::map() as $slug => $m ) {
			if ( $m[3] === $group ) {
				$out[] = $slug;
				$out[] = ucfirst( $slug );
				$out[] = implode( '_', array_map( 'ucfirst', explode( '_', $slug ) ) );
				$out[] = ucwords( str_replace( '_', ' ', $slug ) );
			}
		}
		if ( 'returned' === $group ) {
			$out = array_merge( $out, array( 'Return', 'return', 'exchange', 'Exchange' ) );
		}
		return array_values( array_unique( $out ) );
	}

	public static function badge( $raw ) {
		$i = self::info( $raw );
		return sprintf( '<span class="rwsc-d-badge rwsc-d-tone-%s">%s</span>', esc_attr( $i['tone'] ), esc_html( $i['label'] ) );
	}
}
