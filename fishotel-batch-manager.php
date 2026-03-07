<?php
/**
 * Plugin Name:       FisHotel Batch Manager
 * Description:       Stable 2.4.2 - Origin Locations collapsible toggle; Arrival Date field per batch; Origin Locations manager (admin) with 7 defaults; groundwork for transit page.
 * Version:           2.4.2
 * Author:            Dierks & Claude
 * Text Domain:       fishotel-batch-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FISHOTEL_VERSION', '2.4.2' );
define( 'FISHOTEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FISHOTEL_PLUGIN_FILE', __FILE__ );

require_once FISHOTEL_PLUGIN_DIR . 'includes/class-helpers.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-ajax.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-woocommerce.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-admin.php';
require_once FISHOTEL_PLUGIN_DIR . 'includes/class-updater.php';

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
        add_action( 'admin_post_fishotel_export_order_excel', [$this, 'export_order_excel'] );
        add_action( 'admin_post_fishotel_delete_batch',       [$this, 'delete_batch'] );
        add_action( 'admin_post_fishotel_add_location',       [$this, 'add_location_handler'] );
        add_action( 'admin_post_fishotel_delete_location',    [$this, 'delete_location_handler'] );

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

        add_action( 'woocommerce_after_checkout_form', [$this, 'add_return_to_fish_button'] );
        add_action( 'woocommerce_thankyou', [$this, 'add_return_to_fish_button'] );
    }
}

new FisHotel_Batch_Manager();
new FisHotel_GitHub_Updater( __FILE__ );
