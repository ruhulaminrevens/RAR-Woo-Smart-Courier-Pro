<?php
/**
 * Customer-facing tracking: My Account / thank-you order details and emails.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Dispatch_Frontend {

	public static function init() {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email' ), 20, 4 );
	}

	private static function parcel_rows( WC_Order $order ) {
		$rows = RWSC_Couriers::rows( $order );
		// The Pro delivery card/email already renders the mirrored parcel.
		// Show only extra (e.g. legacy) consignments to avoid duplicate blocks.
		$shipment = RWSC_Shipments::get( $order );
		if ( '' !== (string) $shipment['tracking'] ) {
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $shipment ) {
				return (string) $row['id'] !== (string) $shipment['tracking'];
			} ) );
		}
		return $rows;
	}

	private static function block( WC_Order $order ) {
		$rows = self::parcel_rows( $order );
		if ( ! $rows ) {
			return '';
		}
		$out = '<h2>' . esc_html__( 'Courier tracking', 'rar-woo-smart-courier' ) . '</h2>';
		foreach ( $rows as $r ) {
			$out .= '<p>' . esc_html__( 'Courier:', 'rar-woo-smart-courier' ) . ' <strong>' . esc_html( $r['courier'] ) . '</strong><br>'
				. esc_html__( 'Consignment ID:', 'rar-woo-smart-courier' ) . ' <strong>' . esc_html( $r['id'] ) . '</strong><br>'
				. esc_html__( 'Status:', 'rar-woo-smart-courier' ) . ' <strong>' . esc_html( $r['status'] ) . '</strong>';
			if ( ! empty( $r['url'] ) ) {
				$out .= '<br><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Track your parcel', 'rar-woo-smart-courier' ) . '</a>';
			}
			$out .= '</p>';
		}
		return $out;
	}

	public static function order_details( $order ) {
		if ( ! RWSC_Api_Settings::yes( 'show_my_account' ) || ! $order instanceof WC_Order ) {
			return;
		}
		$block = self::block( $order );
		if ( '' !== $block ) {
			echo '<section class="rwsc-d-tracking">' . $block . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! RWSC_Api_Settings::yes( 'show_in_emails' ) || ! $order instanceof WC_Order ) {
			return;
		}
		if ( $plain_text ) {
			foreach ( self::parcel_rows( $order ) as $r ) {
				echo "\n" . esc_html( sprintf( '%s consignment: %s', $r['courier'], $r['id'] ) ) . "\n" . ( ! empty( $r['url'] ) ? esc_url( $r['url'] ) . "\n" : '' );
			}
			return;
		}
		echo self::block( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
