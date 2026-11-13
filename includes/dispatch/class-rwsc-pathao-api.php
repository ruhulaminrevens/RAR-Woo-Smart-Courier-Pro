<?php
/**
 * Pathao Courier Merchant API client.
 *
 * - Token lifecycle with refresh, locking (no thundering herd) and one
 *   automatic re-auth on HTTP 401.
 * - GET requests retry once on transport errors / 5xx. POST (order creation)
 *   is NEVER blindly retried: a timed-out POST may have created the parcel.
 * - Metadata (cities, zones, areas, stores) cached server-side; no giant
 *   browser localStorage preload.
 *
 * Endpoints follow the Pathao Merchant API documentation (issue-token,
 * stores, orders, orders/bulk, orders/{id}/info, city-list, zone-list,
 * area-list, merchant/price-plan) plus the two endpoints the official plugin
 * already used (external/login, user/short-info).
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Pathao_API {

	const TOKEN_OPTION = 'rwsc_pathao_token';
	const TTL_META     = 604800; // 7 days.
	const TTL_STORES   = 3600;
	const TTL_PRICE    = 3600;

	public static function base_url( $environment = null ) {
		$environment = $environment ? $environment : RWSC_Api_Settings::get( 'environment', 'live' );
		return 'staging' === $environment ? 'https://courier-api-sandbox.pathao.com' : 'https://api-hermes.pathao.com';
	}

	public static function merchant_panel_url() {
		return 'https://merchant.pathao.com';
	}

	/* ------------------------------------------------------------------ *
	 * Authentication
	 * ------------------------------------------------------------------ */

	/**
	 * Request a token. $args may override stored settings (used by "Test connection").
	 *
	 * @return array|WP_Error Normalised token array.
	 */
	public static function issue_token( array $args = array() ) {
		$s = wp_parse_args(
			$args,
			array(
				'client_id'     => RWSC_Api_Settings::get( 'client_id' ),
				'client_secret' => RWSC_Api_Settings::get( 'client_secret' ),
				'username'      => RWSC_Api_Settings::get( 'username' ),
				'password'      => RWSC_Api_Settings::get( 'password' ),
				'environment'   => RWSC_Api_Settings::get( 'environment' ),
				'auth_mode'     => RWSC_Api_Settings::get( 'auth_mode' ),
				'refresh_token' => '',
			)
		);

		if ( '' === $s['client_id'] || '' === $s['client_secret'] ) {
			return new WP_Error( 'rwsc_no_credentials', __( 'Client ID and Client Secret are required.', 'rar-woo-smart-courier' ) );
		}

		$mode = $s['auth_mode'];
		if ( 'auto' === $mode ) {
			$mode = ( '' !== $s['username'] && '' !== $s['password'] ) ? 'password' : 'client';
		}

		$base = self::base_url( $s['environment'] );
		if ( 'password' === $mode ) {
			if ( '' !== $s['refresh_token'] ) {
				$path = '/aladdin/api/v1/issue-token';
				$body = array(
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $s['refresh_token'],
				);
			} else {
				$path = '/aladdin/api/v1/issue-token';
				$body = array(
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'grant_type'    => 'password',
					'username'      => $s['username'],
					'password'      => $s['password'],
				);
			}
		} else {
			$path = '/aladdin/api/v1/external/login';
			$body = array(
				'client_id'     => $s['client_id'],
				'client_secret' => $s['client_secret'],
			);
		}

		$res = self::raw_request( 'POST', $base . $path, $body, '' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 || empty( $res['body']['access_token'] ) ) {
			// A failed refresh falls back to a fresh login once.
			if ( '' !== $s['refresh_token'] ) {
				$s['refresh_token'] = '';
				return self::issue_token( $s );
			}
			return self::error_from( $res, __( 'Pathao rejected the credentials.', 'rar-woo-smart-courier' ) );
		}

		$d = $res['body'];
		return array(
			'access_token'  => (string) $d['access_token'],
			'refresh_token' => (string) ( $d['refresh_token'] ?? '' ),
			'expires_at'    => time() + max( 300, (int) ( $d['expires_in'] ?? 3600 ) ),
			'mode'          => $mode,
			'fp'            => self::fingerprint( $s ),
		);
	}

	private static function fingerprint( array $s ) {
		return substr( hash( 'sha256', $s['client_id'] . '|' . $s['client_secret'] . '|' . $s['environment'] . '|' . $s['username'] ), 0, 16 );
	}

	/**
	 * Valid access token or WP_Error.
	 */
	public static function token( $force = false ) {
		$data = get_option( self::TOKEN_OPTION );
		$fp   = self::fingerprint(
			array(
				'client_id'     => RWSC_Api_Settings::get( 'client_id' ),
				'client_secret' => RWSC_Api_Settings::get( 'client_secret' ),
				'environment'   => RWSC_Api_Settings::get( 'environment' ),
				'username'      => RWSC_Api_Settings::get( 'username' ),
			)
		);
		if ( ! $force && self::token_valid( $data, $fp ) ) {
			return $data['access_token'];
		}

		if ( ! RWSC_Lock::acquire( 'token', 15 ) ) {
			return new WP_Error( 'rwsc_token_busy', __( 'Could not obtain an API token right now. Please retry.', 'rar-woo-smart-courier' ) );
		}
		try {
			// Another request may have refreshed while we waited for the lock.
			wp_cache_delete( self::TOKEN_OPTION, 'options' );
			$data = get_option( self::TOKEN_OPTION );
			if ( ! $force && self::token_valid( $data, $fp ) ) {
				return $data['access_token'];
			}
			$refresh = ( is_array( $data ) && ! $force ) ? (string) ( $data['refresh_token'] ?? '' ) : '';
			$new     = self::issue_token( array( 'refresh_token' => $refresh ) );
			if ( is_wp_error( $new ) ) {
				RWSC_Logger::error( 'Token request failed: ' . $new->get_error_message() );
				return $new;
			}
			update_option( self::TOKEN_OPTION, $new, false );
			RWSC_Logger::debug( 'New API token stored', array( 'expires_at' => $new['expires_at'], 'mode' => $new['mode'] ) );
			return $new['access_token'];
		} finally {
			RWSC_Lock::release( 'token' );
		}
	}

	private static function token_valid( $data, $fp ) {
		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return false;
		}
		// `expires_in` held an absolute timestamp in the original plugin.
		$exp = (int) ( $data['expires_at'] ?? $data['expires_in'] ?? 0 );
		if ( $exp < time() + 300 ) {
			return false;
		}
		return empty( $data['fp'] ) || $data['fp'] === $fp;
	}

	public static function token_expiry() {
		$data = get_option( self::TOKEN_OPTION );
		return is_array( $data ) ? (int) ( $data['expires_at'] ?? $data['expires_in'] ?? 0 ) : 0;
	}

	/* ------------------------------------------------------------------ *
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * @return array|WP_Error array( 'code' => int, 'body' => array, 'raw' => string )
	 */
	private static function raw_request( $method, $url, $body, $token, $timeout = 30 ) {
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 3,
			'httpversion' => '1.1',
			'headers'     => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'source'       => 'woocommerce',
			),
		);
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		RWSC_Logger::debug( $method . ' ' . $url, array( 'body' => is_array( $body ) ? $body : array() ) );
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			RWSC_Logger::error( 'HTTP transport error: ' . $response->get_error_message(), array( 'url' => $url ) );
			return new WP_Error( 'rwsc_transport', $response->get_error_message(), array( 'transport' => true ) );
		}
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$code    = (int) wp_remote_retrieve_response_code( $response );
		RWSC_Logger::debug( 'Response ' . $code, array( 'body' => is_array( $decoded ) ? $decoded : array() ) );
		return array(
			'code' => $code,
			'body' => is_array( $decoded ) ? $decoded : array(),
			'raw'  => $raw,
		);
	}

	/**
	 * Authenticated call.
	 *
	 * @return array|WP_Error Response array (see raw_request) for 2xx, WP_Error otherwise.
	 */
	public static function request( $method, $path, $body = null, $retry_safe = false ) {
		$url         = self::base_url() . $path;
		$reauthed    = false;
		$retried     = false;
		$res         = null;

		for ( $i = 0; $i < 4; $i++ ) {
			$token = self::token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$res = self::raw_request( $method, $url, $body, $token );

			if ( is_wp_error( $res ) ) {
				if ( $retry_safe && ! $retried ) {
					$retried = true;
					usleep( 400000 );
					continue;
				}
				return $res;
			}
			if ( 401 === $res['code'] && ! $reauthed ) {
				$reauthed = true;
				$forced   = self::token( true );
				if ( is_wp_error( $forced ) ) {
					return $forced;
				}
				continue;
			}
			if ( $retry_safe && $res['code'] >= 500 && ! $retried ) {
				$retried = true;
				usleep( 400000 );
				continue;
			}
			break;
		}

		if ( $res['code'] >= 200 && $res['code'] < 300 ) {
			return $res;
		}
		return self::error_from( $res, __( 'The Pathao API returned an error.', 'rar-woo-smart-courier' ) );
	}

	private static function error_from( array $res, $fallback ) {
		$b       = $res['body'];
		$message = ! empty( $b['message'] ) && is_string( $b['message'] ) ? wp_strip_all_tags( $b['message'] ) : $fallback;
		$errors  = array();
		if ( ! empty( $b['errors'] ) && is_array( $b['errors'] ) ) {
			foreach ( $b['errors'] as $field => $msgs ) {
				$errors[ (string) $field ] = is_array( $msgs ) ? implode( ', ', array_map( 'strval', $msgs ) ) : (string) $msgs;
			}
			$message .= ' — ' . implode( '; ', $errors );
		}
		return new WP_Error(
			'rwsc_api_error',
			$message,
			array(
				'status' => $res['code'],
				'errors' => $errors,
			)
		);
	}

	/** Extract a list from Pathao's `data.data` / `data` envelope. */
	private static function list_from( array $res ) {
		$b = $res['body'];
		if ( isset( $b['data']['data'] ) && is_array( $b['data']['data'] ) ) {
			return $b['data']['data'];
		}
		if ( isset( $b['data'] ) && is_array( $b['data'] ) && ( array() === $b['data'] || isset( $b['data'][0] ) ) ) {
			return $b['data'];
		}
		return array();
	}

	/* ------------------------------------------------------------------ *
	 * Cached metadata
	 * ------------------------------------------------------------------ */

	private static function cached( $key, $ttl, callable $fetch, $force = false ) {
		$tkey = 'rwsc_' . $key;
		if ( ! $force ) {
			$hit = get_transient( $tkey );
			if ( false !== $hit ) {
				return $hit;
			}
		}
		$val = $fetch();
		if ( ! is_wp_error( $val ) && ! empty( $val ) ) {
			set_transient( $tkey, $val, $ttl );
		}
		return $val;
	}

	public static function user( $force = false ) {
		return self::cached(
			'user',
			self::TTL_STORES,
			static function () {
				$res = self::request( 'GET', '/aladdin/api/v1/user/short-info', null, true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				return isset( $res['body']['data'] ) && is_array( $res['body']['data'] ) ? $res['body']['data'] : array();
			},
			$force
		);
	}

	public static function stores( $force = false ) {
		return self::cached(
			'stores',
			self::TTL_STORES,
			static function () {
				$res = self::request( 'GET', '/aladdin/api/v1/stores', null, true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$out = array();
				foreach ( self::list_from( $res ) as $s ) {
					$out[] = array(
						'id'         => (int) ( $s['store_id'] ?? 0 ),
						'name'       => (string) ( $s['store_name'] ?? '' ),
						'address'    => (string) ( $s['store_address'] ?? '' ),
						'active'     => ! empty( $s['is_active'] ),
						'default'    => ! empty( $s['is_default_store'] ),
						'return'     => ! empty( $s['is_default_return_store'] ),
						'city_id'    => (int) ( $s['city_id'] ?? 0 ),
						'zone_id'    => (int) ( $s['zone_id'] ?? 0 ),
					);
				}
				return $out;
			},
			$force
		);
	}

	public static function cities( $force = false ) {
		return self::cached(
			'cities',
			self::TTL_META,
			static function () {
				$res = self::request( 'GET', '/aladdin/api/v1/countries/1/city-list', null, true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$out = array();
				foreach ( self::list_from( $res ) as $c ) {
					$out[] = array(
						'id'   => (int) ( $c['city_id'] ?? 0 ),
						'name' => trim( (string) ( $c['city_name'] ?? '' ) ),
					);
				}
				return $out;
			},
			$force
		);
	}

	public static function zones( $city_id, $force = false ) {
		$city_id = (int) $city_id;
		return self::cached(
			'zones_' . $city_id,
			self::TTL_META,
			static function () use ( $city_id ) {
				$res = self::request( 'GET', '/aladdin/api/v1/cities/' . $city_id . '/zone-list', null, true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$out = array();
				foreach ( self::list_from( $res ) as $z ) {
					$out[] = array(
						'id'   => (int) ( $z['zone_id'] ?? 0 ),
						'name' => trim( (string) ( $z['zone_name'] ?? '' ) ),
					);
				}
				return $out;
			},
			$force
		);
	}

	public static function areas( $zone_id, $force = false ) {
		$zone_id = (int) $zone_id;
		return self::cached(
			'areas_' . $zone_id,
			self::TTL_META,
			static function () use ( $zone_id ) {
				$res = self::request( 'GET', '/aladdin/api/v1/zones/' . $zone_id . '/area-list', null, true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$out = array();
				foreach ( self::list_from( $res ) as $a ) {
					$out[] = array(
						'id'       => (int) ( $a['area_id'] ?? 0 ),
						'name'     => trim( (string) ( $a['area_name'] ?? '' ) ),
						'home'     => ! isset( $a['home_delivery_available'] ) || ! empty( $a['home_delivery_available'] ),
						'pickup'   => ! isset( $a['pickup_available'] ) || ! empty( $a['pickup_available'] ),
					);
				}
				return $out;
			},
			$force
		);
	}

	/**
	 * Delivery price estimate (POST /merchant/price-plan; recipient city + zone required by the API).
	 */
	public static function price( array $args ) {
		$body = array(
			'store_id'       => (int) $args['store_id'],
			'item_type'      => (int) $args['item_type'],
			'delivery_type'  => (int) $args['delivery_type'],
			'item_weight'    => (float) $args['item_weight'],
			'recipient_city' => (int) $args['recipient_city'],
			'recipient_zone' => (int) $args['recipient_zone'],
		);
		$key  = 'price_' . md5( wp_json_encode( $body ) );
		return self::cached(
			$key,
			self::TTL_PRICE,
			static function () use ( $body ) {
				$res = self::request( 'POST', '/aladdin/api/v1/merchant/price-plan', $body, true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				return isset( $res['body']['data'] ) && is_array( $res['body']['data'] ) ? $res['body']['data'] : array();
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Orders
	 * ------------------------------------------------------------------ */

	/**
	 * Create one consignment. Never auto-retried.
	 *
	 * @return array|WP_Error `data` block of the response (consignment_id, delivery_fee, ...)
	 */
	public static function create_order( array $payload ) {
		$res = self::request( 'POST', '/aladdin/api/v1/orders', $payload, false );
		if ( is_wp_error( $res ) ) {
			if ( 'rwsc_transport' === $res->get_error_code() ) {
				// The request may have reached Pathao before the connection failed.
				return new WP_Error( 'rwsc_uncertain', $res->get_error_message(), array( 'uncertain' => true ) );
			}
			return $res;
		}
		$data = isset( $res['body']['data'] ) && is_array( $res['body']['data'] ) ? $res['body']['data'] : array();
		if ( empty( $data['consignment_id'] ) ) {
			return new WP_Error( 'rwsc_no_consignment', __( 'Pathao accepted the request but returned no consignment ID. Check the Pathao merchant panel before retrying.', 'rar-woo-smart-courier' ), array( 'uncertain' => true ) );
		}
		return $data;
	}

	/**
	 * Native bulk endpoint: asynchronous, returns acceptance only (HTTP 202).
	 */
	public static function create_bulk( array $payloads ) {
		$res = self::request( 'POST', '/aladdin/api/v1/orders/bulk', array( 'orders' => array_values( $payloads ) ), false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res['body'];
	}

	/**
	 * Short info (status) of a consignment.
	 */
	public static function order_info( $consignment_id ) {
		$res = self::request( 'GET', '/aladdin/api/v1/orders/' . rawurlencode( (string) $consignment_id ) . '/info', null, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['body']['data'] ) && is_array( $res['body']['data'] ) ? $res['body']['data'] : array();
	}

	/* ------------------------------------------------------------------ *
	 * Maintenance
	 * ------------------------------------------------------------------ */

	public static function flush_cache() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_rpc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rpc\\_%'"
		);
		// phpcs:enable
		wp_cache_flush();
	}
}
