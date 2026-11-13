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
echo 'Dispatch ownership: safe read-only coexistence, and normal booking actions present' . PHP_EOL;
