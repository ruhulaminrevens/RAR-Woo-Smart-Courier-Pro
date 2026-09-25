<?php
/**
 * Admin AJAX endpoints (JSON).
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Ajax {

    public static function init() {
        foreach ( array( 'quote', 'dashboard', 'shipments', 'update_shipment', 'bulk_shipments', 'shipment' ) as $a ) {
            add_action( 'wp_ajax_rwsc_' . $a, array( __CLASS__, $a ) );
        }
    }

    private static function guard( $cap = null ) {
        if ( ! check_ajax_referer( 'rwsc_admin', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page.', 'rar-woo-smart-courier' ) ), 403 );
        }
        $cap = $cap ? $cap : RWSC_Admin::view_cap();
        if ( ! current_user_can( $cap ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'rar-woo-smart-courier' ) ), 403 );
        }
    }

    private static function req( $key, $default = '' ) {
        return isset( $_REQUEST[ $key ] ) ? wp_unslash( $_REQUEST[ $key ] ) : $default; // phpcs:ignore WordPress.Security
    }

    /**
     * Rate calculator / zone tester.
     */
    public static function quote() {
        self::guard();
        $dest  = array(
            'country'   => 'BD',
            'state'     => sanitize_text_field( (string) self::req( 'state' ) ),
            'city'      => sanitize_text_field( (string) self::req( 'city' ) ),
            'address_1' => sanitize_text_field( (string) self::req( 'address' ) ),
        );
        $w     = self::req( 'weight', '' );
        $quote = RWSC_Engine::quotes(
            array(
                'destination'         => $dest,
                'weight'              => '' === $w ? null : (float) $w,
                'free'                => '1' === (string) self::req( 'free' ),
                'include_unavailable' => true,
            )
        );
        $dispatch       = RWSC_Engine::dispatch_day();
        $quote['dispatch'] = wp_date( 'D j M', $dispatch->setTime( 12, 0 )->getTimestamp() );
        wp_send_json_success( $quote );
    }

    public static function dashboard() {
        self::guard();
        $days = (int) self::req( 'days', 30 );
        $data = RWSC_Reports::dashboard( $days );
        $att  = RWSC_Reports::shipments( array( 'tab' => 'ready', 'days' => 90, 'per_page' => 6 ) );
        $data['attention'] = array(
            'rows'  => array_values( array_filter( $att['rows'], static function ( $r ) { return ! empty( $r['stale'] ) || '' === $r['ship']['courier']; } ) ),
            'ready' => $att['tabs']['ready'],
            'tabs'  => $att['tabs'],
        );
        wp_send_json_success( $data );
    }

    public static function shipments() {
        self::guard();
        $data = RWSC_Reports::shipments(
            array(
                'tab'      => sanitize_key( (string) self::req( 'tab', 'ready' ) ),
                'courier'  => sanitize_key( (string) self::req( 'courier' ) ),
                'zone'     => sanitize_key( (string) self::req( 'zone' ) ),
                'q'        => sanitize_text_field( (string) self::req( 'q' ) ),
                'days'     => (int) self::req( 'days', 30 ),
                'page'     => (int) self::req( 'page', 1 ),
                'per_page' => (int) self::req( 'per_page', 25 ),
            )
        );
        wp_send_json_success( $data );
    }

    private static function order( $id ) {
        $order = wc_get_order( absint( $id ) );
        return ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) ? $order : null;
    }

    public static function shipment() {
        self::guard();
        $order = self::order( self::req( 'order_id' ) );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-smart-courier' ) ), 404 );
        }
        wp_send_json_success( RWSC_Reports::row_payload( $order ) );
    }

    private static function changes_from_request() {
        $changes = array();
        foreach ( array( 'courier', 'tracking', 'status', 'note' ) as $k ) {
            if ( isset( $_REQUEST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                $changes[ $k ] = (string) self::req( $k );
            }
        }
        return $changes;
    }

    public static function update_shipment() {
        self::guard();
        $order = self::order( self::req( 'order_id' ) );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-smart-courier' ) ), 404 );
        }
        $notify = self::req( 'notify', '' );
        $result = RWSC_Shipments::update( $order, self::changes_from_request(), array( 'notify' => '' === $notify ? null : '1' === (string) $notify ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( RWSC_Reports::row_payload( self::order( $order->get_id() ) ) );
    }

    public static function bulk_shipments() {
        self::guard();
        $ids     = array_filter( array_map( 'absint', (array) self::req( 'ids', array() ) ) );
        $changes = array_intersect_key( self::changes_from_request(), array_flip( array( 'courier', 'status' ) ) );
        if ( ! $ids || ! $changes ) {
            wp_send_json_error( array( 'message' => __( 'Nothing to update.', 'rar-woo-smart-courier' ) ), 400 );
        }
        $ok     = 0;
        $errors = array();
        foreach ( array_slice( $ids, 0, 200 ) as $id ) {
            $order = self::order( $id );
            if ( ! $order ) {
                continue;
            }
            $r = RWSC_Shipments::update( $order, $changes );
            if ( is_wp_error( $r ) ) {
                $errors[] = '#' . $order->get_order_number() . ': ' . $r->get_error_message();
            } else {
                $ok++;
            }
        }
        wp_send_json_success( array( 'updated' => $ok, 'errors' => $errors ) );
    }
}
