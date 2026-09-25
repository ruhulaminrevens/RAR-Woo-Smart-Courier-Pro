<?php
/**
 * Fast SQL reads for the dashboard and the shipments board (HPOS and legacy storage).
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Reports {

    const EXCLUDED = array( 'trash', 'auto-draft', 'checkout-draft', 'wc-checkout-draft' );

    /** Order meta keys pivoted into each row => row column. */
    const META = array(
        '_rwsc_courier'      => 'courier',
        '_rwsc_zone'         => 'zone',
        '_rwsc_district'     => 'district',
        '_rwsc_status'       => 'ship_status',
        '_rwsc_tracking'     => 'tracking',
        '_rwsc_normal_cost'  => 'normal_cost',
        '_rwsc_free_applied' => 'free_applied',
        '_rwsc_weight'       => 'weight',
        '_rwsc_booked_at'    => 'booked_at',
        '_rwsc_delivered_at' => 'delivered_at',
        '_rwsc_returned_at'  => 'returned_at',
        '_rwsc_status_at'    => 'status_at',
    );

    public static function hpos() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private static function gmt( $ts ) {
        return gmdate( 'Y-m-d H:i:s', (int) $ts );
    }

    /**
     * Pivoted order rows created in [from, to] (timestamps). Pass from = 0 for "all" (latest $cap rows).
     */
    public static function rows( $from, $to, $cap = 5000 ) {
        global $wpdb;
        $keys   = array_keys( self::META );
        $in     = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
        $cases  = array();
        foreach ( self::META as $k => $col ) {
            $cases[] = "MAX(CASE WHEN m.meta_key = '" . esc_sql( $k ) . "' THEN m.meta_value END) AS {$col}";
        }
        $excluded = "'" . implode( "','", array_map( 'esc_sql', self::EXCLUDED ) ) . "'";
        $cap      = max( 1, (int) $cap );

        if ( self::hpos() ) {
            $orders = $wpdb->prefix . 'wc_orders';
            $meta   = $wpdb->prefix . 'wc_orders_meta';
            $addr   = $wpdb->prefix . 'wc_order_addresses';
            $ops    = $wpdb->prefix . 'wc_order_operational_data';
            $where  = "o.type = 'shop_order' AND o.status NOT IN ({$excluded})";
            if ( $from ) {
                $where .= $wpdb->prepare( ' AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s', self::gmt( $from ), self::gmt( $to ) );
            }
            $sql = "SELECT o.id AS id, MAX(o.status) AS status, MAX(o.total_amount) AS total, MAX(o.date_created_gmt) AS created,
                        MAX(o.payment_method) AS payment, MAX(op.shipping_total_amount) AS shipping,
                        MAX(b.first_name) AS first_name, MAX(b.last_name) AS last_name, MAX(b.phone) AS phone,
                        MAX(b.state) AS b_state, MAX(b.city) AS b_city, MAX(s.state) AS s_state, MAX(s.city) AS s_city, MAX(s.phone) AS s_phone,
                        " . implode( ",\n", $cases ) . "
                    FROM {$orders} o
                    LEFT JOIN {$meta} m ON m.order_id = o.id AND m.meta_key IN ({$in})
                    LEFT JOIN {$ops} op ON op.order_id = o.id
                    LEFT JOIN {$addr} b ON b.order_id = o.id AND b.address_type = 'billing'
                    LEFT JOIN {$addr} s ON s.order_id = o.id AND s.address_type = 'shipping'
                    WHERE {$where}
                    GROUP BY o.id
                    ORDER BY o.id DESC
                    LIMIT {$cap}";
        } else {
            $legacy = array(
                '_order_total'        => 'total',
                '_order_shipping'     => 'shipping',
                '_payment_method'     => 'payment',
                '_billing_first_name' => 'first_name',
                '_billing_last_name'  => 'last_name',
                '_billing_phone'      => 'phone',
                '_billing_state'      => 'b_state',
                '_billing_city'       => 'b_city',
                '_shipping_state'     => 's_state',
                '_shipping_city'      => 's_city',
                '_shipping_phone'     => 's_phone',
            );
            foreach ( $legacy as $k => $col ) {
                $cases[] = "MAX(CASE WHEN m.meta_key = '" . esc_sql( $k ) . "' THEN m.meta_value END) AS {$col}";
            }
            $in    = "'" . implode( "','", array_map( 'esc_sql', array_merge( $keys, array_keys( $legacy ) ) ) ) . "'";
            $where = "p.post_type = 'shop_order' AND p.post_status NOT IN ({$excluded})";
            if ( $from ) {
                $where .= $wpdb->prepare( ' AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s', self::gmt( $from ), self::gmt( $to ) );
            }
            $sql = "SELECT p.ID AS id, MAX(p.post_status) AS status, MAX(p.post_date_gmt) AS created,
                        " . implode( ",\n", $cases ) . "
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ({$in})
                    WHERE {$where}
                    GROUP BY p.ID
                    ORDER BY p.ID DESC
                    LIMIT {$cap}";
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
        $out  = array();
        foreach ( (array) $rows as $r ) {
            $status = preg_replace( '/^wc-/', '', (string) $r['status'] );
            $state  = '' !== (string) $r['s_state'] ? $r['s_state'] : $r['b_state'];
            $city   = '' !== (string) $r['s_city'] ? $r['s_city'] : $r['b_city'];
            $courier = (string) $r['courier'];
            $out[]  = array(
                'id'           => (int) $r['id'],
                'status'       => $status,
                'total'        => (float) $r['total'],
                'shipping'     => (float) $r['shipping'],
                'payment'      => (string) $r['payment'],
                'created'      => strtotime( $r['created'] . ' UTC' ),
                'name'         => trim( $r['first_name'] . ' ' . $r['last_name'] ),
                'phone'        => (string) ( '' !== (string) $r['s_phone'] ? $r['s_phone'] : $r['phone'] ),
                'district'     => '' !== (string) $r['district'] ? (string) $r['district'] : RWSC_Locations::resolve_district( $state ),
                'city'         => (string) $city,
                'courier'      => $courier,
                'zone'         => (string) $r['zone'],
                'ship_status'  => '' !== (string) $r['ship_status'] ? (string) $r['ship_status'] : ( '' !== $courier ? 'pending' : '' ),
                'tracking'     => (string) $r['tracking'],
                'normal_cost'  => (float) $r['normal_cost'],
                'free_applied' => 'yes' === $r['free_applied'],
                'weight'       => (float) $r['weight'],
                'booked_at'    => (int) $r['booked_at'],
                'delivered_at' => (int) $r['delivered_at'],
                'returned_at'  => (int) $r['returned_at'],
                'status_at'    => (int) $r['status_at'],
            );
        }
        return $out;
    }

    /**
     * Orders saved by v1.x: have _rwsc_selected_courier but no _rwsc_courier (works on HPOS and legacy storage).
     *
     * @return int[]
     */
    public static function legacy_order_ids( $limit = 100 ) {
        global $wpdb;
        $limit = max( 1, (int) $limit );
        if ( self::hpos() ) {
            $meta = $wpdb->prefix . 'wc_orders_meta';
            $sql  = "SELECT DISTINCT m.order_id FROM {$meta} m
                     WHERE m.meta_key = '_rwsc_selected_courier'
                     AND NOT EXISTS ( SELECT 1 FROM {$meta} m2 WHERE m2.order_id = m.order_id AND m2.meta_key = '_rwsc_courier' )
                     ORDER BY m.order_id DESC LIMIT {$limit}";
        } else {
            $sql = "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
                    INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'shop_order'
                    WHERE m.meta_key = '_rwsc_selected_courier'
                    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id = m.post_id AND m2.meta_key = '_rwsc_courier' )
                    ORDER BY m.post_id DESC LIMIT {$limit}";
        }
        return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /**
     * Order ids with an exact meta value (HPOS and legacy storage).
     *
     * @return int[]
     */
    public static function order_ids_by_meta( $key, $value, $limit = 5 ) {
        global $wpdb;
        $limit = max( 1, (int) $limit );
        if ( self::hpos() ) {
            $sql = $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s AND meta_value = %s LIMIT %d", $key, $value, $limit );
        } else {
            $sql = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT %d", $key, $value, $limit );
        }
        return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard                                                           */
    /* ------------------------------------------------------------------ */

    public static function dashboard( $days ) {
        $days  = in_array( (int) $days, array( 1, 7, 30, 90 ), true ) ? (int) $days : 30;
        $cache = get_transient( 'rwsc_dash_' . $days );
        $ver   = (string) get_option( 'rwsc_report_ver', '0' );
        if ( is_array( $cache ) && isset( $cache['ver'] ) && $cache['ver'] === $ver && time() - $cache['at'] < 300 ) {
            return $cache['data'];
        }

        $tz    = wp_timezone();
        $today = new DateTimeImmutable( 'today', $tz );
        $from  = $today->modify( '-' . ( $days - 1 ) . ' days' );
        $now   = time();
        $prevf = $from->modify( '-' . $days . ' days' );

        $rows  = self::rows( $prevf->getTimestamp(), $now, 20000 );
        $cur   = array();
        $prev  = array();
        foreach ( $rows as $r ) {
            if ( $r['created'] >= $from->getTimestamp() ) {
                $cur[] = $r;
            } else {
                $prev[] = $r;
            }
        }

        $void     = array( 'cancelled', 'failed', 'refunded' );
        $couriers = RWSC_Settings::couriers();
        $stale    = max( 1, (int) RWSC_Settings::get( 'stale_days', 2 ) ) * DAY_IN_SECONDS;
        $ready    = (array) RWSC_Settings::get( 'ready_statuses', array( 'processing' ) );

        $k = array(
            'orders' => 0, 'shipping' => 0.0, 'pending' => 0, 'active' => 0, 'delivered' => 0, 'returned' => 0,
            'subsidy' => 0.0, 'free_orders' => 0, 'avg_days' => null, 'success' => null, 'unassigned' => 0, 'stale' => 0,
            'cod' => 0.0, 'cod_field' => 0.0,
        );
        $by_courier = array();
        $by_zone    = array( 'dhaka' => 0, 'nearby' => 0, 'outside' => 0 );
        $by_status  = array_fill_keys( array_keys( RWSC_Shipments::statuses() ), 0 );
        $districts  = array();
        $daily      = array();
        for ( $i = 0; $i < $days; $i++ ) {
            $daily[ $from->modify( "+{$i} days" )->format( 'Y-m-d' ) ] = array();
        }
        $durations = array();

        foreach ( $cur as $r ) {
            if ( '' === $r['courier'] ) {
                if ( in_array( $r['status'], $ready, true ) ) {
                    $k['unassigned']++;
                }
                continue;
            }
            if ( in_array( $r['status'], $void, true ) && 'returned' !== $r['ship_status'] ) {
                continue;
            }
            $c = $r['courier'];
            $k['orders']++;
            $k['shipping'] += $r['shipping'];
            if ( $r['free_applied'] ) {
                $k['free_orders']++;
                $k['subsidy'] += $r['normal_cost'];
            }
            if ( in_array( $r['payment'], (array) RWSC_Settings::get( 'cod_methods', array( 'cod' ) ), true ) ) {
                $k['cod'] += $r['total'];
            }
            $st = $r['ship_status'];
            if ( isset( $by_status[ $st ] ) ) {
                $by_status[ $st ]++;
            }
            if ( 'pending' === $st ) {
                $k['pending']++;
                if ( $now - $r['created'] > $stale && in_array( $r['status'], $ready, true ) ) {
                    $k['stale']++;
                }
            } elseif ( in_array( $st, RWSC_Shipments::active_statuses(), true ) ) {
                $k['active']++;
                if ( in_array( $r['payment'], (array) RWSC_Settings::get( 'cod_methods', array( 'cod' ) ), true ) ) {
                    $k['cod_field'] += $r['total'];
                }
            } elseif ( 'delivered' === $st ) {
                $k['delivered']++;
            } elseif ( 'returned' === $st ) {
                $k['returned']++;
            }
            if ( isset( $by_zone[ $r['zone'] ] ) ) {
                $by_zone[ $r['zone'] ]++;
            }
            if ( '' !== $r['district'] ) {
                $districts[ $r['district'] ] = isset( $districts[ $r['district'] ] ) ? $districts[ $r['district'] ] + 1 : 1;
            }
            if ( ! isset( $by_courier[ $c ] ) ) {
                $by_courier[ $c ] = array( 'key' => $c, 'orders' => 0, 'shipping' => 0.0, 'delivered' => 0, 'returned' => 0, 'active' => 0, 'pending' => 0, 'days' => array() );
            }
            $b = &$by_courier[ $c ];
            $b['orders']++;
            $b['shipping'] += $r['shipping'];
            if ( 'delivered' === $st ) {
                $b['delivered']++;
            } elseif ( 'returned' === $st ) {
                $b['returned']++;
            } elseif ( 'pending' === $st ) {
                $b['pending']++;
            } else {
                $b['active']++;
            }
            if ( 'delivered' === $st && $r['delivered_at'] ) {
                $start = $r['booked_at'] ? $r['booked_at'] : $r['created'];
                if ( $r['delivered_at'] > $start ) {
                    $d             = ( $r['delivered_at'] - $start ) / DAY_IN_SECONDS;
                    $b['days'][]   = $d;
                    $durations[]   = $d;
                }
            }
            unset( $b );
            $day = wp_date( 'Y-m-d', $r['created'] );
            if ( isset( $daily[ $day ] ) ) {
                $daily[ $day ][ $c ] = isset( $daily[ $day ][ $c ] ) ? $daily[ $day ][ $c ] + 1 : 1;
            }
        }

        $finished = $k['delivered'] + $k['returned'];
        $k['success']  = $finished ? round( $k['delivered'] * 100 / $finished, 1 ) : null;
        $k['avg_days'] = $durations ? round( array_sum( $durations ) / count( $durations ), 1 ) : null;

        $prev_orders   = 0;
        $prev_shipping = 0.0;
        foreach ( $prev as $r ) {
            if ( '' === $r['courier'] || ( in_array( $r['status'], $void, true ) && 'returned' !== $r['ship_status'] ) ) {
                continue;
            }
            $prev_orders++;
            $prev_shipping += $r['shipping'];
        }

        $leader = array();
        foreach ( $by_courier as $c => $b ) {
            $fin      = $b['delivered'] + $b['returned'];
            $leader[] = array(
                'key'       => $c,
                'name'      => RWSC_Settings::courier_name( $c ),
                'color'     => RWSC_Settings::courier_color( $c ),
                'orders'    => $b['orders'],
                'share'     => $k['orders'] ? round( $b['orders'] * 100 / $k['orders'], 1 ) : 0,
                'shipping'  => round( $b['shipping'], 2 ),
                'delivered' => $b['delivered'],
                'returned'  => $b['returned'],
                'active'    => $b['active'],
                'pending'   => $b['pending'],
                'success'   => $fin ? round( $b['delivered'] * 100 / $fin, 1 ) : null,
                'avg_days'  => $b['days'] ? round( array_sum( $b['days'] ) / count( $b['days'] ), 1 ) : null,
            );
        }
        usort(
            $leader,
            static function ( $a, $b ) {
                return $b['orders'] <=> $a['orders'];
            }
        );

        arsort( $districts );
        $top = array();
        foreach ( array_slice( $districts, 0, 8, true ) as $code => $n ) {
            $top[] = array( 'code' => $code, 'name' => RWSC_Locations::name( $code ), 'orders' => $n );
        }

        $series = array();
        foreach ( $daily as $date => $per ) {
            $series[] = array( 'date' => $date, 'by' => $per, 'total' => array_sum( $per ) );
        }

        $data = array(
            'days'        => $days,
            'from'        => $from->format( 'Y-m-d' ),
            'kpi'         => $k,
            'prev'        => array( 'orders' => $prev_orders, 'shipping' => round( $prev_shipping, 2 ) ),
            'couriers'    => $leader,
            'zones'       => $by_zone,
            'statuses'    => $by_status,
            'districts'   => $top,
            'daily'       => $series,
            'courier_meta' => array_map(
                static function ( $c ) {
                    return array( 'name' => $c['name'], 'color' => RWSC_Settings::color( $c['color'] ) );
                },
                $couriers
            ),
            'generated'   => time(),
        );

        set_transient( 'rwsc_dash_' . $days, array( 'ver' => $ver, 'at' => time(), 'data' => $data ), 600 );
        return $data;
    }

    public static function bust() {
        update_option( 'rwsc_report_ver', (string) microtime( true ), false );
    }

    /* ------------------------------------------------------------------ */
    /* Shipments board                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param array $args tab, courier, zone, q, days, page, per_page.
     */
    public static function shipments( array $args ) {
        $args = wp_parse_args(
            $args,
            array(
                'tab'      => 'ready',
                'courier'  => '',
                'zone'     => '',
                'q'        => '',
                'days'     => 30,
                'page'     => 1,
                'per_page' => 25,
            )
        );
        $days = (int) $args['days'];
        $now  = time();
        $from = $days > 0 ? ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' )->getTimestamp() : 0;
        $rows = self::rows( $from, $now, 5000 );

        $ready  = (array) RWSC_Settings::get( 'ready_statuses', array( 'processing' ) );
        $void   = array( 'cancelled', 'failed', 'refunded' );
        $tabs   = array( 'ready' => 0, 'active' => 0, 'booked' => 0, 'picked' => 0, 'in_transit' => 0, 'hold' => 0, 'delivered' => 0, 'returned' => 0, 'unassigned' => 0, 'all' => 0 );
        $stale  = max( 1, (int) RWSC_Settings::get( 'stale_days', 2 ) ) * DAY_IN_SECONDS;
        $q      = trim( (string) $args['q'] );
        $qn     = strtolower( $q );
        $qphone = RWSC_Shipments::phone_local( $q );

        $match = array();
        foreach ( $rows as $r ) {
            // Filters shared by every tab.
            if ( '' !== $args['courier'] && $r['courier'] !== $args['courier'] ) {
                continue;
            }
            if ( '' !== $args['zone'] && $r['zone'] !== $args['zone'] ) {
                continue;
            }
            if ( '' !== $q ) {
                $hay = strtolower( $r['id'] . ' ' . $r['name'] . ' ' . $r['tracking'] . ' ' . $r['city'] . ' ' . RWSC_Locations::name( $r['district'] ) );
                $hit = false !== strpos( $hay, $qn ) || ( strlen( $qphone ) >= 5 && false !== strpos( RWSC_Shipments::phone_local( $r['phone'] ), $qphone ) );
                if ( ! $hit ) {
                    continue;
                }
            }
            $in_tab = array();
            $st     = $r['ship_status'];
            if ( '' === $r['courier'] ) {
                if ( in_array( $r['status'], $ready, true ) ) {
                    $in_tab[] = 'unassigned';
                    $in_tab[] = 'ready';
                }
            } else {
                if ( 'pending' === $st && in_array( $r['status'], $ready, true ) ) {
                    $in_tab[] = 'ready';
                }
                if ( isset( $tabs[ $st ] ) && 'pending' !== $st ) {
                    $in_tab[] = $st;
                }
                if ( in_array( $st, RWSC_Shipments::active_statuses(), true ) ) {
                    $in_tab[] = 'active';
                }
            }
            if ( '' !== $r['courier'] || in_array( $r['status'], $ready, true ) ) {
                if ( ! in_array( $r['status'], $void, true ) || '' !== $st ) {
                    $in_tab[] = 'all';
                }
            }
            foreach ( array_unique( $in_tab ) as $t ) {
                $tabs[ $t ]++;
            }
            if ( in_array( $args['tab'], $in_tab, true ) ) {
                $r['stale'] = 'pending' === $st && $now - $r['created'] > $stale;
                $match[]    = $r;
            }
        }

        $per   = max( 5, min( 100, (int) $args['per_page'] ) );
        $total = count( $match );
        $pages = max( 1, (int) ceil( $total / $per ) );
        $page  = max( 1, min( $pages, (int) $args['page'] ) );
        $slice = array_slice( $match, ( $page - 1 ) * $per, $per );

        $items = array();
        foreach ( $slice as $r ) {
            $order = wc_get_order( $r['id'] );
            if ( $order ) {
                $items[] = self::row_payload( $order, ! empty( $r['stale'] ) );
            }
        }

        return array(
            'rows'  => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'tabs'  => $tabs,
        );
    }

    /**
     * Everything the shipments board needs for one order.
     */
    public static function row_payload( WC_Order $order, $stale = false ) {
        $s     = RWSC_Shipments::get( $order );
        $dest  = RWSC_Shipments::order_destination( $order );
        $items = array();
        $count = 0;
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' × ' . $item->get_quantity();
            $count  += (int) $item->get_quantity();
        }
        $created = $order->get_date_created();
        $ship_first = $order->get_shipping_first_name();
        $name    = trim( ( '' !== (string) $ship_first ? $ship_first . ' ' . $order->get_shipping_last_name() : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
        $district = '' !== $s['district'] ? $s['district'] : RWSC_Locations::resolve_district( $dest['state'] );

        return array(
            'id'          => $order->get_id(),
            'number'      => $order->get_order_number(),
            'edit_url'    => $order->get_edit_order_url(),
            'status'      => $order->get_status(),
            'status_name' => wc_get_order_status_name( $order->get_status() ),
            'created'     => $created ? $created->getTimestamp() : 0,
            'name'        => $name,
            'phone'       => RWSC_Shipments::order_phone( $order ),
            'address'     => trim( $dest['address_1'] . ( '' !== $dest['address_2'] ? ', ' . $dest['address_2'] : '' ) ),
            'city'        => $dest['city'],
            'district'    => $district,
            'district_name' => RWSC_Locations::name( $district ),
            'total'       => (float) $order->get_total(),
            'shipping'    => (float) $order->get_shipping_total(),
            'payment'     => $order->get_payment_method_title(),
            'cod'         => $s['cod'],
            'items'       => $items,
            'item_count'  => $count,
            'customer_note' => (string) $order->get_customer_note(),
            'stale'       => (bool) $stale,
            'ship'        => $s,
        );
    }
}
