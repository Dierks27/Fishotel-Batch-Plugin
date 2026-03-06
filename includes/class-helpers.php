<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait FisHotel_Helpers {

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

}
