<?php
/**
 * WooCommerce order screens: shipment meta box, list column, filters and bulk actions (HPOS + legacy).
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Orders_Admin {

    const HPOS_SCREEN = 'woocommerce_page_wc-orders';

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 30 );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 50, 2 );

        // Columns.
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 20 );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'legacy_column' ), 20, 2 );
        add_filter( 'manage_' . self::HPOS_SCREEN . '_columns', array( __CLASS__, 'columns' ), 20 );
        add_action( 'manage_' . self::HPOS_SCREEN . '_custom_column', array( __CLASS__, 'hpos_column' ), 20, 2 );

        // Filters.
        add_action( 'restrict_manage_posts', array( __CLASS__, 'legacy_filters' ), 20 );
        add_action( 'pre_get_posts', array( __CLASS__, 'legacy_filter_query' ) );
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'hpos_filters' ), 20, 2 );
        add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'hpos_filter_query' ) );

        // Bulk actions.
        add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ), 30 );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk' ), 10, 3 );
        add_filter( 'bulk_actions-' . self::HPOS_SCREEN, array( __CLASS__, 'bulk_actions' ), 30 );
        add_filter( 'handle_bulk_actions-' . self::HPOS_SCREEN, array( __CLASS__, 'handle_bulk' ), 10, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );

        // Order preview modal.
        add_filter( 'woocommerce_admin_order_preview_get_order_details', array( __CLASS__, 'preview_data' ), 10, 2 );
        add_action( 'woocommerce_admin_order_preview_end', array( __CLASS__, 'preview_template' ) );
    }

    /* ------------------------------------------------------------------ */
    /* Meta box                                                            */
    /* ------------------------------------------------------------------ */

    public static function meta_box() {
        $screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box( 'rwsc-shipment', __( 'Smart Courier', 'rar-woo-smart-courier' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
        }
    }

    /**
     * @param WP_Post|WC_Order $post_or_order Object.
     */
    public static function render_meta_box( $post_or_order ) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
        if ( ! $order ) {
            return;
        }
        $s        = RWSC_Shipments::get( $order );
        $couriers = RWSC_Settings::couriers();
        wp_nonce_field( 'rwsc_meta_box', 'rwsc_meta_nonce' );
        echo '<input type="hidden" name="rwsc_tracking_orig" value="' . esc_attr( $s['tracking'] ) . '">';
        echo '<input type="hidden" name="rwsc_note_orig" value="' . esc_attr( $s['note'] ) . '">';
        echo '<input type="hidden" name="rwsc_status_orig" value="' . esc_attr( '' !== $s['status'] ? $s['status'] : 'pending' ) . '">';
        ?>
        <div class="rwsc-mb" style="--c:<?php echo esc_attr( $s['color'] ); ?>">
            <?php if ( '' !== $s['courier'] ) : ?>
                <div class="rwsc-mb-head">
                    <span class="rwsc-pill" style="--c:<?php echo esc_attr( $s['color'] ); ?>"><i></i><?php echo esc_html( $s['courier_name'] ); ?></span>
                    <span class="rwsc-st" style="--s:<?php echo esc_attr( $s['status_color'] ); ?>"><?php echo esc_html( $s['status_label'] ); ?></span>
                </div>
                <div class="rwsc-mb-steps" aria-hidden="true">
                    <?php
                    $steps = array( 0, 1, 2, 3, 4 );
                    foreach ( $steps as $st ) {
                        $cls = $s['step'] >= $st ? ( 'returned' === $s['status'] && 4 === $st ? 'bad' : 'on' ) : '';
                        echo '<span class="' . esc_attr( $cls ) . '"></span>';
                    }
                    ?>
                </div>
                <dl class="rwsc-mb-kv">
                    <?php if ( $s['zone_label'] ) : ?><dt><?php esc_html_e( 'Zone', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html( $s['zone_label'] . ( $s['district_name'] ? ' · ' . $s['district_name'] : '' ) ); ?></dd><?php endif; ?>
                    <?php if ( $s['eta'] ) : ?><dt><?php esc_html_e( 'ETA', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html( $s['eta'] ); ?></dd><?php endif; ?>
                    <?php if ( $s['edd_label'] ) : ?><dt><?php esc_html_e( 'Estimated', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html( $s['edd_label'] ); ?></dd><?php endif; ?>
                    <?php if ( $s['weight'] ) : ?><dt><?php esc_html_e( 'Weight', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html( wc_format_localized_decimal( $s['weight'] ) ); ?> kg</dd><?php endif; ?>
                    <?php if ( $s['cod'] > 0 ) : ?><dt>COD</dt><dd><?php echo wp_kses_post( wc_price( $s['cod'] ) ); ?></dd><?php endif; ?>
                    <?php if ( $s['free_applied'] ) : ?><dt><?php esc_html_e( 'Free ship', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html__( 'Yes – normal', 'rar-woo-smart-courier' ) . ' ' . wp_strip_all_tags( wc_price( $s['normal_cost'] ) ); ?></dd><?php endif; ?>
                </dl>
            <?php else : ?>
                <p class="rwsc-mb-empty"><?php esc_html_e( 'No courier yet. Pick one below (or "Recommended") and update the order.', 'rar-woo-smart-courier' ); ?></p>
            <?php endif; ?>

            <label class="rwsc-mb-l"><?php esc_html_e( 'Courier', 'rar-woo-smart-courier' ); ?>
                <select name="rwsc_courier">
                    <option value=""><?php esc_html_e( '— keep —', 'rar-woo-smart-courier' ); ?></option>
                    <option value="auto"><?php esc_html_e( '★ Recommended for this address', 'rar-woo-smart-courier' ); ?></option>
                    <?php foreach ( $couriers as $key => $c ) : if ( '' === $c['name'] ) { continue; } ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['courier'], $key ); ?>><?php echo esc_html( $c['name'] . ( 'yes' !== $c['enabled'] ? ' (' . __( 'disabled', 'rar-woo-smart-courier' ) . ')' : '' ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="rwsc-mb-l"><?php esc_html_e( 'Tracking / consignment no.', 'rar-woo-smart-courier' ); ?>
                <input type="text" name="rwsc_tracking" value="<?php echo esc_attr( $s['tracking'] ); ?>" autocomplete="off">
            </label>
            <?php if ( $s['tracking_url'] ) : ?>
                <a class="rwsc-mb-track" href="<?php echo esc_url( $s['tracking_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open tracking page ↗', 'rar-woo-smart-courier' ); ?></a>
            <?php endif; ?>
            <label class="rwsc-mb-l"><?php esc_html_e( 'Shipment status', 'rar-woo-smart-courier' ); ?>
                <select name="rwsc_status">
                    <?php foreach ( RWSC_Shipments::statuses() as $k => $st ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( '' !== $s['status'] ? $s['status'] : 'pending', $k ); ?>><?php echo esc_html( $st['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="rwsc-mb-l"><?php esc_html_e( 'Note for courier / label', 'rar-woo-smart-courier' ); ?>
                <textarea name="rwsc_note" rows="2"><?php echo esc_textarea( $s['note'] ); ?></textarea>
            </label>
            <label class="rwsc-mb-c"><input type="checkbox" name="rwsc_notify" value="1" <?php checked( RWSC_Settings::on( 'notify_tracking' ) ); ?>> <?php esc_html_e( 'Email the customer when tracking is added', 'rar-woo-smart-courier' ); ?></label>
            <div class="rwsc-mb-links">
                <a href="<?php echo esc_url( RWSC_Print::url( 'labels', array( 'ids' => $order->get_id() ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print label', 'rar-woo-smart-courier' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=rwsc-shipments' ) ); ?>"><?php esc_html_e( 'Shipments board', 'rar-woo-smart-courier' ); ?></a>
            </div>
            <?php if ( $s['log'] ) : ?>
                <details class="rwsc-mb-log"><summary><?php esc_html_e( 'Timeline', 'rar-woo-smart-courier' ); ?> (<?php echo (int) count( $s['log'] ); ?>)</summary>
                    <ol>
                        <?php foreach ( array_reverse( $s['log'] ) as $e ) : ?>
                            <li><b><?php echo esc_html( $e['m'] ); ?></b><span><?php echo esc_html( wp_date( 'j M, g:i a', (int) $e['t'] ) . ' · ' . $e['u'] ); ?></span></li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param int      $order_id Order id.
     * @param WC_Order $order    Order (HPOS passes it; legacy passes a post).
     */
    public static function save_meta_box( $order_id, $order = null ) {
        if ( ! isset( $_POST['rwsc_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rwsc_meta_nonce'] ) ), 'rwsc_meta_box' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
            return;
        }
        // Only fields the user changed in this form are sent, so edits made meanwhile on the
        // Shipments board (tracking, status) are not overwritten by a stale order screen.
        $field = static function ( $name, $cb ) {
            return isset( $_POST[ $name ] ) ? call_user_func( $cb, wp_unslash( $_POST[ $name ] ) ) : null; // phpcs:ignore WordPress.Security
        };
        $changes  = array( 'courier' => (string) $field( 'rwsc_courier', 'sanitize_key' ) );
        $tracking = $field( 'rwsc_tracking', 'sanitize_text_field' );
        $note     = $field( 'rwsc_note', 'sanitize_textarea_field' );
        $status   = (string) $field( 'rwsc_status', 'sanitize_key' );
        if ( null !== $tracking && trim( $tracking ) !== trim( (string) $field( 'rwsc_tracking_orig', 'sanitize_text_field' ) ) ) {
            $changes['tracking'] = $tracking;
        }
        if ( null !== $note && trim( $note ) !== trim( (string) $field( 'rwsc_note_orig', 'sanitize_textarea_field' ) ) ) {
            $changes['note'] = $note;
        }
        if ( '' !== $status && $status !== (string) $field( 'rwsc_status_orig', 'sanitize_key' ) ) {
            $changes['status'] = $status;
        }
        if ( '' === $changes['courier'] && 1 === count( $changes ) ) {
            return; // Nothing changed in the Smart Courier box.
        }
        $r = RWSC_Shipments::update( $order, $changes, array( 'notify' => ! empty( $_POST['rwsc_notify'] ) ) );
        if ( is_wp_error( $r ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
            WC_Admin_Meta_Boxes::add_error( 'Smart Courier: ' . $r->get_error_message() );
        }
    }

    /* ------------------------------------------------------------------ */
    /* List column                                                         */
    /* ------------------------------------------------------------------ */

    public static function columns( $columns ) {
        $new = array();
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( 'shipping_address' === $k || 'order_status' === $k && ! isset( $columns['shipping_address'] ) ) {
                $new['rwsc_courier'] = __( 'Courier', 'rar-woo-smart-courier' );
            }
        }
        if ( ! isset( $new['rwsc_courier'] ) ) {
            $new['rwsc_courier'] = __( 'Courier', 'rar-woo-smart-courier' );
        }
        return $new;
    }

    public static function legacy_column( $column, $post_id ) {
        if ( 'rwsc_courier' === $column ) {
            $order = wc_get_order( $post_id );
            if ( $order ) {
                self::column_html( $order );
            }
        }
    }

    public static function hpos_column( $column, $order ) {
        if ( 'rwsc_courier' === $column && $order instanceof WC_Order ) {
            self::column_html( $order );
        }
    }

    private static function column_html( WC_Order $order ) {
        $s = RWSC_Shipments::get( $order );
        if ( '' === $s['courier'] ) {
            echo '<span class="rwsc-dim">—</span>';
            return;
        }
        echo '<span class="rwsc-pill" style="--c:' . esc_attr( $s['color'] ) . '"><i></i>' . esc_html( $s['courier_name'] ) . '</span>';
        echo '<span class="rwsc-st" style="--s:' . esc_attr( $s['status_color'] ) . '">' . esc_html( $s['status_label'] ) . '</span>';
        if ( '' !== $s['tracking'] ) {
            if ( $s['tracking_url'] ) {
                echo '<a class="rwsc-trk" href="' . esc_url( $s['tracking_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $s['tracking'] ) . ' ↗</a>';
            } else {
                echo '<span class="rwsc-trk">' . esc_html( $s['tracking'] ) . '</span>';
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Filters                                                             */
    /* ------------------------------------------------------------------ */

    private static function filter_controls() {
        // phpcs:disable WordPress.Security.NonceVerification
        $cur_c = isset( $_GET['rwsc_courier'] ) ? sanitize_key( wp_unslash( $_GET['rwsc_courier'] ) ) : '';
        $cur_s = isset( $_GET['rwsc_ship'] ) ? sanitize_key( wp_unslash( $_GET['rwsc_ship'] ) ) : '';
        // phpcs:enable
        echo '<select name="rwsc_courier" aria-label="' . esc_attr__( 'Courier', 'rar-woo-smart-courier' ) . '"><option value="">' . esc_html__( 'All couriers', 'rar-woo-smart-courier' ) . '</option>';
        echo '<option value="_none"' . selected( $cur_c, '_none', false ) . '>' . esc_html__( 'No courier', 'rar-woo-smart-courier' ) . '</option>';
        foreach ( RWSC_Settings::couriers() as $key => $c ) {
            if ( '' === $c['name'] ) {
                continue;
            }
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $cur_c, $key, false ) . '>' . esc_html( $c['name'] ) . '</option>';
        }
        echo '</select><select name="rwsc_ship" aria-label="' . esc_attr__( 'Shipment status', 'rar-woo-smart-courier' ) . '"><option value="">' . esc_html__( 'Any shipment status', 'rar-woo-smart-courier' ) . '</option>';
        foreach ( RWSC_Shipments::statuses() as $k => $st ) {
            echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur_s, $k, false ) . '>' . esc_html( $st['label'] ) . '</option>';
        }
        echo '</select>';
    }

    private static function meta_query_from_request() {
        // phpcs:disable WordPress.Security.NonceVerification
        $c  = isset( $_GET['rwsc_courier'] ) ? sanitize_key( wp_unslash( $_GET['rwsc_courier'] ) ) : '';
        $s  = isset( $_GET['rwsc_ship'] ) ? sanitize_key( wp_unslash( $_GET['rwsc_ship'] ) ) : '';
        // phpcs:enable
        $mq = array();
        if ( '_none' === $c ) {
            $mq[] = array( 'key' => '_rwsc_courier', 'compare' => 'NOT EXISTS' );
        } elseif ( '' !== $c ) {
            $mq[] = array( 'key' => '_rwsc_courier', 'value' => $c );
        }
        if ( '' !== $s && RWSC_Shipments::is_status( $s ) ) {
            $mq[] = array( 'key' => '_rwsc_status', 'value' => $s );
        }
        return $mq;
    }

    public static function legacy_filters( $post_type ) {
        if ( 'shop_order' === $post_type ) {
            self::filter_controls();
        }
    }

    public static function legacy_filter_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
            return;
        }
        $mq = self::meta_query_from_request();
        if ( $mq ) {
            $existing = (array) $query->get( 'meta_query' );
            $query->set( 'meta_query', array_merge( $existing, $mq ) );
        }
    }

    public static function hpos_filters( $order_type, $which = 'top' ) {
        if ( 'shop_order' === $order_type && 'top' === $which ) {
            self::filter_controls();
        }
    }

    public static function hpos_filter_query( $args ) {
        $mq = self::meta_query_from_request();
        if ( $mq ) {
            $args['meta_query'] = array_merge( isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(), $mq ); // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        return $args;
    }

    /* ------------------------------------------------------------------ */
    /* Bulk actions                                                        */
    /* ------------------------------------------------------------------ */

    public static function bulk_actions( $actions ) {
        $actions['rwsc_assign'] = __( 'Courier: assign recommended', 'rar-woo-smart-courier' );
        foreach ( array( 'booked', 'picked', 'in_transit', 'delivered', 'returned' ) as $st ) {
            /* translators: %s: shipment status */
            $actions[ 'rwsc_status_' . $st ] = sprintf( __( 'Shipment: mark %s', 'rar-woo-smart-courier' ), RWSC_Shipments::status_label( $st ) );
        }
        $actions['rwsc_print_labels'] = __( 'Courier: print labels', 'rar-woo-smart-courier' );
        $actions['rwsc_manifest']     = __( 'Courier: hand-over manifest', 'rar-woo-smart-courier' );
        $actions['rwsc_csv']          = __( 'Courier: export CSV', 'rar-woo-smart-courier' );
        return $actions;
    }

    public static function handle_bulk( $redirect, $action, $ids ) {
        if ( 0 !== strpos( (string) $action, 'rwsc_' ) || ! current_user_can( 'edit_shop_orders' ) ) {
            return $redirect;
        }
        $ids = array_map( 'absint', (array) $ids );
        if ( in_array( $action, array( 'rwsc_print_labels', 'rwsc_manifest', 'rwsc_csv' ), true ) ) {
            $type = 'rwsc_print_labels' === $action ? 'labels' : ( 'rwsc_manifest' === $action ? 'manifest' : 'csv' );
            return RWSC_Print::url( $type, array( 'ids' => implode( ',', $ids ) ) );
        }
        $done = 0;
        $fail = 0;
        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
                continue;
            }
            if ( 'rwsc_assign' === $action ) {
                $r = RWSC_Shipments::update( $order, array( 'courier' => '' === (string) $order->get_meta( '_rwsc_courier', true ) ? 'auto' : '' ) );
                if ( ! is_wp_error( $r ) && '' !== $r['courier'] ) {
                    $order->save();
                }
            } else {
                $r = RWSC_Shipments::update( $order, array( 'status' => substr( $action, strlen( 'rwsc_status_' ) ) ) );
            }
            if ( is_wp_error( $r ) ) {
                $fail++;
            } else {
                $done++;
            }
        }
        return add_query_arg( array( 'rwsc_bulk' => $done, 'rwsc_bulk_fail' => $fail ), $redirect );
    }

    public static function bulk_notice() {
        if ( ! isset( $_GET['rwsc_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $done = absint( $_GET['rwsc_bulk'] ); // phpcs:ignore WordPress.Security.NonceVerification
        $fail = isset( $_GET['rwsc_bulk_fail'] ) ? absint( $_GET['rwsc_bulk_fail'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        /* translators: %d: count */
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'Smart Courier updated %d order.', 'Smart Courier updated %d orders.', $done, 'rar-woo-smart-courier' ), $done ) );
        if ( $fail ) {
            /* translators: %d: count */
            echo ' ' . esc_html( sprintf( _n( '%d order could not be updated.', '%d orders could not be updated.', $fail, 'rar-woo-smart-courier' ), $fail ) );
        }
        echo '</p></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Order preview                                                       */
    /* ------------------------------------------------------------------ */

    public static function preview_data( $data, $order ) {
        if ( $order instanceof WC_Order ) {
            $s = RWSC_Shipments::get( $order );
            if ( '' !== $s['courier'] ) {
                $data['rwsc_html'] = '<div class="wc-order-preview-note"><strong>' . esc_html__( 'Courier', 'rar-woo-smart-courier' ) . '</strong>' . esc_html( $s['courier_name'] . ' · ' . $s['status_label'] . ( $s['tracking'] ? ' · ' . $s['tracking'] : '' ) . ( $s['edd_label'] ? ' · ' . $s['edd_label'] : '' ) ) . '</div>';
            }
        }
        return $data;
    }

    public static function preview_template() {
        echo '{{{ data.rwsc_html }}}';
    }
}
