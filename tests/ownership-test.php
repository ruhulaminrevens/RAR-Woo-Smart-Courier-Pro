<?php
/** Standalone AJAX ownership test: php tests/ownership-test.php */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
function add_action( $name, $callback ) { $GLOBALS['hooks'][] = $name; }
require dirname( __DIR__ ) . '/includes/dispatch/class-rwsc-dispatch-ajax.php';
RWSC_Dispatch_Ajax::init( true );
$expected = array( 'wp_ajax_rwsc_d_locations', 'wp_ajax_rwsc_d_price', 'wp_ajax_rwsc_d_test', 'wp_ajax_rwsc_d_courier_test' );
sort( $expected ); sort( $GLOBALS['hooks'] );
if ( $GLOBALS['hooks'] !== $expected ) {
    fwrite( STDERR, 'FAIL: coexistence AJAX handlers must be read-only: ' . json_encode( $GLOBALS['hooks'] ) . PHP_EOL );
    exit( 1 );
}
$GLOBALS['hooks'] = array();
RWSC_Dispatch_Ajax::init();
foreach ( array( 'wp_ajax_rwsc_d_send', 'wp_ajax_rwsc_d_send_native', 'wp_ajax_rwsc_d_courier_send', 'wp_ajax_rwsc_d_courier_status' ) as $hook ) {
    if ( ! in_array( $hook, $GLOBALS['hooks'], true ) ) {
        fwrite( STDERR, 'FAIL: dispatch mode missing ' . $hook . PHP_EOL );
        exit( 1 );
    }
}
class RWSC_Dispatch {
    public static function conflicting_dispatch_active() { return true; }
}
class RWSC_Dispatch_Orders_UI {
    public static $assets_count = 0;
    public static function assets() { ++self::$assets_count; }
}
function wp_unslash( $v ) { return $v; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $v ) ); }
require dirname( __DIR__ ) . '/includes/dispatch/class-rwsc-dispatch-admin.php';
$GLOBALS['hooks'] = array();
RWSC_Dispatch_Admin::init();
if ( ! in_array( 'admin_menu', $GLOBALS['hooks'], true ) || ! in_array( 'admin_enqueue_scripts', $GLOBALS['hooks'], true ) ) {
    throw new RuntimeException( 'Migration menus and assets must be registered during Suite coexistence' );
}
$_GET['page'] = 'rwsc-tools';
RWSC_Dispatch_Admin::assets_when_conflicted();
$_GET['page'] = 'wc-orders';
RWSC_Dispatch_Admin::assets_when_conflicted();
if ( 1 !== RWSC_Dispatch_Orders_UI::$assets_count ) {
    throw new RuntimeException( 'Coexistence must load assets only for tools/settings, not WooCommerce orders' );
}
echo 'Dispatch ownership: safe read-only coexistence, accessible tools/assets, and normal booking actions present' . PHP_EOL;
