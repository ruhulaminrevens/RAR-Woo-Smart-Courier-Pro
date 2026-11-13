<?php
/**
 * Option migration regression tests: php tests/migration-test.php
 * No WordPress bootstrap, HTTP or real credentials required.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['test_options'] = array();
function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['test_options'][ $key ] = $value;
    return true;
}
function wp_salt( $key = '' ) { return 'fixed-test-salt-' . $key; }
class RWSC_Pathao_API { const TOKEN_OPTION = 'rwsc_pathao_token'; }
require dirname( __DIR__ ) . '/includes/dispatch/class-rwsc-api-settings.php';

$checks = 0;
function check_true( $condition, $label ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: $label\n" );
        exit( 1 );
    }
}

$suite_secret = RWSC_Api_Settings::encrypt( 'secret-from-suite' );
$GLOBALS['test_options']['pt_hms_settings'] = array(
    'client_id' => 'suite-id',
    'client_secret' => $suite_secret,
    'webhook_secret' => 'suite-webhook-secret',
    'queue_statuses' => array( 'wc-processing' ),
    'sync_enabled' => 'yes',
);
$GLOBALS['test_options']['pt_hms_token_data'] = array( 'access_token' => 'legacy-test-token' );
$GLOBALS['test_options']['rwsc_api'] = array(
    'client_id' => 'pro-id',
    'client_secret' => '',
    'queue_statuses' => array( 'wc-on-hold' ),
);
check_true( RWSC_Api_Settings::maybe_import_suite( true ), 'fills blank Pro credentials' );
$settings = get_option( 'rwsc_api' );
check_true( 'pro-id' === $settings['client_id'], 'keeps existing Pro client ID' );
check_true( array( 'wc-on-hold' ) === $settings['queue_statuses'], 'preserves Pro array values' );
check_true( $suite_secret === $settings['client_secret'], 'reuses Suite encrypted credentials verbatim' );
check_true( 0 === strpos( $settings['webhook_secret'], 'rpc1:' ), 'encrypts imported webhook secret' );
check_true( 'suite-webhook-secret' === RWSC_Api_Settings::decrypt( $settings['webhook_secret'] ), 'webhook secret roundtrip' );
check_true( 'legacy-test-token' === get_option( RWSC_Pathao_API::TOKEN_OPTION )['access_token'], 'imports legacy token' );
check_true( false === RWSC_Api_Settings::maybe_import_suite( true ), 'reimport is idempotent' );
check_true( in_array( 'webhook_secret', RWSC_Api_Settings::SECRETS, true ), 'webhook secret encrypted on save' );

echo "Migration: $checks assertions passed\n";
