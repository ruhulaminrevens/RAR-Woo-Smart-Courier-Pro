<?php
/**
 * Logger writing to WooCommerce > Status > Logs (source: rar-woo-smart-courier).
 * Secrets and tokens are always redacted; phone numbers are masked.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Logger {

	const SOURCE = 'rar-woo-smart-courier';

	private static $redact_keys = array( 'client_secret', 'password', 'access_token', 'refresh_token', 'authorization', 'secret', 'webhook_secret', 'redx_token', 'sf_api_key', 'sf_secret_key', 'sf_webhook_token', 'api-access-token', 'api-key', 'secret-key', 'pf_password', 'pf_key', 'paperflykey', 'pf_username', 'secret_key', 'api_key', 'token', 'bearer', 'redx_api_token' );

	public static function debug( $message, array $context = array() ) {
		if ( RWSC_Api_Settings::yes( 'debug_log' ) ) {
			self::write( 'debug', $message, $context );
		}
	}

	public static function info( $message, array $context = array() ) {
		self::write( 'info', $message, $context );
	}

	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	private static function write( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$line = (string) $message;
		if ( $context ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}
		wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
	}

	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			$k = strtolower( (string) $key );
			if ( in_array( $k, self::$redact_keys, true ) ) {
				$data[ $key ] = '***';
			} elseif ( in_array( $k, array( 'recipient_phone', 'recipient_secondary_phone', 'phone' ), true ) && is_string( $value ) ) {
				$data[ $key ] = strlen( $value ) > 4 ? str_repeat( '*', strlen( $value ) - 4 ) . substr( $value, -4 ) : '****';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}

	/**
	 * Path of the most recent log file for the in-admin viewer (read-only, tail).
	 */
	public static function latest_file() {
		if ( ! defined( 'WC_LOG_DIR' ) || ! is_dir( WC_LOG_DIR ) ) {
			return '';
		}
		$files = glob( trailingslashit( WC_LOG_DIR ) . self::SOURCE . '-*.log' );
		if ( ! $files ) {
			return '';
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);
		return $files[0];
	}

	public static function tail( $lines = 80 ) {
		$file = self::latest_file();
		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}
		$size = filesize( $file );
		$fh   = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		fseek( $fh, max( 0, $size - 60000 ) );
		$chunk = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$rows = explode( "\n", trim( (string) $chunk ) );
		return implode( "\n", array_slice( $rows, -1 * (int) $lines ) );
	}
}
