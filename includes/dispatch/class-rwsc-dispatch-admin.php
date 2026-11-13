<?php
/**
 * Courier API admin pages: Settings (tabbed per courier) and Tools & Health.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Dispatch_Admin {

	const PAGE_PARENT   = 'rwsc-dashboard';   // Smart Courier top-level menu.
	const PAGE_DASH     = 'rwsc-api-overview';
	const PAGE_ORDERS   = 'rwsc-parcels';
	const PAGE_SETTINGS = 'rwsc-api';
	const PAGE_TOOLS    = 'rwsc-tools';
	const PARCEL_CAP    = 300;                // Newest parcels scanned for the cross-courier list.

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
		add_action( 'admin_post_rwsc_d_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_rwsc_d_import_suite', array( __CLASS__, 'import_suite' ) );
		add_action( 'admin_post_rwsc_d_save_settings', array( __CLASS__, 'save_settings' ) );
				add_action( 'admin_post_rwsc_d_migrate_meta', array( __CLASS__, 'migrate_meta' ) );
		add_action( 'admin_post_rwsc_d_import_legacy', array( __CLASS__, 'import_legacy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		$cap = RWSC_Dispatch_Ajax::operator_cap();
		add_submenu_page( self::PAGE_PARENT, __( 'API Overview', 'rar-woo-smart-courier' ), __( 'API Overview', 'rar-woo-smart-courier' ), $cap, self::PAGE_DASH, array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( self::PAGE_PARENT, __( 'Parcels', 'rar-woo-smart-courier' ), __( 'Parcels', 'rar-woo-smart-courier' ), $cap, self::PAGE_ORDERS, array( __CLASS__, 'page_orders' ) );
		add_submenu_page( self::PAGE_PARENT, __( 'Courier APIs', 'rar-woo-smart-courier' ), __( 'Courier APIs', 'rar-woo-smart-courier' ), 'manage_options', self::PAGE_SETTINGS, array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::PAGE_PARENT, __( 'Tools & Health', 'rar-woo-smart-courier' ), __( 'Tools & Health', 'rar-woo-smart-courier' ), 'manage_options', self::PAGE_TOOLS, array( __CLASS__, 'page_tools' ) );
	}

	/** Number of couriers that can send right now. */
	private static function usable_count() {
		$n = 0;
		foreach ( RWSC_Couriers::slugs() as $slug ) {
			if ( RWSC_Couriers::usable( $slug ) ) {
				++$n;
			}
		}
		return $n;
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! RWSC_Dispatch_Orders_UI::is_plugin_screen() ) {
			return; // Do not nag on unrelated admin screens (plugins list, posts, ...).
		}
		if ( RWSC_Api_Settings::secrets_unreadable() ) {
			echo '<div class="notice notice-warning"><p><strong>RAR Woo Smart Courier:</strong> saved API secrets cannot be decrypted (WordPress salts changed). Re-enter them under Courier Suite → Settings.</p></div>';
		} elseif ( 0 === self::usable_count() && ! in_array( $page, array( self::PAGE_SETTINGS, self::PAGE_DASH ), true ) ) {
			echo '<div class="notice notice-info"><p><strong>RAR Woo Smart Courier</strong> has no connected courier yet. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ) . '">Connect Pathao, RedX, SteadFast, Paperfly or Sundarban</a>.</p></div>';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Shared chrome
	 * ------------------------------------------------------------------ */

	private static function page_url( $page, array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) );
	}

	/** Hero banner + top navigation. Opens <div class="wrap rwsc-d-wrap">. */
	private static function head( $active, $title, $sub, array $actions = array() ) {
			$nav = array(
			self::PAGE_PARENT => __( 'Smart Courier', 'rar-woo-smart-courier' ),
			'rwsc-shipments'  => __( 'Shipments', 'rar-woo-smart-courier' ),
			self::PAGE_DASH   => __( 'API Overview', 'rar-woo-smart-courier' ),
			self::PAGE_ORDERS => __( 'Parcels', 'rar-woo-smart-courier' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$nav[ self::PAGE_SETTINGS ] = __( 'Courier APIs', 'rar-woo-smart-courier' );
			$nav[ self::PAGE_TOOLS ]    = __( 'Tools & Health', 'rar-woo-smart-courier' );
		}
		echo '<div class="wrap rwsc-d-wrap rwsc-d-js"><h1 class="screen-reader-text">' . esc_html( $title ) . '</h1><hr class="wp-header-end">';
		echo '<div class="rwsc-d-hero"><img src="' . esc_url( RWSC_URL . 'assets/images/dispatch-logo.svg' ) . '" alt="">'
			. '<div><h1>' . esc_html( $title ) . '<span class="rwsc-d-ver">v' . esc_html( RWSC_VERSION ) . '</span></h1><p>' . esc_html( $sub ) . '</p></div>';
		if ( $actions ) {
			echo '<div class="rwsc-d-hero-actions">';
			foreach ( $actions as $a ) {
				echo '<a class="button' . ( ! empty( $a[2] ) ? ' button-primary' : '' ) . '" href="' . esc_url( $a[1] ) . '">' . esc_html( $a[0] ) . '</a>';
			}
			echo '</div>';
		}
		echo '</div><nav class="rwsc-d-nav" aria-label="Courier APIs">';
		foreach ( $nav as $slug => $label ) {
			echo '<a href="' . esc_url( self::page_url( $slug ) ) . '"' . ( $slug === $active ? ' class="is-on" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private static function foot() {
		echo '<p class="rwsc-d-foot">' . esc_html__( 'RAR Woo Smart Courier is an independent plugin by Ruhul Amin. Pathao, RedX, SteadFast, Paperfly and Sundarban Courier are names of their respective owners; this plugin is not affiliated with or endorsed by them.', 'rar-woo-smart-courier' ) . '</p></div>';
	}

	/** Connection state of a courier for badges: array( key => ok|warn|off, text ). */
	private static function state( $slug ) {
		if ( 'pathao' === $slug ) {
			return RWSC_Api_Settings::is_configured() ? array( 'ok', __( 'Connected', 'rar-woo-smart-courier' ) ) : array( 'off', __( 'Not connected', 'rar-woo-smart-courier' ) );
		}
		$m = RWSC_Couriers::get( $slug );
		if ( ! $m->enabled() ) {
			return array( 'off', __( 'Disabled', 'rar-woo-smart-courier' ) );
		}
		if ( $m->manual() ) {
			return array( 'ok', __( 'Manual · active', 'rar-woo-smart-courier' ) );
		}
		return $m->configured() ? array( 'ok', __( 'Connected', 'rar-woo-smart-courier' ) ) : array( 'warn', __( 'Needs credentials', 'rar-woo-smart-courier' ) );
	}

	private static function state_pill( $slug ) {
		$st = self::state( $slug );
		return '<span class="rwsc-d-state is-' . esc_attr( $st[0] ) . '">' . esc_html( $st[1] ) . '</span>';
	}

	/* ------------------------------------------------------------------ *
	 * Parcel collection (all couriers)
	 * ------------------------------------------------------------------ */

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'unsent';
		if ( ! isset( RWSC_Pathao_Status::groups()[ $view ] ) ) {
			$view = 'unsent';
		}
		$courier = isset( $_GET['courier'] ) ? sanitize_key( wp_unslash( $_GET['courier'] ) ) : 'all';
		if ( 'all' !== $courier && ! in_array( $courier, RWSC_Couriers::slugs(), true ) ) {
			$courier = 'all';
		}
		$f = array(
			'view'     => $view,
			'courier'  => $courier,
			's'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'paged'    => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'per_page' => isset( $_GET['per_page'] ) ? min( 100, max( 10, absint( $_GET['per_page'] ) ) ) : 25,
		);
		// phpcs:enable
		return $f;
	}

	/** meta_query clause: "this courier holds a parcel". */
	private static function sent_clause( $courier ) {
		return array( 'key' => RWSC_Couriers::id_key( $courier ), 'value' => '', 'compare' => '!=' );
	}

	private static function base_args( array $f ) {
		$args = array(
			'type'    => 'shop_order',
			'status'  => array_keys( wc_get_order_statuses() ),
			'orderby' => 'date',
			'order'   => 'DESC',
		);
		if ( $f['from'] && $f['to'] ) {
			$args['date_created'] = $f['from'] . '...' . $f['to'];
		} elseif ( $f['from'] ) {
			$args['date_created'] = '>=' . $f['from'];
		} elseif ( $f['to'] ) {
			$args['date_created'] = '<=' . $f['to'];
		}
		return $args;
	}

	/** Order id whose consignment equals the search text, in any courier (0 = none). */
	private static function search_consignment( $text ) {
		$o = RWSC_Pathao_Orders::find_by_consignment( $text );
		if ( $o ) {
			return $o->get_id();
		}
		foreach ( RWSC_Couriers::modules() as $m ) {
			$o = $m->find_by_consignment( $text );
			if ( $o ) {
				return $o->get_id();
			}
		}
		return 0;
	}

	/**
	 * Parcels for the list / dashboard / CSV.
	 *
	 * @param bool $all  true = no pagination (CSV).
	 * @param int  $cap  Newest orders scanned for sent views.
	 * @return array rows (array of order+info), total, pages, capped
	 */
	public static function collect( array $f, $all = false, $cap = self::PARCEL_CAP ) {
		$out = array( 'rows' => array(), 'total' => 0, 'pages' => 1, 'capped' => false );

		if ( 'unsent' === $f['view'] ) {
			$args = self::base_args( $f );
			$q    = (array) RWSC_Api_Settings::get( 'queue_statuses' );
			if ( $q ) {
				$args['status'] = $q;
			}
			// Keep the SQL cheap (a join per courier is costly on big shops): Pathao is pruned in SQL,
			// the four other couriers with a plain NOT EXISTS, and sent_via() makes the final call in PHP.
			$k_p  = RWSC_Couriers::id_key( 'pathao' );
			$meta = array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array( 'key' => $k_p, 'compare' => 'NOT EXISTS' ),
					array( 'key' => $k_p, 'value' => '', 'compare' => '=' ),
				),
			);
			foreach ( RWSC_Couriers::modules() as $slug => $m ) {
				$meta[] = array( 'key' => $m->key( 'id' ), 'compare' => 'NOT EXISTS' );
			}
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( '' !== $f['s'] ) {
				$args['s'] = $f['s'];
			}
			$args['limit']    = $f['per_page'];
			$args['paginate'] = true;
			$pages            = $all ? 50 : 1;
			for ( $p = 1; $p <= $pages; $p++ ) {
				$args['paged'] = $all ? $p : $f['paged'];
				$res           = RWSC_Pathao_Orders::query( $args );
				foreach ( $res->orders as $o ) {
					if ( '' === RWSC_Couriers::sent_via( $o ) ) {
						$out['rows'][] = array( 'order' => $o, 'info' => null );
					}
				}
				$out['total'] = isset( $res->total ) ? (int) $res->total : count( $out['rows'] );
				$out['pages'] = max( 1, (int) $res->max_num_pages );
				if ( $p >= (int) $res->max_num_pages ) {
					break;
				}
			}
			return $out;
		}

		$args          = self::base_args( $f );
		$args['limit'] = $cap;
		$args['return'] = 'ids';
		$by_cid        = '' !== $f['s'] ? self::search_consignment( $f['s'] ) : 0;
		$slugs         = 'all' === $f['courier'] ? RWSC_Couriers::slugs() : array( $f['courier'] );
		$ids           = array();
		$capped        = false;
		if ( $by_cid ) {
			$ids = array( $by_cid );
		} else {
			if ( '' !== $f['s'] ) {
				$args['s'] = $f['s'];
			}
			// One cheap single-key query per courier, merged newest-first (order ids grow with time).
			foreach ( $slugs as $slug ) {
				$a                = $args;
				$a['meta_query']  = array( self::sent_clause( $slug ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$got              = (array) RWSC_Pathao_Orders::query( $a );
				$capped           = $capped || count( $got ) >= $cap;
				$ids              = array_merge( $ids, array_map( 'absint', $got ) );
			}
			$ids = array_values( array_unique( $ids ) );
			rsort( $ids );
			$ids = array_slice( $ids, 0, $cap );
		}
		$out['capped'] = $capped;
		$rows = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$i = RWSC_Couriers::parcel_info( $o );
			if ( ! $i ) {
				continue;
			}
			if ( 'all' !== $f['courier'] && $i['courier'] !== $f['courier'] ) {
				continue;
			}
			if ( 'all' !== $f['view'] && $i['group'] !== $f['view'] ) {
				continue;
			}
			$rows[] = array( 'order' => $o, 'info' => $i );
		}
		$out['total'] = count( $rows );
		$out['pages'] = max( 1, (int) ceil( $out['total'] / max( 1, $f['per_page'] ) ) );
		$out['rows']  = $all ? $rows : array_slice( $rows, ( $f['paged'] - 1 ) * $f['per_page'], $f['per_page'] );
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Dashboard
	 * ------------------------------------------------------------------ */

	private static function checklist() {
		$sum   = RWSC_Couriers::summary();
		$sent  = 0;
		foreach ( $sum as $r ) {
			$sent += (int) $r['sent'];
		}
		$hook = (int) get_option( 'rwsc_last_webhook', 0 ) || (int) get_option( 'rwsc_last_webhook_sf', 0 );
		return array(
			array( __( 'Connect a courier', 'rar-woo-smart-courier' ), __( 'Add API credentials for Pathao, RedX, SteadFast or Paperfly — or enable Sundarban for manual CN entry.', 'rar-woo-smart-courier' ), self::usable_count() > 0, self::page_url( self::PAGE_SETTINGS ) ),
			array( __( 'Receive live status updates', 'rar-woo-smart-courier' ), __( 'Paste the webhook URL into the courier panel (Pathao, SteadFast). Until then the 30-minute sync keeps statuses fresh.', 'rar-woo-smart-courier' ), $hook, self::page_url( self::PAGE_SETTINGS, array( 'tab' => 'general' ) ) ),
			array( __( 'Choose automation', 'rar-woo-smart-courier' ), __( 'Pick the default courier, auto-send status and the WooCommerce status after sent / delivered / returned.', 'rar-woo-smart-courier' ), '' !== (string) RWSC_Api_Settings::get( 'auto_send_status' ) || '' !== (string) RWSC_Api_Settings::get( 'status_on_sent' ) || '' !== (string) RWSC_Api_Settings::get( 'status_on_delivered' ), self::page_url( self::PAGE_SETTINGS, array( 'tab' => 'general' ) ) ),
			array( __( 'Send your first parcel', 'rar-woo-smart-courier' ), __( 'Open Parcels, pick one order and use the courier button — or the bulk bar for many.', 'rar-woo-smart-courier' ), $sent > 0, self::page_url( self::PAGE_ORDERS ) ),
		);
	}

	public static function page_dashboard() {
		if ( ! current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) {
			return;
		}
		$sum   = RWSC_Couriers::summary();
		$tot   = array( 'sent' => 0, 'pending' => 0, 'transit' => 0, 'delivered' => 0, 'returned' => 0, 'attention' => 0, 'cod_open' => 0, 'fees' => 0 );
		foreach ( $sum as $r ) {
			foreach ( $tot as $k => $v ) {
				$tot[ $k ] += $r[ $k ];
			}
		}
		$fin = $tot['delivered'] + $tot['returned'];

		self::head(
			self::PAGE_DASH,
			__( 'Courier APIs', 'rar-woo-smart-courier' ),
			__( 'Pathao · RedX · SteadFast · Paperfly · Sundarban — one control centre', 'rar-woo-smart-courier' ),
			array(
				array( __( 'Send parcels', 'rar-woo-smart-courier' ), self::page_url( self::PAGE_ORDERS ), true ),
				array( __( 'Needs attention', 'rar-woo-smart-courier' ), self::page_url( self::PAGE_ORDERS, array( 'view' => 'attention' ) ) ),
			)
		);

		// Setup checklist (hidden once complete).
		$steps = self::checklist();
		$done  = 0;
		foreach ( $steps as $st ) {
			$done += $st[2] ? 1 : 0;
		}
		if ( $done < count( $steps ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Get started', 'rar-woo-smart-courier' ) . ' <span class="rwsc-d-muted">' . esc_html( $done . '/' . count( $steps ) ) . '</span></h2>'
				. '<div class="rwsc-d-meter"><i style="width:' . esc_attr( (int) round( 100 * $done / count( $steps ) ) ) . '%"></i></div><ul class="rwsc-d-steps">';
			foreach ( $steps as $n => $st ) {
				echo '<li class="' . ( $st[2] ? 'is-done' : '' ) . '"><span class="n">' . ( $st[2] ? '✓' : (int) ( $n + 1 ) ) . '</span><div><strong>' . esc_html( $st[0] ) . '</strong><div class="rwsc-d-muted">' . esc_html( $st[1] ) . '</div>'
					. ( $st[2] ? '' : '<a href="' . esc_url( $st[3] ) . '">' . esc_html__( 'Open', 'rar-woo-smart-courier' ) . ' →</a>' ) . '</div></li>';
			}
			echo '</ul></div>';
		}

		// KPIs.
		$pct = static function ( $n, $d ) {
			return $d ? ' · ' . round( 100 * $n / $d ) . '%' : '';
		};
		$kpi = array(
			array( __( 'Parcels (30 days)', 'rar-woo-smart-courier' ), $tot['sent'], '' ),
			array( __( 'Pickup / transit', 'rar-woo-smart-courier' ), $tot['pending'] + $tot['transit'], '' ),
			array( __( 'Delivered', 'rar-woo-smart-courier' ), $tot['delivered'], 'is-ok', $pct( $tot['delivered'], $fin ) ),
			array( __( 'Returned', 'rar-woo-smart-courier' ), $tot['returned'], $tot['returned'] ? 'is-bad' : '', $pct( $tot['returned'], $fin ) ),
			array( __( 'Needs attention', 'rar-woo-smart-courier' ), $tot['attention'], $tot['attention'] ? 'is-warn' : '' ),
			array( __( 'COD still with couriers', 'rar-woo-smart-courier' ), '৳' . number_format_i18n( $tot['cod_open'] ), '' ),
		);
		echo '<div class="rwsc-d-kpis">';
		foreach ( $kpi as $k ) {
			echo '<div class="rwsc-d-kpi ' . esc_attr( $k[2] ) . '"><span>' . esc_html( $k[0] ) . '</span><strong>' . esc_html( (string) $k[1] ) . '</strong>' . ( ! empty( $k[3] ) ? '<small>' . esc_html( trim( $k[3], ' ·' ) ) . '</small>' : '' ) . '</div>';
		}
		echo '</div>';
		if ( $tot['sent'] ) {
			echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Pipeline', 'rar-woo-smart-courier' ) . '</h2><div class="rwsc-d-bar" role="img" aria-label="' . esc_attr__( 'Parcel status distribution', 'rar-woo-smart-courier' ) . '">';
			foreach ( array( 'pending', 'transit', 'delivered', 'returned', 'attention' ) as $g ) {
				if ( $tot[ $g ] ) {
					echo '<i class="b-' . esc_attr( $g ) . '" style="width:' . esc_attr( round( 100 * $tot[ $g ] / $tot['sent'], 2 ) ) . '%" title="' . esc_attr( $g . ': ' . $tot[ $g ] ) . '"></i>';
				}
			}
			echo '</div><p class="rwsc-d-muted">' . esc_html__( 'Pending · In transit · Delivered · Returned · Needs attention — last 30 days, all couriers.', 'rar-woo-smart-courier' ) . '</p></div>';
		}

		// Courier cards.
		echo '<div class="rwsc-d-cards">';
		foreach ( RWSC_Couriers::slugs() as $slug ) {
			$st  = self::state( $slug );
			$r   = $sum[ $slug ];
			$fn  = $r['delivered'] + $r['returned'];
			echo '<div class="rwsc-d-ccard ' . ( 'off' === $st[0] ? 'is-off' : '' ) . '"><div class="rwsc-d-ccard-h">' . RWSC_Couriers::chip( $slug ) . self::state_pill( $slug ) . '</div>'
				. '<div class="rwsc-d-ccard-n"><div><b>' . (int) $r['sent'] . '</b><span>' . esc_html__( 'sent', 'rar-woo-smart-courier' ) . '</span></div><div><b>' . (int) $r['delivered'] . '</b><span>' . esc_html__( 'delivered', 'rar-woo-smart-courier' ) . '</span></div><div><b>' . (int) $r['returned'] . '</b><span>' . esc_html__( 'returned', 'rar-woo-smart-courier' ) . '</span></div></div>'
				. '<div class="rwsc-d-muted">' . esc_html( $fn ? sprintf( /* translators: %d: percent */ __( 'Delivery success %d%%', 'rar-woo-smart-courier' ), round( 100 * $r['delivered'] / $fn ) ) : __( 'No finished parcels yet', 'rar-woo-smart-courier' ) ) . '</div>'
				. '<div class="rwsc-d-ccard-f"><a class="button button-small" href="' . esc_url( self::page_url( self::PAGE_ORDERS, array( 'view' => 'all', 'courier' => $slug ) ) ) . '">' . esc_html__( 'Parcels', 'rar-woo-smart-courier' ) . '</a>'
				. ( current_user_can( 'manage_options' ) ? ' <a class="button button-small" href="' . esc_url( self::page_url( self::PAGE_SETTINGS, array( 'tab' => $slug ) ) ) . '">' . esc_html__( 'Settings', 'rar-woo-smart-courier' ) . '</a>' : '' ) . '</div></div>';
		}
		echo '</div>';

		// Attention + recent parcels (one scan of the newest 100 parcels).
		$f    = array( 'view' => 'all', 'courier' => 'all', 's' => '', 'from' => '', 'to' => '', 'paged' => 1, 'per_page' => 100 );
		$scan = self::collect( $f, false, 100 );
		$att  = array();
		foreach ( $scan['rows'] as $row ) {
			if ( 'attention' === $row['info']['group'] ) {
				$att[] = $row;
			}
		}
		self::mini_table( __( 'Needs attention', 'rar-woo-smart-courier' ), __( 'Failed pickups, delivery failures and holds — act on these first.', 'rar-woo-smart-courier' ), array_slice( $att, 0, 8 ), __( 'Nothing needs attention. 🎉', 'rar-woo-smart-courier' ) );
		self::mini_table( __( 'Recent parcels', 'rar-woo-smart-courier' ), '', array_slice( $scan['rows'], 0, 8 ), __( 'No parcels yet.', 'rar-woo-smart-courier' ) );

		// Performance table.
		echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Courier performance — last 30 days', 'rar-woo-smart-courier' ) . '</h2><p class="rwsc-d-panel-sub">' . esc_html__( 'Success rate = delivered ÷ (delivered + returned). Open COD = amount to collect on parcels not yet finished.', 'rar-woo-smart-courier' ) . '</p>'
			. '<table class="widefat striped rwsc-d-table"><thead><tr><th>' . esc_html__( 'Courier', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Sent', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'In progress', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Delivered', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Returned', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Success rate', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Attention', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Open COD (৳)', 'rar-woo-smart-courier' ) . '</th></tr></thead><tbody>';
		foreach ( $sum as $slug => $r ) {
			$fn = $r['delivered'] + $r['returned'];
			echo '<tr><td>' . RWSC_Couriers::chip( $slug ) . '</td><td>' . (int) $r['sent'] . '</td><td>' . (int) ( $r['pending'] + $r['transit'] ) . '</td><td>' . (int) $r['delivered'] . '</td><td>' . (int) $r['returned'] . '</td><td>' . ( $fn ? esc_html( round( 100 * $r['delivered'] / $fn ) . '%' ) : '—' ) . '</td><td>' . (int) $r['attention'] . '</td><td>' . esc_html( number_format_i18n( (int) $r['cod_open'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $sum['pathao']['fees'] ) ) {
			echo '<p class="rwsc-d-muted">' . esc_html( sprintf( /* translators: %s: amount */ __( 'Pathao delivery fees recorded: ৳%s (only Pathao returns a fee).', 'rar-woo-smart-courier' ), number_format_i18n( $sum['pathao']['fees'] ) ) ) . '</p>';
		}
		foreach ( $sum as $r ) {
			if ( ! empty( $r['capped'] ) ) {
				echo '<p class="rwsc-d-muted">' . esc_html__( 'Statistics use the latest 500 parcels per courier of the last 30 days.', 'rar-woo-smart-courier' ) . '</p>';
				break;
			}
		}
		echo '</div>';
		self::foot();
	}

	private static function mini_table( $title, $sub, array $rows, $empty ) {
		echo '<div class="rwsc-d-panel"><h2>' . esc_html( $title ) . '</h2>' . ( $sub ? '<p class="rwsc-d-panel-sub">' . esc_html( $sub ) . '</p>' : '' );
		if ( ! $rows ) {
			echo '<p class="rwsc-d-muted">' . esc_html( $empty ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped rwsc-d-table"><tbody>';
		foreach ( $rows as $row ) {
			$o = $row['order'];
			$i = $row['info'];
			echo '<tr><td><a href="' . esc_url( $o->get_edit_order_url() ) . '"><strong>#' . esc_html( $o->get_order_number() ) . '</strong></a></td><td>' . esc_html( RWSC_Order_Data::recipient_name( $o ) ) . '</td><td>' . RWSC_Couriers::chip( $i['courier'] ) . ' <span class="rwsc-d-cid">' . esc_html( $i['id'] ) . '</span></td><td>' . esc_html( $i['label'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ------------------------------------------------------------------ *
	 * Parcels (all couriers)
	 * ------------------------------------------------------------------ */

	public static function page_orders() {
		if ( ! current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) {
			return;
		}
		$f    = self::filters();
		$res  = self::collect( $f );
		$base = self::page_url( self::PAGE_ORDERS );

		self::head(
			self::PAGE_ORDERS,
			__( 'Parcels', 'rar-woo-smart-courier' ),
			__( 'Every order and parcel across all couriers — send, track, refresh and export.', 'rar-woo-smart-courier' )
		);

		// Status views.
		echo '<div class="rwsc-d-seg">';
		foreach ( RWSC_Pathao_Status::groups() as $key => $label ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'view' => $key, 'courier' => $f['courier'] ), $base ) ) . '"' . ( $key === $f['view'] ? ' class="is-on"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</div>';
		// Courier filter (sent views only).
		if ( 'unsent' !== $f['view'] ) {
			echo '<div class="rwsc-d-seg">';
			echo '<a href="' . esc_url( add_query_arg( array( 'view' => $f['view'], 'courier' => 'all' ), $base ) ) . '"' . ( 'all' === $f['courier'] ? ' class="is-on"' : '' ) . '>' . esc_html__( 'All couriers', 'rar-woo-smart-courier' ) . '</a>';
			foreach ( RWSC_Couriers::slugs() as $slug ) {
				echo '<a href="' . esc_url( add_query_arg( array( 'view' => $f['view'], 'courier' => $slug ), $base ) ) . '"' . ( $slug === $f['courier'] ? ' class="is-on"' : '' ) . '>' . esc_html( RWSC_Couriers::label( $slug ) ) . '</a>';
			}
			echo '</div>';
		}

		echo '<form method="get" class="rwsc-d-filter"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_ORDERS ) . '"><input type="hidden" name="view" value="' . esc_attr( $f['view'] ) . '"><input type="hidden" name="courier" value="' . esc_attr( $f['courier'] ) . '">'
			. '<input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="' . esc_attr__( 'Order #, name, phone, consignment', 'rar-woo-smart-courier' ) . '"> '
			. '<input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"> <input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"> '
			. '<select name="per_page">';
		foreach ( array( 25, 50, 100 ) as $n ) {
			echo '<option value="' . esc_attr( $n ) . '"' . selected( $f['per_page'], $n, false ) . '>' . esc_html( $n ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'rar-woo-smart-courier' ) . '</button> '
			. '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( $f, array( 'action' => 'rwsc_d_export_csv' ) ), admin_url( 'admin-post.php' ) ), 'rwsc_export' ) ) . '">' . esc_html__( 'Export CSV', 'rar-woo-smart-courier' ) . '</a></form>';

		// Bulk bar.
		$opts = '';
		foreach ( RWSC_Couriers::slugs() as $slug ) {
			$m = RWSC_Couriers::get( $slug );
			if ( RWSC_Couriers::usable( $slug ) && ( ! $m || ! $m->manual() ) ) {
				$opts .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( RWSC_Couriers::label( $slug ) ) . '</option>';
			}
		}
		echo '<div class="rwsc-d-bulkbar">';
		if ( '' !== $opts ) {
			echo '<select id="rwsc-d-bulk-courier" aria-label="' . esc_attr__( 'Courier', 'rar-woo-smart-courier' ) . '">' . $opts . '</select> <button type="button" class="button button-primary" id="rwsc-d-bulk-send">' . esc_html__( 'Send selected', 'rar-woo-smart-courier' ) . '</button> '; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<button type="button" class="button" id="rwsc-d-bulk-refresh">' . esc_html__( 'Refresh selected', 'rar-woo-smart-courier' ) . '</button> <span class="rwsc-d-muted" id="rwsc-d-bulk-out"></span></div>';

		echo '<table class="wp-list-table widefat striped rwsc-d-table"><thead><tr><td class="check-column"><input type="checkbox" id="rwsc-d-check-all"></td>'
			. '<th>' . esc_html__( 'Order', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Customer', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'District', 'rar-woo-smart-courier' ) . '</th>'
			. '<th>' . esc_html__( 'Total', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Courier / parcel', 'rar-woo-smart-courier' ) . '</th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No orders match.', 'rar-woo-smart-courier' ) . '</td></tr>';
		}
		foreach ( $res['rows'] as $row ) {
			$o     = $row['order'];
			$id    = $o->get_id();
			$state = $o->get_shipping_state() ? $o->get_shipping_state() : $o->get_billing_state();
			echo '<tr><th class="check-column"><input type="checkbox" class="rwsc-d-check" value="' . esc_attr( $id ) . '" data-courier="' . esc_attr( $row['info'] ? $row['info']['courier'] : '' ) . '"></th>'
				. '<td><a href="' . esc_url( $o->get_edit_order_url() ) . '"><strong>#' . esc_html( $o->get_order_number() ) . '</strong></a><div class="rwsc-d-muted">' . esc_html( $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd M Y' ) : '' ) . ' · ' . esc_html( wc_get_order_status_name( $o->get_status() ) ) . '</div></td>'
				. '<td>' . esc_html( RWSC_Order_Data::recipient_name( $o ) ) . '<div class="rwsc-d-muted">' . esc_html( RWSC_Order_Data::recipient_phone( $o ) ) . '</div></td>'
				. '<td>' . esc_html( RWSC_Pathao_Locations::state_label( $state ) ) . '</td>'
				. '<td>' . wp_kses_post( $o->get_formatted_order_total() ) . '</td>'
				. '<td>' . RWSC_Couriers::cell_wrap( $o ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';

		echo '<p class="rwsc-d-muted">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d parcel / order in this view.', '%d parcels / orders in this view.', $res['total'], 'rar-woo-smart-courier' ), $res['total'] ) );
		if ( $res['capped'] ) {
			echo ' ' . esc_html( sprintf( /* translators: %d: cap */ __( 'Only the newest %d orders with a parcel are scanned — narrow the dates to see older ones.', 'rar-woo-smart-courier' ), self::PARCEL_CAP ) );
		}
		echo '</p>';

		if ( $res['pages'] > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $f['paged'],
						'total'   => $res['pages'],
					)
				)
			) . '</div></div>';
		}
		self::foot();
	}

	public static function export_csv() {
		if ( ! current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
		}
		check_admin_referer( 'rwsc_export' );
		$f = self::filters();
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions
		$res = self::collect( $f, true );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=courier-parcels-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'Order', 'Date', 'Name', 'Phone', 'District', 'Address', 'Order total', 'COD collect', 'Courier', 'Consignment', 'Status', 'Group', 'Delivery fee', 'Sent at' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$guard = static function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v; // Spreadsheet formula injection.
		};
		foreach ( $res['rows'] as $row ) {
			$o     = $row['order'];
			$i     = $row['info'];
			$state = $o->get_shipping_state() ? $o->get_shipping_state() : $o->get_billing_state();
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$out,
				array_map(
					$guard,
					array(
						$o->get_order_number(),
						$o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
						RWSC_Order_Data::recipient_name( $o ),
						RWSC_Order_Data::recipient_phone( $o ),
						RWSC_Pathao_Locations::state_label( $state ),
						RWSC_Order_Data::recipient_address( $o ),
						$o->get_total(),
						$i ? $i['amount'] : '',
						$i ? RWSC_Couriers::label( $i['courier'] ) : '',
						$i ? $i['id'] : '',
						$i ? $i['raw'] : '',
						$i ? $i['group'] : '',
						$i && $i['fee'] ? $i['fee'] : '',
						$i && $i['sent_at'] ? wp_date( 'Y-m-d H:i', $i['sent_at'] ) : '',
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
		}
		check_admin_referer( 'rwsc_d_save_settings' );
		$posted = isset( $_POST['rwsc_api'] ) && is_array( $_POST['rwsc_api'] ) ? wp_unslash( $_POST['rwsc_api'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		RWSC_Api_Settings::save( $posted );
		delete_transient( 'rwsc_suite_summary' );
		$tab = isset( $_POST['rwsc_tab'] ) ? sanitize_key( wp_unslash( $_POST['rwsc_tab'] ) ) : 'general';
		wp_safe_redirect( add_query_arg( array( 'saved' => '1', 'tab' => $tab ), admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ) );
		exit;
	}

	/* ---- settings form helpers (return HTML) ---- */

	private static function row( $label, $html, $help = '' ) {
		return '<div class="rwsc-d-row"><div class="rwsc-d-lbl">' . esc_html( $label ) . '</div><div class="rwsc-d-ctl">' . $html . ( $help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : '' ) . '</div></div>';
	}

	private static function panel( $title, $sub, $rows ) {
		return '<div class="rwsc-d-panel"><h2>' . esc_html( $title ) . '</h2>' . ( $sub ? '<p class="rwsc-d-panel-sub">' . esc_html( $sub ) . '</p>' : '' ) . $rows . '</div>';
	}

	private static function select( $name, $options, $current ) {
		$h = '<select name="rwsc_api[' . esc_attr( $name ) . ']">';
		foreach ( $options as $v => $l ) {
			$h .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) $current, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		return $h . '</select>';
	}

	private static function check( $name, $label ) {
		return '<label class="rwsc-d-switch"><input type="checkbox" name="rwsc_api[' . esc_attr( $name ) . ']" value="1"' . checked( RWSC_Api_Settings::yes( $name ), true, false ) . '><span></span>' . esc_html( $label ) . '</label>';
	}

	private static function text( $name, $s, $class = 'regular-text', $placeholder = '' ) {
		return '<input type="text" class="' . esc_attr( $class ) . '" name="rwsc_api[' . esc_attr( $name ) . ']" value="' . esc_attr( $s[ $name ] ) . '"' . ( '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '' ) . ' autocomplete="off">';
	}

	private static function secret_input( $name, $s ) {
		$ph = '' !== (string) $s[ $name ] ? '•••••••• (saved — leave blank to keep)' : '';
		return '<input type="password" class="regular-text" name="rwsc_api[' . esc_attr( $name ) . ']" value="" placeholder="' . esc_attr( $ph ) . '" autocomplete="new-password">';
	}

	private static function copybox( $text ) {
		return '<span class="rwsc-d-copy"><code>' . esc_html( $text ) . '</code><button type="button" class="button button-small rwsc-d-copybtn" data-copy="' . esc_attr( $text ) . '">' . esc_html__( 'Copy', 'rar-woo-smart-courier' ) . '</button></span>';
	}

	private static function pane( $slug, $active, $inner ) {
		return '<section class="rwsc-d-pane' . ( $slug === $active ? ' is-on' : '' ) . '" data-pane="' . esc_attr( $slug ) . '" id="rwsc-d-pane-' . esc_attr( $slug ) . '">' . $inner . '</section>';
	}

	private static function pane_head( $slug, $test_html = '' ) {
		return '<div class="rwsc-d-pane-h"><div>' . RWSC_Couriers::chip( $slug ) . ' ' . self::state_pill( $slug ) . '</div><div>' . $test_html . '</div></div>';
	}

	private static function test_btn( $slug, $label, $fields ) {
		return '<button type="button" class="button rwsc-d-ctest" data-courier="' . esc_attr( $slug ) . '" data-fields="' . esc_attr( $fields ) . '">' . esc_html( $label ) . '</button> <span class="rwsc-d-muted rwsc-d-ctest-out" data-courier="' . esc_attr( $slug ) . '"></span>';
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = RWSC_Api_Settings::all();
		$statuses = wc_get_order_statuses();
		$opt_st   = array( '' => __( '— do nothing —', 'rar-woo-smart-courier' ) ) + $statuses;
		$tabs     = array( 'general' => __( 'General', 'rar-woo-smart-courier' ) ) + array_combine( RWSC_Couriers::slugs(), array_map( array( 'RWSC_Couriers', 'label' ), RWSC_Couriers::slugs() ) );
		$active   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'general';
		}

		self::head( self::PAGE_SETTINGS, __( 'Settings', 'rar-woo-smart-courier' ), __( 'Connect each courier, set routing and automation. Secrets are stored encrypted.', 'rar-woo-smart-courier' ) );
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'rar-woo-smart-courier' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="rwsc-d-settings"><input type="hidden" name="action" value="rwsc_d_save_settings"><input type="hidden" name="rwsc_tab" id="rwsc-d-tab-field" value="' . esc_attr( $active ) . '">';
		wp_nonce_field( 'rwsc_d_save_settings' );

		echo '<div class="rwsc-d-tabs" role="tablist">';
		foreach ( $tabs as $slug => $label ) {
			$dot = 'general' === $slug ? '' : ' <span class="rwsc-d-dot is-' . esc_attr( 'ok' === self::state( $slug )[0] ? 'ok' : 'warn' ) . '"></span>';
			echo '<button type="button" role="tab" class="' . ( $slug === $active ? 'is-on' : '' ) . '" data-tab="' . esc_attr( $slug ) . '">' . esc_html( $label ) . $dot . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		echo self::pane( 'general', $active, self::tab_general( $s, $opt_st, $statuses ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::pane( 'pathao', $active, self::tab_pathao( $s, $opt_st, $statuses ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::pane( 'redx', $active, self::tab_redx( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::pane( 'steadfast', $active, self::tab_steadfast( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::pane( 'paperfly', $active, self::tab_paperfly( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::pane( 'sundarban', $active, self::tab_sundarban( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="rwsc-d-savebar"><span class="rwsc-d-muted">' . esc_html__( 'One Save button covers every tab.', 'rar-woo-smart-courier' ) . '</span><button type="submit" class="button button-primary button-large">' . esc_html__( 'Save settings', 'rar-woo-smart-courier' ) . '</button></div>';
		echo '</form>';
		self::foot();
	}

	private static function tab_general( array $s, array $opt_st, array $statuses ) {
		$couriers = array( 'pathao' => 'Pathao', 'redx' => 'RedX', 'steadfast' => 'SteadFast', 'paperfly' => 'Paperfly' );
		$h        = self::panel(
			__( 'Routing', 'rar-woo-smart-courier' ),
			__( 'Decide which courier automation uses and keep one parcel per order.', 'rar-woo-smart-courier' ),
			self::row( __( 'Default courier for auto-send', 'rar-woo-smart-courier' ), self::select( 'auto_courier', $couriers, $s['auto_courier'] ), 'Used only when the customer did not choose a courier. If RAR Woo Smart Courier recorded a choice (Pathao / RedX / SteadFast / Paperfly) that courier is used; any other courier (and Sundarban, which needs a CN typed by staff) is left for staff.' )
			. self::row( __( 'One courier per order', 'rar-woo-smart-courier' ), self::check( 'block_cross_courier', 'Refuse a second courier once one already has a parcel' ), 'Prevents two physical parcels for one order. An uncertain (timed-out) send blocks all couriers for 10 minutes.' )
			. self::row( 'RAR Woo Smart Courier', self::check( 'inherit_smart_courier', 'Use Smart Courier fallback weight for products without weight' ) . '<br>' . self::check( 'courier_guard', 'Warn when the customer chose another courier at checkout' ), RWSC_Order_Data::smart_courier_active() ? 'RAR Woo Smart Courier detected.' : 'RAR Woo Smart Courier not detected (settings are harmless).' )
		);

		$q = '';
		foreach ( $statuses as $k => $l ) {
			$q .= '<label class="rwsc-d-switch"><input type="checkbox" name="rwsc_api[queue_statuses][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) $s['queue_statuses'], true ), true, false ) . '><span></span>' . esc_html( $l ) . '</label>';
		}
		$h .= self::panel(
			__( 'Automation', 'rar-woo-smart-courier' ),
			__( 'Applies to every courier.', 'rar-woo-smart-courier' ),
			self::row( __( 'Auto-send when order becomes', 'rar-woo-smart-courier' ), self::select( 'auto_send_status', $opt_st, $s['auto_send_status'] ), 'Queued in the background. Skips orders where the customer chose a courier that cannot be auto-sent; failures are noted on the order.' )
			. self::row( __( 'After sending set status', 'rar-woo-smart-courier' ), self::select( 'status_on_sent', $opt_st, $s['status_on_sent'] ) )
			. self::row( __( 'On delivered set status', 'rar-woo-smart-courier' ), self::select( 'status_on_delivered', $opt_st, $s['status_on_delivered'] ) )
			. self::row( __( 'On returned set status', 'rar-woo-smart-courier' ), self::select( 'status_on_returned', $opt_st, $s['status_on_returned'] ) )
			. self::row( __( 'Ready-to-dispatch statuses', 'rar-woo-smart-courier' ), $q, 'Orders in these statuses appear in Parcels → “Not sent yet”.' )
			. self::row( __( 'Order notes', 'rar-woo-smart-courier' ), self::check( 'add_order_notes', 'Add a private order note for every courier event' ) )
			. self::row( __( 'Status sync', 'rar-woo-smart-courier' ), self::check( 'sync_enabled', 'Poll couriers every 30 min for open parcels (safety net for missed webhooks)' ) . '<br>Batch <input type="number" min="5" max="100" name="rwsc_api[sync_batch]" value="' . esc_attr( $s['sync_batch'] ) . '"> orders, up to <input type="number" min="7" max="180" name="rwsc_api[sync_max_age_days]" value="' . esc_attr( $s['sync_max_age_days'] ) . '"> days old' )
		);

		$h .= self::panel(
			__( 'Customer & advanced', 'rar-woo-smart-courier' ),
			'',
			self::row( __( 'Customer tracking', 'rar-woo-smart-courier' ), '<span class="rwsc-d-muted">' . esc_html__( 'Customer tracking (My Account, e-mails, [rwsc_tracking]) is handled by the Smart Courier settings.', 'rar-woo-smart-courier' ) . '</span>' )
			. self::row( __( 'Debug log', 'rar-woo-smart-courier' ), self::check( 'debug_log', 'Log API calls to WooCommerce → Status → Logs (secrets redacted)' ) )
			. self::row( __( 'Uninstall', 'rar-woo-smart-courier' ), self::check( 'delete_data_on_uninstall', 'Delete settings and tokens when the plugin is deleted (order data is always kept)' ) )
		);
		return $h;
	}

	private static function tab_pathao( array $s, array $opt_st, array $statuses ) {
		$stores = array( 0 => __( 'Pathao default store', 'rar-woo-smart-courier' ) );
		if ( RWSC_Api_Settings::is_configured() ) {
			$list = RWSC_Pathao_API::stores();
			if ( is_array( $list ) ) {
				foreach ( $list as $st ) {
					$stores[ $st['id'] ] = $st['name'] . ( $st['active'] ? '' : ' (inactive)' );
				}
			}
		}
		$secret_ph = '' !== $s['client_secret'] ? '•••••••• (saved — leave blank to keep)' : '';
		$pass_ph   = '' !== $s['password'] ? '•••••••• (saved — leave blank to keep)' : '';
		$btns      = '<button type="button" class="button" id="rwsc-d-test">' . esc_html__( 'Test connection', 'rar-woo-smart-courier' ) . '</button> <button type="button" class="button" id="rwsc-d-reset-token">' . esc_html__( 'Renew token', 'rar-woo-smart-courier' ) . '</button> <span id="rwsc-d-test-result" class="rwsc-d-muted"></span>';

		$h = self::pane_head( 'pathao', $btns );
		$h .= self::panel(
			__( 'Connection', 'rar-woo-smart-courier' ),
			'',
			self::row( 'Client ID', '<input type="text" class="regular-text" name="rwsc_api[client_id]" value="' . esc_attr( $s['client_id'] ) . '" autocomplete="off"' . ( defined( 'PATHAO_CLIENT_ID' ) ? ' readonly' : '' ) . '>', defined( 'PATHAO_CLIENT_ID' ) ? 'Defined in wp-config.php.' : '' )
			. self::row( 'Client Secret', '<input type="password" class="regular-text" name="rwsc_api[client_secret]" value="" placeholder="' . esc_attr( $secret_ph ) . '" autocomplete="new-password">', 'Stored encrypted. Optionally define <code>PATHAO_CLIENT_ID</code> / <code>PATHAO_CLIENT_SECRET</code> in wp-config.php.' )
			. self::row( __( 'Environment', 'rar-woo-smart-courier' ), self::select( 'environment', array( 'live' => 'Live', 'staging' => 'Staging (sandbox)' ), $s['environment'] ) )
			. self::row(
				__( 'Authentication', 'rar-woo-smart-courier' ),
				self::select( 'auth_mode', array( 'auto' => 'Auto (password grant if email+password set, else client login)', 'client' => 'Client ID/Secret login', 'password' => 'Password grant (documented issue-token)' ), $s['auth_mode'] )
				. '<br>Merchant e-mail <input type="text" class="regular-text" name="rwsc_api[username]" value="' . esc_attr( $s['username'] ) . '" autocomplete="off"> Password <input type="password" class="regular-text" name="rwsc_api[password]" placeholder="' . esc_attr( $pass_ph ) . '" autocomplete="new-password">',
				'E-mail/password are only needed for the password grant. Leave empty to keep using client login.'
			)
			. self::row( 'Webhook URL', self::copybox( RWSC_Webhook::url() ), 'Paste into the Pathao merchant panel → Developer API → Webhook.' )
			. self::row( 'Webhook secret', self::text( 'webhook_secret', $s ), 'Blank = your Client Secret (Pathao default). Compared in constant time.' )
		);
		$h .= self::panel(
			__( 'Dispatch defaults', 'rar-woo-smart-courier' ),
			'',
			self::row( __( 'Pickup store', 'rar-woo-smart-courier' ), self::select( 'default_store_id', $stores, $s['default_store_id'] ) )
			. self::row( __( 'Delivery type', 'rar-woo-smart-courier' ), self::select( 'delivery_type', array( 48 => 'Normal (48h)', 12 => 'On-demand (12h)' ), $s['delivery_type'] ) )
			. self::row( __( 'Item type', 'rar-woo-smart-courier' ), self::select( 'item_type', array( 2 => 'Parcel', 1 => 'Document' ), $s['item_type'] ) )
			. self::row( __( 'Fallback weight (kg)', 'rar-woo-smart-courier' ), '<input type="number" step="0.1" min="0.5" max="10" name="rwsc_api[fallback_weight]" value="' . esc_attr( $s['fallback_weight'] ) . '">', 'Used when no product has a weight. Product weights are converted to kg and multiplied by quantity; total is clamped to 0.5–10 kg.' )
			. self::row( __( 'City / zone / area', 'rar-woo-smart-courier' ), self::select( 'location_mode', array( 'auto' => 'Auto — let Pathao detect from address (recommended)', 'city_only' => 'Send city only', 'smart' => 'Smart — send city/zone/area when confidently matched' ), $s['location_mode'] ), 'A suggestion is always shown in the dispatch window so you can apply it manually.' )
			. self::row( __( 'Prepaid gateways', 'rar-woo-smart-courier' ), self::text( 'prepaid_gateways', $s ), 'Comma-separated gateway IDs whose orders collect ৳0. RAR Advance Payment is handled automatically (balance due only, once verified).' )
			. self::row( __( 'Item description', 'rar-woo-smart-courier' ), self::text( 'description_template', $s, 'large-text' ), 'Tokens: {items} {order_number} {customer_note} {site}' )
			. self::row( __( 'Special instruction', 'rar-woo-smart-courier' ), self::text( 'instruction_template', $s, 'large-text' ) )
			. self::row( __( 'Bulk sending', 'rar-woo-smart-courier' ), self::select( 'bulk_mode', array( 'individual' => 'One by one — instant consignment IDs (recommended)', 'native' => 'Pathao bulk API — faster, IDs arrive via webhook' ), $s['bulk_mode'] ) )
			. self::row( __( 'Tracking URL', 'rar-woo-smart-courier' ), self::text( 'tracking_url_template', $s, 'large-text' ), 'Tokens: {consignment_id} {phone}. Verify this link format in your Pathao panel.' )
		);
		return $h;
	}

	private static function tab_redx( array $s ) {
		return self::pane_head( 'redx', self::test_btn( 'redx', __( 'Test RedX connection', 'rar-woo-smart-courier' ), 'redx_token' ) )
			. self::panel(
				__( 'RedX', 'rar-woo-smart-courier' ),
				'',
				self::row( __( 'Status', 'rar-woo-smart-courier' ), self::check( 'enable_redx', 'Enable RedX (order-list button, bulk action, status sync)' ) )
				. self::row( 'API token', self::secret_input( 'redx_token', $s ), 'Stored encrypted. Sent as <code>API-ACCESS-TOKEN: Bearer …</code>.' )
				. self::row( __( 'Environment', 'rar-woo-smart-courier' ), self::select( 'redx_env', array( 'live' => 'Live', 'sandbox' => 'Sandbox' ), $s['redx_env'] ) )
				. self::row( __( 'Parcel API mode', 'rar-woo-smart-courier' ), self::select( 'redx_mode', array( 'standard' => 'Standard — RedX WordPress endpoint (no area needed)', 'official' => 'Area based — /parcel with delivery area (needs area match)' ), $s['redx_mode'] ), 'Standard is the endpoint used by the RedX WooCommerce plugin 1.0.1. Area-based mode is unverified: test with one parcel.' )
				. self::row( __( 'Pickup store ID', 'rar-woo-smart-courier' ), '<input type="number" min="0" name="rwsc_api[redx_pickup_store_id]" value="' . esc_attr( $s['redx_pickup_store_id'] ) . '">', 'Only used in area-based mode.' )
				. self::row( __( 'API base URL override', 'rar-woo-smart-courier' ), self::text( 'redx_base_url', $s, 'regular-text', RWSC_RedX::LIVE ), 'Leave blank for the default of the selected environment.' )
				. self::row( __( 'Tracking URL', 'rar-woo-smart-courier' ), self::text( 'redx_tracking_template', $s, 'large-text' ), 'Token: {id}.' )
			);
	}

	private static function tab_steadfast( array $s ) {
		return self::pane_head( 'steadfast', self::test_btn( 'steadfast', __( 'Test SteadFast connection', 'rar-woo-smart-courier' ), 'sf_api_key,sf_secret_key' ) )
			. self::panel(
				__( 'SteadFast', 'rar-woo-smart-courier' ),
				'',
				self::row( __( 'Status', 'rar-woo-smart-courier' ), self::check( 'enable_steadfast', 'Enable SteadFast (order-list button, bulk action, status sync, webhook)' ) )
				. self::row( 'API key', self::secret_input( 'sf_api_key', $s ), 'Stored encrypted.' )
				. self::row( 'Secret key', self::secret_input( 'sf_secret_key', $s ), 'Stored encrypted.' )
				. self::row( __( 'Send customer note', 'rar-woo-smart-courier' ), self::check( 'sf_send_note', 'Send the order’s customer note to SteadFast' ) )
				. self::row( 'Webhook URL', self::copybox( RWSC_SteadFast::webhook_url() ), 'Paste in the SteadFast portal → API/Webhook settings.' )
				. self::row( 'Webhook Bearer token', self::secret_input( 'sf_webhook_token', $s ), 'Choose a long random string, enter it here AND in the SteadFast portal. While empty, every webhook call is rejected (safe default).' )
				. self::row( __( 'API base URL override', 'rar-woo-smart-courier' ), self::text( 'sf_base_url', $s, 'regular-text', RWSC_SteadFast::BASE ), 'Default host is portal.packzy.com; https://portal.steadfast.com.bd/api/v1 is the alternative host.' )
				. self::row( __( 'Tracking URL', 'rar-woo-smart-courier' ), self::text( 'sf_tracking_template', $s, 'large-text' ), 'Token: {tracking_code}.' )
			);
	}

	private static function tab_paperfly( array $s ) {
		return self::pane_head( 'paperfly', self::test_btn( 'paperfly', __( 'Test Paperfly credentials', 'rar-woo-smart-courier' ), 'pf_username,pf_password,pf_key' ) )
			. '<div class="rwsc-d-note warn">' . esc_html__( 'Paperfly’s merchant API documentation is not public; this module follows community SDKs. Test with one parcel before relying on it.', 'rar-woo-smart-courier' ) . '</div>'
			. self::panel(
				__( 'Paperfly', 'rar-woo-smart-courier' ),
				'',
				self::row( __( 'Status', 'rar-woo-smart-courier' ), self::check( 'enable_paperfly', 'Enable Paperfly (order-list button, bulk action, status sync)' ) )
				. self::row( 'Username', self::text( 'pf_username', $s ) )
				. self::row( 'Password', self::secret_input( 'pf_password', $s ), 'Stored encrypted.' )
				. self::row( 'Paperfly key', self::secret_input( 'pf_key', $s ), 'Sent as the <code>paperflykey</code> header. Stored encrypted.' )
				. self::row( 'Merchant code', self::text( 'pf_merchant_code', $s ), 'Optional — sent as <code>merchantCode</code> when set (some accounts need it).' )
				. self::row( __( 'Environment', 'rar-woo-smart-courier' ), self::select( 'pf_env', array( 'live' => 'Live (api.paperfly.com.bd)', 'sandbox' => 'Sandbox (sandbox.paperfly-bd.com)' ), $s['pf_env'] ) )
				. self::row( __( 'Create-order path', 'rar-woo-smart-courier' ), self::text( 'pf_create_path', $s ), 'Default <code>OrderPlacement</code>. Some accounts use <code>NewOrderUpload</code> — use whichever your Paperfly onboarding mail gives.' )
				. self::row( __( 'Delivery option', 'rar-woo-smart-courier' ), self::select( 'pf_delivery_option', array( 'regular' => 'Regular', 'express' => 'Express' ), $s['pf_delivery_option'] ) )
				. self::row(
					__( 'Pickup details', 'rar-woo-smart-courier' ),
					'Name ' . self::text( 'pf_pick_name', $s ) . '<br>Address ' . self::text( 'pf_pick_address', $s, 'large-text' ) . '<br>Thana ' . self::text( 'pf_pick_thana', $s, '' ) . ' District ' . self::text( 'pf_pick_district', $s, '' ) . ' Phone ' . self::text( 'pf_pick_phone', $s, '' ),
					'All five are required by Paperfly for every parcel. Customer thana = the order’s City field; district = the state.'
				)
				. self::row( __( 'API base URL override', 'rar-woo-smart-courier' ), self::text( 'pf_base_url', $s, 'regular-text', RWSC_Paperfly::LIVE ) )
				. self::row( __( 'Tracking URL', 'rar-woo-smart-courier' ), self::text( 'pf_tracking_template', $s, 'large-text' ), 'Token: {id}. Default is Paperfly’s home page — paste the tracking-page link format from your Paperfly panel.' )
			);
	}

	private static function tab_sundarban( array $s ) {
		return self::pane_head( 'sundarban' )
			. '<div class="rwsc-d-note">' . esc_html__( 'Sundarban has no merchant API we could verify, so this module is manual: book the parcel in the Sundarban portal/app, then click “Sundarban” on the order and enter the CN number. You keep one-courier-per-order protection, duplicate-CN protection, tracking link and COD tracking.', 'rar-woo-smart-courier' ) . '</div>'
			. self::panel(
				__( 'Sundarban Courier (manual)', 'rar-woo-smart-courier' ),
				'',
				self::row( __( 'Status', 'rar-woo-smart-courier' ), self::check( 'enable_sundarban', 'Enable Sundarban (record CN numbers, set status by hand)' ) )
				. self::row( __( 'Tracking URL', 'rar-woo-smart-courier' ), self::text( 'sd_tracking_template', $s, 'large-text' ), 'Token: {id} (the CN). Default opens Sundarban’s public tracking page.' )
			);
	}

	/** Pull settings from RAR Courier Suite into the built-in engine (fills blanks, never overwrites). */
	public static function import_suite() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
		}
		check_admin_referer( 'rwsc_d_import_suite' );
		$ok = RWSC_Api_Settings::maybe_import_suite( true );
		RWSC_Bridge::backfill( 300 );
		wp_safe_redirect( add_query_arg( 'suite_imported', $ok ? '1' : '0', admin_url( 'admin.php?page=' . self::PAGE_TOOLS ) ) );
		exit;
	}

	public static function import_legacy() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
		}
		check_admin_referer( 'rwsc_d_import_legacy' );
		$n = RWSC_Couriers::import_legacy( 500 );
		wp_safe_redirect( add_query_arg( 'imported', $n, admin_url( 'admin.php?page=' . self::PAGE_TOOLS ) ) );
		exit;
	}

	public static function migrate_meta() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
		}
		check_admin_referer( 'rwsc_migrate' );
		global $wpdb;
		$n = 0;
		if ( RWSC_Pathao_Orders::hpos_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ptc_consignment_id' AND meta_value <> '' LIMIT 500" );
			foreach ( $ids as $id ) {
				$o = wc_get_order( (int) $id );
				if ( $o && '' === (string) $o->get_meta( RWSC_Pathao_Orders::META_ID, true ) ) {
					foreach ( array( RWSC_Pathao_Orders::META_ID, RWSC_Pathao_Orders::META_STATUS, RWSC_Pathao_Orders::META_FEE ) as $k ) {
						$v = get_post_meta( $o->get_id(), $k, true );
						if ( '' !== $v ) {
							$o->update_meta_data( $k, $v );
						}
					}
					$o->save_meta_data();
					++$n;
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'migrated', $n, admin_url( 'admin.php?page=' . self::PAGE_TOOLS ) ) );
		exit;
	}


	public static function page_tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$lock_ok = '1' === (string) $wpdb->get_var( "SELECT GET_LOCK('rwsc_selftest', 0)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $lock_ok ) {
			$wpdb->get_var( "SELECT RELEASE_LOCK('rwsc_selftest')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$exp    = RWSC_Pathao_API::token_expiry();
		$lastwh = (int) get_option( 'rwsc_last_webhook', 0 );
		$lastsy = (int) get_option( 'rwsc_last_sync_run', 0 );
		$next   = wp_next_scheduled( RWSC_Cron::HOOK_SYNC );
		$redx   = RWSC_Couriers::get( 'redx' );
		$sf     = RWSC_Couriers::get( 'steadfast' );
		$pf     = RWSC_Couriers::get( 'paperfly' );
		$sd     = RWSC_Couriers::get( 'sundarban' );
		$groups = array(
			__( 'Environment', 'rar-woo-smart-courier' ) => array(
				array( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ),
				array( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : '—', defined( 'WC_VERSION' ) ),
				array( 'HPOS (custom order tables)', RWSC_Pathao_Orders::hpos_enabled() ? 'enabled' : 'disabled (legacy posts)', true ),
				array( 'OpenSSL (secret encryption)', function_exists( 'openssl_encrypt' ) ? 'available' : 'missing — secrets stored in plain text', function_exists( 'openssl_encrypt' ) ),
				array( 'Named DB locks (duplicate-send protection)', $lock_ok ? 'GET_LOCK works' : 'unavailable — option-lock fallback in use', true ),
				array( 'RAR Woo Smart Courier', RWSC_Order_Data::smart_courier_active() ? 'detected' : 'not active', true ),
				array( 'RAR Advance Payment', class_exists( 'RAR_WAP_Order' ) ? 'detected (COD = balance due)' : 'not active', true ),
				array( 'Status sync cron', $next ? 'next ' . wp_date( 'H:i', $next ) . ( $lastsy ? ', last run ' . human_time_diff( $lastsy ) . ' ago' : '' ) : 'not scheduled', (bool) $next ),
			),
			'Pathao' => array(
				array( 'Credentials', RWSC_Api_Settings::is_configured() ? 'configured (' . RWSC_Api_Settings::get( 'environment' ) . ')' : 'not configured', RWSC_Api_Settings::is_configured() ),
				array( 'API token', $exp ? ( $exp > time() ? 'valid until ' . wp_date( 'd M H:i', $exp ) : 'expired (renews automatically)' ) : 'none yet', true ),
				array( 'Last webhook received', $lastwh ? human_time_diff( $lastwh ) . ' ago' : 'never — set the webhook in the Pathao panel', (bool) $lastwh ),
				array( 'Pathao webhook URL', RWSC_Webhook::url(), true ),
			),
			'RedX' => array(
				array( 'RedX', $redx->enabled() ? ( $redx->configured() ? 'enabled, token set (' . RWSC_Api_Settings::get( 'redx_mode' ) . ' mode, ' . RWSC_Api_Settings::get( 'redx_env' ) . ')' : 'enabled but token missing' ) : 'disabled', ! $redx->enabled() || $redx->configured() ),
			),
			'SteadFast' => array(
				array( 'SteadFast', $sf->enabled() ? ( $sf->configured() ? 'enabled, keys set' : 'enabled but keys missing' ) : 'disabled', ! $sf->enabled() || $sf->configured() ),
				array( 'SteadFast webhook', RWSC_SteadFast::webhook_url() . ( '' === (string) RWSC_Api_Settings::get( 'sf_webhook_token' ) ? ' — token not set (calls are rejected)' : '' ), '' !== (string) RWSC_Api_Settings::get( 'sf_webhook_token' ) || ! $sf->enabled() ),
				array( 'Last SteadFast webhook', get_option( 'rwsc_last_webhook_sf' ) ? human_time_diff( (int) get_option( 'rwsc_last_webhook_sf' ) ) . ' ago' : 'never', true ),
			),
			'Paperfly' => array(
				array( 'Paperfly', $pf->enabled() ? ( $pf->configured() ? 'enabled, credentials set (' . RWSC_Api_Settings::get( 'pf_env' ) . ', ' . RWSC_Api_Settings::get( 'pf_create_path' ) . ')' : 'enabled but credentials missing' ) : 'disabled', ! $pf->enabled() || $pf->configured() ),
			),
			'Sundarban' => array(
				array( 'Sundarban (manual)', $sd->enabled() ? 'enabled — CN numbers entered by staff' : 'disabled', true ),
			),
		);

		self::head( self::PAGE_TOOLS, __( 'Tools & Health', 'rar-woo-smart-courier' ), __( 'Diagnostics, maintenance and import tools. Nothing here changes a parcel unless you press a button.', 'rar-woo-smart-courier' ) );
		if ( isset( $_GET['suite_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-' . ( '1' === $_GET['suite_imported'] ? 'success' : 'info' ) . '"><p>' . esc_html( '1' === $_GET['suite_imported'] ? __( 'RAR Courier Suite settings imported and existing parcels synced to the Shipments board.', 'rar-woo-smart-courier' ) : __( 'No RAR Courier Suite settings found to import.', 'rar-woo-smart-courier' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.EscapeOutput
		}
		if ( isset( $_GET['migrated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Migrated %d order(s).', absint( $_GET['migrated'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( isset( $_GET['imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Imported %d RedX / SteadFast parcel(s).', absint( $_GET['imported'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}

		$bad = 0;
		foreach ( $groups as $rows ) {
			foreach ( $rows as $r ) {
				$bad += $r[2] ? 0 : 1;
			}
		}
		echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Health', 'rar-woo-smart-courier' ) . ' <span class="rwsc-d-state is-' . ( $bad ? 'warn' : 'ok' ) . '">' . esc_html( $bad ? sprintf( /* translators: %d: count */ _n( '%d item to review', '%d items to review', $bad, 'rar-woo-smart-courier' ), $bad ) : __( 'All good', 'rar-woo-smart-courier' ) ) . '</span></h2>';
		foreach ( $groups as $title => $rows ) {
			echo '<h3 style="margin:16px 0 4px">' . esc_html( $title ) . '</h3><table class="rwsc-d-health"><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><th>' . esc_html( $r[0] ) . '</th><td><span class="rwsc-d-dot' . ( $r[2] ? '' : ' is-warn' ) . '"></span>' . esc_html( $r[1] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Couriers — last 30 days', 'rar-woo-smart-courier' ) . '</h2><table class="widefat striped rwsc-d-table"><thead><tr><th>Courier</th><th>Sent</th><th>Pending</th><th>In transit</th><th>Delivered</th><th>Returned</th><th>Needs attention</th><th>Open COD (৳)</th></tr></thead><tbody>';
		foreach ( RWSC_Couriers::summary() as $slug => $r ) {
			echo '<tr><td>' . esc_html( RWSC_Couriers::label( $slug ) ) . '</td><td>' . (int) $r['sent'] . '</td><td>' . (int) $r['pending'] . '</td><td>' . (int) $r['transit'] . '</td><td>' . (int) $r['delivered'] . '</td><td>' . (int) $r['returned'] . '</td><td>' . (int) $r['attention'] . '</td><td>' . esc_html( number_format_i18n( (int) $r['cod_open'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Migrate from RAR Courier Suite', 'rar-woo-smart-courier' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rwsc_d_import_suite">';
		wp_nonce_field( 'rwsc_d_import_suite' );
		echo '<p>' . esc_html__( 'Copies the credentials and options of RAR Courier Suite that are still empty here, and mirrors existing parcels into the Shipments board. Safe to repeat; nothing is overwritten. Do this before deleting the Suite.', 'rar-woo-smart-courier' ) . '</p><button class="button button-primary">' . esc_html__( 'Import from RAR Courier Suite', 'rar-woo-smart-courier' ) . '</button> <span class="rwsc-d-muted">' . ( get_option( 'rwsc_suite_imported' ) ? esc_html( sprintf( /* translators: %s: time */ __( 'Last imported %s ago.', 'rar-woo-smart-courier' ), human_time_diff( (int) get_option( 'rwsc_suite_imported' ) ) ) ) : esc_html__( 'Not imported yet.', 'rar-woo-smart-courier' ) ) . '</span></form></div>';

		echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Maintenance', 'rar-woo-smart-courier' ) . '</h2>'
			. '<p><button type="button" class="button" id="rwsc-d-flush">' . esc_html__( 'Clear cached Pathao stores / cities / zones', 'rar-woo-smart-courier' ) . '</button> <span id="rwsc-d-flush-result" class="rwsc-d-muted"></span></p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rwsc_d_import_legacy">';
		wp_nonce_field( 'rwsc_d_import_legacy' );
		echo '<p>' . esc_html__( 'Import parcels created by the old "Redx for WooCommerce" / "SteadFast API" plugins so they show here and join status sync (500 per run, safe to repeat).', 'rar-woo-smart-courier' ) . '</p><button class="button">' . esc_html__( 'Import RedX / SteadFast parcels', 'rar-woo-smart-courier' ) . '</button></form>';
		if ( RWSC_Pathao_Orders::hpos_enabled() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:14px"><input type="hidden" name="action" value="rwsc_d_migrate_meta">';
			wp_nonce_field( 'rwsc_migrate' );
			echo '<p>' . esc_html__( 'HPOS is on: copy Pathao data created by the original Pathao plugin from legacy post meta into order meta (500 per run, safe to repeat).', 'rar-woo-smart-courier' ) . '</p><button class="button">' . esc_html__( 'Migrate legacy parcel data', 'rar-woo-smart-courier' ) . '</button></form>';
		}
		echo '</div>';

		$tail = RWSC_Logger::tail( 60 );
		echo '<div class="rwsc-d-panel"><h2>' . esc_html__( 'Latest log lines', 'rar-woo-smart-courier' ) . '</h2><textarea readonly rows="12" class="large-text code">' . esc_textarea( '' !== $tail ? $tail : 'No log yet. Enable the debug log in Settings → General.' ) . '</textarea></div>';
		self::foot();
	}
}
