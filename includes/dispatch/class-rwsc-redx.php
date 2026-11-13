<?php
/**
 * RedX module.
 *
 * Verified from the vendor plugin "Redx for WooCommerce 1.0.1": base
 * https://openapi.redx.com.bd/v1.0.0-beta, header "API-ACCESS-TOKEN: Bearer <token>",
 * POST /wordpress/parcel with customer_name, customer_phone, customer_address,
 * merchant_invoice_id, cash_collection_amount, parcel_weight, instruction, value ->
 * response { tracking_id }.
 *
 * NOT verified (public docs unreachable when this was written, treat as best effort and
 * test on one parcel): sandbox host, GET /areas, POST /parcel ("official" mode),
 * GET /parcel/track/{id} and GET /parcel/info/{id} used for status sync. All hosts/paths are
 * overridable with the redx_base_url setting and the rpc_redx_request filter.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_RedX extends RWSC_Courier_Base {

	const LIVE    = 'https://openapi.redx.com.bd/v1.0.0-beta';
	const SANDBOX = 'https://sandbox.redx.com.bd/v1.0.0-beta';

	public function slug() {
		return 'redx';
	}

	public function prefix() {
		return 'rdx';
	}

	public function label() {
		return 'RedX';
	}

	public function legacy_id_key() {
		return '_redx_tracking_id';
	}

	public function configured() {
		return '' !== (string) RWSC_Api_Settings::get( 'redx_token' );
	}

	private function base() {
		$o = trim( (string) RWSC_Api_Settings::get( 'redx_base_url' ) );
		if ( '' !== $o ) {
			return untrailingslashit( esc_url_raw( $o ) );
		}
		return 'sandbox' === RWSC_Api_Settings::get( 'redx_env' ) ? self::SANDBOX : self::LIVE;
	}

	/**
	 * @param string      $method GET|POST.
	 * @param string      $path   Path under base.
	 * @param array|null  $body   JSON body.
	 * @param string|null $token  Override token (connection test with unsaved value).
	 * @return array|WP_Error array( code, json, raw )
	 */
	public function request( $method, $path, $body = null, $token = null ) {
		$token = null !== $token ? $token : (string) RWSC_Api_Settings::get( 'redx_token' );
		if ( '' === $token ) {
			return new WP_Error( 'rwsc_no_credentials', __( 'RedX API token is missing.', 'rar-woo-smart-courier' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
				'API-ACCESS-TOKEN' => 'Bearer ' . $token,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$args = apply_filters( 'rpc_redx_request', $args, $path );
		$url  = $this->base() . $path;

		for ( $try = 0; $try < 2; $try++ ) {
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				if ( 'GET' === $method && 0 === $try ) {
					continue;
				}
				return new WP_Error( 'rwsc_transport', $res->get_error_message(), array( 'uncertain' => 'POST' === $method ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 'GET' === $method && 0 === $try && $code >= 500 ) {
				continue;
			}
			break;
		}
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		RWSC_Logger::debug( 'RedX ' . $method . ' ' . $path, array( 'http' => $code ) );
		return array(
			'code' => $code,
			'json' => is_array( $json ) ? $json : array(),
			'raw'  => $raw,
		);
	}

	private function error_from( array $r ) {
		$j = $r['json'];
		$m = $j['message'] ?? $j['error'] ?? $j['detail'] ?? '';
		if ( is_array( $m ) ) {
			$m = wp_json_encode( $m );
		}
		if ( '' === (string) $m ) {
			$m = self::cut( wp_strip_all_tags( $r['raw'] ), 300 );
		}
		/* translators: 1: HTTP code 2: message */
		return sprintf( __( 'RedX error (HTTP %1$d): %2$s', 'rar-woo-smart-courier' ), $r['code'], $m );
	}

	public function test( $token = null ) {
		$r = $this->request( 'GET', '/areas', null, $token );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r['code'] >= 200 && $r['code'] < 300 ) {
			return __( 'RedX token accepted.', 'rar-woo-smart-courier' );
		}
		return new WP_Error( 'rwsc_redx_http', $this->error_from( $r ) );
	}

	/* ---------------- Areas (official mode) ---------------- */

	public function areas() {
		$c = get_transient( 'rwsc_redx_areas' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$r = $this->request( 'GET', '/areas' );
		if ( is_wp_error( $r ) || $r['code'] < 200 || $r['code'] >= 300 ) {
			return array();
		}
		$list = $r['json']['areas'] ?? $r['json']['data'] ?? array();
		$out  = array();
		foreach ( (array) $list as $a ) {
			if ( isset( $a['id'], $a['name'] ) ) {
				$out[] = array( 'id' => (int) $a['id'], 'name' => (string) $a['name'] );
			}
		}
		set_transient( 'rwsc_redx_areas', $out, 7 * DAY_IN_SECONDS );
		return $out;
	}

	/** Longest area name found inside the address / city text. */
	public function resolve_area( WC_Order $order ) {
		$hay  = RWSC_Pathao_Locations::norm( RWSC_Order_Data::recipient_address( $order ) . ' ' . $order->get_shipping_city() . ' ' . $order->get_billing_city() );
		$best = null;
		foreach ( $this->areas() as $a ) {
			$n = RWSC_Pathao_Locations::norm( $a['name'] );
			if ( '' !== $n && false !== strpos( $hay, $n ) && ( ! $best || strlen( $n ) > strlen( RWSC_Pathao_Locations::norm( $best['name'] ) ) ) ) {
				$best = $a;
			}
		}
		return $best;
	}

	/* ---------------- Contract ---------------- */

	public function build( WC_Order $order ) {
		$c    = $this->common( $order );
		$grams = (int) max( 100, round( ( $c['weight_kg'] > 0 ? $c['weight_kg'] : (float) RWSC_Api_Settings::get( 'fallback_weight', 0.5 ) ) * 1000 ) );
		$f    = array(
			'name'        => $c['name'],
			'phone'       => $c['phone'],
			'address'     => $c['address'],
			'invoice'     => $c['invoice'],
			'cod'         => $c['cod'],
			'value'       => $c['total'],
			'weight_g'    => $grams,
			'instruction' => $c['note'],
			'area_id'     => 0,
			'area_name'   => '',
		);
		if ( 'official' === RWSC_Api_Settings::get( 'redx_mode' ) ) {
			$a = $this->resolve_area( $order );
			if ( $a ) {
				$f['area_id']   = $a['id'];
				$f['area_name'] = $a['name'];
			}
		}
		return apply_filters( 'rpc_redx_fields', $f, $order );
	}

	public function validate( array $f ) {
		$e = $this->common_errors( $f );
		if ( (int) ( $f['weight_g'] ?? 0 ) < 1 ) {
			$e['weight_g'] = __( 'Weight is required.', 'rar-woo-smart-courier' );
		}
		if ( 'official' === RWSC_Api_Settings::get( 'redx_mode' ) && empty( $f['area_id'] ) ) {
			$e['area_id'] = __( 'RedX delivery area could not be matched from the address. Add the area name to the address or use the standard mode.', 'rar-woo-smart-courier' );
		}
		return $e;
	}

	protected function api_create( array $f, WC_Order $order ) {
		$body = array(
			'customer_name'          => self::cut( $f['name'], 100 ),
			'customer_phone'         => $f['phone'],
			'customer_address'       => self::cut( $f['address'], 250 ),
			'merchant_invoice_id'    => $f['invoice'],
			'cash_collection_amount' => (int) $f['cod'],
			'parcel_weight'          => (int) $f['weight_g'],
			'instruction'            => self::cut( $f['instruction'], 300 ),
			'value'                  => (int) $f['value'],
		);
		$path = '/wordpress/parcel';
		if ( 'official' === RWSC_Api_Settings::get( 'redx_mode' ) ) {
			$path                      = '/parcel';
			$body['delivery_area']     = $f['area_name'];
			$body['delivery_area_id']  = (int) $f['area_id'];
			$store                     = (int) RWSC_Api_Settings::get( 'redx_pickup_store_id' );
			if ( $store > 0 ) {
				$body['pickup_store_id'] = $store;
			}
			$body['parcel_details_json'] = array_map(
				static function ( $i ) {
					return array( 'name' => self::cut( $i['name'], 80 ), 'category' => 'general', 'value' => (int) round( $i['total'] ) );
				},
				RWSC_Order_Data::items_summary( $order )
			);
		}
		$r = $this->request( 'POST', $path, $body );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$tid = $r['json']['tracking_id'] ?? ( $r['json']['parcel']['tracking_id'] ?? '' );
		if ( $r['code'] >= 200 && $r['code'] < 300 && '' !== (string) $tid ) {
			return array( 'id' => (string) $tid, 'ref' => (string) $tid, 'status' => 'pickup-pending' );
		}
		if ( $r['code'] >= 200 && $r['code'] < 300 ) {
			// Success without a tracking id: the parcel may exist. Block blind retries.
			return new WP_Error( 'rwsc_redx_noid', __( 'RedX replied OK but returned no tracking ID. Check the RedX panel before retrying.', 'rar-woo-smart-courier' ), array( 'uncertain' => true ) );
		}
		return new WP_Error( 'rwsc_redx_http', $this->error_from( $r ), array( 'http' => $r['code'] ) );
	}

	protected function api_status( WC_Order $order ) {
		$id = rawurlencode( $this->consignment( $order ) );
		$r  = $this->request( 'GET', '/parcel/track/' . $id );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$raw = '';
		$ts  = 0;
		if ( $r['code'] >= 200 && $r['code'] < 300 ) {
			$best = 0;
			foreach ( (array) ( $r['json']['tracking'] ?? array() ) as $ev ) {
				$t = isset( $ev['time'] ) ? (int) strtotime( (string) $ev['time'] ) : 0;
				if ( $t >= $best ) {
					$best = $t;
					$raw  = (string) ( $ev['message_en'] ?? $ev['message'] ?? $ev['status'] ?? '' );
					$ts   = $t;
				}
			}
		}
		if ( '' === $raw ) {
			$r2 = $this->request( 'GET', '/parcel/info/' . $id );
			if ( is_wp_error( $r2 ) ) {
				return $r2;
			}
			if ( $r2['code'] >= 200 && $r2['code'] < 300 ) {
				$raw = (string) ( $r2['json']['parcel']['status'] ?? $r2['json']['status'] ?? '' );
			} else {
				return new WP_Error( 'rwsc_redx_http', $this->error_from( $r2 ) );
			}
		}
		if ( '' === $raw ) {
			return new WP_Error( 'rwsc_no_status', __( 'RedX returned no status for this parcel.', 'rar-woo-smart-courier' ) );
		}
		return array( 'status' => $raw, 'ts' => 0 ); // ts left 0: event times are not reliably comparable.
	}

	public function normalise( $raw ) {
		$raw = trim( (string) $raw );
		$t   = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', $raw ) );
		$lab = '' === $raw ? '—' : ucfirst( trim( preg_replace( '/[\s_-]+/', ' ', $raw ) ) );
		$g   = 'transit';
		$fin = false;
		$slug = trim( preg_replace( '/\s+/', '_', $t ), '_' );
		if ( preg_match( '/fail|hold|not delivered|unreach|resched|refus|problem/', $t ) ) {
			$g = 'attention';
		} elseif ( preg_match( '/returning|return in|to return|return process/', $t ) ) {
			$g = 'attention';
		} elseif ( preg_match( '/return|cancel|rto/', $t ) ) {
			$g = 'returned';
			$fin = true;
		} elseif ( preg_match( '/^(parcel )?delivered|successfully delivered|delivery complete/', $t ) ) {
			$g    = 'delivered';
			$fin  = true;
			$slug = 'delivered';
		} elseif ( preg_match( '/pending|created|waiting|requested|pickup request|pick up pending/', $t ) ) {
			$g = 'pending';
		}
		return array( 'slug' => $slug, 'label' => $lab, 'group' => $g, 'final' => $fin );
	}

	public function tracking_url( WC_Order $order ) {
		$cid = $this->consignment( $order );
		if ( '' === $cid ) {
			return '';
		}
		$tpl = (string) RWSC_Api_Settings::get( 'redx_tracking_template' );
		return strtr( $tpl, array( '{id}' => rawurlencode( $cid ) ) );
	}
}
