<?php
/**
 * Plugin Name:       FisHotel Batch Manager
 * Description:       Stable 2.1.7 - Code audit pass: removed dead .fishotel-admin-page CSS (was never applied to any element).
 * Version:           2.1.7
 * Author:            Dierks & Claude
 * Text Domain:       fishotel-batch-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Batch_Manager {

    private $is_syncing = false;

    public function __construct() {
        add_action( 'init', [$this, 'init'] );
        add_action( 'admin_menu', [$this, 'add_admin_menu'] );
        add_action( 'admin_init', [$this, 'register_settings'] );
        add_action( 'admin_post_fishotel_import_csv', [$this, 'handle_csv_import'] );
        add_action( 'admin_post_fishotel_process_mapping', [$this, 'process_mapping'] );
        add_action( 'admin_post_fishotel_create_product_from_master', [$this, 'create_product_from_master'] );
        add_action( 'admin_post_fishotel_delete_batch_item', [$this, 'delete_batch_item'] );
        add_action( 'admin_post_fishotel_add_batch', [$this, 'add_batch'] );
        add_action( 'admin_post_fishotel_cancel_request', [$this, 'admin_cancel_request'] );
        add_action( 'admin_post_fishotel_remove_single_fish', [$this, 'admin_remove_single_fish'] );
        add_action( 'admin_post_fishotel_adjust_wallet', [$this, 'admin_adjust_wallet'] );
        add_action( 'admin_post_fishotel_mark_deposit_paid', [$this, 'mark_deposit_paid_handler'] );
        add_action( 'admin_post_fishotel_mark_deposit_unpaid', [$this, 'mark_deposit_unpaid_handler'] );
        add_action( 'admin_post_fishotel_fully_delete_deposit', [$this, 'fully_delete_deposit_handler'] );
        add_action( 'admin_post_fishotel_reset_test_data', [$this, 'reset_test_data_handler'] );

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

        // Secure AJAX with nonces
        add_action( 'wp_ajax_fishotel_submit_requests', [$this, 'ajax_submit_requests'] );
        add_action( 'wp_ajax_nopriv_fishotel_submit_requests', [$this, 'ajax_submit_requests'] );
        add_action( 'wp_ajax_fishotel_ajax_login', [$this, 'ajax_login'] );
        add_action( 'wp_ajax_nopriv_fishotel_ajax_login', [$this, 'ajax_login'] );
        add_action( 'wp_ajax_fishotel_save_hf_username', [$this, 'ajax_save_hf_username'] );
        add_action( 'wp_ajax_nopriv_fishotel_save_hf_username', [$this, 'ajax_save_hf_username'] );
        add_action( 'wp_ajax_fishotel_check_balance', [$this, 'ajax_check_balance'] );
        add_action( 'wp_ajax_fishotel_get_order_details', [$this, 'ajax_get_order_details'] );
        add_action( 'wp_ajax_fishotel_remove_from_order', [$this, 'ajax_remove_from_order'] );

        add_action( 'woocommerce_after_checkout_form', [$this, 'add_return_to_fish_button'] );
        add_action( 'woocommerce_thankyou', [$this, 'add_return_to_fish_button'] );
    }

    // ==================== HELPERS ====================
    private function get_deposit_amount( $batch_name = '' ) {
        $amounts = get_option( 'fishotel_batch_deposit_amounts', [] );
        if ( ! empty( $batch_name ) ) {
            $key = sanitize_title( $batch_name );
            if ( isset( $amounts[$key] ) && (float) $amounts[$key] > 0 ) {
                return (float) $amounts[$key];
            }
        }
        return 25.0; // Safety fallback — set deposit amount per batch in Section 5
    }
    private function get_deposit_product_id() { 
        return (int) get_option( 'fishotel_deposit_product_id', 31985 ); 
    }

    private function get_paid_deposits( $user_id ) {
        $paid = get_user_meta( $user_id, '_fishotel_paid_deposits', true );
        return is_array( $paid ) ? $paid : [];
    }

    private function mark_deposit_paid( $user_id, $batch_name, $amount = 0, $order_id = 0, $manual = false ) {
        $paid = $this->get_paid_deposits( $user_id );
        $key = sanitize_title( $batch_name );
        $paid[$key] = [
            'amount'   => (float) $amount,
            'date'     => current_time( 'mysql' ),
            'order_id' => (int) $order_id,
            'manual'   => (bool) $manual,
            'notes'    => $manual ? 'Manually marked paid by admin' : ''
        ];
        update_user_meta( $user_id, '_fishotel_paid_deposits', $paid );
    }

    private function is_deposit_paid_for_batch( $user_id, $batch_name ) {
        if ( ! $user_id || empty( $batch_name ) ) return false;

        $paid = $this->get_paid_deposits( $user_id );
        $key = sanitize_title( $batch_name );
        if ( isset( $paid[$key] ) ) return true;

        $existing = get_posts([
            'post_type'   => 'fish_request',
            'meta_key'    => '_customer_id',
            'meta_value'  => $user_id,
            'meta_query'  => [
                ['key' => '_batch_name', 'value' => $batch_name],
                ['key' => '_cart_items', 'value' => '[]', 'compare' => '!='],
            ],
            'numberposts' => 1
        ]);

        if ( ! empty( $existing ) ) {
            $this->mark_deposit_paid( $user_id, $batch_name );
            return true;
        }
        return false;
    }

    private function get_user_deposit_balance( $user_id ) {
        return (float) get_user_meta( $user_id, '_fishotel_deposit_balance', true );
    }

    private function update_user_deposit_balance( $user_id, $amount ) {
        $current = $this->get_user_deposit_balance( $user_id );
        update_user_meta( $user_id, '_fishotel_deposit_balance', $current + $amount );
    }

    // ==================== AJAX HANDLERS (SECURED WITH NONCES) ====================
    public function ajax_submit_requests() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );
        $cart_items = json_decode( wp_unslash( $_POST['cart_items'] ), true );
        $total = floatval( $_POST['total'] );
        $batch_name = sanitize_text_field( $_POST['batch_name'] );
        $deposit_amount = $this->get_deposit_amount( $batch_name );

        if ( empty( $cart_items ) ) wp_send_json_error( [ 'message' => 'No items.' ] );

        $user_id = get_current_user_id();
        $is_admin_test = get_option( 'fishotel_admin_test_mode', 0 ) && current_user_can( 'manage_options' );

        $already_paid = $this->is_deposit_paid_for_batch( $user_id, $batch_name );
        $current_balance = $this->get_user_deposit_balance( $user_id );

        if ( $is_admin_test || $already_paid || $current_balance >= $deposit_amount ) {
            $request_id = wp_insert_post( [
                'post_type'   => 'fish_request',
                'post_title'  => 'Request #' . time() . ' - ' . $batch_name,
                'post_status' => 'publish',
            ]);

            if ( $request_id ) {
                $requested_at = current_time( 'mysql' );
                foreach ( $cart_items as &$item ) {
                    $item['requested_at'] = $requested_at;
                }
                unset( $item );

                update_post_meta( $request_id, '_customer_id', $user_id );
                update_post_meta( $request_id, '_batch_name', $batch_name );
                update_post_meta( $request_id, '_cart_items', wp_json_encode( $cart_items ) );
                update_post_meta( $request_id, '_total', $total );
                update_post_meta( $request_id, '_status', 'provisional' );
                update_post_meta( $request_id, '_deposit_verified', 1 );

                if ( ! $already_paid && ! $is_admin_test ) {
                    $this->update_user_deposit_balance( $user_id, -$deposit_amount );
                    $this->mark_deposit_paid( $user_id, $batch_name, $deposit_amount, 0, false );
                }

                foreach ( $cart_items as $item ) {
                    $batch_id = intval( $item['batch_id'] );
                    $qty = intval( $item['qty'] );
                    if ( $batch_id ) {
                        $current = (float) get_post_meta( $batch_id, '_stock', true );
                        update_post_meta( $batch_id, '_stock', max( 0, $current - $qty ) );
                    }
                }
            }

            wp_send_json_success( [ 
                'message' => 'Request saved and stock reserved.' . ( $already_paid ? ' (No additional deposit charged)' : '' )
            ] );
        } else {
            $needed = $deposit_amount - $current_balance;
            wp_send_json_error( [
                'message' => 'Insufficient wallet balance. Please deposit $' . number_format( $needed, 2 ) . ' to reach the minimum.',
                'needs_payment' => true,
                'deposit_due' => $needed
            ]);
        }
    }

    public function ajax_check_balance() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );
        $user_id = get_current_user_id();
        $batch_name = isset( $_POST['batch_name'] ) ? sanitize_text_field( $_POST['batch_name'] ) : '';

        $balance = $this->get_user_deposit_balance( $user_id );
        $deposit_amount = $this->get_deposit_amount( $batch_name );
        $already_paid = false;

        if ( ! empty( $batch_name ) ) {
            $already_paid = $this->is_deposit_paid_for_batch( $user_id, $batch_name );
        }

        $enough = $already_paid || ( $balance >= $deposit_amount );

        wp_send_json_success( [
            'enough_balance' => $enough,
            'already_paid'   => $already_paid,
            'needed'         => $enough ? 0 : ( $deposit_amount - $balance )
        ]);
    }

    public function ajax_login() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );
        $username = sanitize_text_field( $_POST['username'] );
        $password = $_POST['password'];

        $user = wp_signon( [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true
        ], false );

        if ( is_wp_error( $user ) ) {
            wp_send_json_error( [ 'message' => $user->get_error_message() ] );
        } else {
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID, true );
            wp_send_json_success( [ 'message' => 'Logged in successfully.' ] );
        }
    }

    public function ajax_save_hf_username() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );
        $hf_username = sanitize_text_field( $_POST['hf_username'] );
        $user_id = get_current_user_id();

        if ( $user_id ) {
            update_user_meta( $user_id, '_fishotel_humble_username', $hf_username );
            wp_send_json_success( [ 'message' => 'Username saved.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        }
    }

    public function ajax_get_order_details() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );
        $request_id = intval( $_POST['request_id'] );
        $request = get_post( $request_id );
        if ( ! $request || get_post_meta( $request_id, '_customer_id', true ) != get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
        }

        $batch_name = get_post_meta( $request_id, '_batch_name', true );
        $items = json_decode( get_post_meta( $request_id, '_cart_items', true ), true ) ?: [];

        $html = '<table class="modal-table"><thead><tr><th>Fish</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Total</th><th style="width:140px;"></th></tr></thead><tbody>';
        foreach ( $items as $index => $item ) {
            $line_total = $item['price'] * $item['qty'];
            $html .= '<tr>';
            $html .= '<td>' . esc_html( $item['fish_name'] ) . '</td>';
            $html .= '<td style="text-align:center;">' . $item['qty'] . '</td>';
            $html .= '<td style="text-align:right;">$' . number_format( $item['price'], 2 ) . '</td>';
            $html .= '<td style="text-align:right;">$' . number_format( $line_total, 2 ) . '</td>';
            $html .= '<td style="text-align:right;"><button class="remove-fish-btn" onclick="removeFish(' . $request_id . ',' . $index . ')">Remove Fish</button></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        wp_send_json_success( [ 'batch_name' => $batch_name, 'html' => $html ] );
    }

    public function ajax_remove_from_order() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );
        $request_id = intval( $_POST['request_id'] );
        $fish_index = intval( $_POST['fish_index'] );

        $request = get_post( $request_id );
        if ( ! $request || get_post_meta( $request_id, '_customer_id', true ) != get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
        }

        $items = json_decode( get_post_meta( $request_id, '_cart_items', true ), true ) ?: [];
        $deposit_refunded = false;

        if ( isset( $items[$fish_index] ) ) {
            $item = $items[$fish_index];
            $batch_id = intval( $item['batch_id'] );
            $qty = intval( $item['qty'] );
            if ( $batch_id ) {
                $current = (float) get_post_meta( $batch_id, '_stock', true );
                update_post_meta( $batch_id, '_stock', $current + $qty );
            }
            array_splice( $items, $fish_index, 1 );
            update_post_meta( $request_id, '_cart_items', wp_json_encode( $items ) );
            $new_total = array_reduce( $items, fn( $carry, $item ) => $carry + ( $item['price'] * $item['qty'] ), 0 );
            update_post_meta( $request_id, '_total', $new_total );

            if ( empty( $items ) ) {
                $user_id = get_current_user_id();
                $batch_name = get_post_meta( $request_id, '_batch_name', true );
                $deposit_amount = $this->get_deposit_amount( $batch_name );
                $this->update_user_deposit_balance( $user_id, $deposit_amount );

                $paid = $this->get_paid_deposits( $user_id );
                $key = sanitize_title( $batch_name );
                if ( isset( $paid[$key] ) ) unset( $paid[$key] );
                update_user_meta( $user_id, '_fishotel_paid_deposits', $paid );

                $deposit_refunded = true;
            }
        }

        wp_send_json_success( [
            'message'          => 'Fish removed and stock restored.' . ( $deposit_refunded ? ' Deposit refunded to wallet.' : '' ),
            'deposit_refunded' => $deposit_refunded,
        ] );
    }

    // ==================== REMAINING METHODS (unchanged except formatting) ====================
    public function mark_deposit_paid_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mark_deposit_paid' ) ) wp_die( 'Security check failed.' );
        $user_id = intval( $_GET['user_id'] ?? 0 );
        $batch_raw = sanitize_text_field( urldecode( $_GET['batch'] ?? '' ) );
        if ( ! $user_id || empty( $batch_raw ) ) wp_die( 'Invalid params' );

        $this->mark_deposit_paid( $user_id, $batch_raw, 0, 0, true );

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-orders&updated=1' ) );
        exit;
    }

    public function mark_deposit_unpaid_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'mark_deposit_unpaid' ) ) wp_die( 'Security check failed.' );
        $user_id = intval( $_GET['user_id'] ?? 0 );
        $batch_raw = sanitize_text_field( urldecode( $_GET['batch'] ?? '' ) );
        if ( ! $user_id || empty( $batch_raw ) ) wp_die( 'Invalid params' );

        $paid = $this->get_paid_deposits( $user_id );
        $key = sanitize_title( $batch_raw );
        if ( isset( $paid[$key] ) ) unset( $paid[$key] );
        update_user_meta( $user_id, '_fishotel_paid_deposits', $paid );

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-orders&updated=1' ) );
        exit;
    }

    public function fully_delete_deposit_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'fully_delete_deposit' ) ) wp_die( 'Security check failed.' );
        $user_id = intval( $_GET['user_id'] ?? 0 );
        $batch_raw = sanitize_text_field( urldecode( $_GET['batch'] ?? '' ) );
        if ( ! $user_id || empty( $batch_raw ) ) wp_die( 'Invalid params' );

        $paid = $this->get_paid_deposits( $user_id );
        $key = sanitize_title( $batch_raw );
        if ( isset( $paid[$key] ) ) unset( $paid[$key] );
        update_user_meta( $user_id, '_fishotel_paid_deposits', $paid );

        $all_requests = get_posts([
            'post_type'   => 'fish_request',
            'meta_key'    => '_customer_id',
            'meta_value'  => $user_id,
            'meta_query'  => [
                ['key' => '_batch_name', 'value' => $batch_raw],
            ],
            'numberposts' => -1
        ]);
        foreach ( $all_requests as $r ) wp_delete_post( $r->ID, true );

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-orders&updated=1' ) );
        exit;
    }

    public function reset_test_data_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_reset_test_data' ) ) wp_die( 'Security check failed.' );

        $users = get_users();
        foreach ( $users as $user ) {
            delete_user_meta( $user->ID, '_fishotel_deposit_balance' );
            delete_user_meta( $user->ID, '_fishotel_paid_deposits' );
        }

        $all_requests = get_posts( [ 'post_type' => 'fish_request', 'numberposts' => -1, 'fields' => 'ids' ] );
        foreach ( $all_requests as $id ) wp_delete_post( $id, true );

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-orders&updated=1&reset_done=' . count( $users ) ) );
        exit;
    }

    public function force_deposit_purchasable( $purchasable, $product ) {
        if ( $product->get_id() === $this->get_deposit_product_id() ) return true;
        return $purchasable;
    }

    public function force_deposit_order_item_name( $name, $item, $order ) {
        if ( $item->get_product_id() == $this->get_deposit_product_id() ) return 'Wallet Refill';
        return $name;
    }

    public function init() {
        $this->register_post_types();
        add_rewrite_endpoint( 'wallet', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'my-requests', EP_ROOT | EP_PAGES );
        if ( ! get_option( 'fishotel_rewrite_flushed_198' ) ) {
            flush_rewrite_rules( false );
            update_option( 'fishotel_rewrite_flushed_198', true );
        }
    }

    public function record_last_login( $user_login, $user ) {
        update_user_meta( $user->ID, '_fishotel_last_login', current_time( 'mysql' ) );
    }

    public function add_hf_username_field() {
        $hf_username = get_user_meta( get_current_user_id(), '_fishotel_humble_username', true );
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="humblefish_username">Humble.Fish Username</label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="humblefish_username" id="humblefish_username" value="<?php echo esc_attr( $hf_username ); ?>" />
            <span style="font-size:0.9em;color:#777;">(Leave blank if you are not a Humble.Fish member)</span>
        </p>
        <?php
    }

    public function save_hf_username_field( $user_id ) {
        if ( isset( $_POST['humblefish_username'] ) ) {
            $hf_username = sanitize_text_field( $_POST['humblefish_username'] );
            update_user_meta( $user_id, '_fishotel_humble_username', $hf_username );
        }
    }

    public function handle_deposit_add_to_cart() {
        if ( ! isset( $_GET['add-to-cart'] ) || $_GET['add-to-cart'] !== 'deposit' || ! isset( $_GET['amount'] ) ) return;
        $amount = floatval( $_GET['amount'] );
        if ( $amount <= 0 ) {
            wc_add_notice( 'Please enter a valid amount.', 'error' );
            wp_redirect( home_url( '/wallet-deposit' ) );
            exit;
        }
        $product_id = $this->get_deposit_product_id();
        WC()->cart->empty_cart();
        WC()->session->__unset( 'fishotel_deposit_amount' );
        WC()->session->set( 'fishotel_deposit_amount', $amount );
        $cart_item_data = [ 'deposit_amount' => $amount, 'is_deposit' => true ];
        WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );
        wc_add_notice( 'Wallet Refill of $' . number_format( $amount, 2 ) . ' added to cart.', 'success' );
        wp_redirect( wc_get_checkout_url() );
        exit;
    }

    public function restore_deposit_cart_item( $cart_item, $values, $key ) {
        if ( isset( $values['is_deposit'] ) ) {
            $cart_item['is_deposit'] = true;
            $cart_item['deposit_amount'] = $values['deposit_amount'] ?? WC()->session->get( 'fishotel_deposit_amount' );
        }
        return $cart_item;
    }

    public function set_deposit_cart_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        $session_amount = WC()->session->get( 'fishotel_deposit_amount' );
        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['is_deposit'] ) && $cart_item['is_deposit'] === true ) {
                $amount = $cart_item['deposit_amount'] ?? $session_amount ?? 0;
                $cart_item['data']->set_price( $amount );
                $cart_item['data']->set_regular_price( $amount );
            }
        }
    }

    public function deposit_cart_item_name( $name, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['is_deposit'] ) && $cart_item['is_deposit'] === true ) return 'Wallet Refill';
        return $name;
    }

    public function deposit_cart_item_price( $price, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['is_deposit'] ) && $cart_item['is_deposit'] === true ) {
            $amount = $cart_item['deposit_amount'] ?? WC()->session->get( 'fishotel_deposit_amount' ) ?? 0;
            return wc_price( $amount );
        }
        return $price;
    }

    public function deposit_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['is_deposit'] ) && $cart_item['is_deposit'] === true ) {
            $amount = $cart_item['deposit_amount'] ?? WC()->session->get( 'fishotel_deposit_amount' ) ?? 0;
            return wc_price( $amount );
        }
        return $subtotal;
    }

    public function add_return_to_fish_button( $order_id = null ) {
        $has_deposit = false;
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                foreach ( $order->get_items() as $item ) {
                    if ( $item->get_product_id() == $this->get_deposit_product_id() || stripos( $item->get_name(), 'Wallet Refill' ) !== false ) {
                        $has_deposit = true;
                        break;
                    }
                }
            }
        } elseif ( WC()->cart && ! empty( WC()->cart->get_cart() ) ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                if ( isset( $cart_item['is_deposit'] ) ) {
                    $has_deposit = true;
                    break;
                }
            }
        }
        if ( $has_deposit ) {
            echo '<div style="text-align:center;margin:40px 0 60px 0;">
                <a href="' . home_url( '/live-fish-list/' ) . '" class="button button-primary" style="background:#e67e22;color:#000;font-weight:700;padding:18px 50px;font-size:20px;">← Return to Fish List</a>
            </div>';
        }
    }

    public function wallet_deposit_shortcode() {
        if ( ! is_user_logged_in() ) return '<p>Please <a href="' . wp_login_url( get_permalink() ) . '">log in</a> to deposit funds.</p>';
        $user_id = get_current_user_id();
        $balance = $this->get_user_deposit_balance( $user_id );
        $deposit_amount = $this->get_deposit_amount();
        $suggested = max( $deposit_amount - $balance, 0 );
        ob_start();
        ?>
        <div class="fishotel-wallet-deposit" style="max-width:620px;margin:40px auto;padding:40px;background:#1e1e1e;border-radius:16px;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,0.6);">
            <h2 style="color:#e67e22;text-align:center;margin-bottom:10px;">Add Funds to Your Wallet</h2>
            <p style="text-align:center;font-size:1.3em;color:#e67e22;margin:0 0 20px 0;"><strong>Cost to participate in a Custom Order: $<?php echo number_format( $deposit_amount, 2 ); ?></strong></p>
            <p style="text-align:center;font-size:1.2em;">Current balance: <strong style="color:#27ae60;">$<?php echo number_format( $balance, 2 ); ?></strong></p>
            <?php if ( $balance >= $deposit_amount ) : ?>
                <p style="text-align:center;color:#27ae60;font-weight:700;">✅ Your balance is sufficient — no deposit needed right now!</p>
            <?php else : ?>
                <p style="text-align:center;color:#aaa;margin-bottom:30px;">You need to add at least <strong>$<?php echo number_format( $suggested, 2 ); ?></strong> to reach the minimum.</p>
            <?php endif; ?>
            <form id="deposit-form" style="margin:30px 0;">
                <div style="display:flex;align-items:center;justify-content:center;gap:20px;margin-bottom:30px;">
                    <button type="button" class="qty-minus" style="background:#333;color:#e67e22;border:none;width:60px;height:60px;font-size:32px;border-radius:12px;cursor:pointer;">−</button>
                    <input type="number" id="deposit-amount" step="0.01" min="0.01" value="<?php echo $suggested; ?>" style="width:220px;text-align:center;font-size:32px;background:#333;border:2px solid #555;color:#fff;border-radius:12px;padding:12px;">
                    <button type="button" class="qty-plus" style="background:#333;color:#e67e22;border:none;width:60px;height:60px;font-size:32px;border-radius:12px;cursor:pointer;">+</button>
                </div>
                <button type="submit" class="button button-primary" style="width:100%;padding:20px;font-size:20px;background:#e67e22;color:#000;font-weight:700;border:none;border-radius:12px;">Continue to Secure Checkout</button>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.qty-minus').click(function() { let input = $('#deposit-amount'); let val = parseFloat(input.val()) || 0; if (val > 0.01) input.val((val - 0.01).toFixed(2)); });
            $('.qty-plus').click(function() { let input = $('#deposit-amount'); let val = parseFloat(input.val()) || 0; input.val((val + 0.01).toFixed(2)); });
            $('#deposit-form').submit(function(e) { e.preventDefault(); let amount = parseFloat($('#deposit-amount').val()); if (amount <= 0) { alert('Please enter a valid amount.'); return; } window.location.href = '<?php echo wc_get_checkout_url(); ?>?add-to-cart=deposit&amount=' + amount; });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public function credit_wallet_on_payment( $order_id ) {
        if ( get_post_meta( $order_id, '_fishotel_wallet_credited', true ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;
        $amount = 0;
        $deposit_product_id = $this->get_deposit_product_id();
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_product_id() == $deposit_product_id || stripos( $item->get_name(), 'Wallet Refill' ) !== false ) $amount += (float) $item->get_total();
        }
        if ( $amount > 0 ) {
            update_post_meta( $order_id, '_fishotel_wallet_credited', 1 );
            $this->update_user_deposit_balance( $user_id, $amount );
            $batch_name = $order->get_meta( '_deposit_batch_name' ) ?: 'Unknown Batch';
            $this->mark_deposit_paid( $user_id, $batch_name, $amount, $order_id, false );
            $history = get_user_meta( $user_id, '_fishotel_wallet_history', true );
            if ( ! is_array( $history ) ) $history = [];
            $history[] = [ 'date' => current_time( 'mysql' ), 'amount' => $amount, 'reason' => 'Wallet Refill via Order #' . $order_id, 'order_id' => $order_id, 'admin_id' => 0 ];
            update_user_meta( $user_id, '_fishotel_wallet_history', $history );
            update_user_meta( $user_id, '_fishotel_wallet_last_updated', current_time( 'mysql' ) );
        }
    }

    public function add_wallet_menu_item( $items ) { $items['wallet'] = 'My Wallet'; return $items; }
    public function add_custom_orders_menu_item( $items ) { $items['my-requests'] = 'Custom Orders'; return $items; }

    public function custom_orders_endpoint_content() {
        $user_id = get_current_user_id();
        $hf_username = get_user_meta( $user_id, '_fishotel_humble_username', true );

        echo '<h2>Custom Orders</h2>';

        echo '<div style="background:#2a2a2a;padding:25px;border:1px solid #444;border-radius:12px;margin-bottom:30px;box-shadow:0 4px 15px rgba(0,0,0,0.5);">';
        echo '<h3 style="margin:0 0 15px 0;color:#e67e22;">Humble.Fish Username</h3>';
        echo '<form id="hf-username-form-inline" style="display:flex;gap:15px;align-items:center;">';
        echo '<input type="text" id="hf-username-input" value="' . esc_attr( $hf_username ) . '" placeholder="Humble.Fish Username" style="flex:1;padding:14px;background:#1e1e1e;border:2px solid #555;color:#fff;border-radius:8px;font-size:16px;">';
        echo '<button type="submit" class="button button-primary" style="padding:14px 30px;background:#e67e22;color:#000;font-weight:700;border:none;border-radius:8px;">Save Username</button>';
        echo '<span id="hf-save-msg" style="margin-left:10px;color:#27ae60;font-weight:700;"></span>';
        echo '</form></div>';

        $requests = get_posts( [ 'post_type' => 'fish_request', 'meta_key' => '_customer_id', 'meta_value' => $user_id, 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ] );

        if ( empty( $requests ) ) {
            echo '<p>You have no custom orders yet.</p>';
        } else {
            echo '<table class="widefat fixed striped" style="width:100%;"><thead><tr><th>Req #</th><th>Batch</th><th>Date</th><th>Total</th><th>Status</th><th style="width:140px;">Action</th></tr></thead><tbody>';
            foreach ( $requests as $req ) {
                $batch_name = get_post_meta( $req->ID, '_batch_name', true );
                $total = get_post_meta( $req->ID, '_total', true );
                $status = get_post_meta( $req->ID, '_status', true ) ?: 'provisional';
                $date_short = wp_date( 'M j', strtotime( $req->post_date ) );
                echo '<tr><td>#' . $req->ID . '</td><td>' . esc_html( $batch_name ) . '</td><td>' . $date_short . '</td><td>$' . number_format( $total, 2 ) . '</td><td><strong>' . ucfirst( $status ) . '</strong></td><td>';
                echo '<button class="button button-small details-btn" data-request-id="' . $req->ID . '" style="background:#e67e22;color:#000;font-weight:700;padding:9px 24px;border-radius:6px;border:none;">Details</button>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // PREMIUM CENTERED MODAL
        echo '<div id="change-order-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.92);z-index:99999;align-items:center;justify-content:center;overflow:auto;">
            <div style="background:#1e1e1e;width:90%;max-width:920px;margin:40px auto;border-radius:16px;box-shadow:0 25px 80px rgba(0,0,0,0.85);overflow:hidden;">
                <div style="background:#2a2a2a;padding:25px 35px;border-bottom:1px solid #444;">
                    <h2 id="modal-batch-name" style="margin:0;color:#e67e22;font-size:28px;text-align:center;"></h2>
                </div>
                <div style="padding:35px;" id="modal-fish-list"></div>
                <div style="padding:25px 35px;background:#252525;border-top:1px solid #444;text-align:center;">
                    <button onclick="closeChangeOrderModal()" class="button button-primary" style="background:#e67e22;color:#000;padding:16px 60px;font-size:18px;font-weight:700;border:none;border-radius:8px;">CLOSE WINDOW</button>
                </div>
            </div>
        </div>';

        ?>
        <style>
        #change-order-modal .modal-table { width:100%; border-collapse:collapse; }
        #change-order-modal .modal-table th { background:#e67e22;color:#000;padding:14px 18px;text-align:left;font-weight:700; }
        #change-order-modal .modal-table td { padding:14px 18px;border-bottom:1px solid #444;vertical-align:middle; }
        #change-order-modal .remove-fish-btn { background:#e74c3c;color:#fff;padding:9px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700; }
        #change-order-modal .remove-fish-btn:hover { background:#c0392b; }
        .details-btn { font-size:14px; }
        </style>
        <script>
        function openChangeOrderModal(requestId) {
            jQuery.ajax({
                url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                type: "POST",
                data: { 
                    action: "fishotel_get_order_details", 
                    request_id: requestId,
                    nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>'
                },
                success: function(r) {
                    if (r.success) {
                        jQuery('#modal-batch-name').text(r.data.batch_name + ' – REQUEST #' + requestId);
                        jQuery('#modal-fish-list').html(r.data.html);
                        jQuery('#change-order-modal').fadeIn(300);
                    } else alert('Could not load order.');
                }
            });
        }
        function closeChangeOrderModal() { jQuery('#change-order-modal').fadeOut(200); location.reload(); }
        jQuery(document).ready(function($) {
            $('.details-btn').click(function() { openChangeOrderModal($(this).data('request-id')); });
            $('#hf-username-form-inline').submit(function(e) {
                e.preventDefault();
                $.ajax({
                    url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                    type: "POST",
                    data: { 
                        action: "fishotel_save_hf_username", 
                        hf_username: $('#hf-username-input').val().trim(),
                        nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>'
                    },
                    success: function() { $('#hf-save-msg').html('✅ Saved!'); setTimeout(() => $('#hf-save-msg').html(''), 2000); }
                });
            });
        });
        function removeFish(requestId, fishIndex) {
            if (!confirm('Remove this fish and restore stock?')) return;
            jQuery.ajax({
                url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                type: "POST",
                data: { 
                    action: "fishotel_remove_from_order", 
                    request_id: requestId, 
                    fish_index: fishIndex,
                    nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>'
                },
                success: function() { openChangeOrderModal(requestId); }
            });
        }
        </script>
        <?php
    }

    public function enqueue_batch_orders_scripts( $hook ) {
        $fishotel_pages = [
            'fishotel-batch-orders',
            'fishotel-batch-settings',
            'fishotel-wallets',
            'fishotel-sync',
        ];
        $page = $_GET['page'] ?? '';
        $is_fishotel = in_array( $page, $fishotel_pages, true )
            || ( isset( $_GET['post_type'] ) && in_array( $_GET['post_type'], ['fish_master', 'fish_batch', 'fish_request'], true ) )
            || in_array( get_post_type(), ['fish_master', 'fish_batch', 'fish_request'], true );

        if ( $is_fishotel ) {
            wp_enqueue_script( 'jquery' );
        }

        if ( $is_fishotel ) {
            $css = "
/* ===== FisHotel Admin Brand Theme ===== */

