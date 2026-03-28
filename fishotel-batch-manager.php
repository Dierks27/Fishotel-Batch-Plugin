<?php
/**
 * Plugin Name:       FisHotel Batch Manager
 * Description:       v9.10.22 - Draft Room smaller cards in standard popup, felt background.
 * Version:           9.10.22
 * Author:            Dierks & Claude
 * Text Domain:       fishotel-batch-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FISHOTEL_VERSION', '9.10.22' );
define( 'FISHOTEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FISHOTEL_PLUGIN_FILE', __FILE__ );

require_once FISHOTEL_PLUGIN_DIR . 'includes/class-helpers.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-ajax.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-woocommerce.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-admin.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-northstar.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-hotel-program.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-updater.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-casino.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-arcade.php';


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
        'casino'          => 'Casino Night',
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
    use FisHotel_NorthStar;
    use FisHotel_HotelProgram;

    private $is_syncing = false;

    public function __construct() {
        add_action( 'init', [$this, 'init'] );
        $this->hotel_program_init();
        add_action( 'fishotel_verification_cron', [$this, 'run_verification_cron'] );
        add_action( 'fishotel_lastcall_cron',     [$this, 'run_lastcall_cron'] );
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
        add_action( 'admin_post_fishotel_save_graduation_data', [$this, 'save_graduation_data_handler'] );
        add_action( 'admin_post_fishotel_open_lastcall',       [$this, 'open_lastcall_handler'] );
        add_action( 'admin_post_fishotel_reset_lastcall',      [$this, 'reset_lastcall_handler'] );
        add_action( 'admin_post_fishotel_lc_update_settings',  [$this, 'lc_update_settings_handler'] );
        add_action( 'admin_post_fishotel_lc_pool_update',      [$this, 'lc_pool_update_handler'] );
        add_action( 'admin_post_fishotel_lc_pool_remove',      [$this, 'lc_pool_remove_handler'] );
        add_action( 'admin_post_fishotel_lc_pool_add',         [$this, 'lc_pool_add_handler'] );
        add_action( 'admin_post_fishotel_lc_order_move',       [$this, 'lc_order_move_handler'] );
        add_action( 'admin_post_fishotel_lc_close_window',     [$this, 'lc_close_window_handler'] );
        add_action( 'admin_post_fishotel_save_admin_wishlist', [$this, 'save_admin_wishlist_handler'] );

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
        add_shortcode( 'fishotel_notifications', [$this, 'notifications_shortcode'] );

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
        add_action( 'wp_ajax_fishotel_northstar_fetch',  [$this, 'ajax_northstar_fetch'] );
        add_action( 'wp_ajax_fishotel_northstar_import', [$this, 'ajax_northstar_import'] );

        // Layer Designer AJAX
        add_action( 'wp_ajax_fishotel_save_layer_config',   [$this, 'ajax_save_layer_config'] );
        add_action( 'wp_ajax_fishotel_upload_layer_asset',  [$this, 'ajax_upload_layer_asset'] );
        add_action( 'wp_ajax_fishotel_delete_layer_asset',  [$this, 'ajax_delete_layer_asset'] );
        add_action( 'wp_ajax_fishotel_get_layer_assets',    [$this, 'ajax_get_layer_assets'] );

        // Scene Types AJAX
        add_action( 'wp_ajax_fishotel_save_scene_types',    [$this, 'ajax_save_scene_types'] );

        // Asset Library AJAX
        add_action( 'wp_ajax_fishotel_scan_assets',         [$this, 'ajax_scan_assets'] );
        add_action( 'wp_ajax_fishotel_get_assets',          [$this, 'ajax_get_assets'] );
        add_action( 'wp_ajax_fishotel_upload_asset',        [$this, 'ajax_upload_asset'] );
        add_action( 'wp_ajax_fishotel_save_asset_meta',     [$this, 'ajax_save_asset_meta'] );
        add_action( 'wp_ajax_fishotel_delete_asset',        [$this, 'ajax_delete_asset'] );
        add_action( 'wp_ajax_fishotel_bulk_update_assets',  [$this, 'ajax_bulk_update_assets'] );

        // Verification AJAX
        add_action( 'wp_ajax_fishotel_verification_accept', [$this, 'ajax_verification_accept'] );
        add_action( 'wp_ajax_fishotel_verification_pass',   [$this, 'ajax_verification_pass'] );
        add_action( 'wp_ajax_fishotel_dismiss_notification', [$this, 'ajax_dismiss_notification'] );
        add_action( 'wp_ajax_fishotel_run_cron_now',       [$this, 'ajax_run_cron_now'] );

        // Last Call AJAX
        add_action( 'wp_ajax_fishotel_save_lastcall_wishlist', [$this, 'ajax_save_lastcall_wishlist'] );
        add_action( 'wp_ajax_fishotel_run_lastcall_draft',      [$this, 'ajax_run_lastcall_draft'] );
        add_action( 'wp_ajax_fishotel_get_lastcall_pick',      [$this, 'ajax_get_lastcall_pick'] );
        add_action( 'wp_ajax_fishotel_get_lastcall_results',   [$this, 'ajax_get_lastcall_results'] );
        add_action( 'wp_ajax_nopriv_fishotel_get_lastcall_results', [$this, 'ajax_get_lastcall_results'] );
        add_action( 'wp_ajax_fishotel_mark_lastcall_seen',     [$this, 'ajax_mark_lastcall_seen'] );

        add_action( 'woocommerce_after_checkout_form', [$this, 'add_return_to_fish_button'] );
        add_action( 'woocommerce_thankyou', [$this, 'add_return_to_fish_button'] );
    }

    // ─── Helpers — Arrival Species ──────────────────────────────────────

    /**
     * Resolve the clean common name for a fish_batch post.
     * Tier 1: fish_master title via _master_id (uniform naming) + size suffix from batch title
     * Tier 2: fish_batch post title (suffix stripped)
     * Tier 3: if title looks like a scientific name, reverse-lookup fish_master by _scientific_name
     */
    public static function resolve_common_name( $batch_post_id, $batch_post_title = '' ) {
        $master_id = get_post_meta( $batch_post_id, '_master_id', true );
        if ( $master_id && get_post( $master_id ) ) {
            $master_name = preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', get_the_title( $master_id ) );

            // Append size suffix from batch title if present
            if ( ! empty( $batch_post_title ) ) {
                $batch_common = preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $batch_post_title );
                $batch_common = trim( $batch_common );
                // If batch common name is longer than master name, extract the size suffix
                if ( stripos( $batch_common, $master_name ) === 0 && strlen( $batch_common ) > strlen( $master_name ) ) {
                    $suffix = trim( substr( $batch_common, strlen( $master_name ) ) );
                    if ( ! empty( $suffix ) ) {
                        return $master_name . ' ' . $suffix;
                    }
                }
            }

            return $master_name;
        }

        $resolved = preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $batch_post_title );

        // Tier 3: scientific name reverse lookup
        $is_scientific = (
            strtoupper( $resolved ) === $resolved
            && strpos( $resolved, ' ' ) !== false
            && strpos( $resolved, ':' ) === false
        );
        if ( $is_scientific ) {
            $query = new \WP_Query( [
                'post_type'      => 'fish_master',
                'meta_query'     => [ [
                    'key'     => '_scientific_name',
                    'value'   => $resolved,
                    'compare' => 'LIKE',
                ] ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] );
            if ( ! empty( $query->posts ) ) {
                return preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', get_the_title( $query->posts[0] ) );
            }
        }

        return $resolved;
    }

    /**
     * Deduplicate a species array by common_name.
     * Sums qty_ordered, qty_received, qty_doa. Keeps highest-priority status.
     */
    public static function dedup_species( $species ) {
        $priority = [ 'in_quarantine' => 1, 'short' => 2, 'no_arrival' => 3, 'counting' => 4, 'landed' => 5, 'in_transit' => 6 ];
        $merged = [];
        foreach ( $species as $sp ) {
            $key = $sp['common_name'] ?? ( $sp['name'] ?? '' );
            if ( ! isset( $merged[ $key ] ) ) {
                $merged[ $key ] = $sp;
            } else {
                $m = &$merged[ $key ];
                $m['qty_ordered']  = ( $m['qty_ordered'] ?? 0 ) + ( $sp['qty_ordered'] ?? 0 );
                $m['qty_received'] = ( $m['qty_received'] ?? 0 ) + ( $sp['qty_received'] ?? 0 );
                $m['qty_doa']      = ( $m['qty_doa'] ?? 0 ) + ( $sp['qty_doa'] ?? 0 );
                if ( isset( $sp['recv'] ) )    $m['recv']    = ( $m['recv'] ?? 0 ) + $sp['recv'];
                if ( isset( $sp['ordered'] ) ) $m['ordered'] = ( $m['ordered'] ?? 0 ) + $sp['ordered'];
                if ( isset( $sp['doa'] ) )     $m['doa']     = ( $m['doa'] ?? 0 ) + $sp['doa'];
                if ( isset( $sp['alive'] ) )   $m['alive']   = ( $m['alive'] ?? 0 ) + $sp['alive'];
                // Keep highest-priority status
                $cur_p = $priority[ $m['status'] ?? 'in_transit' ] ?? 99;
                $new_p = $priority[ $sp['status'] ?? 'in_transit' ] ?? 99;
                if ( $new_p < $cur_p ) $m['status'] = $sp['status'];
                // Keep latest updated_at
                if ( ( $sp['updated_at'] ?? 0 ) > ( $m['updated_at'] ?? 0 ) ) {
                    $m['updated_at'] = $sp['updated_at'] ?? 0;
                }
                // Concatenate tanks
                $old_tank = $m['tank'] ?? '';
                $new_tank = $sp['tank'] ?? '';
                if ( $new_tank && $new_tank !== '—' && $old_tank !== $new_tank ) {
                    $m['tank'] = trim( $old_tank . ',' . $new_tank, ',—' );
                }
                unset( $m );
            }
        }
        return array_values( $merged );
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

        $species_raw = [];
        foreach ( $batch_fish as $bp ) {
            $species_raw[] = [
                'fish_id'      => $bp->ID,
                'common_name'  => self::resolve_common_name( $bp->ID, $bp->post_title ),
                'qty_ordered'  => $demand[ $bp->ID ] ?? 0,
                'qty_received' => ( ( $cq = get_post_meta( $bp->ID, '_current_qty', true ) ) !== '' && $cq !== false ) ? intval( $cq ) : intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) ),
                'qty_doa'      => intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) ),
                'tank'         => get_post_meta( $bp->ID, '_arrival_tank', true ) ?: '',
                'status'       => get_post_meta( $bp->ID, '_arrival_status', true ) ?: 'in_transit',
                'updated_at'   => intval( get_post_meta( $bp->ID, '_arrival_updated_at', true ) ),
            ];
        }
        $species = self::dedup_species( $species_raw );

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

        $species_raw = [];
        foreach ( $batch_fish as $bp ) {
            $cname = self::resolve_common_name( $bp->ID, $bp->post_title );
            $cq    = get_post_meta( $bp->ID, '_current_qty', true );
            $recv  = ( $cq !== '' && $cq !== false ) ? intval( $cq ) : intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
            $doa   = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
            $species_raw[] = [
                'common_name' => $cname,
                'name'        => strtoupper( $cname ),
                'recv'        => $recv,
                'doa'         => $doa,
                'alive'       => $recv - $doa,
                'ordered'     => $demand[ $bp->ID ] ?? 0,
                'qty_ordered' => $demand[ $bp->ID ] ?? 0,
                'qty_received'=> $recv,
                'qty_doa'     => $doa,
                'tank'        => get_post_meta( $bp->ID, '_arrival_tank', true ) ?: '—',
                'status'      => get_post_meta( $bp->ID, '_arrival_status', true ) ?: 'in_transit',
                'updated_at'  => intval( get_post_meta( $bp->ID, '_arrival_updated_at', true ) ),
            ];
        }
        $species = self::dedup_species( $species_raw );
        // Recompute derived fields after dedup
        foreach ( $species as &$sp ) {
            $sp['name']  = strtoupper( $sp['common_name'] );
            $sp['alive'] = ( $sp['recv'] ?? $sp['qty_received'] ) - ( $sp['doa'] ?? $sp['qty_doa'] );
        }
        unset( $sp );

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
<?php $this->render_arrival_board_html( $matched, $stage_label, $species, $batch_slug, $arrival_date, true, ( $batch_status === 'arrived' ) ); ?>
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
            display:flex; align-items:center; padding:6px 4px; background:#111;
            border-bottom:1px solid #1a1a10; position:relative; z-index:2;
        }
        .fh-ab-col-hd {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:0.12em; color:#8a7a50; padding:2px 4px;
        }

        /* Data rows */
        .fh-ab-row {
            display:flex; align-items:center; width:100%;
            padding:4px 4px; border-bottom:1px solid #0d0d0d;
            box-shadow:inset 0 1px 0 #252520;
            position:relative; z-index:2;
            transition:background 0.3s; box-sizing:border-box;
        }
        .fh-ab-row:last-child { border-bottom:none; box-shadow:none; }
        .fh-ab-row.fh-ab-qt { background:rgba(0,255,100,0.04); }
        .fh-ab-row.fh-ab-short { background:rgba(255,180,0,0.06); }
        .fh-ab-row.fh-ab-noarr { background:rgba(255,60,60,0.05); }

        /* Status indicator — mechanical readout style */
        .fh-ab-badge {
            flex:1 1 0; font-family:'Courier New',monospace; font-size:14px;
            font-weight:700; text-transform:uppercase; letter-spacing:0.1em;
            padding:3px 8px; border-radius:0; white-space:nowrap;
            text-align:center; margin:1px 6px; box-sizing:border-box;
            background:transparent; line-height:26px; height:auto;
        }
        .fh-ab-badge-qt { color:#44ff88; border:1px solid rgba(68,255,136,0.3); box-shadow:0 0 2px rgba(68,255,136,0.15); text-shadow:0 0 6px rgba(68,255,136,0.5); }
        .fh-ab-badge-short { color:#ffaa33; border:1px solid rgba(255,170,51,0.3); box-shadow:0 0 2px rgba(255,170,51,0.15); text-shadow:0 0 6px rgba(255,170,51,0.5); }
        .fh-ab-badge-noarr { color:#ff5555; border:1px solid rgba(255,85,85,0.3); box-shadow:0 0 2px rgba(255,85,85,0.15); text-shadow:0 0 6px rgba(255,85,85,0.5); }
        .fh-ab-badge-transit { color:#8a7a50; border:1px solid rgba(138,122,80,0.3); box-shadow:0 0 2px rgba(138,122,80,0.15); text-shadow:0 0 6px rgba(138,122,80,0.5); }
        .fh-ab-badge-counting { color:#d4bc7e; border:1px solid rgba(212,188,126,0.3); box-shadow:0 0 2px rgba(212,188,126,0.15); text-shadow:0 0 6px rgba(212,188,126,0.5); }
        .fh-ab-badge-landed { color:#66ccff; border:1px solid rgba(102,204,255,0.3); box-shadow:0 0 2px rgba(102,204,255,0.15); text-shadow:0 0 6px rgba(102,204,255,0.5); }
        .fh-ab-badge-pending { color:#d4a017; border:1px solid rgba(212,160,23,0.3); box-shadow:0 0 2px rgba(212,160,23,0.15); text-shadow:0 0 6px rgba(212,160,23,0.5); }
        .fh-ab-badge-doa { color:#ff5555; border:1px solid rgba(255,85,85,0.3); box-shadow:0 0 2px rgba(255,85,85,0.15); text-shadow:0 0 6px rgba(255,85,85,0.5); }

        /* Flap tile groups */
        .fh-ab-tiles {
            display:flex; flex-wrap:nowrap; gap:0; overflow:hidden; flex-shrink:0;
        }
        .fh-ab-flap {
            width:var(--fh-tile-w, 18px); height:30px;
            background:#0d1117; border-radius:2px;
            box-shadow:inset 0 1px 0 rgba(255,255,255,0.04), 0 2px 0 #0a0806, 0 1px 4px rgba(0,0,0,0.9);
            display:flex; align-items:center; justify-content:center;
            font-family:'Courier New',monospace; font-weight:700;
            font-size:20px; color:#cdc2a4; letter-spacing:-0.5px;
            text-transform:uppercase; position:relative; flex-shrink:0;
        }
        .fh-ab-flap:nth-child(odd) { background:#0b0f14; }

        /* Arrived column: two tile groups with a board-painted slash divider */
        .fh-ab-arrived {
            display:flex; align-items:center; flex-shrink:0;
            margin-left:auto;
        }
        .fh-ab-slash {
            width:14px; height:30px; display:flex; align-items:center; justify-content:center;
            padding:0 4px;
            font-family:'Courier New',monospace; font-weight:700; font-size:14px;
            color:#5a5030; text-shadow:0 0 2px rgba(90,80,48,0.4);
            flex-shrink:0; user-select:none;
        }
        .fh-ab-flap::after {
            content:''; position:absolute; left:0; top:50%;
            width:100%; height:1px; background:#000;
        }

        /* Mobile card list — hidden at all sizes; desktop board scales down instead */
        .fh-ab-mobile { display:none !important; }

        /* ── Responsive: scale desktop board on mobile ── */
        @media (max-width:700px) {
            .fh-ab {
                overflow-x:hidden;
            }
            .fh-ab-row {
                display:flex !important;
            }
            .fh-ab-flap {
                width:13px !important;
                height:24px !important;
                font-size:12px !important;
                line-height:24px !important;
            }
            .fh-ab-badge {
                font-size:8px !important;
                padding:2px 3px !important;
                margin:1px 3px !important;
                line-height:20px !important;
            }
            .fh-ab-header {
                font-size:11px !important;
            }
            .fh-ab-hl { font-size:13px; }
            .fh-ab-hr, .fh-ab-fl, .fh-ab-fr { font-size:10px; }
            .fh-ab-header, .fh-ab-footer { padding:8px 12px; }
            .fh-ab-col-hd {
                font-size:9px !important;
            }
            .fh-ab-slash {
                width:10px; padding:0 2px; font-size:12px;
            }
        }
        <?php
    }

    // ─── Arrival Board — Reusable HTML + JS ───────────────────────────
    public function render_arrival_board_html( $batch_name, $stage_label, $species, $batch_slug, $arrival_date, $is_embed = false, $counting_in_progress = false ) {
        $status_labels = [
            'in_transit'     => 'IN TRANSIT',
            'landed'         => 'LANDED',
            'counting'       => 'COUNTING',
            'in_quarantine'  => 'IN QT',
            'short'          => 'SHORT',
            'no_arrival'     => 'NO ARRIVAL',
            'pending'        => 'PENDING',
        ];
        $row_classes = [
            'in_quarantine' => 'fh-ab-qt',
            'short'         => 'fh-ab-short',
            'no_arrival'    => 'fh-ab-noarr',
            'pending'       => '',
        ];
        $badge_classes = [
            'in_quarantine' => 'fh-ab-badge-qt',
            'short'         => 'fh-ab-badge-short',
            'no_arrival'    => 'fh-ab-badge-noarr',
            'in_transit'    => 'fh-ab-badge-transit',
            'landed'        => 'fh-ab-badge-landed',
            'counting'      => 'fh-ab-badge-counting',
            'pending'       => 'fh-ab-badge-pending',
            'doa'           => 'fh-ab-badge-doa',
        ];
        $origin = strtoupper( preg_split( '/[\s\-]/', $batch_name )[0] ?? $batch_name );
        $col_limit = 20; // species tile count
        $total_tiles = 24; // 20 species + 2 arrived-left + 2 arrived-right
        ?>
        <div class="fh-ab-wrap" id="fh-arrival-board" data-batch-slug="<?php echo esc_attr( $batch_slug ); ?>" data-total-tiles="<?php echo $total_tiles; ?>">
        <div class="fh-ab">
            <!-- Header -->
            <div class="fh-ab-header">
                <div class="fh-ab-hl">
                    <span style="opacity:0.7;font-size:16px;">&#x2708;</span>
                    MSP INTL &middot; ARRIVALS
                </div>
                <div class="fh-ab-hr"><?php echo esc_html( $stage_label ); ?></div>
            </div>

            <!-- Column headers -->
            <div class="fh-ab-cols" id="fh-ab-cols">
                <div class="fh-ab-col-hd fh-ab-col-species">SPECIES</div>
                <div class="fh-ab-col-hd" style="flex:1 1 0; text-align:center;">STATUS</div>
                <div class="fh-ab-col-hd fh-ab-col-arrived" style="margin-left:auto; text-align:center;">ARRIVED</div>
            </div>

            <!-- Data rows: species tiles + badge + arrived tiles -->
            <div id="fh-ab-body">
            <?php foreach ( $species as $idx => $sp ) :
                $st         = $sp['status'] ?? 'in_transit';
                // During counting, show PENDING instead of alarming NO ARRIVAL / IN TRANSIT
                if ( $counting_in_progress && in_array( $st, [ 'no_arrival', 'in_transit' ], true ) ) {
                    $st = 'pending';
                }
                $st_label   = $status_labels[ $st ] ?? strtoupper( $st );
                $row_class  = $row_classes[ $st ] ?? '';
                $badge_cls  = $badge_classes[ $st ] ?? 'fh-ab-badge-transit';
                // Word-boundary truncation: cut at last full word that fits
                $raw_name   = strtoupper( $sp['common_name'] ?? ( $sp['name'] ?? '' ) );
                $len        = mb_strlen( $raw_name );
                if ( $len > $col_limit ) {
                    $sp_pos = mb_strrpos( $raw_name, ' ', -( $len - $col_limit ) );
                    $name   = $sp_pos !== false ? mb_substr( $raw_name, 0, $sp_pos ) : mb_substr( $raw_name, 0, $col_limit );
                } else {
                    $name = $raw_name;
                }
                $recv       = intval( $sp['recv'] ?? ( $sp['qty_received'] ?? 0 ) );
                $ordered    = intval( $sp['ordered'] ?? ( $sp['qty_ordered'] ?? 0 ) );
                $arr_left   = ( $recv < 10 ? ' ' : '' ) . $recv;
                $arr_right  = ( $ordered < 10 ? ' ' : '' ) . $ordered;
            ?>
            <div class="fh-ab-row <?php echo esc_attr( $row_class ); ?>" data-fish-id="<?php echo intval( $sp['fish_id'] ?? $idx ); ?>" data-updated="<?php echo intval( $sp['updated_at'] ?? 0 ); ?>" data-status="<?php echo esc_attr( $st ); ?>" data-recv="<?php echo $recv; ?>" data-ordered="<?php echo $ordered; ?>">
                <div class="fh-ab-tiles" data-fh-ab="<?php echo esc_attr( $name ); ?>" data-fh-full="<?php echo esc_attr( $raw_name ); ?>" data-fh-col="0"></div>
                <span class="fh-ab-badge <?php echo esc_attr( $badge_cls ); ?>" data-fh-status="<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st_label ); ?></span>
                <div class="fh-ab-arrived">
                    <div class="fh-ab-tiles" data-fh-ab="<?php echo esc_attr( $arr_left ); ?>" data-fh-col="1"></div>
                    <span class="fh-ab-slash">/</span>
                    <div class="fh-ab-tiles" data-fh-ab="<?php echo esc_attr( $arr_right ); ?>" data-fh-col="2"></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Mobile card list (hidden on desktop) -->
            <div class="fh-ab-mobile" id="fh-ab-mobile">
            <?php
            $mobile_badge = [ 'in_quarantine' => 'fh-ab-mbadge-qt', 'short' => 'fh-ab-mbadge-short', 'no_arrival' => 'fh-ab-mbadge-noarr', 'in_transit' => 'fh-ab-mbadge-transit', 'landed' => 'fh-ab-mbadge-landed', 'counting' => 'fh-ab-mbadge-counting', 'pending' => 'fh-ab-mbadge-pending' ];
            foreach ( $species as $idx => $sp ) :
                $st       = $sp['status'] ?? 'in_transit';
                if ( $counting_in_progress && in_array( $st, [ 'no_arrival', 'in_transit' ], true ) ) {
                    $st = 'pending';
                }
                $st_label = $status_labels[ $st ] ?? strtoupper( $st );
                $m_badge  = $mobile_badge[ $st ] ?? 'fh-ab-mbadge-transit';
                $m_name   = $sp['common_name'] ?? ( $sp['name'] ?? '' );
            ?>
            <div class="fh-ab-mrow" data-fish-id="<?php echo intval( $sp['fish_id'] ?? $idx ); ?>" data-status="<?php echo esc_attr( $st ); ?>">
                <div class="fh-ab-mleft">
                    <span class="fh-ab-mname"><?php echo esc_html( $m_name ); ?></span>
                </div>
                <span class="fh-ab-mbadge <?php echo esc_attr( $m_badge ); ?>"><?php echo esc_html( $st_label ); ?></span>
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
            var AMBER_SHADES = ['#cdc2a4','#d6ccb2','#c2b89a','#c9c0a5','#c5bb9c','#d8d0ba','#c3b99e','#cfc7ac'];
            var CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/# ';
            var rowCounter = 0;
            var COL_LENS = [20, 2, 2]; // species, arrived-left, arrived-right
            var TOTAL_TILES = 24;
            var MOBILE_BP = 700;
            function updateColLens() {
                var isMobile = window.innerWidth <= MOBILE_BP;
                COL_LENS[0] = isMobile ? 18 : 20;
                TOTAL_TILES = COL_LENS[0] + COL_LENS[1] + COL_LENS[2];
            }
            updateColLens();

            function tileHash(row, col) {
                return ((row * 7 + col * 13 + row * col * 3 + 37) * 2654435761) >>> 0;
            }

            function truncWord(str, maxLen) {
                str = str.toUpperCase();
                if (str.length <= maxLen) return str;
                var sub = str.substring(0, maxLen);
                var ls = sub.lastIndexOf(' ');
                return ls > 0 ? sub.substring(0, ls) : sub;
            }

            // Calculate tile width from container and set CSS variable
            function calcTileWidth() {
                updateColLens();
                var board = document.querySelector('.fh-ab');
                if (!board) return;
                var style = getComputedStyle(board);
                var boardW = board.clientWidth - parseFloat(style.paddingLeft || 0) - parseFloat(style.paddingRight || 0);
                // Subtract row padding (4px each side), min badge space, slash (14px + 8px padding)
                var rowPad = 8; // 4px * 2
                var badgeMin = 90; // minimum space for the flex badge
                var slashW = 22; // 14px + 4px padding each side
                var available = boardW - rowPad - badgeMin - slashW;
                var tileW = Math.floor(available / TOTAL_TILES);
                board.style.setProperty('--fh-tile-w', tileW + 'px');
                // Align column headers: species flush-left, arrived right-aligned
                var colSpecies = document.querySelector('.fh-ab-col-species');
                var colArrived = document.querySelector('.fh-ab-col-arrived');
                if (colSpecies) colSpecies.style.flex = '1 1 auto';
                if (colArrived) colArrived.style.flex = '0 0 ' + (tileW * (COL_LENS[1] + COL_LENS[2]) + slashW) + 'px';
            }

            function buildTilesAB(container, text, maxLen) {
                text = text.toUpperCase().substring(0, maxLen);
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
                text = text.toUpperCase().substring(0, maxLen);
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

            function fmtArrivedLeft(r) {
                return (r < 10 ? ' ' + r : '' + r);
            }
            function fmtArrivedRight(o) {
                return (o < 10 ? ' ' + o : '' + o);
            }

            // Calculate tile width, then build + animate
            calcTileWidth();
            requestAnimationFrame(function() {
                var rows = document.querySelectorAll('.fh-ab-row');
                rows.forEach(function(row, rIdx) {
                    var tiles = row.querySelectorAll('.fh-ab-tiles[data-fh-ab]');
                    setTimeout(function() {
                        tiles.forEach(function(t) {
                            var text = t.getAttribute('data-fh-ab') || '';
                            var col = parseInt(t.getAttribute('data-fh-col'));
                            var len = COL_LENS[col] || text.length || 1;
                            if (col === 0) text = truncWord(text, len);
                            buildTilesAB(t, text, len);
                            animateRowAB(t, text, len);
                        });
                    }, rIdx * 80);
                });
            });
            // Recalculate on resize
            window.addEventListener('resize', calcTileWidth);

            // Flip-cycle for long species names: break into readable word-boundary chunks,
            // pause on each chunk, then flip to the next like a real Solari board
            setTimeout(function() {
                var specLen = COL_LENS[0];
                document.querySelectorAll('.fh-ab-row').forEach(function(row) {
                    var specTiles = row.querySelector('.fh-ab-tiles[data-fh-col="0"]');
                    if (!specTiles) return;
                    var fullName = (specTiles.getAttribute('data-fh-full') || '').toUpperCase();
                    if (fullName.length <= specLen) return;
                    // Build word-boundary chunks that fit the tile width
                    var chunks = [];
                    var words = fullName.split(' ');
                    var line = '';
                    for (var w = 0; w < words.length; w++) {
                        var test = line ? line + ' ' + words[w] : words[w];
                        if (test.length <= specLen) {
                            line = test;
                        } else {
                            if (line) chunks.push(line);
                            line = words[w].length <= specLen ? words[w] : words[w].substring(0, specLen);
                        }
                    }
                    if (line) chunks.push(line);
                    if (chunks.length < 2) return; // only one chunk, nothing to cycle
                    var idx = 0; // chunk 0 is already displayed
                    setInterval(function() {
                        idx = (idx + 1) % chunks.length;
                        var text = chunks[idx];
                        specTiles.setAttribute('data-fh-ab', text);
                        animateRowAB(specTiles, text, specLen);
                    }, 4000);
                });
            }, 4000); // wait for initial build+animate to finish

            // Polling maps
            var STATUS_LABELS = {
                'in_transit':'IN TRANSIT','landed':'LANDED','counting':'COUNTING',
                'in_quarantine':'IN QT','short':'SHORT','no_arrival':'NO ARRIVAL',
                'pending':'PENDING'
            };
            var ROW_CSS = {
                'in_quarantine':'fh-ab-qt','short':'fh-ab-short','no_arrival':'fh-ab-noarr'
            };
            var BADGE_CSS = {
                'in_quarantine':'fh-ab-badge-qt','short':'fh-ab-badge-short',
                'no_arrival':'fh-ab-badge-noarr','in_transit':'fh-ab-badge-transit',
                'landed':'fh-ab-badge-landed','counting':'fh-ab-badge-counting',
                'pending':'fh-ab-badge-pending','doa':'fh-ab-badge-doa'
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
                                var cn = truncWord((sp.common_name || '').toUpperCase(), 20);
                                var rowName = (row.querySelectorAll('.fh-ab-tiles[data-fh-col="0"]')[0] || {}).getAttribute('data-fh-ab') || '';
                                if (cn !== rowName && parseInt(row.getAttribute('data-fish-id')) !== sp.fish_id) return;

                                var oldUpdated = parseInt(row.getAttribute('data-updated')) || 0;
                                if (sp.updated_at <= oldUpdated) return;
                                row.setAttribute('data-updated', sp.updated_at);

                                row.className = 'fh-ab-row ' + (ROW_CSS[sp.status] || '');
                                row.setAttribute('data-status', sp.status);

                                var badge = row.querySelector('.fh-ab-badge');
                                if (badge) {
                                    badge.className = 'fh-ab-badge ' + (BADGE_CSS[sp.status] || 'fh-ab-badge-transit');
                                    badge.textContent = STATUS_LABELS[sp.status] || sp.status.toUpperCase();
                                    badge.setAttribute('data-fh-status', sp.status);
                                }

                                // Re-animate changed tile columns (0=species, 1=arrived-left, 2=arrived-right)
                                var newValues = [cn, fmtArrivedLeft(sp.qty_received), fmtArrivedRight(sp.qty_ordered)];
                                row.querySelectorAll('.fh-ab-tiles[data-fh-col]').forEach(function(t) {
                                    var col = parseInt(t.getAttribute('data-fh-col'));
                                    var oldText = t.getAttribute('data-fh-ab');
                                    var newText = newValues[col] || '';
                                    if (oldText !== newText) {
                                        t.setAttribute('data-fh-ab', newText);
                                        buildTilesAB(t, newText, COL_LENS[col]);
                                        animateRowAB(t, newText, COL_LENS[col]);
                                    }
                                });
                            });
                        });
                        // Update mobile rows
                        var MBADGE = {'in_quarantine':'fh-ab-mbadge-qt','short':'fh-ab-mbadge-short','no_arrival':'fh-ab-mbadge-noarr','in_transit':'fh-ab-mbadge-transit','landed':'fh-ab-mbadge-landed','counting':'fh-ab-mbadge-counting'};
                        var mrows = document.querySelectorAll('.fh-ab-mrow');
                        data.species.forEach(function(sp) {
                            mrows.forEach(function(mr) {
                                if (parseInt(mr.getAttribute('data-fish-id')) !== sp.fish_id) {
                                    var mname = mr.querySelector('.fh-ab-mname');
                                    if (!mname || mname.textContent.toUpperCase().substring(0,20) !== (sp.common_name||'').toUpperCase().substring(0,20)) return;
                                }
                                mr.setAttribute('data-status', sp.status);
                                var mb = mr.querySelector('.fh-ab-mbadge');
                                if (mb) { mb.className = 'fh-ab-mbadge ' + (MBADGE[sp.status] || 'fh-ab-mbadge-transit'); mb.textContent = STATUS_LABELS[sp.status] || sp.status.toUpperCase(); }
                            });
                        });

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

$fishotel_instance = new FisHotel_Batch_Manager();
new FisHotel_Casino();
new FisHotel_Arcade();
new FisHotel_GitHub_Updater( __FILE__ );

register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'fishotel_verification_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'fishotel_verification_cron' );
    }
    if ( ! wp_next_scheduled( 'fishotel_lastcall_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'fishotel_lastcall_cron' );
    }
} );

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'fishotel_verification_cron' );
    wp_clear_scheduled_hook( 'fishotel_lastcall_cron' );
} );
