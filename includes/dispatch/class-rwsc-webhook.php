<?php
/**
 * Pathao webhook receiver. Same URL as the official plugin
 * (/wp-json/ptc/v1/webhook) so the merchant-panel setting keeps working.
 *
 * Hardening vs original: constant-time signature check, idempotency,
 * out-of-order protection (via RWSC_Pathao_Orders::apply_status), no wp_send_json_*
 * misuse inside REST callbacks, unknown orders acknowledged instead of 404
 * (prevents retry storms), consignment cross-check.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Webhook {

	/** Event key => status string (kept from the original plugin). */
	private static $events = array(
		'order.created'                   => 'Order_Created',
		'order.updated'                   => 'Order_Updated',
		'order.pickup-requested'          => 'Pickup_Requested',
		'order.assigned-for-pickup'       => 'Assigned_for_Pickup',
		'order.picked'                    => 'Picked',
		'order.pickup-failed'             => 'Pickup_Failed',
		'order.pickup-cancelled'          => 'Pickup_Cancelled',
		'order.at-the-sorting-hub'        => 'At_the_Sorting_HUB',
		'order.in-transit'                => 'In_Transit',
		'order.received-at-last-mile-hub' => 'Received_at_Last_Mile_HUB',
		'order.assigned-for-delivery'     => 'Assigned_for_Delivery',
		'order.delivered'                 => 'Delivered',
		'order.partial-delivery'          => 'Partial_Delivery',
		'order.returned'                  => 'Return',
		'order.delivery-failed'           => 'Delivery_Failed',
		'order.on-hold'                   => 'On_Hold',
		'order.paid-return'               => 'paid_return',
		'order.exchanged'                 => 'exchange',
		'order.paid'                      => 'Payment_Invoice',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function url() {
		return rest_url( 'ptc/v1/webhook' );
	}

	public static function register() {
		register_rest_route(
			'ptc/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);
	}

	public static function authorize( WP_REST_Request $request ) {
		$given  = (string) $request->get_header( 'x_pathao_signature' );
		$secret = RWSC_Api_Settings::webhook_secret();
		if ( '' === $given || '' === $secret ) {
			return false;
		}
		return hash_equals( $secret, $given );
	}

	private static function respond( $message, $status = 202 ) {
		$response = new WP_REST_Response(
			array(
				'status'  => $status,
				'message' => $message,
				'data'    => null,
			),
			$status
		);
		// Value required by Pathao's webhook-integration handshake.
		$response->header( 'X-Pathao-Merchant-Webhook-Integration-Secret', 'f3992ecc-59da-4cbe-a049-a13da2018d51' );
		return $response;
	}

	public static function handle( WP_REST_Request $request ) {
		$p     = $request->get_json_params();
		$p     = is_array( $p ) ? $p : $request->get_params();
		$event = isset( $p['event'] ) ? sanitize_text_field( (string) $p['event'] ) : '';

		if ( 'webhook_integration' === $event ) {
			return self::respond( 'Successfully accepted webhook_integration', 202 );
		}

		update_option( 'rwsc_last_webhook', time(), false );

		$merchant_order_id = isset( $p['merchant_order_id'] ) ? sanitize_text_field( (string) $p['merchant_order_id'] ) : '';
		$consignment       = isset( $p['consignment_id'] ) ? sanitize_text_field( (string) $p['consignment_id'] ) : '';
		$status            = isset( $p['order_status'] ) ? sanitize_text_field( (string) $p['order_status'] ) : '';
		if ( '' === $status && isset( self::$events[ $event ] ) ) {
			$status = self::$events[ $event ];
		}
		if ( '' === $status ) {
			RWSC_Logger::debug( 'Webhook without usable status', array( 'event' => $event ) );
			return self::respond( 'No status in payload', 202 );
		}

		// Idempotency: Pathao may deliver the same event more than once.
		$ts   = isset( $p['updated_at'] ) ? (int) strtotime( (string) $p['updated_at'] . ' +06:00' ) : 0;
		$dedupe = 'rwsc_wh_' . md5( $consignment . '|' . $merchant_order_id . '|' . $status . '|' . $event . '|' . ( $p['updated_at'] ?? '' ) );
		if ( get_transient( $dedupe ) ) {
			return self::respond( 'Duplicate event ignored', 202 );
		}
		set_transient( $dedupe, 1, 10 * MINUTE_IN_SECONDS );

		$order = null;
		if ( ctype_digit( $merchant_order_id ) ) {
			$o = wc_get_order( (int) $merchant_order_id );
			if ( $o instanceof WC_Order ) {
				$order = $o;
			}
		}
		if ( ! $order && '' !== $consignment ) {
			$order = RWSC_Pathao_Orders::find_by_consignment( $consignment );
		}
		if ( ! $order ) {
			RWSC_Logger::debug( 'Webhook for unknown order', array( 'merchant_order_id' => $merchant_order_id, 'consignment_id' => $consignment ) );
			return self::respond( 'Order not found, ignored', 202 ); // Not 404: avoids retry storms for foreign orders.
		}

		// Never let a colliding merchant_order_id overwrite another parcel.
		$existing = RWSC_Pathao_Orders::consignment( $order );
		if ( '' !== $consignment && '' !== $existing && RWSC_Pathao_Orders::EMPTY_FLAG !== $existing && $existing !== $consignment ) {
			RWSC_Logger::error( 'Webhook consignment mismatch for order ' . $order->get_id(), array( 'stored' => $existing, 'received' => $consignment ) );
			return self::respond( 'Consignment mismatch, ignored', 202 );
		}

		RWSC_Pathao_Orders::apply_status(
			$order,
			$status,
			array(
				'consignment_id' => $consignment,
				'delivery_fee'   => $p['delivery_fee'] ?? null,
				'reason'         => $p['reason'] ?? ( $p['note'] ?? '' ),
				'collected'      => $p['collected_amount'] ?? '',
				'ts'             => $ts,
			),
			'webhook'
		);

		return self::respond( 'Order status updated', 202 );
	}
}
