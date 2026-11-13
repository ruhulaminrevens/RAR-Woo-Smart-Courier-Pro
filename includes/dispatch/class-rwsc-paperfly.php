<?php
/**
 * Paperfly module.
 *
 * Taken from community SDKs of the Paperfly merchant API (codeboxrcodehub/paperfly-courier,
 * nazmulpcc/paperfly) because Paperfly's own documentation is not public:
 *  - hosts: https://api.paperfly.com.bd (live), https://sandbox.paperfly-bd.com (sandbox);
 *  - auth: HTTP Basic (username:password) + header "paperflykey";
 *  - create: POST {OrderPlacement | NewOrderUpload} JSON (merOrderRef, pickMerchant*, productSizeWeight,
 *    productBrief, packagePrice, deliveryOption, custname, custaddress, customerThana, customerDistrict,
 *    custPhone, max_weight[, merchantCode]) -> {"success":{...}} | {"error":{"message":..}};
 *  - track: POST API-Order-Tracking {ReferenceNumber[, merchantCode]}.
 * The two SDKs disagree on the create path, so it is a setting. The response shape of tracking is NOT
 * documented anywhere we could read: the status word is searched in the usual keys. Test with one parcel.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Paperfly extends RWSC_Courier_Base {

	const LIVE    = 'https://api.paperfly.com.bd';
	const SANDBOX = 'https://sandbox.paperfly-bd.com';

	public function slug() {
		return 'paperfly';
	}

	public function prefix() {
		return 'pfl';
	}

	public function label() {
		return 'Paperfly';
	}

	public function legacy_id_key() {
		return '';
	}

	public function configured() {
		return '' !== (string) RWSC_Api_Settings::get( 'pf_username' ) && '' !== (string) RWSC_Api_Settings::get( 'pf_password' ) && '' !== (string) RWSC_Api_Settings::get( 'pf_key' );
	}

	private function base() {
		$o = trim( (string) RWSC_Api_Settings::get( 'pf_base_url' ) );
		if ( '' !== $o ) {
			return untrailingslashit( esc_url_raw( $o ) );
		}
		return 'sandbox' === RWSC_Api_Settings::get( 'pf_env' ) ? self::SANDBOX : self::LIVE;
	}

	/** @return array|WP_Error array( code, json, raw ) */
	public function request( $method, $path, $body = null, $user = null, $pass = null, $key = null ) {
		$user = null !== $user ? $user : (string) RWSC_Api_Settings::get( 'pf_username' );
		$pass = null !== $pass ? $pass : (string) RWSC_Api_Settings::get( 'pf_password' );
		$key  = null !== $key ? $key : (string) RWSC_Api_Settings::get( 'pf_key' );
		if ( '' === $user || '' === $pass || '' === $key ) {
			return new WP_Error( 'rwsc_no_credentials', __( 'Paperfly username / password / key is missing.', 'rar-woo-smart-courier' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'paperflykey'   => $key,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$url = $this->base() . '/' . ltrim( $path, '/' );
		for ( $try = 0; $try < 2; $try++ ) {
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				if ( 'GET' === $method && 0 === $try ) {
					continue;
				}
				return new WP_Error( 'rwsc_transport', $res->get_error_message(), array( 'uncertain' => 'POST' === $method && false !== stripos( $path, 'order' ) && false === stripos( $path, 'track' ) ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 'GET' === $method && 0 === $try && $code >= 500 ) {
				continue;
			}
			break;
		}
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		RWSC_Logger::debug( 'Paperfly ' . $method . ' ' . $path, array( 'http' => $code ) );
		return array(
			'code' => $code,
			'json' => is_array( $json ) ? $json : array(),
			'raw'  => $raw,
		);
	}

	private function error_from( array $r ) {
		$j = $r['json'];
		$m = $j['error']['message'] ?? $j['message'] ?? '';
		if ( is_array( $m ) ) {
			$m = wp_json_encode( $m );
		}
		if ( '' === (string) $m ) {
			$m = self::cut( wp_strip_all_tags( $r['raw'] ), 300 );
		}
		/* translators: 1: HTTP code 2: message */
		return sprintf( __( 'Paperfly error (HTTP %1$d): %2$s', 'rar-woo-smart-courier' ), $r['code'], $m );
	}

	/** Credential check without creating anything: a tracking lookup of a dummy reference. */
	public function test( $user = null, $pass = null, $key = null ) {
		$body = array( 'ReferenceNumber' => 'RPC-CONNECTION-TEST' );
		$mc   = trim( (string) RWSC_Api_Settings::get( 'pf_merchant_code' ) );
		if ( '' !== $mc ) {
			$body['merchantCode'] = $mc;
		}
		$r = $this->request( 'POST', 'API-Order-Tracking', $body, $user, $pass, $key );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( in_array( $r['code'], array( 401, 403 ), true ) ) {
			return new WP_Error( 'rwsc_pf_auth', $this->error_from( $r ) );
		}
		/* translators: %d: HTTP code */
		return sprintf( __( 'Paperfly reachable and credentials not rejected (HTTP %d). Unknown test reference is expected to report "not found".', 'rar-woo-smart-courier' ), $r['code'] );
	}

	/* ---------------- Contract ---------------- */

	public function build( WC_Order $order ) {
		$c        = $this->common( $order );
		$use_ship = '' !== trim( $order->get_shipping_address_1() );
		$city     = trim( (string) ( $use_ship ? $order->get_shipping_city() : $order->get_billing_city() ) );
		$state    = (string) ( $use_ship ? $order->get_shipping_state() : $order->get_billing_state() );
		$addr2    = trim( (string) ( $use_ship ? $order->get_shipping_address_2() : $order->get_billing_address_2() ) );
		$district = trim( (string) RWSC_Pathao_Locations::state_label( $state ) );
		$thana    = $city;
		if ( '' === $district ) {
			$district = $city;
		}
		if ( '' !== $thana && 0 === strcasecmp( $thana, $district ) && '' !== $addr2 ) {
			$thana = $addr2; // The city field holds the district: fall back to address line 2.
		}
		$items = array();
		foreach ( RWSC_Order_Data::items_summary( $order ) as $i ) {
			$items[] = $i['name'] . ' x' . $i['qty'];
		}
		$f = array(
			'name'     => $c['name'],
			'phone'    => $c['phone'],
			'address'  => $c['address'],
			'invoice'  => $c['invoice'],
			'cod'      => $c['cod'],
			'thana'    => $thana,
			'district' => $district,
			'brief'    => self::cut( implode( ', ', $items ), 200 ),
			'weight'   => max( 0.5, $c['weight_kg'] > 0 ? $c['weight_kg'] : (float) RWSC_Api_Settings::get( 'fallback_weight', 0.5 ) ),
			'option'   => (string) RWSC_Api_Settings::get( 'pf_delivery_option', 'regular' ),
		);
		return apply_filters( 'rpc_paperfly_fields', $f, $order );
	}

	public function validate( array $f ) {
		$e = $this->common_errors( $f );
		if ( '' === trim( (string) ( $f['thana'] ?? '' ) ) ) {
			$e['thana'] = __( 'Paperfly needs the customer thana (put it in the City field).', 'rar-woo-smart-courier' );
		}
		if ( '' === trim( (string) ( $f['district'] ?? '' ) ) ) {
			$e['district'] = __( 'Paperfly needs the customer district.', 'rar-woo-smart-courier' );
		}
		foreach ( array( 'pf_pick_name', 'pf_pick_address', 'pf_pick_thana', 'pf_pick_district', 'pf_pick_phone' ) as $k ) {
			if ( '' === trim( (string) RWSC_Api_Settings::get( $k ) ) ) {
				$e['pickup'] = __( 'Complete the Paperfly pickup details in Settings.', 'rar-woo-smart-courier' );
				break;
			}
		}
		return $e;
	}

	protected function api_create( array $f, WC_Order $order ) {
		$body = array(
			'merOrderRef'          => $f['invoice'],
			'pickMerchantName'     => (string) RWSC_Api_Settings::get( 'pf_pick_name' ),
			'pickMerchantAddress'  => (string) RWSC_Api_Settings::get( 'pf_pick_address' ),
			'pickMerchantThana'    => (string) RWSC_Api_Settings::get( 'pf_pick_thana' ),
			'pickMerchantDistrict' => (string) RWSC_Api_Settings::get( 'pf_pick_district' ),
			'pickupMerchantPhone'  => (string) RWSC_Api_Settings::get( 'pf_pick_phone' ),
			'productSizeWeight'    => 'standard',
			'productBrief'         => '' !== (string) $f['brief'] ? $f['brief'] : 'Order ' . $f['invoice'],
			'packagePrice'         => (string) (int) $f['cod'],
			'deliveryOption'       => $f['option'],
			'custname'             => self::cut( $f['name'], 100 ),
			'custaddress'          => self::cut( $f['address'], 250 ),
			'customerThana'        => $f['thana'],
			'customerDistrict'     => $f['district'],
			'custPhone'            => $f['phone'],
			'max_weight'           => (string) $f['weight'],
		);
		$mc = trim( (string) RWSC_Api_Settings::get( 'pf_merchant_code' ) );
		if ( '' !== $mc ) {
			$body['merchantCode'] = $mc;
		}
		$path = trim( (string) RWSC_Api_Settings::get( 'pf_create_path', 'OrderPlacement' ) );
		$r    = $this->request( 'POST', '' !== $path ? $path : 'OrderPlacement', $body );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$ok = $r['json']['success'] ?? null;
		if ( $r['code'] >= 200 && $r['code'] < 300 && is_array( $ok ) ) {
			$tn = (string) ( $ok['tracking_number'] ?? $ok['trackingNumber'] ?? $ok['order_id'] ?? '' );
			return array(
				'id'     => '' !== $tn ? $tn : (string) $f['invoice'],
				'ref'    => (string) $f['invoice'], // The reference used for tracking calls.
				'status' => 'Order placed',
			);
		}
		if ( $r['code'] >= 200 && $r['code'] < 300 && empty( $r['json']['error'] ) ) {
			return new WP_Error( 'rwsc_pf_unclear', __( 'Paperfly replied OK in an unknown format. Check the Paperfly panel before retrying.', 'rar-woo-smart-courier' ), array( 'uncertain' => true ) );
		}
		return new WP_Error( 'rwsc_pf_http', $this->error_from( $r ), array( 'http' => $r['code'] ) );
	}

	protected function api_status( WC_Order $order ) {
		$ref  = $this->get( $order, 'ref' );
		$body = array( 'ReferenceNumber' => '' !== $ref ? $ref : (string) $order->get_order_number() );
		$mc   = trim( (string) RWSC_Api_Settings::get( 'pf_merchant_code' ) );
		if ( '' !== $mc ) {
			$body['merchantCode'] = $mc;
		}
		$r = $this->request( 'POST', 'API-Order-Tracking', $body );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r['code'] < 200 || $r['code'] >= 300 ) {
			return new WP_Error( 'rwsc_pf_http', $this->error_from( $r ) );
		}
		$raw = self::find_status( $r['json']['success'] ?? $r['json'] );
		if ( '' === $raw ) {
			return new WP_Error( 'rwsc_no_status', __( 'Paperfly returned no recognisable status for this parcel.', 'rar-woo-smart-courier' ) );
		}
		return array( 'status' => $raw, 'ts' => 0 );
	}

	/** Search the usual status keys, then the last element of any history list. */
	private static function find_status( $node ) {
		if ( ! is_array( $node ) ) {
			return is_string( $node ) ? trim( $node ) : '';
		}
		foreach ( array( 'trackingStatus', 'currentStatus', 'current_status', 'orderStatus', 'order_status', 'status', 'Status' ) as $k ) {
			if ( isset( $node[ $k ] ) && is_string( $node[ $k ] ) && '' !== trim( $node[ $k ] ) ) {
				return trim( $node[ $k ] );
			}
		}
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				$last = $v;
				$list = array_values( $v );
				if ( $list && is_array( end( $list ) ) ) {
					$last = end( $list );
				}
				$s = self::find_status( $last );
				if ( '' !== $s ) {
					return $s;
				}
			}
		}
		return '';
	}

	public function normalise( $raw ) {
		return $this->keyword_normalise( $raw );
	}

	public function tracking_url( WC_Order $order ) {
		$cid = $this->consignment( $order );
		if ( '' === $cid ) {
			return '';
		}
		return strtr( (string) RWSC_Api_Settings::get( 'pf_tracking_template' ), array( '{id}' => rawurlencode( $cid ) ) );
	}
}
