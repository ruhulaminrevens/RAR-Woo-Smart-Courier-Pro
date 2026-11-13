<?php
/**
 * Builds, normalises and validates the Pathao payload for a WooCommerce order.
 *
 * Fixes over the original plugin:
 *  - weight is summed per line x quantity, converted to kg, clamped 0.5-10;
 *  - district is sent by name (not the raw BD-13 state code);
 *  - phone numbers accept Bangla digits / +88 / 880 prefixes;
 *  - collectable amount is advance-payment aware (RAR Woo Advance Payment);
 *  - only documented delivery/item types are offered;
 *  - RAR Woo Smart Courier choice is surfaced.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Order_Data {

	/* ------------------------------------------------------------------ *
	 * Compatibility helpers
	 * ------------------------------------------------------------------ */

	public static function smart_courier_active() {
		return class_exists( 'RAR_Woo_Smart_Courier' );
	}

	/**
	 * Courier name the customer picked at checkout (RAR Woo Smart Courier), or ''.
	 */
	public static function selected_courier( WC_Order $order ) {
		foreach ( array( '_rwsc_selected_courier', '_nabiad_courier' ) as $key ) {
			$v = trim( (string) $order->get_meta( $key, true ) );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * The courier a person picked (customer at checkout, or staff manually). A courier that
	 * Smart Courier merely auto-recommended for an admin / phone order is not a choice, so it
	 * never overrides the "default courier for automation" setting.
	 */
	public static function chosen_courier( WC_Order $order ) {
		if ( 'auto' === (string) $order->get_meta( '_rwsc_source', true ) ) {
			return '';
		}
		return self::selected_courier( $order );
	}

	/**
	 * True when the customer explicitly chose a courier other than Pathao.
	 */
	public static function chose_other_courier( WC_Order $order ) {
		$c = self::chosen_courier( $order );
		return '' !== $c && false === stripos( $c, 'pathao' );
	}

	/* ------------------------------------------------------------------ *
	 * Normalisers
	 * ------------------------------------------------------------------ */

	public static function normalize_phone( $raw ) {
		$raw = strtr(
			(string) $raw,
			array(
				'০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
				'৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
			)
		);
		$d = preg_replace( '/\D+/', '', $raw );
		if ( 0 === strpos( $d, '00880' ) ) {
			$d = substr( $d, 4 );
		}
		if ( 0 === strpos( $d, '880' ) && strlen( $d ) >= 13 ) {
			$d = substr( $d, 2 );
		} elseif ( 0 === strpos( $d, '88' ) && strlen( $d ) === 13 ) {
			$d = substr( $d, 2 );
		}
		if ( 10 === strlen( $d ) && '1' === $d[0] ) {
			$d = '0' . $d;
		}
		return $d;
	}

	private static function str_len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}

	private static function str_cut( $s, $len ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $s, 0, $len, 'UTF-8' ) : substr( (string) $s, 0, $len );
	}

	/* ------------------------------------------------------------------ *
	 * Field builders
	 * ------------------------------------------------------------------ */

	public static function recipient_name( WC_Order $order ) {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		return self::str_cut( $name, 100 );
	}

	public static function recipient_phone( WC_Order $order ) {
		$phone = method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '';
		if ( '' === trim( (string) $phone ) ) {
			$phone = $order->get_billing_phone();
		}
		return self::normalize_phone( $phone );
	}

	public static function recipient_address( WC_Order $order ) {
		$use_ship = '' !== trim( $order->get_shipping_address_1() );
		$a1       = $use_ship ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$a2       = $use_ship ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$city     = $use_ship ? $order->get_shipping_city() : $order->get_billing_city();
		$state    = $use_ship ? $order->get_shipping_state() : $order->get_billing_state();
		$zip      = $use_ship ? $order->get_shipping_postcode() : $order->get_billing_postcode();

		$parts = array();
		foreach ( array( $a1, $a2, $city, RWSC_Pathao_Locations::state_label( $state ), $zip ) as $p ) {
			$p = trim( (string) $p );
			if ( '' === $p ) {
				continue;
			}
			// Skip a part already contained in what we have (e.g. city repeated in the address).
			foreach ( $parts as $existing ) {
				if ( false !== stripos( $existing, $p ) ) {
					continue 2;
				}
			}
			$parts[] = $p;
		}
		return self::str_cut( implode( ', ', $parts ), 220 );
	}

	/**
	 * @return array{0: float, 1: array} Weight in kg and warnings.
	 */
	public static function weight_kg( WC_Order $order ) {
		$warnings = array();
		$sum      = 0.0;
		$missing  = 0;

		$unit_fallback = 0.0;
		if ( RWSC_Api_Settings::yes( 'inherit_smart_courier' ) && self::smart_courier_active() ) {
			$sc            = get_option( 'rwsc_settings', array() );
			$unit_fallback = is_array( $sc ) && isset( $sc['fallback_weight'] ) ? (float) $sc['fallback_weight'] : 0.0;
		}

		foreach ( $order->get_items() as $item ) {
			$qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id() ); // Refunds are negative.
			if ( $qty <= 0 ) {
				continue;
			}
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
			$w       = $product ? (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) : 0.0;
			if ( $w <= 0 ) {
				++$missing;
				$w = $unit_fallback;
			}
			$sum += $w * $qty;
		}

		if ( $missing ) {
			$warnings[] = array(
				'code'  => 'weight_missing',
				'level' => 'info',
				/* translators: %d: number of products */
				'text'  => sprintf( _n( '%d product has no weight set.', '%d products have no weight set.', $missing, 'rar-woo-smart-courier' ), $missing ),
			);
		}
		if ( $sum <= 0 ) {
			$sum = (float) RWSC_Api_Settings::get( 'fallback_weight', 0.5 );
		}
		$sum = round( $sum, 2 );
		if ( $sum < 0.5 ) {
			$sum = 0.5;
		}
		if ( $sum > 10 ) {
			$warnings[] = array(
				'code'  => 'weight_over',
				'level' => 'warning',
				/* translators: %s: weight in kg */
				'text'  => sprintf( __( 'Calculated weight is %s kg; Pathao accepts at most 10 kg per parcel. Weight was capped — split the order if needed.', 'rar-woo-smart-courier' ), wc_format_decimal( $sum, 2 ) ),
			);
			$sum = 10.0;
		}
		return array( $sum, $warnings );
	}

	/**
	 * Cash the rider must collect, in whole BDT.
	 *
	 * @return array{0: int, 1: array} amount and warnings
	 */
	public static function amount_to_collect( WC_Order $order ) {
		$warnings = array();
		$total    = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
		$amount   = $total;

		$prepaid = array_filter( array_map( 'trim', explode( ',', (string) RWSC_Api_Settings::get( 'prepaid_gateways', '' ) ) ) );

		$is_cod = 'cod' === $order->get_payment_method();
		if ( in_array( $order->get_payment_method(), $prepaid, true ) ) {
			$amount = 0.0;
		} elseif ( $is_cod ) {
			$amount = $total; // WooCommerce stamps date_paid when a COD order turns "processing"; it is not prepaid.
		} elseif ( class_exists( 'RAR_WAP_Order' ) && RAR_WAP_Order::is_rar_order( $order ) ) {
			$amount = (float) RAR_WAP_Order::collectable_amount( $order );
			if ( 'verified' !== RAR_WAP_Order::get_status( $order ) ) {
				$warnings[] = array(
					'code'  => 'advance_unverified',
					'level' => 'warning',
					'text'  => __( 'The advance payment is not verified yet — the full order total is set to be collected. Verify the payment first if the customer already paid.', 'rar-woo-smart-courier' ),
				);
			}
		} elseif ( $order->get_date_paid() ) {
			$amount = 0.0; // Paid online through another gateway.
		}

		$amount = (float) apply_filters( 'ptc_amount_to_collect', $amount, $order );
		return array( (int) round( max( 0.0, $amount ) ), $warnings );
	}

	public static function items_summary( WC_Order $order ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id() );
			if ( $qty > 0 ) {
				$out[] = array(
					'name'  => $item->get_name(),
					'qty'   => $qty,
					'total' => (float) $item->get_total(),
				);
			}
		}
		return $out;
	}

	private static function render_template( $tpl, WC_Order $order, array $items ) {
		$list = array();
		foreach ( $items as $i ) {
			$list[] = $i['name'] . ' x' . $i['qty'];
		}
		$map = array(
			'{items}'         => implode( ', ', $list ),
			'{order_number}'  => $order->get_order_number(),
			'{customer_note}' => trim( (string) $order->get_customer_note() ),
			'{site}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		return trim( preg_replace( '/\s+/', ' ', strtr( (string) $tpl, $map ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Assembly
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the dispatch modal needs for one order.
	 */
	public static function build( WC_Order $order ) {
		$items                      = self::items_summary( $order );
		list( $weight, $w_warn )    = self::weight_kg( $order );
		list( $collect, $c_warn )   = self::amount_to_collect( $order );
		$warnings                   = array_merge( $w_warn, $c_warn );
		$suggest                    = RWSC_Pathao_Locations::resolve_for_order( $order );
		$mode                       = RWSC_Api_Settings::get( 'location_mode', 'auto' );
		$consignment                = RWSC_Pathao_Orders::consignment( $order );

		$city = $zone = $area = 0;
		if ( 'city_only' === $mode ) {
			$city = $suggest['city_id'];
		} elseif ( 'smart' === $mode ) {
			$city = $suggest['city_id'];
			$zone = $suggest['zone_id'];
			$area = $suggest['area_id'];
		}

		$store_id = (int) RWSC_Api_Settings::get( 'default_store_id', 0 );

		$fields = array(
			'recipient_name'            => self::recipient_name( $order ),
			'recipient_phone'           => self::recipient_phone( $order ),
			'recipient_secondary_phone' => '',
			'recipient_address'         => self::recipient_address( $order ),
			'recipient_city'            => (int) $city,
			'recipient_zone'            => (int) $zone,
			'recipient_area'            => (int) $area,
			'store_id'                  => $store_id,
			'delivery_type'             => (int) RWSC_Api_Settings::get( 'delivery_type', 48 ),
			'item_type'                 => (int) RWSC_Api_Settings::get( 'item_type', 2 ),
			'item_quantity'             => max( 1, array_sum( wp_list_pluck( $items, 'qty' ) ) ),
			'item_weight'               => $weight,
			'amount_to_collect'         => $collect,
			'item_description'          => self::str_cut( self::render_template( RWSC_Api_Settings::get( 'description_template', '{items}' ), $order, $items ), 500 ),
			'special_instruction'       => self::str_cut( self::render_template( RWSC_Api_Settings::get( 'instruction_template', '{customer_note}' ), $order, $items ), 500 ),
		);

		// Backwards compatible filter name from the official plugin.
		$filtered = apply_filters( 'pathao_modal_order_data', $fields, $order, apply_filters( 'pathao_order_data_context', array(), $order ) );
		if ( is_array( $filtered ) ) {
			foreach ( $fields as $k => $v ) {
				if ( array_key_exists( $k, $filtered ) ) {
					$fields[ $k ] = $filtered[ $k ];
				}
			}
		}

		if ( $consignment ) {
			$warnings[] = array(
				'code'  => 'already_sent',
				'level' => 'error',
				'text'  => __( 'This order already has a Pathao consignment.', 'rar-woo-smart-courier' ),
			);
		}
		if ( self::chose_other_courier( $order ) ) {
			$warnings[] = array(
				'code'  => 'other_courier',
				'level' => 'warning',
				/* translators: %s: courier name */
				'text'  => sprintf( __( 'Customer chose %s at checkout (RAR Smart Courier).', 'rar-woo-smart-courier' ), self::selected_courier( $order ) ),
			);
		}
		if ( ! $order->has_status( array_map( static function ( $s ) { return str_replace( 'wc-', '', $s ); }, (array) RWSC_Api_Settings::get( 'queue_statuses' ) ) ) && ! $consignment ) {
			$warnings[] = array(
				'code'  => 'status_unexpected',
				'level' => 'info',
				/* translators: %s: order status */
				'text'  => sprintf( __( 'Order status is "%s", not a normal dispatch status.', 'rar-woo-smart-courier' ), wc_get_order_status_name( $order->get_status() ) ),
			);
		}

		return array(
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'edit_url'       => $order->get_edit_order_url(),
			'date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd M, h:i a' ) : '',
			'status_label'   => wc_get_order_status_name( $order->get_status() ),
			'total'          => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ),
			'payment_method' => $order->get_payment_method_title(),
			'courier'        => self::selected_courier( $order ),
			'sent'           => (bool) $consignment,
			'consignment_id' => $consignment,
			'fields'         => $fields,
			'suggest'        => $suggest,
			'items'          => $items,
			'warnings'       => $warnings,
		);
	}

	/**
	 * Validate against the documented API constraints.
	 *
	 * @param array $f Field values (will be normalised by reference).
	 * @return array field => message
	 */
	public static function validate( array &$f ) {
		$e = array();

		$f['recipient_name']  = trim( (string) ( $f['recipient_name'] ?? '' ) );
		$f['recipient_phone'] = self::normalize_phone( $f['recipient_phone'] ?? '' );
		$f['recipient_secondary_phone'] = self::normalize_phone( $f['recipient_secondary_phone'] ?? '' );
		$f['recipient_address'] = trim( preg_replace( '/\s+/', ' ', (string) ( $f['recipient_address'] ?? '' ) ) );

		$nl = self::str_len( $f['recipient_name'] );
		if ( $nl < 3 || $nl > 100 ) {
			$e['recipient_name'] = __( 'Name must be 3–100 characters.', 'rar-woo-smart-courier' );
		}
		if ( ! preg_match( '/^01[3-9]\d{8}$/', $f['recipient_phone'] ) ) {
			$e['recipient_phone'] = __( 'Phone must be a valid 11-digit Bangladeshi mobile number.', 'rar-woo-smart-courier' );
		}
		if ( '' !== $f['recipient_secondary_phone'] && ! preg_match( '/^01[3-9]\d{8}$/', $f['recipient_secondary_phone'] ) ) {
			$e['recipient_secondary_phone'] = __( 'Secondary phone must be 11 digits.', 'rar-woo-smart-courier' );
		}
		$al = self::str_len( $f['recipient_address'] );
		if ( $al < 10 || $al > 220 ) {
			$e['recipient_address'] = __( 'Address must be 10–220 characters.', 'rar-woo-smart-courier' );
		}
		if ( empty( $f['store_id'] ) || (int) $f['store_id'] < 1 ) {
			$e['store_id'] = __( 'Select a pickup store.', 'rar-woo-smart-courier' );
		}
		if ( ! in_array( (int) ( $f['delivery_type'] ?? 0 ), array( 12, 48 ), true ) ) {
			$e['delivery_type'] = __( 'Delivery type must be Normal (48) or On-demand (12).', 'rar-woo-smart-courier' );
		}
		if ( ! in_array( (int) ( $f['item_type'] ?? 0 ), array( 1, 2 ), true ) ) {
			$e['item_type'] = __( 'Item type must be Document (1) or Parcel (2).', 'rar-woo-smart-courier' );
		}
		if ( (int) ( $f['item_quantity'] ?? 0 ) < 1 ) {
			$e['item_quantity'] = __( 'Quantity must be at least 1.', 'rar-woo-smart-courier' );
		}
		$w = (float) ( $f['item_weight'] ?? 0 );
		if ( $w < 0.5 || $w > 10 ) {
			$e['item_weight'] = __( 'Weight must be between 0.5 and 10 kg.', 'rar-woo-smart-courier' );
		}
		if ( ! isset( $f['amount_to_collect'] ) || '' === $f['amount_to_collect'] || (float) $f['amount_to_collect'] < 0 ) {
			$e['amount_to_collect'] = __( 'Collectable amount must be 0 or more.', 'rar-woo-smart-courier' );
		}
		return $e;
	}

	/**
	 * Final API payload (typed). Third parties may adjust it via `pathao_order_payload`.
	 */
	public static function to_payload( array $f, WC_Order $order ) {
		$payload = array(
			'store_id'            => (int) $f['store_id'],
			'merchant_order_id'   => (string) $order->get_id(),
			'recipient_name'      => sanitize_text_field( $f['recipient_name'] ),
			'recipient_phone'     => $f['recipient_phone'],
			'recipient_address'   => sanitize_textarea_field( $f['recipient_address'] ),
			'delivery_type'       => (int) $f['delivery_type'],
			'item_type'           => (int) $f['item_type'],
			'item_quantity'       => (int) $f['item_quantity'],
			'item_weight'         => round( (float) $f['item_weight'], 2 ),
			'amount_to_collect'   => (int) round( (float) $f['amount_to_collect'] ),
			'item_description'    => sanitize_textarea_field( (string) ( $f['item_description'] ?? '' ) ),
			'special_instruction' => sanitize_textarea_field( (string) ( $f['special_instruction'] ?? '' ) ),
		);
		if ( '' !== ( $f['recipient_secondary_phone'] ?? '' ) ) {
			$payload['recipient_secondary_phone'] = $f['recipient_secondary_phone'];
		}
		// City/zone/area are optional: when omitted Pathao derives them from the address.
		foreach ( array( 'recipient_city', 'recipient_zone', 'recipient_area' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$payload[ $k ] = (int) $f[ $k ];
			}
		}
		$payload = apply_filters( 'pathao_order_payload', $payload, $order );
		return is_array( $payload ) ? $payload : array();
	}
}