/* Page title */
.fishotel-admin .wrap > h1 {
    font-size: 2rem;
    font-weight: 800;
    border-bottom: 3px solid #b5a165;
    padding-bottom: 12px;
    margin-bottom: 24px;
    color: #ffffff;
}
.fishotel-admin .wrap > h1::before {
    content: '🐠 ';
}

/* Wrap background */
.fishotel-admin .wrap {
    color: #ffffff;
}

/* Postbox / metabox panels */
.fishotel-admin .postbox {
    background: #1e1e1e;
    border: 1px solid #444;
    border-radius: 8px;
}
.fishotel-admin .postbox .hndle {
    background: #2a2a2a;
    color: #b5a165;
    border-bottom: 1px solid #444;
    border-radius: 8px 8px 0 0;
    font-weight: 700;
}
.fishotel-admin .postbox .hndle h2 {
    color: #b5a165;
}
.fishotel-admin .postbox .inside {
    color: #ffffff;
}

/* .button-primary */
.fishotel-admin .button-primary {
    background: #e67e22 !important;
    border-color: #e67e22 !important;
    color: #000000 !important;
    font-weight: 700 !important;
    border-radius: 6px !important;
    text-shadow: none !important;
    box-shadow: none !important;
}
.fishotel-admin .button-primary:hover {
    background: #cf6d17 !important;
    border-color: #cf6d17 !important;
}

