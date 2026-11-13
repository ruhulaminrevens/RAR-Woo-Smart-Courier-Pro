<?php
/**
 * Order service: HPOS-safe meta access, send / refresh / status application.
 *
 * Meta keys are identical to the official plugin (ptc_consignment_id,
 * ptc_status, ptc_delivery_fee) so existing parcels keep working. Legacy
 * wp_postmeta values are read as a fallback and migrated on first touch.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Pathao_Orders {

	const META_ID     = 'ptc_consignment_id';
	const META_STATUS = 'ptc_status';
	const META_FEE    = 'ptc_delivery_fee';
	const EMPTY_FLAG  = '-'; // Native bulk: accepted, consignment ID pending.

	/** @var bool */
	private static $changing_wc_status = false;

	/* ------------------------------------------------------------------ *
	 * Meta
	 * ------------------------------------------------------------------ */

	public static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Order query that honours meta_query on BOTH storage modes.
	 *
	 * wc_get_orders() ignores meta_query when HPOS is off (legacy post storage), so
	 * legacy mode is routed through WP_Query. Accepts the subset of wc_get_orders()
	 * args this plugin uses: status, orderby(date), order, limit, paged, paginate,
	 * return(ids|objects), meta_query, date_created, post__in, s.
	 *
	 * @param array $args Query args.
	 * @return array|object ids/objects array, or object{orders,total,max_num_pages} when paginate.
	 */
	public static function query( array $args ) {
		if ( self::hpos_enabled() ) {
			return wc_get_orders( $args );
		}
		$paginate = ! empty( $args['paginate'] );
		$limit    = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$wq       = array(
			'post_type'      => 'shop_order',
			'post_status'    => ! empty( $args['status'] ) ? (array) $args['status'] : 'any',
			'posts_per_page' => $limit,
			'paged'          => isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1,
			'orderby'        => 'date',
			'order'          => ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => ! $paginate,
		);
		if ( ! empty( $args['meta_query'] ) ) {
			$wq['meta_query'] = $args['meta_query']; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( ! empty( $args['post__in'] ) ) {
			$wq['post__in'] = array_map( 'absint', (array) $args['post__in'] );
		}
		if ( ! empty( $args['s'] ) ) {
			$found = function_exists( 'wc_order_search' ) ? wc_order_search( $args['s'] ) : array();
			if ( ! $found ) {
				$empty = (object) array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 0 );
				return $paginate ? $empty : array();
			}
			$wq['post__in'] = isset( $wq['post__in'] ) ? array_values( array_intersect( $wq['post__in'], $found ) ) : $found;
			if ( ! $wq['post__in'] ) {
				$empty = (object) array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 0 );
				return $paginate ? $empty : array();
			}
		}
		if ( ! empty( $args['date_created'] ) ) {
			$dc = (string) $args['date_created'];
			$dq = array( 'inclusive' => true );
			$ts = static function ( $v, $end = false ) {
				if ( ctype_digit( $v ) ) {
					return gmdate( 'Y-m-d H:i:s', (int) $v + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
				}
				return $v . ( $end && false === strpos( $v, ':' ) ? ' 23:59:59' : '' );
			};
			if ( false !== strpos( $dc, '...' ) ) {
				list( $a, $b ) = explode( '...', $dc, 2 );
				$dq['after']   = $ts( $a );
				$dq['before']  = $ts( $b, true );
			} elseif ( 0 === strpos( $dc, '>=' ) || 0 === strpos( $dc, '>' ) ) {
				$dq['after'] = $ts( ltrim( $dc, '>=' ) );
			} elseif ( 0 === strpos( $dc, '<=' ) || 0 === strpos( $dc, '<' ) ) {
				$dq['before'] = $ts( ltrim( $dc, '<=' ), true );
			}
			$wq['date_query'] = array( $dq );
		}
		$q   = new WP_Query( $wq );
		$ids = array_map( 'intval', $q->posts );
		if ( $paginate ) {
			return (object) array(
				'orders'        => array_values( array_filter( array_map( 'wc_get_order', $ids ) ) ),
				'total'         => (int) $q->found_posts,
				'max_num_pages' => (int) $q->max_num_pages,
			);
		}
		if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
			return $ids;
		}
		return array_values( array_filter( array_map( 'wc_get_order', $ids ) ) );
	}

	public static function get( WC_Order $order, $key ) {
		$v = $order->get_meta( $key, true );
		if ( '' === $v && self::hpos_enabled() ) {
			// Parcels created by the original plugin before HPOS was enabled live in wp_postmeta.
			$legacy = get_post_meta( $order->get_id(), $key, true );
			if ( '' !== $legacy && false !== $legacy ) {
				$order->update_meta_data( $key, $legacy );
				$order->save_meta_data();
				return $legacy;
			}
		}
		return $v;
	}

	public static function consignment( WC_Order $order ) {
		return trim( (string) self::get( $order, self::META_ID ) );
	}

	public static function is_sent( WC_Order $order ) {
		return '' !== self::consignment( $order );
	}

	public static function has_real_consignment( WC_Order $order ) {
		$c = self::consignment( $order );
		return '' !== $c && self::EMPTY_FLAG !== $c;
	}

	public static function events( WC_Order $order ) {
		$e = $order->get_meta( 'ptc_events', true );
		return is_array( $e ) ? $e : array();
	}

	private static function add_event( WC_Order $order, $status, $source, $reason = '', $ts = 0 ) {
		$events   = self::events( $order );
		$events[] = array(
			't'   => $ts ? (int) $ts : time(),
			's'   => (string) $status,
			'src' => (string) $source,
			'r'   => $reason,
		);
		$order->update_meta_data( 'ptc_events', array_slice( $events, -40 ) );
	}

	private static function note( WC_Order $order, $text ) {
		if ( RWSC_Api_Settings::yes( 'add_order_notes' ) ) {
			$order->add_order_note( $text );
		}
	}

	public static function tracking_url( WC_Order $order ) {
		$cid = self::consignment( $order );
		if ( '' === $cid || self::EMPTY_FLAG === $cid ) {
			return '';
		}
		$phone = RWSC_Order_Data::recipient_phone( $order );
		return strtr(
			(string) RWSC_Api_Settings::get( 'tracking_url_template' ),
			array(
				'{consignment_id}' => rawurlencode( $cid ),
				'{phone}'          => rawurlencode( $phone ),
			)
		);
	}

	public static function panel_url( $consignment_id ) {
		return RWSC_Pathao_API::merchant_panel_url() . '/courier/orders/' . rawurlencode( $consignment_id );
	}

	/* ------------------------------------------------------------------ *
	 * Send
	 * ------------------------------------------------------------------ */

	/**
	 * Send one order to Pathao.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $fields   Field values from the dispatch modal.
	 * @param int   $user_id  Acting user (0 = automation).
	 * @param array $opts     force => bypass the "uncertain earlier attempt" guard.
	 * @return array|WP_Error array( consignment_id, delivery_fee, status )
	 */
	public static function send( $order_id, array $fields, $user_id = 0, array $opts = array() ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'rwsc_no_order', __( 'Order not found.', 'rar-woo-smart-courier' ), array( 'status' => 404 ) );
		}

		$errors = RWSC_Order_Data::validate( $fields );
		if ( $errors ) {
			return new WP_Error( 'rwsc_validation', __( 'Please fix the highlighted fields.', 'rar-woo-smart-courier' ), array( 'status' => 422, 'errors' => $errors ) );
		}

		$lock = 'send_' . $order->get_id();
		if ( ! RWSC_Lock::acquire( $lock, 3, 90 ) ) {
			return new WP_Error( 'rwsc_busy', __( 'Someone else is sending this order right now.', 'rar-woo-smart-courier' ), array( 'status' => 409 ) );
		}

		try {
			// Re-read inside the lock: a parallel request may have just finished.
			$order = wc_get_order( $order_id );
			if ( self::is_sent( $order ) ) {
				return new WP_Error( 'rwsc_already_sent', __( 'This order already has a Pathao consignment.', 'rar-woo-smart-courier' ), array( 'status' => 409 ) );
			}
			$other = RWSC_Couriers::sent_via( $order );
			if ( '' !== $other && 'pathao' !== $other && RWSC_Api_Settings::yes( 'block_cross_courier' ) && empty( $opts['force'] ) ) {
				return new WP_Error( 'rwsc_other_courier', sprintf( /* translators: %s: courier */ __( 'This order was already sent with %s. Cancel it there first, or turn off "one courier per order" in Settings.', 'rar-woo-smart-courier' ), RWSC_Couriers::label( $other ) ), array( 'status' => 409 ) );
			}
			if ( empty( $opts['force'] ) && get_transient( 'rwsc_uncertain_' . $order->get_id() ) ) {
				return new WP_Error(
					'rwsc_uncertain_guard',
					__( 'An earlier attempt timed out and may have created a parcel. Check the courier panel first, or retry after 10 minutes.', 'rar-woo-smart-courier' ),
					array( 'status' => 409 )
				);
			}

			$payload = RWSC_Order_Data::to_payload( $fields, $order );
			do_action( 'pathao_before_send_order', $payload, $order );

			$data = RWSC_Pathao_API::create_order( $payload );
			if ( is_wp_error( $data ) ) {
				$err = $data->get_error_data();
				if ( is_array( $err ) && ! empty( $err['uncertain'] ) ) {
					set_transient( 'rwsc_uncertain_' . $order->get_id(), 'pathao', 10 * MINUTE_IN_SECONDS );
				}
				$order->update_meta_data( 'ptc_last_error', wp_strip_all_tags( $data->get_error_message() ) );
				$order->save_meta_data();
				RWSC_Logger::error( 'Send failed for order ' . $order->get_id() . ': ' . $data->get_error_message() );
				do_action( 'pathao_send_order_failed', is_array( $err ) ? $err : array(), is_array( $err ) ? (int) ( $err['status'] ?? 0 ) : 0, $payload, $order );
				return $data;
			}

			$status = (string) ( $data['order_status'] ?? 'Pending' );
			$fee    = isset( $data['delivery_fee'] ) ? (string) $data['delivery_fee'] : '';

			$order->update_meta_data( self::META_ID, (string) $data['consignment_id'] );
			$order->update_meta_data( self::META_STATUS, $status );
			$order->update_meta_data( self::META_FEE, $fee );
			$order->update_meta_data( 'ptc_sent_at', time() );
			$order->update_meta_data( 'ptc_sent_by', (int) $user_id );
			$order->update_meta_data( 'ptc_store_id', (int) $payload['store_id'] );
			$order->update_meta_data( 'ptc_amount_to_collect', (int) $payload['amount_to_collect'] );
			$order->delete_meta_data( 'ptc_last_error' );
			// Read by RAR Woo Order Workflow & Notify for labels and customer messages.
			$order->update_meta_data( '_pathao_consignment_id', (string) $data['consignment_id'] );
			$order->update_meta_data( '_pathao_tracking_id', (string) $data['consignment_id'] );
			self::add_event( $order, $status, 'send' );

			$by = $user_id ? get_userdata( $user_id ) : false;
			self::note(
				$order,
				sprintf(
					/* translators: 1: consignment ID, 2: delivery fee, 3: user */
					__( 'Sent to Pathao Courier. Consignment %1$s, delivery fee ৳%2$s, by %3$s.', 'rar-woo-smart-courier' ),
					$data['consignment_id'],
					'' === $fee ? '—' : $fee,
					$by ? $by->display_name : __( 'automation', 'rar-woo-smart-courier' )
				)
			);
			$order->save();
			self::maybe_change_wc_status( $order, (string) RWSC_Api_Settings::get( 'status_on_sent' ), __( 'Sent to Pathao Courier.', 'rar-woo-smart-courier' ) );

			delete_transient( 'rwsc_stats' );
			delete_transient( 'rwsc_suite_summary' );
			do_action( 'pathao_after_send_order', array( 'data' => $data ), $payload, $order );

			return array(
				'consignment_id' => (string) $data['consignment_id'],
				'delivery_fee'   => $fee,
				'status'         => $status,
			);
		} finally {
			RWSC_Lock::release( $lock );
		}
	}

	/**
	 * Native bulk endpoint (asynchronous). Consignment IDs arrive by webhook.
	 *
	 * @param array $items array( order_id => fields ).
	 * @return array|WP_Error array( 'accepted' => ids, 'rejected' => id => message )
	 */
	public static function send_native_bulk( array $items, $user_id = 0 ) {
		$payloads = array();
		$rejected = array();
		$locked   = array();

		foreach ( $items as $order_id => $fields ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || self::is_sent( $order ) ) {
				$rejected[ $order_id ] = __( 'Missing or already sent.', 'rar-woo-smart-courier' );
				continue;
			}
			$errors = RWSC_Order_Data::validate( $fields );
			if ( $errors ) {
				$rejected[ $order_id ] = implode( ' ', $errors );
				continue;
			}
			if ( ! RWSC_Lock::acquire( 'send_' . $order_id, 3, 120 ) ) {
				$rejected[ $order_id ] = __( 'Being sent by someone else.', 'rar-woo-smart-courier' );
				continue;
			}
			$locked[]             = $order_id;
			$payloads[ $order_id ] = RWSC_Order_Data::to_payload( $fields, $order );
		}

		if ( ! $payloads ) {
			return array( 'accepted' => array(), 'rejected' => $rejected );
		}

		do_action( 'pathao_before_send_bulk_orders', array( 'orders' => array_values( $payloads ) ) );
		$res = RWSC_Pathao_API::create_bulk( $payloads );

		try {
			if ( is_wp_error( $res ) ) {
				do_action( 'pathao_send_bulk_orders_failed', $res->get_error_data(), 0, array( 'orders' => array_values( $payloads ) ) );
				return $res;
			}
			foreach ( array_keys( $payloads ) as $order_id ) {
				$order = wc_get_order( $order_id );
				$order->update_meta_data( self::META_ID, self::EMPTY_FLAG );
				$order->update_meta_data( self::META_STATUS, 'queued' );
				$order->update_meta_data( 'ptc_sent_at', time() );
				$order->update_meta_data( 'ptc_sent_by', (int) $user_id );
				self::add_event( $order, 'queued', 'bulk' );
				self::note( $order, __( 'Queued with Pathao bulk API. Consignment ID will arrive via webhook.', 'rar-woo-smart-courier' ) );
				$order->save();
			}
			delete_transient( 'rwsc_stats' );
			delete_transient( 'rwsc_suite_summary' );
			do_action( 'pathao_after_send_bulk_orders', $res, array( 'orders' => array_values( $payloads ) ) );
			return array( 'accepted' => array_keys( $payloads ), 'rejected' => $rejected );
		} finally {
			foreach ( $locked as $order_id ) {
				RWSC_Lock::release( 'send_' . $order_id );
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Status
	 * ------------------------------------------------------------------ */

	/**
	 * Apply a status coming from webhook, sync or manual refresh.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $raw    Pathao status string / slug / event key.
	 * @param array    $extra  delivery_fee, consignment_id, reason, ts (unix), collected.
	 * @param string   $source webhook | sync | manual.
	 * @return bool True when the stored status changed.
	 */
	public static function apply_status( WC_Order $order, $raw, array $extra = array(), $source = 'webhook' ) {
		$new = RWSC_Pathao_Status::info( $raw );
		if ( '' === $new['slug'] ) {
			return false;
		}
		$cur_raw = (string) self::get( $order, self::META_STATUS );
		$cur     = RWSC_Pathao_Status::info( $cur_raw );
		$ts      = ! empty( $extra['ts'] ) ? (int) $extra['ts'] : 0;
		$last_ts = (int) $order->get_meta( 'ptc_status_ts', true );

		$event_only = 'payment_invoice' === $new['slug'];
		$stale      = $ts && $last_ts && $ts < $last_ts; // Out-of-order webhook delivery.
		$changed    = ! $event_only && ! $stale && $new['slug'] !== $cur['slug'];

		if ( ! empty( $extra['consignment_id'] ) ) {
			$existing = self::consignment( $order );
			if ( '' === $existing || self::EMPTY_FLAG === $existing ) {
				$order->update_meta_data( self::META_ID, sanitize_text_field( (string) $extra['consignment_id'] ) );
				$order->update_meta_data( '_pathao_consignment_id', sanitize_text_field( (string) $extra['consignment_id'] ) );
				$order->update_meta_data( '_pathao_tracking_id', sanitize_text_field( (string) $extra['consignment_id'] ) );
			}
		}
		if ( isset( $extra['delivery_fee'] ) && '' !== $extra['delivery_fee'] && null !== $extra['delivery_fee'] ) {
			$order->update_meta_data( self::META_FEE, sanitize_text_field( (string) $extra['delivery_fee'] ) );
		}
		if ( isset( $extra['collected'] ) && '' !== $extra['collected'] ) {
			$order->update_meta_data( 'ptc_collected_amount', sanitize_text_field( (string) $extra['collected'] ) );
		}

		if ( $changed ) {
			$order->update_meta_data( self::META_STATUS, sanitize_text_field( (string) $raw ) );
			$order->update_meta_data( 'ptc_status_ts', $ts ? $ts : time() );
		}
		$reason = isset( $extra['reason'] ) ? sanitize_text_field( (string) $extra['reason'] ) : '';
		if ( $changed || $event_only || ( ! $stale && 'manual' !== $source && 'sync' !== $source ) ) {
			self::add_event( $order, (string) $raw, $source, $reason, $ts );
		}

		if ( $changed ) {
			self::note(
				$order,
				sprintf(
					/* translators: 1: status label, 2: source */
					__( 'Pathao status: %1$s (%2$s).', 'rar-woo-smart-courier' ),
					$new['label'] . ( $reason ? ' — ' . $reason : '' ),
					$source
				)
			);
		}
		$order->save();

		if ( $changed ) {
			// Final states leave the sync queue.
			if ( $new['final'] ) {
				$order->update_meta_data( 'ptc_final', 1 );
			} else {
				$order->delete_meta_data( 'ptc_final' );
			}
			$order->save_meta_data();
			if ( 'delivered' === $new['slug'] ) {
				self::maybe_change_wc_status( $order, (string) RWSC_Api_Settings::get( 'status_on_delivered' ), __( 'Pathao: delivered.', 'rar-woo-smart-courier' ) );
			} elseif ( in_array( $new['slug'], array( 'returned', 'paid_return' ), true ) ) {
				self::maybe_change_wc_status( $order, (string) RWSC_Api_Settings::get( 'status_on_returned' ), __( 'Pathao: returned.', 'rar-woo-smart-courier' ) );
			}
			delete_transient( 'rwsc_stats' );
			delete_transient( 'rwsc_suite_summary' );
			do_action( 'rpc_status_changed', $order, $new, $cur, $source );
		}
		return $changed;
	}

	public static function maybe_change_wc_status( WC_Order $order, $target, $note ) {
		if ( '' === $target || self::$changing_wc_status ) {
			return;
		}
		$target = str_replace( 'wc-', '', $target );
		if ( $order->get_status() === $target ) {
			return;
		}
		self::$changing_wc_status = true;
		$order->update_status( $target, $note );
		self::$changing_wc_status = false;
	}

	/**
	 * Pull the current status from Pathao.
	 *
	 * @return array|WP_Error array( changed => bool, status => raw )
	 */
	public static function refresh( WC_Order $order, $source = 'manual' ) {
		if ( ! self::has_real_consignment( $order ) ) {
			return new WP_Error( 'rwsc_no_consignment', __( 'No consignment ID yet (bulk-queued orders update via webhook).', 'rar-woo-smart-courier' ) );
		}
		$info = RWSC_Pathao_API::order_info( self::consignment( $order ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$raw = (string) ( $info['order_status_slug'] ?? $info['order_status'] ?? '' );
		if ( '' === $raw ) {
			return new WP_Error( 'rwsc_no_status', __( 'Pathao returned no status for this consignment.', 'rar-woo-smart-courier' ) );
		}
		$ts = ! empty( $info['updated_at'] ) ? (int) strtotime( (string) $info['updated_at'] . ' +06:00' ) : 0; // Pathao timestamps are Asia/Dhaka.
		$order->update_meta_data( 'ptc_last_sync', time() );
		$changed = self::apply_status( $order, $raw, array( 'ts' => $ts ), $source );
		return array(
			'changed' => $changed,
			'status'  => $raw,
		);
	}

	public static function find_by_consignment( $consignment_id ) {
		$consignment_id = sanitize_text_field( (string) $consignment_id );
		if ( '' === $consignment_id ) {
			return null;
		}
		$ids = self::query(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => self::META_ID,
						'value' => $consignment_id,
					),
				),
			)
		);
		return $ids ? wc_get_order( $ids[0] ) : null;
	}

	/* ------------------------------------------------------------------ *
	 * Automation entry point (Action Scheduler / WP-Cron)
	 * ------------------------------------------------------------------ */

	public static function auto_send( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || '' !== RWSC_Couriers::sent_via( $order ) ) {
			return;
		}
		$slug = RWSC_Couriers::pick_for_auto( $order ); // Honours the customer's RAR Smart Courier choice.
		if ( '' === $slug ) {
			return;
		}
		if ( 'pathao' !== $slug ) {
			RWSC_Couriers::auto_send_module( $slug, $order_id );
			return;
		}
		$data   = RWSC_Order_Data::build( $order );
		$fields = $data['fields'];
		if ( empty( $fields['store_id'] ) ) {
			$stores = RWSC_Pathao_API::stores();
			if ( ! is_wp_error( $stores ) && $stores ) {
				$fields['store_id'] = self::default_store_id( $stores );
			}
		}
		$res = self::send( $order_id, $fields, 0 );
		if ( is_wp_error( $res ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				self::note( $order, sprintf( /* translators: %s: error */ __( 'Pathao auto-send failed: %s', 'rar-woo-smart-courier' ), $res->get_error_message() ) );
				$order->save();
			}
		}
	}

	public static function default_store_id( array $stores ) {
		$pref = (int) RWSC_Api_Settings::get( 'default_store_id', 0 );
		foreach ( $stores as $s ) {
			if ( $pref && (int) $s['id'] === $pref ) {
				return $pref;
			}
		}
		foreach ( $stores as $s ) {
			if ( ! empty( $s['default'] ) ) {
				return (int) $s['id'];
			}
		}
		foreach ( $stores as $s ) {
			if ( ! empty( $s['active'] ) ) {
				return (int) $s['id'];
			}
		}
		return $stores ? (int) $stores[0]['id'] : 0;
	}

	/* ------------------------------------------------------------------ *
	 * Presentation helpers (order list column + meta box)
	 * ------------------------------------------------------------------ */

	public static function cell_html( WC_Order $order ) {
		$cid = self::consignment( $order );
		if ( '' === $cid ) {
			$via = RWSC_Couriers::sent_via( $order );
			if ( '' !== $via ) {
				return '<span class="rwsc-d-muted">' . esc_html( sprintf( /* translators: %s: courier */ __( 'Sent with %s', 'rar-woo-smart-courier' ), RWSC_Couriers::label( $via ) ) ) . '</span>';
			}
			$html = '<button type="button" class="button button-small rwsc-d-open" data-order-ids="' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Send with Pathao', 'rar-woo-smart-courier' ) . '</button>';
			if ( RWSC_Order_Data::chose_other_courier( $order ) ) {
				$html .= '<div class="rwsc-d-muted">' . esc_html( sprintf( /* translators: %s: courier */ __( 'Customer chose: %s', 'rar-woo-smart-courier' ), RWSC_Order_Data::selected_courier( $order ) ) ) . '</div>';
			}
			return $html;
		}
		if ( self::EMPTY_FLAG === $cid ) {
			return '<span class="rwsc-d-muted">' . esc_html__( 'Queued — awaiting consignment ID', 'rar-woo-smart-courier' ) . '</span>';
		}
		$fee  = (string) self::get( $order, self::META_FEE );
		$html = '<a href="' . esc_url( self::panel_url( $cid ) ) . '" target="_blank" rel="noopener noreferrer" class="rwsc-d-cid">' . esc_html( $cid ) . '</a> '
			. RWSC_Pathao_Status::badge( (string) self::get( $order, self::META_STATUS ) );
		if ( '' !== $fee ) {
			$html .= '<div class="rwsc-d-muted">' . esc_html( sprintf( /* translators: %s: fee */ __( 'Fee ৳%s', 'rar-woo-smart-courier' ), $fee ) ) . '</div>';
		}
		return $html;
	}
}
