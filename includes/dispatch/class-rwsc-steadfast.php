<?php
/**
 * SteadFast module.
 *
 * Verified from the vendor plugin "SteadFast API 1.0.0" and the SteadFast package README:
 * base https://portal.packzy.com/api/v1 (alias portal.steadfast.com.bd/api/v1), headers
 * Api-Key / Secret-Key, POST /create_order {invoice, recipient_name, recipient_phone,
 * recipient_address, cod_amount, note} -> {status:200, consignment:{consignment_id, tracking_code, status}},
 * GET /status_by_cid/{id} -> {delivery_status}, GET /get_balance -> {current_balance}.
 * Webhook: Authorization "Bearer <token>" with consignment_id, invoice, status, cod_amount, updated_at.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_SteadFast extends RWSC_Courier_Base {

	const BASE = 'https://portal.packzy.com/api/v1';

	/** raw => array( label, group, final ) */
	private static function map() {
		return array(
			'pending'                         => array( 'Pending', 'pending', false ),
			'in_review'                       => array( 'In review', 'pending', false ),
			'hold'                            => array( 'On hold', 'attention', false ),
			'delivered_approval_pending'      => array( 'Delivered (approval pending)', 'transit', false ),
			'partial_delivered_approval_pending' => array( 'Partly delivered (approval pending)', 'transit', false ),
			'cancelled_approval_pending'      => array( 'Cancelled (approval pending)', 'transit', false ),
			'unknown_approval_pending'        => array( 'Unknown (approval pending)', 'attention', false ),
			'delivered'                       => array( 'Delivered', 'delivered', true ),
			'partial_delivered'               => array( 'Partially delivered', 'delivered', true ),
			'cancelled'                       => array( 'Cancelled', 'returned', true ),
			'unknown'                         => array( 'Unknown', 'attention', false ),
		);
	}

	public function slug() {
		return 'steadfast';
	}

	public function prefix() {
		return 'sfc';
	}

	public function label() {
		return 'SteadFast';
	}

	public function legacy_id_key() {
		return 'steadfast_consignment_id';
	}

	public function configured() {
		return '' !== (string) RWSC_Api_Settings::get( 'sf_api_key' ) && '' !== (string) RWSC_Api_Settings::get( 'sf_secret_key' );
	}

	private function base() {
		$b = trim( (string) RWSC_Api_Settings::get( 'sf_base_url' ) );
		return '' !== $b ? untrailingslashit( esc_url_raw( $b ) ) : self::BASE;
	}

	/** @return array|WP_Error array( code, json, raw ) */
	public function request( $method, $path, $body = null, $key = null, $secret = null ) {
		$key    = null !== $key ? $key : (string) RWSC_Api_Settings::get( 'sf_api_key' );
		$secret = null !== $secret ? $secret : (string) RWSC_Api_Settings::get( 'sf_secret_key' );
		if ( '' === $key || '' === $secret ) {
			return new WP_Error( 'rwsc_no_credentials', __( 'SteadFast API key / secret key is missing.', 'rar-woo-smart-courier' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'Api-Key'      => $key,
				'Secret-Key'   => $secret,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$url = $this->base() . $path;
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
		RWSC_Logger::debug( 'SteadFast ' . $method . ' ' . $path, array( 'http' => $code ) );
		return array(
			'code' => $code,
			'json' => is_array( $json ) ? $json : array(),
			'raw'  => $raw,
		);
	}

	private function error_from( array $r ) {
		$j = $r['json'];
		$m = '';
		if ( ! empty( $j['errors'] ) && is_array( $j['errors'] ) ) {
			$parts = array();
			foreach ( $j['errors'] as $field => $msgs ) {
				$parts[] = $field . ': ' . implode( ', ', (array) $msgs );
			}
			$m = implode( '; ', $parts );
		}
		if ( '' === $m ) {
			$m = (string) ( $j['message'] ?? '' );
		}
		if ( '' === $m ) {
			$m = self::cut( wp_strip_all_tags( $r['raw'] ), 300 );
		}
		/* translators: 1: HTTP code 2: message */
		return sprintf( __( 'SteadFast error (HTTP %1$d): %2$s', 'rar-woo-smart-courier' ), $r['code'], $m );
	}

	public function test( $key = null, $secret = null ) {
		$r = $this->request( 'GET', '/get_balance', null, $key, $secret );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r['code'] >= 200 && $r['code'] < 300 && isset( $r['json']['current_balance'] ) ) {
			/* translators: %s: balance */
			return sprintf( __( 'SteadFast connected. Current balance: ৳%s', 'rar-woo-smart-courier' ), number_format_i18n( (float) $r['json']['current_balance'], 2 ) );
		}
		return new WP_Error( 'rwsc_sf_http', $this->error_from( $r ) );
	}

	public function build( WC_Order $order ) {
		$c   = $this->common( $order );
		$cod = $c['cod'];
		$ov  = $order->get_meta( 'steadfast_amount', true ); // Amount typed in the old SteadFast plugin's column.
		if ( '' !== (string) $ov && is_numeric( $ov ) ) {
			$cod = (int) $ov;
		}
		$note = 'yes' === RWSC_Api_Settings::get( 'sf_send_note' ) ? $c['note'] : '';
		$f    = array(
			'name'    => $c['name'],
			'phone'   => $c['phone'],
			'address' => $c['address'],
			'invoice' => preg_replace( '/[^A-Za-z0-9_-]/', '', $c['invoice'] ),
			'cod'     => $cod,
			'note'    => $note,
		);
		return apply_filters( 'rpc_steadfast_fields', $f, $order );
	}

	public function validate( array $f ) {
		$e = $this->common_errors( $f );
		if ( '' === (string) ( $f['invoice'] ?? '' ) ) {
			$e['invoice'] = __( 'Invoice ID is empty.', 'rar-woo-smart-courier' );
		}
		if ( (int) ( $f['cod'] ?? 0 ) < 0 ) {
			$e['cod'] = __( 'COD amount cannot be negative.', 'rar-woo-smart-courier' );
		}
		return $e;
	}

	protected function api_create( array $f, WC_Order $order ) {
		$r = $this->request(
			'POST',
			'/create_order',
			array(
				'invoice'           => $f['invoice'],
				'recipient_name'    => self::cut( $f['name'], 100 ),
				'recipient_phone'   => $f['phone'],
				'recipient_address' => self::cut( $f['address'], 250 ),
				'cod_amount'        => (int) $f['cod'],
				'note'              => self::cut( $f['note'], 300 ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$cons = $r['json']['consignment'] ?? array();
		if ( $r['code'] >= 200 && $r['code'] < 300 && ! empty( $cons['consignment_id'] ) ) {
			return array(
				'id'     => (string) $cons['consignment_id'],
				'ref'    => (string) ( $cons['tracking_code'] ?? '' ),
				'status' => (string) ( $cons['status'] ?? 'in_review' ),
			);
		}
		if ( $r['code'] >= 200 && $r['code'] < 300 && ( (int) ( $r['json']['status'] ?? 0 ) ) === 200 ) {
			return new WP_Error( 'rwsc_sf_noid', __( 'SteadFast replied OK but returned no consignment ID. Check the SteadFast panel before retrying.', 'rar-woo-smart-courier' ), array( 'uncertain' => true ) );
		}
		$msg = $this->error_from( $r );
		if ( preg_match( '/invoice.*(taken|exist|already|duplicate)/i', $msg ) ) {
			$msg .= ' ' . __( '(This invoice may already exist in SteadFast — check the panel before retrying.)', 'rar-woo-smart-courier' );
		}
		return new WP_Error( 'rwsc_sf_http', $msg, array( 'http' => $r['code'] ) );
	}

	protected function api_status( WC_Order $order ) {
		$r = $this->request( 'GET', '/status_by_cid/' . rawurlencode( $this->consignment( $order ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$st = (string) ( $r['json']['delivery_status'] ?? '' );
		if ( $r['code'] >= 200 && $r['code'] < 300 && '' !== $st ) {
			return array( 'status' => $st, 'ts' => 0 );
		}
		return new WP_Error( 'rwsc_sf_http', $this->error_from( $r ) );
	}

	protected function mirror_legacy( WC_Order $order, array $res ) {
		parent::mirror_legacy( $order, $res );
		$order->update_meta_data( 'steadfast_is_sent', 'yes' );
	}

	public function normalise( $raw ) {
		$slug = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', (string) $raw ), '_' ) );
		$map  = self::map();
		if ( isset( $map[ $slug ] ) ) {
			return array( 'slug' => $slug, 'label' => $map[ $slug ][0], 'group' => $map[ $slug ][1], 'final' => $map[ $slug ][2] );
		}
		return array(
			'slug'  => $slug,
			'label' => '' === $slug ? '—' : ucfirst( str_replace( '_', ' ', $slug ) ),
			'group' => 'transit',
			'final' => false,
		);
	}

	public function tracking_url( WC_Order $order ) {
		$ref = $this->get( $order, 'ref' );
		if ( '' === $ref ) {
			return '';
		}
		return strtr( (string) RWSC_Api_Settings::get( 'sf_tracking_template' ), array( '{tracking_code}' => rawurlencode( $ref ) ) );
	}

	/* ---------------- Webhook ---------------- */

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function webhook_url() {
		return rest_url( 'ptc/v1/steadfast' );
	}

	public static function register() {
		register_rest_route(
			'ptc/v1',
			'/steadfast',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);
	}

	/** Bearer token must be configured AND match; an empty token rejects everything. */
	public static function authorize( WP_REST_Request $req ) {
		$token = (string) RWSC_Api_Settings::get( 'sf_webhook_token' );
		if ( '' === $token ) {
			return new WP_Error( 'rwsc_forbidden', 'Webhook token not configured.', array( 'status' => 401 ) );
		}
		$h = (string) $req->get_header( 'authorization' );
		if ( 0 === stripos( $h, 'bearer ' ) ) {
			$h = substr( $h, 7 );
		}
		if ( ! hash_equals( $token, trim( $h ) ) ) {
			return new WP_Error( 'rwsc_forbidden', 'Invalid token.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function handle( WP_REST_Request $req ) {
		$p    = $req->get_json_params();
		$p    = is_array( $p ) ? $p : $req->get_params();
		$type = sanitize_key( (string) ( $p['notification_type'] ?? 'delivery_status' ) );
		$cid  = sanitize_text_field( (string) ( $p['consignment_id'] ?? '' ) );
		update_option( 'rwsc_last_webhook_sf', time(), false );
		$ack = static function ( $msg ) {
			return new WP_REST_Response( array( 'status' => 'success', 'message' => $msg ), 200 );
		};
		if ( '' === $cid ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'consignment_id required' ), 400 );
		}
		$status = sanitize_text_field( (string) ( $p['status'] ?? '' ) );
		if ( 'delivery_status' !== $type || '' === $status ) {
			return $ack( 'ignored' ); // tracking_update etc.: acknowledged, nothing to store.
		}
		$dedupe = 'rwsc_sfwh_' . md5( $cid . '|' . $status . '|' . ( $p['updated_at'] ?? '' ) );
		if ( get_transient( $dedupe ) ) {
			return $ack( 'duplicate' );
		}
		set_transient( $dedupe, 1, 10 * MINUTE_IN_SECONDS );

		$mod   = RWSC_Couriers::get( 'steadfast' );
		$order = $mod->find_by_consignment( $cid );
		if ( ! $order ) {
			return $ack( 'unknown consignment' ); // Ack: avoids retry storms for foreign parcels.
		}
		$ts = ! empty( $p['updated_at'] ) ? (int) strtotime( (string) $p['updated_at'] . ' +06:00' ) : 0;
		$mod->apply_status( $order, $status, $ts, 'webhook' );
		return $ack( 'ok' );
	}
}
