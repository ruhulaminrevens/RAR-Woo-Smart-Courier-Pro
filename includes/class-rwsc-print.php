<?php
/**
 * Printable parcel labels, courier hand-over manifests and CSV export.
 *
 * @package RAR_Woo_Smart_Courier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RWSC_Print {

    public static function init() {
        add_action( 'admin_post_rwsc_print', array( __CLASS__, 'handle' ) );
    }

    /**
     * Base URL for a print/export type (JS appends ids or filters).
     */
    public static function url( $type, array $args ) {
        return add_query_arg(
            array_merge(
                array(
                    'action'   => 'rwsc_print',
                    'type'     => $type,
                    '_wpnonce' => wp_create_nonce( 'rwsc_print' ),
                ),
                $args
            ),
            admin_url( 'admin-post.php' )
        );
    }

    /**
     * Orders selected by ids=1,2,3 or by the shipments-board filters.
     *
     * @return WC_Order[]
     */
    private static function orders() {
        // phpcs:disable WordPress.Security.NonceVerification
        $ids = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
        if ( ! $ids ) {
            $args = array(
                'tab'      => isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'ready',
                'courier'  => isset( $_GET['courier'] ) ? sanitize_key( wp_unslash( $_GET['courier'] ) ) : '',
                'zone'     => isset( $_GET['zone'] ) ? sanitize_key( wp_unslash( $_GET['zone'] ) ) : '',
                'q'        => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
                'days'     => isset( $_GET['days'] ) ? (int) $_GET['days'] : 30,
                'per_page' => 100,
            );
            // phpcs:enable
            for ( $page = 1; $page <= 5; $page++ ) {
                $args['page'] = $page;
                $r            = RWSC_Reports::shipments( $args );
                foreach ( $r['rows'] as $row ) {
                    $ids[] = (int) $row['id'];
                }
                if ( $page >= $r['pages'] ) {
                    break;
                }
            }
        }
        $orders = array();
        foreach ( array_slice( array_unique( $ids ), 0, 500 ) as $id ) {
            $o = wc_get_order( $id );
            if ( $o && 'shop_order' === $o->get_type() ) {
                $orders[] = $o;
            }
        }
        return $orders;
    }

    public static function handle() {
        if ( ! current_user_can( RWSC_Admin::view_cap() ) ) {
            wp_die( esc_html__( 'Not allowed.', 'rar-woo-smart-courier' ), 403 );
        }
        check_admin_referer( 'rwsc_print' );
        $type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'labels';
        $orders = self::orders();
        if ( 'csv' === $type ) {
            self::csv( $orders );
        } elseif ( 'manifest' === $type ) {
            self::manifest( $orders );
        } else {
            self::labels( $orders );
        }
        exit;
    }

    private static function sender() {
        $name  = trim( (string) RWSC_Settings::get( 'sender_name', '' ) );
        $addr  = trim( (string) RWSC_Settings::get( 'sender_address', '' ) );
        if ( '' === $addr ) {
            $addr = trim( implode( ', ', array_filter( array( get_option( 'woocommerce_store_address' ), get_option( 'woocommerce_store_address_2' ), get_option( 'woocommerce_store_city' ) ) ) ) );
        }
        return array(
            'name'    => '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'phone'   => (string) RWSC_Settings::get( 'sender_phone', '' ),
            'address' => $addr,
        );
    }

    private static function money( $v ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( $v ) ), ENT_QUOTES, 'UTF-8' );
    }

    private static function page_open( $title, $extra_css = '' ) {
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;font:13px/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans Bengali","Hind Siliguri",Arial,sans-serif;color:#0f172a;background:#e2e8f0}
.bar{position:sticky;top:0;z-index:5;display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 16px;background:#0f172a;color:#fff}
.bar b{font-size:15px}.bar .sp{flex:1}.bar a,.bar button{appearance:none;border:1px solid #334155;background:#1e293b;color:#fff;border-radius:8px;padding:8px 12px;font:inherit;cursor:pointer;text-decoration:none}
.bar a.on{background:#6366f1;border-color:#6366f1}.bar button.pri{background:#22c55e;border-color:#22c55e;color:#052e16;font-weight:700}
.empty{max-width:560px;margin:60px auto;padding:30px;background:#fff;border-radius:14px;text-align:center}
<?php echo $extra_css; // phpcs:ignore WordPress.Security.EscapeOutput ?>
@media print{body{background:#fff}.bar{display:none}}
</style>
</head>
<body>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Labels                                                              */
    /* ------------------------------------------------------------------ */

    private static function labels( array $orders ) {
        $size  = isset( $_GET['size'] ) ? sanitize_key( wp_unslash( $_GET['size'] ) ) : 'a4'; // phpcs:ignore WordPress.Security.NonceVerification
        $size  = in_array( $size, array( 'a4', '4x6', 'a6' ), true ) ? $size : 'a4';
        $from  = self::sender();
        $css   = '
.sheet{margin:16px auto;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.18)}
.a4 .sheet{width:210mm;min-height:297mm;padding:8mm;display:grid;grid-template-columns:1fr 1fr;grid-auto-rows:68mm;gap:4mm}
.t .sheet{padding:3mm}.s4x6 .sheet{width:100mm;height:150mm}.a6 .sheet{width:105mm;height:148mm}
.lb{border:1.4px dashed #94a3b8;border-radius:8px;padding:3mm 3.5mm;display:flex;flex-direction:column;gap:1.4mm;overflow:hidden;position:relative;height:100%}
.t .lb{border-style:solid}
.lb .hd{display:flex;align-items:center;gap:6px;border-bottom:1.5px solid #0f172a;padding-bottom:1.4mm}
.cr{font-weight:800;font-size:13px;color:#fff;background:var(--c);border-radius:5px;padding:1px 7px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.zn{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#334155}.on{margin-left:auto;font-weight:800;font-size:14px}
.to{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700}
.nm{font-size:15px;font-weight:800;line-height:1.15}.ph{font-size:15px;font-weight:800;letter-spacing:.02em}
.ad{font-size:11.5px;line-height:1.3;max-height:3.9em;overflow:hidden}.ds{font-weight:800}
.mid{display:flex;align-items:stretch;gap:6px;margin-top:auto}
.cod{border:2px solid #0f172a;border-radius:6px;padding:2px 8px;min-width:30mm;text-align:center;display:flex;flex-direction:column;justify-content:center}
.cod small{font-size:9px;font-weight:800;letter-spacing:.08em}.cod b{font-size:17px;line-height:1.1}.cod.paid b{font-size:14px}
.bc{flex:1;text-align:center;min-width:0}.bc svg{width:100%;height:11mm;display:block}.bc span{font:700 11px/1.2 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.08em}
.ft{display:flex;gap:6px;justify-content:space-between;font-size:9.5px;color:#334155;border-top:1px solid #cbd5e1;padding-top:1.2mm}
.ft .it{max-width:60%;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.nt{font-size:10px;background:#fef3c7;border-radius:4px;padding:1px 5px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.t .nm,.t .ph{font-size:18px}.t .ad{font-size:13px;max-height:none}.t .bc svg{height:16mm}.t .cod b{font-size:22px}
@page{margin:0}
@media print{.sheet{margin:0;box-shadow:none;page-break-after:always;break-after:page}}';
        self::page_open( __( 'Parcel labels', 'rar-woo-smart-courier' ), $css );

        $base = remove_query_arg( 'size' );
        echo '<div class="bar"><b>' . esc_html__( 'Parcel labels', 'rar-woo-smart-courier' ) . '</b><span>' . esc_html( sprintf( /* translators: %d count */ _n( '%d parcel', '%d parcels', count( $orders ), 'rar-woo-smart-courier' ), count( $orders ) ) ) . '</span><span class="sp"></span>';
        foreach ( array( 'a4' => 'A4 (8 per page)', '4x6' => '4×6 in thermal', 'a6' => 'A6' ) as $k => $l ) {
            echo '<a class="' . ( $k === $size ? 'on' : '' ) . '" href="' . esc_url( add_query_arg( 'size', $k, $base ) ) . '">' . esc_html( $l ) . '</a>';
        }
        echo '<button class="pri" onclick="window.print()">' . esc_html__( 'Print', 'rar-woo-smart-courier' ) . '</button></div>';

        if ( ! $orders ) {
            echo '<div class="empty">' . esc_html__( 'No orders selected.', 'rar-woo-smart-courier' ) . '</div></body></html>';
            return;
        }

        $wrap = 'a4' === $size ? 'a4' : 't ' . ( '4x6' === $size ? 's4x6' : 'a6' );
        echo '<div class="' . esc_attr( $wrap ) . '">';
        $chunks = 'a4' === $size ? array_chunk( $orders, 8 ) : array_chunk( $orders, 1 );
        foreach ( $chunks as $chunk ) {
            echo '<div class="sheet">';
            foreach ( $chunk as $order ) {
                self::label( $order, $from );
            }
            echo '</div>';
        }
        echo '</div></body></html>';
    }

    private static function label( WC_Order $order, array $from ) {
        $s     = RWSC_Shipments::get( $order );
        $dest  = RWSC_Shipments::order_destination( $order );
        $name  = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        $name  = '' !== $name ? $name : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $dist  = '' !== $s['district'] ? $s['district'] : RWSC_Locations::resolve_district( $dest['state'] );
        $items = array();
        $qty   = 0;
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' ×' . $item->get_quantity();
            $qty    += (int) $item->get_quantity();
        }
        $weight = $s['weight'] > 0 ? $s['weight'] : RWSC_Engine::order_weight( $order );
        $code   = '' !== $s['tracking'] ? $s['tracking'] : (string) $order->get_order_number();
        $note   = trim( $s['note'] . ( '' !== $s['note'] && $order->get_customer_note() ? ' · ' : '' ) . $order->get_customer_note() );
        ?>
        <div class="lb" style="--c:<?php echo esc_attr( $s['color'] ); ?>">
            <div class="hd">
                <span class="cr"><?php echo esc_html( '' !== $s['courier_name'] ? $s['courier_name'] : __( 'Courier', 'rar-woo-smart-courier' ) ); ?></span>
                <span class="zn"><?php echo esc_html( $s['zone_label'] ); ?></span>
                <span class="on">#<?php echo esc_html( $order->get_order_number() ); ?></span>
            </div>
            <div class="to"><?php esc_html_e( 'Deliver to', 'rar-woo-smart-courier' ); ?></div>
            <div class="nm"><?php echo esc_html( $name ); ?></div>
            <div class="ph"><?php echo esc_html( RWSC_Shipments::order_phone( $order ) ); ?></div>
            <div class="ad"><?php echo esc_html( trim( $dest['address_1'] . ( $dest['address_2'] ? ', ' . $dest['address_2'] : '' ) ) ); ?><?php echo $dest['city'] ? ', ' . esc_html( $dest['city'] ) : ''; ?> — <span class="ds"><?php echo esc_html( RWSC_Locations::name( $dist ) ? RWSC_Locations::name( $dist ) : $dest['state'] ); ?></span></div>
            <?php if ( '' !== $note ) : ?><div class="nt"><?php echo esc_html( wp_trim_words( $note, 18 ) ); ?></div><?php endif; ?>
            <div class="mid">
                <?php if ( $s['cod'] > 0 ) : ?>
                    <div class="cod"><small><?php esc_html_e( 'COLLECT (COD)', 'rar-woo-smart-courier' ); ?></small><b><?php echo esc_html( self::money( $s['cod'] ) ); ?></b></div>
                <?php else : ?>
                    <div class="cod paid"><small><?php esc_html_e( 'PAYMENT', 'rar-woo-smart-courier' ); ?></small><b><?php esc_html_e( 'PAID', 'rar-woo-smart-courier' ); ?></b></div>
                <?php endif; ?>
                <div class="bc"><?php echo self::barcode_svg( $code ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html( $code ); ?></span></div>
            </div>
            <div class="ft">
                <span class="it"><?php echo esc_html( sprintf( /* translators: 1: qty 2: kg */ __( '%1$d pcs · %2$s kg', 'rar-woo-smart-courier' ), $qty, wc_format_localized_decimal( round( $weight, 2 ) ) ) . ' · ' . implode( ', ', $items ) ); ?></span>
                <span><?php echo esc_html( $from['name'] . ( $from['phone'] ? ' · ' . $from['phone'] : '' ) ); ?></span>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Manifest                                                            */
    /* ------------------------------------------------------------------ */

    private static function manifest( array $orders ) {
        $css = '
.doc{width:min(1100px,100%);margin:16px auto;background:#fff;padding:22px 26px;box-shadow:0 10px 30px rgba(15,23,42,.18)}
h1{font-size:20px;margin:0 0 2px}.sub{color:#475569;margin-bottom:14px}
.grp{margin:18px 0 26px;page-break-inside:avoid}.gh{display:flex;align-items:center;gap:10px;border-bottom:2px solid var(--c);padding-bottom:6px;margin-bottom:6px}
.gh b{font-size:16px}.dot{width:12px;height:12px;border-radius:50%;background:var(--c);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.gh .sum{margin-left:auto;color:#334155;font-weight:600}
table{width:100%;border-collapse:collapse;font-size:12px}th,td{border:1px solid #cbd5e1;padding:5px 6px;text-align:left;vertical-align:top}
th{background:#f1f5f9;font-size:11px;text-transform:uppercase;letter-spacing:.04em;-webkit-print-color-adjust:exact;print-color-adjust:exact}
td.n{text-align:right;white-space:nowrap}tfoot td{font-weight:800;background:#f8fafc}
.sig{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:22px}.sig div{border-top:1px solid #0f172a;padding-top:4px;font-size:11px;color:#334155}
@page{size:A4 landscape;margin:10mm}@media print{.doc{box-shadow:none;margin:0;width:auto;padding:0}}';
        self::page_open( __( 'Courier hand-over manifest', 'rar-woo-smart-courier' ), $css );
        echo '<div class="bar"><b>' . esc_html__( 'Hand-over manifest', 'rar-woo-smart-courier' ) . '</b><span class="sp"></span><button class="pri" onclick="window.print()">' . esc_html__( 'Print', 'rar-woo-smart-courier' ) . '</button></div>';
        if ( ! $orders ) {
            echo '<div class="empty">' . esc_html__( 'No orders selected.', 'rar-woo-smart-courier' ) . '</div></body></html>';
            return;
        }
        $groups = array();
        foreach ( $orders as $o ) {
            $s                          = RWSC_Shipments::get( $o );
            $groups[ $s['courier'] ][] = array( $o, $s );
        }
        $from = self::sender();
        echo '<div class="doc"><h1>' . esc_html( $from['name'] ) . ' — ' . esc_html__( 'Courier hand-over manifest', 'rar-woo-smart-courier' ) . '</h1>';
        echo '<div class="sub">' . esc_html( wp_date( 'l, j F Y · g:i a' ) ) . ' · ' . esc_html( sprintf( /* translators: %d parcels */ _n( '%d parcel', '%d parcels', count( $orders ), 'rar-woo-smart-courier' ), count( $orders ) ) ) . '</div>';
        foreach ( $groups as $key => $rows ) {
            $cod = 0;
            $kg  = 0;
            $name = '' !== $key ? RWSC_Settings::courier_name( $key ) : __( 'Unassigned', 'rar-woo-smart-courier' );
            foreach ( $rows as $r ) {
                $cod += $r[1]['cod'];
                $kg  += $r[1]['weight'] > 0 ? $r[1]['weight'] : RWSC_Engine::order_weight( $r[0] );
            }
            echo '<div class="grp" style="--c:' . esc_attr( '' !== $key ? RWSC_Settings::courier_color( $key ) : '#94a3b8' ) . '">';
            echo '<div class="gh"><span class="dot"></span><b>' . esc_html( $name ) . '</b><span class="sum">' . esc_html( sprintf( /* translators: 1 parcels 2 cod 3 kg */ __( '%1$d parcels · COD %2$s · %3$s kg', 'rar-woo-smart-courier' ), count( $rows ), self::money( $cod ), wc_format_localized_decimal( round( $kg, 2 ) ) ) ) . '</span></div>';
            echo '<table><thead><tr><th>#</th><th>' . esc_html__( 'Order', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Customer', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Phone', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Address', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Items', 'rar-woo-smart-courier' ) . '</th><th>kg</th><th>COD</th><th>' . esc_html__( 'Tracking', 'rar-woo-smart-courier' ) . '</th><th>' . esc_html__( 'Status', 'rar-woo-smart-courier' ) . '</th></tr></thead><tbody>';
            $i = 0;
            foreach ( $rows as $r ) {
                list( $o, $s ) = $r;
                $d    = RWSC_Shipments::order_destination( $o );
                $dist = '' !== $s['district'] ? RWSC_Locations::name( $s['district'] ) : $d['state'];
                $nm   = trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() );
                $nm   = '' !== $nm ? $nm : trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
                $q    = 0;
                foreach ( $o->get_items() as $it ) {
                    $q += (int) $it->get_quantity();
                }
                printf(
                    '<tr><td class="n">%d</td><td>#%s</td><td>%s</td><td>%s</td><td>%s</td><td class="n">%d</td><td class="n">%s</td><td class="n">%s</td><td>%s</td><td>%s</td></tr>',
                    ++$i,
                    esc_html( $o->get_order_number() ),
                    esc_html( $nm ),
                    esc_html( RWSC_Shipments::order_phone( $o ) ),
                    esc_html( trim( $d['address_1'] . ', ' . $d['city'] . ', ' . $dist, ', ' ) ),
                    (int) $q,
                    esc_html( wc_format_localized_decimal( round( $s['weight'] > 0 ? $s['weight'] : RWSC_Engine::order_weight( $o ), 2 ) ) ),
                    $s['cod'] > 0 ? esc_html( self::money( $s['cod'] ) ) : esc_html__( 'Paid', 'rar-woo-smart-courier' ),
                    esc_html( $s['tracking'] ),
                    esc_html( $s['status_label'] )
                );
            }
            echo '</tbody><tfoot><tr><td colspan="7">' . esc_html__( 'Total', 'rar-woo-smart-courier' ) . '</td><td class="n">' . esc_html( self::money( $cod ) ) . '</td><td colspan="2"></td></tr></tfoot></table>';
            echo '<div class="sig"><div>' . esc_html__( 'Handed over by (shop)', 'rar-woo-smart-courier' ) . '</div><div>' . esc_html__( 'Received by (courier rep, name & phone)', 'rar-woo-smart-courier' ) . '</div><div>' . esc_html__( 'Date & time', 'rar-woo-smart-courier' ) . '</div></div></div>';
        }
        echo '</div></body></html>';
    }

    /* ------------------------------------------------------------------ */
    /* CSV                                                                 */
    /* ------------------------------------------------------------------ */

    private static function csv( array $orders ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="rwsc-shipments-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Excel UTF-8 BOM (Bangla text).
        fputcsv( $out, array( 'Invoice', 'Recipient Name', 'Phone', 'Address', 'Area / City', 'District', 'Zone', 'COD Amount', 'Weight (kg)', 'Items', 'Item Qty', 'Note', 'Courier', 'Tracking', 'Shipment Status', 'Order Status', 'Order Date' ), ',', '"', '' );
        foreach ( $orders as $o ) {
            $s     = RWSC_Shipments::get( $o );
            $d     = RWSC_Shipments::order_destination( $o );
            $nm    = trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() );
            $nm    = '' !== $nm ? $nm : trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
            $items = array();
            $q     = 0;
            foreach ( $o->get_items() as $it ) {
                $items[] = $it->get_name() . ' x' . $it->get_quantity();
                $q      += (int) $it->get_quantity();
            }
            $created = $o->get_date_created();
            fputcsv(
                $out,
                array(
                    self::cell( $o->get_order_number() ),
                    self::cell( $nm ),
                    self::cell( RWSC_Shipments::order_phone( $o ) ),
                    self::cell( trim( $d['address_1'] . ( $d['address_2'] ? ', ' . $d['address_2'] : '' ) ) ),
                    self::cell( $d['city'] ),
                    self::cell( '' !== $s['district'] ? RWSC_Locations::name( $s['district'] ) : RWSC_Locations::name( RWSC_Locations::resolve_district( $d['state'] ) ) ),
                    self::cell( $s['zone_label'] ),
                    wc_format_decimal( $s['cod'], wc_get_price_decimals() ),
                    round( $s['weight'] > 0 ? $s['weight'] : RWSC_Engine::order_weight( $o ), 3 ),
                    self::cell( implode( '; ', $items ) ),
                    $q,
                    self::cell( trim( $s['note'] . ' ' . $o->get_customer_note() ) ),
                    self::cell( $s['courier_name'] ),
                    self::cell( $s['tracking'] ),
                    self::cell( $s['status_label'] ),
                    self::cell( wc_get_order_status_name( $o->get_status() ) ),
                    $created ? $created->date_i18n( 'Y-m-d H:i' ) : '',
                ),
                ',',
                '"',
                ''
            );
        }
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    /** Neutralise spreadsheet formula injection. */
    private static function cell( $v ) {
        $v = (string) $v;
        return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
    }

    /* ------------------------------------------------------------------ */
    /* Code 128 (set B) barcode as inline SVG                              */
    /* ------------------------------------------------------------------ */

    const C128 = array(
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    );

    /**
     * Code 128-B symbol values for ASCII 32–126 text (others replaced by "?").
     */
    public static function code128_values( $text ) {
        $text = preg_replace( '/[^\x20-\x7E]/', '?', (string) $text );
        $vals = array( 104 );
        $sum  = 104;
        $len  = strlen( $text );
        for ( $i = 0; $i < $len; $i++ ) {
            $v      = ord( $text[ $i ] ) - 32;
            $vals[] = $v;
            $sum   += $v * ( $i + 1 );
        }
        $vals[] = $sum % 103;
        $vals[] = 106;
        return $vals;
    }

    public static function barcode_svg( $text ) {
        $vals = self::code128_values( $text );
        $x    = 10; // Quiet zone.
        $bars = '';
        foreach ( $vals as $v ) {
            $pattern = self::C128[ $v ];
            $plen    = strlen( $pattern );
            for ( $i = 0; $i < $plen; $i++ ) {
                $w = (int) $pattern[ $i ];
                if ( 0 === $i % 2 ) {
                    $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="40"/>';
                }
                $x += $w;
            }
        }
        $x += 10;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' 40" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $text ) . '" shape-rendering="crispEdges">' . $bars . '</svg>';
    }
}
