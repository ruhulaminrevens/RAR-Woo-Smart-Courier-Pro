<?php
/**
 * Bangladesh districts (WooCommerce state codes), aliases and delivery-zone resolution.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Locations {

    const DHAKA = 'BD-13';

    /**
     * code => [ English, Bangla, Division, aliases... ]
     */
    private static $districts = array(
        'BD-05' => array( 'Bagerhat', 'বাগেরহাট', 'Khulna' ),
        'BD-01' => array( 'Bandarban', 'বান্দরবান', 'Chattogram' ),
        'BD-02' => array( 'Barguna', 'বরগুনা', 'Barishal' ),
        'BD-06' => array( 'Barishal', 'বরিশাল', 'Barishal', 'Barisal' ),
        'BD-07' => array( 'Bhola', 'ভোলা', 'Barishal' ),
        'BD-03' => array( 'Bogura', 'বগুড়া', 'Rajshahi', 'Bogra' ),
        'BD-04' => array( 'Brahmanbaria', 'ব্রাহ্মণবাড়িয়া', 'Chattogram', 'B.Baria', 'B Baria' ),
        'BD-09' => array( 'Chandpur', 'চাঁদপুর', 'Chattogram' ),
        'BD-10' => array( 'Chattogram', 'চট্টগ্রাম', 'Chattogram', 'Chittagong', 'Ctg' ),
        'BD-12' => array( 'Chuadanga', 'চুয়াডাঙ্গা', 'Khulna' ),
        'BD-11' => array( "Cox's Bazar", 'কক্সবাজার', 'Chattogram', 'Coxs Bazar', 'Cox Bazar', 'Coxsbazar' ),
        'BD-08' => array( 'Cumilla', 'কুমিল্লা', 'Chattogram', 'Comilla' ),
        'BD-13' => array( 'Dhaka', 'ঢাকা', 'Dhaka', 'Dacca' ),
        'BD-14' => array( 'Dinajpur', 'দিনাজপুর', 'Rangpur' ),
        'BD-15' => array( 'Faridpur', 'ফরিদপুর', 'Dhaka' ),
        'BD-16' => array( 'Feni', 'ফেনী', 'Chattogram' ),
        'BD-19' => array( 'Gaibandha', 'গাইবান্ধা', 'Rangpur' ),
        'BD-18' => array( 'Gazipur', 'গাজীপুর', 'Dhaka' ),
        'BD-17' => array( 'Gopalganj', 'গোপালগঞ্জ', 'Dhaka' ),
        'BD-20' => array( 'Habiganj', 'হবিগঞ্জ', 'Sylhet' ),
        'BD-21' => array( 'Jamalpur', 'জামালপুর', 'Mymensingh' ),
        'BD-22' => array( 'Jashore', 'যশোর', 'Khulna', 'Jessore' ),
        'BD-25' => array( 'Jhalokati', 'ঝালকাঠি', 'Barishal', 'Jhalakathi', 'Jhalokathi' ),
        'BD-23' => array( 'Jhenaidah', 'ঝিনাইদহ', 'Khulna', 'Jhenaidaha' ),
        'BD-24' => array( 'Joypurhat', 'জয়পুরহাট', 'Rajshahi', 'Jaipurhat' ),
        'BD-29' => array( 'Khagrachhari', 'খাগড়াছড়ি', 'Chattogram', 'Khagrachari' ),
        'BD-27' => array( 'Khulna', 'খুলনা', 'Khulna' ),
        'BD-26' => array( 'Kishoreganj', 'কিশোরগঞ্জ', 'Dhaka', 'Kishorganj' ),
        'BD-28' => array( 'Kurigram', 'কুড়িগ্রাম', 'Rangpur' ),
        'BD-30' => array( 'Kushtia', 'কুষ্টিয়া', 'Khulna' ),
        'BD-31' => array( 'Lakshmipur', 'লক্ষ্মীপুর', 'Chattogram', 'Laxmipur' ),
        'BD-32' => array( 'Lalmonirhat', 'লালমনিরহাট', 'Rangpur' ),
        'BD-36' => array( 'Madaripur', 'মাদারীপুর', 'Dhaka' ),
        'BD-37' => array( 'Magura', 'মাগুরা', 'Khulna' ),
        'BD-33' => array( 'Manikganj', 'মানিকগঞ্জ', 'Dhaka' ),
        'BD-39' => array( 'Meherpur', 'মেহেরপুর', 'Khulna' ),
        'BD-38' => array( 'Moulvibazar', 'মৌলভীবাজার', 'Sylhet', 'Maulvibazar', 'Moulvi Bazar' ),
        'BD-35' => array( 'Munshiganj', 'মুন্সীগঞ্জ', 'Dhaka' ),
        'BD-34' => array( 'Mymensingh', 'ময়মনসিংহ', 'Mymensingh' ),
        'BD-48' => array( 'Naogaon', 'নওগাঁ', 'Rajshahi' ),
        'BD-43' => array( 'Narail', 'নড়াইল', 'Khulna' ),
        'BD-40' => array( 'Narayanganj', 'নারায়ণগঞ্জ', 'Dhaka', 'Narayangonj' ),
        'BD-42' => array( 'Narsingdi', 'নরসিংদী', 'Dhaka', 'Narshingdi' ),
        'BD-44' => array( 'Natore', 'নাটোর', 'Rajshahi' ),
        'BD-45' => array( 'Chapainawabganj', 'চাঁপাইনবাবগঞ্জ', 'Rajshahi', 'Nawabganj', 'Chapai Nawabganj', 'Chapai' ),
        'BD-41' => array( 'Netrokona', 'নেত্রকোণা', 'Mymensingh', 'Netrakona' ),
        'BD-46' => array( 'Nilphamari', 'নীলফামারী', 'Rangpur' ),
        'BD-47' => array( 'Noakhali', 'নোয়াখালী', 'Chattogram' ),
        'BD-49' => array( 'Pabna', 'পাবনা', 'Rajshahi' ),
        'BD-52' => array( 'Panchagarh', 'পঞ্চগড়', 'Rangpur' ),
        'BD-51' => array( 'Patuakhali', 'পটুয়াখালী', 'Barishal' ),
        'BD-50' => array( 'Pirojpur', 'পিরোজপুর', 'Barishal' ),
        'BD-53' => array( 'Rajbari', 'রাজবাড়ী', 'Dhaka' ),
        'BD-54' => array( 'Rajshahi', 'রাজশাহী', 'Rajshahi' ),
        'BD-56' => array( 'Rangamati', 'রাঙ্গামাটি', 'Chattogram' ),
        'BD-55' => array( 'Rangpur', 'রংপুর', 'Rangpur' ),
        'BD-58' => array( 'Satkhira', 'সাতক্ষীরা', 'Khulna' ),
        'BD-62' => array( 'Shariatpur', 'শরীয়তপুর', 'Dhaka' ),
        'BD-57' => array( 'Sherpur', 'শেরপুর', 'Mymensingh' ),
        'BD-59' => array( 'Sirajganj', 'সিরাজগঞ্জ', 'Rajshahi' ),
        'BD-61' => array( 'Sunamganj', 'সুনামগঞ্জ', 'Sylhet' ),
        'BD-60' => array( 'Sylhet', 'সিলেট', 'Sylhet' ),
        'BD-63' => array( 'Tangail', 'টাঙ্গাইল', 'Dhaka' ),
        'BD-64' => array( 'Thakurgaon', 'ঠাকুরগাঁও', 'Rangpur' ),
    );

    /** @var array|null normalised alias => code */
    private static $index = null;

    public static function districts() {
        $out = array();
        foreach ( self::$districts as $code => $d ) {
            $out[ $code ] = array(
                'code'     => $code,
                'name'     => $d[0],
                'bn'       => $d[1],
                'division' => $d[2],
            );
        }
        uasort(
            $out,
            static function ( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
        );
        return $out;
    }

    public static function divisions() {
        return array( 'Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh' );
    }

    public static function exists( $code ) {
        return isset( self::$districts[ strtoupper( (string) $code ) ] );
    }

    public static function name( $code, $with_bn = false ) {
        $code = strtoupper( (string) $code );
        if ( ! isset( self::$districts[ $code ] ) ) {
            return '';
        }
        $d = self::$districts[ $code ];
        return $with_bn ? $d[0] . ' (' . $d[1] . ')' : $d[0];
    }

    public static function division( $code ) {
        $code = strtoupper( (string) $code );
        return isset( self::$districts[ $code ] ) ? self::$districts[ $code ][2] : '';
    }

    /**
     * Lower-case, strip punctuation/extra spaces. Works for Bangla too.
     */
    public static function normalize( $text ) {
        $text = wp_strip_all_tags( (string) $text );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $text = str_replace( array( "'", '’', '`' ), '', $text );
        $text = preg_replace( '/[^\p{L}\p{M}\p{N}]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
    }

    private static function index() {
        if ( null !== self::$index ) {
            return self::$index;
        }
        self::$index = array();
        foreach ( self::$districts as $code => $d ) {
            $names = array_merge( array( $d[0], $d[1] ), array_slice( $d, 3 ) );
            foreach ( $names as $n ) {
                $k = self::normalize( $n );
                if ( '' !== $k && ! isset( self::$index[ $k ] ) ) {
                    self::$index[ $k ] = $code;
                }
            }
            $k = self::normalize( $d[0] . ' district' );
            self::$index[ $k ] = $code;
        }
        // WooCommerce state labels (they contain quirks such as "Faridpur " and "Nawabganj").
        if ( function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( 'BD' );
            if ( is_array( $states ) ) {
                foreach ( $states as $code => $label ) {
                    $k = self::normalize( html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ) );
                    if ( '' !== $k && isset( self::$districts[ $code ] ) && ! isset( self::$index[ $k ] ) ) {
                        self::$index[ $k ] = $code;
                    }
                }
            }
        }
        return self::$index;
    }

    /**
     * Resolve a WooCommerce state value (code or free text, English or Bangla) to a district code.
     *
     * @return string Code like BD-13, or '' when unknown.
     */
    public static function resolve_district( $state ) {
        $state = trim( (string) $state );
        if ( '' === $state ) {
            return '';
        }
        $upper = strtoupper( $state );
        if ( isset( self::$districts[ $upper ] ) ) {
            return $upper;
        }
        // Some stores use codes without the country prefix ("13").
        if ( preg_match( '/^(?:BD-?)?(\d{1,2})$/i', $state, $m ) ) {
            $code = sprintf( 'BD-%02d', (int) $m[1] );
            if ( isset( self::$districts[ $code ] ) ) {
                return $code;
            }
        }
        $k   = self::normalize( $state );
        $idx = self::index();
        if ( isset( $idx[ $k ] ) ) {
            return $idx[ $k ];
        }
        // "Dhaka Division", "Gazipur Sadar", "Chittagong City" etc.
        $first = explode( ' ', $k );
        if ( isset( $idx[ $first[0] ] ) ) {
            return $idx[ $first[0] ];
        }
        return '';
    }

    /**
     * Does any keyword occur in the text? Latin keywords match on word boundaries,
     * Bangla keywords as substrings.
     *
     * @return string The keyword that matched, or ''.
     */
    public static function match_area( $text, array $keywords ) {
        $hay = self::normalize( $text );
        if ( '' === $hay || empty( $keywords ) ) {
            return '';
        }
        $padded = ' ' . $hay . ' ';
        foreach ( $keywords as $kw ) {
            $n = self::normalize( $kw );
            if ( '' === $n ) {
                continue;
            }
            if ( preg_match( '/^[a-z0-9 ]+$/', $n ) ) {
                if ( false !== strpos( $padded, ' ' . $n . ' ' ) ) {
                    return (string) $kw;
                }
            } elseif ( false !== strpos( $hay, $n ) ) {
                return (string) $kw;
            }
        }
        return '';
    }

    /**
     * Resolve the delivery zone for a destination.
     *
     * @param array $dest country, state, city, address / address_1, address_2, postcode.
     * @return array { zone: dhaka|nearby|outside|'' , district: code, area: matched keyword, reason: string }
     */
    public static function zone( array $dest ) {
        $country = isset( $dest['country'] ) ? strtoupper( trim( (string) $dest['country'] ) ) : '';
        $state   = isset( $dest['state'] ) ? (string) $dest['state'] : '';
        $city    = isset( $dest['city'] ) ? (string) $dest['city'] : '';
        $addr    = trim( ( isset( $dest['address'] ) ? $dest['address'] : ( isset( $dest['address_1'] ) ? $dest['address_1'] : '' ) ) . ' ' . ( isset( $dest['address_2'] ) ? $dest['address_2'] : '' ) );

        $result = array(
            'zone'     => '',
            'district' => '',
            'area'     => '',
            'reason'   => '',
        );

        if ( RWSC_Settings::on( 'bd_only' ) && '' !== $country && 'BD' !== $country ) {
            $result['reason'] = 'not_bd';
            return $result;
        }

        $district = self::resolve_district( $state );
        if ( '' === $district && '' !== $city ) {
            $district = self::resolve_district( $city );
        }
        $result['district'] = $district;

        $area_text = $city . ( RWSC_Settings::on( 'scan_address' ) ? ' ' . $addr : '' );

        $hit = self::match_area( $area_text, (array) RWSC_Settings::get( 'dhaka_areas', array() ) );
        if ( '' !== $hit ) {
            return array_merge( $result, array( 'zone' => 'dhaka', 'area' => $hit, 'reason' => 'dhaka_area' ) );
        }

        if ( self::DHAKA === $district || '' === $district ) {
            $hit = self::match_area( $area_text, (array) RWSC_Settings::get( 'nearby_areas', array() ) );
            if ( '' !== $hit ) {
                return array_merge( $result, array( 'zone' => 'nearby', 'area' => $hit, 'reason' => 'nearby_area' ) );
            }
        }

        if ( self::DHAKA === $district ) {
            return array_merge( $result, array( 'zone' => 'dhaka', 'reason' => 'district' ) );
        }

        if ( '' !== $district && in_array( $district, (array) RWSC_Settings::get( 'nearby_districts', array() ), true ) ) {
            return array_merge( $result, array( 'zone' => 'nearby', 'reason' => 'nearby_district' ) );
        }

        if ( '' === $district && '' !== self::match_area( $city, array( 'Dhaka', 'ঢাকা' ) ) ) {
            return array_merge( $result, array( 'zone' => 'dhaka', 'district' => self::DHAKA, 'reason' => 'city' ) );
        }

        $result['zone']   = 'outside';
        $result['reason'] = '' === $district ? 'unknown' : 'district';
        return $result;
    }

    public static function zone_label( $zone ) {
        $labels = array(
            'dhaka'   => __( 'Inside Dhaka', 'rar-woo-smart-courier' ),
            'nearby'  => __( 'Nearby Dhaka', 'rar-woo-smart-courier' ),
            'outside' => __( 'Outside Dhaka', 'rar-woo-smart-courier' ),
        );
        return isset( $labels[ $zone ] ) ? $labels[ $zone ] : '';
    }

    public static function zone_labels() {
        return array(
            'dhaka'   => self::zone_label( 'dhaka' ),
            'nearby'  => self::zone_label( 'nearby' ),
            'outside' => self::zone_label( 'outside' ),
        );
    }
}
