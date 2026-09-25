<?php
/**
 * Storefront: checkout styles, customer order view, emails, My Account "Track" button and tracking shortcode.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details' ), 5 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email' ), 15, 4 );
        add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_actions' ), 20, 2 );
        add_shortcode( 'rwsc_tracking', array( __CLASS__, 'shortcode' ) );
        add_shortcode( 'rar_courier_tracking', array( __CLASS__, 'shortcode' ) );
    }

    public static function assets() {
        $load = ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() || is_wc_endpoint_url( 'order-received' ) ) );
        if ( ! $load ) {
            global $post;
            $load = $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'rwsc_tracking' ) || has_shortcode( $post->post_content, 'rar_courier_tracking' ) );
        }
        if ( $load ) {
            wp_enqueue_style( 'rwsc-front', RWSC_URL . 'assets/css/frontend.css', array(), RWSC_VERSION );
        }
    }

    /**
     * Shipment card (thank-you page, My Account → View order, tracking shortcode).
     */
    public static function card( WC_Order $order, $heading = true ) {
        $s = RWSC_Shipments::get( $order );
        if ( '' === $s['courier'] ) {
            return '';
        }
        $steps = array(
            0 => __( 'Order confirmed', 'rar-woo-smart-courier' ),
            1 => __( 'Booked', 'rar-woo-smart-courier' ),
            2 => __( 'Picked up', 'rar-woo-smart-courier' ),
            3 => __( 'On the way', 'rar-woo-smart-courier' ),
            4 => 'returned' === $s['status'] ? __( 'Returned', 'rar-woo-smart-courier' ) : __( 'Delivered', 'rar-woo-smart-courier' ),
        );
        ob_start();
        ?>
        <section class="rwsc-track-card" style="--c:<?php echo esc_attr( $s['color'] ); ?>">
            <?php if ( $heading ) : ?><h2 class="rwsc-tc-title"><?php esc_html_e( 'Delivery', 'rar-woo-smart-courier' ); ?></h2><?php endif; ?>
            <div class="rwsc-tc-top">
                <span class="rwsc-tc-courier"><i></i><?php echo esc_html( $s['courier_name'] ); ?></span>
                <span class="rwsc-tc-status" style="--s:<?php echo esc_attr( $s['status_color'] ); ?>"><?php echo esc_html( 'pending' === $s['status'] ? __( 'Preparing your parcel', 'rar-woo-smart-courier' ) : $s['status_label'] ); ?></span>
            </div>
            <ol class="rwsc-tc-steps">
                <?php foreach ( $steps as $i => $label ) : ?>
                    <li class="<?php echo esc_attr( ( $s['step'] >= $i ? 'done' : '' ) . ( 'returned' === $s['status'] && 4 === $i ? ' bad' : '' ) ); ?>"><span></span><?php echo esc_html( $label ); ?></li>
                <?php endforeach; ?>
            </ol>
            <dl class="rwsc-tc-kv">
                <?php if ( $s['eta'] ) : ?><div><dt><?php esc_html_e( 'Delivery time', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html( $s['eta'] ); ?></dd></div><?php endif; ?>
                <?php if ( $s['edd_label'] && ! in_array( $s['status'], array( 'delivered', 'returned' ), true ) ) : ?><div><dt><?php esc_html_e( 'Estimated arrival', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html( $s['edd_label'] ); ?></dd></div><?php endif; ?>
                <?php if ( $s['delivered_at'] && 'delivered' === $s['status'] ) : ?><div><dt><?php esc_html_e( 'Delivered on', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo esc_html( wp_date( 'D j M, g:i a', $s['delivered_at'] ) ); ?></dd></div><?php endif; ?>
                <?php if ( $s['tracking'] ) : ?><div><dt><?php esc_html_e( 'Tracking number', 'rar-woo-smart-courier' ); ?></dt><dd><code><?php echo esc_html( $s['tracking'] ); ?></code></dd></div><?php endif; ?>
                <?php if ( $s['cod'] > 0 && ! in_array( $s['status'], array( 'delivered', 'returned' ), true ) ) : ?><div><dt><?php esc_html_e( 'Pay on delivery', 'rar-woo-smart-courier' ); ?></dt><dd><?php echo wp_kses_post( wc_price( $s['cod'] ) ); ?></dd></div><?php endif; ?>
            </dl>
            <?php if ( $s['tracking_url'] ) : ?>
                <a class="rwsc-tc-btn" href="<?php echo esc_url( $s['tracking_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: %s courier */ echo esc_html( sprintf( __( 'Track on %s', 'rar-woo-smart-courier' ), $s['courier_name'] ) ); ?> ↗</a>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function order_details( $order ) {
        if ( ! $order instanceof WC_Order || ! RWSC_Settings::on( 'show_account' ) ) {
            return;
        }
        echo self::card( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
        if ( ! $order instanceof WC_Order || ! RWSC_Settings::on( 'show_email' ) ) {
            return;
        }
        $s = RWSC_Shipments::get( $order );
        if ( '' === $s['courier'] ) {
            return;
        }
        $rows = array( __( 'Courier', 'rar-woo-smart-courier' ) => $s['courier_name'] );
        if ( $s['eta'] ) {
            $rows[ __( 'Delivery time', 'rar-woo-smart-courier' ) ] = $s['eta'];
        }
        if ( $s['edd_label'] && ! in_array( $s['status'], array( 'delivered', 'returned' ), true ) ) {
            $rows[ __( 'Estimated arrival', 'rar-woo-smart-courier' ) ] = $s['edd_label'];
        }
        if ( $s['tracking'] ) {
            $rows[ __( 'Tracking number', 'rar-woo-smart-courier' ) ] = $s['tracking'];
        }
        if ( $sent_to_admin ) {
            $rows[ __( 'Shipment', 'rar-woo-smart-courier' ) ] = $s['status_label'] . ( $s['zone_label'] ? ' · ' . $s['zone_label'] : '' );
        }
        if ( $plain_text ) {
            echo "\n" . esc_html( strtoupper( __( 'Delivery', 'rar-woo-smart-courier' ) ) ) . "\n";
            foreach ( $rows as $k => $v ) {
                echo esc_html( $k . ': ' . $v ) . "\n";
            }
            if ( $s['tracking_url'] ) {
                echo esc_html__( 'Track', 'rar-woo-smart-courier' ) . ': ' . esc_url_raw( $s['tracking_url'] ) . "\n";
            }
            echo "\n";
            return;
        }
        echo '<div style="margin:0 0 24px"><h2>' . esc_html__( 'Delivery', 'rar-woo-smart-courier' ) . '</h2><table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse">';
        foreach ( $rows as $k => $v ) {
            echo '<tr><th class="td" scope="row" style="text-align:left;width:40%">' . esc_html( $k ) . '</th><td class="td" style="text-align:left">' . esc_html( $v ) . '</td></tr>';
        }
        echo '</table>';
        if ( $s['tracking_url'] ) {
            /* translators: %s courier */
            echo '<p style="margin:12px 0 0"><a href="' . esc_url( $s['tracking_url'] ) . '" style="display:inline-block;padding:10px 16px;border-radius:6px;background:' . esc_attr( $s['color'] ) . ';color:#fff;text-decoration:none;font-weight:bold">' . esc_html( sprintf( __( 'Track on %s', 'rar-woo-smart-courier' ), $s['courier_name'] ) ) . '</a></p>';
        }
        echo '</div>';
    }

    public static function account_actions( $actions, $order ) {
        if ( ! RWSC_Settings::on( 'show_account' ) || ! $order instanceof WC_Order ) {
            return $actions;
        }
        $s = RWSC_Shipments::get( $order );
        if ( $s['tracking_url'] && ! in_array( $s['status'], array( 'delivered', 'returned' ), true ) ) {
            $actions['rwsc-track'] = array(
                'url'  => $s['tracking_url'],
                'name' => __( 'Track', 'rar-woo-smart-courier' ),
            );
        }
        return $actions;
    }

    /* ------------------------------------------------------------------ */
    /* [rwsc_tracking]                                                     */
    /* ------------------------------------------------------------------ */

    private static function client_key() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
        return 'rwsc_trk_' . md5( $ip );
    }

    private static function order_key( $number ) {
        return 'rwsc_trko_' . md5( strtolower( trim( (string) $number ) ) );
    }

    public static function shortcode( $atts ) {
        $atts   = shortcode_atts( array( 'title' => __( 'Track your order', 'rar-woo-smart-courier' ) ), $atts, 'rwsc_tracking' );
        $number = '';
        $phone  = '';
        $result = '';
        $error  = '';

        if ( isset( $_POST['rwsc_track'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only lookup protected by order number + phone and rate limiting; works on cached pages.
            $number = isset( $_POST['rwsc_order'] ) ? sanitize_text_field( wp_unslash( $_POST['rwsc_order'] ) ) : '';
            $phone  = isset( $_POST['rwsc_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['rwsc_phone'] ) ) : '';
            $tries  = (int) get_transient( self::client_key() );
            $otries = (int) get_transient( self::order_key( $number ) );
            if ( ! empty( $_POST['rwsc_website'] ) ) {
                $error = __( 'No order matches that order number and phone number.', 'rar-woo-smart-courier' );
            } elseif ( $tries >= 60 || $otries >= 8 ) {
                // Per order number (stops phone guessing) and a generous per-IP cap (proxies/CDNs share IPs).
                $error = __( 'Too many attempts. Please try again in a few minutes.', 'rar-woo-smart-courier' );
            } else {
                set_transient( self::client_key(), $tries + 1, 10 * MINUTE_IN_SECONDS );
                set_transient( self::order_key( $number ), $otries + 1, 10 * MINUTE_IN_SECONDS );
                $order = self::find_order( $number, $phone );
                if ( $order ) {
                    $result = self::card( $order, false );
                    if ( '' === $result ) {
                        $result = '<p class="rwsc-tr-msg">' . esc_html__( 'We found your order. It has not been handed to a courier yet – please check back soon.', 'rar-woo-smart-courier' ) . '</p>';
                    }
                    /* translators: 1: order number 2: order status */
                    $result = '<p class="rwsc-tr-found">' . esc_html( sprintf( __( 'Order #%1$s · %2$s', 'rar-woo-smart-courier' ), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ) ) ) . '</p>' . $result;
                } else {
                    $error = __( 'No order matches that order number and phone number.', 'rar-woo-smart-courier' );
                }
            }
        }

        ob_start();
        ?>
        <div class="rwsc-tracker">
            <form method="post" class="rwsc-tr-form">
                <h3><?php echo esc_html( $atts['title'] ); ?></h3>
                <input type="hidden" name="rwsc_track" value="1">
                <input type="text" name="rwsc_website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
                <label><?php esc_html_e( 'Order number', 'rar-woo-smart-courier' ); ?><input type="text" name="rwsc_order" value="<?php echo esc_attr( $number ); ?>" required inputmode="numeric" autocomplete="off"></label>
                <label><?php esc_html_e( 'Phone number used for the order', 'rar-woo-smart-courier' ); ?><input type="tel" name="rwsc_phone" value="<?php echo esc_attr( $phone ); ?>" required placeholder="01XXXXXXXXX" autocomplete="tel"></label>
                <button type="submit"><?php esc_html_e( 'Track', 'rar-woo-smart-courier' ); ?></button>
            </form>
            <?php if ( $error ) : ?><p class="rwsc-tr-err" role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
            <?php echo $result; // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Order by number (or id) whose billing/shipping phone matches.
     */
    private static function find_order( $number, $phone ) {
        $number = ltrim( trim( (string) $number ), '#' );
        $phone  = RWSC_Shipments::phone_local( $phone );
        if ( '' === $number || strlen( $phone ) < 10 ) {
            return null;
        }
        $candidates = array();
        if ( ctype_digit( $number ) ) {
            $o = wc_get_order( (int) $number );
            if ( $o ) {
                $candidates[] = $o;
            }
        }
        // Sequential order-number plugins store the number in meta.
        foreach ( RWSC_Reports::order_ids_by_meta( '_order_number', $number, 3 ) as $id ) {
            $o = wc_get_order( $id );
            if ( $o ) {
                $candidates[] = $o;
            }
        }
        foreach ( $candidates as $o ) {
            if ( ! $o instanceof WC_Order || 'shop_order' !== $o->get_type() || (string) $o->get_order_number() !== $number ) {
                continue;
            }
            $phones = array( RWSC_Shipments::phone_local( $o->get_billing_phone() ) );
            if ( method_exists( $o, 'get_shipping_phone' ) ) {
                $phones[] = RWSC_Shipments::phone_local( $o->get_shipping_phone() );
            }
            if ( in_array( $phone, array_filter( $phones ), true ) ) {
                return $o;
            }
        }
        return null;
    }
}
