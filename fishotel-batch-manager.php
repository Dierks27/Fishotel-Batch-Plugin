<?php
/**
 * Plugin Name:       FisHotel Batch Manager
 * Description:       Stable v3.21 - Arrival Day Live Tracking Board: Solari split-flap, REST API, embed URL, in_quarantine stage.
 * Version:           3.21
 * Author:            Dierks & Claude
 * Text Domain:       fishotel-batch-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FISHOTEL_VERSION', '3.21' );
define( 'FISHOTEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FISHOTEL_PLUGIN_FILE', __FILE__ );

require_once FISHOTEL_PLUGIN_DIR . 'includes/class-helpers.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-ajax.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-woocommerce.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-admin.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-updater.php';

// Stage-aware title helpers — shared by all title/heading filters
function fishotel_stage_label_map() {
    return [
        'open_ordering'   => 'Now Boarding',
        'orders_closed'   => 'In Transit',
        'arrived'         => 'Arrived — Counting',
        'in_quarantine'   => 'In Quarantine',
        'graduation'      => 'Graduation Day',
        'verification'    => 'Accept or Pass',
        'draft'           => 'Draft Pool',
        'invoicing'       => 'Invoicing',
    ];
}

function fishotel_get_transit_batch() {
    if ( is_admin() ) return false;
    $obj = get_queried_object();
    if ( ! $obj || ! isset( $obj->post_name ) ) return false;
    $assignments = get_option( 'fishotel_batch_page_assignments', [] );
    $statuses    = get_option( 'fishotel_batch_statuses', [] );
    $batch       = array_search( $obj->post_name, $assignments, true );
    $labels      = fishotel_stage_label_map();
    if ( $batch && isset( $labels[ $statuses[ $batch ] ?? '' ] ) ) {
        return $batch;
    }
    return false;
}

function fishotel_get_batch_stage_label() {
    $batch = fishotel_get_transit_batch();
    if ( ! $batch ) return false;
    $statuses = get_option( 'fishotel_batch_statuses', [] );
    $labels   = fishotel_stage_label_map();
    return $labels[ $statuses[ $batch ] ?? '' ] ?? false;
}

function fishotel_get_transit_title() {
    $batch = fishotel_get_transit_batch();
    $label = fishotel_get_batch_stage_label();
    return ( $batch && $label ) ? $batch . ' – ' . $label : false;
}

// WordPress core <title>
add_filter( 'document_title_parts', function( $title ) {
    $t = fishotel_get_transit_title();
    if ( $t ) $title['title'] = $t;
    return $title;
} );

// Rank Math
add_filter( 'rank_math/frontend/title', function( $title ) {
    $t = fishotel_get_transit_title();
    return $t ? $t . ' | The FisHotel' : $title;
} );
add_filter( 'rank_math/frontend/og/title', function( $title ) {
    $t = fishotel_get_transit_title();
    return $t ? $t . ' | The FisHotel' : $title;
} );

// Yoast SEO
add_filter( 'wpseo_title', function( $title ) {
    $t = fishotel_get_transit_title();
    return $t ? $t . ' | The FisHotel' : $title;
} );
add_filter( 'wpseo_opengraph_title', function( $title ) {
    $t = fishotel_get_transit_title();
    return $t ? $t . ' | The FisHotel' : $title;
} );

// Replace Elementor H1 and breadcrumb on batch pages via JS
add_action( 'wp_footer', function() {
    $batch = fishotel_get_transit_batch();
    $label = fishotel_get_batch_stage_label();
    if ( ! $batch || ! $label ) return;
    $origin  = strtoupper( preg_split( '/[\s\-]/', $batch )[0] ?? $batch );
    $heading = $origin . ' · ' . strtoupper( $label );
    ?>
    <script>
    (function(){
        var h = <?php echo wp_json_encode( $heading ); ?>;
        var h1 = document.querySelector('h1');
        if (h1) h1.textContent = h;
        var bc = document.querySelector('.rank-math-breadcrumb, .breadcrumb, .yoast-breadcrumb, .elementor-breadcrumb');
        if (bc) {
            var last = bc.querySelector('span.last, span:last-child, a:last-child');
            if (last) last.textContent = h;
            var links = bc.querySelectorAll('a');
            for (var i = 0; i < links.length; i++) {
                if (links[i].textContent.trim() === 'Live Fish List') {
                    links[i].textContent = h;
                }
            }
        }
    })();
    </script>
    <?php
} );

class FisHotel_Batch_Manager {

    use FisHotel_Helpers;
    use FisHotel_Ajax;
    use FisHotel_WooCommerce;
    use FisHotel_Shortcodes;
    use FisHotel_Admin;

    private $is_syncing = false;

    public function __construct() {
        add_action( 'init', [$this, 'init'] );
        add_action( 'admin_menu', [$this, 'add_admin_menu'] );
        add_action( 'admin_init', [$this, 'register_settings'] );
        add_action( 'rest_api_init', [$this, 'register_rest_routes'] );
        add_filter( 'query_vars', [$this, 'add_embed_query_var'] );
        add_action( 'template_redirect', [$this, 'handle_embed_redirect'] );
        add_action( 'admin_post_fishotel_import_csv',    [$this, 'handle_csv_import'] );
        add_action( 'admin_post_fishotel_import_prices', [$this, 'handle_price_import'] );
        add_action( 'admin_post_fishotel_process_mapping', [$this, 'process_mapping'] );
        add_action( 'admin_post_fishotel_create_product_from_master', [$this, 'create_product_from_master'] );
        add_action( 'admin_post_fishotel_delete_batch_item', [$this, 'delete_batch_item'] );
        add_action( 'admin_post_fishotel_add_batch',        [$this, 'add_batch'] );
        add_action( 'admin_post_fishotel_advance_stage',    [$this, 'advance_stage_handler'] );
        add_action( 'admin_post_fishotel_admin_order',      [$this, 'handle_admin_order'] );
        add_action( 'admin_post_fishotel_cancel_request', [$this, 'admin_cancel_request'] );
        add_action( 'admin_post_fishotel_remove_single_fish', [$this, 'admin_remove_single_fish'] );
        add_action( 'admin_post_fishotel_adjust_wallet', [$this, 'admin_adjust_wallet'] );
        add_action( 'admin_post_fishotel_mark_deposit_paid', [$this, 'mark_deposit_paid_handler'] );
        add_action( 'admin_post_fishotel_mark_deposit_unpaid', [$this, 'mark_deposit_unpaid_handler'] );
        add_action( 'admin_post_fishotel_fully_delete_deposit', [$this, 'fully_delete_deposit_handler'] );
        add_action( 'admin_post_fishotel_reset_test_data', [$this, 'reset_test_data_handler'] );
        add_action( 'admin_post_fishotel_create_test_requests', [$this, 'create_test_requests_handler'] );
        add_action( 'admin_post_fishotel_export_order_excel', [$this, 'export_order_excel'] );
        add_action( 'admin_post_fishotel_delete_batch',       [$this, 'delete_batch'] );
        add_action( 'admin_post_fishotel_add_location',       [$this, 'add_location_handler'] );
        add_action( 'admin_post_fishotel_delete_location',    [$this, 'delete_location_handler'] );
        add_action( 'admin_post_fishotel_save_ticker',        [$this, 'save_ticker_handler'] );
        add_action( 'admin_post_fishotel_save_arrival_data',  [$this, 'save_arrival_data_handler'] );
        add_action( 'admin_post_fishotel_log_survival_entry', [$this, 'log_survival_entry_handler'] );

        add_action( 'wp_login', [$this, 'record_last_login'], 10, 2 );

        add_action( 'woocommerce_edit_account_form', [$this, 'add_hf_username_field'] );
        add_action( 'woocommerce_save_account_details', [$this, 'save_hf_username_field'] );

        add_shortcode( 'fishotel_wallet_deposit', [$this, 'wallet_deposit_shortcode'] );
        add_action( 'woocommerce_payment_complete', [$this, 'credit_wallet_on_payment'] );
        add_action( 'woocommerce_order_status_completed', [$this, 'credit_wallet_on_payment'] );
        add_action( 'woocommerce_order_status_processing', [$this, 'credit_wallet_on_payment'] );

        add_action( 'woocommerce_account_menu_items', [$this, 'add_wallet_menu_item'] );
        add_action( 'woocommerce_account_wallet_endpoint', [$this, 'wallet_endpoint_content'] );

        add_action( 'woocommerce_account_menu_items', [$this, 'add_custom_orders_menu_item'] );
        add_action( 'woocommerce_account_my-requests_endpoint', [$this, 'custom_orders_endpoint_content'] );

        add_action( 'wp_loaded', [$this, 'handle_deposit_add_to_cart'] );
        add_action( 'woocommerce_before_calculate_totals', [$this, 'set_deposit_cart_price'], 9999 );
        add_filter( 'woocommerce_get_cart_item_from_session', [$this, 'restore_deposit_cart_item'], 9999, 3 );
        add_filter( 'woocommerce_cart_item_name', [$this, 'deposit_cart_item_name'], 9999, 3 );
        add_filter( 'woocommerce_cart_item_price', [$this, 'deposit_cart_item_price'], 9999, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', [$this, 'deposit_cart_item_subtotal'], 9999, 3 );
        add_filter( 'woocommerce_is_purchasable', [$this, 'force_deposit_purchasable'], 9999, 2 );
        add_filter( 'woocommerce_order_item_name', [$this, 'force_deposit_order_item_name'], 9999, 3 );

        add_action( 'woocommerce_product_options_general_product_data', [$this, 'add_fishotel_price_field'] );
        add_action( 'woocommerce_process_product_meta', [$this, 'save_fishotel_price_field'] );
        add_action( 'save_post_product', [$this, 'sync_wc_to_master'], 10, 3 );

        add_action( 'admin_post_fishotel_sync_quarantined', [$this, 'sync_all_quarantined'] );

        add_action( 'add_meta_boxes_fish_master', [$this, 'add_fish_meta_box'] );
        add_action( 'add_meta_boxes_fish_master', [$this, 'add_batch_items_metabox'] );
        add_action( 'add_meta_boxes_fish_request', [$this, 'add_request_view_metabox'] );
        add_action( 'save_post_fish_master', [$this, 'save_fish_meta'] );
        add_action( 'save_post_fish_request', [$this, 'save_fish_request_meta'] );

        add_action( 'updated_post_meta', [$this, 'sync_price_master_to_woo'], 10, 4 );
        add_action( 'updated_post_meta', [$this, 'sync_price_woo_to_master'], 10, 4 );

        add_filter( 'manage_fish_master_posts_columns', [$this, 'master_columns'] );
        add_action( 'manage_fish_master_posts_custom_column', [$this, 'master_column_content'], 10, 2 );

        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_batch_orders_scripts'] );

        add_shortcode( 'fishotel_batch', [$this, 'batch_shortcode'] );
        add_shortcode( 'fishotel_wallet', [$this, 'wallet_shortcode'] );

        // Secure AJAX with nonces
        add_action( 'wp_ajax_fishotel_submit_requests', [$this, 'ajax_submit_requests'] );
        add_action( 'wp_ajax_nopriv_fishotel_submit_requests', [$this, 'ajax_submit_requests'] );
        add_action( 'wp_ajax_fishotel_ajax_login', [$this, 'ajax_login'] );
        add_action( 'wp_ajax_nopriv_fishotel_ajax_login', [$this, 'ajax_login'] );
        add_action( 'wp_ajax_fishotel_save_hf_username', [$this, 'ajax_save_hf_username'] );
        add_action( 'wp_ajax_nopriv_fishotel_save_hf_username', [$this, 'ajax_save_hf_username'] );
        add_action( 'wp_ajax_fishotel_check_balance', [$this, 'ajax_check_balance'] );
        add_action( 'wp_ajax_fishotel_get_order_details', [$this, 'ajax_get_order_details'] );
        add_action( 'wp_ajax_fishotel_remove_request_item', [$this, 'ajax_remove_request_item'] );
        add_action( 'wp_ajax_fishotel_remove_from_order', [$this, 'ajax_remove_from_order'] );
        add_action( 'wp_ajax_fishotel_save_arrival_field', [$this, 'ajax_save_arrival_field'] );

        add_action( 'woocommerce_after_checkout_form', [$this, 'add_return_to_fish_button'] );
        add_action( 'woocommerce_thankyou', [$this, 'add_return_to_fish_button'] );
    }

    // ─── REST API ────────────────────────────────────────────────────────
    public function register_rest_routes() {
        register_rest_route( 'fishotel/v1', '/arrival-status/(?P<batch>.+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_arrival_status' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function rest_arrival_status( $request ) {
        $batch_name = urldecode( $request->get_param( 'batch' ) );
        $statuses   = get_option( 'fishotel_batch_statuses', [] );
        $batch_status = $statuses[ $batch_name ] ?? 'unknown';

        $batch_fish = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_key'    => '_batch_name',
            'meta_value'  => $batch_name,
        ] );

        // Aggregate demand from requests
        $requests = get_posts( [
            'post_type'   => 'fish_request',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_query'  => [ [ 'key' => '_batch_name', 'value' => $batch_name, 'compare' => '=' ] ],
        ] );
        $demand = [];
        foreach ( $requests as $req ) {
            if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
            $items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
            foreach ( $items as $item ) {
                $bid = intval( $item['batch_id'] ?? 0 );
                $qty = intval( $item['qty'] ?? 1 );
                if ( $bid ) $demand[ $bid ] = ( $demand[ $bid ] ?? 0 ) + $qty;
            }
        }

        $species = [];
        foreach ( $batch_fish as $bp ) {
            $species[] = [
                'fish_id'      => $bp->ID,
                'common_name'  => $bp->post_title,
                'qty_ordered'  => $demand[ $bp->ID ] ?? 0,
                'qty_received' => intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) ),
                'qty_doa'      => intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) ),
                'tank'         => get_post_meta( $bp->ID, '_arrival_tank', true ) ?: '',
                'status'       => get_post_meta( $bp->ID, '_arrival_status', true ) ?: 'in_transit',
                'updated_at'   => intval( get_post_meta( $bp->ID, '_arrival_updated_at', true ) ),
            ];
        }

        return rest_ensure_response( [
            'batch'   => $batch_name,
            'status'  => $batch_status,
            'species' => $species,
        ] );
    }

    // ─── Embed URL ───────────────────────────────────────────────────────
    public function add_embed_query_var( $vars ) {
        $vars[] = 'fishotel_embed_batch';
        return $vars;
    }

    public function handle_embed_redirect() {
        $embed_batch = get_query_var( 'fishotel_embed_batch' );
        if ( ! $embed_batch ) return;

        $batch_name = urldecode( str_replace( '-', ' ', $embed_batch ) );

        // Find matching batch (case-insensitive)
        $batches_str = get_option( 'fishotel_batches', '' );
        $batches     = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );
        $matched     = '';
        foreach ( $batches as $b ) {
            if ( strtolower( $b ) === strtolower( $batch_name ) ) { $matched = $b; break; }
        }
        if ( ! $matched ) { wp_die( 'Batch not found.' ); }

        // Load batch data
        $statuses    = get_option( 'fishotel_batch_statuses', [] );
        $batch_status = $statuses[ $matched ] ?? 'unknown';
        $batch_fish  = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_key'    => '_batch_name',
            'meta_value'  => $matched,
        ] );

        $arrival_dates = get_option( 'fishotel_batch_arrival_dates', [] );
        $arrival_date  = $arrival_dates[ $matched ] ?? '';

        // Build species data
        $requests = get_posts( [
            'post_type' => 'fish_request', 'numberposts' => -1, 'post_status' => 'any',
            'meta_query' => [ [ 'key' => '_batch_name', 'value' => $matched, 'compare' => '=' ] ],
        ] );
        $demand = [];
        foreach ( $requests as $req ) {
            if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
            $items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
            foreach ( $items as $item ) {
                $bid = intval( $item['batch_id'] ?? 0 );
                if ( $bid ) $demand[ $bid ] = ( $demand[ $bid ] ?? 0 ) + intval( $item['qty'] ?? 1 );
            }
        }

        $species = [];
        foreach ( $batch_fish as $bp ) {
            $recv = intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
            $doa  = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
            $species[] = [
                'name'    => strtoupper( mb_substr( $bp->post_title, 0, 20 ) ),
                'recv'    => $recv,
                'doa'     => $doa,
                'alive'   => $recv - $doa,
                'ordered' => $demand[ $bp->ID ] ?? 0,
                'tank'    => get_post_meta( $bp->ID, '_arrival_tank', true ) ?: '—',
                'status'  => get_post_meta( $bp->ID, '_arrival_status', true ) ?: 'in_transit',
            ];
        }

        $stage_labels = fishotel_stage_label_map();
        $stage_label  = strtoupper( $stage_labels[ $batch_status ] ?? $batch_status );
        $batch_slug   = urlencode( $matched );

        // Output bare embed page
        header( 'Content-Type: text/html; charset=utf-8' );
        show_admin_bar( false );
        ?><!DOCTYPE html>
<html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FisHotel Arrivals — <?php echo esc_html( $matched ); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0a0908;color:#fff;font-family:'Oswald',sans-serif;overflow-x:hidden}
<?php $this->render_arrival_board_css(); ?>
</style>
</head><body>
<?php $this->render_arrival_board_html( $matched, $stage_label, $species, $batch_slug, $arrival_date, true ); ?>
</body></html>
        <?php
        exit;
    }

    // ─── Arrival Board — Reusable CSS ─────────────────────────────────
    public function render_arrival_board_css() {
        ?>
        /* ── Arrival Board — Solari Split-Flap ── */
        .fh-ab-wrap { width:100%; overflow:hidden; }
        .fh-ab {
            width:100%; box-sizing:border-box;
            background:#0a0a0a; border-radius:4px;
            border-top:7px solid #1e1a14; border-left:7px solid #1a1610;
            border-right:6px solid #14110c; border-bottom:5px solid #100e0a;
            box-shadow:0 0 0 1px #0a0806, 4px 6px 20px rgba(0,0,0,0.85),
                        8px 12px 50px rgba(0,0,0,0.6), inset 0 0 60px rgba(0,0,0,0.4);
            overflow:hidden; position:relative;
            font-family:'Oswald',sans-serif;
        }
        .fh-ab::before {
            content:''; position:absolute; inset:0; z-index:1; pointer-events:none;
            opacity:0.03; mix-blend-mode:overlay;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        }
        .fh-ab-header, .fh-ab-footer {
            padding:9px 20px; display:flex; align-items:center; justify-content:space-between;
            font-weight:700; text-transform:uppercase; position:relative; z-index:2;
        }
        .fh-ab-header {
            background:linear-gradient(to bottom, #161410, #111);
            border-bottom:1px solid #2a2520;
        }
        .fh-ab-footer {
            background:linear-gradient(to top, #161410, #111);
            border-top:1px solid #2a2520;
        }
        .fh-ab-hl {
            font-size:18px; letter-spacing:0.08em; color:#f0e8d0;
            text-shadow:0 0 8px rgba(212,188,126,0.6), 0 0 20px rgba(181,161,101,0.3);
            display:flex; align-items:center; gap:8px;
        }
        .fh-ab-hr, .fh-ab-fl, .fh-ab-fr {
            font-size:12px; letter-spacing:0.14em; color:#8a7a50;
        }
        .fh-ab-fl-lit { color:#d4bc7e; text-shadow:0 0 8px rgba(212,188,126,0.6); }

        /* Column headers row */
        .fh-ab-cols {
            display:grid;
            grid-template-columns: minmax(140px,2fr) minmax(110px,1.3fr) 70px 80px 70px;
            padding:6px 16px; background:#111; border-bottom:1px solid #1a1a10;
            position:relative; z-index:2;
        }
        .fh-ab-col-hd {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:0.12em; color:#8a7a50; padding:2px 4px;
        }
        .fh-ab-col-hd:nth-child(n+3) { text-align:center; }

        /* Data rows */
        .fh-ab-row {
            display:grid;
            grid-template-columns: minmax(140px,2fr) minmax(110px,1.3fr) 70px 80px 70px;
            padding:5px 16px; border-bottom:1px solid #0d0d0d;
            box-shadow:inset 0 1px 0 #252520;
            position:relative; z-index:2; align-items:center;
            transition: background 0.3s;
        }
        .fh-ab-row:last-child { border-bottom:none; box-shadow:none; }
        .fh-ab-row.fh-ab-qt { background:rgba(0,255,100,0.04); }
        .fh-ab-row.fh-ab-short { background:rgba(255,180,0,0.06); }
        .fh-ab-row.fh-ab-noarr { background:rgba(255,60,60,0.05); }

        .fh-ab-cell {
            padding:3px 4px; overflow:hidden; white-space:nowrap;
        }
        .fh-ab-cell:nth-child(n+3) { text-align:center; }

        /* Flap tiles inside cells */
        .fh-ab-tiles {
            display:flex; flex-wrap:nowrap; gap:1px; overflow:hidden;
        }
        .fh-ab-flap {
            width:22px; height:34px; min-width:22px;
            background:#141414; border-radius:2px;
            box-shadow:inset 0 1px 0 rgba(255,255,255,0.04), 0 2px 0 #0a0806, 0 1px 4px rgba(0,0,0,0.9);
            display:flex; align-items:center; justify-content:center;
            font-family:'Courier New',monospace; font-weight:700;
            font-size:17px; color:#c8a84b; letter-spacing:-0.5px;
            text-transform:uppercase; position:relative;
        }
        .fh-ab-flap:nth-child(odd) { background:#121212; }
        .fh-ab-flap::after {
            content:''; position:absolute; left:0; top:50%;
            width:100%; height:1px; background:#000;
        }

        /* Status cell — text only, no tiles */
        .fh-ab-status-text {
            font-family:'Courier New',monospace; font-weight:700;
            font-size:13px; text-transform:uppercase; letter-spacing:0.04em;
        }
        .fh-ab-status-qt { color:#44ff88; }
        .fh-ab-status-short { color:#ffaa33; }
        .fh-ab-status-noarr { color:#ff5555; }
        .fh-ab-status-transit { color:#8a7a50; }
        .fh-ab-status-landed { color:#66ccff; }
        .fh-ab-status-counting { color:#d4bc7e; }

        /* Responsive */
        @media (max-width:700px) {
            .fh-ab-cols, .fh-ab-row {
                grid-template-columns: minmax(100px,2fr) minmax(80px,1.3fr) 50px 60px 50px;
                padding:4px 8px;
            }
            .fh-ab-flap {
                width:14px; height:22px; min-width:14px;
                font-size:11px;
            }
            .fh-ab-hl { font-size:13px; }
            .fh-ab-hr, .fh-ab-fl, .fh-ab-fr { font-size:10px; }
            .fh-ab-col-hd { font-size:9px; }
            .fh-ab-status-text { font-size:10px; }
        }
        <?php
    }

    // ─── Arrival Board — Reusable HTML + JS ───────────────────────────
    public function render_arrival_board_html( $batch_name, $stage_label, $species, $batch_slug, $arrival_date, $is_embed = false ) {
        $status_labels = [
            'in_transit'     => 'IN TRANSIT',
            'landed'         => 'LANDED',
            'counting'       => 'COUNTING',
            'in_quarantine'  => 'IN QT',
            'short'          => 'SHORT',
            'no_arrival'     => 'NO ARRIVAL',
        ];
        $status_classes = [
            'in_quarantine' => 'fh-ab-status-qt',
            'short'         => 'fh-ab-status-short',
            'no_arrival'    => 'fh-ab-status-noarr',
            'in_transit'    => 'fh-ab-status-transit',
            'landed'        => 'fh-ab-status-landed',
            'counting'      => 'fh-ab-status-counting',
        ];
        $row_classes = [
            'in_quarantine' => 'fh-ab-qt',
            'short'         => 'fh-ab-short',
            'no_arrival'    => 'fh-ab-noarr',
        ];
        $origin = strtoupper( preg_split( '/[\s\-]/', $batch_name )[0] ?? $batch_name );
        ?>
        <div class="fh-ab-wrap" id="fh-arrival-board" data-batch-slug="<?php echo esc_attr( $batch_slug ); ?>">
        <div class="fh-ab">
            <!-- Header -->
            <div class="fh-ab-header">
                <div class="fh-ab-hl">
                    <span style="opacity:0.7;font-size:16px;">&#x2708;</span>
                    FISHOTEL INTL &middot; ARRIVALS
                </div>
                <div class="fh-ab-hr"><?php echo esc_html( $stage_label ); ?></div>
            </div>

            <!-- Column headers -->
            <div class="fh-ab-cols">
                <div class="fh-ab-col-hd">SPECIES</div>
                <div class="fh-ab-col-hd">STATUS</div>
                <div class="fh-ab-col-hd">TANK</div>
                <div class="fh-ab-col-hd">ARRIVED</div>
                <div class="fh-ab-col-hd">QT POS</div>
            </div>

            <!-- Data rows -->
            <div id="fh-ab-body">
            <?php foreach ( $species as $idx => $sp ) :
                $st        = $sp['status'] ?? 'in_transit';
                $st_label  = $status_labels[ $st ] ?? strtoupper( $st );
                $st_class  = $status_classes[ $st ] ?? 'fh-ab-status-transit';
                $row_class = $row_classes[ $st ] ?? '';
                $name      = strtoupper( mb_substr( is_array( $sp ) && isset( $sp['name'] ) ? $sp['name'] : ( $sp['common_name'] ?? '' ), 0, 20 ) );
                $tank      = strtoupper( $sp['tank'] ?? '—' );
                $recv      = isset( $sp['recv'] ) ? $sp['recv'] : ( $sp['qty_received'] ?? 0 );
                $ordered   = isset( $sp['ordered'] ) ? $sp['ordered'] : ( $sp['qty_ordered'] ?? 0 );
                $arrived_display = $recv . ' / ' . $ordered;
                $qt_pos    = '—';
            ?>
            <div class="fh-ab-row <?php echo esc_attr( $row_class ); ?>" data-fish-id="<?php echo intval( $sp['fish_id'] ?? $idx ); ?>" data-updated="<?php echo intval( $sp['updated_at'] ?? 0 ); ?>">
                <div class="fh-ab-cell"><div class="fh-ab-tiles" data-fh-ab="<?php echo esc_attr( $name ); ?>"></div></div>
                <div class="fh-ab-cell"><span class="fh-ab-status-text <?php echo esc_attr( $st_class ); ?>" data-fh-st="<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st_label ); ?></span></div>
                <div class="fh-ab-cell"><div class="fh-ab-tiles" data-fh-ab="<?php echo esc_attr( $tank ); ?>"></div></div>
                <div class="fh-ab-cell"><div class="fh-ab-tiles" data-fh-ab="<?php echo esc_attr( $arrived_display ); ?>"></div></div>
                <div class="fh-ab-cell"><div class="fh-ab-tiles" data-fh-ab="<?php echo esc_attr( $qt_pos ); ?>"></div></div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Footer -->
            <div class="fh-ab-footer">
                <div class="fh-ab-fl">
                    <span class="fh-ab-fl-lit"><?php echo esc_html( $origin ); ?></span>
                    &middot; <?php echo esc_html( strtoupper( $batch_name ) ); ?>
                </div>
                <div class="fh-ab-fr">LAST UPDATED: <span id="fh-ab-time"><?php echo date( 'H:i:s' ); ?></span></div>
            </div>
        </div>
        </div>

        <script>
        (function(){
            var AMBER_SHADES = ['#c8a84b','#d4bc7e','#b89640','#c4a055','#c09848','#d8c080','#bfa24a','#cbb060'];
            var CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var rowCounter = 0;

            function tileHash(row, col) {
                return ((row * 7 + col * 13 + row * col * 3 + 37) * 2654435761) >>> 0;
            }

            function buildTilesAB(container, text, maxLen) {
                maxLen = maxLen || text.length || 1;
                text = text.toUpperCase();
                while (text.length < maxLen) text += ' ';
                container.innerHTML = '';
                var r = rowCounter++;
                for (var i = 0; i < maxLen; i++) {
                    var c = text[i] || ' ';
                    var flap = document.createElement('div');
                    flap.className = 'fh-ab-flap';
                    flap.setAttribute('data-char', c);
                    if (c !== ' ') flap.textContent = c;
                    var h = tileHash(r, i);
                    flap.style.color = AMBER_SHADES[h % AMBER_SHADES.length];
                    if (h % 11 === 0) flap.style.opacity = '0.85';
                    container.appendChild(flap);
                }
            }

            function animateRowAB(container, text, maxLen) {
                maxLen = maxLen || text.length || 1;
                text = text.toUpperCase();
                while (text.length < maxLen) text += ' ';
                var flaps = container.querySelectorAll('.fh-ab-flap');
                flaps.forEach(function(flap, i) {
                    var finalChar = text[i] || ' ';
                    var isSpace = finalChar === ' ';
                    var flipCount = 6 + Math.floor(Math.random() * 5);
                    var step = 0;
                    setTimeout(function() {
                        var iv = setInterval(function() {
                            step++;
                            if (step >= flipCount) {
                                clearInterval(iv);
                                flap.textContent = isSpace ? '' : finalChar;
                                return;
                            }
                            flap.textContent = CHARS[Math.floor(Math.random() * CHARS.length)];
                        }, 60);
                    }, i * 30);
                });
            }

            // Column max lengths for consistent tile widths
            var COL_LENS = { species: 20, tank: 5, arrived: 7, qtpos: 3 };

            // Initial build + staggered cascade
            requestAnimationFrame(function() {
                var rows = document.querySelectorAll('.fh-ab-row');
                rows.forEach(function(row, rIdx) {
                    var tiles = row.querySelectorAll('.fh-ab-tiles[data-fh-ab]');
                    setTimeout(function() {
                        tiles.forEach(function(t, cIdx) {
                            var text = t.getAttribute('data-fh-ab') || '';
                            var lens = [COL_LENS.species, 0, COL_LENS.tank, COL_LENS.arrived, COL_LENS.qtpos];
                            var len = lens[cIdx] || text.length || 1;
                            buildTilesAB(t, text, len);
                            animateRowAB(t, text, len);
                        });
                    }, rIdx * 80);
                });
            });

            // Status label map
            var STATUS_LABELS = {
                'in_transit':'IN TRANSIT','landed':'LANDED','counting':'COUNTING',
                'in_quarantine':'IN QT','short':'SHORT','no_arrival':'NO ARRIVAL'
            };
            var STATUS_CSS = {
                'in_quarantine':'fh-ab-status-qt','short':'fh-ab-status-short',
                'no_arrival':'fh-ab-status-noarr','in_transit':'fh-ab-status-transit',
                'landed':'fh-ab-status-landed','counting':'fh-ab-status-counting'
            };
            var ROW_CSS = {
                'in_quarantine':'fh-ab-qt','short':'fh-ab-short','no_arrival':'fh-ab-noarr'
            };

            // Auto-poll every 15 seconds
            var boardEl = document.getElementById('fh-arrival-board');
            var batchSlug = boardEl ? boardEl.getAttribute('data-batch-slug') : '';
            if (batchSlug) {
                setInterval(function() {
                    var apiUrl = '/wp-json/fishotel/v1/arrival-status/' + batchSlug;
                    fetch(apiUrl).then(function(r){ return r.json(); }).then(function(data) {
                        if (!data || !data.species) return;
                        var rows = document.querySelectorAll('.fh-ab-row');
                        data.species.forEach(function(sp) {
                            rows.forEach(function(row) {
                                if (parseInt(row.getAttribute('data-fish-id')) !== sp.fish_id) return;
                                var oldUpdated = parseInt(row.getAttribute('data-updated')) || 0;
                                if (sp.updated_at <= oldUpdated) return;
                                row.setAttribute('data-updated', sp.updated_at);

                                // Update status text
                                var stEl = row.querySelector('.fh-ab-status-text');
                                if (stEl) {
                                    stEl.className = 'fh-ab-status-text ' + (STATUS_CSS[sp.status] || 'fh-ab-status-transit');
                                    stEl.textContent = STATUS_LABELS[sp.status] || sp.status.toUpperCase();
                                    stEl.setAttribute('data-fh-st', sp.status);
                                }

                                // Update row tint
                                row.className = 'fh-ab-row ' + (ROW_CSS[sp.status] || '');

                                // Re-animate changed tile cells
                                var tiles = row.querySelectorAll('.fh-ab-tiles[data-fh-ab]');
                                var newName = (sp.common_name || '').toUpperCase().substring(0, 20);
                                var newTank = (sp.tank || '—').toUpperCase();
                                var newArrived = sp.qty_received + ' / ' + sp.qty_ordered;
                                var newValues = [newName, newTank, newArrived, '—'];
                                var lens = [COL_LENS.species, COL_LENS.tank, COL_LENS.arrived, COL_LENS.qtpos];
                                tiles.forEach(function(t, i) {
                                    var oldText = t.getAttribute('data-fh-ab');
                                    var newText = newValues[i] || '';
                                    if (oldText !== newText) {
                                        t.setAttribute('data-fh-ab', newText);
                                        buildTilesAB(t, newText, lens[i]);
                                        animateRowAB(t, newText, lens[i]);
                                    }
                                });
                            });
                        });
                        // Update time
                        var timeEl = document.getElementById('fh-ab-time');
                        if (timeEl) {
                            var now = new Date();
                            timeEl.textContent = ('0'+now.getHours()).slice(-2)+':'+('0'+now.getMinutes()).slice(-2)+':'+('0'+now.getSeconds()).slice(-2);
                        }
                    }).catch(function(){});
                }, 15000);
            }
        })();
        </script>
        <?php
    }
}

new FisHotel_Batch_Manager();
new FisHotel_GitHub_Updater( __FILE__ );
