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
                        $ritem['request_id'] = $req->ID;
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

                        // Previously submitted requests — dimmed, with × remove button.
                        if (prevItems.length > 0) {
                            html += '<div style="color:#888;font-size:0.82em;font-style:italic;margin:2px 0 6px;">Previously Requested</div>';
                            prevItems.forEach((item, idx) => {
                                const lt = (item.price * item.qty).toFixed(2);
                                const safeName = item.fish_name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
                                html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid #2a2a2a;opacity:0.7;">
                                    <span style="font-style:italic;color:#aaa;">${item.fish_name} × ${item.qty} = $${lt}</span>
                                    <button onclick="removePrevItem(this,${idx},'${safeName}',${item.request_id},${item.batch_id},${item.price * item.qty})" title="Remove" style="background:none;border:none;color:#e74c3c;font-size:1.8em;cursor:pointer;padding:0 8px;">×</button>
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

                    window.removePrevItem = function(btn, prevIdx, fishName, requestId, batchId, lineTotal) {
                        if (!confirm('Remove ' + fishName + ' from your request? This cannot be undone.')) return;
                        btn.disabled = true;
                        btn.innerText = '…';
                        fetch("<?php echo admin_url( 'admin-ajax.php' ); ?>", {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'fishotel_remove_request_item',
                                nonce: '<?php echo wp_create_nonce( 'fishotel_batch_ajax' ); ?>',
                                request_id: requestId,
                                batch_id: batchId
                            })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                prevItems.splice(prevIdx, 1);
                                cartTotal -= lineTotal;
                                prevTotal -= lineTotal;
                                renderRequestList();
                            } else {
                                alert(data.data && data.data.message ? data.data.message : 'Failed to remove item.');
                                btn.disabled = false;
                                btn.innerText = '×';
                            }
                        })
                        .catch(() => {
                            alert('Network error. Please try again.');
                            btn.disabled = false;
                            btn.innerText = '×';
                        });
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

        // ─── Stage 3: Transit page (orders_closed / in_transit) ────────────
        if ( in_array( $status, [ 'orders_closed', 'in_transit' ], true ) ) {
            $arrival_dates  = get_option( 'fishotel_batch_arrival_dates', [] );
            $arrival_date   = $arrival_dates[ $batch_name ] ?? '';
            $origin_locs    = $this->get_origin_locations();

            // Detect origin from batch name
            $origin_name = 'International Waters';
            $origin_lat  = 0;
            $origin_lng  = -160;
            $batch_words = preg_split( '/[\s\-_]+/', $batch_name );
            foreach ( $origin_locs as $loc ) {
                foreach ( $batch_words as $word ) {
                    if ( strcasecmp( trim( $word ), $loc['name'] ) === 0 ) {
                        $origin_name = $loc['name'];
                        $origin_lat  = (float) $loc['lat'];
                        $origin_lng  = (float) $loc['lng'];
                        break 2;
                    }
                }
                // Also try multi-word location names as substring
                if ( stripos( $batch_name, $loc['name'] ) !== false ) {
                    $origin_name = $loc['name'];
                    $origin_lat  = (float) $loc['lat'];
                    $origin_lng  = (float) $loc['lng'];
                    break;
                }
            }

            // Destination: center-US
            $dest_lat = 39.8283;
            $dest_lng = -98.5795;

            // Calibrated projection — viewBox matches image pixels (1280×720).
            // Anchor points clicked directly on the map image:
            //   Minnesota (44.0, -94.0)   → SVG (280, 290)
            //   Fiji     (-17.71, 178.07) → SVG (1235, 531)
            $x_scale  =  3.5101;
            $x_offset =  609.95;
            $y_scale  = -3.9054;
            $y_offset =  461.84;

            $ox = $origin_lng * $x_scale + $x_offset;
            $oy = $origin_lat * $y_scale + $y_offset;
            $dx = $dest_lng   * $x_scale + $x_offset;
            $dy = $dest_lat   * $y_scale + $y_offset;

            // Bezier control point: midpoint shifted 216px upward (150 × 720/500)
            $cx = ( $ox + $dx ) / 2;
            $cy = ( ( $oy + $dy ) / 2 ) - 216;

            // Flight progress: days_elapsed / total_days
            $closed_dates   = get_option( 'fishotel_batch_closed_dates', [] );
            $closed_date    = $closed_dates[ $batch_name ] ?? '';
            $progress       = 0.05; // default: just departed
            $arrived        = false;
            $days_left      = null;
            $quarantine_day = null;

            if ( $closed_date && $arrival_date ) {
                $closed_ts  = strtotime( $closed_date );
                $arrival_ts = strtotime( $arrival_date );
                $now_ts     = time();
                $total_days = max( (int) round( ( $arrival_ts - $closed_ts ) / 86400 ), 1 );
                $elapsed    = (int) round( ( $now_ts - $closed_ts ) / 86400 );
                $days_until = (int) ceil( ( $arrival_ts - $now_ts ) / 86400 );

                if ( $days_until <= 0 ) {
                    $arrived        = true;
                    $progress       = 1.0;
                    $quarantine_day = abs( $days_until ) + 1;
                    if ( $quarantine_day > 14 ) $quarantine_day = 14;
                } else {
                    $progress  = min( max( $elapsed / $total_days, 0.02 ), 0.98 );
                    $days_left = $days_until;
                }
            } elseif ( $arrival_date ) {
                // Has arrival date but no closed date — estimate with 14-day transit
                $arrival_ts = strtotime( $arrival_date );
                $now_ts     = time();
                $days_until = (int) ceil( ( $arrival_ts - $now_ts ) / 86400 );

                if ( $days_until <= 0 ) {
                    $arrived        = true;
                    $progress       = 1.0;
                    $quarantine_day = abs( $days_until ) + 1;
                    if ( $quarantine_day > 14 ) $quarantine_day = 14;
                } else {
                    $total_days = 14;
                    $elapsed    = max( $total_days - $days_until, 0 );
                    $progress   = min( max( $elapsed / $total_days, 0.02 ), 0.98 );
                    $days_left  = $days_until;
                }
            }

            // Plane position on quadratic bezier at t=$progress
            $t  = $progress;
            $t1 = 1 - $t;
            $plane_x = $t1 * $t1 * $ox + 2 * $t1 * $t * $cx + $t * $t * $dx;
            $plane_y = $t1 * $t1 * $oy + 2 * $t1 * $t * $cy + $t * $t * $dy;

            // Tangent angle for plane rotation
            $tan_x = 2 * $t1 * ( $cx - $ox ) + 2 * $t * ( $dx - $cx );
            $tan_y = 2 * $t1 * ( $cy - $oy ) + 2 * $t * ( $dy - $cy );
            $angle = rad2deg( atan2( $tan_y, $tan_x ) );

            // Badge logic
            if ( $arrived ) {
                $badge_text  = 'QUARANTINE IN PROGRESS — DAY ' . intval( $quarantine_day ) . ' OF 14';
                $badge_color = '#e67e22';
            } elseif ( ! $arrival_date ) {
                $badge_text  = 'DEPARTURE CONFIRMED';
                $badge_color = '#e67e22';
            } elseif ( $days_left === 0 ) {
                $badge_text  = 'ARRIVING TODAY!!';
                $badge_color = '#e67e22';
            } elseif ( $days_left === 1 ) {
                $badge_text  = 'ARRIVING TOMORROW!';
                $badge_color = '#e67e22';
            } else {
                $badge_text  = 'ARRIVING IN ' . intval( $days_left ) . ' DAYS';
                $badge_color = '#e67e22';
            }

            // ─── Boarding pass data ─────────────────────────────────────
            $bp_items     = [];
            $bp_total     = 0.0;
            $bp_logged_in = is_user_logged_in();
            if ( $bp_logged_in ) {
                $bp_uid  = get_current_user_id();
                $bp_reqs = get_posts( [
                    'post_type'   => 'fish_request',
                    'numberposts' => -1,
                    'post_status' => 'any',
                    'meta_query'  => [
                        'relation' => 'AND',
                        [ 'key' => '_customer_id', 'value' => $bp_uid,      'compare' => '=' ],
                        [ 'key' => '_batch_name',  'value' => $batch_name,  'compare' => '=' ],
                    ],
                ] );
                foreach ( $bp_reqs as $req ) {
                    if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
                    $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                    foreach ( $req_items as $item ) {
                        $bp_items[] = $item;
                        $bp_total  += (float) $item['price'] * (int) $item['qty'];
                    }
                }
            }
            $bp_deposit = $this->get_deposit_amount( $batch_name );

            // ─── Flight manifest data (all customer requests aggregated) ───
            $manifest_reqs = get_posts( [
                'post_type'   => 'fish_request',
                'numberposts' => -1,
                'post_status' => 'any',
                'meta_query'  => [ [ 'key' => '_batch_name', 'value' => $batch_name, 'compare' => '=' ] ],
            ] );
            $manifest_species = [];
            foreach ( $manifest_reqs as $mreq ) {
                if ( get_post_meta( $mreq->ID, '_is_admin_order', true ) ) continue;
                $mreq_items = json_decode( get_post_meta( $mreq->ID, '_cart_items', true ), true ) ?: [];
                foreach ( $mreq_items as $mitem ) {
                    $mbid = intval( $mitem['batch_id'] );
                    if ( ! isset( $manifest_species[ $mbid ] ) ) {
                        $m_master_id = get_post_meta( $mbid, '_master_id', true );
                        $manifest_species[ $mbid ] = [
                            'fish_name'       => $mitem['fish_name'],
                            'scientific_name' => $m_master_id ? (string) get_post_meta( $m_master_id, '_scientific_name', true ) : '',
                            'total_qty'       => 0,
                        ];
                    }
                    $manifest_species[ $mbid ]['total_qty'] += intval( $mitem['qty'] );
                }
            }
            usort( $manifest_species, fn( $a, $b ) => strcasecmp( $a['fish_name'], $b['fish_name'] ) );
            $manifest_total_fish = array_sum( array_column( $manifest_species, 'total_qty' ) );
            ?>
            <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
            <style>
                .fh-transit-wrap { max-width: 960px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
                .fh-hero-map {
                    position: relative; width: 100%; background: #0c161f; border-radius: 12px; overflow: hidden;
                    border: 2px solid #333;
                }
                .fh-hero-map.fh-arrived { animation: fhPulseBorder 2s ease-in-out infinite; }
                @keyframes fhPulseBorder { 0%,100%{ border-color:#333; } 50%{ border-color:#b5a165; } }
                .fh-hero-map svg { width: 100%; height: auto; display: block; }
                .fh-status-banner { text-align: center; padding: 32px 20px 28px; }
                .fh-status-banner h2 {
                    font-family: 'Oswald', sans-serif; font-weight: 700; font-size: clamp(1.6rem, 4vw, 2.4rem);
                    text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 10px 0;
                }
                .fh-status-banner .fh-subline {
                    color: #b5a165; font-size: clamp(0.85rem, 2vw, 1.05rem); margin: 0 0 20px 0;
                    font-family: 'Oswald', sans-serif; font-weight: 400;
                }
                .fh-stamp {
                    display: inline-block; border: 2px solid #e67e22; border-radius: 4px; padding: 8px 22px;
                    font-family: 'Oswald', sans-serif; font-weight: 700; font-size: clamp(0.95rem, 2.5vw, 1.3rem);
                    text-transform: uppercase; letter-spacing: 0.06em; transform: rotate(-2deg);
                    color: #e67e22;
                }
                /* ── Flight Manifest ── */
                .fh-manifest {
                    margin-top: 28px; background: #0c161f; border: 2px solid #b5a165; border-radius: 10px;
                    font-family: 'Oswald', sans-serif; color: #fff; overflow: hidden;
                }
                .fh-manifest-header {
                    padding: 16px 24px; border-top: 1px solid #b5a165; border-bottom: 1px solid #b5a165;
                    font-family: 'Oswald', sans-serif; font-weight: 700; font-size: clamp(0.95rem, 2.5vw, 1.2rem);
                    text-transform: uppercase; letter-spacing: 0.12em; color: #b5a165;
                }
                .fh-manifest table { width: 100%; border-collapse: collapse; }
                .fh-manifest thead tr { background: rgba(181,161,101,0.15); }
                .fh-manifest th {
                    text-align: left; color: #b5a165; font-weight: 400; font-size: 11px;
                    text-transform: uppercase; letter-spacing: 0.08em; padding: 8px 16px;
                }
                .fh-manifest th:last-child { text-align: center; }
                .fh-manifest td { padding: 8px 16px; font-size: 14px; color: #fff; }
                .fh-manifest td:last-child { text-align: center; }
                .fh-manifest tbody tr:nth-child(odd) { background: #0c161f; }
                .fh-manifest tbody tr:nth-child(even) { background: #0f1e2d; }
                .fh-manifest-sci { font-style: italic; color: #8a9bae; }
                .fh-manifest-total td {
                    padding: 12px 16px; border-top: 1px solid #b5a165;
                    color: #b5a165; font-weight: 700; font-size: 13px;
                    text-transform: uppercase; letter-spacing: 0.06em;
                }
                /* ── Boarding Pass ── */
                .fh-bp-wrap { position: relative; margin-top: 28px; }
                .fh-boarding-pass {
                    display: flex; background: #0c161f; border: 2px dashed #b5a165; border-radius: 10px;
                    font-family: 'Oswald', sans-serif; color: #fff; overflow: hidden;
                }
                .fh-bp-left {
                    flex: 0 0 18%; padding: 16px 14px; display: flex; flex-direction: column; gap: 10px;
                    border-right: 2px dashed #b5a165; background: #111d28;
                }
                .fh-bp-left img { width: 56px; height: 56px; margin-bottom: 4px; }
                .fh-bp-airline-name {
                    font-family: 'Oswald', sans-serif; font-size: 9px; color: #b5a165; text-transform: uppercase;
                    letter-spacing: 0.18em; margin: -2px 0 6px 0; font-weight: 600;
                }
                .fh-bp-label { font-size: 12px; color: #b5a165; text-transform: uppercase; letter-spacing: 0.1em; margin: 0; }
                .fh-bp-value { font-size: 17px; font-weight: 700; margin: 0 0 6px 0; }
                .fh-bp-center { flex: 1; padding: 20px; overflow-x: auto; }
                .fh-bp-center table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
                .fh-bp-center thead tr { background: rgba(181,161,101,0.15); }
                .fh-bp-center th {
                    text-align: left; color: #b5a165; font-weight: 400; font-size: 11px; text-transform: uppercase;
                    letter-spacing: 0.08em; padding: 4px 8px 8px; border-bottom: 1px solid #333;
                }
                .fh-bp-center td { padding: 6px 8px; border-bottom: 1px solid #1a1a1a; }
                .fh-bp-center .fh-bp-subtotal td { border-top: 1px solid #b5a165; font-weight: 700; color: #b5a165; padding-top: 10px; }
                .fh-bp-stub {
                    flex: 0 0 20%; padding: 20px 16px; display: flex; flex-direction: column; align-items: center;
                    justify-content: center; gap: 12px; text-align: center; position: relative;
                    border-left: 2px dashed #b5a165;
                    background-image: repeating-linear-gradient(180deg, transparent, transparent 8px, #0c161f 8px, #0c161f 10px);
                    background-size: 4px 10px; background-position: left; background-repeat: repeat-y;
                }
                .fh-bp-scissors {
                    position: absolute; top: -2px; left: -10px; font-size: 16px; color: #b5a165;
                    z-index: 1; line-height: 1;
                }
                .fh-bp-gate {
                    font-family: 'Oswald', sans-serif; font-size: 10px; color: #b5a165;
                    text-transform: uppercase; letter-spacing: 0.1em; margin-top: -4px;
                }
                .fh-bp-stub-title {
                    writing-mode: vertical-rl; text-orientation: mixed; transform: rotate(180deg);
                    font-size: 1.1rem; font-weight: 700; letter-spacing: 0.15em; text-transform: uppercase;
                    color: #b5a165; position: absolute; left: 8px; top: 50%; transform: rotate(180deg) translateX(50%);
                }
                .fh-bp-deposit-stamp {
                    display: inline-block; border: 2px solid #e67e22; border-radius: 4px; padding: 6px 14px;
                    font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em;
                    transform: rotate(-15deg); color: #e67e22;
                }
                .fh-boarding-pass::after {
                    content: ''; position: absolute; inset: 0; border-radius: 10px;
                    filter: url(#fh-paper-grain); background: rgba(255,255,255,0.04);
                    pointer-events: none; z-index: 1; mix-blend-mode: overlay;
                }
                .fh-boarding-pass { position: relative; }
                .fh-bp-ghost { filter: blur(5px); pointer-events: none; user-select: none; }
                .fh-bp-login-overlay {
                    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
                    flex-direction: column; gap: 0;
                    background: rgba(12,22,31,0.82); border-radius: 10px; z-index: 2;
                }
                .fh-bp-login-link {
                    display: flex; flex-direction: column; align-items: center; gap: 8px;
                    font-family: 'Oswald', sans-serif; font-size: 1.1rem; color: #b5a165;
                    text-transform: uppercase; letter-spacing: 0.08em;
                    text-decoration: none; transition: color 0.2s;
                }
                .fh-bp-login-link:hover { color: #e67e22; }
                .fh-bp-lock { font-size: 2rem; display: block; }
                @media (max-width: 700px) {
                    .fh-boarding-pass { flex-direction: column; }
                    .fh-bp-left {
                        flex: none; border-right: none; border-bottom: 2px dashed #b5a165;
                        flex-direction: row; padding: 12px 14px; gap: 0; align-items: center;
                    }
                    .fh-bp-brand {
                        flex: 0 0 35%; display: flex; flex-direction: column; align-items: center; justify-content: center;
                    }
                    .fh-bp-brand img { width: 40px; height: 40px; margin-bottom: 2px; }
                    .fh-bp-brand .fh-bp-airline-name { font-size: 7px; margin: 0; }
                    .fh-bp-details {
                        flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 2px 12px;
                    }
                    .fh-bp-details .fh-bp-label { font-size: 9px; margin: 0; }
                    .fh-bp-details .fh-bp-value { font-size: 12px; margin: 0 0 2px 0; }
                    .fh-bp-stub { flex: none; border-left: none; border-bottom: none; border-top: 2px dashed #b5a165; padding: 12px 16px; flex-direction: row; flex-wrap: wrap; }
                    .fh-bp-stub-title { writing-mode: horizontal-tb; position: static; transform: none; }
                }

                /* Navigation lights on plane */
                .fh-nav-light {
                    position: absolute;
                    width: 4px;
                    height: 4px;
                    border-radius: 50%;
                    animation: fh-blink 1.2s infinite;
                }
                .fh-nav-red {
                    background: #ff4444;
                    box-shadow: 0 0 3px 2px rgba(255,40,40,0.8), 0 0 6px 3px rgba(255,0,0,0.4);
                    top: 20px;
                    left: -3px;
                    animation-delay: 0s;
                }
                .fh-nav-green {
                    background: #44ff66;
                    box-shadow: 0 0 3px 2px rgba(40,255,80,0.8), 0 0 6px 3px rgba(0,255,50,0.4);
                    top: 20px;
                    left: 72px;
                    animation-delay: 0.6s;
                }
                @keyframes fh-blink {
                    0%, 45%  { opacity: 1; }
                    50%, 95% { opacity: 0; }
                    100%     { opacity: 1; }
                }
            </style>

            <div class="fh-transit-wrap">

                <!-- ===== SECTION 1: Hero Map ===== -->
                <div class="fh-hero-map <?php echo $arrived ? 'fh-arrived' : ''; ?>">
                    <svg viewBox="0 0 1280 720" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                        <!-- World map background image (1280×720 = 1 SVG unit per pixel, no crop/offset) -->
                        <image href="https://fishotel.com/wp-content/uploads/2026/03/fishotel-world-map.jpg" x="0" y="0" width="1280" height="720"/>

                        <!-- Flight arc: gold dashed bezier -->
                        <path d="M<?php echo round($ox,1); ?>,<?php echo round($oy,1); ?> Q<?php echo round($cx,1); ?>,<?php echo round($cy,1); ?> <?php echo round($dx,1); ?>,<?php echo round($dy,1); ?>"
                              fill="none" stroke="#d4bc7e" stroke-width="3" stroke-dasharray="8,6" opacity="0.8"/>

                        <!-- Origin: gold circle + label -->
                        <circle cx="<?php echo round($ox,1); ?>" cy="<?php echo round($oy,1); ?>" r="8" fill="#b5a165"/>
                        <text x="<?php echo round($ox,1); ?>" y="<?php echo round($oy,1) + 24; ?>" text-anchor="middle"
                              fill="#b5a165" font-size="16" font-family="Oswald, sans-serif" font-weight="700" letter-spacing="1"
                              style="text-transform:uppercase;"><?php echo esc_html( strtoupper( $origin_name ) ); ?></text>

                        <!-- Destination: FisHotel logo + label (hard-coded to Minnesota) -->
                        <image href="https://fishotel.com/wp-content/uploads/2026/03/Small-Fish-Hotel-White.png" x="260" y="280" width="40" height="40"/>
                        <text x="280" y="330" text-anchor="middle" fill="#fff" font-size="13" font-family="Oswald, sans-serif" letter-spacing="1" opacity="0.9">FISHOTEL</text>

                        <!-- Plane drop shadow filter -->
                        <defs>
                            <filter id="fh-plane-shadow" x="-30%" y="-30%" width="160%" height="160%">
                                <feDropShadow dx="0" dy="0" stdDeviation="2" flood-color="#000" flood-opacity="0.8"/>
                            </filter>
                        </defs>
                    </svg>

                    <!-- Plane icon (HTML img centered on bezier point) -->
                    <div style="position:absolute;left:<?php echo round( $plane_x / 12.8, 2 ); ?>%;top:<?php echo round( $plane_y / 7.2, 2 ); ?>%;transform:translate(-50%,-50%);pointer-events:none;">
                        <div style="position:relative;transform:rotate(<?php echo round( $angle + 90, 1 ); ?>deg);">
                            <img src="https://fishotel.com/wp-content/uploads/2026/03/fishotel-plane.png" alt="Plane"
                                 style="width:75px;height:50px;display:block;
                                        filter:drop-shadow(0 0 4px rgba(0,0,0,1)) drop-shadow(0 0 9px rgba(0,0,0,1));">
                            <span class="fh-nav-light fh-nav-red"></span>
                            <span class="fh-nav-light fh-nav-green"></span>
                        </div>
                    </div>
                </div>

                <!-- ===== SECTION 2: Status Banner ===== -->
                <div class="fh-status-banner">
                    <?php if ( $arrived ) : ?>
                        <h2 style="color:#e67e22;">&#x1F6EC; YOUR FISH HAVE LANDED!</h2>
                    <?php else : ?>
                        <h2 style="color:#e67e22;">&#9992; YOUR FISH ARE ON THEIR WAY!</h2>
                    <?php endif; ?>

                    <p class="fh-subline">
                        <?php echo esc_html( $batch_name ); ?>
                        <?php if ( $arrival_date ) : ?>
                            &middot; Arriving <?php echo esc_html( date( 'M j, Y', strtotime( $arrival_date ) ) ); ?>
                        <?php endif; ?>
                    </p>

                    <div class="fh-stamp"><?php echo esc_html( $badge_text ); ?></div>
                </div>

                <!-- ===== SECTION 3: Flight Manifest ===== -->
                <?php if ( ! empty( $manifest_species ) ) : ?>
                <div class="fh-manifest">
                    <div class="fh-manifest-header">&#9992; Flight Manifest</div>
                    <table>
                        <thead><tr>
                            <th>Common Name</th><th>Scientific Name</th><th>Qty</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $manifest_species as $ms ) : ?>
                            <tr>
                                <td><?php echo esc_html( $ms['fish_name'] ); ?></td>
                                <td class="fh-manifest-sci"><?php echo esc_html( $ms['scientific_name'] ); ?></td>
                                <td><?php echo intval( $ms['total_qty'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fh-manifest-total">
                                <td colspan="2" style="text-align:right;">Total Passengers: <?php echo intval( $manifest_total_fish ); ?> Fish</td>
                                <td style="text-align:center;"><?php echo intval( $manifest_total_fish ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- SVG noise filter for aged paper texture -->
                <svg width="0" height="0" style="position:absolute;">
                    <filter id="fh-paper-grain">
                        <feTurbulence type="fractalNoise" baseFrequency="0.65" numOctaves="3" stitchTiles="stitch"/>
                        <feColorMatrix type="saturate" values="0"/>
                    </filter>
                </svg>

                <!-- ===== SECTION 3: Boarding Pass ===== -->
                <div class="fh-bp-wrap">
                    <?php if ( ! $bp_logged_in ) : ?>
                        <div class="fh-bp-login-overlay">
                            <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="fh-bp-login-link">
                                <span class="fh-bp-lock">&#x1F512;</span>
                                Log in to see your boarding pass
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="fh-boarding-pass <?php echo ! $bp_logged_in ? 'fh-bp-ghost' : ''; ?>">
                        <!-- LEFT: Flight info -->
                        <div class="fh-bp-left">
                            <div class="fh-bp-brand">
                                <img src="https://fishotel.com/wp-content/uploads/2026/03/Small-Fish-Hotel-White.png" alt="FisHotel">
                                <p class="fh-bp-airline-name">THE FISHOTEL</p>
                            </div>
                            <div class="fh-bp-details">
                                <div>
                                    <p class="fh-bp-label">Passenger</p>
                                    <p class="fh-bp-value"><?php echo $bp_logged_in ? esc_html( wp_get_current_user()->display_name ) : 'Guest'; ?></p>
                                </div>
                                <div>
                                    <p class="fh-bp-label">Flight</p>
                                    <p class="fh-bp-value"><?php echo esc_html( $batch_name ); ?></p>
                                </div>
                                <div>
                                    <p class="fh-bp-label">From</p>
                                    <p class="fh-bp-value"><?php echo esc_html( strtoupper( $origin_name ) ); ?></p>
                                </div>
                                <div>
                                    <p class="fh-bp-label">To</p>
                                    <p class="fh-bp-value">CHAMPLIN, MN</p>
                                </div>
                            </div>
                        </div>

                        <!-- CENTER: Fish manifest -->
                        <div class="fh-bp-center">
                            <table>
                                <thead><tr>
                                    <th>Common Name</th><th>Qty</th><th>Unit Price</th><th>Total</th>
                                </tr></thead>
                                <tbody>
                                <?php if ( ! empty( $bp_items ) ) : ?>
                                    <?php foreach ( $bp_items as $fi ) :
                                        $fi_qty   = (int) $fi['qty'];
                                        $fi_price = (float) $fi['price'];
                                        $fi_total = $fi_qty * $fi_price;
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $fi['fish_name'] ); ?></td>
                                        <td><?php echo $fi_qty; ?></td>
                                        <td>$<?php echo number_format( $fi_price, 2 ); ?></td>
                                        <td>$<?php echo number_format( $fi_total, 2 ); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="fh-bp-subtotal">
                                        <td colspan="3" style="text-align:right;">Subtotal</td>
                                        <td>$<?php echo number_format( $bp_total, 2 ); ?></td>
                                    </tr>
                                <?php else : ?>
                                    <tr><td colspan="4" style="color:#666;text-align:center;padding:20px 0;">No fish on this flight</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- RIGHT STUB -->
                        <div class="fh-bp-stub">
                            <span class="fh-bp-scissors">&#9986;</span>
                            <span class="fh-bp-stub-title">Boarding Pass</span>
                            <div>
                                <p class="fh-bp-label">Deposit</p>
                                <p class="fh-bp-value">$<?php echo number_format( $bp_deposit, 2 ); ?></p>
                            </div>
                            <div class="fh-bp-deposit-stamp">&check; Deposit Paid</div>
                            <p class="fh-bp-gate">GATE: QT-1</p>
                            <?php if ( $arrival_date ) : ?>
                            <div>
                                <p class="fh-bp-label">Arrival</p>
                                <p class="fh-bp-value"><?php echo esc_html( date( 'M j, Y', strtotime( $arrival_date ) ) ); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
            <?php
            return ob_get_clean();
        }

        // Read-only closed batch view for all non-open_ordering stages.
        $my_items = [];
        $my_total = 0.0;
        if ( is_user_logged_in() ) {
            $uid       = get_current_user_id();
            $user_reqs = get_posts( [
                'post_type'   => 'fish_request',
                'numberposts' => -1,
                'post_status' => 'any',
                'meta_query'  => [
                    'relation' => 'AND',
                    [ 'key' => '_customer_id', 'value' => $uid,        'compare' => '=' ],
                    [ 'key' => '_batch_name',  'value' => $batch_name, 'compare' => '=' ],
                ],
            ] );
            foreach ( $user_reqs as $req ) {
                if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
                $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                foreach ( $req_items as $item ) {
                    $my_items[] = $item;
                    $my_total  += (float) $item['price'] * (int) $item['qty'];
                }
            }
        }

        $all_reqs = get_posts( [
            'post_type'   => 'fish_request',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_query'  => [ [ 'key' => '_batch_name', 'value' => $batch_name, 'compare' => '=' ] ],
        ] );
        $species_totals = [];
        foreach ( $all_reqs as $req ) {
            if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
            $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
            foreach ( $req_items as $item ) {
                $bid = intval( $item['batch_id'] );
                if ( ! isset( $species_totals[$bid] ) ) {
                    $master_id = get_post_meta( $bid, '_master_id', true );
                    $species_totals[$bid] = [
                        'fish_name'       => $item['fish_name'],
                        'scientific_name' => $master_id ? (string) get_post_meta( $master_id, '_scientific_name', true ) : '',
                        'total_qty'       => 0,
                    ];
                }
                $species_totals[$bid]['total_qty'] += intval( $item['qty'] );
            }
        }
        usort( $species_totals, fn( $a, $b ) => strcmp( $a['scientific_name'], $b['scientific_name'] ) );
        ?>
        <div style="max-width:800px;margin:0 auto;">

            <div style="background:#1a1a1a;border-left:4px solid #b5a165;border-radius:8px;padding:24px 28px;margin-bottom:24px;">
                <h2 style="margin:0 0 6px 0;color:#b5a165;font-size:clamp(1.2rem,3vw,1.7rem);">🔒 Orders Closed — <?php echo esc_html( $batch_name ); ?></h2>
                <p style="margin:0;color:#aaa;font-size:0.97em;">Ordering is closed. Here's everything that was requested for this batch.</p>
            </div>

            <?php if ( is_user_logged_in() ) : ?>
            <div style="background:#1e1e1e;border:1px solid #333;border-radius:8px;padding:22px 24px;margin-bottom:24px;">
                <h3 style="margin:0 0 14px 0;color:#e67e22;font-size:1em;text-transform:uppercase;letter-spacing:0.05em;">My Requests</h3>
                <?php if ( empty( $my_items ) ) : ?>
                    <p style="color:#aaa;margin:0;">You have no requests on file for this batch.</p>
                <?php else : ?>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid #333;color:#b5a165;font-size:0.82em;text-transform:uppercase;">
                            <th style="padding:6px 8px;text-align:left;font-weight:600;">Fish</th>
                            <th style="padding:6px 8px;text-align:center;font-weight:600;">Qty</th>
                            <th style="padding:6px 8px;text-align:right;font-weight:600;">Price</th>
                            <th style="padding:6px 8px;text-align:right;font-weight:600;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $my_items as $item ) :
                        $line = (float) $item['price'] * (int) $item['qty'];
                    ?>
                        <tr style="border-bottom:1px solid #2a2a2a;">
                            <td style="padding:9px 8px;color:#fff;"><?php echo esc_html( $item['fish_name'] ); ?></td>
                            <td style="padding:9px 8px;text-align:center;color:#fff;"><?php echo intval( $item['qty'] ); ?></td>
                            <td style="padding:9px 8px;text-align:right;color:#aaa;">$<?php echo number_format( (float) $item['price'], 2 ); ?></td>
                            <td style="padding:9px 8px;text-align:right;color:#e67e22;font-weight:600;">$<?php echo number_format( $line, 2 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #444;">
                            <td colspan="3" style="padding:10px 8px;text-align:right;color:#fff;font-weight:700;">Total:</td>
                            <td style="padding:10px 8px;text-align:right;color:#e67e22;font-weight:700;font-size:1.1em;">$<?php echo number_format( $my_total, 2 ); ?></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <div style="background:#1e1e1e;border:1px solid #333;border-radius:8px;padding:16px 24px;margin-bottom:24px;color:#aaa;font-size:0.95em;">
                <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="color:#e67e22;">Log in</a> to see your requests for this batch.
            </div>
            <?php endif; ?>

            <div style="background:#1e1e1e;border:1px solid #333;border-radius:8px;padding:22px 24px;">
                <h3 style="margin:0 0 14px 0;color:#fff;font-size:1em;text-transform:uppercase;letter-spacing:0.05em;">Full Batch Summary</h3>
                <?php if ( empty( $species_totals ) ) : ?>
                    <p style="color:#aaa;margin:0;">No requests were submitted for this batch.</p>
                <?php else : ?>
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;min-width:380px;">
                    <thead>
                        <tr style="border-bottom:2px solid #333;color:#b5a165;font-size:0.82em;text-transform:uppercase;">
                            <th style="padding:8px 10px;text-align:left;font-weight:600;">Common Name</th>
                            <th style="padding:8px 10px;text-align:left;font-weight:600;">Scientific Name</th>
                            <th style="padding:8px 10px;text-align:center;font-weight:600;">Total Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $species_totals as $row ) : ?>
                        <tr style="border-bottom:1px solid #2a2a2a;">
                            <td style="padding:9px 10px;color:#fff;font-weight:500;"><?php echo esc_html( $row['fish_name'] ); ?></td>
                            <td style="padding:9px 10px;color:#aaa;font-style:italic;"><?php echo esc_html( $row['scientific_name'] ); ?></td>
                            <td style="padding:9px 10px;text-align:center;color:#e67e22;font-weight:700;"><?php echo intval( $row['total_qty'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    public function wallet_shortcode() {
        $product_id    = (int) get_option( 'fishotel_deposit_product_id', 31985 );
        $product       = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
        $product_price = ( $product && (float) $product->get_price() > 0 ) ? (float) $product->get_price() : 1;
        $is_logged_in  = is_user_logged_in();
        $balance       = $is_logged_in ? $this->get_user_deposit_balance( get_current_user_id() ) : 0;
        $login_url     = wp_login_url( get_permalink() );

        ob_start();
        ?>
        <div class="fhw-wallet" style="max-width:560px;margin:40px auto;color:#fff;font-family:inherit;">

            <!-- Balance panel -->
            <div style="background:#1e1e1e;border:1px solid #333;border-radius:12px;padding:32px 28px;text-align:center;margin-bottom:20px;box-shadow:0 6px 24px rgba(0,0,0,0.5);">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:#888;margin-bottom:12px;">Your Current Wallet Balance</div>
                <?php if ( $is_logged_in ) : ?>
                    <div style="font-size:56px;font-weight:700;color:#b5a165;line-height:1;">$<?php echo number_format( $balance, 2 ); ?></div>
                <?php else : ?>
                    <div style="font-size:17px;color:#aaa;margin-top:4px;"><a href="<?php echo esc_url( $login_url ); ?>" style="color:#b5a165;text-decoration:underline;">Log in</a> to view your balance.</div>
                <?php endif; ?>
            </div>

            <!-- How it works -->
            <div style="background:#1e1e1e;border:1px solid #333;border-radius:12px;padding:24px 28px;margin-bottom:20px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:#888;margin-bottom:14px;">How It Works</div>
                <ul style="margin:0;padding:0;list-style:none;">
                    <li style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;color:#ccc;font-size:15px;"><span style="color:#b5a165;flex-shrink:0;">●</span>Your wallet holds your deposit for each batch</li>
                    <li style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;color:#ccc;font-size:15px;"><span style="color:#b5a165;flex-shrink:0;">●</span>A minimum deposit is required to participate (amount varies per batch)</li>
                    <li style="display:flex;align-items:flex-start;gap:10px;color:#ccc;font-size:15px;"><span style="color:#b5a165;flex-shrink:0;">●</span>Your deposit is credited against your final invoice</li>
                </ul>
            </div>

            <!-- Top-up selector -->
            <div style="background:#1e1e1e;border:1px solid #333;border-radius:12px;padding:28px;box-shadow:0 6px 24px rgba(0,0,0,0.5);">
                <?php if ( $is_logged_in ) : ?>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:2px;color:#888;margin-bottom:18px;">Add Funds</div>

                    <!-- Preset buttons -->
                    <div style="display:flex;gap:10px;margin-bottom:16px;">
                        <?php foreach ( [ 25, 50, 100, 200 ] as $preset ) : ?>
                            <button type="button" class="fhw-preset" data-amount="<?php echo $preset; ?>"
                                style="flex:1;padding:14px 0;font-size:16px;font-weight:700;background:transparent;border:2px solid #b5a165;color:#b5a165;border-radius:8px;cursor:pointer;">
                                $<?php echo $preset; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Custom amount -->
                    <div style="margin-bottom:20px;">
                        <input type="number" id="fhw-custom" min="1" step="1" placeholder="Custom amount ($)"
                            style="width:100%;box-sizing:border-box;padding:13px 16px;font-size:16px;background:#2a2a2a;border:2px solid #444;border-radius:8px;color:#fff;outline:none;">
                    </div>

                    <!-- CTA -->
                    <a id="fhw-cta" href="#"
                        style="display:block;text-align:center;padding:18px;font-size:18px;font-weight:700;background:#e67e22;color:#000;border-radius:10px;text-decoration:none;">
                        Add to Wallet &rarr;
                    </a>
                    <p id="fhw-error" style="display:none;color:#e74c3c;text-align:center;margin:10px 0 0;font-size:14px;">Please select or enter an amount.</p>

                <?php else : ?>
                    <div style="text-align:center;padding:10px 0;">
                        <p style="color:#aaa;margin:0 0 18px;font-size:16px;">You must be logged in to add funds.</p>
                        <a href="<?php echo esc_url( $login_url ); ?>"
                            style="display:inline-block;padding:14px 40px;font-size:16px;font-weight:700;background:#e67e22;color:#000;border-radius:8px;text-decoration:none;">
                            Log in to Add Funds
                        </a>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php if ( $is_logged_in ) : ?>
        <style>
        .fhw-preset.fhw-selected { background:#b5a165 !important; color:#000 !important; }
        .fhw-preset:hover { background:rgba(181,161,101,0.15) !important; }
        #fhw-cta:hover { background:#d35400 !important; }
        #fhw-custom:focus { border-color:#b5a165 !important; }
        </style>
        <script>
        (function() {
            var productId    = <?php echo (int) $product_id; ?>;
            var productPrice = <?php echo json_encode( $product_price ); ?>;
            var baseUrl      = '/?add-to-cart=' + productId + '&quantity=';
            var selected     = 0;
            var presets      = document.querySelectorAll('.fhw-preset');
            var custom       = document.getElementById('fhw-custom');
            var cta          = document.getElementById('fhw-cta');
            var error        = document.getElementById('fhw-error');

            function clearPresets() {
                presets.forEach(function(b) { b.classList.remove('fhw-selected'); });
            }

            presets.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    clearPresets();
                    this.classList.add('fhw-selected');
                    selected = parseInt(this.dataset.amount, 10);
                    custom.value = '';
                    error.style.display = 'none';
                });
            });

            custom.addEventListener('input', function() {
                clearPresets();
                selected = 0;
                error.style.display = 'none';
            });

            cta.addEventListener('click', function(e) {
                e.preventDefault();
                var amount = selected || parseFloat(custom.value);
                if ( !amount || amount <= 0 ) {
                    error.style.display = 'block';
                    return;
                }
                var qty = Math.max(1, Math.round(amount / productPrice));
                window.location.href = baseUrl + qty;
            });
        })();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

}
