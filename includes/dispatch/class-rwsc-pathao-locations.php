<?php
/**
 * Bangladesh district / area resolver for Pathao city, zone and area IDs.
 *
 * WooCommerce stores the district as a state CODE (BD-13). Names exist in
 * English (several spellings) and Bangla. Every spelling of the 64 districts is
 * collapsed to one canonical key so WooCommerce, RAR Cart & Checkout and
 * Pathao's own city names always agree.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Pathao_Locations {

	/** @var array<string,string>|null normalised spelling => canonical key */
	private static $index = null;

	/**
	 * Canonical English key first, then Bangla and alternative spellings.
	 */
	private static function groups() {
		return array(
			array( 'Dhaka', 'ঢাকা' ),
			array( 'Gazipur', 'গাজীপুর' ),
			array( 'Narayanganj', 'নারায়ণগঞ্জ', 'Narayangonj' ),
			array( 'Narsingdi', 'নরসিংদী', 'Narshingdi' ),
			array( 'Manikganj', 'মানিকগঞ্জ' ),
			array( 'Munshiganj', 'মুন্সীগঞ্জ', 'Munsiganj' ),
			array( 'Kishoreganj', 'কিশোরগঞ্জ' ),
			array( 'Tangail', 'টাঙ্গাইল' ),
			array( 'Faridpur', 'ফরিদপুর' ),
			array( 'Gopalganj', 'গোপালগঞ্জ' ),
			array( 'Madaripur', 'মাদারীপুর' ),
			array( 'Rajbari', 'রাজবাড়ী' ),
			array( 'Shariatpur', 'শরীয়তপুর' ),
			array( 'Chattogram', 'চট্টগ্রাম', 'Chittagong' ),
			array( "Cox's Bazar", 'কক্সবাজার', 'Coxs Bazar', 'Cox Bazar', 'Coxsbazar' ),
			array( 'Cumilla', 'কুমিল্লা', 'Comilla' ),
			array( 'Brahmanbaria', 'ব্রাহ্মণবাড়িয়া', 'Brahamanbaria' ),
			array( 'Chandpur', 'চাঁদপুর' ),
			array( 'Lakshmipur', 'লক্ষ্মীপুর', 'Laxmipur' ),
			array( 'Noakhali', 'নোয়াখালী' ),
			array( 'Feni', 'ফেনী' ),
			array( 'Khagrachhari', 'খাগড়াছড়ি', 'Khagrachari' ),
			array( 'Rangamati', 'রাঙ্গামাটি' ),
			array( 'Bandarban', 'বান্দরবান' ),
			array( 'Rajshahi', 'রাজশাহী' ),
			array( 'Naogaon', 'নওগাঁ' ),
			array( 'Natore', 'নাটোর' ),
			array( 'Chapainawabganj', 'চাঁপাইনবাবগঞ্জ', 'Chapainababganj', 'Nawabganj', 'Chapai Nawabganj', 'Chapai' ),
			array( 'Pabna', 'পাবনা' ),
			array( 'Sirajganj', 'সিরাজগঞ্জ' ),
			array( 'Bogura', 'বগুড়া', 'Bogra' ),
			array( 'Joypurhat', 'জয়পুরহাট', 'Jaipurhat' ),
			array( 'Khulna', 'খুলনা' ),
			array( 'Bagerhat', 'বাগেরহাট' ),
			array( 'Satkhira', 'সাতক্ষীরা' ),
			array( 'Jashore', 'যশোর', 'Jessore' ),
			array( 'Jhenaidah', 'ঝিনাইদহ', 'Jhenaidaha' ),
			array( 'Narail', 'নড়াইল' ),
			array( 'Magura', 'মাগুরা' ),
			array( 'Kushtia', 'কুষ্টিয়া' ),
			array( 'Chuadanga', 'চুয়াডাঙ্গা' ),
			array( 'Meherpur', 'মেহেরপুর' ),
			array( 'Barishal', 'বরিশাল', 'Barisal' ),
			array( 'Bhola', 'ভোলা' ),
			array( 'Patuakhali', 'পটুয়াখালী' ),
			array( 'Pirojpur', 'পিরোজপুর' ),
			array( 'Jhalokati', 'ঝালকাঠি', 'Jhalokathi', 'Jhalakathi' ),
			array( 'Barguna', 'বরগুনা' ),
			array( 'Sylhet', 'সিলেট' ),
			array( 'Moulvibazar', 'মৌলভীবাজার', 'Maulvibazar', 'Moulvi Bazar' ),
			array( 'Habiganj', 'হবিগঞ্জ' ),
			array( 'Sunamganj', 'সুনামগঞ্জ' ),
			array( 'Rangpur', 'রংপুর' ),
			array( 'Dinajpur', 'দিনাজপুর' ),
			array( 'Gaibandha', 'গাইবান্ধা' ),
			array( 'Kurigram', 'কুড়িগ্রাম' ),
			array( 'Lalmonirhat', 'লালমনিরহাট' ),
			array( 'Nilphamari', 'নীলফামারী' ),
			array( 'Panchagarh', 'পঞ্চগড়' ),
			array( 'Thakurgaon', 'ঠাকুরগাঁও' ),
			array( 'Mymensingh', 'ময়মনসিংহ' ),
			array( 'Jamalpur', 'জামালপুর' ),
			array( 'Netrokona', 'নেত্রকোনা', 'Netrakona' ),
			array( 'Sherpur', 'শেরপুর' ),
		);
	}

	/**
	 * Lower-case, accent-free, letters/digits/Bangla marks only.
	 */
	public static function norm( $value ) {
		$value = remove_accents( (string) $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = str_replace( '&', 'and', $value );
		return (string) preg_replace( '/[^\p{L}\p{M}\p{N}]+/u', '', $value );
	}

	private static function index() {
		if ( null === self::$index ) {
			self::$index = array();
			foreach ( self::groups() as $group ) {
				$key = self::norm( $group[0] );
				foreach ( $group as $spelling ) {
					self::$index[ self::norm( $spelling ) ] = $key;
				}
			}
		}
		return self::$index;
	}

	/**
	 * Any district spelling (or BD-xx code) to its canonical key; '' if unknown.
	 */
	public static function canonical_district( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$name = self::state_label( $value );
		$idx  = self::index();
		$n    = self::norm( $name );
		return $idx[ $n ] ?? '';
	}

	/**
	 * Human district name from a WooCommerce state code (BD-13 => Dhaka).
	 */
	public static function state_label( $state, $country = 'BD' ) {
		$state = trim( (string) $state );
		if ( preg_match( '/^[A-Z]{2}-?\d+$/', $state ) && function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( $country );
			if ( is_array( $states ) ) {
				if ( isset( $states[ $state ] ) ) {
					return (string) $states[ $state ];
				}
				// Codes may arrive without the country prefix.
				$prefixed = $country . '-' . ltrim( preg_replace( '/^[A-Z]{2}-?/', '', $state ), '-' );
				if ( isset( $states[ $prefixed ] ) ) {
					return (string) $states[ $prefixed ];
				}
			}
		}
		return $state;
	}

	/**
	 * Find the Pathao city entry for a district. Matches by canonical key so
	 * "Chattogram" (WooCommerce) finds "Chittagong" (Pathao).
	 *
	 * @param string $district Name or state code.
	 * @return array|null array( id, name )
	 */
	public static function find_city( $district ) {
		$want = self::canonical_district( $district );
		if ( '' === $want ) {
			$want = self::norm( self::state_label( $district ) );
		}
		if ( '' === $want ) {
			return null;
		}
		$cities = RWSC_Pathao_API::cities();
		if ( is_wp_error( $cities ) || ! is_array( $cities ) ) {
			return null;
		}
		$idx = self::index();
		foreach ( $cities as $c ) {
			$n = self::norm( $c['name'] );
			if ( ( $idx[ $n ] ?? $n ) === $want ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Best unique match of any hint string against a list of named entries.
	 *
	 * @param array    $entries array( array( id, name ) ... ).
	 * @param string[] $hints   Strings from the order address, strongest first.
	 * @return array|null
	 */
	public static function best_match( array $entries, array $hints ) {
		$norm_hints = array();
		foreach ( $hints as $h ) {
			$n = self::norm( $h );
			if ( '' !== $n ) {
				$norm_hints[] = $n;
			}
		}
		if ( ! $norm_hints ) {
			return null;
		}

		$best  = 0;
		$found = array();
		foreach ( $entries as $e ) {
			$en = self::norm( $e['name'] );
			if ( strlen( $en ) < 3 ) {
				continue;
			}
			$score = 0;
			foreach ( $norm_hints as $i => $hn ) {
				$weight = 0 === $i ? 10 : 0; // The first hint is the structured area/thana field.
				if ( $en === $hn ) {
					$score = max( $score, 100 + $weight );
				} elseif ( strlen( $en ) >= 4 && false !== strpos( $hn, $en ) ) {
					$score = max( $score, 80 + $weight );
				} elseif ( strlen( $hn ) >= 4 && false !== strpos( $en, $hn ) ) {
					$score = max( $score, 60 + $weight );
				}
			}
			if ( $score > $best ) {
				$best  = $score;
				$found = array( $e );
			} elseif ( $score === $best && $score > 0 ) {
				$found[] = $e;
			}
		}
		if ( $best < 80 || ! $found ) {
			return null;
		}
		if ( count( $found ) > 1 ) {
			// Several equally good: prefer the longest (most specific) name, only if unique.
			usort(
				$found,
				static function ( $a, $b ) {
					return strlen( $b['name'] ) <=> strlen( $a['name'] );
				}
			);
			if ( strlen( $found[0]['name'] ) === strlen( $found[1]['name'] ) ) {
				return null;
			}
		}
		return $found[0] + array( 'score' => $best );
	}

	/**
	 * Resolve Pathao city/zone/area for a WooCommerce order.
	 *
	 * @param WC_Order $order Order.
	 * @return array city_id, zone_id, area_id, *_name, confidence (none|city|zone|area)
	 */
	public static function resolve_for_order( WC_Order $order ) {
		$out = array(
			'city_id'    => 0,
			'zone_id'    => 0,
			'area_id'    => 0,
			'city_name'  => '',
			'zone_name'  => '',
			'area_name'  => '',
			'confidence' => 'none',
			'source'     => '',
		);

		// 1. IDs captured at checkout by the official plugin's address fields.
		foreach ( array( 'shipping', 'billing' ) as $type ) {
			$city = (int) $order->get_meta( '_' . $type . '_pathao_city' );
			if ( $city ) {
				$out['city_id']    = $city;
				$out['zone_id']    = (int) $order->get_meta( '_' . $type . '_pathao_zone' );
				$out['area_id']    = (int) $order->get_meta( '_' . $type . '_pathao_area' );
				$out['confidence'] = $out['area_id'] ? 'area' : ( $out['zone_id'] ? 'zone' : 'city' );
				$out['source']     = 'checkout';
				return $out;
			}
		}

		// 2. Match from the address using cached Pathao metadata.
		$state = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		$city  = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		$addr2 = $order->get_shipping_address_2() ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$addr1 = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1();

		$pcity = self::find_city( $state );
		if ( ! $pcity ) {
			return $out;
		}
		$out['city_id']    = (int) $pcity['id'];
		$out['city_name']  = $pcity['name'];
		$out['confidence'] = 'city';
		$out['source']     = 'address';

		$zones = RWSC_Pathao_API::zones( $pcity['id'] );
		if ( is_wp_error( $zones ) || ! is_array( $zones ) ) {
			return $out;
		}
		$zone = self::best_match( $zones, array( $city, $addr2, $addr1 ) );
		if ( ! $zone ) {
			return $out;
		}
		$out['zone_id']    = (int) $zone['id'];
		$out['zone_name']  = $zone['name'];
		$out['confidence'] = 'zone';

		$areas = RWSC_Pathao_API::areas( $zone['id'] );
		if ( ! is_wp_error( $areas ) && is_array( $areas ) ) {
			$area = self::best_match( $areas, array( $addr2, $addr1 ) );
			if ( $area ) {
				$out['area_id']    = (int) $area['id'];
				$out['area_name']  = $area['name'];
				$out['confidence'] = 'area';
			}
		}
		return $out;
	}
}
