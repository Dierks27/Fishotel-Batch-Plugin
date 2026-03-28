<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function fh_generate_chip_scatter( $batch_name, $user_id ) {
    $variants = [ 'Casino-Chip.png', 'Casino-Chip-02.png', 'Casino-Chip-03.png' ];
    $html     = '';

    // Time-based chip stack (changes every 4 hours)
    $time_block = floor( time() / ( 4 * 3600 ) );
    $stack_seed = abs( crc32( $batch_name . $user_id . $time_block ) );

    // Dice roll: plus or minus (bit 0), amount 1-6 (bits 1-3)
    $is_plus      = ( $stack_seed & 1 ) === 1;
    $change_amount = 1 + ( ( $stack_seed >> 1 ) % 6 );
    $chip_delta   = $is_plus ? $change_amount : -$change_amount;
    $chip_count   = max( 1, min( 11, 5 + $chip_delta ) );

    // Cluster chips in a pile around a center point
    $center_x = 73;
    $center_y = 18;

    for ( $i = 0; $i < $chip_count; $i++ ) {
        $seed    = abs( crc32( $batch_name . $user_id . $time_block . $i ) );
        $variant = $variants[ $seed % 3 ];
        $src     = plugins_url( 'assists/casino/' . $variant, FISHOTEL_PLUGIN_FILE );
        // Clustered position: center ± 8%
        $x       = $center_x + ( ( $seed >> 4 ) % 17 ) - 8;
        $y       = $center_y + ( ( $seed >> 8 ) % 17 ) - 8;
        // Rotation: -30 to +30
        $rot     = ( $seed % 61 ) - 30;
        // Size: fixed 64px
        $size    = 64;
        // Opacity: 0.85-0.95
        $opacity = 0.85 + ( ( ( $seed >> 16 ) % 11 ) / 100 );

        $html .= sprintf(
            '<img src="%s" alt="" style="position:absolute;left:%d%%;top:%d%%;width:%dpx;height:auto;transform:rotate(%ddeg);opacity:%.2f;pointer-events:none;z-index:1;filter:drop-shadow(2px 4px 6px rgba(0,0,0,0.7)) drop-shadow(0px 1px 2px rgba(0,0,0,0.9));" />',
            esc_url( $src ), $x, $y, $size, $rot, $opacity
        );
    }
    return $html;
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
                    'THANK YOU FOR FLYING FISHOTEL',
                    'ALL FISH QUARANTINE INSPECTED',
                    '{species} SPECIES THIS BATCH',
                    'DEPOSIT REQUIRED TO REQUEST',
                    'FIRST COME FIRST SERVED',
                ];
            }
            $ticker_resolved = [];
            foreach ( $ticker_msgs as $msg ) {
                $msg = str_replace( [ '{species}', '{stock}' ], [ $total_species, intval( $total_stock ) ], $msg );
                $msg = strtoupper( trim( $msg ) );
                $ticker_resolved[] = $msg;
            }
            $closed_dates   = get_option( 'fishotel_batch_closed_dates', [] );
            $closed_raw     = $closed_dates[ $batch_name ] ?? '';
            $closed_times   = get_option( 'fishotel_batch_closed_times', [] );
            $closed_time    = $closed_times[ $batch_name ] ?? '23:59';
            if ( $closed_raw ) {
                $date_part = strtoupper( date( 'M j', strtotime( $closed_raw ) ) );
                $hour = intval( substr( $closed_time, 0, 2 ) );
                $min  = substr( $closed_time, 3, 2 );
                if ( $hour === 0 )       $time_part = '12:' . $min . 'AM';
                elseif ( $hour < 12 )    $time_part = $hour . ':' . $min . 'AM';
                elseif ( $hour === 12 )  $time_part = '12:' . $min . 'PM';
                else                     $time_part = ( $hour - 12 ) . ':' . $min . 'PM';
                if ( $min === '00' ) $time_part = str_replace( ':00', '', $time_part );
                $gate_closes_display = $date_part . ' · ' . $time_part;
            } else {
                $gate_closes_display = 'TBD';
            }

            // Generate flight number: FHI + origin code + date digits
            $batch_origin  = get_option( 'fishotel_batch_origins', [] );
            $origin_name   = $batch_origin[ $batch_name ] ?? $batch_name;
            $origin_code   = strtoupper( substr( preg_replace( '/[^a-zA-Z]/', '', $origin_name ), 0, 2 ) );
            // Extract digits from batch name for route number (month+day)
            preg_match_all( '/\d+/', $batch_name, $dmatches );
            $route_num = '';
            foreach ( $dmatches[0] as $d ) $route_num .= $d;
            $route_num = substr( $route_num, 0, 4 ); // cap at 4 digits
            if ( ! $route_num ) $route_num = '001';
            $flight_number = 'FHI-' . $origin_code . $route_num;

            // Boarding pass stub data
            $arrival_dates  = get_option( 'fishotel_batch_arrival_dates', [] );
            $arrival_date   = $arrival_dates[ $batch_name ] ?? '';
            $bp_deposit     = $this->get_deposit_amount( $batch_name );
            ?>

            <style>
                #fishotel-login-modal input:-webkit-autofill,
                #fishotel-login-modal input:-webkit-autofill:hover,
                #fishotel-login-modal input:-webkit-autofill:focus {
                    -webkit-box-shadow: 0 0 0 1000px #071420 inset !important;
                    -webkit-text-fill-color: #e8dcc0 !important;
                    caret-color: #e8dcc0;
                }
            </style>
            <!-- ===== Login Modal — GATE ACCESS REQUIRED ===== -->
            <div id="fishotel-login-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(4,12,22,0.95);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='150' height='150'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='150' height='150' filter='url(%23n)' opacity='0.06'/%3E%3C/svg%3E&quot;),linear-gradient(165deg,#071420 0%,#060f1a 50%,#040c15 100%);background-size:150px 150px,cover;border:1px solid rgba(181,161,101,0.4);border-radius:3px;box-shadow:0 20px 60px rgba(0,0,0,0.6),0 0 0 1px rgba(181,161,101,0.1);padding:36px 40px 32px;width:400px;max-width:92%;color:#fff;position:relative;overflow:hidden;">
                    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,#b5a165 20%,#d4bc7e 50%,#b5a165 80%,transparent);"></div>
                    <h3 style="margin:0 0 24px;font-family:'Special Elite',monospace;font-size:13px;letter-spacing:0.3em;color:#d4bc7e;text-transform:uppercase;text-align:center;">Gate Access Required</h3>
                    <form method="post" action="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
                        <?php wp_nonce_field( 'fishotel_login', 'fishotel_login_nonce' ); ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( get_permalink() ); ?>">
                        <input type="hidden" name="rememberme" value="forever">
                        <p><input type="text" name="log" placeholder="USERNAME OR EMAIL" autocomplete="username" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.06);border:1px solid rgba(181,161,101,0.3);color:#e8dcc0;font-family:'Special Elite',monospace;letter-spacing:0.05em;box-sizing:border-box;" onfocus="this.style.borderColor='rgba(181,161,101,0.7)';this.style.background='rgba(255,255,255,0.09)';this.style.outline='none'" onblur="this.style.borderColor='rgba(181,161,101,0.3)';this.style.background='rgba(255,255,255,0.06)'"></p>
                        <p><input type="password" name="pwd" placeholder="PASSWORD" autocomplete="current-password" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.06);border:1px solid rgba(181,161,101,0.3);color:#e8dcc0;font-family:'Special Elite',monospace;letter-spacing:0.05em;box-sizing:border-box;" onfocus="this.style.borderColor='rgba(181,161,101,0.7)';this.style.background='rgba(255,255,255,0.09)';this.style.outline='none'" onblur="this.style.borderColor='rgba(181,161,101,0.3)';this.style.background='rgba(255,255,255,0.06)'"></p>
                        <p><button type="submit" style="width:100%;padding:12px;background:rgba(181,161,101,0.22);border:1.5px solid #d4bc7e;color:#f0e0a0;font-family:'Special Elite',monospace;font-size:12px;letter-spacing:0.25em;text-transform:uppercase;cursor:pointer;box-shadow:0 0 18px rgba(181,161,101,0.15);" onmouseover="this.style.background='rgba(181,161,101,0.32)'" onmouseout="this.style.background='rgba(181,161,101,0.22)'"><span class="fh-modal-btn-ornament">&#x2726;</span> LOG IN <span class="fh-modal-btn-ornament">&#x2726;</span></button></p>
                        <p style="text-align:center;margin:15px 0 0 0;"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" style="color:rgba(212,188,126,0.5);font-family:'Special Elite',monospace;font-size:10px;letter-spacing:0.15em;text-transform:uppercase;text-decoration:none;" onmouseover="this.style.color='#d4bc7e'" onmouseout="this.style.color='rgba(212,188,126,0.5)'">Forgot Password?</a></p>
                    </form>
                    <button onclick="closeLoginModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;color:rgba(212,188,126,0.5);font-size:18px;cursor:pointer;" onmouseover="this.style.color='#d4bc7e'" onmouseout="this.style.color='rgba(212,188,126,0.5)'">&#x2715;</button>
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
            $bp_deposit_paid = is_user_logged_in() && ( floatval( get_user_meta( get_current_user_id(), '_fishotel_wallet_balance', true ) ) >= $bp_deposit || ! empty( $prev_items ) );

            // Aggregate total requested qty per fish across ALL users for this batch
            $all_batch_reqs = get_posts( [
                'post_type'   => 'fish_request',
                'numberposts' => -1,
                'post_status' => 'any',
                'meta_query'  => [
                    [ 'key' => '_batch_name', 'value' => $batch_name, 'compare' => '=' ],
                ],
            ] );
            $total_qty_map = [];
            foreach ( $all_batch_reqs as $req ) {
                if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
                $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                foreach ( $req_items as $ritem ) {
                    $bid = $ritem['batch_id'] ?? '';
                    if ( $bid ) {
                        $total_qty_map[ $bid ] = ( $total_qty_map[ $bid ] ?? 0 ) + (int) $ritem['qty'];
                    }
                }
            }

            // Build per-fish qty map for current user's existing requests (for spinner pre-fill)
            $user_cart_qty = [];
            foreach ( $prev_items as $pi ) {
                $bid = $pi['batch_id'] ?? '';
                if ( $bid ) {
                    $user_cart_qty[ $bid ] = ( $user_cart_qty[ $bid ] ?? 0 ) + (int) $pi['qty'];
                }
            }
            ?>

            <link href="https://fonts.googleapis.com/css2?family=Special+Elite&family=Klee+One&family=Patrick+Hand&display=swap" rel="stylesheet">
            <style>
                /* ── PanAm Gate Theme — Globals ── */
                .fh-gate-wrap {
                    max-width:900px; margin:0 auto;
                    font-family:'Oswald',sans-serif; color:#fff;
                }
                @media (max-width:1023px) {
                    .fh-gate-wrap {
                        max-width:100%; margin-left:-15px; margin-right:-15px;
                        padding-left:10px; padding-right:10px;
                        box-sizing:border-box;
                    }
                }

                /* ── Solari Departure Board — Authentic Fixed Grid ── */
                .fh-board-wrapper { overflow:hidden; margin-bottom:24px; max-width:900px; }
                .fh-board {
                    width:900px; min-width:900px; box-sizing:border-box;
                    background:#0a0a0a; border-radius:4px;
                    border-top:7px solid #1e1a14;
                    border-left:7px solid #1a1610;
                    border-right:6px solid #14110c;
                    border-bottom:5px solid #100e0a;
                    box-shadow:
                        0 0 0 1px #0a0806,
                        4px 6px 20px rgba(0,0,0,0.85),
                        8px 12px 50px rgba(0,0,0,0.6),
                        inset 0 0 60px rgba(0,0,0,0.4);
                    overflow:hidden; margin-bottom:24px;
                    position:relative;
                }
                /* Film-grain noise overlay */
                .fh-board::before {
                    content:''; position:absolute; inset:0; z-index:1; pointer-events:none;
                    opacity:0.03; mix-blend-mode:overlay;
                    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
                }
                .fh-board-header {
                    background:linear-gradient(to bottom, #161410, #111);
                    padding:9px 20px; display:flex; align-items:center; justify-content:space-between;
                    font-family:'Oswald',sans-serif; font-weight:700;
                    text-transform:uppercase;
                    border-bottom:1px solid #2a2520;
                    position:relative; z-index:2;
                }
                .fh-board-header-left {
                    font-size:22px; letter-spacing:0.08em; color:#f0e8d0;
                    text-shadow:0 0 8px rgba(212,188,126,0.6), 0 0 20px rgba(181,161,101,0.3);
                    display:flex; align-items:center; gap:8px;
                }
                .fh-board-header-icon { opacity:0.7; font-size:20px; }
                .fh-board-header-right {
                    font-size:13px; letter-spacing:0.14em; color:#8a7a50;
                }
                .fh-board-footer {
                    background:linear-gradient(to top, #161410, #111);
                    padding:7px 20px; display:flex; align-items:center; justify-content:space-between;
                    font-family:'Oswald',sans-serif; font-weight:700;
                    text-transform:uppercase;
                    border-top:1px solid #2a2520;
                    position:relative; z-index:2;
                }
                .fh-board-footer-left {
                    font-size:13px; letter-spacing:0.14em; color:#8a7a50;
                }
                .fh-board-footer-right {
                    font-size:13px; letter-spacing:0.14em; color:#8a7a50;
                }
                .fh-footer-lit {
                    color:#d4bc7e;
                    text-shadow:0 0 8px rgba(212,188,126,0.6), 0 0 20px rgba(181,161,101,0.3);
                }
                .fh-footer-unlit {
                    color:#2a2a2a;
                    text-shadow:none;
                }
                .fh-footer-sep {
                    color:#5a4e34;
                    text-shadow:none;
                }
                .fh-board-row {
                    display:flex;
                    border-bottom:1px solid #0d0d0d;
                    box-shadow:inset 0 1px 0 #252520;
                    position:relative; z-index:2;
                }
                .fh-board-row:last-child { border-bottom:none; box-shadow:none; }
                .fh-board-label {
                    width:130px; min-width:130px; box-sizing:border-box; background:#111;
                    padding:4px 10px 4px 12px; display:flex; align-items:center; justify-content:flex-end;
                    font-family:'Oswald',sans-serif; font-weight:700; font-size:clamp(0.55rem,1.4vw,0.72rem);
                    text-transform:uppercase; letter-spacing:0.12em; color:#8a7a50;
                    border-right:1px solid #1a1a10;
                }
                .fh-board-tiles {
                    flex:1; display:flex; align-items:center;
                    padding:4px 6px; flex-wrap:nowrap; overflow:hidden;
                    background:#0a0a0a;
                }
                /* ── Split-Flap Tiles — Authentic Fixed Grid ── */
                .fh-flap {
                    width:32px; height:46px; min-width:32px;
                    background:#141414;
                    border-radius:2px;
                    box-shadow:inset 0 1px 0 rgba(255,255,255,0.04), 0 3px 0 #0a0806, 0 2px 6px rgba(0,0,0,0.9);
                    display:flex; align-items:center; justify-content:center;
                    font-family:'Courier New',monospace; font-weight:700;
                    font-size:24px; color:#c8a84b; letter-spacing:-0.5px;
                    text-transform:uppercase; position:relative;
                    opacity:0.92;
                }
                .fh-flap:nth-child(odd) { background:#121212; }
                .fh-flap::after {
                    content:''; position:absolute; left:0; top:50%;
                    width:100%; height:1px; background:#000;
                }
                /* ── Status Light ── */
                .fh-status-light {
                    display:inline-block; width:10px; height:10px;
                    border-radius:50%; margin-right:8px; vertical-align:middle;
                    position:relative; top:-1px;
                }
                .fh-status-green {
                    background:#00e040;
                    box-shadow:0 0 4px #00e040, 0 0 10px #00cc33, 0 0 20px rgba(0,200,50,0.4);
                    animation:fh-pulse-green 2.5s ease-in-out infinite;
                }
                .fh-status-amber {
                    background:#e07b2a;
                    box-shadow:0 0 4px #e07b2a, 0 0 10px #cc6a1f, 0 0 20px rgba(224,123,42,0.4);
                    animation:fh-pulse-amber 2.5s ease-in-out infinite;
                }
                @keyframes fh-pulse-green {
                    0%, 100% { opacity:1; box-shadow:0 0 4px #00e040, 0 0 10px #00cc33, 0 0 20px rgba(0,200,50,0.4); }
                    50% { opacity:0.75; box-shadow:0 0 2px #00e040, 0 0 6px #00cc33, 0 0 12px rgba(0,200,50,0.2); }
                }
                @keyframes fh-pulse-amber {
                    0%, 100% { opacity:1; box-shadow:0 0 4px #e07b2a, 0 0 10px #cc6a1f, 0 0 20px rgba(224,123,42,0.4); }
                    50% { opacity:0.75; box-shadow:0 0 2px #e07b2a, 0 0 6px #cc6a1f, 0 0 12px rgba(224,123,42,0.2); }
                }

                @media (max-width:1023px) {
                    .fh-board {
                        transform-origin:top left;
                        transform:scale(var(--fh-board-scale,1));
                        margin-bottom:calc((var(--fh-board-scale,1) - 1) * 593px);
                    }
                }

                /* ── Boarding Pass Card — cream paper form ── */
                .fh-bp-open { position:relative; margin-bottom:24px; overflow:hidden; border-radius:2px; }
                .fh-bp-open-inner {
                    display:flex; background:#f2ead8; border-radius:2px; overflow:hidden;
                    font-family:'Special Elite',monospace; color:#0a0805; position:relative;
                    border:1px solid #c8b99a;
                    box-shadow:0 4px 24px rgba(0,0,0,0.25);
                }
                .fh-bp-open::after {
                    content:''; position:absolute; inset:0; border-radius:2px;
                    filter:url(#fh-paper-grain); background:rgba(180,165,130,0.06);
                    pointer-events:none; z-index:1; mix-blend-mode:multiply;
                }
                .fh-bp-open-left { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; }
                .fh-bp-open-header {
                    display:flex; justify-content:space-between; align-items:center;
                    padding:8px 20px; background:#1a3a6b; height:32px; box-sizing:border-box;
                    border-bottom:2px solid #d4bc7e;
                }
                .fh-bp-open-header-title {
                    font-size:0.75rem; font-weight:700; text-transform:uppercase;
                    letter-spacing:0.14em; color:#f2ead8;
                    font-family:'Special Elite',monospace;
                }
                .fh-bp-open-header-flight {
                    font-size:0.75rem; font-weight:400; color:#d4bc7e;
                    letter-spacing:0.1em; font-family:'Special Elite',monospace;
                }
                .fh-bp-open-body { padding:16px 20px; flex:1; border-top:3px solid #d4bc7e; }
                /* Combined passenger + route line */
                .fh-bp-open-passenger-line {
                    font-family:'Special Elite',monospace; font-size:13px; color:#0a0805;
                    margin:0 0 12px; padding:8px 10px 10px;
                    border-bottom:2px solid #1a1a2e; line-height:1.5;
                    text-transform:uppercase; letter-spacing:0.04em;
                    background:rgba(181,161,101,0.08);
                }
                .fh-bp-open-passenger-line .fh-bp-pax-label {
                    font-size:10px; color:#3d2b1f; letter-spacing:0.1em; margin-right:6px;
                }
                .fh-bp-open-fish-table {
                    width:100%; border-collapse:collapse; font-size:13px; margin-bottom:6px;
                    font-family:'Special Elite',monospace;
                }
                .fh-bp-open-fish-table thead tr { background:transparent !important; }
                .fh-bp-open-fish-table th,
                .fh-bp-open-inner .fh-bp-open-body .fh-bp-open-fish-table thead th {
                    text-align:left; color:#1a1209 !important; font-weight:400; font-size:11px;
                    text-transform:uppercase; letter-spacing:0.05em; padding:5px 10px;
                    border-top:1.5px solid #3d2b1f !important;
                    border-bottom:1.5px solid #3d2b1f !important;
                    background:transparent !important;
                    font-family:'Special Elite',monospace;
                }
                .fh-bp-open-fish-table th:nth-child(3),
                .fh-bp-open-fish-table th:nth-child(4) { text-align:right; }
                .fh-bp-open-fish-table th:last-child { text-align:center; }
                .fh-bp-open-fish-table td {
                    padding:5px 8px; border-bottom:1px solid rgba(61,43,31,0.15);
                    font-size:15px; color:#0a0805;
                    font-family:'Patrick Hand',cursive; text-transform:none;
                }
                .fh-bp-open-fish-table td:first-child { padding-left:8px; }
                .fh-bp-open-fish-table td:nth-child(2) { text-align:center; }
                .fh-bp-open-fish-table td:nth-child(3),
                .fh-bp-open-fish-table td:nth-child(4) { text-align:right; }
                .fh-bp-open-fish-table td:last-child { text-align:center; }
                .fh-bp-open-fish-table tbody tr:nth-child(odd) { background:#f2ead8; }
                .fh-bp-open-fish-table tbody tr:nth-child(even) { background:#ebe0c4; }
                .fh-bp-open-inner .fh-bp-open-fish-table tbody td,
                .fh-bp-open-inner table.fh-bp-open-fish-table tbody tr td { background:transparent !important; color:#0a0805 !important; }
                .fh-bp-open-inner .fh-bp-open-fish-table tbody tr:nth-child(odd) td,
                .fh-bp-open-inner table.fh-bp-open-fish-table tbody tr:nth-child(odd) td { background:#f2ead8 !important; }
                .fh-bp-open-inner .fh-bp-open-fish-table tbody tr:nth-child(even) td,
                .fh-bp-open-inner table.fh-bp-open-fish-table tbody tr:nth-child(even) td { background:#ebe0c4 !important; }
                .fh-bp-open-fish-table .fh-bp-prev-row { /* same style as new items */ }
                .fh-bp-open-fish-table .fh-bp-remove-btn {
                    background:none; border:none; color:#8b1a1a; font-size:18px;
                    cursor:pointer; padding:4px 8px; line-height:1;
                    min-width:28px; min-height:28px;
                }
                .fh-bp-open-empty {
                    color:#5a4a3a; font-style:italic; padding:10px 0;
                    text-align:center; font-size:0.85rem;
                    font-family:'Special Elite',monospace;
                }
                #cart-total {
                    font-family:'Special Elite',monospace; font-weight:700; color:#0a0805;
                    margin:10px 0; font-size:13px; text-align:right;
                    padding-top:8px; border-top:2px solid #1a1a2e;
                    text-transform:uppercase; letter-spacing:0.04em;
                }
                #submit-requests {
                    width:100%; padding:10px 24px; font-size:13px; font-weight:700;
                    background:transparent; color:#8b1a1a; border:2px solid #8b1a1a; border-radius:0;
                    cursor:pointer; display:block;
                    font-family:'Special Elite',monospace; text-transform:uppercase; letter-spacing:3px;
                    transition:background 0.15s;
                }
                #submit-requests:hover { background:rgba(139,26,26,0.08); }

                /* Stub (right panel) */
                .fh-bp-open-stub {
                    flex:0 0 220px; max-width:220px; box-sizing:border-box;
                    padding:20px 16px 20px 20px;
                    display:flex; flex-direction:column; gap:10px;
                    border-left:2px dashed #1a1a2e; position:relative;
                    background:#f2ead8; overflow:hidden;
                }
                .fh-bp-open-scissors {
                    position:absolute; top:-2px; left:-1px; font-size:16px;
                    color:#1a1a2e; z-index:2; line-height:1;
                    transform:translateX(-50%);
                }
                .fh-bp-open-stub-label {
                    font-size:10px; color:#3d2b1f; text-transform:uppercase;
                    letter-spacing:0.1em; margin:0; font-weight:400;
                    font-family:'Special Elite',monospace;
                }
                .fh-bp-open-stub-value {
                    font-size:0.9rem; font-weight:700; margin:0; color:#0a0805;
                    font-family:'Special Elite',monospace;
                }
                .fh-bp-open-deposit-stamp {
                    display:inline-block; border:2px solid #8b1a1a; border-radius:0;
                    padding:4px 10px; font-weight:700; font-size:0.65rem;
                    text-transform:uppercase; letter-spacing:0.06em;
                    transform:rotate(-12deg); color:#8b1a1a; margin-top:2px;
                    align-self:flex-start;
                    font-family:'Special Elite',monospace;
                }
                .fh-bp-open-vertical {
                    writing-mode:vertical-rl; text-orientation:mixed;
                    font-size:0.85rem; font-weight:700; letter-spacing:0.15em;
                    text-transform:uppercase; color:rgba(26,26,46,0.12);
                    position:absolute; right:4px; top:50%;
                    transform:rotate(180deg) translateY(50%);
                    font-family:'Special Elite',monospace;
                }

                /* Ticket sleeve (logged-out) */
                .fh-ticket-sleeve {
                    font-family:"Special Elite",monospace;
                    background:linear-gradient(165deg,#0d2a4a 0%,#0a2040 50%,#071628 100%);
                    display:flex; flex-direction:column;
                    min-height:420px; overflow:hidden; position:relative;
                }
                .fh-ticket-sleeve::before {
                    content:''; position:absolute; inset:0; pointer-events:none;
                    background:repeating-linear-gradient(-55deg,rgba(181,161,101,0.04) 0px,rgba(181,161,101,0.04) 1px,transparent 1px,transparent 10px);
                }
                .fh-ticket-sleeve::after {
                    content:''; position:absolute; top:0; left:0; right:0; height:3px;
                    background:linear-gradient(90deg,transparent,#b5a165 20%,#d4bc7e 50%,#b5a165 80%,transparent);
                }
                .fh-ts-flight-no { position:absolute; top:14px; right:20px; font-size:11px; letter-spacing:0.18em; color:rgba(212,188,126,0.65); }
                .fh-ts-top { padding:20px 24px 12px; text-align:center; }
                .fh-ts-top-label { font-size:10px; letter-spacing:0.3em; color:rgba(212,188,126,0.8); text-transform:uppercase; }
                .fh-ts-rule { height:1px; margin:0 20px; background:linear-gradient(90deg,transparent,#b5a165 15%,#d4bc7e 50%,#b5a165 85%,transparent); }
                .fh-ts-stripe { background:linear-gradient(180deg,#f5f0e8 0%,#ede8dc 100%); padding:18px 40px 16px; text-align:center; border-top:2px solid #d4bc7e; border-bottom:2px solid #d4bc7e; position:relative; }
                .fh-ts-stripe::before { content:'\2726'; position:absolute; left:18px; top:50%; transform:translateY(-50%); font-size:12px; color:#b5a165; }
                .fh-ts-stripe::after  { content:'\2726'; position:absolute; right:18px; top:50%; transform:translateY(-50%); font-size:12px; color:#b5a165; }
                .fh-ts-name { font-size:28px; letter-spacing:0.18em; color:#0a2040; text-transform:uppercase; line-height:1; }
                .fh-ts-name-sub { font-size:11px; letter-spacing:0.28em; color:#8a6f2e; text-transform:uppercase; margin-top:6px; font-weight:bold; }
                .fh-ts-body { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:12px 24px; gap:4px; }
                .fh-ts-issued-by { font-size:9px; letter-spacing:0.28em; color:rgba(212,188,126,0.6); text-transform:uppercase; }
                .fh-ts-issuer { font-size:13px; letter-spacing:0.18em; color:#d4bc7e; text-transform:uppercase; font-weight:bold; }
                .fh-ts-tagline { font-size:10px; letter-spacing:0.14em; color:rgba(212,188,126,0.75); text-transform:uppercase; font-style:italic; }
                .fh-ts-divider { width:140px; height:1px; margin:4px auto; background:linear-gradient(90deg,transparent,rgba(181,161,101,0.5),transparent); }
                .fh-ts-login {
                    display:inline-block; padding:12px 34px; margin-top:2px;
                    border:1.5px solid #d4bc7e; color:#f0e0a0;
                    font-family:"Special Elite",monospace;
                    font-size:12px; letter-spacing:0.22em;
                    text-transform:uppercase; text-decoration:none;
                    background:rgba(181,161,101,0.22);
                    box-shadow:0 0 18px rgba(181,161,101,0.15),inset 0 1px 0 rgba(255,255,255,0.08);
                }
                .fh-ts-login::before, .fh-ts-login::after { content:'\2726'; font-size:8px; color:#b5a165; margin:0 8px; vertical-align:middle; }
                .fh-ts-login:hover { background:rgba(181,161,101,0.32); color:#fff8e0; text-decoration:none; }
                .fh-ts-fine { font-size:8.5px; letter-spacing:0.1em; color:rgba(212,188,126,0.5); text-align:center; text-transform:uppercase; line-height:1.8; margin-top:6px; padding:0 16px; }
                .fh-ts-bottom-rule { height:3px; background:linear-gradient(90deg,transparent,#b5a165 20%,#d4bc7e 50%,#b5a165 80%,transparent); }

                /* Boarding pass mobile */
                @media (max-width:767px) {
                    .fh-bp-open-inner { flex-direction:column; }
                    .fh-bp-open-left { flex:none; }
                    .fh-bp-open-stub {
                        flex:none; max-width:none; border-left:none; border-top:2px dashed #1a1a2e;
                        flex-direction:row; flex-wrap:wrap; gap:10px 16px;
                        padding:14px 16px;
                    }
                    .fh-bp-open-stub > div { flex:0 0 auto; }
                    .fh-bp-open-vertical { display:none; }
                    .fh-bp-open-scissors { display:none; }
                }

                /* ── Departure Manifest — Clipboard backing board ── */
                .fh-manifest-card {
                    background:transparent;
                    border:none; border-radius:0;
                    overflow:visible; margin-bottom:24px;
                    position:relative;
                    padding:0;
                }

                /* ── Paper sheet — aged document ── */
                .fh-clipboard-paper {
                    background:#f2ead8; margin-top:18px; border-radius:2px;
                    position:relative;
                    border:1px solid #c8b99a;
                    box-shadow:0 4px 40px rgba(0,0,0,0.5), inset 0 0 80px rgba(60,40,20,0.12);
                    overflow:hidden;
                }
                /* Paper grain noise */
                .fh-clipboard-paper::before {
                    content:''; position:absolute; inset:0;
                    filter:url(#fh-manifest-grain); background:rgba(180,165,130,0.06);
                    pointer-events:none; z-index:1; mix-blend-mode:multiply;
                }
                /* Coffee ring stain */
                .fh-clipboard-paper::after {
                    content:''; position:absolute; top:35px; right:60px;
                    width:110px; height:110px; border-radius:50%;
                    border:8px solid rgba(139,105,20,0.07);
                    box-shadow:inset 0 0 12px rgba(139,105,20,0.04), 0 0 6px rgba(139,105,20,0.03);
                    pointer-events:none; z-index:1;
                    transform:rotate(-12deg);
                }

                /* ── Manifest header — official government form ── */
                .fh-manifest-header {
                    padding:24px 28px 16px; background:transparent;
                    border-bottom:3px solid #1a1a2e;
                    position:relative; z-index:2;
                }
                /* Punch holes */
                .fh-punch-hole {
                    position:absolute; top:16px; width:14px; height:14px;
                    border-radius:50%; background:#1a1a2e;
                    border:1px solid #a09080;
                    box-shadow:inset 0 1px 3px rgba(0,0,0,0.5);
                }
                .fh-punch-hole-left { left:16px; }
                .fh-punch-hole-right { right:16px; }
                .fh-manifest-title {
                    font-family:'Special Elite',monospace; font-weight:700;
                    font-size:clamp(1.05rem,2.8vw,1.4rem); text-transform:uppercase;
                    letter-spacing:0.1em; color:#0a0805; text-align:center;
                    margin-bottom:12px;
                }
                .fh-manifest-fields {
                    display:flex; flex-wrap:wrap; gap:4px 24px;
                    justify-content:center;
                    font-family:'Special Elite',monospace; font-size:0.78rem;
                    color:#1a1209; text-transform:uppercase; letter-spacing:0.04em;
                }
                .fh-manifest-fields span {
                    white-space:nowrap;
                }

                /* ── Desktop Table ── */
                .fh-scroll-wrap {
                    overflow-x:auto; width:100%; box-sizing:border-box;
                    scrollbar-width:thin; scrollbar-color:#a09080 #ebe0c4;
                    position:relative; z-index:2;
                }
                .fh-scroll-wrap::-webkit-scrollbar { height:8px; background:#ebe0c4; }
                .fh-scroll-wrap::-webkit-scrollbar-thumb { background:#a09080; border-radius:0; }

                .fishotel-open-table { width:100%; border-collapse:collapse; table-layout:fixed; }
                .fishotel-open-table thead tr { background:#f2ead8; }
                .fh-clipboard-paper .fishotel-open-table thead th {
                    background:#f2ead8 !important; color:#0a0805 !important;
                }
                .fishotel-open-table th {
                    text-align:left; color:#0a0805; font-family:'Special Elite',monospace;
                    font-weight:700; font-size:11px; text-transform:uppercase;
                    letter-spacing:0.06em; padding:10px 10px;
                    border-bottom:2px solid #3d2b1f;
                    font-variant:small-caps;
                }
                .fishotel-open-table th[data-sort] { cursor:pointer; }
                .fishotel-open-table th[data-sort]:hover { color:#8b1a1a; }
                .fishotel-open-table th[data-sort].sort-asc::after { content:" \25B2"; font-size:0.7em; }
                .fishotel-open-table th[data-sort].sort-desc::after { content:" \25BC"; font-size:0.7em; }
                .fishotel-open-table td {
                    padding:10px 10px; font-family:'Special Elite',monospace;
                    font-size:13px; color:#0a0805;
                    height:44px; box-sizing:border-box;
                    border-bottom:1px solid rgba(0,0,0,0.12);
                }
                /* Row number column — document margin */
                .fishotel-open-table .fh-row-num,
                .fishotel-open-table th:first-child {
                    border-right:2px solid rgba(0,0,0,0.08);
                }
                .fishotel-open-table .fh-row-num {
                    width:32px; text-align:center; color:#a09080;
                    font-size:11px; font-family:'Special Elite',monospace;
                    padding:10px 4px; position:relative;
                }
                /* Common Name — allow wrapping */
                .fishotel-open-table td:nth-child(2) {
                    white-space:normal; word-wrap:break-word; overflow-wrap:break-word;
                    position:relative;
                }
                /* Scientific Name — smaller, nowrap */
                .fishotel-open-table td:nth-child(3) {
                    color:#2a1f14 !important; font-style:italic; font-size:12px;
                    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
                }
                /* Inline size badge after common name */
                .fh-size-inline {
                    font-size:10px; color:#8a7a6a; font-family:'Special Elite',monospace;
                    margin-left:5px; letter-spacing:0.03em;
                }
                .fishotel-open-table tbody tr:nth-child(odd) { background:#f2ead8; }
                .fishotel-open-table tbody tr:nth-child(even) { background:#ebe0c4; }
                /* Override dark theme td backgrounds and text colors */
                .fishotel-open-table tbody td { background:transparent !important; }
                .fishotel-open-table tbody tr:nth-child(odd) td { background:transparent !important; }
                .fishotel-open-table tbody tr:nth-child(even) td { background:transparent !important; }
                .fh-clipboard-paper .fishotel-open-table tbody td:nth-child(2) { color:#1a1209 !important; }
                .fh-clipboard-paper .fishotel-open-table tbody td:nth-child(5) { color:#2a1f14 !important; }

                /* ── Stock Colors — typed ink, no dots ── */
                .fh-stock-green { color:#0a0805; font-weight:700; }
                .fh-stock-orange { color:#0a0805; font-weight:700; }
                .fh-stock-red { color:#0a0805; font-weight:700; }
                .fh-stock-low::after {
                    content:'*'; font-family:'Special Elite',monospace;
                    color:#3d2b1f; margin-left:1px;
                }
                .fh-row-closed { opacity:0.5; }

                /* ── Price column — printed price on form ── */
                .fishotel-open-table tbody td:nth-child(4) {
                    font-size:13px; color:#2a1f14; font-weight:500;
                }

                /* ── Size Badge (mobile cards only) ── */
                .fh-size-badge {
                    background:rgba(0,0,0,0.06); color:#5a4a3a;
                    padding:2px 8px; border-radius:0; font-size:0.78em;
                    font-family:'Special Elite',monospace; letter-spacing:0.04em;
                    border:1px solid rgba(0,0,0,0.1);
                }

                /* ── Qty Spinner (table) — handwritten overlay ── */
                .fh-qty-wrap {
                    display:inline-flex; align-items:center; position:relative;
                    background:#f2ead8; border:1px solid rgba(0,0,0,0.2); border-radius:0;
                    overflow:visible;
                }
                .fh-qty-wrap .qty-minus,
                .fh-qty-wrap .qty-plus {
                    background:none; border:none; color:#5a4a3a; padding:4px 7px;
                    cursor:pointer; font-size:14px; font-family:'Special Elite',monospace;
                }
                .fh-qty-wrap .qty-minus:hover,
                .fh-qty-wrap .qty-plus:hover { color:#0a0805; }
                .fh-qty-wrap .qty-input {
                    width:38px; text-align:center; background:#f8f3e8;
                    border:none; border-left:1px solid rgba(0,0,0,0.12);
                    border-right:1px solid rgba(0,0,0,0.12);
                    padding:4px 0; font-family:'Special Elite',monospace; font-size:14px;
                    color:#3a2e1e; -webkit-text-fill-color:#3a2e1e;
                    position:relative; z-index:1;
                }
                .fishotel-open-table .qty-input:-webkit-autofill,
                .fishotel-open-table .qty-input:-webkit-autofill:hover,
                .fishotel-open-table .qty-input:-webkit-autofill:focus {
                    -webkit-box-shadow: 0 0 0 1000px #f8f3e8 inset !important;
                    -webkit-text-fill-color: #3a2e1e !important;
                    caret-color: #3a2e1e;
                }
                .fish-card .qty-input {
                    color:#3a2e1e !important; -webkit-text-fill-color:#3a2e1e !important;
                }
                .fish-card .qty-input:-webkit-autofill,
                .fish-card .qty-input:-webkit-autofill:hover,
                .fish-card .qty-input:-webkit-autofill:focus {
                    -webkit-box-shadow: 0 0 0 1000px #f8f3e8 inset !important;
                    -webkit-text-fill-color: #3a2e1e !important;
                    caret-color: #3a2e1e;
                }
                /* Handwritten overlay on qty input */
                .fh-hw-input-val {
                    position:absolute; top:50%; left:50%;
                    transform:translate(-50%,-50%);
                    font-family:'Klee One',cursive; font-size:28px; color:#0d0a05;
                    font-weight:700; line-height:1; pointer-events:none; z-index:2;
                    white-space:nowrap;
                }

                /* ── Request Button — ink stamp impression ── */
                .fh-req-btn {
                    padding:5px 14px; font-size:0.82em; margin-left:8px;
                    background:#fdf0f0; color:#8b1a1a; border:2px solid #8b1a1a;
                    border-radius:0; cursor:pointer;
                    font-family:'Special Elite',monospace; font-weight:400;
                    text-transform:uppercase; letter-spacing:0.06em;
                    box-shadow:inset 0 1px 4px rgba(139,26,26,0.15);
                    transition:background 0.15s;
                }
                .fh-req-btn:hover { background:#f5dede; border-color:#6b1010; color:#6b1010; }

                /* ── Green checkmark — handwritten annotation in common name cell ── */
                .fh-in-cart-check {
                    font-family:'Klee One',cursive; font-size:22px; color:#2d6a2d;
                    display:inline-block;
                    line-height:1; font-weight:700;
                    position:absolute; left:-2px; z-index:3;
                    /* rotation and top set per-row via inline style */
                }
                /* Ensure common name cell anchors absolutely-positioned children */
                .fh-common-cell { position:relative; }
                /* ── Handwritten qty in # margin — felt-tip pen annotation ── */
                .fh-hw-qty {
                    font-family:'Klee One',cursive; font-size:30px; color:#1a4d1a;
                    line-height:1; font-weight:700;
                    position:absolute; left:50%; z-index:3;
                    /* top and transform (translateX + rotation) set per-row via inline style */
                }

                /* ── CLOSED stamp — red rubber stamp diagonal ── */
                .fh-closed-stamp {
                    display:inline-block; border:2px solid #8b1a1a; border-radius:0;
                    padding:2px 12px; font-family:'Special Elite',monospace; font-weight:700;
                    font-size:0.82em; text-transform:uppercase; letter-spacing:0.1em;
                    color:#8b1a1a; transform:rotate(-15deg);
                    box-shadow:inset 0 1px 2px rgba(139,26,26,0.2);
                    opacity:0.85;
                }

                /* ── Mobile Controls ── */
                .fh-mobile-controls {
                    display:none; margin-bottom:16px; gap:10px; flex-wrap:wrap;
                    position:relative; z-index:2;
                }
                .fh-mobile-controls select {
                    padding:10px 14px; font-size:0.9em; font-family:'Special Elite',monospace;
                    border:1px solid rgba(0,0,0,0.15); border-radius:0; background:#f2ead8;
                    color:#3a2e1e !important; -webkit-text-fill-color:#3a2e1e !important;
                    flex:1; min-width:150px;
                    text-transform:uppercase; letter-spacing:0.03em;
                }
                .fh-mobile-controls input {
                    padding:10px 14px; font-size:0.9em; font-family:'Special Elite',monospace;
                    border:1px solid rgba(0,0,0,0.15); border-radius:0; background:#f2ead8;
                    color:#3a2e1e !important; -webkit-text-fill-color:#3a2e1e !important;
                    flex:1; min-width:180px; width:auto;
                }
                .fh-mobile-controls input::placeholder { color:#8a7a6a !important; -webkit-text-fill-color:#8a7a6a !important; }

                /* ── Mobile Cards ── */
                .fish-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(310px, 1fr)); gap:16px; position:relative; z-index:2; box-sizing:border-box; width:100%; overflow:hidden; }
                .fish-card {
                    background:#f2ead8; border:1px solid rgba(0,0,0,0.12); border-radius:0;
                    padding:16px 18px; font-family:'Special Elite',monospace;
                    border-bottom:1px solid rgba(0,0,0,0.12);
                    box-sizing:border-box; min-width:0; overflow:hidden;
                }
                .fish-card h4 { margin:0 0 4px; color:#1a1a2e; font-weight:400; font-size:1rem; }
                .fish-card .sci { font-style:italic; color:#5a4a3a; margin-bottom:10px; font-size:0.85rem; }
                .fish-card .price { font-size:1.1em; color:#1a1a2e; font-weight:400; }
                .fish-card .stock { font-weight:400; }
                .fish-card .action { margin-top:14px; display:flex; align-items:center; gap:10px; }

                /* ── FCFS Strip ── */
                .fh-gate-fcfs {
                    text-align:center; padding:14px;
                    font-family:'Special Elite',monospace; font-weight:400;
                    font-size:clamp(0.75rem,1.8vw,0.9rem); text-transform:uppercase;
                    letter-spacing:0.1em; color:#5a4a3a;
                    border-top:2px solid rgba(0,0,0,0.15);
                    position:relative; z-index:2;
                }

                /* ── Responsive ── */
                @media (min-width:1024px) {
                    .fish-cards, .fh-mobile-controls { display:none !important; }
                }
                @media (max-width:1023px) {
                    .fh-scroll-wrap { display:none !important; }
                    .fh-mobile-controls { display:flex !important; }
                }
                @media (max-width:767px) {
                    #submit-requests { width:100% !important; padding:16px !important; }
                    .fh-manifest-card { padding:0; }
                    .fh-manifest-header { padding:18px 14px 12px; }
                    .fh-manifest-title { font-size:0.95rem; }
                    .fh-manifest-fields { font-size:0.68rem; gap:2px 12px; }
                    .fh-punch-hole { width:10px; height:10px; top:12px; }
                    .fh-punch-hole-left { left:10px; }
                    .fh-punch-hole-right { right:10px; }
                }
            </style>

            <div class="fh-gate-wrap">

                <!-- ===== Solari Departure Board ===== -->
                <div class="fh-board-wrapper">
                <div class="fh-board" id="fh-board">
                    <div class="fh-board-header">
                        <div class="fh-board-header-left"><span class="fh-board-header-icon">&#x2708;</span> FISHOTEL INTERNATIONAL</div>
                        <div class="fh-board-header-right"><span class="fh-status-light <?php echo $status === 'open_ordering' ? 'fh-status-green' : 'fh-status-amber'; ?>"></span>FHI &middot; GATE OPEN</div>
                    </div>
                    <div class="fh-board-row"><div class="fh-board-label">Airport</div><div class="fh-board-tiles" data-fh-text="FISHOTEL INTL"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Destination</div><div class="fh-board-tiles" data-fh-text="CHAMPLIN, MN"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Flight</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( $flight_number ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Status</div><div class="fh-board-tiles" data-fh-text="NOW BOARDING"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Gate Closes</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( $gate_closes_display ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Species</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( $total_species . ' SPECIES AVAILABLE' ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Stock</div><div class="fh-board-tiles" data-fh-text="<?php echo esc_attr( intval( $total_stock ) . ' TOTAL STOCK' ); ?>"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Boarding</div><div class="fh-board-tiles" data-fh-text="FIRST COME FIRST SERVED"></div></div>
                    <div class="fh-board-row"><div class="fh-board-label">Notice</div><div class="fh-board-tiles" id="fh-notice-row" data-fh-text="<?php echo esc_attr( $ticker_resolved[0] ?? '' ); ?>"></div></div>
                    <div class="fh-board-footer">
                        <div class="fh-board-footer-left"><span class="fh-footer-unlit">ARRIVALS</span> <span class="fh-footer-sep">&middot;</span> <span class="fh-footer-lit">DEPARTURES</span></div>
                        <div class="fh-board-footer-right">SOLARI DI UDINE</div>
                    </div>
                </div>
                </div>
                <script>(function(){var w=document.querySelector('.fh-board-wrapper');function s(){if(!w)return;var scale=Math.min(1,w.offsetWidth/900);w.style.setProperty('--fh-board-scale',scale)}requestAnimationFrame(s);window.addEventListener('resize',s)})()</script>

                <!-- ===== Boarding Pass ===== -->
                <div class="fh-bp-open" id="my-requests">
                    <svg width="0" height="0" style="position:absolute;pointer-events:none;"><filter id="fh-paper-grain"><feTurbulence type="fractalNoise" baseFrequency="0.65" numOctaves="3" stitchTiles="stitch"/><feColorMatrix type="saturate" values="0"/></filter></svg>
                    <?php if ( ! is_user_logged_in() ) : ?>
                    <div class="fh-ticket-sleeve">
                        <div class="fh-ts-flight-no"><?php echo esc_html( $flight_number ); ?></div>
                        <div class="fh-ts-top">
                            <div class="fh-ts-top-label">&#x2708; &nbsp; Passenger Ticket &amp; Boarding Pass &nbsp; &#x2708;</div>
                        </div>
                        <div class="fh-ts-rule"></div>
                        <div class="fh-ts-stripe">
                            <div class="fh-ts-name">The FisHotel</div>
                            <div class="fh-ts-name-sub">International &middot; Quarantine Service</div>
                        </div>
                        <div class="fh-ts-body">
                            <div class="fh-ts-issued-by">Issued by</div>
                            <div class="fh-ts-issuer">FisHotel World Airways, Inc.</div>
                            <div class="fh-ts-tagline">World's Most Luxury Vacation Experience</div>
                            <div class="fh-ts-divider"></div>
                            <button type="button" class="fh-ts-login" onclick="document.getElementById('fishotel-login-modal').style.display='flex'">Log In to See Your Boarding Pass</button>
                            <div class="fh-ts-fine">
                                Each passenger should carefully examine this ticket.<br>
                                This ticket shall not be valid without a verified deposit on file.
                            </div>
                        </div>
                        <div class="fh-ts-bottom-rule"></div>
                    </div>
                    <?php else : ?>
                    <div class="fh-bp-open-inner">
                        <div class="fh-bp-open-left">
                            <div class="fh-bp-open-header">
                                <span class="fh-bp-open-header-title">BOARDING PASS</span>
                                <span class="fh-bp-open-header-flight"><?php echo esc_html( $flight_number ); ?></span>
                            </div>
                            <div class="fh-bp-open-body">
                                <p class="fh-bp-open-passenger-line"><span class="fh-bp-pax-label">PASSENGER</span> <?php echo is_user_logged_in() ? esc_html( strtoupper( wp_get_current_user()->display_name ) ) : 'GUEST'; ?> &middot; <?php echo esc_html( strtoupper( $origin_name ) ); ?> &#x2708; CHAMPLIN, MN</p>
                                <div id="request-list" style="min-height:36px;"></div>
                                <div id="cart-total">Total: $0.00</div>
                                <button id="submit-requests">Submit My Requests</button>
                            </div>
                        </div>
                        <div class="fh-bp-open-stub">
                            <span class="fh-bp-open-scissors">&#x2702;</span>
                            <div>
                                <p class="fh-bp-open-stub-label">Flight</p>
                                <p class="fh-bp-open-stub-value"><?php echo esc_html( $flight_number ); ?></p>
                            </div>
                            <div>
                                <p class="fh-bp-open-stub-label">Gate</p>
                                <p class="fh-bp-open-stub-value">QT-1</p>
                            </div>
                            <div>
                                <p class="fh-bp-open-stub-label">Batch</p>
                                <p class="fh-bp-open-stub-value"><?php echo esc_html( $batch_name ); ?></p>
                            </div>
                            <div>
                                <p class="fh-bp-open-stub-label">Deposit</p>
                                <p class="fh-bp-open-stub-value">$<?php echo number_format( $bp_deposit, 2 ); ?></p>
                            </div>
                            <?php if ( is_user_logged_in() ) : ?>
                            <div class="fh-bp-open-deposit-stamp">Deposit Paid</div>
                            <?php endif; ?>
                            <div>
                                <p class="fh-bp-open-stub-label">Arrival</p>
                                <p class="fh-bp-open-stub-value"><?php echo $arrival_date ? esc_html( strtoupper( date( 'M j, Y', strtotime( $arrival_date ) ) ) ) : 'TBD'; ?></p>
                            </div>
                            <span class="fh-bp-open-vertical">Boarding Pass</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ===== Departure Manifest ===== -->
                <div class="fh-manifest-card">
                    <div class="fh-clipboard-paper">
                    <svg width="0" height="0" style="position:absolute;pointer-events:none;"><filter id="fh-manifest-grain"><feTurbulence type="fractalNoise" baseFrequency="0.8" numOctaves="4" stitchTiles="stitch"/><feColorMatrix type="saturate" values="0"/></filter></svg>
                    <div class="fh-manifest-header">
                        <div class="fh-punch-hole fh-punch-hole-left"></div>
                        <div class="fh-punch-hole fh-punch-hole-right"></div>
                        <div class="fh-manifest-title">FISHOTEL INTL. &middot; DEPARTURE MANIFEST</div>
                        <div class="fh-manifest-fields">
                            <span>FLIGHT NO: <?php echo esc_html( $flight_number ); ?></span>
                            <span>|</span>
                            <span>DATE: <?php echo strtoupper( date( 'M j, Y' ) ); ?></span>
                            <span>|</span>
                            <span>ORIGIN: <?php echo esc_html( strtoupper( $origin_name ) ); ?></span>
                            <span>|</span>
                            <span>DESTINATION: CHAMPLIN, MN</span>
                        </div>
                    </div>

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
                                <th style="width:4%;text-align:center;">&nbsp;</th>
                                <th data-sort="common" style="width:33%;">Common Name</th>
                                <th data-sort="sci" style="width:24%;">Scientific Name</th>
                                <th style="text-align:right;width:10%;" data-sort="price">Avg Price</th>
                                <th style="text-align:center;width:7%;" data-sort="stock">Stock</th>
                                <th style="text-align:center;width:22%;">Action</th>
                            </tr></thead><tbody>
                            <?php
                            // Build set of batch IDs already in customer's cart
                            $in_cart_ids = [];
                            foreach ( $prev_items as $pi ) { $in_cart_ids[ $pi['batch_id'] ] = true; }
                            $row_num = 0; foreach ( $batch_posts as $bp ) {
                                $master_id = get_post_meta( $bp->ID, '_master_id', true );
                                if ( ! $master_id ) continue;
                                $master = get_post( $master_id );
                                if ( ! $master ) continue;
                                $row_num++;
                                $sci_name = get_post_meta( $master_id, '_scientific_name', true );
                                $price = floatval( get_post_meta( $master_id, '_selling_price', true ) );
                                $stock = floatval( get_post_meta( $bp->ID, '_stock', true ) );
                                $common_name = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $bp->post_title ) );
                                $stock_class = $stock > 10 ? 'fh-stock-green' : ( $stock > 0 ? 'fh-stock-orange' : 'fh-stock-red' );
                                $low_class   = ( $stock > 0 && $stock <= 5 ) ? ' fh-stock-low' : '';
                                $row_class   = $stock <= 0 ? ' class="fh-row-closed"' : '';
                                echo '<tr' . $row_class . ' data-price="' . $price . '" data-stock="' . $stock . '" data-common="' . esc_attr( strtolower( $common_name ) ) . '" data-sci="' . esc_attr( strtolower( $sci_name ) ) . '" data-rownum="' . $row_num . '">';
                                $fish_total_qty = $total_qty_map[ $bp->ID ] ?? 0;
                                echo '<td class="fh-row-num">';
                                if ( $fish_total_qty > 0 ) {
                                    $bid_hash = $bp->ID;
                                    $q_rot = ( ( $bid_hash * 83 + 17 ) % 40 ) - 22;
                                    $q_top = 8 + ( ( $bid_hash * 47 + 11 ) % 21 );
                                    echo '<span class="fh-hw-qty" style="transform:translateX(-50%) rotate(' . $q_rot . 'deg);top:' . $q_top . 'px;">' . $fish_total_qty . '</span>';
                                }
                                echo '</td>';
                                echo '<td class="fh-common-cell">' . esc_html( $common_name ) . '</td>';
                                echo '<td>' . esc_html( $sci_name ) . '</td>';
                                echo '<td style="text-align:right;">' . number_format( $price, 2 ) . '</td>';
                                echo '<td style="text-align:center;" class="' . $stock_class . $low_class . '">';
                                echo '<span>' . intval( $stock ) . '</span></td>';
                                echo '<td style="text-align:center;white-space:nowrap;">';
                                if ( $stock > 0 ) {
                                    $is_in_cart = isset( $in_cart_ids[ $bp->ID ] );
                                    $touched_class = $is_in_cart ? ' fh-qty-touched' : '';
                                    $prefill = $user_cart_qty[ $bp->ID ] ?? '';
                                    echo '<div class="fh-qty-wrap' . $touched_class . '">';
                                    echo '<button class="qty-minus">&#x2212;</button>';
                                    echo '<input type="number" min="0" value="' . esc_attr( $prefill ) . '" class="qty-input">';
                                    echo '<button class="qty-plus">+</button>';
                                    echo '</div>';
                                    echo '<button class="add-to-request fh-req-btn" data-batch-id="' . $bp->ID . '" data-price="' . $price . '" data-fish-name="' . esc_attr( $common_name ) . '">Request</button>';
                                } else {
                                    echo '<span class="fh-closed-stamp">Void</span>';
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
                            $common_name = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $bp->post_title ) );
                            $stock_class = $stock > 10 ? 'fh-stock-green' : ( $stock > 0 ? 'fh-stock-orange' : 'fh-stock-red' );
                            $low_class_m = ( $stock > 0 && $stock <= 5 ) ? ' fh-stock-low' : '';
                            $card_class  = 'fish-card' . ( $stock <= 0 ? ' fh-row-closed' : '' );
                            echo '<div class="' . $card_class . '" data-price="' . $price . '" data-stock="' . $stock . '" data-common="' . esc_attr( strtolower( $common_name ) ) . '" data-sci="' . esc_attr( strtolower( $sci_name ) ) . '">';
                            echo '<h4>' . esc_html( $common_name ) . '</h4>';
                            echo '<div class="sci">' . esc_html( $sci_name ) . '</div>';
                            echo '<div style="margin:10px 0;">';
                            echo '<span class="price">$' . number_format( $price, 2 ) . '</span>';
                            echo ' <span class="stock ' . $stock_class . $low_class_m . '">';
                            echo 'Stock: ' . intval( $stock ) . '</span>';
                            echo '</div>';
                            if ( $stock > 0 ) {
                                $card_prefill = $user_cart_qty[ $bp->ID ] ?? '';
                                echo '<div class="action">';
                                echo '<div class="fh-qty-wrap">';
                                echo '<button class="qty-minus" style="padding:6px 10px;">&#x2212;</button>';
                                echo '<input type="number" min="0" value="' . esc_attr( $card_prefill ) . '" class="qty-input" style="width:48px;padding:6px 0;">';
                                echo '<button class="qty-plus" style="padding:6px 10px;">+</button>';
                                echo '</div>';
                                echo '<button class="add-to-request fh-req-btn" data-batch-id="' . $bp->ID . '" data-price="' . $price . '" data-fish-name="' . esc_attr( $common_name ) . '" style="flex:1;padding:10px;font-size:0.95em;">Request</button>';
                                echo '</div>';
                            } else {
                                echo '<div style="margin-top:12px;"><span class="fh-closed-stamp">Void</span></div>';
                            }
                            echo '</div>';
                        } ?>
                    </div>

                    <!-- FCFS Strip -->
                    <div class="fh-gate-fcfs">First Come &middot; First Served</div>
                    </div><!-- .fh-clipboard-paper -->
                </div>

            </div><!-- .fh-gate-wrap -->

                <script>
                    let prevItems  = <?php echo wp_json_encode( $prev_items ); ?>;
                    let prevTotal  = <?php echo (float) $prev_total; ?>;
                    let cartItems  = [];
                    let cartTotal  = prevTotal;  // includes any already-submitted amounts
                    const fhDemandTotals = <?php echo wp_json_encode( (object) $total_qty_map ); ?>;
                    let currentUserHasHFUsername = <?php echo ( get_user_meta( get_current_user_id(), '_fishotel_humble_username', true ) !== '' ) ? 'true' : 'false'; ?>;

                    if (<?php echo is_user_logged_in() ? 'true' : 'false'; ?> && !currentUserHasHFUsername) {
                        setTimeout(function() {
                            showHFUsernameModal();
                        }, 800);
                    }

                    // ── Solari Departure Board — Dynamic Fixed Grid ──
                    (function() {
                        var CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789·,.- ';
                        var board = document.getElementById('fh-board');
                        if (!board) return;

                        // Defer init to next animation frame so CSS layout is fully resolved
                        requestAnimationFrame(function() {

                        // Dynamically calculate COLS from actual tile zone width
                        var tileZone = board.querySelector('.fh-board-tiles');
                        var TILE_W = window.innerWidth <= 600 ? 20 : 32;
                        var zoneWidth = tileZone ? tileZone.offsetWidth : 640;
                        // Subtract padding (6px each side on desktop, 4px on mobile)
                        var zonePad = window.innerWidth <= 600 ? 8 : 12;
                        var usable = zoneWidth - zonePad;
                        var COLS = Math.floor(usable / TILE_W);
                        if (COLS < 8) COLS = 8; // safety floor

                        // Lock each tile zone to exactly COLS * TILE_W + padding so overflow is impossible
                        var allZones = board.querySelectorAll('.fh-board-tiles');
                        allZones.forEach(function(z) { z.style.maxWidth = (COLS * TILE_W + zonePad) + 'px'; });

                        // Pad text to exactly COLS characters
                        function padText(text) {
                            text = text || '';
                            if (text.length > COLS) text = text.substring(0, COLS);
                            while (text.length < COLS) text += ' ';
                            return text;
                        }

                        // Chunk a long message into COLS-width pieces at word boundaries
                        // Non-final chunks get » in last tile position
                        function chunkMessage(msg) {
                            msg = (msg || '').trim();
                            if (msg.length <= COLS) return [padText(msg)];
                            var chunks = [];
                            var remaining = msg;
                            while (remaining.length > 0) {
                                if (remaining.length <= COLS) {
                                    chunks.push(padText(remaining));
                                    break;
                                }
                                // Reserve last position for » indicator
                                var cut = remaining.substring(0, COLS - 1);
                                var ls = cut.lastIndexOf(' ');
                                if (ls > Math.floor(COLS / 3)) {
                                    cut = remaining.substring(0, ls);
                                } else {
                                    cut = remaining.substring(0, COLS - 1);
                                }
                                var display = cut.trim();
                                while (display.length < COLS - 1) display += ' ';
                                display += '\u00BB'; // » continuation indicator
                                chunks.push(display);
                                remaining = remaining.substring(cut.length).trim();
                            }
                            return chunks;
                        }

                        // Tile color aging — varies per tile using a simple hash
                        var AMBER_SHADES = ['#c8a84b','#d4bc7e','#b89640','#c4a055','#c09848','#d8c080','#bfa24a','#cbb060'];
                        var rowCounter = 0;
                        function tileHash(row, col) {
                            // Scatter function — no visible pattern across rows or columns
                            return ((row * 7 + col * 13 + row * col * 3 + 37) * 2654435761) >>> 0;
                        }

                        // Always render exactly COLS tiles — spaces are identical blank tiles
                        function buildTiles(container, text) {
                            text = padText(text);
                            container.innerHTML = '';
                            var r = rowCounter++;
                            for (var i = 0; i < COLS; i++) {
                                var c = text[i];
                                var flap = document.createElement('div');
                                flap.className = 'fh-flap';
                                flap.setAttribute('data-char', c);
                                if (c !== ' ') flap.textContent = c;
                                // Apply unique color per tile based on row+col hash
                                var h = tileHash(r, i);
                                flap.style.color = AMBER_SHADES[h % AMBER_SHADES.length];
                                if (h % 11 === 0) flap.style.opacity = '0.85'; // occasional faded flap
                                container.appendChild(flap);
                            }
                        }

                        // Animate tiles staggered left-to-right
                        function animateRow(container, text) {
                            text = padText(text);
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

                        // NOTICE row — cycles through ticker messages with chunking
                        // Shuffle message order on load so repeat visitors see varied sequence
                        var noticeMsgs = <?php echo wp_json_encode( $ticker_resolved ); ?>;
                        var noticeRow = document.getElementById('fh-notice-row');
                        if (noticeRow && noticeMsgs.length > 0) {
                            // Fisher-Yates shuffle at message level (chunks stay together)
                            for (var i = noticeMsgs.length - 1; i > 0; i--) {
                                var j = Math.floor(Math.random() * (i + 1));
                                var tmp = noticeMsgs[i];
                                noticeMsgs[i] = noticeMsgs[j];
                                noticeMsgs[j] = tmp;
                            }
                            // Pre-chunk shuffled messages into a flat display queue
                            var displayQueue = [];
                            for (var m = 0; m < noticeMsgs.length; m++) {
                                var chunks = chunkMessage(noticeMsgs[m]);
                                for (var k = 0; k < chunks.length; k++) {
                                    displayQueue.push(chunks[k]);
                                }
                            }
                            if (displayQueue.length > 1) {
                                var qIdx = 0;
                                setInterval(function() {
                                    qIdx = (qIdx + 1) % displayQueue.length;
                                    buildTiles(noticeRow, displayQueue[qIdx]);
                                    animateRow(noticeRow, displayQueue[qIdx]);
                                }, 4000);
                            }
                        }

                        }); // end requestAnimationFrame
                    })();

                    function showLoginModal() {
                        document.getElementById('fishotel-login-modal').style.display = 'flex';
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

                    // Handwriting wobble — seeded per-character transform
                    function hwWobble(str) {
                        function hash(s, i) {
                            let h = 0x811c9dc5;
                            const full = s + ':' + i;
                            for (let j = 0; j < full.length; j++) {
                                h ^= full.charCodeAt(j);
                                h = Math.imul(h, 0x01000193);
                            }
                            return (h >>> 0) / 0xFFFFFFFF;
                        }
                        let out = '';
                        for (let i = 0; i < str.length; i++) {
                            const ch = str[i];
                            if (ch === ' ') { out += '<span style="display:inline-block;width:0.35em"></span>'; continue; }
                            const r = ((hash(str, i) * 5) - 2.5).toFixed(2);
                            const y = ((hash(str, i * 7 + 3) * 3) - 1.5).toFixed(2);
                            const s = (0.92 + hash(str, i * 13 + 7) * 0.14).toFixed(3);
                            const esc = ch === '<' ? '&lt;' : ch === '>' ? '&gt;' : ch === '&' ? '&amp;' : ch;
                            out += '<span style="display:inline-block;transform:rotate(' + r + 'deg) translateY(' + y + 'px) scale(' + s + ');transform-origin:bottom center">' + esc + '</span>';
                        }
                        return out;
                    }

                    function renderRequestList() {
                        const list = document.getElementById("request-list");
                        if (!list) return;

                        if (prevItems.length === 0 && cartItems.length === 0) {
                            list.innerHTML = '<div class="fh-bp-open-empty">No fish selected yet &mdash; browse the manifest below</div>';
                            updateCartTotal();
                            return;
                        }

                        let html = '';
                        if (prevItems.length > 0) {
                            html += '<div style="color:#3d2b1f;font-size:11px;text-transform:uppercase;letter-spacing:3px;margin:0 0 4px;font-family:Special Elite,monospace;font-weight:400;">Passenger Itinerary</div><div style="border-bottom:1px solid rgba(61,43,31,0.3);margin-bottom:6px;"></div>';
                        }
                        html += '<table class="fh-bp-open-fish-table"><thead><tr><th>Common Name</th><th>Qty</th><th>Unit Price</th><th>Total</th><th></th></tr></thead><tbody>';

                        // Previously submitted requests
                        prevItems.forEach((item, idx) => {
                            const lt = (item.price * item.qty).toFixed(2);
                            const safeName = item.fish_name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
                            html += `<tr class="fh-bp-prev-row"><td>${hwWobble(item.fish_name)}</td><td>${hwWobble(String(item.qty))}</td><td>${hwWobble('$'+parseFloat(item.price).toFixed(2))}</td><td style="font-weight:700;">${hwWobble('$'+lt)}</td>
                                <td><button class="fh-bp-remove-btn" onclick="removePrevItem(this,${idx},'${safeName}',${item.request_id},${item.batch_id},${item.price * item.qty})" title="Remove">&times;</button></td></tr>`;
                        });

                        // Current session new requests
                        if (cartItems.length > 0 && prevItems.length > 0) {
                            html += `<tr><td colspan="5" style="padding:6px 8px 2px;color:#3d2b1f;font-size:11px;text-transform:uppercase;letter-spacing:3px;border:none;font-family:Special Elite,monospace;font-weight:400;">New Requests</td></tr>`;
                        }
                        cartItems.forEach((item, index) => {
                            const lineTotal = item.price * item.qty;
                            html += `<tr data-line-total="${lineTotal}" data-index="${index}"><td>${hwWobble(item.fish_name)}</td><td>${hwWobble(String(item.qty))}</td><td>${hwWobble('$'+parseFloat(item.price).toFixed(2))}</td><td style="font-weight:700;">${hwWobble('$'+lineTotal.toFixed(2))}</td>
                                <td><button class="fh-bp-remove-btn" onclick="removeItem(this)" title="Remove">&times;</button></td></tr>`;
                        });

                        html += '</tbody></table>';
                        list.innerHTML = html;
                        updateCartTotal();
                    }

                    function updateCartTotal() {
                        const el = document.getElementById("cart-total");
                        if (el) el.innerHTML = `Total: $${cartTotal.toFixed(2)}`;
                    }

                    renderRequestList(); // show prev items immediately on load

                    // Mark manifest rows that are already in cart/prev with a green checkmark
                    function markRequestedRows() {
                        // Collect all batch_ids the current user has requested
                        const requestedIds = new Set();
                        prevItems.forEach(i => requestedIds.add(String(i.batch_id)));
                        cartItems.forEach(i => requestedIds.add(String(i.batch_id)));

                        // Build demand totals: start from server-side ALL-user totals, add session cart additions
                        const demandMap = Object.assign({}, fhDemandTotals);
                        cartItems.forEach(i => {
                            const k = String(i.batch_id);
                            demandMap[k] = (demandMap[k] || 0) + parseInt(i.qty);
                        });

                        // Desktop table rows
                        document.querySelectorAll('.fishotel-open-table .add-to-request').forEach(btn => {
                            const batchId = btn.getAttribute('data-batch-id');
                            const tr = btn.closest('tr');
                            if (!tr) return;
                            const commonCell = tr.querySelector('.fh-common-cell');
                            const numCell = tr.querySelector('.fh-row-num');
                            // Remove existing annotations
                            if (commonCell) { const ex = commonCell.querySelector('.fh-in-cart-check'); if (ex) ex.remove(); }
                            if (numCell) { const ex = numCell.querySelector('.fh-hw-qty'); if (ex) ex.remove(); }

                            // Checkmark for current user's items
                            if (requestedIds.has(batchId)) {
                                const rowNum = parseInt(tr.getAttribute('data-rownum')) || 1;
                                const rot = ((rowNum * 137 + 23) % 31) - 20;
                                const topPx = 4 + ((rowNum * 47) % 12);
                                const nudge = (rowNum % 2 === 1) ? -2 : 1;
                                if (commonCell) {
                                    const chk = document.createElement('span');
                                    chk.className = 'fh-in-cart-check';
                                    chk.textContent = '\u2713';
                                    chk.style.transform = 'rotate(' + rot + 'deg)';
                                    chk.style.top = topPx + 'px';
                                    chk.style.left = nudge + 'px';
                                    commonCell.appendChild(chk);
                                }
                            }

                            // Demand qty in # column — shown to ALL visitors
                            const demand = demandMap[batchId] || 0;
                            if (numCell && demand > 0) {
                                const bid = parseInt(batchId) || 1;
                                const qRot = ((bid * 83 + 17) % 40) - 22;
                                const qTop = 8 + ((bid * 47 + 11) % 21);
                                const hw = document.createElement('span');
                                hw.className = 'fh-hw-qty';
                                hw.textContent = demand;
                                hw.style.transform = 'translateX(-50%) rotate(' + qRot + 'deg)';
                                hw.style.top = qTop + 'px';
                                numCell.appendChild(hw);
                            }
                        });
                        // Mobile cards
                        document.querySelectorAll('.fish-card .add-to-request').forEach(btn => {
                            const batchId = btn.getAttribute('data-batch-id');
                            const action = btn.closest('.action');
                            if (!action) return;
                            const existing = action.querySelector('.fh-in-cart-check');
                            if (existing) existing.remove();
                            if (requestedIds.has(batchId)) {
                                const chk = document.createElement('span');
                                chk.className = 'fh-in-cart-check';
                                chk.textContent = '\u2713';
                                action.appendChild(chk);
                            }
                        });
                    }
                    markRequestedRows();

                    // No-op placeholder — wobble overlay removed from table view
                    function updateQtyDisplay(input) {}

                    document.querySelectorAll(".qty-minus").forEach(btn => {
                        btn.addEventListener("click", function() {
                            const input = this.nextElementSibling;
                            let val = parseInt(input.value) || 0;
                            if (val > 0) {
                                val--;
                                input.value = val === 0 ? '' : val;
                            }
                            updateQtyDisplay(input);
                        });
                    });
                    document.querySelectorAll(".qty-plus").forEach(btn => {
                        btn.addEventListener("click", function() {
                            const input = this.previousElementSibling;
                            let val = parseInt(input.value) || 0;
                            input.value = val + 1;
                            updateQtyDisplay(input);
                        });
                    });
                    // Direct input typing
                    document.querySelectorAll('.fh-qty-wrap .qty-input').forEach(inp => {
                        inp.addEventListener('input', function() {
                            updateQtyDisplay(this);
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
                                        markRequestedRows();

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
                        const line = btn.closest('tr');
                        const index = parseInt(line.getAttribute("data-index"));
                        const lineTotal = parseFloat(line.getAttribute("data-line-total")) || 0;
                        cartTotal -= lineTotal;
                        cartItems.splice(index, 1);
                        renderRequestList();
                        markRequestedRows();
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
                                markRequestedRows();
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

        // ─── Stage 3: Transit page (orders_closed) ────────────────────────
        if ( $status === 'orders_closed' ) {
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

            // Generate flight number for this batch
            $origin_code   = strtoupper( substr( preg_replace( '/[^a-zA-Z]/', '', $origin_name ), 0, 2 ) );
            preg_match_all( '/\d+/', $batch_name, $dmatches );
            $route_num = '';
            foreach ( $dmatches[0] as $d ) $route_num .= $d;
            $route_num = substr( $route_num, 0, 4 );
            if ( ! $route_num ) $route_num = '001';
            $flight_number = 'FHI-' . $origin_code . $route_num;

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
                        $item['fish_name'] = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $item['fish_name'] ?? '' ) );
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
                            'fish_name'       => trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $mitem['fish_name'] ?? '' ) ),
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
            <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Caveat:wght@400&family=Special+Elite&display=swap" rel="stylesheet">
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
                /* ── Flight Manifest — Vintage Cargo Document ── */
                .fh-manifest {
                    margin-top: 28px; background: #f0e8d5; border: 1px solid #c8b99a; border-radius: 2px;
                    font-family: 'Oswald', sans-serif; color: #1a1a1a; overflow: hidden;
                    position: relative;
                    box-shadow: 0 4px 30px rgba(0,0,0,0.3), inset 0 0 60px rgba(60,40,20,0.1);
                }
                /* Paper grain */
                .fh-manifest::before {
                    content:''; position:absolute; inset:0;
                    filter:url(#fh-paper-grain); background:rgba(180,165,130,0.05);
                    pointer-events:none; z-index:1; mix-blend-mode:multiply;
                }
                /* Staple marks */
                .fh-manifest-staple {
                    position:absolute; left:18px; width:16px; height:3px;
                    background:#4a4a4a; border-radius:2px;
                    box-shadow:0 1px 0 rgba(255,255,255,0.3);
                    z-index:3; pointer-events:none;
                }
                .fh-manifest-staple:first-child { top:15px; }
                .fh-manifest-staple:nth-child(2) { top:25px; }
                /* Rubber stamp */
                .fh-manifest-stamp {
                    position:absolute; top:148px; left:50%; z-index:4;
                    transform:translateX(-50%) rotate(-5deg);
                    font-family:'Special Elite',monospace; font-weight:700; font-size:16px;
                    color:#c0152a; text-transform:uppercase; letter-spacing:3px;
                    border:2px solid #c0152a; padding:6px 10px; opacity:0.82;
                    box-shadow:inset 0 0 0 2px rgba(192,21,42,0.3);
                    filter:blur(0.4px);
                    pointer-events:none; line-height:1;
                }
                .fh-manifest-splatter {
                    position:absolute; border-radius:50%; background:rgba(192,21,42,0.55);
                    pointer-events:none; z-index:4;
                }
                /* Coffee stain */
                .fh-manifest-coffee {
                    position:absolute; bottom:30px; right:40px; z-index:2;
                    width:120px; height:110px; pointer-events:none;
                    transform:rotate(15deg);
                }
                /* Header */
                .fh-manifest-doc-header {
                    padding:28px 28px 0; text-align:center; position:relative; z-index:2;
                }
                .fh-manifest-doc-header .fh-punch-hole {
                    position:absolute; top:16px; width:14px; height:14px;
                    border-radius:50%; background:#1a1a2e;
                    border:1px solid #a09080;
                    box-shadow:inset 0 1px 3px rgba(0,0,0,0.5);
                }
                .fh-manifest-doc-header .fh-punch-left { left:50%; margin-left:-70px; }
                .fh-manifest-doc-header .fh-punch-right { left:50%; margin-left:56px; }
                .fh-manifest-airline {
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(1.1rem,2.8vw,1.5rem); text-transform:uppercase;
                    letter-spacing:0.08em; color:#1a1a1a; margin:0 0 4px;
                }
                .fh-manifest-subtitle {
                    font-family:'Special Elite',monospace; font-weight:400;
                    font-size:clamp(0.7rem,1.8vw,0.85rem); text-transform:uppercase;
                    letter-spacing:0.2em; color:#7a6020; margin:0 0 14px;
                }
                /* HR margin override — defeat theme's ~108px global hr margins */
                .fh-manifest hr, .fh-manifest .fh-manifest-letterhead-rule, .fh-manifest .fh-manifest-rule {
                    margin-top: 8px !important; margin-bottom: 8px !important;
                }
                /* Letterhead detail block */
                .fh-manifest-letterhead-rule {
                    border:none; border-top:1px solid rgba(139,109,56,0.4); margin:0;
                }
                .fh-manifest-letterhead-row {
                    display:flex; justify-content:space-between; padding:6px 0;
                    font-family:'Special Elite',monospace; font-size:9px;
                    text-transform:uppercase; letter-spacing:0.06em; color:#5a4a20;
                }
                .fh-manifest-emblem {
                    text-align:center; padding:6px 0;
                    font-family:'Special Elite',monospace; font-size:10px;
                    letter-spacing:2px; color:#7a6020; text-transform:uppercase;
                }
                .fh-manifest-rule {
                    border:none; border-top:2px solid #1a1a2e; margin:0 0 16px;
                }
                .fh-manifest-info {
                    display:flex; flex-wrap:wrap; gap:4px 20px; justify-content:center;
                    font-family:'Special Elite',monospace; font-size:0.75rem; font-weight:400;
                    color:#3a2a1a; text-transform:uppercase; letter-spacing:0.04em;
                    padding-bottom:16px; border-bottom:1px solid rgba(0,0,0,0.12);
                    margin-bottom:0;
                }
                .fh-manifest-info span { white-space:nowrap; }
                /* Table — explicit overrides to defeat global plugin table styles */
                .fh-manifest table { width:100%; border-collapse:collapse; position:relative; z-index:2; background:transparent !important; }
                .fh-manifest table tr { background-color:#f0e8d5 !important; }
                .fh-manifest table tr:nth-child(even) { background-color:#ebe0c8 !important; }
                .fh-manifest thead tr { background-color:rgba(181,161,101,0.1) !important; }
                .fh-manifest th {
                    text-align:left; color:#1a1a1a !important; font-family:'Special Elite',monospace;
                    font-weight:600; font-size:11px;
                    text-transform:uppercase; letter-spacing:0.08em; padding:10px 16px;
                    border-bottom:2px solid #b5a165; border-color:rgba(139,109,56,0.3) !important;
                }
                .fh-manifest th:first-child { width:30px; text-align:center; }
                .fh-manifest th:last-child { text-align:center; }
                .fh-manifest table td {
                    padding:9px 16px; font-family:'Special Elite',monospace; font-size:13px;
                    color:#1a1a1a !important; border-bottom:1px solid rgba(139,109,56,0.3) !important;
                    background-color: #f0e8d5 !important;
                }
                .fh-manifest table tr:nth-child(even) td {
                    background-color: #ebe0c8 !important;
                }
                .fh-manifest td:first-child { text-align:center; width:30px; }
                .fh-manifest td:last-child { text-align:center; }
                .fh-manifest-sci { font-style:italic; color:#4a3a2a !important; }
                /* Checkmark — wobbly SVG */
                .fh-manifest-check {
                    display:inline-block; width:22px; height:22px; vertical-align:middle;
                }
                .fh-manifest-check svg { width:22px; height:22px; }
                .fh-manifest tbody tr:nth-child(5n+1) .fh-manifest-check { transform:rotate(-3deg); }
                .fh-manifest tbody tr:nth-child(5n+2) .fh-manifest-check { transform:rotate(-1deg); }
                .fh-manifest tbody tr:nth-child(5n+3) .fh-manifest-check { transform:rotate(-4deg); }
                .fh-manifest tbody tr:nth-child(5n+4) .fh-manifest-check { transform:rotate(-2deg); }
                .fh-manifest tbody tr:nth-child(5n+5) .fh-manifest-check { transform:rotate(-5deg); }
                /* Total row */
                .fh-manifest .fh-manifest-total td {
                    padding:12px 16px; border-top:2px solid #b5a165 !important; border-bottom:none !important;
                    color:#1a1a1a !important; font-weight:700; font-size:13px;
                    text-transform:uppercase; letter-spacing:0.06em;
                    background-color:#e8d9b8 !important;
                }
                .fh-manifest-total td:first-child { border-left:3px solid #b5a165; }
                .fh-manifest table tr.fh-manifest-total { background-color:#e8d9b8 !important; }
                /* Footer */
                .fh-manifest-footer {
                    padding:16px 28px 24px; position:relative; z-index:2;
                    border-top:1px solid rgba(0,0,0,0.12);
                }
                .fh-manifest-carrier {
                    font-family:'Special Elite',monospace; font-size:9px; font-weight:400;
                    text-transform:uppercase; letter-spacing:0.1em; color:#8a7a5a;
                    text-align:center; margin:12px 0 0;
                }
                .fh-manifest-signatures {
                    display:flex; gap:40px; justify-content:flex-start;
                }
                .fh-manifest-sig-block { display:flex; flex-direction:column; }
                .fh-manifest-sig-line {
                    width:200px; border-bottom:1px solid #3a2a1a; margin-bottom:4px;
                }
                .fh-manifest-sig-label {
                    font-family:'Special Elite',monospace; font-size:8px; font-weight:400;
                    text-transform:uppercase; letter-spacing:0.1em; color:#8a7a5a;
                }
                /* Notes section */
                .fh-manifest-notes {
                    padding:8px 28px 12px; position:relative; z-index:2;
                }
                .fh-manifest-notes-label {
                    font-family:'Special Elite',monospace; font-size:10px; text-transform:uppercase;
                    color:#6b5a3a; display:inline-block; margin-right:8px; vertical-align:top; margin-top:4px;
                }
                .fh-manifest-notes-body {
                    display:inline-block; border-bottom:1px solid rgba(139,109,56,0.5);
                    padding:2px 4px 4px; min-width:280px;
                }
                .fh-manifest-note-text {
                    font-family:'Caveat',cursive; font-size:19px; font-weight:400;
                    color:#1a2744; opacity:0.75;
                }
                /* Signature */
                .fh-manifest-sig-name {
                    font-family:'Caveat',cursive; font-size:28px; font-weight:700;
                    color:#1a1a2e; display:inline-block; margin-bottom:-8px;
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
                .fh-ticket-sleeve {
                    font-family: "Special Elite", monospace;
                    background: linear-gradient(165deg, #0d2a4a 0%, #0a2040 50%, #071628 100%);
                    display: flex; flex-direction: column;
                    min-height: 420px; overflow: hidden; position: relative;
                }
                .fh-ticket-sleeve::before {
                    content: ''; position: absolute; inset: 0; pointer-events: none;
                    background: repeating-linear-gradient(-55deg, rgba(181,161,101,0.04) 0px, rgba(181,161,101,0.04) 1px, transparent 1px, transparent 10px);
                }
                .fh-ticket-sleeve::after {
                    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
                    background: linear-gradient(90deg, transparent, #b5a165 20%, #d4bc7e 50%, #b5a165 80%, transparent);
                }
                .fh-ts-flight-no { position: absolute; top: 14px; right: 20px; font-size: 11px; letter-spacing: 0.18em; color: rgba(212,188,126,0.65); }
                .fh-ts-top { padding: 20px 24px 12px; text-align: center; }
                .fh-ts-top-label { font-size: 10px; letter-spacing: 0.3em; color: rgba(212,188,126,0.8); text-transform: uppercase; }
                .fh-ts-rule { height: 1px; margin: 0 20px; background: linear-gradient(90deg, transparent, #b5a165 15%, #d4bc7e 50%, #b5a165 85%, transparent); }
                .fh-ts-stripe { background: linear-gradient(180deg, #f5f0e8 0%, #ede8dc 100%); padding: 18px 40px 16px; text-align: center; border-top: 2px solid #d4bc7e; border-bottom: 2px solid #d4bc7e; position: relative; }
                .fh-ts-stripe::before { content: '\2726'; position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-size: 12px; color: #b5a165; }
                .fh-ts-stripe::after  { content: '\2726'; position: absolute; right: 18px; top: 50%; transform: translateY(-50%); font-size: 12px; color: #b5a165; }
                .fh-ts-name { font-size: 28px; letter-spacing: 0.18em; color: #0a2040; text-transform: uppercase; line-height: 1; }
                .fh-ts-name-sub { font-size: 11px; letter-spacing: 0.28em; color: #8a6f2e; text-transform: uppercase; margin-top: 6px; font-weight: bold; }
                .fh-ts-body { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 12px 24px; gap: 4px; }
                .fh-ts-issued-by { font-size: 9px; letter-spacing: 0.28em; color: rgba(212,188,126,0.6); text-transform: uppercase; }
                .fh-ts-issuer { font-size: 13px; letter-spacing: 0.18em; color: #d4bc7e; text-transform: uppercase; font-weight: bold; }
                .fh-ts-tagline { font-size: 10px; letter-spacing: 0.14em; color: rgba(212,188,126,0.75); text-transform: uppercase; font-style: italic; }
                .fh-ts-divider { width: 140px; height: 1px; margin: 4px auto; background: linear-gradient(90deg, transparent, rgba(181,161,101,0.5), transparent); }
                .fh-ts-login {
                    display: inline-block; padding: 12px 34px; margin-top: 2px;
                    border: 1.5px solid #d4bc7e; color: #f0e0a0;
                    font-family: "Special Elite", monospace;
                    font-size: 12px; letter-spacing: 0.22em;
                    text-transform: uppercase; text-decoration: none;
                    background: rgba(181,161,101,0.22);
                    box-shadow: 0 0 18px rgba(181,161,101,0.15), inset 0 1px 0 rgba(255,255,255,0.08);
                }
                .fh-ts-login::before, .fh-ts-login::after { content: '\2726'; font-size: 8px; color: #b5a165; margin: 0 8px; vertical-align: middle; }
                .fh-ts-login:hover { background: rgba(181,161,101,0.32); color: #fff8e0; text-decoration: none; }
                .fh-ts-fine { font-size: 8.5px; letter-spacing: 0.1em; color: rgba(212,188,126,0.5); text-align: center; text-transform: uppercase; line-height: 1.8; margin-top: 6px; padding: 0 16px; }
                .fh-ts-bottom-rule { height: 3px; background: linear-gradient(90deg, transparent, #b5a165 20%, #d4bc7e 50%, #b5a165 80%, transparent); }
                @media (max-width: 767px) {
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
                    <!-- Staple marks -->
                    <?php $fh_staple_rots = [-4,-2,-1,1,3,5]; ?>
                    <div class="fh-manifest-staple" style="transform:rotate(<?php echo $fh_staple_rots[rand(0,5)]; ?>deg)"></div>
                    <div class="fh-manifest-staple" style="transform:rotate(<?php echo $fh_staple_rots[rand(0,5)]; ?>deg)"></div>
                    <!-- Rubber stamp -->
                    <div class="fh-manifest-stamp">Cleared for Departure</div>
                    <span class="fh-manifest-splatter" style="top:49px;left:48%;width:3px;height:3px"></span>
                    <span class="fh-manifest-splatter" style="top:54px;left:55%;width:2px;height:2px"></span>
                    <span class="fh-manifest-splatter" style="top:72px;left:46%;width:4px;height:4px"></span>
                    <span class="fh-manifest-splatter" style="top:50px;left:58%;width:2px;height:3px"></span>
                    <span class="fh-manifest-splatter" style="top:75px;left:53%;width:3px;height:3px"></span>
                    <span class="fh-manifest-splatter" style="top:48px;left:42%;width:2px;height:2px"></span>
                    <!-- Coffee stain -->
                    <svg class="fh-manifest-coffee" viewBox="0 0 120 110" xmlns="http://www.w3.org/2000/svg">
                        <defs><radialGradient id="fh-coffee-grad" cx="50%" cy="50%" r="50%">
                            <stop offset="0%" stop-color="rgba(101,67,33,0.18)"/>
                            <stop offset="50%" stop-color="rgba(101,67,33,0.25)"/>
                            <stop offset="85%" stop-color="rgba(101,67,33,0.05)"/>
                            <stop offset="100%" stop-color="rgba(101,67,33,0)"/>
                        </radialGradient></defs>
                        <ellipse cx="60" cy="55" rx="55" ry="50" fill="url(#fh-coffee-grad)"/>
                    </svg>
                    <!-- Document header -->
                    <div class="fh-manifest-doc-header">
                        <div class="fh-punch-hole fh-punch-left"></div>
                        <div class="fh-punch-hole fh-punch-right"></div>
                        <p class="fh-manifest-airline">FisHotel World Airways, Inc.</p>
                        <p class="fh-manifest-subtitle">Live Animal Cargo Manifest</p>
                        <hr class="fh-manifest-letterhead-rule">
                        <div class="fh-manifest-letterhead-row">
                            <span>Est. 2019 &middot; Champlin, MN &middot; U.S.A.</span>
                            <span>License No. FHW-2019-MN &middot; USDA Certified</span>
                        </div>
                        <hr class="fh-manifest-letterhead-rule">
                        <div class="fh-manifest-emblem">&#x2726; Live Animal Transport &middot; Marine Species &middot; International Quarantine Service &#x2726;</div>
                        <hr class="fh-manifest-rule">
                        <div class="fh-manifest-info">
                            <span>Flight No: <?php echo esc_html( $flight_number ); ?></span>
                            <span>|</span>
                            <span>Date: <?php echo strtoupper( date( 'M j, Y' ) ); ?></span>
                            <span>|</span>
                            <span>Origin: <?php echo esc_html( strtoupper( $origin_name ) ); ?></span>
                            <span>|</span>
                            <span>Destination: Champlin, MN</span>
                        </div>
                    </div>
                    <table>
                        <thead><tr>
                            <th>&nbsp;</th><th>Common Name</th><th>Scientific Name</th><th>Qty</th>
                        </tr></thead>
                        <tbody>
                        <?php
                        $fh_check_paths = [
                            'M2 7.5 C3 6.5, 5 10, 5.5 11 C6.5 8.5, 9.5 3.5, 12 2',
                            'M2.5 8 C3.5 7, 5.5 10.5, 6 11.5 C7 9, 10 4, 12.5 2.5',
                            'M2 7 C3 6, 4.5 9.5, 5 10.5 C6 8, 9 3, 11.5 1.5',
                            'M2.5 7 C3 6, 5 9.5, 5.5 10.5 C6.5 8, 10 3.5, 12 2.5',
                            'M2 8 C3.5 7.5, 5 11, 5.5 11.5 C6.5 9.5, 9.5 4.5, 12.5 3',
                        ];
                        $fh_ri = 0;
                        ?>
                        <?php foreach ( $manifest_species as $ms ) : ?>
                            <?php $fh_cp = $fh_check_paths[ $fh_ri % 5 ]; $fh_ri++; ?>
                            <tr>
                                <td><span class="fh-manifest-check"><svg viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg"><path d="<?php echo $fh_cp; ?>" stroke="#1a4a9e" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></span></td>
                                <td><?php echo esc_html( $ms['fish_name'] ); ?></td>
                                <td class="fh-manifest-sci"><?php echo esc_html( $ms['scientific_name'] ); ?></td>
                                <td><?php echo intval( $ms['total_qty'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fh-manifest-total">
                                <td>&nbsp;</td>
                                <td colspan="2" style="text-align:right;">Total Passengers: <?php echo intval( $manifest_total_fish ); ?> Fish</td>
                                <td style="text-align:center;"><?php echo intval( $manifest_total_fish ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- Notes -->
                    <?php
                    $fh_notes = [
                        'handle with care — live specimens',
                        'all counted, verified × 2',
                        'CITES permit attached — see file',
                        'Ref: QT-' . rand(1000,9999) . ' · confirmed',
                        'inspector on site 0600 hrs',
                        'fragile · keep upright · no stacking',
                        'priority shipment — expedite customs',
                        'temp-controlled hold confirmed',
                        'all specimens accounted for',
                        'customs pre-cleared · FHW auth.',
                    ];
                    $fh_note = $fh_notes[ rand(0, count($fh_notes) - 1) ];
                    $fh_note_words = explode(' ', $fh_note);
                    $fh_rotations = [-1.5, 0.5, -2.0, 1.0, -0.5];
                    $fh_note_html = '';
                    foreach ( $fh_note_words as $wi => $w ) {
                        $r = $fh_rotations[ $wi % count($fh_rotations) ];
                        $fh_note_html .= '<span style="display:inline-block;transform:rotate(' . $r . 'deg)">' . esc_html($w) . '</span> ';
                    }
                    $fh_sig_styles = [
                        'transform:rotate(-4deg) scaleX(1.05);letter-spacing:1px',
                        'transform:rotate(-2deg) scaleX(0.95);letter-spacing:-1px',
                        'transform:rotate(-6deg) scaleX(1.1);letter-spacing:2px',
                        'transform:rotate(-3deg) scaleX(1.0);letter-spacing:0px',
                        'transform:rotate(-5deg) scaleX(1.08);letter-spacing:1.5px',
                    ];
                    $fh_sig_style = $fh_sig_styles[ rand(0, 4) ];
                    ?>
                    <div class="fh-manifest-notes">
                        <span class="fh-manifest-notes-label">NOTES:</span>
                        <div class="fh-manifest-notes-body">
                            <span class="fh-manifest-note-text"><?php echo $fh_note_html; ?></span>
                        </div>
                    </div>
                    <!-- Footer -->
                    <div class="fh-manifest-footer">
                        <div class="fh-manifest-signatures">
                            <div class="fh-manifest-sig-block">
                                <span class="fh-manifest-sig-name" style="<?php echo esc_attr($fh_sig_style); ?>">Dierks</span>
                                <div class="fh-manifest-sig-line"></div>
                                <span class="fh-manifest-sig-label">Supervising Agent</span>
                            </div>
                        </div>
                        <hr class="fh-manifest-letterhead-rule">
                        <p class="fh-manifest-carrier">Carrier: FisHotel World Airways, Inc. &middot; All Live Cargo Subject to Quarantine Inspection</p>
                    </div>
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
                    <div class="fh-ticket-sleeve">
                        <div class="fh-ts-flight-no"><?php echo esc_html( $flight_number ); ?></div>
                        <div class="fh-ts-top">
                            <div class="fh-ts-top-label">&#x2708; &nbsp; Passenger Ticket &amp; Boarding Pass &nbsp; &#x2708;</div>
                        </div>
                        <div class="fh-ts-rule"></div>
                        <div class="fh-ts-stripe">
                            <div class="fh-ts-name">The FisHotel</div>
                            <div class="fh-ts-name-sub">International &middot; Quarantine Service</div>
                        </div>
                        <div class="fh-ts-body">
                            <div class="fh-ts-issued-by">Issued by</div>
                            <div class="fh-ts-issuer">FisHotel World Airways, Inc.</div>
                            <div class="fh-ts-tagline">World's Most Luxury Vacation Experience</div>
                            <div class="fh-ts-divider"></div>
                            <button type="button" class="fh-ts-login" onclick="document.getElementById('fishotel-login-modal').style.display='flex'">Log In to See Your Boarding Pass</button>
                            <div class="fh-ts-fine">
                                Each passenger should carefully examine this ticket.<br>
                                This ticket shall not be valid without a verified deposit on file.
                            </div>
                        </div>
                        <div class="fh-ts-bottom-rule"></div>
                    </div>
                    <?php else : ?>
                    <div class="fh-boarding-pass">
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
                    <?php endif; ?>
                </div>

            </div>
            <?php
            return ob_get_clean();
        }

        // ─── Stage 5: Verification page ──
        if ( $status === 'verification' ) {
            ob_end_clean();
            return $this->render_verification_page( $batch_name );
        }

        // ─── Stage 4: Hotel Program postcard (in_quarantine) ──
        if ( $status === 'in_quarantine' ) {
            ob_end_clean();
            return $this->hotel_postcard_shortcode( $batch_name );
        }

        // ─── Stage 6a: Last Call draft pool ──
        if ( $status === 'draft' ) {
            ob_end_clean();
            return $this->render_last_call_page( $batch_name );
        }

        // ─── Stage 6b: Casino intermission ──
        if ( $status === 'casino' ) {
            ob_end_clean();
            $arcade = new FisHotel_Arcade();
            return $arcade->arcade_shortcode( [] );
        }

        // ─── Stage 7: Invoicing ──
        if ( $status === 'invoicing' ) {
            ob_end_clean();
            $fonts_url = 'https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Special+Elite&display=swap';
            ob_start();
            ?>
            <link href="<?php echo esc_url( $fonts_url ); ?>" rel="stylesheet">
            <div style="max-width:680px;margin:40px auto;padding:40px;background:#f5f0e8;border:4px double #2e2418;text-align:center;font-family:'Courier New',monospace;color:#2e2418;color-scheme:light;">
                <h2 style="font-family:'Oswald',sans-serif;letter-spacing:0.2em;color:#96885f;margin-top:0;">THE FISHOTEL</h2>
                <p style="font-variant:small-caps;letter-spacing:0.15em;font-size:0.9rem;">Invoice Processing</p>
                <hr style="border:none;border-top:1px solid #d6cfc2;margin:20px 0;">
                <p style="font-family:'Special Elite',monospace;font-size:0.95rem;color:#555;line-height:1.6;">
                    Your order is being finalized.<br>Invoice coming soon.
                </p>
                <p style="font-size:0.75rem;color:#998877;margin-top:24px;">THE FISHOTEL &middot; CHAMPLIN, MN &middot; EST. 2024</p>
            </div>
            <?php
            return ob_get_clean();
        }

        // ─── Stage 3b: Arrival tracking view (arrived + all post-arrived stages) ──
        $arrived_stages = [ 'arrived', 'in_quarantine', 'graduation', 'verification', 'draft', 'casino', 'invoicing' ];
        if ( in_array( $status, $arrived_stages, true ) ) {
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
                $cq   = get_post_meta( $bp->ID, '_current_qty', true );
                $recv = ( $cq !== '' && $cq !== false ) ? intval( $cq ) : intval( get_post_meta( $bp->ID, '_arrival_qty_received', true ) );
                $doa  = intval( get_post_meta( $bp->ID, '_arrival_qty_doa', true ) );
                $species_arrival[ $bp->ID ] = [ 'received' => $recv, 'doa' => $doa, 'alive' => $recv - $doa ];
            }

            // Check if arrival data has been entered yet
            $total_received = 0;
            foreach ( $species_arrival as $sa_check ) { $total_received += $sa_check['received']; }
            $arrival_pending = ( $total_received === 0 );

            // Current user items
            $my_items   = [];
            $uid        = is_user_logged_in() ? get_current_user_id() : 0;
            if ( $uid ) {
                foreach ( $all_requests as $req ) {
                    if ( get_post_meta( $req->ID, '_is_admin_order', true ) ) continue;
                    if ( intval( get_post_meta( $req->ID, '_customer_id', true ) ) !== $uid ) continue;
                    $req_items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                    foreach ( $req_items as $item ) {
                        $item['fish_name'] = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $item['fish_name'] ?? '' ) );
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
            <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
            <style>
                .fh-arrival-wrap {
                    max-width:900px; margin:0 auto;
                    font-family:'Oswald',sans-serif; color:#fff;
                }
                /* ── Hotel Spa Welcome Panel ── */
                .fh-welcome-panel {
                    background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='wp'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23wp)' opacity='0.04'/%3E%3C/svg%3E"),
                              linear-gradient(165deg,#0c161f 0%,#0a1320 50%,#081018 100%);
                    background-size:200px 200px,cover;
                    border:2px solid #b5a165; border-radius:12px;
                    padding:36px 28px 28px; text-align:center; margin-bottom:24px;
                    position:relative; overflow:hidden;
                }
                .fh-welcome-title {
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(1.4rem,3.5vw,2rem); color:#b5a165;
                    text-transform:uppercase; letter-spacing:0.08em; margin:0 0 8px;
                }
                .fh-welcome-sub {
                    color:#8a9bae; font-size:clamp(0.8rem,2vw,0.95rem); margin:0 0 24px;
                    font-weight:400; line-height:1.5;
                }
                .fh-welcome-stats {
                    display:flex; justify-content:center; gap:12px; flex-wrap:wrap;
                    margin-bottom:24px;
                }
                .fh-welcome-stat {
                    text-align:center; padding:0 16px;
                }
                .fh-welcome-stat-label {
                    font-family:'Oswald',sans-serif; font-weight:400;
                    font-size:10px; letter-spacing:0.12em; text-transform:uppercase;
                    color:#8a7a50; margin:0 0 4px;
                }
                .fh-welcome-stat-val {
                    font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.95rem,2.5vw,1.2rem); color:#d4bc7e;
                    text-transform:uppercase; letter-spacing:0.04em; margin:0;
                }
                .fh-welcome-stat-sep {
                    display:flex; align-items:center; color:#8a7a50;
                    font-family:'Oswald',sans-serif; font-size:18px; padding:0 4px;
                }
                .fh-welcome-stamp {
                    display:inline-block; border:3px solid #e67e22; border-radius:4px;
                    padding:8px 22px; font-family:'Oswald',sans-serif; font-weight:700;
                    font-size:clamp(0.95rem,2.5vw,1.3rem); text-transform:uppercase;
                    letter-spacing:0.06em; transform:rotate(-3deg); color:#e67e22;
                }

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

                /* ── Pending state ── */
                .fh-arr-pending {
                    background:#0c161f; border:2px solid #b5a165; border-radius:10px;
                    padding:40px 28px; text-align:center; margin-bottom:24px;
                }
                .fh-arr-pending-icon { font-size:48px; margin-bottom:12px; }
                .fh-arr-pending-title {
                    font-family:'Oswald',sans-serif; font-weight:700; font-size:20px;
                    text-transform:uppercase; letter-spacing:0.1em; color:#b5a165; margin:0 0 10px;
                }
                .fh-arr-pending-text { color:#8a9bae; font-size:14px; line-height:1.5; margin:0; }

                /* (Spa check-in card styles removed — replaced by welcome panel) */

                /* ── Hotel Spa Check-In Card ── */
                /* ── Livestock Customs Declaration ── */
                .fh-customs-card {
                    margin-bottom:24px; position:relative;
                    background:#c8bfa0; border:1px solid #9e9070; border-radius:2px;
                    box-shadow:2px 3px 10px rgba(0,0,0,0.5);
                    overflow:hidden;
                    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='cn'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.7' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23cn)' opacity='0.06'/%3E%3C/svg%3E");
                    background-size:200px 200px;
                }
                .fh-customs-inner { margin:8px; border:1px solid rgba(100,80,50,0.25); padding:12px 16px; position:relative; background:transparent; }
                .fh-customs-edge { height:4px; background:repeating-linear-gradient(90deg,#8b0000 0,#8b0000 12px,transparent 12px,transparent 18px); }
                .fh-customs-topline { display:flex; justify-content:space-between; margin:0 !important; padding:0; font-family:'Courier New',monospace; font-size:10px; color:#8a7a5a; text-transform:uppercase; letter-spacing:0.06em; }
                .fh-customs-seal { text-align:center; margin:0 0 6px 0 !important; padding:0 !important; line-height:0; }
                .fh-customs-seal img { width:80px; height:80px; display:block; margin:0 auto; opacity:0.85; }
                .fh-customs-title { text-align:center; margin:0 !important; padding:0 !important; }
                .fh-customs-title h2 { font-family:'Oswald',sans-serif; font-weight:700; font-size:18px; color:#2e2418; letter-spacing:6px; text-transform:uppercase; margin:0 !important; padding:0 !important; }
                .fh-customs-title p { font-family:'Oswald',sans-serif; font-weight:400; font-size:12px; color:#6b5a3a; letter-spacing:4px; text-transform:uppercase; margin:0 !important; padding:0 !important; }
                .fh-customs-hr { border:none; border-top:2px solid #2e2418; margin:2px 0 !important; padding:0 !important; height:0; }
                .fh-customs-hr2 { border:none; border-top:1px solid #a89878; margin:2px 0 !important; padding:0 !important; height:0; }
                .fh-customs-fields { display:flex; gap:12px; margin:0 0 8px 0 !important; padding:0; flex-wrap:wrap; }
                .fh-customs-field { flex:1; min-width:80px; }
                .fh-customs-field-label { font-family:'Courier New',monospace; font-size:9px; color:#8a7a5a; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:1px; }
                .fh-customs-field-val { font-family:'Courier New',monospace; font-size:13px; color:#2e2418; font-weight:700; text-transform:uppercase; border-bottom:1px solid #6b5a3a; padding-bottom:1px; }
                .fh-customs-section { font-family:'Oswald',sans-serif; font-weight:600; font-size:11px; color:#6b5a3a; letter-spacing:3px; text-transform:uppercase; margin:8px 0 4px 0; }
                .fh-customs-table { width:100%; border-collapse:collapse; font-family:'Courier New',monospace; font-size:12px; color:#2e2418; background:transparent !important; }
                .fh-customs-table thead tr { background:transparent !important; }
                .fh-customs-table th { font-size:9px; color:#8a7a5a !important; text-transform:uppercase; letter-spacing:2px; text-align:left; padding:3px 6px; border-bottom:2px solid #6b5a3a; font-weight:400; background:transparent !important; }
                .fh-customs-table td { padding:4px 6px; border-bottom:1px dashed #a89878; vertical-align:middle; color:#2e2418 !important; background:transparent !important; }
                .fh-customs-table tr:last-child td { border-bottom:none; }
                .fh-customs-table tr { background:transparent !important; }
                .fh-customs-table tbody tr { background:transparent !important; }
                .fh-customs-table tbody tr:nth-child(odd) { background:transparent !important; }
                .fh-customs-table tbody tr:nth-child(even) { background:transparent !important; }
                .fh-customs-species { font-weight:700; text-transform:uppercase; }
                .fh-customs-status-qt { color:#1a7a3a; font-weight:700; }
                .fh-customs-status-pending { color:#b5750e; font-weight:700; }
                .fh-customs-status-doa { color:#8b0000; font-weight:700; }
                .fh-customs-status-noarr { color:#6b5a3a; font-weight:700; }
                .fh-customs-status-short { color:#b5750e; font-weight:700; }
                .fh-customs-footer { display:flex; justify-content:space-between; align-items:flex-end; margin-top:8px; }
                .fh-customs-facility { font-family:'Courier New',monospace; font-size:9px; color:#8a7a5a; text-transform:uppercase; letter-spacing:0.04em; line-height:1.5; }
                .fh-customs-stamp { display:inline-block; }
                .fh-customs-empty { padding:12px 0; text-align:center; font-family:'Courier New',monospace; font-size:12px; color:#6b5a3a; text-transform:uppercase; letter-spacing:0.06em; }
                @media (max-width:600px) {
                    .fh-customs-inner { padding:10px 10px; }
                    .fh-customs-fields { display:grid !important; grid-template-columns:1fr 1fr !important; gap:8px !important; }
                    .fh-customs-fields > div { flex:none !important; }
                    .fh-customs-field { min-width:60px; }
                    .fh-customs-table { font-size:10px !important; }
                    .fh-customs-table th { font-size:8px; }
                    .fh-customs-table th,
                    .fh-customs-table td { padding:4px 2px !important; }
                    .fh-customs-footer { flex-direction:column; align-items:center; gap:8px; text-align:center; }
                    .fh-customs-stamp img { width:140px !important; }
                }

                /* ── Collapsible boarding pass strip (hidden at arrived stage) ── */
                .fh-ab-strip { display:none !important; }
                .fh-ab-detail { display:none !important; }
                .fh-ab-strip-visible {
                    display:flex; align-items:center; gap:16px;
                    background:#0c161f; border:2px solid #b5a165; border-radius:10px;
                    padding:12px 20px; margin-bottom:24px; cursor:pointer;
                    transition:background 0.2s;
                }
                .fh-ab-strip:hover { background:#0f1e2d; }
                .fh-ab-strip-logo img { width:36px; height:36px; object-fit:contain; }
                .fh-ab-strip-info { flex:1; }
                .fh-ab-strip-title {
                    font-family:'Oswald',sans-serif; font-weight:700; font-size:14px;
                    text-transform:uppercase; letter-spacing:0.08em; color:#b5a165; margin:0;
                }
                .fh-ab-strip-meta {
                    font-family:'Oswald',sans-serif; font-size:11px; font-weight:400;
                    text-transform:uppercase; letter-spacing:0.06em; color:#8a9bae; margin:0;
                }
                .fh-ab-strip-chevron {
                    font-size:20px; color:#b5a165; transition:transform 0.3s;
                }
                .fh-ab-strip-chevron.open { transform:rotate(180deg); }
                .fh-ab-detail {
                    max-height:0; overflow:hidden;
                    transition:max-height 0.4s ease;
                }
                .fh-ab-detail.open { max-height:2000px; }
            </style>
            <div class="fh-arrival-wrap">

                <!-- ===== Collapsible Boarding Pass Strip ===== -->
                <?php
                $my_species_count = count( $my_items );
                $strip_origin = strtoupper( preg_split( '/[\s\-]/', $batch_name )[0] ?? $batch_name );
                $strip_parts = [];
                $strip_parts[] = $strip_origin . ' ' . preg_replace( '/^[a-zA-Z]+\s*/', '', $batch_name );
                $strip_parts[] = 'FISH ARE HERE';
                if ( $qt_days_left > 0 ) {
                    $strip_parts[] = 'IN QUARANTINE';
                    $strip_parts[] = $qt_days_left . ' DAY' . ( $qt_days_left !== 1 ? 'S' : '' ) . ' REMAINING';
                } elseif ( $arrival_date ) {
                    $strip_parts[] = 'QT COMPLETE';
                }
                $strip_text = implode( ' · ', $strip_parts );
                ?>
                <div class="fh-ab-strip" onclick="var d=document.getElementById('fh-ab-detail'),c=this.querySelector('.fh-ab-strip-chevron');d.classList.toggle('open');c.classList.toggle('open');">
                    <div class="fh-ab-strip-logo">
                        <img src="https://fishotel.com/wp-content/uploads/2026/03/Small-Fish-Hotel-White.png" alt="FisHotel">
                    </div>
                    <div class="fh-ab-strip-info">
                        <p class="fh-ab-strip-title">&#x1F420; <?php echo esc_html( $strip_text ); ?></p>
                        <p class="fh-ab-strip-meta">
                            <?php if ( $uid && $my_species_count > 0 ) echo $my_species_count . ' species requested'; ?>
                        </p>
                    </div>
                    <div class="fh-ab-strip-chevron">&#x25BC;</div>
                </div>

                <!-- ===== Expandable Detail (Hotel Spa Welcome Panel) ===== -->
                <div id="fh-ab-detail" class="fh-ab-detail">
                <?php
                $arrived_fmt_stat = $arrival_date ? strtoupper( date( 'M j, Y', strtotime( $arrival_date ) ) ) : '—';
                $qt_end_date      = $arrival_date ? strtoupper( date( 'M j, Y', strtotime( $arrival_date . ' +14 days' ) ) ) : '—';
                $stamp_label      = $qt_days_left > 0 ? 'IN QUARANTINE' : 'QT COMPLETE';
                ?>
                <div class="fh-welcome-panel">
                    <h2 class="fh-welcome-title">Welcome to the Hotel Spa</h2>
                    <p class="fh-welcome-sub">Your fish have checked in and are receiving the full spa treatment.</p>
                    <div class="fh-welcome-stats">
                        <div class="fh-welcome-stat">
                            <p class="fh-welcome-stat-label">Arrived</p>
                            <p class="fh-welcome-stat-val"><?php echo esc_html( $arrived_fmt_stat ); ?></p>
                        </div>
                        <div class="fh-welcome-stat-sep">&middot;</div>
                        <div class="fh-welcome-stat">
                            <p class="fh-welcome-stat-label">QT Ends</p>
                            <p class="fh-welcome-stat-val"><?php echo esc_html( $qt_end_date ); ?></p>
                        </div>
                        <div class="fh-welcome-stat-sep">&middot;</div>
                        <div class="fh-welcome-stat">
                            <p class="fh-welcome-stat-label">Days Remaining</p>
                            <p class="fh-welcome-stat-val"><?php echo $qt_days_left > 0 ? $qt_days_left : '0'; ?></p>
                        </div>
                    </div>
                    <div class="fh-welcome-stamp"><?php echo esc_html( $stamp_label ); ?></div>
                </div>
                </div>

                <?php if ( $arrival_pending ) : ?>
                <!-- ===== Pending State ===== -->
                <div class="fh-arr-pending">
                    <div class="fh-arr-pending-icon">&#x1F420;</div>
                    <p class="fh-arr-pending-title">Arrival Data Being Recorded</p>
                    <p class="fh-arr-pending-text">Dierks is counting fish right now. Check back in a few hours &mdash; your status will appear here once confirmed.</p>
                </div>

                <?php else : ?>
                <!-- ===== Livestock Customs Declaration ===== -->
                <?php
                // Generate flight number for customs form
                $batch_origin_arr  = get_option( 'fishotel_batch_origins', [] );
                $origin_name_arr   = $batch_origin_arr[ $batch_name ] ?? $batch_name;
                $origin_code_arr   = strtoupper( substr( preg_replace( '/[^a-zA-Z]/', '', $origin_name_arr ), 0, 2 ) );
                preg_match_all( '/\d+/', $batch_name, $dmatches_arr );
                $route_num_arr = '';
                foreach ( $dmatches_arr[0] as $d ) $route_num_arr .= $d;
                $route_num_arr = substr( $route_num_arr, 0, 4 );
                if ( ! $route_num_arr ) $route_num_arr = '001';
                $flight_number = 'FHI-' . $origin_code_arr . $route_num_arr;

                $customs_stamp = 'QUARANTINE HOLD';
                if ( $qt_days_left <= 0 && $arrival_date ) $customs_stamp = 'RELEASED';
                $passenger_name = $uid ? strtoupper( wp_get_current_user()->display_name ) : '';
                $customs_tank = '—';
                if ( $uid && ! empty( $my_items ) ) {
                    $first_bid = intval( $my_items[0]['batch_id'] ?? 0 );
                    if ( isset( $fcfs[ $first_bid ] ) ) {
                        foreach ( $fcfs[ $first_bid ] as $entry ) {
                            if ( $entry['customer_id'] === $uid ) { $customs_tank = 'NO. ' . $entry['cum_end']; break; }
                        }
                    }
                }
                ?>
                <?php if ( $uid && ! empty( $my_items ) ) : ?>
                <div class="fh-customs-card">
                    <div class="fh-customs-edge"></div>
                    <div class="fh-customs-inner">
                        <div class="fh-customs-topline">
                            <span>FORM FH-QT-001</span>
                            <span>COPY 1 &mdash; GUEST</span>
                        </div>
                        <?php
                        $stamp_index = ( crc32( $batch_name ) % 6 ) + 1;
                        $stamp_file  = 'top-stamp-' . str_pad( $stamp_index, 2, '0', STR_PAD_LEFT ) . '.png';
                        $stamp_url   = plugins_url( 'assists/stamps/' . $stamp_file, dirname( __FILE__ ) );
                        ?>
                        <div class="fh-customs-seal">
                            <img src="<?php echo esc_url( $stamp_url ); ?>" alt="Dept of Marine Affairs">
                        </div>
                        <div class="fh-customs-title">
                            <h2>FISHOTEL INTERNATIONAL</h2>
                            <p>LIVESTOCK CUSTOMS DECLARATION</p>
                        </div>
                        <hr class="fh-customs-hr"><hr class="fh-customs-hr2">
                        <div class="fh-customs-fields">
                            <div class="fh-customs-field">
                                <div class="fh-customs-field-label">Passenger</div>
                                <div class="fh-customs-field-val"><?php echo esc_html( $passenger_name ); ?></div>
                            </div>
                            <div class="fh-customs-field">
                                <div class="fh-customs-field-label">Flight</div>
                                <div class="fh-customs-field-val"><?php echo esc_html( $flight_number ); ?></div>
                            </div>
                            <div class="fh-customs-field">
                                <div class="fh-customs-field-label">Batch</div>
                                <div class="fh-customs-field-val"><?php echo esc_html( strtoupper( $batch_name ) ); ?></div>
                            </div>
                            <div class="fh-customs-field">
                                <div class="fh-customs-field-label">QT Tank</div>
                                <div class="fh-customs-field-val"><?php echo esc_html( $customs_tank ); ?></div>
                            </div>
                        </div>
                        <div class="fh-customs-section">Declared Livestock</div>
                        <table class="fh-customs-table">
                            <thead><tr>
                                <th>Species</th>
                                <th>Qty Declared</th>
                                <th>Qty Received</th>
                                <th>Condition</th>
                                <th>Queue Pos.</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ( $my_items as $item ) :
                                $bid      = intval( $item['batch_id'] ?? 0 );
                                $my_qty   = intval( $item['qty'] ?? 1 );
                                $sa_c     = $species_arrival[ $bid ] ?? [ 'received' => 0, 'doa' => 0, 'alive' => 0 ];
                                $recv_c   = intval( $sa_c['received'] );
                                $doa_c    = intval( $sa_c['doa'] );
                                $c_status_raw = get_post_meta( $bid, '_arrival_status', true ) ?: 'pending';
                                $c_status_map = [
                                    'in_quarantine' => [ 'IN QT', 'fh-customs-status-qt' ],
                                    'pending'       => [ 'PENDING', 'fh-customs-status-pending' ],
                                    'no_arrival'    => [ 'NO ARRIVAL', 'fh-customs-status-noarr' ],
                                    'short'         => [ 'SHORT', 'fh-customs-status-short' ],
                                    'in_transit'    => [ 'IN TRANSIT', 'fh-customs-status-pending' ],
                                    'landed'        => [ 'LANDED', 'fh-customs-status-pending' ],
                                    'counting'      => [ 'COUNTING', 'fh-customs-status-pending' ],
                                ];
                                $c_stat   = $c_status_map[ $c_status_raw ] ?? [ strtoupper( $c_status_raw ), 'fh-customs-status-pending' ];
                                if ( $doa_c > 0 && $recv_c === $doa_c ) $c_stat = [ 'DOA', 'fh-customs-status-doa' ];

                                $position_c = '—';
                                if ( isset( $fcfs[ $bid ] ) ) {
                                    $total_demand = 0;
                                    $my_pos = 0;
                                    foreach ( $fcfs[ $bid ] as $entry ) {
                                        $total_demand = $entry['cum_end'];
                                        if ( $entry['customer_id'] === $uid ) $my_pos = $entry['cum_end'];
                                    }
                                    if ( $my_pos > 0 ) $position_c = $my_pos . ' of ' . $total_demand;
                                }
                                $fish_name = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', get_the_title( $bid ) ) );
                                error_log( '[FH Debug] customs fish_name: ' . $fish_name );
                            ?>
                            <tr>
                                <td class="fh-customs-species"><?php echo esc_html( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $fish_name ) ); ?></td>
                                <td><?php echo $my_qty; ?></td>
                                <td><?php echo $recv_c; ?></td>
                                <td><span class="<?php echo esc_attr( $c_stat[1] ); ?>"><?php echo esc_html( $c_stat[0] ); ?></span></td>
                                <td><?php echo esc_html( $position_c ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="fh-customs-footer">
                            <div class="fh-customs-facility">
                                QUARANTINE FACILITY: THE HOTEL SPA<br>
                                CHAMPLIN, MN &middot; EST. 2024
                            </div>
                            <?php
                            $qt_stamp_index = ( crc32( $batch_name . 'qt' ) % 3 ) + 1;
                            $qt_stamp_file  = 'qt-hold-' . str_pad( $qt_stamp_index, 2, '0', STR_PAD_LEFT ) . '.png';
                            $qt_stamp_url   = plugins_url( 'assists/stamps/' . $qt_stamp_file, dirname( __FILE__ ) );
                            ?>
                            <div class="fh-customs-stamp">
                                <img src="<?php echo esc_url( $qt_stamp_url ); ?>" alt="Quarantine Hold"
                                     style="width:180px; height:auto; display:block; opacity:0.85; transform:rotate(-4deg);">
                            </div>
                        </div>
                    </div>
                    <div class="fh-customs-edge"></div>
                </div>
                <?php elseif ( ! $uid ) : ?>
                <div class="fh-customs-card">
                    <div class="fh-customs-edge"></div>
                    <div class="fh-customs-inner">
                        <div class="fh-customs-topline">
                            <span>FORM FH-QT-001</span>
                            <span>COPY 1 &mdash; GUEST</span>
                        </div>
                        <div class="fh-customs-title">
                            <h2>FISHOTEL INTERNATIONAL</h2>
                            <p>LIVESTOCK CUSTOMS DECLARATION</p>
                        </div>
                        <hr class="fh-customs-hr"><hr class="fh-customs-hr2">
                        <div class="fh-customs-empty">
                            <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="color:#6b5a3a;text-decoration:none;">PLEASE PRESENT YOUR CREDENTIALS AT THE FRONT DESK</a>
                        </div>
                    </div>
                    <div class="fh-customs-edge"></div>
                </div>
                <?php elseif ( empty( $my_items ) ) : ?>
                <div class="fh-customs-card">
                    <div class="fh-customs-edge"></div>
                    <div class="fh-customs-inner">
                        <div class="fh-customs-topline">
                            <span>FORM FH-QT-001</span>
                            <span>COPY 1 &mdash; GUEST</span>
                        </div>
                        <div class="fh-customs-title">
                            <h2>FISHOTEL INTERNATIONAL</h2>
                            <p>LIVESTOCK CUSTOMS DECLARATION</p>
                        </div>
                        <hr class="fh-customs-hr"><hr class="fh-customs-hr2">
                        <div class="fh-customs-empty">NO DECLARATION ON FILE</div>
                    </div>
                    <div class="fh-customs-edge"></div>
                </div>
                <?php endif; ?>

                <!-- ===== Solari Arrival Board ===== -->
                <?php
                // Build species data for the Solari board
                $board_raw = [];
                foreach ( $batch_posts as $bp ) {
                    $cname  = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $bp->post_title ) );
                    $sa     = $species_arrival[ $bp->ID ] ?? [ 'received' => 0, 'doa' => 0, 'alive' => 0 ];
                    $demand = 0;
                    if ( isset( $fcfs[ $bp->ID ] ) ) {
                        $last = end( $fcfs[ $bp->ID ] );
                        $demand = $last ? $last['cum_end'] : 0;
                    }
                    // Remove colons and collapse whitespace (e.g. "BUTTERFLY : BARONESSA" → "BUTTERFLY BARONESSA")
                    $board_cname = trim( preg_replace( '/\s+/', ' ', str_replace( ':', '', $cname ) ) );
                    $board_raw[] = [
                        'fish_id'      => $bp->ID,
                        'common_name'  => $board_cname,
                        'name'         => strtoupper( $board_cname ),
                        'qty_received' => $sa['received'],
                        'recv'         => $sa['received'],
                        'qty_ordered'  => $demand,
                        'ordered'      => $demand,
                        'qty_doa'      => $sa['doa'],
                        'doa'          => $sa['doa'],
                        'alive'        => $sa['alive'],
                        'tank'         => get_post_meta( $bp->ID, '_arrival_tank', true ) ?: '—',
                        'status'       => get_post_meta( $bp->ID, '_arrival_status', true ) ?: 'in_transit',
                        'updated_at'   => intval( get_post_meta( $bp->ID, '_arrival_updated_at', true ) ),
                    ];
                }
                $board_species = FisHotel_Batch_Manager::dedup_species( $board_raw );
                foreach ( $board_species as &$bsp ) {
                    $bsp['name']  = strtoupper( $bsp['common_name'] );
                    $bsp['alive'] = ( $bsp['recv'] ?? $bsp['qty_received'] ) - ( $bsp['doa'] ?? $bsp['qty_doa'] );
                }
                unset( $bsp );
                $stage_labels = fishotel_stage_label_map();
                $ab_stage_label = strtoupper( $stage_labels[ $status ] ?? $status );
                $ab_batch_slug = urlencode( $batch_name );
                ?>
                <style><?php $this->render_arrival_board_css(); ?></style>
                <?php $this->render_arrival_board_html( $batch_name, $ab_stage_label, $board_species, $ab_batch_slug, $arrival_date, false, ( $status === 'arrived' ) ); ?>
                <?php endif; ?>

                <!-- ===== QT Footer ===== -->
                <?php if ( $qt_end_fmt ) : ?>
                <div class="fh-arrival-footer">
                    Quarantine ends <strong><?php echo esc_html( $qt_end_fmt ); ?></strong>
                </div>
                <?php endif; ?>

                <script>
                // Strip batch suffix from customs form species cells (JS fallback)
                document.querySelectorAll('.fh-customs-species').forEach(function(td) {
                    td.textContent = td.textContent.replace(/\s+[\u2013\u2014-]\s+.+$/, '');
                });
                </script>

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
                    $item['fish_name'] = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $item['fish_name'] ?? '' ) );
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
                        'fish_name'       => trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+.+$/u', '', $item['fish_name'] ?? '' ) ),
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

    /* ─────────────────────────────────────────────
     *  Stage 5: Customer Verification Page
     * ───────────────────────────────────────────── */

    public function render_verification_page( $batch_name ) {
        ob_start();

        $fonts_url = 'https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Special+Elite&family=Klee+One&display=swap';

        // ── Not logged in ──
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            ?>
            <link href="<?php echo esc_url( $fonts_url ); ?>" rel="stylesheet">
            <style>
                .fhf-login-wrap{max-width:680px;margin:40px auto;position:relative;color-scheme:light !important;}
                .fhf-login-doc{background:#f5f0e8;border:4px double #2e2418;padding:60px 40px;text-align:center;position:relative;filter:blur(3px);pointer-events:none;min-height:300px;
                    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");}
                .fhf-login-doc p{color:#2e2418 !important;}
                .fhf-login-doc .fhf-login-title{color:#96885f !important;}
                .fhf-login-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:10;}
                .fhf-login-heading{font-family:'Courier New',monospace;font-size:clamp(0.8rem,2.2vw,1rem);color:#2e2418 !important;text-transform:uppercase;letter-spacing:0.12em;font-variant:small-caps;text-align:center;margin:0 0 20px;max-width:420px;line-height:1.6;}
                .fhf-login-btn{display:inline-block;background:#2e2418;color:#f5f0e8 !important;font-family:'Courier New',monospace;font-weight:700;font-size:0.85rem;padding:12px 40px;text-decoration:none;text-transform:uppercase;letter-spacing:0.1em;border:2px solid #2e2418;cursor:pointer;transition:background 0.2s;}
                .fhf-login-btn:hover{background:#4a3a28;color:#f5f0e8 !important;}
                .fhf-login-note{font-family:'Special Elite',monospace;font-size:0.72rem;color:#998877 !important;text-align:center;margin:16px 0 0;max-width:360px;}
                #fhf-login-modal input:-webkit-autofill,
                #fhf-login-modal input:-webkit-autofill:hover,
                #fhf-login-modal input:-webkit-autofill:focus {
                    -webkit-box-shadow: 0 0 0 1000px #071420 inset !important;
                    -webkit-text-fill-color: #e8dcc0 !important;
                    caret-color: #e8dcc0;
                }
            </style>
            <div class="fhf-login-wrap">
                <div class="fhf-login-doc">
                    <p class="fhf-login-title" style="font-family:'Oswald',sans-serif;font-size:1.5rem;letter-spacing:0.12em;font-weight:700;">THE FISHOTEL</p>
                    <p style="font-family:'Courier New',monospace;font-size:0.8rem;font-variant:small-caps;letter-spacing:0.15em;">Guest Folio</p>
                    <div style="margin-top:30px;height:40px;border-bottom:1px solid #d6cfc2;"></div>
                    <div style="display:flex;justify-content:space-between;padding:12px 20px;font-family:'Courier New',monospace;font-size:0.7rem;color:#998877;">
                        <span>GUEST: ____________</span><span>BATCH: ____________</span>
                    </div>
                    <div style="margin-top:20px;height:80px;border:1px solid #d6cfc2;"></div>
                </div>
                <div class="fhf-login-overlay">
                    <p class="fhf-login-heading">Please identify yourself at the front desk</p>
                    <button type="button" class="fhf-login-btn" onclick="document.getElementById('fhf-login-modal').style.display='flex'">Log In</button>
                    <p class="fhf-login-note">Don't have an account? This is a members-only process.</p>
                </div>
            </div>
            <!-- ===== Folio Login Modal — GATE ACCESS REQUIRED ===== -->
            <div id="fhf-login-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(4,12,22,0.95);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='150' height='150'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='150' height='150' filter='url(%23n)' opacity='0.06'/%3E%3C/svg%3E&quot;),linear-gradient(165deg,#071420 0%,#060f1a 50%,#040c15 100%);background-size:150px 150px,cover;border:1px solid rgba(181,161,101,0.4);border-radius:3px;box-shadow:0 20px 60px rgba(0,0,0,0.6),0 0 0 1px rgba(181,161,101,0.1);padding:36px 40px 32px;width:400px;max-width:92%;color:#fff;position:relative;overflow:hidden;">
                    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,#b5a165 20%,#d4bc7e 50%,#b5a165 80%,transparent);"></div>
                    <h3 style="margin:0 0 24px;font-family:'Special Elite',monospace;font-size:13px;letter-spacing:0.3em;color:#d4bc7e;text-transform:uppercase;text-align:center;">Gate Access Required</h3>
                    <form method="post" action="<?php echo esc_url( $login_url ); ?>">
                        <?php wp_nonce_field( 'fishotel_login', 'fishotel_login_nonce' ); ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( get_permalink() ); ?>">
                        <input type="hidden" name="rememberme" value="forever">
                        <p><input type="text" name="log" placeholder="USERNAME OR EMAIL" autocomplete="username" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.06);border:1px solid rgba(181,161,101,0.3);color:#e8dcc0;font-family:'Special Elite',monospace;letter-spacing:0.05em;box-sizing:border-box;" onfocus="this.style.borderColor='rgba(181,161,101,0.7)';this.style.background='rgba(255,255,255,0.09)';this.style.outline='none'" onblur="this.style.borderColor='rgba(181,161,101,0.3)';this.style.background='rgba(255,255,255,0.06)'"></p>
                        <p><input type="password" name="pwd" placeholder="PASSWORD" autocomplete="current-password" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.06);border:1px solid rgba(181,161,101,0.3);color:#e8dcc0;font-family:'Special Elite',monospace;letter-spacing:0.05em;box-sizing:border-box;" onfocus="this.style.borderColor='rgba(181,161,101,0.7)';this.style.background='rgba(255,255,255,0.09)';this.style.outline='none'" onblur="this.style.borderColor='rgba(181,161,101,0.3)';this.style.background='rgba(255,255,255,0.06)'"></p>
                        <p><button type="submit" style="width:100%;padding:12px;background:rgba(181,161,101,0.22);border:1.5px solid #d4bc7e;color:#f0e0a0;font-family:'Special Elite',monospace;font-size:12px;letter-spacing:0.25em;text-transform:uppercase;cursor:pointer;box-shadow:0 0 18px rgba(181,161,101,0.15);" onmouseover="this.style.background='rgba(181,161,101,0.32)'" onmouseout="this.style.background='rgba(181,161,101,0.22)'">&#x2726; LOG IN &#x2726;</button></p>
                        <p style="text-align:center;margin:15px 0 0 0;"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" style="color:rgba(212,188,126,0.5);font-family:'Special Elite',monospace;font-size:10px;letter-spacing:0.15em;text-transform:uppercase;text-decoration:none;" onmouseover="this.style.color='#d4bc7e'" onmouseout="this.style.color='rgba(212,188,126,0.5)'">Forgot Password?</a></p>
                    </form>
                    <button onclick="document.getElementById('fhf-login-modal').style.display='none'" style="position:absolute;top:14px;right:16px;background:none;border:none;color:rgba(212,188,126,0.5);font-size:18px;cursor:pointer;" onmouseover="this.style.color='#d4bc7e'" onmouseout="this.style.color='rgba(212,188,126,0.5)'">&#x2715;</button>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $uid  = get_current_user_id();
        $user = wp_get_current_user();

        // ── Load verification queue ──
        $option_key = 'fishotel_verification_queue_' . sanitize_title( $batch_name );
        $queue_data = get_option( $option_key, [] );

        // ── Find current user's species entries ──
        $my_species = [];
        if ( ! empty( $queue_data['species'] ) ) {
            foreach ( $queue_data['species'] as $fish_id => $sp ) {
                foreach ( $sp['queue'] as $pos => $entry ) {
                    if ( intval( $entry['user_id'] ) !== $uid ) continue;

                    // Determine display status
                    $display_status = $this->verification_display_status( $sp, $pos );

                    // Count pending ahead
                    $pending_ahead = 0;
                    for ( $i = 0; $i < $pos; $i++ ) {
                        if ( $sp['queue'][ $i ]['status'] === 'pending' ) $pending_ahead++;
                    }

                    $my_species[] = [
                        'fish_id'        => $fish_id,
                        'name'           => $sp['name'],
                        'graduated_qty'  => $sp['graduated_qty'],
                        'requested_qty'  => $entry['requested_qty'],
                        'display_status' => $display_status,
                        'pending_ahead'  => $pending_ahead,
                        'entry_status'   => $entry['status'],
                        'accepted_qty'   => $entry['accepted_qty'],
                    ];
                }
            }
        }

        // ── No fish in this batch ──
        if ( empty( $my_species ) ) {
            ?>
            <link href="<?php echo esc_url( $fonts_url ); ?>" rel="stylesheet">
            <style>
                .fhf-empty{max-width:680px;margin:40px auto;background:#f5f0e8;border:4px double #2e2418;padding:60px 40px;text-align:center;position:relative;
                    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");}
            </style>
            <div class="fhf-empty">
                <p style="font-family:'Oswald',sans-serif;color:#96885f;font-size:1.4rem;letter-spacing:0.12em;font-weight:700;margin:0 0 8px;">THE FISHOTEL</p>
                <p style="font-family:'Courier New',monospace;color:#2e2418;font-size:0.8rem;font-variant:small-caps;letter-spacing:0.15em;margin:0 0 24px;">Guest Folio</p>
                <p style="font-family:'Special Elite',monospace;color:#2e2418;font-size:0.95rem;margin:0;">No requests on file for <strong><?php echo esc_html( strtoupper( $batch_name ) ); ?></strong>.</p>
            </div>
            <?php
            return ob_get_clean();
        }

        // ── Status summary ──
        $has_waiting    = false;
        $has_action     = false;
        $all_resolved   = true;
        $all_waiting    = true;
        foreach ( $my_species as $ms ) {
            if ( $ms['display_status'] === 'waiting' ) $has_waiting = true;
            if ( in_array( $ms['display_status'], [ 'pending_decision', 'passed_to_you' ], true ) ) $has_action = true;
            if ( ! in_array( $ms['display_status'], [ 'accepted', 'passed' ], true ) ) $all_resolved = false;
            if ( $ms['display_status'] !== 'waiting' ) $all_waiting = false;
        }

        $nonce      = wp_create_nonce( 'fishotel_verification_nonce' );
        $guest_name = $user->display_name ?: $user->user_login;
        $folio_date = strtoupper( date_i18n( 'F j, Y' ) );
        $paper_bg   = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E\")";
        ?>
        <link href="<?php echo esc_url( $fonts_url ); ?>" rel="stylesheet">
        <style>
            /* ── Folio document ── */
            .fhf-wrap{max-width:680px;margin:40px auto;position:relative;color-scheme:light !important;background:#f5f0e8 !important;color:#2e2418 !important;}
            .fhf-doc{
                background:#f5f0e8 !important;background-image:<?php echo $paper_bg; ?> !important;
                border:4px double #2e2418;padding:0;position:relative;overflow:hidden;color:#2e2418 !important;
            }
            /* Coffee ring stain */
            .fhf-doc::after{
                content:'';position:absolute;bottom:60px;right:30px;width:90px;height:90px;
                border-radius:50%;border:8px solid rgba(101,67,33,0.06);
                background:radial-gradient(ellipse at center,rgba(101,67,33,0.04) 0%,transparent 70%);
                transform:rotate(15deg);pointer-events:none;z-index:1;
            }
            /* Punch holes */
            .fhf-punch{position:absolute;top:16px;width:14px;height:14px;border-radius:50%;background:#1a1a2e;border:1px solid #a09080;box-shadow:inset 0 1px 3px rgba(0,0,0,0.5);z-index:5;}
            .fhf-punch-l{left:16px;}
            .fhf-punch-r{right:16px;}
            /* Header */
            .fhf-header{padding:28px 32px 16px;position:relative;text-align:center;border-bottom:2px solid #2e2418;z-index:2;}
            .fhf-form-ref{position:absolute;top:10px;font-family:'Courier New',monospace;font-size:9px;color:#998877;letter-spacing:0.08em;}
            .fhf-form-left{left:32px;}
            .fhf-form-right{right:32px;}
            .fhf-header-crest{position:relative;text-align:center;margin-bottom:4px;}
            .fhf-crest-logo{position:absolute;width:110px;height:110px;top:50%;left:50%;transform:translate(-50%,-50%);opacity:0.10;filter:brightness(0) saturate(100%) !important;pointer-events:none;}
            .fhf-hotel-name{position:relative;z-index:1;font-family:'Oswald',sans-serif;font-weight:700;font-size:2rem !important;letter-spacing:0.35em !important;text-transform:uppercase;margin:8px 0 2px;background:linear-gradient(180deg,#c9a84c 0%,#96885f 50%,#b5965a 100%) !important;-webkit-background-clip:text !important;-webkit-text-fill-color:transparent !important;background-clip:text !important;}
            .fhf-subtitle{font-family:'Courier New',monospace;font-size:0.75rem;font-variant:small-caps;letter-spacing:0.18em;color:#2e2418;margin:0 0 12px;}
            .fhf-guest-row{display:flex;justify-content:space-between;align-items:baseline;font-family:'Courier New',monospace;font-size:0.78rem;color:#2e2418;padding:0 0 4px;flex-wrap:wrap;gap:4px 16px;}
            .fhf-guest-row span{white-space:nowrap;}
            .fhf-date{font-family:'Courier New',monospace;font-size:0.72rem;color:#665544;letter-spacing:0.08em;margin:6px 0 0;}
            /* Body */
            .fhf-body{padding:20px 32px 24px;position:relative;z-index:2;}
            /* Table */
            .fhf-table{width:100%;border-collapse:collapse;margin-bottom:20px;}
            .fhf-table th{padding:8px 6px;text-align:left;font-family:'Courier New',monospace;font-size:0.7rem;letter-spacing:0.12em;text-transform:uppercase;color:#2e2418;border-bottom:2px solid #2e2418;font-weight:700;}
            .fhf-table td{padding:10px 6px;border-bottom:1px solid #d6cfc2;vertical-align:middle;font-size:0.85rem;color:#2e2418;}
            .fhf-table tr:nth-child(even) td{background:#ede4d0;}
            .fhf-table tr:nth-child(odd) td{background:transparent;}
            .fhf-table td:nth-child(2),.fhf-table td:nth-child(3),.fhf-table th:nth-child(2),.fhf-table th:nth-child(3){text-align:center;font-family:'Courier New',monospace;}
            .fhf-species{font-family:'Special Elite',monospace;font-size:0.9rem;}
            .fhf-table td:nth-child(4),.fhf-table th:nth-child(4){text-align:right;}
            /* Status stamps */
            .fhf-stamp{display:inline-block;padding:3px 10px;font-family:'Courier New',monospace;font-size:0.7rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;white-space:nowrap;}
            .fhf-stamp-confirmed{color:#2e5e2e;border:2px solid #2e5e2e;transform:rotate(-2deg);background:rgba(46,94,46,0.06);}
            .fhf-stamp-yourturn{color:#1a3a5c;border:2px solid #1a3a5c;background:rgba(26,58,92,0.06);animation:fhf-pulse 2.5s ease-in-out infinite;}
            .fhf-stamp-waiting{color:#96885f;border:2px solid #96885f;background:rgba(150,136,95,0.06);}
            .fhf-stamp-passed{color:#999;border:2px solid #bbb;text-decoration:line-through;background:rgba(0,0,0,0.02);}
            @keyframes fhf-pulse{0%,100%{box-shadow:0 0 6px rgba(26,58,92,0.3);}50%{box-shadow:0 0 2px rgba(26,58,92,0.1);}}
            /* Action row */
            .fhf-action-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;}
            .fhf-qty-input{
                width:48px;padding:4px 4px;text-align:center;background:transparent;
                border:none;border-bottom:2px solid #2e2418;
                font-family:'Klee One',cursive;font-size:1rem;color:#2e2418;outline:none;
            }
            .fhf-btn-keep{
                font-family:'Courier New',monospace;font-weight:700;font-size:0.72rem;letter-spacing:0.08em;text-transform:uppercase;
                color:#2e5e2e;border:2px solid #2e5e2e;background:rgba(46,94,46,0.06);
                padding:5px 14px;cursor:pointer;transition:background 0.2s;
            }
            .fhf-btn-keep:hover{background:rgba(46,94,46,0.15);}
            .fhf-btn-release{
                font-family:'Courier New',monospace;font-weight:700;font-size:0.72rem;letter-spacing:0.08em;text-transform:uppercase;
                color:#8b0000;border:2px solid #8b0000;background:rgba(139,0,0,0.04);
                padding:5px 14px;cursor:pointer;transform:rotate(1deg);transition:background 0.2s;
            }
            .fhf-btn-release:hover{background:rgba(139,0,0,0.12);}
            .fhf-error{color:#8b0000;font-family:'Courier New',monospace;font-size:0.7rem;margin-top:4px;display:none;text-align:right;}
            /* Footer */
            .fhf-footer{padding:16px 32px 24px;border-top:1px solid #d6cfc2;position:relative;z-index:2;}
            .fhf-sig-line{font-family:'Courier New',monospace;font-size:0.72rem;color:#665544;margin:0 0 8px;letter-spacing:0.06em;}
            .fhf-sig-underline{display:inline-block;width:200px;border-bottom:1px solid #2e2418;margin-left:8px;}
            .fhf-respond{font-family:'Courier New',monospace;font-size:0.68rem;letter-spacing:0.06em;margin:12px 0 0;}
            .fhf-colophon{text-align:center;font-family:'Courier New',monospace;font-size:0.6rem;color:#998877;letter-spacing:0.15em;text-transform:uppercase;margin:16px 0 0;}
            /* Folio all-waiting note */
            .fhf-preparing{text-align:center;padding:24px 16px;font-family:'Special Elite',monospace;font-style:italic;font-size:0.95rem;color:#665544;}
            /* Folio settled overlay */
            .fhf-settled-stamp{
                position:absolute;bottom:200px;left:50%;transform:translateX(-50%) rotate(-12deg) skewX(-2deg);
                opacity:0.35;pointer-events:none;z-index:10;
            }
            .fhf-settled-stamp img{width:200px;height:auto;display:block;}
            /* Dark theme overrides — force cream paper aesthetic on every element */
            .fhf-wrap .fhf-doc{background:#f5f0e8 !important;color:#2e2418 !important;}
            .fhf-wrap .fhf-header,.fhf-wrap .fhf-body,.fhf-wrap .fhf-footer{background:transparent !important;color:#2e2418 !important;}
            .fhf-wrap p,.fhf-wrap span,.fhf-wrap td,.fhf-wrap th,.fhf-wrap label,.fhf-wrap div,.fhf-wrap a,.fhf-wrap strong,.fhf-wrap em,.fhf-wrap h1,.fhf-wrap h2,.fhf-wrap h3,.fhf-wrap h4{color:#2e2418 !important;}
            .fhf-wrap .fhf-header{background:#f5f0e8 !important;}
            .fhf-wrap .fhf-header *{color:#2e2418 !important;}
            .fhf-wrap .fhf-hotel-name{background:linear-gradient(180deg,#c9a84c 0%,#96885f 50%,#b5965a 100%) !important;-webkit-background-clip:text !important;-webkit-text-fill-color:transparent !important;background-clip:text !important;}
            .fhf-wrap .fhf-subtitle,.fhf-wrap .fhf-form-ref,.fhf-wrap .fhf-date,.fhf-wrap .fhf-guest-row,.fhf-wrap .fhf-guest-row span,.fhf-wrap .fhf-guest-row strong{color:#2e2418 !important;}
            .fhf-wrap .fhf-form-ref{color:#998877 !important;}
            .fhf-wrap .fhf-date{color:#665544 !important;}
            .fhf-wrap .fhf-table th{background:#d4c9a8 !important;color:#2e2418 !important;}
            .fhf-wrap .fhf-table td{background:transparent !important;color:#2e2418 !important;}
            .fhf-wrap .fhf-table tr:nth-child(even) td{background:#ede4d0 !important;}
            .fhf-wrap .fhf-table thead tr{background:#d4c9a8 !important;}
            .fhf-wrap .fhf-stamp-confirmed{color:#2e5e2e !important;border-color:#2e5e2e !important;}
            .fhf-wrap .fhf-stamp-yourturn{color:#1a3a5c !important;border-color:#1a3a5c !important;}
            .fhf-wrap .fhf-stamp-waiting{color:#96885f !important;border-color:#96885f !important;}
            .fhf-wrap .fhf-stamp-passed{color:#999 !important;border-color:#bbb !important;}
            .fhf-wrap .fhf-settled-stamp img{opacity:1 !important;}
            .fhf-wrap .fhf-sig-line,.fhf-wrap .fhf-respond{color:#2e2418 !important;}
            .fhf-wrap .fhf-colophon{color:#998877 !important;}
            .fhf-wrap .fhf-preparing{color:#665544 !important;}
            .fhf-wrap .fhf-error{color:#8b0000 !important;}
            /* Tighten spacing */
            .fhf-wrap .fhf-body{min-height:0 !important;padding-bottom:16px !important;}
            .fhf-wrap .fhf-footer{padding-top:16px !important;padding-bottom:20px !important;}
            /* Table header text */
            .fhf-wrap .fhf-table thead th{color:#1a0f00 !important;font-weight:700 !important;letter-spacing:0.12em !important;}
            /* Mobile */
            @media(max-width:600px){
                .fhf-doc{border-width:3px;}
                .fhf-header,.fhf-body,.fhf-footer{padding-left:16px;padding-right:16px;}
                .fhf-table th:nth-child(2),.fhf-table td:nth-child(2){display:none;}
                .fhf-action-row{gap:6px;justify-content:flex-start;}
                .fhf-guest-row{flex-direction:column;gap:2px;}
                .fhf-punch-l{left:10px;}.fhf-punch-r{right:10px;}.fhf-punch{width:10px;height:10px;top:12px;}
            }
        </style>
        <div class="fhf-wrap">
            <div class="fhf-doc">
                <!-- Punch holes -->
                <div class="fhf-punch fhf-punch-l"></div>
                <div class="fhf-punch fhf-punch-r"></div>

                <?php if ( $all_resolved ) : ?>
                    <div class="fhf-settled-stamp"><img src="<?php echo esc_url( plugins_url( 'assists/stamps/Charges-Confirmed.png', FISHOTEL_PLUGIN_FILE ) ); ?>" alt="Charges Confirmed"></div>
                <?php endif; ?>

                <!-- Header -->
                <div class="fhf-header">
                    <span class="fhf-form-ref fhf-form-left">FORM FH-GF-001</span>
                    <span class="fhf-form-ref fhf-form-right">COPY 1 &mdash; GUEST</span>
                    <div class="fhf-header-crest">
                        <img src="<?php echo esc_url( plugins_url( 'assists/Small-Fish-Hotel-White.png', FISHOTEL_PLUGIN_FILE ) ); ?>" class="fhf-crest-logo" alt="">
                        <h2 class="fhf-hotel-name">THE FISHOTEL</h2>
                    </div>
                    <p class="fhf-subtitle">Guest Folio</p>
                    <div class="fhf-guest-row">
                        <span>GUEST: <strong><?php echo esc_html( strtoupper( $guest_name ) ); ?></strong></span>
                        <span>BATCH: <strong><?php echo esc_html( strtoupper( $batch_name ) ); ?></strong></span>
                    </div>
                    <p class="fhf-date"><?php echo esc_html( $folio_date ); ?></p>
                </div>

                <!-- Body -->
                <div class="fhf-body">
                    <?php if ( $all_waiting ) : ?>
                        <p class="fhf-preparing">Your folio is being prepared. Check back soon.</p>
                    <?php endif; ?>

                    <table class="fhf-table">
                        <thead><tr><th>Species</th><th>Qty Available</th><th>Your Request</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ( $my_species as $ms ) : ?>
                            <tr>
                                <td class="fhf-species"><?php echo esc_html( $ms['name'] ); ?></td>
                                <td><?php echo intval( $ms['graduated_qty'] ); ?></td>
                                <td><?php echo intval( $ms['requested_qty'] ); ?></td>
                                <td class="fhv-action-cell" data-fish-id="<?php echo intval( $ms['fish_id'] ); ?>">
                                    <?php
                                    $ds = $ms['display_status'];
                                    if ( $ds === 'accepted' ) {
                                        echo '<span class="fhf-stamp fhf-stamp-confirmed">CONFIRMED</span>';
                                    } elseif ( $ds === 'pending_decision' || $ds === 'passed_to_you' ) {
                                        $badge_label = ( $ds === 'passed_to_you' ) ? 'OFFERED TO YOU' : 'YOUR TURN';
                                        $default_qty = min( $ms['requested_qty'], $ms['graduated_qty'] );
                                        ?>
                                        <div class="fhf-action-row">
                                            <span class="fhf-stamp fhf-stamp-yourturn"><?php echo $badge_label; ?></span>
                                            <input type="number" class="fhf-qty-input" value="<?php echo $default_qty; ?>" min="1" max="<?php echo intval( $ms['requested_qty'] ); ?>" data-fish="<?php echo intval( $ms['fish_id'] ); ?>">
                                            <button type="button" class="fhf-btn-keep" onclick="fhvAccept(this)">Keep It</button>
                                            <button type="button" class="fhf-btn-release" onclick="fhvPass(this)">Release</button>
                                        </div>
                                        <div class="fhf-error"></div>
                                        <?php
                                    } elseif ( $ds === 'waiting' ) {
                                        echo '<span class="fhf-stamp fhf-stamp-waiting">WAITING</span>';
                                        if ( $ms['pending_ahead'] > 0 ) {
                                            echo '<span style="font-family:\'Courier New\',monospace;font-size:0.65rem;color:#998877;margin-left:6px;">(' . intval( $ms['pending_ahead'] ) . ' ahead)</span>';
                                        }
                                    } elseif ( $ds === 'passed' ) {
                                        echo '<span class="fhf-stamp fhf-stamp-passed">PASSED</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer -->
                <div class="fhf-footer">
                    <hr style="border:none;border-top:1px solid #d6cfc2;margin:0 0 12px;">
                    <p style="font-family:'Courier New',monospace;font-variant:small-caps;font-size:11px;color:#2e2418;text-align:center;letter-spacing:0.1em;margin:0 0 14px;">Charges to Date &mdash; Enjoy the Remainder of Your Stay at The FisHotel</p>
                    <p class="fhf-sig-line">GUEST SIGNATURE <span class="fhf-sig-underline"></span></p>
                    <?php if ( $has_action ) : ?>
                        <p class="fhf-respond" style="color:#2e2418;">PLEASE RESPOND AT YOUR EARLIEST CONVENIENCE</p>
                    <?php endif; ?>
                    <p class="fhf-colophon">The FisHotel &middot; Champlin, MN &middot; Est. 2024</p>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var ajaxUrl = '<?php echo admin_url( "admin-ajax.php" ); ?>';
            var nonce   = '<?php echo $nonce; ?>';
            var batch   = '<?php echo esc_js( $batch_name ); ?>';

            window.fhvAccept = function(btn) {
                var cell    = btn.closest('.fhv-action-cell');
                var fishId  = cell.getAttribute('data-fish-id');
                var qtyEl   = cell.querySelector('.fhf-qty-input');
                var errEl   = cell.querySelector('.fhf-error');
                var qty     = parseInt(qtyEl.value, 10);
                if (!qty || qty < 1) { errEl.textContent = 'Enter a valid quantity.'; errEl.style.display = 'block'; return; }
                btn.disabled = true;
                errEl.style.display = 'none';

                var fd = new FormData();
                fd.append('action', 'fishotel_verification_accept');
                fd.append('fish_id', fishId);
                fd.append('batch_name', batch);
                fd.append('qty', qty);
                fd.append('nonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data.success) {
                            cell.innerHTML = '<span class="fhf-stamp fhf-stamp-confirmed">CONFIRMED</span>';
                        } else {
                            errEl.textContent = data.data && data.data.message ? data.data.message : 'Something went wrong.';
                            errEl.style.display = 'block';
                            btn.disabled = false;
                        }
                    })
                    .catch(function(){
                        errEl.textContent = 'Network error — please try again.';
                        errEl.style.display = 'block';
                        btn.disabled = false;
                    });
            };

            window.fhvPass = function(btn) {
                var cell   = btn.closest('.fhv-action-cell');
                var fishId = cell.getAttribute('data-fish-id');
                var errEl  = cell.querySelector('.fhf-error');
                btn.disabled = true;
                errEl.style.display = 'none';

                var fd = new FormData();
                fd.append('action', 'fishotel_verification_pass');
                fd.append('fish_id', fishId);
                fd.append('batch_name', batch);
                fd.append('nonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data.success) {
                            cell.innerHTML = '<span class="fhf-stamp fhf-stamp-passed">PASSED</span>';
                        } else {
                            errEl.textContent = data.data && data.data.message ? data.data.message : 'Something went wrong.';
                            errEl.style.display = 'block';
                            btn.disabled = false;
                        }
                    })
                    .catch(function(){
                        errEl.textContent = 'Network error — please try again.';
                        errEl.style.display = 'block';
                        btn.disabled = false;
                    });
            };
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private function verification_display_status( $species_data, $position ) {
        $queue = $species_data['queue'];
        $entry = $queue[ $position ];

        // Already passed
        if ( $entry['status'] === 'passed' ) return 'passed';

        // Already accepted — locked confirmed state
        if ( $entry['status'] === 'accepted' ) return 'accepted';

        // Check if passed_to_you — entry before them has status passed
        if ( $position > 0 && $queue[ $position - 1 ]['status'] === 'passed' && $entry['status'] === 'pending' ) {
            return 'passed_to_you';
        }

        // Check if pending_decision — first pending entry with all before accepted/passed
        if ( $entry['status'] === 'pending' ) {
            $all_before_resolved = true;
            for ( $i = 0; $i < $position; $i++ ) {
                if ( ! in_array( $queue[ $i ]['status'], [ 'accepted', 'passed' ], true ) ) {
                    $all_before_resolved = false;
                    break;
                }
            }
            if ( $all_before_resolved ) return 'pending_decision';
        }

        return 'waiting';
    }

    /* ─────────────────────────────────────────────
     *  [fishotel_notifications] shortcode
     * ───────────────────────────────────────────── */

    public function notifications_shortcode() {
        if ( ! is_user_logged_in() ) return '';

        $uid = get_current_user_id();
        $notifications = get_posts( [
            'post_type'      => 'fishotel_notification',
            'numberposts'    => 20,
            'post_status'    => 'any',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [ 'key' => '_fh_notif_user_id', 'value' => $uid, 'compare' => '=' ],
                [ 'key' => '_fh_notif_read',    'value' => '0',  'compare' => '=' ],
            ],
        ] );

        if ( empty( $notifications ) ) return '';

        $nonce = wp_create_nonce( 'fishotel_notif_nonce' );
        ob_start();
        ?>
        <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            .fhn-banner{background:#0c161f;border:1px solid #b5a165;border-radius:8px;padding:14px 18px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-family:'Oswald',sans-serif;}
            .fhn-banner-msg{color:#ddd;font-size:0.9rem;line-height:1.4;}
            .fhn-dismiss{background:none;border:none;color:#666;font-size:1.3rem;cursor:pointer;padding:0 4px;line-height:1;flex-shrink:0;}
            .fhn-dismiss:hover{color:#b5a165;}
        </style>
        <?php foreach ( $notifications as $notif ) : ?>
            <div class="fhn-banner" id="fhn-<?php echo $notif->ID; ?>">
                <div class="fhn-banner-msg"><?php echo wp_kses_post( $notif->post_content ); ?></div>
                <button type="button" class="fhn-dismiss" onclick="fhnDismiss(<?php echo $notif->ID; ?>)" title="Dismiss">&times;</button>
            </div>
        <?php endforeach; ?>
        <script>
        function fhnDismiss(id) {
            var el = document.getElementById('fhn-' + id);
            if (el) el.style.display = 'none';
            var fd = new FormData();
            fd.append('action', 'fishotel_dismiss_notification');
            fd.append('post_id', id);
            fd.append('nonce', '<?php echo $nonce; ?>');
            fetch('<?php echo admin_url( "admin-ajax.php" ); ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        }
        </script>
        <?php
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────────
     *  Stage 6: Last Call Draft Pool
     * ───────────────────────────────────────────── */

    public function render_last_call_page( $batch_name ) {
        $slug     = sanitize_title( $batch_name );
        $pool     = get_option( 'fishotel_lastcall_pool_' . $slug, [] );
        $deadline = intval( get_option( 'fishotel_lastcall_deadline_' . $slug, 0 ) );
        $results  = get_option( 'fishotel_lastcall_results_' . $slug, [] );
        $now      = time();

        $window_open  = $deadline > $now && empty( $results );
        $window_closed_no_results = $deadline <= $now && empty( $results );
        $has_results  = ! empty( $results );

        $is_logged_in = is_user_logged_in();
        $uid          = get_current_user_id();
        $wishlist     = $is_logged_in ? get_option( 'fishotel_lastcall_wishlist_' . $slug . '_' . $uid, [] ) : [];

        // Enrich pool items with price from fish_master
        foreach ( $pool as &$item ) {
            $master_id = get_post_meta( $item['fish_id'], '_master_id', true );
            $item['price'] = $master_id ? floatval( get_post_meta( $master_id, '_selling_price', true ) ) : 0;
        }
        unset( $item );

        // Build folio napkin data for logged-in users
        $folio_items   = [];
        $folio_total   = 0;
        if ( $is_logged_in ) {
            $queue_data = get_option( 'fishotel_verification_queue_' . $slug, [] );
            if ( ! empty( $queue_data['species'] ) ) {
                foreach ( $queue_data['species'] as $fish_id => $species ) {
                    if ( empty( $species['queue'] ) ) continue;
                    foreach ( $species['queue'] as $entry ) {
                        if ( intval( $entry['user_id'] ) === $uid && $entry['status'] === 'accepted' && $entry['accepted_qty'] > 0 ) {
                            $master_id = get_post_meta( $fish_id, '_master_id', true );
                            $price     = $master_id ? floatval( get_post_meta( $master_id, '_selling_price', true ) ) : 0;
                            $folio_items[] = [
                                'name'  => $species['name'],
                                'qty'   => intval( $entry['accepted_qty'] ),
                                'price' => $price,
                            ];
                            $folio_total += $price * intval( $entry['accepted_qty'] );
                        }
                    }
                }
            }
        }
        $has_folio = ! empty( $folio_items );

        $fonts_url = 'https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Special+Elite&family=Klee+One&family=Righteous&family=Dancing+Script:wght@600&family=Tulpen+One&display=swap';
        $ajax_url  = admin_url( 'admin-ajax.php' );
        $nonce     = wp_create_nonce( 'fishotel_lastcall_nonce' );
        $felt_url  = plugins_url( 'assists/casino/Felt-Table.jpg', FISHOTEL_PLUGIN_FILE );
        $card_url  = plugins_url( 'assists/casino/FisHotel-Face-Card.png', FISHOTEL_PLUGIN_FILE );
        $napkin_url = plugins_url( 'assists/casino/Blank-Napkin.png', FISHOTEL_PLUGIN_FILE );

        ob_start();
        ?>
        <link href="<?php echo esc_url( $fonts_url ); ?>" rel="stylesheet">
        <style>
            /* ── Casino table outer rail ── */
            .fhlc-table-rail{max-width:760px;margin:40px auto;border-radius:48% / 18%;padding:20px;position:relative;background:repeating-linear-gradient(0deg,rgba(0,0,0,0.06) 0px,transparent 1px,transparent 3px,rgba(0,0,0,0.04) 4px,transparent 5px,transparent 8px,rgba(0,0,0,0.07) 9px,transparent 10px,transparent 14px),repeating-linear-gradient(2deg,rgba(120,70,30,0.07) 0px,transparent 2px,transparent 6px,rgba(80,40,10,0.05) 7px,transparent 8px,transparent 13px),linear-gradient(160deg,#5c2e0e 0%,#3b1a08 40%,#2a1005 70%,#1a0a02 100%);box-shadow:0 30px 80px rgba(0,0,0,0.95),0 10px 30px rgba(0,0,0,0.8),0 0 0 4px #1a0a02;overflow:hidden;}
            /* Curved rail highlight — light catching the top edge */
            .fhlc-table-rail::before{content:'';position:absolute;inset:0;border-radius:inherit;background:linear-gradient(175deg,rgba(160,110,60,0.45) 0%,rgba(120,75,35,0.2) 15%,transparent 40%,rgba(0,0,0,0.3) 100%);pointer-events:none;z-index:1;}
            /* Inner lip shadow where rail meets felt */
            .fhlc-table-rail::after{content:'';position:absolute;inset:16px;border-radius:48% / 18%;box-shadow:inset 0 4px 16px rgba(0,0,0,0.85),inset 0 2px 6px rgba(0,0,0,0.6);pointer-events:none;z-index:2;}

            /* ── Felt surface (sunken inside rail) ── */
            .fhlc-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#e0ddd5;background:url('<?php echo esc_url( $felt_url ); ?>') center center / cover;padding:40px 32px;position:relative;border-radius:48% / 18%;overflow:hidden;}
            /* Felt vignette — darker at edges, lighter center, even lighting */
            .fhlc-wrap::before{content:'';position:absolute;inset:0;border-radius:inherit;background:radial-gradient(ellipse at 50% 48%,rgba(0,0,0,0) 0%,rgba(0,0,0,0.1) 35%,rgba(0,0,0,0.3) 65%,rgba(0,0,0,0.45) 85%,rgba(0,0,0,0.5) 100%);pointer-events:none;z-index:0;}
            .fhlc-wrap > *{position:relative;z-index:1;}
            .fhlc-wrap *{box-sizing:border-box;}

            /* ── Header ── */
            .fhlc-header{text-align:center;margin-bottom:32px;}
            .fhlc-logo{width:200px;height:auto;display:block;margin:0 auto;filter:drop-shadow(0 0 1.5px #0a1f1a) drop-shadow(0 0 1.5px #0a1f1a) drop-shadow(0 0 1.5px #0a1f1a) drop-shadow(0 0 1.5px #0a1f1a);}
            .fhlc-timer{font-family:'Righteous',cursive;color:#c8a84b;font-size:38px;text-shadow:0 0 24px rgba(200,168,75,0.4);letter-spacing:4px;margin:16px 0 0;}
            .fhlc-timer-label{color:rgba(255,255,255,0.4);letter-spacing:0.35em;font-size:10px;text-transform:uppercase;display:block;margin-bottom:4px;}

            /* ── Pool grid ── */
            .fhlc-pool-title{color:rgba(255,255,255,0.75);letter-spacing:0.4em;font-family:'Oswald',sans-serif;font-size:11px;text-transform:uppercase;margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid rgba(255,255,255,0.15);}
            .fhlc-pool{display:flex;flex-wrap:wrap;gap:20px;margin-bottom:32px;}

            /* ── Playing cards (absolute positioning) ── */
            .fhlc-card{width:155px;height:240px;flex:0 0 155px;position:relative;overflow:hidden;padding:0;background:url('<?php echo esc_url( $card_url ); ?>') center center;background-size:100% 100%;background-color:#faf8f2;border:none;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.65);transition:transform 0.25s,box-shadow 0.25s;}
            .fhlc-card:nth-child(odd){transform:rotate(-1.2deg);}
            .fhlc-card:nth-child(even){transform:rotate(1.5deg);}
            .fhlc-card:hover{transform:rotate(0deg) translateY(-6px);z-index:10;box-shadow:0 18px 50px rgba(0,0,0,0.75);}
            .fhlc-card-suit{position:absolute;top:26px;left:9px;font-size:13px;font-family:Georgia,serif;line-height:1;}
            .fhlc-card:nth-child(odd) .fhlc-card-suit{color:#111;}
            .fhlc-card:nth-child(even) .fhlc-card-suit{color:#b00;}
            .fhlc-card-name{position:absolute;top:42px;left:14px;right:14px;text-align:center;font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;line-height:1.2;margin:0;}
            .fhlc-card:nth-child(odd) .fhlc-card-name{color:#111;}
            .fhlc-card:nth-child(even) .fhlc-card-name{color:#b00;}
            .fhlc-card-row{position:absolute;left:14px;right:14px;display:flex;gap:6px;justify-content:center;font-size:11px;font-weight:700;color:#111;font-family:'Courier New',monospace;}
            .fhlc-card-row.fhlc-row-avail{top:118px;}
            .fhlc-card-row.fhlc-row-price{top:132px;}
            .fhlc-card-qty{color:#111;}
            .fhlc-card:nth-child(even) .fhlc-card-qty{color:#b00;}
            .fhlc-card-price{color:#111;}
            .fhlc-card-add{position:absolute;bottom:34px;left:26px;right:26px;padding:4px 0;font-size:9px;font-family:'Courier New',monospace;text-transform:uppercase;background:transparent;border-radius:4px;text-align:center;cursor:pointer;letter-spacing:0.06em;font-weight:600;transition:background 0.2s,color 0.2s;}
            .fhlc-card:nth-child(odd) .fhlc-card-add{color:#111;border:1px solid #111;}
            .fhlc-card:nth-child(odd) .fhlc-card-add:hover{background:#111;color:#faf8f2;}
            .fhlc-card:nth-child(even) .fhlc-card-add{color:#b00;border:1px solid #b00;}
            .fhlc-card:nth-child(even) .fhlc-card-add:hover{background:#b00;color:#fff;}
            .fhlc-card-add.fhlc-added{background:transparent;color:#bbb;border-color:#ccc;cursor:default;pointer-events:none;}
            @media (max-width:768px){
                .fhlc-table-rail{margin:20px auto;max-width:95%;}
                .fhlc-wrap{padding:32px 20px;}

            }
            @media (max-width:480px){
                .fhlc-table-rail{margin:12px auto;max-width:98%;}
                .fhlc-wrap{padding:24px 14px;}
                .fhlc-pool{gap:12px;}
                .fhlc-card{width:130px;height:201px;flex:0 0 130px;}
                .fhlc-card-suit{top:22px;left:8px;font-size:11px;}
                .fhlc-card-name{top:35px;left:12px;right:12px;font-size:10px;}
                .fhlc-card-row{left:12px;right:12px;font-size:9px;}
                .fhlc-card-row.fhlc-row-avail{top:99px;}
                .fhlc-card-row.fhlc-row-price{top:111px;}
                .fhlc-card-add{bottom:29px;left:22px;right:22px;font-size:8px;padding:3px 0;}
            }

            /* ── Wishlist (betting slip) ── */
            .fhlc-wl-center{max-width:600px;margin:0 auto;text-align:center;}
            .fhlc-wl-title{text-align:left;color:#fff;font-family:'Oswald',sans-serif;font-size:1.1rem;font-weight:600;letter-spacing:0.2em;text-transform:uppercase;text-shadow:none;margin:0 0 8px;padding:6px 12px;background:rgba(0,0,0,0.55);border-radius:4px;display:inline-block;}
            .fhlc-wl-desc{text-align:left;color:rgba(255,255,255,0.75);font-size:12px;margin:0 0 16px;padding:4px 10px;background:rgba(0,0,0,0.55);border-radius:4px;display:inline-block;}
            .fhlc-wl-list{list-style:none;margin:0;padding:10px;background:#f5f0e0;border:1px solid #bbb;border-top:3px solid #111;outline:3px solid #f5f0e0;outline-offset:-7px;border-radius:4px;box-shadow:0 4px 20px rgba(0,0,0,0.5);text-align:left;position:relative;overflow:hidden;}
            .fhlc-wl-list::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background-image:repeating-linear-gradient(to right,#999 0px,#999 4px,transparent 4px,transparent 8px);z-index:2;}
            .fhlc-wl-list::after{content:'CUSTOMER COPY';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-15deg);font-size:48px;color:rgba(200,0,0,0.03);font-weight:bold;pointer-events:none;letter-spacing:4px;z-index:0;}
            .fhlc-wl-item{display:flex;align-items:center;gap:10px;background:linear-gradient(to right,#f0ead6 0%,#f0ead6 8%,transparent 8%);border:none;border-bottom:1px solid #ddd;border-radius:0;padding:10px 8px;margin-bottom:0;cursor:grab;user-select:none;color:#1a1a1a;transition:background 0.2s;text-align:left;position:relative;z-index:1;}
            .fhlc-wl-item:last-child{border-bottom:none;}
            .fhlc-wl-item:active{cursor:grabbing;}
            .fhlc-wl-item.fhlc-dragging{opacity:0.5;}
            .fhlc-wl-item.fhlc-drag-over{background:rgba(0,0,0,0.05);}
            .fhlc-wl-handle{color:#ccc;font-size:1.1rem;flex-shrink:0;cursor:grab;}
            .fhlc-wl-rank{color:#8b0000;font-family:Georgia,serif;font-weight:900;font-size:15px;font-style:italic;width:20px;text-align:center;flex-shrink:0;filter:blur(0.3px);}
            .fhlc-wl-name{font-family:'Oswald',sans-serif;font-size:0.9rem;color:#111;flex:1;}
            .fhlc-wl-alt-toggle{background:transparent;border:1px solid #ccc;color:#888;font-style:italic;font-size:11px;font-family:Georgia,serif;cursor:pointer;padding:3px 8px;border-radius:4px;flex-shrink:0;white-space:nowrap;transition:color 0.2s,border-color 0.2s;}
            .fhlc-wl-alt-toggle:hover{color:#666;border-color:#999;}
            .fhlc-wl-alt-toggle.fhlc-alt-on{color:#b00;border-color:#b00;}
            .fhlc-wl-item.fhlc-wl-alt-item{margin-left:28px;border-left:2px solid #b00;position:relative;}
            .fhlc-wl-item.fhlc-wl-alt-item::before{content:'\21B3';position:absolute;left:-22px;top:50%;transform:translateY(-50%);color:#b00;font-size:0.9rem;}
            .fhlc-wl-remove{background:none;border:none;color:#b00;font-size:1rem;cursor:pointer;padding:0 4px;flex-shrink:0;}
            .fhlc-wl-remove:hover{color:#800;}
            .fhlc-slip-header{border-bottom:2px solid #333;padding:8px 12px;margin-bottom:4px;font-family:'Courier New',monospace;font-size:11px;line-height:1.4;color:#333;position:relative;z-index:1;}
            .fhlc-slip-col-headers{display:flex;font-size:10px;font-weight:bold;color:#666;padding:4px 8px;border-bottom:2px solid #333;font-family:'Courier New',monospace;position:relative;z-index:1;}
            .fhlc-slip-barcode{margin-top:16px;padding:12px 0;border-top:1px solid #ccc;position:relative;z-index:1;}
            .fhlc-slip-footer{font-size:8px;color:#666;text-align:center;margin-top:8px;font-family:'Courier New',monospace;line-height:1.3;position:relative;z-index:1;}
            .fhlc-slip-detach{position:absolute;top:18px;left:50%;transform:translateX(-50%);font-size:8px;color:#666;letter-spacing:1px;font-family:'Courier New',monospace;z-index:3;}

            /* ── Save button ── */
            .fhlc-save-row{margin-top:16px;width:100%;text-align:center;}
            .fhlc-save-btn{display:inline-block;margin:16px auto;background:linear-gradient(160deg,#1a3a6b,#0f2448,#162f5c);color:#f5f0e8;font-family:'Righteous',cursive;font-weight:400;font-size:0.9rem;letter-spacing:3px;text-transform:uppercase;border:2px solid #96885f;border-radius:50% / 40%;padding:12px 48px;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,0.5);transition:background 0.2s,border-color 0.2s;float:none;}
            .fhlc-save-btn:hover{background:linear-gradient(160deg,#224a85,#153060,#1d3c72);border-color:#c8a84b;}
            .fhlc-save-btn:disabled{opacity:0.5;cursor:not-allowed;}
            .fhlc-save-status{font-size:0.78rem;color:#27ae60;font-family:'Courier New',monospace;}

            /* ── States ── */
            .fhlc-closed-msg{text-align:center;font-family:'Special Elite',monospace;font-size:1rem;color:rgba(255,255,255,0.6);margin:32px 0;line-height:1.6;}
            .fhlc-login-note{text-align:center;font-family:'Special Elite',monospace;font-size:0.85rem;color:rgba(255,255,255,0.5);margin:24px 0;padding:20px;border:1px dashed rgba(255,255,255,0.2);border-radius:8px;}
            .fhlc-results-stub{text-align:center;font-family:'Special Elite',monospace;font-size:1rem;color:rgba(255,255,255,0.5);margin:32px 0;}

            /* ── Folio napkin ── */
            .fhlc-napkin{position:absolute;top:60px;left:32px;width:200px;height:260px;background:url('<?php echo esc_url( $napkin_url ); ?>') top center / cover no-repeat;z-index:2;pointer-events:none;}
            .fhlc-napkin-text{position:absolute;top:44px;left:34px;right:40px;font-family:'Dancing Script',cursive;font-weight:600;font-size:14px;line-height:20px;color:#1a3a8b;}
            .fhlc-napkin-header{margin-top:8px;font-size:15px;font-weight:700;text-decoration:underline;color:#1a3a8b;transform:rotate(-1.2deg) translateY(1px);display:block;}
            .fhlc-napkin .fhlc-napkin-divider{border:none !important;border-top:1px solid rgba(26,58,139,0.25) !important;margin:6px 0 !important;padding:0 !important;height:0 !important;}
            .fhlc-napkin-total{text-align:right;color:#1a3a8b;font-weight:700;font-size:15px;transform:rotate(0.8deg) translateY(-1px);display:block;margin-top:0;}
            .fhlc-napkin-text > div:nth-child(2){transform:rotate(-1.4deg) translateY(-1px);}
            .fhlc-napkin-text > div:nth-child(3){transform:rotate(0.9deg) translateY(2px);}
            .fhlc-napkin-text > div:nth-child(4){transform:rotate(-0.6deg) translateY(-1.5px);}
            .fhlc-napkin-text > div:nth-child(5){transform:rotate(1.2deg) translateY(0.5px);}
            .fhlc-napkin-text > div:nth-child(6){transform:rotate(-0.3deg) translateY(1.5px);}

        </style>

        <div class="fhlc-table-rail">
        <div class="fhlc-wrap">
            <?php echo fh_generate_chip_scatter( $batch_name, $uid ); ?>

            <?php if ( $is_logged_in && $has_folio ) :
                $napkin_seed = abs( crc32( $batch_name ) );
                $napkin_rot  = ( $napkin_seed % 25 ) - 12;
            ?>
            <!-- Folio napkin -->
            <div class="fhlc-napkin" style="--napkin-rot:<?php echo $napkin_rot; ?>deg;transform:rotate(var(--napkin-rot));">
                <div class="fhlc-napkin-text">
                    <div class="fhlc-napkin-header">My Current Fish List</div>
                    <?php
                    $folio_visible = array_slice( $folio_items, 0, 5 );
                    $folio_extra   = count( $folio_items ) - 5;
                    foreach ( $folio_visible as $fi ) : ?>
                        <div>&#x2713; <?php echo esc_html( $fi['name'] ); ?> &times;<?php echo $fi['qty']; ?></div>
                    <?php endforeach; ?>
                    <?php if ( $folio_extra > 0 ) : ?>
                        <div style="font-size:11px;opacity:0.7;margin-top:2px;">&hellip;and <?php echo $folio_extra; ?> more</div>
                    <?php endif; ?>
                    <hr class="fhlc-napkin-divider">
                    <?php if ( $folio_total > 0 ) : ?>
                        <div class="fhlc-napkin-total">Committed: $<?php echo number_format( $folio_total, 2 ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="fhlc-header">
                <img src="<?php echo esc_url( plugins_url( 'assists/casino/FisHotel-Casino.png', FISHOTEL_PLUGIN_FILE ) ); ?>" alt="FisHotel Casino" class="fhlc-logo">
                <?php if ( $window_open ) : ?>
                    <div class="fhlc-timer">
                        <span class="fhlc-timer-label">Wishlist closes in</span>
                        <span id="fhlc-countdown" data-deadline="<?php echo esc_attr( $deadline ); ?>">--:--:--</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pool Section (hidden when draft results exist) -->
            <?php if ( ! $has_results ) : ?>
            <?php
            $drafted_fish_ids = [];
            $visible_pool = $pool;
            ?>
            <?php if ( ! empty( $visible_pool ) ) : ?>
            <h3 class="fhlc-pool-title" style="padding-left:250px;">The Pool</h3>
            <div class="fhlc-pool">
                <?php foreach ( $visible_pool as $ci => $item ) : ?>
                <div class="fhlc-card" data-fish-id="<?php echo esc_attr( $item['fish_id'] ); ?>">
                    <span class="fhlc-card-suit"><?php echo ( $ci % 2 === 0 ) ? '&#9824;' : '&#9829;'; ?></span>
                    <p class="fhlc-card-name"><?php echo esc_html( $item['name'] ); ?></p>
                    <div class="fhlc-card-row fhlc-row-avail">
                        <span>Available</span>
                        <span class="fhlc-card-qty"><?php echo intval( $item['pool_qty'] ); ?></span>
                    </div>
                    <?php if ( $item['price'] > 0 ) : ?>
                    <div class="fhlc-card-row fhlc-row-price">
                        <span>Price</span>
                        <span class="fhlc-card-price">$<?php echo number_format( $item['price'], 2 ); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $window_open && $is_logged_in ) : ?>
                    <button type="button" class="fhlc-card-add" data-fish-id="<?php echo esc_attr( $item['fish_id'] ); ?>" data-fish-name="<?php echo esc_attr( $item['name'] ); ?>">+ Request</button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else : ?>
            <p class="fhlc-closed-msg">No fish available in the pool.</p>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ( $has_results ) : ?>
                <!-- State 3: Draft Card Reveal -->
                <?php
                $seen_reveal = $is_logged_in ? get_user_meta( $uid, 'fishotel_lastcall_seen_reveal_' . $slug, true ) : true;
                $start_live  = ! $seen_reveal;
                $sounds_url  = plugins_url( 'assists/casino/sounds/', FISHOTEL_PLUGIN_FILE );

                // Seeded card back selection (consistent per batch)
                $cardback_files = [ 'White-Seahorse-Cardback.jpg', 'Royal-Cardback-Fish.jpg', 'Royal-Cardback-Seahorse.jpg' ];
                $cardback_idx   = abs( crc32( $batch_name . 'cardback' ) ) % 3;
                $card_back_url  = plugins_url( 'assists/casino/' . $cardback_files[ $cardback_idx ], FISHOTEL_PLUGIN_FILE );

                wp_enqueue_script(
                    'fhlc-draft-reveal',
                    plugins_url( 'assists/casino/draft-reveal.js', FISHOTEL_PLUGIN_FILE ),
                    [],
                    FISHOTEL_VERSION,
                    true
                );
                wp_localize_script( 'fhlc-draft-reveal', 'fhlcDraftData', [
                    'ajaxUrl'    => $ajax_url,
                    'nonce'      => $nonce,
                    'batchName'  => $batch_name,
                    'myUid'      => $uid,
                    'startLive'  => $start_live,
                    'cardBack'   => $card_back_url,
                    'cardFace'   => $card_url,
                    'soundsUrl'  => $sounds_url,
                ] );
                ?>
                <style>
                /* ── Card reveal controls ── */
                .fhlc-reveal-controls{display:flex;justify-content:flex-end;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;}
                .fhlc-speed{display:flex;gap:4px;}
                .fhlc-speed button{background:#222;border:1px solid #444;color:#888;font-size:0.7rem;padding:4px 10px;border-radius:4px;cursor:pointer;font-family:'Courier New',monospace;transition:border-color 0.2s,color 0.2s;}
                .fhlc-speed button.fhlc-speed-active{border-color:#c9a84c;color:#c9a84c;}
                .fhlc-skip{background:none;border:1px solid #444;color:#888;font-size:0.72rem;padding:4px 14px;border-radius:4px;cursor:pointer;font-family:'Courier New',monospace;}
                .fhlc-skip:hover{color:#c9a84c;border-color:#c9a84c;}

                /* ── Desktop card stage ── */
                .fhlc-card-stage{perspective:1200px;min-height:280px;margin-bottom:16px;}
                .fhlc-round-label{font-family:'Oswald',sans-serif;font-size:0.8rem;color:#c9a84c;text-transform:uppercase;letter-spacing:0.2em;text-align:right;margin:24px 0 10px;padding-bottom:6px;border-bottom:1px solid rgba(201,168,76,0.3);}
                .fhlc-reveal-divider{border:none;border-top:1px solid rgba(201,168,76,0.25);margin:24px 0 8px;}
                .fhlc-card-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;}
                .fhlc-deal-card{width:100%;aspect-ratio:675/1044;position:relative;transform-style:preserve-3d;cursor:default;}
                .fhlc-deal-card.fhlc-entering{animation:fhlcDealIn 0.35s ease-out forwards;}
                .fhlc-deal-card.fhlc-flipping .fhlc-card-inner{transform:rotateY(180deg);}
                .fhlc-deal-card.fhlc-mine{border-radius:8px;}
                .fhlc-deal-card.fhlc-mine .fhlc-card-front::after{content:'★ YOURS';position:absolute;top:-1px;right:-24px;background:linear-gradient(135deg,#1a3a6b,#0f2448);color:rgba(255,255,255,0.9);font-family:'Oswald',sans-serif;font-size:clamp(5px,0.7vw,7px);font-weight:700;letter-spacing:0.1em;padding:1px 26px;transform:rotate(45deg);z-index:5;box-shadow:0 1px 4px rgba(0,0,0,0.4);}
                .fhlc-deal-card.fhlc-dimmed{opacity:0.3;transition:opacity 0.4s;}
                .fhlc-card-inner{position:relative;width:100%;height:100%;transition:transform 0.6s ease-in-out;transform-style:preserve-3d;}
                .fhlc-card-front,.fhlc-card-back{position:absolute;inset:0;backface-visibility:hidden;border-radius:8px;overflow:hidden;}
                .fhlc-card-back{background-size:cover;background-position:center;box-shadow:0 6px 24px rgba(0,0,0,0.5);}
                .fhlc-card-front{transform:rotateY(180deg);background-color:#faf8f2;background-size:100% 100%;background-repeat:no-repeat;box-shadow:0 6px 24px rgba(0,0,0,0.5);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10% 16%;text-align:center;}
                .fhlc-card-front .fhlc-cf-round{font-family:'Courier New',monospace;font-size:clamp(8px,1.2vw,11px);color:#888;text-transform:uppercase;letter-spacing:0.1em;margin-top:8%;text-align:center;}
                .fhlc-card-front .fhlc-cf-fish{font-family:'Tulpen One','Oswald',sans-serif;font-size:clamp(12px,2vw,22px);font-weight:700;color:#1a1a1a;margin:4% 0;line-height:1.1;width:100%;overflow:hidden;word-break:break-word;text-transform:uppercase;}
                .fhlc-card-front .fhlc-cf-customer{font-family:'Special Elite',cursive;font-size:clamp(8px,1.2vw,12px);color:#96885f;width:100%;overflow:hidden;word-break:break-word;margin-bottom:2%;}
                .fhlc-card-front .fhlc-cf-qty{font-family:'Courier New',monospace;font-size:clamp(8px,1.1vw,10px);color:#666;margin-top:0;}
                .fhlc-card-front .fhlc-cf-suit{position:absolute;top:4%;left:5%;font-size:clamp(10px,1.4vw,16px);}
                .fhlc-card-front .fhlc-cf-suit-br{position:absolute;bottom:4%;right:5%;font-size:clamp(10px,1.4vw,16px);transform:rotate(180deg);}
                @keyframes fhlcDealIn{from{opacity:0;transform:translateY(-40px) scale(0.85);}to{opacity:1;transform:translateY(0) scale(1);}}

                /* ── Mobile table (hidden on desktop) ── */
                .fhlc-mobile-table{display:none;}
                .fhlc-mobile-table table{width:100%;border-collapse:collapse;background:transparent!important;border:none!important;margin:0!important;}
                .fhlc-mobile-table th{font-family:'Oswald',sans-serif;font-size:0.7rem;color:rgba(201,168,76,0.6);text-transform:uppercase;letter-spacing:0.15em;padding:8px 10px;text-align:left;border-bottom:1px solid rgba(201,168,76,0.25);background:transparent!important;border-top:none!important;}
                .fhlc-mobile-table td{padding:8px 10px;font-family:'Special Elite',cursive;font-size:0.8rem;border-bottom:1px solid rgba(255,255,255,0.06);color:rgba(255,255,255,0.55);background:transparent!important;}
                .fhlc-mobile-table tr{background:transparent!important;}
                .fhlc-mobile-table tr:nth-child(even){background:rgba(255,255,255,0.02)!important;}
                .fhlc-mobile-table .fhlc-row-mine td{color:#c9a84c;font-weight:600;}
                .fhlc-mobile-table .fhlc-row-entering td{animation:fhlcRowPulse 0.5s ease-out;}
                @keyframes fhlcRowPulse{0%{background:rgba(201,168,76,0.15)!important;}100%{background:transparent!important;}}

                /* ── Post-reveal controls ── */
                #fhlc-reveal-wrap{padding-bottom:70px;}
                .fhlc-post-controls{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;justify-content:center;}
                .fhlc-post-controls button{background:transparent;border:1px solid rgba(201,168,76,0.5);color:#c9a84c;font-family:'Oswald',sans-serif;font-size:0.75rem;font-weight:400;letter-spacing:0.2em;text-transform:uppercase;padding:8px 22px;border-radius:2px;cursor:pointer;transition:background 0.2s,border-color 0.2s,color 0.2s;}
                .fhlc-post-controls button:hover{background:rgba(201,168,76,0.1);border-color:#c9a84c;color:#e8c96a;}
                .fhlc-post-controls button.fhlc-filter-active{background:rgba(201,168,76,0.15);border-color:#c9a84c;color:#e8c96a;}

                /* ── Full results dropdown ── */
                .fhlc-full-results{margin-top:16px;border-top:1px solid rgba(201,168,76,0.25);padding-top:16px;}
                .fhlc-full-results table{width:100%;border-collapse:collapse;background:transparent!important;border:none!important;}
                .fhlc-full-results th{font-family:'Oswald',sans-serif;font-size:0.7rem;color:rgba(201,168,76,0.6);text-transform:uppercase;letter-spacing:0.15em;padding:8px 10px;text-align:left;border-bottom:1px solid rgba(201,168,76,0.25);background:transparent!important;border-top:none!important;}
                .fhlc-full-results td{padding:8px 10px;font-family:'Special Elite',cursive;font-size:0.8rem;border-bottom:1px solid rgba(255,255,255,0.06);color:rgba(255,255,255,0.55);background:transparent!important;}
                .fhlc-full-results tr{background:transparent!important;}
                .fhlc-full-results .fhlc-row-mine td{color:#c9a84c;font-weight:600;}
                .fhlc-full-results .fhlc-round-hdr td{padding:10px 8px 4px;color:rgba(201,168,76,0.5);font-family:'Oswald',sans-serif;font-size:0.7rem;letter-spacing:0.15em;border-bottom:1px solid rgba(201,168,76,0.2);font-weight:600;background:transparent!important;}

                /* ── Responsive ── */
                @media(max-width:768px){
                    .fhlc-card-stage{display:none!important;}
                    .fhlc-mobile-table{display:block!important;}
                }
                @media(min-width:769px){
                    .fhlc-mobile-table{display:none!important;}
                    .fhlc-card-stage{display:block!important;}
                }
                @media(max-width:600px){
                    .fhlc-card-grid{grid-template-columns:repeat(3,1fr);gap:10px;}
                }
                @media(prefers-reduced-motion:reduce){
                    .fhlc-deal-card.fhlc-entering{animation:none!important;opacity:1;transform:none;}
                    .fhlc-card-inner{transition:none!important;}
                    .fhlc-mobile-table .fhlc-row-entering td{animation:none!important;}
                }
                </style>

                <!-- Card reveal container -->
                <div id="fhlc-reveal-wrap">
                    <div class="fhlc-reveal-controls" id="fhlc-reveal-controls">
                        <div class="fhlc-speed">
                            <button data-speed="3.5" class="fhlc-speed-btn">Slow</button>
                            <button data-speed="2" class="fhlc-speed-btn fhlc-speed-active">Normal</button>
                            <button data-speed="0.8" class="fhlc-speed-btn">Fast</button>
                        </div>
                        <button class="fhlc-skip" id="fhlc-skip">Skip to results &raquo;</button>
                    </div>

                    <!-- Desktop: card grid -->
                    <div class="fhlc-card-stage" id="fhlc-card-stage"></div>

                    <!-- Mobile: synced table -->
                    <div class="fhlc-mobile-table" id="fhlc-mobile-table">
                        <table>
                            <thead><tr><th>Rd</th><th>Customer</th><th>Fish</th><th>Qty</th></tr></thead>
                            <tbody id="fhlc-mobile-tbody"></tbody>
                        </table>
                    </div>

                    <!-- Post-reveal controls -->
                    <div class="fhlc-post-controls" id="fhlc-post-controls" style="display:none;">
                        <button class="fhlc-filter-btn" id="fhlc-filter-mine">Your Fish</button>
                        <button id="fhlc-view-all">View Full Results</button>
                        <button id="fhlc-replay-btn">Replay</button>
                    </div>

                    <!-- Full results table -->
                    <div class="fhlc-full-results" id="fhlc-full-results" style="display:none;"></div>
                </div>

            <?php elseif ( $window_closed_no_results ) : ?>
                <!-- State 2: Window closed, no results yet -->
                <div class="fhlc-closed-msg">
                    <p>The bar is closed.<br>The draft will begin shortly.</p>
                </div>

            <?php elseif ( $window_open ) : ?>
                <!-- State 1: Wishlist open -->
                <?php if ( $is_logged_in ) : ?>
                <div class="fhlc-wl-center">
                    <h3 class="fhlc-wl-title">Your Wishlist</h3>
                    <p class="fhlc-wl-desc">Click a fish above to add it. Drag to reorder. The draft awards one fish per round in wishlist order.</p>

                    <div style="position:relative;padding-top:20px;">
                        <span class="fhlc-slip-detach">DO NOT DETACH</span>
                        <ul class="fhlc-wl-list" id="fhlc-wishlist">
                            <li class="fhlc-slip-header" style="list-style:none;">
                                <div style="font-weight:bold;font-size:13px;">THE FISHOTEL CASINO</div>
                                <div style="display:flex;justify-content:space-between;margin-top:4px;">
                                    <span>BETTING SLIP #<?php echo substr( md5( $batch_name . $uid ), 0, 6 ); ?></span>
                                    <span><?php echo date( 'M d, Y' ); ?> &middot; <?php echo date( 'H:i' ); ?></span>
                                </div>
                            </li>
                            <li style="list-style:none;" class="fhlc-slip-col-headers">
                                <span style="width:40px;">RANK</span>
                                <span style="flex:1;margin-left:12px;">SELECTION</span>
                                <span style="width:140px;text-align:center;">ALTERNATE</span>
                            </li>
                            <?php
                            if ( ! empty( $wishlist ) ) {
                                foreach ( $wishlist as $i => $wl_item ) {
                                    $name = '';
                                    foreach ( $pool as $p ) {
                                        if ( intval( $p['fish_id'] ) === intval( $wl_item['fish_id'] ) ) { $name = $p['name']; break; }
                                    }
                                    if ( ! $name ) continue;
                                    $is_alt   = ! empty( $wl_item['is_alternative_to'] );
                                    $is_first = $i === 0;
                                    $above_rank = $i;
                                    ?>
                                    <li class="fhlc-wl-item<?php echo $is_alt ? ' fhlc-wl-alt-item' : ''; ?>" draggable="true" data-fish-id="<?php echo esc_attr( $wl_item['fish_id'] ); ?>">
                                        <span class="fhlc-wl-handle">&#x2630;</span>
                                        <span class="fhlc-wl-rank"><?php echo $i + 1; ?></span>
                                        <span class="fhlc-wl-name"><?php echo esc_html( $name ); ?></span>
                                        <?php if ( ! $is_first ) : ?>
                                        <button type="button" class="fhlc-wl-alt-toggle<?php echo $is_alt ? ' fhlc-alt-on' : ''; ?>">Only if I don&rsquo;t get #<?php echo $above_rank; ?></button>
                                        <?php endif; ?>
                                        <button type="button" class="fhlc-wl-remove" title="Remove">&times;</button>
                                    </li>
                                    <?php
                                }
                            }
                            ?>
                            <li style="list-style:none;" class="fhlc-slip-barcode">
                                <div style="display:flex;justify-content:center;gap:2px;height:40px;">
                                    <?php
                                    $barcode_seed = abs( crc32( $batch_name . $uid ) );
                                    for ( $bc = 0; $bc < 40; $bc++ ) {
                                        $bw = ( ( $barcode_seed >> ( $bc % 30 ) ) % 3 ) + 1;
                                        echo '<div style="width:' . $bw . 'px;background:#000;height:100%;"></div>';
                                    }
                                    ?>
                                </div>
                            </li>
                            <li style="list-style:none;" class="fhlc-slip-footer">
                                ALL WAGERS FINAL UPON SUBMISSION &middot; MANAGEMENT RESERVES ALL RIGHTS<br>
                                TICKET VALIDATION: <?php echo strtoupper( substr( md5( $batch_name ), 0, 12 ) ); ?>
                            </li>
                        </ul>
                    </div>

                    <div class="fhlc-save-row">
                        <button type="button" class="fhlc-save-btn" id="fhlc-save-btn">Save Wishlist</button>
                        <div class="fhlc-save-status" id="fhlc-save-status" style="display:block;margin-top:8px;"><?php echo ! empty( $wishlist ) ? '&#x2713; Saved' : ''; ?></div>
                    </div>
                </div><!-- /.fhlc-wl-center -->

                <?php else : ?>
                    <div class="fhlc-login-note">
                        <p>Log in to submit your wishlist.</p>
                        <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="color:#c9a84c;text-decoration:underline;">Log In</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        </div><!-- /.fhlc-table-rail -->

        <?php if ( $window_open ) : ?>
        <script>
        (function(){
            /* ── Countdown ── */
            var cdEl = document.getElementById('fhlc-countdown');
            if(cdEl){
                var dl = parseInt(cdEl.dataset.deadline,10)*1000;
                function tick(){
                    var diff = dl - Date.now();
                    if(diff<=0){cdEl.textContent='CLOSED';return;}
                    var h=Math.floor(diff/3600000),m=Math.floor((diff%3600000)/60000),s=Math.floor((diff%60000)/1000);
                    cdEl.textContent=(h<10?'0':'')+h+':'+(m<10?'0':'')+m+':'+(s<10?'0':'')+s;
                    setTimeout(tick,1000);
                }
                tick();
            }

            <?php if ( $is_logged_in ) : ?>
            /* ── Pool data for JS ── */
            var poolData = <?php echo wp_json_encode( array_map( function( $p ) {
                return [ 'fish_id' => $p['fish_id'], 'name' => $p['name'], 'pool_qty' => $p['pool_qty'] ];
            }, $pool ) ); ?>;
            var poolMap = {};
            poolData.forEach(function(p){ poolMap[p.fish_id] = p; });

            var list = document.getElementById('fhlc-wishlist');
            var saveBtn = document.getElementById('fhlc-save-btn');
            var saveStatus = document.getElementById('fhlc-save-status');

            function escHtml(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

            /* ── Empty-state for wishlist list ── */
            function toggleWlEmpty(){
                var hasItems = list.querySelectorAll('.fhlc-wl-item').length > 0;
                if(!hasItems){
                    list.style.background='transparent';list.style.border='none';list.style.height='0';list.style.padding='0';list.style.margin='0';list.style.outline='none';list.style.boxShadow='none';
                } else {
                    list.style.background='';list.style.border='';list.style.height='';list.style.padding='';list.style.margin='';list.style.outline='';list.style.boxShadow='';
                }
            }
            toggleWlEmpty();

            /* ── Pool card click → add to wishlist ── */
            document.querySelectorAll('.fhlc-card-add').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var fid = btn.dataset.fishId;
                    var fname = btn.dataset.fishName;
                    /* Skip if already in wishlist */
                    var existing = list.querySelectorAll('.fhlc-wl-item');
                    for(var i=0;i<existing.length;i++){
                        if(existing[i].dataset.fishId == fid) return;
                    }
                    var li = createWlItem(fid, fname, false);
                    list.appendChild(li);
                    renumber();
                    markPoolButtons();
                    toggleWlEmpty();
                    saveStatus.textContent = '';
                });
            });

            function markPoolButtons(){
                var inList = {};
                list.querySelectorAll('.fhlc-wl-item').forEach(function(li){ inList[li.dataset.fishId] = true; });
                document.querySelectorAll('.fhlc-card-add').forEach(function(btn){
                    if(inList[btn.dataset.fishId]){
                        btn.classList.add('fhlc-added');
                        btn.textContent = '\u2713 Added';
                    } else {
                        btn.classList.remove('fhlc-added');
                        btn.textContent = '+ Request';
                    }
                });
            }
            markPoolButtons();

            function createWlItem(fishId, name, isAlt){
                var li = document.createElement('li');
                li.className = 'fhlc-wl-item' + (isAlt ? ' fhlc-wl-alt-item' : '');
                li.draggable = true;
                li.dataset.fishId = fishId;
                li.innerHTML =
                    '<span class="fhlc-wl-handle">&#x2630;</span>' +
                    '<span class="fhlc-wl-rank">?</span>' +
                    '<span class="fhlc-wl-name">' + escHtml(name) + '</span>' +
                    '<button type="button" class="fhlc-wl-alt-toggle">Only if I don\u2019t get #?</button>' +
                    '<button type="button" class="fhlc-wl-remove" title="Remove">&times;</button>';
                bindItem(li);
                return li;
            }

            /* ── Remove ── */
            list.addEventListener('click', function(e){
                if(e.target.classList.contains('fhlc-wl-remove')){
                    e.target.closest('.fhlc-wl-item').remove();
                    renumber();
                    markPoolButtons();
                    toggleWlEmpty();
                    saveStatus.textContent = '';
                }
                if(e.target.classList.contains('fhlc-wl-alt-toggle')){
                    e.target.classList.toggle('fhlc-alt-on');
                    var item = e.target.closest('.fhlc-wl-item');
                    item.classList.toggle('fhlc-wl-alt-item', e.target.classList.contains('fhlc-alt-on'));
                    saveStatus.textContent = '';
                }
            });

            /* ── Drag and drop ── */
            var dragEl = null;
            function bindItem(li){
                li.addEventListener('dragstart', function(e){
                    dragEl = li;
                    li.classList.add('fhlc-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });
                li.addEventListener('dragend', function(){
                    li.classList.remove('fhlc-dragging');
                    var items = list.querySelectorAll('.fhlc-wl-item');
                    items.forEach(function(it){ it.classList.remove('fhlc-drag-over'); });
                    dragEl = null;
                    renumber();
                    saveStatus.textContent = '';
                });
                li.addEventListener('dragover', function(e){
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    li.classList.add('fhlc-drag-over');
                });
                li.addEventListener('dragleave', function(){
                    li.classList.remove('fhlc-drag-over');
                });
                li.addEventListener('drop', function(e){
                    e.preventDefault();
                    li.classList.remove('fhlc-drag-over');
                    if(dragEl && dragEl !== li){
                        var items = Array.from(list.children);
                        var fromIdx = items.indexOf(dragEl);
                        var toIdx = items.indexOf(li);
                        if(fromIdx < toIdx) li.after(dragEl);
                        else li.before(dragEl);
                    }
                });
            }
            /* Bind existing items */
            list.querySelectorAll('.fhlc-wl-item').forEach(bindItem);

            function renumber(){
                var items = list.querySelectorAll('.fhlc-wl-item');
                items.forEach(function(li, i){
                    li.querySelector('.fhlc-wl-rank').textContent = i + 1;
                    var altBtn = li.querySelector('.fhlc-wl-alt-toggle');
                    if(i === 0){
                        /* First item can never be an alt */
                        if(altBtn){ altBtn.style.display = 'none'; }
                        li.classList.remove('fhlc-wl-alt-item');
                    } else {
                        if(altBtn){
                            altBtn.style.display = '';
                            altBtn.textContent = 'Only if I don\u2019t get #' + i;
                        }
                    }
                });
            }
            renumber();

            /* ── Save ── */
            saveBtn.addEventListener('click', function(){
                saveBtn.disabled = true;
                saveStatus.textContent = 'Saving...';
                var items = list.querySelectorAll('.fhlc-wl-item');
                var wl = [];
                var prevFishId = null;
                items.forEach(function(li, i){
                    var fishId = li.dataset.fishId;
                    var altBtn = li.querySelector('.fhlc-wl-alt-toggle');
                    var isAlt = altBtn && altBtn.classList.contains('fhlc-alt-on') && i > 0;
                    wl.push({ fish_id: parseInt(fishId,10), rank: i+1, is_alternative_to: isAlt && prevFishId ? parseInt(prevFishId,10) : null });
                    prevFishId = fishId;
                });
                var fd = new FormData();
                fd.append('action', 'fishotel_save_lastcall_wishlist');
                fd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
                fd.append('batch_name', '<?php echo esc_js( $batch_name ); ?>');
                fd.append('wishlist', JSON.stringify(wl));
                fetch('<?php echo esc_url( $ajax_url ); ?>', { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        saveBtn.disabled = false;
                        saveStatus.textContent = d.success ? '\u2713 Saved' : 'Error: ' + (d.data && d.data.message || 'Unknown');
                    })
                    .catch(function(){
                        saveBtn.disabled = false;
                        saveStatus.textContent = 'Network error';
                    });
            });
            <?php endif; ?>
        })();
        </script>
        <?php endif; ?>

        <?php if ( $is_logged_in && $has_folio ) : ?>
        <script>
        (function(){
            var nt = document.querySelector('.fhlc-napkin-text');
            if(!nt) return;
            var divs = nt.querySelectorAll('div');
            var maxLen = 0;
            divs.forEach(function(d){ var t = d.textContent.trim(); if(t.length > maxLen) maxLen = t.length; });
            if(maxLen > 22) nt.style.fontSize = '10px';
            else if(maxLen > 16) nt.style.fontSize = '12px';
        })();
        </script>
        <?php endif; ?>

        <script>
        (function(){
            var napkin = document.querySelector('.fhlc-napkin');
            if (!napkin) return;
            function scaleNapkin(){
                var vw = window.innerWidth;
                var scale = 1.0;
                if (vw <= 900) {
                    scale = 0.55 + ((vw - 390) / (900 - 390)) * 0.45;
                    scale = Math.max(0.55, Math.min(1.0, scale));
                }
                napkin.style.transformOrigin = 'left top';
                napkin.style.transform = 'rotate(var(--napkin-rot)) scale(' + scale + ')';
            }
            window.addEventListener('resize', scaleNapkin);
            scaleNapkin();
        })();
        </script>

        <?php
        return ob_get_clean();
    }

}