/* Tables .widefat */
.fishotel-admin .widefat {
    background: #1e1e1e;
    border: 1px solid #444;
    color: #ffffff;
}
.fishotel-admin .widefat thead th,
.fishotel-admin .widefat tfoot th {
    background: #2a2a2a;
    color: #b5a165;
    border-bottom: 2px solid #b5a165;
    font-weight: 700;
}
.fishotel-admin .widefat tbody tr {
    background: #1e1e1e;
}
.fishotel-admin .widefat tbody tr:nth-child(even) {
    background: #242424;
}
.fishotel-admin .widefat td {
    color: #ffffff;
    border-bottom: 1px solid #333;
}
.fishotel-admin .widefat th {
    border-bottom: 1px solid #444;
}
/* Override WP striped class */
.fishotel-admin .widefat.striped tbody tr:nth-child(odd) {
    background: #1e1e1e;
}
.fishotel-admin .widefat.striped tbody tr:nth-child(even) {
    background: #242424;
}

/* Form inputs, selects, textareas */
.fishotel-admin input[type=text],
.fishotel-admin input[type=number],
.fishotel-admin input[type=email],
.fishotel-admin input[type=password],
.fishotel-admin input[type=search],
.fishotel-admin select,
.fishotel-admin textarea {
    background: #333 !important;
    border: 1px solid #555 !important;
    color: #ffffff !important;
    border-radius: 4px !important;
}
.fishotel-admin input[type=text]:focus,
.fishotel-admin input[type=number]:focus,
.fishotel-admin select:focus,
.fishotel-admin textarea:focus {
    border-color: #b5a165 !important;
    box-shadow: 0 0 0 1px #b5a165 !important;
    outline: none !important;
}

/* Page descriptions / subtitles */
.fishotel-admin p.description,
.fishotel-admin .page-description {
    color: #aaaaaa;
    font-style: italic;
}

/* Regular .button (not primary) */
.fishotel-admin .button:not(.button-primary) {
    background: #2a2a2a;
    border-color: #555;
    color: #ffffff;
    border-radius: 6px;
}
.fishotel-admin .button:not(.button-primary):hover {
    background: #333;
    border-color: #777;
    color: #ffffff;
}

