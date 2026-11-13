<?php
/**
 * Admin AJAX endpoints. Every endpoint checks a nonce AND a capability
 * (the original plugin exposed its location/store endpoints to any logged-in user).
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Dispatch_Ajax {

	const NONCE = 'rwsc_dispatch';

	public static function init( $read_only = false ) {
		// When Suite or a vendor plugin still owns dispatch, allow only non-
		// mutating connection/lookup checks. Never register send/refresh handlers.
		$actions = $read_only
			? array( 'locations', 'price', 'test', 'courier_test' )
			: array( 'prepare', 'locations', 'price', 'send', 'send_native', 'refresh', 'test', 'reset_token', 'flush_cache', 'courier_send', 'courier_refresh', 'courier_test', 'courier_status' );
		foreach ( $actions as $a ) {
			add_action( 'wp_ajax_rwsc_d_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function operator_cap() {
		return apply_filters( 'rpc_operator_cap', 'manage_woocommerce' );
	}

	private static function guard( $settings_cap = false ) {
		check_ajax_referer( self::NONCE, 'nonce' );
		$cap = $settings_cap ? 'manage_options' : self::operator_cap();
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'rar-woo-smart-courier' ) ), 403 );
		}
	}

	private static function fail( WP_Error $e, $status = 400 ) {
		$data   = $e->get_error_data();
		$code   = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : $status;
		$errors = is_array( $data ) && ! empty( $data['errors'] ) ? $data['errors'] : array();
		wp_send_json_error(
			array(
				'message'   => $e->get_error_message(),
				'code'      => $e->get_error_code(),
				'errors'    => $errors,
				'uncertain' => is_array( $data ) && ! empty( $data['uncertain'] ),
			),
			$code >= 400 && $code < 600 ? $code : 400
		);
	}

	private static function post_ids( $key ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
	}

	/** Whitelist + sanitise the editable fields from the modal. */
	private static function clean_fields( $raw ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$out  = array();
		$text = array( 'recipient_name', 'recipient_phone', 'recipient_secondary_phone', 'recipient_address', 'item_description', 'special_instruction' );
		foreach ( $text as $k ) {
			$out[ $k ] = isset( $raw[ $k ] ) ? sanitize_textarea_field( (string) $raw[ $k ] ) : '';
		}
		foreach ( array( 'recipient_city', 'recipient_zone', 'recipient_area', 'store_id', 'delivery_type', 'item_type', 'item_quantity' ) as $k ) {
			$out[ $k ] = isset( $raw[ $k ] ) ? absint( $raw[ $k ] ) : 0;
		}
		$out['item_weight']       = isset( $raw['item_weight'] ) ? (float) $raw['item_weight'] : 0;
		$out['amount_to_collect'] = isset( $raw['amount_to_collect'] ) && '' !== $raw['amount_to_collect'] ? (float) $raw['amount_to_collect'] : '';
		return $out;
	}

	/* ------------------------------------------------------------------ */

	public static function handle_prepare() {
		self::guard();
		if ( ! RWSC_Api_Settings::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Pathao is not configured. Add your API credentials under Smart Courier → Courier APIs → Pathao.', 'rar-woo-smart-courier' ) ), 400 );
		}
		$ids = array_slice( self::post_ids( 'order_ids' ), 0, 100 );
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'No orders selected.', 'rar-woo-smart-courier' ) ), 400 );
		}

		$stores = RWSC_Pathao_API::stores();
		if ( is_wp_error( $stores ) ) {
			self::fail( $stores, 502 );
		}
		$stores = is_array( $stores ) ? $stores : array();
		$active = array_values(
			array_filter(
				$stores,
				static function ( $s ) {
					return ! empty( $s['active'] );
				}
			)
		);
		$default_store = RWSC_Pathao_Orders::default_store_id( $stores );

		$cities = RWSC_Pathao_API::cities();
		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$d = RWSC_Order_Data::build( $order );
			if ( empty( $d['fields']['store_id'] ) ) {
				$d['fields']['store_id'] = $default_store;
			}
			$orders[] = $d;
		}

		wp_send_json_success(
			array(
				'orders'        => $orders,
				'stores'        => $active ? $active : $stores,
				'default_store' => $default_store,
				'cities'        => is_wp_error( $cities ) ? array() : $cities,
				'guard'         => RWSC_Api_Settings::yes( 'courier_guard' ),
				'bulk_mode'     => RWSC_Api_Settings::get( 'bulk_mode' ),
				'location_mode' => RWSC_Api_Settings::get( 'location_mode' ),
			)
		);
	}

	public static function handle_locations() {
		self::guard();
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id || ! in_array( $type, array( 'zones', 'areas' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Bad request' ), 400 );
		}
		$list = 'zones' === $type ? RWSC_Pathao_API::zones( $id ) : RWSC_Pathao_API::areas( $id );
		if ( is_wp_error( $list ) ) {
			self::fail( $list, 502 );
		}
		wp_send_json_success( array( 'items' => is_array( $list ) ? $list : array() ) );
	}

	public static function handle_price() {
		self::guard();
		$a = array(
			'store_id'       => absint( $_POST['store_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification
			'item_type'      => absint( $_POST['item_type'] ?? 2 ), // phpcs:ignore WordPress.Security.NonceVerification
			'delivery_type'  => absint( $_POST['delivery_type'] ?? 48 ), // phpcs:ignore WordPress.Security.NonceVerification
			'item_weight'    => (float) ( $_POST['item_weight'] ?? 0.5 ), // phpcs:ignore WordPress.Security.NonceVerification
			'recipient_city' => absint( $_POST['recipient_city'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification
			'recipient_zone' => absint( $_POST['recipient_zone'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification
		);
		if ( ! $a['store_id'] || ! $a['recipient_city'] || ! $a['recipient_zone'] ) {
			wp_send_json_error( array( 'message' => __( 'City and zone are needed for an estimate.', 'rar-woo-smart-courier' ) ), 400 );
		}
		$a['item_weight'] = min( 10, max( 0.5, $a['item_weight'] ) );
		$p                = RWSC_Pathao_API::price( $a );
		if ( is_wp_error( $p ) ) {
			self::fail( $p, 502 );
		}
		wp_send_json_success( is_array( $p ) ? $p : array() );
	}

	public static function handle_send() {
		self::guard();
		$order_id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$fields   = self::clean_fields( isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array() ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$force    = ! empty( $_POST['force'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification

		$res = RWSC_Pathao_Orders::send( $order_id, $fields, get_current_user_id(), array( 'force' => $force ) );
		if ( is_wp_error( $res ) ) {
			self::fail( $res );
		}
		$order = wc_get_order( $order_id );
		wp_send_json_success(
			array(
				'order_id'       => $order_id,
				'consignment_id' => $res['consignment_id'],
				'delivery_fee'   => $res['delivery_fee'],
				'status'         => $res['status'],
				'cell'           => $order ? RWSC_Pathao_Orders::cell_html( $order ) : '',
				'panel_url'      => RWSC_Pathao_Orders::panel_url( $res['consignment_id'] ),
			)
		);
	}

	public static function handle_send_native() {
		self::guard();
		$raw   = isset( $_POST['orders'] ) ? wp_unslash( $_POST['orders'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$items = array();
		if ( is_array( $raw ) ) {
			foreach ( array_slice( $raw, 0, 100, true ) as $oid => $fields ) {
				$items[ absint( $oid ) ] = self::clean_fields( $fields );
			}
		}
		$res = RWSC_Pathao_Orders::send_native_bulk( $items, get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			self::fail( $res );
		}
		$cells = array();
		foreach ( $res['accepted'] as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$cells[ $oid ] = RWSC_Pathao_Orders::cell_html( $o );
			}
		}
		$res['cells'] = $cells;
		wp_send_json_success( $res );
	}

	public static function handle_refresh() {
		self::guard();
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-smart-courier' ) ), 404 );
		}
		$res = RWSC_Pathao_Orders::refresh( $order, 'manual' );
		if ( is_wp_error( $res ) ) {
			self::fail( $res, 502 );
		}
		$order = wc_get_order( $order->get_id() );
		wp_send_json_success(
			array(
				'order_id' => $order->get_id(),
				'changed'  => $res['changed'],
				'cell'     => RWSC_Pathao_Orders::cell_html( $order ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * RedX / SteadFast
	 * ------------------------------------------------------------------ */

	private static function courier_from_post() {
		$slug = isset( $_POST['courier'] ) ? sanitize_key( wp_unslash( $_POST['courier'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$m    = RWSC_Couriers::get( $slug );
		if ( ! $m ) {
			wp_send_json_error( array( 'message' => __( 'Unknown courier.', 'rar-woo-smart-courier' ) ), 400 );
		}
		return $m;
	}

	public static function handle_courier_send() {
		self::guard();
		$m   = self::courier_from_post();
		$id  = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$ov = array();
		if ( $m->manual() ) {
			$ov['cn'] = RWSC_Sundarban::clean_cn( wp_unslash( $_POST['cn'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}
		$res = $m->send( $id, get_current_user_id(), $ov );
		if ( is_wp_error( $res ) ) {
			$order = wc_get_order( $id );
			$e     = $res->get_error_data();
			wp_send_json_error(
				array(
					'message'   => $res->get_error_message(),
					'code'      => $res->get_error_code(),
					'uncertain' => is_array( $e ) && ! empty( $e['uncertain'] ),
					'cell'      => $order ? RWSC_Couriers::cell_html( $order ) : '',
				),
				is_array( $e ) && ! empty( $e['status'] ) ? (int) $e['status'] : 400
			);
		}
		wp_send_json_success( array( 'order_id' => $id, 'consignment_id' => $res['consignment_id'], 'cell' => RWSC_Couriers::cell_html( wc_get_order( $id ) ) ) );
	}

	public static function handle_courier_refresh() {
		self::guard();
		$m     = self::courier_from_post();
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-smart-courier' ) ), 404 );
		}
		$res = $m->refresh( $order, 'manual' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 502 );
		}
		wp_send_json_success( array( 'order_id' => $order->get_id(), 'changed' => $res['changed'], 'cell' => RWSC_Couriers::cell_html( wc_get_order( $order->get_id() ) ) ) );
	}

	/** Manual couriers (Sundarban): staff set the parcel status. */
	public static function handle_courier_status() {
		self::guard();
		$m     = self::courier_from_post();
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $m->manual() || ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Not a manual courier or order not found.', 'rar-woo-smart-courier' ) ), 400 );
		}
		$res = $m->set_status( $order, sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'order_id' => $order->get_id(), 'cell' => RWSC_Couriers::cell_html( wc_get_order( $order->get_id() ) ) ) );
	}

	public static function handle_courier_test() {
		self::guard( true );
		$m = self::courier_from_post();
		$p = static function ( $k ) {
			return isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		};
		if ( 'redx' === $m->slug() ) {
			$res = $m->test( '' !== $p( 'redx_token' ) ? $p( 'redx_token' ) : null );
		} elseif ( 'paperfly' === $m->slug() ) {
			$res = $m->test( '' !== $p( 'pf_username' ) ? $p( 'pf_username' ) : null, '' !== $p( 'pf_password' ) ? $p( 'pf_password' ) : null, '' !== $p( 'pf_key' ) ? $p( 'pf_key' ) : null );
		} elseif ( 'sundarban' === $m->slug() ) {
			wp_send_json_error( array( 'message' => __( 'Sundarban has no API to test.', 'rar-woo-smart-courier' ) ), 400 );
		} else {
			$res = $m->test( '' !== $p( 'sf_api_key' ) ? $p( 'sf_api_key' ) : null, '' !== $p( 'sf_secret_key' ) ? $p( 'sf_secret_key' ) : null );
		}
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => $res ) );
	}

	/* ------------------------------------------------------------------ *
	 * Settings-level (manage_options)
	 * ------------------------------------------------------------------ */

	public static function handle_test() {
		self::guard( true );
		$args = array();
		foreach ( array( 'client_id', 'client_secret', 'username', 'password', 'environment', 'auth_mode' ) as $k ) {
			$v = isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' !== $v ) {
				$args[ $k ] = $v; // Blank means "use the saved value" (secrets are never echoed to the form).
			}
		}
		if ( isset( $args['environment'] ) && ! in_array( $args['environment'], array( 'live', 'staging' ), true ) ) {
			unset( $args['environment'] );
		}
		$tok = RWSC_Pathao_API::issue_token( $args );
		if ( is_wp_error( $tok ) ) {
			self::fail( $tok, 400 );
		}
		// Persist the working token only when testing the saved credentials.
		$tested_saved = ! array_intersect( array( 'client_id', 'client_secret', 'username', 'password', 'environment', 'auth_mode' ), array_keys( $args ) );
		if ( $tested_saved ) {
			update_option( RWSC_Pathao_API::TOKEN_OPTION, $tok, false );
		}
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: auth mode */
					__( 'Connected (%s authentication). Save settings to use these credentials.', 'rar-woo-smart-courier' ),
					$tok['mode']
				),
			)
		);
	}

	public static function handle_reset_token() {
		self::guard( true );
		delete_option( RWSC_Pathao_API::TOKEN_OPTION );
		$t = RWSC_Pathao_API::token( true );
		if ( is_wp_error( $t ) ) {
			self::fail( $t, 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Token renewed.', 'rar-woo-smart-courier' ) ) );
	}

	public static function handle_flush_cache() {
		self::guard( true );
		RWSC_Pathao_API::flush_cache();
		wp_send_json_success( array( 'message' => __( 'Cached stores, cities, zones and areas cleared.', 'rar-woo-smart-courier' ) ) );
	}
}
