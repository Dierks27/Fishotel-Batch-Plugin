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
        // Force a fresh GitHub update check every time this settings page loads.
        delete_transient( 'fishotel_github_updater_version' );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>✅ All settings saved successfully!</p></div>';
        if ( isset( $_GET['error'] ) ) echo '<div class="notice notice-error is-dismissible"><p>❌ Invalid parameters. Please try again.</p></div>';

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
                                <?php if ( $current_status === 'open_ordering' ) : ?>
                                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;margin-left:4px;">
                                        <?php wp_nonce_field( 'fishotel_close_ordering_nonce' ); ?>
                                        <input type="hidden" name="action" value="fishotel_close_ordering">
                                        <input type="hidden" name="batch_name" value="<?php echo esc_attr( $batch ); ?>">
                                        <button type="submit" class="button button-small" style="background:#c0392b;color:#fff;border-color:#a93226;" onclick="return confirm('Close ordering for \'<?php echo esc_js( $batch ); ?>\'? This immediately sets the stage to Arrived.');">🔒 Close Ordering</button>
                                    </form>
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

    public function close_ordering_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_close_ordering_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( ! $batch_name ) {
            wp_die( 'No batch specified.' );
        }
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $statuses[ $batch_name ] = 'arrived';
        update_option( 'fishotel_batch_statuses', $statuses );
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
