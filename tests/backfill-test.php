<?php
/**
 * Resumable migration cursor regression: php tests/backfill-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['test_options'] = array();
$GLOBALS['scheduled'] = array();
$GLOBALS['orders'] = array();
$GLOBALS['ids_by_key'] = array( 'pathao_id' => array(), 'redx_id' => array() );
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['test_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['test_options'][ $k ] ); return true; }
function wp_next_scheduled( $name ) { return $GLOBALS['scheduled'][ $name ] ?? false; }
function wp_schedule_single_event( $time, $name ) { $GLOBALS['scheduled'][ $name ] = $time; return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {}
class WC_Order {
    private $id;
    private $meta = array();
    public function __construct( $id ) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
}
class RWSC_Couriers {
    public static function slugs() { return array( 'pathao', 'redx' ); }
    public static function id_key( $slug ) { return $slug . '_id'; }
    public static function parcel_info( WC_Order $order ) {
        return array( 'courier' => $order->get_id() <= 205 ? 'pathao' : 'redx',
            'id' => 'CN-' . $order->get_id(), 'raw' => 'queued',
            'group' => 'pending', 'final' => false );
    }
}
class RWSC_Pathao_Status { public static function slug( $raw ) { return $raw; } }
class RWSC_Logger { public static function debug( $message, $data = array() ) {} }
class RWSC_Shipments {
    public static $calls = 0;
    public static function get( WC_Order $order ) {
        return array( 'courier' => $order->get_meta( '_rwsc_courier' ),
            'tracking' => $order->get_meta( '_rwsc_tracking' ),
            'status' => $order->get_meta( '_rwsc_status' ) );
    }
    public static function update( WC_Order $order, array $changes, array $args = array() ) {
        if ( empty( $args['history'] ) || ! array_key_exists( 'notify', $args ) || false !== $args['notify'] ) {
            throw new RuntimeException( 'Historical import must be silent' );
        }
        foreach ( $changes as $key => $value ) { $order->update_meta_data( '_rwsc_' . $key, $value ); }
        ++self::$calls;
        return self::get( $order );
    }
}
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }
function wc_get_orders( $args ) {
    $ids = $GLOBALS['ids_by_key'][ $args['meta_key'] ] ?? array();
    return array_slice( $ids, ( $args['paged'] - 1 ) * $args['limit'], $args['limit'] );
}
for ( $id = 1; $id <= 278; ++$id ) {
    $GLOBALS['orders'][ $id ] = new WC_Order( $id );
    $GLOBALS['ids_by_key'][ $id <= 205 ? 'pathao_id' : 'redx_id' ][] = $id;
}
require dirname( __DIR__ ) . '/includes/dispatch/class-rwsc-bridge.php';
$scanned = 0;
$synced = 0;
for ( $run = 1; $run <= 8; ++$run ) {
    $result = RWSC_Bridge::backfill( 100 );
    $scanned += $result['scanned'];
    $synced += $result['synced'];
    if ( 1 === $run && ( $result['complete'] || get_option( 'rwsc_dispatch_backfill_done' ) ) ) {
        throw new RuntimeException( 'First 100 must not signal completion' );
    }
    if ( $result['complete'] ) { break; }
}
if ( $run !== 4 || $scanned !== 278 || $synced !== 278 || RWSC_Shipments::$calls !== 278
    || ! get_option( 'rwsc_dispatch_backfill_done' ) || get_option( 'rwsc_dispatch_backfill_cursor', false ) !== false ) {
    throw new RuntimeException( 'Bad resumable batch results: ' . json_encode( array( $run, $scanned, $synced, RWSC_Shipments::$calls ) ) );
}
echo "Backfill: 278 orders / 4 pages, silent, cursor completes correctly\n";
