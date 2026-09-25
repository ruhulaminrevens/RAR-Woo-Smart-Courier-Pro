<?php
/**
 * Settings storage, defaults, sanitising and migration.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Settings {

    const OPTION = 'rwsc_settings';

    /** Zones used by the pricing matrix. */
    const ZONES = array( 'dhaka', 'nearby', 'outside' );

    /** Price fields per zone. */
    const PRICE_FIELDS = array( '1', '2', 'extra' );

    /** @var array|null */
    private static $cache = null;

    /**
     * Built-in courier presets (merchant should verify their own contract rates).
     */
    public static function builtin_couriers() {
        return array(
            'pathao'    => array(
                'name'         => 'Pathao',
                'priority'     => 10,
                'color'        => '#ef4444',
                'tracking_url' => 'https://merchant.pathao.com/tracking?consignment_id={tracking}&phone={phone}',
                'dhaka_1' => 70,  'dhaka_2' => 90,  'dhaka_extra' => 15,
                'nearby_1' => 100, 'nearby_2' => 130, 'nearby_extra' => 25,
                'outside_1' => 130, 'outside_2' => 170, 'outside_extra' => 25,
                'eta_dhaka' => '1–2 business days', 'eta_nearby' => '1–3 business days', 'eta_outside' => '2–3 business days',
            ),
            'paperfly'  => array(
                'name'         => 'Paperfly',
                'priority'     => 20,
                'color'        => '#f59e0b',
                'tracking_url' => '',
                'dhaka_1' => 70,  'dhaka_2' => 90,  'dhaka_extra' => 20,
                'nearby_1' => 110, 'nearby_2' => 130, 'nearby_extra' => 20,
                'outside_1' => 130, 'outside_2' => 150, 'outside_extra' => 20,
                'eta_dhaka' => '1–2 business days', 'eta_nearby' => '1–3 business days', 'eta_outside' => '1–3 business days',
            ),
            'steadfast' => array(
                'name'         => 'Steadfast',
                'priority'     => 30,
                'color'        => '#10b981',
                'tracking_url' => 'https://steadfast.com.bd/t/{tracking}',
                'dhaka_1' => 70,  'dhaka_2' => 90,  'dhaka_extra' => 20,
                'nearby_1' => 100, 'nearby_2' => 130, 'nearby_extra' => 30,
                'outside_1' => 130, 'outside_2' => 170, 'outside_extra' => 30,
                'eta_dhaka' => '1–3 business days', 'eta_nearby' => '1–3 business days', 'eta_outside' => '1–4 business days',
            ),
            'redx'      => array(
                'name'         => 'Redx',
                'priority'     => 40,
                'color'        => '#db2777',
                'tracking_url' => 'https://redx.com.bd/track-global-parcel/?trackingId={tracking}',
                'dhaka_1' => 80,  'dhaka_2' => 100, 'dhaka_extra' => 20,
                'nearby_1' => 100, 'nearby_2' => 130, 'nearby_extra' => 30,
                'outside_1' => 120, 'outside_2' => 150, 'outside_extra' => 30,
                'eta_dhaka' => '1–3 business days', 'eta_nearby' => '1–3 business days', 'eta_outside' => '1–3 business days',
            ),
            'sundarban' => array(
                'name'         => 'Sundarban',
                'priority'     => 50,
                'color'        => '#2563eb',
                'tracking_url' => 'https://tracking.sundarbancourierltd.com/?cnnumber={tracking}',
                'dhaka_1' => 110, 'dhaka_2' => 150, 'dhaka_extra' => 40,
                'nearby_1' => 130, 'nearby_2' => 170, 'nearby_extra' => 40,
                'outside_1' => 150, 'outside_2' => 190, 'outside_extra' => 40,
                'eta_dhaka' => '1–3 business days', 'eta_nearby' => '1–3 business days', 'eta_outside' => '2–4 business days',
            ),
            'custom'    => array(
                'enabled'      => 'no',
                'name'         => '',
                'priority'     => 60,
                'color'        => '#8b5cf6',
                'tracking_url' => '',
                'dhaka_1' => 0, 'dhaka_2' => 0, 'dhaka_extra' => 0,
                'nearby_1' => 0, 'nearby_2' => 0, 'nearby_extra' => 0,
                'outside_1' => 0, 'outside_2' => 0, 'outside_extra' => 0,
                'eta_dhaka' => '', 'eta_nearby' => '', 'eta_outside' => '',
            ),
        );
    }

    /**
     * Blank template every courier is merged onto.
     */
    public static function courier_template() {
        return array(
            'enabled'      => 'yes',
            'name'         => '',
            'priority'     => 100,
            'color'        => '#64748b',
            'tracking_url' => '',
            'max_weight'   => 0,
            'dhaka_on'     => 'yes',
            'nearby_on'    => 'yes',
            'outside_on'   => 'yes',
            'dhaka_1' => 0, 'dhaka_2' => 0, 'dhaka_extra' => 0,
            'nearby_1' => 0, 'nearby_2' => 0, 'nearby_extra' => 0,
            'outside_1' => 0, 'outside_2' => 0, 'outside_extra' => 0,
            'eta_dhaka' => '', 'eta_nearby' => '', 'eta_outside' => '',
        );
    }

    public static function defaults() {
        $couriers = array();
        foreach ( self::builtin_couriers() as $key => $c ) {
            $couriers[ $key ] = wp_parse_args( $c, self::courier_template() );
        }
        return array(
            'version'          => RWSC_VERSION,
            'enabled'          => 'yes',
            'test_mode'        => 'yes',
            'bd_only'          => 'yes',
            'fallback_weight'  => 1,
            'packaging_weight' => 0,
            'nearby_districts' => array( 'BD-18', 'BD-40' ),
            'nearby_areas'     => array( 'Savar', 'Ashulia', 'Keraniganj', 'Dohar', 'Dhamrai', 'Nawabganj', 'সাভার', 'আশুলিয়া', 'কেরানীগঞ্জ', 'দোহার', 'ধামরাই', 'নবাবগঞ্জ' ),
            'dhaka_areas'      => array(),
            'scan_address'     => 'yes',
            'recommend_by'     => 'smart',
            'label_style'      => 'detailed',
            'show_eta'         => 'yes',
            'show_edd'         => 'yes',
            'show_badges'      => 'yes',
            'badge_rec'        => 'Recommended',
            'badge_fast'       => 'Fastest',
            'badge_cheap'      => 'Best price',
            'free_mode'        => 'all',
            'keep_pickup'      => 'yes',
            'weekend'          => array( 'fri' ),
            'holidays'         => array(),
            'cutoff'           => '17:00',
            'notify_tracking'  => 'yes',
            'show_account'     => 'yes',
            'show_email'       => 'yes',
            'auto_assign'      => 'yes',
            'auto_complete'    => 'no',
            'on_return'        => '',
            'ready_statuses'   => array( 'processing' ),
            'stale_days'       => 2,
            'cod_methods'      => array( 'cod' ),
            'sender_name'      => '',
            'sender_phone'     => '',
            'sender_address'   => '',
            'couriers'         => $couriers,
        );
    }

    /**
     * Full merged settings.
     */
    public static function all() {
        if ( null !== self::$cache ) {
            return self::$cache;
        }
        $saved = get_option( self::OPTION, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        $defaults = self::defaults();
        $s        = wp_parse_args( $saved, $defaults );

        // Couriers: merge each saved courier onto its builtin preset / template.
        $builtin  = self::builtin_couriers();
        $couriers = array();
        $saved_c  = isset( $saved['couriers'] ) && is_array( $saved['couriers'] ) ? $saved['couriers'] : array();
        foreach ( $builtin as $key => $preset ) {
            $base             = wp_parse_args( $preset, self::courier_template() );
            $couriers[ $key ] = isset( $saved_c[ $key ] ) && is_array( $saved_c[ $key ] ) ? wp_parse_args( $saved_c[ $key ], $base ) : $base;
        }
        foreach ( $saved_c as $key => $c ) {
            if ( isset( $couriers[ $key ] ) || ! is_array( $c ) ) {
                continue;
            }
            $couriers[ sanitize_key( $key ) ] = wp_parse_args( $c, self::courier_template() );
        }
        $s['couriers'] = $couriers;

        foreach ( array( 'nearby_districts', 'nearby_areas', 'dhaka_areas', 'weekend', 'holidays', 'ready_statuses', 'cod_methods' ) as $list ) {
            $s[ $list ] = array_values( array_filter( (array) $s[ $list ], 'strlen' ) );
        }

        self::$cache = $s;
        return $s;
    }

    public static function get( $key, $default = null ) {
        $s = self::all();
        return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
    }

    public static function on( $key ) {
        return 'yes' === self::get( $key );
    }

    public static function flush() {
        self::$cache = null;
    }

    /**
     * Couriers sorted by priority.
     *
     * @param bool $enabled_only Only enabled couriers with a name.
     */
    public static function couriers( $enabled_only = false ) {
        $all = self::get( 'couriers', array() );
        $out = array();
        foreach ( $all as $key => $c ) {
            $c['key']  = $key;
            $c['name'] = trim( (string) $c['name'] );
            if ( $enabled_only && ( 'yes' !== $c['enabled'] || '' === $c['name'] ) ) {
                continue;
            }
            $out[ $key ] = $c;
        }
        uasort(
            $out,
            static function ( $a, $b ) {
                if ( (int) $a['priority'] !== (int) $b['priority'] ) {
                    return (int) $a['priority'] <=> (int) $b['priority'];
                }
                return strcmp( $a['key'], $b['key'] );
            }
        );
        return $out;
    }

    public static function courier( $key ) {
        $all = self::couriers();
        return isset( $all[ $key ] ) ? $all[ $key ] : null;
    }

    /**
     * Find a courier key by (legacy) display name.
     */
    public static function courier_key_by_name( $name ) {
        $name = strtolower( trim( (string) $name ) );
        if ( '' === $name ) {
            return '';
        }
        foreach ( self::couriers() as $key => $c ) {
            if ( strtolower( $c['name'] ) === $name || $key === sanitize_key( $name ) ) {
                return $key;
            }
        }
        return '';
    }

    public static function courier_name( $key ) {
        $c = self::courier( $key );
        if ( $c && '' !== $c['name'] ) {
            return $c['name'];
        }
        $archive = (array) get_option( 'rwsc_courier_archive', array() );
        if ( isset( $archive[ $key ]['name'] ) ) {
            return (string) $archive[ $key ]['name'];
        }
        return $key ? ucfirst( $key ) : '';
    }

    public static function courier_color( $key ) {
        $c = self::courier( $key );
        if ( $c ) {
            return self::color( $c['color'], '#64748b' );
        }
        $archive = (array) get_option( 'rwsc_courier_archive', array() );
        return isset( $archive[ $key ]['color'] ) ? self::color( $archive[ $key ]['color'], '#94a3b8' ) : '#94a3b8';
    }

    /**
     * Persist settings, bust WooCommerce shipping caches.
     */
    public static function save( array $settings ) {
        $settings['version'] = RWSC_VERSION;
        update_option( self::OPTION, $settings, false );
        self::flush();
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
        do_action( 'rwsc_settings_saved', $settings );
    }

    /**
     * Stable hash of everything that affects rates (used in shipping package hash).
     */
    public static function hash() {
        $s = self::all();
        unset( $s['notify_tracking'], $s['show_account'], $s['show_email'], $s['auto_complete'], $s['on_return'], $s['stale_days'], $s['sender_name'], $s['sender_phone'], $s['sender_address'] );
        return substr( md5( wp_json_encode( $s ) ), 0, 12 );
    }

    /* ------------------------------------------------------------------ */
    /* Sanitising                                                          */
    /* ------------------------------------------------------------------ */

    public static function yes( $input, $key ) {
        return ! empty( $input[ $key ] ) && 'no' !== $input[ $key ] ? 'yes' : 'no';
    }

    public static function color( $value, $fallback = '#64748b' ) {
        $c = sanitize_hex_color( (string) $value );
        return $c ? $c : $fallback;
    }

    public static function money( $value ) {
        $v = (float) str_replace( ',', '', (string) $value );
        return max( 0, round( $v, 2 ) );
    }

    public static function list_from_text( $value ) {
        if ( is_array( $value ) ) {
            $parts = $value;
        } else {
            $parts = preg_split( '/[,\n\r]+/u', (string) $value );
        }
        $out = array();
        foreach ( $parts as $p ) {
            $p = trim( sanitize_text_field( (string) $p ) );
            if ( '' !== $p && ! in_array( $p, $out, true ) ) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function time_hm( $value, $fallback = '17:00' ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
            return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
        }
        return $fallback;
    }

    /**
     * Sanitise a posted settings form, keeping anything not posted.
     *
     * @param array $in      Raw (unslashed) input.
     * @param array $current Current settings.
     */
    public static function sanitize( array $in, array $current ) {
        $s = $current;

        foreach ( array( 'enabled', 'test_mode', 'bd_only', 'scan_address', 'show_eta', 'show_edd', 'show_badges', 'keep_pickup', 'notify_tracking', 'show_account', 'show_email', 'auto_assign', 'auto_complete' ) as $flag ) {
            if ( isset( $in['_flags'] ) && in_array( $flag, (array) $in['_flags'], true ) ) {
                $s[ $flag ] = self::yes( $in, $flag );
            }
        }

        if ( isset( $in['fallback_weight'] ) ) {
            $s['fallback_weight'] = max( 0.01, round( (float) $in['fallback_weight'], 3 ) );
        }
        if ( isset( $in['packaging_weight'] ) ) {
            $s['packaging_weight'] = max( 0, round( (float) $in['packaging_weight'], 3 ) );
        }
        if ( isset( $in['_lists'] ) ) {
            $lists = (array) $in['_lists'];
            if ( in_array( 'nearby_districts', $lists, true ) ) {
                $codes = array();
                foreach ( (array) ( isset( $in['nearby_districts'] ) ? $in['nearby_districts'] : array() ) as $code ) {
                    $code = strtoupper( sanitize_text_field( (string) $code ) );
                    if ( RWSC_Locations::exists( $code ) && ! in_array( $code, $codes, true ) ) {
                        $codes[] = $code;
                    }
                }
                $s['nearby_districts'] = $codes;
            }
            foreach ( array( 'nearby_areas', 'dhaka_areas' ) as $l ) {
                if ( in_array( $l, $lists, true ) ) {
                    $s[ $l ] = self::list_from_text( isset( $in[ $l ] ) ? $in[ $l ] : '' );
                }
            }
            if ( in_array( 'weekend', $lists, true ) ) {
                $days        = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );
                $s['weekend'] = array_values( array_intersect( $days, (array) ( isset( $in['weekend'] ) ? $in['weekend'] : array() ) ) );
                if ( count( $s['weekend'] ) >= 7 ) {
                    $s['weekend'] = array( 'fri' );
                }
            }
            if ( in_array( 'holidays', $lists, true ) ) {
                $s['holidays'] = self::parse_holidays( isset( $in['holidays'] ) ? $in['holidays'] : '' );
            }
            if ( in_array( 'ready_statuses', $lists, true ) ) {
                $valid = array_keys( wc_get_order_statuses() );
                $st    = array();
                foreach ( (array) ( isset( $in['ready_statuses'] ) ? $in['ready_statuses'] : array() ) as $v ) {
                    $v = 'wc-' . preg_replace( '/^wc-/', '', sanitize_key( $v ) );
                    if ( in_array( $v, $valid, true ) ) {
                        $st[] = substr( $v, 3 );
                    }
                }
                $s['ready_statuses'] = $st ? $st : array( 'processing' );
            }
            if ( in_array( 'cod_methods', $lists, true ) ) {
                $s['cod_methods'] = array_map( 'sanitize_key', self::list_from_text( isset( $in['cod_methods'] ) ? $in['cod_methods'] : '' ) );
            }
        }

        $choices = array(
            'recommend_by' => array( 'smart', 'cheapest', 'priority' ),
            'label_style'  => array( 'detailed', 'compact' ),
            'free_mode'    => array( 'all', 'recommended' ),
            'on_return'    => array( '', 'cancelled', 'failed', 'on-hold', 'refunded' ),
        );
        foreach ( $choices as $k => $allowed ) {
            if ( isset( $in[ $k ] ) && in_array( (string) $in[ $k ], $allowed, true ) ) {
                $s[ $k ] = (string) $in[ $k ];
            }
        }
        foreach ( array( 'badge_rec', 'badge_fast', 'badge_cheap', 'sender_name', 'sender_phone' ) as $k ) {
            if ( isset( $in[ $k ] ) ) {
                $s[ $k ] = sanitize_text_field( (string) $in[ $k ] );
            }
        }
        if ( isset( $in['sender_address'] ) ) {
            $s['sender_address'] = sanitize_textarea_field( (string) $in['sender_address'] );
        }
        if ( isset( $in['cutoff'] ) ) {
            $s['cutoff'] = self::time_hm( $in['cutoff'], '17:00' );
        }
        if ( isset( $in['stale_days'] ) ) {
            $s['stale_days'] = min( 30, max( 1, (int) $in['stale_days'] ) );
        }

        if ( isset( $in['couriers'] ) && is_array( $in['couriers'] ) ) {
            $s['couriers'] = self::sanitize_couriers( $in['couriers'], isset( $current['couriers'] ) ? $current['couriers'] : array() );
        }

        return $s;
    }

    public static function sanitize_couriers( array $posted, array $current ) {
        $builtin = self::builtin_couriers();
        $out     = array();
        foreach ( $posted as $key => $p ) {
            $key = sanitize_key( $key );
            if ( '' === $key || ! is_array( $p ) ) {
                continue;
            }
            if ( ! empty( $p['_delete'] ) && ! isset( $builtin[ $key ] ) ) {
                // Custom couriers can be removed; built-ins can only be disabled. Keep the name for old orders.
                if ( isset( $current[ $key ]['name'] ) && '' !== $current[ $key ]['name'] ) {
                    $archive         = (array) get_option( 'rwsc_courier_archive', array() );
                    $archive[ $key ] = array( 'name' => $current[ $key ]['name'], 'color' => isset( $current[ $key ]['color'] ) ? $current[ $key ]['color'] : '#94a3b8' );
                    update_option( 'rwsc_courier_archive', $archive, false );
                }
                continue;
            }
            $base = isset( $current[ $key ] ) ? $current[ $key ] : ( isset( $builtin[ $key ] ) ? wp_parse_args( $builtin[ $key ], self::courier_template() ) : self::courier_template() );
            $c    = wp_parse_args( $base, self::courier_template() );

            $c['enabled']      = self::yes( $p, 'enabled' );
            $c['name']         = isset( $p['name'] ) ? sanitize_text_field( (string) $p['name'] ) : $c['name'];
            $c['priority']     = isset( $p['priority'] ) ? max( 1, min( 999, (int) $p['priority'] ) ) : (int) $c['priority'];
            $c['color']        = isset( $p['color'] ) ? self::color( $p['color'], $c['color'] ) : $c['color'];
            $c['tracking_url'] = isset( $p['tracking_url'] ) ? self::tracking_template( $p['tracking_url'] ) : $c['tracking_url'];
            $c['max_weight']   = isset( $p['max_weight'] ) ? max( 0, round( (float) $p['max_weight'], 2 ) ) : (float) $c['max_weight'];
            foreach ( self::ZONES as $z ) {
                $c[ $z . '_on' ] = self::yes( $p, $z . '_on' );
                foreach ( self::PRICE_FIELDS as $f ) {
                    if ( isset( $p[ $z . '_' . $f ] ) ) {
                        $c[ $z . '_' . $f ] = self::money( $p[ $z . '_' . $f ] );
                    }
                }
                if ( isset( $p[ 'eta_' . $z ] ) ) {
                    $c[ 'eta_' . $z ] = sanitize_text_field( (string) $p[ 'eta_' . $z ] );
                }
            }
            if ( 'yes' === $c['enabled'] && '' === trim( $c['name'] ) ) {
                $c['enabled'] = 'no';
            }
            $out[ $key ] = $c;
        }
        // Built-ins that were not posted keep their current values.
        foreach ( $builtin as $key => $preset ) {
            if ( ! isset( $out[ $key ] ) && ! isset( $posted[ $key ] ) ) {
                $out[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : wp_parse_args( $preset, self::courier_template() );
            }
        }
        return $out;
    }

    public static function tracking_template( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        // Keep placeholders intact through esc_url_raw.
        $tmp = str_replace( array( '{tracking}', '{phone}', '{order}' ), array( 'RWSCTRACKING', 'RWSCPHONE', 'RWSCORDER' ), $url );
        $tmp = esc_url_raw( $tmp, array( 'http', 'https' ) );
        return str_replace( array( 'RWSCTRACKING', 'RWSCPHONE', 'RWSCORDER' ), array( '{tracking}', '{phone}', '{order}' ), $tmp );
    }

    /**
     * Parse "YYYY-MM-DD optional label" lines.
     */
    public static function parse_holidays( $text ) {
        $out = array();
        foreach ( preg_split( '/[\r\n,]+/', (string) $text ) as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\s+(.*))?$/', $line, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                $date  = sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
                $label = isset( $m[4] ) ? trim( $m[4] ) : '';
                $out[ $date ] = $date . ( '' !== $label ? ' ' . $label : '' );
            }
        }
        ksort( $out );
        return array_values( $out );
    }

    public static function holiday_dates() {
        $dates = array();
        foreach ( (array) self::get( 'holidays', array() ) as $line ) {
            $dates[] = substr( (string) $line, 0, 10 );
        }
        return $dates;
    }

    /* ------------------------------------------------------------------ */
    /* Migration                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Upgrade settings saved by v1.x to the v2 structure. Idempotent.
     */
    public static function migrate() {
        $saved = get_option( self::OPTION, null );
        if ( ! is_array( $saved ) ) {
            return;
        }
        if ( isset( $saved['version'] ) && version_compare( (string) $saved['version'], '2.0.0', '>=' ) ) {
            return;
        }

        // Nearby districts were saved as labels ("Gazipur") – convert to state codes.
        if ( isset( $saved['nearby_districts'] ) ) {
            $codes = array();
            foreach ( (array) $saved['nearby_districts'] as $label ) {
                $code = RWSC_Locations::resolve_district( $label );
                if ( $code && ! in_array( $code, $codes, true ) ) {
                    $codes[] = $code;
                }
            }
            $saved['nearby_districts'] = $codes;
        }
        // "nearby_cities" was renamed to "nearby_areas".
        if ( isset( $saved['nearby_cities'] ) && ! isset( $saved['nearby_areas'] ) ) {
            $saved['nearby_areas'] = array_values( array_filter( array_map( 'trim', (array) $saved['nearby_cities'] ), 'strlen' ) );
        }
        unset( $saved['nearby_cities'] );

        $saved['version'] = RWSC_VERSION;
        update_option( self::OPTION, $saved, false );
        self::flush();
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
    }

    /**
     * Settings array safe to expose in admin JS.
     */
    public static function export() {
        $s = self::all();
        unset( $s['version'] );
        return $s;
    }
}
