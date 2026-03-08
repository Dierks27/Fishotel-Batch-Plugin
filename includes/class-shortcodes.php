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
            $total_species  = 0;
            $total_stock    = 0;
            foreach ( $batch_posts as $bp ) {
                $mid = get_post_meta( $bp->ID, '_master_id', true );
                if ( ! $mid || ! get_post( $mid ) ) continue;
                $total_species++;
                $total_stock += floatval( get_post_meta( $bp->ID, '_stock', true ) );
            }
            $ticker_msgs = get_option( 'fishotel_ticker_messages', [] );
            if ( empty( $ticker_msgs ) ) {
                $ticker_msgs = [
                    'FIRST COME · FIRST SERVED',
                    '{species} SPECIES AVAILABLE',
                    '{stock} TOTAL STOCK',
                    'DEPOSIT REQUIRED TO REQUEST',
                ];
            }
            $ticker_resolved = [];
            foreach ( $ticker_msgs as $msg ) {
                $msg = str_replace( [ '{species}', '{stock}' ], [ $total_species, intval( $total_stock ) ], $msg );
                $msg = strtoupper( substr( $msg, 0, 40 ) );
                $ticker_resolved[] = $msg;
            }
            $closed_dates   = get_option( 'fishotel_batch_closed_dates', [] );
            $closed_raw     = $closed_dates[ $batch_name ] ?? '';
            $gate_closes_display = $closed_raw ? strtoupper( date( 'M j, Y', strtotime( $closed_raw ) ) ) : 'TBD';
            ?>

            <!-- ===== Login Modal — GATE ACCESS REQUIRED ===== -->
            <div id="fishotel-login-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.88);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:#0c161f;padding:0;border-radius:10px;width:400px;max-width:92%;color:#fff;box-shadow:0 10px 40px rgba(0,0,0,0.8);border:2px solid #b5a165;overflow:hidden;position:relative;">
                    <div style="background:rgba(181,161,101,0.15);padding:18px 24px;border-bottom:1px solid #b5a165;text-align:center;">
                        <h3 style="margin:0;font-family:'Oswald',sans-serif;font-weight:700;font-size:1.3rem;text-transform:uppercase;letter-spacing:0.1em;color:#b5a165;">Gate Access Required</h3>
                    </div>
                    <div style="padding:28px 28px 24px;">
                        <form id="fishotel-login-form">
                            <p><input type="text" id="fishotel-username" placeholder="Username or Email" style="width:100%;padding:12px 14px;background:#0f1e2d;border:1px solid #b5a165;border-radius:4px;color:#fff;font-size:15px;font-family:'Oswald',sans-serif;"></p>
                            <p><input type="password" id="fishotel-password" placeholder="Password" style="width:100%;padding:12px 14px;background:#0f1e2d;border:1px solid #b5a165;border-radius:4px;color:#fff;font-size:15px;font-family:'Oswald',sans-serif;"></p>
                            <p><button type="submit" id="fishotel-login-btn" style="width:100%;padding:14px;background:#e67e22;color:#0c161f;font-size:16px;font-weight:700;border:none;border-radius:4px;cursor:pointer;font-family:'Oswald',sans-serif;text-transform:uppercase;letter-spacing:0.06em;">Log In</button></p>
                            <p style="text-align:center;margin:15px 0 0 0;"><a href="<?php echo wp_lostpassword_url(); ?>" style="color:#b5a165;font-family:'Oswald',sans-serif;font-size:0.9rem;">Forgot Password?</a></p>
                        </form>
                    </div>
                    <button onclick="closeLoginModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;color:#b5a165;font-size:22px;cursor:pointer;">&#x2715;</button>
                </div>
            </div>

            <!-- ===== HF Username Modal — ONE QUICK THING ===== -->
            <div id="hf-username-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.88);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:#0c161f;padding:0;border-radius:10px;width:430px;max-width:92%;color:#fff;box-shadow:0 10px 40px rgba(0,0,0,0.8);border:2px solid #b5a165;overflow:hidden;position:relative;">
                    <div style="background:rgba(181,161,101,0.15);padding:18px 24px;border-bottom:1px solid #b5a165;text-align:center;">
                        <h3 style="margin:0;font-family:'Oswald',sans-serif;font-weight:700;font-size:1.3rem;text-transform:uppercase;letter-spacing:0.1em;color:#b5a165;">One Quick Thing</h3>
                    </div>
                    <div style="padding:28px 28px 24px;">
                        <p style="text-align:center;margin:0 0 20px;color:#8a9bae;font-family:'Oswald',sans-serif;">What is your Humble.Fish username?<br><small style="color:#5a6a7a;">(Optional but recommended for tracking your orders)</small></p>
                        <form id="hf-username-form">
                            <p><input type="text" id="hf-username-input" placeholder="Humble.Fish Username" style="width:100%;padding:12px 14px;background:#0f1e2d;border:1px solid #b5a165;border-radius:4px;color:#fff;font-size:15px;font-family:'Oswald',sans-serif;"></p>
                            <p><button type="submit" id="hf-username-btn" style="width:100%;padding:14px;background:#e67e22;color:#0c161f;font-size:16px;font-weight:700;border:none;border-radius:4px;cursor:pointer;font-family:'Oswald',sans-serif;text-transform:uppercase;letter-spacing:0.06em;">Save & Continue</button></p>
                            <p style="text-align:center;margin-top:10px;"><a href="#" onclick="closeHFModal();return false;" style="color:#5a6a7a;font-family:'Oswald',sans-serif;font-size:0.9rem;">Skip for now</a></p>
                        </form>
                    </div>
                    <button onclick="closeHFModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;color:#b5a165;font-size:22px;cursor:pointer;">&#x2715;</button>
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

            <style>
                /* ── PanAm Gate Theme — Globals ── */
                .fh-gate-wrap {
                    max-width:900px; margin:0 auto;
                    font-family:'Oswald',sans-serif; color:#fff;
                }

                /* ── Solari Departure Board ── */
                .fh-board {
                    width:100%; box-sizing:border-box;
                    background:#0a0a0a; border-radius:8px;
                    box-shadow:0 0 40px rgba(0,0,0,0.8);
                    overflow:hidden; margin-bottom:24px;
                }
                .fh-board-header {
                    background:#111; padding:10px 20px; text-align:center;
                    font-family:'Oswald',sans-serif; font-weight:700; font-size:clamp(0.6rem,1.6vw,0.8rem);
                    text-transform:uppercase; letter-spacing:0.18em; color:#b5a165;
                }
                .fh-board-row {
                    display:flex; border-bottom:1px solid #1a1a1a;
                }
                .fh-board-row:last-child { border-bottom:none; }
                .fh-board-label {
                    width:160px; min-width:160px; background:#111;
                    padding:8px 16px 8px 20px; display:flex; align-items:center; justify-content:flex-end;
                    font-family:'Oswald',sans-serif; font-weight:700; font-size:clamp(0.55rem,1.4vw,0.72rem);
                    text-transform:uppercase; letter-spacing:0.12em; color:#b5a165;
                    border-right:1px solid #1a1a1a;
                }
                .fh-board-tiles {
                    flex:1; display:flex; align-items:center; gap:2px;
                    padding:6px 10px; flex-wrap:nowrap; overflow:hidden;
                    background:#0a0a0a;
                }
                /* ── Split-Flap Tiles ── */
                .fh-flap {
                    width:38px; height:48px; min-width:38px;
                    background:#1a1a1a; border-radius:3px;
                    box-shadow:inset 0 1px 0 rgba(255,255,255,0.04), 0 2px 6px rgba(0,0,0,0.9);
                    display:flex; align-items:center; justify-content:center;
                    font-family:'Courier New',monospace; font-weight:700;
                    font-size:26px; color:#d4bc7e;
                    text-transform:uppercase; position:relative;
                }
                .fh-flap::after {
                    content:''; position:absolute; left:2px; right:2px; top:50%;
                    height:0; border-bottom:1px solid rgba(0,0,0,0.7);
                }
                .fh-flap-space {
                    background:transparent; box-shadow:none; width:16px; min-width:16px;
                }
                .fh-flap-space::after { display:none; }

                @media (max-width:600px) {
                    .fh-board-label { width:90px; min-width:90px; padding:6px 8px 6px 10px; font-size:0.5rem; }
                    .fh-board-tiles { padding:4px 6px; gap:1px; }
                    .fh-flap { width:22px; height:30px; min-width:22px; font-size:16px; }
                    .fh-flap-space { width:10px; min-width:10px; }
                }

                /* ── Boarding Request Card ── */
                .fh-boarding-card {
                    background:#0c161f; border:2px solid #b5a165; border-radius:10px;
                    overflow:hidden; margin-bottom:24px;
                }
                .fh-boarding-header {
                    padding:14px 24px; border-bottom:1px solid #b5a165;
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.85rem,2vw,1.1rem); text-transform:uppercase;
                    letter-spacing:0.12em; color:#b5a165;
                }
                .fh-boarding-body { padding:18px 24px; }
                #request-list div { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(181,161,101,0.2); }
                #request-list span { max-width:70%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#fff; font-family:'Oswald',sans-serif; }
                #request-list button { background:none; border:none; color:#e74c3c; font-size:1.6em; cursor:pointer; padding:0 8px; }
                #cart-total { font-family:'Oswald',sans-serif; font-weight:700; color:#e67e22; margin:14px 0; font-size:1.2em; }
                #submit-requests {
                    width:auto; padding:14px 40px; font-size:16px; font-weight:700;
                    background:#e67e22; color:#0c161f; border:none; border-radius:4px;
                    cursor:pointer; display:block; margin:4px auto 0;
                    font-family:'Oswald',sans-serif; text-transform:uppercase; letter-spacing:0.06em;
                }
                #submit-requests:hover { background:#d4700f; }

                /* ── Observation Deck (logged-out) ── */
                .fh-obs-deck {
                    background:#0c161f; border:2px solid #b5a165; border-radius:10px;
                    overflow:hidden; margin-bottom:24px;
                }
                .fh-obs-header {
                    padding:14px 24px; border-bottom:1px solid #b5a165;
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.85rem,2vw,1.1rem); text-transform:uppercase;
                    letter-spacing:0.12em; color:#b5a165;
                }
                .fh-obs-body {
                    padding:24px; text-align:center;
                }
                .fh-obs-body p { color:#8a9bae; font-family:'Oswald',sans-serif; margin:0 0 16px; font-size:1rem; }
                .fh-obs-counts {
                    display:flex; gap:16px; justify-content:center; margin-bottom:18px;
                }
                .fh-obs-count-item {
                    text-align:center;
                }
                .fh-obs-count-item .fh-obs-num {
                    font-family:'Oswald',sans-serif; font-weight:700; font-size:1.4rem; color:#e67e22;
                }
                .fh-obs-count-item .fh-obs-lbl {
                    font-family:'Oswald',sans-serif; font-weight:400; font-size:0.7rem;
                    text-transform:uppercase; letter-spacing:0.1em; color:#b5a165;
                }
                .fh-obs-login-btn {
                    display:inline-block; padding:12px 32px;
                    background:#e67e22; color:#0c161f;
                    font-family:'Oswald',sans-serif; font-weight:700; font-size:0.95rem;
                    text-transform:uppercase; letter-spacing:0.06em;
                    border:none; border-radius:4px; cursor:pointer;
                }
                .fh-obs-login-btn:hover { background:#d4700f; }

                /* ── Departure Manifest Card ── */
                .fh-manifest-card {
                    background:#0c161f; border:2px solid #b5a165; border-radius:10px;
                    overflow:hidden; margin-bottom:24px;
                }
                .fh-manifest-header {
                    padding:14px 24px; border-bottom:1px solid #b5a165;
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.85rem,2vw,1.1rem); text-transform:uppercase;
                    letter-spacing:0.12em; color:#b5a165;
                }

                /* ── Desktop Table ── */
                .fh-scroll-wrap {
                    overflow-x:auto; width:100%; box-sizing:border-box;
                    scrollbar-width:thin; scrollbar-color:#b5a165 #0c161f;
                }
                .fh-scroll-wrap::-webkit-scrollbar { height:8px; background:#0c161f; }
                .fh-scroll-wrap::-webkit-scrollbar-thumb { background:#b5a165; border-radius:4px; }

                .fishotel-open-table { width:100%; min-width:920px; border-collapse:collapse; }
                .fishotel-open-table thead tr { background:rgba(181,161,101,0.15); }
                .fishotel-open-table th {
                    text-align:left; color:#b5a165; font-family:'Oswald',sans-serif;
                    font-weight:400; font-size:11px; text-transform:uppercase;
                    letter-spacing:0.08em; padding:10px 14px; border-bottom:1px solid #b5a165;
                }
                .fishotel-open-table th[data-sort] { cursor:pointer; }
                .fishotel-open-table th[data-sort]:hover { color:#e67e22; }
                .fishotel-open-table th[data-sort].sort-asc::after { content:" \25B2"; font-size:0.7em; }
                .fishotel-open-table th[data-sort].sort-desc::after { content:" \25BC"; font-size:0.7em; }
                .fishotel-open-table td {
                    padding:10px 14px; font-family:'Oswald',sans-serif;
                    font-size:14px; color:#fff;
                }
                .fishotel-open-table tbody tr:nth-child(odd) { background:#0c161f; }
                .fishotel-open-table tbody tr:nth-child(even) { background:#0f1e2d; }

                /* ── Stock Colors ── */
                .fh-stock-green { color:#44ff66; font-weight:700; }
                .fh-stock-orange { color:#e67e22; font-weight:700; }
                .fh-stock-red { color:#ff4444; font-weight:700; }

                /* ── Size Badge ── */
                .fh-size-badge {
                    background:rgba(181,161,101,0.2); color:#b5a165;
                    padding:2px 8px; border-radius:3px; font-size:0.78em;
                    font-family:'Oswald',sans-serif; letter-spacing:0.06em;
                }

                /* ── Qty Spinner (table) ── */
                .fh-qty-wrap {
                    display:inline-flex; align-items:center;
                    background:#0f1e2d; border:1px solid #b5a165; border-radius:4px;
                    overflow:hidden;
                }
                .fh-qty-wrap .qty-minus,
                .fh-qty-wrap .qty-plus {
                    background:none; border:none; color:#b5a165; padding:4px 8px;
                    cursor:pointer; font-size:14px; font-family:'Oswald',sans-serif;
                }
                .fh-qty-wrap .qty-minus:hover,
                .fh-qty-wrap .qty-plus:hover { color:#e67e22; }
                .fh-qty-wrap .qty-input {
                    width:42px; text-align:center; background:#0c161f; color:#fff;
                    border:none; padding:4px 0; font-family:'Oswald',sans-serif; font-size:14px;
                }

                /* ── Request Button (ticket stub) ── */
                .fh-req-btn {
                    padding:5px 14px; font-size:0.82em; margin-left:8px;
                    background:#e67e22; color:#0c161f; border:none; border-radius:3px;
                    cursor:pointer; font-family:'Oswald',sans-serif; font-weight:700;
                    text-transform:uppercase; letter-spacing:0.04em;
                }
                .fh-req-btn:hover { background:#d4700f; }

                /* ── CLOSED stamp ── */
                .fh-closed-stamp {
                    display:inline-block; border:2px solid #ff4444; border-radius:3px;
                    padding:2px 10px; font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:0.72em; text-transform:uppercase; letter-spacing:0.08em;
                    color:#ff4444; transform:rotate(-2deg);
                }

                /* ── Mobile Controls ── */
                .fh-mobile-controls {
                    display:none; margin-bottom:16px; gap:10px; flex-wrap:wrap;
                }
                .fh-mobile-controls select {
                    padding:10px 14px; font-size:0.95em; font-family:'Oswald',sans-serif;
                    border:1px solid #b5a165; border-radius:4px; background:#0c161f;
                    color:#b5a165; flex:1; min-width:150px;
                }
                .fh-mobile-controls input {
                    padding:10px 14px; font-size:0.95em; font-family:'Oswald',sans-serif;
                    border:1px solid #b5a165; border-radius:4px; background:#0c161f;
                    color:#fff; flex:1; min-width:180px; width:auto;
                }
                .fh-mobile-controls input::placeholder { color:#5a6a7a; }

                /* ── Mobile Cards ── */
                .fish-cards { display:grid; gap:16px; }
                .fish-card {
                    background:#0c161f; border:1px solid #b5a165; border-radius:8px;
                    padding:18px; font-family:'Oswald',sans-serif;
                }
                .fish-card h4 { margin:0 0 4px; color:#fff; font-weight:700; font-size:1.05rem; }
                .fish-card .sci { font-style:italic; color:#8a9bae; margin-bottom:10px; font-size:0.9rem; }
                .fish-card .price { font-size:1.15em; color:#e67e22; font-weight:700; }
                .fish-card .stock { font-weight:700; }
                .fish-card .action { margin-top:14px; display:flex; align-items:center; gap:10px; }

                /* ── FCFS Strip ── */
                .fh-gate-fcfs {
                    text-align:center; padding:14px;
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.7rem,1.8vw,0.85rem); text-transform:uppercase;
                    letter-spacing:0.14em; color:#b5a165;
                    border-top:1px solid rgba(181,161,101,0.3);
                }

                /* ── Responsive ── */
                @media (min-width:1101px) {
                    .fish-cards, .fh-mobile-controls { display:none !important; }
                }
                @media (max-width:1100px) {
                    .fh-scroll-wrap { display:none !important; }
                    .fh-mobile-controls { display:flex !important; }
                }
                @media (max-width:1100px) and (min-width:601px) {
                    .fish-cards { grid-template-columns:1fr 1fr; }
                }
                @media (max-width:600px) {
                    .fish-cards { grid-template-columns:1fr; }
                    #submit-requests { width:100% !important; padding:16px !important; }
                }
            </style>

            <div class="fh-gate-wrap">

                <!-- ===== Solari Departure Board ===== -->
                <div class="fh-board" id="fh-board">
                    <div class="fh-board-header">&#x2708; FISHOTEL INTERNATIONAL &middot; FHI &middot; GATE OPEN</div>
                    <div class="fh-board-row"><div class="fh-board-label">Airport</div><div class="fh-board-tiles" data-fh-text="FISHOTEL INTERNATIONAL"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Destination</div><div class="fh-board-tiles" data-fh-text="CHAMPLIN, MN"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Flight</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( strtoupper( $batch_name ) ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Status</div><div class="fh-board-tiles" data-fh-text="NOW BOARDING"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Gate Closes</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( $gate_closes_display ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Species</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( $total_species . ' SPECIES AVAILABLE' ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Stock</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( intval( $total_stock ) . ' TOTAL STOCK' ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Boarding</div><div class="fh-board-tiles" data-fh-text="FIRST COME · FIRST SERVED"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Notice</div><div class="fh-board-tiles" id="fh-notice-row" data-fh-text="<?php echo esc_attr( $ticker_resolved[0] ?? '' ); ?>"></div></div>
                </div>

                <!-- ===== My Boarding Request (logged-in) / Observation Deck (logged-out) ===== -->
                <?php if ( is_user_logged_in() ) : ?>
                <div class="fh-boarding-card" id="my-requests">
                    <div class="fh-boarding-header">My Boarding Request</div>
                    <div class="fh-boarding-body">
                        <div id="request-list" style="min-height:36px;color:#8a9bae;">No fish requested yet.</div>
                        <div id="cart-total">Total: $0.00</div>
                        <button id="submit-requests">Submit My Requests</button>
                    </div>
                </div>
                <?php else : ?>
                <div class="fh-obs-deck">
                    <div class="fh-obs-header">Observation Deck</div>
                    <div class="fh-obs-body">
                        <p>Log in to place your boarding request</p>
                        <div class="fh-obs-counts">
                            <div class="fh-obs-count-item">
                                <div class="fh-obs-num"><?php echo $total_species; ?></div>
                                <div class="fh-obs-lbl">Species</div>
                            </div>
                            <div class="fh-obs-count-item">
                                <div class="fh-obs-num"><?php echo $total_stock; ?></div>
                                <div class="fh-obs-lbl">Available</div>
                            </div>
                        </div>
                        <button class="fh-obs-login-btn" onclick="showLoginModal()">Log In to Request</button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ===== Departure Manifest ===== -->
                <div class="fh-manifest-card">
                    <div class="fh-manifest-header">Departure Manifest</div>

                    <!-- Mobile controls -->
                    <div class="fh-mobile-controls" style="padding:12px 16px 0;">
                        <select id="mobile-sort">
                            <option value="sci">Scientific Name A-Z</option>
                            <option value="common">Common Name A-Z</option>
                            <option value="price-low">Price Low to High</option>
                            <option value="price-high">Price High to Low</option>
                            <option value="stock-high">Stock High to Low</option>
                        </select>
                        <input type="text" id="mobile-search" placeholder="Search manifest...">
                    </div>

                    <!-- Desktop table -->
                    <div class="fh-scroll-wrap">
                        <table class="fishotel-open-table">
                            <thead><tr>
                                <th data-sort="common">Common Name</th>
                                <th data-sort="sci">Scientific Name</th>
                                <th style="text-align:center;">Size</th>
                                <th style="text-align:right;" data-sort="price">Avg Price</th>
                                <th style="text-align:center;" data-sort="stock">Stock</th>
                                <th style="text-align:center;">Action</th>
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
                                $stock_class = $stock > 5 ? 'fh-stock-green' : ( $stock > 0 ? 'fh-stock-orange' : 'fh-stock-red' );
                                echo '<tr data-price="' . $price . '" data-stock="' . $stock . '" data-common="' . esc_attr( strtolower( $master->post_title ) ) . '" data-sci="' . esc_attr( strtolower( $sci_name ) ) . '">';
                                echo '<td style="font-weight:600;">' . esc_html( $master->post_title ) . '</td>';
                                echo '<td style="font-style:italic;color:#8a9bae;">' . esc_html( $sci_name ) . '</td>';
                                echo '<td style="text-align:center;">' . ( $size ? '<span class="fh-size-badge">' . esc_html( $size ) . '</span>' : '&mdash;' ) . '</td>';
                                echo '<td style="text-align:right;color:#e67e22;font-weight:600;">$' . number_format( $price, 2 ) . '</td>';
                                echo '<td style="text-align:center;" class="' . $stock_class . '">' . $stock . '</td>';
                                echo '<td style="text-align:center;white-space:nowrap;">';
                                if ( $stock > 0 ) {
                                    echo '<div class="fh-qty-wrap">';
                                    echo '<button class="qty-minus">&#x2212;</button>';
                                    echo '<input type="number" min="1" value="1" class="qty-input">';
                                    echo '<button class="qty-plus">+</button>';
                                    echo '</div>';
                                    echo '<button class="add-to-request fh-req-btn" data-batch-id="' . $bp->ID . '" data-price="' . $price . '" data-fish-name="' . esc_attr( $master->post_title ) . '">Request</button>';
                                } else {
                                    echo '<span class="fh-closed-stamp">Closed</span>';
                                }
                                echo '</td>';
                                echo '</tr>';
                            } ?>
                            </tbody></table>
                    </div>

                    <!-- Mobile cards -->
                    <div class="fish-cards" style="padding:16px;">
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
                            $stock_class = $stock > 5 ? 'fh-stock-green' : ( $stock > 0 ? 'fh-stock-orange' : 'fh-stock-red' );
                            echo '<div class="fish-card" data-price="' . $price . '" data-stock="' . $stock . '" data-common="' . esc_attr( strtolower( $master->post_title ) ) . '" data-sci="' . esc_attr( strtolower( $sci_name ) ) . '">';
                            echo '<h4>' . esc_html( $master->post_title ) . '</h4>';
                            echo '<div class="sci">' . esc_html( $sci_name ) . '</div>';
                            echo '<div style="margin:10px 0;">';
                            if ( $size ) echo '<span class="fh-size-badge" style="margin-right:8px;">' . esc_html( $size ) . '</span>';
                            echo '<span class="price">$' . number_format( $price, 2 ) . '</span>';
                            echo ' <span class="stock ' . $stock_class . '">Stock: ' . $stock . '</span>';
                            echo '</div>';
                            if ( $stock > 0 ) {
                                echo '<div class="action">';
                                echo '<div class="fh-qty-wrap">';
                                echo '<button class="qty-minus" style="padding:6px 10px;">&#x2212;</button>';
                                echo '<input type="number" min="1" value="1" class="qty-input" style="width:48px;padding:6px 0;">';
                                echo '<button class="qty-plus" style="padding:6px 10px;">+</button>';
                                echo '</div>';
                                echo '<button class="add-to-request fh-req-btn" data-batch-id="' . $bp->ID . '" data-price="' . $price . '" data-fish-name="' . esc_attr( $master->post_title ) . '" style="flex:1;padding:10px;font-size:0.95em;">Request</button>';
                                echo '</div>';
                            } else {
                                echo '<div style="margin-top:12px;"><span class="fh-closed-stamp">Closed</span></div>';
                            }
                            echo '</div>';
                        } ?>
                    </div>

                    <!-- FCFS Strip -->
                    <div class="fh-gate-fcfs">First Come &middot; First Served</div>
                </div>

            </div><!-- .fh-gate-wrap -->

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

                    // ── Solari Departure Board ──
                    (function() {
                        var CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789·,.- ';
                        var board = document.getElementById('fh-board');
                        if (!board) return;

                        // Build tiles for every data-fh-text row
                        function buildTiles(container, text) {
                            container.innerHTML = '';
                            for (var i = 0; i < text.length; i++) {
                                var c = text[i];
                                var flap = document.createElement('div');
                                flap.className = c === ' ' ? 'fh-flap fh-flap-space' : 'fh-flap';
                                flap.setAttribute('data-char', c);
                                container.appendChild(flap);
                            }
                        }

                        // Animate tiles from blank → final text, staggered left-to-right
                        function animateRow(container, text) {
                            var flaps = container.querySelectorAll('.fh-flap');
                            flaps.forEach(function(flap, i) {
                                var finalChar = text[i] || ' ';
                                var isSpace = finalChar === ' ';
                                var flipCount = 6 + Math.floor(Math.random() * 5);
                                var step = 0;
                                var iv;
                                setTimeout(function() {
                                    iv = setInterval(function() {
                                        step++;
                                        if (step >= flipCount) {
                                            clearInterval(iv);
                                            flap.textContent = isSpace ? '' : finalChar;
                                            return;
                                        }
                                        flap.textContent = CHARS[Math.floor(Math.random() * CHARS.length)];
                                    }, 60);
                                }, i * 40);
                            });
                        }

                        // Build all static rows and animate on load
                        var rows = board.querySelectorAll('.fh-board-tiles[data-fh-text]');
                        rows.forEach(function(container) {
                            var text = container.getAttribute('data-fh-text');
                            buildTiles(container, text);
                            animateRow(container, text);
                        });

                        // NOTICE row — cycles through ticker messages
                        var noticeMsgs = <?php echo wp_json_encode( $ticker_resolved ); ?>;
                        var noticeRow = document.getElementById('fh-notice-row');
                        if (noticeRow && noticeMsgs.length > 1) {
                            var noticeIdx = 0;
                            setInterval(function() {
                                noticeIdx = (noticeIdx + 1) % noticeMsgs.length;
                                var msg = noticeMsgs[noticeIdx];
                                buildTiles(noticeRow, msg);
                                animateRow(noticeRow, msg);
                            }, 4000);
                        }
                    })();

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
                            html += '<div style="color:#5a6a7a;font-size:0.82em;font-style:italic;margin:2px 0 6px;font-family:Oswald,sans-serif;">Previously Requested</div>';
                            prevItems.forEach((item, idx) => {
                                const lt = (item.price * item.qty).toFixed(2);
                                const safeName = item.fish_name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
                                html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid rgba(181,161,101,0.15);opacity:0.7;">
                                    <span style="font-style:italic;color:#8a9bae;">${item.fish_name} × ${item.qty} = $${lt}</span>
                                    <button onclick="removePrevItem(this,${idx},'${safeName}',${item.request_id},${item.batch_id},${item.price * item.qty})" title="Remove" style="background:none;border:none;color:#e74c3c;font-size:1.6em;cursor:pointer;padding:0 8px;">×</button>
                                </div>`;
                            });
                        }

                        // Current session new requests.
                        if (cartItems.length > 0) {
                            if (prevItems.length > 0) html += '<div style="color:#b5a165;font-size:0.82em;margin:10px 0 4px;font-family:Oswald,sans-serif;">New Requests</div>';
                            cartItems.forEach((item, index) => {
                                const lineTotal = item.price * item.qty;
                                html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(181,161,101,0.2);" data-line-total="${lineTotal}" data-index="${index}">
                                    <span>${item.fish_name} × ${item.qty} = $${lineTotal.toFixed(2)}</span>
                                    <button onclick="removeItem(this)" title="Remove" style="background:none;border:none;color:#e74c3c;font-size:1.6em;cursor:pointer;padding:0 8px;">×</button>
                                </div>`;
                            });
                        }

                        if (prevItems.length === 0 && cartItems.length === 0) html = '<span style="color:#5a6a7a;">No fish requested yet.</span>';
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
            $batch_origins  = get_option( 'fishotel_batch_origins', [] );

            // Look up origin from per-batch dropdown selection
            $origin_name = 'International Waters';
            $origin_lat  = 0;
            $origin_lng  = -160;
            $selected_origin = $batch_origins[ $batch_name ] ?? '';
            if ( $selected_origin ) {
                foreach ( $origin_locs as $loc ) {
                    if ( $loc['name'] === $selected_origin ) {
                        $origin_name = $loc['name'];
                        $origin_lat  = (float) $loc['lat'];
                        $origin_lng  = (float) $loc['lng'];
                        break;
                    }
                }
            }

            // Destination: center-US
            $dest_lat = 39.8283;
            $dest_lng = -95.5795;

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

            // Flight progress: hours_elapsed / total_hours for smooth real-time movement
            $closed_dates   = get_option( 'fishotel_batch_closed_dates', [] );
            $closed_date    = $closed_dates[ $batch_name ] ?? '';
            $progress       = 0.05; // default: just departed
            $arrived        = false;
            $days_left      = null;
            $quarantine_day = null;

            if ( $closed_date && $arrival_date ) {
                $closed_ts  = strtotime( $closed_date );
                $arrival_ts = strtotime( $arrival_date . ' 12:00:00 America/Chicago' );
                $now_ts     = time();
                $total_hours = max( ( $arrival_ts - $closed_ts ) / 3600, 1 );
                $elapsed_hrs = ( $now_ts - $closed_ts ) / 3600;
                $days_until  = (int) ceil( ( $arrival_ts - $now_ts ) / 86400 );

                if ( $arrival_ts <= $now_ts ) {
                    $arrived        = true;
                    $progress       = 1.0;
                    $quarantine_day = abs( $days_until ) + 1;
                    if ( $quarantine_day > 14 ) $quarantine_day = 14;
                } else {
                    $progress  = min( max( $elapsed_hrs / $total_hours, 0.02 ), 0.98 );
                    $days_left = $days_until;
                }
            } elseif ( $arrival_date ) {
                // Has arrival date but no closed date — estimate with 14-day transit
                $arrival_ts = strtotime( $arrival_date . ' 12:00:00 America/Chicago' );
                $now_ts     = time();
                $days_until = (int) ceil( ( $arrival_ts - $now_ts ) / 86400 );

                if ( $arrival_ts <= $now_ts ) {
                    $arrived        = true;
                    $progress       = 1.0;
                    $quarantine_day = abs( $days_until ) + 1;
                    if ( $quarantine_day > 14 ) $quarantine_day = 14;
                } else {
                    $total_hours = 14 * 24;
                    $elapsed_hrs = max( $total_hours - ( $days_until * 24 ), 0 );
                    $progress    = min( max( $elapsed_hrs / $total_hours, 0.02 ), 0.98 );
                    $days_left   = $days_until;
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
                    top: 23px;
                    left: 8px;
                    animation-delay: 0s;
                }
                .fh-nav-green {
                    background: #44ff66;
                    box-shadow: 0 0 3px 2px rgba(40,255,80,0.8), 0 0 6px 3px rgba(0,255,50,0.4);
                    top: 23px;
                    left: 63px;
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

        // ─── Stage 3b: Arrival tracking view (arrived) ────────────────────
        if ( $status === 'arrived' ) {
            $arrival_dates = get_option( 'fishotel_batch_arrival_dates', [] );
            $arrival_date  = $arrival_dates[ $batch_name ] ?? '';
            $arrival_fmt   = $arrival_date ? date( 'F j, Y', strtotime( $arrival_date ) ) : '';
            $qt_end_fmt    = $arrival_date ? date( 'F j, Y', strtotime( $arrival_date . ' +14 days' ) ) : '';

            // Load ALL non-admin requests for this batch, sorted by post date (FCFS)
            $all_requests = get_posts( [
                'post_type'   => 'fish_request',
                'numberposts' => -1,
                'post_status' => 'any',
                'orderby'     => 'date',
                'order'       => 'ASC',
                'meta_query'  => [ [ 'key' => '_batch_name', 'value' => $batch_name, 'compare' => '=' ] ],
            ] );

            // Build FCFS queue per species: batch_id => [ [customer_id, qty, cumulative_end], ... ]
            $fcfs = [];
            foreach ( $all_requests as $req ) {
                if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
                $cust_id   = intval( get_post_meta( $req->ID, '_customer_id', true ) );
                $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                foreach ( $req_items as $item ) {
                    $bid = intval( $item['batch_id'] ?? 0 );
                    $qty = intval( $item['qty'] ?? 1 );
                    if ( ! $bid ) continue;
                    if ( ! isset( $fcfs[ $bid ] ) ) $fcfs[ $bid ] = [];
                    $prev_end = ! empty( $fcfs[ $bid ] ) ? end( $fcfs[ $bid ] )['cum_end'] : 0;
                    $fcfs[ $bid ][] = [
                        'customer_id' => $cust_id,
                        'qty'         => $qty,
                        'cum_end'     => $prev_end + $qty,
                    ];
                }
            }

            // Arrival meta per species
            $species_arrival = [];
            foreach ( $batch_posts as $bp ) {
                $recv = intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
                $doa  = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
                $species_arrival[ $bp->ID ] = [ 'received' => $recv, 'doa' => $doa, 'alive' => $recv - $doa ];
            }

            // Current user items
            $my_items   = [];
            $uid        = is_user_logged_in() ? get_current_user_id() : 0;
            if ( $uid ) {
                foreach ( $all_requests as $req ) {
                    if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
                    if ( intval( get_post_meta( $req->ID, '_customer_id', true ) ) !== $uid ) continue;
                    $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                    foreach ( $req_items as $item ) {
                        $my_items[] = $item;
                    }
                }
            }
            // Quarantine countdown
            $qt_days_left = 0;
            if ( $arrival_date ) {
                $qt_end_ts    = strtotime( $arrival_date . ' +14 days' );
                $qt_days_left = max( 0, (int) ceil( ( $qt_end_ts - time() ) / 86400 ) );
            }
            ?>
            <style>
                .fh-arrival-wrap {
                    max-width:800px; margin:0 auto;
                    font-family:'Oswald',sans-serif; color:#fff;
                }
                /* ── Hero Banner ── */
                .fh-arrival-hero {
                    background:#0c161f; border:2px solid #b5a165; border-radius:12px;
                    padding:36px 28px 28px; text-align:center; margin-bottom:24px;
                    position:relative; overflow:hidden;
                }
                .fh-arrival-hero h2 {
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(1.6rem,4vw,2.4rem); color:#e67e22;
                    text-transform:uppercase; letter-spacing:0.04em; margin:0 0 10px;
                }
                .fh-arrival-hero .fh-subline {
                    color:#b5a165; font-size:clamp(0.85rem,2vw,1.05rem); margin:0 0 20px;
                    font-weight:400;
                }
                .fh-arrival-stamp {
                    display:inline-block; border:3px solid #e67e22; border-radius:4px;
                    padding:8px 22px; font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.95rem,2.5vw,1.3rem); text-transform:uppercase;
                    letter-spacing:0.06em; transform:rotate(-3deg); color:#e67e22;
                    margin-bottom:18px;
                }
                .fh-arrival-countdown {
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.8rem,2vw,1rem); text-transform:uppercase;
                    letter-spacing:0.1em; color:#b5a165;
                }
                .fh-arrival-countdown strong { color:#e67e22; font-size:1.3em; }

                /* ── Cards (manifest style) ── */
                .fh-arr-card {
                    margin-bottom:24px; background:#0c161f; border:2px solid #b5a165;
                    border-radius:10px; overflow:hidden;
                    font-family:'Oswald',sans-serif; color:#fff;
                }
                .fh-arr-card-header {
                    padding:14px 24px; border-bottom:1px solid #b5a165;
                    font-weight:700; font-size:clamp(0.85rem,2vw,1.1rem);
                    text-transform:uppercase; letter-spacing:0.12em; color:#b5a165;
                }
                .fh-arr-tbl { width:100%; border-collapse:collapse; font-size:0.88rem; }
                .fh-arr-tbl thead tr { background:rgba(181,161,101,0.15); }
                .fh-arr-tbl th {
                    text-align:left; color:#b5a165; font-weight:400; font-size:11px;
                    text-transform:uppercase; letter-spacing:0.08em; padding:8px 14px;
                }
                .fh-arr-tbl td { padding:8px 14px; font-size:14px; color:#fff; }
                .fh-arr-tbl tbody tr:nth-child(odd) { background:#0c161f; }
                .fh-arr-tbl tbody tr:nth-child(even) { background:#0f1e2d; }

                /* ── Indicator lights ── */
                .fh-light {
                    display:inline-block; width:12px; height:12px; border-radius:50%;
                }
                .fh-light-green {
                    background:#44ff66;
                    box-shadow:0 0 4px 2px rgba(40,255,80,0.6), 0 0 10px 4px rgba(0,255,50,0.25);
                    animation: fh-glow-green 2s ease-in-out infinite;
                }
                .fh-light-red {
                    background:#ff4444;
                    box-shadow:0 0 4px 2px rgba(255,40,40,0.6), 0 0 10px 4px rgba(255,0,0,0.25);
                    animation: fh-glow-red 1.5s ease-in-out infinite;
                }
                @keyframes fh-glow-green {
                    0%,100% { box-shadow:0 0 4px 2px rgba(40,255,80,0.6), 0 0 10px 4px rgba(0,255,50,0.25); }
                    50% { box-shadow:0 0 6px 3px rgba(40,255,80,0.8), 0 0 14px 6px rgba(0,255,50,0.4); }
                }
                @keyframes fh-glow-red {
                    0%,100% { box-shadow:0 0 4px 2px rgba(255,40,40,0.6), 0 0 10px 4px rgba(255,0,0,0.25); }
                    50% { box-shadow:0 0 6px 3px rgba(255,40,40,0.8), 0 0 14px 6px rgba(255,0,0,0.4); }
                }

                .fh-pos-badge {
                    display:inline-block; color:#b5a165; font-family:'Oswald',sans-serif;
                    font-weight:700; font-size:13px; letter-spacing:0.04em;
                }

                /* ── QT Footer ── */
                .fh-arrival-footer {
                    text-align:center; padding:20px; font-family:'Oswald',sans-serif;
                    font-weight:700; font-size:clamp(0.85rem,2vw,1.05rem);
                    text-transform:uppercase; letter-spacing:0.06em; color:#e67e22;
                }

                .fh-arr-login {
                    background:#0c161f; border:2px solid #b5a165; border-radius:10px;
                    padding:20px 24px; text-align:center; margin-bottom:24px; color:#aaa;
                }

                .fh-arr-sci { font-style:italic; color:#8a9bae; }
            </style>
            <div class="fh-arrival-wrap">

                <!-- ===== Hero Banner ===== -->
                <div class="fh-arrival-hero">
                    <h2>Your Fish Are Here!</h2>
                    <p class="fh-subline">
                        <?php echo esc_html( $batch_name ); ?>
                        <?php if ( $arrival_fmt ) : ?>
                            &middot; Arrived <?php echo esc_html( $arrival_fmt ); ?>
                        <?php endif; ?>
                    </p>
                    <div class="fh-arrival-stamp">ARRIVED</div>
                    <?php if ( $qt_days_left > 0 ) : ?>
                    <div class="fh-arrival-countdown">In Quarantine &mdash; <strong><?php echo $qt_days_left; ?></strong> Day<?php echo $qt_days_left !== 1 ? 's' : ''; ?> Remaining</div>
                    <?php else : ?>
                    <div class="fh-arrival-countdown" style="color:#27ae60;">Quarantine Complete</div>
                    <?php endif; ?>
                </div>

                <!-- ===== Your Fish Table ===== -->
                <?php if ( $uid && ! empty( $my_items ) ) : ?>
                <div class="fh-arr-card">
                    <div class="fh-arr-card-header">Your Fish &mdash; Arrival Status</div>
                    <div style="overflow-x:auto;">
                    <table class="fh-arr-tbl">
                        <thead><tr>
                            <th>Common Name</th>
                            <th style="text-align:center;">Requested</th>
                            <th style="text-align:center;">Alive</th>
                            <th style="text-align:center;">Position</th>
                            <th style="text-align:center;">Status</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $my_items as $item ) :
                            $bid      = intval( $item['batch_id'] ?? 0 );
                            $my_qty   = intval( $item['qty'] ?? 1 );
                            $sa       = $species_arrival[ $bid ] ?? [ 'received' => 0, 'doa' => 0, 'alive' => 0 ];
                            $alive    = $sa['alive'];

                            $position = '—';
                            $filled   = false;
                            if ( isset( $fcfs[ $bid ] ) ) {
                                foreach ( $fcfs[ $bid ] as $entry ) {
                                    if ( $entry['customer_id'] === $uid ) {
                                        $position = $entry['cum_end'];
                                        $filled   = $alive >= $entry['cum_end'];
                                        break;
                                    }
                                }
                            }
                        ?>
                        <tr>
                            <td style="font-weight:500;"><?php echo esc_html( $item['fish_name'] ); ?></td>
                            <td style="text-align:center;"><?php echo $my_qty; ?></td>
                            <td style="text-align:center;"><?php echo $alive; ?></td>
                            <td style="text-align:center;"><span class="fh-pos-badge">#<?php echo esc_html( $position ); ?></span></td>
                            <td style="text-align:center;"><span class="fh-light <?php echo $filled ? 'fh-light-green' : 'fh-light-red'; ?>"></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php elseif ( ! $uid ) : ?>
                <div class="fh-arr-login">
                    <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="color:#e67e22;text-decoration:none;font-family:'Oswald',sans-serif;font-size:1.1rem;text-transform:uppercase;letter-spacing:0.08em;">Log in to see your arrival status</a>
                </div>
                <?php endif; ?>

                <!-- ===== Full Species Summary ===== -->
                <div class="fh-arr-card">
                    <div class="fh-arr-card-header">Full Species Summary</div>
                    <div style="overflow-x:auto;">
                    <table class="fh-arr-tbl">
                        <thead><tr>
                            <th>Common Name</th>
                            <th>Scientific Name</th>
                            <th style="text-align:center;width:60px;">Arrived</th>
                            <th style="text-align:center;width:50px;">DOA</th>
                            <th style="text-align:center;width:50px;">Alive</th>
                            <th style="text-align:center;width:50px;">Fill</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $batch_posts as $bp ) :
                            $master_id = get_post_meta( $bp->ID, '_master_id', true );
                            $sci_name  = $master_id ? (string) get_post_meta( $master_id, '_scientific_name', true ) : '';
                            $sa        = $species_arrival[ $bp->ID ] ?? [ 'received' => 0, 'doa' => 0, 'alive' => 0 ];
                            $total_demand = 0;
                            if ( isset( $fcfs[ $bp->ID ] ) ) {
                                $last = end( $fcfs[ $bp->ID ] );
                                $total_demand = $last ? $last['cum_end'] : 0;
                            }
                            $fill_ok = ( $total_demand === 0 ) || ( $sa['alive'] >= $total_demand );
                        ?>
                        <tr>
                            <td style="font-weight:500;"><?php echo esc_html( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $bp->post_title ) ); ?></td>
                            <td class="fh-arr-sci"><?php echo esc_html( $sci_name ); ?></td>
                            <td style="text-align:center;"><?php echo $sa['received']; ?></td>
                            <td style="text-align:center;color:<?php echo $sa['doa'] > 0 ? '#ff4444' : '#444'; ?>;"><?php echo $sa['doa']; ?></td>
                            <td style="text-align:center;color:#44ff66;font-weight:700;"><?php echo $sa['alive']; ?></td>
                            <td style="text-align:center;">
                                <?php if ( $total_demand === 0 ) : ?>
                                    <span style="color:#444;">—</span>
                                <?php else : ?>
                                    <span class="fh-light <?php echo $fill_ok ? 'fh-light-green' : 'fh-light-red'; ?>"></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <!-- ===== QT Footer ===== -->
                <?php if ( $qt_end_fmt ) : ?>
                <div class="fh-arrival-footer">
                    Quarantine ends <strong><?php echo esc_html( $qt_end_fmt ); ?></strong>
                </div>
                <?php endif; ?>

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
