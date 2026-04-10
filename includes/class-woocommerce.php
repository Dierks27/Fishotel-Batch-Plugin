<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait FisHotel_WooCommerce {

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

    public function create_test_requests_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_create_test_requests' ) ) wp_die( 'Security check failed.' );

        $batch_statuses = get_option( 'fishotel_batch_statuses', [] );
        $transit_batch  = '';
        foreach ( $batch_statuses as $name => $stage ) {
            if ( $stage === 'orders_closed' ) { $transit_batch = $name; break; }
        }
        if ( ! $transit_batch ) {
            foreach ( $batch_statuses as $name => $stage ) {
                if ( in_array( $stage, [ 'arrived', 'orders_closed' ], true ) ) { $transit_batch = $name; break; }
            }
        }
        if ( ! $transit_batch ) {
            $transit_batch = get_option( 'fishotel_current_batch', '' );
        }
        if ( ! $transit_batch ) {
            wp_die( 'No active batch found. Create a batch first.' );
        }

        $batch_fish = get_posts( [ 'post_type' => 'fish_batch', 'meta_key' => '_batch_name', 'meta_value' => $transit_batch, 'numberposts' => -1 ] );
        if ( count( $batch_fish ) < 2 ) {
            wp_die( 'Need at least 2 batch fish in "' . esc_html( $transit_batch ) . '" to create test requests.' );
        }

        $fake_users = [
            [ 'name' => 'TestUser_Alpha',   'hf' => 'AlphaReef' ],
            [ 'name' => 'TestUser_Bravo',   'hf' => 'BravoTank' ],
            [ 'name' => 'TestUser_Charlie', 'hf' => 'CharlieMarine' ],
        ];
        $created = 0;

        for ( $i = 0; $i < 3; $i++ ) {
            shuffle( $batch_fish );
            $pick_count = min( rand( 2, 4 ), count( $batch_fish ) );
            $cart_items = [];
            $total = 0;

            for ( $j = 0; $j < $pick_count; $j++ ) {
                $bf    = $batch_fish[ $j ];
                $price = round( rand( 800, 4500 ) / 100, 2 );
                $qty   = rand( 1, 3 );
                $cart_items[] = [
                    'batch_id'     => $bf->ID,
                    'fish_name'    => preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $bf->post_title ),
                    'qty'          => $qty,
                    'price'        => $price,
                    'requested_at' => current_time( 'mysql' ),
                ];
                $total += $price * $qty;
            }

            $request_id = wp_insert_post( [
                'post_type'   => 'fish_request',
                'post_title'  => $fake_users[ $i ]['name'] . ' — ' . $transit_batch,
                'post_status' => 'publish',
            ] );

            if ( $request_id ) {
                update_post_meta( $request_id, '_customer_id',    1 );
                update_post_meta( $request_id, '_customer_name',  $fake_users[ $i ]['name'] );
                update_post_meta( $request_id, '_hf_username',    $fake_users[ $i ]['hf'] );
                update_post_meta( $request_id, '_batch_name',     $transit_batch );
                update_post_meta( $request_id, '_cart_items',     wp_json_encode( $cart_items ) );
                update_post_meta( $request_id, '_total',          $total );
                update_post_meta( $request_id, '_status',         'provisional' );
                update_post_meta( $request_id, '_is_admin_order', 0 );
                $created++;
            }
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-orders&updated=1&test_created=' . $created ) );
        exit;
    }

    public function force_deposit_purchasable( $purchasable, $product ) {
        if ( $product->get_id() === $this->get_deposit_product_id() ) return true;
        return $purchasable;
    }

    public function force_deposit_order_item_name( $name, $item, $order ) {
        if ( $item->get_product_id() === $this->get_deposit_product_id() ) return 'Wallet Refill';
        return $name;
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
                    if ( $item->get_product_id() === $this->get_deposit_product_id() || stripos( $item->get_name(), 'Wallet Refill' ) !== false ) {
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

    public function credit_wallet_on_payment( $order_id ) {
        if ( get_post_meta( $order_id, '_fishotel_wallet_credited', true ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;
        $amount = 0;
        $deposit_product_id = $this->get_deposit_product_id();
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_product_id() === $deposit_product_id || stripos( $item->get_name(), 'Wallet Refill' ) !== false ) $amount += (float) $item->get_total();
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

    public function wallet_endpoint_content() {
        $user_id = get_current_user_id();
        $balance = floatval( get_user_meta( $user_id, '_fishotel_deposit_balance', true ) );

        echo '<h2 style="font-family:Teko,sans-serif;text-transform:uppercase;letter-spacing:1px;color:#96885f;font-size:28px;font-weight:500;">My Wallet</h2>';
        echo '<div style="background:transparent;padding:20px 0;border:none;border-left:4px solid #96885f;padding-left:20px;margin-bottom:25px;box-shadow:none;border-radius:0;">';
        echo '<h3 style="margin:0 0 8px 0;color:#96885f;font-family:Teko,sans-serif;font-size:20px;text-transform:uppercase;letter-spacing:1px;font-weight:500;">Current Balance</h3>';
        echo '<p style="font-size:32px;font-weight:700;color:#fff;margin:0 0 16px 0;font-family:Montserrat,sans-serif;">$' . number_format( $balance, 2 ) . '</p>';
        echo '<p style="color:#999;font-size:14px;margin:0;font-family:Montserrat,sans-serif;">Your wallet balance is applied automatically toward batch deposits and final invoices.</p>';
        echo '</div>';
    }

    public function add_custom_orders_menu_item( $items ) { $items['my-requests'] = 'Custom Orders'; return $items; }

    public function custom_orders_endpoint_content() {
        $user_id = get_current_user_id();
        $hf_username = get_user_meta( $user_id, '_fishotel_humble_username', true );

        echo '<h2>Custom Orders</h2>';

        echo '<div style="background:transparent;padding:20px 0;border:none;border-left:4px solid #96885f;padding-left:20px;margin-bottom:25px;box-shadow:none;border-radius:0;">';
        echo '<h3 style="margin:0 0 12px 0;color:#96885f;font-family:Teko,sans-serif;font-size:22px;text-transform:uppercase;letter-spacing:1px;font-weight:500;">Humble.Fish Username</h3>';
        echo '<form id="hf-username-form-inline" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="text" id="hf-username-input" value="' . esc_attr( $hf_username ) . '" placeholder="Humble.Fish Username" style="flex:1 1 200px;padding:10px 14px;background:#1a1a1a;border:2px solid #96885f;color:#fff;border-radius:4px;font-size:15px;font-family:Montserrat,sans-serif;">';
        echo '<button type="submit" class="button button-primary" style="padding:10px 24px;background:#96885f;color:#1a1a1a;font-weight:700;border:none;border-radius:4px;font-family:Montserrat,sans-serif;text-transform:uppercase;font-size:13px;letter-spacing:1px;cursor:pointer;">Save Username</button>';
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

    /* ─────────────────────────────────────────────
     *  Shipping Date — Checkout Integration
     * ───────────────────────────────────────────── */

    private function fishotel_cart_contains_fish() {
        if ( ! WC()->cart ) return false;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( has_term( [ 'quarantined-fish', 'inverts', 'invoices' ], 'product_cat', $item['product_id'] ) ) {
                return true;
            }
        }
        return false;
    }

    private function fishotel_get_available_shipping_dates() {
        $allowed_days  = get_option( 'fishotel_shipping_days', [ 'Monday', 'Tuesday', 'Wednesday' ] );
        $min_advance   = intval( get_option( 'fishotel_shipping_min_advance', 1 ) );
        $max_ahead     = intval( get_option( 'fishotel_shipping_max_ahead', 30 ) );
        $blacklist     = get_option( 'fishotel_shipping_blacklist', [] );
        $max_per_day   = intval( get_option( 'fishotel_shipping_max_per_day', 6 ) );

        $dates      = [];
        $start_date = new DateTime( 'now', new DateTimeZone( 'America/Chicago' ) );
        $start_date->modify( "+{$min_advance} days" );

        for ( $i = 0; $i < $max_ahead; $i++ ) {
            $check_date  = clone $start_date;
            $check_date->modify( "+{$i} days" );
            $day_name    = $check_date->format( 'l' );
            $date_string = $check_date->format( 'Y-m-d' );

            if ( ! in_array( $day_name, $allowed_days, true ) ) continue;
            if ( in_array( $date_string, $blacklist, true ) ) continue;

            $dates[ $date_string ] = $check_date->format( 'l, F j, Y' );
        }

        // Remove dates that have reached the daily shipment limit.
        if ( $max_per_day > 0 && ! empty( $dates ) ) {
            $date_keys = array_keys( $dates );
            $orders = get_posts( [
                'post_type'   => 'shop_order',
                'post_status' => [ 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ],
                'meta_query'  => [
                    [
                        'key'     => '_fishotel_shipping_date',
                        'value'   => $date_keys,
                        'compare' => 'IN',
                    ],
                ],
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ] );

            $counts = [];
            foreach ( $orders as $order_id ) {
                $ship_date = get_post_meta( $order_id, '_fishotel_shipping_date', true );
                if ( $ship_date ) {
                    $counts[ $ship_date ] = ( $counts[ $ship_date ] ?? 0 ) + 1;
                }
            }

            foreach ( $counts as $date => $count ) {
                if ( $count >= $max_per_day ) {
                    unset( $dates[ $date ] );
                }
            }
        }

        return $dates;
    }

    public function fishotel_shipping_date_field( $checkout ) {
        if ( ! $this->fishotel_cart_contains_fish() ) return;

        $available = $this->fishotel_get_available_shipping_dates();
        if ( empty( $available ) ) {
            echo '<div id="fishotel-shipping-date-field"><p style="color:#e74c3c;font-weight:700;">No shipping dates are currently available. Please contact us before placing your order.</p></div>';
            return;
        }

        echo '<div id="fishotel-shipping-date-field">';
        woocommerce_form_field( 'fishotel_shipping_date', [
            'type'     => 'select',
            'class'    => [ 'form-row-wide' ],
            'label'    => 'Select Shipping Day',
            'required' => true,
            'options'  => array_merge( [ '' => '— Choose a shipping date —' ], $available ),
        ], $checkout->get_value( 'fishotel_shipping_date' ) );
        echo '</div>';
    }

    public function fishotel_shipping_date_validate() {
        if ( ! $this->fishotel_cart_contains_fish() ) return;

        $date = sanitize_text_field( $_POST['fishotel_shipping_date'] ?? '' );
        if ( empty( $date ) ) {
            wc_add_notice( 'Please select a shipping date.', 'error' );
            return;
        }
        $available = $this->fishotel_get_available_shipping_dates();
        if ( ! isset( $available[ $date ] ) ) {
            wc_add_notice( 'The selected shipping date is not available. Please choose a different date.', 'error' );
        }
    }

    /**
     * Secondary validation via woocommerce_after_checkout_validation.
     * Catches orders that bypass the woocommerce_checkout_process hook
     * (e.g. Block-based checkout, off-site payment gateways).
     */
    public function fishotel_shipping_date_validate_backup( $data, $errors ) {
        if ( ! $this->fishotel_cart_contains_fish() ) return;

        $date = sanitize_text_field( $_POST['fishotel_shipping_date'] ?? '' );
        if ( empty( $date ) ) {
            $errors->add( 'shipping_date', 'Please select a shipping date.' );
            return;
        }
        $available = $this->fishotel_get_available_shipping_dates();
        if ( ! isset( $available[ $date ] ) ) {
            $errors->add( 'shipping_date', 'The selected shipping date is not available. Please choose a different date.' );
        }
    }

    public function fishotel_shipping_date_save( $order_id ) {
        $date = sanitize_text_field( $_POST['fishotel_shipping_date'] ?? '' );
        if ( $date ) {
            update_post_meta( $order_id, '_fishotel_shipping_date', $date );
        }
    }

    /**
     * Editable shipping date field on the admin Edit Order screen.
     */
    public function fishotel_shipping_date_display( $order ) {
        $date  = $order->get_meta( '_fishotel_shipping_date' );
        $value = $date ? esc_attr( $date ) : '';
        wp_nonce_field( 'fishotel_save_shipping_date', 'fishotel_shipping_date_nonce' );
        echo '<p class="form-field form-field-wide">';
        echo '<label for="fishotel_shipping_date_admin"><strong>FisHotel Shipping Date:</strong></label><br>';
        echo '<input type="date" id="fishotel_shipping_date_admin" name="fishotel_shipping_date_admin" value="' . $value . '" style="width:100%;max-width:220px;">';
        if ( $date ) {
            echo '<br><span style="color:#999;font-size:12px;">' . esc_html( date( 'l, F j, Y', strtotime( $date ) ) ) . '</span>';
        }
        echo '</p>';
    }

    /**
     * Save admin-edited shipping date from the Edit Order screen.
     */
    public function fishotel_shipping_date_admin_save( $order_id ) {
        if ( ! isset( $_POST['fishotel_shipping_date_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['fishotel_shipping_date_nonce'], 'fishotel_save_shipping_date' ) ) return;
        if ( ! current_user_can( 'edit_shop_orders' ) ) return;

        $date = sanitize_text_field( $_POST['fishotel_shipping_date_admin'] ?? '' );
        if ( $date ) {
            update_post_meta( $order_id, '_fishotel_shipping_date', $date );
        } else {
            delete_post_meta( $order_id, '_fishotel_shipping_date' );
        }
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

}
