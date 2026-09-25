<?php
/**
 * Admin menus, pages, assets and settings handlers.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Admin {

    const SETTINGS_SLUG = 'rar-woo-smart-courier';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_rwsc_save_settings', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_rwsc_import_settings', array( __CLASS__, 'handle_import' ) );
        add_action( 'admin_post_rwsc_reset_settings', array( __CLASS__, 'handle_reset' ) );
        add_action( 'admin_post_rwsc_create_tracking_page', array( __CLASS__, 'handle_tracking_page' ) );
        add_action( 'admin_notices', array( __CLASS__, 'test_mode_notice' ) );
    }

    public static function view_cap() {
        return apply_filters( 'rwsc_view_capability', 'edit_shop_orders' );
    }

    public static function manage_cap() {
        return apply_filters( 'rwsc_manage_capability', 'manage_woocommerce' );
    }

    public static function menu() {
        $icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
        add_menu_page( __( 'Smart Courier', 'rar-woo-smart-courier' ), __( 'Smart Courier', 'rar-woo-smart-courier' ), self::view_cap(), 'rwsc-dashboard', array( __CLASS__, 'render_dashboard' ), $icon, 56 );
        add_submenu_page( 'rwsc-dashboard', __( 'Courier Dashboard', 'rar-woo-smart-courier' ), __( 'Dashboard', 'rar-woo-smart-courier' ), self::view_cap(), 'rwsc-dashboard', array( __CLASS__, 'render_dashboard' ) );
        $count = self::ready_count();
        $badge = $count ? ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>' : '';
        add_submenu_page( 'rwsc-dashboard', __( 'Shipments', 'rar-woo-smart-courier' ), __( 'Shipments', 'rar-woo-smart-courier' ) . $badge, self::view_cap(), 'rwsc-shipments', array( __CLASS__, 'render_shipments' ) );
        add_submenu_page( 'rwsc-dashboard', __( 'Smart Courier Settings', 'rar-woo-smart-courier' ), __( 'Settings', 'rar-woo-smart-courier' ), self::manage_cap(), self::SETTINGS_SLUG, array( __CLASS__, 'render_settings' ) );
    }

    /**
     * Orders waiting to be booked (cached for 5 minutes, for the menu badge).
     */
    public static function ready_count() {
        $cached = get_transient( 'rwsc_ready_count' );
        if ( false !== $cached ) {
            return (int) $cached;
        }
        $n = 0;
        try {
            $r = RWSC_Reports::shipments( array( 'tab' => 'ready', 'days' => 30, 'per_page' => 5 ) );
            $n = (int) $r['tabs']['ready'];
        } catch ( Exception $e ) {
            $n = 0;
        }
        set_transient( 'rwsc_ready_count', $n, 3 * MINUTE_IN_SECONDS );
        return $n;
    }

    public static function is_plugin_screen() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return in_array( $page, array( 'rwsc-dashboard', 'rwsc-shipments', self::SETTINGS_SLUG ), true ) ? $page : '';
    }

    public static function assets( $hook ) {
        $page     = self::is_plugin_screen();
        $order_ui = function_exists( 'wc_get_page_screen_id' ) && function_exists( 'get_current_screen' ) && get_current_screen() && in_array( get_current_screen()->id, array( wc_get_page_screen_id( 'shop-order' ), 'shop_order', 'edit-shop_order' ), true );
        if ( ! $page && ! $order_ui ) {
            return;
        }
        wp_enqueue_style( 'rwsc-admin', RWSC_URL . 'assets/css/admin.css', array(), RWSC_VERSION );
        if ( ! $page ) {
            return;
        }
        wp_enqueue_script( 'rwsc-admin', RWSC_URL . 'assets/js/admin.js', array(), RWSC_VERSION, true );
        wp_add_inline_script( 'rwsc-admin', 'window.RWSC = ' . wp_json_encode( self::js_config( $page ) ) . ';', 'before' );
    }

    public static function currency_symbol() {
        return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
    }

    public static function js_config( $page ) {
        $couriers = array();
        foreach ( RWSC_Settings::couriers() as $key => $c ) {
            $couriers[] = array(
                'key'      => $key,
                'name'     => '' !== $c['name'] ? $c['name'] : ucfirst( $key ),
                'color'    => RWSC_Settings::color( $c['color'] ),
                'enabled'  => 'yes' === $c['enabled'] && '' !== $c['name'],
                'tracking' => '' !== (string) $c['tracking_url'],
            );
        }
        $statuses = array();
        foreach ( RWSC_Shipments::statuses() as $k => $st ) {
            $statuses[] = array( 'key' => $k, 'label' => $st['label'], 'short' => $st['short'], 'color' => $st['color'] );
        }
        $districts = array();
        foreach ( RWSC_Locations::districts() as $d ) {
            $districts[] = array( $d['code'], $d['name'], $d['bn'], $d['division'] );
        }
        $offset = wp_timezone()->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) );

        return array(
            'page'       => $page,
            'ajax'       => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'rwsc_admin' ),
            'version'    => RWSC_VERSION,
            'canManage'  => current_user_can( self::manage_cap() ),
            'currency'   => array(
                'symbol'   => self::currency_symbol(),
                'pos'      => get_option( 'woocommerce_currency_pos', 'left' ),
                'decimals' => wc_get_price_decimals(),
                'code'     => get_woocommerce_currency(),
            ),
            'couriers'   => $couriers,
            'statuses'   => $statuses,
            'zones'      => RWSC_Locations::zone_labels(),
            'districts'  => $districts,
            'engine'     => array(
                'enabled'  => RWSC_Settings::on( 'enabled' ),
                'testMode' => RWSC_Settings::on( 'test_mode' ),
                'hpos'     => RWSC_Reports::hpos(),
            ),
            'display'    => array(
                'style'     => RWSC_Settings::get( 'label_style' ),
                'showEta'   => RWSC_Settings::on( 'show_eta' ),
                'showEdd'   => RWSC_Settings::on( 'show_edd' ),
                'badges'    => RWSC_Settings::on( 'show_badges' ),
                'badgeText' => array(
                    'recommended' => RWSC_Settings::get( 'badge_rec' ),
                    'fastest'     => RWSC_Settings::get( 'badge_fast' ),
                    'cheapest'    => RWSC_Settings::get( 'badge_cheap' ),
                ),
            ),
            'tzOffset'   => $offset,
            'ready'      => array_values( (array) RWSC_Settings::get( 'ready_statuses', array( 'processing' ) ) ),
            'staleDays'  => (int) RWSC_Settings::get( 'stale_days', 2 ),
            'shopName'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'urls'       => array(
                'dashboard' => admin_url( 'admin.php?page=rwsc-dashboard' ),
                'shipments' => admin_url( 'admin.php?page=rwsc-shipments' ),
                'settings'  => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
                'orders'    => RWSC_Reports::hpos() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' ),
                'print'     => RWSC_Print::url( 'labels', array() ),
                'manifest'  => RWSC_Print::url( 'manifest', array() ),
                'csv'       => RWSC_Print::url( 'csv', array() ),
                'newOrder'  => RWSC_Reports::hpos() ? admin_url( 'admin.php?page=wc-orders&action=new' ) : admin_url( 'post-new.php?post_type=shop_order' ),
            ),
            'health'     => self::health(),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Health checks                                                       */
    /* ------------------------------------------------------------------ */

    public static function health() {
        $out = array();
        if ( ! RWSC_Settings::on( 'enabled' ) ) {
            $out[] = array( 'level' => 'bad', 'title' => __( 'Courier engine is OFF', 'rar-woo-smart-courier' ), 'text' => __( 'Customers see your normal WooCommerce shipping methods.', 'rar-woo-smart-courier' ), 'link' => 'checkout' );
        } elseif ( RWSC_Settings::on( 'test_mode' ) ) {
            $out[] = array( 'level' => 'warn', 'title' => __( 'Safe Test Mode is ON', 'rar-woo-smart-courier' ), 'text' => __( 'Only shop managers see courier options at checkout. Turn it off when you are happy with the rates.', 'rar-woo-smart-courier' ), 'link' => 'checkout' );
        }
        if ( ! RWSC_Settings::couriers( true ) ) {
            $out[] = array( 'level' => 'bad', 'title' => __( 'No courier is enabled', 'rar-woo-smart-courier' ), 'text' => __( 'Enable at least one courier and give it a name.', 'rar-woo-smart-courier' ), 'link' => 'couriers' );
        }

        // A zone that covers Bangladesh with a usable base method.
        $covered = false;
        $has_base = false;
        if ( class_exists( 'WC_Shipping_Zones' ) ) {
            $zones   = WC_Shipping_Zones::get_zones();
            $zones[] = array( 'zone_id' => 0 );
            foreach ( $zones as $z ) {
                $zone = WC_Shipping_Zones::get_zone( (int) $z['zone_id'] );
                if ( ! $zone ) {
                    continue;
                }
                $locs   = $zone->get_zone_locations();
                $has_bd = 0 === (int) $z['zone_id'];
                foreach ( $locs as $l ) {
                    if ( ( 'country' === $l->type && 'BD' === $l->code ) || ( 'state' === $l->type && 0 === strpos( $l->code, 'BD:' ) ) || 'continent' === $l->type && 'AS' === $l->code ) {
                        $has_bd = true;
                    }
                }
                if ( ! $has_bd ) {
                    continue;
                }
                foreach ( $zone->get_shipping_methods( true ) as $m ) {
                    $covered = true;
                    if ( ! in_array( $m->id, array( 'local_pickup', 'pickup_location' ), true ) ) {
                        $has_base = true;
                    }
                }
            }
        }
        if ( ! $has_base ) {
            $out[] = array(
                'level' => 'bad',
                'title' => __( 'No shipping method for Bangladesh', 'rar-woo-smart-courier' ),
                'text'  => $covered ? __( 'Your Bangladesh zone only has pickup. Add a "Flat rate" method – couriers replace it at checkout.', 'rar-woo-smart-courier' ) : __( 'Create a WooCommerce shipping zone for Bangladesh with a "Flat rate" method (and optionally "Free shipping"). Couriers replace the flat rate at checkout.', 'rar-woo-smart-courier' ),
                'url'   => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
            );
        }
        $allowed = WC()->countries ? WC()->countries->get_shipping_countries() : array();
        if ( $allowed && ! isset( $allowed['BD'] ) ) {
            $out[] = array( 'level' => 'bad', 'title' => __( 'Bangladesh is not a shipping country', 'rar-woo-smart-courier' ), 'text' => __( 'WooCommerce → Settings → General → "Shipping location(s)" must include Bangladesh.', 'rar-woo-smart-courier' ), 'url' => admin_url( 'admin.php?page=wc-settings&tab=general' ) );
        }

        $unit = get_option( 'woocommerce_weight_unit', 'kg' );
        if ( 'kg' !== $unit ) {
            /* translators: %s: weight unit */
            $out[] = array( 'level' => 'info', 'title' => sprintf( __( 'Product weights are in %s', 'rar-woo-smart-courier' ), $unit ), 'text' => __( 'They are converted to kg automatically for courier pricing.', 'rar-woo-smart-courier' ) );
        }
        $missing = self::products_without_weight();
        if ( $missing > 0 ) {
            /* translators: 1: number of products, 2: fallback kg */
            $out[] = array( 'level' => 'info', 'title' => sprintf( _n( '%d product has no weight', '%d products have no weight', $missing, 'rar-woo-smart-courier' ), $missing ), 'text' => sprintf( __( 'The fallback weight (%s kg per item) is used for them.', 'rar-woo-smart-courier' ), wc_format_localized_decimal( RWSC_Settings::get( 'fallback_weight' ) ) ), 'link' => 'zones' );
        }
        return $out;
    }

    public static function products_without_weight() {
        $cached = get_transient( 'rwsc_no_weight' );
        if ( false !== $cached ) {
            return (int) $cached;
        }
        global $wpdb;
        $n = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} w ON w.post_id = p.ID AND w.meta_key = '_weight'
             LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_virtual'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             AND ( w.meta_value IS NULL OR w.meta_value = '' OR w.meta_value = '0' )
             AND ( v.meta_value IS NULL OR v.meta_value <> 'yes' )"
        );
        set_transient( 'rwsc_no_weight', $n, HOUR_IN_SECONDS );
        return $n;
    }

    public static function test_mode_notice() {
        if ( ! current_user_can( self::manage_cap() ) || ! RWSC_Settings::on( 'enabled' ) || ! RWSC_Settings::on( 'test_mode' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings' ), true ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Smart Courier:</strong> ' . esc_html__( 'Safe Test Mode is ON – only shop managers see courier options at checkout.', 'rar-woo-smart-courier' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '#checkout' ) ) . '">' . esc_html__( 'Review settings', 'rar-woo-smart-courier' ) . '</a></p></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Pages                                                               */
    /* ------------------------------------------------------------------ */

    private static function hero( $title, $subtitle, $page ) {
        $engine = RWSC_Settings::on( 'enabled' ) ? ( RWSC_Settings::on( 'test_mode' ) ? 'test' : 'live' ) : 'off';
        $labels = array(
            'live' => __( 'Live at checkout', 'rar-woo-smart-courier' ),
            'test' => __( 'Safe Test Mode', 'rar-woo-smart-courier' ),
            'off'  => __( 'Engine off', 'rar-woo-smart-courier' ),
        );
        $nav = array(
            'rwsc-dashboard'    => __( 'Dashboard', 'rar-woo-smart-courier' ),
            'rwsc-shipments'    => __( 'Shipments', 'rar-woo-smart-courier' ),
            self::SETTINGS_SLUG => __( 'Settings', 'rar-woo-smart-courier' ),
        );
        ?>
        <header class="rwsc-hero">
            <div class="rwsc-hero-glow" aria-hidden="true"></div>
            <div class="rwsc-hero-row">
                <div class="rwsc-brand">
                    <span class="rwsc-logo" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg></span>
                    <div>
                        <h1><?php echo esc_html( $title ); ?> <span class="rwsc-ver">v<?php echo esc_html( RWSC_VERSION ); ?></span></h1>
                        <p><?php echo esc_html( $subtitle ); ?></p>
                    </div>
                </div>
                <div class="rwsc-hero-side">
                    <div class="rwsc-clock" id="rwsc-clock" aria-live="off"></div>
                    <span class="rwsc-engine rwsc-engine-<?php echo esc_attr( $engine ); ?>"><i></i><?php echo esc_html( $labels[ $engine ] ); ?></span>
                </div>
            </div>
            <nav class="rwsc-nav" aria-label="<?php esc_attr_e( 'Smart Courier', 'rar-woo-smart-courier' ); ?>">
                <?php
                foreach ( $nav as $slug => $label ) {
                    if ( self::SETTINGS_SLUG === $slug && ! current_user_can( self::manage_cap() ) ) {
                        continue;
                    }
                    printf( '<a href="%s" class="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), $slug === $page ? 'is-active' : '', esc_html( $label ) );
                }
                ?>
            </nav>
        </header>
        <?php
    }

    public static function render_dashboard() {
        if ( ! current_user_can( self::view_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'rar-woo-smart-courier' ) );
        }
        echo '<div class="wrap rwsc-wrap"><div class="rwsc-app">';
        self::hero( __( 'Smart Courier', 'rar-woo-smart-courier' ), __( 'Courier performance, dispatch queue and live rate calculator', 'rar-woo-smart-courier' ), 'rwsc-dashboard' );
        echo '<div id="rwsc-root" class="rwsc-root" data-page="dashboard"><div class="rwsc-loading"><span></span>' . esc_html__( 'Loading dashboard…', 'rar-woo-smart-courier' ) . '</div></div>';
        echo '<noscript><p>' . esc_html__( 'Please enable JavaScript to use the dashboard.', 'rar-woo-smart-courier' ) . '</p></noscript>';
        echo '</div></div>';
    }

    public static function render_shipments() {
        if ( ! current_user_can( self::view_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'rar-woo-smart-courier' ) );
        }
        echo '<div class="wrap rwsc-wrap"><div class="rwsc-app">';
        self::hero( __( 'Shipments', 'rar-woo-smart-courier' ), __( 'Book, track and hand over parcels courier by courier', 'rar-woo-smart-courier' ), 'rwsc-shipments' );
        echo '<div id="rwsc-root" class="rwsc-root" data-page="shipments"><div class="rwsc-loading"><span></span>' . esc_html__( 'Loading shipments…', 'rar-woo-smart-courier' ) . '</div></div>';
        echo '</div></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Settings page                                                       */
    /* ------------------------------------------------------------------ */

    private static function toggle( $name, $label, $help = '', $on = null ) {
        $on = null === $on ? RWSC_Settings::on( $name ) : $on;
        ?>
        <div class="rwsc-field rwsc-field-toggle">
            <input type="hidden" name="_flags[]" value="<?php echo esc_attr( $name ); ?>">
            <label class="rwsc-switch"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="yes" <?php checked( $on ); ?>><span aria-hidden="true"></span></label>
            <div><b><?php echo esc_html( $label ); ?></b><?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?></div>
        </div>
        <?php
    }

    private static function courier_card( $key, array $c, $is_template = false ) {
        $builtin = array_key_exists( $key, RWSC_Settings::builtin_couriers() );
        $n       = 'couriers[' . $key . ']';
        $zones   = RWSC_Locations::zone_labels();
        $color   = RWSC_Settings::color( $c['color'] );
        ?>
        <div class="rwsc-cc<?php echo 'yes' === $c['enabled'] ? '' : ' is-off'; ?>" data-key="<?php echo esc_attr( $key ); ?>" style="--c:<?php echo esc_attr( $color ); ?>">
            <div class="rwsc-cc-h">
                <label class="rwsc-switch" title="<?php esc_attr_e( 'Enabled', 'rar-woo-smart-courier' ); ?>"><input type="checkbox" class="rwsc-cc-on" name="<?php echo esc_attr( $n ); ?>[enabled]" value="yes" <?php checked( $c['enabled'], 'yes' ); ?>><span aria-hidden="true"></span></label>
                <input type="color" class="rwsc-cc-color" name="<?php echo esc_attr( $n ); ?>[color]" value="<?php echo esc_attr( $color ); ?>" aria-label="<?php esc_attr_e( 'Colour', 'rar-woo-smart-courier' ); ?>">
                <input type="text" class="rwsc-cc-name" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="<?php esc_attr_e( 'Courier name', 'rar-woo-smart-courier' ); ?>" aria-label="<?php esc_attr_e( 'Courier name', 'rar-woo-smart-courier' ); ?>">
                <label class="rwsc-mini"><?php esc_html_e( 'Priority', 'rar-woo-smart-courier' ); ?> <input type="number" min="1" max="999" name="<?php echo esc_attr( $n ); ?>[priority]" value="<?php echo esc_attr( (int) $c['priority'] ); ?>"></label>
                <?php if ( ! $builtin ) : ?>
                    <input type="hidden" class="rwsc-cc-delete" name="<?php echo esc_attr( $n ); ?>[_delete]" value="">
                    <button type="button" class="rwsc-icon-btn rwsc-cc-remove" title="<?php esc_attr_e( 'Remove courier', 'rar-woo-smart-courier' ); ?>" aria-label="<?php esc_attr_e( 'Remove courier', 'rar-woo-smart-courier' ); ?>">✕</button>
                <?php endif; ?>
            </div>
            <div class="rwsc-matrix-wrap">
                <table class="rwsc-matrix">
                    <thead><tr>
                        <th><?php esc_html_e( 'Zone', 'rar-woo-smart-courier' ); ?></th>
                        <th><?php esc_html_e( 'On', 'rar-woo-smart-courier' ); ?></th>
                        <th>≤ 1 kg</th><th>≤ 2 kg</th><th><?php esc_html_e( '+ per kg', 'rar-woo-smart-courier' ); ?></th>
                        <th><?php esc_html_e( 'Delivery time (ETA)', 'rar-woo-smart-courier' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $zones as $z => $zl ) : ?>
                        <tr>
                            <th><span class="rwsc-zdot rwsc-z-<?php echo esc_attr( $z ); ?>"></span><?php echo esc_html( $zl ); ?></th>
                            <td><input type="checkbox" name="<?php echo esc_attr( $n . '[' . $z . '_on]' ); ?>" value="yes" <?php checked( $c[ $z . '_on' ], 'yes' ); ?> aria-label="<?php echo esc_attr( $zl ); ?>"></td>
                            <?php foreach ( array( '1', '2', 'extra' ) as $f ) : ?>
                                <td><input type="number" min="0" step="0.01" inputmode="decimal" name="<?php echo esc_attr( $n . '[' . $z . '_' . $f . ']' ); ?>" value="<?php echo esc_attr( (float) $c[ $z . '_' . $f ] ); ?>"></td>
                            <?php endforeach; ?>
                            <td><input type="text" class="rwsc-eta" name="<?php echo esc_attr( $n . '[eta_' . $z . ']' ); ?>" value="<?php echo esc_attr( $c[ 'eta_' . $z ] ); ?>" placeholder="1–2 business days"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="rwsc-cc-f">
                <label class="rwsc-grow"><?php esc_html_e( 'Tracking link', 'rar-woo-smart-courier' ); ?>
                    <input type="text" name="<?php echo esc_attr( $n ); ?>[tracking_url]" value="<?php echo esc_attr( $c['tracking_url'] ); ?>" placeholder="https://courier.example/track?id={tracking}">
                </label>
                <label><?php esc_html_e( 'Max weight (kg, 0 = no limit)', 'rar-woo-smart-courier' ); ?>
                    <input type="number" min="0" step="0.1" name="<?php echo esc_attr( $n ); ?>[max_weight]" value="<?php echo esc_attr( (float) $c['max_weight'] ); ?>">
                </label>
            </div>
            <?php if ( ! $is_template ) : ?><span class="rwsc-cc-key"><?php echo esc_html( $key ); ?></span><?php endif; ?>
        </div>
        <?php
    }

    public static function render_settings() {
        if ( ! current_user_can( self::manage_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'rar-woo-smart-courier' ) );
        }
        $s        = RWSC_Settings::all();
        $couriers = RWSC_Settings::couriers();
        $notice   = isset( $_GET['rwsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['rwsc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $notices  = array(
            'saved'    => array( 'ok', __( 'Settings saved. Checkout rates were refreshed.', 'rar-woo-smart-courier' ) ),
            'imported' => array( 'ok', __( 'Settings imported.', 'rar-woo-smart-courier' ) ),
            'reset'    => array( 'ok', __( 'Settings reset to defaults.', 'rar-woo-smart-courier' ) ),
            'badjson'  => array( 'bad', __( 'Import failed: that is not a valid Smart Courier settings export.', 'rar-woo-smart-courier' ) ),
            'page'     => array( 'ok', __( 'Tracking page created.', 'rar-woo-smart-courier' ) ),
        );
        $tabs = array(
            'couriers' => __( 'Couriers & rates', 'rar-woo-smart-courier' ),
            'zones'    => __( 'Zones & weight', 'rar-woo-smart-courier' ),
            'checkout' => __( 'Checkout display', 'rar-woo-smart-courier' ),
            'dates'    => __( 'Delivery dates', 'rar-woo-smart-courier' ),
            'tracking' => __( 'Tracking & automation', 'rar-woo-smart-courier' ),
            'tools'    => __( 'Tools', 'rar-woo-smart-courier' ),
        );
        $districts = RWSC_Locations::districts();
        $by_div    = array();
        foreach ( $districts as $d ) {
            $by_div[ $d['division'] ][] = $d;
        }
        $order_statuses = wc_get_order_statuses();
        unset( $order_statuses['wc-cancelled'], $order_statuses['wc-refunded'], $order_statuses['wc-failed'], $order_statuses['wc-checkout-draft'], $order_statuses['wc-completed'] );
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $tracking_page = (int) get_option( 'rwsc_tracking_page_id', 0 );
        ?>
        <div class="wrap rwsc-wrap">
        <div class="rwsc-app rwsc-settings">
            <?php self::hero( __( 'Smart Courier settings', 'rar-woo-smart-courier' ), __( 'Rates, zones, delivery dates, checkout display and automation', 'rar-woo-smart-courier' ), self::SETTINGS_SLUG ); ?>
            <?php if ( $notice && isset( $notices[ $notice ] ) ) : ?>
                <div class="rwsc-flash rwsc-flash-<?php echo esc_attr( $notices[ $notice ][0] ); ?>" role="status"><?php echo esc_html( $notices[ $notice ][1] ); ?></div>
            <?php endif; ?>

            <nav class="rwsc-tabs" role="tablist">
                <?php foreach ( $tabs as $id => $label ) : ?>
                    <a href="#<?php echo esc_attr( $id ); ?>" role="tab" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rwsc-settings-form" class="rwsc-form">
                <input type="hidden" name="action" value="rwsc_save_settings">
                <input type="hidden" name="_tab" value="couriers" id="rwsc-tab-field">
                <?php wp_nonce_field( 'rwsc_save_settings' ); ?>

                <!-- Couriers -->
                <section class="rwsc-panel-tab" data-panel="couriers">
                    <div class="rwsc-sec-h">
                        <div><h2><?php esc_html_e( 'Couriers & rates', 'rar-woo-smart-courier' ); ?></h2>
                        <p><?php esc_html_e( 'Charges per parcel by zone and weight: up to 1 kg, up to 2 kg, then an extra amount for every started kg above 2 kg. Use the rates from your own courier contracts.', 'rar-woo-smart-courier' ); ?></p></div>
                        <button type="button" class="rwsc-btn rwsc-btn-ghost" id="rwsc-add-courier">＋ <?php esc_html_e( 'Add courier', 'rar-woo-smart-courier' ); ?></button>
                    </div>
                    <div class="rwsc-cc-grid" id="rwsc-cc-grid">
                        <?php
                        foreach ( $couriers as $key => $c ) {
                            self::courier_card( $key, $c );
                        }
                        ?>
                    </div>
                    <template id="rwsc-cc-tpl"><?php self::courier_card( '__KEY__', wp_parse_args( array( 'name' => '', 'priority' => 70, 'color' => '#0f766e' ), RWSC_Settings::courier_template() ), true ); ?></template>
                    <p class="rwsc-hint"><?php esc_html_e( 'A zone with no "≤ 1 kg" price (0) is not offered for that courier. Use WooCommerce Free shipping for free delivery.', 'rar-woo-smart-courier' ); ?></p>
                    <p class="rwsc-hint"><?php esc_html_e( 'Tracking link placeholders: {tracking} = tracking / consignment number, {phone} = customer phone (01XXXXXXXXX), {order} = order number.', 'rar-woo-smart-courier' ); ?></p>
                </section>

                <!-- Zones -->
                <section class="rwsc-panel-tab" data-panel="zones">
                    <div class="rwsc-sec-h"><div><h2><?php esc_html_e( 'Delivery zones', 'rar-woo-smart-courier' ); ?></h2>
                        <p><?php esc_html_e( 'Dhaka district = Inside Dhaka. Pick the districts that get the Nearby rate, and the areas inside Dhaka district (Savar, Keraniganj …) that couriers charge as Nearby.', 'rar-woo-smart-courier' ); ?></p></div></div>

                    <div class="rwsc-card">
                        <h3><?php esc_html_e( 'Nearby districts', 'rar-woo-smart-courier' ); ?> <span class="rwsc-count" id="rwsc-nd-count"></span></h3>
                        <input type="hidden" name="_lists[]" value="nearby_districts">
                        <input type="search" class="rwsc-input rwsc-filter" data-filter="#rwsc-districts" placeholder="<?php esc_attr_e( 'Search districts (English or বাংলা)…', 'rar-woo-smart-courier' ); ?>">
                        <div class="rwsc-divs" id="rwsc-districts">
                            <?php foreach ( RWSC_Locations::divisions() as $div ) : if ( empty( $by_div[ $div ] ) ) { continue; } ?>
                                <div class="rwsc-div"><h4><?php echo esc_html( $div ); ?></h4><div class="rwsc-chips">
                                <?php foreach ( $by_div[ $div ] as $d ) : $is_dhaka = RWSC_Locations::DHAKA === $d['code']; ?>
                                    <label class="rwsc-chip<?php echo $is_dhaka ? ' is-locked' : ''; ?>" data-s="<?php echo esc_attr( strtolower( $d['name'] ) . ' ' . $d['bn'] ); ?>">
                                        <input type="checkbox" name="nearby_districts[]" value="<?php echo esc_attr( $d['code'] ); ?>" <?php checked( in_array( $d['code'], (array) $s['nearby_districts'], true ) ); ?> <?php disabled( $is_dhaka ); ?>>
                                        <span><?php echo esc_html( $d['name'] ); ?> <small><?php echo esc_html( $d['bn'] ); ?></small><?php if ( $is_dhaka ) : ?> <em><?php esc_html_e( 'Inside Dhaka', 'rar-woo-smart-courier' ); ?></em><?php endif; ?></span>
                                    </label>
                                <?php endforeach; ?>
                                </div></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="rwsc-grid2">
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Nearby areas inside Dhaka district', 'rar-woo-smart-courier' ); ?></h3>
                            <input type="hidden" name="_lists[]" value="nearby_areas">
                            <textarea class="rwsc-tags" name="nearby_areas" rows="3" data-placeholder="<?php esc_attr_e( 'Add area and press Enter', 'rar-woo-smart-courier' ); ?>"><?php echo esc_textarea( implode( ', ', (array) $s['nearby_areas'] ) ); ?></textarea>
                            <small class="rwsc-help"><?php esc_html_e( 'Matched against the Town / City (and address lines when enabled below). English and বাংলা both work.', 'rar-woo-smart-courier' ); ?></small>
                        </div>
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Always "Inside Dhaka" areas', 'rar-woo-smart-courier' ); ?></h3>
                            <input type="hidden" name="_lists[]" value="dhaka_areas">
                            <textarea class="rwsc-tags" name="dhaka_areas" rows="3" data-placeholder="<?php esc_attr_e( 'e.g. Tongi, Uttara', 'rar-woo-smart-courier' ); ?>"><?php echo esc_textarea( implode( ', ', (array) $s['dhaka_areas'] ) ); ?></textarea>
                            <small class="rwsc-help"><?php esc_html_e( 'Optional. Areas outside Dhaka district that your couriers charge at the Inside Dhaka rate.', 'rar-woo-smart-courier' ); ?></small>
                        </div>
                    </div>

                    <div class="rwsc-grid2">
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Address matching', 'rar-woo-smart-courier' ); ?></h3>
                            <?php self::toggle( 'scan_address', __( 'Also look for area names in the address lines', 'rar-woo-smart-courier' ), __( 'Recommended – many customers write "Savar" in the address and "Dhaka" as the city.', 'rar-woo-smart-courier' ) ); ?>
                            <?php self::toggle( 'bd_only', __( 'Only for Bangladesh addresses', 'rar-woo-smart-courier' ), __( 'Other countries keep your normal WooCommerce rates.', 'rar-woo-smart-courier' ) ); ?>
                        </div>
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Parcel weight', 'rar-woo-smart-courier' ); ?></h3>
                            <div class="rwsc-row2">
                                <label class="rwsc-lbl"><?php esc_html_e( 'Fallback weight per item (kg)', 'rar-woo-smart-courier' ); ?>
                                    <input class="rwsc-input" type="number" min="0.01" step="0.01" name="fallback_weight" value="<?php echo esc_attr( $s['fallback_weight'] ); ?>"></label>
                                <label class="rwsc-lbl"><?php esc_html_e( 'Packaging weight per parcel (kg)', 'rar-woo-smart-courier' ); ?>
                                    <input class="rwsc-input" type="number" min="0" step="0.01" name="packaging_weight" value="<?php echo esc_attr( $s['packaging_weight'] ); ?>"></label>
                            </div>
                            <?php /* translators: %s: store weight unit */ ?>
                            <small class="rwsc-help"><?php echo esc_html( sprintf( __( 'Product weights are read in your store unit (%s) and converted to kg.', 'rar-woo-smart-courier' ), get_option( 'woocommerce_weight_unit', 'kg' ) ) ); ?></small>
                        </div>
                    </div>

                    <div class="rwsc-card rwsc-tester" id="rwsc-zone-tester">
                        <h3><?php esc_html_e( 'Test an address', 'rar-woo-smart-courier' ); ?> <small><?php esc_html_e( '(uses saved settings)', 'rar-woo-smart-courier' ); ?></small></h3>
                        <div class="rwsc-calc" data-calc></div>
                    </div>
                </section>

                <!-- Checkout -->
                <section class="rwsc-panel-tab" data-panel="checkout">
                    <div class="rwsc-sec-h"><div><h2><?php esc_html_e( 'Checkout display', 'rar-woo-smart-courier' ); ?></h2>
                        <p><?php esc_html_e( 'How courier choices look and which one is pre-selected for the customer.', 'rar-woo-smart-courier' ); ?></p></div></div>
                    <div class="rwsc-grid2">
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Engine', 'rar-woo-smart-courier' ); ?></h3>
                            <?php self::toggle( 'enabled', __( 'Show courier choices at checkout', 'rar-woo-smart-courier' ), __( 'Replaces the zone\'s flat rate with one option per courier.', 'rar-woo-smart-courier' ) ); ?>
                            <?php self::toggle( 'test_mode', __( 'Safe Test Mode', 'rar-woo-smart-courier' ), __( 'Only shop managers see the couriers. Customers keep the normal shipping until you switch this off.', 'rar-woo-smart-courier' ) ); ?>
                            <?php self::toggle( 'keep_pickup', __( 'Keep "Local pickup" as an extra option', 'rar-woo-smart-courier' ) ); ?>
                        </div>
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Recommendation', 'rar-woo-smart-courier' ); ?></h3>
                            <?php
                            $rec = array(
                                'smart'    => array( __( 'Smart', 'rar-woo-smart-courier' ), __( 'Fastest delivery first, then lower price, then your priority.', 'rar-woo-smart-courier' ) ),
                                'cheapest' => array( __( 'Cheapest', 'rar-woo-smart-courier' ), __( 'Lowest price first, then faster delivery.', 'rar-woo-smart-courier' ) ),
                                'priority' => array( __( 'My priority', 'rar-woo-smart-courier' ), __( 'Your priority numbers decide (lower = first).', 'rar-woo-smart-courier' ) ),
                            );
                            foreach ( $rec as $v => $r ) :
                                ?>
                                <label class="rwsc-radio"><input type="radio" name="recommend_by" value="<?php echo esc_attr( $v ); ?>" <?php checked( $s['recommend_by'], $v ); ?>><span><b><?php echo esc_html( $r[0] ); ?></b><small><?php echo esc_html( $r[1] ); ?></small></span></label>
                            <?php endforeach; ?>
                            <small class="rwsc-help"><?php esc_html_e( 'The recommended courier is listed first and pre-selected.', 'rar-woo-smart-courier' ); ?></small>
                        </div>
                    </div>
                    <div class="rwsc-grid2">
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Label', 'rar-woo-smart-courier' ); ?></h3>
                            <label class="rwsc-radio"><input type="radio" name="label_style" value="detailed" <?php checked( $s['label_style'], 'detailed' ); ?>><span><b><?php esc_html_e( 'Detailed', 'rar-woo-smart-courier' ); ?></b><small><?php esc_html_e( 'Colour dot, badges, price, and a second line with ETA + delivery date.', 'rar-woo-smart-courier' ); ?></small></span></label>
                            <label class="rwsc-radio"><input type="radio" name="label_style" value="compact" <?php checked( $s['label_style'], 'compact' ); ?>><span><b><?php esc_html_e( 'Compact', 'rar-woo-smart-courier' ); ?></b><small>Pathao · 1–2 business days: ৳70 · Recommended</small></span></label>
                            <?php self::toggle( 'show_eta', __( 'Show delivery time (ETA)', 'rar-woo-smart-courier' ) ); ?>
                            <?php self::toggle( 'show_edd', __( 'Show estimated delivery date', 'rar-woo-smart-courier' ), __( 'e.g. "Arrives Sat 27 – Sun 28 Sep" – uses your weekend, holidays and cut-off time.', 'rar-woo-smart-courier' ) ); ?>
                            <?php self::toggle( 'show_badges', __( 'Show badges', 'rar-woo-smart-courier' ) ); ?>
                            <div class="rwsc-row3">
                                <label class="rwsc-lbl"><?php esc_html_e( 'Recommended', 'rar-woo-smart-courier' ); ?><input type="text" class="rwsc-input" name="badge_rec" value="<?php echo esc_attr( $s['badge_rec'] ); ?>"></label>
                                <label class="rwsc-lbl"><?php esc_html_e( 'Fastest', 'rar-woo-smart-courier' ); ?><input type="text" class="rwsc-input" name="badge_fast" value="<?php echo esc_attr( $s['badge_fast'] ); ?>"></label>
                                <label class="rwsc-lbl"><?php esc_html_e( 'Cheapest', 'rar-woo-smart-courier' ); ?><input type="text" class="rwsc-input" name="badge_cheap" value="<?php echo esc_attr( $s['badge_cheap'] ); ?>"></label>
                            </div>
                            <small class="rwsc-help"><?php esc_html_e( 'Leave a badge text empty to hide that badge.', 'rar-woo-smart-courier' ); ?></small>
                        </div>
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Free shipping', 'rar-woo-smart-courier' ); ?></h3>
                            <p class="rwsc-help"><?php esc_html_e( 'When WooCommerce "Free shipping" applies (e.g. minimum order amount), couriers become free and the normal price is shown struck through.', 'rar-woo-smart-courier' ); ?></p>
                            <label class="rwsc-radio"><input type="radio" name="free_mode" value="all" <?php checked( $s['free_mode'], 'all' ); ?>><span><b><?php esc_html_e( 'Every courier is free', 'rar-woo-smart-courier' ); ?></b></span></label>
                            <label class="rwsc-radio"><input type="radio" name="free_mode" value="recommended" <?php checked( $s['free_mode'], 'recommended' ); ?>><span><b><?php esc_html_e( 'Only the recommended courier is free', 'rar-woo-smart-courier' ); ?></b><small><?php esc_html_e( 'Customers can still pay for another courier.', 'rar-woo-smart-courier' ); ?></small></span></label>
                            <h3 class="rwsc-mt"><?php esc_html_e( 'Preview', 'rar-woo-smart-courier' ); ?> <small><?php esc_html_e( 'Inside Dhaka, 1 kg', 'rar-woo-smart-courier' ); ?></small></h3>
                            <div class="rwsc-preview" id="rwsc-label-preview"></div>
                        </div>
                    </div>
                </section>

                <!-- Dates -->
                <section class="rwsc-panel-tab" data-panel="dates">
                    <div class="rwsc-sec-h"><div><h2><?php esc_html_e( 'Delivery dates', 'rar-woo-smart-courier' ); ?></h2>
                        <p><?php esc_html_e( 'Estimated delivery dates count business days from the day the parcel can be handed over.', 'rar-woo-smart-courier' ); ?></p></div></div>
                    <div class="rwsc-grid2">
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Weekly off-days', 'rar-woo-smart-courier' ); ?></h3>
                            <input type="hidden" name="_lists[]" value="weekend">
                            <div class="rwsc-chips">
                                <?php
                                $days = array( 'sat' => 'Sat', 'sun' => 'Sun', 'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri' );
                                foreach ( $days as $k => $d ) :
                                    ?>
                                    <label class="rwsc-chip"><input type="checkbox" name="weekend[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['weekend'], true ) ); ?>><span><?php echo esc_html( $d ); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <label class="rwsc-lbl rwsc-mt"><?php esc_html_e( 'Same-day hand-over cut-off', 'rar-woo-smart-courier' ); ?>
                                <input class="rwsc-input" type="time" name="cutoff" value="<?php echo esc_attr( $s['cutoff'] ); ?>" style="max-width:160px"></label>
                            <small class="rwsc-help"><?php esc_html_e( 'Orders after this time are counted from the next business day.', 'rar-woo-smart-courier' ); ?></small>
                            <?php $dispatch = RWSC_Engine::dispatch_day(); ?>
                            <div class="rwsc-note"><?php /* translators: %s: date */ echo esc_html( sprintf( __( 'An order placed now is handed over on %s.', 'rar-woo-smart-courier' ), wp_date( 'l, j F', $dispatch->setTime( 12, 0 )->getTimestamp() ) ) ); ?></div>
                        </div>
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Holidays', 'rar-woo-smart-courier' ); ?></h3>
                            <input type="hidden" name="_lists[]" value="holidays">
                            <textarea class="rwsc-input rwsc-mono" name="holidays" id="rwsc-holidays" rows="8" placeholder="2026-12-16 Victory Day"><?php echo esc_textarea( implode( "\n", (array) $s['holidays'] ) ); ?></textarea>
                            <small class="rwsc-help"><?php esc_html_e( 'One per line: YYYY-MM-DD and an optional name. Add Eid and other moon-dependent holidays when they are announced.', 'rar-woo-smart-courier' ); ?></small>
                            <button type="button" class="rwsc-btn rwsc-btn-ghost rwsc-mt" id="rwsc-add-holidays"><?php esc_html_e( 'Add fixed-date national holidays for next 12 months', 'rar-woo-smart-courier' ); ?></button>
                        </div>
                    </div>
                </section>

                <!-- Tracking & automation -->
                <section class="rwsc-panel-tab" data-panel="tracking">
                    <div class="rwsc-sec-h"><div><h2><?php esc_html_e( 'Tracking & automation', 'rar-woo-smart-courier' ); ?></h2>
                        <p><?php esc_html_e( 'What the customer sees, and what happens automatically as parcels move.', 'rar-woo-smart-courier' ); ?></p></div></div>
                    <div class="rwsc-grid2">
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Customer', 'rar-woo-smart-courier' ); ?></h3>
                            <?php self::toggle( 'notify_tracking', __( 'Email the customer when a tracking number is added', 'rar-woo-smart-courier' ), __( 'Sent as a WooCommerce customer note with the tracking link and delivery estimate.', 'rar-woo-smart-courier' ) ); ?>
                            <?php self::toggle( 'show_account', __( 'Show courier & tracking on the order page', 'rar-woo-smart-courier' ), __( 'Thank-you page, My Account → Orders, plus a "Track" button.', 'rar-woo-smart-courier' ) ); ?>
                            <?php self::toggle( 'show_email', __( 'Add courier & tracking to order emails', 'rar-woo-smart-courier' ) ); ?>
                            <div class="rwsc-note">
                                <?php esc_html_e( 'Public tracking page shortcode:', 'rar-woo-smart-courier' ); ?> <code>[rwsc_tracking]</code>
                                <?php if ( $tracking_page && get_post( $tracking_page ) ) : ?>
                                    — <a href="<?php echo esc_url( get_permalink( $tracking_page ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View tracking page', 'rar-woo-smart-courier' ); ?></a>
                                <?php else : ?>
                                    — <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rwsc_create_tracking_page' ), 'rwsc_tracking_page' ) ); ?>"><?php esc_html_e( 'Create a "Track your order" page', 'rar-woo-smart-courier' ); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Automation', 'rar-woo-smart-courier' ); ?></h3>
                            <?php self::toggle( 'auto_assign', __( 'Auto-assign the recommended courier', 'rar-woo-smart-courier' ), __( 'For orders created by phone, admin or the staff app, when they reach a "ready" status.', 'rar-woo-smart-courier' ) ); ?>
                            <input type="hidden" name="_lists[]" value="ready_statuses">
                            <div class="rwsc-lbl"><?php esc_html_e( 'Ready to ship when the order is', 'rar-woo-smart-courier' ); ?></div>
                            <div class="rwsc-chips">
                                <?php foreach ( $order_statuses as $k => $label ) : $k = substr( $k, 3 ); ?>
                                    <label class="rwsc-chip"><input type="checkbox" name="ready_statuses[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['ready_statuses'], true ) ); ?>><span><?php echo esc_html( $label ); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <?php self::toggle( 'auto_complete', __( 'Mark the order Completed when the parcel is Delivered', 'rar-woo-smart-courier' ) ); ?>
                            <label class="rwsc-lbl"><?php esc_html_e( 'When a parcel is Returned, set the order to', 'rar-woo-smart-courier' ); ?>
                                <select class="rwsc-input" name="on_return">
                                    <option value="" <?php selected( $s['on_return'], '' ); ?>><?php esc_html_e( '— leave the order status —', 'rar-woo-smart-courier' ); ?></option>
                                    <?php foreach ( array( 'cancelled', 'failed', 'on-hold', 'refunded' ) as $st ) : ?>
                                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $s['on_return'], $st ); ?>><?php echo esc_html( wc_get_order_status_name( $st ) ); ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                            <label class="rwsc-lbl"><?php esc_html_e( 'Warn when a parcel waits for booking longer than (days)', 'rar-woo-smart-courier' ); ?>
                                <input class="rwsc-input" type="number" min="1" max="30" name="stale_days" value="<?php echo esc_attr( (int) $s['stale_days'] ); ?>" style="max-width:120px"></label>
                        </div>
                    </div>
                    <div class="rwsc-grid2">
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Cash on delivery', 'rar-woo-smart-courier' ); ?></h3>
                            <input type="hidden" name="_lists[]" value="cod_methods">
                            <p class="rwsc-help"><?php esc_html_e( 'Orders paid with these methods show the amount to collect on labels, manifests and CSV.', 'rar-woo-smart-courier' ); ?></p>
                            <div class="rwsc-chips">
                                <?php foreach ( $gateways as $gid => $gw ) : ?>
                                    <label class="rwsc-chip"><input type="checkbox" name="cod_methods[]" value="<?php echo esc_attr( $gid ); ?>" <?php checked( in_array( $gid, (array) $s['cod_methods'], true ) ); ?>><span><?php echo esc_html( wp_strip_all_tags( $gw->get_method_title() ? $gw->get_method_title() : $gid ) ); ?></span></label>
                                <?php endforeach; ?>
                                <?php foreach ( array_diff( (array) $s['cod_methods'], array_keys( $gateways ) ) as $extra ) : ?>
                                    <label class="rwsc-chip"><input type="checkbox" name="cod_methods[]" value="<?php echo esc_attr( $extra ); ?>" checked><span><?php echo esc_html( $extra ); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="rwsc-card">
                            <h3><?php esc_html_e( 'Sender on labels', 'rar-woo-smart-courier' ); ?></h3>
                            <div class="rwsc-row2">
                                <label class="rwsc-lbl"><?php esc_html_e( 'Shop name', 'rar-woo-smart-courier' ); ?><input type="text" class="rwsc-input" name="sender_name" value="<?php echo esc_attr( $s['sender_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></label>
                                <label class="rwsc-lbl"><?php esc_html_e( 'Phone', 'rar-woo-smart-courier' ); ?><input type="text" class="rwsc-input" name="sender_phone" value="<?php echo esc_attr( $s['sender_phone'] ); ?>" placeholder="01XXXXXXXXX"></label>
                            </div>
                            <label class="rwsc-lbl"><?php esc_html_e( 'Return address', 'rar-woo-smart-courier' ); ?><textarea class="rwsc-input" name="sender_address" rows="2"><?php echo esc_textarea( $s['sender_address'] ); ?></textarea></label>
                        </div>
                    </div>
                </section>

                <div class="rwsc-savebar" id="rwsc-savebar">
                    <span class="rwsc-dirty" id="rwsc-dirty"><?php esc_html_e( 'Unsaved changes', 'rar-woo-smart-courier' ); ?></span>
                    <button type="submit" class="rwsc-btn rwsc-btn-primary"><?php esc_html_e( 'Save settings', 'rar-woo-smart-courier' ); ?></button>
                </div>
            </form>

            <!-- Tools (separate forms) -->
            <section class="rwsc-panel-tab" data-panel="tools">
                <div class="rwsc-sec-h"><div><h2><?php esc_html_e( 'Tools', 'rar-woo-smart-courier' ); ?></h2><p><?php esc_html_e( 'Backup, move settings between sites, and check your setup.', 'rar-woo-smart-courier' ); ?></p></div></div>
                <div class="rwsc-grid2">
                    <div class="rwsc-card">
                        <h3><?php esc_html_e( 'Export settings', 'rar-woo-smart-courier' ); ?></h3>
                        <textarea class="rwsc-input rwsc-mono" rows="6" readonly id="rwsc-export"><?php echo esc_textarea( wp_json_encode( array( 'rwsc' => RWSC_VERSION, 'settings' => RWSC_Settings::export() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
                        <div class="rwsc-btns"><button type="button" class="rwsc-btn rwsc-btn-ghost" data-copy="#rwsc-export"><?php esc_html_e( 'Copy', 'rar-woo-smart-courier' ); ?></button><button type="button" class="rwsc-btn rwsc-btn-ghost" id="rwsc-export-dl"><?php esc_html_e( 'Download .json', 'rar-woo-smart-courier' ); ?></button></div>
                    </div>
                    <div class="rwsc-card">
                        <h3><?php esc_html_e( 'Import settings', 'rar-woo-smart-courier' ); ?></h3>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="rwsc_import_settings">
                            <?php wp_nonce_field( 'rwsc_import_settings' ); ?>
                            <textarea class="rwsc-input rwsc-mono" rows="6" name="rwsc_json" placeholder='{"rwsc":"2.0.0","settings":{…}}' required></textarea>
                            <div class="rwsc-btns"><button type="submit" class="rwsc-btn rwsc-btn-ghost"><?php esc_html_e( 'Import', 'rar-woo-smart-courier' ); ?></button></div>
                        </form>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Reset every Smart Courier setting to defaults?', 'rar-woo-smart-courier' ) ); ?>');" class="rwsc-mt">
                            <input type="hidden" name="action" value="rwsc_reset_settings">
                            <?php wp_nonce_field( 'rwsc_reset_settings' ); ?>
                            <button type="submit" class="rwsc-btn rwsc-btn-danger"><?php esc_html_e( 'Reset to defaults', 'rar-woo-smart-courier' ); ?></button>
                        </form>
                    </div>
                </div>
                <div class="rwsc-card">
                    <h3><?php esc_html_e( 'Setup check', 'rar-woo-smart-courier' ); ?></h3>
                    <div id="rwsc-health"></div>
                    <table class="rwsc-kv">
                        <tr><th><?php esc_html_e( 'Plugin', 'rar-woo-smart-courier' ); ?></th><td><?php echo esc_html( RWSC_VERSION ); ?></td></tr>
                        <tr><th>WooCommerce</th><td><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ); ?></td></tr>
                        <tr><th>WordPress / PHP</th><td><?php echo esc_html( get_bloginfo( 'version' ) . ' / ' . PHP_VERSION ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Order storage', 'rar-woo-smart-courier' ); ?></th><td><?php echo esc_html( RWSC_Reports::hpos() ? 'High-Performance Order Storage (HPOS)' : 'WordPress posts (legacy)' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Weight unit / currency', 'rar-woo-smart-courier' ); ?></th><td><?php echo esc_html( get_option( 'woocommerce_weight_unit' ) . ' / ' . get_woocommerce_currency() ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Timezone', 'rar-woo-smart-courier' ); ?></th><td><?php echo esc_html( wp_timezone_string() ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'v1.x order upgrade', 'rar-woo-smart-courier' ); ?></th><td><?php echo get_option( 'rwsc_backfill_done' ) ? esc_html__( 'Done', 'rar-woo-smart-courier' ) : esc_html__( 'Running in the background', 'rar-woo-smart-courier' ); ?></td></tr>
                    </table>
                </div>
            </section>
        </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Handlers                                                            */
    /* ------------------------------------------------------------------ */

    private static function back( $notice, $tab = '' ) {
        $url = add_query_arg( 'rwsc_notice', $notice, admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
        wp_safe_redirect( $url . ( $tab ? '#' . $tab : '' ) );
        exit;
    }

    public static function handle_save() {
        if ( ! current_user_can( self::manage_cap() ) ) {
            wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
        }
        check_admin_referer( 'rwsc_save_settings' );
        $input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $new   = RWSC_Settings::sanitize( (array) $input, RWSC_Settings::all() );
        RWSC_Settings::save( $new );
        $tab = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
        self::back( 'saved', $tab );
    }

    public static function handle_import() {
        if ( ! current_user_can( self::manage_cap() ) ) {
            wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
        }
        check_admin_referer( 'rwsc_import_settings' );
        $raw  = isset( $_POST['rwsc_json'] ) ? wp_unslash( $_POST['rwsc_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $data = json_decode( (string) $raw, true );
        if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
            self::back( 'badjson', 'tools' );
        }
        $in           = $data['settings'];
        $in['_flags'] = array( 'enabled', 'test_mode', 'bd_only', 'scan_address', 'show_eta', 'show_edd', 'show_badges', 'keep_pickup', 'notify_tracking', 'show_account', 'show_email', 'auto_assign', 'auto_complete' );
        $in['_lists'] = array( 'nearby_districts', 'nearby_areas', 'dhaka_areas', 'weekend', 'holidays', 'ready_statuses', 'cod_methods' );
        if ( isset( $in['holidays'] ) && is_array( $in['holidays'] ) ) {
            $in['holidays'] = implode( "\n", $in['holidays'] );
        }
        $couriers = isset( $in['couriers'] ) && is_array( $in['couriers'] ) ? $in['couriers'] : array();
        unset( $in['couriers'] );
        $new = RWSC_Settings::sanitize( $in, RWSC_Settings::defaults() );
        if ( $couriers ) {
            $new['couriers'] = RWSC_Settings::sanitize_couriers( $couriers, RWSC_Settings::defaults()['couriers'] );
        }
        RWSC_Settings::save( $new );
        self::back( 'imported', 'tools' );
    }

    public static function handle_reset() {
        if ( ! current_user_can( self::manage_cap() ) ) {
            wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
        }
        check_admin_referer( 'rwsc_reset_settings' );
        RWSC_Settings::save( RWSC_Settings::defaults() );
        self::back( 'reset', 'tools' );
    }

    public static function handle_tracking_page() {
        if ( ! current_user_can( self::manage_cap() ) || ! current_user_can( 'publish_pages' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
        }
        check_admin_referer( 'rwsc_tracking_page' );
        $existing = (int) get_option( 'rwsc_tracking_page_id', 0 );
        if ( ! $existing || ! get_post( $existing ) ) {
            $id = wp_insert_post(
                array(
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => __( 'Track your order', 'rar-woo-smart-courier' ),
                    'post_content' => '<!-- wp:shortcode -->[rwsc_tracking]<!-- /wp:shortcode -->',
                )
            );
            if ( $id && ! is_wp_error( $id ) ) {
                update_option( 'rwsc_tracking_page_id', (int) $id, false );
            }
        }
        self::back( 'page', 'tracking' );
    }
}
