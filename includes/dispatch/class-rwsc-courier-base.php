<?php
/**
 * Shared engine for the non-Pathao couriers (RedX, SteadFast).
 *
 * Guarantees (same as the Pathao module):
 *  - one named lock per order across ALL couriers (no double parcels from double clicks / bulk retries);
 *  - a POST that dies on the network is never retried and blocks every courier for 10 minutes ("uncertain");
 *  - a second courier cannot be used on an order that already has a consignment (setting, default on);
 *  - HPOS-safe: order meta API only (legacy postmeta is read as fallback);
 *  - status updates are idempotent, ordered and never move a final parcel backwards.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

abstract class RWSC_Courier_Base {

	/** @var bool Re-entrancy guard for WC status changes. */
	protected static $busy = false;

	abstract public function slug();
	abstract public function prefix();
	abstract public function label();
	/** Meta key used by the vendor's own plugin to store the consignment/tracking id. */
	abstract public function legacy_id_key();
	abstract public function configured();
	/** @return array Editable field set for this order. */
	abstract public function build( WC_Order $order );
	/** @return array field => message */
	abstract public function validate( array $f );
	/** @return array|WP_Error array( id, ref, status ) */
	abstract protected function api_create( array $f, WC_Order $order );
	/** @return array|WP_Error array( status => raw, ts => int ) */
	abstract protected function api_status( WC_Order $order );
	/** @return array array( slug, label, group, final ) */
	abstract public function normalise( $raw );
	abstract public function tracking_url( WC_Order $order );

	/** True for couriers without an API (staff record the consignment no. by hand). */
	public function manual() {
		return false;
	}

	public function enabled() {
		return RWSC_Api_Settings::yes( 'enable_' . $this->slug() );
	}

	public function key( $k ) {
		return $this->prefix() . '_' . $k;
	}

	/* ---------------- Meta ---------------- */

	public function get( WC_Order $order, $k ) {
		return (string) $order->get_meta( $this->key( $k ), true );
	}

	public function consignment( WC_Order $order ) {
		$v = $this->get( $order, 'id' );
		if ( '' !== $v ) {
			return $v;
		}
		if ( '' === $this->legacy_id_key() ) {
			return '';
		}
		$legacy = (string) $order->get_meta( $this->legacy_id_key(), true );
		if ( '' === $legacy && RWSC_Pathao_Orders::hpos_enabled() ) {
			$legacy = (string) get_post_meta( $order->get_id(), $this->legacy_id_key(), true );
		}
		if ( '' !== $legacy ) {
			$order->update_meta_data( $this->key( 'id' ), $legacy );
			$order->save_meta_data();
		}
		return $legacy;
	}

	public function is_sent( WC_Order $order ) {
		return '' !== $this->consignment( $order );
	}

	public function events( WC_Order $order ) {
		$e = $order->get_meta( $this->key( 'events' ), true );
		return is_array( $e ) ? $e : array();
	}

	protected function add_event( WC_Order $order, $raw, $source, $ts = 0 ) {
		$events   = $this->events( $order );
		$events[] = array(
			't'   => $ts ? (int) $ts : time(),
			's'   => (string) $raw,
			'src' => (string) $source,
		);
		$order->update_meta_data( $this->key( 'events' ), array_slice( $events, -40 ) );
	}

	protected function note( WC_Order $order, $text ) {
		if ( RWSC_Api_Settings::yes( 'add_order_notes' ) ) {
			$order->add_order_note( $text );
		}
	}

	/* ---------------- Shared field helpers ---------------- */

	protected static function cut( $s, $len ) {
		$s = trim( (string) $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}

	protected static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s ) : strlen( (string) $s );
	}

	/** Fields every courier needs, computed by the same (tested) rules as Pathao. */
	protected function common( WC_Order $order ) {
		list( $kg )      = RWSC_Order_Data::weight_kg( $order );
		list( $collect ) = RWSC_Order_Data::amount_to_collect( $order );
		return array(
			'name'      => RWSC_Order_Data::recipient_name( $order ),
			'phone'     => RWSC_Order_Data::recipient_phone( $order ),
			'address'   => RWSC_Order_Data::recipient_address( $order ),
			'invoice'   => (string) $order->get_order_number(),
			'cod'       => (int) $collect,
			'total'     => (int) round( (float) $order->get_total() ),
			'weight_kg' => (float) $kg,
			'note'      => self::cut( $order->get_customer_note(), 400 ),
		);
	}

	protected function common_errors( array $f ) {
		$e = array();
		if ( self::len( $f['name'] ?? '' ) < 3 ) {
			$e['name'] = __( 'Recipient name must be at least 3 characters.', 'rar-woo-smart-courier' );
		}
		if ( ! preg_match( '/^01[3-9]\d{8}$/', (string) ( $f['phone'] ?? '' ) ) ) {
			$e['phone'] = __( 'Phone must be a valid 11-digit Bangladeshi mobile number.', 'rar-woo-smart-courier' );
		}
		if ( self::len( $f['address'] ?? '' ) < 10 ) {
			$e['address'] = __( 'Address must be at least 10 characters.', 'rar-woo-smart-courier' );
		}
		return $e;
	}

	/* ---------------- Send ---------------- */

	/**
	 * @param int   $order_id  Order.
	 * @param int   $user_id   Acting user (0 = automation).
	 * @param array $overrides Field overrides.
	 * @param array $opts      force => bool.
	 * @return array|WP_Error
	 */
	public function send( $order_id, $user_id = 0, array $overrides = array(), array $opts = array() ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'rwsc_no_order', __( 'Order not found.', 'rar-woo-smart-courier' ), array( 'status' => 404 ) );
		}
		if ( ! $this->enabled() || ! $this->configured() ) {
			/* translators: %s: courier */
			return new WP_Error( 'rwsc_not_configured', sprintf( __( '%s is not enabled / configured (Smart Courier → Courier APIs).', 'rar-woo-smart-courier' ), $this->label() ), array( 'status' => 400 ) );
		}

		$lock = 'send_' . $order->get_id(); // Same lock name as Pathao: mutual exclusion across couriers.
		if ( ! RWSC_Lock::acquire( $lock, 3, 90 ) ) {
			return new WP_Error( 'rwsc_busy', __( 'Someone else is sending this order right now.', 'rar-woo-smart-courier' ), array( 'status' => 409 ) );
		}

		try {
			$order = wc_get_order( $order_id ); // Re-read inside the lock.
			$other = RWSC_Couriers::sent_via( $order );
			if ( $other === $this->slug() ) {
				/* translators: %s: courier */
				return new WP_Error( 'rwsc_already_sent', sprintf( __( 'This order already has a %s consignment.', 'rar-woo-smart-courier' ), $this->label() ), array( 'status' => 409 ) );
			}
			if ( '' !== $other && RWSC_Api_Settings::yes( 'block_cross_courier' ) && empty( $opts['force'] ) ) {
				/* translators: %s: courier */
				return new WP_Error( 'rwsc_other_courier', sprintf( __( 'This order was already sent with %s. Cancel it there first, or turn off "one courier per order" in Settings.', 'rar-woo-smart-courier' ), RWSC_Couriers::label( $other ) ), array( 'status' => 409 ) );
			}
			if ( empty( $opts['force'] ) && get_transient( 'rwsc_uncertain_' . $order->get_id() ) ) {
				return new WP_Error( 'rwsc_uncertain_guard', __( 'An earlier attempt timed out and may have created a parcel. Check the courier panel first, or retry after 10 minutes.', 'rar-woo-smart-courier' ), array( 'status' => 409 ) );
			}

			$fields = array_merge( $this->build( $order ), $overrides );
			$errors = $this->validate( $fields );
			if ( $errors ) {
				return new WP_Error( 'rwsc_validation', implode( ' ', $errors ), array( 'status' => 422, 'errors' => $errors ) );
			}

			$res = $this->api_create( $fields, $order );
			if ( is_wp_error( $res ) ) {
				$d = $res->get_error_data();
				if ( is_array( $d ) && ! empty( $d['uncertain'] ) ) {
					set_transient( 'rwsc_uncertain_' . $order->get_id(), $this->slug(), 10 * MINUTE_IN_SECONDS );
				}
				$order->update_meta_data( $this->key( 'last_error' ), wp_strip_all_tags( $res->get_error_message() ) );
				$order->save_meta_data();
				RWSC_Logger::error( $this->label() . ' send failed for order ' . $order->get_id() . ': ' . $res->get_error_message() );
				return $res;
			}

			$order->update_meta_data( $this->key( 'id' ), (string) $res['id'] );
			$order->update_meta_data( $this->key( 'ref' ), (string) ( $res['ref'] ?? '' ) );
			$order->update_meta_data( $this->key( 'status' ), (string) $res['status'] );
			$order->update_meta_data( $this->key( 'sent_at' ), time() );
			$order->update_meta_data( $this->key( 'sent_by' ), (int) $user_id );
			$order->update_meta_data( $this->key( 'amount' ), (int) ( $fields['cod'] ?? 0 ) );
			$order->delete_meta_data( $this->key( 'last_error' ) );
			$order->delete_meta_data( $this->key( 'final' ) );
			$this->mirror_legacy( $order, $res );
			$this->add_event( $order, $res['status'], 'send' );
			$by = $user_id ? get_userdata( $user_id ) : false;
			$this->note(
				$order,
				sprintf(
					/* translators: 1: courier, 2: id, 3: user */
					__( 'Sent to %1$s. Consignment %2$s, by %3$s.', 'rar-woo-smart-courier' ),
					$this->label(),
					$res['id'],
					$by ? $by->display_name : __( 'automation', 'rar-woo-smart-courier' )
				)
			);
			$order->save();
			RWSC_Pathao_Orders::maybe_change_wc_status( $order, (string) RWSC_Api_Settings::get( 'status_on_sent' ), sprintf( /* translators: %s: courier */ __( 'Sent to %s.', 'rar-woo-smart-courier' ), $this->label() ) );
			delete_transient( 'rwsc_stats' );
			delete_transient( 'rwsc_suite_summary' );
			do_action( 'rpc_courier_sent', $this->slug(), $order, $res, $fields );

			return array(
				'consignment_id' => (string) $res['id'],
				'status'         => (string) $res['status'],
				'courier'        => $this->slug(),
			);
		} finally {
			RWSC_Lock::release( $lock );
		}
	}

	/** Keep the vendor plugins' meta keys current so old reports / other plugins keep working. */
	protected function mirror_legacy( WC_Order $order, array $res ) {
		if ( '' !== $this->legacy_id_key() ) {
			$order->update_meta_data( $this->legacy_id_key(), (string) $res['id'] );
		}
	}

	/* ---------------- Status ---------------- */

	/**
	 * @param int    $ts Provider timestamp (0 = unknown).
	 * @return bool Changed.
	 */
	public function apply_status( WC_Order $order, $raw, $ts = 0, $source = 'webhook' ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return false;
		}
		$cur_raw = $this->get( $order, 'status' );
		$last_ts = (int) $this->get( $order, 'status_ts' );
		$new     = $this->normalise( $raw );
		$cur     = $this->normalise( $cur_raw );

		if ( $ts && $last_ts && $ts < $last_ts ) {
			return false; // Out-of-order event.
		}
		if ( '' !== $cur_raw && $cur['final'] && ! $new['final'] && 'manual_edit' !== $source ) {
			return false; // Never reopen a finished parcel.
		}
		if ( $cur_raw === $raw ) {
			if ( $ts && $ts > $last_ts ) {
				$order->update_meta_data( $this->key( 'status_ts' ), $ts );
				$order->save_meta_data();
			}
			return false;
		}

		$order->update_meta_data( $this->key( 'status' ), $raw );
		if ( $ts ) {
			$order->update_meta_data( $this->key( 'status_ts' ), $ts );
		}
		if ( $new['final'] ) {
			$order->update_meta_data( $this->key( 'final' ), 1 );
		} else {
			$order->delete_meta_data( $this->key( 'final' ) );
		}
		$this->add_event( $order, $raw, $source, $ts );
		/* translators: 1: courier, 2: status */
		$this->note( $order, sprintf( __( '%1$s status: %2$s.', 'rar-woo-smart-courier' ), $this->label(), $new['label'] ) );
		$order->save();

		if ( 'delivered' === $new['slug'] ) {
			RWSC_Pathao_Orders::maybe_change_wc_status( $order, (string) RWSC_Api_Settings::get( 'status_on_delivered' ), sprintf( /* translators: %s: courier */ __( '%s: delivered.', 'rar-woo-smart-courier' ), $this->label() ) );
		} elseif ( 'returned' === $new['group'] && $new['final'] ) {
			RWSC_Pathao_Orders::maybe_change_wc_status( $order, (string) RWSC_Api_Settings::get( 'status_on_returned' ), sprintf( /* translators: %s: courier */ __( '%s: returned / cancelled.', 'rar-woo-smart-courier' ), $this->label() ) );
		}
		delete_transient( 'rwsc_stats' );
		delete_transient( 'rwsc_suite_summary' );
		do_action( 'rpc_courier_status_changed', $this->slug(), $order, $new, $source );
		return true;
	}

	/** @return array|WP_Error array( changed, status ) */
	public function refresh( WC_Order $order, $source = 'manual' ) {
		if ( $this->manual() ) {
			return new WP_Error( 'rwsc_manual', __( 'This courier has no API: update the status by hand.', 'rar-woo-smart-courier' ) );
		}
		if ( ! $this->is_sent( $order ) ) {
			return new WP_Error( 'rwsc_no_consignment', __( 'No consignment ID yet.', 'rar-woo-smart-courier' ) );
		}
		if ( ! $this->configured() ) {
			return new WP_Error( 'rwsc_no_credentials', __( 'Courier credentials are missing.', 'rar-woo-smart-courier' ) );
		}
		$r = $this->api_status( $order );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$order->update_meta_data( $this->key( 'last_sync' ), time() );
		$order->save_meta_data();
		$changed = $this->apply_status( $order, $r['status'], (int) ( $r['ts'] ?? 0 ), $source );
		return array(
			'changed' => $changed,
			'status'  => $r['status'],
		);
	}

	public function find_by_consignment( $cid ) {
		$cid = sanitize_text_field( (string) $cid );
		if ( '' === $cid ) {
			return null;
		}
		$ids = RWSC_Pathao_Orders::query(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array( array( 'key' => $this->key( 'id' ), 'value' => $cid ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? wc_get_order( $ids[0] ) : null;
	}

	/** Open parcels that need a status pull (flat queries: identical on HPOS and legacy). */
	public function sync_ids( $limit, $days ) {
		$base = array(
			'limit'        => $limit,
			'return'       => 'ids',
			'type'         => 'shop_order',
			'status'       => array_keys( wc_get_order_statuses() ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
		);
		$core = array(
			array( 'key' => $this->key( 'id' ), 'value' => '', 'compare' => '!=' ),
			array( 'key' => $this->key( 'final' ), 'compare' => 'NOT EXISTS' ),
		);
		$never = RWSC_Pathao_Orders::query( array_merge( $base, array( 'meta_query' => array_merge( array( 'relation' => 'AND' ), $core, array( array( 'key' => $this->key( 'last_sync' ), 'compare' => 'NOT EXISTS' ) ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$ids   = $never;
		if ( count( $ids ) < $limit ) {
			$stale = RWSC_Pathao_Orders::query(
				array_merge(
					$base,
					array(
						'limit'      => $limit - count( $ids ),
						'meta_query' => array_merge( array( 'relation' => 'AND' ), $core, array( array( 'key' => $this->key( 'last_sync' ), 'value' => time() - 1800, 'type' => 'NUMERIC', 'compare' => '<' ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				)
			);
			$ids   = array_values( array_unique( array_merge( $ids, $stale ) ) );
		}
		return $ids;
	}

	/* ---------------- Presentation ---------------- */

	public function badge( $raw ) {
		$n    = $this->normalise( $raw );
		$tone = array( 'delivered' => 'success', 'returned' => 'danger', 'attention' => 'warning', 'transit' => 'info', 'pending' => 'info' );
		return sprintf( '<span class="rwsc-d-badge rwsc-d-tone-%s">%s</span>', esc_attr( $tone[ $n['group'] ] ?? 'neutral' ), esc_html( $n['label'] ) );
	}

	/** Status picker for manual couriers (overridden there). */
	protected function manual_controls( WC_Order $order ) {
		return '';
	}

	/** Keyword based status mapping shared by couriers whose status words are free text. */
	protected function keyword_normalise( $raw ) {
		$raw  = trim( (string) $raw );
		$t    = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', $raw ) );
		$lab  = '' === $raw ? '—' : ucfirst( trim( preg_replace( '/[\s_-]+/', ' ', $raw ) ) );
		$g    = 'transit';
		$fin  = false;
		$slug = trim( preg_replace( '/\s+/', '_', $t ), '_' );
		if ( preg_match( '/fail|hold|undeliver|not delivered|unreach|resched|refus|problem|wrong/', $t ) ) {
			$g = 'attention';
		} elseif ( preg_match( '/returning|return in|to return|return process|return pending/', $t ) ) {
			$g = 'attention';
		} elseif ( preg_match( '/return|cancel|rto/', $t ) ) {
			$g   = 'returned';
			$fin = true;
		} elseif ( preg_match( '/delivered|delivery complete|cash collected|payment complete/', $t ) ) {
			$g    = 'delivered';
			$fin  = true;
			$slug = 'delivered';
		} elseif ( preg_match( '/pending|placed|created|booked|waiting|requested|pickup request|processing/', $t ) ) {
			$g = 'pending';
		}
		return array( 'slug' => $slug, 'label' => $lab, 'group' => $g, 'final' => $fin );
	}

	/** HTML for a parcel that already exists. */
	public function sent_html( WC_Order $order ) {
		$cid  = $this->consignment( $order );
		$url  = $this->tracking_url( $order );
		$html = RWSC_Couriers::chip( $this->slug() ) . ' ';
		$html .= $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" class="rwsc-d-cid">' . esc_html( $cid ) . '</a>' : esc_html( $cid );
		$st    = $this->get( $order, 'status' );
		if ( '' !== $st ) {
			$html .= ' ' . $this->badge( $st );
		}
		if ( $this->manual() ) {
			return $html . $this->manual_controls( $order );
		}
		$html .= ' <a href="#" class="rwsc-d-cr" data-courier="' . esc_attr( $this->slug() ) . '" data-order-id="' . esc_attr( $order->get_id() ) . '" title="' . esc_attr__( 'Refresh status', 'rar-woo-smart-courier' ) . '">&#8635;</a>';
		return $html;
	}
}
