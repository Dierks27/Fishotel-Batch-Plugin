<?php
/**
 * FisHotel Casino — Full casino experience with 4 games, wallet, leaderboard.
 * Activated during the "casino" batch stage for 4 days of entertainment.
 *
 * Shortcodes:
 *   [fishotel_casino]            — Main casino floor (dollhouse cutaway)
 *   [fishotel_casino_wallet]     — Chip balance widget
 *   [fishotel_casino_leaderboard] — Top players board
 *
 * @since 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Casino {

    /* ─── Constants ─────────────────────────────────────────── */
    const DAILY_CHIPS       = 1000;
    const META_CHIPS        = '_fishotel_casino_chips';
    const META_STATS        = '_fishotel_casino_stats';
    const META_DAILY        = '_fishotel_casino_last_daily';
    const META_LEADERBOARD  = '_fishotel_casino_total_winnings';

    /* ─── Boot ──────────────────────────────────────────────── */
    public function __construct() {
        /* Shortcodes */
        add_shortcode( 'fishotel_casino',             [ $this, 'casino_floor_shortcode' ] );
        add_shortcode( 'fishotel_casino_wallet',      [ $this, 'wallet_widget_shortcode' ] );
        add_shortcode( 'fishotel_casino_leaderboard', [ $this, 'leaderboard_shortcode' ] );

        /* AJAX — logged-in users only */
        $actions = [
            'fishotel_casino_get_chips',
            'fishotel_casino_claim_daily',
            'fishotel_casino_roulette_spin',
            'fishotel_casino_blackjack_action',
            'fishotel_casino_slots_spin',
            'fishotel_casino_poker_action',
            'fishotel_casino_leaderboard_data',
        ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_{$a}", [ $this, $a ] );
        }
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 1 — WALLET (Casino Chips)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    private function get_chips( $user_id ) {
        return (int) get_user_meta( $user_id, self::META_CHIPS, true );
    }

    private function set_chips( $user_id, $amount ) {
        $amount = max( 0, (int) $amount );
        update_user_meta( $user_id, self::META_CHIPS, $amount );
        return $amount;
    }

    private function add_chips( $user_id, $amount ) {
        $current = $this->get_chips( $user_id );
        return $this->set_chips( $user_id, $current + (int) $amount );
    }

    private function deduct_chips( $user_id, $amount ) {
        $current = $this->get_chips( $user_id );
        $amount  = min( (int) $amount, $current ); // never go negative
        return $this->set_chips( $user_id, $current - $amount );
    }

    /** Award daily chip allotment (once per calendar day). */
    private function maybe_claim_daily( $user_id ) {
        $last  = get_user_meta( $user_id, self::META_DAILY, true );
        $today = current_time( 'Y-m-d' );
        if ( $last === $today ) {
            return false; // already claimed
        }
        update_user_meta( $user_id, self::META_DAILY, $today );
        $this->add_chips( $user_id, self::DAILY_CHIPS );
        $this->track_stat( $user_id, 'days_played', 1 );
        return true;
    }

    /* ─── Stats helpers ─────────────────────────────────────── */

    private function get_stats( $user_id ) {
        $stats = get_user_meta( $user_id, self::META_STATS, true );
        return is_array( $stats ) ? $stats : [
            'total_wagered'   => 0,
            'total_won'       => 0,
            'total_lost'      => 0,
            'biggest_win'     => 0,
            'games_played'    => 0,
            'days_played'     => 0,
            'roulette_spins'  => 0,
            'blackjack_hands' => 0,
            'slots_spins'     => 0,
            'poker_hands'     => 0,
        ];
    }

    private function track_stat( $user_id, $key, $add = 1 ) {
        $stats = $this->get_stats( $user_id );
        $stats[ $key ] = ( $stats[ $key ] ?? 0 ) + $add;
        update_user_meta( $user_id, self::META_STATS, $stats );
    }

    private function record_game( $user_id, $game, $wager, $payout ) {
        $net = $payout - $wager;
        $this->track_stat( $user_id, 'total_wagered', $wager );
        $this->track_stat( $user_id, 'games_played', 1 );

        if ( $net > 0 ) {
            $this->track_stat( $user_id, 'total_won', $net );
            $stats = $this->get_stats( $user_id );
            if ( $net > ( $stats['biggest_win'] ?? 0 ) ) {
                $stats['biggest_win'] = $net;
                update_user_meta( $user_id, self::META_STATS, $stats );
            }
            // Leaderboard: cumulative winnings
            $total = (int) get_user_meta( $user_id, self::META_LEADERBOARD, true );
            update_user_meta( $user_id, self::META_LEADERBOARD, $total + $net );
        } else {
            $this->track_stat( $user_id, 'total_lost', abs( $net ) );
        }

        // Per-game counter
        $counter_map = [
            'roulette'  => 'roulette_spins',
            'blackjack' => 'blackjack_hands',
            'slots'     => 'slots_spins',
            'poker'     => 'poker_hands',
        ];
        if ( isset( $counter_map[ $game ] ) ) {
            $this->track_stat( $user_id, $counter_map[ $game ], 1 );
        }
    }

    /* ─── Jackpot Detection ─────────────────────────────────── */

    /**
     * Check if a game result qualifies as a jackpot and award physical prize.
     * Returns jackpot data array if won, false otherwise.
     *
     * Jackpot criteria:
     *   Slots: multiplier >= 50 (⭐⭐⭐)
     *   Blackjack: natural 21 (first two cards)
     *   Roulette: landed on 00
     *   Poker: Royal Flush (multiplier = 250)
     */
    public function check_jackpot( $user_id, $game, $result_data = [] ) {
        $is_jackpot = false;

        switch ( $game ) {
            case 'slots':
                $is_jackpot = ( $result_data['multiplier'] ?? 0 ) >= 50;
                break;
            case 'blackjack':
                $is_jackpot = ( $result_data['result'] ?? '' ) === 'blackjack';
                break;
            case 'roulette':
                $is_jackpot = ( $result_data['label'] ?? '' ) === '00';
                break;
            case 'poker':
                $is_jackpot = ( $result_data['multiplier'] ?? 0 ) >= 250;
                break;
        }

        if ( ! $is_jackpot ) return false;

        /* Find a jackpot prize sticker for this game */
        $stickers = get_posts( [
            'post_type'   => 'fishotel_sticker',
            'numberposts' => 1,
            'post_status' => 'publish',
            'meta_query'  => [
                [ 'key' => '_sticker_jackpot_enabled', 'value' => '1' ],
                [ 'key' => '_sticker_jackpot_game', 'value' => $game ],
            ],
        ] );

        if ( empty( $stickers ) ) return false;

        $sticker = $stickers[0];

        /* Award physical prize */
        $prizes = get_user_meta( $user_id, '_fishotel_physical_prizes', true );
        $prizes = is_array( $prizes ) ? $prizes : [];

        /* Determine current batch */
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $batch_name = '';
        foreach ( $statuses as $name => $status ) {
            if ( $status === 'casino' ) { $batch_name = $name; break; }
        }

        $prizes[] = [
            'sticker_id'   => $sticker->ID,
            'sticker_name' => $sticker->post_title,
            'source'       => 'jackpot',
            'game_type'    => $game,
            'earned_at'    => time(),
            'batch_name'   => $batch_name,
            'chip_cost'    => 0,
            'added_to_box' => false,
        ];
        update_user_meta( $user_id, '_fishotel_physical_prizes', $prizes );

        return [
            'jackpot'      => true,
            'sticker_id'   => $sticker->ID,
            'sticker_name' => $sticker->post_title,
            'sticker_image' => get_the_post_thumbnail_url( $sticker->ID, 'medium' ) ?: '',
            'game'         => $game,
        ];
    }

    /* ─── AJAX: get chips ───────────────────────────────────── */
    public function fishotel_casino_get_chips() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        wp_send_json_success( [ 'chips' => $this->get_chips( $uid ), 'stats' => $this->get_stats( $uid ) ] );
    }

    /* ─── AJAX: claim daily chips ───────────────────────────── */
    public function fishotel_casino_claim_daily() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        $claimed = $this->maybe_claim_daily( $uid );
        wp_send_json_success( [
            'claimed' => $claimed,
            'chips'   => $this->get_chips( $uid ),
            'daily'   => self::DAILY_CHIPS,
        ] );
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 2 — CASINO FLOOR (Dollhouse Cutaway Scene)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function casino_floor_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p class="fhc-login-msg">Please log in to enter the FisHotel Casino.</p>';
        }

        $uid  = get_current_user_id();
        $this->maybe_claim_daily( $uid );
        $chips = $this->get_chips( $uid );

        $casino_img  = plugins_url( 'assists/casino/FisHotel-Casino.png', FISHOTEL_PLUGIN_FILE );
        $felt_url    = plugins_url( 'assists/casino/Felt-Table.jpg', FISHOTEL_PLUGIN_FILE );
        $chip_url    = plugins_url( 'assists/casino/Casino-Chip.png', FISHOTEL_PLUGIN_FILE );
        $nonce       = wp_create_nonce( 'fishotel_casino_nonce' );
        $ajax_url    = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <!-- ═══════════ FisHotel Casino Floor ═══════════ -->
        <div id="fhc-casino-app" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax_url ); ?>">

            <!-- ── Top Bar: Wallet ── -->
            <div class="fhc-topbar">
                <div class="fhc-topbar-inner">
                    <img src="<?php echo esc_url( $casino_img ); ?>" alt="FisHotel Casino" class="fhc-logo">
                    <div class="fhc-chip-display">
                        <img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" class="fhc-chip-icon">
                        <span id="fhc-chip-count"><?php echo number_format( $chips ); ?></span>
                    </div>
                </div>
            </div>

            <!-- ── Casino Floor: Dollhouse Cutaway ── -->
            <div class="fhc-floor" style="background-image:url('<?php echo esc_url( $felt_url ); ?>');">

                <!-- Game Stations — positioned via CSS grid -->
                <div class="fhc-stations">

                    <div class="fhc-station" data-game="roulette">
                        <div class="fhc-station-icon">🎰</div>
                        <div class="fhc-station-label">Roulette</div>
                    </div>

                    <div class="fhc-station" data-game="blackjack">
                        <div class="fhc-station-icon">🃏</div>
                        <div class="fhc-station-label">Blackjack</div>
                    </div>

                    <div class="fhc-station" data-game="slots">
                        <div class="fhc-station-icon">🎰</div>
                        <div class="fhc-station-label">Slots</div>
                    </div>

                    <div class="fhc-station" data-game="poker">
                        <div class="fhc-station-icon">♠️</div>
                        <div class="fhc-station-label">Poker</div>
                    </div>

                    <div class="fhc-station fhc-station-lb" data-game="leaderboard">
                        <div class="fhc-station-icon">🏆</div>
                        <div class="fhc-station-label">Leaderboard</div>
                    </div>

                </div>
            </div>

            <!-- ── Game Overlay (loads selected game) ── -->
            <div id="fhc-game-overlay" class="fhc-overlay" style="display:none;">
                <div class="fhc-overlay-header">
                    <button id="fhc-back-btn" class="fhc-btn fhc-btn-back">&larr; Back to Floor</button>
                    <div class="fhc-chip-display fhc-chip-mini">
                        <img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" class="fhc-chip-icon">
                        <span class="fhc-chip-count-mirror"><?php echo number_format( $chips ); ?></span>
                    </div>
                </div>
                <div id="fhc-game-area"></div>
            </div>

        </div>

        <style>
        /* ─── Casino Global Styles ─── */
        #fhc-casino-app{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;color:#fff;max-width:1200px;margin:0 auto;position:relative}
        .fhc-topbar{background:linear-gradient(135deg,#1a0a2e 0%,#16213e 50%,#0f3460 100%);padding:16px 24px;border-radius:16px 16px 0 0;display:flex;align-items:center}
        .fhc-topbar-inner{display:flex;align-items:center;justify-content:space-between;width:100%;gap:16px}
        .fhc-logo{height:48px;filter:drop-shadow(0 2px 8px rgba(255,215,0,.4))}
        .fhc-chip-display{display:flex;align-items:center;gap:8px;background:rgba(0,0,0,.4);padding:8px 18px;border-radius:40px;border:2px solid #c9a227}
        .fhc-chip-icon{width:28px;height:28px}
        #fhc-chip-count,.fhc-chip-count-mirror{font-size:1.3em;font-weight:700;color:#ffd700;text-shadow:0 0 10px rgba(255,215,0,.5)}

        /* ─── Casino Floor ─── */
        .fhc-floor{background-size:cover;background-position:center;min-height:500px;padding:40px 20px;border-radius:0 0 16px 16px;position:relative;display:flex;align-items:center;justify-content:center}
        .fhc-floor::before{content:'';position:absolute;inset:0;background:rgba(0,30,0,.55);border-radius:0 0 16px 16px}
        .fhc-stations{position:relative;z-index:2;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:24px;width:100%;max-width:900px}

        .fhc-station{background:rgba(0,0,0,.5);border:2px solid rgba(201,162,39,.5);border-radius:20px;padding:36px 20px;text-align:center;cursor:pointer;transition:all .3s ease;backdrop-filter:blur(6px)}
        .fhc-station:hover{transform:translateY(-6px) scale(1.03);border-color:#ffd700;box-shadow:0 12px 40px rgba(255,215,0,.25);background:rgba(0,0,0,.7)}
        .fhc-station-icon{font-size:3em;margin-bottom:12px;filter:drop-shadow(0 4px 8px rgba(0,0,0,.4))}
        .fhc-station-label{font-size:1.15em;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#e8d48b}
        .fhc-station-lb{grid-column:1/-1;max-width:300px;margin:0 auto}

        /* ─── Game Overlay ─── */
        .fhc-overlay{position:fixed;inset:0;z-index:99999;background:linear-gradient(135deg,#0d1117 0%,#1a1a2e 50%,#16213e 100%);overflow-y:auto;padding:20px}
        .fhc-overlay-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:0 10px}
        .fhc-btn{padding:10px 20px;border:none;border-radius:10px;font-size:.95em;font-weight:600;cursor:pointer;transition:all .2s}
        .fhc-btn-back{background:rgba(255,255,255,.1);color:#e8d48b;border:1px solid rgba(201,162,39,.4)}
        .fhc-btn-back:hover{background:rgba(255,255,255,.2);border-color:#ffd700}
        .fhc-btn-gold{background:linear-gradient(135deg,#c9a227,#e8d48b);color:#1a0a2e;font-weight:700}
        .fhc-btn-gold:hover{filter:brightness(1.1);transform:translateY(-1px)}
        .fhc-btn-gold:disabled{opacity:.4;cursor:not-allowed;transform:none}
        .fhc-chip-mini{padding:6px 14px}
        .fhc-chip-mini .fhc-chip-icon{width:22px;height:22px}
        #fhc-game-area{max-width:900px;margin:0 auto}

        /* ─── Shared Game Styles ─── */
        .fhc-game-title{text-align:center;font-size:1.8em;font-weight:700;color:#ffd700;margin-bottom:20px;text-shadow:0 2px 12px rgba(255,215,0,.3)}
        .fhc-bet-controls{display:flex;align-items:center;justify-content:center;gap:12px;margin:16px 0;flex-wrap:wrap}
        .fhc-bet-btn{background:rgba(255,255,255,.08);border:1px solid rgba(201,162,39,.4);color:#e8d48b;padding:8px 16px;border-radius:8px;cursor:pointer;font-weight:600;transition:all .2s}
        .fhc-bet-btn:hover,.fhc-bet-btn.active{background:#c9a227;color:#1a0a2e}
        .fhc-result{text-align:center;font-size:1.3em;font-weight:700;padding:16px;margin:16px 0;border-radius:12px;min-height:52px}
        .fhc-result.win{background:rgba(46,204,113,.15);color:#2ecc71;border:1px solid rgba(46,204,113,.3)}
        .fhc-result.lose{background:rgba(231,76,60,.15);color:#e74c3c;border:1px solid rgba(231,76,60,.3)}
        .fhc-result.push{background:rgba(241,196,15,.15);color:#f1c40f;border:1px solid rgba(241,196,15,.3)}
        .fhc-table{background:rgba(0,60,0,.4);border:8px solid #5c3a1e;border-radius:24px;padding:30px;box-shadow:inset 0 0 60px rgba(0,0,0,.4),0 8px 32px rgba(0,0,0,.5)}

        /* ─── Mobile ─── */
        @media(max-width:640px){
            .fhc-stations{grid-template-columns:1fr 1fr;gap:14px}
            .fhc-station{padding:24px 12px}
            .fhc-station-icon{font-size:2em}
            .fhc-station-label{font-size:.9em}
            .fhc-station-lb{grid-column:1/-1}
            .fhc-overlay{padding:10px}
            #fhc-game-area{padding:0 4px}
        }
        </style>

        <script>
        (function(){
            /* ─── App State ─── */
            const app   = document.getElementById('fhc-casino-app');
            const nonce = app.dataset.nonce;
            const ajax  = app.dataset.ajax;
            let chips    = <?php echo (int) $chips; ?>;

            function updateChipDisplays(n) {
                chips = n;
                const formatted = Number(n).toLocaleString();
                document.getElementById('fhc-chip-count').textContent = formatted;
                document.querySelectorAll('.fhc-chip-count-mirror').forEach(el => el.textContent = formatted);
            }

            async function post(action, data={}) {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', nonce);
                for (const k in data) fd.append(k, data[k]);
                const r = await fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'});
                return r.json();
            }

            /* ─── Floor Navigation ─── */
            const overlay  = document.getElementById('fhc-game-overlay');
            const gameArea = document.getElementById('fhc-game-area');

            document.querySelectorAll('.fhc-station').forEach(station => {
                station.addEventListener('click', () => {
                    const game = station.dataset.game;
                    overlay.style.display = '';
                    gameArea.innerHTML = '<p style="text-align:center;color:#888;padding:40px;">Loading…</p>';
                    loadGame(game);
                    document.body.style.overflow = 'hidden';
                });
            });

            document.getElementById('fhc-back-btn').addEventListener('click', () => {
                overlay.style.display = 'none';
                gameArea.innerHTML = '';
                document.body.style.overflow = '';
            });

            function loadGame(name) {
                switch(name) {
                    case 'roulette':   renderRoulette();  break;
                    case 'blackjack':  renderBlackjack(); break;
                    case 'slots':      renderSlots();     break;
                    case 'poker':      renderPoker();     break;
                    case 'leaderboard': renderLeaderboard(); break;
                }
            }

            /* ════════════════════════════════════════════════
             *  GAME 1 — ROULETTE
             * ════════════════════════════════════════════════ */
            function renderRoulette() {
                const NUMBERS = [
                    {n:0,c:'green'},{n:28,c:'black'},{n:9,c:'red'},{n:26,c:'black'},{n:30,c:'red'},
                    {n:11,c:'black'},{n:7,c:'red'},{n:20,c:'black'},{n:32,c:'red'},{n:17,c:'black'},
                    {n:5,c:'red'},{n:22,c:'black'},{n:34,c:'red'},{n:15,c:'black'},{n:3,c:'red'},
                    {n:24,c:'black'},{n:36,c:'red'},{n:13,c:'black'},{n:1,c:'red'},{n:0,c:'green',label:'00'},
                    {n:27,c:'red'},{n:10,c:'black'},{n:25,c:'red'},{n:29,c:'black'},{n:12,c:'red'},
                    {n:8,c:'black'},{n:19,c:'red'},{n:31,c:'black'},{n:18,c:'red'},{n:6,c:'black'},
                    {n:21,c:'red'},{n:33,c:'black'},{n:16,c:'red'},{n:4,c:'black'},{n:23,c:'red'},
                    {n:35,c:'black'},{n:14,c:'red'},{n:2,c:'black'}
                ];
                const SEGMENTS = NUMBERS.length;
                const SEG_ANGLE = 360 / SEGMENTS;

                let bet = 50;
                let betType = 'red'; // red, black, odd, even, number
                let betNumber = null;
                let spinning = false;

                gameArea.innerHTML = `
                    <div class="fhc-game-title">Roulette</div>
                    <div class="fhc-table" style="text-align:center;">
                        <canvas id="fhc-roul-wheel" width="340" height="340" style="margin:0 auto;display:block;max-width:100%;"></canvas>
                        <div id="fhc-roul-result" class="fhc-result" style="margin-top:16px;"></div>

                        <div style="margin:16px 0;">
                            <label style="color:#e8d48b;font-weight:600;">Bet Amount:</label>
                            <div class="fhc-bet-controls" id="fhc-roul-bet-amt">
                                <button class="fhc-bet-btn" data-amt="10">10</button>
                                <button class="fhc-bet-btn active" data-amt="50">50</button>
                                <button class="fhc-bet-btn" data-amt="100">100</button>
                                <button class="fhc-bet-btn" data-amt="250">250</button>
                                <button class="fhc-bet-btn" data-amt="500">500</button>
                            </div>
                        </div>

                        <div style="margin:16px 0;">
                            <label style="color:#e8d48b;font-weight:600;">Bet Type:</label>
                            <div class="fhc-bet-controls" id="fhc-roul-bet-type">
                                <button class="fhc-bet-btn active" data-type="red" style="color:#e74c3c;">Red</button>
                                <button class="fhc-bet-btn" data-type="black" style="color:#ccc;">Black</button>
                                <button class="fhc-bet-btn" data-type="odd">Odd</button>
                                <button class="fhc-bet-btn" data-type="even">Even</button>
                            </div>
                        </div>

                        <button id="fhc-roul-spin" class="fhc-btn fhc-btn-gold" style="font-size:1.2em;padding:14px 48px;margin:16px 0;">SPIN</button>
                    </div>
                `;

                // Draw wheel
                const canvas = document.getElementById('fhc-roul-wheel');
                const ctx = canvas.getContext('2d');
                let wheelRotation = 0;

                function drawWheel(rotation) {
                    const cx = canvas.width/2, cy = canvas.height/2, r = 155;
                    ctx.clearRect(0,0,canvas.width,canvas.height);
                    ctx.save();
                    ctx.translate(cx, cy);
                    ctx.rotate(rotation * Math.PI / 180);

                    for (let i = 0; i < SEGMENTS; i++) {
                        const start = (i * SEG_ANGLE - 90) * Math.PI / 180;
                        const end   = ((i+1) * SEG_ANGLE - 90) * Math.PI / 180;
                        ctx.beginPath();
                        ctx.moveTo(0, 0);
                        ctx.arc(0, 0, r, start, end);
                        ctx.closePath();
                        ctx.fillStyle = NUMBERS[i].c === 'red' ? '#c0392b' : NUMBERS[i].c === 'black' ? '#2c3e50' : '#27ae60';
                        ctx.fill();
                        ctx.strokeStyle = '#c9a227';
                        ctx.lineWidth = 1;
                        ctx.stroke();

                        // Number text
                        const mid = (start + end) / 2;
                        const tx = Math.cos(mid) * (r * 0.72);
                        const ty = Math.sin(mid) * (r * 0.72);
                        ctx.save();
                        ctx.translate(tx, ty);
                        ctx.rotate(mid + Math.PI/2);
                        ctx.fillStyle = '#fff';
                        ctx.font = 'bold 11px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText(NUMBERS[i].label || NUMBERS[i].n, 0, 0);
                        ctx.restore();
                    }

                    ctx.restore();

                    // Pointer
                    ctx.beginPath();
                    ctx.moveTo(cx, cy - r - 6);
                    ctx.lineTo(cx - 10, cy - r - 24);
                    ctx.lineTo(cx + 10, cy - r - 24);
                    ctx.closePath();
                    ctx.fillStyle = '#ffd700';
                    ctx.fill();
                    ctx.strokeStyle = '#1a0a2e';
                    ctx.lineWidth = 2;
                    ctx.stroke();

                    // Center hub
                    ctx.beginPath();
                    ctx.arc(cx, cy, 20, 0, Math.PI*2);
                    ctx.fillStyle = '#1a0a2e';
                    ctx.fill();
                    ctx.strokeStyle = '#c9a227';
                    ctx.lineWidth = 3;
                    ctx.stroke();
                }

                drawWheel(0);

                // Bet amount buttons
                document.querySelectorAll('#fhc-roul-bet-amt .fhc-bet-btn').forEach(b => {
                    b.addEventListener('click', () => {
                        if (spinning) return;
                        document.querySelectorAll('#fhc-roul-bet-amt .fhc-bet-btn').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        bet = parseInt(b.dataset.amt);
                    });
                });

                // Bet type buttons
                document.querySelectorAll('#fhc-roul-bet-type .fhc-bet-btn').forEach(b => {
                    b.addEventListener('click', () => {
                        if (spinning) return;
                        document.querySelectorAll('#fhc-roul-bet-type .fhc-bet-btn').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        betType = b.dataset.type;
                    });
                });

                // Spin
                document.getElementById('fhc-roul-spin').addEventListener('click', async () => {
                    if (spinning) return;
                    if (bet > chips) {
                        document.getElementById('fhc-roul-result').textContent = 'Not enough chips!';
                        document.getElementById('fhc-roul-result').className = 'fhc-result lose';
                        return;
                    }
                    spinning = true;
                    document.getElementById('fhc-roul-spin').disabled = true;
                    document.getElementById('fhc-roul-result').textContent = '';
                    document.getElementById('fhc-roul-result').className = 'fhc-result';

                    const res = await post('fishotel_casino_roulette_spin', {bet: bet, bet_type: betType, bet_number: betNumber || ''});
                    if (!res.success) {
                        document.getElementById('fhc-roul-result').textContent = res.data.message;
                        document.getElementById('fhc-roul-result').className = 'fhc-result lose';
                        spinning = false;
                        document.getElementById('fhc-roul-spin').disabled = false;
                        return;
                    }

                    const d = res.data;
                    const winIdx = NUMBERS.findIndex(s => s.n === d.number && (d.label === '00' ? s.label === '00' : !s.label));
                    const targetAngle = winIdx * SEG_ANGLE + SEG_ANGLE/2;
                    const spins = 4 + Math.random() * 2;
                    const totalRotation = wheelRotation + spins * 360 + (360 - targetAngle);

                    // Animate
                    const start = performance.now();
                    const duration = 4000;
                    const from = wheelRotation;

                    function easeOut(t) { return 1 - Math.pow(1 - t, 3); }

                    function animate(now) {
                        let t = Math.min((now - start) / duration, 1);
                        let current = from + (totalRotation - from) * easeOut(t);
                        drawWheel(current);
                        if (t < 1) {
                            requestAnimationFrame(animate);
                        } else {
                            wheelRotation = totalRotation % 360;
                            updateChipDisplays(d.chips);
                            const resultEl = document.getElementById('fhc-roul-result');
                            if (d.payout > 0) {
                                resultEl.textContent = `${d.label || d.number} ${d.color}! You won ${d.payout.toLocaleString()} chips!`;
                                resultEl.className = 'fhc-result win';
                            } else {
                                resultEl.textContent = `${d.label || d.number} ${d.color}. You lost ${bet.toLocaleString()} chips.`;
                                resultEl.className = 'fhc-result lose';
                            }
                            spinning = false;
                            document.getElementById('fhc-roul-spin').disabled = false;
                        }
                    }
                    requestAnimationFrame(animate);
                });
            }


            /* ════════════════════════════════════════════════
             *  GAME 2 — BLACKJACK
             * ════════════════════════════════════════════════ */
            function renderBlackjack() {
                let bet = 50;
                let gameState = null; // {player:[], dealer:[], status:'playing'|'stand'|'bust'|'blackjack'|'done'}
                let gameId = 0;

                gameArea.innerHTML = `
                    <div class="fhc-game-title">Blackjack</div>
                    <div class="fhc-table">
                        <div id="fhc-bj-dealer" style="text-align:center;margin-bottom:24px;">
                            <div style="color:#e8d48b;font-weight:600;margin-bottom:8px;">Dealer</div>
                            <div id="fhc-bj-dealer-cards" class="fhc-card-row"></div>
                            <div id="fhc-bj-dealer-score" style="color:#aaa;margin-top:6px;"></div>
                        </div>
                        <div id="fhc-bj-result" class="fhc-result"></div>
                        <div id="fhc-bj-player" style="text-align:center;margin-top:24px;">
                            <div style="color:#e8d48b;font-weight:600;margin-bottom:8px;">Your Hand</div>
                            <div id="fhc-bj-player-cards" class="fhc-card-row"></div>
                            <div id="fhc-bj-player-score" style="color:#ffd700;font-weight:700;font-size:1.2em;margin-top:6px;"></div>
                        </div>

                        <div id="fhc-bj-actions" style="text-align:center;margin-top:20px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                            <div id="fhc-bj-bet-phase">
                                <label style="color:#e8d48b;font-weight:600;">Bet:</label>
                                <div class="fhc-bet-controls" id="fhc-bj-bet-amt">
                                    <button class="fhc-bet-btn" data-amt="10">10</button>
                                    <button class="fhc-bet-btn active" data-amt="50">50</button>
                                    <button class="fhc-bet-btn" data-amt="100">100</button>
                                    <button class="fhc-bet-btn" data-amt="250">250</button>
                                </div>
                                <button id="fhc-bj-deal" class="fhc-btn fhc-btn-gold" style="margin-top:12px;padding:12px 40px;font-size:1.1em;">DEAL</button>
                            </div>
                            <div id="fhc-bj-play-phase" style="display:none;">
                                <button id="fhc-bj-hit" class="fhc-btn fhc-btn-gold" style="padding:12px 32px;">HIT</button>
                                <button id="fhc-bj-stand" class="fhc-btn fhc-btn-gold" style="padding:12px 32px;">STAND</button>
                                <button id="fhc-bj-double" class="fhc-btn fhc-btn-gold" style="padding:12px 32px;">DOUBLE</button>
                            </div>
                            <div id="fhc-bj-done-phase" style="display:none;">
                                <button id="fhc-bj-newhand" class="fhc-btn fhc-btn-gold" style="padding:12px 40px;font-size:1.1em;">NEW HAND</button>
                            </div>
                        </div>
                    </div>

                    <style>
                    .fhc-card-row{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;min-height:90px;align-items:center}
                    .fhc-card{width:60px;height:88px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1em;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,.4);transition:transform .3s}
                    .fhc-card-red{background:#fff;color:#c0392b;border:2px solid #ddd}
                    .fhc-card-black{background:#fff;color:#2c3e50;border:2px solid #ddd}
                    .fhc-card-back{background:linear-gradient(135deg,#1a0a2e,#16213e);border:2px solid #c9a227;color:#c9a227;font-size:.7em}
                    </style>
                `;

                const suits = ['♥','♦','♣','♠'];
                const ranks = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];

                function cardHtml(card, faceDown) {
                    if (faceDown) return '<div class="fhc-card fhc-card-back">FH</div>';
                    const isRed = card.suit === '♥' || card.suit === '♦';
                    return `<div class="fhc-card ${isRed ? 'fhc-card-red' : 'fhc-card-black'}">${card.rank}${card.suit}</div>`;
                }

                function renderHands(showDealer) {
                    if (!gameState) return;
                    const dc = document.getElementById('fhc-bj-dealer-cards');
                    const pc = document.getElementById('fhc-bj-player-cards');
                    dc.innerHTML = gameState.dealer.map((c,i) => cardHtml(c, !showDealer && i === 1)).join('');
                    pc.innerHTML = gameState.player.map(c => cardHtml(c, false)).join('');
                    document.getElementById('fhc-bj-player-score').textContent = 'Score: ' + calcHand(gameState.player);
                    document.getElementById('fhc-bj-dealer-score').textContent = showDealer ? 'Score: ' + calcHand(gameState.dealer) : '';
                }

                function calcHand(cards) {
                    let total = 0, aces = 0;
                    for (const c of cards) {
                        if (c.rank === 'A') { aces++; total += 11; }
                        else if (['K','Q','J'].includes(c.rank)) total += 10;
                        else total += parseInt(c.rank);
                    }
                    while (total > 21 && aces > 0) { total -= 10; aces--; }
                    return total;
                }

                // Bet amount
                document.querySelectorAll('#fhc-bj-bet-amt .fhc-bet-btn').forEach(b => {
                    b.addEventListener('click', () => {
                        document.querySelectorAll('#fhc-bj-bet-amt .fhc-bet-btn').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        bet = parseInt(b.dataset.amt);
                    });
                });

                function showPhase(phase) {
                    document.getElementById('fhc-bj-bet-phase').style.display = phase === 'bet' ? '' : 'none';
                    document.getElementById('fhc-bj-play-phase').style.display = phase === 'play' ? '' : 'none';
                    document.getElementById('fhc-bj-done-phase').style.display = phase === 'done' ? '' : 'none';
                }

                // Deal
                document.getElementById('fhc-bj-deal').addEventListener('click', async () => {
                    if (bet > chips) {
                        document.getElementById('fhc-bj-result').textContent = 'Not enough chips!';
                        document.getElementById('fhc-bj-result').className = 'fhc-result lose';
                        return;
                    }
                    document.getElementById('fhc-bj-result').textContent = '';
                    document.getElementById('fhc-bj-result').className = 'fhc-result';
                    const res = await post('fishotel_casino_blackjack_action', {bet: bet, move: 'deal'});
                    if (!res.success) return;
                    gameState = res.data.state;
                    gameId = res.data.game_id;
                    updateChipDisplays(res.data.chips);
                    renderHands(false);
                    if (gameState.status === 'blackjack') {
                        renderHands(true);
                        endHand(res.data);
                    } else {
                        showPhase('play');
                    }
                });

                async function doAction(move) {
                    const res = await post('fishotel_casino_blackjack_action', {game_id: gameId, move: move});
                    if (!res.success) return;
                    gameState = res.data.state;
                    updateChipDisplays(res.data.chips);
                    if (res.data.state.status === 'playing') {
                        renderHands(false);
                    } else {
                        renderHands(true);
                        endHand(res.data);
                    }
                }

                function endHand(d) {
                    const r = document.getElementById('fhc-bj-result');
                    if (d.result === 'blackjack') { r.textContent = `Blackjack! +${d.payout.toLocaleString()}`; r.className = 'fhc-result win'; }
                    else if (d.result === 'win')   { r.textContent = `You win! +${d.payout.toLocaleString()}`; r.className = 'fhc-result win'; }
                    else if (d.result === 'push')   { r.textContent = 'Push — bet returned.'; r.className = 'fhc-result push'; }
                    else { r.textContent = `Dealer wins. -${d.wager.toLocaleString()}`; r.className = 'fhc-result lose'; }
                    showPhase('done');
                }

                document.getElementById('fhc-bj-hit').addEventListener('click',    () => doAction('hit'));
                document.getElementById('fhc-bj-stand').addEventListener('click',  () => doAction('stand'));
                document.getElementById('fhc-bj-double').addEventListener('click', () => doAction('double'));
                document.getElementById('fhc-bj-newhand').addEventListener('click', () => {
                    showPhase('bet');
                    document.getElementById('fhc-bj-result').textContent = '';
                    document.getElementById('fhc-bj-result').className = 'fhc-result';
                    document.getElementById('fhc-bj-player-cards').innerHTML = '';
                    document.getElementById('fhc-bj-dealer-cards').innerHTML = '';
                    document.getElementById('fhc-bj-player-score').textContent = '';
                    document.getElementById('fhc-bj-dealer-score').textContent = '';
                    gameState = null;
                });
            }


            /* ════════════════════════════════════════════════
             *  GAME 3 — SLOTS
             * ════════════════════════════════════════════════ */
            function renderSlots() {
                const SYMBOLS = ['🐠','🐟','🐡','🦈','🐙','🦀','🐚','🌊','⭐'];
                const PAYOUTS = {
                    '🐠🐠🐠': 5, '🐟🐟🐟': 5, '🐡🐡🐡': 8,
                    '🦈🦈🦈': 10, '🐙🐙🐙': 15, '🦀🦀🦀': 12,
                    '🐚🐚🐚': 8, '🌊🌊🌊': 20, '⭐⭐⭐': 50
                };
                let bet = 50;
                let spinning = false;

                gameArea.innerHTML = `
                    <div class="fhc-game-title">Fish Slots</div>
                    <div class="fhc-table" style="text-align:center;">
                        <div id="fhc-slots-machine" style="display:flex;justify-content:center;gap:12px;margin:30px 0;">
                            <div class="fhc-reel" id="fhc-reel-0">🐠</div>
                            <div class="fhc-reel" id="fhc-reel-1">🐟</div>
                            <div class="fhc-reel" id="fhc-reel-2">🦈</div>
                        </div>
                        <div id="fhc-slots-result" class="fhc-result"></div>

                        <div style="margin:16px 0;">
                            <label style="color:#e8d48b;font-weight:600;">Bet:</label>
                            <div class="fhc-bet-controls" id="fhc-slots-bet">
                                <button class="fhc-bet-btn" data-amt="10">10</button>
                                <button class="fhc-bet-btn active" data-amt="50">50</button>
                                <button class="fhc-bet-btn" data-amt="100">100</button>
                                <button class="fhc-bet-btn" data-amt="250">250</button>
                            </div>
                        </div>

                        <button id="fhc-slots-spin" class="fhc-btn fhc-btn-gold" style="font-size:1.2em;padding:14px 48px;">PULL</button>

                        <div style="margin-top:24px;color:#888;font-size:.85em;">
                            <div style="color:#e8d48b;font-weight:600;margin-bottom:8px;">Payouts (multiplier × bet):</div>
                            <div>⭐⭐⭐ = 50× &nbsp; 🌊🌊🌊 = 20× &nbsp; 🐙🐙🐙 = 15×</div>
                            <div>🦀🦀🦀 = 12× &nbsp; 🦈🦈🦈 = 10× &nbsp; 🐡🐡🐡 = 8×</div>
                            <div>Any 3 match = 5× &nbsp; 2 match = 2×</div>
                        </div>
                    </div>

                    <style>
                    .fhc-reel{width:100px;height:100px;background:rgba(0,0,0,.5);border:3px solid #c9a227;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:3em;transition:none;box-shadow:inset 0 0 20px rgba(0,0,0,.5)}
                    .fhc-reel.spinning{animation:fhc-reel-spin .15s infinite}
                    @keyframes fhc-reel-spin{0%{transform:translateY(-4px)}50%{transform:translateY(4px)}100%{transform:translateY(-4px)}}
                    </style>
                `;

                // Bet buttons
                document.querySelectorAll('#fhc-slots-bet .fhc-bet-btn').forEach(b => {
                    b.addEventListener('click', () => {
                        if (spinning) return;
                        document.querySelectorAll('#fhc-slots-bet .fhc-bet-btn').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        bet = parseInt(b.dataset.amt);
                    });
                });

                document.getElementById('fhc-slots-spin').addEventListener('click', async () => {
                    if (spinning) return;
                    if (bet > chips) {
                        document.getElementById('fhc-slots-result').textContent = 'Not enough chips!';
                        document.getElementById('fhc-slots-result').className = 'fhc-result lose';
                        return;
                    }
                    spinning = true;
                    document.getElementById('fhc-slots-spin').disabled = true;
                    document.getElementById('fhc-slots-result').textContent = '';
                    document.getElementById('fhc-slots-result').className = 'fhc-result';

                    // Start spinning animation
                    const reels = [0,1,2].map(i => document.getElementById('fhc-reel-'+i));
                    reels.forEach(r => r.classList.add('spinning'));
                    const shuffleInterval = setInterval(() => {
                        reels.forEach(r => { r.textContent = SYMBOLS[Math.floor(Math.random()*SYMBOLS.length)]; });
                    }, 80);

                    const res = await post('fishotel_casino_slots_spin', {bet: bet});
                    if (!res.success) {
                        clearInterval(shuffleInterval);
                        reels.forEach(r => r.classList.remove('spinning'));
                        spinning = false;
                        document.getElementById('fhc-slots-spin').disabled = false;
                        return;
                    }

                    const d = res.data;
                    // Stop reels one by one
                    for (let i = 0; i < 3; i++) {
                        await new Promise(r => setTimeout(r, 600 + i * 500));
                        reels[i].classList.remove('spinning');
                        reels[i].textContent = d.reels[i];
                    }
                    clearInterval(shuffleInterval);

                    updateChipDisplays(d.chips);
                    const resultEl = document.getElementById('fhc-slots-result');
                    if (d.payout > 0) {
                        resultEl.textContent = `Winner! +${d.payout.toLocaleString()} chips! (${d.multiplier}×)`;
                        resultEl.className = 'fhc-result win';
                    } else {
                        resultEl.textContent = `No match. -${bet.toLocaleString()} chips.`;
                        resultEl.className = 'fhc-result lose';
                    }
                    spinning = false;
                    document.getElementById('fhc-slots-spin').disabled = false;
                });
            }


            /* ════════════════════════════════════════════════
             *  GAME 4 — VIDEO POKER (5-Card Draw)
             * ════════════════════════════════════════════════ */
            function renderPoker() {
                let bet = 50;
                let hand = [];
                let held = [false,false,false,false,false];
                let phase = 'bet'; // bet, hold, done
                let gameId = 0;

                gameArea.innerHTML = `
                    <div class="fhc-game-title">Video Poker</div>
                    <div class="fhc-table" style="text-align:center;">
                        <div id="fhc-pk-cards" class="fhc-card-row" style="min-height:110px;gap:10px;margin:20px 0;"></div>
                        <div id="fhc-pk-hand-name" style="color:#ffd700;font-size:1.2em;font-weight:700;min-height:30px;"></div>
                        <div id="fhc-pk-result" class="fhc-result"></div>

                        <div id="fhc-pk-bet-phase" style="margin:16px 0;">
                            <label style="color:#e8d48b;font-weight:600;">Bet:</label>
                            <div class="fhc-bet-controls" id="fhc-pk-bet-amt">
                                <button class="fhc-bet-btn" data-amt="10">10</button>
                                <button class="fhc-bet-btn active" data-amt="50">50</button>
                                <button class="fhc-bet-btn" data-amt="100">100</button>
                                <button class="fhc-bet-btn" data-amt="250">250</button>
                            </div>
                            <button id="fhc-pk-deal" class="fhc-btn fhc-btn-gold" style="margin-top:12px;padding:12px 40px;">DEAL</button>
                        </div>
                        <div id="fhc-pk-hold-phase" style="display:none;margin:16px 0;">
                            <p style="color:#e8d48b;margin-bottom:12px;">Click cards to hold, then draw.</p>
                            <button id="fhc-pk-draw" class="fhc-btn fhc-btn-gold" style="padding:12px 40px;">DRAW</button>
                        </div>
                        <div id="fhc-pk-done-phase" style="display:none;margin:16px 0;">
                            <button id="fhc-pk-again" class="fhc-btn fhc-btn-gold" style="padding:12px 40px;">NEW HAND</button>
                        </div>

                        <div style="margin-top:24px;color:#888;font-size:.85em;">
                            <div style="color:#e8d48b;font-weight:600;margin-bottom:8px;">Payouts (multiplier × bet):</div>
                            <div>Royal Flush = 250× &nbsp; Straight Flush = 50× &nbsp; 4 of a Kind = 25×</div>
                            <div>Full House = 9× &nbsp; Flush = 6× &nbsp; Straight = 4×</div>
                            <div>3 of a Kind = 3× &nbsp; Two Pair = 2× &nbsp; Jacks or Better = 1×</div>
                        </div>
                    </div>

                    <style>
                    .fhc-pk-card{width:72px;height:104px;border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:1.1em;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,.4);cursor:pointer;transition:transform .2s,box-shadow .2s;position:relative}
                    .fhc-pk-card.held{transform:translateY(-14px);box-shadow:0 8px 24px rgba(255,215,0,.4);border-color:#ffd700 !important}
                    .fhc-pk-card .held-tag{position:absolute;top:-10px;background:#ffd700;color:#1a0a2e;font-size:.6em;padding:2px 8px;border-radius:6px;display:none}
                    .fhc-pk-card.held .held-tag{display:block}
                    </style>
                `;

                function renderCards() {
                    const el = document.getElementById('fhc-pk-cards');
                    el.innerHTML = hand.map((c, i) => {
                        const isRed = c.suit === '♥' || c.suit === '♦';
                        const heldClass = held[i] ? 'held' : '';
                        return `<div class="fhc-pk-card ${isRed ? 'fhc-card-red' : 'fhc-card-black'} ${heldClass}" data-idx="${i}">
                            <span class="held-tag">HELD</span>
                            <span>${c.rank}</span><span>${c.suit}</span>
                        </div>`;
                    }).join('');
                    // Click to hold
                    if (phase === 'hold') {
                        el.querySelectorAll('.fhc-pk-card').forEach(card => {
                            card.addEventListener('click', () => {
                                const idx = parseInt(card.dataset.idx);
                                held[idx] = !held[idx];
                                card.classList.toggle('held');
                                card.querySelector('.held-tag').style.display = held[idx] ? 'block' : 'none';
                            });
                        });
                    }
                }

                function showPhase(p) {
                    phase = p;
                    document.getElementById('fhc-pk-bet-phase').style.display  = p === 'bet' ? '' : 'none';
                    document.getElementById('fhc-pk-hold-phase').style.display = p === 'hold' ? '' : 'none';
                    document.getElementById('fhc-pk-done-phase').style.display = p === 'done' ? '' : 'none';
                }

                document.querySelectorAll('#fhc-pk-bet-amt .fhc-bet-btn').forEach(b => {
                    b.addEventListener('click', () => {
                        document.querySelectorAll('#fhc-pk-bet-amt .fhc-bet-btn').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        bet = parseInt(b.dataset.amt);
                    });
                });

                document.getElementById('fhc-pk-deal').addEventListener('click', async () => {
                    if (bet > chips) {
                        document.getElementById('fhc-pk-result').textContent = 'Not enough chips!';
                        document.getElementById('fhc-pk-result').className = 'fhc-result lose';
                        return;
                    }
                    document.getElementById('fhc-pk-result').textContent = '';
                    document.getElementById('fhc-pk-result').className = 'fhc-result';
                    document.getElementById('fhc-pk-hand-name').textContent = '';
                    held = [false,false,false,false,false];

                    const res = await post('fishotel_casino_poker_action', {bet: bet, move: 'deal'});
                    if (!res.success) return;
                    hand = res.data.hand;
                    gameId = res.data.game_id;
                    updateChipDisplays(res.data.chips);
                    renderCards();
                    showPhase('hold');
                });

                document.getElementById('fhc-pk-draw').addEventListener('click', async () => {
                    const res = await post('fishotel_casino_poker_action', {
                        game_id: gameId,
                        move: 'draw',
                        held: JSON.stringify(held)
                    });
                    if (!res.success) return;
                    hand = res.data.hand;
                    updateChipDisplays(res.data.chips);
                    phase = 'done';
                    renderCards();
                    document.getElementById('fhc-pk-hand-name').textContent = res.data.hand_name;
                    const r = document.getElementById('fhc-pk-result');
                    if (res.data.payout > 0) {
                        r.textContent = `You win ${res.data.payout.toLocaleString()} chips! (${res.data.multiplier}×)`;
                        r.className = 'fhc-result win';
                    } else {
                        r.textContent = `No winning hand. -${bet.toLocaleString()} chips.`;
                        r.className = 'fhc-result lose';
                    }
                    showPhase('done');
                });

                document.getElementById('fhc-pk-again').addEventListener('click', () => {
                    hand = [];
                    held = [false,false,false,false,false];
                    document.getElementById('fhc-pk-cards').innerHTML = '';
                    document.getElementById('fhc-pk-result').textContent = '';
                    document.getElementById('fhc-pk-result').className = 'fhc-result';
                    document.getElementById('fhc-pk-hand-name').textContent = '';
                    showPhase('bet');
                });
            }


            /* ════════════════════════════════════════════════
             *  LEADERBOARD
             * ════════════════════════════════════════════════ */
            function renderLeaderboard() {
                gameArea.innerHTML = `
                    <div class="fhc-game-title">Leaderboard</div>
                    <div class="fhc-table">
                        <div id="fhc-lb-content" style="text-align:center;color:#aaa;padding:20px;">Loading…</div>
                    </div>
                `;
                post('fishotel_casino_leaderboard_data').then(res => {
                    if (!res.success) return;
                    const d = res.data;
                    let html = '<table style="width:100%;border-collapse:collapse;color:#e8d48b;">';
                    html += '<tr style="border-bottom:2px solid rgba(201,162,39,.3);"><th style="padding:10px;text-align:left;">#</th><th style="padding:10px;text-align:left;">Player</th><th style="padding:10px;text-align:right;">Chips Won</th><th style="padding:10px;text-align:right;">Games</th></tr>';
                    d.leaders.forEach((p, i) => {
                        const medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : (i+1);
                        const highlight = p.is_you ? 'background:rgba(255,215,0,.1);' : '';
                        html += `<tr style="border-bottom:1px solid rgba(255,255,255,.05);${highlight}">
                            <td style="padding:10px;">${medal}</td>
                            <td style="padding:10px;">${p.name}${p.is_you ? ' (you)' : ''}</td>
                            <td style="padding:10px;text-align:right;color:#ffd700;font-weight:700;">${Number(p.winnings).toLocaleString()}</td>
                            <td style="padding:10px;text-align:right;">${p.games}</td>
                        </tr>`;
                    });
                    html += '</table>';
                    if (d.your_rank && !d.leaders.find(l => l.is_you)) {
                        html += `<div style="margin-top:16px;padding:12px;background:rgba(255,215,0,.1);border-radius:8px;color:#e8d48b;">Your rank: #${d.your_rank} — ${Number(d.your_winnings).toLocaleString()} chips won</div>`;
                    }
                    document.getElementById('fhc-lb-content').innerHTML = html;
                });
            }

        })();
        </script>
        <?php
        return ob_get_clean();
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 3 — ROULETTE (Server-side logic)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function fishotel_casino_roulette_spin() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $bet      = max( 1, (int) sanitize_text_field( $_POST['bet'] ?? 0 ) );
        $bet_type = sanitize_text_field( $_POST['bet_type'] ?? 'red' );

        if ( $bet > $this->get_chips( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Not enough chips.' ] );
        }

        // Spin — American roulette (0, 00, 1-36)
        $pockets = [];
        $pockets[] = [ 'number' => 0, 'color' => 'green', 'label' => '0' ];
        $pockets[] = [ 'number' => 0, 'color' => 'green', 'label' => '00' ];
        $reds = [1,3,5,7,9,12,14,16,18,19,21,23,25,27,30,32,34,36];
        for ( $i = 1; $i <= 36; $i++ ) {
            $pockets[] = [
                'number' => $i,
                'color'  => in_array( $i, $reds ) ? 'red' : 'black',
                'label'  => (string) $i,
            ];
        }

        $winner = $pockets[ wp_rand( 0, count( $pockets ) - 1 ) ];
        $payout = 0;

        // Evaluate bet
        switch ( $bet_type ) {
            case 'red':
                if ( $winner['color'] === 'red' ) $payout = $bet * 2;
                break;
            case 'black':
                if ( $winner['color'] === 'black' ) $payout = $bet * 2;
                break;
            case 'odd':
                if ( $winner['number'] > 0 && $winner['number'] % 2 === 1 ) $payout = $bet * 2;
                break;
            case 'even':
                if ( $winner['number'] > 0 && $winner['number'] % 2 === 0 ) $payout = $bet * 2;
                break;
            case 'number':
                $target = (int) sanitize_text_field( $_POST['bet_number'] ?? -1 );
                if ( $winner['number'] === $target && $winner['label'] === (string) $target ) {
                    $payout = $bet * 36;
                }
                break;
        }

        // Deduct bet, add payout
        $this->deduct_chips( $uid, $bet );
        if ( $payout > 0 ) {
            $this->add_chips( $uid, $payout );
        }
        $this->record_game( $uid, 'roulette', $bet, $payout );

        $jackpot = $this->check_jackpot( $uid, 'roulette', [ 'label' => $winner['label'], 'payout' => $payout ] );

        wp_send_json_success( [
            'number'  => $winner['number'],
            'color'   => $winner['color'],
            'label'   => $winner['label'],
            'payout'  => $payout,
            'chips'   => $this->get_chips( $uid ),
            'jackpot' => $jackpot ?: null,
        ] );
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 4 — BLACKJACK (Server-side logic)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    private function bj_new_deck() {
        $suits = [ '♥', '♦', '♣', '♠' ];
        $ranks = [ 'A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K' ];
        $deck  = [];
        foreach ( $suits as $s ) {
            foreach ( $ranks as $r ) {
                $deck[] = [ 'rank' => $r, 'suit' => $s ];
            }
        }
        shuffle( $deck );
        return $deck;
    }

    private function bj_hand_value( $cards ) {
        $total = 0;
        $aces  = 0;
        foreach ( $cards as $c ) {
            if ( $c['rank'] === 'A' ) {
                $aces++;
                $total += 11;
            } elseif ( in_array( $c['rank'], [ 'K', 'Q', 'J' ] ) ) {
                $total += 10;
            } else {
                $total += (int) $c['rank'];
            }
        }
        while ( $total > 21 && $aces > 0 ) {
            $total -= 10;
            $aces--;
        }
        return $total;
    }

    public function fishotel_casino_blackjack_action() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $move = sanitize_text_field( $_POST['move'] ?? '' );

        if ( $move === 'deal' ) {
            $bet = max( 1, (int) sanitize_text_field( $_POST['bet'] ?? 0 ) );
            if ( $bet > $this->get_chips( $uid ) ) {
                wp_send_json_error( [ 'message' => 'Not enough chips.' ] );
            }
            $this->deduct_chips( $uid, $bet );

            $deck   = $this->bj_new_deck();
            $player = [ array_pop( $deck ), array_pop( $deck ) ];
            $dealer = [ array_pop( $deck ), array_pop( $deck ) ];

            $game_id = wp_rand( 100000, 999999 );
            $state   = [
                'player' => $player,
                'dealer' => $dealer,
                'deck'   => $deck,
                'bet'    => $bet,
                'status' => 'playing',
            ];

            // Check for natural blackjack
            $pval = $this->bj_hand_value( $player );
            $dval = $this->bj_hand_value( $dealer );

            if ( $pval === 21 ) {
                $state['status'] = 'blackjack';
                if ( $dval === 21 ) {
                    // Push
                    $this->add_chips( $uid, $bet );
                    $this->record_game( $uid, 'blackjack', $bet, $bet );
                    set_transient( "fhc_bj_{$uid}_{$game_id}", $state, 3600 );
                    wp_send_json_success( [
                        'game_id' => $game_id,
                        'state'   => $state,
                        'result'  => 'push',
                        'payout'  => 0,
                        'wager'   => $bet,
                        'chips'   => $this->get_chips( $uid ),
                    ] );
                }
                $payout = (int) ( $bet * 2.5 );
                $this->add_chips( $uid, $payout );
                $this->record_game( $uid, 'blackjack', $bet, $payout );
                $jackpot = $this->check_jackpot( $uid, 'blackjack', [ 'result' => 'blackjack' ] );
                set_transient( "fhc_bj_{$uid}_{$game_id}", $state, 3600 );
                wp_send_json_success( [
                    'game_id' => $game_id,
                    'state'   => $state,
                    'result'  => 'blackjack',
                    'payout'  => $payout,
                    'wager'   => $bet,
                    'chips'   => $this->get_chips( $uid ),
                    'jackpot' => $jackpot ?: null,
                ] );
            }

            set_transient( "fhc_bj_{$uid}_{$game_id}", $state, 3600 );
            wp_send_json_success( [
                'game_id' => $game_id,
                'state'   => [ 'player' => $player, 'dealer' => $dealer, 'status' => 'playing' ],
                'chips'   => $this->get_chips( $uid ),
            ] );
        }

        // Hit / Stand / Double
        $game_id = (int) sanitize_text_field( $_POST['game_id'] ?? 0 );
        $state   = get_transient( "fhc_bj_{$uid}_{$game_id}" );
        if ( ! $state ) wp_send_json_error( [ 'message' => 'Game expired.' ] );

        $bet  = $state['bet'];
        $deck = $state['deck'];

        if ( $move === 'hit' ) {
            $state['player'][] = array_pop( $deck );
            $state['deck']     = $deck;
            $pval = $this->bj_hand_value( $state['player'] );

            if ( $pval > 21 ) {
                $state['status'] = 'bust';
                delete_transient( "fhc_bj_{$uid}_{$game_id}" );
                $this->record_game( $uid, 'blackjack', $bet, 0 );
                wp_send_json_success( [
                    'game_id' => $game_id,
                    'state'   => $state,
                    'result'  => 'lose',
                    'payout'  => 0,
                    'wager'   => $bet,
                    'chips'   => $this->get_chips( $uid ),
                ] );
            }
            set_transient( "fhc_bj_{$uid}_{$game_id}", $state, 3600 );
            wp_send_json_success( [
                'game_id' => $game_id,
                'state'   => [ 'player' => $state['player'], 'dealer' => $state['dealer'], 'status' => 'playing' ],
                'chips'   => $this->get_chips( $uid ),
            ] );
        }

        if ( $move === 'double' ) {
            if ( $bet > $this->get_chips( $uid ) ) {
                wp_send_json_error( [ 'message' => 'Not enough chips to double.' ] );
            }
            $this->deduct_chips( $uid, $bet );
            $state['bet'] = $bet * 2;
            $bet = $state['bet'];
            $state['player'][] = array_pop( $deck );
            $state['deck'] = $deck;
            $move = 'stand'; // Force stand after double
        }

        if ( $move === 'stand' ) {
            // Dealer plays
            while ( $this->bj_hand_value( $state['dealer'] ) < 17 ) {
                $state['dealer'][] = array_pop( $state['deck'] );
            }
            $pval = $this->bj_hand_value( $state['player'] );
            $dval = $this->bj_hand_value( $state['dealer'] );

            $result = 'lose';
            $payout = 0;

            if ( $pval > 21 ) {
                $result = 'lose';
            } elseif ( $dval > 21 || $pval > $dval ) {
                $result = 'win';
                $payout = $bet * 2;
            } elseif ( $pval === $dval ) {
                $result = 'push';
                $payout = $bet;
            }

            if ( $payout > 0 ) {
                $this->add_chips( $uid, $payout );
            }
            $this->record_game( $uid, 'blackjack', $bet, $payout );

            $state['status'] = 'done';
            delete_transient( "fhc_bj_{$uid}_{$game_id}" );

            wp_send_json_success( [
                'game_id' => $game_id,
                'state'   => $state,
                'result'  => $result,
                'payout'  => $payout,
                'wager'   => $bet,
                'chips'   => $this->get_chips( $uid ),
            ] );
        }
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 5 — SLOTS (Server-side logic)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function fishotel_casino_slots_spin() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $bet = max( 1, (int) sanitize_text_field( $_POST['bet'] ?? 0 ) );
        if ( $bet > $this->get_chips( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Not enough chips.' ] );
        }

        $symbols = [ '🐠', '🐟', '🐡', '🦈', '🐙', '🦀', '🐚', '🌊', '⭐' ];
        $reels   = [
            $symbols[ wp_rand( 0, count( $symbols ) - 1 ) ],
            $symbols[ wp_rand( 0, count( $symbols ) - 1 ) ],
            $symbols[ wp_rand( 0, count( $symbols ) - 1 ) ],
        ];

        // Payout table
        $triple_payouts = [
            '⭐' => 50, '🌊' => 20, '🐙' => 15, '🦀' => 12,
            '🦈' => 10, '🐡' => 8, '🐚' => 8, '🐠' => 5, '🐟' => 5,
        ];

        $multiplier = 0;
        if ( $reels[0] === $reels[1] && $reels[1] === $reels[2] ) {
            $multiplier = $triple_payouts[ $reels[0] ] ?? 5;
        } elseif ( $reels[0] === $reels[1] || $reels[1] === $reels[2] || $reels[0] === $reels[2] ) {
            $multiplier = 2;
        }

        $payout = $bet * $multiplier;
        $this->deduct_chips( $uid, $bet );
        if ( $payout > 0 ) {
            $this->add_chips( $uid, $payout );
        }
        $this->record_game( $uid, 'slots', $bet, $payout );

        $jackpot = $this->check_jackpot( $uid, 'slots', [ 'multiplier' => $multiplier ] );

        wp_send_json_success( [
            'reels'      => $reels,
            'multiplier' => $multiplier,
            'payout'     => $payout,
            'chips'      => $this->get_chips( $uid ),
            'jackpot'    => $jackpot ?: null,
        ] );
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 6 — VIDEO POKER (Server-side logic)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    private function poker_new_deck() {
        return $this->bj_new_deck(); // same 52-card deck
    }

    private function poker_evaluate( $hand ) {
        // Sort by rank value
        $values = [];
        $rank_map = [ 'A' => 14, 'K' => 13, 'Q' => 12, 'J' => 11 ];
        foreach ( $hand as $c ) {
            $values[] = $rank_map[ $c['rank'] ] ?? (int) $c['rank'];
        }
        sort( $values );

        $suits = array_column( $hand, 'suit' );
        $is_flush    = count( array_unique( $suits ) ) === 1;
        $is_straight = ( $values[4] - $values[0] === 4 && count( array_unique( $values ) ) === 5 );
        // Ace-low straight: A,2,3,4,5
        if ( ! $is_straight && $values === [ 2, 3, 4, 5, 14 ] ) {
            $is_straight = true;
        }

        $counts = array_count_values( $values );
        arsort( $counts );
        $freq = array_values( $counts );

        // Royal flush
        if ( $is_flush && $is_straight && $values[0] === 10 ) return [ 'Royal Flush', 250 ];
        if ( $is_flush && $is_straight )                       return [ 'Straight Flush', 50 ];
        if ( $freq[0] === 4 )                                  return [ 'Four of a Kind', 25 ];
        if ( $freq[0] === 3 && $freq[1] === 2 )               return [ 'Full House', 9 ];
        if ( $is_flush )                                       return [ 'Flush', 6 ];
        if ( $is_straight )                                    return [ 'Straight', 4 ];
        if ( $freq[0] === 3 )                                  return [ 'Three of a Kind', 3 ];
        if ( $freq[0] === 2 && $freq[1] === 2 )               return [ 'Two Pair', 2 ];
        if ( $freq[0] === 2 ) {
            // Jacks or better
            $pair_val = array_search( 2, $counts );
            if ( $pair_val >= 11 ) return [ 'Jacks or Better', 1 ];
        }
        return [ 'No Win', 0 ];
    }

    public function fishotel_casino_poker_action() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $move = sanitize_text_field( $_POST['move'] ?? '' );

        if ( $move === 'deal' ) {
            $bet = max( 1, (int) sanitize_text_field( $_POST['bet'] ?? 0 ) );
            if ( $bet > $this->get_chips( $uid ) ) {
                wp_send_json_error( [ 'message' => 'Not enough chips.' ] );
            }
            $this->deduct_chips( $uid, $bet );

            $deck = $this->poker_new_deck();
            $hand = array_splice( $deck, 0, 5 );
            $game_id = wp_rand( 100000, 999999 );

            set_transient( "fhc_pk_{$uid}_{$game_id}", [
                'hand' => $hand,
                'deck' => $deck,
                'bet'  => $bet,
            ], 3600 );

            wp_send_json_success( [
                'game_id' => $game_id,
                'hand'    => $hand,
                'chips'   => $this->get_chips( $uid ),
            ] );
        }

        if ( $move === 'draw' ) {
            $game_id = (int) sanitize_text_field( $_POST['game_id'] ?? 0 );
            $state   = get_transient( "fhc_pk_{$uid}_{$game_id}" );
            if ( ! $state ) wp_send_json_error( [ 'message' => 'Game expired.' ] );

            $held = json_decode( stripslashes( $_POST['held'] ?? '[]' ), true );
            if ( ! is_array( $held ) || count( $held ) !== 5 ) {
                $held = [ false, false, false, false, false ];
            }

            $hand = $state['hand'];
            $deck = $state['deck'];
            $bet  = $state['bet'];

            // Replace non-held cards
            for ( $i = 0; $i < 5; $i++ ) {
                if ( ! $held[ $i ] ) {
                    $hand[ $i ] = array_pop( $deck );
                }
            }

            list( $hand_name, $multiplier ) = $this->poker_evaluate( $hand );
            $payout = $bet * $multiplier;

            if ( $payout > 0 ) {
                $this->add_chips( $uid, $payout );
            }
            $this->record_game( $uid, 'poker', $bet, $payout );
            delete_transient( "fhc_pk_{$uid}_{$game_id}" );

            $jackpot = $this->check_jackpot( $uid, 'poker', [ 'multiplier' => $multiplier ] );

            wp_send_json_success( [
                'hand'       => $hand,
                'hand_name'  => $hand_name,
                'multiplier' => $multiplier,
                'payout'     => $payout,
                'chips'      => $this->get_chips( $uid ),
                'jackpot'    => $jackpot ?: null,
            ] );
        }
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 7 — LEADERBOARD
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function fishotel_casino_leaderboard_data() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();

        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.ID, u.display_name,
                    CAST(mw.meta_value AS SIGNED) AS winnings,
                    ms.meta_value AS stats_raw
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} mw ON mw.user_id = u.ID AND mw.meta_key = %s
             LEFT JOIN {$wpdb->usermeta} ms ON ms.user_id = u.ID AND ms.meta_key = %s
             WHERE CAST(mw.meta_value AS SIGNED) > 0
             ORDER BY CAST(mw.meta_value AS SIGNED) DESC
             LIMIT 20",
            self::META_LEADERBOARD,
            self::META_STATS
        ) );

        $leaders = [];
        foreach ( $results as $row ) {
            $stats = maybe_unserialize( $row->stats_raw );
            $leaders[] = [
                'name'     => $row->display_name,
                'winnings' => (int) $row->winnings,
                'games'    => is_array( $stats ) ? ( $stats['games_played'] ?? 0 ) : 0,
                'is_you'   => (int) $row->ID === $uid,
            ];
        }

        // Current user's rank if not in top 20
        $your_rank     = null;
        $your_winnings = 0;
        if ( $uid ) {
            $your_winnings = (int) get_user_meta( $uid, self::META_LEADERBOARD, true );
            if ( $your_winnings > 0 ) {
                $your_rank = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) + 1 FROM {$wpdb->usermeta}
                     WHERE meta_key = %s AND CAST(meta_value AS SIGNED) > %d",
                    self::META_LEADERBOARD,
                    $your_winnings
                ) );
            }
        }

        wp_send_json_success( [
            'leaders'       => $leaders,
            'your_rank'     => $your_rank,
            'your_winnings' => $your_winnings,
        ] );
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 8 — WALLET WIDGET SHORTCODE
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function wallet_widget_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) return '';
        $uid   = get_current_user_id();
        $chips = $this->get_chips( $uid );
        $stats = $this->get_stats( $uid );
        $chip_url = plugins_url( 'assists/casino/Casino-Chip.png', FISHOTEL_PLUGIN_FILE );

        ob_start();
        ?>
        <div class="fhc-wallet-widget" style="background:linear-gradient(135deg,#1a0a2e,#16213e);padding:20px;border-radius:16px;border:2px solid rgba(201,162,39,.4);color:#fff;max-width:400px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                <img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" style="width:36px;height:36px;">
                <span style="font-size:1.5em;font-weight:700;color:#ffd700;"><?php echo number_format( $chips ); ?></span>
                <span style="color:#e8d48b;">chips</span>
            </div>
            <div style="font-size:.85em;color:#aaa;line-height:1.6;">
                Games Played: <?php echo number_format( $stats['games_played'] ); ?><br>
                Total Wagered: <?php echo number_format( $stats['total_wagered'] ); ?><br>
                Total Won: <?php echo number_format( $stats['total_won'] ); ?><br>
                Biggest Win: <?php echo number_format( $stats['biggest_win'] ); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 9 — LEADERBOARD SHORTCODE
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function leaderboard_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) return '<p>Please log in to view the leaderboard.</p>';
        $nonce   = wp_create_nonce( 'fishotel_casino_nonce' );
        $ajax_url = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <div id="fhc-lb-standalone" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax_url ); ?>"
             style="background:linear-gradient(135deg,#1a0a2e,#16213e);padding:24px;border-radius:16px;border:2px solid rgba(201,162,39,.4);color:#fff;max-width:700px;margin:0 auto;">
            <h3 style="color:#ffd700;text-align:center;margin-top:0;">Casino Leaderboard</h3>
            <div id="fhc-lb-standalone-content" style="text-align:center;color:#aaa;">Loading…</div>
        </div>
        <script>
        (function(){
            const el = document.getElementById('fhc-lb-standalone');
            const fd = new FormData();
            fd.append('action','fishotel_casino_leaderboard_data');
            fd.append('nonce', el.dataset.nonce);
            fetch(el.dataset.ajax,{method:'POST',body:fd,credentials:'same-origin'})
            .then(r=>r.json()).then(res=>{
                if(!res.success) return;
                const d=res.data;
                let h='<table style="width:100%;border-collapse:collapse;color:#e8d48b;">';
                h+='<tr style="border-bottom:2px solid rgba(201,162,39,.3);"><th style="padding:10px;text-align:left;">#</th><th style="padding:10px;text-align:left;">Player</th><th style="padding:10px;text-align:right;">Chips Won</th></tr>';
                d.leaders.forEach((p,i)=>{
                    const m=i===0?'🥇':i===1?'🥈':i===2?'🥉':(i+1);
                    const hl=p.is_you?'background:rgba(255,215,0,.1);':'';
                    h+=`<tr style="border-bottom:1px solid rgba(255,255,255,.05);${hl}"><td style="padding:10px;">${m}</td><td style="padding:10px;">${p.name}${p.is_you?' (you)':''}</td><td style="padding:10px;text-align:right;color:#ffd700;font-weight:700;">${Number(p.winnings).toLocaleString()}</td></tr>`;
                });
                h+='</table>';
                document.getElementById('fhc-lb-standalone-content').innerHTML=h;
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

} /* end class FisHotel_Casino */
