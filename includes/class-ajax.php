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
        if ( ! $request || (int) get_post_meta( $request_id, '_customer_id', true ) !== get_current_user_id() ) {
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

    public function ajax_remove_request_item() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        }

        $request_id = intval( $_POST['request_id'] );
        $batch_id   = intval( $_POST['batch_id'] );

        $request = get_post( $request_id );
        if ( ! $request || (int) get_post_meta( $request_id, '_customer_id', true ) !== $user_id ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
        }

        $items     = json_decode( get_post_meta( $request_id, '_cart_items', true ), true ) ?: [];
        $found_idx = -1;
        foreach ( $items as $i => $it ) {
            if ( intval( $it['batch_id'] ) === $batch_id ) { $found_idx = $i; break; }
        }
        if ( $found_idx === -1 ) {
            wp_send_json_error( [ 'message' => 'Item not found.' ] );
        }

        $item = $items[$found_idx];
        $qty  = intval( $item['qty'] );
        if ( $batch_id ) {
            $current = (float) get_post_meta( $batch_id, '_stock', true );
            update_post_meta( $batch_id, '_stock', $current + $qty );
        }
        array_splice( $items, $found_idx, 1 );

        $deposit_refunded = false;
        if ( empty( $items ) ) {
            $batch_name     = get_post_meta( $request_id, '_batch_name', true );
            $deposit_amount = $this->get_deposit_amount( $batch_name );
            $this->update_user_deposit_balance( $user_id, $deposit_amount );

            $paid = $this->get_paid_deposits( $user_id );
            $key  = sanitize_title( $batch_name );
            if ( isset( $paid[$key] ) ) unset( $paid[$key] );
            update_user_meta( $user_id, '_fishotel_paid_deposits', $paid );

            wp_delete_post( $request_id, true );
            $deposit_refunded = true;
        } else {
            update_post_meta( $request_id, '_cart_items', wp_json_encode( $items ) );
            $new_total = array_reduce( $items, fn( $carry, $it ) => $carry + ( $it['price'] * $it['qty'] ), 0 );
            update_post_meta( $request_id, '_total', $new_total );
        }

        wp_send_json_success( [
            'message'          => 'Fish removed and stock restored.' . ( $deposit_refunded ? ' Deposit refunded to wallet.' : '' ),
            'deposit_refunded' => $deposit_refunded,
        ] );
    }

    public function ajax_remove_from_order() {
        check_ajax_referer( 'fishotel_batch_ajax', 'nonce' );
        $request_id = intval( $_POST['request_id'] );
        $fish_index = intval( $_POST['fish_index'] );

        $request = get_post( $request_id );
        if ( ! $request || (int) get_post_meta( $request_id, '_customer_id', true ) !== get_current_user_id() ) {
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

    public function ajax_save_arrival_field() {
        check_ajax_referer( 'fishotel_arrival_save', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $fish_id    = intval( $_POST['fish_id'] ?? 0 );
        $field      = sanitize_text_field( $_POST['field'] ?? '' );
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );

        $valid_fields = [ 'qty_received', 'qty_doa', 'tank', 'status' ];
        if ( ! in_array( $field, $valid_fields, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid field.' ] );
        }

        $post = get_post( $fish_id );
        if ( ! $post || $post->post_type !== 'fish_batch' ) {
            wp_send_json_error( [ 'message' => 'Invalid fish batch.' ] );
        }
        if ( get_post_meta( $fish_id, '_batch_name', true ) !== $batch_name ) {
            wp_send_json_error( [ 'message' => 'Batch mismatch.' ] );
        }

        if ( in_array( $field, [ 'qty_received', 'qty_doa' ], true ) ) {
            $value = intval( $_POST['value'] ?? 0 );
        } else {
            $value = sanitize_text_field( $_POST['value'] ?? '' );
        }

        update_post_meta( $fish_id, '_arrival_' . $field, $value );
        update_post_meta( $fish_id, '_arrival_updated_at', time() );

        wp_send_json_success( [ 'fish_id' => $fish_id, 'field' => $field, 'value' => $value ] );
    }

    /* ─────────────────────────────────────────────
     *  LAYER DESIGNER AJAX
     * ───────────────────────────────────────────── */

    public function ajax_save_layer_config() {
        check_ajax_referer( 'fishotel_layer_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $scene_type = sanitize_key( $_POST['scene_type'] ?? '' );
        if ( ! $scene_type ) {
            wp_send_json_error( [ 'message' => 'Missing scene type.' ] );
        }

        $raw    = json_decode( wp_unslash( $_POST['layers'] ?? '[]' ), true );
        $layers = [];
        if ( is_array( $raw ) ) {
            foreach ( $raw as $L ) {
                $layers[] = [
                    'id'        => sanitize_key( $L['id'] ?? ( 'layer_' . wp_rand() ) ),
                    'asset'     => sanitize_file_name( $L['asset'] ?? '' ),
                    'label'     => sanitize_text_field( $L['label'] ?? '' ),
                    'x'         => strval( max( 0, min( 100, intval( $L['x'] ?? 0 ) ) ) ),
                    'y'         => strval( max( 0, min( 100, intval( $L['y'] ?? 0 ) ) ) ),
                    'width'     => strval( max( 1, min( 100, intval( $L['width'] ?? 100 ) ) ) ),
                    'blend'     => in_array( $L['blend'] ?? '', [ 'normal','screen','overlay','multiply','soft-light','hard-light','lighten','color-dodge' ], true ) ? $L['blend'] : 'normal',
                    'opacity'   => strval( max( 0, min( 1, floatval( $L['opacity'] ?? 1 ) ) ) ),
                    'animation' => in_array( $L['animation'] ?? '', [ 'none','drift-left-right','drift-up','sway','shimmer','pulse','float' ], true ) ? $L['animation'] : 'none',
                    'speed'     => strval( max( 1, min( 60, intval( $L['speed'] ?? 10 ) ) ) ),
                    'pause'     => strval( max( 0, min( 30, intval( $L['pause'] ?? 0 ) ) ) ),
                    'z'         => strval( max( 1, min( 20, intval( $L['z'] ?? 1 ) ) ) ),
                    'show_on'   => array_values( array_intersect( (array) ( $L['show_on'] ?? [] ), [ 'morning','afternoon','sunset','night','all' ] ) ),
                ];
            }
        }

        $all = get_option( 'fishotel_layer_configs', [] );
        $all[ $scene_type ] = $layers;
        update_option( 'fishotel_layer_configs', $all );

        wp_send_json_success( [ 'message' => 'Saved.' ] );
    }

    public function ajax_upload_layer_asset() {
        check_ajax_referer( 'fishotel_layer_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        if ( empty( $_FILES['layer_asset'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
        }

        $file = $_FILES['layer_asset'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Upload error code ' . $file['error'] ] );
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'png' ) {
            wp_send_json_error( [ 'message' => 'Only PNG files allowed.' ] );
        }

        $dest_dir = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene-layers/';
        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        $safe_name = sanitize_file_name( $file['name'] );
        $dest      = $dest_dir . $safe_name;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( [ 'message' => 'Failed to write file.' ] );
        }

        wp_send_json_success( [ 'filename' => $safe_name ] );
    }

    public function ajax_delete_layer_asset() {
        check_ajax_referer( 'fishotel_layer_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        if ( ! $filename ) {
            wp_send_json_error( [ 'message' => 'Missing filename.' ] );
        }

        $path = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene-layers/' . $filename;
        if ( ! file_exists( $path ) ) {
            wp_send_json_error( [ 'message' => 'File not found.' ] );
        }

        if ( ! unlink( $path ) ) {
            wp_send_json_error( [ 'message' => 'Failed to delete file.' ] );
        }

        wp_send_json_success( [ 'filename' => $filename ] );
    }

    public function ajax_get_layer_assets() {
        check_ajax_referer( 'fishotel_layer_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $dir    = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene-layers/';
        $assets = [];
        if ( is_dir( $dir ) ) {
            foreach ( glob( $dir . '*.png' ) as $f ) {
                $assets[] = basename( $f );
            }
            sort( $assets );
        }

        wp_send_json_success( [ 'assets' => $assets ] );
    }

    /* ─────────────────────────────────────────────
     *  SCENE TYPES AJAX
     * ───────────────────────────────────────────── */

    public function ajax_save_scene_types() {
        check_ajax_referer( 'fishotel_scene_types', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $raw = json_decode( wp_unslash( $_POST['scene_types'] ?? '[]' ), true );
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            wp_send_json_error( [ 'message' => 'Must have at least one scene type.' ] );
        }

        $types = [];
        foreach ( $raw as $t ) {
            $clean = preg_replace( '/[^a-z0-9\-]/', '', strtolower( trim( $t ) ) );
            if ( $clean && ! in_array( $clean, $types, true ) ) {
                $types[] = $clean;
            }
        }

        if ( empty( $types ) ) {
            wp_send_json_error( [ 'message' => 'No valid scene types after sanitization.' ] );
        }

        update_option( 'fishotel_scene_types', $types );
        wp_send_json_success( [ 'scene_types' => $types ] );
    }

    /* ─────────────────────────────────────────────
     *  ASSET LIBRARY AJAX
     * ───────────────────────────────────────────── */

    private function asset_library_folders() {
        $base = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/';
        return [
            'scene-layers'      => $base . 'scene-layers/',
            'scene-backgrounds' => $base . 'scene/',
            'stamps'            => $base . 'stamps/',
        ];
    }

    private function asset_library_folder_to_category() {
        return [
            'scene-layers'      => 'layer',
            'scene-backgrounds' => 'background',
            'stamps'            => 'stamp',
        ];
    }

    private function asset_library_get() {
        return get_option( 'fishotel_asset_library', [ 'assets' => [] ] );
    }

    private function asset_library_save( $library ) {
        update_option( 'fishotel_asset_library', $library );
    }

    /**
     * Scan all asset folders and register any files not already in the library.
     */
    public function ajax_scan_assets() {
        check_ajax_referer( 'fishotel_asset_library', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $library     = $this->asset_library_get();
        $existing    = array_column( $library['assets'], 'filename' );
        $existing_map = [];
        foreach ( $library['assets'] as $a ) {
            $existing_map[ $a['folder'] . '/' . $a['filename'] ] = true;
        }

        $folders  = $this->asset_library_folders();
        $cat_map  = $this->asset_library_folder_to_category();
        $added    = 0;

        foreach ( $folders as $folder_key => $dir_path ) {
            if ( ! is_dir( $dir_path ) ) {
                wp_mkdir_p( $dir_path );
                continue;
            }
            $files = glob( $dir_path . '*.{png,jpg,jpeg,gif,webp}', GLOB_BRACE );
            if ( ! $files ) continue;

            foreach ( $files as $file ) {
                $fname = basename( $file );
                $key   = $folder_key . '/' . $fname;
                if ( isset( $existing_map[ $key ] ) ) continue;

                $info = @getimagesize( $file );
                $library['assets'][] = [
                    'id'          => 'asset_' . wp_rand() . '_' . time(),
                    'filename'    => $fname,
                    'folder'      => $folder_key,
                    'label'       => pathinfo( $fname, PATHINFO_FILENAME ),
                    'category'    => $cat_map[ $folder_key ] ?? 'layer',
                    'scene_types' => [],
                    'time_bands'  => [],
                    'tags'        => [],
                    'uploaded'    => filemtime( $file ),
                    'width'       => $info ? $info[0] : 0,
                    'height'      => $info ? $info[1] : 0,
                    'filesize'    => filesize( $file ),
                ];
                $existing_map[ $key ] = true;
                $added++;
            }
        }

        $this->asset_library_save( $library );
        wp_send_json_success( [ 'added' => $added, 'total' => count( $library['assets'] ) ] );
    }

    /**
     * Return filtered asset list.
     */
    public function ajax_get_assets() {
        check_ajax_referer( 'fishotel_asset_library', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $library  = $this->asset_library_get();
        $assets   = $library['assets'];
        $category = sanitize_key( $_POST['category'] ?? '' );
        $scene    = sanitize_key( $_POST['scene_type'] ?? '' );
        $band     = sanitize_key( $_POST['time_band'] ?? '' );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );

        if ( $category ) {
            $assets = array_filter( $assets, function( $a ) use ( $category ) {
                return $a['category'] === $category;
            });
        }
        if ( $scene ) {
            $assets = array_filter( $assets, function( $a ) use ( $scene ) {
                return empty( $a['scene_types'] ) || in_array( $scene, $a['scene_types'], true );
            });
        }
        if ( $band ) {
            $assets = array_filter( $assets, function( $a ) use ( $band ) {
                return empty( $a['time_bands'] ) || in_array( $band, $a['time_bands'], true );
            });
        }
        if ( $search ) {
            $search_lower = strtolower( $search );
            $assets = array_filter( $assets, function( $a ) use ( $search_lower ) {
                return strpos( strtolower( $a['filename'] ), $search_lower ) !== false
                    || strpos( strtolower( $a['label'] ), $search_lower ) !== false
                    || $this->asset_tags_match( $a, $search_lower );
            });
        }

        // Add URLs for thumbnails
        $folders = $this->asset_library_folders();
        $base_url = plugins_url( 'assists/', FISHOTEL_PLUGIN_FILE );
        $folder_url_map = [
            'scene-layers'      => $base_url . 'scene-layers/',
            'scene-backgrounds' => $base_url . 'scene/',
            'stamps'            => $base_url . 'stamps/',
        ];

        $result = [];
        foreach ( array_values( $assets ) as $a ) {
            $a['url'] = ( $folder_url_map[ $a['folder'] ] ?? '' ) . $a['filename'];
            $result[] = $a;
        }

        wp_send_json_success( [ 'assets' => $result ] );
    }

    private function asset_tags_match( $asset, $search ) {
        if ( ! empty( $asset['tags'] ) ) {
            foreach ( $asset['tags'] as $tag ) {
                if ( strpos( strtolower( $tag ), $search ) !== false ) return true;
            }
        }
        return false;
    }

    /**
     * Upload asset to correct folder and register in library.
     */
    public function ajax_upload_asset() {
        check_ajax_referer( 'fishotel_asset_library', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        if ( empty( $_FILES['asset_file'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
        }

        $file = $_FILES['asset_file'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Upload error code ' . $file['error'] ] );
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $allowed = [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ];
        if ( ! in_array( $ext, $allowed, true ) ) {
            wp_send_json_error( [ 'message' => 'File type not allowed. Use: ' . implode( ', ', $allowed ) ] );
        }

        $folder = sanitize_key( $_POST['folder'] ?? 'scene-layers' );
        $folders = $this->asset_library_folders();
        if ( ! isset( $folders[ $folder ] ) ) {
            wp_send_json_error( [ 'message' => 'Invalid folder.' ] );
        }

        $dest_dir = $folders[ $folder ];
        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        $safe_name = sanitize_file_name( $file['name'] );
        $dest      = $dest_dir . $safe_name;

        // Avoid overwrite — append number if exists
        $counter = 1;
        while ( file_exists( $dest ) ) {
            $name_part = pathinfo( $safe_name, PATHINFO_FILENAME );
            $safe_name = $name_part . '-' . $counter . '.' . $ext;
            $dest      = $dest_dir . $safe_name;
            $counter++;
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( [ 'message' => 'Failed to write file.' ] );
        }

        $info        = @getimagesize( $dest );
        $cat_map     = $this->asset_library_folder_to_category();
        $label       = sanitize_text_field( $_POST['label'] ?? pathinfo( $safe_name, PATHINFO_FILENAME ) );
        $scene_types = array_map( 'sanitize_key', (array) ( $_POST['scene_types'] ?? [] ) );
        $time_bands  = array_map( 'sanitize_key', (array) ( $_POST['time_bands'] ?? [] ) );

        $asset = [
            'id'          => 'asset_' . wp_rand() . '_' . time(),
            'filename'    => $safe_name,
            'folder'      => $folder,
            'label'       => $label,
            'category'    => $cat_map[ $folder ] ?? 'layer',
            'scene_types' => $scene_types,
            'time_bands'  => $time_bands,
            'tags'        => array_map( 'sanitize_text_field', array_filter( explode( ',', $_POST['tags'] ?? '' ) ) ),
            'uploaded'    => time(),
            'width'       => $info ? $info[0] : 0,
            'height'      => $info ? $info[1] : 0,
            'filesize'    => filesize( $dest ),
        ];

        $library = $this->asset_library_get();
        $library['assets'][] = $asset;
        $this->asset_library_save( $library );

        $base_url = plugins_url( 'assists/', FISHOTEL_PLUGIN_FILE );
        $folder_url_map = [
            'scene-layers'      => $base_url . 'scene-layers/',
            'scene-backgrounds' => $base_url . 'scene/',
            'stamps'            => $base_url . 'stamps/',
        ];
        $asset['url'] = ( $folder_url_map[ $folder ] ?? '' ) . $safe_name;

        wp_send_json_success( [ 'asset' => $asset ] );
    }

    /**
     * Update asset metadata.
     */
    public function ajax_save_asset_meta() {
        check_ajax_referer( 'fishotel_asset_library', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $id = sanitize_text_field( $_POST['asset_id'] ?? '' );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Missing asset ID.' ] );
        }

        $library = $this->asset_library_get();
        $found   = false;

        foreach ( $library['assets'] as &$asset ) {
            if ( $asset['id'] !== $id ) continue;
            $found = true;

            if ( isset( $_POST['label'] ) ) {
                $asset['label'] = sanitize_text_field( $_POST['label'] );
            }
            if ( isset( $_POST['category'] ) ) {
                $cat = sanitize_key( $_POST['category'] );
                if ( in_array( $cat, [ 'layer', 'background', 'stamp' ], true ) ) {
                    // Move file if category implies different folder
                    $new_folder = array_search( $cat, $this->asset_library_folder_to_category() );
                    if ( $new_folder && $new_folder !== $asset['folder'] ) {
                        $old_path = $this->asset_library_folders()[ $asset['folder'] ] . $asset['filename'];
                        $new_dir  = $this->asset_library_folders()[ $new_folder ];
                        if ( ! is_dir( $new_dir ) ) wp_mkdir_p( $new_dir );
                        $new_path = $new_dir . $asset['filename'];
                        if ( file_exists( $old_path ) && ! file_exists( $new_path ) ) {
                            rename( $old_path, $new_path );
                            $asset['folder'] = $new_folder;
                        }
                    }
                    $asset['category'] = $cat;
                }
            }
            if ( isset( $_POST['scene_types'] ) ) {
                $asset['scene_types'] = array_map( 'sanitize_key', (array) $_POST['scene_types'] );
            }
            if ( isset( $_POST['time_bands'] ) ) {
                $asset['time_bands'] = array_map( 'sanitize_key', (array) $_POST['time_bands'] );
            }
            if ( isset( $_POST['tags'] ) ) {
                $asset['tags'] = array_map( 'sanitize_text_field', array_filter( explode( ',', $_POST['tags'] ) ) );
            }
            break;
        }
        unset( $asset );

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => 'Asset not found.' ] );
        }

        $this->asset_library_save( $library );
        wp_send_json_success( [ 'message' => 'Saved.' ] );
    }

    /**
     * Delete asset file and remove from library.
     */
    public function ajax_delete_asset() {
        check_ajax_referer( 'fishotel_asset_library', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $id = sanitize_text_field( $_POST['asset_id'] ?? '' );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Missing asset ID.' ] );
        }

        $library = $this->asset_library_get();
        $folders = $this->asset_library_folders();

        // Check if asset is used in any layer config
        $references = [];
        $target     = null;
        foreach ( $library['assets'] as $a ) {
            if ( $a['id'] === $id ) { $target = $a; break; }
        }

        if ( ! $target ) {
            wp_send_json_error( [ 'message' => 'Asset not found.' ] );
        }

        $all_layers = get_option( 'fishotel_layer_configs', [] );
        foreach ( $all_layers as $scene => $layers ) {
            foreach ( $layers as $L ) {
                if ( ( $L['asset'] ?? '' ) === $target['filename'] ) {
                    $references[] = $scene;
                }
            }
        }

        $force = ! empty( $_POST['force'] );
        if ( ! empty( $references ) && ! $force ) {
            wp_send_json_error( [
                'message'    => 'Asset is used in layer config(s): ' . implode( ', ', array_unique( $references ) ) . '. Send force=1 to delete anyway.',
                'references' => array_unique( $references ),
                'confirm'    => true,
            ] );
        }

        // Delete file
        $file_path = ( $folders[ $target['folder'] ] ?? '' ) . $target['filename'];
        if ( file_exists( $file_path ) ) {
            unlink( $file_path );
        }

        // Remove from library
        $library['assets'] = array_values( array_filter( $library['assets'], function( $a ) use ( $id ) {
            return $a['id'] !== $id;
        }));
        $this->asset_library_save( $library );

        wp_send_json_success( [ 'message' => 'Deleted.' ] );
    }

    /**
     * Bulk update scene_types/time_bands for multiple assets.
     */
    public function ajax_bulk_update_assets() {
        check_ajax_referer( 'fishotel_asset_library', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $ids         = array_map( 'sanitize_text_field', (array) ( $_POST['asset_ids'] ?? [] ) );
        $action_type = sanitize_key( $_POST['bulk_action'] ?? '' );

        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => 'No assets selected.' ] );
        }

        $library = $this->asset_library_get();

        if ( $action_type === 'delete' ) {
            $folders = $this->asset_library_folders();
            foreach ( $library['assets'] as $a ) {
                if ( in_array( $a['id'], $ids, true ) ) {
                    $path = ( $folders[ $a['folder'] ] ?? '' ) . $a['filename'];
                    if ( file_exists( $path ) ) unlink( $path );
                }
            }
            $library['assets'] = array_values( array_filter( $library['assets'], function( $a ) use ( $ids ) {
                return ! in_array( $a['id'], $ids, true );
            }));
        } elseif ( $action_type === 'set_scene_types' ) {
            $scene_types = array_map( 'sanitize_key', (array) ( $_POST['scene_types'] ?? [] ) );
            foreach ( $library['assets'] as &$a ) {
                if ( in_array( $a['id'], $ids, true ) ) {
                    $a['scene_types'] = $scene_types;
                }
            }
            unset( $a );
        } elseif ( $action_type === 'set_time_bands' ) {
            $time_bands = array_map( 'sanitize_key', (array) ( $_POST['time_bands'] ?? [] ) );
            foreach ( $library['assets'] as &$a ) {
                if ( in_array( $a['id'], $ids, true ) ) {
                    $a['time_bands'] = $time_bands;
                }
            }
            unset( $a );
        } elseif ( $action_type === 'move_folder' ) {
            $new_folder = sanitize_key( $_POST['folder'] ?? '' );
            $folders    = $this->asset_library_folders();
            $cat_map    = $this->asset_library_folder_to_category();
            if ( ! isset( $folders[ $new_folder ] ) ) {
                wp_send_json_error( [ 'message' => 'Invalid folder.' ] );
            }
            $new_dir = $folders[ $new_folder ];
            if ( ! is_dir( $new_dir ) ) wp_mkdir_p( $new_dir );

            foreach ( $library['assets'] as &$a ) {
                if ( ! in_array( $a['id'], $ids, true ) ) continue;
                if ( $a['folder'] === $new_folder ) continue;
                $old_path = ( $folders[ $a['folder'] ] ?? '' ) . $a['filename'];
                $new_path = $new_dir . $a['filename'];
                if ( file_exists( $old_path ) && ! file_exists( $new_path ) ) {
                    rename( $old_path, $new_path );
                    $a['folder']   = $new_folder;
                    $a['category'] = $cat_map[ $new_folder ] ?? $a['category'];
                }
            }
            unset( $a );
        }

        $this->asset_library_save( $library );
        wp_send_json_success( [ 'message' => 'Bulk action completed.', 'total' => count( $library['assets'] ) ] );
    }

    /* ─────────────────────────────────────────────
     *  Verification AJAX stubs (Stage 5)
     * ───────────────────────────────────────────── */

    public function ajax_verification_accept() {
        check_ajax_referer( 'fishotel_verification_nonce', 'nonce' );
        // TODO: queue mutation implemented in Stage 5 Prompt 3
        wp_send_json_success();
    }

    public function ajax_verification_pass() {
        check_ajax_referer( 'fishotel_verification_nonce', 'nonce' );
        // TODO: queue mutation implemented in Stage 5 Prompt 3
        wp_send_json_success();
    }

}
