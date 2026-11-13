<?php
/**
 * Courier registry: Pathao (RWSC_Pathao_Orders engine) + RedX + SteadFast modules.
 * Routing (RAR Woo Smart Courier choice), cross-courier state, shared UI cells,
 * bulk helpers, legacy import and the combined status sync.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Couriers {

	const BULK_LIMIT = 25;

	/** @var array|null */
	private static $mods = null;

	public static function modules() {
		if ( null === self::$mods ) {
			self::$mods = array(
				'redx'      => new RWSC_RedX(),
				'steadfast' => new RWSC_SteadFast(),
				'paperfly'  => new RWSC_Paperfly(),
				'sundarban' => new RWSC_Sundarban(),
			);
		}
		return self::$mods;
	}

	/** @return RWSC_Courier_Base|null */
	public static function get( $slug ) {
		$m = self::modules();
		return $m[ $slug ] ?? null;
	}

	public static function label( $slug ) {
		if ( 'pathao' === $slug ) {
			return 'Pathao';
		}
		$m = self::get( $slug );
		return $m ? $m->label() : (string) $slug;
	}

	public static function slugs() {
		return array( 'pathao', 'redx', 'steadfast', 'paperfly', 'sundarban' );
	}

	/** Courier slug from free text ("Pathao Courier", "Red X", "Steadfast"). */
	public static function detect( $text ) {
		$t = strtolower( (string) $text );
		if ( '' === trim( $t ) ) {
			return '';
		}
		if ( false !== strpos( $t, 'pathao' ) || false !== strpos( $t, 'পাঠাও' ) ) {
			return 'pathao';
		}
		if ( preg_match( '/red\s*-?\s*x|redx|রেডএক্স|রেডেক্স/u', $t ) ) {
			return 'redx';
		}
		if ( preg_match( '/stead\s*-?\s*fast|packzy|স্টিডফাস্ট|স্টেডফাস্ট/u', $t ) ) {
			return 'steadfast';
		}
		if ( preg_match( '/paper\s*-?\s*fly|paperfly|পেপারফ্লাই|পেপার\s*ফ্লাই|পেপারফ্লাই/u', $t ) ) {
			return 'paperfly';
		}
		if ( preg_match( '/sundar\s*-?\s*ban|sundor\s*-?\s*ban|sundorbon|সুন্দরবন/u', $t ) ) {
			return 'sundarban';
		}
		return '';
	}

	/** Slug of the courier that already holds a parcel for this order ('' = none). */
	public static function sent_via( WC_Order $order ) {
		if ( RWSC_Pathao_Orders::is_sent( $order ) ) {
			return 'pathao';
		}
		foreach ( self::modules() as $slug => $m ) {
			if ( $m->is_sent( $order ) ) {
				return $slug;
			}
		}
		return '';
	}

	public static function usable( $slug ) {
		if ( 'pathao' === $slug ) {
			return RWSC_Api_Settings::is_configured();
		}
		$m = self::get( $slug );
		return $m && $m->enabled() && $m->configured();
	}


	/**
	 * Which courier should automation use? Honours the customer's RAR Smart Courier choice.
	 *
	 * @return string Slug, or '' to skip.
	 */
	public static function pick_for_auto( WC_Order $order ) {
		$chosen_text = RWSC_Order_Data::chosen_courier( $order );
		if ( '' !== $chosen_text ) {
			$slug = self::detect( $chosen_text );
			return ( '' !== $slug && self::usable( $slug ) && ! ( self::get( $slug ) && self::get( $slug )->manual() ) ) ? $slug : ''; // Unsupported courier chosen: leave it to staff.
		}
		$def = (string) RWSC_Api_Settings::get( 'auto_courier', 'pathao' );
		return ( self::usable( $def ) && ! ( self::get( $def ) && self::get( $def )->manual() ) ) ? $def : '';
	}

	/** Automation entry for a non-Pathao courier. */
	public static function auto_send_module( $slug, $order_id ) {
		$m = self::get( $slug );
		if ( ! $m ) {
			return;
		}
		$res = $m->send( $order_id, 0 );
		if ( is_wp_error( $res ) && ! in_array( $res->get_error_code(), array( 'rwsc_already_sent', 'rwsc_busy' ), true ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( sprintf( /* translators: 1: courier 2: error */ __( '%1$s auto-send failed: %2$s', 'rar-woo-smart-courier' ), $m->label(), $res->get_error_message() ) );
			}
		}
	}

	/** Pathao's own tracking rows + modules, for My Account / e-mails. */
	public static function rows( WC_Order $order ) {
		$rows = array();
		if ( RWSC_Pathao_Orders::has_real_consignment( $order ) ) {
			$i      = RWSC_Pathao_Status::info( (string) RWSC_Pathao_Orders::get( $order, RWSC_Pathao_Orders::META_STATUS ) );
			$rows[] = array( 'courier' => 'Pathao', 'id' => RWSC_Pathao_Orders::consignment( $order ), 'status' => $i['label'], 'url' => RWSC_Pathao_Orders::tracking_url( $order ) );
		}
		foreach ( self::modules() as $m ) {
			if ( $m->is_sent( $order ) ) {
				$n      = $m->normalise( $m->get( $order, 'status' ) );
				$rows[] = array( 'courier' => $m->label(), 'id' => $m->consignment( $order ), 'status' => $n['label'], 'url' => $m->tracking_url( $order ) );
			}
		}
		return $rows;
	}

	/** Brand chip (coloured dot + name) used across the UI. */
	public static function chip( $slug ) {
		return '<span class="rwsc-d-chip rwsc-d-c-' . esc_attr( sanitize_html_class( $slug ) ) . '">' . esc_html( self::label( $slug ) ) . '</span>';
	}

	/** Meta key that holds the consignment / parcel id of a courier. */
	public static function id_key( $slug ) {
		if ( 'pathao' === $slug ) {
			return RWSC_Pathao_Orders::META_ID;
		}
		$m = self::get( $slug );
		return $m ? $m->key( 'id' ) : '';
	}

	/**
	 * Unified parcel facts for an order (first courier that holds a parcel).
	 *
	 * @return array|null courier, id, raw, label, group, final, amount, sent_at
	 */
	public static function parcel_info( WC_Order $order ) {
		$via = self::sent_via( $order );
		if ( '' === $via ) {
			return null;
		}
		if ( 'pathao' === $via ) {
			$raw = (string) RWSC_Pathao_Orders::get( $order, RWSC_Pathao_Orders::META_STATUS );
			$i   = RWSC_Pathao_Status::info( $raw );
			return array(
				'courier' => 'pathao',
				'id'      => RWSC_Pathao_Orders::consignment( $order ),
				'raw'     => $raw,
				'label'   => $i['label'],
				'group'   => $i['group'],
				'final'   => (bool) $i['final'],
				'amount'  => (float) $order->get_meta( 'ptc_amount_to_collect', true ),
				'sent_at' => (int) $order->get_meta( 'ptc_sent_at', true ),
				'fee'     => (float) RWSC_Pathao_Orders::get( $order, RWSC_Pathao_Orders::META_FEE ),
			);
		}
		$m   = self::get( $via );
		$raw = (string) $m->get( $order, 'status' );
		$n   = $m->normalise( $raw );
		return array(
			'courier' => $via,
			'id'      => $m->consignment( $order ),
			'raw'     => $raw,
			'label'   => $n['label'],
			'group'   => $n['group'],
			'final'   => (bool) $n['final'],
			'amount'  => (float) $m->get( $order, 'amount' ),
			'sent_at' => (int) $m->get( $order, 'sent_at' ),
			'fee'     => 0.0,
		);
	}

	/** Order-list cell: the parcel (any courier) or send buttons for every usable courier. */
	public static function cell_html( WC_Order $order ) {
		$via = self::sent_via( $order );
		if ( 'pathao' === $via ) {
			return RWSC_Pathao_Orders::cell_html( $order );
		}
		if ( '' !== $via ) {
			return '<div>' . self::get( $via )->sent_html( $order ) . '</div>';
		}
		$chosen = self::detect( RWSC_Order_Data::selected_courier( $order ) );
		$btns   = '';
		if ( RWSC_Api_Settings::is_configured() ) {
			$btns .= '<button type="button" class="button button-small rwsc-d-open' . ( 'pathao' === $chosen ? ' button-primary' : '' ) . '" data-order-ids="' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Pathao', 'rar-woo-smart-courier' ) . '</button> ';
		}
		foreach ( self::modules() as $slug => $m ) {
			if ( ! $m->enabled() ) {
				continue;
			}
			$btns .= '<button type="button" class="button button-small rwsc-d-cs' . ( $m->manual() ? ' rwsc-d-manual' : '' ) . ( $chosen === $slug ? ' button-primary' : '' ) . '" data-courier="' . esc_attr( $slug ) . '" data-order-id="' . esc_attr( $order->get_id() ) . '">' . esc_html( $m->label() ) . '</button> ';
		}
		if ( '' === $btns ) {
			$btns = '<a href="' . esc_url( admin_url( 'admin.php?page=rar-woo-smart-courier&tab=api' ) ) . '" class="rwsc-d-muted">' . esc_html__( 'Connect a courier', 'rar-woo-smart-courier' ) . '</a>';
		}
		$err = '';
		foreach ( self::modules() as $m ) {
			$e = $m->get( $order, 'last_error' );
			if ( '' !== $e && $m->enabled() ) {
				$err .= '<div class="rwsc-d-error rwsc-d-muted">' . esc_html( $m->label() . ': ' . self::cut( $e, 140 ) ) . '</div>';
			}
		}
		$pe = (string) $order->get_meta( 'ptc_last_error', true );
		if ( '' !== $pe && RWSC_Api_Settings::is_configured() ) {
			$err .= '<div class="rwsc-d-error rwsc-d-muted">' . esc_html( 'Pathao: ' . self::cut( $pe, 140 ) ) . '</div>';
		}
		$hint = '' !== RWSC_Order_Data::selected_courier( $order ) ? '<div class="rwsc-d-muted">' . esc_html( sprintf( /* translators: %s: courier */ __( 'Customer chose: %s', 'rar-woo-smart-courier' ), RWSC_Order_Data::selected_courier( $order ) ) ) . '</div>' : '';
		return '<div class="rwsc-d-btns">' . $btns . '</div>' . $err . $hint;
	}

	/** Wrapper used by every list / meta box so both AJAX updaters can refresh it. */
	public static function cell_wrap( WC_Order $order ) {
		return '<div class="rwsc-d-cell rwsc-d-ccell" data-order-id="' . esc_attr( $order->get_id() ) . '">' . self::cell_html( $order ) . '</div>';
	}

	private static function cut( $s, $n ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $s, 0, $n ) : substr( (string) $s, 0, $n );
	}

	/**
	 * Sequential bulk send/refresh used by order-list bulk actions (capped).
	 *
	 * @return array array( ok => int, fail => int, first_error => string )
	 */
	public static function bulk( $slug, $action, array $ids, $user_id ) {
		$m = self::get( $slug );
		$r = array( 'ok' => 0, 'fail' => 0, 'first_error' => '' );
		if ( ! $m ) {
			return $r;
		}
		foreach ( array_slice( array_values( array_unique( array_map( 'absint', $ids ) ) ), 0, self::BULK_LIMIT ) as $id ) {
			if ( 'send' === $action ) {
				$res = $m->send( $id, $user_id );
			} else {
				$order = wc_get_order( $id );
				$res   = ( $order && $m->is_sent( $order ) ) ? $m->refresh( $order, 'bulk' ) : new WP_Error( 'rwsc_skip', __( 'Not sent with this courier.', 'rar-woo-smart-courier' ) );
			}
			if ( is_wp_error( $res ) ) {
				++$r['fail'];
				if ( '' === $r['first_error'] ) {
					$r['first_error'] = '#' . $id . ' ' . $res->get_error_message();
				}
			} else {
				++$r['ok'];
			}
		}
		return $r;
	}

	/** Last-30-day parcel counts per courier, Pathao included (cached 15 min). */
	public static function summary() {
		$c = get_transient( 'rwsc_suite_summary' );
		if ( is_array( $c ) && isset( $c['pathao'] ) ) {
			return $c;
		}
		$out = array();
		foreach ( self::slugs() as $slug ) {
			$row = array( 'sent' => 0, 'pending' => 0, 'transit' => 0, 'delivered' => 0, 'returned' => 0, 'attention' => 0, 'cod_open' => 0, 'fees' => 0, 'capped' => false );
			$ids = RWSC_Pathao_Orders::query(
				array(
					'limit'        => 500,
					'return'       => 'ids',
					'type'         => 'shop_order',
					'status'       => array_keys( wc_get_order_statuses() ),
					'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
					'meta_query'   => array( array( 'key' => self::id_key( $slug ), 'value' => array( '', '-' ), 'compare' => 'NOT IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$row['capped'] = count( $ids ) >= 500;
			foreach ( $ids as $id ) {
				$o = wc_get_order( $id );
				if ( ! $o ) {
					continue;
				}
				if ( 'pathao' === $slug ) {
					$i = RWSC_Pathao_Status::info( (string) RWSC_Pathao_Orders::get( $o, RWSC_Pathao_Orders::META_STATUS ) );
					$g = $i['group'];
					$f = $i['final'];
					$a = (float) $o->get_meta( 'ptc_amount_to_collect', true );
					$row['fees'] += (float) RWSC_Pathao_Orders::get( $o, RWSC_Pathao_Orders::META_FEE );
				} else {
					$m = self::get( $slug );
					$n = $m->normalise( $m->get( $o, 'status' ) );
					$g = $n['group'];
					$f = $n['final'];
					$a = (float) $m->get( $o, 'amount' );
				}
				++$row['sent'];
				if ( isset( $row[ $g ] ) ) {
					++$row[ $g ];
				}
				if ( ! $f ) {
					$row['cod_open'] += (int) $a;
				}
			}
			$out[ $slug ] = $row;
		}
		set_transient( 'rwsc_suite_summary', $out, 15 * MINUTE_IN_SECONDS ); // Cleared on every send / status change.
		return $out;
	}

	/** Combined cron sync for the non-Pathao couriers. */
	public static function sync_all( $deadline ) {
		$days  = (int) RWSC_Api_Settings::get( 'sync_max_age_days', 45 );
		$batch = (int) RWSC_Api_Settings::get( 'sync_batch', 25 );
		foreach ( self::modules() as $m ) {
			if ( $m->manual() || ! $m->enabled() || ! $m->configured() ) {
				continue;
			}
			foreach ( $m->sync_ids( $batch, $days ) as $id ) {
				if ( microtime( true ) > $deadline ) {
					return;
				}
				$order = wc_get_order( $id );
				if ( ! $order ) {
					continue;
				}
				$res = $m->refresh( $order, 'sync' );
				if ( is_wp_error( $res ) ) {
					$order->update_meta_data( $m->key( 'last_sync' ), time() ); // Do not hammer a failing parcel.
					$order->save_meta_data();
					if ( in_array( $res->get_error_code(), array( 'rwsc_no_credentials' ), true ) || 0 === strpos( (string) $res->get_error_code(), 'rwsc_transport' ) ) {
						RWSC_Logger::error( $m->label() . ' sync aborted: ' . $res->get_error_message() );
						break;
					}
				}
			}
		}
	}

	/**
	 * Import parcels created by the vendor plugins (postmeta) into this plugin's meta.
	 * Works for both storage modes because the vendor plugins wrote to wp_postmeta.
	 *
	 * @return int Orders imported.
	 */
	public static function import_legacy( $limit = 500 ) {
		global $wpdb;
		$n      = 0;
		$limit  = max( 1, min( 500, (int) $limit ) );
		$cursor = get_option( 'rwsc_legacy_import_cursor', array() );
		$cursor = is_array( $cursor ) ? $cursor : array();
		foreach ( self::modules() as $m ) {
			$key = $m->legacy_id_key();
			if ( '' === $key ) {
				continue;
			}
			$slug = $m->slug();
			$after = (int) ( $cursor[ $slug ] ?? 0 );
			// Per-courier ID cursor: repeated clicks scan the next batch rather than
			// always checking the same earliest 500 legacy postmeta entries.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				$key, $after, $limit
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				$after = max( $after, (int) $id );
				$order = wc_get_order( (int) $id );
				if ( ! $order instanceof WC_Order || '' !== (string) $m->get( $order, 'id' ) ) {
					continue;
				}
				$cid = (string) get_post_meta( (int) $id, $key, true );
				if ( '' === $cid ) {
					continue;
				}
				$order->update_meta_data( $m->key( 'id' ), $cid );
				if ( 'steadfast' === $slug ) {
					$st = (string) get_post_meta( (int) $id, 'stdf_delivery_status', true );
					if ( '' !== $st ) {
						$order->update_meta_data( $m->key( 'status' ), $st );
					}
				}
				$order->save_meta_data();
				if ( class_exists( 'RWSC_Bridge' ) ) {
					RWSC_Bridge::sync( $order, array( 'notify' => false, 'history' => true ) );
				}
				++$n;
			}
			$cursor[ $slug ] = $after;
		}
		update_option( 'rwsc_legacy_import_cursor', $cursor, false );
		return $n;
	}
}
