<?php
/**
 * Rate engine: weight, cost, ETA, delivery-date estimates, quotes and WooCommerce rate filtering.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Engine {

    const RATE_PREFIX = 'rwsc_';

    public static function init() {
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_rates' ), 100, 2 );
        add_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'full_label' ), 100, 2 );
        add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'package_hash' ), 100 );
        add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'shipping_item' ), 10, 4 );
    }

    /**
     * Should the courier engine run for the current visitor?
     */
    public static function active_for_current_user() {
        if ( ! RWSC_Settings::on( 'enabled' ) ) {
            return false;
        }
        if ( RWSC_Settings::on( 'test_mode' ) ) {
            return current_user_can( 'manage_woocommerce' );
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Weight                                                              */
    /* ------------------------------------------------------------------ */

    public static function to_kg( $weight ) {
        $weight = (float) $weight;
        if ( $weight <= 0 ) {
            return 0.0;
        }
        return function_exists( 'wc_get_weight' ) ? (float) wc_get_weight( $weight, 'kg' ) : $weight;
    }

    /**
     * Total parcel weight in kg.
     *
     * @param array $lines List of [ WC_Product|null, qty ].
     */
    public static function lines_weight( array $lines ) {
        $fallback = max( 0.01, (float) RWSC_Settings::get( 'fallback_weight', 1 ) );
        $total    = 0.0;
        foreach ( $lines as $line ) {
            $product = isset( $line[0] ) ? $line[0] : null;
            $qty     = isset( $line[1] ) ? max( 1, (float) $line[1] ) : 1;
            if ( $product instanceof WC_Product && ! $product->needs_shipping() ) {
                continue;
            }
            $w = $product instanceof WC_Product ? self::to_kg( $product->get_weight() ) : 0.0;
            if ( $w <= 0 ) {
                $w = $fallback;
            }
            $total += $w * $qty;
        }
        if ( $total <= 0 ) {
            $total = $fallback;
        }
        $total += max( 0, (float) RWSC_Settings::get( 'packaging_weight', 0 ) );
        return round( max( 0.01, $total ), 3 );
    }

    public static function package_weight( array $package ) {
        $lines = array();
        if ( ! empty( $package['contents'] ) && is_array( $package['contents'] ) ) {
            foreach ( $package['contents'] as $item ) {
                $lines[] = array( isset( $item['data'] ) ? $item['data'] : null, isset( $item['quantity'] ) ? $item['quantity'] : 1 );
            }
        }
        return (float) apply_filters( 'rwsc_package_weight', self::lines_weight( $lines ), $package );
    }

    public static function order_weight( WC_Order $order ) {
        $lines = array();
        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof WC_Order_Item_Product ) {
                $lines[] = array( $item->get_product(), $item->get_quantity() );
            }
        }
        return (float) apply_filters( 'rwsc_order_weight', self::lines_weight( $lines ), $order );
    }

    /* ------------------------------------------------------------------ */
    /* Cost & ETA                                                          */
    /* ------------------------------------------------------------------ */

    public static function cost( array $c, $zone, $weight ) {
        $one   = isset( $c[ $zone . '_1' ] ) ? (float) $c[ $zone . '_1' ] : 0;
        $two   = isset( $c[ $zone . '_2' ] ) ? (float) $c[ $zone . '_2' ] : 0;
        $extra = isset( $c[ $zone . '_extra' ] ) ? (float) $c[ $zone . '_extra' ] : 0;
        if ( $two <= 0 ) {
            $two = $one;
        }
        $weight = (float) $weight;
        if ( $weight <= 1 ) {
            $cost = $one;
        } elseif ( $weight <= 2 ) {
            $cost = $two;
        } else {
            $cost = $two + ceil( round( $weight - 2, 3 ) ) * $extra;
        }
        return (float) apply_filters( 'rwsc_courier_cost', max( 0, $cost ), $c, $zone, $weight );
    }

    /**
     * Parse an ETA text ("1–2 business days", "২৪ ঘণ্টা", "Same day") into [min, max] business days.
     *
     * @return int[]|null
     */
    public static function eta_range( $eta ) {
        $eta = trim( (string) $eta );
        if ( '' === $eta ) {
            return null;
        }
        $eta = strtr( $eta, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
        $lc  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $eta, 'UTF-8' ) : strtolower( $eta );
        if ( preg_match( '/same\s*-?\s*day|আজই|একই\s*দিন/u', $lc ) ) {
            return array( 0, 0 );
        }
        if ( ! preg_match_all( '/\d+(?:\.\d+)?/', $lc, $m ) ) {
            return preg_match( '/next\s*-?\s*day|পরের\s*দিন/u', $lc ) ? array( 1, 1 ) : null;
        }
        $nums = array_map( 'floatval', $m[0] );
        $min  = min( $nums );
        $max  = max( $nums );
        if ( preg_match( '/hour|hrs?\b|ঘণ্টা|ঘন্টা/u', $lc ) ) {
            $min = floor( $min / 24 );
            $max = ceil( $max / 24 );
        } elseif ( preg_match( '/week|সপ্তাহ/u', $lc ) ) {
            $min = $min * 5;
            $max = $max * 5;
        }
        return array( (int) $min, (int) max( $min, $max ) );
    }

    public static function eta_score( $eta ) {
        $r = self::eta_range( $eta );
        return $r ? $r[0] * 100 + $r[1] : 99999;
    }

    /* ------------------------------------------------------------------ */
    /* Delivery-date estimate                                              */
    /* ------------------------------------------------------------------ */

    public static function now() {
        return current_datetime();
    }

    public static function is_workday( DateTimeImmutable $d ) {
        $dow = strtolower( substr( $d->format( 'D' ), 0, 3 ) );
        if ( in_array( $dow, (array) RWSC_Settings::get( 'weekend', array() ), true ) ) {
            return false;
        }
        return ! in_array( $d->format( 'Y-m-d' ), RWSC_Settings::holiday_dates(), true );
    }

    /**
     * First day the parcel can be handed over (respects cut-off time, weekend and holidays).
     */
    public static function dispatch_day( ?DateTimeImmutable $now = null ) {
        $now   = $now ? $now : self::now();
        $parts = explode( ':', (string) RWSC_Settings::get( 'cutoff', '17:00' ) );
        $cut   = ( (int) $parts[0] ) * 60 + ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
        $day   = $now->setTime( 0, 0 );
        if ( (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' ) >= $cut ) {
            $day = $day->modify( '+1 day' );
        }
        $guard = 0;
        while ( ! self::is_workday( $day ) && $guard++ < 60 ) {
            $day = $day->modify( '+1 day' );
        }
        return $day;
    }

    public static function add_workdays( DateTimeImmutable $day, $n ) {
        $n     = (int) $n;
        $guard = 0;
        while ( $n > 0 && $guard++ < 180 ) {
            $day = $day->modify( '+1 day' );
            if ( self::is_workday( $day ) ) {
                $n--;
            }
        }
        return $day;
    }

    /**
     * Estimated delivery window.
     *
     * @return array|null { from: Y-m-d, to: Y-m-d, label: string }
     */
    public static function edd( $eta, ?DateTimeImmutable $now = null ) {
        $range = self::eta_range( $eta );
        if ( ! $range ) {
            return null;
        }
        $start = self::dispatch_day( $now );
        $from  = self::add_workdays( $start, $range[0] );
        $to    = self::add_workdays( $start, $range[1] );
        return array(
            'from'  => $from->format( 'Y-m-d' ),
            'to'    => $to->format( 'Y-m-d' ),
            'label' => self::range_label( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ), $now ),
        );
    }

    /**
     * Human label for a Y-m-d range, e.g. "Sat 27 – Sun 28 Sep" or "Tomorrow, Sat 27 Sep".
     */
    public static function range_label( $from, $to, ?DateTimeImmutable $now = null ) {
        if ( ! $from ) {
            return '';
        }
        $to  = $to ? $to : $from;
        $tz  = wp_timezone();
        $f   = new DateTimeImmutable( $from . ' 12:00:00', $tz );
        $t   = new DateTimeImmutable( $to . ' 12:00:00', $tz );
        $now = $now ? $now : self::now();
        if ( $from === $to ) {
            $today    = $now->format( 'Y-m-d' );
            $tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
            $d        = wp_date( 'D j M', $f->getTimestamp() );
            if ( $from === $today ) {
                /* translators: %s: date */
                return sprintf( __( 'Today, %s', 'rar-woo-smart-courier' ), $d );
            }
            if ( $from === $tomorrow ) {
                /* translators: %s: date */
                return sprintf( __( 'Tomorrow, %s', 'rar-woo-smart-courier' ), $d );
            }
            return $d;
        }
        if ( $f->format( 'Y-m' ) === $t->format( 'Y-m' ) ) {
            return wp_date( 'D j', $f->getTimestamp() ) . ' – ' . wp_date( 'D j M', $t->getTimestamp() );
        }
        return wp_date( 'D j M', $f->getTimestamp() ) . ' – ' . wp_date( 'D j M', $t->getTimestamp() );
    }

    /* ------------------------------------------------------------------ */
    /* Quotes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Calculate courier quotes for a destination.
     *
     * @param array $args destination (array), weight (kg) | lines ([product, qty]), free (bool), include_unavailable (bool), now (DateTimeImmutable).
     */
    public static function quotes( array $args ) {
        $args = wp_parse_args(
            $args,
            array(
                'destination'         => array(),
                'weight'              => null,
                'lines'               => null,
                'free'                => false,
                'include_unavailable' => false,
                'now'                 => null,
            )
        );

        $zone_info = RWSC_Locations::zone( (array) $args['destination'] );
        if ( null !== $args['weight'] && '' !== $args['weight'] ) {
            $weight = round( max( 0.01, (float) $args['weight'] ), 3 );
        } elseif ( is_array( $args['lines'] ) ) {
            $weight = self::lines_weight( $args['lines'] );
        } else {
            $weight = self::lines_weight( array() );
        }

        $out = array(
            'zone'          => $zone_info['zone'],
            'zone_label'    => RWSC_Locations::zone_label( $zone_info['zone'] ),
            'district'      => $zone_info['district'],
            'district_name' => RWSC_Locations::name( $zone_info['district'] ),
            'area'          => $zone_info['area'],
            'reason'        => $zone_info['reason'],
            'weight'        => $weight,
            'free'          => (bool) $args['free'],
            'quotes'        => array(),
        );
        if ( '' === $zone_info['zone'] ) {
            return $out;
        }
        $zone = $zone_info['zone'];
        $now  = $args['now'] instanceof DateTimeImmutable ? $args['now'] : null;

        $available   = array();
        $unavailable = array();
        foreach ( RWSC_Settings::couriers( true ) as $key => $c ) {
            $eta    = trim( (string) $c[ 'eta_' . $zone ] );
            $range  = self::eta_range( $eta );
            $normal = self::cost( $c, $zone, $weight );
            $q      = array(
                'key'          => $key,
                'name'         => $c['name'],
                'color'        => RWSC_Settings::color( $c['color'] ),
                'priority'     => (int) $c['priority'],
                'normal_cost'  => $normal,
                'cost'         => $normal,
                'eta'          => $eta,
                'eta_min'      => $range ? $range[0] : null,
                'eta_max'      => $range ? $range[1] : null,
                'eta_score'    => self::eta_score( $eta ),
                'edd'          => self::edd( $eta, $now ),
                'recommended'  => false,
                'fastest'      => false,
                'cheapest'     => false,
                'free_applied' => false,
                'available'    => true,
                'reason'       => '',
            );
            if ( 'yes' !== $c[ $zone . '_on' ] || (float) $c[ $zone . '_1' ] <= 0 ) {
                // Zone switched off, or no price entered for it (a 0 price would otherwise be offered free).
                $q['available'] = false;
                $q['reason']    = 'zone';
            } elseif ( (float) $c['max_weight'] > 0 && $weight > (float) $c['max_weight'] ) {
                $q['available'] = false;
                $q['reason']    = 'weight';
            }
            if ( $q['available'] ) {
                $available[] = $q;
            } else {
                $unavailable[] = $q;
            }
        }

        $by    = (string) RWSC_Settings::get( 'recommend_by', 'smart' );
        $order = 'cheapest' === $by ? array( 'normal_cost', 'eta_score', 'priority' ) : ( 'priority' === $by ? array( 'priority', 'eta_score', 'normal_cost' ) : array( 'eta_score', 'normal_cost', 'priority' ) );
        usort(
            $available,
            static function ( $a, $b ) use ( $order ) {
                foreach ( $order as $k ) {
                    if ( $a[ $k ] != $b[ $k ] ) { // phpcs:ignore Universal.Operators.StrictComparisons
                        return $a[ $k ] < $b[ $k ] ? -1 : 1;
                    }
                }
                return strcmp( $a['name'], $b['name'] );
            }
        );

        if ( $available ) {
            $available[0]['recommended'] = true;
            if ( count( $available ) > 1 ) {
                $scores = wp_list_pluck( $available, 'eta_score' );
                $costs  = wp_list_pluck( $available, 'normal_cost' );
                if ( min( $scores ) < 99999 && min( $scores ) !== max( $scores ) ) {
                    foreach ( $available as $i => $q ) {
                        $available[ $i ]['fastest'] = $q['eta_score'] === min( $scores );
                    }
                }
                if ( min( $costs ) != max( $costs ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
                    foreach ( $available as $i => $q ) {
                        $available[ $i ]['cheapest'] = abs( $q['normal_cost'] - min( $costs ) ) < 0.0001;
                    }
                }
            }
            if ( $out['free'] ) {
                $mode = (string) RWSC_Settings::get( 'free_mode', 'all' );
                foreach ( $available as $i => $q ) {
                    if ( 'all' === $mode || $q['recommended'] ) {
                        $available[ $i ]['cost']         = 0.0;
                        $available[ $i ]['free_applied'] = true;
                    }
                }
            }
        }

        $out['quotes'] = $args['include_unavailable'] ? array_merge( $available, $unavailable ) : $available;
        return apply_filters( 'rwsc_quotes', $out, $args );
    }

    public static function badges( array $q ) {
        $b = array();
        if ( ! empty( $q['recommended'] ) ) {
            $b[] = 'recommended';
        }
        if ( ! empty( $q['fastest'] ) ) {
            $b[] = 'fastest';
        }
        if ( ! empty( $q['cheapest'] ) ) {
            $b[] = 'cheapest';
        }
        return $b;
    }

    public static function badge_text( $badge ) {
        $map = array(
            'recommended' => RWSC_Settings::get( 'badge_rec', 'Recommended' ),
            'fastest'     => RWSC_Settings::get( 'badge_fast', 'Fastest' ),
            'cheapest'    => RWSC_Settings::get( 'badge_cheap', 'Best price' ),
        );
        return isset( $map[ $badge ] ) ? trim( (string) $map[ $badge ] ) : '';
    }

    /* ------------------------------------------------------------------ */
    /* WooCommerce integration                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Make WooCommerce's rate cache aware of settings, visibility and the date (for ETA dates).
     */
    public static function package_hash( $packages ) {
        if ( ! is_array( $packages ) ) {
            return $packages;
        }
        $now   = self::now();
        $parts = explode( ':', (string) RWSC_Settings::get( 'cutoff', '17:00' ) );
        $after = ( (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' ) ) >= ( (int) $parts[0] * 60 + ( isset( $parts[1] ) ? (int) $parts[1] : 0 ) );
        $sig   = RWSC_Settings::hash() . '|' . ( self::active_for_current_user() ? 1 : 0 ) . '|' . $now->format( 'Ymd' ) . ( $after ? 'b' : 'a' );
        foreach ( $packages as $i => $package ) {
            if ( is_array( $package ) ) {
                $packages[ $i ]['rwsc_signature'] = $sig;
            }
        }
        return $packages;
    }

    private static function is_pickup( $method_id ) {
        return in_array( $method_id, apply_filters( 'rwsc_pickup_methods', array( 'local_pickup', 'pickup_location' ) ), true );
    }

    /**
     * Replace the zone's base rate with one rate per courier.
     *
     * @param WC_Shipping_Rate[] $rates   Rates.
     * @param array              $package Package.
     */
    public static function filter_rates( $rates, $package ) {
        if ( ! is_array( $rates ) || empty( $rates ) || ! self::active_for_current_user() ) {
            return $rates;
        }

        $pickup = array();
        $free   = null;
        $base   = null;
        $others = array();
        foreach ( $rates as $id => $rate ) {
            if ( ! $rate instanceof WC_Shipping_Rate ) {
                continue;
            }
            if ( 0 === strpos( (string) $rate->get_id(), self::RATE_PREFIX ) ) {
                continue;
            }
            $mid = (string) $rate->get_method_id();
            if ( self::is_pickup( $mid ) ) {
                $pickup[ $id ] = $rate;
            } elseif ( 'free_shipping' === $mid ) {
                $free = $free ? $free : $rate;
            } else {
                if ( ! $base && 'flat_rate' === $mid ) {
                    $base = $rate;
                }
                $others[ $id ] = $rate;
            }
        }
        if ( ! $base && $others ) {
            $base = reset( $others );
        }
        if ( ! $base ) {
            $base = $free;
        }
        $base = apply_filters( 'rwsc_base_rate', $base, $rates, $package );
        if ( ! $base instanceof WC_Shipping_Rate ) {
            return $rates;
        }

        $quote = self::quotes(
            array(
                'destination' => isset( $package['destination'] ) ? (array) $package['destination'] : array(),
                'weight'      => self::package_weight( (array) $package ),
                'free'        => null !== $free,
            )
        );
        if ( '' === $quote['zone'] || empty( $quote['quotes'] ) ) {
            return $rates;
        }

        $tax_status = method_exists( $base, 'get_tax_status' ) ? (string) $base->get_tax_status() : ( array_sum( (array) $base->get_taxes() ) > 0 ? 'taxable' : 'none' );
        $taxable    = wc_tax_enabled() && 'taxable' === $tax_status;
        $instance   = (int) $base->get_instance_id();
        $method_id  = (string) $base->get_method_id();
        $base_meta  = $base->get_meta_data();
        $items_meta = isset( $base_meta['Items'] ) ? $base_meta['Items'] : ( isset( $base_meta[ __( 'Items', 'woocommerce' ) ] ) ? $base_meta[ __( 'Items', 'woocommerce' ) ] : '' );

        $new = array();
        foreach ( $quote['quotes'] as $q ) {
            $id    = self::RATE_PREFIX . $q['key'] . '_' . $instance;
            $taxes = ( $taxable && $q['cost'] > 0 ) ? WC_Tax::calc_shipping_tax( $q['cost'], WC_Tax::get_shipping_tax_rates() ) : array();
            $rate  = new WC_Shipping_Rate( $id, self::plain_label( $q ), $q['cost'], $taxes, $method_id, $instance );
            if ( method_exists( $rate, 'set_tax_status' ) ) {
                $rate->set_tax_status( $taxable ? 'taxable' : 'none' );
            }
            if ( method_exists( $rate, 'set_delivery_time' ) ) {
                $rate->set_delivery_time( self::delivery_text( $q ) );
            }
            if ( '' !== $items_meta ) {
                $rate->add_meta_data( __( 'Items', 'woocommerce' ), $items_meta );
            }
            $rate->add_meta_data( 'rwsc_courier_key', $q['key'] );
            $rate->add_meta_data( 'rwsc_courier_name', $q['name'] );
            $rate->add_meta_data( 'rwsc_eta', $q['eta'] );
            $rate->add_meta_data( 'rwsc_edd', $q['edd'] ? $q['edd']['from'] . '|' . $q['edd']['to'] : '' );
            $rate->add_meta_data( 'rwsc_zone', $quote['zone'] );
            $rate->add_meta_data( 'rwsc_district', $quote['district'] );
            $rate->add_meta_data( 'rwsc_weight', (string) $quote['weight'] );
            $rate->add_meta_data( 'rwsc_normal_cost', (string) $q['normal_cost'] );
            $rate->add_meta_data( 'rwsc_free_applied', $q['free_applied'] ? 'yes' : 'no' );
            $rate->add_meta_data( 'rwsc_badges', implode( ',', self::badges( $q ) ) );
            $rate->add_meta_data( 'rwsc_recommended', $q['recommended'] ? 'yes' : 'no' );
            $new[ $id ] = $rate;
        }

        if ( RWSC_Settings::on( 'keep_pickup' ) ) {
            $new += $pickup;
        }

        return apply_filters( 'rwsc_package_rates', $new, $rates, $package, $quote );
    }

    /**
     * Plain-text rate label (used by Cart/Checkout blocks, emails fall back to the courier name).
     */
    public static function plain_label( array $q ) {
        $label = $q['name'];
        if ( RWSC_Settings::on( 'show_badges' ) && ! empty( $q['recommended'] ) ) {
            $txt = self::badge_text( 'recommended' );
            if ( '' !== $txt ) {
                $label .= ' · ' . $txt;
            }
        }
        return $label;
    }

    /**
     * ETA / date text shown under a rate.
     */
    public static function delivery_text( array $q ) {
        $parts = array();
        if ( RWSC_Settings::on( 'show_eta' ) && '' !== $q['eta'] ) {
            $parts[] = $q['eta'];
        }
        if ( RWSC_Settings::on( 'show_edd' ) && ! empty( $q['edd']['label'] ) ) {
            /* translators: %s: estimated delivery date(s) */
            $parts[] = sprintf( __( 'Arrives %s', 'rar-woo-smart-courier' ), $q['edd']['label'] );
        }
        return implode( ' · ', $parts );
    }

    public static function rate_meta( $rate ) {
        $raw = is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ? $rate->get_meta_data() : array();
        $out = array();
        foreach ( (array) $raw as $k => $v ) {
            if ( is_string( $k ) ) {
                $out[ $k ] = $v;
            } elseif ( is_array( $v ) && isset( $v['key'] ) ) {
                $out[ $v['key'] ] = isset( $v['value'] ) ? $v['value'] : '';
            }
        }
        return $out;
    }

    /**
     * Rich HTML label for the classic Cart / Checkout.
     */
    public static function full_label( $label, $method ) {
        if ( ! $method instanceof WC_Shipping_Rate || 0 !== strpos( (string) $method->get_id(), self::RATE_PREFIX ) ) {
            return $label;
        }
        $m      = self::rate_meta( $method );
        $key    = isset( $m['rwsc_courier_key'] ) ? (string) $m['rwsc_courier_key'] : '';
        $name   = isset( $m['rwsc_courier_name'] ) ? (string) $m['rwsc_courier_name'] : $method->get_label();
        $eta    = isset( $m['rwsc_eta'] ) ? trim( (string) $m['rwsc_eta'] ) : '';
        $edd    = isset( $m['rwsc_edd'] ) ? explode( '|', (string) $m['rwsc_edd'] ) : array();
        $normal = isset( $m['rwsc_normal_cost'] ) ? (float) $m['rwsc_normal_cost'] : (float) $method->get_cost();
        $free   = isset( $m['rwsc_free_applied'] ) && 'yes' === $m['rwsc_free_applied'];
        $badges = isset( $m['rwsc_badges'] ) && '' !== $m['rwsc_badges'] ? explode( ',', (string) $m['rwsc_badges'] ) : array();
        $color  = RWSC_Settings::courier_color( $key );

        $cost = (float) $method->get_cost();
        if ( WC()->cart && WC()->cart->display_prices_including_tax() ) {
            $cost += (float) $method->get_shipping_tax();
        }
        if ( $free ) {
            $price = $normal > 0 ? '<del class="rwsc-old-price">' . wp_kses_post( wc_price( $normal ) ) . '</del>' : '';
        } else {
            $price = '<span class="rwsc-price">' . wp_kses_post( wc_price( $cost ) ) . '</span>';
        }

        $date = '';
        if ( RWSC_Settings::on( 'show_edd' ) && ! empty( $edd[0] ) ) {
            $date = self::range_label( $edd[0], isset( $edd[1] ) ? $edd[1] : $edd[0] );
        }

        if ( 'compact' === RWSC_Settings::get( 'label_style' ) ) {
            $out = '<span class="rwsc-line" style="--rwsc-c:' . esc_attr( $color ) . '"><span class="rwsc-name">' . esc_html( $name ) . '</span>';
            if ( RWSC_Settings::on( 'show_eta' ) && '' !== $eta ) {
                $out .= ' <span class="rwsc-sep">·</span> <span class="rwsc-eta">' . esc_html( $eta ) . '</span>';
            }
            $out .= '<span class="rwsc-colon">:</span> ' . $price;
            if ( RWSC_Settings::on( 'show_badges' ) && in_array( 'recommended', $badges, true ) ) {
                $out .= ' <span class="rwsc-rec">· ' . esc_html( self::badge_text( 'recommended' ) ) . '</span>';
            }
            return $out . '</span>';
        }

        $b = '';
        if ( RWSC_Settings::on( 'show_badges' ) ) {
            foreach ( $badges as $badge ) {
                $txt = self::badge_text( $badge );
                if ( '' !== $txt ) {
                    $b .= '<span class="rwsc-badge rwsc-b-' . esc_attr( $badge ) . '">' . esc_html( $txt ) . '</span>';
                }
            }
        }
        $sub = array();
        if ( RWSC_Settings::on( 'show_eta' ) && '' !== $eta ) {
            $sub[] = '<span class="rwsc-eta">' . esc_html( $eta ) . '</span>';
        }
        if ( '' !== $date ) {
            /* translators: %s: date range */
            $sub[] = '<span class="rwsc-edd">' . esc_html( sprintf( __( 'Arrives %s', 'rar-woo-smart-courier' ), $date ) ) . '</span>';
        }

        $out  = '<span class="rwsc-opt" style="--rwsc-c:' . esc_attr( $color ) . '">';
        $out .= '<span class="rwsc-top"><span class="rwsc-dot" aria-hidden="true"></span><span class="rwsc-name">' . esc_html( $name ) . '</span>' . $b . '<span class="rwsc-amt">' . $price . '</span></span>';
        if ( $sub ) {
            $out .= '<span class="rwsc-sub">' . implode( '<span class="rwsc-sep"> · </span>', $sub ) . '</span>';
        }
        return $out . '</span>';
    }

    /**
     * Normalise shipping-item meta when the order is created (classic and Store API checkouts).
     *
     * @param WC_Order_Item_Shipping $item        Item.
     * @param int|string             $package_key Package key.
     * @param array                  $package     Package.
     * @param WC_Order               $order       Order.
     */
    public static function shipping_item( $item, $package_key, $package, $order ) {
        if ( ! $item instanceof WC_Order_Item_Shipping ) {
            return;
        }
        $key = (string) $item->get_meta( 'rwsc_courier_key', true );
        if ( '' === $key ) {
            return;
        }
        $map = array(
            'rwsc_courier_name' => '_rwsc_selected_courier',
            'rwsc_eta'          => '_rwsc_eta',
            'rwsc_edd'          => '_rwsc_edd',
            'rwsc_zone'         => '_rwsc_zone',
            'rwsc_district'     => '_rwsc_district',
            'rwsc_weight'       => '_rwsc_weight',
            'rwsc_normal_cost'  => '_rwsc_normal_cost',
            'rwsc_free_applied' => '_rwsc_free_applied',
            'rwsc_recommended'  => '_rwsc_recommended',
        );
        $item->update_meta_data( '_rwsc_courier', sanitize_key( $key ) );
        foreach ( $map as $from => $to ) {
            $item->update_meta_data( $to, sanitize_text_field( (string) $item->get_meta( $from, true ) ) );
        }
        foreach ( array_merge( array_keys( $map ), array( 'rwsc_courier_key', 'rwsc_badges' ) ) as $k ) {
            $item->delete_meta_data( $k );
        }
        $name = (string) $item->get_meta( '_rwsc_selected_courier', true );
        if ( '' !== $name ) {
            $item->set_method_title( $name );
        }
    }
}
