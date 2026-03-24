<?php
/**
 * FisHotel Arcade — Interactive casino building with clickable rooms,
 * sticker/badge reward system, trophy case, and daily bonus.
 *
 * Shortcodes:
 *   [fishotel_arcade]       — Casino building cutaway with 6 clickable rooms
 *   [fishotel_trophy_case]  — Collectible sticker grid (earned vs locked)
 *
 * @since 7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FisHotel_Arcade {

    /* ─── Sticker meta keys ─────────────────────────────────── */
    const META_EARNED_STICKERS   = '_fishotel_earned_stickers';
    const META_TOTAL_WINS        = '_fishotel_total_game_wins';
    const META_BLACKJACK_WINS    = '_fishotel_blackjack_wins';
    const META_ROULETTE_WINS     = '_fishotel_roulette_wins';
    const META_SLOTS_WINS        = '_fishotel_slots_wins';
    const META_POKER_WINS        = '_fishotel_poker_wins';
    const META_HIGHEST_WIN       = '_fishotel_highest_single_win';
    const META_DAILY_BONUS       = '_fishotel_last_daily_bonus';

    const DAILY_BONUS_CHIPS = 100;

    /* ─── Boot ──────────────────────────────────────────────── */
    public function __construct() {
        add_shortcode( 'fishotel_arcade',      [ $this, 'arcade_shortcode' ] );
        add_shortcode( 'fishotel_trophy_case', [ $this, 'trophy_case_shortcode' ] );

        /* AJAX */
        add_action( 'wp_ajax_fishotel_arcade_daily_bonus',    [ $this, 'ajax_daily_bonus' ] );
        add_action( 'wp_ajax_fishotel_arcade_check_stickers', [ $this, 'ajax_check_stickers' ] );
        add_action( 'wp_ajax_fishotel_arcade_shop_purchase',  [ $this, 'ajax_shop_purchase' ] );
        add_action( 'wp_ajax_fishotel_arcade_shop_items',     [ $this, 'ajax_shop_items' ] );
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  ARCADE BUILDING SHORTCODE
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function arcade_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#96885f;font-family:Oswald,sans-serif;">Please <a href="' . wp_login_url( get_permalink() ) . '">log in</a> to enter the FisHotel Arcade.</p>';
        }

        $uid       = get_current_user_id();
        $nonce     = wp_create_nonce( 'fishotel_arcade_nonce' );
        $ajax_url  = admin_url( 'admin-ajax.php' );
        $casino_nonce = wp_create_nonce( 'fishotel_casino_nonce' );

        $building_img = plugins_url( 'assists/casino/Casino.jpg', FISHOTEL_PLUGIN_FILE );
        $casino_img   = plugins_url( 'assists/casino/FisHotel-Casino.png', FISHOTEL_PLUGIN_FILE );
        $chip_url     = plugins_url( 'assists/casino/Casino-Chip.png', FISHOTEL_PLUGIN_FILE );
        $felt_url     = plugins_url( 'assists/casino/Felt-Table.jpg', FISHOTEL_PLUGIN_FILE );

        /* Casino chip balance (from FisHotel_Casino class) */
        $chips = (int) get_user_meta( $uid, '_fishotel_casino_chips', true );

        /* Daily bonus status */
        $last_bonus = get_user_meta( $uid, self::META_DAILY_BONUS, true );
        $can_claim  = empty( $last_bonus ) || ( time() - (int) $last_bonus ) >= 86400;

        /* Rooms — coordinates on 1280×720 image */
        $rooms = [
            'bar'       => [ 'label' => 'The Bar',          'x1' => 215, 'y1' => 195, 'x2' => 540, 'y2' => 345, 'game' => 'daily_bonus' ],
            'sports'    => [ 'label' => 'Sports Lounge',     'x1' => 645, 'y1' => 195, 'x2' => 1065, 'y2' => 345, 'game' => 'coming_soon' ],
            'roulette'  => [ 'label' => 'Roulette Room',     'x1' => 215, 'y1' => 370, 'x2' => 540, 'y2' => 520, 'game' => 'roulette' ],
            'blackjack' => [ 'label' => 'Blackjack Table',   'x1' => 645, 'y1' => 370, 'x2' => 1065, 'y2' => 520, 'game' => 'blackjack' ],
            'slots'     => [ 'label' => 'Slot Machines',     'x1' => 215, 'y1' => 540, 'x2' => 540, 'y2' => 680, 'game' => 'slots' ],
            'poker'     => [ 'label' => 'Poker Lounge',      'x1' => 645, 'y1' => 540, 'x2' => 1065, 'y2' => 680, 'game' => 'poker' ],
        ];

        ob_start();
        ?>
        <!-- ═══════════ FisHotel Arcade Building ═══════════ -->
        <div id="fh-arcade"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-casino-nonce="<?php echo esc_attr( $casino_nonce ); ?>"
             data-ajax="<?php echo esc_url( $ajax_url ); ?>"
             data-can-claim="<?php echo $can_claim ? '1' : '0'; ?>">

            <!-- Top Bar -->
            <div class="fh-arc-topbar">
                <img src="<?php echo esc_url( $casino_img ); ?>" alt="FisHotel Casino" class="fh-arc-logo">
                <div class="fh-arc-chips">
                    <img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" class="fh-arc-chip-icon">
                    <span id="fh-arc-chip-count"><?php echo number_format( $chips ); ?></span>
                </div>
            </div>

            <!-- Building Cutaway -->
            <div class="fh-arc-building">
                <img src="<?php echo esc_url( $building_img ); ?>" alt="FisHotel Casino Building" class="fh-arc-building-img">

                <?php foreach ( $rooms as $key => $room ) :
                    $left   = ( $room['x1'] / 1280 ) * 100;
                    $top    = ( $room['y1'] / 720 ) * 100;
                    $width  = ( ( $room['x2'] - $room['x1'] ) / 1280 ) * 100;
                    $height = ( ( $room['y2'] - $room['y1'] ) / 720 ) * 100;
                ?>
                <div class="fh-arc-room" data-room="<?php echo esc_attr( $key ); ?>" data-game="<?php echo esc_attr( $room['game'] ); ?>"
                     style="left:<?php echo $left; ?>%;top:<?php echo $top; ?>%;width:<?php echo $width; ?>%;height:<?php echo $height; ?>%;">
                    <span class="fh-arc-room-label"><?php echo esc_html( $room['label'] ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Game Modal Overlay -->
            <div id="fh-arc-overlay" class="fh-arc-overlay" style="display:none;">
                <div class="fh-arc-overlay-header">
                    <button id="fh-arc-back" class="fh-arc-btn-back">&larr; Back to Arcade</button>
                    <div class="fh-arc-chips fh-arc-chips-mini">
                        <img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" class="fh-arc-chip-icon">
                        <span class="fh-arc-chip-mirror"><?php echo number_format( $chips ); ?></span>
                    </div>
                </div>
                <div id="fh-arc-game-area"></div>
            </div>

            <!-- Prize Shop Button (floating over building) -->
            <button id="fh-arc-shop-btn" class="fh-arc-shop-btn">PRIZE SHOP</button>

            <!-- Sticker Unlock Modal -->
            <div id="fh-arc-sticker-modal" style="display:none;">
                <div class="fh-arc-sticker-modal-inner">
                    <div class="fh-arc-sticker-modal-glow"></div>
                    <h2 class="fh-arc-sticker-title">NEW STICKER UNLOCKED!</h2>
                    <div id="fh-arc-sticker-img"></div>
                    <div id="fh-arc-sticker-name"></div>
                    <button id="fh-arc-sticker-close" class="fh-arc-btn-gold">Awesome!</button>
                </div>
            </div>

            <!-- Jackpot Modal -->
            <div id="fh-arc-jackpot-modal" style="display:none;">
                <div class="fh-arc-sticker-modal-inner">
                    <div class="fh-arc-sticker-modal-glow" style="background:radial-gradient(circle,rgba(255,215,0,.4) 0%,transparent 70%);"></div>
                    <h2 class="fh-arc-sticker-title" style="color:#ffd700;">JACKPOT!</h2>
                    <div id="fh-arc-jackpot-img"></div>
                    <div id="fh-arc-jackpot-name" style="font-family:'Special Elite',cursive;font-size:1.3em;color:#f5f0e8;margin-bottom:8px;position:relative;"></div>
                    <div style="color:#96885f;font-size:.95em;margin-bottom:20px;position:relative;">Included FREE with your fish shipment!</div>
                    <button id="fh-arc-jackpot-close" class="fh-arc-btn-gold">Amazing!</button>
                </div>
            </div>

            <!-- Prize Shop Modal -->
            <div id="fh-arc-shop-modal" class="fh-arc-overlay" style="display:none;">
                <div class="fh-arc-overlay-header">
                    <button id="fh-arc-shop-back" class="fh-arc-btn-back">&larr; Back to Arcade</button>
                    <div class="fh-arc-chips fh-arc-chips-mini">
                        <img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" class="fh-arc-chip-icon">
                        <span class="fh-arc-chip-mirror"><?php echo number_format( $chips ); ?></span>
                    </div>
                </div>
                <div style="max-width:900px;margin:0 auto;">
                    <div style="text-align:center;margin-bottom:24px;">
                        <h2 style="font-family:'Oswald',sans-serif;color:#96885f;font-size:2em;text-transform:uppercase;letter-spacing:3px;margin:0;">FISHOTEL PRIZE SHOP</h2>
                        <p style="color:#aaa;font-family:'Special Elite',cursive;">Spend your chips on real sticker prizes — included with your fish shipment!</p>
                    </div>
                    <div id="fh-arc-shop-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px;padding:0 16px;"></div>
                    <div id="fh-arc-shop-empty" style="display:none;text-align:center;color:#888;padding:40px;">No prizes available right now. Check back soon!</div>
                </div>
            </div>
        </div>

        <style>
        /* ─── Arcade: Global ─── */
        @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Special+Elite&display=swap');
        #fh-arcade{font-family:'Segoe UI',system-ui,sans-serif;color:#fff;max-width:1000px;margin:0 auto;position:relative}

        /* ─── Top Bar ─── */
        .fh-arc-topbar{background:linear-gradient(135deg,#1a3a5c 0%,#0f2a44 100%);padding:14px 24px;border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:space-between}
        .fh-arc-logo{height:44px;filter:drop-shadow(0 2px 8px rgba(150,136,95,.5))}
        .fh-arc-chips{display:flex;align-items:center;gap:8px;background:rgba(0,0,0,.4);padding:8px 18px;border-radius:40px;border:2px solid #96885f}
        .fh-arc-chip-icon{width:26px;height:26px}
        #fh-arc-chip-count,.fh-arc-chip-mirror{font-size:1.3em;font-weight:700;color:#96885f;text-shadow:0 0 8px rgba(150,136,95,.4)}
        .fh-arc-chips-mini{padding:6px 14px}
        .fh-arc-chips-mini .fh-arc-chip-icon{width:20px;height:20px}

        /* ─── Building Cutaway ─── */
        .fh-arc-building{position:relative;width:100%;aspect-ratio:1280/720;overflow:hidden;border-radius:0 0 12px 12px;background:#1a1a1a}
        .fh-arc-building-img{width:100%;height:100%;object-fit:cover;display:block}

        /* ─── Room Hotspots ─── */
        .fh-arc-room{position:absolute;cursor:pointer;border:2px solid transparent;border-radius:6px;transition:all .3s ease;display:flex;align-items:flex-end;justify-content:center;padding-bottom:6px;box-sizing:border-box}
        .fh-arc-room:hover{border-color:rgba(150,136,95,.7);box-shadow:inset 0 0 30px rgba(150,136,95,.25),0 0 20px rgba(150,136,95,.3);background:rgba(150,136,95,.08)}
        .fh-arc-room:active{transform:scale(0.98)}
        .fh-arc-room-label{font-family:'Oswald',sans-serif;font-size:clamp(10px,1.3vw,16px);font-weight:600;color:#f5f0e8;text-transform:uppercase;letter-spacing:1.5px;text-shadow:0 2px 6px rgba(0,0,0,.9),0 0 12px rgba(0,0,0,.6);opacity:0;transition:opacity .3s ease;pointer-events:none}
        .fh-arc-room:hover .fh-arc-room-label{opacity:1}

        /* ─── Game Overlay ─── */
        .fh-arc-overlay{position:fixed;inset:0;z-index:99999;background:linear-gradient(135deg,#1a1a1a 0%,#1a3a5c 100%);overflow-y:auto;padding:20px}
        .fh-arc-overlay-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:0 10px}
        .fh-arc-btn-back{padding:10px 20px;border:1px solid rgba(150,136,95,.5);border-radius:10px;background:rgba(255,255,255,.08);color:#96885f;font-weight:600;cursor:pointer;font-size:.95em;transition:all .2s}
        .fh-arc-btn-back:hover{background:rgba(255,255,255,.15);border-color:#96885f}
        .fh-arc-btn-gold{background:linear-gradient(135deg,#96885f,#c8a84b);color:#1a1a1a;font-weight:700;padding:12px 32px;border:none;border-radius:10px;cursor:pointer;font-size:1em;transition:all .2s}
        .fh-arc-btn-gold:hover{filter:brightness(1.15);transform:translateY(-1px)}
        #fh-arc-game-area{max-width:900px;margin:0 auto}

        /* ─── Sticker Unlock Modal ─── */
        #fh-arc-sticker-modal{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center}
        .fh-arc-sticker-modal-inner{text-align:center;padding:40px;max-width:400px;position:relative}
        .fh-arc-sticker-modal-glow{position:absolute;inset:-40px;background:radial-gradient(circle,rgba(150,136,95,.3) 0%,transparent 70%);animation:fh-arc-glow-pulse 2s ease-in-out infinite}
        @keyframes fh-arc-glow-pulse{0%,100%{opacity:.6;transform:scale(1)}50%{opacity:1;transform:scale(1.05)}}
        .fh-arc-sticker-title{font-family:'Oswald',sans-serif;font-size:2em;color:#96885f;text-transform:uppercase;letter-spacing:3px;margin-bottom:20px;text-shadow:0 0 20px rgba(150,136,95,.5);position:relative}
        #fh-arc-sticker-img img{width:120px;height:120px;object-fit:contain;filter:drop-shadow(0 4px 16px rgba(150,136,95,.6));margin-bottom:16px}
        #fh-arc-sticker-name{font-family:'Special Elite',cursive;font-size:1.3em;color:#f5f0e8;margin-bottom:24px;position:relative}

        /* ─── Daily Bonus Room ─── */
        .fh-arc-daily{text-align:center;padding:60px 20px}
        .fh-arc-daily h2{font-family:'Oswald',sans-serif;color:#96885f;font-size:2em;text-transform:uppercase;letter-spacing:2px}
        .fh-arc-daily p{color:#f5f0e8;font-size:1.1em;margin:16px 0}
        .fh-arc-daily-claimed{color:#96885f;font-family:'Special Elite',cursive;font-size:1.2em}

        /* ─── Coming Soon ─── */
        .fh-arc-coming-soon{text-align:center;padding:80px 20px}
        .fh-arc-coming-soon h2{font-family:'Oswald',sans-serif;color:#96885f;font-size:2em;text-transform:uppercase;letter-spacing:2px}
        .fh-arc-coming-soon p{color:#aaa;font-size:1.1em;margin-top:12px}

        /* ─── Prize Shop Button ─── */
        .fh-arc-shop-btn{position:absolute;top:12px;right:12px;z-index:10;background:linear-gradient(135deg,#96885f,#c8a84b);color:#1a1a1a;font-family:'Oswald',sans-serif;font-weight:700;font-size:clamp(11px,1.2vw,15px);text-transform:uppercase;letter-spacing:2px;padding:10px 20px;border:none;border-radius:10px;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.5);transition:all .2s}
        .fh-arc-shop-btn:hover{filter:brightness(1.15);transform:translateY(-2px);box-shadow:0 6px 24px rgba(150,136,95,.4)}

        /* ─── Shop Card ─── */
        .fh-arc-shop-card{background:#1a1a1a;border:2px solid rgba(150,136,95,.3);border-radius:16px;padding:20px;text-align:center;transition:all .3s}
        .fh-arc-shop-card:hover{border-color:#96885f;transform:translateY(-3px)}
        .fh-arc-shop-card img{width:80px;height:80px;object-fit:contain;border-radius:8px;margin-bottom:10px}
        .fh-arc-shop-card-name{font-family:'Oswald',sans-serif;color:#f5f0e8;font-size:.95em;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px}
        .fh-arc-shop-card-price{color:#96885f;font-weight:700;font-size:1.1em;margin-bottom:4px}
        .fh-arc-shop-card-stock{color:#888;font-size:.8em;margin-bottom:12px}
        .fh-arc-shop-buy{background:linear-gradient(135deg,#96885f,#c8a84b);color:#1a1a1a;font-weight:700;padding:8px 24px;border:none;border-radius:8px;cursor:pointer;font-size:.9em;transition:all .2s}
        .fh-arc-shop-buy:hover{filter:brightness(1.15)}
        .fh-arc-shop-buy:disabled{opacity:.4;cursor:not-allowed}
        .fh-arc-shop-soldout{color:#e74c3c;font-weight:600;font-size:.85em}

        /* ─── Jackpot Modal ─── */
        #fh-arc-jackpot-modal{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.9);display:flex;align-items:center;justify-content:center}
        #fh-arc-jackpot-img img{width:120px;height:120px;object-fit:contain;filter:drop-shadow(0 4px 16px rgba(255,215,0,.6));margin-bottom:16px}

        /* ─── Mobile ─── */
        @media(max-width:640px){
            .fh-arc-topbar{padding:10px 14px}
            .fh-arc-logo{height:32px}
            .fh-arc-room-label{font-size:9px}
            .fh-arc-overlay{padding:10px}
            .fh-arc-shop-btn{padding:7px 14px;font-size:10px}
        }
        </style>

        <script>
        (function(){
            const app          = document.getElementById('fh-arcade');
            const nonce        = app.dataset.nonce;
            const casinoNonce  = app.dataset.casinoNonce;
            const ajax         = app.dataset.ajax;
            let canClaim       = app.dataset.canClaim === '1';
            let chips          = <?php echo (int) $chips; ?>;

            function updateChips(n) {
                chips = n;
                const f = Number(n).toLocaleString();
                document.getElementById('fh-arc-chip-count').textContent = f;
                document.querySelectorAll('.fh-arc-chip-mirror').forEach(el => el.textContent = f);
                /* Also update casino chip displays if they exist */
                const fhc = document.getElementById('fhc-chip-count');
                if (fhc) fhc.textContent = f;
                document.querySelectorAll('.fhc-chip-count-mirror').forEach(el => el.textContent = f);
            }

            async function postAjax(action, data={}, useNonce) {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', useNonce || nonce);
                for (const k in data) fd.append(k, data[k]);
                const r = await fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'});
                return r.json();
            }

            /* ─── Room Click → Open Game ─── */
            const overlay  = document.getElementById('fh-arc-overlay');
            const gameArea = document.getElementById('fh-arc-game-area');

            document.querySelectorAll('.fh-arc-room').forEach(room => {
                room.addEventListener('click', function() {
                    const game = this.dataset.game;
                    overlay.style.display = '';
                    gameArea.innerHTML = '<p style="text-align:center;color:#888;padding:40px;">Loading…</p>';
                    document.body.style.overflow = 'hidden';
                    openGame(game);
                });
            });

            document.getElementById('fh-arc-back').addEventListener('click', () => {
                overlay.style.display = 'none';
                gameArea.innerHTML = '';
                document.body.style.overflow = '';
            });

            function openGame(name) {
                switch(name) {
                    case 'daily_bonus': renderDailyBonus(); break;
                    case 'coming_soon': renderComingSoon(); break;
                    case 'roulette':    loadCasinoGame('roulette'); break;
                    case 'blackjack':   loadCasinoGame('blackjack'); break;
                    case 'slots':       loadCasinoGame('slots'); break;
                    case 'poker':       loadCasinoGame('poker'); break;
                }
            }

            /* ─── Daily Bonus ─── */
            function renderDailyBonus() {
                if (canClaim) {
                    gameArea.innerHTML = `
                        <div class="fh-arc-daily">
                            <h2>Welcome to the Bar!</h2>
                            <p>Grab your daily chip bonus on the house.</p>
                            <button id="fh-arc-claim-daily" class="fh-arc-btn-gold" style="font-size:1.2em;padding:16px 48px;margin-top:20px;">Claim <?php echo self::DAILY_BONUS_CHIPS; ?> Free Chips</button>
                            <div id="fh-arc-daily-msg" style="margin-top:16px;"></div>
                        </div>`;
                    document.getElementById('fh-arc-claim-daily').addEventListener('click', async function() {
                        this.disabled = true;
                        const res = await postAjax('fishotel_arcade_daily_bonus');
                        if (res.success) {
                            updateChips(res.data.chips);
                            canClaim = false;
                            document.getElementById('fh-arc-daily-msg').innerHTML = '<p class="fh-arc-daily-claimed">Here\'s your daily <?php echo self::DAILY_BONUS_CHIPS; ?> chips! Come back tomorrow for more.</p>';
                            this.style.display = 'none';
                        } else {
                            document.getElementById('fh-arc-daily-msg').innerHTML = '<p style="color:#e74c3c;">' + (res.data.message || 'Already claimed!') + '</p>';
                        }
                    });
                } else {
                    gameArea.innerHTML = `
                        <div class="fh-arc-daily">
                            <h2>Welcome to the Bar!</h2>
                            <p class="fh-arc-daily-claimed">You already grabbed your daily bonus. Come back tomorrow!</p>
                        </div>`;
                }
            }

            /* ─── Coming Soon ─── */
            function renderComingSoon() {
                gameArea.innerHTML = `
                    <div class="fh-arc-coming-soon">
                        <h2>Sports Lounge</h2>
                        <p>Draft Results & more coming soon…</p>
                        <p style="font-family:'Special Elite',cursive;color:#96885f;margin-top:24px;">Stay tuned!</p>
                    </div>`;
            }

            /* ─── Casino Games (delegates to FisHotel_Casino JS) ─── */
            function loadCasinoGame(gameName) {
                /* We inject a mini casino app container that the casino class JS can target */
                gameArea.innerHTML = `
                    <div id="fhc-casino-app" data-nonce="${casinoNonce}" data-ajax="${ajax}" style="display:none;"></div>
                    <div id="fhc-game-area-inline"></div>`;

                /* Build the casino post helper */
                const casinoApp = document.getElementById('fhc-casino-app');
                async function casinoPost(action, data={}) {
                    const fd = new FormData();
                    fd.append('action', action);
                    fd.append('nonce', casinoNonce);
                    for (const k in data) fd.append(k, data[k]);
                    const r = await fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'});
                    return r.json();
                }

                const inlineArea = document.getElementById('fhc-game-area-inline');

                /* We need to inline the game renderers from class-casino.php.
                   Rather than duplicating, we create a micro-casino bridge. */
                switch(gameName) {
                    case 'roulette':  renderArcadeRoulette(inlineArea, casinoPost); break;
                    case 'blackjack': renderArcadeBlackjack(inlineArea, casinoPost); break;
                    case 'slots':     renderArcadeSlots(inlineArea, casinoPost); break;
                    case 'poker':     renderArcadePoker(inlineArea, casinoPost); break;
                }
            }

            /* ═══════════════════════════════════════════════
             *  INLINE GAME RENDERERS (bridge to casino AJAX)
             * ═══════════════════════════════════════════════ */

            <?php echo $this->render_game_js_roulette(); ?>
            <?php echo $this->render_game_js_blackjack(); ?>
            <?php echo $this->render_game_js_slots(); ?>
            <?php echo $this->render_game_js_poker(); ?>

            /* ─── Sticker Check (after game wins) ─── */
            async function checkStickers() {
                const res = await postAjax('fishotel_arcade_check_stickers');
                if (res.success && res.data.new_stickers && res.data.new_stickers.length > 0) {
                    for (const s of res.data.new_stickers) {
                        await showStickerModal(s);
                    }
                }
            }

            function showStickerModal(sticker) {
                return new Promise(resolve => {
                    const modal = document.getElementById('fh-arc-sticker-modal');
                    document.getElementById('fh-arc-sticker-img').innerHTML = sticker.image ? '<img src="' + sticker.image + '" alt="' + sticker.name + '">' : '';
                    document.getElementById('fh-arc-sticker-name').textContent = sticker.name;
                    modal.style.display = '';
                    document.getElementById('fh-arc-sticker-close').onclick = () => {
                        modal.style.display = 'none';
                        resolve();
                    };
                });
            }

            /* ─── Jackpot Modal ─── */
            function showJackpotModal(jp) {
                return new Promise(resolve => {
                    const modal = document.getElementById('fh-arc-jackpot-modal');
                    document.getElementById('fh-arc-jackpot-img').innerHTML = jp.sticker_image ? '<img src="' + jp.sticker_image + '" alt="' + jp.sticker_name + '">' : '';
                    document.getElementById('fh-arc-jackpot-name').textContent = jp.sticker_name;
                    modal.style.display = '';
                    document.getElementById('fh-arc-jackpot-close').onclick = () => {
                        modal.style.display = 'none';
                        resolve();
                    };
                });
            }

            /* ─── Combined post-win handler: jackpot + sticker check ─── */
            async function handleWin(gameResult) {
                if (gameResult && gameResult.jackpot) {
                    await showJackpotModal(gameResult.jackpot);
                }
                await checkStickers();
            }
            window.fhArcadeCheckStickers = checkStickers;
            window.fhArcadeHandleWin = handleWin;

            /* ─── Prize Shop ─── */
            const shopModal = document.getElementById('fh-arc-shop-modal');
            const shopGrid  = document.getElementById('fh-arc-shop-grid');
            const shopEmpty = document.getElementById('fh-arc-shop-empty');

            document.getElementById('fh-arc-shop-btn').addEventListener('click', () => {
                shopModal.style.display = '';
                document.body.style.overflow = 'hidden';
                loadShop();
            });
            document.getElementById('fh-arc-shop-back').addEventListener('click', () => {
                shopModal.style.display = 'none';
                document.body.style.overflow = '';
            });

            async function loadShop() {
                shopGrid.innerHTML = '<p style="text-align:center;color:#888;padding:40px;grid-column:1/-1;">Loading...</p>';
                shopEmpty.style.display = 'none';
                const res = await postAjax('fishotel_arcade_shop_items');
                if (!res.success) return;
                const items = res.data.items;
                if (!items || items.length === 0) {
                    shopGrid.innerHTML = '';
                    shopEmpty.style.display = '';
                    return;
                }
                shopGrid.innerHTML = items.map(item => {
                    const inStock = item.stock === -1 || item.stock > 0;
                    const canBuy = inStock && chips >= item.price;
                    const stockText = item.stock === -1 ? '' : (item.stock > 0 ? item.stock + ' left' : 'SOLD OUT');
                    return `<div class="fh-arc-shop-card" data-id="${item.id}">
                        ${item.image ? '<img src="'+item.image+'" alt="'+item.name+'">' : '<div style="font-size:3em;margin-bottom:10px;">&#127942;</div>'}
                        <div class="fh-arc-shop-card-name">${item.name}</div>
                        <div class="fh-arc-shop-card-price">${item.price.toLocaleString()} chips</div>
                        ${stockText ? '<div class="fh-arc-shop-card-stock'+(item.stock===0?' fh-arc-shop-soldout':'')+'">'+stockText+'</div>' : ''}
                        ${inStock ? '<button class="fh-arc-shop-buy" data-id="'+item.id+'" data-price="'+item.price+'" '+(canBuy?'':'disabled')+'>BUY</button>' : '<div class="fh-arc-shop-soldout">SOLD OUT</div>'}
                    </div>`;
                }).join('');

                shopGrid.querySelectorAll('.fh-arc-shop-buy').forEach(btn => {
                    btn.addEventListener('click', async function() {
                        if (this.disabled) return;
                        this.disabled = true;
                        this.textContent = 'Buying...';
                        const res = await postAjax('fishotel_arcade_shop_purchase', {sticker_id: this.dataset.id});
                        if (res.success) {
                            updateChips(res.data.chips);
                            this.textContent = 'PURCHASED!';
                            this.style.background = '#27ae60';
                            this.style.color = '#fff';
                            /* Show success briefly then reload shop */
                            setTimeout(() => loadShop(), 1500);
                        } else {
                            this.textContent = res.data.message || 'Error';
                            this.style.background = '#e74c3c';
                            this.style.color = '#fff';
                            setTimeout(() => { this.textContent = 'BUY'; this.style.background = ''; this.style.color = ''; this.disabled = false; }, 2000);
                        }
                    });
                });
            }

        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  INLINE GAME JS RENDERERS
     *  These output JS functions that replicate the casino
     *  game UIs but within the arcade overlay, using the
     *  same server-side AJAX endpoints from FisHotel_Casino.
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    private function render_game_js_roulette() {
        return <<<'JS'
            function renderArcadeRoulette(area, post) {
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
                let bet = 50, betType = 'red', spinning = false, wheelRotation = 0;

                area.innerHTML = `
                    <div style="text-align:center;font-family:'Oswald',sans-serif;font-size:1.8em;color:#96885f;margin-bottom:16px;text-transform:uppercase;letter-spacing:2px;">Roulette</div>
                    <div class="fhc-table" style="text-align:center;">
                        <canvas id="fh-arc-roul-wheel" width="340" height="340" style="margin:0 auto;display:block;max-width:100%;"></canvas>
                        <div id="fh-arc-roul-result" class="fhc-result" style="margin-top:16px;"></div>
                        <div style="margin:16px 0;"><label style="color:#96885f;font-weight:600;">Bet Amount:</label>
                            <div class="fhc-bet-controls" id="fh-arc-roul-bet-amt">
                                <button class="fhc-bet-btn" data-amt="10">10</button>
                                <button class="fhc-bet-btn active" data-amt="50">50</button>
                                <button class="fhc-bet-btn" data-amt="100">100</button>
                                <button class="fhc-bet-btn" data-amt="250">250</button>
                                <button class="fhc-bet-btn" data-amt="500">500</button>
                            </div>
                        </div>
                        <div style="margin:16px 0;"><label style="color:#96885f;font-weight:600;">Bet Type:</label>
                            <div class="fhc-bet-controls" id="fh-arc-roul-bet-type">
                                <button class="fhc-bet-btn active" data-type="red" style="color:#e74c3c;">Red</button>
                                <button class="fhc-bet-btn" data-type="black" style="color:#ccc;">Black</button>
                                <button class="fhc-bet-btn" data-type="odd">Odd</button>
                                <button class="fhc-bet-btn" data-type="even">Even</button>
                            </div>
                        </div>
                        <button id="fh-arc-roul-spin" class="fh-arc-btn-gold" style="font-size:1.2em;padding:14px 48px;margin:16px 0;">SPIN</button>
                    </div>`;

                /* Inject shared casino game styles */
                if (!document.getElementById('fhc-shared-styles')) {
                    const ss = document.createElement('style');
                    ss.id = 'fhc-shared-styles';
                    ss.textContent = `.fhc-table{background:rgba(0,60,0,.4);border:8px solid #5c3a1e;border-radius:24px;padding:30px;box-shadow:inset 0 0 60px rgba(0,0,0,.4),0 8px 32px rgba(0,0,0,.5)}
                    .fhc-bet-controls{display:flex;align-items:center;justify-content:center;gap:12px;margin:16px 0;flex-wrap:wrap}
                    .fhc-bet-btn{background:rgba(255,255,255,.08);border:1px solid rgba(150,136,95,.4);color:#96885f;padding:8px 16px;border-radius:8px;cursor:pointer;font-weight:600;transition:all .2s}
                    .fhc-bet-btn:hover,.fhc-bet-btn.active{background:#96885f;color:#1a1a1a}
                    .fhc-result{text-align:center;font-size:1.3em;font-weight:700;padding:16px;margin:16px 0;border-radius:12px;min-height:52px}
                    .fhc-result.win{background:rgba(46,204,113,.15);color:#2ecc71;border:1px solid rgba(46,204,113,.3)}
                    .fhc-result.lose{background:rgba(231,76,60,.15);color:#e74c3c;border:1px solid rgba(231,76,60,.3)}
                    .fhc-result.push{background:rgba(241,196,15,.15);color:#f1c40f;border:1px solid rgba(241,196,15,.3)}
                    .fhc-card-row{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;min-height:90px;align-items:center}
                    .fhc-card{width:60px;height:88px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1em;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,.4)}
                    .fhc-card-red{background:#fff;color:#c0392b;border:2px solid #ddd}
                    .fhc-card-black{background:#fff;color:#2c3e50;border:2px solid #ddd}
                    .fhc-card-back{background:linear-gradient(135deg,#1a3a5c,#0f2a44);border:2px solid #96885f;color:#96885f;font-size:.7em}
                    .fhc-reel{width:100px;height:100px;background:rgba(0,0,0,.5);border:3px solid #96885f;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:3em;box-shadow:inset 0 0 20px rgba(0,0,0,.5)}
                    .fhc-reel.spinning{animation:fhc-reel-spin .15s infinite}
                    @keyframes fhc-reel-spin{0%{transform:translateY(-4px)}50%{transform:translateY(4px)}100%{transform:translateY(-4px)}}
                    .fhc-pk-card{width:72px;height:104px;border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:1.1em;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,.4);cursor:pointer;transition:transform .2s;position:relative}
                    .fhc-pk-card.held{transform:translateY(-14px);box-shadow:0 8px 24px rgba(150,136,95,.4);border-color:#96885f !important}
                    .fhc-pk-card .held-tag{position:absolute;top:-10px;background:#96885f;color:#1a1a1a;font-size:.6em;padding:2px 8px;border-radius:6px;display:none}
                    .fhc-pk-card.held .held-tag{display:block}`;
                    document.head.appendChild(ss);
                }

                const canvas = document.getElementById('fh-arc-roul-wheel');
                const ctx = canvas.getContext('2d');

                function drawWheel(rotation) {
                    const cx=canvas.width/2, cy=canvas.height/2, r=155;
                    ctx.clearRect(0,0,canvas.width,canvas.height);
                    ctx.save(); ctx.translate(cx,cy); ctx.rotate(rotation*Math.PI/180);
                    for(let i=0;i<SEGMENTS;i++){
                        const start=(i*SEG_ANGLE-90)*Math.PI/180, end=((i+1)*SEG_ANGLE-90)*Math.PI/180;
                        ctx.beginPath(); ctx.moveTo(0,0); ctx.arc(0,0,r,start,end); ctx.closePath();
                        ctx.fillStyle=NUMBERS[i].c==='red'?'#c0392b':NUMBERS[i].c==='black'?'#2c3e50':'#27ae60';
                        ctx.fill(); ctx.strokeStyle='#96885f'; ctx.lineWidth=1; ctx.stroke();
                        const mid=(start+end)/2, tx=Math.cos(mid)*(r*.72), ty=Math.sin(mid)*(r*.72);
                        ctx.save(); ctx.translate(tx,ty); ctx.rotate(mid+Math.PI/2);
                        ctx.fillStyle='#fff'; ctx.font='bold 11px sans-serif'; ctx.textAlign='center';
                        ctx.fillText(NUMBERS[i].label||NUMBERS[i].n,0,0); ctx.restore();
                    }
                    ctx.restore();
                    ctx.beginPath(); ctx.moveTo(cx,cy-r-6); ctx.lineTo(cx-10,cy-r-24); ctx.lineTo(cx+10,cy-r-24);
                    ctx.closePath(); ctx.fillStyle='#96885f'; ctx.fill(); ctx.strokeStyle='#1a1a1a'; ctx.lineWidth=2; ctx.stroke();
                    ctx.beginPath(); ctx.arc(cx,cy,20,0,Math.PI*2); ctx.fillStyle='#1a3a5c'; ctx.fill();
                    ctx.strokeStyle='#96885f'; ctx.lineWidth=3; ctx.stroke();
                }
                drawWheel(0);

                document.querySelectorAll('#fh-arc-roul-bet-amt .fhc-bet-btn').forEach(b=>{
                    b.addEventListener('click',()=>{if(spinning)return;
                        document.querySelectorAll('#fh-arc-roul-bet-amt .fhc-bet-btn').forEach(x=>x.classList.remove('active'));
                        b.classList.add('active'); bet=parseInt(b.dataset.amt);});
                });
                document.querySelectorAll('#fh-arc-roul-bet-type .fhc-bet-btn').forEach(b=>{
                    b.addEventListener('click',()=>{if(spinning)return;
                        document.querySelectorAll('#fh-arc-roul-bet-type .fhc-bet-btn').forEach(x=>x.classList.remove('active'));
                        b.classList.add('active'); betType=b.dataset.type;});
                });

                document.getElementById('fh-arc-roul-spin').addEventListener('click', async()=>{
                    if(spinning) return;
                    if(bet>chips){document.getElementById('fh-arc-roul-result').textContent='Not enough chips!';document.getElementById('fh-arc-roul-result').className='fhc-result lose';return;}
                    spinning=true; document.getElementById('fh-arc-roul-spin').disabled=true;
                    document.getElementById('fh-arc-roul-result').textContent=''; document.getElementById('fh-arc-roul-result').className='fhc-result';
                    const res=await post('fishotel_casino_roulette_spin',{bet:bet,bet_type:betType,bet_number:''});
                    if(!res.success){document.getElementById('fh-arc-roul-result').textContent=res.data.message;document.getElementById('fh-arc-roul-result').className='fhc-result lose';spinning=false;document.getElementById('fh-arc-roul-spin').disabled=false;return;}
                    const d=res.data;
                    const winIdx=NUMBERS.findIndex(s=>s.n===d.number&&(d.label==='00'?s.label==='00':!s.label));
                    const targetAngle=winIdx*SEG_ANGLE+SEG_ANGLE/2;
                    const spins=4+Math.random()*2;
                    const totalRotation=wheelRotation+spins*360+(360-targetAngle);
                    const startTime=performance.now(), duration=4000, from=wheelRotation;
                    function easeOut(t){return 1-Math.pow(1-t,3);}
                    function animate(now){
                        let t=Math.min((now-startTime)/duration,1);
                        drawWheel(from+(totalRotation-from)*easeOut(t));
                        if(t<1){requestAnimationFrame(animate);}else{
                            wheelRotation=totalRotation%360; updateChips(d.chips);
                            const r=document.getElementById('fh-arc-roul-result');
                            if(d.payout>0){r.textContent=`${d.label||d.number} ${d.color}! Won ${d.payout.toLocaleString()} chips!`;r.className='fhc-result win';if(window.fhArcadeHandleWin)window.fhArcadeHandleWin(d);}
                            else{r.textContent=`${d.label||d.number} ${d.color}. Lost ${bet.toLocaleString()} chips.`;r.className='fhc-result lose';}
                            spinning=false; document.getElementById('fh-arc-roul-spin').disabled=false;
                        }
                    }
                    requestAnimationFrame(animate);
                });
            }
JS;
    }

    private function render_game_js_blackjack() {
        return <<<'JS'
            function renderArcadeBlackjack(area, post) {
                let bet=50, gameState=null, gameId=0;
                area.innerHTML = `
                    <div style="text-align:center;font-family:'Oswald',sans-serif;font-size:1.8em;color:#96885f;margin-bottom:16px;text-transform:uppercase;letter-spacing:2px;">Blackjack</div>
                    <div class="fhc-table">
                        <div style="text-align:center;margin-bottom:24px;"><div style="color:#96885f;font-weight:600;margin-bottom:8px;">Dealer</div>
                            <div id="fh-arc-bj-dc" class="fhc-card-row"></div><div id="fh-arc-bj-ds" style="color:#aaa;margin-top:6px;"></div></div>
                        <div id="fh-arc-bj-result" class="fhc-result"></div>
                        <div style="text-align:center;margin-top:24px;"><div style="color:#96885f;font-weight:600;margin-bottom:8px;">Your Hand</div>
                            <div id="fh-arc-bj-pc" class="fhc-card-row"></div><div id="fh-arc-bj-ps" style="color:#96885f;font-weight:700;font-size:1.2em;margin-top:6px;"></div></div>
                        <div style="text-align:center;margin-top:20px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                            <div id="fh-arc-bj-bet"><label style="color:#96885f;font-weight:600;">Bet:</label>
                                <div class="fhc-bet-controls" id="fh-arc-bj-bets">
                                    <button class="fhc-bet-btn" data-amt="10">10</button><button class="fhc-bet-btn active" data-amt="50">50</button>
                                    <button class="fhc-bet-btn" data-amt="100">100</button><button class="fhc-bet-btn" data-amt="250">250</button></div>
                                <button id="fh-arc-bj-deal" class="fh-arc-btn-gold" style="margin-top:12px;padding:12px 40px;">DEAL</button></div>
                            <div id="fh-arc-bj-play" style="display:none;">
                                <button id="fh-arc-bj-hit" class="fh-arc-btn-gold" style="padding:12px 32px;">HIT</button>
                                <button id="fh-arc-bj-stand" class="fh-arc-btn-gold" style="padding:12px 32px;">STAND</button>
                                <button id="fh-arc-bj-double" class="fh-arc-btn-gold" style="padding:12px 32px;">DOUBLE</button></div>
                            <div id="fh-arc-bj-done" style="display:none;">
                                <button id="fh-arc-bj-new" class="fh-arc-btn-gold" style="padding:12px 40px;">NEW HAND</button></div>
                        </div>
                    </div>`;

                function cardHtml(c,fd){if(fd)return'<div class="fhc-card fhc-card-back">FH</div>';const r=c.suit==='♥'||c.suit==='♦';return`<div class="fhc-card ${r?'fhc-card-red':'fhc-card-black'}">${c.rank}${c.suit}</div>`;}
                function calcHand(cards){let t=0,a=0;for(const c of cards){if(c.rank==='A'){a++;t+=11;}else if(['K','Q','J'].includes(c.rank))t+=10;else t+=parseInt(c.rank);}while(t>21&&a>0){t-=10;a--;}return t;}
                function renderHands(show){if(!gameState)return;document.getElementById('fh-arc-bj-dc').innerHTML=gameState.dealer.map((c,i)=>cardHtml(c,!show&&i===1)).join('');document.getElementById('fh-arc-bj-pc').innerHTML=gameState.player.map(c=>cardHtml(c,false)).join('');document.getElementById('fh-arc-bj-ps').textContent='Score: '+calcHand(gameState.player);document.getElementById('fh-arc-bj-ds').textContent=show?'Score: '+calcHand(gameState.dealer):'';}
                function showPhase(p){document.getElementById('fh-arc-bj-bet').style.display=p==='bet'?'':'none';document.getElementById('fh-arc-bj-play').style.display=p==='play'?'':'none';document.getElementById('fh-arc-bj-done').style.display=p==='done'?'':'none';}

                document.querySelectorAll('#fh-arc-bj-bets .fhc-bet-btn').forEach(b=>{b.addEventListener('click',()=>{document.querySelectorAll('#fh-arc-bj-bets .fhc-bet-btn').forEach(x=>x.classList.remove('active'));b.classList.add('active');bet=parseInt(b.dataset.amt);});});

                function endHand(d){const r=document.getElementById('fh-arc-bj-result');if(d.result==='blackjack'){r.textContent=`Blackjack! +${d.payout.toLocaleString()}`;r.className='fhc-result win';}else if(d.result==='win'){r.textContent=`You win! +${d.payout.toLocaleString()}`;r.className='fhc-result win';}else if(d.result==='push'){r.textContent='Push — bet returned.';r.className='fhc-result push';}else{r.textContent=`Dealer wins. -${d.wager.toLocaleString()}`;r.className='fhc-result lose';}showPhase('done');if(d.result==='blackjack'||d.result==='win'){if(window.fhArcadeHandleWin)window.fhArcadeHandleWin(d);}}

                document.getElementById('fh-arc-bj-deal').addEventListener('click',async()=>{
                    if(bet>chips){document.getElementById('fh-arc-bj-result').textContent='Not enough chips!';document.getElementById('fh-arc-bj-result').className='fhc-result lose';return;}
                    document.getElementById('fh-arc-bj-result').textContent='';document.getElementById('fh-arc-bj-result').className='fhc-result';
                    const res=await post('fishotel_casino_blackjack_action',{bet:bet,move:'deal'});if(!res.success)return;
                    gameState=res.data.state;gameId=res.data.game_id;updateChips(res.data.chips);renderHands(false);
                    if(gameState.status==='blackjack'){renderHands(true);endHand(res.data);}else{showPhase('play');}
                });

                async function doAction(move){const res=await post('fishotel_casino_blackjack_action',{game_id:gameId,move:move});if(!res.success)return;gameState=res.data.state;updateChips(res.data.chips);if(res.data.state.status==='playing'){renderHands(false);}else{renderHands(true);endHand(res.data);}}

                document.getElementById('fh-arc-bj-hit').addEventListener('click',()=>doAction('hit'));
                document.getElementById('fh-arc-bj-stand').addEventListener('click',()=>doAction('stand'));
                document.getElementById('fh-arc-bj-double').addEventListener('click',()=>doAction('double'));
                document.getElementById('fh-arc-bj-new').addEventListener('click',()=>{showPhase('bet');document.getElementById('fh-arc-bj-result').textContent='';document.getElementById('fh-arc-bj-result').className='fhc-result';document.getElementById('fh-arc-bj-pc').innerHTML='';document.getElementById('fh-arc-bj-dc').innerHTML='';document.getElementById('fh-arc-bj-ps').textContent='';document.getElementById('fh-arc-bj-ds').textContent='';gameState=null;});
            }
JS;
    }

    private function render_game_js_slots() {
        return <<<'JS'
            function renderArcadeSlots(area, post) {
                const SYMBOLS=['🐠','🐟','🐡','🦈','🐙','🦀','🐚','🌊','⭐'];
                let bet=50, spinning=false;
                area.innerHTML = `
                    <div style="text-align:center;font-family:'Oswald',sans-serif;font-size:1.8em;color:#96885f;margin-bottom:16px;text-transform:uppercase;letter-spacing:2px;">Fish Slots</div>
                    <div class="fhc-table" style="text-align:center;">
                        <div style="display:flex;justify-content:center;gap:12px;margin:30px 0;">
                            <div class="fhc-reel" id="fh-arc-reel-0">🐠</div><div class="fhc-reel" id="fh-arc-reel-1">🐟</div><div class="fhc-reel" id="fh-arc-reel-2">🦈</div></div>
                        <div id="fh-arc-slots-result" class="fhc-result"></div>
                        <div style="margin:16px 0;"><label style="color:#96885f;font-weight:600;">Bet:</label>
                            <div class="fhc-bet-controls" id="fh-arc-slots-bet">
                                <button class="fhc-bet-btn" data-amt="10">10</button><button class="fhc-bet-btn active" data-amt="50">50</button>
                                <button class="fhc-bet-btn" data-amt="100">100</button><button class="fhc-bet-btn" data-amt="250">250</button></div></div>
                        <button id="fh-arc-slots-spin" class="fh-arc-btn-gold" style="font-size:1.2em;padding:14px 48px;">PULL</button>
                        <div style="margin-top:24px;color:#888;font-size:.85em;">
                            <div style="color:#96885f;font-weight:600;margin-bottom:8px;">Payouts (multiplier × bet):</div>
                            <div>⭐⭐⭐ = 50× &nbsp; 🌊🌊🌊 = 20× &nbsp; 🐙🐙🐙 = 15×</div>
                            <div>🦀🦀🦀 = 12× &nbsp; 🦈🦈🦈 = 10× &nbsp; 🐡🐡🐡 = 8×</div>
                            <div>Any 3 match = 5× &nbsp; 2 match = 2×</div></div>
                    </div>`;

                document.querySelectorAll('#fh-arc-slots-bet .fhc-bet-btn').forEach(b=>{b.addEventListener('click',()=>{if(spinning)return;document.querySelectorAll('#fh-arc-slots-bet .fhc-bet-btn').forEach(x=>x.classList.remove('active'));b.classList.add('active');bet=parseInt(b.dataset.amt);});});

                document.getElementById('fh-arc-slots-spin').addEventListener('click',async()=>{
                    if(spinning)return;if(bet>chips){document.getElementById('fh-arc-slots-result').textContent='Not enough chips!';document.getElementById('fh-arc-slots-result').className='fhc-result lose';return;}
                    spinning=true;document.getElementById('fh-arc-slots-spin').disabled=true;
                    document.getElementById('fh-arc-slots-result').textContent='';document.getElementById('fh-arc-slots-result').className='fhc-result';
                    const reels=[0,1,2].map(i=>document.getElementById('fh-arc-reel-'+i));
                    reels.forEach(r=>r.classList.add('spinning'));
                    const si=setInterval(()=>{reels.forEach(r=>{r.textContent=SYMBOLS[Math.floor(Math.random()*SYMBOLS.length)];});},80);
                    const res=await post('fishotel_casino_slots_spin',{bet:bet});
                    if(!res.success){clearInterval(si);reels.forEach(r=>r.classList.remove('spinning'));spinning=false;document.getElementById('fh-arc-slots-spin').disabled=false;return;}
                    const d=res.data;
                    for(let i=0;i<3;i++){await new Promise(r=>setTimeout(r,600+i*500));reels[i].classList.remove('spinning');reels[i].textContent=d.reels[i];}
                    clearInterval(si); updateChips(d.chips);
                    const r=document.getElementById('fh-arc-slots-result');
                    if(d.payout>0){r.textContent=`Winner! +${d.payout.toLocaleString()} chips! (${d.multiplier}×)`;r.className='fhc-result win';if(window.fhArcadeHandleWin)window.fhArcadeHandleWin(d);}
                    else{r.textContent=`No match. -${bet.toLocaleString()} chips.`;r.className='fhc-result lose';}
                    spinning=false;document.getElementById('fh-arc-slots-spin').disabled=false;
                });
            }
JS;
    }

    private function render_game_js_poker() {
        return <<<'JS'
            function renderArcadePoker(area, post) {
                let bet=50, hand=[], held=[false,false,false,false,false], phase='bet', gameId=0;
                area.innerHTML = `
                    <div style="text-align:center;font-family:'Oswald',sans-serif;font-size:1.8em;color:#96885f;margin-bottom:16px;text-transform:uppercase;letter-spacing:2px;">Video Poker</div>
                    <div class="fhc-table" style="text-align:center;">
                        <div id="fh-arc-pk-cards" class="fhc-card-row" style="min-height:110px;gap:10px;margin:20px 0;"></div>
                        <div id="fh-arc-pk-name" style="color:#96885f;font-size:1.2em;font-weight:700;min-height:30px;"></div>
                        <div id="fh-arc-pk-result" class="fhc-result"></div>
                        <div id="fh-arc-pk-bet" style="margin:16px 0;"><label style="color:#96885f;font-weight:600;">Bet:</label>
                            <div class="fhc-bet-controls" id="fh-arc-pk-bets">
                                <button class="fhc-bet-btn" data-amt="10">10</button><button class="fhc-bet-btn active" data-amt="50">50</button>
                                <button class="fhc-bet-btn" data-amt="100">100</button><button class="fhc-bet-btn" data-amt="250">250</button></div>
                            <button id="fh-arc-pk-deal" class="fh-arc-btn-gold" style="margin-top:12px;padding:12px 40px;">DEAL</button></div>
                        <div id="fh-arc-pk-hold" style="display:none;margin:16px 0;">
                            <p style="color:#96885f;margin-bottom:12px;">Click cards to hold, then draw.</p>
                            <button id="fh-arc-pk-draw" class="fh-arc-btn-gold" style="padding:12px 40px;">DRAW</button></div>
                        <div id="fh-arc-pk-done" style="display:none;margin:16px 0;">
                            <button id="fh-arc-pk-again" class="fh-arc-btn-gold" style="padding:12px 40px;">NEW HAND</button></div>
                        <div style="margin-top:24px;color:#888;font-size:.85em;">
                            <div style="color:#96885f;font-weight:600;margin-bottom:8px;">Payouts (multiplier × bet):</div>
                            <div>Royal Flush = 250× &nbsp; Straight Flush = 50× &nbsp; 4 of a Kind = 25×</div>
                            <div>Full House = 9× &nbsp; Flush = 6× &nbsp; Straight = 4×</div>
                            <div>3 of a Kind = 3× &nbsp; Two Pair = 2× &nbsp; Jacks or Better = 1×</div></div>
                    </div>`;

                function renderCards(){
                    const el=document.getElementById('fh-arc-pk-cards');
                    el.innerHTML=hand.map((c,i)=>{const r=c.suit==='♥'||c.suit==='♦';return`<div class="fhc-pk-card ${r?'fhc-card-red':'fhc-card-black'} ${held[i]?'held':''}" data-idx="${i}"><span class="held-tag">HELD</span><span>${c.rank}</span><span>${c.suit}</span></div>`;}).join('');
                    if(phase==='hold'){el.querySelectorAll('.fhc-pk-card').forEach(card=>{card.addEventListener('click',()=>{const idx=parseInt(card.dataset.idx);held[idx]=!held[idx];card.classList.toggle('held');card.querySelector('.held-tag').style.display=held[idx]?'block':'none';});});}
                }
                function showPhase(p){phase=p;document.getElementById('fh-arc-pk-bet').style.display=p==='bet'?'':'none';document.getElementById('fh-arc-pk-hold').style.display=p==='hold'?'':'none';document.getElementById('fh-arc-pk-done').style.display=p==='done'?'':'none';}
                document.querySelectorAll('#fh-arc-pk-bets .fhc-bet-btn').forEach(b=>{b.addEventListener('click',()=>{document.querySelectorAll('#fh-arc-pk-bets .fhc-bet-btn').forEach(x=>x.classList.remove('active'));b.classList.add('active');bet=parseInt(b.dataset.amt);});});

                document.getElementById('fh-arc-pk-deal').addEventListener('click',async()=>{
                    if(bet>chips){document.getElementById('fh-arc-pk-result').textContent='Not enough chips!';document.getElementById('fh-arc-pk-result').className='fhc-result lose';return;}
                    document.getElementById('fh-arc-pk-result').textContent='';document.getElementById('fh-arc-pk-result').className='fhc-result';document.getElementById('fh-arc-pk-name').textContent='';
                    held=[false,false,false,false,false];
                    const res=await post('fishotel_casino_poker_action',{bet:bet,move:'deal'});if(!res.success)return;
                    hand=res.data.hand;gameId=res.data.game_id;updateChips(res.data.chips);renderCards();showPhase('hold');
                });

                document.getElementById('fh-arc-pk-draw').addEventListener('click',async()=>{
                    const res=await post('fishotel_casino_poker_action',{game_id:gameId,move:'draw',held:JSON.stringify(held)});if(!res.success)return;
                    hand=res.data.hand;updateChips(res.data.chips);phase='done';renderCards();
                    document.getElementById('fh-arc-pk-name').textContent=res.data.hand_name;
                    const r=document.getElementById('fh-arc-pk-result');
                    if(res.data.payout>0){r.textContent=`You win ${res.data.payout.toLocaleString()} chips! (${res.data.multiplier}×)`;r.className='fhc-result win';if(window.fhArcadeHandleWin)window.fhArcadeHandleWin(res.data);}
                    else{r.textContent=`No winning hand. -${bet.toLocaleString()} chips.`;r.className='fhc-result lose';}
                    showPhase('done');
                });

                document.getElementById('fh-arc-pk-again').addEventListener('click',()=>{hand=[];held=[false,false,false,false,false];document.getElementById('fh-arc-pk-cards').innerHTML='';document.getElementById('fh-arc-pk-result').textContent='';document.getElementById('fh-arc-pk-result').className='fhc-result';document.getElementById('fh-arc-pk-name').textContent='';showPhase('bet');});
            }
JS;
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  DAILY BONUS AJAX
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function ajax_daily_bonus() {
        check_ajax_referer( 'fishotel_arcade_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $last = get_user_meta( $uid, self::META_DAILY_BONUS, true );
        if ( ! empty( $last ) && ( time() - (int) $last ) < 86400 ) {
            wp_send_json_error( [ 'message' => 'You already claimed your daily bonus. Come back tomorrow!' ] );
        }

        update_user_meta( $uid, self::META_DAILY_BONUS, time() );

        /* Add chips via Casino class constants */
        $current = (int) get_user_meta( $uid, '_fishotel_casino_chips', true );
        update_user_meta( $uid, '_fishotel_casino_chips', $current + self::DAILY_BONUS_CHIPS );

        wp_send_json_success( [
            'chips'   => $current + self::DAILY_BONUS_CHIPS,
            'message' => 'Here\'s your daily ' . self::DAILY_BONUS_CHIPS . ' chips! Come back tomorrow for more.',
        ] );
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  PRIZE SHOP AJAX
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /** Return shop items for the frontend grid. */
    public function ajax_shop_items() {
        check_ajax_referer( 'fishotel_arcade_nonce', 'nonce' );
        if ( ! get_current_user_id() ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $stickers = get_posts( [
            'post_type'   => 'fishotel_sticker',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [ [ 'key' => '_sticker_shop_enabled', 'value' => '1' ] ],
        ] );

        $items = [];
        foreach ( $stickers as $s ) {
            $stock = (int) get_post_meta( $s->ID, '_sticker_shop_stock', true );
            $items[] = [
                'id'    => $s->ID,
                'name'  => $s->post_title,
                'image' => get_the_post_thumbnail_url( $s->ID, 'medium' ) ?: '',
                'price' => (int) get_post_meta( $s->ID, '_sticker_shop_price', true ),
                'stock' => $stock, // -1 = unlimited
            ];
        }

        wp_send_json_success( [ 'items' => $items ] );
    }

    /** Purchase a sticker from the shop. */
    public function ajax_shop_purchase() {
        check_ajax_referer( 'fishotel_arcade_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $sticker_id = (int) ( $_POST['sticker_id'] ?? 0 );
        if ( ! $sticker_id ) wp_send_json_error( [ 'message' => 'Invalid item.' ] );

        $sticker = get_post( $sticker_id );
        if ( ! $sticker || $sticker->post_type !== 'fishotel_sticker' ) {
            wp_send_json_error( [ 'message' => 'Item not found.' ] );
        }

        if ( get_post_meta( $sticker_id, '_sticker_shop_enabled', true ) !== '1' ) {
            wp_send_json_error( [ 'message' => 'Item not available in shop.' ] );
        }

        $price = (int) get_post_meta( $sticker_id, '_sticker_shop_price', true );
        $stock = (int) get_post_meta( $sticker_id, '_sticker_shop_stock', true );
        $chips = (int) get_user_meta( $uid, '_fishotel_casino_chips', true );

        if ( $chips < $price ) {
            wp_send_json_error( [ 'message' => 'Not enough chips! You need ' . number_format( $price ) . '.' ] );
        }

        if ( $stock !== -1 && $stock <= 0 ) {
            wp_send_json_error( [ 'message' => 'Sold out!' ] );
        }

        /* Deduct chips */
        update_user_meta( $uid, '_fishotel_casino_chips', $chips - $price );

        /* Decrement stock */
        if ( $stock !== -1 ) {
            update_post_meta( $sticker_id, '_sticker_shop_stock', max( 0, $stock - 1 ) );
        }

        /* Track shop revenue */
        $revenue = (int) get_post_meta( $sticker_id, '_sticker_shop_sold', true );
        update_post_meta( $sticker_id, '_sticker_shop_sold', $revenue + 1 );
        $total_rev = (int) get_post_meta( $sticker_id, '_sticker_shop_revenue', true );
        update_post_meta( $sticker_id, '_sticker_shop_revenue', $total_rev + $price );

        /* Determine current batch */
        $statuses   = get_option( 'fishotel_batch_statuses', [] );
        $batch_name = '';
        foreach ( $statuses as $name => $status ) {
            if ( $status === 'casino' ) { $batch_name = $name; break; }
        }

        /* Add to physical prizes */
        $prizes = get_user_meta( $uid, '_fishotel_physical_prizes', true );
        $prizes = is_array( $prizes ) ? $prizes : [];
        $prizes[] = [
            'sticker_id'   => $sticker_id,
            'sticker_name' => $sticker->post_title,
            'source'       => 'shop',
            'game_type'    => null,
            'earned_at'    => time(),
            'batch_name'   => $batch_name,
            'chip_cost'    => $price,
            'added_to_box' => false,
        ];
        update_user_meta( $uid, '_fishotel_physical_prizes', $prizes );

        wp_send_json_success( [
            'chips'   => $chips - $price,
            'message' => 'Purchase complete! ' . $sticker->post_title . ' will be included with your fish shipment!',
        ] );
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  STICKER / BADGE SYSTEM
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * AJAX: Check if user has earned new stickers after a game win.
     * Called from JS after each winning game result.
     */
    public function ajax_check_stickers() {
        check_ajax_referer( 'fishotel_arcade_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        /* Gather user stats from casino meta */
        $stats     = get_user_meta( $uid, '_fishotel_casino_stats', true );
        $stats     = is_array( $stats ) ? $stats : [];
        $chips     = (int) get_user_meta( $uid, '_fishotel_casino_chips', true );

        /* Map trigger types to user values */
        $user_values = [
            'total_wins'              => (int) ( $stats['games_played'] ?? 0 ),
            'blackjack_wins'          => (int) ( $stats['blackjack_hands'] ?? 0 ),
            'roulette_wins'           => (int) ( $stats['roulette_spins'] ?? 0 ),
            'slots_wins'              => (int) ( $stats['slots_spins'] ?? 0 ),
            'poker_wins'              => (int) ( $stats['poker_hands'] ?? 0 ),
            'chips_won_single_game'   => (int) ( $stats['biggest_win'] ?? 0 ),
            'total_wagered'           => (int) ( $stats['total_wagered'] ?? 0 ),
            'total_won'               => (int) ( $stats['total_won'] ?? 0 ),
            'biggest_win'             => (int) ( $stats['biggest_win'] ?? 0 ),
            'days_played'             => (int) ( $stats['days_played'] ?? 0 ),
        ];

        /* Get all sticker posts */
        $stickers = get_posts( [
            'post_type'   => 'fishotel_sticker',
            'numberposts' => -1,
            'post_status' => 'publish',
        ] );

        /* Get already earned stickers */
        $earned = get_user_meta( $uid, self::META_EARNED_STICKERS, true );
        $earned = is_array( $earned ) ? $earned : [];

        $new_stickers = [];

        foreach ( $stickers as $sticker ) {
            if ( in_array( $sticker->ID, $earned ) ) continue;

            $trigger_type  = get_post_meta( $sticker->ID, '_sticker_trigger_type', true );
            $trigger_value = (int) get_post_meta( $sticker->ID, '_sticker_trigger_value', true );

            if ( empty( $trigger_type ) || $trigger_value <= 0 ) continue;

            $user_val = $user_values[ $trigger_type ] ?? 0;

            if ( $user_val >= $trigger_value ) {
                $earned[] = $sticker->ID;
                $image    = get_the_post_thumbnail_url( $sticker->ID, 'medium' );
                $new_stickers[] = [
                    'id'    => $sticker->ID,
                    'name'  => $sticker->post_title,
                    'image' => $image ?: '',
                ];
            }
        }

        if ( ! empty( $new_stickers ) ) {
            update_user_meta( $uid, self::META_EARNED_STICKERS, $earned );
        }

        wp_send_json_success( [ 'new_stickers' => $new_stickers ] );
    }

    /**
     * Get all stickers for a user (earned + locked).
     */
    public static function get_user_sticker_data( $user_id ) {
        $stickers = get_posts( [
            'post_type'   => 'fishotel_sticker',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby'     => 'title',
            'order'       => 'ASC',
        ] );

        $earned = get_user_meta( $user_id, self::META_EARNED_STICKERS, true );
        $earned = is_array( $earned ) ? $earned : [];

        $data = [];
        foreach ( $stickers as $s ) {
            $is_earned = in_array( $s->ID, $earned );
            $data[] = [
                'id'          => $s->ID,
                'name'        => $s->post_title,
                'image'       => get_the_post_thumbnail_url( $s->ID, 'medium' ) ?: '',
                'earned'      => $is_earned,
                'trigger_type'  => get_post_meta( $s->ID, '_sticker_trigger_type', true ),
                'trigger_value' => get_post_meta( $s->ID, '_sticker_trigger_value', true ),
                'earned_date' => $is_earned ? '' : null, // Could track dates if needed
            ];
        }
        return $data;
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  TROPHY CASE SHORTCODE
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function trophy_case_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#96885f;font-family:Oswald,sans-serif;">Please <a href="' . wp_login_url( get_permalink() ) . '">log in</a> to view your trophy case.</p>';
        }

        $uid      = get_current_user_id();
        $stickers = self::get_user_sticker_data( $uid );

        /* Trigger type labels */
        $trigger_labels = [
            'total_wins'            => 'Play %d games',
            'blackjack_wins'        => 'Play %d blackjack hands',
            'roulette_wins'         => 'Play %d roulette spins',
            'slots_wins'            => 'Play %d slot spins',
            'poker_wins'            => 'Play %d poker hands',
            'chips_won_single_game' => 'Win %d chips in a single game',
            'total_wagered'         => 'Wager %d total chips',
            'total_won'             => 'Win %d total chips',
            'biggest_win'           => 'Win %d chips in one game',
            'days_played'           => 'Play on %d different days',
        ];

        $earned_count = count( array_filter( $stickers, function( $s ) { return $s['earned']; } ) );

        ob_start();
        ?>
        <div class="fh-trophy-case">
            <div class="fh-trophy-header">
                <h2>Trophy Case</h2>
                <span class="fh-trophy-count"><?php echo $earned_count; ?> / <?php echo count( $stickers ); ?> Stickers</span>
            </div>

            <?php if ( empty( $stickers ) ) : ?>
                <p style="text-align:center;color:#aaa;padding:40px;">No stickers available yet. Check back soon!</p>
            <?php else : ?>
                <div class="fh-trophy-grid">
                    <?php foreach ( $stickers as $s ) : ?>
                        <div class="fh-trophy-card <?php echo $s['earned'] ? 'fh-trophy-earned' : 'fh-trophy-locked'; ?>">
                            <div class="fh-trophy-badge">
                                <?php if ( $s['earned'] ) : ?>
                                    <span class="fh-trophy-earned-tag">EARNED</span>
                                <?php else : ?>
                                    <span class="fh-trophy-lock">&#128274;</span>
                                <?php endif; ?>
                                <?php if ( $s['image'] ) : ?>
                                    <img src="<?php echo esc_url( $s['image'] ); ?>" alt="<?php echo esc_attr( $s['name'] ); ?>">
                                <?php else : ?>
                                    <div class="fh-trophy-placeholder">&#127942;</div>
                                <?php endif; ?>
                            </div>
                            <div class="fh-trophy-name"><?php echo esc_html( $s['name'] ); ?></div>
                            <?php if ( ! $s['earned'] ) :
                                $desc = sprintf( $trigger_labels[ $s['trigger_type'] ] ?? 'Complete challenge', (int) $s['trigger_value'] );
                            ?>
                                <div class="fh-trophy-desc"><?php echo esc_html( $desc ); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            /* ─── Physical Prizes Section ─── */
            $prizes = get_user_meta( $uid, '_fishotel_physical_prizes', true );
            $prizes = is_array( $prizes ) ? $prizes : [];
            if ( ! empty( $prizes ) ) :
                $jackpot_prizes = array_filter( $prizes, function( $p ) { return $p['source'] === 'jackpot'; } );
                $shop_prizes    = array_filter( $prizes, function( $p ) { return $p['source'] === 'shop'; } );
            ?>
                <div class="fh-trophy-header" style="margin-top:40px;">
                    <h2>Physical Prizes</h2>
                    <span class="fh-trophy-count">Coming with your fish!</span>
                </div>

                <?php if ( ! empty( $jackpot_prizes ) ) : ?>
                    <h3 style="font-family:'Oswald',sans-serif;color:#ffd700;text-transform:uppercase;letter-spacing:2px;padding-left:16px;font-size:1em;">Won via Jackpot</h3>
                    <div class="fh-trophy-grid">
                        <?php foreach ( $jackpot_prizes as $p ) :
                            $img = get_the_post_thumbnail_url( $p['sticker_id'], 'medium' );
                        ?>
                            <div class="fh-trophy-card fh-trophy-earned" style="border-color:#ffd700;">
                                <div class="fh-trophy-badge">
                                    <span class="fh-trophy-earned-tag" style="background:#ffd700;">JACKPOT</span>
                                    <?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt=""><?php else : ?><div class="fh-trophy-placeholder">&#127942;</div><?php endif; ?>
                                </div>
                                <div class="fh-trophy-name"><?php echo esc_html( $p['sticker_name'] ); ?></div>
                                <div class="fh-trophy-desc" style="color:#ffd700;">FREE — <?php echo esc_html( ucfirst( $p['game_type'] ?? '' ) ); ?> jackpot</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $shop_prizes ) ) : ?>
                    <h3 style="font-family:'Oswald',sans-serif;color:#96885f;text-transform:uppercase;letter-spacing:2px;padding-left:16px;font-size:1em;margin-top:20px;">Purchased in Shop</h3>
                    <div class="fh-trophy-grid">
                        <?php foreach ( $shop_prizes as $p ) :
                            $img = get_the_post_thumbnail_url( $p['sticker_id'], 'medium' );
                        ?>
                            <div class="fh-trophy-card fh-trophy-earned">
                                <div class="fh-trophy-badge">
                                    <span class="fh-trophy-earned-tag">PURCHASED</span>
                                    <?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt=""><?php else : ?><div class="fh-trophy-placeholder">&#127942;</div><?php endif; ?>
                                </div>
                                <div class="fh-trophy-name"><?php echo esc_html( $p['sticker_name'] ); ?></div>
                                <div class="fh-trophy-desc"><?php echo number_format( $p['chip_cost'] ); ?> chips</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <style>
        @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Special+Elite&display=swap');
        .fh-trophy-case{max-width:900px;margin:0 auto;padding:30px 0}
        .fh-trophy-header{text-align:center;margin-bottom:30px}
        .fh-trophy-header h2{font-family:'Oswald',sans-serif;color:#96885f;font-size:2em;text-transform:uppercase;letter-spacing:3px;margin:0 0 8px 0}
        .fh-trophy-count{font-family:'Special Elite',cursive;color:#f5f0e8;font-size:1.1em}
        .fh-trophy-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:20px;padding:0 16px}
        .fh-trophy-card{background:#1a1a1a;border:2px solid rgba(150,136,95,.3);border-radius:16px;padding:20px;text-align:center;transition:all .3s ease}
        .fh-trophy-earned{border-color:#96885f;box-shadow:0 4px 20px rgba(150,136,95,.2)}
        .fh-trophy-earned:hover{transform:translateY(-4px);box-shadow:0 8px 30px rgba(150,136,95,.3)}
        .fh-trophy-locked{opacity:.6}
        .fh-trophy-locked img{filter:grayscale(1)}
        .fh-trophy-badge{position:relative;margin-bottom:12px}
        .fh-trophy-badge img{width:80px;height:80px;object-fit:contain;border-radius:8px}
        .fh-trophy-placeholder{font-size:3em;line-height:80px}
        .fh-trophy-earned-tag{position:absolute;top:-8px;right:-8px;background:#96885f;color:#1a1a1a;font-family:'Oswald',sans-serif;font-size:.65em;font-weight:700;padding:3px 10px;border-radius:8px;text-transform:uppercase;letter-spacing:1px;z-index:2}
        .fh-trophy-lock{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:2em;z-index:2;opacity:.7}
        .fh-trophy-name{font-family:'Oswald',sans-serif;color:#f5f0e8;font-size:1em;font-weight:600;text-transform:uppercase;letter-spacing:1px}
        .fh-trophy-desc{color:#888;font-size:.8em;margin-top:6px;font-style:italic}
        @media(max-width:480px){.fh-trophy-grid{grid-template-columns:repeat(2,1fr);gap:12px}.fh-trophy-card{padding:14px}}
        </style>
        <?php
        return ob_get_clean();
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  ADMIN PAGE — Arcade
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function render_admin_page() {
        $ctab = sanitize_text_field( $_GET['casino_tab'] ?? 'stickers' );
        $page = sanitize_text_field( $_GET['page'] ?? 'fishotel-batch-hq' );

        echo '<div style="margin-top:16px;">';
        echo '<h2 style="margin:0 0 12px 0;color:#333;">Casino Management</h2>';
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
        $tabs = [
            'stickers'  => 'Badges &amp; Prizes',
            'winners'   => 'Prize Winners',
            'inventory' => 'Shop Inventory',
            'stats'     => 'User Stats',
            'chips'     => 'Chip Balances',
        ];
        foreach ( $tabs as $slug => $label ) {
            $active = $ctab === $slug ? ' nav-tab-active' : '';
            echo '<a href="?page=' . esc_attr( $page ) . '&tab=casino&casino_tab=' . $slug . '" class="nav-tab' . $active . '">' . $label . '</a>';
        }
        echo '</nav>';

        switch ( $ctab ) {
            case 'stickers':  $this->render_admin_stickers(); break;
            case 'winners':   $this->render_admin_winners(); break;
            case 'inventory': $this->render_admin_inventory(); break;
            case 'stats':     $this->render_admin_stats(); break;
            case 'chips':     $this->render_admin_chips(); break;
        }

        echo '</div>';
    }

    private function render_admin_stickers() {
        /* Handle jackpot trigger save */
        if ( isset( $_POST['fh_jackpot_save'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'fh_jackpot_triggers' ) ) {
            $games = [ 'blackjack', 'roulette', 'slots', 'poker' ];
            $triggers = [];
            foreach ( $games as $g ) {
                $triggers[ $g ] = [
                    'enabled'      => ! empty( $_POST["jp_{$g}_enabled"] ),
                    'trigger_type' => sanitize_text_field( $_POST["jp_{$g}_type"] ?? '' ),
                    'parameters'   => [],
                ];
                /* Collect all params for this game */
                foreach ( $_POST as $k => $v ) {
                    $prefix = "jp_{$g}_param_";
                    if ( strpos( $k, $prefix ) === 0 ) {
                        $param_key = substr( $k, strlen( $prefix ) );
                        $triggers[ $g ]['parameters'][ $param_key ] = sanitize_text_field( $v );
                    }
                }
            }
            update_option( 'fishotel_jackpot_triggers', $triggers );
            echo '<div class="notice notice-success"><p>Jackpot triggers saved!</p></div>';
        }

        /* Handle sticker form submissions */
        if ( isset( $_POST['fh_sticker_action'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'fh_sticker_save' ) ) {
            $action = sanitize_text_field( $_POST['fh_sticker_action'] );

            if ( $action === 'create' || $action === 'update' ) {
                $title   = sanitize_text_field( $_POST['sticker_name'] ?? '' );
                $trigger = sanitize_text_field( $_POST['sticker_trigger_type'] ?? '' );
                $value   = max( 1, (int) ( $_POST['sticker_trigger_value'] ?? 1 ) );

                /* Shop & Jackpot fields */
                $shop_enabled    = ! empty( $_POST['sticker_shop_enabled'] ) ? '1' : '0';
                $shop_price      = max( 0, (int) ( $_POST['sticker_shop_price'] ?? 0 ) );
                $shop_stock      = (int) ( $_POST['sticker_shop_stock'] ?? -1 );
                $jackpot_enabled = ! empty( $_POST['sticker_jackpot_enabled'] ) ? '1' : '0';
                $jackpot_game    = sanitize_text_field( $_POST['sticker_jackpot_game'] ?? '' );

                if ( $action === 'create' && ! empty( $title ) ) {
                    $post_id = wp_insert_post( [
                        'post_type'   => 'fishotel_sticker',
                        'post_title'  => $title,
                        'post_status' => 'publish',
                    ] );
                    if ( $post_id ) {
                        update_post_meta( $post_id, '_sticker_trigger_type', $trigger );
                        update_post_meta( $post_id, '_sticker_trigger_value', $value );
                        update_post_meta( $post_id, '_sticker_shop_enabled', $shop_enabled );
                        update_post_meta( $post_id, '_sticker_shop_price', $shop_price );
                        update_post_meta( $post_id, '_sticker_shop_stock', $shop_stock );
                        update_post_meta( $post_id, '_sticker_jackpot_enabled', $jackpot_enabled );
                        update_post_meta( $post_id, '_sticker_jackpot_game', $jackpot_game );
                        echo '<div class="notice notice-success"><p>Sticker created!</p></div>';
                    }
                } elseif ( $action === 'update' ) {
                    $post_id = (int) ( $_POST['sticker_id'] ?? 0 );
                    if ( $post_id ) {
                        wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
                        update_post_meta( $post_id, '_sticker_trigger_type', $trigger );
                        update_post_meta( $post_id, '_sticker_trigger_value', $value );
                        update_post_meta( $post_id, '_sticker_shop_enabled', $shop_enabled );
                        update_post_meta( $post_id, '_sticker_shop_price', $shop_price );
                        update_post_meta( $post_id, '_sticker_shop_stock', $shop_stock );
                        update_post_meta( $post_id, '_sticker_jackpot_enabled', $jackpot_enabled );
                        update_post_meta( $post_id, '_sticker_jackpot_game', $jackpot_game );
                        echo '<div class="notice notice-success"><p>Sticker updated!</p></div>';
                    }
                }
            } elseif ( $action === 'delete' ) {
                $post_id = (int) ( $_POST['sticker_id'] ?? 0 );
                if ( $post_id ) {
                    wp_delete_post( $post_id, true );
                    echo '<div class="notice notice-success"><p>Sticker deleted.</p></div>';
                }
            }
        }

        $stickers = get_posts( [ 'post_type' => 'fishotel_sticker', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ] );

        $trigger_types = [
            'total_wins'            => 'Total Games Played',
            'blackjack_wins'        => 'Blackjack Hands Played',
            'roulette_wins'         => 'Roulette Spins',
            'slots_wins'            => 'Slot Machine Spins',
            'poker_wins'            => 'Poker Hands Played',
            'chips_won_single_game' => 'Biggest Single Win (chips)',
            'total_wagered'         => 'Total Chips Wagered',
            'total_won'             => 'Total Chips Won',
            'days_played'           => 'Days Played',
        ];

        ?>
        <?php
        /* ─── Jackpot Trigger Builder ─── */
        $triggers = get_option( 'fishotel_jackpot_triggers', [] );
        $defaults = [
            'blackjack' => [ 'enabled' => false, 'trigger_type' => 'win_streak', 'parameters' => [ 'streak_length' => 3 ] ],
            'roulette'  => [ 'enabled' => false, 'trigger_type' => 'specific_number', 'parameters' => [ 'number' => '0' ] ],
            'slots'     => [ 'enabled' => false, 'trigger_type' => 'multiplier_threshold', 'parameters' => [ 'multiplier' => 50 ] ],
            'poker'     => [ 'enabled' => false, 'trigger_type' => 'specific_hand', 'parameters' => [ 'hand_type' => 'royal_flush' ] ],
        ];
        foreach ( $defaults as $g => $d ) {
            if ( ! isset( $triggers[ $g ] ) ) $triggers[ $g ] = $d;
        }
        ?>
        <h3>Jackpot Trigger Settings</h3>
        <form method="post" style="background:#f0f0f0;padding:20px;border:1px solid #ccc;border-radius:8px;margin-bottom:30px;">
            <?php wp_nonce_field( 'fh_jackpot_triggers' ); ?>
            <input type="hidden" name="fh_jackpot_save" value="1">

            <?php
            $game_configs = [
                'blackjack' => [
                    'label' => 'Blackjack',
                    'types' => [
                        'win_streak'  => 'Win Streak',
                        'natural_21'  => 'Natural 21 (Specific)',
                        'chip_threshold' => 'Chip Threshold',
                        'hand_value'  => 'Specific Hand Value',
                    ],
                ],
                'roulette' => [
                    'label' => 'Roulette',
                    'types' => [
                        'specific_number'    => 'Specific Number',
                        'number_range'       => 'Number Range',
                        'same_number_streak' => 'Same Number Streak',
                        'color_streak'       => 'Color Streak',
                        'chip_threshold'     => 'Chip Threshold',
                    ],
                ],
                'slots' => [
                    'label' => 'Slots',
                    'types' => [
                        'multiplier_threshold' => 'Multiplier Threshold',
                        'specific_symbol'      => 'Specific Symbol Match',
                        'chip_threshold'       => 'Chip Threshold',
                    ],
                ],
                'poker' => [
                    'label' => 'Video Poker',
                    'types' => [
                        'specific_hand'  => 'Specific Hand Type',
                        'chip_threshold' => 'Chip Threshold',
                    ],
                ],
            ];

            foreach ( $game_configs as $g => $cfg ) :
                $t = $triggers[ $g ];
                $enabled = ! empty( $t['enabled'] );
                $cur_type = $t['trigger_type'] ?? '';
                $p = $t['parameters'] ?? [];
            ?>
            <fieldset style="border:1px solid #ddd;padding:12px 16px;margin-bottom:16px;border-radius:6px;background:#fff;">
                <legend style="font-weight:700;font-size:1.05em;padding:0 8px;"><?php echo $cfg['label']; ?> Jackpot</legend>
                <label><input type="checkbox" name="jp_<?php echo $g; ?>_enabled" value="1" <?php checked( $enabled ); ?>> Enable Jackpot</label>

                <div style="margin:10px 0 0 24px;">
                    <label>Trigger Type:
                    <select name="jp_<?php echo $g; ?>_type" class="fh-jp-type" data-game="<?php echo $g; ?>">
                        <?php foreach ( $cfg['types'] as $tk => $tl ) : ?>
                            <option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $cur_type, $tk ); ?>><?php echo esc_html( $tl ); ?></option>
                        <?php endforeach; ?>
                    </select></label>

                    <!-- Parameter panels -->
                    <div class="fh-jp-params" data-game="<?php echo $g; ?>" style="margin-top:10px;">

                    <?php if ( $g === 'blackjack' ) : ?>
                        <div class="fh-jp-panel" data-type="win_streak">
                            Win <input type="number" name="jp_blackjack_param_streak_length" value="<?php echo esc_attr( $p['streak_length'] ?? 3 ); ?>" min="2" style="width:60px;"> hands in a row
                            <span class="fh-jp-odds" style="color:#888;font-size:.85em;margin-left:8px;"></span>
                        </div>
                        <div class="fh-jp-panel" data-type="natural_21" style="display:none;">
                            <label><input type="radio" name="jp_blackjack_param_variant" value="any" <?php checked( ( $p['variant'] ?? 'any' ), 'any' ); ?>> Any natural 21 (~5%)</label><br>
                            <label><input type="radio" name="jp_blackjack_param_variant" value="suited" <?php checked( ( $p['variant'] ?? '' ), 'suited' ); ?>> Suited natural 21 (~1.25%)</label><br>
                            <label><input type="radio" name="jp_blackjack_param_variant" value="ace_of_spades" <?php checked( ( $p['variant'] ?? '' ), 'ace_of_spades' ); ?>> With Ace of Spades (~0.4%)</label>
                        </div>
                        <div class="fh-jp-panel" data-type="chip_threshold" style="display:none;">
                            Win <input type="number" name="jp_blackjack_param_threshold" value="<?php echo esc_attr( $p['threshold'] ?? 5000 ); ?>" min="1" style="width:100px;"> chips or more
                        </div>
                        <div class="fh-jp-panel" data-type="hand_value" style="display:none;">
                            Win with exactly <input type="number" name="jp_blackjack_param_target_value" value="<?php echo esc_attr( $p['target_value'] ?? 21 ); ?>" min="2" max="21" style="width:60px;">
                            using <input type="number" name="jp_blackjack_param_card_count" value="<?php echo esc_attr( $p['card_count'] ?? 5 ); ?>" min="0" style="width:60px;"> cards (0 = any)
                        </div>

                    <?php elseif ( $g === 'roulette' ) : ?>
                        <div class="fh-jp-panel" data-type="specific_number">
                            Land on number: <input type="text" name="jp_roulette_param_number" value="<?php echo esc_attr( $p['number'] ?? '00' ); ?>" style="width:60px;" placeholder="0-36 or 00">
                            <span style="color:#888;font-size:.85em;margin-left:8px;">(~2.6% per spin)</span>
                        </div>
                        <div class="fh-jp-panel" data-type="number_range" style="display:none;">
                            <select name="jp_roulette_param_range">
                                <option value="zeros" <?php selected( $p['range'] ?? '', 'zeros' ); ?>>Zeros (0/00) ~5.3%</option>
                                <option value="first" <?php selected( $p['range'] ?? '', 'first' ); ?>>1st Dozen (1-12) ~31.6%</option>
                                <option value="second" <?php selected( $p['range'] ?? '', 'second' ); ?>>2nd Dozen (13-24) ~31.6%</option>
                                <option value="third" <?php selected( $p['range'] ?? '', 'third' ); ?>>3rd Dozen (25-36) ~31.6%</option>
                            </select>
                        </div>
                        <div class="fh-jp-panel" data-type="same_number_streak" style="display:none;">
                            Same number <input type="number" name="jp_roulette_param_streak_length" value="<?php echo esc_attr( $p['streak_length'] ?? 2 ); ?>" min="2" style="width:60px;"> times in a row
                            <span style="color:#888;font-size:.85em;margin-left:8px;">(2 = ~0.07%)</span>
                        </div>
                        <div class="fh-jp-panel" data-type="color_streak" style="display:none;">
                            <select name="jp_roulette_param_color">
                                <option value="red" <?php selected( $p['color'] ?? '', 'red' ); ?>>Red</option>
                                <option value="black" <?php selected( $p['color'] ?? '', 'black' ); ?>>Black</option>
                            </select>
                            <input type="number" name="jp_roulette_param_streak_length" value="<?php echo esc_attr( $p['streak_length'] ?? 5 ); ?>" min="2" style="width:60px;"> times in a row
                        </div>
                        <div class="fh-jp-panel" data-type="chip_threshold" style="display:none;">
                            Win <input type="number" name="jp_roulette_param_threshold" value="<?php echo esc_attr( $p['threshold'] ?? 5000 ); ?>" min="1" style="width:100px;"> chips or more
                        </div>

                    <?php elseif ( $g === 'slots' ) : ?>
                        <div class="fh-jp-panel" data-type="multiplier_threshold">
                            Get <input type="number" name="jp_slots_param_multiplier" value="<?php echo esc_attr( $p['multiplier'] ?? 50 ); ?>" min="2" style="width:80px;">x or higher
                        </div>
                        <div class="fh-jp-panel" data-type="specific_symbol" style="display:none;">
                            Symbol: <select name="jp_slots_param_symbol">
                                <?php foreach ( ['⭐','🌊','🐙','🦀','🦈','🐡','🐚','🐠','🐟'] as $sym ) : ?>
                                    <option value="<?php echo $sym; ?>" <?php selected( $p['symbol'] ?? '', $sym ); ?>><?php echo $sym; ?></option>
                                <?php endforeach; ?>
                            </select>
                            × <input type="number" name="jp_slots_param_count" value="<?php echo esc_attr( $p['count'] ?? 3 ); ?>" min="2" max="3" style="width:50px;">
                        </div>
                        <div class="fh-jp-panel" data-type="chip_threshold" style="display:none;">
                            Win <input type="number" name="jp_slots_param_threshold" value="<?php echo esc_attr( $p['threshold'] ?? 5000 ); ?>" min="1" style="width:100px;"> chips or more
                        </div>

                    <?php elseif ( $g === 'poker' ) : ?>
                        <div class="fh-jp-panel" data-type="specific_hand">
                            <select name="jp_poker_param_hand_type">
                                <?php foreach ( [ 'royal_flush' => 'Royal Flush (~0.002%)', 'straight_flush' => 'Straight Flush (~0.01%)', 'four_of_a_kind' => 'Four of a Kind (~0.02%)', 'full_house' => 'Full House (~0.14%)', 'flush' => 'Flush (~0.2%)', 'straight' => 'Straight (~0.4%)' ] as $hk => $hl ) : ?>
                                    <option value="<?php echo $hk; ?>" <?php selected( $p['hand_type'] ?? '', $hk ); ?>><?php echo esc_html( $hl ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fh-jp-panel" data-type="chip_threshold" style="display:none;">
                            Win <input type="number" name="jp_poker_param_threshold" value="<?php echo esc_attr( $p['threshold'] ?? 5000 ); ?>" min="1" style="width:100px;"> chips or more
                        </div>
                    <?php endif; ?>

                    </div>
                </div>
            </fieldset>
            <?php endforeach; ?>

            <p><input type="submit" class="button button-primary" value="Save Jackpot Triggers"></p>
        </form>

        <script>
        document.querySelectorAll('.fh-jp-type').forEach(sel => {
            function showPanel() {
                const game = sel.dataset.game;
                const type = sel.value;
                document.querySelectorAll(`.fh-jp-params[data-game="${game}"] .fh-jp-panel`).forEach(p => {
                    p.style.display = p.dataset.type === type ? '' : 'none';
                });
            }
            sel.addEventListener('change', showPanel);
            showPanel();
        });
        </script>

        <hr style="margin:30px 0;">

        <h3>Create New Sticker</h3>
        <form method="post" style="background:#f9f9f9;padding:16px;border:1px solid #ddd;border-radius:8px;margin-bottom:24px;">
            <?php wp_nonce_field( 'fh_sticker_save' ); ?>
            <input type="hidden" name="fh_sticker_action" value="create">
            <table class="form-table">
                <tr><th>Name</th><td><input type="text" name="sticker_name" required style="width:300px;" placeholder="e.g., High Roller"></td></tr>
                <tr><th>Trigger Type</th><td><select name="sticker_trigger_type">
                    <?php foreach ( $trigger_types as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select></td></tr>
                <tr><th>Trigger Value</th><td><input type="number" name="sticker_trigger_value" min="1" value="10" style="width:100px;"> <em>(user must reach this number)</em></td></tr>
                <tr><th>Badge Image</th><td><em>Set via Featured Image after creating (edit the sticker post in WP admin).</em></td></tr>
                <tr><th>Available in Shop</th><td>
                    <label><input type="checkbox" name="sticker_shop_enabled" value="1"> Enable in Prize Shop</label><br>
                    <span style="margin-left:24px;">Price: <input type="number" name="sticker_shop_price" min="0" value="500" style="width:100px;"> chips</span>
                    <span style="margin-left:12px;">Stock: <input type="number" name="sticker_shop_stock" value="-1" style="width:80px;"> <em>(-1 = unlimited)</em></span>
                </td></tr>
                <tr><th>Jackpot Prize</th><td>
                    <label><input type="checkbox" name="sticker_jackpot_enabled" value="1"> Award on natural jackpot</label><br>
                    <span style="margin-left:24px;">Game: <select name="sticker_jackpot_game">
                        <option value="slots">Slots (50x+)</option>
                        <option value="blackjack">Blackjack (Natural 21)</option>
                        <option value="roulette">Roulette (00)</option>
                        <option value="poker">Poker (Royal Flush)</option>
                    </select></span>
                </td></tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Create Sticker"></p>
        </form>

        <h3>Existing Stickers (<?php echo count( $stickers ); ?>)</h3>
        <?php if ( empty( $stickers ) ) : ?>
            <p>No stickers yet. Create one above!</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>Name</th><th>Badge Trigger</th><th>Shop</th><th>Jackpot</th><th>Image</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ( $stickers as $s ) :
                    $tt = get_post_meta( $s->ID, '_sticker_trigger_type', true );
                    $tv = get_post_meta( $s->ID, '_sticker_trigger_value', true );
                    $thumb = get_the_post_thumbnail_url( $s->ID, 'thumbnail' );
                    $shop_on = get_post_meta( $s->ID, '_sticker_shop_enabled', true ) === '1';
                    $shop_price = (int) get_post_meta( $s->ID, '_sticker_shop_price', true );
                    $shop_stock = (int) get_post_meta( $s->ID, '_sticker_shop_stock', true );
                    $jp_on = get_post_meta( $s->ID, '_sticker_jackpot_enabled', true ) === '1';
                    $jp_game = get_post_meta( $s->ID, '_sticker_jackpot_game', true );
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $s->post_title ); ?></strong></td>
                        <td><?php echo $tt ? esc_html( ( $trigger_types[ $tt ] ?? $tt ) . ' (' . $tv . ')' ) : '—'; ?></td>
                        <td><?php echo $shop_on ? number_format( $shop_price ) . ' chips' . ( $shop_stock >= 0 ? ' (' . $shop_stock . ' left)' : '' ) : '—'; ?></td>
                        <td><?php echo $jp_on ? esc_html( ucfirst( $jp_game ) ) : '—'; ?></td>
                        <td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" style="width:40px;height:40px;object-fit:contain;border-radius:4px;"><?php else : ?>—<?php endif; ?></td>
                        <td>
                            <a href="<?php echo get_edit_post_link( $s->ID ); ?>" class="button button-small">Edit</a>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'fh_sticker_save' ); ?>
                                <input type="hidden" name="fh_sticker_action" value="delete">
                                <input type="hidden" name="sticker_id" value="<?php echo $s->ID; ?>">
                                <input type="submit" class="button button-small" value="Delete" onclick="return confirm('Delete this sticker?');">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /* ─── Tab 2: Prize Winners (Packing List) ─── */
    private function render_admin_winners() {
        /* Handle mark-as-added */
        if ( isset( $_POST['fh_prize_mark'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'fh_prize_mark' ) ) {
            $target_uid = (int) $_POST['prize_user_id'];
            $target_idx = (int) $_POST['prize_index'];
            $prizes = get_user_meta( $target_uid, '_fishotel_physical_prizes', true );
            if ( is_array( $prizes ) && isset( $prizes[ $target_idx ] ) ) {
                $prizes[ $target_idx ]['added_to_box'] = true;
                update_user_meta( $target_uid, '_fishotel_physical_prizes', $prizes );
            }
        }

        global $wpdb;
        $all_users = $wpdb->get_results(
            "SELECT u.ID, u.display_name, um.meta_value AS prizes_raw
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = '_fishotel_physical_prizes'
             WHERE um.meta_value != '' AND um.meta_value != 'a:0:{}'
             LIMIT 200"
        );

        $rows = [];
        foreach ( $all_users as $u ) {
            $prizes = maybe_unserialize( $u->prizes_raw );
            if ( ! is_array( $prizes ) ) continue;
            foreach ( $prizes as $idx => $p ) {
                $rows[] = array_merge( $p, [ 'user_id' => $u->ID, 'user_name' => $u->display_name, 'idx' => $idx ] );
            }
        }
        usort( $rows, function( $a, $b ) { return ( $b['earned_at'] ?? 0 ) - ( $a['earned_at'] ?? 0 ); } );

        $admin_post_url = admin_url( 'admin-post.php' );
        ?>
        <h3>Prize Winners — Packing List (<?php echo count( $rows ); ?> prizes)</h3>
        <?php if ( empty( $rows ) ) : ?>
            <p>No prizes won or purchased yet.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>User</th><th>Batch</th><th>Prize</th><th>Source</th><th>Chips</th><th>Date</th><th>Packed?</th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $r['user_name'] ); ?></td>
                        <td><?php echo esc_html( $r['batch_name'] ?? '—' ); ?></td>
                        <td><strong><?php echo esc_html( $r['sticker_name'] ); ?></strong></td>
                        <td><?php echo $r['source'] === 'jackpot'
                            ? '<span style="color:#ffd700;">JACKPOT (Free)</span>'
                            : '<span style="color:#96885f;">SHOP (' . number_format( $r['chip_cost'] ?? 0 ) . ' chips)</span>'; ?></td>
                        <td><?php echo $r['source'] === 'shop' ? number_format( $r['chip_cost'] ?? 0 ) : '0'; ?></td>
                        <td><?php echo $r['earned_at'] ? date( 'M j, Y g:ia', $r['earned_at'] ) : '—'; ?></td>
                        <td><?php if ( ! empty( $r['added_to_box'] ) ) : ?>
                            <span style="color:#27ae60;">Added</span>
                        <?php else : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'fh_prize_mark' ); ?>
                                <input type="hidden" name="fh_prize_mark" value="1">
                                <input type="hidden" name="prize_user_id" value="<?php echo $r['user_id']; ?>">
                                <input type="hidden" name="prize_index" value="<?php echo $r['idx']; ?>">
                                <input type="submit" class="button button-small" value="Mark Added">
                            </form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /* ─── Tab 3: Shop Inventory ─── */
    private function render_admin_inventory() {
        /* Handle restock */
        if ( isset( $_POST['fh_restock'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'fh_restock' ) ) {
            $sid = (int) $_POST['restock_sticker_id'];
            $add = max( 1, (int) $_POST['restock_qty'] );
            $current = (int) get_post_meta( $sid, '_sticker_shop_stock', true );
            if ( $current >= 0 ) {
                update_post_meta( $sid, '_sticker_shop_stock', $current + $add );
                echo '<div class="notice notice-success"><p>Restocked +' . $add . '!</p></div>';
            }
        }

        $stickers = get_posts( [
            'post_type'   => 'fishotel_sticker',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [ [ 'key' => '_sticker_shop_enabled', 'value' => '1' ] ],
        ] );

        $total_revenue = 0;
        $total_sold    = 0;
        $most_popular  = [ 'name' => '—', 'sold' => 0 ];

        ?>
        <h3>Shop Inventory</h3>
        <?php if ( empty( $stickers ) ) : ?>
            <p>No items enabled in the shop yet. Go to Badges &amp; Prizes tab to enable shop items.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>Sticker</th><th>Price</th><th>Stock</th><th>Sold</th><th>Revenue</th><th>Restock</th></tr></thead>
                <tbody>
                <?php foreach ( $stickers as $s ) :
                    $price   = (int) get_post_meta( $s->ID, '_sticker_shop_price', true );
                    $stock   = (int) get_post_meta( $s->ID, '_sticker_shop_stock', true );
                    $sold    = (int) get_post_meta( $s->ID, '_sticker_shop_sold', true );
                    $revenue = (int) get_post_meta( $s->ID, '_sticker_shop_revenue', true );
                    $total_revenue += $revenue;
                    $total_sold    += $sold;
                    if ( $sold > $most_popular['sold'] ) { $most_popular = [ 'name' => $s->post_title, 'sold' => $sold ]; }
                ?>
                    <tr<?php echo ( $stock >= 0 && $stock <= 3 && $stock !== -1 ) ? ' style="background:#fff3cd;"' : ''; ?>>
                        <td><strong><?php echo esc_html( $s->post_title ); ?></strong></td>
                        <td><?php echo number_format( $price ); ?> chips</td>
                        <td><?php echo $stock === -1 ? 'Unlimited' : $stock . ( $stock <= 3 ? ' <span style="color:#e74c3c;">(LOW)</span>' : '' ); ?></td>
                        <td><?php echo $sold; ?></td>
                        <td><?php echo number_format( $revenue ); ?> chips</td>
                        <td><?php if ( $stock >= 0 ) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'fh_restock' ); ?>
                                <input type="hidden" name="fh_restock" value="1">
                                <input type="hidden" name="restock_sticker_id" value="<?php echo $s->ID; ?>">
                                <input type="number" name="restock_qty" value="10" min="1" style="width:60px;">
                                <input type="submit" class="button button-small" value="Restock">
                            </form>
                        <?php else : ?>—<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:20px;padding:16px;background:#f9f9f9;border:1px solid #ddd;border-radius:8px;">
                <strong>Shop Stats:</strong>
                Total Revenue: <?php echo number_format( $total_revenue ); ?> chips |
                Total Sold: <?php echo $total_sold; ?> items |
                Most Popular: <?php echo esc_html( $most_popular['name'] ); ?> (<?php echo $most_popular['sold']; ?> sold)
            </div>
        <?php endif;
    }

    private function render_admin_stats() {
        global $wpdb;

        /* Users with most stickers earned */
        $results = $wpdb->get_results(
            "SELECT u.ID, u.display_name, um.meta_value AS stickers_raw
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = '" . self::META_EARNED_STICKERS . "'
             WHERE um.meta_value != '' AND um.meta_value != 'a:0:{}'
             ORDER BY u.display_name ASC
             LIMIT 50"
        );

        ?>
        <h3>User Sticker Leaderboard</h3>
        <?php if ( empty( $results ) ) : ?>
            <p>No users have earned stickers yet.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>#</th><th>User</th><th>Stickers Earned</th><th>Casino Stats</th></tr></thead>
                <tbody>
                <?php
                $rows = [];
                foreach ( $results as $r ) {
                    $earned = maybe_unserialize( $r->stickers_raw );
                    $count  = is_array( $earned ) ? count( $earned ) : 0;
                    if ( $count > 0 ) $rows[] = [ 'name' => $r->display_name, 'count' => $count, 'id' => $r->ID ];
                }
                usort( $rows, function( $a, $b ) { return $b['count'] - $a['count']; } );

                foreach ( $rows as $i => $row ) :
                    $stats = get_user_meta( $row['id'], '_fishotel_casino_stats', true );
                    $stats = is_array( $stats ) ? $stats : [];
                ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo esc_html( $row['name'] ); ?></td>
                        <td><strong><?php echo $row['count']; ?></strong></td>
                        <td style="font-size:.85em;color:#666;">
                            Games: <?php echo (int) ( $stats['games_played'] ?? 0 ); ?> |
                            Won: <?php echo number_format( (int) ( $stats['total_won'] ?? 0 ) ); ?> chips |
                            Biggest: <?php echo number_format( (int) ( $stats['biggest_win'] ?? 0 ) ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    private function render_admin_chips() {
        /* Handle chip adjustment */
        if ( isset( $_POST['fh_chip_action'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'fh_chip_adjust' ) ) {
            $target_user = (int) $_POST['chip_user_id'];
            $amount      = (int) $_POST['chip_amount'];
            if ( $target_user && $amount !== 0 ) {
                $current = (int) get_user_meta( $target_user, '_fishotel_casino_chips', true );
                $new_val = max( 0, $current + $amount );
                update_user_meta( $target_user, '_fishotel_casino_chips', $new_val );
                echo '<div class="notice notice-success"><p>Chips updated: ' . number_format( $current ) . ' → ' . number_format( $new_val ) . '</p></div>';
            }
        }

        global $wpdb;

        $users = $wpdb->get_results(
            "SELECT u.ID, u.display_name,
                    CAST(COALESCE(um.meta_value, '0') AS SIGNED) AS chips
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = '_fishotel_casino_chips'
             HAVING chips > 0
             ORDER BY chips DESC
             LIMIT 50"
        );

        ?>
        <h3>Chip Balances</h3>
        <form method="post" style="background:#f9f9f9;padding:16px;border:1px solid #ddd;border-radius:8px;margin-bottom:20px;">
            <?php wp_nonce_field( 'fh_chip_adjust' ); ?>
            <input type="hidden" name="fh_chip_action" value="adjust">
            <label>User ID: <input type="number" name="chip_user_id" required style="width:80px;"></label>
            <label style="margin-left:12px;">Add/Remove: <input type="number" name="chip_amount" required style="width:100px;" placeholder="+500 or -100"></label>
            <input type="submit" class="button" value="Adjust Chips" style="margin-left:12px;">
        </form>

        <?php if ( empty( $users ) ) : ?>
            <p>No users with chips yet.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>User ID</th><th>Name</th><th>Chips</th></tr></thead>
                <tbody>
                <?php foreach ( $users as $u ) : ?>
                    <tr>
                        <td><?php echo $u->ID; ?></td>
                        <td><?php echo esc_html( $u->display_name ); ?></td>
                        <td><strong><?php echo number_format( (int) $u->chips ); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

} /* end class FisHotel_Arcade */