/* Danger Zone */
.fishotel-danger-zone {
    margin-top: 40px;
    border: 1px solid #6b2222;
    border-radius: 8px;
    background: #1e1e1e;
    padding: 0;
}
.fishotel-danger-toggle {
    background: none;
    border: none;
    color: #aaaaaa;
    font-size: 0.85em;
    cursor: pointer;
    padding: 10px 16px;
    text-decoration: underline;
    width: 100%;
    text-align: left;
}
.fishotel-danger-toggle:hover { color: #e74c3c; }
.fishotel-danger-body {
    display: none;
    padding: 20px;
    border-top: 1px solid #6b2222;
}
.fishotel-danger-body.open { display: block; }

/* ===== Form-table text inside dark panels ===== */
.fishotel-admin .inside th,
.fishotel-admin .inside td,
.fishotel-admin .inside label,
.fishotel-admin .inside p,
.fishotel-admin .inside li {
    color: #dddddd;
}
.fishotel-admin .inside small {
    color: #aaaaaa;
}
.fishotel-admin .inside .form-table th {
    color: #dddddd;
    font-weight: 600;
}
/* Ensure form-table rows have transparent background */
.fishotel-admin .inside .form-table tr {
    background: transparent;
}

/* ===== Fix 1: Full dark page background ===== */
#wpcontent,
#wpbody-content {
    background: #1a1a1a !important;
}
/* h1 on FisHotel custom pages (already scoped by .fishotel-admin) and list pages */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wrap h1 {
    color: #ffffff;
    font-size: 2rem;
    font-weight: 800;
    border-bottom: 3px solid #b5a165;
    padding-bottom: 12px;
    margin-bottom: 24px;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wrap h1::before {
    content: '🐠 ';
}

/* ===== Fix 2: Post type list page dark styling ===== */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wrap {
    color: #ffffff;
}

/* List table — dark rows, gold headers */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table {
    background: #1e1e1e;
    border: 1px solid #444;
    color: #ffffff;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table thead th,
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table tfoot th {
    background: #2a2a2a;
    color: #b5a165;
    border-bottom: 2px solid #b5a165;
    font-weight: 700;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table td {
    color: #ffffff;
    border-bottom: 1px solid #333;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table tbody tr {
    background: #1e1e1e;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table tbody tr:nth-child(even),
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table.striped > tbody > tr:nth-child(even) {
    background: #242424;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .wp-list-table.striped > tbody > tr:nth-child(odd) {
    background: #1e1e1e;
}

/* Title links */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .column-title a,
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .column-title strong a {
    color: #b5a165;
    font-weight: 700;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .column-title a:hover {
    color: #e67e22;
}

/* Row actions */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .row-actions a {
    color: #aaaaaa;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .row-actions .trash a { color: #e74c3c; }
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .row-actions .edit a { color: #e67e22; }

/* Add New / page-title-action button */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .page-title-action {
    background: #e67e22 !important;
    color: #000000 !important;
    border-color: #e67e22 !important;
    border-radius: 6px !important;
    font-weight: 700 !important;
    text-shadow: none !important;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .page-title-action:hover {
    background: #cf6d17 !important;
    border-color: #cf6d17 !important;
}

/* General buttons on list pages */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .button {
    background: #2a2a2a;
    border-color: #555;
    color: #ffffff;
    border-radius: 6px;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .button:hover {
    background: #333;
    border-color: #777;
    color: #ffffff;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .button-primary {
    background: #e67e22 !important;
    border-color: #e67e22 !important;
    color: #000000 !important;
    font-weight: 700 !important;
    text-shadow: none !important;
}

/* Search box, filter selects */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) input[type=search],
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) select {
    background: #333 !important;
    border: 1px solid #555 !important;
    color: #ffffff !important;
    border-radius: 4px !important;
}

/* Sub-filter links (All | Trash) */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .subsubsub {
    color: #555;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .subsubsub a {
    color: #aaaaaa;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .subsubsub a.current {
    color: #b5a165;
    font-weight: 700;
}

/* Pagination / tablenav text */
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .tablenav {
    color: #aaaaaa;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .tablenav-pages a,
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .tablenav-pages span.paging-input {
    color: #aaaaaa;
}
:is(body.post-type-fish_master, body.post-type-fish_batch, body.post-type-fish_request) .tablenav-pages a:hover {
    color: #b5a165;
}
";
            wp_add_inline_style( 'wp-admin', $css );
        }
    }

    public function register_post_types() {
        register_post_type( 'fish_master', [ 'labels' => [ 'name' => 'Master Fish Library', 'singular_name' => 'Master Fish' ], 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => [ 'title' ] ] );
        register_post_type( 'fish_batch', [ 'labels' => [ 'name' => 'Batch Fish', 'singular_name' => 'Batch Fish' ], 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => [ 'title' ] ] );
        register_post_type( 'fish_request', [ 'labels' => [ 'name' => 'Batch Requests', 'singular_name' => 'Batch Request' ], 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => [ 'title' ] ] );
    }

    public function add_admin_menu() {
        $fish_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="white" d="M2 10 Q7 3 13 6 L17 2 L14 10 L17 18 L13 14 Q7 17 2 10 Z"/></svg>';
        $fish_icon = 'data:image/svg+xml;base64,' . base64_encode( $fish_svg );
        add_menu_page( 'FisHotel Batch Manager', 'FisHotel Batch', 'manage_options', 'fishotel-batch-settings', [$this, 'batch_settings_html'], $fish_icon, 56 );
        add_submenu_page( 'fishotel-batch-settings', 'Master Fish Library', 'Master Fish Library', 'manage_options', 'edit.php?post_type=fish_master' );
        add_submenu_page( 'fishotel-batch-settings', 'Batch Requests', 'Batch Requests', 'manage_options', 'fishotel-batch-orders', [$this, 'batch_orders_html'] );
        add_submenu_page( 'fishotel-batch-settings', 'Customer Wallets', 'Customer Wallets', 'manage_options', 'fishotel-wallets', [$this, 'wallets_html'] );
        add_submenu_page( 'fishotel-batch-settings', 'Sync Quarantined Fish', 'Sync Quarantined Fish', 'manage_options', 'fishotel-sync', [$this, 'sync_page_html'] );
    }

    public function register_settings() {
        register_setting( 'fishotel_batch_settings', 'fishotel_current_batch' );
        register_setting( 'fishotel_batch_settings', 'fishotel_batch_page_assignments' );
        register_setting( 'fishotel_batch_settings', 'fishotel_batch_statuses' );
        register_setting( 'fishotel_batch_settings', 'fishotel_admin_test_mode' );
        register_setting( 'fishotel_batch_settings', 'fishotel_deposit_product_id', [ 'default' => 31985 ] );
        register_setting( 'fishotel_batch_settings', 'fishotel_batch_deposit_amounts' );
    }

    public function batch_settings_html() {
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>✅ All settings saved successfully!</p></div>';
        if ( isset( $_GET['error'] ) ) echo '<div class="notice notice-error is-dismissible"><p>❌ Invalid parameters. Please try again.</p></div>';

        $batches_str = get_option( 'fishotel_batches', '' );
        $batches_array = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );
        $current = get_option( 'fishotel_current_batch', '' );
        $assignments = get_option( 'fishotel_batch_page_assignments', [] );
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $batch_deposit_amounts = get_option( 'fishotel_batch_deposit_amounts', [] );
        $admin_test_mode = get_option( 'fishotel_admin_test_mode', 0 );
        $deposit_product_id = $this->get_deposit_product_id();

        if ( isset( $_POST['fishotel_save_all'] ) && check_admin_referer( 'fishotel_save_all_nonce' ) ) {
            update_option( 'fishotel_deposit_product_id', intval( $_POST['deposit_product_id'] ?? 31985 ) );
            update_option( 'fishotel_current_batch', sanitize_text_field( $_POST['fishotel_current_batch'] ?? '' ) );
            update_option( 'fishotel_admin_test_mode', isset( $_POST['admin_test_mode'] ) ? 1 : 0 );

            $new_assignments = [];
            $new_statuses = [];
            $new_deposit_amounts = [];
            foreach ( $batches_array as $batch ) {
                $key = sanitize_key( $batch );
                $title_key = sanitize_title( $batch );
                if ( isset( $_POST['assign_' . $key] ) ) $new_assignments[$batch] = sanitize_text_field( $_POST['assign_' . $key] );
                if ( isset( $_POST['status_' . $key] ) ) $new_statuses[$batch] = sanitize_text_field( $_POST['status_' . $key] );
                if ( isset( $_POST['deposit_amount_' . $key] ) && (float) $_POST['deposit_amount_' . $key] > 0 ) {
                    $new_deposit_amounts[$title_key] = floatval( $_POST['deposit_amount_' . $key] );
                }
            }
            update_option( 'fishotel_batch_page_assignments', $new_assignments );
            update_option( 'fishotel_batch_statuses', $new_statuses );
            update_option( 'fishotel_batch_deposit_amounts', $new_deposit_amounts );

            wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
            exit;
        }

        $pages = get_pages( [ 'sort_column' => 'post_title' ] );
        $stage_options = [
            'open_ordering' => '1. Open Ordering',
            'arrived'       => '2. Orders Closed / Arrived',
            'quarantine'    => '3. In Quarantine',
            'verification'  => '4. Customer Verification',
            'draft_pool'    => '5. Draft Pool (Round 1 & 2)',
            'fulfillment'   => '6. Fulfillment / Drafting',
            'completed'     => '7. Completed'
        ];
        ?>
        <div class="wrap fishotel-admin">
            <h1>FisHotel Batch Manager</h1>
            <p class="page-description">Complete backend control for fishotel.com batch system</p>

            <!-- ===== ZONE 1: Import Card ===== -->
            <div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-top:24px;">
                <div style="display:flex;gap:40px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="flex:1;min-width:220px;">
                        <form method="post" enctype="multipart/form-data" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                            <?php wp_nonce_field( 'fishotel_import_csv_nonce' ); ?>
                            <input type="hidden" name="action" value="fishotel_import_csv">
                            <label style="display:block;font-weight:700;color:#fff;margin-bottom:10px;">📥 Import Exporter CSV</label>
                            <input type="file" name="fish_csv" accept=".csv" style="display:block;color:#ddd;margin-bottom:14px;">
                            <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 24px;cursor:pointer;font-size:14px;">Upload &amp; Analyze</button>
                        </form>
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <label style="display:block;font-weight:700;color:#fff;margin-bottom:10px;">Default Import Batch</label>
                        <select name="fishotel_current_batch" form="fishotel-save-all-form" style="width:100%;max-width:320px;">
                            <option value="">— None —</option>
                            <?php foreach ( $batches_array as $b ) : ?>
                                <option value="<?php echo esc_attr( $b ); ?>" <?php selected( $current, $b ); ?>><?php echo esc_html( $b ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display:block;margin-top:8px;color:#aaa;">Pre-selects the target batch when importing a new CSV fish list.</small>

                        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="margin-top:20px;">
                            <?php wp_nonce_field( 'add_batch_nonce' ); ?>
                            <input type="hidden" name="action" value="fishotel_add_batch">
                            <label style="display:block;font-weight:700;color:#fff;margin-bottom:10px;">Add New Batch</label>
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <input type="text" name="new_batch" placeholder="e.g. Fiji 3-15-26" style="flex:1;min-width:160px;">
                                <button type="submit" name="add_batch_submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:9px 18px;cursor:pointer;white-space:nowrap;">Add Batch</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ===== ZONE 2: Batches Table + Save ===== -->
            <form method="post" action="" id="fishotel-save-all-form" style="margin-top:24px;">
                <?php wp_nonce_field( 'fishotel_save_all_nonce' ); ?>
                <input type="hidden" name="fishotel_save_all" value="1">

                <table class="widefat" style="border-radius:8px;overflow:hidden;">
                    <thead>
                        <tr>
                            <th>Batch Name</th>
                            <th>Current Stage</th>
                            <th>Public Page</th>
                            <th style="width:140px;">Deposit Amount</th>
                            <th style="width:180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $batches_array as $batch ) :
                            $key        = sanitize_key( $batch );
                            $title_key  = sanitize_title( $batch );
                            $current_page   = $assignments[$batch] ?? '';
                            $current_status = $statuses[$batch] ?? 'open_ordering';
                            $batch_deposit  = $batch_deposit_amounts[$title_key] ?? '';
                            $view_url  = $current_page ? home_url( '/' . $current_page ) : '';
                            $embed_url = $current_page ? home_url( '/' . $current_page . '?embed=1' ) : '';
                        ?>
                        <tr>
                            <td><strong style="color:#b5a165;"><?php echo esc_html( $batch ); ?></strong></td>
                            <td>
                                <select name="status_<?php echo $key; ?>" style="width:100%;">
                                    <?php foreach ( $stage_options as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="assign_<?php echo $key; ?>" style="width:100%;">
                                    <option value="">— Not assigned —</option>
                                    <?php foreach ( $pages as $page ) : ?>
                                        <option value="<?php echo esc_attr( $page->post_name ); ?>" <?php selected( $current_page, $page->post_name ); ?>><?php echo esc_html( $page->post_title ); ?> (<?php echo esc_html( $page->post_name ); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="deposit_amount_<?php echo $key; ?>" value="<?php echo esc_attr( $batch_deposit ); ?>" placeholder="e.g. 25.00" style="width:90px;">
                                <small style="display:block;color:#aaa;margin-top:3px;">USD (required)</small>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php if ( $view_url ) : ?>
                                    <a href="<?php echo esc_url( $view_url ); ?>" target="_blank" class="button button-small">View</a>
                                    <button type="button" class="button button-small" onclick="copyShareLink('<?php echo esc_js( $embed_url ); ?>')">Copy Link</button>
                                <?php else : ?>
                                    <span style="color:#555;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Save All Settings -->
                <p style="text-align:center;margin:28px 0 12px 0;">
                    <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:8px;padding:16px 60px;font-size:18px;cursor:pointer;">💾 Save All Settings</button>
                </p>

                <!-- ===== ZONE 3: Advanced Settings ===== -->
                <div style="margin-top:8px;padding-bottom:40px;text-align:center;">
                    <button type="button" id="fishotel-advanced-toggle" style="background:none;border:none;color:#aaa;font-size:0.85em;cursor:pointer;text-decoration:underline;padding:6px 0;">⚙️ Advanced Settings ▾</button>
                    <div id="fishotel-advanced-body" style="display:none;margin-top:12px;background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:20px;text-align:left;">
                        <table class="form-table">
                            <tr>
                                <th style="color:#ddd;">Wallet Deposit Product ID</th>
                                <td>
                                    <input type="number" name="deposit_product_id" value="<?php echo esc_attr( $deposit_product_id ); ?>" style="width:120px;">
                                    <small style="display:block;margin-top:5px;color:#aaa;">(Your product #31985 — change only if you recreate it)</small>
                                </td>
                            </tr>
                            <tr>
                                <th style="color:#ddd;">Admin Test Mode</th>
                                <td><label style="color:#ddd;"><input type="checkbox" name="admin_test_mode" <?php checked( $admin_test_mode, 1 ); ?>> Bypass deposit check for admins</label></td>
                            </tr>
                        </table>
                    </div>
                </div>

            </form>
        </div>

        <script>
        function copyShareLink(url) { navigator.clipboard.writeText(url).then(() => alert('Clean Share Link copied!')); }
        document.getElementById('fishotel-advanced-toggle').addEventListener('click', function() {
            var body = document.getElementById('fishotel-advanced-body');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                this.textContent = '⚙️ Advanced Settings ▴';
            } else {
                body.style.display = 'none';
                this.textContent = '⚙️ Advanced Settings ▾';
            }
        });
        </script>
        <?php
    }

    public function wallets_html() {
        $per_page = isset( $_GET['per_page'] ) ? max( 20, intval( $_GET['per_page'] ) ) : 50;
        if ( $per_page > 500 ) $per_page = 500;
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        $args = [ 'orderby' => 'display_name', 'number' => -1 ];
        if ( ! empty( $search ) ) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
        }
        $all_users = get_users( $args );

        $filtered_users = [];
        $six_months_ago = strtotime( '-6 months' );
        foreach ( $all_users as $user ) {
            $balance = $this->get_user_deposit_balance( $user->ID );
            $last_login = get_user_meta( $user->ID, '_fishotel_last_login', true );
            $show = false;
            if ( $balance > 0 ) $show = true;
            elseif ( $last_login && strtotime( $last_login ) >= $six_months_ago ) $show = true;
            if ( $show || ! empty( $search ) ) $filtered_users[] = $user;
        }

        $total = count( $filtered_users );
        $total_pages = ceil( $total / $per_page );
        $offset = ( $paged - 1 ) * $per_page;
        $users = array_slice( $filtered_users, $offset, $per_page );

        ?>
        <div class="wrap fishotel-admin">
            <h1>Customer Wallets</h1>
            <p class="page-description">Full control over every user’s deposit wallet. Changes are logged permanently.</p>
            <p><em>Default view shows anyone with balance > $0 OR anyone who logged in within 6 months. Only $0 + no login for 6+ months are hidden. Search shows everyone.</em></p>

            <form method="get" action="">
                <input type="hidden" name="page" value="fishotel-wallets">
                <div style="margin-bottom:15px;">
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search username, email or name..." style="width:320px;">
                    <select name="per_page" style="margin-left:10px;">
                        <option value="20" <?php selected( $per_page, 20 ); ?>>20 per page</option>
                        <option value="50" <?php selected( $per_page, 50 ); ?>>50 per page</option>
                        <option value="100" <?php selected( $per_page, 100 ); ?>>100 per page</option>
                        <option value="250" <?php selected( $per_page, 250 ); ?>>250 per page</option>
                        <option value="500" <?php selected( $per_page, 500 ); ?>>500 per page</option>
                    </select>
                    <input type="submit" class="button" value="Filter">
                </div>
            </form>

            <p><strong>Showing <?php echo $offset + 1; ?>–<?php echo min( $offset + $per_page, $total ); ?> of <?php echo number_format( $total ); ?> clients</strong></p>

            <table class="widefat fixed striped" id="wallet-table">
                <thead>
                    <tr>
                        <th style="cursor:pointer;" data-sort="string">Name</th>
                        <th style="cursor:pointer;" data-sort="string">Email</th>
                        <th style="cursor:pointer;" data-sort="string">HF Username</th>
                        <th style="cursor:pointer;" data-sort="numeric">Wallet Balance</th>
                        <th style="cursor:pointer;" data-sort="datetime">Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $users as $user ) : 
                        $balance = $this->get_user_deposit_balance( $user->ID );
                        $humble_username = get_user_meta( $user->ID, '_fishotel_humble_username', true ) ?: 'Not set';
                        $last_login = get_user_meta( $user->ID, '_fishotel_last_login', true );
                        $last_login_display = $last_login ? wp_date( 'M j, Y g:i A', strtotime( $last_login ) ) : 'Never';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $user->display_name ); ?></td>
                            <td><?php echo esc_html( $user->user_email ); ?></td>
                            <td><?php echo esc_html( $humble_username ); ?></td>
                            <td data-balance="<?php echo $balance; ?>" style="font-weight:700;color:<?php echo $balance >= $this->get_deposit_amount() ? '#27ae60' : '#e74c3c'; ?>;">$<?php echo number_format( $balance, 2 ); ?></td>
                            <td data-last-login="<?php echo $last_login ?: '0000-00-00'; ?>"><?php echo esc_html( $last_login_display ); ?></td>
                            <td><a href="#" class="button button-small" onclick="showAdjustModal(<?php echo $user->ID; ?>, '<?php echo esc_js( $user->display_name ); ?>', <?php echo $balance; ?>); return false;">Adjust Balance</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php echo paginate_links( [
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $paged,
                            'add_args' => [ 'per_page' => $per_page, 's' => $search ]
                        ] ); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div id="wallet-adjust-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#1e1e1e;padding:30px;border-radius:12px;width:420px;max-width:92%;color:#fff;">
                <h3 style="margin:0 0 20px 0;color:#e67e22;">Adjust Wallet for <span id="modal-user-name"></span></h3>
                <form id="wallet-adjust-form" method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="fishotel_adjust_wallet">
                    <input type="hidden" name="user_id" id="modal-user-id">
                    <?php wp_nonce_field( 'adjust_wallet' ); ?>
                    <p><label>Amount (positive = add, negative = subtract)<br><input type="number" step="0.01" name="amount" id="modal-amount" style="width:100%;padding:10px;background:#333;border:1px solid #555;color:#fff;" required></label></p>
                    <p><label>Reason / Note<br><input type="text" name="reason" id="modal-reason" placeholder="e.g. Manual deposit, Refund, etc." style="width:100%;padding:10px;background:#333;border:1px solid #555;color:#fff;" required></label></p>
                    <p><button type="submit" class="button button-primary" style="width:100%;padding:12px;background:#e67e22;color:#000;font-weight:700;">Save Adjustment</button></p>
                </form>
                <button onclick="closeAdjustModal()" style="position:absolute;top:12px;right:12px;background:none;border:none;color:#aaa;font-size:24px;cursor:pointer;">×</button>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            sortTable(3, false);
            $('#wallet-table th').on('click', function() {
                const index = $(this).index();
                const isNumeric = index === 3;
                const isDate = index === 4;
                const currentAsc = $(this).data('asc') === true;
                sortTable(index, !currentAsc, isNumeric, isDate);
                $(this).data('asc', !currentAsc);
            });
            function sortTable(colIndex, asc, isNumeric, isDate) {
                const table = $('#wallet-table');
                const tbody = table.find('tbody');
                const rows = tbody.find('tr').toArray();
                rows.sort(function(a, b) {
                    let valA = $(a).find('td').eq(colIndex).text().trim();
                    let valB = $(b).find('td').eq(colIndex).text().trim();
                    if (isNumeric) {
                        valA = parseFloat($(a).find('td').eq(3).data('balance')) || 0;
                        valB = parseFloat($(b).find('td').eq(3).data('balance')) || 0;
                    } else if (isDate) {
                        valA = $(a).find('td').eq(4).data('last-login') || '0000-00-00';
                        valB = $(b).find('td').eq(4).data('last-login') || '0000-00-00';
                    }
                    if (valA < valB) return asc ? -1 : 1;
                    if (valA > valB) return asc ? 1 : -1;
                    return 0;
                });
                $.each(rows, function(i, row) { tbody.append(row); });
            }
        });
        function showAdjustModal(userId, displayName, currentBalance) {
            document.getElementById('modal-user-name').innerText = displayName;
            document.getElementById('modal-user-id').value = userId;
            document.getElementById('modal-amount').value = '';
            document.getElementById('modal-reason').value = '';
            document.getElementById('wallet-adjust-modal').style.display = 'flex';
        }
        function closeAdjustModal() {
            document.getElementById('wallet-adjust-modal').style.display = 'none';
        }
        </script>
        <?php
    }

    public function admin_adjust_wallet() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'adjust_wallet' ) ) wp_die( 'Security check failed.' );
        $user_id = intval( $_POST['user_id'] );
        $amount = floatval( $_POST['amount'] );
        $reason = sanitize_text_field( $_POST['reason'] );
        if ( $user_id && $amount != 0 ) {
            $this->update_user_deposit_balance( $user_id, $amount );
            $history = get_user_meta( $user_id, '_fishotel_wallet_history', true );
            if ( ! is_array( $history ) ) $history = [];
            $history[] = [ 'date' => current_time( 'mysql' ), 'amount' => $amount, 'reason' => $reason, 'admin_id' => get_current_user_id() ];
            update_user_meta( $user_id, '_fishotel_wallet_history', $history );
            update_user_meta( $user_id, '_fishotel_wallet_last_updated', current_time( 'mysql' ) );
        }
        wp_redirect( admin_url( 'admin.php?page=fishotel-wallets' ) );
        exit;
    }

    public function batch_orders_html() {
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>✅ Deposit status updated!</p></div>';
        if ( isset( $_GET['error'] ) ) echo '<div class="notice notice-error is-dismissible"><p>❌ Invalid parameters.</p></div>';
        if ( isset( $_GET['reset_done'] ) ) echo '<div class="notice notice-warning is-dismissible"><p>🔄 Test data reset — wallet balances and deposit flags cleared for ' . intval( $_GET['reset_done'] ) . ' users.</p></div>';

        $requests = get_posts( [ 'post_type' => 'fish_request', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ] );

        $paid_users = [];
        $all_users = get_users();
        $batches_str = get_option( 'fishotel_batches', '' );
        $batches = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );

        foreach ( $all_users as $user ) {
            $paid = $this->get_paid_deposits( $user->ID );
            foreach ( $paid as $batch_key => $info ) {
                $batch_name = $batches[array_search( $batch_key, array_map( 'sanitize_title', $batches ) )] ?? $batch_key;
                $paid_users[] = [ 'user_id' => $user->ID, 'user_name' => $user->display_name, 'email' => $user->user_email, 'hf_username' => get_user_meta( $user->ID, '_fishotel_humble_username', true ) ?: 'Not set', 'batch_name' => $batch_name, 'amount' => $info['amount'], 'date' => $info['date'], 'manual' => $info['manual'] ];
            }
        }

        $display_rows = [];
        $seen = [];

        foreach ( $requests as $req ) {
            $customer_id = get_post_meta( $req->ID, '_customer_id', true );
            $batch_name = get_post_meta( $req->ID, '_batch_name', true );
            $key = $customer_id . '|' . sanitize_title( $batch_name );
            if ( ! isset( $seen[$key] ) ) {
                $seen[$key] = true;
                $cart_items = get_post_meta( $req->ID, '_cart_items', true );
                $is_ghost = ( $cart_items === '[]' || empty( $cart_items ) );
                $display_rows[] = [ 'type' => $is_ghost ? 'ghost' : 'request', 'post' => $req, 'user_id' => $customer_id, 'batch_name' => $batch_name, 'total' => get_post_meta( $req->ID, '_total', true ) ];
            }
        }

        foreach ( $paid_users as $paid ) {
            $key = $paid['user_id'] . '|' . sanitize_title( $paid['batch_name'] );
            if ( ! isset( $seen[$key] ) ) {
                $seen[$key] = true;
                $display_rows[] = [ 'type' => 'ghost', 'user_id' => $paid['user_id'], 'user_name' => $paid['user_name'], 'email' => $paid['email'], 'hf_username' => $paid['hf_username'], 'batch_name' => $paid['batch_name'], 'total' => 0, 'is_paid' => true ];
            }
        }

        ?>
        <div class="wrap fishotel-admin">
            <h1>FisHotel Batch Requests</h1>

            <p class="page-description">Stock is reserved. Empty-cart requests show as ghost rows ($0.00) with only Mark Unpaid + Permanent Delete.</p>

            <input type="text" id="batch-search" placeholder="Search requests..." style="width:300px;margin-bottom:15px;">

            <table class="widefat fixed striped" id="batch-table">
                <thead>
                    <tr>
                        <th>Request # / User</th>
                        <th>Customer</th>
                        <th>Humble.Fish Username</th>
                        <th>Batch</th>
                        <th>Total</th>
                        <th>Date</th>
                        <th>Deposit Paid?</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $display_rows as $row ) : 
                        if ( $row['type'] === 'request' ) {
                            $req = $row['post'];
                            $customer_id = $row['user_id'];
                            $customer = $customer_id ? get_user_by( 'id', $customer_id ) : null;
                            $customer_name = $customer ? $customer->display_name : 'Guest';
                            $humble_username = $customer ? get_user_meta( $customer_id, '_fishotel_humble_username', true ) : '';
                            $batch_name = $row['batch_name'];
                            $total = $row['total'];
                            $cancel_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_cancel_request&request_id=' . $req->ID ), 'cancel_request' );
                            $is_paid = $this->is_deposit_paid_for_batch( $customer_id, $batch_name );
                            $paid_html = $is_paid ? '<span style="color:#27ae60;font-weight:700;">YES</span>' : '<span style="color:#e74c3c;font-weight:700;">NO</span>';
                            $mark_unpaid_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_mark_deposit_unpaid&user_id=' . $customer_id . '&batch=' . urlencode( $batch_name ) ), 'mark_deposit_unpaid' );
                            $full_delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_fully_delete_deposit&user_id=' . $customer_id . '&batch=' . urlencode( $batch_name ) ), 'fully_delete_deposit' );
                            ?>
                            <tr>
                                <td>#<?php echo $req->ID; ?></td>
                                <td><?php echo esc_html( $customer_name ); ?></td>
                                <td><?php echo esc_html( $humble_username ?: 'Not set' ); ?></td>
                                <td><strong><?php echo esc_html( $batch_name ); ?></strong></td>
                                <td>$<?php echo number_format( $total, 2 ); ?></td>
                                <td><?php echo esc_html( $req->post_date ); ?></td>
                                <td><?php echo $paid_html; ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo admin_url( 'post.php?post=' . $req->ID . '&action=edit' ); ?>" class="button button-small" style="background:#3498db;color:#fff;" title="View Fish">V</a>
                                    <a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-small" style="background:#e67e22;color:#000;" title="Cancel & Restore Stock" onclick="return confirm('Cancel and restore stock?');">C</a>
                                    <a href="<?php echo esc_url( $mark_unpaid_url ); ?>" class="button button-small" style="background:#27ae60;color:#000;" title="Mark Unpaid" onclick="return confirm('Mark UNPAID?');">M</a>
                                    <a href="<?php echo esc_url( $full_delete_url ); ?>" class="button button-small" style="background:#e74c3c;color:#fff;" title="Permanently Delete" onclick="return confirm('PERMANENTLY DELETE?');">D</a>
                                </td>
                            </tr>
                            <?php
                        } else {
                            $customer_id = $row['user_id'];
                            $customer_name = $row['user_name'];
                            $humble_username = $row['hf_username'];
                            $batch_name = $row['batch_name'];
                            $mark_unpaid_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_mark_deposit_unpaid&user_id=' . $customer_id . '&batch=' . urlencode( $batch_name ) ), 'mark_deposit_unpaid' );
                            $full_delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_fully_delete_deposit&user_id=' . $customer_id . '&batch=' . urlencode( $batch_name ) ), 'fully_delete_deposit' );
                            ?>
                            <tr style="background:#f9f9f9;">
                                <td><em>Paid deposit only</em></td>
                                <td><?php echo esc_html( $customer_name ); ?></td>
                                <td><?php echo esc_html( $humble_username ); ?></td>
                                <td><strong><?php echo esc_html( $batch_name ); ?></strong></td>
                                <td>$0.00</td>
                                <td>—</td>
                                <td><span style="color:#27ae60;font-weight:700;">YES (Ghost)</span></td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo esc_url( $mark_unpaid_url ); ?>" class="button button-small" style="background:#27ae60;color:#000;" title="Mark Unpaid" onclick="return confirm('Mark UNPAID?');">M</a>
                                    <a href="<?php echo esc_url( $full_delete_url ); ?>" class="button button-small" style="background:#e74c3c;color:#fff;" title="Permanently Delete" onclick="return confirm('PERMANENTLY DELETE forever?');">D</a>
                                </td>
                            </tr>
                            <?php
                        }
                    endforeach; ?>
                </tbody>
            </table>

            <div class="fishotel-danger-zone">
                <button class="fishotel-danger-toggle" type="button" id="fishotel-danger-toggle">⚠️ Danger Zone</button>
                <div class="fishotel-danger-body" id="fishotel-danger-body">
                    <p style="color:#e74c3c;font-weight:700;margin-top:0;">Destructive actions — cannot be undone.</p>
                    <p class="page-description">Clears ALL wallet balances, deposit flags, and deletes ALL fish requests for every user. Use only during testing.</p>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                        <input type="hidden" name="action" value="fishotel_reset_test_data">
                        <?php wp_nonce_field( 'fishotel_reset_test_data' ); ?>
                        <button type="submit" style="background:#e74c3c;color:#fff;font-weight:700;padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px;" onclick="return confirm('RESET ALL wallet balances, deposit flags, and fish requests for every user? This cannot be undone.');">🔄 Reset Test Data</button>
                    </form>
                </div>
            </div>
        </div>

        <style>
        #batch-table .button-small { padding:4px 8px; font-size:11px; min-width:28px; text-align:center; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#batch-search').on('keyup', function() {
                const term = $(this).val().toLowerCase();
                $('#batch-table tbody tr').each(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(term) > -1);
                });
            });
            $('#fishotel-danger-toggle').on('click', function() {
                $('#fishotel-danger-body').toggleClass('open');
            });
        });
        </script>
        <?php
    }

    public function admin_cancel_request() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cancel_request' ) ) wp_die( 'Security check failed.' );
        $request_id = intval( $_GET['request_id'] );
        $request = get_post( $request_id );
        if ( $request && $request->post_type === 'fish_request' ) {
            $customer_id = get_post_meta( $request_id, '_customer_id', true );
            $batch_name = get_post_meta( $request_id, '_batch_name', true );
            $items = get_post_meta( $request_id, '_cart_items', true );
            if ( $items ) {
                $items = json_decode( $items, true );
                foreach ( $items as $item ) {
                    $batch_id = intval( $item['batch_id'] );
                    $qty = intval( $item['qty'] );
                    if ( $batch_id ) {
                        $current = (float) get_post_meta( $batch_id, '_stock', true );
                        update_post_meta( $batch_id, '_stock', $current + $qty );
                    }
                }
            }
            if ( $this->is_deposit_paid_for_batch( $customer_id, $batch_name ) ) {
                update_post_meta( $request_id, '_cart_items', '[]' );
                update_post_meta( $request_id, '_total', 0 );
                update_post_meta( $request_id, '_status', 'paid-no-requests' );
            } else {
                wp_delete_post( $request_id, true );
            }
        }
        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-orders' ) );
        exit;
    }

    public function admin_remove_single_fish() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'remove_single_fish' ) ) wp_die( 'Security check failed.' );
        $request_id = intval( $_GET['request_id'] );
        $fish_index = intval( $_GET['fish_index'] );
        $items = get_post_meta( $request_id, '_cart_items', true );
        if ( $items ) {
            $items = json_decode( $items, true );
            if ( isset( $items[$fish_index] ) ) {
                $item = $items[$fish_index];
                $batch_id = intval( $item['batch_id'] );
                $qty = intval( $item['qty'] );
                if ( $batch_id ) {
                    $current = (float) get_post_meta( $batch_id, '_stock', true );
                    update_post_meta( $batch_id, '_stock', $current + $qty );
                }
                array_splice( $items, $fish_index, 1 );
                update_post_meta( $request_id, '_cart_items', wp_json_encode( $items ) );
            }
        }
        wp_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=fishotel-batch-orders' ) );
        exit;
    }

    public function add_request_view_metabox() {
        add_meta_box( 'fish_request_view', 'Fish in this Request', [$this, 'render_request_view_metabox'], 'fish_request', 'normal', 'high' );
    }

    public function render_request_view_metabox( $post ) {
        $items = get_post_meta( $post->ID, '_cart_items', true );
        if ( ! $items || $items === '[]' ) {
            echo '<p>No active fish in this request (deposit was paid).</p>';
            return;
        }
        $items = json_decode( $items, true );
        $customer_id = get_post_meta( $post->ID, '_customer_id', true );
        $customer = $customer_id ? get_user_by( 'id', $customer_id ) : null;
        $customer_name = $customer ? $customer->display_name : 'Guest';
        $humble_username = $customer ? get_user_meta( $customer_id, '_fishotel_humble_username', true ) : '';
        echo '<p><strong>Customer:</strong> ' . esc_html( $customer_name ) . '</p>';
        echo '<p><strong>Humble.Fish Username:</strong> ' . esc_html( $humble_username ?: 'Not set' ) . '</p>';
        echo '<table class="widefat fixed striped"><thead><tr><th>Fish Name</th><th>Qty</th><th>Price</th><th>Line Total</th><th>Action</th></tr></thead><tbody>';
        foreach ( $items as $index => $item ) {
            $line_total = $item['price'] * $item['qty'];
            $remove_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_remove_single_fish&request_id=' . $post->ID . '&fish_index=' . $index ), 'remove_single_fish' );
            echo '<tr><td>' . esc_html( $item['fish_name'] ) . '</td><td>' . esc_html( $item['qty'] ) . '</td><td>$' . number_format( $item['price'], 2 ) . '</td><td>$' . number_format( $line_total, 2 ) . '</td><td><a href="' . esc_url( $remove_url ) . '" class="button button-small button-link-delete" onclick="return confirm(\'Remove this fish and restore stock?\');">Remove Fish</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    public function batch_shortcode() {
        ob_start();
        $current_slug = get_post_field( 'post_name', get_the_ID() );
        $assignments = get_option( 'fishotel_batch_page_assignments', [] );
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $batch_name = array_search( $current_slug, $assignments );
        if ( ! $batch_name ) {
            echo '<p>No batch assigned to this page yet. Please assign one in the admin.</p>';
            return ob_get_clean();
        }
        $status = isset( $statuses[$batch_name] ) ? $statuses[$batch_name] : 'open_ordering';
        $batch_posts = get_posts( [ 'post_type' => 'fish_batch', 'meta_key' => '_batch_name', 'meta_value' => $batch_name, 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
        if ( empty( $batch_posts ) ) {
            echo '<p>No fish found in batch "' . esc_html( $batch_name ) . '".</p>';
            return ob_get_clean();
        }
        usort($batch_posts, function($a, $b) {
            $master_a = get_post_meta($a->ID, '_master_id', true);
            $master_b = get_post_meta($b->ID, '_master_id', true);
            $sci_a = get_post_meta($master_a, '_scientific_name', true);
            $sci_b = get_post_meta($master_b, '_scientific_name', true);
            return strcmp($sci_a, $sci_b);
        });
        if ( $status === 'open_ordering' ) {
            $current_hf_username = get_user_meta( get_current_user_id(), '_fishotel_humble_username', true );
            $needs_hf_popup = is_user_logged_in() && empty( $current_hf_username );
            ?>
            <h2 class="fishotel-batch-title" style="margin-bottom:20px;">Open Ordering – <?php echo esc_html( $batch_name ); ?></h2>

            <div id="fishotel-login-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:#1e1e1e;padding:30px;border-radius:12px;width:380px;max-width:92%;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,0.6);">
                    <h3 style="margin:0 0 20px 0;text-align:center;color:#e67e22;">LOG IN TO CONTINUE</h3>
                    <form id="fishotel-login-form">
                        <p><input type="text" id="fishotel-username" placeholder="Username or Email" style="width:100%;padding:12px;background:#333;border:1px solid #555;border-radius:6px;color:#fff;font-size:16px;"></p>
                        <p><input type="password" id="fishotel-password" placeholder="Password" style="width:100%;padding:12px;background:#333;border:1px solid #555;border-radius:6px;color:#fff;font-size:16px;"></p>
                        <p><button type="submit" id="fishotel-login-btn" class="button button-primary" style="width:100%;padding:14px;background:#e67e22;color:#000;font-size:16px;font-weight:700;border:none;border-radius:6px;cursor:pointer;">LOG IN</button></p>
                        <p style="text-align:center;margin:15px 0 0 0;"><a href="<?php echo wp_lostpassword_url(); ?>" style="color:#e67e22;">Forgot Password?</a></p>
                    </form>
                    <button onclick="closeLoginModal()" style="position:absolute;top:12px;right:12px;background:none;border:none;color:#aaa;font-size:24px;cursor:pointer;">×</button>
                </div>
            </div>

            <div id="hf-username-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:#1e1e1e;padding:30px;border-radius:12px;width:420px;max-width:92%;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,0.6);">
                    <h3 style="margin:0 0 20px 0;text-align:center;color:#e67e22;">One quick thing...</h3>
                    <p style="text-align:center;margin-bottom:20px;">What is your Humble.Fish username?<br><small>(Optional but recommended for tracking your orders)</small></p>
                    <form id="hf-username-form">
                        <p><input type="text" id="hf-username-input" placeholder="Humble.Fish Username" style="width:100%;padding:12px;background:#333;border:1px solid #555;border-radius:6px;color:#fff;font-size:16px;"></p>
                        <p><button type="submit" id="hf-username-btn" class="button button-primary" style="width:100%;padding:14px;background:#e67e22;color:#000;font-size:16px;font-weight:700;border:none;border-radius:6px;cursor:pointer;">Save & Continue</button></p>
                        <p style="text-align:center;margin-top:10px;"><a href="#" onclick="closeHFModal();return false;" style="color:#aaa;">Skip for now</a></p>
                    </form>
                    <button onclick="closeHFModal()" style="position:absolute;top:12px;right:12px;background:none;border:none;color:#aaa;font-size:24px;cursor:pointer;">×</button>
                </div>
            </div>

            <div id="my-requests" style="margin-bottom:15px;border:1px solid #444;padding:20px;background:#1e1e1e;border-radius:8px;color:#fff;">
                <h3 style="margin-top:0;color:#fff;">My Current Requests</h3>
                <div id="request-list" style="min-height:40px;">No fish requested yet.</div>
                <div id="cart-total" style="font-weight:700;color:#e67e22;margin:12px 0;font-size:1.2em;">Total: $0.00</div>
                <button id="submit-requests" style="width:auto;padding:14px 40px;font-size:18px;font-weight:700;background:#e67e22;color:#000;border:none;border-radius:8px;cursor:pointer;margin-top:4px;display:block;margin-left:auto;margin-right:auto;">Review &amp; Submit My Requests</button>
            </div>

            <style>
                .fishotel-batch-title { word-break: break-word; overflow-wrap: break-word; font-size: clamp(1.2rem, 4vw, 2rem); }
                @media (max-width: 600px) { .fishotel-batch-title { line-height: 1.3; } #submit-requests { width: 100% !important; padding: 18px !important; } }
                .fishotel-open-table { width: 100%; min-width: 920px; border-collapse: collapse; background: white; }
                .fishotel-open-table thead { position: sticky; top: 0; z-index: 10; background: #f8f8f8; }
                .fishotel-open-table th, .fishotel-open-table td { padding: 6px 8px; font-size: 0.92em; }
                .fishotel-open-table th[data-sort]:hover { background: #ececec; }
                .fishotel-open-table th[data-sort].sort-asc::after { content: " ▲"; font-size: 0.75em; }
                .fishotel-open-table th[data-sort].sort-desc::after { content: " ▼"; font-size: 0.75em; }
                .scroll-wrapper { overflow-x: auto; scrollbar-width: thin; scrollbar-color: #e67e22 #f8f8f8; margin-bottom: 20px; }
                .scroll-wrapper::-webkit-scrollbar { height: 8px; background: #f8f8f8; }
                .scroll-wrapper::-webkit-scrollbar-thumb { background: #e67e22; border-radius: 4px; }
                .mobile-controls { display: none; margin-bottom: 15px; gap: 10px; flex-wrap: wrap; }
                .mobile-controls select { padding: 8px; font-size: 1em; border: 1px solid #444; border-radius: 4px; background: #222; color: #fff; flex: 1; min-width: 150px; }
                .mobile-controls input { padding: 8px; font-size: 1em; border: 1px solid #444; border-radius: 4px; background: #222; color: #fff; flex: 1; min-width: 180px; width: auto; }
                .fish-cards { display: grid; gap: 15px; }
                .fish-card { background: #222; border: 1px solid #444; border-radius: 8px; padding: 15px; }
                .fish-card h4 { margin: 0 0 5px 0; color: #fff; }
                .fish-card .sci { font-style: italic; color: #aaa; margin-bottom: 10px; }
                .fish-card .price { font-size: 1.2em; color: #e67e22; font-weight: bold; }
                .fish-card .stock { color: #27ae60; font-weight: bold; }
                .fish-card .action { margin-top: 15px; display: flex; align-items: center; gap: 10px; }
                #request-list div { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #444; }
                #request-list span { max-width: 70%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #fff; }
                #request-list button { background: none; border: none; color: #e74c3c; font-size: 1.8em; cursor: pointer; padding: 0 8px; }
                @media (min-width: 1101px) { .fish-cards, .mobile-controls { display: none !important; } }
                @media (max-width: 1100px) { .scroll-wrapper { display: none !important; } .mobile-controls { display: flex !important; } }
                @media (max-width: 1100px) and (min-width: 601px) { .fish-cards { grid-template-columns: 1fr 1fr; } }
                @media (max-width: 600px) { .fish-cards { grid-template-columns: 1fr; } }
            </style>

            <div class="mobile-controls">
                <select id="mobile-sort">
                    <option value="sci">Scientific Name A-Z</option>
                    <option value="common">Common Name A-Z</option>
                    <option value="price-low">Price Low to High</option>
                    <option value="price-high">Price High to Low</option>
                    <option value="stock-high">Stock High to Low</option>
                </select>
                <input type="text" id="mobile-search" placeholder="Search fish...">
            </div>

            <div class="scroll-wrapper">
                <table class="fishotel-open-table">
                    <thead><tr style="background:#f8f8f8;">
                        <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;cursor:pointer;" data-sort="common">Common Name</th>
                        <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;cursor:pointer;" data-sort="sci">Scientific Name</th>
                        <th style="padding:6px 8px;text-align:center;border-bottom:2px solid #ddd;">Size</th>
                        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #ddd;cursor:pointer;" data-sort="price">Avg Price</th>
                        <th style="padding:6px 8px;text-align:center;border-bottom:2px solid #ddd;cursor:pointer;" data-sort="stock">Stock</th>
                        <th style="padding:6px 8px;text-align:center;border-bottom:2px solid #ddd;">Action</th>
                    </tr></thead><tbody>
                    <?php foreach ( $batch_posts as $bp ) {
                        $master_id = get_post_meta( $bp->ID, '_master_id', true );
                        if ( ! $master_id ) continue;
                        $master = get_post( $master_id );
                        if ( ! $master ) continue;
                        $sci_name = get_post_meta( $master_id, '_scientific_name', true );
                        $price = floatval( get_post_meta( $master_id, '_selling_price', true ) );
                        $stock = floatval( get_post_meta( $bp->ID, '_stock', true ) );
                        $size = '';
                        $title_to_check = $master->post_title . ' ' . $bp->post_title;
                        if ( preg_match( '/\((SM|MED|Lrg|XL|Nano|Tiny)\)/i', $title_to_check, $matches ) ) $size = strtoupper( $matches[1] );
                        echo '<tr style="border-bottom:1px solid #eee;" data-price="' . $price . '" data-stock="' . $stock . '" data-common="' . esc_attr( strtolower( $master->post_title ) ) . '" data-sci="' . esc_attr( strtolower( $sci_name ) ) . '">';
                        echo '<td style="padding:6px 8px;font-weight:600;">' . esc_html( $master->post_title ) . '</td>';
                        echo '<td style="padding:6px 8px;color:#555;font-style:italic;">' . esc_html( $sci_name ) . '</td>';
                        echo '<td style="padding:6px 8px;text-align:center;">' . ( $size ? '<span style="background:#e67e22;color:white;padding:3px 8px;border-radius:3px;font-size:0.8em;">' . esc_html( $size ) . '</span>' : '—' ) . '</td>';
                        echo '<td style="padding:6px 8px;text-align:right;color:#e67e22;font-weight:600;">$' . number_format( $price, 2 ) . '</td>';
                        echo '<td style="padding:6px 8px;text-align:center;font-weight:600;color:' . ( $stock > 0 ? '#27ae60' : '#e74c3c' ) . ';">' . $stock . '</td>';
                        echo '<td style="padding:6px 8px;text-align:center;white-space:nowrap;">';
                        if ( $stock > 0 ) {
                            echo '<div style="display:inline-flex;align-items:center;background:#333;border:1px solid #e67e22;border-radius:4px;overflow:hidden;">';
                            echo '<button class="qty-minus" style="background:none;border:none;color:#e67e22;padding:4px 8px;cursor:pointer;">−</button>';
                            echo '<input type="number" min="1" value="1" class="qty-input" style="width:45px;text-align:center;background:white;color:#333;border:none;padding:4px 0;">';
                            echo '<button class="qty-plus" style="background:none;border:none;color:#e67e22;padding:4px 8px;cursor:pointer;">+</button>';
                            echo '</div> ';
                            echo '<button class="add-to-request button button-small" data-batch-id="' . $bp->ID . '" data-price="' . $price . '" data-fish-name="' . esc_attr( $master->post_title ) . '" style="padding:5px 12px;font-size:0.85em;margin-left:6px;">Request</button>';
                        } else {
                            echo '<span style="color:#95a5a6;">Sold Out</span>';
                        }
                        echo '</td>';
                        echo '</tr>';
                    } ?>
                    </tbody></table>
                </div>

                <div class="fish-cards">
                    <?php foreach ( $batch_posts as $bp ) {
                        $master_id = get_post_meta( $bp->ID, '_master_id', true );
                        if ( ! $master_id ) continue;
                        $master = get_post( $master_id );
                        if ( ! $master ) continue;
                        $sci_name = get_post_meta( $master_id, '_scientific_name', true );
                        $price = floatval( get_post_meta( $master_id, '_selling_price', true ) );
                        $stock = floatval( get_post_meta( $bp->ID, '_stock', true ) );
                        $size = '';
                        $title_to_check = $master->post_title . ' ' . $bp->post_title;
                        if ( preg_match( '/\((SM|MED|Lrg|XL|Nano|Tiny)\)/i', $title_to_check, $matches ) ) $size = strtoupper( $matches[1] );
                        echo '<div class="fish-card" data-price="' . $price . '" data-stock="' . $stock . '" data-common="' . esc_attr( strtolower( $master->post_title ) ) . '" data-sci="' . esc_attr( strtolower( $sci_name ) ) . '">';
                        echo '<h4>' . esc_html( $master->post_title ) . '</h4>';
                        echo '<div class="sci">' . esc_html( $sci_name ) . '</div>';
                        echo '<div style="margin:10px 0;">';
                        if ( $size ) echo '<span style="background:#e67e22;color:white;padding:3px 8px;border-radius:3px;font-size:0.8em;margin-right:8px;">' . esc_html( $size ) . '</span>';
                        echo '<span class="price">$' . number_format( $price, 2 ) . '</span>';
                        echo ' <span class="stock">Stock: ' . $stock . '</span>';
                        echo '</div>';
                        if ( $stock > 0 ) {
                            echo '<div class="action">';
                            echo '<div style="display:flex;align-items:center;background:#333;border:1px solid #e67e22;border-radius:4px;overflow:hidden;">';
                            echo '<button class="qty-minus" style="background:none;border:none;color:#e67e22;padding:6px 10px;cursor:pointer;">−</button>';
                            echo '<input type="number" min="1" value="1" class="qty-input" style="width:50px;text-align:center;background:white;color:#333;border:none;padding:6px 0;">';
                            echo '<button class="qty-plus" style="background:none;border:none;color:#e67e22;padding:6px 10px;cursor:pointer;">+</button>';
                            echo '</div>';
                            echo '<button class="add-to-request button button-small" data-batch-id="' . $bp->ID . '" data-price="' . $price . '" data-fish-name="' . esc_attr( $master->post_title ) . '" style="flex:1;padding:10px;font-size:1em;">Request</button>';
                            echo '</div>';
                        } else {
                            echo '<span style="color:#95a5a6;display:block;margin-top:10px;">Sold Out</span>';
                        }
                        echo '</div>';
                    } ?>
                </div>

                <script>
                    let cartTotal = 0;
                    let cartItems = [];
                    let currentUserHasHFUsername = <?php echo ( get_user_meta( get_current_user_id(), '_fishotel_humble_username', true ) !== '' ) ? 'true' : 'false'; ?>;

                    if (<?php echo is_user_logged_in() ? 'true' : 'false'; ?> && !currentUserHasHFUsername) {
                        setTimeout(function() {
                            showHFUsernameModal();
                        }, 800);
                    }

                    function showLoginModal(batchId, price, fishName) {
                        document.getElementById('fishotel-login-modal').style.display = 'flex';
                        document.getElementById('fishotel-login-form').onsubmit = function(e) {
                            e.preventDefault();
                            const btn = document.getElementById('fishotel-login-btn');
                            btn.innerHTML = 'Logging in...';
                            btn.disabled = true;
                            jQuery.ajax({
                                url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                                type: "POST",
                                data: {
                                    action: "fishotel_ajax_login",
                                    username: document.getElementById('fishotel-username').value,
                                    password: document.getElementById('fishotel-password').value,
                                    nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        closeLoginModal();
                                        location.reload();
                                    } else {
                                        alert(response.data.message || 'Login failed. Please try again.');
                                        btn.innerHTML = 'LOG IN';
                                        btn.disabled = false;
                                    }
                                },
                                error: function() {
                                    alert('Login error. Please try again.');
                                    btn.innerHTML = 'LOG IN';
                                    btn.disabled = false;
                                }
                            });
                        };
                    }

                    function showHFUsernameModal(batchId, price, fishName) {
                        document.getElementById('hf-username-modal').style.display = 'flex';
                        document.getElementById('hf-username-form').onsubmit = function(e) {
                            e.preventDefault();
                            const btn = document.getElementById('hf-username-btn');
                            btn.innerHTML = 'Saving...';
                            btn.disabled = true;
                            const hfName = document.getElementById('hf-username-input').value.trim();
                            jQuery.ajax({
                                url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                                type: "POST",
                                data: {
                                    action: "fishotel_save_hf_username",
                                    hf_username: hfName,
                                    nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        currentUserHasHFUsername = true;
                                        closeHFModal();
                                        location.reload();
                                    } else {
                                        alert('Error saving username. Please try again.');
                                        btn.innerHTML = 'Save & Continue';
                                        btn.disabled = false;
                                    }
                                },
                                error: function() {
                                    alert('Error saving username. Please try again.');
                                    btn.innerHTML = 'Save & Continue';
                                    btn.disabled = false;
                                }
                            });
                        };
                    }

                    function closeHFModal() { document.getElementById('hf-username-modal').style.display = 'none'; }
                    function closeLoginModal() { document.getElementById('fishotel-login-modal').style.display = 'none'; }

                    function renderRequestList() {
                        let list = document.getElementById("request-list");
                        list.innerHTML = '';
                        cartItems.forEach((item, index) => {
                            const lineTotal = item.price * item.qty;
                            list.innerHTML += `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;" data-line-total="${lineTotal}" data-index="${index}">
                                <span>${item.fish_name} × ${item.qty} = $${lineTotal.toFixed(2)}</span>
                                <button onclick="removeItem(this)" title="Remove this item" style="background:none;border:none;color:#e74c3c;font-size:1.8em;cursor:pointer;padding:0 8px;">×</button>
                            </div>`;
                        });
                        if (cartItems.length === 0) list.innerHTML = "No fish requested yet.";
                        updateCartTotal();
                    }

                    function updateCartTotal() {
                        document.getElementById("cart-total").innerHTML = `Total: $${cartTotal.toFixed(2)}`;
                    }

                    document.querySelectorAll(".qty-minus").forEach(btn => {
                        btn.addEventListener("click", function() {
                            const input = this.nextElementSibling;
                            let val = parseInt(input.value) || 1;
                            if (val > 1) input.value = val - 1;
                        });
                    });
                    document.querySelectorAll(".qty-plus").forEach(btn => {
                        btn.addEventListener("click", function() {
                            const input = this.previousElementSibling;
                            let val = parseInt(input.value) || 1;
                            input.value = val + 1;
                        });
                    });

                    document.querySelectorAll(".add-to-request").forEach(btn => {
                        btn.addEventListener("click", function() {
                            const batchId = this.getAttribute("data-batch-id");
                            const price = parseFloat(this.getAttribute("data-price")) || 0;
                            const fishName = this.getAttribute("data-fish-name") || this.closest(".fish-card").querySelector("h4").innerText || this.closest("tr").querySelector("td").innerText;

                            if (!<?php echo is_user_logged_in() ? 'true' : 'false'; ?>) {
                                showLoginModal(batchId, price, fishName);
                                return;
                            }

                            if (!currentUserHasHFUsername) {
                                showHFUsernameModal(batchId, price, fishName);
                                return;
                            }

                            jQuery.ajax({
                                url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                                type: "POST",
                                data: { 
                                    action: "fishotel_check_balance",
                                    batch_name: "<?php echo esc_js( $batch_name ); ?>",
                                    nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>'
                                },
                                success: function(response) {
                                    if (response.success && response.data.enough_balance) {
                                        const qtyInput = btn.closest('tr')
                                            ? btn.closest('tr').querySelector('.qty-input')
                                            : btn.closest('.fish-card') ? btn.closest('.fish-card').querySelector('.qty-input') : null;
                                        const qty = parseInt(qtyInput ? qtyInput.value : 1) || 1;
                                        cartItems.push({
                                            batch_id: batchId,
                                            fish_name: fishName,
                                            qty: qty,
                                            price: price
                                        });
                                        cartTotal += price * qty;
                                        renderRequestList();

                                        const originalText = btn.innerText;
                                        btn.innerText = "Added!";
                                        btn.style.backgroundColor = "#27ae60";
                                        setTimeout(() => {
                                            btn.innerText = originalText;
                                            btn.style.backgroundColor = "";
                                        }, 1200);
                                    } else {
                                        const needed = response.data.needed || <?php echo $this->get_deposit_amount(); ?>;
                                        window.location.href = '<?php echo home_url( "/wallet-deposit" ); ?>?suggested=' + needed;
                                    }
                                }
                            });
                        });
                    });

                    window.removeItem = function(btn) {
                        const line = btn.parentNode;
                        const index = parseInt(line.getAttribute("data-index"));
                        const lineTotal = parseFloat(line.getAttribute("data-line-total")) || 0;
                        cartTotal -= lineTotal;
                        cartItems.splice(index, 1);
                        renderRequestList();
                    };

                    document.getElementById("mobile-sort").addEventListener("change", function() {
                        const sortType = this.value;
                        const cards = Array.from(document.querySelectorAll(".fish-card"));
                        cards.sort((a, b) => {
                            if (sortType === "sci") return a.dataset.sci.localeCompare(b.dataset.sci);
                            if (sortType === "common") return a.dataset.common.localeCompare(b.dataset.common);
                            if (sortType === "price-low") return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
                            if (sortType === "price-high") return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
                            if (sortType === "stock-high") return parseFloat(b.dataset.stock) - parseFloat(a.dataset.stock);
                            return 0;
                        });
                        const container = document.querySelector(".fish-cards");
                        cards.forEach(card => container.appendChild(card));
                    });

                    document.getElementById("mobile-search").addEventListener("keyup", function() {
                        const term = this.value.toLowerCase().trim();
                        const cards = document.querySelectorAll(".fish-card");
                        let firstMatch = null;
                        cards.forEach(card => {
                            const common = card.dataset.common;
                            const sci = card.dataset.sci;
                            if (common.includes(term) || sci.includes(term)) {
                                card.style.display = "block";
                                if (!firstMatch) firstMatch = card;
                            } else {
                                card.style.display = "none";
                            }
                        });
                        if (firstMatch && term !== "") {
                            firstMatch.scrollIntoView({ behavior: "smooth", block: "center" });
                        }
                    });

                    // Desktop table column sorting
                    (function() {
                        const table = document.querySelector('.fishotel-open-table');
                        if (!table) return;
                        const tbody = table.querySelector('tbody');
                        let lastSortKey = null;
                        let lastAsc = true;
                        table.querySelectorAll('th[data-sort]').forEach(function(th) {
                            th.addEventListener('click', function() {
                                const key = th.getAttribute('data-sort');
                                const asc = (lastSortKey === key) ? !lastAsc : true;
                                lastSortKey = key;
                                lastAsc = asc;
                                // Update arrow indicators
                                table.querySelectorAll('th[data-sort]').forEach(function(h) {
                                    h.classList.remove('sort-asc', 'sort-desc');
                                });
                                th.classList.add(asc ? 'sort-asc' : 'sort-desc');
                                // Sort rows
                                const rows = Array.from(tbody.querySelectorAll('tr'));
                                rows.sort(function(a, b) {
                                    let valA = a.getAttribute('data-' + key) || '';
                                    let valB = b.getAttribute('data-' + key) || '';
                                    if (key === 'price' || key === 'stock') {
                                        valA = parseFloat(valA) || 0;
                                        valB = parseFloat(valB) || 0;
                                        return asc ? valA - valB : valB - valA;
                                    }
                                    return asc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                                });
                                rows.forEach(function(row) { tbody.appendChild(row); });
                            });
                        });
                    })();

                    document.getElementById("submit-requests").addEventListener("click", function() {
                        if (cartItems.length === 0) {
                            alert("No requests to submit.");
                            return;
                        }
                        jQuery.ajax({
                            url: "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                            type: "POST",
                            data: {
                                action: "fishotel_submit_requests",
                                cart_items: JSON.stringify(cartItems),
                                total: cartTotal,
                                batch_name: "<?php echo esc_js( $batch_name ); ?>",
                                nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert("Request saved and stock reserved!\n\n" + (response.data.message || ''));
                                    location.reload();
                                } else if (response.data && response.data.needs_payment) {
                                    const needed = response.data.deposit_due || <?php echo $this->get_deposit_amount(); ?>;
                                    window.location.href = '<?php echo home_url( "/wallet-deposit" ); ?>?suggested=' + needed;
                                } else {
                                    alert(response.data.message || "Error submitting request.");
                                }
                            },
                            error: function() {
                                alert("Error submitting request. Please try again.");
                            }
                        });
                    });
                </script>
            <?php
            return ob_get_clean();
        }
        return '<p>Stage "' . esc_html( $status ) . '" is coming soon.</p>';
    }

    public function handle_csv_import() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'fishotel_import_csv_nonce' ) ) wp_die( 'Security check failed.' );
        if ( ! isset( $_FILES['fish_csv'] ) || $_FILES['fish_csv']['error'] !== UPLOAD_ERR_OK ) wp_die( 'No file uploaded or upload error.' );

        $file = $_FILES['fish_csv'];
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'csv' ) wp_die( 'Please upload a .csv file.' );

        $upload_dir = wp_upload_dir();
        $target = $upload_dir['basedir'] . '/fishotel-import-' . time() . '.csv';
        move_uploaded_file( $file['tmp_name'], $target );

        $rows = [];
        if ( ( $handle = fopen( $target, 'r' ) ) !== false ) {
            while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) $rows[] = array_map( 'trim', $data );
            fclose( $handle );
        }

        $header_row = 0;
        foreach ( $rows as $i => $row ) {
            $row_str = implode( ' ', $row );
            if ( stripos( $row_str, 'Scientific Name' ) !== false || ( stripos( $row_str, 'CODE' ) !== false && stripos( $row_str, 'COMMON NAME' ) !== false && stripos( $row_str, 'SCIENTIFIC NAME' ) !== false ) ) {
                $header_row = $i;
                break;
            }
        }

        $headers = $rows[$header_row];
        $fish_start = $header_row + 1;
        for ( $i = $header_row + 1; $i < count( $rows ); $i++ ) {
            $row_str = implode( ' ', $rows[$i] );
            if ( stripos( $row_str, 'CODE' ) !== false && stripos( $row_str, 'COMMON NAME' ) !== false && stripos( $row_str, 'SCIENTIFIC NAME' ) !== false ) {
                $fish_start = $i + 1;
                break;
            }
            if ( stripos( $row_str, 'FISH' ) !== false ) {
                $fish_start = $i + 1;
                break;
            }
        }

        $fish_end = count( $rows );
        for ( $i = $fish_start; $i < count( $rows ); $i++ ) {
            $row_str = implode( ' ', $rows[$i] );
            if ( stripos( $row_str, 'INVERTS' ) !== false || stripos( $row_str, 'CORALS' ) !== false ) {
                $fish_end = $i;
                break;
            }
        }

        $fish_rows = array_slice( $rows, $fish_start, $fish_end - $fish_start );

        $stock_col = -1;
        $stock_keywords = ['qty', 'quantity', 'stock', 'order', 'available'];
        foreach ( $headers as $i => $col ) {
            $col_lower = strtolower( $col );
            if ( stripos( $col_lower, 'qty' ) !== false ) {
                $stock_col = $i;
                break;
            }
        }
        if ( $stock_col === -1 ) {
            foreach ( $headers as $i => $col ) {
                $col_lower = strtolower( $col );
                foreach ( $stock_keywords as $kw ) {
                    if ( stripos( $col_lower, $kw ) !== false ) {
                        $stock_col = $i;
                        break 2;
                    }
                }
            }
        }
        if ( $stock_col === -1 ) {
            $best_count = 0;
            for ( $c = 0; $c < count( $headers ); $c++ ) {
                $count = 0;
                foreach ( $fish_rows as $row ) {
                    if ( isset( $row[$c] ) && is_numeric( $row[$c] ) && (float)$row[$c] > 0 ) $count++;
                }
                if ( $count > $best_count ) {
                    $best_count = $count;
                    $stock_col = $c;
                }
            }
        }

        $detected_stock_name = $stock_col >= 0 ? $headers[$stock_col] : 'None detected';

        echo '<h2>Preview — Fish Section Detected</h2>';
        echo '<p><strong>Auto-detected Quantity Column:</strong> ' . esc_html( $detected_stock_name ) . '</p>';

        echo '<form method="post" action="' . admin_url( 'admin-post.php' ) . '">';
        echo '<input type="hidden" name="action" value="fishotel_process_mapping">';
        wp_nonce_field( 'fishotel_process_mapping_nonce' );
        echo '<input type="hidden" name="csv_file" value="' . esc_attr( basename( $target ) ) . '">';
        echo '<input type="hidden" name="header_row" value="' . $header_row . '">';

        echo '<p><button type="button" onclick="selectAll()">Select All</button> <button type="button" onclick="deselectAll()">Deselect All</button></p>';

        echo '<table border="1" cellpadding="5" style="border-collapse:collapse;">';
        echo '<tr><th>Select</th><th>#</th>';
        foreach ( $headers as $h ) echo '<th>' . esc_html( $h ) . '</th>';
        echo '</tr>';

        foreach ( $fish_rows as $idx => $row ) {
            $padded_row = array_pad( $row, count( $headers ), '' );
            $checked = '';
            if ( $stock_col >= 0 && isset( $padded_row[$stock_col] ) && is_numeric( $padded_row[$stock_col] ) && (float)$padded_row[$stock_col] > 0 ) $checked = 'checked';
            echo '<tr>';
            echo '<td><input type="checkbox" name="selected[]" value="' . $idx . '" ' . $checked . '></td>';
            echo '<td>' . ( $idx + 1 ) . '</td>';
            foreach ( $padded_row as $cell ) echo '<td>' . esc_html( $cell ) . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        echo '<h3>Map Columns</h3>';
        $key_fields = ['Scientific Name', 'Common Name / Description', 'Stock / Quantity', 'Item Code'];
        foreach ( $key_fields as $field ) {
            $key = sanitize_key( $field );
            echo '<p><strong>' . esc_html( $field ) . ':</strong> ';
            echo '<select name="map_' . $key . '">';
            echo '<option value="">— Ignore —</option>';
            foreach ( $headers as $i => $col ) echo '<option value="' . $i . '">' . esc_html( $col ) . '</option>';
            echo '</select></p>';
        }

        $batches_str = get_option( 'fishotel_batches', '' );
        $batches = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );
        $current_batch = get_option( 'fishotel_current_batch', '' );

        echo '<h3>Assign to Batch</h3>';
        echo '<select name="import_batch" style="width:300px;">';
        foreach ( $batches as $b ) {
            $sel = ( $b === $current_batch ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $b ) . '" ' . $sel . '>' . esc_html( $b ) . '</option>';
        }
        echo '</select>';

        echo '<br><br><input type="submit" class="button button-primary" value="Import Selected Fish into Master Library">';
        echo '</form>';

        ?>
        <script>
        function selectAll() { document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = true); }
        function deselectAll() { document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = false); }
        </script>
        <?php
    }

    public function process_mapping() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_process_mapping_nonce' ) ) wp_die( 'Security check failed.' );

        $csv_file = basename( sanitize_text_field( $_POST['csv_file'] ) );
        $header_row = (int) $_POST['header_row'];
        $selected = isset( $_POST['selected'] ) ? array_map( 'intval', $_POST['selected'] ) : [];
        $import_batch = sanitize_text_field( $_POST['import_batch'] );

        $upload_dir = wp_upload_dir();
        $target = $upload_dir['basedir'] . '/' . $csv_file;

        $rows = [];
        if ( ( $handle = fopen( $target, 'r' ) ) !== false ) {
            while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) $rows[] = array_map( 'trim', $data );
            fclose( $handle );
        }

        $headers = $rows[$header_row];
        $fish_start = $header_row + 1;

        foreach ( $rows as $i => $row ) {
            if ( $i > $header_row && stripos( implode( ' ', $row ), 'FISH' ) !== false ) {
                $fish_start = $i + 1;
                break;
            }
        }

        $fish_rows = array_slice( $rows, $fish_start );

        $imported = 0;

        foreach ( $selected as $idx ) {
            $row = array_pad( $fish_rows[$idx], count( $headers ), '' );

            $sci_name = '';
            $common_name = '';
            $stock = 0;
            $item_code = '';

            if ( isset( $_POST['map_scientificname'] ) ) $sci_name = $row[(int)$_POST['map_scientificname']] ?? '';
            if ( isset( $_POST['map_commonnamedescription'] ) ) $common_name = $row[(int)$_POST['map_commonnamedescription']] ?? '';
            if ( isset( $_POST['map_stockquantity'] ) ) $stock = (float)($row[(int)$_POST['map_stockquantity']] ?? 0);
            if ( isset( $_POST['map_itemcode'] ) ) $item_code = $row[(int)$_POST['map_itemcode']] ?? '';

            if ( empty( $sci_name ) ) continue;

            $sci_name    = ucwords( strtolower( $sci_name ) );
            $common_name = ucwords( strtolower( $common_name ?: $sci_name ) );

            $master = get_posts( [
                'post_type'   => 'fish_master',
                'meta_query'  => [ [ 'key' => '_scientific_name', 'value' => $sci_name, 'compare' => '=' ] ],
                'numberposts' => 1,
            ]);

            if ( $master ) {
                $master_id = $master[0]->ID;
            } else {
                $master_id = wp_insert_post( [
                    'post_type'   => 'fish_master',
                    'post_title'  => $common_name,
                    'post_status' => 'publish',
                ]);
                if ( $master_id ) update_post_meta( $master_id, '_scientific_name', $sci_name );
            }

            if ( $master_id ) {
                $batch_title = $common_name . ' - ' . $import_batch;

                $batch_post = wp_insert_post( [
                    'post_type'   => 'fish_batch',
                    'post_title'  => $batch_title,
                    'post_status' => 'publish',
                ]);

                if ( $batch_post ) {
                    update_post_meta( $batch_post, '_master_id', $master_id );
                    update_post_meta( $batch_post, '_batch_name', $import_batch );
                    update_post_meta( $batch_post, '_stock', $stock );
                    update_post_meta( $batch_post, '_item_code', $item_code );
                    $imported++;
                }
            }
        }

        echo '<h2>Import Complete!</h2>';
        echo '<p>' . $imported . ' batch fish items created for batch <strong>' . esc_html( $import_batch ) . '</strong>.</p>';
        echo '<p><a href="' . admin_url( 'edit.php?post_type=fish_master' ) . '">View Master Fish Library</a></p>';
    }

    public function sync_page_html() {
        $products = wc_get_products( [ 'category' => [ 'quarantined-fish' ], 'limit' => -1, 'status' => 'publish' ] );
        ?>
        <div class="wrap fishotel-admin">
            <h1>Sync Quarantined Fish Products</h1>
            <p class="page-description">Click any column header to sort.</p>

            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <?php wp_nonce_field( 'fishotel_sync_quarantined' ); ?>
                <input type="hidden" name="action" value="fishotel_sync_quarantined">

                <p><button type="button" onclick="selectAllProducts()">Select All</button> <button type="button" onclick="deselectAllProducts()">Deselect All</button></p>

                <table id="quarantined-table" class="widefat">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-products"></th>
                            <th>Product Name</th>
                            <th>ID</th>
                            <th>Current Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $products as $product ) : ?>
                            <tr>
                                <td><input type="checkbox" name="products[]" value="<?php echo $product->get_id(); ?>"></td>
                                <td><?php echo $product->get_name(); ?></td>
                                <td><?php echo $product->get_id(); ?></td>
                                <td><?php echo wc_price( $product->get_price() ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <br><input type="submit" class="button button-primary" value="Sync Selected Products Now">
            </form>

            <script>
            document.querySelectorAll('#quarantined-table th').forEach((header, index) => {
                header.style.cursor = 'pointer';
                header.addEventListener('click', () => {
                    const table = header.closest('table');
                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const isNumeric = index === 2 || index === 3;
                    rows.sort((a, b) => {
                        let valA = a.cells[index].innerText.trim();
                        let valB = b.cells[index].innerText.trim();
                        if (isNumeric) {
                            valA = parseFloat(valA.replace(/[^0-9.]/g, '')) || 0;
                            valB = parseFloat(valB.replace(/[^0-9.]/g, '')) || 0;
                        }
                        return valA > valB ? 1 : valA < valB ? -1 : 0;
                    });
                    rows.forEach(row => tbody.appendChild(row));
                });
            });

            function selectAllProducts() {
                document.querySelectorAll('input[name="products[]"]').forEach(cb => cb.checked = true);
                document.getElementById('select-all-products').checked = true;
            }
            function deselectAllProducts() {
                document.querySelectorAll('input[name="products[]"]').forEach(cb => cb.checked = false);
                document.getElementById('select-all-products').checked = false;
            }
            document.getElementById('select-all-products').addEventListener('change', function() {
                if (this.checked) selectAllProducts();
                else deselectAllProducts();
            });
            </script>
        </div>
        <?php
    }

    public function sync_all_quarantined() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'fishotel_sync_quarantined' ) ) wp_die( 'Security check failed.' );
        $products = isset( $_POST['products'] ) ? array_map( 'intval', $_POST['products'] ) : [];
        $synced = 0;
        foreach ( $products as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $this->sync_wc_to_master( $product_id, get_post( $product_id ), true );
                $synced++;
            }
        }
        echo '<h2>Sync Complete!</h2>';
        echo '<p>Synced ' . $synced . ' selected products to Master Fish Library.</p>';
        echo '<p><a href="' . admin_url( 'edit.php?post_type=fish_master' ) . '">View Master Fish Library</a></p>';
    }

    public function add_fishotel_price_field() {
        woocommerce_wp_text_input( [
            'id'          => '_fishotel_selling_price',
            'label'       => 'FisHotel Selling Price',
            'placeholder' => '0.00',
            'desc_tip'    => 'true',
            'description' => 'This price will be used in the Master Fish Library and public list.',
            'type'        => 'number',
            'custom_attributes' => [ 'step' => '0.01' ]
        ]);
    }

    public function save_fishotel_price_field( $post_id ) {
        $price = isset( $_POST['_fishotel_selling_price'] ) ? wc_clean( $_POST['_fishotel_selling_price'] ) : '';
        update_post_meta( $post_id, '_fishotel_selling_price', $price );
    }

    public function sync_wc_to_master( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( $post->post_type !== 'product' ) return;

        $categories = wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'slugs' ] );
        if ( ! in_array( 'quarantined-fish', $categories ) ) return;

        $product = wc_get_product( $post_id );

        $sci_name = wp_strip_all_tags( $product->get_short_description() );
        if ( empty( $sci_name ) ) $sci_name = wp_strip_all_tags( $product->get_description() );
        $sci_name = ucwords( strtolower( $sci_name ) );

        $common_name = ucwords( strtolower( $product->get_name() ) );
        $price = get_post_meta( $post_id, '_fishotel_selling_price', true ) ?: $product->get_price();

        $master = get_posts( [
            'post_type'   => 'fish_master',
            'meta_query'  => [ [ 'key' => '_scientific_name', 'value' => $sci_name, 'compare' => '=' ] ],
            'numberposts' => 1,
        ]);

        if ( $master ) {
            $master_id = $master[0]->ID;
            wp_update_post( [ 'ID' => $master_id, 'post_title' => $common_name ] );
        } else {
            $master_id = wp_insert_post( [
                'post_type'   => 'fish_master',
                'post_title'  => $common_name,
                'post_status' => 'publish',
            ]);
        }

        if ( $master_id ) {
            update_post_meta( $master_id, '_scientific_name', $sci_name );
            update_post_meta( $master_id, '_selling_price', floatval( $price ) );
            update_post_meta( $master_id, '_wc_product_id', $post_id );
            update_post_meta( $post_id, '_linked_fish_master', $master_id );
        }
    }

    public function add_fish_meta_box() {
        add_meta_box( 'fish_details', 'Master Fish Details', [$this, 'fish_meta_box_html'], 'fish_master', 'normal', 'high' );
    }

    public function fish_meta_box_html( $post ) {
        wp_nonce_field( 'fish_meta_nonce', 'fish_meta_nonce' );
        $woo_id = get_post_meta( $post->ID, '_wc_product_id', true );
        $view_link = '';
        if ( $woo_id && wc_get_product( $woo_id ) ) $view_link = ' <a href="' . get_edit_post_link( $woo_id ) . '" target="_blank" class="button button-small button-primary">View Product Page</a>';
        ?>
        <table class="form-table">
            <tr><th><label>Scientific Name</label></th><td><input type="text" name="scientific_name" value="<?php echo esc_attr( get_post_meta( $post->ID, '_scientific_name', true ) ); ?>" style="width:100%;"></td></tr>
            <tr><th><label>Selling Price</label></th><td><input type="number" step="0.01" name="selling_price" value="<?php echo esc_attr( get_post_meta( $post->ID, '_selling_price', true ) ); ?>" style="width:120px;"> USD</td></tr>
            <tr><th><label>Linked WooCommerce Product ID</label></th><td><input type="text" name="wc_product_id" value="<?php echo esc_attr( $woo_id ); ?>" style="width:120px;" readonly><?php echo $view_link; ?></td></tr>
        </table>
        <?php
    }

    public function add_batch_items_metabox() {
        add_meta_box( 'fish_batch_items', 'Attached Batch Items', [$this, 'render_batch_items_metabox'], 'fish_master', 'normal', 'high' );
    }

    public function render_batch_items_metabox( $post ) {
        $children = get_posts( [ 'post_type' => 'fish_batch', 'meta_key' => '_master_id', 'meta_value' => $post->ID, 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ] );
        if ( empty( $children ) ) { echo '<p>No batch items attached yet.</p>'; return; }
        echo '<table class="widefat fixed striped" style="margin-top:15px;"><thead><tr><th>Batch Name</th><th>Stock</th><th>Item Code</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $children as $child ) {
            $stock = get_post_meta( $child->ID, '_stock', true );
            $item_code = get_post_meta( $child->ID, '_item_code', true );
            $batch_name = get_post_meta( $child->ID, '_batch_name', true );
            $edit_url = admin_url( 'post.php?post=' . $child->ID . '&action=edit' );
            $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_delete_batch_item&batch_id=' . $child->ID ), 'delete_batch_item' );
            echo '<tr><td>' . esc_html( $child->post_title ) . ' <small>(' . esc_html( $batch_name ) . ')</small></td><td><strong>' . esc_html( $stock ) . '</strong></td><td>' . esc_html( $item_code ) . '</td><td><a href="' . esc_url( $edit_url ) . '" class="button button-small">Edit</a> <a href="' . esc_url( $delete_url ) . '" class="button button-small button-link-delete" onclick="return confirm(\'Delete this batch item?\');">Delete</a></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><small>Stock and details can be edited by clicking "Edit". Deleted items are permanently removed from this master.</small></p>';
    }

    public function delete_batch_item() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_batch_item' ) ) wp_die( 'Security check failed.' );
        $batch_id = intval( $_GET['batch_id'] );
        wp_delete_post( $batch_id, true );
        wp_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=fish_master' ) );
        exit;
    }

    public function save_fish_meta( $post_id ) {
        if ( ! isset( $_POST['fish_meta_nonce'] ) || ! wp_verify_nonce( $_POST['fish_meta_nonce'], 'fish_meta_nonce' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        update_post_meta( $post_id, '_scientific_name', sanitize_text_field( $_POST['scientific_name'] ?? '' ) );
        update_post_meta( $post_id, '_selling_price', floatval( $_POST['selling_price'] ?? 0 ) );
        update_post_meta( $post_id, '_wc_product_id', intval( $_POST['wc_product_id'] ?? 0 ) );
    }

    public function sync_price_master_to_woo( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $this->is_syncing || $meta_key !== '_selling_price' || get_post_type( $post_id ) !== 'fish_master' ) return;
        $woo_id = get_post_meta( $post_id, '_wc_product_id', true );
        if ( $woo_id ) {
            $this->is_syncing = true;
            update_post_meta( $woo_id, '_fishotel_selling_price', $meta_value );
            update_post_meta( $woo_id, '_price', $meta_value );
            update_post_meta( $woo_id, '_regular_price', $meta_value );
            $this->is_syncing = false;
        }
    }

    public function sync_price_woo_to_master( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $this->is_syncing || $meta_key !== '_fishotel_selling_price' || get_post_type( $post_id ) !== 'product' ) return;
        $master_id = get_post_meta( $post_id, '_linked_fish_master', true );
        if ( $master_id ) {
            $this->is_syncing = true;
            update_post_meta( $master_id, '_selling_price', $meta_value );
            $this->is_syncing = false;
        }
    }

    public function master_columns( $columns ) {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = 'Common Name';
        $new_columns['scientific_name'] = 'Scientific Name';
        $new_columns['selling_price'] = 'Selling Price';
        $new_columns['batch_items'] = 'Batch Items';
        $new_columns['actions'] = 'Actions';
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public function master_column_content( $column, $post_id ) {
        if ( $column === 'scientific_name' ) echo esc_html( get_post_meta( $post_id, '_scientific_name', true ) );
        if ( $column === 'selling_price' ) {
            $price = get_post_meta( $post_id, '_selling_price', true );
            echo $price ? wc_price( $price ) : '—';
        }
        if ( $column === 'batch_items' ) {
            $children = get_posts( [ 'post_type' => 'fish_batch', 'meta_key' => '_master_id', 'meta_value' => $post_id, 'numberposts' => -1, 'fields' => 'ids' ] );
            $count = count( $children );
            $total_stock = 0;
            foreach ( $children as $child_id ) $total_stock += (float) get_post_meta( $child_id, '_stock', true );
            echo $count . ' items (' . $total_stock . ' total stock)';
        }
        if ( $column === 'actions' ) {
            $woo_id = get_post_meta( $post_id, '_wc_product_id', true );
            if ( $woo_id && get_post( $woo_id ) ) {
                $view_url = admin_url( 'post.php?post=' . $woo_id . '&action=edit' );
                echo '<a href="' . esc_url( $view_url ) . '" class="button button-small button-primary">View Linked Product</a>';
            } else {
                $create_url = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_create_product_from_master&master_id=' . $post_id ), 'create_product_from_master' );
                echo '<a href="' . esc_url( $create_url ) . '" class="button button-small">Create Woo Product</a>';
            }
        }
    }

    public function add_batch() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'add_batch_nonce' ) ) wp_die( 'Security check failed.' );
        $new_batch = sanitize_text_field( $_POST['new_batch'] );
        if ( ! empty( $new_batch ) ) {
            $batches = get_option( 'fishotel_batches', '' );
            $batches_array = array_filter( array_map( 'trim', explode( "\n", $batches ) ) );
            if ( ! in_array( $new_batch, $batches_array ) ) {
                $batches_array[] = $new_batch;
                update_option( 'fishotel_batches', implode( "\n", $batches_array ) );
            }
        }
        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings' ) );
        exit;
    }

    public function create_product_from_master() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'create_product_from_master' ) ) wp_die( 'Security check failed.' );
        $master_id = intval( $_GET['master_id'] );
        $master = get_post( $master_id );
        if ( ! $master || $master->post_type !== 'fish_master' ) wp_die( 'Invalid Master.' );
        $common_name = $master->post_title;
        $sci_name = get_post_meta( $master_id, '_scientific_name', true );
        $price = floatval( get_post_meta( $master_id, '_selling_price', true ) );
        $product_id = wp_insert_post( [ 'post_type' => 'product', 'post_title' => $common_name, 'post_status' => 'draft' ] );
        if ( $product_id ) {
            wp_set_object_terms( $product_id, 'quarantined-fish', 'product_cat' );
            update_post_meta( $product_id, '_price', $price );
            update_post_meta( $product_id, '_regular_price', $price );
            update_post_meta( $product_id, '_fishotel_selling_price', $price );
            update_post_meta( $product_id, '_linked_fish_master', $master_id );
            update_post_meta( $product_id, '_sku', $sci_name );
            wp_update_post( [ 'ID' => $product_id, 'post_excerpt' => $sci_name ] );
            wp_redirect( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) );
            exit;
        }
        wp_die( 'Failed to create product.' );
    }

}

new FisHotel_Batch_Manager();