<?php
/**
 * Sundarban Courier module — MANUAL (no API).
 *
 * Sundarban publishes no merchant booking API that we could find; merchants book through the
 * Sundarban portal / "Sundarban Merchant" app and receive a CN (consignment note) number. The only
 * network surface we saw is an undocumented website endpoint that needs an embedded key — we do not
 * use it. So this module records the CN against the order, keeps the one-courier-per-order and
 * duplicate-CN guards, tracks the status the staff set, links to Sundarban's public tracking page and
 * includes the order in the same dashboards / customer tracking block.
 * If Sundarban gives you API access later, hook the filter `rpc_sundarban_status` (cn, order) -> raw status.
 *
 * @package RAR_Woo_Smart_Courier
 */

defined( 'ABSPATH' ) || exit;

final class RWSC_Sundarban extends RWSC_Courier_Base {

	public function slug() {
		return 'sundarban';
	}

	public function prefix() {
		return 'sdb';
	}

	public function label() {
		return 'Sundarban';
	}

	public function legacy_id_key() {
		return '';
	}

	public function manual() {
		return true;
	}

	public function configured() {
		return true; // Nothing to connect.
	}

	/** slug => array( label, group, final ) */
	public static function statuses() {
		return array(
			'booked'     => array( 'Booked', 'pending', false ),
			'in_transit' => array( 'In transit', 'transit', false ),
			'hold'       => array( 'On hold / problem', 'attention', false ),
			'delivered'  => array( 'Delivered', 'delivered', true ),
			'returned'   => array( 'Returned', 'returned', true ),
			'cancelled'  => array( 'Cancelled', 'returned', true ),
		);
	}

	public static function clean_cn( $cn ) {
		return preg_replace( '/[^A-Za-z0-9\-_\/]/', '', trim( (string) $cn ) );
	}

	public function build( WC_Order $order ) {
		$c = $this->common( $order );
		return array(
			'name'    => $c['name'],
			'phone'   => $c['phone'],
			'address' => $c['address'],
			'invoice' => $c['invoice'],
			'cod'     => $c['cod'],
			'cn'      => '',
		);
	}

	public function validate( array $f ) {
		$e  = array();
		$cn = self::clean_cn( $f['cn'] ?? '' );
		if ( strlen( $cn ) < 5 || strlen( $cn ) > 40 ) {
			$e['cn'] = __( 'Enter the Sundarban CN (consignment note) number, 5–40 characters.', 'rar-woo-smart-courier' );
		} else {
			$dup = $this->find_by_consignment( $cn );
			if ( $dup ) {
				/* translators: %s: order number */
				$e['cn'] = sprintf( __( 'This CN is already recorded on order #%s.', 'rar-woo-smart-courier' ), $dup->get_order_number() );
			}
		}
		return $e;
	}

	protected function api_create( array $f, WC_Order $order ) {
		$cn = self::clean_cn( $f['cn'] );
		return array( 'id' => $cn, 'ref' => $cn, 'status' => 'booked' );
	}

	protected function api_status( WC_Order $order ) {
		$raw = apply_filters( 'rpc_sundarban_status', '', $this->consignment( $order ), $order );
		if ( '' === (string) $raw ) {
			return new WP_Error( 'rwsc_manual', __( 'Sundarban has no API: update the status by hand.', 'rar-woo-smart-courier' ) );
		}
		return array( 'status' => (string) $raw, 'ts' => 0 );
	}

	public function normalise( $raw ) {
		$slug = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', (string) $raw ), '_' ) );
		$map  = self::statuses();
		if ( isset( $map[ $slug ] ) ) {
			return array( 'slug' => $slug, 'label' => $map[ $slug ][0], 'group' => $map[ $slug ][1], 'final' => $map[ $slug ][2] );
		}
		return $this->keyword_normalise( $raw );
	}

	public function tracking_url( WC_Order $order ) {
		$cid = $this->consignment( $order );
		if ( '' === $cid ) {
			return '';
		}
		return strtr( (string) RWSC_Api_Settings::get( 'sd_tracking_template' ), array( '{id}' => rawurlencode( $cid ) ) );
	}

	protected function manual_controls( WC_Order $order ) {
		$cur = $this->normalise( $this->get( $order, 'status' ) )['slug'];
		$h   = ' <select class="rwsc-d-cm" data-courier="sundarban" data-order-id="' . esc_attr( $order->get_id() ) . '">';
		foreach ( self::statuses() as $k => $m ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $m[0] ) . '</option>';
		}
		return $h . '</select>';
	}

	/** Staff set the status. Allowed to correct a final status (source manual_edit). */
	public function set_status( WC_Order $order, $slug ) {
		$slug = sanitize_key( $slug );
		if ( ! $this->is_sent( $order ) || ! isset( self::statuses()[ $slug ] ) ) {
			return new WP_Error( 'rwsc_bad_status', __( 'Unknown status or no CN recorded.', 'rar-woo-smart-courier' ) );
		}
		$order->update_meta_data( $this->key( 'last_sync' ), time() );
		$order->save_meta_data();
		$this->apply_status( $order, $slug, 0, 'manual_edit' );
		return true;
	}
}
