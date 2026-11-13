<?php
/**
 * WooCommerce order screens (HPOS and legacy): list column, bulk actions,
 * order meta box, and admin asset loading.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Dispatch_Orders_UI {

	public static function init() {
		// Columns: HPOS + legacy.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );

		// Bulk actions: HPOS + legacy.
		foreach ( array( 'woocommerce_page_wc-orders', 'edit-shop_order' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( __CLASS__, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function columns( $columns ) {
		$columns['rwsc_dispatch'] = __( 'Dispatch', 'rar-woo-smart-courier' );
		return $columns;
	}

	public static function column_hpos( $column, $order ) {
		// Column id is rwsc_dispatch (rwsc_courier belongs to the shipment column). Older ids kept for custom code.
		if ( in_array( $column, array( 'rwsc_dispatch', 'rwsc_pathao', 'rwsc_couriers' ), true ) && $order instanceof WC_Order ) {
			echo RWSC_Couriers::cell_wrap( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function column_legacy( $column, $post_id ) {
		if ( in_array( $column, array( 'rwsc_dispatch', 'rwsc_pathao', 'rwsc_couriers' ), true ) ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				self::column_hpos( $column, $order );
			}
		}
	}

	public static function bulk_actions( $actions ) {
		if ( current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) {
			if ( RWSC_Api_Settings::is_configured() ) {
				$actions['rwsc_send']    = __( 'Send with Pathao', 'rar-woo-smart-courier' );
				$actions['rwsc_refresh'] = __( 'Refresh Pathao status', 'rar-woo-smart-courier' );
			}
			foreach ( RWSC_Couriers::modules() as $slug => $m ) {
				if ( $m->enabled() && ! $m->manual() ) {
					/* translators: %s: courier */
					$actions[ 'rwsc_send_' . $slug ]    = sprintf( __( 'Send with %s', 'rar-woo-smart-courier' ), $m->label() );
					/* translators: %s: courier */
					$actions[ 'rwsc_refresh_' . $slug ] = sprintf( __( 'Refresh %s status', 'rar-woo-smart-courier' ), $m->label() );
				}
			}
		}
		return $actions;
	}

	/**
	 * The modal does the work in the browser; we only hand it the selection.
	 */
	public static function handle_bulk( $redirect_to, $action, $ids ) {
		if ( preg_match( '/^rwsc_(send|refresh)_([a-z]+)$/', (string) $action, $mm ) && RWSC_Couriers::get( $mm[2] ) && ! RWSC_Couriers::get( $mm[2] )->manual() && current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) {
			$res = RWSC_Couriers::bulk( $mm[2], $mm[1], (array) $ids, get_current_user_id() );
			return add_query_arg(
				'rwsc_bulk',
				rawurlencode( implode( '|', array( $mm[2], $mm[1], $res['ok'], $res['fail'], $res['first_error'] ) ) ),
				$redirect_to
			);
		}
		if ( ! in_array( $action, array( 'rwsc_send', 'rwsc_refresh' ), true ) || ! current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) {
			return $redirect_to;
		}
		return add_query_arg(
			array(
				'rwsc_do'  => 'rwsc_send' === $action ? 'send' : 'refresh',
				'rwsc_ids' => implode( ',', array_map( 'absint', (array) $ids ) ),
			),
			$redirect_to
		);
	}

	public static function meta_box() {
		$screen = 'shop_order';
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen = wc_get_page_screen_id( 'shop-order' );
		}
		add_meta_box( 'rwsc-d-courier-dispatch', __( 'Courier Dispatch', 'rar-woo-smart-courier' ), array( __CLASS__, 'render_box' ), $screen, 'side', 'high' );
	}

	public static function render_suite_box( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		echo RWSC_Couriers::cell_wrap( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function bulk_notice() {
		if ( empty( $_GET['rwsc_bulk'] ) || ! current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$p = explode( '|', sanitize_text_field( rawurldecode( wp_unslash( $_GET['rwsc_bulk'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( count( $p ) < 4 ) {
			return;
		}
		$ok  = (int) $p[2];
		$bad = (int) $p[3];
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			$bad ? 'notice-warning' : 'notice-success',
			esc_html(
				sprintf(
					/* translators: 1: courier 2: action 3: ok 4: failed 5: first error */
					__( '%1$s %2$s: %3$d done, %4$d failed. %5$s', 'rar-woo-smart-courier' ),
					RWSC_Couriers::label( $p[0] ),
					'send' === $p[1] ? __( 'send', 'rar-woo-smart-courier' ) : __( 'refresh', 'rar-woo-smart-courier' ),
					$ok,
					$bad,
					isset( $p[4] ) ? $p[4] : ''
				)
			)
		);
	}

	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		echo '<div class="rwsc-d-box">' . RWSC_Couriers::cell_wrap( $order ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$chose = RWSC_Order_Data::selected_courier( $order );
		if ( '' !== $chose ) {
			echo '<p class="rwsc-d-muted">' . esc_html( sprintf( /* translators: %s: courier */ __( 'Checkout courier: %s', 'rar-woo-smart-courier' ), $chose ) ) . '</p>';
		}
		$via = RWSC_Couriers::sent_via( $order );
		if ( 'pathao' === $via && RWSC_Pathao_Orders::has_real_consignment( $order ) ) {
			echo '<p><button type="button" class="button rwsc-d-refresh" data-order-id="' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Refresh status', 'rar-woo-smart-courier' ) . '</button> ';
			$track = RWSC_Pathao_Orders::tracking_url( $order );
			if ( $track ) {
				echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $track ) . '">' . esc_html__( 'Track', 'rar-woo-smart-courier' ) . '</a>';
			}
			echo '</p>';
		}
		$items = array();
		if ( 'pathao' === $via ) {
			foreach ( array_reverse( RWSC_Pathao_Orders::events( $order ) ) as $e ) {
				$items[] = array( RWSC_Pathao_Status::info( $e['s'] )['label'], $e );
			}
		} elseif ( '' !== $via ) {
			$m = RWSC_Couriers::get( $via );
			$u = $m->tracking_url( $order );
			if ( $u ) {
				echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $u ) . '">' . esc_html__( 'Track', 'rar-woo-smart-courier' ) . '</a></p>';
			}
			foreach ( array_reverse( (array) $m->events( $order ) ) as $e ) {
				$items[] = array( $m->normalise( isset( $e['s'] ) ? $e['s'] : '' )['label'], $e );
			}
		}
		if ( $items ) {
			echo '<ul class="rwsc-d-timeline">';
			foreach ( array_slice( $items, 0, 12 ) as $it ) {
				$e = $it[1];
				echo '<li><strong>' . esc_html( $it[0] ) . '</strong> <span class="rwsc-d-muted">' . esc_html( wp_date( 'd M, h:i a', (int) ( isset( $e['t'] ) ? $e['t'] : 0 ) ) . ' · ' . ( isset( $e['src'] ) ? $e['src'] : '' ) . ( ! empty( $e['r'] ) ? ' · ' . $e['r'] : '' ) ) . '</span></li>';
			}
			echo '</ul>';
		}
	}

	public static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( in_array( $id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true ) ) {
			return true;
		}
		return false !== strpos( $id, 'rwsc-api' ) || false !== strpos( $id, 'rwsc-tools' ) || false !== strpos( $id, 'rwsc-parcels' );
	}

	public static function assets() {
		if ( ! self::is_plugin_screen() || ! current_user_can( RWSC_Dispatch_Ajax::operator_cap() ) ) {
			return;
		}
		wp_enqueue_style( 'rwsc-dispatch', RWSC_URL . 'assets/css/dispatch.css', array(), RWSC_VERSION );
		wp_enqueue_script( 'rwsc-dispatch', RWSC_URL . 'assets/js/dispatch.js', array(), RWSC_VERSION, true );

		$do  = isset( $_GET['rwsc_do'] ) ? sanitize_key( wp_unslash( $_GET['rwsc_do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ids = isset( $_GET['rwsc_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['rwsc_ids'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification

		wp_localize_script(
			'rwsc-dispatch',
			'RWSC_D',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( RWSC_Dispatch_Ajax::NONCE ),
				'canSettings'   => current_user_can( 'manage_options' ),
				'autoDo'        => in_array( $do, array( 'send', 'refresh' ), true ) ? $do : '',
				'autoIds'       => array_values( $ids ),
				'deliveryTypes' => array(
					48 => __( 'Normal delivery', 'rar-woo-smart-courier' ),
					12 => __( 'On-demand', 'rar-woo-smart-courier' ),
				),
				'itemTypes'     => array(
					2 => __( 'Parcel', 'rar-woo-smart-courier' ),
					1 => __( 'Document', 'rar-woo-smart-courier' ),
				),
				'i18n'          => array(
					'title'      => __( 'Send to Pathao', 'rar-woo-smart-courier' ),
					'loading'    => __( 'Loading orders…', 'rar-woo-smart-courier' ),
					'sendAll'    => __( 'Send %d order(s)', 'rar-woo-smart-courier' ),
					'cancel'     => __( 'Close', 'rar-woo-smart-courier' ),
					'sending'    => __( 'Sending…', 'rar-woo-smart-courier' ),
					'sent'       => __( 'Sent', 'rar-woo-smart-courier' ),
					'skipped'    => __( 'Skipped', 'rar-woo-smart-courier' ),
					'failed'     => __( 'Failed', 'rar-woo-smart-courier' ),
					'estimate'   => __( 'Estimated fee', 'rar-woo-smart-courier' ),
					'suggested'  => __( 'Suggested', 'rar-woo-smart-courier' ),
					'apply'      => __( 'Apply', 'rar-woo-smart-courier' ),
					'refreshing' => __( 'Refreshing…', 'rar-woo-smart-courier' ),
					'done'       => __( 'Done', 'rar-woo-smart-courier' ),
					'enterCn'     => __( 'Enter the Sundarban CN (consignment note) number for order #%s', 'rar-woo-smart-courier' ),
					'confirmSend' => __( 'Send order #%1$s with %2$s?', 'rar-woo-smart-courier' ),
					'confirmBulk' => __( 'Send %1$s order(s) with %2$s?', 'rar-woo-smart-courier' ),
					'netError'   => __( 'Network error. If a parcel was being created, check the courier panel before retrying.', 'rar-woo-smart-courier' ),
				),
			)
		);
	}
}
