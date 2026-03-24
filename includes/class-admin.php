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
        register_post_type( 'fishotel_notification', [ 'labels' => [ 'name' => 'Notifications', 'singular_name' => 'Notification' ], 'public' => false, 'show_ui' => false, 'show_in_menu' => false, 'supports' => [ 'title', 'editor' ] ] );
        register_post_type( 'fishotel_sticker', [ 'labels' => [ 'name' => 'Stickers', 'singular_name' => 'Sticker' ], 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => [ 'title', 'thumbnail' ] ] );
    }

    public function add_admin_menu() {
        $fish_icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmgiIHZpZXdCb3g9IjAgMCAxMDAgNjAiPjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik02NSAzMEMxMCAzMCAwIDU1IDAgNTVzMzAtMTUgMzUtMzBDMTAgMjUgMCAxMCAwIDVjMCAwIDMwIDIwIDM1IDE1IDAtMTAgMjAtMjAgNDAtMjAgMjAgMCAzNSAxNSAzNSAzMCAwIDE1LTE1IDMwLTM1IDMwLTIwIDAtNDAtMTAtNDAtMjB6Ii8+PGVsbGlwc2UgZmlsbD0iIzIzMjgzMyIgY3g9Ijc4IiBjeT0iMjciIHJ4PSIzIiByeT0iMyIvPjwvc3ZnPg==';
        // 3 visible menu items
        add_menu_page( 'FisHotel Batch Manager', 'FisHotel Batch', 'manage_options', 'fishotel-batch-hq', [$this, 'batch_hq_html'], $fish_icon, 56 );
        add_submenu_page( 'fishotel-batch-hq', 'Batch HQ', 'Batch HQ', 'manage_options', 'fishotel-batch-hq' );
        add_submenu_page( 'fishotel-batch-hq', 'QT Operations', 'QT Operations', 'manage_options', 'fishotel-arrival-entry', [$this, 'arrival_entry_html'] );
        add_submenu_page( 'fishotel-batch-hq', 'Sourcing', 'Sourcing', 'manage_options', 'fishotel-sourcing', [$this, 'sourcing_html'] );
        add_submenu_page( 'fishotel-batch-hq', 'Hotel Program', 'Hotel Program', 'manage_options', 'fishotel-hotel-program', [$this, 'hotel_program_html'] );
        // Hidden backward-compat pages (old slugs still work via direct URL)
        add_submenu_page( null, 'FisHotel Settings', '', 'manage_options', 'fishotel-batch-settings', [$this, 'batch_settings_html'] );
        add_submenu_page( null, 'Batch Requests', '', 'manage_options', 'fishotel-batch-orders', [$this, 'batch_orders_html'] );
        add_submenu_page( null, 'Customer Wallets', '', 'manage_options', 'fishotel-wallets', [$this, 'wallets_html'] );
        add_submenu_page( null, 'Order Summary', '', 'manage_options', 'fishotel-order-summary', [$this, 'order_summary_html'] );
        add_submenu_page( null, 'Sync Quarantined Fish', '', 'manage_options', 'fishotel-sync', [$this, 'sync_page_html'] );
        add_submenu_page( null, 'North Star Stock', '', 'manage_options', 'fishotel-northstar', [$this, 'northstar_stock_html'] );

        // CPT menu highlighting
        add_filter( 'parent_file', function( $parent_file ) {
            global $typenow;
            if ( $typenow === 'fish_master' || $typenow === 'fish_batch' ) return 'fishotel-batch-hq';
            return $parent_file;
        } );
        add_filter( 'submenu_file', function( $submenu_file ) {
            global $typenow;
            if ( $typenow === 'fish_master' || $typenow === 'fish_batch' ) return 'fishotel-sourcing';
            return $submenu_file;
        } );

        // Back-navigation breadcrumb on nested pages
        add_action( 'admin_notices', [ $this, 'render_back_breadcrumb' ] );
    }

    public function render_back_breadcrumb() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen ) return;

        $crumbs = [];
        $sourcing_url = admin_url( 'admin.php?page=fishotel-sourcing' );

        // CPT list pages
        if ( $screen->base === 'edit' && $screen->post_type === 'fish_master' ) {
            $crumbs = [ [ 'Sourcing', $sourcing_url ] ];
        } elseif ( $screen->base === 'edit' && $screen->post_type === 'fish_batch' ) {
            $crumbs = [ [ 'Sourcing', $sourcing_url ] ];
        }
        // Single post edit pages
        elseif ( $screen->base === 'post' && $screen->post_type === 'fish_master' ) {
            $crumbs = [
                [ 'Sourcing', $sourcing_url ],
                [ 'Fish Library', admin_url( 'edit.php?post_type=fish_master' ) ],
            ];
        } elseif ( $screen->base === 'post' && $screen->post_type === 'fish_batch' ) {
            $crumbs = [
                [ 'Sourcing', $sourcing_url ],
                [ 'Batch Fish', admin_url( 'edit.php?post_type=fish_batch' ) ],
            ];
        }

        if ( empty( $crumbs ) ) return;

        echo '<div style="margin:8px 0 -8px 0;padding:0;">';
        $parts = [];
        foreach ( $crumbs as $c ) {
            $parts[] = '<a href="' . esc_url( $c[1] ) . '" style="color:#b5a165;text-decoration:none;font-size:13px;">' . esc_html( $c[0] ) . '</a>';
        }
        echo implode( ' <span style="color:#666;font-size:13px;">&#8250;</span> ', $parts );
        echo '</div>';
    }

    // ─── Tab wrapper: Batch HQ ────────────────────────────────────────

    public function batch_hq_html() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'settings';
        $tabs = [
            'settings' => 'Settings',
            'requests' => 'Requests',
            'summary'  => 'Order Summary',
            'wallets'  => 'Wallets',
            'casino'   => 'Casino',
        ];
        $this->render_admin_tabs( 'fishotel-batch-hq', 'Batch HQ', $tabs, $tab );

        switch ( $tab ) {
            case 'requests': $this->batch_orders_html(); break;
            case 'summary':  $this->order_summary_html(); break;
            case 'wallets':  $this->wallets_html(); break;
            case 'casino':   $this->batch_casino_html(); break;
            default:         $this->batch_settings_html(); break;
        }
    }

    // ─── Casino tab: delegates to FisHotel_Arcade admin ────────────

    private function batch_casino_html() {
        $arcade = new FisHotel_Arcade();
        $arcade->render_admin_page();
    }

    // ─── Tab wrapper: Sourcing ──────────────────────────────────────

    public function sourcing_html() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'northstar';
        $tabs = [
            'northstar' => 'North Star Stock',
            'library'   => [ 'label' => 'Fish Library', 'url' => admin_url( 'edit.php?post_type=fish_master' ) ],
            'batch'     => [ 'label' => 'Batch Fish', 'url' => admin_url( 'edit.php?post_type=fish_batch' ) ],
            'sync'      => 'Sync QT Fish',
        ];
        $this->render_admin_tabs( 'fishotel-sourcing', 'Sourcing', $tabs, $tab );

        switch ( $tab ) {
            case 'sync':    $this->sync_page_html(); break;
            default:        $this->northstar_stock_html(); break;
        }
    }

    // ─── Shared tab bar renderer ────────────────────────────────────

    private function render_admin_tabs( string $page_slug, string $title, array $tabs, string $active ) {
        echo '<div class="wrap fishotel-admin" style="margin-bottom:0;padding-bottom:0;">';
        echo '<h1 style="color:#b5a165;font-size:26px;font-weight:700;margin-bottom:12px;">' . esc_html( $title ) . '</h1>';
        echo '<nav class="nav-tab-wrapper" style="border-bottom:2px solid #555;margin-bottom:0;">';
        foreach ( $tabs as $key => $tab_data ) {
            if ( is_array( $tab_data ) ) {
                $url   = $tab_data['url'];
                $label = $tab_data['label'];
            } else {
                $url   = admin_url( 'admin.php?page=' . $page_slug . '&tab=' . $key );
                $label = $tab_data;
            }
            $class = ( $active === $key ) ? ' nav-tab-active' : '';
            $style = ( $active === $key )
                ? 'background:#1e1e1e;color:#b5a165;border:2px solid #555;border-bottom:2px solid #1e1e1e;margin-bottom:-2px;font-weight:700;'
                : 'background:transparent;color:#aaa;border:2px solid transparent;';
            echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $class . '" style="' . $style . 'padding:8px 18px;font-size:14px;text-decoration:none;">' . esc_html( $label ) . '</a>';
        }
        echo '</nav></div>';
    }

    public function register_settings() {
        register_setting( 'fishotel_batch_settings', 'fishotel_current_batch' );
        register_setting( 'fishotel_batch_settings', 'fishotel_batch_page_assignments' );
        register_setting( 'fishotel_batch_settings', 'fishotel_batch_statuses' );
        register_setting( 'fishotel_batch_settings', 'fishotel_admin_test_mode' );
        register_setting( 'fishotel_batch_settings', 'fishotel_deposit_product_id', [ 'default' => 31985 ] );
        register_setting( 'fishotel_batch_settings', 'fishotel_batch_deposit_amounts' );
        register_setting( 'fishotel_batch_settings', 'fishotel_verification_response_hours', [ 'default' => 24 ] );
        register_setting( 'fishotel_batch_settings', 'fishotel_lastcall_window_hours', [ 'default' => 48 ] );
        register_setting( 'fishotel_batch_settings', 'fishotel_lastcall_rounds', [ 'default' => 2 ] );
    }

    public function batch_settings_html() {
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>All settings saved successfully!</p></div>';
        if ( isset( $_GET['error'] ) ) echo '<div class="notice notice-error is-dismissible"><p>Invalid parameters. Please try again.</p></div>';
        if ( isset( $_GET['fishotel_update_checked'] ) ) {
            $v = sanitize_text_field( $_GET['fishotel_update_checked'] );
            echo $v === 'error'
                ? '<div class="notice notice-error is-dismissible"><p>Could not reach GitHub to check for updates.</p></div>'
                : '<div class="notice notice-info is-dismissible"><p>GitHub version: <strong>' . esc_html( $v ) . '</strong> — Installed: <strong>' . FISHOTEL_VERSION . '</strong></p></div>';
        }

        $price_import_result = get_transient( 'fishotel_price_import_result_' . get_current_user_id() );
        if ( $price_import_result ) {
            delete_transient( 'fishotel_price_import_result_' . get_current_user_id() );
            $updated  = intval( $price_import_result['updated'] );
            $notfound = $price_import_result['not_found'];
            echo '<div class="notice notice-info is-dismissible" style="padding:16px 20px;">';
            echo '<p style="font-size:15px;margin:0 0 6px;"><strong>Import Master Prices — Results</strong></p>';
            echo '<p style="margin:4px 0;"><strong>' . $updated . '</strong> fish price' . ( $updated !== 1 ? 's' : '' ) . ' updated</p>';
            echo '<p style="margin:4px 0;"><strong>' . count( $notfound ) . '</strong> scientific name' . ( count( $notfound ) !== 1 ? 's' : '' ) . ' not found in Master Library</p>';
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
        $closed_times  = get_option( 'fishotel_batch_closed_times', [] );
        $origin_locations = $this->get_origin_locations();
        $batch_origins = get_option( 'fishotel_batch_origins', [] );

        if ( isset( $_POST['fishotel_save_all'] ) && check_admin_referer( 'fishotel_save_all_nonce' ) ) {
            update_option( 'fishotel_deposit_product_id', intval( $_POST['deposit_product_id'] ?? 31985 ) );
            update_option( 'fishotel_current_batch', sanitize_text_field( $_POST['fishotel_current_batch'] ?? '' ) );
            update_option( 'fishotel_admin_test_mode', isset( $_POST['admin_test_mode'] ) ? 1 : 0 );
            if ( isset( $_POST['verification_response_hours'] ) ) {
                update_option( 'fishotel_verification_response_hours', max( 1, intval( $_POST['verification_response_hours'] ) ) );
            }
            if ( isset( $_POST['lastcall_window_hours'] ) ) {
                update_option( 'fishotel_lastcall_window_hours', max( 1, intval( $_POST['lastcall_window_hours'] ) ) );
            }
            if ( isset( $_POST['lastcall_rounds'] ) ) {
                update_option( 'fishotel_lastcall_rounds', max( 1, min( 10, intval( $_POST['lastcall_rounds'] ) ) ) );
            }

            $new_assignments = [];
            $new_statuses = [];
            $new_deposit_amounts = [];
            $new_arrival_dates = [];
            $new_closed_dates  = [];
            $new_closed_times  = [];
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
                if ( isset( $_POST['closed_time_' . $key] ) ) {
                    $time = sanitize_text_field( $_POST['closed_time_' . $key] );
                    if ( $time && preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
                        $new_closed_times[ $batch ] = $time;
                    }
                }
                if ( isset( $_POST['origin_' . $key] ) ) {
                    $origin = sanitize_text_field( $_POST['origin_' . $key] );
                    if ( $origin !== '' ) $new_origins[ $batch ] = $origin;
                }
                $force_day_val = isset( $_POST['force_day_' . $key] ) ? (int) $_POST['force_day_' . $key] : 0;
                $force_day_option = 'fishotel_force_day_' . sanitize_key( $batch );
                if ( $force_day_val > 0 && $force_day_val <= 21 ) {
                    update_option( $force_day_option, $force_day_val );
                } else {
                    delete_option( $force_day_option );
                }
            }
            update_option( 'fishotel_batch_page_assignments', $new_assignments );
            update_option( 'fishotel_batch_statuses', $new_statuses );
            update_option( 'fishotel_batch_deposit_amounts', $new_deposit_amounts );
            update_option( 'fishotel_batch_arrival_dates', $new_arrival_dates );
            update_option( 'fishotel_batch_closed_dates', $new_closed_dates );
            update_option( 'fishotel_batch_closed_times', $new_closed_times );
            update_option( 'fishotel_batch_origins', $new_origins );

            wp_redirect( admin_url( 'admin.php?page=fishotel-batch-settings&updated=1' ) );
            exit;
        }

        $pages = get_pages( [ 'sort_column' => 'post_title' ] );
        $stage_options = $this->get_valid_stages();
        ?>
        <div class="wrap fishotel-admin">
            <h1>FisHotel Batch Manager</h1>
            <p class="page-description">Complete backend control for fishotel.com batch system &nbsp;·&nbsp; v<?php echo FISHOTEL_VERSION; ?> &nbsp;·&nbsp; <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-batch-settings&fishotel_force_update_check=1' ) ); ?>" style="color:#b5a165;">Check for updates</a></p>

            <!-- ===== ZONE 1: Import Card ===== -->
            <div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:25px;margin-top:24px;">
                <div style="display:flex;gap:40px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="flex:1;min-width:220px;">
                        <form method="post" enctype="multipart/form-data" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                            <?php wp_nonce_field( 'fishotel_import_csv_nonce' ); ?>
                            <input type="hidden" name="action" value="fishotel_import_csv">
                            <label style="display:block;font-weight:700;color:#fff;margin-bottom:10px;">Import Exporter CSV</label>
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
                            $closed_time_val = $closed_times[$batch] ?? '23:59';
                            $current_origin = $batch_origins[$batch] ?? '';
                            $force_day_cur  = (int) get_option( 'fishotel_force_day_' . $key, 0 );
                            $view_url  = $current_page ? home_url( '/' . $current_page ) : '';
                            $embed_url = $current_page ? home_url( '/' . $current_page . '?embed=1' ) : '';
                        ?>
                        <tr>
                            <td>
                                <strong style="color:#b5a165;cursor:pointer;" onclick="fhToggleDetail('<?php echo $key; ?>')">
                                    <span id="fh-chev-<?php echo $key; ?>" style="display:inline-block;transition:transform .2s;font-size:11px;margin-right:4px;">&#9654;</span><?php echo esc_html( $batch ); ?>
                                </strong>
                                <?php if ( $force_day_cur > 0 ) : ?>
                                    <span style="color:#e67e22;font-size:11px;margin-left:8px;">&#9888; Preview Day <?php echo $force_day_cur; ?></span>
                                <?php endif; ?>
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
                                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Close Time</label>
                                        <input type="time" name="closed_time_<?php echo $key; ?>" value="<?php echo esc_attr( $closed_time_val ); ?>" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:5px 8px;border-radius:4px;width:100px;">
                                    </div>
                                    <div>
                                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Arrival Date</label>
                                        <input type="date" name="arrival_date_<?php echo $key; ?>" value="<?php echo esc_attr( $arrival_date ); ?>" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:5px 8px;border-radius:4px;width:140px;">
                                    </div>
                                    <div>
                                        <label style="display:block;color:#aaa;font-size:12px;margin-bottom:4px;">Preview Day</label>
                                        <input type="number" min="0" max="21" name="force_day_<?php echo $key; ?>" value="<?php echo $force_day_cur ? esc_attr( $force_day_cur ) : ''; ?>" placeholder="Off" style="background:#2a2a2a;border:1px solid #555;color:#fff;padding:5px 8px;border-radius:4px;width:60px;">
                                        <span style="display:block;color:#666;font-size:10px;margin-top:2px;">Force postcard day (1–21)</span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Save All Settings -->
                <p style="text-align:center;margin:28px 0 12px 0;">
                    <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:8px;padding:16px 60px;font-size:18px;cursor:pointer;">Save All Settings</button>
                </p>

            </form>

                <!-- ===== ZONE 3: Advanced Settings ===== -->
                <div style="margin-top:8px;padding-bottom:40px;text-align:center;">
                    <button type="button" id="fishotel-advanced-toggle" style="background:none;border:none;color:#aaa;font-size:0.85em;cursor:pointer;text-decoration:underline;padding:6px 0;">Advanced Settings ▾</button>
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
                                <th style="color:#ddd;">Verification Response Window</th>
                                <td>
                                    <input type="number" name="verification_response_hours" form="fishotel-save-all-form" value="<?php echo esc_attr( get_option( 'fishotel_verification_response_hours', 24 ) ); ?>" min="1" style="width:80px;padding:5px 8px;border-radius:4px;"> <span style="color:#aaa;">hours</span>
                                    <small style="display:block;margin-top:5px;color:#aaa;">Time customers have to accept or pass before auto-pass kicks in</small>
                                    <button type="button" id="fh-run-cron-btn" style="margin-top:8px;background:#444;color:#ddd;border:1px solid #666;border-radius:4px;padding:5px 14px;font-size:12px;cursor:pointer;" onclick="(function(btn){btn.disabled=true;btn.textContent='Running...';fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=fishotel_run_cron_now',credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){btn.textContent=d.success?'Done!':'Error';setTimeout(function(){btn.disabled=false;btn.textContent='Run Verification Cron Now'},2000)}).catch(function(){btn.textContent='Error';btn.disabled=false})})(this)">Run Verification Cron Now</button>
                                </td>
                            </tr>
                            <tr>
                                <th style="color:#ddd;">Last Call Window</th>
                                <td>
                                    <input type="number" name="lastcall_window_hours" form="fishotel-save-all-form" value="<?php echo esc_attr( get_option( 'fishotel_lastcall_window_hours', 48 ) ); ?>" min="1" style="width:80px;padding:5px 8px;border-radius:4px;"> <span style="color:#aaa;">hours</span>
                                    <small style="display:block;margin-top:5px;color:#aaa;">Wishlist submission window for Last Call draft pool</small>
                                </td>
                            </tr>
                            <tr>
                                <th style="color:#ddd;">Last Call Rounds</th>
                                <td>
                                    <input type="number" name="lastcall_rounds" form="fishotel-save-all-form" value="<?php echo esc_attr( get_option( 'fishotel_lastcall_rounds', 2 ) ); ?>" min="1" max="10" style="width:80px;padding:5px 8px;border-radius:4px;">
                                    <small style="display:block;margin-top:5px;color:#aaa;">Number of draft rounds before remaining pool is released</small>
                                </td>
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
                <button type="button" id="fishotel-ticker-toggle" style="background:none;border:none;color:#aaa;font-size:0.85em;cursor:pointer;text-decoration:underline;padding:6px 0;">Ticker Messages ▾</button>
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
                <button type="button" id="fishotel-origins-toggle" style="background:none;border:none;color:#aaa;font-size:0.85em;cursor:pointer;text-decoration:underline;padding:6px 0;">Origin Locations ▾</button>
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
                btn.setAttribute('data-tip', 'Copied!');
                btn.textContent = 'OK';
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
                this.textContent = 'Advanced Settings ▴';
            } else {
                body.style.display = 'none';
                this.textContent = 'Advanced Settings ▾';
            }
        });
        document.getElementById('fishotel-ticker-toggle').addEventListener('click', function() {
            var body = document.getElementById('fishotel-ticker-body');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                this.textContent = 'Ticker Messages ▴';
            } else {
                body.style.display = 'none';
                this.textContent = 'Ticker Messages ▾';
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
                this.textContent = 'Origin Locations ▴';
            } else {
                body.style.display = 'none';
                this.textContent = 'Origin Locations ▾';
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
        echo '<h1>Copy Order Summary</h1>';
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
                Export Order to Excel
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
            echo '<div class="notice notice-success is-dismissible" style="margin:16px 0;"><p>Your order has been saved and stock reserved.</p></div>';
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

                <button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">Save My Order</button>
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
        if ( $user_id && $amount !== 0.0 ) {
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
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Deposit status updated!</p></div>';
        if ( isset( $_GET['error'] ) ) echo '<div class="notice notice-error is-dismissible"><p>Invalid parameters.</p></div>';
        if ( isset( $_GET['reset_done'] ) ) echo '<div class="notice notice-warning is-dismissible"><p>Test data reset — wallet balances and deposit flags cleared for ' . intval( $_GET['reset_done'] ) . ' users.</p></div>';

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
                <button class="fishotel-danger-toggle" type="button" id="fishotel-danger-toggle">Danger Zone</button>
                <div class="fishotel-danger-body" id="fishotel-danger-body">
                    <p style="color:#e74c3c;font-weight:700;margin-top:0;">Destructive actions — cannot be undone.</p>
                    <p class="page-description">Clears ALL wallet balances, deposit flags, and deletes ALL fish requests for every user. Use only during testing.</p>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline-block;margin-right:12px;">
                        <input type="hidden" name="action" value="fishotel_reset_test_data">
                        <?php wp_nonce_field( 'fishotel_reset_test_data' ); ?>
                        <button type="submit" style="background:#e74c3c;color:#fff;font-weight:700;padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px;" onclick="return confirm('RESET ALL wallet balances, deposit flags, and fish requests for every user? This cannot be undone.');">Reset Test Data</button>
                    </form>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="fishotel_create_test_requests">
                        <?php wp_nonce_field( 'fishotel_create_test_requests' ); ?>
                        <button type="submit" style="background:#8e44ad;color:#fff;font-weight:700;padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px;" onclick="return confirm('Create 3 fake test requests for the active transit batch?');">Create Test Requests</button>
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
            'fishotel-batch-hq',
            'fishotel-sourcing',
            'fishotel-batch-orders',
            'fishotel-batch-settings',
            'fishotel-wallets',
            'fishotel-order-summary',
            'fishotel-sync',
            'fishotel-northstar',
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
    content: '';
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
    content: '';
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
            'open_ordering'   => 'Open Ordering',
            'orders_closed'   => 'Orders Closed',
            'arrived'         => 'Arrived — Counting',
            'in_quarantine'   => 'In Quarantine',
            'graduation'      => 'Graduation Day',
            'verification'    => 'Accept or Pass',
            'draft'           => 'Draft Pool',
            'casino'          => 'Casino Night',
            'invoicing'       => 'Invoicing',
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
                'label'      => 'Close Ordering',
                'style'      => 'background:#c0392b;color:#fff;border-color:#a93226;',
                'confirm'    => "Close ordering for '%s'? This immediately sets the stage to Arrived.",
            ],
            'draft' => [
                'next_stage' => 'casino',
                'label'      => 'Close Draft &amp; Open Casino',
                'style'      => 'background:#96885f;color:#fff;border-color:#7a6f4e;',
                'confirm'    => "Close the draft for '%s' and open Casino Night?",
            ],
            'casino' => [
                'next_stage' => 'invoicing',
                'label'      => 'Close Casino &amp; Open Invoicing',
                'style'      => 'background:#27ae60;color:#fff;border-color:#1e8449;',
                'confirm'    => "Close Casino Night for '%s' and move to Invoicing?",
            ],
        ];
    }

    public function advance_stage_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_advance_stage_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $next_stage = sanitize_key( $_POST['next_stage'] ?? $_POST['new_stage'] ?? '' );
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

        if ( isset( $_GET['updated'] ) )           echo '<div class="notice notice-success is-dismissible"><p>Arrival data saved.</p></div>';
        if ( isset( $_GET['survival_logged'] ) )  echo '<div class="notice notice-success is-dismissible"><p>Survival log entry added.</p></div>';
        if ( isset( $_GET['graduation_saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Graduation counts saved.</p></div>';
        if ( isset( $_GET['lastcall_opened'] ) )  echo '<div class="notice notice-success is-dismissible"><p>Last Call opened! Draft pool and order have been built.</p></div>';
        if ( isset( $_GET['lastcall_reset'] ) )   echo '<div class="notice notice-warning is-dismissible"><p>Draft results have been reset. You can re-run the draft.</p></div>';

        $tab = sanitize_text_field( $_GET['tab'] ?? 'arrival' );
        if ( ! in_array( $tab, [ 'arrival', 'tracker', 'graduation', 'lastcall' ], true ) ) $tab = 'arrival';

        $batches_str   = get_option( 'fishotel_batches', '' );
        $batches_array = array_values( array_filter( array_map( 'trim', explode( "\n", $batches_str ) ) ) );
        $statuses      = get_option( 'fishotel_batch_statuses', [] );
        $arrived_plus  = [ 'arrived', 'in_quarantine', 'graduation', 'verification', 'draft', 'invoicing' ];

        $eligible = array_filter( $batches_array, function ( $b ) use ( $statuses, $arrived_plus ) {
            return in_array( $statuses[ $b ] ?? '', $arrived_plus, true );
        } );

        $selected = isset( $_GET['batch'] )
            ? sanitize_text_field( wp_unslash( $_GET['batch'] ) )
            : ( ! empty( $eligible ) ? reset( $eligible ) : '' );

        echo '<div class="wrap">';
        echo '<h1>QT Operations</h1>';
        echo '<p style="color:#aaa;">Manage arrivals, track quarantine survival, and confirm graduation counts.</p>';

        // Batch selector
        echo '<form method="get" style="margin-bottom:20px;">';
        echo '<input type="hidden" name="page" value="fishotel-arrival-entry">';
        echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
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

        $tab_base = admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $selected ) );
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
        echo '<a href="' . esc_url( $tab_base . '&tab=arrival' ) . '" class="nav-tab' . ( $tab === 'arrival' ? ' nav-tab-active' : '' ) . '">Arrival Data</a>';
        echo '<a href="' . esc_url( $tab_base . '&tab=tracker' ) . '" class="nav-tab' . ( $tab === 'tracker' ? ' nav-tab-active' : '' ) . '">Quarantine Tracker</a>';
        echo '<a href="' . esc_url( $tab_base . '&tab=graduation' ) . '" class="nav-tab' . ( $tab === 'graduation' ? ' nav-tab-active' : '' ) . '">Graduation Headcount</a>';
        echo '<a href="' . esc_url( $tab_base . '&tab=lastcall' ) . '" class="nav-tab' . ( $tab === 'lastcall' ? ' nav-tab-active' : '' ) . '">Last Call</a>';
        echo '</nav>';

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

        // Arrival date vars (shared across tabs)
        $hf_arrival_dates = get_option( 'fishotel_batch_arrival_dates', [] );
        $hf_arrival_date  = $hf_arrival_dates[ $selected ] ?? '';

        // ── Arrival Data Form ──────────────────────────────────────────────
        if ( $tab === 'arrival' ) :
        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:28px;">';
        echo '<h2 style="color:#e67e22;margin-top:0;font-size:1.2em;">Arrival Data</h2>';
        echo '<input type="text" id="fh-arrival-search" placeholder="Search species..." style="width:100%;padding:10px 14px;margin-bottom:16px;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:6px;font-size:14px;box-sizing:border-box;outline:none;" onfocus="this.style.borderColor=\'#e67e22\'" onblur="this.style.borderColor=\'#555\'">';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'fishotel_save_arrival_nonce' );
        echo '<input type="hidden" name="action" value="fishotel_save_arrival_data">';
        echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';

        // Split into demanded (has customer orders or arrival data) and surprise (no demand, no arrival data)
        $demanded_fish = [];
        $surprise_fish = [];
        foreach ( $batch_fish as $bf ) {
            $has_demand  = ( $demand[ $bf->ID ] ?? 0 ) > 0;
            $has_arrival = intval( get_post_meta( $bf->ID, '_arrival_qty_received', true ) ) > 0 || get_post_meta( $bf->ID, '_arrival_tank', true ) !== '';
            if ( $has_demand || $has_arrival ) {
                $demanded_fish[] = $bf;
            } else {
                $surprise_fish[] = $bf;
            }
        }

        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr style="border-bottom:2px solid #444;text-align:left;">';
        echo '<th style="padding:8px;color:#b5a165;">Common Name</th>';
        echo '<th style="padding:8px;color:#b5a165;">Scientific Name</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Demand</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Qty Ordered</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Qty Received</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Qty DOA</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Tank</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Status</th>';
        echo '<th style="padding:8px;color:#b5a165;text-align:center;">Fill Rate</th>';
        echo '</tr></thead><tbody>';

        foreach ( $demanded_fish as $bp ) {
            $master_id = get_post_meta( $bp->ID, '_master_id', true );
            $common    = FisHotel_Batch_Manager::resolve_common_name( $bp->ID, $bp->post_title );
            $sci_name  = $master_id ? get_post_meta( $master_id, '_scientific_name', true ) : '';

            $qty_ordered  = get_post_meta( $bp->ID, '_arrival_qty_ordered', true );
            $qty_received = get_post_meta( $bp->ID, '_arrival_qty_received', true );
            $qty_doa      = get_post_meta( $bp->ID, '_arrival_qty_doa', true );
            $tank         = get_post_meta( $bp->ID, '_arrival_tank', true );
            $arr_status   = get_post_meta( $bp->ID, '_arrival_status', true );

            $cust_demand = $demand[ $bp->ID ] ?? 0;
            $available   = intval( $qty_received ) - intval( $qty_doa );
            $fill_ok     = ( $cust_demand === 0 ) || ( $available >= $cust_demand );

            // Auto-suggest status if none saved
            if ( ! $arr_status ) {
                $recv = intval( $qty_received );
                if ( $recv === 0 ) $arr_status = 'no_arrival';
                elseif ( $recv >= $cust_demand && $cust_demand > 0 ) $arr_status = 'in_quarantine';
                elseif ( $recv > 0 ) $arr_status = 'short';
            }

            $row_bg = intval( $qty_received ) > 0 ? 'border-bottom:1px solid #333;background:rgba(181,161,101,0.08);' : 'border-bottom:1px solid #333;';
            echo '<tr class="fh-arrival-row" data-id="' . $bp->ID . '" data-demand="' . $cust_demand . '" style="' . $row_bg . '">';
            echo '<td style="padding:8px;">' . esc_html( $common ) . '</td>';
            echo '<td style="padding:8px;color:#aaa;font-style:italic;">' . esc_html( $sci_name ) . '</td>';
            echo '<td style="padding:8px;text-align:center;">' . $cust_demand . '</td>';
            echo '<td style="padding:8px;text-align:center;color:#fff;font-weight:600;">' . intval( $cust_demand ) . '</td>';
            echo '<td style="padding:8px;text-align:center;"><input type="number" name="items[' . $bp->ID . '][qty_received]" value="' . esc_attr( $qty_received ) . '" min="0" class="fh-recv" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"></td>';
            echo '<td style="padding:8px;text-align:center;"><input type="number" name="items[' . $bp->ID . '][qty_doa]" value="' . esc_attr( $qty_doa ) . '" min="0" class="fh-doa" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"></td>';
            $tank_options = [ '' => '— Assign Tank —', 'Floor 1 — 20 Gallon' => [ '201', '202', '203', '204' ], 'Floor 2 — 40 Gallon' => [ '101', '102', '103' ], 'QT Annex — 40 Gallon' => [ '301', '302' ] ];
            echo '<td style="padding:8px;text-align:center;"><select name="items[' . $bp->ID . '][tank]" class="fh-tank" style="background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;font-size:12px;">';
            echo '<option value=""' . selected( $tank, '', false ) . '>— Assign Tank —</option>';
            foreach ( [ 'Floor 1 — 20 Gallon' => [ '201', '202', '203', '204' ], 'Floor 2 — 40 Gallon' => [ '101', '102', '103' ], 'QT Annex — 40 Gallon' => [ '301', '302' ] ] as $group => $tanks ) {
                echo '<optgroup label="' . esc_attr( $group ) . '">';
                foreach ( $tanks as $t ) { echo '<option value="' . $t . '"' . selected( $tank, $t, false ) . '>' . $t . '</option>'; }
                echo '</optgroup>';
            }
            echo '</select></td>';

            $status_options = [ 'in_transit' => 'In Transit', 'counting' => 'Counting', 'in_quarantine' => "In Quarantine \xe2\x9c\x93", 'short' => "Short Supply \xe2\x9a\xa0", 'no_arrival' => "No Arrival \xe2\x9c\x97" ];
            echo '<td style="padding:8px;text-align:center;"><select name="items[' . $bp->ID . '][status]" class="fh-status" style="background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;font-size:12px;">';
            foreach ( $status_options as $skey => $slabel ) {
                echo '<option value="' . $skey . '"' . selected( $arr_status, $skey, false ) . '>' . $slabel . '</option>';
            }
            echo '</select></td>';

            $dot_color = $fill_ok ? '#27ae60' : '#e74c3c';
            $fill_text = $available . ' / ' . $cust_demand;
            echo '<td style="padding:8px;text-align:center;" class="fh-fill-cell"><span class="fh-fill-dot" style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . $dot_color . ';margin-right:6px;vertical-align:middle;"></span><span class="fh-fill-text" style="vertical-align:middle;">' . $fill_text . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // ── Add Surprise Fish ──
        if ( ! empty( $surprise_fish ) ) {
            echo '<div style="margin-top:16px;border-top:1px solid #444;padding-top:16px;">';
            echo '<button type="button" id="fh-surprise-toggle" style="background:#333;color:#b5a165;border:1px solid #555;border-radius:6px;padding:8px 20px;font-size:13px;cursor:pointer;" onclick="document.getElementById(\'fh-surprise-form\').style.display=document.getElementById(\'fh-surprise-form\').style.display===\'none\'?\'block\':\'none\'">+ Add Surprise Fish</button>';
            echo '<div id="fh-surprise-form" style="display:none;margin-top:12px;background:#252525;border:1px solid #444;border-radius:6px;padding:16px;">';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">';
            echo '<div><label style="color:#aaa;font-size:11px;display:block;margin-bottom:4px;">Species</label>';
            echo '<select id="fh-surprise-species" style="background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:6px;min-width:200px;font-size:12px;">';
            foreach ( $surprise_fish as $sf ) {
                $sf_name = FisHotel_Batch_Manager::resolve_common_name( $sf->ID, $sf->post_title );
                echo '<option value="' . $sf->ID . '">' . esc_html( $sf_name ) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label style="color:#aaa;font-size:11px;display:block;margin-bottom:4px;">Qty Received</label><input type="number" id="fh-surprise-recv" min="0" value="0" style="width:70px;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:6px;text-align:center;"></div>';
            echo '<div><label style="color:#aaa;font-size:11px;display:block;margin-bottom:4px;">Qty DOA</label><input type="number" id="fh-surprise-doa" min="0" value="0" style="width:70px;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:6px;text-align:center;"></div>';
            echo '<div><label style="color:#aaa;font-size:11px;display:block;margin-bottom:4px;">Tank</label><select id="fh-surprise-tank" style="background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:6px;font-size:12px;">';
            echo '<option value="">— Assign —</option>';
            echo '<optgroup label="Floor 1 — 20 Gallon"><option value="201">201</option><option value="202">202</option><option value="203">203</option><option value="204">204</option></optgroup>';
            echo '<optgroup label="Floor 2 — 40 Gallon"><option value="101">101</option><option value="102">102</option><option value="103">103</option></optgroup>';
            echo '<optgroup label="QT Annex — 40 Gallon"><option value="301">301</option><option value="302">302</option></optgroup>';
            echo '</select></div>';
            echo '<div><label style="color:#aaa;font-size:11px;display:block;margin-bottom:4px;">Status</label><select id="fh-surprise-status" style="background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:6px;font-size:12px;">';
            echo '<option value="in_quarantine">In Quarantine</option><option value="no_arrival">No Arrival</option>';
            echo '</select></div>';
            echo '<div><button type="button" id="fh-surprise-add" style="background:#27ae60;color:#fff;border:none;border-radius:6px;padding:8px 20px;font-size:13px;cursor:pointer;font-weight:600;">Add to Arrival</button></div>';
            echo '</div></div></div>';
        }

        echo '<div style="margin-top:16px;text-align:right;">';
        echo '<button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">Save Arrival Data</button>';
        echo '</div></form></div>';

        // ── HF Post Generator ──────────────────────────────────────────────
        $hf_arrival_fmt   = $hf_arrival_date ? date( 'F j, Y', strtotime( $hf_arrival_date ) ) : 'TBD';
        $hf_qt_end        = $hf_arrival_date ? date( 'F j, Y', strtotime( $hf_arrival_date . ' +14 days' ) ) : 'TBD';

        $hf_total_ordered  = 0;
        $hf_total_received = 0;
        $hf_total_doa      = 0;
        $hf_rows           = '';

        foreach ( $batch_fish as $bp ) {
            $recv = intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
            $doa  = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
            $ord  = intval( get_post_meta( $bp->ID, '_arrival_qty_ordered', true ) );
            $hf_total_ordered  += $ord;
            $hf_total_received += $recv;
            $hf_total_doa      += $doa;

            $cust_demand = $demand[ $bp->ID ] ?? 0;
            $available   = $recv - $doa;
            $fill_label  = ( $cust_demand === 0 ) ? 'No Demand' : ( $available >= $cust_demand ? 'Filled' : 'Short' );
            $species     = FisHotel_Batch_Manager::resolve_common_name( $bp->ID, $bp->post_title );
            $hf_rows    .= '[tr][td]' . $species . '[/td][td]' . $ord . '[/td][td]' . $recv . '[/td][td]' . $doa . '[/td][td]' . $fill_label . '[/td][/tr]' . "\n";
        }

        $hf_post  = '[b]' . esc_html( $selected ) . ' — Arrival Report (' . $hf_arrival_fmt . ')[/b]' . "\n\n";
        $hf_post .= 'Total ordered: ' . $hf_total_ordered . ' | Received: ' . $hf_total_received . ' | DOA: ' . $hf_total_doa . "\n\n";
        $hf_post .= '[table]' . "\n";
        $hf_post .= '[tr][th]Species[/th][th]Ordered[/th][th]Received[/th][th]DOA[/th][th]Status[/th][/tr]' . "\n";
        $hf_post .= $hf_rows;
        $hf_post .= '[/table]' . "\n\n";
        $hf_post .= 'Quarantine ends: [b]' . $hf_qt_end . '[/b]';

        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:28px;">';
        echo '<h2 style="color:#b5a165;margin-top:0;font-size:1.2em;">HF Arrival Summary</h2>';
        echo '<button type="button" id="fh-gen-hf" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:8px 24px;font-size:13px;cursor:pointer;margin-bottom:12px;" onclick="document.getElementById(\'fh-hf-output\').style.display=\'block\';this.style.display=\'none\';">Generate HF Post</button>';
        echo '<textarea id="fh-hf-output" readonly style="display:none;width:100%;min-height:200px;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:6px;padding:12px;font-family:monospace;font-size:13px;resize:vertical;" onclick="this.select()">' . esc_textarea( $hf_post ) . '</textarea>';
        echo '</div>';
        endif; // arrival tab

        // ── Survival Tracker ───────────────────────────────────────────────
        if ( $tab === 'tracker' ) :
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
            echo '<td style="padding:8px;">' . esc_html( FisHotel_Batch_Manager::resolve_common_name( $bp->ID, $bp->post_title ) ) . '</td>';
            echo '<td style="padding:8px;">' . $badges . '</td>';
            echo '<td style="padding:8px;text-align:center;"><input type="number" name="survival[' . $bp->ID . ']" min="0" placeholder="—" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div style="margin-top:16px;text-align:right;">';
        echo '<button type="submit" style="background:#27ae60;color:#fff;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">Log Today\'s Counts</button>';
        echo '</div></form></div>';
        endif; // tracker tab

        // ── Graduation Headcount ─────────────────────────────────────────────
        if ( $tab === 'graduation' ) :
        $current_stage    = $statuses[ $selected ] ?? '';
        $grad_locked      = in_array( $current_stage, [ 'graduation', 'verification', 'draft', 'invoicing' ], true );
        $grad_has_entries = false;

        foreach ( $batch_fish as $bp ) {
            $recv = intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
            if ( $recv > 0 ) { $grad_has_entries = true; break; }
        }

        if ( $grad_has_entries ) {
            echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;">';
            echo '<h2 style="color:#b5a165;margin-top:0;font-size:1.2em;">Graduation Headcount</h2>';
            echo '<p style="color:#aaa;margin-top:0;font-size:13px;">Final confirmed counts after QT. These numbers lock when batch advances to graduation.</p>';

            if ( $grad_locked ) {
                echo '<p style="color:#e67e22;font-size:12px;margin-bottom:12px;">&#x1F512; Counts locked &mdash; batch has advanced to graduation stage. Edit batch status to unlock.</p>';
            }

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'fishotel_save_graduation_nonce' );
            echo '<input type="hidden" name="action" value="fishotel_save_graduation_data">';
            echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';

            echo '<table style="width:100%;border-collapse:collapse;">';
            echo '<thead><tr style="border-bottom:2px solid #444;text-align:left;">';
            echo '<th style="padding:8px;color:#b5a165;">Species</th>';
            echo '<th style="padding:8px;color:#b5a165;text-align:center;">Arrived Qty</th>';
            echo '<th style="padding:8px;color:#b5a165;text-align:center;">DOA</th>';
            echo '<th style="padding:8px;color:#b5a165;text-align:center;">Graduated Qty</th>';
            echo '</tr></thead><tbody>';

            foreach ( $batch_fish as $bp ) {
                $recv = intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
                if ( $recv <= 0 ) continue;
                $doa       = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
                $cq        = get_post_meta( $bp->ID, '_current_qty', true );
                $live_qty  = ( $cq !== '' && $cq !== false ) ? intval( $cq ) : ( $recv - $doa );
                $grad_qty  = get_post_meta( $bp->ID, '_graduation_qty', true );
                $default   = $live_qty;
                $grad_val  = ( $grad_qty !== '' && $grad_qty !== false ) ? intval( $grad_qty ) : $default;
                $common    = FisHotel_Batch_Manager::resolve_common_name( $bp->ID, $bp->post_title );
                $disabled  = $grad_locked ? ' disabled' : '';

                echo '<tr style="border-bottom:1px solid #333;">';
                echo '<td style="padding:8px;">' . esc_html( $common ) . '</td>';
                echo '<td style="padding:8px;text-align:center;color:#aaa;">' . $recv . '</td>';
                echo '<td style="padding:8px;text-align:center;color:#aaa;">' . $doa . '</td>';
                echo '<td style="padding:8px;text-align:center;">';
                echo '<input type="number" name="graduation[' . $bp->ID . ']" value="' . $grad_val . '" min="0" style="width:70px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;"' . $disabled . '>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            if ( ! $grad_locked ) {
                echo '<div style="margin-top:16px;text-align:right;">';
                echo '<button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">Save Graduation Counts</button>';
                echo '</div>';
            }
            echo '</form></div>';

            // ── HF Graduation Summary ────────────────────────────────────────
            $hf_grad_date = $hf_arrival_date ? date( 'F j, Y', strtotime( $hf_arrival_date . ' +14 days' ) ) : 'TBD';
            $hf_grad_rows    = '';
            $hf_grad_total   = 0;
            $hf_grad_species = 0;

            foreach ( $batch_fish as $bp ) {
                $recv = intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
                if ( $recv <= 0 ) continue;
                $doa      = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
                $gq       = get_post_meta( $bp->ID, '_graduation_qty', true );
                $grad_val = ( $gq !== '' && $gq !== false ) ? intval( $gq ) : ( $recv - $doa );
                $lost     = $recv - $doa - $grad_val;
                $surv_pct = $recv > 0 ? round( $grad_val / $recv * 100 ) : 0;
                $species  = FisHotel_Batch_Manager::resolve_common_name( $bp->ID, $bp->post_title );

                $hf_grad_rows .= '[tr][td]' . $species . '[/td][td]' . $recv . '[/td][td]' . $grad_val . '[/td][td]' . $lost . '[/td][td]' . $surv_pct . '%[/td][/tr]' . "\n";
                $hf_grad_total += $grad_val;
                $hf_grad_species++;
            }

            $hf_grad  = '[b]' . esc_html( $selected ) . ' — Graduation Report (' . $hf_grad_date . ')[/b]' . "\n\n";
            $hf_grad .= 'Quarantine complete! Here\'s the final headcount.' . "\n\n";
            $hf_grad .= '[table]' . "\n";
            $hf_grad .= '[tr][th]Species[/th][th]Arrived[/th][th]Graduated[/th][th]Lost in QT[/th][th]Survival[/th][/tr]' . "\n";
            $hf_grad .= $hf_grad_rows;
            $hf_grad .= '[/table]' . "\n\n";
            $hf_grad .= 'Total graduated: ' . $hf_grad_total . ' fish across ' . $hf_grad_species . ' species.' . "\n\n";
            $hf_grad .= 'Stage 5 (Accept or Pass) opening soon — watch for your notification!';

            echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-top:28px;">';
            echo '<h2 style="color:#b5a165;margin-top:0;font-size:1.2em;">HF Graduation Summary</h2>';
            echo '<button type="button" id="fh-gen-hf-grad" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:8px 24px;font-size:13px;cursor:pointer;margin-bottom:12px;" onclick="document.getElementById(\'fh-hf-grad-output\').style.display=\'block\';this.style.display=\'none\';">Generate HF Post</button>';
            echo '<textarea id="fh-hf-grad-output" readonly style="display:none;width:100%;min-height:200px;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:6px;padding:12px;font-family:monospace;font-size:13px;resize:vertical;" onclick="this.select()">' . esc_textarea( $hf_grad ) . '</textarea>';
            echo '</div>';
        }
        endif; // graduation tab

        // ── Last Call ───────────────────────────────────────────────────────────
        if ( $tab === 'lastcall' ) :
        $current_stage    = $statuses[ $selected ] ?? '';
        $lc_slug          = sanitize_title( $selected );
        $lc_pool          = get_option( 'fishotel_lastcall_pool_' . $lc_slug, [] );
        $lc_order         = get_option( 'fishotel_lastcall_order_' . $lc_slug, [] );
        $lc_opened        = intval( get_option( 'fishotel_lastcall_opened_at_' . $lc_slug, 0 ) );
        $lc_deadline      = intval( get_option( 'fishotel_lastcall_deadline_' . $lc_slug, 0 ) );
        $lc_results       = get_option( 'fishotel_lastcall_results_' . $lc_slug, [] );
        $lc_rounds_val    = intval( get_option( 'fishotel_lastcall_rounds', 2 ) );
        $lc_now           = time();
        $lc_is_open       = $current_stage === 'draft' && $lc_opened;
        $lc_window_closed = $lc_is_open && $lc_deadline && $lc_now > $lc_deadline;
        $lc_has_results   = ! empty( $lc_results );
        $lc_queue         = get_option( 'fishotel_verification_queue_' . $lc_slug, [] );
        $admin_post_url   = esc_url( admin_url( 'admin-post.php' ) );

        // Compute accepted totals per user from verification queue (for "zero fish" badge)
        $lc_user_accepted = [];
        if ( ! empty( $lc_queue['species'] ) ) {
            foreach ( $lc_queue['species'] as $sp ) {
                foreach ( $sp['queue'] as $entry ) {
                    $uid = intval( $entry['user_id'] ?? 0 );
                    if ( ! isset( $lc_user_accepted[ $uid ] ) ) $lc_user_accepted[ $uid ] = 0;
                    if ( ( $entry['status'] ?? '' ) === 'accepted' ) {
                        $lc_user_accepted[ $uid ] += intval( $entry['accepted_qty'] ?? 0 );
                    }
                }
            }
        }

        // ── NOT YET OPEN — show Open button ──
        if ( ! $lc_is_open ) {
            $can_open     = in_array( $current_stage, [ 'verification', 'graduation' ], true );
            $queue_exists = ! empty( $lc_queue );

            echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;">';
            echo '<h2 style="color:#b5a165;margin-top:0;font-size:1.2em;">Last Call — Draft Pool</h2>';
            echo '<p style="color:#aaa;font-size:13px;margin-bottom:16px;">Opens the Last Call draft pool. Builds the available fish pool from verification leftovers and creates the draft order.</p>';

            if ( ! $queue_exists ) {
                echo '<p style="color:#e74c3c;font-size:12px;">No verification queue found. Run verification first.</p>';
            } elseif ( ! $can_open ) {
                echo '<p style="color:#e67e22;font-size:12px;">Batch must be at verification or graduation stage. Current: <strong>' . esc_html( $current_stage ) . '</strong></p>';
            }

            echo '<form method="post" action="' . $admin_post_url . '">';
            wp_nonce_field( 'fishotel_open_lastcall_nonce' );
            echo '<input type="hidden" name="action" value="fishotel_open_lastcall">';
            echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
            $disabled = ( ! $can_open || ! $queue_exists ) ? ' disabled' : '';
            echo '<button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;"' . $disabled . '>Open Last Call</button>';
            echo '</form></div>';

        } else {
        // ── LAST CALL IS OPEN — full control panel ──

        // Status badge
        if ( $lc_has_results ) {
            $badge = '<span style="background:#27ae60;color:#fff;padding:3px 12px;border-radius:4px;font-size:12px;font-weight:700;">DRAFT COMPLETE</span>';
        } elseif ( $lc_window_closed ) {
            $badge = '<span style="background:#e67e22;color:#000;padding:3px 12px;border-radius:4px;font-size:12px;font-weight:700;">CLOSED</span>';
        } else {
            $badge = '<span style="background:#27ae60;color:#fff;padding:3px 12px;border-radius:4px;font-size:12px;font-weight:700;">OPEN</span>';
        }

        // ── Section 1: Status & Settings ──
        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:16px;">';
        echo '<h2 style="color:#b5a165;margin-top:0;font-size:1.2em;">Last Call — Status ' . $badge . '</h2>';
        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<tr style="border-bottom:1px solid #333;"><td style="padding:8px;color:#aaa;width:160px;">Opened</td><td style="padding:8px;color:#ddd;">' . ( $lc_opened ? date( 'F j, Y g:i A', $lc_opened ) : 'N/A' ) . '</td></tr>';
        echo '</table>';

        // Editable deadline
        echo '<form method="post" action="' . $admin_post_url . '" style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
        wp_nonce_field( 'fishotel_lc_update_settings_nonce' );
        echo '<input type="hidden" name="action" value="fishotel_lc_update_settings">';
        echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
        echo '<label style="color:#aaa;font-size:13px;">Deadline:</label>';
        $dl_val = $lc_deadline ? date( 'Y-m-d\TH:i', $lc_deadline ) : '';
        echo '<input type="datetime-local" name="lc_deadline" value="' . esc_attr( $dl_val ) . '" style="padding:5px 8px;border-radius:4px;border:1px solid #555;background:#2a2a2a;color:#fff;">';
        echo '<label style="color:#aaa;font-size:13px;margin-left:12px;">Rounds:</label>';
        echo '<input type="number" name="lc_rounds" value="' . esc_attr( $lc_rounds_val ) . '" min="1" max="10" style="width:60px;padding:5px 8px;border-radius:4px;border:1px solid #555;background:#2a2a2a;color:#fff;">';
        echo '<button type="submit" style="background:#444;color:#ddd;border:1px solid #666;border-radius:4px;padding:6px 16px;font-size:12px;cursor:pointer;">Save</button>';
        echo '</form>';
        echo '</div>';

        // ── Section 2: The Pool ──
        if ( ! $lc_has_results ) {
        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:16px;">';
        echo '<h3 style="color:#b5a165;margin-top:0;font-size:1.1em;">The Pool</h3>';

        if ( ! empty( $lc_pool ) ) {
            echo '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">';
            echo '<thead><tr style="border-bottom:2px solid #444;">';
            echo '<th style="padding:8px;color:#b5a165;text-align:left;">Species</th>';
            echo '<th style="padding:8px;color:#b5a165;text-align:center;">Qty</th>';
            echo '<th style="padding:8px;color:#b5a165;text-align:center;">Update</th>';
            echo '<th style="padding:8px;color:#b5a165;text-align:center;">Remove</th>';
            echo '</tr></thead><tbody>';
            foreach ( $lc_pool as $idx => $item ) {
                echo '<tr style="border-bottom:1px solid #333;">';
                echo '<td style="padding:8px;color:#ddd;">' . esc_html( $item['name'] ) . '</td>';
                echo '<td style="padding:4px 8px;text-align:center;">';
                echo '<form method="post" action="' . $admin_post_url . '" style="display:inline-flex;align-items:center;gap:6px;margin:0;">';
                wp_nonce_field( 'fishotel_lc_pool_update_nonce' );
                echo '<input type="hidden" name="action" value="fishotel_lc_pool_update">';
                echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
                echo '<input type="hidden" name="pool_idx" value="' . $idx . '">';
                echo '<input type="number" name="pool_qty" value="' . intval( $item['pool_qty'] ) . '" min="0" style="width:60px;text-align:center;background:#2a2a2a;border:1px solid #555;color:#fff;border-radius:4px;padding:4px;">';
                echo '</td><td style="padding:4px 8px;text-align:center;">';
                echo '<button type="submit" style="background:#444;color:#ddd;border:1px solid #666;border-radius:4px;padding:4px 12px;font-size:11px;cursor:pointer;">Save</button>';
                echo '</form>';
                echo '</td>';
                echo '<td style="padding:4px 8px;text-align:center;">';
                echo '<form method="post" action="' . $admin_post_url . '" style="display:inline;margin:0;" onsubmit="return confirm(\'Remove ' . esc_attr( $item['name'] ) . ' from pool?\');">';
                wp_nonce_field( 'fishotel_lc_pool_remove_nonce' );
                echo '<input type="hidden" name="action" value="fishotel_lc_pool_remove">';
                echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
                echo '<input type="hidden" name="pool_idx" value="' . $idx . '">';
                echo '<button type="submit" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:14px;" title="Remove">&times;</button>';
                echo '</form></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#888;font-size:12px;">Pool is empty.</p>';
        }

        // Add species to pool
        $pool_fish_ids = array_column( $lc_pool, 'fish_id' );
        $available_to_add = [];
        foreach ( $batch_fish as $bp ) {
            if ( ! in_array( $bp->ID, $pool_fish_ids ) ) {
                $common = FisHotel_Batch_Manager::resolve_common_name( $bp->ID, $bp->post_title );
                $available_to_add[] = [ 'id' => $bp->ID, 'name' => $common ];
            }
        }
        if ( ! empty( $available_to_add ) ) {
            echo '<form method="post" action="' . $admin_post_url . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">';
            wp_nonce_field( 'fishotel_lc_pool_add_nonce' );
            echo '<input type="hidden" name="action" value="fishotel_lc_pool_add">';
            echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
            echo '<select name="add_fish_id" style="flex:1;min-width:180px;padding:6px;border:1px solid #555;background:#2a2a2a;color:#fff;border-radius:4px;">';
            echo '<option value="">+ Add species to pool...</option>';
            foreach ( $available_to_add as $af ) {
                echo '<option value="' . esc_attr( $af['id'] ) . '">' . esc_html( $af['name'] ) . '</option>';
            }
            echo '</select>';
            echo '<input type="number" name="add_qty" value="1" min="1" style="width:60px;padding:6px;border:1px solid #555;background:#2a2a2a;color:#fff;border-radius:4px;text-align:center;">';
            echo '<button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:7px 18px;font-size:12px;cursor:pointer;">Add</button>';
            echo '</form>';
        }
        echo '</div>';
        } // end pool section (not shown when results exist)

        // ── Section 3: Draft Order ──
        if ( ! $lc_has_results && ! empty( $lc_order ) ) {
        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:16px;">';
        echo '<h3 style="color:#b5a165;margin-top:0;font-size:1.1em;">Draft Order <span style="color:#888;font-size:0.8em;">(' . count( $lc_order ) . ' customers)</span></h3>';
        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr style="border-bottom:2px solid #444;">';
        echo '<th style="padding:6px 8px;color:#b5a165;text-align:center;width:40px;">#</th>';
        echo '<th style="padding:6px 8px;color:#b5a165;text-align:left;">Customer</th>';
        echo '<th style="padding:6px 8px;color:#b5a165;text-align:left;">HF Username</th>';
        echo '<th style="padding:6px 8px;color:#b5a165;text-align:center;width:100px;">Move</th>';
        echo '</tr></thead><tbody>';
        foreach ( $lc_order as $pos => $uid ) {
            $u       = get_user_by( 'id', $uid );
            $display = $u ? $u->display_name : 'User #' . $uid;
            $hf_name = get_user_meta( $uid, '_fishotel_humble_username', true );
            $zero    = ( isset( $lc_user_accepted[ $uid ] ) && $lc_user_accepted[ $uid ] <= 0 );
            $zero_badge = $zero ? ' <span style="background:#e74c3c;color:#fff;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:700;">ZERO FISH</span>' : '';

            echo '<tr style="border-bottom:1px solid #333;">';
            echo '<td style="padding:6px 8px;text-align:center;color:#888;">' . ( $pos + 1 ) . '</td>';
            echo '<td style="padding:6px 8px;color:#ddd;">' . esc_html( $display ) . $zero_badge . '</td>';
            echo '<td style="padding:6px 8px;color:#aaa;">' . esc_html( $hf_name ?: '—' ) . '</td>';
            echo '<td style="padding:6px 8px;text-align:center;">';
            if ( $pos > 0 ) {
                echo '<form method="post" action="' . $admin_post_url . '" style="display:inline;margin:0;">';
                wp_nonce_field( 'fishotel_lc_order_move_nonce' );
                echo '<input type="hidden" name="action" value="fishotel_lc_order_move">';
                echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
                echo '<input type="hidden" name="pos" value="' . $pos . '">';
                echo '<input type="hidden" name="dir" value="up">';
                echo '<button type="submit" style="background:none;border:none;color:#888;cursor:pointer;font-size:14px;" title="Move up">&uarr;</button>';
                echo '</form>';
            }
            if ( $pos < count( $lc_order ) - 1 ) {
                echo '<form method="post" action="' . $admin_post_url . '" style="display:inline;margin:0;">';
                wp_nonce_field( 'fishotel_lc_order_move_nonce' );
                echo '<input type="hidden" name="action" value="fishotel_lc_order_move">';
                echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
                echo '<input type="hidden" name="pos" value="' . $pos . '">';
                echo '<input type="hidden" name="dir" value="down">';
                echo '<button type="submit" style="background:none;border:none;color:#888;cursor:pointer;font-size:14px;" title="Move down">&darr;</button>';
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        } // end draft order section

        // ── Section 4: Wishlists ──
        if ( ! $lc_has_results && ! empty( $lc_order ) ) {
        // Build pool options JSON for the JS editor
        $pool_options_json = wp_json_encode( array_map( function( $p ) {
            return [ 'id' => $p['fish_id'], 'name' => $p['name'] ];
        }, $lc_pool ) );

        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:16px;">';
        echo '<h3 style="color:#b5a165;margin-top:0;font-size:1.1em;">Wishlists</h3>';
        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr style="border-bottom:2px solid #444;">';
        echo '<th style="padding:6px 8px;color:#b5a165;text-align:left;">Customer</th>';
        echo '<th style="padding:6px 8px;color:#b5a165;text-align:center;">Items</th>';
        echo '<th style="padding:6px 8px;color:#b5a165;text-align:center;">Saved</th>';
        echo '</tr></thead><tbody>';
        $wl_idx = 0;
        foreach ( $lc_order as $uid ) {
            $u       = get_user_by( 'id', $uid );
            $display = $u ? $u->display_name : 'User #' . $uid;
            $wl      = get_option( 'fishotel_lastcall_wishlist_' . $lc_slug . '_' . $uid, [] );
            $count   = count( $wl );
            $saved   = $count > 0 ? 'Yes' : '—';

            echo '<tr style="border-bottom:1px solid #333;cursor:pointer;" onclick="var d=document.getElementById(\'fh-wl-detail-' . $wl_idx . '\');d.style.display=d.style.display===\'none\'?\'table-row\':\'none\';">';
            echo '<td style="padding:6px 8px;color:#ddd;">' . esc_html( $display ) . ' <span style="color:#666;font-size:11px;">&#x25BC;</span></td>';
            echo '<td style="padding:6px 8px;text-align:center;color:' . ( $count > 0 ? '#ddd' : '#666' ) . ';">' . $count . '</td>';
            echo '<td style="padding:6px 8px;text-align:center;color:' . ( $count > 0 ? '#27ae60' : '#666' ) . ';">' . $saved . '</td>';
            echo '</tr>';

            // Expandable detail row
            echo '<tr id="fh-wl-detail-' . $wl_idx . '" style="display:none;background:#181818;">';
            echo '<td colspan="3" style="padding:8px 20px;">';

            // Read-only view
            echo '<div id="fh-wl-view-' . $wl_idx . '">';
            if ( empty( $wl ) ) {
                echo '<span style="color:#666;font-size:12px;">No wishlist submitted.</span>';
            } else {
                echo '<ol style="margin:4px 0;padding-left:20px;color:#aaa;font-size:12px;">';
                foreach ( $wl as $wi ) {
                    $fish_name = '';
                    foreach ( $lc_pool as $p ) {
                        if ( intval( $p['fish_id'] ) === intval( $wi['fish_id'] ) ) { $fish_name = $p['name']; break; }
                    }
                    if ( ! $fish_name ) $fish_name = 'Fish #' . $wi['fish_id'];
                    $alt_label = ! empty( $wi['is_alternative_to'] ) ? ' <span style="color:#e67e22;font-size:10px;">(alt)</span>' : '';
                    echo '<li style="margin:2px 0;">' . esc_html( $fish_name ) . $alt_label . '</li>';
                }
                echo '</ol>';
            }
            echo '<button type="button" style="margin-top:8px;background:#444;color:#ddd;border:1px solid #666;border-radius:4px;padding:5px 14px;font-size:11px;cursor:pointer;" onclick="fhWlEdit(' . $wl_idx . ',' . $uid . ')">Edit Wishlist</button>';
            echo '</div>';

            // Edit form (hidden initially)
            echo '<div id="fh-wl-edit-' . $wl_idx . '" style="display:none;">';
            echo '<form method="post" action="' . $admin_post_url . '" id="fh-wl-form-' . $wl_idx . '">';
            wp_nonce_field( 'fishotel_save_admin_wishlist_nonce' );
            echo '<input type="hidden" name="action" value="fishotel_save_admin_wishlist">';
            echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
            echo '<input type="hidden" name="target_user_id" value="' . esc_attr( $uid ) . '">';
            echo '<input type="hidden" name="wishlist_json" id="fh-wl-json-' . $wl_idx . '" value="">';

            echo '<div id="fh-wl-rows-' . $wl_idx . '" style="margin-bottom:8px;">';
            // Rows populated by JS
            echo '</div>';

            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">';
            echo '<button type="button" style="background:#333;color:#c9a84c;border:1px solid #555;border-radius:4px;padding:5px 14px;font-size:11px;cursor:pointer;" onclick="fhWlAddRow(' . $wl_idx . ')">+ Add Row</button>';
            echo '<button type="submit" style="background:#27ae60;color:#fff;border:none;border-radius:4px;padding:5px 18px;font-size:12px;font-weight:700;cursor:pointer;">Save Wishlist</button>';
            echo '<button type="button" style="background:#333;color:#e74c3c;border:1px solid #e74c3c;border-radius:4px;padding:5px 14px;font-size:11px;cursor:pointer;" onclick="fhWlClear(' . $wl_idx . ')">Clear Wishlist</button>';
            echo '<button type="button" style="background:none;border:none;color:#888;font-size:11px;cursor:pointer;text-decoration:underline;" onclick="document.getElementById(\'fh-wl-edit-' . $wl_idx . '\').style.display=\'none\';document.getElementById(\'fh-wl-view-' . $wl_idx . '\').style.display=\'block\';">Cancel</button>';
            echo '</div>';
            echo '</form>';
            echo '</div>';

            echo '</td></tr>';
            $wl_idx++;
        }
        echo '</tbody></table>';
        echo '</div>';

        // Wishlist editor JS
        ?>
        <script>
        (function(){
            var poolOpts = <?php echo $pool_options_json; ?>;
            var wishlistData = {};
            <?php
            // Preload all wishlists into JS
            foreach ( $lc_order as $idx => $uid ) {
                $wl = get_option( 'fishotel_lastcall_wishlist_' . $lc_slug . '_' . $uid, [] );
                echo 'wishlistData[' . $idx . '] = ' . wp_json_encode( $wl ) . ";\n";
            }
            ?>

            function buildSelect(selectedId) {
                var html = '<select name="wl_fish" style="min-width:140px;padding:4px 6px;border:1px solid #555;background:#2a2a2a;color:#fff;border-radius:4px;font-size:11px;">';
                html += '<option value="">Select species...</option>';
                poolOpts.forEach(function(p) {
                    html += '<option value="' + p.id + '"' + (parseInt(p.id) === parseInt(selectedId) ? ' selected' : '') + '>' + escH(p.name) + '</option>';
                });
                html += '</select>';
                return html;
            }

            function escH(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            function renderRows(idx) {
                var container = document.getElementById('fh-wl-rows-' + idx);
                var items = wishlistData[idx] || [];
                var html = '';
                items.forEach(function(item, i) {
                    var isFirst = i === 0;
                    html += '<div class="fh-wl-erow" data-idx="' + i + '" style="display:flex;align-items:center;gap:6px;padding:4px 0;border-bottom:1px solid #222;" draggable="true">';
                    html += '<span style="cursor:grab;color:#555;font-size:13px;">&#x2630;</span>';
                    html += '<span style="color:#888;font-size:11px;width:18px;text-align:center;">' + (i + 1) + '</span>';
                    html += buildSelect(item.fish_id);
                    html += '<label style="font-size:10px;color:#888;white-space:nowrap;' + (isFirst ? 'visibility:hidden;' : '') + '"><input type="checkbox" class="fh-wl-alt"' + (item.is_alternative_to ? ' checked' : '') + ' style="cursor:pointer;"> alt</label>';
                    html += '<button type="button" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:14px;padding:0 4px;" onclick="fhWlRemoveRow(' + idx + ',' + i + ')">&times;</button>';
                    html += '</div>';
                });
                container.innerHTML = html;
                bindDrag(idx);
            }

            window.fhWlEdit = function(idx, uid) {
                document.getElementById('fh-wl-view-' + idx).style.display = 'none';
                document.getElementById('fh-wl-edit-' + idx).style.display = 'block';
                renderRows(idx);
            };

            window.fhWlAddRow = function(idx) {
                if (!wishlistData[idx]) wishlistData[idx] = [];
                wishlistData[idx].push({ fish_id: 0, rank: wishlistData[idx].length + 1, is_alternative_to: null });
                renderRows(idx);
            };

            window.fhWlRemoveRow = function(idx, rowIdx) {
                wishlistData[idx].splice(rowIdx, 1);
                renderRows(idx);
            };

            window.fhWlClear = function(idx) {
                if (!confirm('Clear entire wishlist for this customer?')) return;
                wishlistData[idx] = [];
                renderRows(idx);
            };

            // Serialize on form submit
            document.querySelectorAll('[id^="fh-wl-form-"]').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    var idxMatch = form.id.match(/fh-wl-form-(\d+)/);
                    if (!idxMatch) return;
                    var idx = parseInt(idxMatch[1], 10);
                    var rows = form.querySelectorAll('.fh-wl-erow');
                    var items = [];
                    var prevFishId = null;
                    rows.forEach(function(row, i) {
                        var sel = row.querySelector('select[name="wl_fish"]');
                        var altCb = row.querySelector('.fh-wl-alt');
                        var fishId = sel ? parseInt(sel.value, 10) : 0;
                        if (!fishId) return;
                        var isAlt = altCb && altCb.checked && prevFishId ? prevFishId : null;
                        items.push({ fish_id: fishId, rank: i + 1, is_alternative_to: isAlt });
                        prevFishId = fishId;
                    });
                    document.getElementById('fh-wl-json-' + idx).value = JSON.stringify(items);
                });
            });

            // Drag and drop within edit rows
            function bindDrag(idx) {
                var container = document.getElementById('fh-wl-rows-' + idx);
                var dragEl = null;
                container.querySelectorAll('.fh-wl-erow').forEach(function(row) {
                    row.addEventListener('dragstart', function(e) {
                        dragEl = row;
                        row.style.opacity = '0.4';
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    row.addEventListener('dragend', function() {
                        row.style.opacity = '1';
                        dragEl = null;
                        // Sync data from DOM order
                        var rows = container.querySelectorAll('.fh-wl-erow');
                        var newData = [];
                        rows.forEach(function(r) {
                            var sel = r.querySelector('select[name="wl_fish"]');
                            var altCb = r.querySelector('.fh-wl-alt');
                            newData.push({
                                fish_id: sel ? parseInt(sel.value, 10) || 0 : 0,
                                rank: newData.length + 1,
                                is_alternative_to: altCb && altCb.checked ? 1 : null
                            });
                        });
                        wishlistData[idx] = newData;
                        renderRows(idx);
                    });
                    row.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        row.style.borderTop = '2px solid #c9a84c';
                    });
                    row.addEventListener('dragleave', function() {
                        row.style.borderTop = '';
                    });
                    row.addEventListener('drop', function(e) {
                        e.preventDefault();
                        row.style.borderTop = '';
                        if (dragEl && dragEl !== row) {
                            container.insertBefore(dragEl, row);
                        }
                    });
                });
            }
        })();
        </script>
        <?php
        } // end wishlists section

        // ── Section 5: Actions ──
        echo '<div style="background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:24px;margin-bottom:16px;">';
        echo '<h3 style="color:#b5a165;margin-top:0;font-size:1.1em;">Actions</h3>';

        if ( ! $lc_window_closed && ! $lc_has_results ) {
            // Window open — close early button
            echo '<form method="post" action="' . $admin_post_url . '" style="margin:0;" onsubmit="return confirm(\'Close the wishlist window early? Customers will no longer be able to submit.\');">';
            wp_nonce_field( 'fishotel_lc_close_window_nonce' );
            echo '<input type="hidden" name="action" value="fishotel_lc_close_window">';
            echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
            echo '<button type="submit" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 24px;font-size:13px;cursor:pointer;">Close Window Early</button>';
            echo '</form>';

        } elseif ( $lc_window_closed && ! $lc_has_results ) {
            // Window closed, no results — run draft with roulette reveal
            $draft_nonce = wp_create_nonce( 'fishotel_lastcall_draft_nonce' );
            $wheel_url   = plugins_url( 'assists/casino/Roulette-Wheel.png', FISHOTEL_PLUGIN_FILE );
            ?>
            <style>
            .fhlc-roulette-container{position:relative;padding:20px 0;text-align:center;}
            .fhlc-roulette-status{font-family:Oswald,sans-serif;font-size:22px;color:#FFD700;margin-bottom:16px;text-transform:uppercase;letter-spacing:1px;}
            .fhlc-roulette-wheel-wrap{position:relative;width:500px;height:500px;margin:0 auto;}
            .fhlc-wheel-img{width:100%;height:100%;transform-origin:center;transition:transform 0s;}
            .fhlc-wheel-overlay{position:absolute;top:0;left:0;width:100%;height:100%;transform-origin:center;transition:transform 0s;}
            .fhlc-segment-text{position:absolute;top:50%;left:50%;margin-top:-7px;transform-origin:0 50%;font-family:Georgia,serif;font-size:13px;font-weight:600;white-space:nowrap;pointer-events:none;text-shadow:0 1px 3px rgba(0,0,0,0.5);}
            .fhlc-segment-text.fhlc-winning{animation:fhlcPulseGold 1s ease-in-out 3;}
            @keyframes fhlcPulseGold{0%,100%{text-shadow:0 1px 2px rgba(0,0,0,0.3);}50%{text-shadow:0 0 20px #FFD700,0 0 30px #FFD700;}}
            .fhlc-ball{position:absolute;width:16px;height:16px;border-radius:50%;background:radial-gradient(circle at 30% 30%,#ffffff,#e0e0e0);box-shadow:0 2px 8px rgba(0,0,0,0.4),inset 0 1px 3px rgba(255,255,255,0.5);top:50%;left:50%;margin-top:-8px;margin-left:-8px;transform-origin:8px 8px;opacity:0;z-index:10;}
            .fhlc-ball.fhlc-ball-dropped{animation:fhlcBallDrop 0.4s ease-out forwards;}
            .fhlc-pointer{position:absolute;top:-10px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-top:20px solid #FFD700;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.5));z-index:20;}
            .fhlc-running-log{margin-top:24px;background:#111;border:1px solid #333;padding:16px;max-height:300px;overflow-y:auto;font-family:'Courier New',monospace;font-size:12px;color:#aaa;border-radius:4px;text-align:left;max-width:600px;margin-left:auto;margin-right:auto;}
            .fhlc-running-log>div{padding:3px 0;border-bottom:1px solid rgba(212,201,168,0.15);}
            </style>
            <div class="fh-draft-control-panel">
                <p style="color:#e67e22;font-size:13px;margin-bottom:12px;">Wishlist window has closed. Ready to run the draft.</p>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="button" id="fh-run-draft-btn" style="background:#e67e22;color:#000;font-weight:700;border:none;border-radius:6px;padding:10px 32px;font-size:14px;cursor:pointer;">&#x25B6; Run Draft</button>
                    <span id="fh-draft-status" style="font-size:12px;color:#aaa;"></span>
                </div>
                <div id="fh-reveal-controls" style="display:none;margin-top:16px;align-items:center;gap:12px;flex-wrap:wrap;">
                    <button type="button" id="fh-skip-reveal-btn" style="background:#444;color:#ddd;border:1px solid #666;border-radius:4px;padding:6px 16px;font-size:12px;cursor:pointer;">&#x23E9; Skip to Results</button>
                    <button type="button" id="fh-replay-draft-btn" style="background:#444;color:#ddd;border:1px solid #666;border-radius:4px;padding:6px 16px;font-size:12px;cursor:pointer;">&#x1F504; Replay</button>
                    <label style="color:#aaa;font-size:12px;">Speed:
                        <select id="fh-reveal-speed" style="padding:4px 6px;border:1px solid #555;background:#2a2a2a;color:#fff;border-radius:4px;font-size:11px;">
                            <option value="4">Slow (4s)</option>
                            <option value="2.5" selected>Normal (2.5s)</option>
                            <option value="1.2">Fast (1.2s)</option>
                        </select>
                    </label>
                </div>
            </div>

            <div id="fh-reveal-container" style="display:none;margin:32px 0;">
                <div class="fhlc-roulette-container">
                    <div class="fhlc-roulette-status" id="fh-roulette-status"></div>
                    <div class="fhlc-roulette-wheel-wrap">
                        <div class="fhlc-pointer"></div>
                        <img src="<?php echo esc_url( $wheel_url ); ?>" class="fhlc-wheel-img" id="fh-wheel-img" alt="Roulette Wheel">
                        <div class="fhlc-wheel-overlay" id="fh-wheel-overlay"></div>
                        <div class="fhlc-ball" id="fh-ball"></div>
                    </div>
                    <div class="fhlc-running-log" id="fh-reveal-log"></div>
                </div>
            </div>

            <div id="fh-results-container" style="display:none;margin:32px 0;">
                <h4 style="color:#27ae60;font-size:1em;margin:0 0 8px;">Draft Results</h4>
                <p id="fh-results-meta" style="color:#aaa;font-size:12px;margin-bottom:8px;"></p>
                <table style="width:100%;border-collapse:collapse;margin-bottom:12px;">
                    <thead><tr style="border-bottom:2px solid #444;">
                        <th style="padding:6px 8px;color:#b5a165;text-align:center;">Rd</th>
                        <th style="padding:6px 8px;color:#b5a165;text-align:center;">Pick</th>
                        <th style="padding:6px 8px;color:#b5a165;text-align:left;">Customer</th>
                        <th style="padding:6px 8px;color:#b5a165;text-align:left;">Fish</th>
                        <th style="padding:6px 8px;color:#b5a165;text-align:center;">Qty</th>
                    </tr></thead>
                    <tbody id="fh-results-tbody"></tbody>
                </table>
                <p style="color:#aaa;font-size:12px;">Page will reload in a moment to show full controls...</p>
            </div>

            <script>
            (function(){
                var ajaxUrl    = '<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>';
                var draftNonce = '<?php echo esc_js( $draft_nonce ); ?>';
                var batchName  = '<?php echo esc_js( $selected ); ?>';
                var currentPickIndex = 0, totalPicks = 0, spinSpeed = 2.5, isRevealing = false;
                var cumulativeRotation = 0;

                var runBtn      = document.getElementById('fh-run-draft-btn');
                var statusEl    = document.getElementById('fh-draft-status');
                var controls    = document.getElementById('fh-reveal-controls');
                var skipBtn     = document.getElementById('fh-skip-reveal-btn');
                var replayBtn   = document.getElementById('fh-replay-draft-btn');
                var speedSel    = document.getElementById('fh-reveal-speed');
                var revealWrap  = document.getElementById('fh-reveal-container');
                var logEl       = document.getElementById('fh-reveal-log');
                var resultsWrap = document.getElementById('fh-results-container');
                var wheelImg    = document.getElementById('fh-wheel-img');
                var wheelOvl    = document.getElementById('fh-wheel-overlay');
                var ball        = document.getElementById('fh-ball');
                var rouletteStatus = document.getElementById('fh-roulette-status');

                runBtn.addEventListener('click', function(){
                    if(!confirm('Run the Last Call draft? This cannot be undone.')) return;
                    runBtn.disabled = true;
                    statusEl.textContent = 'Running draft...';
                    statusEl.style.color = '#aaa';
                    var fd = new FormData();
                    fd.append('action', 'fishotel_run_lastcall_draft');
                    fd.append('nonce', draftNonce);
                    fd.append('batch_name', batchName);
                    fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if(d.success){
                                totalPicks = d.data.picks.length;
                                currentPickIndex = 0;
                                cumulativeRotation = 0;
                                statusEl.textContent = 'Draft complete (' + totalPicks + ' picks). Starting reveal...';
                                statusEl.style.color = '#27ae60';
                                runBtn.style.display = 'none';
                                controls.style.display = 'flex';
                                startReveal();
                            } else {
                                statusEl.textContent = 'Error: ' + (d.data && d.data.message || 'Unknown');
                                statusEl.style.color = '#e74c3c';
                                runBtn.disabled = false;
                            }
                        }).catch(function(){
                            statusEl.textContent = 'Network error';
                            statusEl.style.color = '#e74c3c';
                            runBtn.disabled = false;
                        });
                });

                function startReveal(){
                    isRevealing = true;
                    revealWrap.style.display = 'block';
                    logEl.innerHTML = '';
                    revealNextPick();
                }

                function buildWheelOverlay(fishNames){
                    wheelOvl.innerHTML = '';
                    var segAngle = 360 / 24;
                    for(var i = 0; i < 24; i++){
                        var div = document.createElement('div');
                        div.className = 'fhlc-segment-text fhlc-segment-' + (i+1);
                        var rot = i * segAngle + segAngle / 2 - 90;
                        div.style.transform = 'rotate(' + rot + 'deg) translateX(60px)';
                        div.style.color = (i % 2 === 0) ? '#f5f5f5' : '#2e2418';
                        div.textContent = fishNames[i] || '';
                        wheelOvl.appendChild(div);
                    }
                }

                function revealNextPick(){
                    if(currentPickIndex >= totalPicks || !isRevealing){
                        isRevealing = false;
                        showResults();
                        return;
                    }
                    var fd = new FormData();
                    fd.append('action', 'fishotel_get_lastcall_pick');
                    fd.append('nonce', draftNonce);
                    fd.append('batch_name', batchName);
                    fd.append('pick_index', currentPickIndex);
                    fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if(!d.success || !isRevealing) return;
                            var pick = d.data;
                            rouletteStatus.textContent = 'Round ' + pick.round + ', Pick ' + pick.pick_number + ' \u2014 ' + pick.customer_name + ' is up';

                            // Build wheel overlay with fish names
                            buildWheelOverlay(pick.wheel_fish);

                            // Reset wheel position instantly
                            wheelImg.style.transition = 'none';
                            wheelOvl.style.transition = 'none';
                            wheelImg.style.transform = 'rotate(' + cumulativeRotation + 'deg)';
                            wheelOvl.style.transform = 'rotate(' + cumulativeRotation + 'deg)';
                            ball.style.opacity = '0';
                            ball.style.transition = 'none';

                            // Calculate target: spin 3+ rotations, land winning segment at top (pointer)
                            var segAngle = 360 / 24;
                            var winAngle = (pick.wheel_segment - 1) * segAngle + segAngle / 2;
                            var spins = 3 + Math.random();
                            var targetRotation = cumulativeRotation + (spins * 360) + (360 - winAngle);
                            cumulativeRotation = targetRotation;

                            // Start spin after brief pause
                            setTimeout(function(){
                                if(!isRevealing) return;
                                wheelImg.style.transition = 'transform ' + spinSpeed + 's cubic-bezier(0.25,0.46,0.45,0.94)';
                                wheelOvl.style.transition = 'transform ' + spinSpeed + 's cubic-bezier(0.25,0.46,0.45,0.94)';
                                wheelImg.style.transform = 'rotate(' + targetRotation + 'deg)';
                                wheelOvl.style.transform = 'rotate(' + targetRotation + 'deg)';

                                // Show ball tracking to winning segment
                                ball.style.transition = 'transform ' + spinSpeed + 's cubic-bezier(0.25,0.46,0.45,0.94), opacity 0.3s';
                                ball.style.opacity = '1';
                                ball.style.transform = 'rotate(' + (360 - winAngle + spins * 360) + 'deg) translateY(-210px)';

                                // After spin completes — ball drops into segment
                                setTimeout(function(){
                                    if(!isRevealing) return;
                                    // Ball drop: shrink radius from 210px to 150px
                                    var ballAngle = 360 - winAngle + spins * 360;
                                    ball.style.transition = 'transform 0.4s cubic-bezier(0.22,1,0.36,1)';
                                    ball.style.transform = 'rotate(' + ballAngle + 'deg) translateY(-150px)';

                                    // Highlight winning segment
                                    var winEl = wheelOvl.querySelector('.fhlc-segment-' + pick.wheel_segment);
                                    if(winEl) winEl.classList.add('fhlc-winning');

                                    // Log entry
                                    var entry = document.createElement('div');
                                    entry.textContent = 'Pick ' + pick.pick_number + ': ' + pick.customer_name + ' \u2192 ' + pick.fish_name + ' \u00D7 ' + pick.qty;
                                    logEl.appendChild(entry);
                                    logEl.scrollTop = logEl.scrollHeight;

                                    // Brief pause then next pick
                                    setTimeout(function(){
                                        currentPickIndex++;
                                        if(currentPickIndex < totalPicks) ball.style.opacity = '0';
                                        revealNextPick();
                                    }, 1200);
                                }, spinSpeed * 1000 + 200);
                            }, 300);
                        });
                }

                skipBtn.addEventListener('click', function(){
                    isRevealing = false;
                    showResults();
                });

                replayBtn.addEventListener('click', function(){
                    currentPickIndex = 0;
                    cumulativeRotation = 0;
                    resultsWrap.style.display = 'none';
                    startReveal();
                });

                speedSel.addEventListener('change', function(){
                    spinSpeed = parseFloat(this.value);
                });

                function showResults(){
                    controls.style.display = 'flex';
                    var fd = new FormData();
                    fd.append('action', 'fishotel_get_lastcall_results');
                    fd.append('nonce', '<?php echo esc_js( wp_create_nonce( "fishotel_lastcall_nonce" ) ); ?>');
                    fd.append('batch_name', batchName);
                    fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if(!d.success) return;
                            var tbody = document.getElementById('fh-results-tbody');
                            tbody.innerHTML = '';
                            var picks = d.data.picks || [];
                            picks.forEach(function(pick, idx){
                                var name = pick.hf_username || pick.display_name || 'User #' + pick.user_id;
                                var tr = document.createElement('tr');
                                tr.style.borderBottom = '1px solid #333';
                                tr.innerHTML = '<td style="padding:6px 8px;text-align:center;color:#888;">' + pick.round + '</td>'
                                    + '<td style="padding:6px 8px;text-align:center;color:#888;">' + (idx+1) + '</td>'
                                    + '<td style="padding:6px 8px;color:#ddd;">' + name + '</td>'
                                    + '<td style="padding:6px 8px;color:#ddd;">' + pick.fish_name + '</td>'
                                    + '<td style="padding:6px 8px;text-align:center;color:#ddd;">' + pick.qty + '</td>';
                                tbody.appendChild(tr);
                            });
                            var meta = document.getElementById('fh-results-meta');
                            meta.textContent = picks.length + ' picks, ' + d.data.rounds + ' rounds';
                            resultsWrap.style.display = 'block';
                            setTimeout(function(){ location.reload(); }, 5000);
                        });
                }
            })();
            </script>
            <?php

        } elseif ( $lc_has_results ) {
            // Results exist — show table + action buttons
            $replay_nonce = wp_create_nonce( 'fishotel_lastcall_draft_nonce' );
            $wheel_url    = plugins_url( 'assists/casino/Roulette-Wheel.png', FISHOTEL_PLUGIN_FILE );
            echo '<h4 style="color:#27ae60;font-size:1em;margin:0 0 8px;">Draft Results</h4>';
            echo '<p style="color:#aaa;font-size:12px;margin-bottom:8px;">Ran ' . date( 'F j, Y g:i A', $lc_results['run_at'] ) . ' &mdash; ' . count( $lc_results['picks'] ) . ' picks, ' . $lc_results['rounds'] . ' rounds</p>';
            echo '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">';
            echo '<thead><tr style="border-bottom:2px solid #444;">';
            echo '<th style="padding:6px 8px;color:#b5a165;text-align:center;">Rd</th>';
            echo '<th style="padding:6px 8px;color:#b5a165;text-align:left;">Customer</th>';
            echo '<th style="padding:6px 8px;color:#b5a165;text-align:left;">Fish</th>';
            echo '<th style="padding:6px 8px;color:#b5a165;text-align:center;">Qty</th>';
            echo '</tr></thead><tbody>';
            foreach ( $lc_results['picks'] as $pick ) {
                $u       = get_user_by( 'id', $pick['user_id'] );
                $display = $u ? $u->display_name : 'User #' . $pick['user_id'];
                echo '<tr style="border-bottom:1px solid #333;">';
                echo '<td style="padding:6px 8px;text-align:center;color:#888;">' . intval( $pick['round'] ) . '</td>';
                echo '<td style="padding:6px 8px;color:#ddd;">' . esc_html( $display ) . '</td>';
                echo '<td style="padding:6px 8px;color:#ddd;">' . esc_html( $pick['fish_name'] ) . '</td>';
                echo '<td style="padding:6px 8px;text-align:center;color:#ddd;">' . intval( $pick['qty'] ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            $total_fish = array_sum( array_column( $lc_results['picks'], 'qty' ) );
            echo '<p style="color:#aaa;font-size:12px;"><strong>' . count( $lc_results['picks'] ) . '</strong> picks &mdash; <strong>' . $total_fish . '</strong> fish distributed</p>';

            echo '<div style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap;">';

            echo '<button type="button" id="fh-replay-results-btn" style="background:#444;color:#ddd;border:1px solid #666;border-radius:6px;padding:10px 24px;font-size:13px;cursor:pointer;">&#x1F504; Replay Roulette</button>';

            echo '<form method="post" action="' . $admin_post_url . '" style="margin:0;">';
            wp_nonce_field( 'fishotel_advance_stage_nonce' );
            echo '<input type="hidden" name="action" value="fishotel_advance_stage">';
            echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
            echo '<input type="hidden" name="new_stage" value="casino">';
            echo '<button type="submit" style="background:#96885f;color:#fff;font-weight:700;border:none;border-radius:6px;padding:10px 24px;font-size:13px;cursor:pointer;" onclick="return confirm(\'Close the draft and open Casino Night?\');">Close Draft &amp; Open Casino</button>';
            echo '</form>';

            echo '<form method="post" action="' . $admin_post_url . '" style="margin:0;" onsubmit="return confirm(\'Reset the draft? Clears all results and notifications.\');">';
            wp_nonce_field( 'fishotel_reset_lastcall_nonce' );
            echo '<input type="hidden" name="action" value="fishotel_reset_lastcall">';
            echo '<input type="hidden" name="batch_name" value="' . esc_attr( $selected ) . '">';
            echo '<button type="submit" style="background:#333;color:#e74c3c;font-weight:700;border:1px solid #e74c3c;border-radius:6px;padding:10px 24px;font-size:13px;cursor:pointer;">Reset Draft</button>';
            echo '</form>';
            echo '</div>';
            ?>
            <style>
            .fhlc-roulette-container{position:relative;padding:20px 0;text-align:center;}
            .fhlc-roulette-status{font-family:Oswald,sans-serif;font-size:22px;color:#FFD700;margin-bottom:16px;text-transform:uppercase;letter-spacing:1px;}
            .fhlc-roulette-wheel-wrap{position:relative;width:500px;height:500px;margin:0 auto;}
            .fhlc-wheel-img{width:100%;height:100%;transform-origin:center;transition:transform 0s;}
            .fhlc-wheel-overlay{position:absolute;top:0;left:0;width:100%;height:100%;transform-origin:center;transition:transform 0s;}
            .fhlc-segment-text{position:absolute;top:50%;left:50%;margin-top:-7px;transform-origin:0 50%;font-family:Georgia,serif;font-size:13px;font-weight:600;white-space:nowrap;pointer-events:none;text-shadow:0 1px 3px rgba(0,0,0,0.5);}
            .fhlc-segment-text.fhlc-winning{animation:fhlcPulseGold 1s ease-in-out 3;}
            @keyframes fhlcPulseGold{0%,100%{text-shadow:0 1px 2px rgba(0,0,0,0.3);}50%{text-shadow:0 0 20px #FFD700,0 0 30px #FFD700;}}
            .fhlc-ball{position:absolute;width:16px;height:16px;border-radius:50%;background:radial-gradient(circle at 30% 30%,#ffffff,#e0e0e0);box-shadow:0 2px 8px rgba(0,0,0,0.4),inset 0 1px 3px rgba(255,255,255,0.5);top:50%;left:50%;margin-top:-8px;margin-left:-8px;transform-origin:8px 8px;opacity:0;z-index:10;}
            .fhlc-ball.fhlc-ball-dropped{animation:fhlcBallDrop 0.4s ease-out forwards;}
            .fhlc-pointer{position:absolute;top:-10px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-top:20px solid #FFD700;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.5));z-index:20;}
            .fhlc-running-log{margin-top:24px;background:#111;border:1px solid #333;padding:16px;max-height:300px;overflow-y:auto;font-family:'Courier New',monospace;font-size:12px;color:#aaa;border-radius:4px;text-align:left;max-width:600px;margin-left:auto;margin-right:auto;}
            .fhlc-running-log>div{padding:3px 0;border-bottom:1px solid rgba(212,201,168,0.15);}
            </style>
            <div id="fh-replay-container" style="display:none;margin:32px 0;">
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
                    <button type="button" id="fh-replay-skip" style="background:#444;color:#ddd;border:1px solid #666;border-radius:4px;padding:6px 16px;font-size:12px;cursor:pointer;">&#x23E9; Skip</button>
                    <label style="color:#aaa;font-size:12px;">Speed:
                        <select id="fh-replay-speed" style="padding:4px 6px;border:1px solid #555;background:#2a2a2a;color:#fff;border-radius:4px;font-size:11px;">
                            <option value="4">Slow (4s)</option>
                            <option value="2.5" selected>Normal (2.5s)</option>
                            <option value="1.2">Fast (1.2s)</option>
                        </select>
                    </label>
                </div>
                <div class="fhlc-roulette-container">
                    <div class="fhlc-roulette-status" id="fh-rp-status"></div>
                    <div class="fhlc-roulette-wheel-wrap">
                        <div class="fhlc-pointer"></div>
                        <img src="<?php echo esc_url( $wheel_url ); ?>" class="fhlc-wheel-img" id="fh-rp-wheel-img" alt="Roulette Wheel">
                        <div class="fhlc-wheel-overlay" id="fh-rp-wheel-overlay"></div>
                        <div class="fhlc-ball" id="fh-rp-ball"></div>
                    </div>
                    <div class="fhlc-running-log" id="fh-rp-log"></div>
                </div>
            </div>
            <script>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>';
                var rpNonce = '<?php echo esc_js( $replay_nonce ); ?>';
                var batchName = '<?php echo esc_js( $selected ); ?>';
                var totalPicks = <?php echo count( $lc_results['picks'] ); ?>;
                var rpIdx = 0, rpSpeed = 2.5, rpRunning = false, rpCumRot = 0;
                var rpWrap = document.getElementById('fh-replay-container');
                var rpWheelImg = document.getElementById('fh-rp-wheel-img');
                var rpWheelOvl = document.getElementById('fh-rp-wheel-overlay');
                var rpBall = document.getElementById('fh-rp-ball');
                var rpLog  = document.getElementById('fh-rp-log');
                var rpStatus = document.getElementById('fh-rp-status');

                function rpBuildOverlay(fishNames){
                    rpWheelOvl.innerHTML = '';
                    var segAngle = 360 / 24;
                    for(var i = 0; i < 24; i++){
                        var div = document.createElement('div');
                        div.className = 'fhlc-segment-text fhlc-segment-' + (i+1);
                        var rot = i * segAngle + segAngle / 2 - 90;
                        div.style.transform = 'rotate(' + rot + 'deg) translateX(60px)';
                        div.style.color = (i % 2 === 0) ? '#f5f5f5' : '#2e2418';
                        div.textContent = fishNames[i] || '';
                        rpWheelOvl.appendChild(div);
                    }
                }

                document.getElementById('fh-replay-results-btn').addEventListener('click', function(){
                    rpIdx = 0; rpRunning = true; rpCumRot = 0;
                    rpLog.innerHTML = '';
                    rpWrap.style.display = 'block';
                    this.style.display = 'none';
                    rpReveal();
                });
                document.getElementById('fh-replay-skip').addEventListener('click', function(){
                    rpRunning = false;
                    rpWrap.style.display = 'none';
                    document.getElementById('fh-replay-results-btn').style.display = '';
                });
                document.getElementById('fh-replay-speed').addEventListener('change', function(){
                    rpSpeed = parseFloat(this.value);
                });

                function rpReveal(){
                    if(rpIdx >= totalPicks || !rpRunning){
                        rpRunning = false;
                        rpStatus.textContent = 'DRAFT COMPLETE';
                        setTimeout(function(){
                            rpWrap.style.display = 'none';
                            document.getElementById('fh-replay-results-btn').style.display = '';
                        }, 2000);
                        return;
                    }
                    var fd = new FormData();
                    fd.append('action', 'fishotel_get_lastcall_pick');
                    fd.append('nonce', rpNonce);
                    fd.append('batch_name', batchName);
                    fd.append('pick_index', rpIdx);
                    fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if(!d.success || !rpRunning) return;
                            var p = d.data;
                            rpStatus.textContent = 'Round ' + p.round + ', Pick ' + p.pick_number + ' \u2014 ' + p.customer_name + ' is up';
                            rpBuildOverlay(p.wheel_fish);
                            rpWheelImg.style.transition = 'none'; rpWheelOvl.style.transition = 'none';
                            rpWheelImg.style.transform = 'rotate(' + rpCumRot + 'deg)';
                            rpWheelOvl.style.transform = 'rotate(' + rpCumRot + 'deg)';
                            rpBall.style.opacity = '0'; rpBall.style.transition = 'none';
                            var segAngle = 360 / 24;
                            var winAngle = (p.wheel_segment - 1) * segAngle + segAngle / 2;
                            var spins = 3 + Math.random();
                            var target = rpCumRot + (spins * 360) + (360 - winAngle);
                            rpCumRot = target;
                            setTimeout(function(){
                                if(!rpRunning) return;
                                rpWheelImg.style.transition = 'transform ' + rpSpeed + 's cubic-bezier(0.25,0.46,0.45,0.94)';
                                rpWheelOvl.style.transition = 'transform ' + rpSpeed + 's cubic-bezier(0.25,0.46,0.45,0.94)';
                                rpWheelImg.style.transform = 'rotate(' + target + 'deg)';
                                rpWheelOvl.style.transform = 'rotate(' + target + 'deg)';
                                rpBall.style.transition = 'transform ' + rpSpeed + 's cubic-bezier(0.25,0.46,0.45,0.94), opacity 0.3s';
                                rpBall.style.opacity = '1';
                                rpBall.style.transform = 'rotate(' + (360 - winAngle + spins * 360) + 'deg) translateY(-210px)';
                                setTimeout(function(){
                                    if(!rpRunning) return;
                                    // Ball drop into segment
                                    var rpBallAngle = 360 - winAngle + spins * 360;
                                    rpBall.style.transition = 'transform 0.4s cubic-bezier(0.22,1,0.36,1)';
                                    rpBall.style.transform = 'rotate(' + rpBallAngle + 'deg) translateY(-150px)';
                                    var winEl = rpWheelOvl.querySelector('.fhlc-segment-' + p.wheel_segment);
                                    if(winEl) winEl.classList.add('fhlc-winning');
                                    var entry = document.createElement('div');
                                    entry.textContent = 'Pick ' + p.pick_number + ': ' + p.customer_name + ' \u2192 ' + p.fish_name + ' \u00D7 ' + p.qty;
                                    rpLog.appendChild(entry);
                                    rpLog.scrollTop = rpLog.scrollHeight;
                                    setTimeout(function(){ rpIdx++; if(rpIdx < totalPicks) rpBall.style.opacity = '0'; rpReveal(); }, 1200);
                                }, rpSpeed * 1000 + 200);
                            }, 300);
                        });
                }
            })();
            </script>
            <?php
        }

        echo '</div>';

        } // end else (lc_is_open)
        endif; // lastcall tab

        echo '</div>'; // .wrap

        // Inline JS for real-time fill rate updates
        ?>
        <script>
        jQuery(function($){
            var fhArrival = {
                ajax_url: '<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>',
                nonce: '<?php echo wp_create_nonce( "fishotel_arrival_save" ); ?>',
                batch: '<?php echo esc_js( $selected ); ?>'
            };

            // Real-time fill rate
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

            // Search filter
            $('#fh-arrival-search').on('input', function(){
                var q = $(this).val().toLowerCase();
                $('.fh-arrival-row').each(function(){
                    var name = $(this).find('td').first().text().toLowerCase();
                    $(this).toggle(name.indexOf(q) !== -1);
                });
            });

            // Per-row AJAX auto-save
            function fhSaveField($el, field, value) {
                var row = $el.closest('.fh-arrival-row');
                $.post(fhArrival.ajax_url, {
                    action: 'fishotel_save_arrival_field',
                    nonce: fhArrival.nonce,
                    fish_id: row.data('id'),
                    field: field,
                    value: value,
                    batch_name: fhArrival.batch
                }, function(resp){
                    if (resp.success) {
                        var $td = $el.closest('td');
                        var $chk = $('<span style="color:#27ae60;font-weight:700;margin-left:6px;font-size:13px;">&#x2713;</span>');
                        $td.find('.fh-save-ok').remove();
                        $chk.addClass('fh-save-ok').appendTo($td);
                        setTimeout(function(){ $chk.fadeOut(300, function(){ $chk.remove(); }); }, 1500);
                        if (field === 'qty_received') {
                            row.css('background', (parseInt(value)||0) > 0 ? 'rgba(181,161,101,0.08)' : '');
                        }
                    }
                });
            }
            $('.fh-arrival-row').on('change', '.fh-recv, .fh-doa', function(){
                var field = $(this).hasClass('fh-recv') ? 'qty_received' : 'qty_doa';
                fhSaveField($(this), field, parseInt($(this).val()) || 0);
            });
            $('.fh-arrival-row').on('change', '.fh-tank', function(){
                fhSaveField($(this), 'tank', $(this).val());
            });
            $('.fh-arrival-row').on('change', '.fh-status', function(){
                fhSaveField($(this), 'status', $(this).val());
            });

            // Surprise fish add
            $('#fh-surprise-add').on('click', function(){
                var fishId = $('#fh-surprise-species').val();
                var fishName = $('#fh-surprise-species option:selected').text();
                var recv = $('#fh-surprise-recv').val() || '0';
                var doa = $('#fh-surprise-doa').val() || '0';
                var tank = $('#fh-surprise-tank').val();
                var status = $('#fh-surprise-status').val();
                var btn = $(this);
                btn.prop('disabled', true).text('Saving...');

                // Save all four fields
                var fields = {qty_received: recv, qty_doa: doa, tank: tank, status: status};
                var done = 0, total = 4;
                $.each(fields, function(field, value){
                    $.post(fhArrival.ajax_url, {
                        action: 'fishotel_save_arrival_field',
                        nonce: fhArrival.nonce,
                        fish_id: fishId,
                        field: field,
                        value: field === 'qty_received' || field === 'qty_doa' ? parseInt(value)||0 : value,
                        batch_name: fhArrival.batch
                    }, function(){ if (++done >= total) { location.reload(); } });
                });
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

            update_post_meta( $batch_id, '_arrival_qty_received', intval( $data['qty_received'] ?? 0 ) );
            update_post_meta( $batch_id, '_arrival_qty_doa',      intval( $data['qty_doa'] ?? 0 ) );
            update_post_meta( $batch_id, '_arrival_tank',         sanitize_text_field( $data['tank'] ?? '' ) );
            update_post_meta( $batch_id, '_arrival_status',       sanitize_text_field( $data['status'] ?? '' ) );
            update_post_meta( $batch_id, '_arrival_updated_at',   time() );
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
            update_post_meta( $batch_id, '_current_qty', intval( $count ) );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=tracker&survival_logged=1' ) );
        exit;
    }

    public function save_graduation_data_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_save_graduation_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( ! $batch_name ) wp_die( 'No batch specified.' );

        $graduation = $_POST['graduation'] ?? [];

        foreach ( $graduation as $batch_id => $qty ) {
            $batch_id = intval( $batch_id );
            if ( ! $batch_id ) continue;

            $post = get_post( $batch_id );
            if ( ! $post || $post->post_type !== 'fish_batch' ) continue;
            if ( get_post_meta( $batch_id, '_batch_name', true ) !== $batch_name ) continue;

            update_post_meta( $batch_id, '_graduation_qty', intval( $qty ) );
        }

        // Advance batch status to graduation and build verification queue
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $statuses[ $batch_name ] = 'graduation';
        update_option( 'fishotel_batch_statuses', $statuses );

        $this->build_verification_queue( $batch_name );

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=graduation&graduation_saved=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Open Last Call (Stage 6)
     * ───────────────────────────────────────────── */

    public function open_lastcall_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_open_lastcall_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( ! $batch_name ) wp_die( 'No batch specified.' );

        $slug = sanitize_title( $batch_name );

        // Build pool and draft order
        $pool  = $this->build_last_call_pool( $batch_name );
        $order = $this->build_last_call_draft_order( $batch_name );

        // Set timestamps
        $window_hours = intval( get_option( 'fishotel_lastcall_window_hours', 48 ) );
        $opened_at    = time();
        $deadline     = $opened_at + ( $window_hours * 3600 );

        update_option( 'fishotel_lastcall_opened_at_' . $slug, $opened_at );
        update_option( 'fishotel_lastcall_deadline_' . $slug, $deadline );

        // Advance batch status to draft
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $statuses[ $batch_name ] = 'draft';
        update_option( 'fishotel_batch_statuses', $statuses );

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&lastcall_opened=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Reset Last Call Draft
     * ───────────────────────────────────────────── */

    public function reset_lastcall_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_reset_lastcall_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( ! $batch_name ) wp_die( 'No batch specified.' );

        $slug = sanitize_title( $batch_name );

        // Clear results
        delete_option( 'fishotel_lastcall_results_' . $slug );
        delete_option( 'fishotel_lastcall_window_expired_' . $slug );

        // Clear lastcall_results notifications for this batch
        $notifs = get_posts( [
            'post_type'      => 'fishotel_notification',
            'numberposts'    => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                [ 'key' => '_fh_notif_batch', 'value' => $batch_name ],
                [ 'key' => '_fh_notif_type',  'value' => 'lastcall_results' ],
            ],
        ] );
        foreach ( $notifs as $n ) {
            wp_delete_post( $n->ID, true );
        }

        // Clear seen-reveal user meta
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
            'fishotel_lastcall_seen_reveal_' . $slug
        ) );

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&lastcall_reset=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Last Call: Update Settings (deadline/rounds)
     * ───────────────────────────────────────────── */

    public function lc_update_settings_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_lc_update_settings_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( ! $batch_name ) wp_die( 'No batch specified.' );
        $slug = sanitize_title( $batch_name );

        if ( ! empty( $_POST['lc_deadline'] ) ) {
            $ts = strtotime( $_POST['lc_deadline'] );
            if ( $ts ) update_option( 'fishotel_lastcall_deadline_' . $slug, $ts );
        }
        if ( isset( $_POST['lc_rounds'] ) ) {
            update_option( 'fishotel_lastcall_rounds', max( 1, min( 10, intval( $_POST['lc_rounds'] ) ) ) );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&updated=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Last Call: Pool — Update Qty
     * ───────────────────────────────────────────── */

    public function lc_pool_update_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_lc_pool_update_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $idx        = intval( $_POST['pool_idx'] ?? -1 );
        $qty        = max( 0, intval( $_POST['pool_qty'] ?? 0 ) );
        $slug       = sanitize_title( $batch_name );
        $pool       = get_option( 'fishotel_lastcall_pool_' . $slug, [] );

        if ( isset( $pool[ $idx ] ) ) {
            $pool[ $idx ]['pool_qty'] = $qty;
            update_option( 'fishotel_lastcall_pool_' . $slug, $pool );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&updated=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Last Call: Pool — Remove Species
     * ───────────────────────────────────────────── */

    public function lc_pool_remove_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_lc_pool_remove_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $idx        = intval( $_POST['pool_idx'] ?? -1 );
        $slug       = sanitize_title( $batch_name );
        $pool       = get_option( 'fishotel_lastcall_pool_' . $slug, [] );

        if ( isset( $pool[ $idx ] ) ) {
            array_splice( $pool, $idx, 1 );
            update_option( 'fishotel_lastcall_pool_' . $slug, $pool );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&updated=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Last Call: Pool — Add Species
     * ───────────────────────────────────────────── */

    public function lc_pool_add_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_lc_pool_add_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $fish_id    = intval( $_POST['add_fish_id'] ?? 0 );
        $qty        = max( 1, intval( $_POST['add_qty'] ?? 1 ) );
        $slug       = sanitize_title( $batch_name );

        if ( $fish_id ) {
            $pool = get_option( 'fishotel_lastcall_pool_' . $slug, [] );
            $post = get_post( $fish_id );
            $name = $post ? FisHotel_Batch_Manager::resolve_common_name( $fish_id, $post->post_title ) : 'Fish #' . $fish_id;
            $pool[] = [
                'fish_id'  => $fish_id,
                'name'     => $name,
                'pool_qty' => $qty,
            ];
            update_option( 'fishotel_lastcall_pool_' . $slug, $pool );
        }

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&updated=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Last Call: Draft Order — Move Up/Down
     * ───────────────────────────────────────────── */

    public function lc_order_move_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_lc_order_move_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $pos        = intval( $_POST['pos'] ?? -1 );
        $dir        = sanitize_text_field( $_POST['dir'] ?? '' );
        $slug       = sanitize_title( $batch_name );
        $order      = get_option( 'fishotel_lastcall_order_' . $slug, [] );

        if ( $dir === 'up' && $pos > 0 && isset( $order[ $pos ] ) ) {
            $tmp = $order[ $pos - 1 ];
            $order[ $pos - 1 ] = $order[ $pos ];
            $order[ $pos ] = $tmp;
        } elseif ( $dir === 'down' && $pos < count( $order ) - 1 && isset( $order[ $pos ] ) ) {
            $tmp = $order[ $pos + 1 ];
            $order[ $pos + 1 ] = $order[ $pos ];
            $order[ $pos ] = $tmp;
        }

        update_option( 'fishotel_lastcall_order_' . $slug, $order );
        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&updated=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Last Call: Close Window Early
     * ───────────────────────────────────────────── */

    public function lc_close_window_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_lc_close_window_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $slug       = sanitize_title( $batch_name );

        // Set deadline to now
        update_option( 'fishotel_lastcall_deadline_' . $slug, time() );
        update_option( 'fishotel_lastcall_window_expired_' . $slug, true );

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&updated=1' ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Last Call: Admin Save Wishlist for User
     * ───────────────────────────────────────────── */

    public function save_admin_wishlist_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_save_admin_wishlist_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $batch_name = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $target_uid = intval( $_POST['target_user_id'] ?? 0 );
        if ( ! $batch_name || ! $target_uid ) wp_die( 'Missing parameters.' );

        $slug = sanitize_title( $batch_name );
        $raw  = json_decode( wp_unslash( $_POST['wishlist_json'] ?? '[]' ), true );

        $wishlist = [];
        if ( is_array( $raw ) ) {
            foreach ( $raw as $entry ) {
                $fish_id = intval( $entry['fish_id'] ?? 0 );
                if ( ! $fish_id ) continue;
                $wishlist[] = [
                    'fish_id'           => $fish_id,
                    'rank'              => intval( $entry['rank'] ?? 0 ),
                    'is_alternative_to' => ! empty( $entry['is_alternative_to'] ) ? intval( $entry['is_alternative_to'] ) : null,
                ];
            }
        }

        update_option( 'fishotel_lastcall_wishlist_' . $slug . '_' . $target_uid, $wishlist );

        wp_redirect( admin_url( 'admin.php?page=fishotel-arrival-entry&batch=' . urlencode( $batch_name ) . '&tab=lastcall&updated=1' ) );
        exit;
    }

}
