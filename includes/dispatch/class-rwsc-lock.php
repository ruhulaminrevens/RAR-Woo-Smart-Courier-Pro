<?php
/**
 * Named locks. Two staff members pressing "Send" on the same order, or two
 * requests refreshing the API token, must never run concurrently.
 *
 * Uses MySQL/MariaDB GET_LOCK (proven pattern on the RAR stock plugins) and
 * falls back to an atomic add_option() lock where GET_LOCK is unavailable.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Lock {

	/** @var array<string,string> name => mode (db|opt) */
	private static $held = array();

	private static function db_name( $name ) {
		return 'rwsc_' . substr( md5( $GLOBALS['wpdb']->prefix . $name ), 0, 40 );
	}

	/**
	 * @param string $name    Lock name, e.g. "send_123".
	 * @param int    $timeout Seconds to wait.
	 * @param int    $ttl     Seconds before an option-style lock is considered stale.
	 */
	public static function acquire( $name, $timeout = 5, $ttl = 60 ) {
		global $wpdb;

		$res = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::db_name( $name ), (int) $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '1' === (string) $res ) {
			self::$held[ $name ] = 'db';
			return true;
		}
		if ( '0' === (string) $res ) {
			return false; // Genuinely held by someone else.
		}

		// GET_LOCK unsupported (returned NULL / error): option based fallback.
		$opt      = 'rwsc_lock_' . md5( $name );
		$deadline = microtime( true ) + $timeout;
		do {
			if ( add_option( $opt, time() + $ttl, '', 'no' ) ) {
				self::$held[ $name ] = 'opt';
				return true;
			}
			$expires = (int) get_option( $opt, 0 );
			if ( $expires && $expires < time() ) {
				delete_option( $opt );
				continue;
			}
			usleep( 150000 );
		} while ( microtime( true ) < $deadline );

		return false;
	}

	public static function release( $name ) {
		global $wpdb;
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		if ( 'db' === self::$held[ $name ] ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::db_name( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			delete_option( 'rwsc_lock_' . md5( $name ) );
		}
		unset( self::$held[ $name ] );
	}
}
