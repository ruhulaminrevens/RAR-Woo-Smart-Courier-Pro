<?php
/**
 * Shipment model stored on the WooCommerce order (HPOS + legacy safe, via WC_Order meta API).
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Shipments {

    /** Item meta keys written by the plugin (hidden in the admin item view). */
    const ITEM_KEYS = array(
        '_rwsc_courier', '_rwsc_selected_courier', '_rwsc_eta', '_rwsc_edd', '_rwsc_zone', '_rwsc_district',
        '_rwsc_weight', '_rwsc_normal_cost', '_rwsc_free_applied', '_rwsc_recommended',
        // v1.x visible keys.
        'rwsc_courier_key', 'rwsc_courier_name', 'rwsc_eta', 'rwsc_normal_cost', 'rwsc_free_applied', 'rwsc_recommended',
        'rwsc_edd', 'rwsc_zone', 'rwsc_district', 'rwsc_weight', 'rwsc_badges',
    );

    public static function init() {
        add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'on_checkout_order' ), 20 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_checkout_order' ), 20 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
        add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_item_meta' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_backfill' ), 30 );
    }

    /* ------------------------------------------------------------------ */
    /* Status vocabulary                                                   */
    /* ------------------------------------------------------------------ */

    public static function statuses() {
        return apply_filters(
            'rwsc_shipment_statuses',
            array(
                'pending'    => array( 'label' => __( 'Awaiting booking', 'rar-woo-smart-courier' ), 'short' => __( 'To book', 'rar-woo-smart-courier' ), 'color' => '#64748b', 'step' => 0 ),
                'booked'     => array( 'label' => __( 'Booked', 'rar-woo-smart-courier' ), 'short' => __( 'Booked', 'rar-woo-smart-courier' ), 'color' => '#6366f1', 'step' => 1 ),
                'picked'     => array( 'label' => __( 'Picked up', 'rar-woo-smart-courier' ), 'short' => __( 'Picked', 'rar-woo-smart-courier' ), 'color' => '#0ea5e9', 'step' => 2 ),
                'in_transit' => array( 'label' => __( 'In transit', 'rar-woo-smart-courier' ), 'short' => __( 'Transit', 'rar-woo-smart-courier' ), 'color' => '#f59e0b', 'step' => 3 ),
                'hold'       => array( 'label' => __( 'On hold / issue', 'rar-woo-smart-courier' ), 'short' => __( 'Hold', 'rar-woo-smart-courier' ), 'color' => '#f97316', 'step' => 3 ),
                'delivered'  => array( 'label' => __( 'Delivered', 'rar-woo-smart-courier' ), 'short' => __( 'Delivered', 'rar-woo-smart-courier' ), 'color' => '#16a34a', 'step' => 4 ),
                'returned'   => array( 'label' => __( 'Returned', 'rar-woo-smart-courier' ), 'short' => __( 'Returned', 'rar-woo-smart-courier' ), 'color' => '#dc2626', 'step' => 4 ),
            )
        );
    }

    public static function status_label( $status ) {
        $all = self::statuses();
        return isset( $all[ $status ] ) ? $all[ $status ]['label'] : ucfirst( str_replace( '_', ' ', (string) $status ) );
    }

    public static function is_status( $status ) {
        return array_key_exists( (string) $status, self::statuses() );
    }

    /** Statuses meaning "with the courier, not finished". */
    public static function active_statuses() {
        return array( 'booked', 'picked', 'in_transit', 'hold' );
    }

    /* ------------------------------------------------------------------ */
    /* Read                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * @param WC_Order|int $order Order.
     */
    public static function has( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order ) {
            return false;
        }
        return '' !== (string) $order->get_meta( '_rwsc_courier', true ) || '' !== (string) $order->get_meta( '_rwsc_selected_courier', true );
    }

    /**
     * Normalised shipment data for an order.
     *
     * @param WC_Order $order Order.
     */
    public static function get( WC_Order $order ) {
        $key  = (string) $order->get_meta( '_rwsc_courier', true );
        $name = (string) $order->get_meta( '_rwsc_selected_courier', true );
        if ( '' === $key && '' !== $name ) {
            $key = RWSC_Settings::courier_key_by_name( $name );
        }
        if ( '' !== $key && RWSC_Settings::courier( $key ) ) {
            $name = RWSC_Settings::courier_name( $key );
        }
        $status = (string) $order->get_meta( '_rwsc_status', true );
        if ( '' === $status && '' !== $key ) {
            $status = 'pending';
        }
        $statuses = self::statuses();
        $tracking = (string) $order->get_meta( '_rwsc_tracking', true );
        $zone     = (string) $order->get_meta( '_rwsc_zone', true );
        $zone     = in_array( $zone, RWSC_Settings::ZONES, true ) ? $zone : '';
        $status   = isset( $statuses[ $status ] ) ? $status : ( '' !== $status ? 'pending' : '' );
        $district = (string) $order->get_meta( '_rwsc_district', true );
        $edd      = explode( '|', (string) $order->get_meta( '_rwsc_edd', true ) );
        $log      = $order->get_meta( '_rwsc_log', true );

        return array(
            'order_id'      => $order->get_id(),
            'number'        => $order->get_order_number(),
            'courier'       => $key,
            'courier_name'  => $name,
            'color'         => '' !== $key ? RWSC_Settings::courier_color( $key ) : '#94a3b8',
            'zone'          => $zone,
            'zone_label'    => RWSC_Locations::zone_label( $zone ),
            'district'      => $district,
            'district_name' => RWSC_Locations::name( $district ),
            'eta'           => (string) $order->get_meta( '_rwsc_eta', true ),
            'edd_from'      => isset( $edd[0] ) ? $edd[0] : '',
            'edd_to'        => isset( $edd[1] ) ? $edd[1] : ( isset( $edd[0] ) ? $edd[0] : '' ),
            'edd_label'     => '' !== $edd[0] ? RWSC_Engine::range_label( $edd[0], isset( $edd[1] ) ? $edd[1] : $edd[0] ) : '',
            'weight'        => (float) $order->get_meta( '_rwsc_weight', true ),
            'normal_cost'   => (float) $order->get_meta( '_rwsc_normal_cost', true ),
            'free_applied'  => 'yes' === $order->get_meta( '_rwsc_free_applied', true ),
            'recommended'   => 'yes' === $order->get_meta( '_rwsc_recommended', true ),
            'source'        => (string) $order->get_meta( '_rwsc_source', true ),
            'tracking'      => $tracking,
            'tracking_url'  => self::tracking_url( $key, $tracking, $order ),
            'status'        => $status,
            'status_label'  => '' !== $status ? self::status_label( $status ) : '',
            'status_color'  => isset( $statuses[ $status ] ) ? $statuses[ $status ]['color'] : '#94a3b8',
            'step'          => isset( $statuses[ $status ] ) ? (int) $statuses[ $status ]['step'] : 0,
            'note'          => (string) $order->get_meta( '_rwsc_note', true ),
            'booked_at'     => (int) $order->get_meta( '_rwsc_booked_at', true ),
            'delivered_at'  => (int) $order->get_meta( '_rwsc_delivered_at', true ),
            'returned_at'   => (int) $order->get_meta( '_rwsc_returned_at', true ),
            'updated_at'    => (int) $order->get_meta( '_rwsc_status_at', true ),
            'log'           => is_array( $log ) ? array_values( $log ) : array(),
            'cod'           => self::cod_amount( $order ),
        );
    }

    /**
     * Cash to collect on delivery.
     */
    public static function cod_amount( WC_Order $order ) {
        $methods = (array) RWSC_Settings::get( 'cod_methods', array( 'cod' ) );
        if ( ! in_array( $order->get_payment_method(), $methods, true ) ) {
            return 0.0;
        }
        return max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
    }

    public static function phone_local( $phone ) {
        $phone = strtr( (string) $phone, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
        $d     = preg_replace( '/\D+/', '', $phone );
        if ( 0 === strpos( $d, '880' ) ) {
            $d = substr( $d, 2 );
        } elseif ( 0 === strpos( $d, '88' ) && 13 === strlen( $d ) ) {
            $d = substr( $d, 2 );
        }
        if ( 10 === strlen( $d ) && '1' === $d[0] ) {
            $d = '0' . $d;
        }
        return $d;
    }

    public static function order_phone( WC_Order $order ) {
        $p = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
        return self::phone_local( '' !== $p ? $p : (string) $order->get_billing_phone() );
    }

    public static function tracking_url( $courier_key, $tracking, ?WC_Order $order = null ) {
        $tracking = trim( (string) $tracking );
        if ( '' === $tracking || '' === (string) $courier_key ) {
            return '';
        }
        $c   = RWSC_Settings::courier( $courier_key );
        $tpl = $c ? trim( (string) $c['tracking_url'] ) : '';
        if ( '' === $tpl ) {
            return '';
        }
        $url = str_replace(
            array( '{tracking}', '{phone}', '{order}' ),
            array( rawurlencode( $tracking ), $order ? rawurlencode( self::order_phone( $order ) ) : '', $order ? rawurlencode( (string) $order->get_order_number() ) : '' ),
            $tpl
        );
        return (string) apply_filters( 'rwsc_tracking_url', esc_url_raw( $url ), $courier_key, $tracking, $order );
    }

    /**
     * Destination array for the engine from an order (shipping address, falling back to billing).
     */
    public static function order_destination( WC_Order $order ) {
        $use_ship = '' !== (string) $order->get_shipping_country() || '' !== (string) $order->get_shipping_state() || '' !== (string) $order->get_shipping_address_1();
        $p        = $use_ship ? 'shipping' : 'billing';
        return array(
            'country'   => (string) $order->{"get_{$p}_country"}(),
            'state'     => (string) $order->{"get_{$p}_state"}(),
            'city'      => (string) $order->{"get_{$p}_city"}(),
            'address_1' => (string) $order->{"get_{$p}_address_1"}(),
            'address_2' => (string) $order->{"get_{$p}_address_2"}(),
            'postcode'  => (string) $order->{"get_{$p}_postcode"}(),
        );
    }

    public static function needs_shipping( WC_Order $order ) {
        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof WC_Order_Item_Product ) {
                $p = $item->get_product();
                if ( ! $p || $p->needs_shipping() ) {
                    return true;
                }
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Write                                                               */
    /* ------------------------------------------------------------------ */

    private static function actor() {
        $u = wp_get_current_user();
        return ( $u && $u->exists() ) ? $u->display_name : __( 'System', 'rar-woo-smart-courier' );
    }

    private static function log( WC_Order $order, $status, $message ) {
        $log = $order->get_meta( '_rwsc_log', true );
        $log = is_array( $log ) ? $log : array();
        $log[] = array(
            't' => time(),
            's' => (string) $status,
            'u' => self::actor(),
            'm' => (string) $message,
        );
        if ( count( $log ) > 60 ) {
            $log = array_slice( $log, -60 );
        }
        $order->update_meta_data( '_rwsc_log', $log );
    }

    /**
     * Write courier assignment data to the order (does not save).
     */
    private static function apply_assignment( WC_Order $order, array $d, $source ) {
        $order->update_meta_data( '_rwsc_courier', sanitize_key( $d['courier'] ) );
        $order->update_meta_data( '_rwsc_selected_courier', sanitize_text_field( $d['name'] ) );
        foreach ( array( 'eta', 'edd', 'zone', 'district', 'weight', 'normal_cost', 'free_applied', 'recommended' ) as $k ) {
            if ( isset( $d[ $k ] ) ) {
                $order->update_meta_data( '_rwsc_' . $k, sanitize_text_field( (string) $d[ $k ] ) );
            }
        }
        $order->update_meta_data( '_rwsc_source', sanitize_key( $source ) );
        if ( '' === (string) $order->get_meta( '_rwsc_status', true ) ) {
            $order->update_meta_data( '_rwsc_status', 'pending' );
            $order->update_meta_data( '_rwsc_status_at', time() );
        }
    }

    /**
     * Copy the courier chosen at checkout (stored on the shipping item) to the order.
     *
     * @param WC_Order $order Order.
     */
    public static function on_checkout_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        if ( self::sync_from_items( $order ) ) {
            $order->save();
        }
    }

    public static function sync_from_items( WC_Order $order ) {
        foreach ( $order->get_shipping_methods() as $item ) {
            $key = (string) $item->get_meta( '_rwsc_courier', true );
            if ( '' === $key ) {
                $key = (string) $item->get_meta( 'rwsc_courier_key', true );
            }
            if ( '' === $key ) {
                continue;
            }
            $g = static function ( $k ) use ( $item ) {
                $v = $item->get_meta( '_rwsc_' . $k, true );
                return '' !== (string) $v ? $v : $item->get_meta( 'rwsc_' . $k, true );
            };
            $name = (string) $item->get_meta( '_rwsc_selected_courier', true );
            self::apply_assignment(
                $order,
                array(
                    'courier'      => $key,
                    'name'         => '' !== $name ? $name : RWSC_Settings::courier_name( $key ),
                    'eta'          => $g( 'eta' ),
                    'edd'          => $g( 'edd' ),
                    'zone'         => $g( 'zone' ),
                    'district'     => $g( 'district' ),
                    'weight'       => $g( 'weight' ),
                    'normal_cost'  => $g( 'normal_cost' ),
                    'free_applied' => $g( 'free_applied' ),
                    'recommended'  => $g( 'recommended' ),
                ),
                'checkout'
            );
            if ( ! $order->get_meta( '_rwsc_log', true ) ) {
                /* translators: %s: courier name */
                self::log( $order, 'pending', sprintf( __( 'Customer chose %s at checkout', 'rar-woo-smart-courier' ), RWSC_Settings::courier_name( $key ) ) );
            }
            return true;
        }
        return false;
    }

    /**
     * Pick the recommended courier for an order that has none (admin / phone / staff-app orders).
     *
     * @param WC_Order $order  Order.
     * @param bool     $force  Re-assign even if a courier exists.
     * @param string   $prefer Courier key to prefer (manual choice) – recalculates ETA/date for it.
     * @return array|WP_Error Shipment data.
     */
    public static function auto_assign( WC_Order $order, $force = false, $prefer = '' ) {
        if ( ! $force && '' !== (string) $order->get_meta( '_rwsc_courier', true ) ) {
            return self::get( $order );
        }
        if ( ! self::needs_shipping( $order ) ) {
            return new WP_Error( 'rwsc_no_shipping', __( 'This order has no items that need shipping.', 'rar-woo-smart-courier' ) );
        }
        $quote = RWSC_Engine::quotes(
            array(
                'destination'         => self::order_destination( $order ),
                'weight'              => RWSC_Engine::order_weight( $order ),
                'include_unavailable' => '' !== $prefer,
            )
        );
        if ( '' === $quote['zone'] ) {
            return new WP_Error( 'rwsc_zone', __( 'Delivery address is outside Bangladesh or could not be resolved.', 'rar-woo-smart-courier' ) );
        }
        $pick = null;
        foreach ( $quote['quotes'] as $q ) {
            if ( '' !== $prefer ? $q['key'] === $prefer : $q['available'] ) {
                $pick = $q;
                break;
            }
        }
        if ( ! $pick && '' !== $prefer && RWSC_Settings::courier( $prefer ) ) {
            // Courier disabled or unavailable for this zone – still allow a manual assignment.
            $c    = RWSC_Settings::courier( $prefer );
            $eta  = trim( (string) $c[ 'eta_' . $quote['zone'] ] );
            $pick = array(
                'key'          => $prefer,
                'name'         => $c['name'],
                'eta'          => $eta,
                'edd'          => RWSC_Engine::edd( $eta ),
                'normal_cost'  => RWSC_Engine::cost( $c, $quote['zone'], $quote['weight'] ),
                'free_applied' => false,
                'recommended'  => false,
            );
        }
        if ( ! $pick ) {
            return new WP_Error( 'rwsc_none', __( 'No enabled courier is available for this address and weight.', 'rar-woo-smart-courier' ) );
        }
        self::apply_assignment(
            $order,
            array(
                'courier'      => $pick['key'],
                'name'         => $pick['name'],
                'eta'          => $pick['eta'],
                'edd'          => $pick['edd'] ? $pick['edd']['from'] . '|' . $pick['edd']['to'] : '',
                'zone'         => $quote['zone'],
                'district'     => $quote['district'],
                'weight'       => $quote['weight'],
                'normal_cost'  => $pick['normal_cost'],
                'free_applied' => ! empty( $pick['free_applied'] ) ? 'yes' : 'no',
                'recommended'  => ! empty( $pick['recommended'] ) ? 'yes' : 'no',
            ),
            '' !== $prefer ? 'manual' : 'auto'
        );
        return self::get( $order );
    }

    /**
     * Update courier / tracking / status / note for an order.
     *
     * @param WC_Order $order   Order.
     * @param array    $changes courier, tracking, status, note.
     * @param array    $args    notify (bool|null = setting), save (bool).
     * @return array|WP_Error
     */
    public static function update( WC_Order $order, array $changes, array $args = array() ) {
        $args    = wp_parse_args( $args, array( 'notify' => null, 'save' => true ) );
        $before  = self::get( $order );
        $changed = array();
        $notes   = array();

        if ( isset( $changes['courier'] ) && '' !== (string) $changes['courier'] ) {
            $key = sanitize_key( (string) $changes['courier'] );
            if ( 'auto' === $key ) {
                $r = self::auto_assign( $order, true );
            } elseif ( RWSC_Settings::courier( $key ) ) {
                $r = $key === $before['courier'] ? $before : self::auto_assign( $order, true, $key );
            } else {
                $r = new WP_Error( 'rwsc_courier', __( 'Unknown courier.', 'rar-woo-smart-courier' ) );
            }
            if ( is_wp_error( $r ) ) {
                return $r;
            }
            $new_key = (string) $order->get_meta( '_rwsc_courier', true );
            if ( $new_key !== $before['courier'] ) {
                $changed[] = 'courier';
                $name      = RWSC_Settings::courier_name( $new_key );
                /* translators: %s: courier name */
                $notes[] = sprintf( __( 'Courier set to %s', 'rar-woo-smart-courier' ), $name );
                self::log( $order, (string) $order->get_meta( '_rwsc_status', true ), end( $notes ) );
            }
        }

        if ( array_key_exists( 'tracking', $changes ) && null !== $changes['tracking'] ) {
            $tracking = preg_replace( '/\s+/', '', sanitize_text_field( (string) $changes['tracking'] ) );
            $tracking = substr( $tracking, 0, 64 );
            if ( $tracking !== $before['tracking'] ) {
                $order->update_meta_data( '_rwsc_tracking', $tracking );
                $changed[] = 'tracking';
                /* translators: %s: tracking number */
                $notes[] = '' !== $tracking ? sprintf( __( 'Tracking number %s', 'rar-woo-smart-courier' ), $tracking ) : __( 'Tracking number removed', 'rar-woo-smart-courier' );
                self::log( $order, (string) $order->get_meta( '_rwsc_status', true ), end( $notes ) );
                if ( '' === (string) $order->get_meta( '_rwsc_courier', true ) ) {
                    self::auto_assign( $order );
                }
                if ( '' !== $tracking && in_array( $before['status'], array( '', 'pending' ), true ) && empty( $changes['status'] ) ) {
                    $changes['status'] = 'booked';
                }
            }
        }

        if ( ! empty( $changes['status'] ) ) {
            $status  = sanitize_key( (string) $changes['status'] );
            $current = (string) $order->get_meta( '_rwsc_status', true );
            if ( ! self::is_status( $status ) ) {
                return new WP_Error( 'rwsc_status', __( 'Unknown shipment status.', 'rar-woo-smart-courier' ) );
            }
            if ( '' === (string) $order->get_meta( '_rwsc_courier', true ) ) {
                $r = self::auto_assign( $order );
                if ( is_wp_error( $r ) ) {
                    return $r;
                }
            }
            if ( $status !== $current ) {
                $now = time();
                $order->update_meta_data( '_rwsc_status', $status );
                $order->update_meta_data( '_rwsc_status_at', $now );
                if ( 'booked' === $status || ( in_array( $status, array( 'picked', 'in_transit' ), true ) && ! $order->get_meta( '_rwsc_booked_at', true ) ) ) {
                    $order->update_meta_data( '_rwsc_booked_at', $now );
                }
                if ( 'delivered' === $status ) {
                    $order->update_meta_data( '_rwsc_delivered_at', $now );
                    $order->delete_meta_data( '_rwsc_returned_at' );
                }
                if ( 'returned' === $status ) {
                    $order->update_meta_data( '_rwsc_returned_at', $now );
                    $order->delete_meta_data( '_rwsc_delivered_at' );
                }
                $changed[] = 'status';
                /* translators: %s: shipment status */
                $notes[] = sprintf( __( 'Shipment %s', 'rar-woo-smart-courier' ), self::status_label( $status ) );
                self::log( $order, $status, self::status_label( $status ) );
            }
        }

        if ( array_key_exists( 'note', $changes ) && null !== $changes['note'] ) {
            $note = sanitize_textarea_field( (string) $changes['note'] );
            if ( $note !== $before['note'] ) {
                $order->update_meta_data( '_rwsc_note', $note );
                $changed[] = 'note';
            }
        }

        if ( ! $changed ) {
            return $before;
        }

        if ( $notes ) {
            $order->add_order_note( 'Smart Courier: ' . implode( ' · ', $notes ) . ' (' . self::actor() . ')', 0, true );
        }

        if ( $args['save'] ) {
            $order->save();
        }
        $after = self::get( $order );

        // Tell the customer when a tracking number is first added / changed.
        $notify = null === $args['notify'] ? RWSC_Settings::on( 'notify_tracking' ) : (bool) $args['notify'];
        if ( $notify && in_array( 'tracking', $changed, true ) && '' !== $after['tracking'] ) {
            $order->add_order_note( self::customer_message( $order, $after ), 1, false );
        }

        // Keep the WooCommerce order status in step (optional).
        if ( in_array( 'status', $changed, true ) ) {
            if ( 'delivered' === $after['status'] && RWSC_Settings::on( 'auto_complete' ) && ! $order->has_status( array( 'completed', 'cancelled', 'refunded' ) ) ) {
                $order->update_status( 'completed', __( 'Smart Courier: parcel delivered.', 'rar-woo-smart-courier' ) );
            }
            $on_return = (string) RWSC_Settings::get( 'on_return', '' );
            if ( 'returned' === $after['status'] && '' !== $on_return && ! $order->has_status( $on_return ) ) {
                $order->update_status( $on_return, __( 'Smart Courier: parcel returned.', 'rar-woo-smart-courier' ) );
            }
            do_action( 'rwsc_shipment_status_changed', $order, $after['status'], $before['status'], $after );
        }

        do_action( 'rwsc_shipment_updated', $order, $after, $before, $changed );
        return $after;
    }

    public static function customer_message( WC_Order $order, array $s ) {
        $shop = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        /* translators: 1: courier name, 2: tracking number */
        $msg = sprintf( __( 'Your parcel has been handed to %1$s. Tracking number: %2$s.', 'rar-woo-smart-courier' ), $s['courier_name'], $s['tracking'] );
        if ( '' !== $s['tracking_url'] ) {
            /* translators: %s: URL */
            $msg .= ' ' . sprintf( __( 'Track it here: %s', 'rar-woo-smart-courier' ), $s['tracking_url'] );
        }
        if ( '' !== $s['edd_label'] ) {
            /* translators: %s: date range */
            $msg .= ' ' . sprintf( __( 'Estimated delivery: %s.', 'rar-woo-smart-courier' ), $s['edd_label'] );
        }
        return (string) apply_filters( 'rwsc_customer_tracking_message', $msg . ' — ' . $shop, $order, $s );
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                               */
    /* ------------------------------------------------------------------ */

    public static function on_status_changed( $order_id, $from, $to, $order = null ) {
        if ( ! RWSC_Settings::on( 'auto_assign' ) ) {
            return;
        }
        if ( ! in_array( $to, (array) RWSC_Settings::get( 'ready_statuses', array( 'processing' ) ), true ) ) {
            return;
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order || '' !== (string) $order->get_meta( '_rwsc_courier', true ) ) {
            return;
        }
        if ( self::sync_from_items( $order ) ) {
            $order->save();
            return;
        }
        $r = self::auto_assign( $order );
        if ( ! is_wp_error( $r ) ) {
            /* translators: %s: courier name */
            self::log( $order, 'pending', sprintf( __( 'Auto-assigned %s (recommended)', 'rar-woo-smart-courier' ), $r['courier_name'] ) );
            $order->save();
        }
    }

    public static function hidden_item_meta( $keys ) {
        return array_merge( (array) $keys, self::ITEM_KEYS );
    }

    /**
     * Give v1.x orders a courier key and shipment status (100 orders per admin request).
     */
    public static function maybe_backfill() {
        if ( get_option( 'rwsc_backfill_done' ) || ! current_user_can( 'edit_shop_orders' ) || wp_doing_ajax() ) {
            return;
        }
        if ( get_transient( 'rwsc_backfill_lock' ) ) {
            return;
        }
        set_transient( 'rwsc_backfill_lock', 1, 60 );
        $ids = RWSC_Reports::legacy_order_ids( 100 );
        if ( count( $ids ) < 100 ) {
            update_option( 'rwsc_backfill_done', time(), false );
        }
        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order instanceof WC_Order || '' !== (string) $order->get_meta( '_rwsc_courier', true ) ) {
                continue;
            }
            $name = (string) $order->get_meta( '_rwsc_selected_courier', true );
            $key  = RWSC_Settings::courier_key_by_name( $name );
            if ( '' === $key ) {
                $key = sanitize_key( $name );
            }
            if ( ! self::sync_from_items( $order ) ) {
                $order->update_meta_data( '_rwsc_courier', $key );
            }
            if ( '' === (string) $order->get_meta( '_rwsc_courier', true ) ) {
                $order->update_meta_data( '_rwsc_courier', $key );
            }
            if ( $order->has_status( 'completed' ) ) {
                $order->update_meta_data( '_rwsc_status', 'delivered' );
                $done = $order->get_date_completed();
                $order->update_meta_data( '_rwsc_delivered_at', $done ? $done->getTimestamp() : time() );
            } elseif ( '' === (string) $order->get_meta( '_rwsc_status', true ) ) {
                $order->update_meta_data( '_rwsc_status', 'pending' );
            }
            $order->update_meta_data( '_rwsc_source', 'legacy' );
            $order->save();
        }
        delete_transient( 'rwsc_backfill_lock' );
    }
}
