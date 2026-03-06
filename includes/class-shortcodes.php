<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait FisHotel_Shortcodes {

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

            <?php
            // Load previously submitted requests for this user+batch so we can show them in the cart.
            $prev_items = [];
            $prev_total = 0.0;
            if ( is_user_logged_in() ) {
                $uid = get_current_user_id();
                $existing_reqs = get_posts( [
                    'post_type'   => 'fish_request',
                    'numberposts' => -1,
                    'post_status' => 'any',
                    'meta_query'  => [
                        'relation' => 'AND',
                        [ 'key' => '_customer_id', 'value' => $uid,        'compare' => '=' ],
                        [ 'key' => '_batch_name',  'value' => $batch_name, 'compare' => '=' ],
                    ],
                ] );
                foreach ( $existing_reqs as $req ) {
                    if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
                    $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                    foreach ( $req_items as $ritem ) {
                        $prev_items[] = $ritem;
                        $prev_total  += (float) $ritem['price'] * (int) $ritem['qty'];
                    }
                }
            }
            ?>
            <?php if ( is_user_logged_in() ) : ?>
            <div id="my-requests" style="margin-bottom:15px;border:1px solid #444;padding:20px;background:#1e1e1e;border-radius:8px;color:#fff;">
                <h3 style="margin-top:0;color:#fff;">MY CURRENT REQUESTS</h3>
                <div id="request-list" style="min-height:40px;">No fish requested yet.</div>
                <div id="cart-total" style="font-weight:700;color:#e67e22;margin:12px 0;font-size:1.2em;">Total: $0.00</div>
                <button id="submit-requests" style="width:auto;padding:14px 40px;font-size:18px;font-weight:700;background:#e67e22;color:#000;border:none;border-radius:8px;cursor:pointer;margin-top:4px;display:block;margin-left:auto;margin-right:auto;">Submit My Requests</button>
            </div>
            <?php endif; ?>

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
                    let prevItems  = <?php echo wp_json_encode( $prev_items ); ?>;
                    let prevTotal  = <?php echo (float) $prev_total; ?>;
                    let cartItems  = [];
                    let cartTotal  = prevTotal;  // includes any already-submitted amounts
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
                        const list = document.getElementById("request-list");
                        if (!list) return;
                        let html = '';

                        // Previously submitted requests — dimmed, no remove button.
                        if (prevItems.length > 0) {
                            html += '<div style="color:#888;font-size:0.82em;font-style:italic;margin:2px 0 6px;">Previously Requested</div>';
                            prevItems.forEach(item => {
                                const lt = (item.price * item.qty).toFixed(2);
                                html += `<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #2a2a2a;opacity:0.6;">
                                    <span style="font-style:italic;color:#aaa;">${item.fish_name} × ${item.qty} = $${lt}</span>
                                </div>`;
                            });
                        }

                        // Current session new requests.
                        if (cartItems.length > 0) {
                            if (prevItems.length > 0) html += '<div style="color:#aaa;font-size:0.82em;margin:10px 0 4px;">New Requests</div>';
                            cartItems.forEach((item, index) => {
                                const lineTotal = item.price * item.qty;
                                html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #444;" data-line-total="${lineTotal}" data-index="${index}">
                                    <span>${item.fish_name} × ${item.qty} = $${lineTotal.toFixed(2)}</span>
                                    <button onclick="removeItem(this)" title="Remove" style="background:none;border:none;color:#e74c3c;font-size:1.8em;cursor:pointer;padding:0 8px;">×</button>
                                </div>`;
                            });
                        }

                        if (prevItems.length === 0 && cartItems.length === 0) html = 'No fish requested yet.';
                        list.innerHTML = html;
                        updateCartTotal();
                    }

                    function updateCartTotal() {
                        const el = document.getElementById("cart-total");
                        if (el) el.innerHTML = `Total: $${cartTotal.toFixed(2)}`;
                    }

                    renderRequestList(); // show prev items immediately on load

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
                                        const existingIdx = cartItems.findIndex(i => i.batch_id === batchId);
                                        if (existingIdx >= 0) {
                                            // Update qty on the existing line instead of adding a duplicate.
                                            cartTotal -= cartItems[existingIdx].price * cartItems[existingIdx].qty;
                                            cartItems[existingIdx].qty += qty;
                                            cartTotal += cartItems[existingIdx].price * cartItems[existingIdx].qty;
                                        } else {
                                            cartItems.push({ batch_id: batchId, fish_name: fishName, qty: qty, price: price });
                                            cartTotal += price * qty;
                                        }
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

                    const submitBtn = document.getElementById("submit-requests");
                    if (submitBtn) submitBtn.addEventListener("click", function() {
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

        if ( $status === 'arrived' ) {
            ?>
            <div style="background:#1e1e1e;border-radius:12px;padding:40px 30px;max-width:700px;margin:0 auto;color:#fff;text-align:center;">
                <h2 style="color:#e67e22;margin-top:0;font-size:clamp(1.3rem,4vw,1.8rem);">Ordering is now closed for <?php echo esc_html( $batch_name ); ?></h2>
                <p style="color:#ccc;font-size:1.05em;margin-bottom:30px;">Thank you! We've received all requests and are placing the order now. Check back soon for arrival updates.</p>

                <?php if ( is_user_logged_in() ) :
                    $user_id  = get_current_user_id();
                    $requests = get_posts( [
                        'post_type'      => 'fish_request',
                        'numberposts'    => -1,
                        'post_status'    => 'any',
                        'meta_query'     => [
                            'relation' => 'AND',
                            [ 'key' => '_customer_id', 'value' => $user_id,    'compare' => '=' ],
                            [ 'key' => '_batch_name',  'value' => $batch_name, 'compare' => '=' ],
                        ],
                    ] );

                    $all_items   = [];
                    $grand_total = 0.0;
                    foreach ( $requests as $req ) {
                        $items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                        foreach ( $items as $item ) {
                            $all_items[] = $item;
                            $grand_total += $item['price'] * $item['qty'];
                        }
                    }
                ?>

                <?php if ( empty( $all_items ) ) : ?>
                    <p style="color:#aaa;">You have no requests on file for this batch.</p>
                <?php else : ?>
                    <h3 style="color:#fff;text-align:left;margin-bottom:12px;">Your Requests</h3>
                    <table style="width:100%;border-collapse:collapse;text-align:left;">
                        <thead>
                            <tr style="background:#333;color:#b5a165;">
                                <th style="padding:10px 12px;">Fish</th>
                                <th style="padding:10px 12px;text-align:center;">Qty</th>
                                <th style="padding:10px 12px;text-align:right;">Price</th>
                                <th style="padding:10px 12px;text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $all_items as $item ) :
                                $line_total = $item['price'] * $item['qty'];
                            ?>
                            <tr style="border-bottom:1px solid #2a2a2a;">
                                <td style="padding:9px 12px;color:#fff;"><?php echo esc_html( $item['fish_name'] ); ?></td>
                                <td style="padding:9px 12px;text-align:center;color:#fff;"><?php echo intval( $item['qty'] ); ?></td>
                                <td style="padding:9px 12px;text-align:right;color:#fff;">$<?php echo number_format( $item['price'], 2 ); ?></td>
                                <td style="padding:9px 12px;text-align:right;color:#e67e22;">$<?php echo number_format( $line_total, 2 ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid #444;">
                                <td colspan="3" style="padding:12px;text-align:right;color:#fff;font-weight:700;">Grand Total:</td>
                                <td style="padding:12px;text-align:right;color:#e67e22;font-weight:700;font-size:1.1em;">$<?php echo number_format( $grand_total, 2 ); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>

                <?php else : ?>
                    <p style="color:#aaa;">Please <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="color:#e67e22;">log in</a> to see your requests for this batch.</p>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        }

        return '<p>Stage "' . esc_html( $status ) . '" is coming soon.</p>';
    }

}
