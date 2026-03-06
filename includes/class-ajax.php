<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait FisHotel_Ajax {

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

}
