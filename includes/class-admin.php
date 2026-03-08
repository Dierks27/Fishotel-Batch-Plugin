<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait FisHotel_Admin {

    public function init() {
        $this->register_post_types();
        add_rewrite_endpoint( 'wallet', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'my-requests', EP_ROOT | EP_PAGES );
        if ( ! get_option( 'fishotel_rewrite_flushed_198' ) ) {
            flush_rewrite_rules( false );
            update_option( 'fishotel_rewrite_flushed_198', true );
        }
    }

    public function register_post_types() {
        register_post_type( 'fish_master', [ 'labels' => [ 'name' => 'Master Fish Library', 'singular_name' => 'Master Fish' ], 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => [ 'title' ] ] );
        register_post_type( 'fish_batch', [ 'labels' => [ 'name' => 'Batch Fish', 'singular_name' => 'Batch Fish' ], 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => [ 'title' ] ] );
        register_post_type( 'fish_request', [ 'labels' => [ 'name' => 'Batch Requests', 'singular_name' => 'Batch Request' ], 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => [ 'title' ] ] );
    }

    public function add_admin_menu() {
        $fish_icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmgiIHZpZXdCb3g9IjAgMCAxMDAgNjAiPjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik02NSAzMEMxMCAzMCAwIDU1IDAgNTVzMzAtMTUgMzUtMzBDMTAgMjUgMCAxMCAwIDVjMCAwIDMwIDIwIDM1IDE1IDAtMTAgMjAtMjAgNDAtMjAgMjAgMCAzNSAxNSAzNSAzMCAwIDE1LTE1IDMwLTM1IDMwLTIwIDAtNDAtMTAtNDAtMjB6Ii8+PGVsbGlwc2UgZmlsbD0iIzIzMjgzMyIgY3g9Ijc4IiBjeT0iMjciIHJ4PSIzIiByeT0iMyIvPjwvc3ZnPg==';
        add_menu_page( 'FisHotel Batch Manager', 'FisHotel Batch', 'manage_options', 'fishotel-batch-settings', [$this, 'batch_settings_html'], $fish_icon, 56 );
        add_submenu_page( 'fishotel-batch-settings', 'Master Fish Library', 'Master Fish Library', 'manage_options', 'edit.php?post_type=fish_master' );
        add_submenu_page( 'fishotel-batch-settings', 'Batch Requests', 'Batch Requests', 'manage_options', 'fishotel-batch-orders', [$this, 'batch_orders_html'] );
        add_submenu_page( 'fishotel-batch-settings', 'Customer Wallets', 'Customer Wallets', 'manage_options', 'fishotel-wallets', [$this, 'wallets_html'] );
        add_submenu_page( 'fishotel-batch-settings', 'Order Summary', 'Order Summary', 'manage_options', 'fishotel-order-summary', [$this, 'order_summary_html'] );
        add_submenu_page( 'fishotel-batch-settings', 'Sync Quarantined Fish', 'Sync Quarantined Fish', 'manage_options', 'fishotel-sync', [$this, 'sync_page_html'] );
        add_submenu_page( 'fishotel-batch-settings', 'Arrival Entry', 'Arrival Entry', 'manage_options', 'fishotel-arrival-entry', [$this, 'arrival_entry_html'] );
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
        if ( isset( $_GET['fishotel_update_checked'] ) ) {
            $v = sanitize_text_field( $_GET['fishotel_update_checked'] );
            echo $v === 'error'
                ? '<div class="notice notice-error is-dismissible"><p>❌ Could not reach GitHub to check for updates.</p></div>'
                : '<div class="notice notice-info is-dismissible"><p>🔄 GitHub version: <strong>' . esc_html( $v ) . '</strong> — Installed: <strong>' . FISHOTEL_VERSION . '</strong></p></div>';
        }

        $price_import_result = get_transient( 'fishotel_price_import_result_' . get_current_user_id() );
        if ( $price_import_result ) {
            delete_transient( 'fishotel_price_import_result_' . get_current_user_id() );
            $updated  = intval( $price_import_result['updated'] );
            $notfound = $price_import_result['not_found'];
            echo '<div class="notice notice-info is-dismissible" style="padding:16px 20px;">';
            echo '<p style="font-size:15px;margin:0 0 6px;"><strong>Import Master Prices — Results</strong></p>';
            echo '<p style="margin:4px 0;">✅ <strong>' . $updated . '</strong> fish price' . ( $updated !== 1 ? 's' : '' ) . ' updated</p>';
            echo '<p style="margin:4px 0;">❌ <strong>' . count( $notfound ) . '</strong> scientific name' . ( count( $notfound ) !== 1 ? 's' : '' ) . ' not found in Master Library</p>';
            if ( ! empty( $notfound ) ) {
                echo '<ul style="margin:10px 0 0 20px;color:#c00;">';
                foreach ( $notfound as $name ) {
                    echo '<li>' . esc_html( $name ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        $batches_str = get_option( 'fishotel_batches', '' );
        $batches_array = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );
        $current = get_option( 'fishotel_current_batch', '' );
        $assignments = get_option( 'fishotel_batch_page_assignments', [] );
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $batch_deposit_amounts = get_option( 'fishotel_batch_deposit_amounts', [] );
        $admin_test_mode = get_option( 'fishotel_admin_test_mode', 0 );
        $deposit_product_id = $this->get_deposit_product_id();
        $arrival_dates = get_option( 'fishotel_batch_arrival_dates', [] );
        $closed_dates  = get_option( 'fishotel_batch_closed_dates', [] );
        $origin_locations = $this->get_origin_locations();
        $batch_origins = get_option( 'fishotel_batch_origins', [] );

        if ( isset( $_POST['fishotel_save_all'] ) && check_admin_referer( 'fishotel_save_all_nonce' ) ) {
            update_option( 'fishotel_deposit_product_id', intval( $_POST['deposit_product_id'] ?? 31985 ) );
            update_option( 'fishotel_current_batch', sanitize_text_field( $_POST['fishotel_current_batch'] ?? '' ) );
            update_option( 'fishotel_admin_test_mode', isset( $_POST['admin_test_mode'] ) ? 1 : 0 );

            $new_assignments = [];
            $new_statuses = [];
            $new_deposit_amounts = [];
            $new_arrival_dates = [];
            $new_closed_dates  = [];
            $new_origins = [];
            foreach ( $batches_array as $batch ) {
                $key = sanitize_key( $batch );
                $title_key = sanitize_title( $batch );
                if ( isset( $_POST['assign_' . $key] ) ) $new_assignments[$batch] = sanitize_text_field( $_POST['assign_' . $key] );
                if ( isset( $_POST['status_' . $key] ) ) {
                $stage = sanitize_key( $_POST['status_' . $key] );
                if ( array_key_exists( $stage, $this->get_valid_stages() ) ) {
                    $new_statuses[ $batch ] = $stage;
                }
            }
                if ( isset( $_POST['deposit_amount_' . $key] ) && (float) $_POST['deposit_amount_' . $key] > 0 ) {
                    $new_deposit_amounts[$title_key] = floatval( $_POST['deposit_amount_' . $key] );
                }
                if ( isset( $_POST['arrival_date_' . $key] ) ) {
                    $date = sanitize_text_field( $_POST['arrival_date_' . $key] );
                    if ( $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                        $new_arrival_dates[ $batch ] = $date;
                    }
                }
                if ( isset( $_POST['closed_date_' . $key] ) ) {
                    $date = sanitize_text_field( $_POST['closed_date_' . $key] );
                    if ( $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                        $new_closed_dates[ $batch ] = $date;
                    }
                }
                if ( isset( $_POST['origin_' . $key] ) ) {
                    $origin = sanitize_text_field( $_POST['origin_' . $key] );
                    if ( $origin !== '' ) $new_origins[ $batch ] = $origin;
                }
            }
            update_option( 'fishotel_batch_page_assignments', $new_assignments );
            update_option( 'fishotel_batch_statuses', $new_statuses );
            update_option( 'fishotel_batch_deposit_amounts', $new_deposit_amounts );
            update_option( 'fishotel_batch_arrival_dates', $new_arrival_dates );
            update_option( 'fishotel_batch_closed_dates', $new_closed_dates );
            update_option( 'fishotel_batch_origins', $new_origins );

            wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
            exit;
        }

        $pages = get_pages( [ 'sort_column' => 'post_title' ] );
        $stage_options = $this->get_valid_stages();
        ?>
        <div class="wrap fishotel-admin">
            <h1>FisHotel Batch Manager</h1>
            <p class="page-description">Complete backend control for fishotel.com batch system &nbsp;·&nbsp; v<?php echo FISHOTEL_VERSION; ?> &nbsp;·&nbsp; <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-batch-settings&fishotel_force_update_check=1' ) ); ?>" style="color:#b5a165;">🔄 Check for updates</a></p>

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
            <style>
            .fh-icon-btn {
                position:relative; display:inline-flex; align-items:center; justify-content:center;
                width:32px; height:32px; border-radius:5px; border:1px solid #555;
                background:#2a2a2a; color:#b5a165; font-size:17px; cursor:pointer;
                text-decoration:none; line-height:1; padding:0; margin:0 2px;
                box-sizing:border-box; vertical-align:middle;
                transition:background .15s,border-color .15s;
            }
            .fh-icon-btn:hover { background:#3a3a3a; border-color:#888; color:#d4bc7e; }
            .fh-icon-btn.fh-red { background:#7b1a10; border-color:#c0392b; color:#fff; }
            .fh-icon-btn.fh-red:hover { background:#c0392b; border-color:#e74c3c; }
            .fh-icon-btn::after {
                content:attr(data-tip);
                position:absolute; bottom:calc(100% + 7px); left:50%; transform:translateX(-50%);
                background:#1a1a1a; color:#b5a165; border:1px solid #555; border-radius:4px;
                padding:3px 9px; font-size:11px; white-space:nowrap;
                pointer-events:none; opacity:0; transition:opacity .12s; z-index:200;
            }
            .fh-icon-btn:hover::after { opacity:1; }
            </style>
            <form method="post" action="" id="fishotel-save-all-form" style="margin-top:24px;">
                <?php wp_nonce_field( 'fishotel_save_all_nonce' ); ?>
                <input type="hidden" name="fishotel_save_all" value="1">

                <table class="widefat" style="border-radius:8px;overflow:hidden;">
                    <thead>
                        <tr>
                            <th>Batch Name</th>
                            <th>Origin</th>
                            <th>Current Stage</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $batches_array as $batch ) :
                            $key        = sanitize_key( $batch );
                            $title_key  = sanitize_title( $batch );
                            $current_page   = $assignments[$batch] ?? '';
                            $current_status = $statuses[$batch] ?? 'open_ordering';
                            $batch_deposit  = $batch_deposit_amounts[$title_key] ?? '';
                            $arrival_date   = $arrival_dates[$batch] ?? '';
                            $closed_date    = $closed_dates[$batch] ?? '';
                            $current_origin = $batch_origins[$batch] ?? '';
                            $view_url  = $current_page ? home_url( '/' . $current_page ) : '';
                            $embed_url = $current_page ? home_url( '/' . $current_page . '?embed=1' ) : '';
                        ?>
                        <tr>
                            <td>
                                <strong style="color:#b5a165;cursor:pointer;" onclick="fhToggleDetail('<?php echo $key; ?>')">
                                    <span id="fh-chev-<?php echo $key; ?>" style="display:inline-block;transition:transform .2s;font-size:11px;margin-right:4px;">&#9654;</span><?php echo esc_html( $batch ); ?>
                                </strong>
                            </td>
                            <td>
                                <select name="origin_<?php echo $key; ?>" style="width:100%;">
                                    <option value="">— Select —</option>
                                    <?php foreach ( $origin_locations as $loc ) : ?>
                                        <option value="<?php echo esc_attr( $loc['name'] ); ?>" <?php selected( $current_origin, $loc['name'] ); ?>><?php echo esc_html( $loc['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="status_<?php echo $key; ?>" style="width:100%;">
                                    <?php foreach ( $stage_options as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="white-space:nowrap;padding:6px 10px;">
                                <?php if ( $view_url ) : ?>
                                    <a href="<?php echo esc_url( $view_url ); ?>" target="_blank"
                                       class="fh-icon-btn" data-tip="View">👁</a>
                                    <button type="button" class="fh-icon-btn" data-tip="Copy Link"
                                            onclick="fhCopyLink(this,'<?php echo esc_js( $embed_url ); ?>')">📋</button>
                                <?php endif; ?>
                                <?php
                                $del_nonce  = wp_create_nonce( 'fishotel_delete_batch_' . sanitize_key( $batch ) );
                                $delete_url = admin_url( 'admin-post.php?action=fishotel_delete_batch&batch_name=' . rawurlencode( $batch ) . '&_wpnonce=' . $del_nonce );
                                ?>
                                <a href="<?php echo esc_url( $delete_url ); ?>"
                                   class="fh-icon-btn fh-red" data-tip="Delete"
                                   onclick="return confirm('Delete batch <?php echo esc_js( $batch ); ?>? This cannot be undone.')">🗑</a>
                            </td>
                        </tr>
                        <tr id="fh-detail-<?php echo $key; ?>" style="display:none;">
                            <td colspan="4" style="padding:12px 16px;background:#1a1a1a;border-top:1px dashed #444;">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;max-width:600px;">
                                    <div>
                                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Public Page</label>
                                        <select name="assign_<?php echo $key; ?>" style="width:100%;padding:5px 8px;border-radius:4px;">
                                            <option value="">— Not assigned —</option>
                                            <?php foreach ( $pages as $page ) : ?>
                                                <option value="<?php echo esc_attr( $page->post_name ); ?>" <?php selected( $current_page, $page->post_name ); ?>><?php echo esc_html( $page->post_title ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Deposit Amount</label>
                                        <input type="number" step="0.01" min="0" name="deposit_amount_<?php echo $key; ?>" value="<?php echo esc_attr( $batch_deposit ); ?>" placeholder="e.g. 25.00" style="width:90px;padding:5px 8px;border-radius:4px;">
                                    </div>
                                    <div>
                                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Closed Date</label>
                                        <input type="date" name="closed_date_<?php echo $key; ?>" value="<?php echo esc_attr( $closed_date ); ?>" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:5px 8px;border-radius:4px;width:140px;">
                                    </div>
                                    <div>
                                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Arrival Date</label>
                                        <input type="date" name="arrival_date_<?php echo $key; ?>" value="<?php echo esc_attr( $arrival_date ); ?>" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:5px 8px;border-radius:4px;width:140px;">
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Save All Settings -->
                <p style="text-align:center;margin:28px 0 12px 0;">
                    <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:8px;padding:16px 60px;font-size:18px;cursor:pointer;">💾 Save All Settings</button>
                </p>

            </form>

                <!-- ===== ZONE 3: Advanced Settings ===== -->
                <div style="margin-top:8px;padding-bottom:40px;text-align:center;">
                    <button type="button" id="fishotel-advanced-toggle" style="background:none;border:none;color:#aaa;font-size:0.85em;cursor:pointer;text-decoration:underline;padding:6px 0;">⚙️ Advanced Settings ▾</button>
                    <div id="fishotel-advanced-body" style="display:none;margin-top:12px;background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:20px;text-align:left;">
                        <table class="form-table">
                            <tr>
                                <th style="color:#ddd;">Wallet Deposit Product ID</th>
                                <td>
                                    <input type="number" name="deposit_product_id" form="fishotel-save-all-form" value="<?php echo esc_attr( $deposit_product_id ); ?>" style="width:120px;">
                                    <small style="display:block;margin-top:5px;color:#aaa;">(Your product #31985 — change only if you recreate it)</small>
                                </td>
                            </tr>
                            <tr>
                                <th style="color:#ddd;">Admin Test Mode</th>
                                <td><label style="color:#ddd;"><input type="checkbox" name="admin_test_mode" form="fishotel-save-all-form" <?php checked( $admin_test_mode, 1 ); ?>> Bypass deposit check for admins</label></td>
                            </tr>
                            <tr>
                                <th style="color:#ddd;vertical-align:top;padding-top:14px;">💲 Import Master Prices</th>
                                <td>
                                    <form method="post" enctype="multipart/form-data" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                                        <?php wp_nonce_field( 'fishotel_import_prices_nonce' ); ?>
                                        <input type="hidden" name="action" value="fishotel_import_prices">
                                        <p style="color:#aaa;margin:0 0 10px;font-size:13px;">CSV columns: <code style="background:#333;padding:2px 5px;border-radius:3px;">SCIENTIFIC NAME</code> &amp; <code style="background:#333;padding:2px 5px;border-radius:3px;">PRICE</code>. Updates <code style="background:#333;padding:2px 5px;border-radius:3px;">_selling_price</code> on matching fish_master posts.</p>
                                        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                                            <input type="file" name="prices_csv" accept=".csv" style="color:#ddd;">
                                            <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:8px 20px;cursor:pointer;font-size:13px;">Upload &amp; Apply Prices</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>


            <!-- ===== ZONE 3b: Ticker Messages ===== -->
            <div style="margin-top:8px;text-align:center;">
                <button type="button" id="fishotel-ticker-toggle" style="background:none;border:none;color:#aaa;font-size:0.85em;cursor:pointer;text-decoration:underline;padding:6px 0;">📟 Ticker Messages ▾</button>
                <div id="fishotel-ticker-body" style="display:none;margin-top:12px;background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;text-align:left;">
                    <p style="color:#aaa;font-size:13px;margin:0 0 16px;">Messages rotate on the split-flap board on the live fish list. Use <code style="background:#333;padding:2px 5px;border-radius:3px;">{species}</code> and <code style="background:#333;padding:2px 5px;border-radius:3px;">{stock}</code> as tokens for live counts. Keep messages under 40 characters.</p>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" id="fishotel-ticker-form">
                        <?php wp_nonce_field( 'fishotel_save_ticker_nonce' ); ?>
                        <input type="hidden" name="action" value="fishotel_save_ticker">
                        <div id="fishotel-ticker-list">
                            <?php
                            $ticker_msgs = get_option( 'fishotel_ticker_messages', [] );
                            if ( empty( $ticker_msgs ) ) {
                                $ticker_msgs = [
                                    'FIRST COME · FIRST SERVED',
                                    '{species} SPECIES AVAILABLE',
                                    '{stock} TOTAL STOCK',
                                    'DEPOSIT REQUIRED TO REQUEST',
                                ];
                            }
                            foreach ( $ticker_msgs as $idx => $msg ) : ?>
                            <div class="fh-ticker-row" style="display:flex;gap:10px;align-items:center;margin-bottom:8px;">
                                <input type="text" name="ticker_msg[]" value="<?php echo esc_attr( $msg ); ?>" maxlength="40" style="flex:1;background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:6px;font-size:14px;font-family:'Courier New',monospace;">
                                <button type="button" onclick="this.parentNode.remove()" style="background:#c0392b;color:#fff;border:none;border-radius:4px;padding:6px 14px;cursor:pointer;font-size:13px;font-weight:700;">Remove</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
                            <button type="button" id="fishotel-ticker-add" style="background:#333;color:#b5a165;border:1px solid #555;border-radius:6px;padding:8px 18px;cursor:pointer;font-size:13px;font-weight:700;">+ Add Message</button>
                            <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:8px 22px;cursor:pointer;font-size:13px;">Save Ticker Messages</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ===== ZONE 4: Origin Locations ===== -->
            <div style="margin-top:8px;text-align:center;">
                <button type="button" id="fishotel-origins-toggle" style="background:none;border:none;color:#aaa;font-size:0.85em;cursor:pointer;text-decoration:underline;padding:6px 0;">🌍 Origin Locations ▾</button>
            <div id="fishotel-origins-body" style="display:none;margin-top:12px;background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;text-align:left;">
                <p style="color:#aaa;font-size:13px;margin:0 0 16px;">Library of origin cities used by the transit-page plane animation. The batch name is scanned for any word matching a location name (case-insensitive) to auto-detect origin.</p>
                <table class="widefat" style="border-radius:8px;overflow:hidden;margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th>Location Name</th>
                            <th style="width:140px;">Latitude</th>
                            <th style="width:140px;">Longitude</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $origin_locations as $i => $loc ) : ?>
                        <tr>
                            <td><strong style="color:#b5a165;"><?php echo esc_html( $loc['name'] ); ?></strong></td>
                            <td style="color:#ccc;"><?php echo esc_html( $loc['lat'] ); ?></td>
                            <td style="color:#ccc;"><?php echo esc_html( $loc['lng'] ); ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'fishotel_delete_location_nonce' ); ?>
                                    <input type="hidden" name="action" value="fishotel_delete_location">
                                    <input type="hidden" name="location_index" value="<?php echo $i; ?>">
                                    <button type="submit" onclick="return confirm('Delete <?php echo esc_js( $loc['name'] ); ?>?')"
                                        style="background:#c0392b;color:#fff;border:none;border-radius:4px;padding:4px 12px;cursor:pointer;font-size:14px;font-weight:700;">×</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ( empty( $origin_locations ) ) : ?>
                        <tr><td colspan="4" style="color:#888;font-style:italic;">No locations saved yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                    <?php wp_nonce_field( 'fishotel_add_location_nonce' ); ?>
                    <input type="hidden" name="action" value="fishotel_add_location">
                    <div>
                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Location Name</label>
                        <input type="text" name="loc_name" placeholder="e.g. Fiji" required
                            style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:6px;width:180px;">
                    </div>
                    <div>
                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Latitude</label>
                        <input type="number" step="0.0001" name="loc_lat" placeholder="-17.7134" required
                            style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:6px;width:120px;">
                    </div>
                    <div>
                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Longitude</label>
                        <input type="number" step="0.0001" name="loc_lng" placeholder="178.0650" required
                            style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:6px;width:120px;">
                    </div>
                    <button type="submit"
                        style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:9px 22px;cursor:pointer;font-size:14px;">Add Location</button>
                </form>
            </div>
            </div>

        </div>

        <script>
        function fhToggleDetail(key) {
            var row = document.getElementById('fh-detail-' + key);
            var chev = document.getElementById('fh-chev-' + key);
            if (row.style.display === 'none') {
                row.style.display = 'table-row';
                chev.style.transform = 'rotate(90deg)';
            } else {
                row.style.display = 'none';
                chev.style.transform = '';
            }
        }
        function fhCopyLink(btn, url) {
            navigator.clipboard.writeText(url).then(function() {
                var orig = btn.getAttribute('data-tip');
                var origText = btn.textContent;
                btn.setAttribute('data-tip', '✅ Copied!');
                btn.textContent = '✅';
                setTimeout(function() {
                    btn.setAttribute('data-tip', orig);
                    btn.textContent = origText;
                }, 2000);
            });
        }
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
        document.getElementById('fishotel-ticker-toggle').addEventListener('click', function() {
            var body = document.getElementById('fishotel-ticker-body');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                this.textContent = '📟 Ticker Messages ▴';
            } else {
                body.style.display = 'none';
                this.textContent = '📟 Ticker Messages ▾';
            }
        });
        document.getElementById('fishotel-ticker-add').addEventListener('click', function() {
            var list = document.getElementById('fishotel-ticker-list');
            var row = document.createElement('div');
            row.className = 'fh-ticker-row';
            row.style.cssText = 'display:flex;gap:10px;align-items:center;margin-bottom:8px;';
            row.innerHTML = '<input type="text" name="ticker_msg[]" value="" maxlength="40" placeholder="NEW MESSAGE HERE" style="flex:1;background:#2a2a2a;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:6px;font-size:14px;font-family:\'Courier New\',monospace;">' +
                '<button type="button" onclick="this.parentNode.remove()" style="background:#c0392b;color:#fff;border:none;border-radius:4px;padding:6px 14px;cursor:pointer;font-size:13px;font-weight:700;">Remove</button>';
            list.appendChild(row);
            row.querySelector('input').focus();
        });
        document.getElementById('fishotel-origins-toggle').addEventListener('click', function() {
            var body = document.getElementById('fishotel-origins-body');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                this.textContent = '🌍 Origin Locations ▴';
            } else {
                body.style.display = 'none';
                this.textContent = '🌍 Origin Locations ▾';
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
            <p class="page-description">Full control over every user's deposit wallet. Changes are logged permanently.</p>
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

    public function order_summary_html() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

        $batches_str   = get_option( 'fishotel_batches', '' );
        $batches_array = array_values( array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) ) );
        $selected      = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : ( $batches_array[0] ?? '' );

        echo '<div class="wrap">';
        echo '<h1>📋 Order Summary</h1>';
        echo '<p style="color:#aaa;">Compiled view of all fish requested for a batch — use this to build your importer order.</p>';

        // Batch selector
        echo '<form method="get" style="margin-bottom:20px;">';
        echo '<input type="hidden" name="page" value="fishotel-order-summary">';
        echo '<label style="font-weight:700;margin-right:10px;">Batch:</label>';
        echo '<select name="batch" onchange="this.form.submit()" style="min-width:220px;padding:6px 10px;border-radius:4px;">';
        foreach ( $batches_array as $b ) {
            echo '<option value="' . esc_attr( $b ) . '"' . selected( $selected, $b, false ) . '>' . esc_html( $b ) . '</option>';
        }
        echo '</select>';
        echo '</form>';

        if ( empty( $selected ) ) {
            echo '<p>No batches found. Add one in the <a href="' . admin_url( 'admin.php?page=fishotel-batch-settings' ) . '">Batch Settings</a>.</p></div>';
            return;
        }

        // Load all requests for this batch
        $requests = get_posts( [
            'post_type'      => 'fish_request',
            'numberposts'    => -1,
            'post_status'    => 'any',
            'meta_key'       => '_batch_name',
            'meta_value'     => $selected,
        ] );

        if ( empty( $requests ) ) {
            echo '<p style="color:#aaa;">No requests found for <strong>' . esc_html( $selected ) . '</strong>.</p></div>';
            return;
        }

        // Aggregate: fish_name → [ sci_name, total_qty, customers[ display_name => qty ] ]
        $species   = [];
        $sci_cache = []; // batch_id → scientific_name

        foreach ( $requests as $req ) {
            $customer_id   = (int) get_post_meta( $req->ID, '_customer_id', true );
            $customer_data = get_userdata( $customer_id );
            $customer_name = get_post_meta( $req->ID, '_customer_name', true ) ?: ( $customer_data ? ( $customer_data->display_name ?: $customer_data->user_login ) : 'User #' . $customer_id );

            $items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
            foreach ( $items as $item ) {
                $fish_name = $item['fish_name'] ?? 'Unknown';
                $qty       = intval( $item['qty'] ?? 1 );
                $batch_id  = intval( $item['batch_id'] ?? 0 );

                // Resolve scientific name (cached per batch_id)
                if ( $batch_id && ! isset( $sci_cache[ $batch_id ] ) ) {
                    $master_id             = get_post_meta( $batch_id, '_master_id', true );
                    $sci_cache[ $batch_id ] = $master_id ? (string) get_post_meta( $master_id, '_scientific_name', true ) : '';
                }
                $sci_name = $batch_id ? ( $sci_cache[ $batch_id ] ?? '' ) : '';

                if ( ! isset( $species[ $fish_name ] ) ) {
                    $species[ $fish_name ] = [ 'sci' => $sci_name, 'total' => 0, 'customers' => [] ];
                }
                $species[ $fish_name ]['total'] += $qty;
                $species[ $fish_name ]['customers'][ $customer_name ] = ( $species[ $fish_name ]['customers'][ $customer_name ] ?? 0 ) + $qty;
            }
        }

        // Default sort: scientific name A-Z
        uasort( $species, fn( $a, $b ) => strcasecmp( $a['sci'] ?: $a['fish_name'] ?? '', $b['sci'] ?: '' ) );

        $total_fish = array_sum( array_column( $species, 'total' ) );
        $num_species = count( $species );
        $export_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=fishotel_export_order_excel&batch=' . urlencode( $selected ) ),
            'fishotel_export_excel'
        );
        ?>

        <p style="margin-bottom:16px;">
            <a href="<?php echo esc_url( $export_url ); ?>"
               style="display:inline-block;background:#27ae60;color:#fff;font-weight:700;padding:9px 22px;border-radius:6px;text-decoration:none;font-size:14px;border:1px solid #1e8449;">
                📥 Export Order to Excel
            </a>
        </p>

        <div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:20px;margin-bottom:16px;display:flex;gap:40px;flex-wrap:wrap;">
            <div><span style="color:#aaa;font-size:13px;">BATCH</span><br><strong style="color:#b5a165;font-size:1.2em;"><?php echo esc_html( $selected ); ?></strong></div>
            <div><span style="color:#aaa;font-size:13px;">SPECIES</span><br><strong style="color:#fff;font-size:1.2em;"><?php echo $num_species; ?></strong></div>
            <div><span style="color:#aaa;font-size:13px;">TOTAL FISH QTY</span><br><strong style="color:#e67e22;font-size:1.2em;"><?php echo $total_fish; ?></strong></div>
            <div><span style="color:#aaa;font-size:13px;">REQUESTS</span><br><strong style="color:#fff;font-size:1.2em;"><?php echo count( $requests ); ?></strong></div>
        </div>

        <style>
            #fishotel-summary-table { width:100%; border-collapse:collapse; background:#1e1e1e; color:#fff; font-size:0.93em; }
            #fishotel-summary-table thead { position:sticky; top:32px; z-index:5; background:#111; }
            #fishotel-summary-table th { padding:10px 14px; text-align:left; color:#b5a165; border-bottom:2px solid #444; cursor:pointer; user-select:none; white-space:nowrap; }
            #fishotel-summary-table th:hover { background:#1a1a1a; }
            #fishotel-summary-table th.sort-asc::after  { content:" ▲"; font-size:0.75em; }
            #fishotel-summary-table th.sort-desc::after { content:" ▼"; font-size:0.75em; }
            #fishotel-summary-table td { padding:9px 14px; border-bottom:1px solid #2a2a2a; vertical-align:top; }
            #fishotel-summary-table tr:hover td { background:#252525; }
            #fishotel-summary-table .sci { font-style:italic; color:#aaa; }
            #fishotel-summary-table .qty-badge { background:#e67e22; color:#000; font-weight:700; border-radius:12px; padding:2px 10px; display:inline-block; }
            #fishotel-summary-table .customer-list { color:#ccc; font-size:0.9em; }
            #fishotel-summary-table .customer-list span { display:inline-block; background:#2a2a2a; border:1px solid #444; border-radius:4px; padding:2px 7px; margin:2px 3px 2px 0; }
        </style>

        <div style="overflow-x:auto;border-radius:8px;border:1px solid #444;">
            <table id="fishotel-summary-table">
                <thead>
                    <tr>
                        <th data-col="0">Common Name</th>
                        <th data-col="1">Scientific Name</th>
                        <th data-col="2" style="text-align:center;">Total Qty</th>
                        <th data-col="3">Who Wants How Many</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $species as $fish_name => $data ) :
                        $breakdown = [];
                        arsort( $data['customers'] );
                        foreach ( $data['customers'] as $name => $qty ) {
                            $breakdown[] = esc_html( $name ) . ' (' . $qty . ')';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $fish_name ); ?></strong></td>
                        <td class="sci"><?php echo esc_html( $data['sci'] ); ?></td>
                        <td style="text-align:center;"><span class="qty-badge"><?php echo intval( $data['total'] ); ?></span></td>
                        <td class="customer-list"><?php
                            foreach ( $data['customers'] as $cname => $cqty ) {
                                echo '<span>' . esc_html( $cname ) . ' <strong style="color:#e67e22;">×' . intval( $cqty ) . '</strong></span>';
                            }
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var table  = document.getElementById('fishotel-summary-table');
            var tbody  = table.querySelector('tbody');
            var sortCol = 1, sortDir = 1; // default: sci name asc

            function getVal(row, col) {
                var cell = row.cells[col];
                if ( col === 2 ) return parseInt(cell.textContent.trim()) || 0;
                return cell.textContent.trim().toLowerCase();
            }

            function sortTable(col) {
                if ( sortCol === col ) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }
                var rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function(a, b) {
                    var av = getVal(a, sortCol), bv = getVal(b, sortCol);
                    if ( typeof av === 'number' ) return sortDir * (av - bv);
                    return sortDir * av.localeCompare(bv);
                });
                rows.forEach(function(r) { tbody.appendChild(r); });
                table.querySelectorAll('th').forEach(function(th, i) {
                    th.classList.remove('sort-asc','sort-desc');
                    if ( i === sortCol ) th.classList.add( sortDir === 1 ? 'sort-asc' : 'sort-desc' );
                });
            }

            table.querySelectorAll('th[data-col]').forEach(function(th) {
                th.addEventListener('click', function() { sortTable(parseInt(this.dataset.col)); });
            });

            // Apply default sort indicator
            table.querySelectorAll('th')[1].classList.add('sort-asc');
        })();
        </script>
        <?php

        // ===== Add My Order section =====
        if ( isset( $_GET['admin_order'] ) ) {
            echo '<div class="notice notice-success is-dismissible" style="margin:16px 0;"><p>✅ Your order has been saved and stock reserved.</p></div>';
        }

        $batch_fish = get_posts( [
            'post_type'      => 'fish_batch',
            'numberposts'    => -1,
            'post_status'    => 'any',
            'meta_key'       => '_batch_name',
            'meta_value'     => $selected,
        ] );

        usort( $batch_fish, function( $a, $b ) {
            $ma  = get_post_meta( $a->ID, '_master_id', true );
            $mb  = get_post_meta( $b->ID, '_master_id', true );
            $sca = $ma ? (string) get_post_meta( $ma, '_scientific_name', true ) : '';
            $scb = $mb ? (string) get_post_meta( $mb, '_scientific_name', true ) : '';
            return strcasecmp( $sca, $scb );
        } );

        ?>
        <div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-top:28px;">
            <h2 style="color:#e67e22;margin-top:0;font-size:1.2em;">🛒 Add My Order</h2>
            <p style="color:#aaa;margin-top:0;font-size:13px;">Enter quantities for fish you want to include in your own importer order. Saves as an admin request alongside customer orders.</p>

            <?php if ( empty( $batch_fish ) ) : ?>
                <p style="color:#aaa;">No fish found in this batch.</p>
            <?php else : ?>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <?php wp_nonce_field( 'fishotel_admin_order_nonce' ); ?>
                <input type="hidden" name="action" value="fishotel_admin_order">
                <input type="hidden" name="batch_name" value="<?php echo esc_attr( $selected ); ?>">

                <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                    <thead>
                        <tr style="background:#111;color:#b5a165;">
                            <th style="padding:9px 12px;text-align:left;border-bottom:2px solid #444;">Fish Name</th>
                            <th style="padding:9px 12px;text-align:left;border-bottom:2px solid #444;font-style:italic;">Scientific Name</th>
                            <th style="padding:9px 12px;text-align:right;border-bottom:2px solid #444;">Price</th>
                            <th style="padding:9px 12px;text-align:center;border-bottom:2px solid #444;">Stock</th>
                            <th style="padding:9px 12px;text-align:center;border-bottom:2px solid #444;width:100px;">My Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $batch_fish as $bp ) :
                            $master_id = get_post_meta( $bp->ID, '_master_id', true );
                            if ( ! $master_id ) continue;
                            $master   = get_post( $master_id );
                            if ( ! $master ) continue;
                            $sci_name = get_post_meta( $master_id, '_scientific_name', true );
                            $price    = floatval( get_post_meta( $master_id, '_selling_price', true ) );
                            $stock    = intval( get_post_meta( $bp->ID, '_stock', true ) );
                        ?>
                        <tr style="border-bottom:1px solid #2a2a2a;<?php echo $stock === 0 ? 'opacity:0.45;' : ''; ?>">
                            <td style="padding:8px 12px;color:#fff;font-weight:600;"><?php echo esc_html( $master->post_title ); ?></td>
                            <td style="padding:8px 12px;color:#aaa;font-style:italic;"><?php echo esc_html( $sci_name ); ?></td>
                            <td style="padding:8px 12px;color:#e67e22;text-align:right;">$<?php echo number_format( $price, 2 ); ?></td>
                            <td style="padding:8px 12px;text-align:center;color:<?php echo $stock > 0 ? '#27ae60' : '#e74c3c'; ?>;font-weight:700;"><?php echo $stock; ?></td>
                            <td style="padding:8px 12px;text-align:center;">
                                <input type="number" name="items[<?php echo $bp->ID; ?>]"
                                    value="0" min="0" max="<?php echo $stock; ?>"
                                    <?php echo $stock === 0 ? 'disabled' : ''; ?>
                                    style="width:70px;padding:5px;text-align:center;background:#2a2a2a;color:#fff;border:1px solid #555;border-radius:4px;">
                                <input type="hidden" name="prices[<?php echo $bp->ID; ?>]" value="<?php echo esc_attr( $price ); ?>">
                                <input type="hidden" name="names[<?php echo $bp->ID; ?>]" value="<?php echo esc_attr( $master->post_title ); ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">💾 Save My Order</button>
            </form>
            <?php endif; ?>
        </div>
        <?php

        echo '</div>';
    }

    public function handle_admin_order() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_admin_order_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $raw_items  = $_POST['items']  ?? [];
        $raw_prices = $_POST['prices'] ?? [];
        $raw_names  = $_POST['names']  ?? [];

        if ( ! $batch_name ) wp_die( 'No batch specified.' );

        $cart_items  = [];
        $total       = 0.0;
        $requested_at = current_time( 'mysql' );

        foreach ( $raw_items as $batch_id => $qty ) {
            $batch_id = intval( $batch_id );
            $qty      = intval( $qty );
            if ( $qty <= 0 || ! $batch_id ) continue;

            $price     = floatval( $raw_prices[ $batch_id ] ?? 0 );
            $fish_name = sanitize_text_field( $raw_names[ $batch_id ] ?? '' );
            $stock     = intval( get_post_meta( $batch_id, '_stock', true ) );
            $qty       = min( $qty, $stock ); // cap at available stock
            if ( $qty <= 0 ) continue;

            $cart_items[] = [
                'batch_id'     => $batch_id,
                'fish_name'    => $fish_name,
                'qty'          => $qty,
                'price'        => $price,
                'requested_at' => $requested_at,
            ];
            $total += $price * $qty;

            // Deduct stock
            update_post_meta( $batch_id, '_stock', max( 0, $stock - $qty ) );
        }

        if ( empty( $cart_items ) ) {
            wp_redirect( admin_url( 'admin.php?page=fishotel-order-summary&batch=' . urlencode( $batch_name ) ) );
            exit;
        }

        $request_id = wp_insert_post( [
            'post_type'   => 'fish_request',
            'post_title'  => 'Admin Order #' . time() . ' - ' . $batch_name,
            'post_status' => 'publish',
        ] );

        if ( $request_id ) {
            update_post_meta( $request_id, '_customer_id',    get_current_user_id() );
            update_post_meta( $request_id, '_batch_name',     $batch_name );
            update_post_meta( $request_id, '_cart_items',     wp_json_encode( $cart_items ) );
            update_post_meta( $request_id, '_total',          $total );
            update_post_meta( $request_id, '_status',         'provisional' );
            update_post_meta( $request_id, '_deposit_verified', 1 );
            update_post_meta( $request_id, '_is_admin_order', true );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-order-summary&batch=' . urlencode( $batch_name ) . '&admin_order=1' ) );
        exit;
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
            $custom_name = get_post_meta( $req->ID, '_customer_name', true );
            $key = ( $custom_name ?: $customer_id ) . '|' . sanitize_title( $batch_name );
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
                            $customer_name = get_post_meta( $req->ID, '_customer_name', true ) ?: ( $customer ? $customer->display_name : 'Guest' );
                            $humble_username = get_post_meta( $req->ID, '_hf_username', true ) ?: ( $customer ? get_user_meta( $customer_id, '_fishotel_humble_username', true ) : '' );
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
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline-block;margin-right:12px;">
                        <input type="hidden" name="action" value="fishotel_reset_test_data">
                        <?php wp_nonce_field( 'fishotel_reset_test_data' ); ?>
                        <button type="submit" style="background:#e74c3c;color:#fff;font-weight:700;padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px;" onclick="return confirm('RESET ALL wallet balances, deposit flags, and fish requests for every user? This cannot be undone.');">🔄 Reset Test Data</button>
                    </form>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="fishotel_create_test_requests">
                        <?php wp_nonce_field( 'fishotel_create_test_requests' ); ?>
                        <button type="submit" style="background:#8e44ad;color:#fff;font-weight:700;padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px;" onclick="return confirm('Create 3 fake test requests for the active transit batch?');">🧪 Create Test Requests</button>
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
        wp_nonce_field( 'fish_request_meta_nonce', 'fish_request_meta_nonce' );

        $batch_name     = get_post_meta( $post->ID, '_batch_name', true );
        $customer_id    = get_post_meta( $post->ID, '_customer_id', true );
        $hf_username    = $customer_id ? get_user_meta( $customer_id, '_fishotel_humble_username', true ) : '';
        $deposit_paid   = (bool) get_post_meta( $post->ID, '_deposit_paid', true );
        $is_admin_order = (bool) get_post_meta( $post->ID, '_is_admin_order', true );
        $items_raw      = get_post_meta( $post->ID, '_cart_items', true );
        $items          = $items_raw ? json_decode( $items_raw, true ) : [];
        if ( ! is_array( $items ) ) $items = [];

        $batches_str   = get_option( 'fishotel_batches', '' );
        $batches_array = array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) );

        $all_batch_fish = get_posts( [ 'post_type' => 'fish_batch', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
        $batch_fish_options = [];
        foreach ( $all_batch_fish as $bf ) {
            $bf_batch = get_post_meta( $bf->ID, '_batch_name', true );
            $batch_fish_options[] = [ 'id' => $bf->ID, 'title' => $bf->post_title, 'batch' => $bf_batch ];
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="fhr_batch_name">Batch</label></th>
                <td>
                    <select name="fhr_batch_name" id="fhr_batch_name" style="width:300px;">
                        <option value="">— Select Batch —</option>
                        <?php foreach ( $batches_array as $b ) : ?>
                            <option value="<?php echo esc_attr( $b ); ?>" <?php selected( $batch_name, $b ); ?>><?php echo esc_html( $b ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="fhr_customer_id">Customer</label></th>
                <td>
                    <?php
                    $customer = $customer_id ? get_user_by( 'id', $customer_id ) : null;
                    $customer_label = $customer ? $customer->display_name . ' (#' . $customer_id . ')' : '';
                    ?>
                    <input type="number" name="fhr_customer_id" id="fhr_customer_id" value="<?php echo esc_attr( $customer_id ); ?>" style="width:120px;">
                    <span class="description"><?php echo esc_html( $customer_label ); ?></span>
                </td>
            </tr>
            <tr>
                <th><label for="fhr_hf_username">HF Username</label></th>
                <td><input type="text" name="fhr_hf_username" id="fhr_hf_username" value="<?php echo esc_attr( $hf_username ); ?>" style="width:300px;"></td>
            </tr>
            <tr>
                <th><label for="fhr_deposit_paid">Deposit Paid</label></th>
                <td><input type="checkbox" name="fhr_deposit_paid" id="fhr_deposit_paid" value="1" <?php checked( $deposit_paid ); ?>></td>
            </tr>
            <tr>
                <th><label for="fhr_is_admin_order">Is Admin Order</label></th>
                <td><input type="checkbox" name="fhr_is_admin_order" id="fhr_is_admin_order" value="1" <?php checked( $is_admin_order ); ?>></td>
            </tr>
        </table>

        <h4 style="margin-top:20px;">Cart Items</h4>
        <table class="widefat fixed striped" id="fhr-cart-items-table">
            <thead><tr><th style="width:40%;">Batch Fish</th><th style="width:15%;">Qty</th><th style="width:20%;">Price</th><th style="width:15%;">Line Total</th><th style="width:10%;"></th></tr></thead>
            <tbody>
                <?php if ( ! empty( $items ) ) : foreach ( $items as $i => $item ) : ?>
                    <tr>
                        <td>
                            <select name="fhr_cart[<?php echo $i; ?>][batch_id]" style="width:100%;">
                                <option value="">— Select —</option>
                                <?php foreach ( $batch_fish_options as $opt ) : ?>
                                    <option value="<?php echo $opt['id']; ?>" <?php selected( $item['batch_id'] ?? '', $opt['id'] ); ?>><?php echo esc_html( $opt['title'] . ' (' . $opt['batch'] . ')' ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" name="fhr_cart[<?php echo $i; ?>][qty]" value="<?php echo esc_attr( $item['qty'] ?? 1 ); ?>" min="1" step="1" style="width:80px;"></td>
                        <td><input type="number" name="fhr_cart[<?php echo $i; ?>][price]" value="<?php echo esc_attr( $item['price'] ?? 0 ); ?>" min="0" step="0.01" style="width:100px;"></td>
                        <td class="fhr-line-total">$<?php echo number_format( ( $item['price'] ?? 0 ) * ( $item['qty'] ?? 0 ), 2 ); ?></td>
                        <td><button type="button" class="button button-small fhr-remove-row" title="Remove">✕</button></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="fhr-add-row">+ Add Row</button></p>

        <script>
        jQuery(document).ready(function($) {
            var batchFishOptions = <?php echo wp_json_encode( $batch_fish_options ); ?>;
            var rowIndex = <?php echo count( $items ); ?>;

            $('#fhr-add-row').on('click', function() {
                var opts = '<option value="">— Select —</option>';
                $.each(batchFishOptions, function(_, o) {
                    opts += '<option value="' + o.id + '">' + $('<span>').text(o.title + ' (' + o.batch + ')').html() + '</option>';
                });
                var row = '<tr>'
                    + '<td><select name="fhr_cart[' + rowIndex + '][batch_id]" style="width:100%;">' + opts + '</select></td>'
                    + '<td><input type="number" name="fhr_cart[' + rowIndex + '][qty]" value="1" min="1" step="1" style="width:80px;"></td>'
                    + '<td><input type="number" name="fhr_cart[' + rowIndex + '][price]" value="0" min="0" step="0.01" style="width:100px;"></td>'
                    + '<td class="fhr-line-total">$0.00</td>'
                    + '<td><button type="button" class="button button-small fhr-remove-row" title="Remove">✕</button></td>'
                    + '</tr>';
                $('#fhr-cart-items-table tbody').append(row);
                rowIndex++;
            });

            $(document).on('click', '.fhr-remove-row', function() {
                $(this).closest('tr').remove();
            });

            $(document).on('input', '#fhr-cart-items-table input[type="number"]', function() {
                var $row = $(this).closest('tr');
                var qty = parseFloat($row.find('input[name*="[qty]"]').val()) || 0;
                var price = parseFloat($row.find('input[name*="[price]"]').val()) || 0;
                $row.find('.fhr-line-total').text('$' + (qty * price).toFixed(2));
            });
        });
        </script>
        <?php
    }

    public function save_fish_request_meta( $post_id ) {
        if ( ! isset( $_POST['fish_request_meta_nonce'] ) || ! wp_verify_nonce( $_POST['fish_request_meta_nonce'], 'fish_request_meta_nonce' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        update_post_meta( $post_id, '_batch_name', sanitize_text_field( $_POST['fhr_batch_name'] ?? '' ) );

        $customer_id = intval( $_POST['fhr_customer_id'] ?? 0 );
        update_post_meta( $post_id, '_customer_id', $customer_id );

        if ( $customer_id && ! empty( $_POST['fhr_hf_username'] ) ) {
            update_user_meta( $customer_id, '_fishotel_humble_username', sanitize_text_field( $_POST['fhr_hf_username'] ) );
        }

        update_post_meta( $post_id, '_deposit_paid', ! empty( $_POST['fhr_deposit_paid'] ) ? 1 : 0 );
        update_post_meta( $post_id, '_is_admin_order', ! empty( $_POST['fhr_is_admin_order'] ) ? 1 : 0 );

        $cart_items = [];
        if ( ! empty( $_POST['fhr_cart'] ) && is_array( $_POST['fhr_cart'] ) ) {
            foreach ( $_POST['fhr_cart'] as $row ) {
                $batch_id = intval( $row['batch_id'] ?? 0 );
                if ( ! $batch_id ) continue;
                $fish_post = get_post( $batch_id );
                $cart_items[] = [
                    'batch_id'     => $batch_id,
                    'fish_name'    => $fish_post ? preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $fish_post->post_title ) : 'Unknown',
                    'qty'          => max( 1, intval( $row['qty'] ?? 1 ) ),
                    'price'        => floatval( $row['price'] ?? 0 ),
                    'requested_at' => current_time( 'mysql' ),
                ];
            }
        }
        update_post_meta( $post_id, '_cart_items', wp_json_encode( $cart_items ) );

        $total = 0;
        foreach ( $cart_items as $ci ) $total += $ci['price'] * $ci['qty'];
        update_post_meta( $post_id, '_total', $total );
    }

    public function enqueue_batch_orders_scripts( $hook ) {
        $fishotel_pages = [
            'fishotel-batch-orders',
            'fishotel-batch-settings',
            'fishotel-wallets',
            'fishotel-sync',
            'fishotel-arrival-entry',
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

    public function handle_price_import() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_import_prices_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! isset( $_FILES['prices_csv'] ) || $_FILES['prices_csv']['error'] !== UPLOAD_ERR_OK ) {
            wp_die( 'No file uploaded or upload error.' );
        }

        $file = $_FILES['prices_csv'];
        if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'csv' ) {
            wp_die( 'Please upload a .csv file.' );
        }

        $rows = [];
        if ( ( $handle = fopen( $file['tmp_name'], 'r' ) ) !== false ) {
            while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
                $rows[] = array_map( 'trim', $data );
            }
            fclose( $handle );
        }

        if ( empty( $rows ) ) {
            wp_die( 'CSV file is empty.' );
        }

        // Detect header row and column indices.
        $header    = array_map( 'strtoupper', $rows[0] );
        $sci_col   = array_search( 'SCIENTIFIC NAME', $header, true );
        $price_col = array_search( 'PRICE', $header, true );

        if ( $sci_col === false || $price_col === false ) {
            wp_die( 'CSV must contain "SCIENTIFIC NAME" and "PRICE" column headers.' );
        }

        // Pre-load all fish_master scientific names (lowercased) → post ID map.
        $masters = get_posts( [
            'post_type'      => 'fish_master',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ] );

        $sci_to_id = [];
        foreach ( $masters as $id ) {
            $sci = get_post_meta( $id, '_scientific_name', true );
            if ( $sci !== '' && $sci !== false ) {
                $sci_to_id[ strtolower( trim( $sci ) ) ] = $id;
            }
        }

        $updated   = 0;
        $not_found = [];

        foreach ( array_slice( $rows, 1 ) as $row ) {
            $sci_name = isset( $row[ $sci_col ] ) ? trim( $row[ $sci_col ] ) : '';
            $price    = isset( $row[ $price_col ] ) ? $row[ $price_col ] : '';

            if ( $sci_name === '' ) {
                continue;
            }

            $key = strtolower( $sci_name );

            if ( isset( $sci_to_id[ $key ] ) ) {
                update_post_meta( $sci_to_id[ $key ], '_selling_price', floatval( $price ) );
                $updated++;
            } else {
                $not_found[] = $sci_name;
            }
        }

        set_transient(
            'fishotel_price_import_result_' . get_current_user_id(),
            [ 'updated' => $updated, 'not_found' => $not_found ],
            60
        );

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings' ) );
        exit;
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

    /**
     * Canonical list of valid stage slugs and their display labels.
     * Used as the whitelist for the batch-settings save handler and the dropdown.
     */
    private function get_valid_stages(): array {
        return [
            'open_ordering'  => 'Open Ordering',
            'orders_closed'  => 'Orders Closed',
            'arrived'        => 'Arrived',
            'graduation'     => 'Graduation',
            'verification'   => 'Verification',
            'draft'          => 'Draft',
            'invoicing'      => 'Invoicing',
        ];
    }

    /**
     * Maps each stage to the action that advances it to the next stage.
     * To add a new stage transition, append an entry here — no other changes needed.
     */
    private function get_stage_actions() {
        return [
            'open_ordering' => [
                'next_stage' => 'arrived',
                'label'      => '🔒 Close Ordering',
                'style'      => 'background:#c0392b;color:#fff;border-color:#a93226;',
                'confirm'    => "Close ordering for '%s'? This immediately sets the stage to Arrived.",
            ],
            // Future transitions — uncomment and expand as stages are built:
            // 'arrived' => [
            //     'next_stage' => 'next_stage_key',
            //     'label'      => '🚀 Advance Stage',
            //     'style'      => 'background:#2980b9;color:#fff;border-color:#1a6a9a;',
            //     'confirm'    => "Advance '%s' to the next stage?",
            // ],
        ];
    }

    public function advance_stage_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_advance_stage_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $next_stage = sanitize_key( $_POST['next_stage'] ?? '' );
        if ( ! $batch_name || ! $next_stage ) {
            wp_die( 'Invalid parameters.' );
        }
        // Validate next_stage is a whitelisted transition target.
        $valid_next = array_column( $this->get_stage_actions(), 'next_stage' );
        if ( ! in_array( $next_stage, $valid_next, true ) ) {
            wp_die( 'Invalid stage transition.' );
        }
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $statuses[ $batch_name ] = $next_stage;
        update_option( 'fishotel_batch_statuses', $statuses );

        // Record the date when ordering closes (used for transit progress calculation).
        if ( $next_stage === 'orders_closed' ) {
            $closed_dates = get_option( 'fishotel_batch_closed_dates', [] );
            if ( empty( $closed_dates[ $batch_name ] ) ) {
                $closed_dates[ $batch_name ] = current_time( 'Y-m-d' );
                update_option( 'fishotel_batch_closed_dates', $closed_dates );
            }
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
        exit;
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

    public function delete_batch() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $batch_name = sanitize_text_field( wp_unslash( $_REQUEST['batch_name'] ?? '' ) );
        if ( ! $batch_name ) wp_die( 'No batch specified.' );
        if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ?? '' ), 'fishotel_delete_batch_' . sanitize_key( $batch_name ) ) ) {
            wp_die( 'Security check failed.' );
        }

        // Remove from the batches list.
        $batches_str   = get_option( 'fishotel_batches', '' );
        $batches_array = array_values( array_filter( array_map( 'trim', explode( "\n", $batches_str ) ), fn( $b ) => $b !== $batch_name ) );
        update_option( 'fishotel_batches', implode( "\n", $batches_array ) );

        // Remove from all per-batch option arrays.
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        unset( $statuses[ $batch_name ] );
        update_option( 'fishotel_batch_statuses', $statuses );

        $closed_dates = get_option( 'fishotel_batch_closed_dates', [] );
        unset( $closed_dates[ $batch_name ] );
        update_option( 'fishotel_batch_closed_dates', $closed_dates );

        $assignments = get_option( 'fishotel_batch_page_assignments', [] );
        unset( $assignments[ $batch_name ] );
        update_option( 'fishotel_batch_page_assignments', $assignments );

        $deposit_amounts = get_option( 'fishotel_batch_deposit_amounts', [] );
        unset( $deposit_amounts[ sanitize_title( $batch_name ) ] );
        update_option( 'fishotel_batch_deposit_amounts', $deposit_amounts );

        // Delete all fish_batch posts for this batch (NOT fish_requests or wallet data).
        $batch_post_ids = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
            'meta_key'    => '_batch_name',
            'meta_value'  => $batch_name,
        ] );
        foreach ( $batch_post_ids as $pid ) {
            wp_delete_post( $pid, true );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
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

    // =========================================================================
    // Stage 2 Step 4 — Excel Order Export
    // =========================================================================

    public function export_order_excel() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'fishotel_export_excel' ) ) {
            wp_die( 'Security check failed.' );
        }

        $batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
        if ( ! $batch ) {
            wp_die( 'No batch specified.' );
        }

        $requests = get_posts( [
            'post_type'   => 'fish_request',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_key'    => '_batch_name',
            'meta_value'  => $batch,
        ] );

        // Aggregate by batch_id: separate customer qty vs padding qty.
        $rows      = []; // batch_id => row array
        $sci_cache = [];

        foreach ( $requests as $req ) {
            $is_admin = (bool) get_post_meta( $req->ID, '_is_admin_order', true );
            $items    = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];

            foreach ( $items as $item ) {
                $batch_id  = intval( $item['batch_id'] ?? 0 );
                $fish_name = (string) ( $item['fish_name'] ?? 'Unknown' );
                $qty       = intval( $item['qty'] ?? 1 );
                $price     = floatval( $item['price'] ?? 0 );

                if ( ! $batch_id ) continue;

                if ( ! isset( $rows[ $batch_id ] ) ) {
                    $item_code = (string) get_post_meta( $batch_id, '_item_code', true );

                    if ( ! isset( $sci_cache[ $batch_id ] ) ) {
                        $master_id             = get_post_meta( $batch_id, '_master_id', true );
                        $sci_cache[ $batch_id ] = $master_id ? (string) get_post_meta( (int) $master_id, '_scientific_name', true ) : '';
                    }

                    $rows[ $batch_id ] = [
                        'item_code' => $item_code,
                        'fish_name' => $fish_name,
                        'sci_name'  => $sci_cache[ $batch_id ],
                        'price'     => $price,
                        'cust_qty'  => 0,
                        'pad_qty'   => 0,
                    ];
                }

                if ( $is_admin ) {
                    $rows[ $batch_id ]['pad_qty'] += $qty;
                } else {
                    $rows[ $batch_id ]['cust_qty'] += $qty;
                }
                if ( $rows[ $batch_id ]['price'] == 0 && $price > 0 ) {
                    $rows[ $batch_id ]['price'] = $price;
                }
            }
        }

        // Sort alphabetically by common name.
        uasort( $rows, fn( $a, $b ) => strcasecmp( $a['fish_name'], $b['fish_name'] ) );

        $xlsx     = $this->build_order_xlsx( $batch, array_values( $rows ) );
        $filename = 'order-' . sanitize_title( $batch ) . '-' . gmdate( 'Y-m-d' ) . '.xlsx';

        // Send download headers — nothing must be echoed before this point.
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
        header( 'Content-Length: ' . strlen( $xlsx ) );
        header( 'Cache-Control: max-age=0' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $xlsx;
        exit;
    }

    /**
     * Build a real .xlsx binary using ZipArchive + hand-crafted OOXML.
     * No Composer/external dependencies — pure PHP.
     *
     * Columns: Item Code | Common Name | Scientific Name | Unit Price |
     *          Customer Qty | Padding (Dierks) | TOTAL QTY (=E+F)
     *
     * @param string $batch_name Batch label used in the title row.
     * @param array  $rows       Array of row data arrays (already sorted).
     * @return string Binary XLSX content.
     */
    private function build_order_xlsx( string $batch_name, array $rows ): string {

        // ---- Shared string table ----------------------------------------
        $ss     = [];
        $ss_map = [];
        $add_ss = function ( string $val ) use ( &$ss, &$ss_map ): int {
            if ( ! isset( $ss_map[ $val ] ) ) {
                $ss_map[ $val ] = count( $ss );
                $ss[]           = $val;
            }
            return $ss_map[ $val ];
        };

        $title_str = $batch_name . ' — ' . gmdate( 'F j, Y' );
        $headers   = [ 'Item Code', 'Common Name', 'Scientific Name', 'Unit Price', 'Customer Qty', 'Padding (Dierks)', 'TOTAL QTY' ];

        $add_ss( $title_str );
        $add_ss( 'TOTALS' );
        foreach ( $headers as $h ) $add_ss( $h );
        foreach ( $rows as $row ) {
            $add_ss( (string) $row['item_code'] );
            $add_ss( (string) $row['fish_name'] );
            $add_ss( (string) $row['sci_name'] );
        }

        // ---- Sheet data -------------------------------------------------
        $data_start = 3;  // row 1 = title, row 2 = headers
        $num_rows   = count( $rows );
        $totals_row = $data_start + $num_rows;
        $cols       = [ 'A', 'B', 'C', 'D', 'E', 'F', 'G' ];
        $sheet_rows = '';

        // Row 1: merged title (A1:G1), style 2 (bold-large-centred)
        $sheet_rows .= '<row r="1"><c r="A1" t="s" s="2"><v>' . $add_ss( $title_str ) . '</v></c></row>';

        // Row 2: bold headers, style 1
        $sheet_rows .= '<row r="2">';
        foreach ( $headers as $hi => $h ) {
            $sheet_rows .= '<c r="' . $cols[ $hi ] . '2" t="s" s="1"><v>' . $add_ss( $h ) . '</v></c>';
        }
        $sheet_rows .= '</row>';

        // Data rows (style indices: 0=default, 3=currency)
        foreach ( $rows as $ri => $row ) {
            $r           = $ri + $data_start;
            $sheet_rows .= '<row r="' . $r . '">'
                . '<c r="A' . $r . '" t="s" s="0"><v>' . $add_ss( (string) $row['item_code'] ) . '</v></c>'
                . '<c r="B' . $r . '" t="s" s="0"><v>' . $add_ss( (string) $row['fish_name'] ) . '</v></c>'
                . '<c r="C' . $r . '" t="s" s="0"><v>' . $add_ss( (string) $row['sci_name'] ) . '</v></c>'
                . '<c r="D' . $r . '" t="n" s="3"><v>' . esc_attr( (string) $row['price'] ) . '</v></c>'
                . '<c r="E' . $r . '" t="n" s="0"><v>' . intval( $row['cust_qty'] ) . '</v></c>'
                . '<c r="F' . $r . '" t="n" s="0"><v>' . intval( $row['pad_qty'] ) . '</v></c>'
                . '<c r="G' . $r . '" t="n" s="0"><f>E' . $r . '+F' . $r . '</f></c>'
                . '</row>';
        }

        // TOTALS row: style 1=bold for qty cols, 4=bold-currency for price
        $dr = $data_start;
        $lr = $totals_row - 1;
        $sheet_rows .= '<row r="' . $totals_row . '">'
            . '<c r="A' . $totals_row . '" t="s" s="1"><v>' . $add_ss( 'TOTALS' ) . '</v></c>'
            . '<c r="D' . $totals_row . '" t="n" s="4"><f>SUM(D' . $dr . ':D' . $lr . ')</f></c>'
            . '<c r="E' . $totals_row . '" t="n" s="1"><f>SUM(E' . $dr . ':E' . $lr . ')</f></c>'
            . '<c r="F' . $totals_row . '" t="n" s="1"><f>SUM(F' . $dr . ':F' . $lr . ')</f></c>'
            . '<c r="G' . $totals_row . '" t="n" s="1"><f>SUM(G' . $dr . ':G' . $lr . ')</f></c>'
            . '</row>';

        // ---- Build XML strings ------------------------------------------

        $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols>'
            . '<col min="1" max="1" width="14" customWidth="1"/>'
            . '<col min="2" max="2" width="26" customWidth="1"/>'
            . '<col min="3" max="3" width="30" customWidth="1"/>'
            . '<col min="4" max="4" width="13" customWidth="1"/>'
            . '<col min="5" max="5" width="15" customWidth="1"/>'
            . '<col min="6" max="6" width="18" customWidth="1"/>'
            . '<col min="7" max="7" width="15" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . $sheet_rows . '</sheetData>'
            . '<mergeCells count="1"><mergeCell ref="A1:G1"/></mergeCells>'
            . '</worksheet>';

        // Shared strings XML
        $ss_items = '';
        foreach ( $ss as $s ) {
            $ss_items .= '<si><t xml:space="preserve">' . htmlspecialchars( $s, ENT_XML1, 'UTF-8' ) . '</t></si>';
        }
        $ss_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . count( $ss ) . '" uniqueCount="' . count( $ss ) . '">'
            . $ss_items . '</sst>';

        // Styles XML
        // Fonts: 0=default, 1=bold-11, 2=bold-13(title)
        // Fills: 0=none (required), 1=gray125 (required)
        // CellXfs: 0=default, 1=bold, 2=title(bold-13 centred), 3=currency, 4=bold-currency
        $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1">'
            . '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="13"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        // Package-level XML
        $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Order" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        // ---- Assemble ZIP -----------------------------------------------
        $tmp = wp_tempnam( 'fishotel_xlsx_' );
        $zip = new ZipArchive();
        if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
            wp_delete_file( $tmp );
            wp_die( 'Could not create XLSX: ZipArchive failed to open temp file.' );
        }

        $zip->addFromString( '[Content_Types].xml',          $content_types );
        $zip->addFromString( '_rels/.rels',                  $rels );
        $zip->addFromString( 'xl/workbook.xml',              $workbook );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels',   $workbook_rels );
        $zip->addFromString( 'xl/styles.xml',                $styles_xml );
        $zip->addFromString( 'xl/sharedStrings.xml',         $ss_xml );
        $zip->addFromString( 'xl/worksheets/sheet1.xml',     $sheet_xml );
        $zip->close();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = file_get_contents( $tmp );
        wp_delete_file( $tmp );

        return $data ?: '';
    }

    // ─── Origin Locations ────────────────────────────────────────────────────

    /**
     * Returns stored origin locations, seeding defaults on first install.
     */
    public function get_origin_locations(): array {
        $stored = get_option( 'fishotel_origin_locations', null );
        if ( $stored !== null ) {
            return is_array( $stored ) ? $stored : [];
        }
        $defaults = [
            [ 'name' => 'Fiji',             'lat' => -17.7134, 'lng' => 178.0650 ],
            [ 'name' => 'Bali',             'lat' =>  -8.3405, 'lng' => 115.0920 ],
            [ 'name' => 'Red Sea',          'lat' =>  27.2579, 'lng' =>  33.8116 ],
            [ 'name' => 'Philippines',      'lat' =>  12.8797, 'lng' => 121.7740 ],
            [ 'name' => 'Australia',        'lat' => -25.2744, 'lng' => 133.7751 ],
            [ 'name' => 'Marshall Islands', 'lat' =>   7.1315, 'lng' => 171.1845 ],
            [ 'name' => 'Sri Lanka',        'lat' =>   7.8731, 'lng' =>  80.7718 ],
        ];
        update_option( 'fishotel_origin_locations', $defaults );
        return $defaults;
    }

    public function save_ticker_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_save_ticker_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $msgs = [];
        if ( isset( $_POST['ticker_msg'] ) && is_array( $_POST['ticker_msg'] ) ) {
            foreach ( $_POST['ticker_msg'] as $msg ) {
                $msg = sanitize_text_field( $msg );
                if ( $msg !== '' ) {
                    $msgs[] = substr( $msg, 0, 40 );
                }
            }
        }
        update_option( 'fishotel_ticker_messages', $msgs );
        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
        exit;
    }

    public function add_location_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_add_location_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $name = sanitize_text_field( $_POST['loc_name'] ?? '' );
        $lat  = floatval( $_POST['loc_lat'] ?? 0 );
        $lng  = floatval( $_POST['loc_lng'] ?? 0 );
        if ( $name !== '' ) {
            $locations   = $this->get_origin_locations();
            $locations[] = [ 'name' => $name, 'lat' => $lat, 'lng' => $lng ];
            update_option( 'fishotel_origin_locations', $locations );
        }
        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
        exit;
    }

    public function delete_location_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_delete_location_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $index     = intval( $_POST['location_index'] ?? -1 );
        $locations = $this->get_origin_locations();
        if ( isset( $locations[ $index ] ) ) {
            array_splice( $locations, $index, 1 );
            update_option( 'fishotel_origin_locations', $locations );
        }
        wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
        exit;
    }

    // =========================================================================
    // Stage 3b — Arrival Entry
    // =========================================================================

    public function arrival_entry_html() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

        if ( isset( $_GET['updated'] ) )         echo '<div class="notice notice-success is-dismissible"><p>Arrival data saved.</p></div>';
        if ( isset( $_GET['survival_logged'] ) )  echo '<div class="notice notice-success is-dismissible"><p>Survival log entry added.</p></div>';

        $batches_str   = get_option( 'fishotel_batches', '' );
        $batches_array = array_values( array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) ) );
        $statuses      = get_option( 'fishotel_batch_statuses', [] );
        $arrived_plus  = [ 'arrived', 'graduation', 'verification', 'draft', 'invoicing' ];

        $eligible = array_filter( $batches_array, function ( $b ) use ( $statuses, $arrived_plus ) {
            return in_array( $statuses[ $b ] ?? '', $arrived_plus, true );
        } );

        $selected = isset( $_GET['batch'] )
            ? sanitize_text_field( wp_unslash( $_GET['batch'] ) )
            : ( ! empty( $eligible ) ? reset( $eligible ) : '' );

        echo '<div class="wrap">';
        echo '<h1>Arrival Entry</h1>';
        echo '<p style="color:#aaa;">Record arrival quantities, DOA counts, and track quarantine survival for each species.</p>';

        // Batch selector
        echo '<form method="get" style="margin-bottom:20px;">';
        echo '<input type="hidden" name="page" value="fishotel-arrival-entry">';
        echo '<label style="font-weight:700;margin-right:10px;">Batch:</label>';
        echo '<select name="batch" onchange="this.form.submit()" style="min-width:220px;padding:6px 10px;border-radius:4px;">';
        if ( empty( $eligible ) ) {
            echo '<option value="">-- No batches at arrived stage or later --</option>';
        }
        foreach ( $eligible as $b ) {
            $stage_label = $this->get_valid_stages()[ $statuses[ $b ] ] ?? $statuses[ $b ];
            echo '<option value="' . esc_attr( $b ) . '"' . selected( $selected, $b, false ) . '>' . esc_html( $b ) . ' (' . esc_html( $stage_label ) . ')</option>';
        }
        echo '</select></form>';

        if ( empty( $selected ) ) {
            echo '<p style="color:#aaa;">No batches at the arrived stage yet.</p></div>';
            return;
        }

        // Load species for this batch
        $batch_fish = get_posts( [
            'post_type'   => 'fish_batch',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_key'    => '_batch_name',
            'meta_value'  => $selected,
        ] );

        usort( $batch_fish, function ( $a, $b ) {
            $ma  = get_post_meta( $a->ID, '_master_id', true );
            $mb  = get_post_meta( $b->ID, '_master_id', true );
            $sca = $ma ? (string) get_post_meta( $ma, '_scientific_name', true ) : '';
            $scb = $mb ? (string) get_post_meta( $mb, '_scientific_name', true ) : '';
            return strcasecmp( $sca, $scb );
        } );

        if ( empty( $batch_fish ) ) {
            echo '<p style="color:#aaa;">No species found for <strong>' . esc_html( $selected ) . '</strong>.</p></div>';
            return;
        }

        // Aggregate customer demand per batch_id
        $requests = get_posts( [
            'post_type'   => 'fish_request',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_key'    => '_batch_name',
            'meta_value'  => $selected,
        ] );

        $demand = [];
        foreach ( $requests as $req ) {
            $items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
            foreach ( $items as $item ) {
                $bid = intval( $item['batch_id'] ?? 0 );
                $qty = intval( $item['qty'] ?? 1 );
                if ( $bid ) $demand[ $bid ] = ( $demand[ $bid ] ?? 0 ) + $qty;
            }
        }

        // ── Arrival Data Form ──────────────────────────────────────────────
        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:28px;">';
        echo '<h2 style="color:#e67e22;margin-top:0;font-size:1.2em;">Arrival Data</h2>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'fishotel_save_arrival_nonce' );
        echo '<input type="hidden" name="action" value="fishotel_save_arrival_data">';
        echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';

        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr style="border-bottom:2px solid #444;text-align:left;">';
        echo '<th style="padding:8px;color:#b5a165;">Common Name</th>';
        echo '<th style="padding:8px;color:#b5a165;">Scientific Name</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Demand</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Qty Ordered</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Qty Received</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Qty DOA</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Fill Rate</th>';
        echo '</tr></thead><tbody>';

        foreach ( $batch_fish as $bp ) {
            $master_id = get_post_meta( $bp->ID, '_master_id', true );
            $common    = $bp->post_title;
            $sci_name  = $master_id ? get_post_meta( $master_id, '_scientific_name', true ) : '';

            $qty_ordered  = get_post_meta( $bp->ID, '_arrival_qty_ordered', true );
            $qty_received = get_post_meta( $bp->ID, '_arrival_qty_received', true );
            $qty_doa      = get_post_meta( $bp->ID, '_arrival_qty_doa', true );

            $cust_demand = $demand[ $bp->ID ] ?? 0;
            $available   = intval( $qty_received ) - intval( $qty_doa );
            $fill_ok     = ( $cust_demand === 0 ) || ( $available >= $cust_demand );

            echo '<tr class="fh-arrival-row" data-id="' . $bp->ID . '" data-demand="' . $cust_demand . '" style="border-bottom:1px solid #333;">';
            echo '<td style="padding:8px;">' . esc_html( $common ) . '</td>';
            echo '<td style="padding:8px;color:#aaa;font-style:italic;">' . esc_html( $sci_name ) . '</td>';
            echo '<td style="padding:8px;text-align:center;">' . $cust_demand . '</td>';
            echo '<td style="padding:8px;text-align:center;"><input type="number" name="items[' . $bp->ID . '][qty_ordered]" value="' . esc_attr( $qty_ordered ) . '" min="0" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"></td>';
            echo '<td style="padding:8px;text-align:center;"><input type="number" name="items[' . $bp->ID . '][qty_received]" value="' . esc_attr( $qty_received ) . '" min="0" class="fh-recv" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"></td>';
            echo '<td style="padding:8px;text-align:center;"><input type="number" name="items[' . $bp->ID . '][qty_doa]" value="' . esc_attr( $qty_doa ) . '" min="0" class="fh-doa" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"></td>';

            $dot_color = $fill_ok ? '#27ae60' : '#e74c3c';
            $fill_text = $available . ' / ' . $cust_demand;
            echo '<td style="padding:8px;text-align:center;" class="fh-fill-cell"><span class="fh-fill-dot" style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . $dot_color . ';margin-right:6px;vertical-align:middle;"></span><span class="fh-fill-text" style="vertical-align:middle;">' . $fill_text . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div style="margin-top:16px;text-align:right;">';
        echo '<button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">Save Arrival Data</button>';
        echo '</div></form></div>';

        // ── HF Post Generator ──────────────────────────────────────────────
        $hf_arrival_dates = get_option( 'fishotel_batch_arrival_dates', [] );
        $hf_arrival_date  = $hf_arrival_dates[ $selected ] ?? '';
        $hf_arrival_fmt   = $hf_arrival_date ? date( 'F j, Y', strtotime( $hf_arrival_date ) ) : 'TBD';
        $hf_qt_end        = $hf_arrival_date ? date( 'F j, Y', strtotime( $hf_arrival_date . ' +14 days' ) ) : 'TBD';

        $hf_total_ordered  = 0;
        $hf_total_received = 0;
        $hf_total_doa      = 0;
        $hf_lines          = [];

        foreach ( $batch_fish as $bp ) {
            $recv = intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
            $doa  = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
            $ord  = intval( get_post_meta( $bp->ID, '_arrival_qty_ordered', true ) );
            $hf_total_ordered  += $ord;
            $hf_total_received += $recv;
            $hf_total_doa      += $doa;

            $cust_demand = $demand[ $bp->ID ] ?? 0;
            $available   = $recv - $doa;
            $fill_label  = ( $cust_demand === 0 ) ? 'no demand' : ( $available >= $cust_demand ? 'filled' : 'short' );
            $hf_lines[]  = '[*] ' . $bp->post_title . ' — Received: ' . $recv . ', DOA: ' . $doa . ' (' . $fill_label . ')';
        }

        $hf_post  = '[b]' . esc_html( $selected ) . ' — Arrival Report (' . $hf_arrival_fmt . ')[/b]' . "\n\n";
        $hf_post .= 'Total ordered: ' . $hf_total_ordered . ' | Received: ' . $hf_total_received . ' | DOA: ' . $hf_total_doa . "\n\n";
        $hf_post .= '[list]' . "\n" . implode( "\n", $hf_lines ) . "\n" . '[/list]' . "\n\n";
        $hf_post .= 'Quarantine ends: [b]' . $hf_qt_end . '[/b]';

        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:28px;">';
        echo '<h2 style="color:#b5a165;margin-top:0;font-size:1.2em;">HF Arrival Summary</h2>';
        echo '<button type="button" id="fh-gen-hf" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:8px 24px;font-size:13px;cursor:pointer;margin-bottom:12px;" onclick="document.getElementById(\'fh-hf-output\').style.display=\'block\';this.style.display=\'none\';">Generate HF Post</button>';
        echo '<textarea id="fh-hf-output" readonly style="display:none;width:100%;min-height:200px;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:6px;padding:12px;font-family:monospace;font-size:13px;resize:vertical;" onclick="this.select()">' . esc_textarea( $hf_post ) . '</textarea>';
        echo '</div>';

        // ── Survival Tracker ───────────────────────────────────────────────
        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;">';
        echo '<h2 style="color:#b5a165;margin-top:0;font-size:1.2em;">Quarantine Survival Tracker</h2>';
        echo '<p style="color:#aaa;margin-top:0;font-size:13px;">Log daily live counts per species. Entries are append-only.</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'fishotel_log_survival_nonce' );
        echo '<input type="hidden" name="action" value="fishotel_log_survival_entry">';
        echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';

        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr style="border-bottom:2px solid #444;text-align:left;">';
        echo '<th style="padding:8px;color:#b5a165;">Species</th>';
        echo '<th style="padding:8px;color:#b5a165;">Recent Log</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Today\'s Count</th>';
        echo '</tr></thead><tbody>';

        foreach ( $batch_fish as $bp ) {
            $log = get_post_meta( $bp->ID, '_qt_survival_log', true );
            if ( ! is_array( $log ) ) $log = [];

            // Show last 7 entries as badges
            $recent = array_slice( $log, -7 );
            $badges = '';
            foreach ( $recent as $entry ) {
                $d = date( 'M j', strtotime( $entry['date'] ) );
                $badges .= '<span style="display:inline-block;background:#2a2a2a;border:1px solid #555;border-radius:4px;padding:2px 8px;margin:2px 4px;font-size:12px;">' . esc_html( $d ) . ': <strong>' . intval( $entry['count'] ) . '</strong></span>';
            }
            if ( empty( $badges ) ) $badges = '<span style="color:#666;">No entries yet</span>';

            echo '<tr style="border-bottom:1px solid #333;">';
            echo '<td style="padding:8px;">' . esc_html( $bp->post_title ) . '</td>';
            echo '<td style="padding:8px;">' . $badges . '</td>';
            echo '<td style="padding:8px;text-align:center;"><input type="number" name="survival[' . $bp->ID . ']" min="0" placeholder="—" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div style="margin-top:16px;text-align:right;">';
        echo '<button type="submit" style="background:#27ae60;color:#fff;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">Log Today\'s Counts</button>';
        echo '</div></form></div>';

        echo '</div>'; // .wrap

        // Inline JS for real-time fill rate updates
        ?>
        <script>
        jQuery(function($){
            $('.fh-arrival-row').on('input', '.fh-recv, .fh-doa', function(){
                var row    = $(this).closest('.fh-arrival-row');
                var demand = parseInt(row.data('demand')) || 0;
                var recv   = parseInt(row.find('.fh-recv').val()) || 0;
                var doa    = parseInt(row.find('.fh-doa').val()) || 0;
                var avail  = recv - doa;
                var ok     = (demand === 0) || (avail >= demand);
                row.find('.fh-fill-dot').css('background', ok ? '#27ae60' : '#e74c3c');
                row.find('.fh-fill-text').text(avail + ' / ' + demand);
            });
        });
        </script>
        <?php
    }

    public function save_arrival_data_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_save_arrival_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( ! $batch_name ) wp_die( 'No batch specified.' );

        $items = $_POST['items'] ?? [];

        foreach ( $items as $batch_id => $data ) {
            $batch_id = intval( $batch_id );
            if ( ! $batch_id ) continue;

            $post = get_post( $batch_id );
            if ( ! $post || $post->post_type !== 'fish_batch' ) continue;
            if ( get_post_meta( $batch_id, '_batch_name', true ) !== $batch_name ) continue;

            update_post_meta( $batch_id, '_arrival_qty_ordered',  intval( $data['qty_ordered'] ?? 0 ) );
            update_post_meta( $batch_id, '_arrival_qty_received', intval( $data['qty_received'] ?? 0 ) );
            update_post_meta( $batch_id, '_arrival_qty_doa',      intval( $data['qty_doa'] ?? 0 ) );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&updated=1' ) );
        exit;
    }

    public function log_survival_entry_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_log_survival_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( ! $batch_name ) wp_die( 'No batch specified.' );

        $survival = $_POST['survival'] ?? [];
        $today    = current_time( 'Y-m-d' );

        foreach ( $survival as $batch_id => $count ) {
            $batch_id = intval( $batch_id );
            $count    = trim( $count );
            if ( ! $batch_id || $count === '' ) continue;

            $post = get_post( $batch_id );
            if ( ! $post || $post->post_type !== 'fish_batch' ) continue;
            if ( get_post_meta( $batch_id, '_batch_name', true ) !== $batch_name ) continue;

            $log = get_post_meta( $batch_id, '_qt_survival_log', true );
            if ( ! is_array( $log ) ) $log = [];

            $log[] = [ 'date' => $today, 'count' => intval( $count ) ];
            update_post_meta( $batch_id, '_qt_survival_log', $log );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&survival_logged=1' ) );
        exit;
    }

}
