<?php
/**
 * Courier API settings store (own option `rwsc_api`). On first use it imports
 * the credentials/settings of RAR Courier Suite (`pt_hms_settings`) so that
 * plugin can be removed without re-entering anything. Encryption format and
 * key are identical, so encrypted values are copied as-is.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Api_Settings {

	const OPTION       = 'rwsc_api';
	const SUITE_OPTION = 'pt_hms_settings';
	const SUITE_TOKEN  = 'pt_hms_token_data';

	/** Fields stored encrypted at rest. */
	const SECRETS = array( 'client_secret', 'password', 'redx_token', 'sf_api_key', 'sf_secret_key', 'sf_webhook_token', 'pf_password', 'pf_key' );

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			// Connection.
			'client_id'               => '',
			'client_secret'           => '',
			'username'                => '',
			'password'                => '',
			'environment'             => 'live',
			'auth_mode'               => 'auto', // auto | client | password.
			'webhook_secret'          => '',
			// Dispatch defaults.
			'default_store_id'        => 0,
			'delivery_type'           => 48,
			'item_type'               => 2,
			'fallback_weight'         => 0.5,
			'inherit_smart_courier'   => 'yes',
			'location_mode'           => 'auto', // auto | city_only | smart.
			'prepaid_gateways'        => '',
			'description_template'    => '{items}',
			'instruction_template'    => '{customer_note}',
			'queue_statuses'          => array( 'wc-processing' ),
			'courier_guard'           => 'yes',
			'bulk_mode'               => 'individual', // individual | native.
			// Automation.
			'auto_send_status'        => '',
			'status_on_sent'          => '',
			'status_on_delivered'     => '',
			'status_on_returned'      => '',
			'add_order_notes'         => 'yes',
			'sync_enabled'            => 'yes',
			'sync_batch'              => 25,
			'sync_max_age_days'       => 45,
			// Customer facing.
			'show_my_account'         => 'yes',
			'show_in_emails'          => 'yes',
			'tracking_url_template'   => 'https://merchant.pathao.com/tracking?consignment_id={consignment_id}&phone={phone}',
			// Courier Suite.
			'auto_courier'            => 'pathao', // Used by auto-send when the customer did not choose a courier.
			'block_cross_courier'     => 'yes',
			'enable_redx'             => 'no',
			'redx_token'              => '',
			'redx_env'                => 'live', // live | sandbox.
			'redx_base_url'           => '',
			'redx_mode'               => 'standard', // standard (vendor WordPress endpoint) | official (area based).
			'redx_pickup_store_id'    => 0,
			'redx_tracking_template'  => 'https://redx.com.bd/track-global-parcel/?trackingId={id}',
			'enable_steadfast'        => 'no',
			'sf_api_key'              => '',
			'sf_secret_key'           => '',
			'sf_webhook_token'        => '',
			'sf_base_url'             => '',
			'sf_send_note'            => 'yes',
			'sf_tracking_template'    => 'https://steadfast.com.bd/t/{tracking_code}',
			'enable_paperfly'         => 'no',
			'pf_username'             => '',
			'pf_password'             => '',
			'pf_key'                  => '',
			'pf_env'                  => 'live', // live | sandbox.
			'pf_base_url'             => '',
			'pf_create_path'          => 'OrderPlacement',
			'pf_merchant_code'        => '',
			'pf_pick_name'            => '',
			'pf_pick_address'         => '',
			'pf_pick_thana'           => '',
			'pf_pick_district'        => '',
			'pf_pick_phone'           => '',
			'pf_delivery_option'      => 'regular',
			'pf_tracking_template'    => 'https://paperfly.com.bd/',
			'enable_sundarban'        => 'no',
			'sd_tracking_template'    => 'https://tracking.sundarbancourierltd.com/',
			// Advanced.
			'debug_log'               => 'no',
			'delete_data_on_uninstall' => 'no',
		);
	}

	/**
	 * All settings, secrets decrypted, wp-config constants applied.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		self::maybe_import_suite();
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = wp_parse_args( $saved, self::defaults() );

		foreach ( self::SECRETS as $key ) {
			$out[ $key ] = self::decrypt( (string) $out[ $key ] );
		}

		// Optional wp-config.php overrides keep credentials out of the database.
		if ( defined( 'PATHAO_CLIENT_ID' ) ) {
			$out['client_id'] = (string) PATHAO_CLIENT_ID;
		}
		if ( defined( 'PATHAO_CLIENT_SECRET' ) ) {
			$out['client_secret'] = (string) PATHAO_CLIENT_SECRET;
		}
		if ( defined( 'PATHAO_ENVIRONMENT' ) && in_array( PATHAO_ENVIRONMENT, array( 'live', 'staging' ), true ) ) {
			$out['environment'] = PATHAO_ENVIRONMENT;
		}

		$out['queue_statuses'] = array_values( array_filter( (array) $out['queue_statuses'] ) );
		self::$cache           = $out;
		return $out;
	}

	/**
	 * Import RAR Courier Suite settings. Without $force it only runs when Pro has no
	 * API settings yet and never overwrites anything.
	 *
	 * @param bool $force Fill blank Pro values from the Suite even if Pro settings exist.
	 * @return bool True when something was imported.
	 */
	public static function maybe_import_suite( $force = false ) {
		static $done = false;
		if ( $done && ! $force ) {
			return false;
		}
		$done  = true;
		$own   = get_option( self::OPTION, null );
		$suite = get_option( self::SUITE_OPTION, null );
		if ( ( ! $force && null !== $own ) || ! is_array( $suite ) || empty( $suite ) ) {
			return false;
		}
		$merged = is_array( $own ) ? array_merge( $suite, array_filter( $own, 'strlen' ) ) : $suite;
		update_option( self::OPTION, $merged, false );
		$token = get_option( self::SUITE_TOKEN, null );
		if ( $token && false === get_option( RWSC_Pathao_API::TOKEN_OPTION, false ) ) {
			update_option( RWSC_Pathao_API::TOKEN_OPTION, $token, false );
		}
		update_option( 'rwsc_suite_imported', time(), false );
		self::$cache = null;
		return true;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function yes( $key ) {
		return 'yes' === self::get( $key );
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function is_configured() {
		return '' !== self::get( 'client_id' ) && '' !== self::get( 'client_secret' );
	}

	/**
	 * Secret used to verify X-PATHAO-Signature. Pathao's default is the client secret.
	 */
	public static function webhook_secret() {
		$secret = (string) self::get( 'webhook_secret' );
		return '' !== $secret ? $secret : (string) self::get( 'client_secret' );
	}

	/**
	 * Sanitise and persist posted settings. Blank secret fields keep the stored value.
	 *
	 * @param array $posted Unslashed $_POST['rpc'] array.
	 * @return array Result: array( 'credentials_changed' => bool ).
	 */
	public static function save( array $posted ) {
		$current  = self::all();
		$defaults = self::defaults();
		$new      = $current;

		$text = array( 'client_id', 'username', 'webhook_secret', 'description_template', 'instruction_template', 'prepaid_gateways', 'pf_username', 'pf_merchant_code', 'pf_pick_name', 'pf_pick_address', 'pf_pick_thana', 'pf_pick_district', 'pf_pick_phone' );
		foreach ( $text as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$new[ $key ] = sanitize_textarea_field( (string) $posted[ $key ] );
			}
		}
		foreach ( self::SECRETS as $key ) {
			if ( ! empty( $posted[ 'clear_' . $key ] ) ) {
				$new[ $key ] = '';
			} elseif ( isset( $posted[ $key ] ) && '' !== trim( (string) $posted[ $key ] ) ) {
				$new[ $key ] = sanitize_text_field( (string) $posted[ $key ] );
			}
		}
		if ( isset( $posted['environment'] ) ) {
			$new['environment'] = 'staging' === $posted['environment'] ? 'staging' : 'live';
		}

		$enum = array(
			'auth_mode'     => array( 'auto', 'client', 'password' ),
			'location_mode' => array( 'auto', 'city_only', 'smart' ),
			'bulk_mode'     => array( 'individual', 'native' ),
			'redx_env'      => array( 'live', 'sandbox' ),
			'redx_mode'     => array( 'standard', 'official' ),
			'auto_courier'  => array( 'pathao', 'redx', 'steadfast', 'paperfly' ),
			'pf_env'        => array( 'live', 'sandbox' ),
			'pf_delivery_option' => array( 'regular', 'express' ),
		);
		foreach ( $enum as $key => $allowed ) {
			if ( isset( $posted[ $key ] ) && in_array( $posted[ $key ], $allowed, true ) ) {
				$new[ $key ] = $posted[ $key ];
			}
		}

		if ( isset( $posted['delivery_type'] ) && in_array( (int) $posted['delivery_type'], array( 12, 48 ), true ) ) {
			$new['delivery_type'] = (int) $posted['delivery_type'];
		}
		if ( isset( $posted['item_type'] ) && in_array( (int) $posted['item_type'], array( 1, 2 ), true ) ) {
			$new['item_type'] = (int) $posted['item_type'];
		}
		if ( isset( $posted['redx_pickup_store_id'] ) ) {
			$new['redx_pickup_store_id'] = absint( $posted['redx_pickup_store_id'] );
		}
		if ( isset( $posted['default_store_id'] ) ) {
			$new['default_store_id'] = absint( $posted['default_store_id'] );
		}
		if ( isset( $posted['fallback_weight'] ) ) {
			$new['fallback_weight'] = min( 10, max( 0.5, (float) $posted['fallback_weight'] ) );
		}
		if ( isset( $posted['sync_batch'] ) ) {
			$new['sync_batch'] = min( 100, max( 5, absint( $posted['sync_batch'] ) ) );
		}
		if ( isset( $posted['sync_max_age_days'] ) ) {
			$new['sync_max_age_days'] = min( 180, max( 7, absint( $posted['sync_max_age_days'] ) ) );
		}

		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
		foreach ( array( 'auto_send_status', 'status_on_sent', 'status_on_delivered', 'status_on_returned' ) as $key ) {
			$val         = isset( $posted[ $key ] ) ? sanitize_text_field( (string) $posted[ $key ] ) : '';
			$new[ $key ] = in_array( $val, $wc_statuses, true ) ? $val : '';
		}
		$queue                 = isset( $posted['queue_statuses'] ) ? (array) $posted['queue_statuses'] : array();
		$new['queue_statuses'] = array_values( array_intersect( array_map( 'sanitize_text_field', $queue ), $wc_statuses ) );

		$flags = array( 'inherit_smart_courier', 'courier_guard', 'add_order_notes', 'sync_enabled', 'show_my_account', 'show_in_emails', 'debug_log', 'delete_data_on_uninstall', 'block_cross_courier', 'enable_redx', 'enable_steadfast', 'sf_send_note', 'enable_paperfly', 'enable_sundarban' );
		foreach ( $flags as $key ) {
			$new[ $key ] = ! empty( $posted[ $key ] ) ? 'yes' : 'no';
		}

		if ( isset( $posted['pf_create_path'] ) ) {
			$p                     = preg_replace( '/[^A-Za-z0-9\/_\-]/', '', (string) $posted['pf_create_path'] );
			$new['pf_create_path'] = '' !== $p ? $p : 'OrderPlacement';
		}
		foreach ( array( 'redx_base_url', 'sf_base_url', 'pf_base_url' ) as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$new[ $key ] = esc_url_raw( trim( (string) $posted[ $key ] ), array( 'https' ) );
			}
		}
		foreach ( array( 'redx_tracking_template' => '{id}', 'sf_tracking_template' => '{tracking_code}', 'pf_tracking_template' => '{id}', 'sd_tracking_template' => '{id}' ) as $key => $token ) {
			if ( ! isset( $posted[ $key ] ) ) {
				continue; // Field not on the submitted form: keep the stored value.
			}
			$v           = esc_url_raw( trim( (string) $posted[ $key ] ), array( 'https', 'http' ) );
			$static_ok   = in_array( $key, array( 'pf_tracking_template', 'sd_tracking_template' ), true );
			$new[ $key ] = ( '' !== $v && ( $static_ok || false !== strpos( rawurldecode( $v ), $token ) ) ) ? $v : $defaults[ $key ];
		}

		if ( isset( $posted['tracking_url_template'] ) ) {
			$tpl = esc_url_raw( (string) $posted['tracking_url_template'], array( 'https', 'http' ) );
			$new['tracking_url_template'] = '' !== $tpl ? $tpl : $defaults['tracking_url_template'];
		}

		$changed = $current['client_id'] !== $new['client_id']
			|| $current['client_secret'] !== $new['client_secret']
			|| $current['environment'] !== $new['environment']
			|| $current['username'] !== $new['username']
			|| $current['password'] !== $new['password']
			|| $current['auth_mode'] !== $new['auth_mode'];

		$store = $new;
		foreach ( self::SECRETS as $key ) {
			$store[ $key ] = self::encrypt( (string) $store[ $key ] );
		}
		// Constants must never be written back to the database.
		if ( defined( 'PATHAO_CLIENT_ID' ) ) {
			$store['client_id'] = (string) ( get_option( self::OPTION, array() )['client_id'] ?? '' );
		}
		if ( defined( 'PATHAO_CLIENT_SECRET' ) ) {
			$store['client_secret'] = (string) ( get_option( self::OPTION, array() )['client_secret'] ?? '' );
		}

		update_option( self::OPTION, $store, false );
		self::flush();

		if ( $changed ) {
			delete_option( RWSC_Pathao_API::TOKEN_OPTION );
			RWSC_Pathao_API::flush_cache();
		}
		return array( 'credentials_changed' => $changed );
	}

	/* ------------------------------------------------------------------ *
	 * Encryption at rest (AES-256-CBC, key derived from WordPress salts).
	 * Values that cannot be decrypted (salts changed) read as empty so the
	 * admin is prompted to re-enter them instead of sending garbage to the API.
	 * ------------------------------------------------------------------ */

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|rpc', true );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plain;
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );
		return 'rpc1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'rpc1:' ) ) {
			return $stored; // Legacy plaintext value from the original plugin.
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $cipher, self::key(), true ) ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * True when a stored secret exists but cannot be decrypted (salts changed).
	 */
	public static function secrets_unreadable() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return false;
		}
		foreach ( self::SECRETS as $key ) {
			$v = (string) ( $saved[ $key ] ?? '' );
			if ( 0 === strpos( $v, 'rpc1:' ) && '' === self::decrypt( $v ) ) {
				return true;
			}
		}
		return false;
	}
}
