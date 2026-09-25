<?php
/**
 * Public helper API for themes and other plugins.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'rwsc_get_quotes' ) ) {
    /**
     * Courier quotes for an address.
     *
     * @param array      $destination country, state (BD-13 / "Dhaka"), city, address_1, address_2.
     * @param float|null $weight_kg   Parcel weight in kg (null = fallback weight).
     * @param bool       $free        Apply free-shipping presentation.
     * @return array { zone, zone_label, district, district_name, weight, quotes[] }
     */
    function rwsc_get_quotes( array $destination, $weight_kg = null, $free = false ) {
        return RWSC_Engine::quotes(
            array(
                'destination' => $destination,
                'weight'      => $weight_kg,
                'free'        => (bool) $free,
            )
        );
    }
}

if ( ! function_exists( 'rwsc_get_shipment' ) ) {
    /**
     * Shipment data (courier, tracking, status, ETA …) for an order.
     *
     * @param WC_Order|int $order Order.
     * @return array|null
     */
    function rwsc_get_shipment( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        return ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) ? RWSC_Shipments::get( $order ) : null;
    }
}

if ( ! function_exists( 'rwsc_update_shipment' ) ) {
    /**
     * Update courier / tracking / status / note for an order.
     *
     * @param WC_Order|int $order   Order.
     * @param array        $changes courier, tracking, status, note.
     * @param array        $args    notify (bool|null).
     * @return array|WP_Error
     */
    function rwsc_update_shipment( $order, array $changes, array $args = array() ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
            return new WP_Error( 'rwsc_order', __( 'Order not found.', 'rar-woo-smart-courier' ) );
        }
        return RWSC_Shipments::update( $order, $changes, $args );
    }
}
