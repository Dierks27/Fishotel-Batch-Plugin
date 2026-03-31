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

        /* Arcade admin page & Strength Tester */
        add_action( 'admin_menu',            [ $this, 'register_arcade_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_arcade_admin_assets' ] );
        add_action( 'wp_ajax_fishotel_strength_tester_play', [ $this, 'ajax_strength_tester_play' ] );
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

        /* Draft Room — use the batch name passed from the shortcode chain */
        $draft_batch     = isset( $atts['batch_name'] ) ? $atts['batch_name'] : '';
        $draft_slug      = sanitize_title( $draft_batch );
        $draft_results   = $draft_batch ? get_option( 'fishotel_lastcall_results_' . $draft_slug, [] ) : [];
        $has_draft       = ! empty( $draft_results );
        $cardback_files  = [ 'White-Seahorse-Cardback.jpg', 'Royal-Cardback-Fish.jpg', 'Royal-Cardback-Seahorse.jpg' ];
        $cardback_idx    = $draft_batch ? abs( crc32( $draft_batch . 'cardback' ) ) % 3 : 0;
        $draft_cardback  = plugins_url( 'assists/casino/' . $cardback_files[ $cardback_idx ], FISHOTEL_PLUGIN_FILE );
        $draft_cardface  = plugins_url( 'assists/casino/FisHotel-Face-Card.png', FISHOTEL_PLUGIN_FILE );
        $draft_sounds    = plugins_url( 'assists/casino/sounds/', FISHOTEL_PLUGIN_FILE );
        $draft_script    = plugins_url( 'assists/casino/draft-reveal.js', FISHOTEL_PLUGIN_FILE ) . '?v=' . FISHOTEL_VERSION;
        $draft_nonce     = wp_create_nonce( 'fishotel_lastcall_nonce' );
        $draft_seen      = get_user_meta( $uid, 'fishotel_lastcall_seen_reveal_' . $draft_slug, true );

        /* Rooms — coordinates on 1280×720 image */
        $rooms = [
            'bar'       => [ 'label' => 'The Bar',          'x1' => 215, 'y1' => 192, 'x2' => 640, 'y2' => 347, 'game' => 'daily_bonus' ],
            'bingo'     => [ 'label' => 'Bingo Hall',        'x1' => 646, 'y1' => 193, 'x2' => 774, 'y2' => 348, 'game' => 'bingo' ],
            'sports'    => [ 'label' => 'Sports Lounge',     'x1' => 780, 'y1' => 195, 'x2' => 1072, 'y2' => 349, 'game' => 'coming_soon' ],
            'roulette'  => [ 'label' => 'Roulette Table',    'x1' => 215, 'y1' => 360, 'x2' => 534, 'y2' => 510, 'game' => 'roulette' ],
            'craps'     => [ 'label' => 'Draft Room',        'x1' => 535, 'y1' => 358, 'x2' => 739, 'y2' => 512, 'game' => 'draft' ],
            'blackjack' => [ 'label' => 'Blackjack Table',   'x1' => 741, 'y1' => 356, 'x2' => 1071, 'y2' => 512, 'game' => 'blackjack' ],
            'slots'     => [ 'label' => 'Slot Machines',     'x1' => 216, 'y1' => 525, 'x2' => 542, 'y2' => 671, 'game' => 'slots' ],
            'prizes'    => [ 'label' => 'Prize Room',        'x1' => 545, 'y1' => 525, 'x2' => 737, 'y2' => 671, 'game' => 'coming_soon' ],
            'poker'     => [ 'label' => 'Poker Table',       'x1' => 740, 'y1' => 527, 'x2' => 1068, 'y2' => 669, 'game' => 'poker' ],
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

            <!-- Prize Shop moved to a casino room (future) -->

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

            <!-- Prize Shop will be accessible via a casino room in a future update -->
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

        /* ─── Shared Buttons ─── */
        .fh-arc-overlay{position:fixed;inset:0;z-index:99999;background:linear-gradient(135deg,#1a1a1a 0%,#1a3a5c 100%);overflow-y:auto;padding:20px}
        .fh-arc-overlay-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:0 10px}
        .fh-arc-btn-back{padding:10px 20px;border:1px solid rgba(150,136,95,.5);border-radius:10px;background:rgba(255,255,255,.08);color:#96885f;font-weight:600;cursor:pointer;font-size:.95em;transition:all .2s}
        .fh-arc-btn-back:hover{background:rgba(255,255,255,.15);border-color:#96885f}
        .fh-arc-btn-gold{background:linear-gradient(135deg,#96885f,#c8a84b);color:#1a1a1a;font-weight:700;padding:12px 32px;border:none;border-radius:10px;cursor:pointer;font-size:1em;transition:all .2s}
        .fh-arc-btn-gold:hover{filter:brightness(1.15);transform:translateY(-1px)}

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

        /* (Prize Shop button removed in v8.9.2 — will be a casino room) */

        /* ─── Shop Card ─── */

        /* ─── Room Zoom + Popup (hotel-style) ─── */
        .fh-arc-zoom-backdrop{position:fixed;top:0;left:0;width:100%;height:100%;background:transparent;z-index:99;opacity:0;transition:opacity .3s ease,background .3s ease;cursor:pointer}
        .fh-arc-zoom-backdrop--visible{opacity:1}
        .fh-arc-zoom-backdrop--dimmed{background:rgba(0,0,0,.6)}
        .fh-arc-popup{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(620px,95vw);max-height:92vh;overflow-y:auto;background:#111;border:1px solid rgba(150,136,95,.35);border-radius:12px;padding:12px 16px;box-sizing:border-box;z-index:10001;opacity:0;transition:opacity 200ms ease;font-family:'Oswald',sans-serif;color:#f5f0e8;text-align:center}
        .fh-arc-popup-close{position:absolute;top:10px;right:14px;width:36px;height:36px;background:rgba(0,0,0,.6);border:2px solid rgba(150,136,95,.5);border-radius:50%;color:#ddd;font-size:20px;cursor:pointer;line-height:1;z-index:99;display:flex;align-items:center;justify-content:center;transition:all .2s}
        .fh-arc-popup-close:hover{color:#f5f0e8;background:rgba(255,255,255,.2);border-color:#ffd700}
        .fh-arc-popup-body{min-height:60px}

        /* ─── Jackpot Modal ─── */
        #fh-arc-jackpot-modal{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.9);display:flex;align-items:center;justify-content:center}
        #fh-arc-jackpot-img img{width:120px;height:120px;object-fit:contain;filter:drop-shadow(0 4px 16px rgba(255,215,0,.6));margin-bottom:16px}

        /* ─── Mobile ─── */
        @media(max-width:640px){
            .fh-arc-topbar{padding:10px 14px}
            .fh-arc-logo{height:32px}
            .fh-arc-room-label{font-size:9px}
            .fh-arc-overlay{padding:10px}
        }

        /* ═══ Casino Game Styles ═══ */
        .fhc-table{background:rgba(0,60,0,.4);border:8px solid #5c3a1e;border-radius:24px;padding:30px;box-shadow:inset 0 0 60px rgba(0,0,0,.4),0 8px 32px rgba(0,0,0,.5)}
        .fhc-bet-controls{display:flex;align-items:center;justify-content:center;gap:12px;margin:16px 0;flex-wrap:wrap}
        .fhc-bet-btn{background:rgba(255,255,255,.08);border:1px solid rgba(150,136,95,.4);color:#96885f;padding:8px 16px;border-radius:8px;cursor:pointer;font-weight:600;transition:all .2s}
        .fhc-bet-btn:hover,.fhc-bet-btn.active{background:#96885f;color:#1a1a1a}
        .fhc-result{text-align:center;font-size:1.3em;font-weight:700;padding:16px;margin:16px 0;border-radius:12px;min-height:52px}
        .fhc-result.win{background:rgba(46,204,113,.15);color:#2ecc71;border:1px solid rgba(46,204,113,.3)}
        .fhc-result.lose{background:rgba(231,76,60,.15);color:#e74c3c;border:1px solid rgba(231,76,60,.3)}
        .fhc-result.push{background:rgba(241,196,15,.15);color:#f1c40f;border:1px solid rgba(241,196,15,.3)}
        .fhc-card-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;min-height:130px;align-items:flex-end;padding:30px 0 10px}
        /* ── Visual Playing Cards ── */
        .fh-card{display:inline-block;width:80px;height:112px;background:#fff;border:2px solid #555;border-radius:8px;position:relative;margin:0 2px;box-shadow:0 3px 10px rgba(0,0,0,.4);font-family:Georgia,serif;font-weight:700;transition:transform .3s}
        .fh-card.red{color:#DC143C}.fh-card.black{color:#1a1a1a}
        .fh-card .fh-card-tl{position:absolute;top:5px;left:7px;font-size:16px;line-height:1;text-align:center}
        .fh-card .fh-card-tl .fh-card-suit-sm{font-size:14px;display:block}
        .fh-card .fh-card-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:36px}
        .fh-card .fh-card-br{position:absolute;bottom:5px;right:7px;font-size:16px;line-height:1;transform:rotate(180deg);text-align:center}
        .fh-card .fh-card-br .fh-card-suit-sm{font-size:14px;display:block}
        .fh-card.fh-card-back{background:linear-gradient(135deg,#1a3a5c 0%,#96885f 100%);border-color:#96885f}
        .fh-card.fh-card-back::after{content:'\1F420';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:40px}
        .fh-card.fh-card-held{transform:translateY(-18px)}
        .fh-card.fh-card-held::before{content:'HELD';position:absolute;top:-22px;left:50%;transform:translateX(-50%);background:#FF7F00;color:#fff;padding:2px 10px;border-radius:4px;font-size:11px;font-family:Oswald,sans-serif;white-space:nowrap;z-index:2}
        @media(max-width:480px){.fh-card{width:58px;height:82px}.fh-card .fh-card-tl,.fh-card .fh-card-br{font-size:12px}.fh-card .fh-card-tl .fh-card-suit-sm,.fh-card .fh-card-br .fh-card-suit-sm{font-size:11px}.fh-card .fh-card-center{font-size:24px}}
        /* ── Slot Machine ── */
        .fh-slot-machine{max-width:750px;margin:0 auto;background:linear-gradient(180deg,#2a1810 0%,#1a0f08 100%);border:4px solid #96885f;border-radius:20px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.5)}
        .fh-slot-title{font-family:'Special Elite',monospace;font-size:clamp(20px,5vw,28px);color:#ffd700;text-align:center;text-shadow:0 0 10px rgba(255,215,0,.6);margin-bottom:6px}
        .fh-slot-reels{display:flex;justify-content:center;gap:8px;background:#0a0a0a;padding:16px;border-radius:10px;box-shadow:inset 0 4px 10px rgba(0,0,0,.8);margin:16px 0}
        .fh-reel-window{width:110px;height:110px;overflow:hidden;background:#111;border:3px solid #444;border-radius:12px;position:relative}
        .fh-reel-window::before,.fh-reel-window::after{content:'';position:absolute;left:0;right:0;height:20px;z-index:2;pointer-events:none}
        .fh-reel-window::before{top:0;background:linear-gradient(180deg,#111 0%,transparent 100%)}
        .fh-reel-window::after{bottom:0;background:linear-gradient(0deg,#111 0%,transparent 100%)}
        .fh-reel-strip{transition:transform .1s linear}
        .fh-reel-sym{width:110px;height:110px;display:flex;align-items:center;justify-content:center;font-size:56px}
        .fh-reel-window.winning{box-shadow:0 0 20px #ffd700,inset 0 0 20px rgba(255,215,0,.2);border-color:#ffd700;animation:fh-win-pulse .4s ease-in-out 3}
        @keyframes fh-win-pulse{0%,100%{border-color:#ffd700}50%{border-color:#fff}}
        .fh-slot-spin-btn{font-family:'Oswald',sans-serif;font-size:1.4em;font-weight:700;background:linear-gradient(180deg,#FF7F00 0%,#CC6600 100%);color:#fff;border:none;border-radius:10px;padding:14px 50px;cursor:pointer;box-shadow:0 4px 10px rgba(0,0,0,.3);transition:all .2s;display:block;margin:0 auto}
        .fh-slot-spin-btn:hover{transform:translateY(-2px);box-shadow:0 6px 15px rgba(0,0,0,.4)}
        .fh-slot-spin-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
        .fh-slot-paytable{color:#888;font-size:.8em;margin-top:16px;text-align:center;line-height:1.6}
        .fh-slot-paytable strong{color:#96885f}
        /* ── Chip float ── */
        .fh-chip-float{pointer-events:none;font-weight:700;font-size:1.3em;z-index:99999;animation:fh-float-up 1.5s ease-out forwards;transform:translateX(-50%)}
        .fh-chip-float.win{color:#2ecc71}.fh-chip-float.lose{color:#e74c3c}
        @keyframes fh-float-up{0%{opacity:1;transform:translateX(-50%) translateY(0)}100%{opacity:0;transform:translateX(-50%) translateY(-60px)}}

        /* ═══ SLOT MACHINE ═══ */
        /* Container locks to cabinet's natural 784×1168 aspect ratio */
        .fh-slots{position:relative;max-width:1000px;margin:0 auto}
        .fh-slots-machine{position:relative;width:100%;aspect-ratio:784/1168}
        /* Cabinet is foreground (z:2) — fish reels show through transparent windows */
        .fh-slots-machine>img{position:absolute;top:0;left:0;width:100%;height:100%;z-index:2;pointer-events:none}
        /* Each reel is positioned with calc() from natural pixel coords (784×1168) */
        .fh-slots-rw{position:absolute;overflow:hidden;z-index:1;background:#f5f0e8;border-radius:2px}
        #fh-sw-0{left:calc(195/784*100%);top:calc(608/1168*100%);width:calc(102/784*100%);height:calc(174/1168*100%)}
        #fh-sw-1{left:calc(338/784*100%);top:calc(606/1168*100%);width:calc(102/784*100%);height:calc(174/1168*100%)}
        #fh-sw-2{left:calc(482/784*100%);top:calc(608/1168*100%);width:calc(102/784*100%);height:calc(174/1168*100%)}
        .fh-slots-rw.winning{box-shadow:0 0 18px rgba(255,215,0,.7);animation:fh-slots-glow .4s ease-in-out 3}
        @keyframes fh-slots-glow{0%,100%{box-shadow:0 0 18px rgba(255,215,0,.7)}50%{box-shadow:0 0 30px rgba(255,215,0,1)}}
        .fh-slots-strip{position:absolute;top:0;left:0;width:100%;will-change:transform}
        /* Each symbol is exactly window height so one fish fills one window */
        .fh-slots-sym{display:flex;align-items:center;justify-content:center;padding:10%;box-sizing:border-box}
        .fh-slots-sym img{width:100%;height:100%;object-fit:contain}
        /* ═══ BLUE BAR — LED Dot-Matrix Lightboard ═══ */
        .fh-slots-result{position:absolute;z-index:3;left:calc(170/784*100%);top:calc(455/1168*100%);width:calc(450/784*100%);height:calc(70/1168*100%);background:#080808;border-radius:2px;overflow:hidden;border:1px solid rgba(40,40,40,.6);box-shadow:inset 0 2px 6px rgba(0,0,0,.9)}
        .fh-slots-result canvas{width:100%;height:100%;display:block}
        /* ═══ GREEN — Payout Table (transparent overlay) ═══ */
        .fh-slots-payouts-btn{position:absolute;z-index:3;left:calc(335/784*100%);top:calc(807/1168*100%);width:calc(115/784*100%);height:calc(60/1168*100%);background:transparent;border:none;color:rgba(100,90,60,.5);cursor:pointer;border-radius:3px;font-family:'Oswald',sans-serif;font-size:clamp(7px,1.1vw,11px);letter-spacing:1px;transition:all .2s;display:flex;align-items:center;justify-content:center}
        .fh-slots-payouts-btn:hover{background:rgba(255,215,0,.06);color:rgba(150,136,95,.7)}
        /* ═══ RED — Chip Balance (dark background) ═══ */
        .fh-slots-chips{position:absolute;z-index:3;left:calc(481/784*100%);top:calc(807/1168*100%);width:calc(145/784*100%);height:calc(60/1168*100%);background:rgba(0,0,0,.75);border-radius:3px;font-family:'Oswald',sans-serif;font-size:clamp(9px,1.6vw,14px);color:#ffd700;display:flex;align-items:center;justify-content:center;gap:3px}
        /* ═══ PINK — SPIN Button ═══ */
        .fh-slots-spin{position:absolute;z-index:3;left:calc(306/784*100%);top:calc(879/1168*100%);width:calc(175/784*100%);height:calc(45/1168*100%);background:linear-gradient(180deg,rgba(200,30,30,.7),rgba(140,15,15,.7));color:#fff;border:1px solid rgba(200,30,30,.5);border-radius:4px;padding:0;font-family:'Oswald',sans-serif;font-size:clamp(11px,2vw,16px);font-weight:700;cursor:pointer;transition:all .15s;text-shadow:0 1px 3px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center}
        .fh-slots-spin:hover{background:linear-gradient(180deg,rgba(220,40,40,.9),rgba(160,20,20,.9));border-color:rgba(220,40,40,.7);box-shadow:0 0 10px rgba(220,40,40,.4)}
        .fh-slots-spin:disabled{opacity:.4;cursor:not-allowed;box-shadow:none}
        /* ═══ YELLOW — Bet Buttons (solid, glow on selected) ═══ */
        .fh-slots-bet{position:absolute;z-index:3;background:rgba(0,0,0,.6);color:#f5f0e8;border:1px solid rgba(150,136,95,.4);padding:0;border-radius:3px;cursor:pointer;font-family:'Oswald',sans-serif;font-size:clamp(9px,1.5vw,13px);font-weight:600;transition:all .2s;display:flex;align-items:center;justify-content:center;box-sizing:border-box}
        .fh-slots-bet:hover{background:rgba(0,0,0,.8);border-color:rgba(255,215,0,.5)}
        .fh-slots-bet.active{background:rgba(255,215,0,.2);border-color:#ffd700;color:#ffd700;box-shadow:0 0 12px rgba(255,215,0,.5),inset 0 0 8px rgba(255,215,0,.15)}
        /* 4 bet buttons — user-positioned, smaller to fit tighter spacing */
        #fh-bet-10{left:calc(132/784*100%);top:calc(959/1168*100%);width:calc(80/784*100%);height:calc(55/1168*100%)}
        #fh-bet-50{left:calc(219/784*100%);top:calc(959/1168*100%);width:calc(80/784*100%);height:calc(55/1168*100%)}
        #fh-bet-100{left:calc(311/784*100%);top:calc(959/1168*100%);width:calc(80/784*100%);height:calc(55/1168*100%)}
        #fh-bet-250{left:calc(400/784*100%);top:calc(959/1168*100%);width:calc(80/784*100%);height:calc(55/1168*100%)}
        /* Paytable modal */
        .fh-slots-pay-modal{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center}
        .fh-slots-pay-bd{position:absolute;inset:0;background:rgba(0,0,0,.8);cursor:pointer}
        .fh-slots-pay-card{position:relative;background:linear-gradient(135deg,#2e2418,#1a1410);border:3px solid #96885f;border-radius:14px;padding:20px 16px;max-width:600px;width:90%;box-shadow:0 16px 50px rgba(0,0,0,.6)}
        .fh-slots-pay-close{position:absolute;top:8px;right:12px;width:28px;height:28px;background:rgba(255,255,255,.08);border:2px solid rgba(150,136,95,.4);border-radius:50%;color:#96885f;font-size:16px;cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center;transition:all .2s}
        .fh-slots-pay-close:hover{color:#ffd700;background:rgba(255,255,255,.15);border-color:#ffd700}
        .fh-slots-pay-title{text-align:center;font-family:'Special Elite',monospace;font-size:clamp(14px,3.5vw,18px);color:#ffd700;margin:0 0 2px}
        .fh-slots-pay-sub{text-align:center;font-family:'Oswald',sans-serif;font-size:11px;color:#96885f;margin:0 0 10px}
        .fh-slots-pay-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px}
        .fh-slots-pay-row{display:flex;align-items:center;gap:6px;padding:5px 8px;background:rgba(0,0,0,.25);border:1px solid rgba(150,136,95,.15);border-radius:4px}
        .fh-slots-pay-syms{flex-shrink:0;display:flex;gap:2px;align-items:center}
        .fh-slots-pay-syms img{width:18px;height:18px;object-fit:contain}
        .fh-slots-pay-mult{font-family:'Oswald',sans-serif;font-size:clamp(13px,3vw,16px);font-weight:700;color:#ffd700;text-shadow:0 0 6px rgba(255,215,0,.3)}
        .fh-slots-pay-label{font-family:'Oswald',sans-serif;font-size:11px;color:#f5f0e8}
        .fh-slots-pay-footer{grid-column:1/-1;text-align:center;padding:4px 8px;background:rgba(0,0,0,.2);border:1px solid rgba(150,136,95,.15);border-radius:4px;font-family:'Oswald',sans-serif;font-size:12px;color:#96885f;margin-top:2px}
        /* (Room zoom/hotspot/interior CSS removed in v8.9 — simplified to zoom + direct popup) */

        /* ═══ SAPPHIRE POKER SLOTS ═══ */
        /* Slot selection menu */
        /* ═══ Loading Spinner ═══ */
        .fh-slot-loading{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 0;gap:16px}
        .fh-slot-spinner{width:48px;height:48px;border:4px solid rgba(150,136,95,.2);border-top:4px solid #ffd700;border-radius:50%;animation:fh-spin .8s linear infinite}
        @keyframes fh-spin{to{transform:rotate(360deg)}}
        .fh-slot-loading-text{font-family:'Oswald',sans-serif;font-size:clamp(12px,2vw,16px);color:rgba(150,136,95,.6);letter-spacing:2px}
        .fh-slot-machine-hidden{opacity:0;position:absolute;pointer-events:none}
        .fh-slot-machine-reveal{animation:fh-reveal .4s ease forwards}
        @keyframes fh-reveal{from{opacity:0;transform:scale(.98)}to{opacity:1;transform:scale(1)}}
        .fh-slot-select{display:flex;gap:16px;justify-content:center;padding:20px 0;flex-wrap:wrap}
        .fh-slot-select-card{background:rgba(0,0,0,.5);border:2px solid rgba(150,136,95,.4);border-radius:14px;padding:12px 16px;cursor:pointer;text-align:center;transition:all .25s;min-width:120px;max-width:200px}
        .fh-slot-select-card:hover{border-color:#ffd700;background:rgba(255,215,0,.08);box-shadow:0 0 18px rgba(255,215,0,.2);transform:translateY(-3px)}
        .fh-slot-select-img{width:100%;max-height:180px;object-fit:contain;margin-bottom:8px;border-radius:6px}
        .fh-slot-select-name{font-family:'Oswald',sans-serif;font-size:1em;font-weight:600;color:#f5f0e8;letter-spacing:1px;text-transform:uppercase}
        .fh-slot-select-sub{font-size:.75em;color:#96885f;margin-top:4px;font-family:'Special Elite',cursive}

        /* Container locks to cabinet's natural 784×1168 aspect ratio */
        .fh-sapphire{position:relative;max-width:1000px;margin:0 auto}
        .fh-sapphire-machine{position:relative;width:100%;aspect-ratio:784/1168}
        /* Cabinet is foreground (z:2) — card reels show through transparent windows */
        .fh-sapphire-machine>img{position:absolute;top:0;left:0;width:100%;height:100%;z-index:2;pointer-events:none}
        /* Each reel is positioned with calc() from natural pixel coords (784×1168) */
        .fh-sapphire-rw{position:absolute;overflow:hidden;z-index:1;background:#f5f0e8;border-radius:2px}
        #fh-spw-0{left:calc(148/784*100%);top:calc(516/1168*100%);width:calc(57/784*100%);height:calc(204/1168*100%)}
        #fh-spw-1{left:calc(230/784*100%);top:calc(517/1168*100%);width:calc(53/784*100%);height:calc(201/1168*100%)}
        #fh-spw-2{left:calc(308/784*100%);top:calc(517/1168*100%);width:calc(55/784*100%);height:calc(201/1168*100%)}
        #fh-spw-3{left:calc(390/784*100%);top:calc(516/1168*100%);width:calc(55/784*100%);height:calc(202/1168*100%)}
        .fh-sapphire-rw.winning{box-shadow:0 0 18px rgba(255,215,0,.7);animation:fh-sapphire-glow .4s ease-in-out 3}
        @keyframes fh-sapphire-glow{0%,100%{box-shadow:0 0 18px rgba(255,215,0,.7)}50%{box-shadow:0 0 30px rgba(255,215,0,1)}}
        .fh-sapphire-strip{position:absolute;top:0;left:0;width:100%;will-change:transform}
        /* Each symbol is exactly window height so one card fills one window */
        .fh-sapphire-sym{display:flex;align-items:center;justify-content:center;padding:2%;box-sizing:border-box}
        .fh-sapphire-sym img{width:100%;height:100%;object-fit:contain}
        /* ═══ LED Dot-Matrix Lightboard (above reels) ═══ */
        .fh-sapphire-result{position:absolute;z-index:3;left:calc(133/784*100%);top:calc(364/1168*100%);width:calc(324/784*100%);height:calc(54/1168*100%);background:#080808;border-radius:2px;overflow:hidden;border:1px solid rgba(40,40,40,.6);box-shadow:inset 0 2px 6px rgba(0,0,0,.9)}
        /* ═══ SPIN Button (round, right side) ═══ */
        .fh-sapphire-spin{position:absolute;z-index:3;left:calc(572/784*100%);top:calc(414/1168*100%);width:calc(73/784*100%);height:calc(85/1168*100%);background:linear-gradient(180deg,rgba(200,30,30,.85),rgba(140,15,15,.85));color:#fff;border:3px solid rgba(200,30,30,.6);border-radius:50%;padding:0;font-family:'Oswald',sans-serif;font-size:clamp(11px,2vw,16px);font-weight:700;cursor:pointer;transition:all .15s;text-shadow:0 1px 3px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.4),inset 0 2px 4px rgba(255,255,255,.15)}
        .fh-sapphire-spin:hover{background:linear-gradient(180deg,rgba(220,40,40,.95),rgba(160,20,20,.95));border-color:rgba(220,40,40,.8);box-shadow:0 0 16px rgba(220,40,40,.5),inset 0 2px 4px rgba(255,255,255,.15)}
        .fh-sapphire-spin:disabled{opacity:.4;cursor:not-allowed;box-shadow:none}
        /* ═══ Bet Buttons (below reels) ═══ */
        .fh-sapphire-bet{position:absolute;z-index:3;top:calc(758/1168*100%);height:calc(29/1168*100%);background:rgba(0,0,0,.6);color:#f5f0e8;border:1px solid rgba(150,136,95,.4);padding:0;border-radius:3px;cursor:pointer;font-family:'Oswald',sans-serif;font-size:clamp(9px,1.5vw,13px);font-weight:600;transition:all .2s;display:flex;align-items:center;justify-content:center;box-sizing:border-box}
        .fh-sapphire-bet:hover{background:rgba(0,0,0,.8);border-color:rgba(255,215,0,.5)}
        .fh-sapphire-bet.active{background:rgba(255,215,0,.2);border-color:#ffd700;color:#ffd700;box-shadow:0 0 12px rgba(255,215,0,.5),inset 0 0 8px rgba(255,215,0,.15)}
        #fh-spbet-10{left:calc(150/784*100%);width:calc(57/784*100%)}
        #fh-spbet-50{left:calc(228/784*100%);width:calc(56/784*100%)}
        #fh-spbet-100{left:calc(310/784*100%);width:calc(56/784*100%)}
        #fh-spbet-250{left:calc(391/784*100%);width:calc(60/784*100%)}
        /* ═══ Chip Balance (right panel) ═══ */
        .fh-sapphire-chips{position:absolute;z-index:3;left:calc(567/784*100%);top:calc(792/1168*100%);width:calc(135/784*100%);height:calc(56/1168*100%);background:rgba(0,0,0,.75);border-radius:3px;font-family:'Oswald',sans-serif;font-size:clamp(9px,1.6vw,14px);color:#ffd700;display:flex;align-items:center;justify-content:center;gap:3px}
        /* ═══ Pay Table Button (right panel) ═══ */
        .fh-sapphire-payouts-btn{position:absolute;z-index:3;left:calc(547/784*100%);top:calc(941/1168*100%);width:calc(169/784*100%);height:calc(59/1168*100%);background:linear-gradient(180deg,rgba(80,75,60,.4),rgba(40,38,30,.6));border:1px solid rgba(150,136,95,.35);color:rgba(150,136,95,.7);cursor:pointer;border-radius:3px;font-family:'Oswald',sans-serif;font-size:clamp(7px,1.1vw,11px);letter-spacing:1px;transition:all .2s;display:flex;align-items:center;justify-content:center}
        .fh-sapphire-payouts-btn:hover{background:rgba(255,215,0,.08);color:rgba(150,136,95,.9)}
        /* ═══ On-Cabinet Paytable (below bets) ═══ */
        .fh-sapphire-face-pay{position:absolute;z-index:3;left:calc(104/784*100%);top:calc(801/1168*100%);width:calc(381/784*100%);height:calc(159/1168*100%);display:grid;grid-template-columns:1fr 1fr;gap:1px 4px;align-content:start;font-family:'Oswald',sans-serif;font-size:clamp(6px,1.1vw,11px);line-height:1.25;color:rgba(150,136,95,.5);padding:2px 4px;box-sizing:border-box;overflow:hidden}
        .fh-sapphire-face-pay span{display:flex;align-items:center;justify-content:space-between;padding:0 2px;white-space:nowrap;line-height:1.25}
        .fh-sapphire-face-pay .sp-mult{color:rgba(255,215,0,.45);font-weight:700}
        /* ═══ Paytable Modal ═══ */
        .fh-sapphire-pay-modal{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center}
        .fh-sapphire-pay-bd{position:absolute;inset:0;background:rgba(0,0,0,.8);cursor:pointer}
        .fh-sapphire-pay-card{position:relative;background:linear-gradient(135deg,#2e2418,#1a1410);border:3px solid #96885f;border-radius:14px;padding:20px 16px;max-width:620px;width:90%;box-shadow:0 16px 50px rgba(0,0,0,.6)}
        .fh-sapphire-pay-close{position:absolute;top:8px;right:12px;width:28px;height:28px;background:rgba(255,255,255,.08);border:2px solid rgba(150,136,95,.4);border-radius:50%;color:#96885f;font-size:16px;cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center;transition:all .2s}
        .fh-sapphire-pay-close:hover{color:#ffd700;background:rgba(255,255,255,.15);border-color:#ffd700}
        .fh-sapphire-pay-title{text-align:center;font-family:'Special Elite',monospace;font-size:clamp(14px,3.5vw,18px);color:#ffd700;margin:0 0 2px}
        .fh-sapphire-pay-sub{text-align:center;font-family:'Oswald',sans-serif;font-size:11px;color:#96885f;margin:0 0 10px}
        .fh-sapphire-pay-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px}
        .fh-sapphire-pay-row{display:flex;align-items:center;gap:6px;padding:5px 8px;background:rgba(0,0,0,.25);border:1px solid rgba(150,136,95,.15);border-radius:4px}
        .fh-sapphire-pay-syms{flex-shrink:0;display:flex;gap:2px;align-items:center}
        .fh-sapphire-pay-syms img{width:18px;height:18px;object-fit:contain}
        .fh-sapphire-pay-mult{font-family:'Oswald',sans-serif;font-size:clamp(13px,3vw,16px);font-weight:700;color:#ffd700;text-shadow:0 0 6px rgba(255,215,0,.3)}
        .fh-sapphire-pay-label{font-family:'Oswald',sans-serif;font-size:11px;color:#f5f0e8}
        .fh-sapphire-pay-section{grid-column:1/-1;text-align:center;padding:4px 8px;background:rgba(0,0,0,.2);border:1px solid rgba(150,136,95,.15);border-radius:4px;font-family:'Oswald',sans-serif;font-size:12px;color:#96885f;margin-top:2px}

        /* ═══ BINGO HALL ═══ */
        .fh-bingo{max-width:500px;margin:0 auto;font-family:'Oswald',sans-serif;color:#f5f0e8}
        .fh-bingo-caller{text-align:center;font-size:clamp(16px,4vw,24px);font-weight:700;color:#ffd700;padding:8px 0;min-height:36px;text-shadow:0 0 8px rgba(255,215,0,.4)}
        .fh-bingo-history{display:grid;grid-template-columns:repeat(6,1fr);grid-template-rows:24px 24px;gap:3px;padding:4px 0 8px;max-width:320px;margin:0 auto}
        .fh-bingo-history-ball{height:24px;border-radius:12px;background:#2e2418;border:2px solid #96885f;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#f5f0e8;padding:0 2px}
        .fh-bingo-history-ball.latest{background:#96885f;color:#1a1410;border-color:#ffd700;box-shadow:0 0 8px rgba(255,215,0,.5)}
        .fh-bingo-card-wrap{background:#f5f0e8;border:4px double #2e2418;border-radius:8px;padding:2px;margin:0 auto;max-width:320px}
        .fh-bingo-headers{display:grid;grid-template-columns:repeat(5,1fr);text-align:center;font-size:clamp(14px,3.5vw,20px);font-weight:700;color:#2e2418;padding:4px 0;letter-spacing:2px}
        .fh-bingo-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:2px}
        .fh-bingo-cell{aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #ccc;font-family:'Special Elite',cursive;font-size:clamp(12px,3vw,18px);color:#2e2418;cursor:pointer;position:relative;border-radius:3px;font-weight:700;transition:background .15s}
        .fh-bingo-cell:hover{background:#f0ead8}
        .fh-bingo-cell.free{background:#d4c9a8;font-size:clamp(8px,2vw,12px);color:#96885f;cursor:default}
        .fh-bingo-cell.daubed::after{content:'';position:absolute;inset:10%;border-radius:50%;background:rgba(139,0,0,.7);pointer-events:none}
        .fh-bingo-cell.called{background:#fff8dc}
        .fh-bingo-controls{display:flex;flex-direction:column;align-items:center;gap:6px;padding:8px 0 4px;max-width:320px;margin:0 auto}
        .fh-bingo-controls-row{display:flex;gap:5px;justify-content:center;align-items:center;width:100%}
        .fh-bingo-controls-bets{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;width:100%}
        .fh-bingo-controls-utils{display:flex;gap:10px;justify-content:center;align-items:center}
        .fh-bingo-bet{background:rgba(0,0,0,.6);color:#f5f0e8;border:1px solid rgba(150,136,95,.4);padding:6px 14px;border-radius:4px;cursor:pointer;font-family:'Oswald',sans-serif;font-size:13px;font-weight:600;transition:all .2s}
        .fh-bingo-bet:hover{background:rgba(0,0,0,.8);border-color:rgba(255,215,0,.5)}
        .fh-bingo-bet.active{background:rgba(255,215,0,.2);border-color:#ffd700;color:#ffd700;box-shadow:0 0 10px rgba(255,215,0,.4)}
        .fh-bingo-btn{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;padding:8px 20px;border-radius:6px;border:none;cursor:pointer;transition:all .2s}
        .fh-bingo-btn-buy{background:linear-gradient(180deg,#FF7F00,#CC6600);color:#fff;box-shadow:0 3px 8px rgba(0,0,0,.3)}
        .fh-bingo-btn-buy:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(0,0,0,.4)}
        .fh-bingo-btn-buy:disabled{opacity:.4;cursor:not-allowed;transform:none}
        .fh-bingo-btn-cashout{background:linear-gradient(180deg,#2ecc71,#27ae60);color:#fff;box-shadow:0 3px 8px rgba(0,0,0,.3);display:none}
        .fh-bingo-btn-cashout:hover{transform:translateY(-1px)}
        .fh-bingo-autodaub{display:flex;align-items:center;gap:4px;font-size:11px;color:#96885f;cursor:pointer}
        .fh-bingo-autodaub input{accent-color:#ffd700}
        .fh-bingo-stats{display:flex;justify-content:center;gap:16px;padding:6px 0;font-size:13px;color:#96885f}
        .fh-bingo-stats .bingo-winnings{color:#ffd700;font-weight:700}
        .fh-bingo-win-banner{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:linear-gradient(135deg,#ffd700,#daa520);color:#2e2418;padding:10px 24px;border-radius:8px;font-size:clamp(14px,3.5vw,20px);font-weight:700;text-align:center;z-index:10;box-shadow:0 4px 20px rgba(255,215,0,.5);animation:fh-bingo-banner .5s ease forwards;pointer-events:none}
        @keyframes fh-bingo-banner{0%{opacity:0;transform:translate(-50%,-50%) scale(.7)}100%{opacity:1;transform:translate(-50%,-50%) scale(1)}}
        /* ═══ Bingo Paytable Visual Cards ═══ */
        .fh-bingo-pay-entry{display:flex;align-items:center;gap:8px;padding:4px 8px;background:rgba(0,0,0,.25);border:1px solid rgba(150,136,95,.15);border-radius:3px;margin-bottom:3px}
        .fh-bingo-pay-entry.jackpot{background:rgba(255,215,0,.08);border-color:rgba(255,215,0,.3)}
        .fh-bingo-pay-name{flex:1;font-family:Oswald,sans-serif;font-size:11px;color:#f5f0e8}
        .fh-bingo-pay-entry.jackpot .fh-bingo-pay-name{color:#ffd700}
        .fh-bingo-pay-mult{font-family:Oswald,sans-serif;font-size:14px;font-weight:700;color:#ffd700;white-space:nowrap}
        .fh-bingo-pay-entry.jackpot .fh-bingo-pay-mult{font-size:15px;text-shadow:0 0 8px rgba(255,215,0,.5)}
        </style>

        <script>
        (function(){
            const app          = document.getElementById('fh-arcade');
            const nonce        = app.dataset.nonce;
            const casinoNonce  = app.dataset.casinoNonce;
            const ajax         = app.dataset.ajax;
            let canClaim       = app.dataset.canClaim === '1';
            let chips          = <?php echo (int) $chips; ?>;

            /* Draft reveal config — single batch from the shortcode chain */
            const draftData = {
                hasResults: <?php echo $has_draft ? 'true' : 'false'; ?>,
                config: {
                    ajaxUrl:   ajax,
                    nonce:     '<?php echo esc_js( $draft_nonce ); ?>',
                    batchName: '<?php echo esc_js( $draft_batch ); ?>',
                    myUid:     <?php echo (int) $uid; ?>,
                    startLive: <?php echo ( $has_draft && ! $draft_seen ) ? 'true' : 'false'; ?>,
                    cardBack:  '<?php echo esc_js( $draft_cardback ); ?>',
                    cardFace:  '<?php echo esc_js( $draft_cardface ); ?>',
                    soundsUrl: '<?php echo esc_js( $draft_sounds ); ?>'
                },
                scriptUrl: '<?php echo esc_js( $draft_script ); ?>'
            };

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

            /* ═══════════════════════════════════════════════
             *  ROOM ZOOM + THEMED POPUP (hotel-style)
             * ═══════════════════════════════════════════════ */
            let _arcZoomOpen = false;
            let _arcZoomBuilding = null;
            let _arcZoomRoom = null;
            let _arcEscHandler = null;

            /* Room theme configs */
            const roomThemes = {
                bar:       { title: 'THE BAR', icon: '🍸', subtitle: 'Daily Chip Bonus' },
                bingo:     { title: 'BINGO HALL', icon: '🎱', subtitle: 'Classic 75-Ball' },
                sports:    { title: 'SPORTS LOUNGE', icon: '🏈', subtitle: 'Coming Soon' },
                roulette:  { title: 'ROULETTE TABLE', icon: '🎰', subtitle: 'Spin the Wheel' },
                craps:     { title: 'DRAFT ROOM', icon: '🃏', subtitle: 'Card Reveal' },
                blackjack: { title: 'BLACKJACK TABLE', icon: '🃏', subtitle: 'Beat the Dealer' },
                slots:     { title: 'SLOT MACHINES', icon: '🐠', subtitle: 'Fish Slots' },
                prizes:    { title: 'PRIZE ROOM', icon: '🏆', subtitle: 'Coming Soon' },
                poker:     { title: 'POKER TABLE', icon: '♠', subtitle: 'Video Poker' },
            };


            function arcadeZoomClose() {
                if (!_arcZoomOpen) return;
                _arcZoomOpen = false;
                /* Remove popup if open */
                const card = document.querySelector('.fh-arc-popup');
                if (card) {
                    card.style.opacity = '0';
                    card.addEventListener('transitionend', () => card.remove(), { once: true });
                }
                /* Remove any leftover hotspot elements */
                document.querySelectorAll('.fh-room-hotspot').forEach(h => h.remove());
                /* Unzoom building */
                if (_arcZoomBuilding) {
                    _arcZoomBuilding.style.transform = 'translate(0px, 0px) scale(1)';
                    _arcZoomBuilding.addEventListener('transitionend', function onUz(e) {
                        if (e.propertyName !== 'transform') return;
                        _arcZoomBuilding.removeEventListener('transitionend', onUz);
                        _arcZoomBuilding.style.transform = '';
                        _arcZoomBuilding.style.transition = '';
                        _arcZoomBuilding = null;
                    });
                }
                /* Fade backdrop */
                const bd = document.querySelector('.fh-arc-zoom-backdrop');
                if (bd) {
                    bd.classList.remove('fh-arc-zoom-backdrop--visible');
                    bd.addEventListener('transitionend', () => bd.remove(), { once: true });
                }
                if (_arcEscHandler) {
                    document.removeEventListener('keydown', _arcEscHandler);
                    _arcEscHandler = null;
                }
            }

            /* Open a game popup directly after zoom */
            function openRoomGame(game) {
                const roomKey = _arcZoomRoom ? _arcZoomRoom.dataset.room : 'slots';
                const card = buildRoomPopup(roomKey, game);
                /* Dim backdrop when popup opens */
                const bd = document.querySelector('.fh-arc-zoom-backdrop');
                if (bd) bd.classList.add('fh-arc-zoom-backdrop--dimmed');
                document.body.appendChild(card);
                requestAnimationFrame(() => card.style.opacity = '1');
                card.querySelector('.fh-arc-popup-close').addEventListener('click', () => {
                    const popupBody = document.getElementById('fh-arc-popup-body');
                    /* Slot room: if inside a game, go back to slot selection first */
                    if (game === 'slots' && popupBody && !popupBody.querySelector('.fh-slot-select')) {
                        loadRoomContent(game, popupBody);
                        return;
                    }
                    /* Otherwise close popup and unzoom back to casino floor */
                    card.style.opacity = '0';
                    card.addEventListener('transitionend', () => card.remove(), { once: true });
                    arcadeZoomClose();
                });
                const body = document.getElementById('fh-arc-popup-body');
                loadRoomContent(game, body);
            }

            /* Build themed popup card HTML */
            function buildRoomPopup(roomKey, game) {
                const theme = roomThemes[roomKey] || { title: 'ROOM', icon: '', subtitle: '' };
                const card = document.createElement('div');
                card.className = 'fh-arc-popup';
                card.innerHTML =
                    '<button class="fh-arc-popup-close" onclick="event.stopPropagation();">&times;</button>' +
                    '<div class="fh-arc-popup-body" id="fh-arc-popup-body"></div>';
                return card;
            }

            /* Casino AJAX helper */
            async function casinoPost(action, data={}) {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', casinoNonce);
                for (const k in data) fd.append(k, data[k]);
                const r = await fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'});
                return r.json();
            }

            /* ─── Room click handler — zoom into room, show hotspots ─── */
            document.querySelectorAll('.fh-arc-room').forEach(room => {
                room.addEventListener('click', function() {
                    if (_arcZoomOpen) { arcadeZoomClose(); return; }

                    const roomKey = this.dataset.room;
                    const isMobile = window.innerWidth <= 640;
                    const building = this.closest('.fh-arc-building');

                    _arcZoomRoom = this;
                    _arcZoomOpen = true;

                    /* Backdrop */
                    const backdrop = document.createElement('div');
                    backdrop.className = 'fh-arc-zoom-backdrop';
                    backdrop.addEventListener('click', arcadeZoomClose);
                    document.body.appendChild(backdrop);
                    requestAnimationFrame(() => backdrop.classList.add('fh-arc-zoom-backdrop--visible'));

                    /* Desktop: zoom building into room */
                    if (!isMobile && building) {
                        _arcZoomBuilding = building;
                        const bRect = building.getBoundingClientRect();
                        const rRect = this.getBoundingClientRect();
                        const rCx = rRect.left - bRect.left + rRect.width / 2;
                        const rCy = rRect.top - bRect.top + rRect.height / 2;
                        const scale = Math.min(bRect.width / rRect.width, bRect.height / rRect.height) * 0.75;
                        const tx = bRect.width / 2 - rCx * scale;
                        const ty = bRect.height / 2 - rCy * scale;
                        building.style.transformOrigin = '0 0';
                        building.style.transition = 'transform 420ms cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                        building.style.transform = 'translate(' + tx + 'px, ' + ty + 'px) scale(' + scale + ')';
                    }

                    /* After zoom, open game popup directly */
                    const gameType = this.dataset.game;
                    setTimeout(() => {
                        openRoomGame(gameType);
                    }, isMobile ? 0 : 420);

                    /* Escape key */
                    _arcEscHandler = (e) => { if (e.key === 'Escape') arcadeZoomClose(); };
                    document.addEventListener('keydown', _arcEscHandler);
                });
            });

            /* ─── Load content into popup body ─── */
            function loadRoomContent(game, body) {
                switch(game) {
                    case 'daily_bonus': renderDailyBonus(body); break;
                    case 'coming_soon': renderComingSoon(body); break;
                    case 'draft':       renderDraft(body); break;
                    case 'slots':       renderSlotSelection(body); break;
                    case 'bingo':       renderBingoHall(body); break;
                    default:
                        body.innerHTML = '<p style="text-align:center;color:#aaa;padding:30px;font-family:Special Elite,cursive;">Game being rebuilt — coming soon!</p>';
                        break;
                }
            }

            /* ─── Daily Bonus ─── */
            function renderDailyBonus(body) {
                if (canClaim) {
                    body.innerHTML =
                        '<p style="color:#f5f0e8;margin:0 0 16px;">Grab your daily chip bonus on the house.</p>' +
                        '<button id="fh-arc-claim-daily" class="fh-arc-btn-gold" style="font-size:1.1em;padding:12px 36px;">Claim <?php echo self::DAILY_BONUS_CHIPS; ?> Free Chips</button>' +
                        '<div id="fh-arc-daily-msg" style="margin-top:12px;"></div>';
                    document.getElementById('fh-arc-claim-daily').addEventListener('click', async function() {
                        this.disabled = true;
                        const res = await postAjax('fishotel_arcade_daily_bonus');
                        if (res.success) {
                            updateChips(res.data.chips);
                            canClaim = false;
                            document.getElementById('fh-arc-daily-msg').innerHTML = '<p style="color:#96885f;font-family:Special Elite,cursive;">Here\'s your daily <?php echo self::DAILY_BONUS_CHIPS; ?> chips! Come back tomorrow.</p>';
                            this.style.display = 'none';
                        } else {
                            document.getElementById('fh-arc-daily-msg').innerHTML = '<p style="color:#e74c3c;">' + (res.data.message || 'Already claimed!') + '</p>';
                        }
                    });
                } else {
                    body.innerHTML = '<p style="color:#96885f;font-family:Special Elite,cursive;padding:20px 0;">You already grabbed your daily bonus. Come back tomorrow!</p>';
                }
            }

            /* ─── Coming Soon ─── */
            function renderComingSoon(body) {
                body.innerHTML = '<p style="color:#aaa;padding:20px 0;">Draft Results &amp; more coming soon…</p>' +
                    '<p style="font-family:Special Elite,cursive;color:#96885f;">Stay tuned!</p>';
            }

            /* ─── Draft Card Reveal ─── */
            function renderDraft(body) {
                if (!draftData.hasResults) {
                    body.innerHTML = '<p style="color:#96885f;font-family:Special Elite,cursive;padding:20px 0;text-align:center;">No draft results yet.<br>Check back after the draft runs!</p>';
                    return;
                }
                /* Add rail + felt background to popup */
                var popup = document.querySelector('.fh-arc-popup');
                if (popup) {
                    popup.style.background = 'repeating-linear-gradient(0deg,rgba(0,0,0,0.06) 0px,transparent 1px,transparent 3px,rgba(0,0,0,0.04) 4px,transparent 5px,transparent 8px,rgba(0,0,0,0.07) 9px,transparent 10px,transparent 14px),repeating-linear-gradient(2deg,rgba(120,70,30,0.07) 0px,transparent 2px,transparent 6px,rgba(80,40,10,0.05) 7px,transparent 8px,transparent 13px),linear-gradient(160deg,#5c2e0e 0%,#3b1a08 40%,#2a1005 70%,#1a0a02 100%)';
                    popup.style.border = '3px solid #1a0a02';
                    popup.style.boxShadow = '0 20px 60px rgba(0,0,0,0.9),0 8px 24px rgba(0,0,0,0.7),inset 0 1px 0 rgba(160,110,60,0.3)';
                    popup.style.padding = '14px';
                }
                body.style.background = "url('<?php echo esc_js( $felt_url ); ?>') center/cover #0a3d1f";
                body.style.borderRadius = '8px';
                body.style.padding = '16px';
                body.style.boxShadow = 'inset 0 3px 12px rgba(0,0,0,0.7),inset 0 1px 4px rgba(0,0,0,0.5)';
                body.innerHTML =
                    '<style>' +
                    '.fhlc-reveal-controls{display:flex;justify-content:flex-end;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;}' +
                    '.fhlc-speed{display:flex;gap:4px;}' +
                    '.fhlc-speed button{background:#222;border:1px solid #444;color:#888;font-size:0.7rem;padding:4px 10px;border-radius:4px;cursor:pointer;font-family:Courier New,monospace;transition:border-color 0.2s,color 0.2s;}' +
                    '.fhlc-speed button.fhlc-speed-active{border-color:#c9a84c;color:#c9a84c;}' +
                    '.fhlc-skip{background:none;border:1px solid #444;color:#888;font-size:0.72rem;padding:4px 14px;border-radius:4px;cursor:pointer;font-family:Courier New,monospace;}' +
                    '.fhlc-skip:hover{color:#c9a84c;border-color:#c9a84c;}' +
                    '.fhlc-card-stage{perspective:1200px;min-height:280px;margin-bottom:16px;}' +
                    '.fhlc-round-label{font-family:Oswald,sans-serif;font-size:0.75rem;color:#c9a84c;text-transform:uppercase;letter-spacing:0.2em;text-align:left;margin:16px 0 8px;padding-bottom:4px;border-bottom:1px solid rgba(201,168,76,0.3);}' +
                    '.fhlc-reveal-divider{border:none;border-top:1px solid rgba(201,168,76,0.25);margin:24px 0 8px;}' +
                    '.fhlc-card-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;}' +
                    '.fhlc-deal-card{width:100%;aspect-ratio:675/1044;position:relative;transform-style:preserve-3d;cursor:default;}' +
                    '.fhlc-deal-card.fhlc-entering{animation:fhlcDealIn 0.35s ease-out forwards;}' +
                    '.fhlc-deal-card.fhlc-flipping .fhlc-card-inner{transform:rotateY(180deg);}' +
                    '.fhlc-deal-card.fhlc-mine{border-radius:8px;}' +
                    '.fhlc-deal-card.fhlc-mine .fhlc-card-front::after{content:"\\2605 YOURS";position:absolute;top:-1px;right:-24px;background:linear-gradient(135deg,#1a3a6b,#0f2448);color:rgba(255,255,255,0.9);font-family:Oswald,sans-serif;font-size:clamp(5px,0.7vw,7px);font-weight:700;letter-spacing:0.1em;padding:1px 26px;transform:rotate(45deg);z-index:5;box-shadow:0 1px 4px rgba(0,0,0,0.4);}' +
                    '.fhlc-deal-card.fhlc-dimmed{opacity:0.3;transition:opacity 0.4s;}' +
                    '.fhlc-card-inner{position:relative;width:100%;height:100%;transition:transform 0.6s ease-in-out;transform-style:preserve-3d;}' +
                    '.fhlc-card-front,.fhlc-card-back{position:absolute;inset:0;backface-visibility:hidden;border-radius:8px;overflow:hidden;}' +
                    '.fhlc-card-back{background-size:cover;background-position:center;box-shadow:0 6px 24px rgba(0,0,0,0.5);}' +
                    '.fhlc-card-front{transform:rotateY(180deg);background-color:#faf8f2;background-size:100% 100%;background-repeat:no-repeat;box-shadow:0 6px 24px rgba(0,0,0,0.5);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10% 16%;text-align:center;}' +
                    '.fhlc-card-front .fhlc-cf-round{display:none;}' +
                    '.fhlc-card-front .fhlc-cf-fish{font-family:Pompiere,Tulpen One,cursive;font-size:clamp(8px,1vw,12px);font-weight:400;color:#1a1a1a;margin:3% 0;line-height:1.3;width:100%;overflow:visible;word-break:break-word;text-transform:none;}' +
                    '.fhlc-card-front .fhlc-cf-customer{font-family:Special Elite,cursive;font-size:clamp(6px,0.9vw,10px);color:#96885f;width:100%;overflow:hidden;word-break:break-word;margin-bottom:2%;}' +
                    '.fhlc-card-front .fhlc-cf-qty{font-family:Courier New,monospace;font-size:clamp(6px,0.8vw,9px);color:#666;margin-top:0;}' +
                    '.fhlc-card-front .fhlc-cf-suit{position:absolute;top:4%;left:5%;font-size:clamp(8px,1vw,12px);}' +
                    '.fhlc-card-front .fhlc-cf-suit-br{position:absolute;bottom:4%;right:5%;font-size:clamp(8px,1vw,12px);transform:rotate(180deg);}' +
                    '@keyframes fhlcDealIn{from{opacity:0;transform:translateY(-40px) scale(0.85);}to{opacity:1;transform:translateY(0) scale(1);}}' +
                    '.fhlc-mobile-table{display:none;}' +
                    '.fhlc-mobile-table table{width:100%;border-collapse:collapse;background:transparent!important;border:none!important;}' +
                    '.fhlc-mobile-table th{font-family:Oswald,sans-serif;font-size:0.7rem;color:rgba(201,168,76,0.6);text-transform:uppercase;letter-spacing:0.15em;padding:8px 10px;text-align:left;border-bottom:1px solid rgba(201,168,76,0.25);background:transparent!important;}' +
                    '.fhlc-mobile-table td{padding:8px 10px;font-family:Special Elite,cursive;font-size:0.8rem;border-bottom:1px solid rgba(255,255,255,0.06);color:rgba(255,255,255,0.55);background:transparent!important;}' +
                    '.fhlc-mobile-table tr{background:transparent!important;}' +
                    '.fhlc-mobile-table .fhlc-row-mine td{color:#c9a84c;font-weight:600;}' +
                    '.fhlc-mobile-table .fhlc-row-entering td{animation:fhlcRowPulse 0.5s ease-out;}' +
                    '@keyframes fhlcRowPulse{0%{background:rgba(201,168,76,0.15)!important;}100%{background:transparent!important;}}' +
                    '#fhlc-reveal-wrap{padding-bottom:20px;}' +
                    '.fhlc-post-controls{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;justify-content:center;}' +
                    '.fhlc-post-controls button{background:transparent;border:1px solid rgba(201,168,76,0.5);color:#c9a84c;font-family:Oswald,sans-serif;font-size:0.75rem;font-weight:400;letter-spacing:0.2em;text-transform:uppercase;padding:8px 22px;border-radius:2px;cursor:pointer;transition:background 0.2s,border-color 0.2s,color 0.2s;}' +
                    '.fhlc-post-controls button:hover{background:rgba(201,168,76,0.1);border-color:#c9a84c;color:#e8c96a;}' +
                    '.fhlc-post-controls button.fhlc-filter-active{background:rgba(201,168,76,0.15);border-color:#c9a84c;color:#e8c96a;}' +
                    '.fhlc-full-results{margin-top:16px;border-top:1px solid rgba(201,168,76,0.25);padding-top:16px;}' +
                    '.fhlc-full-results table{width:100%;border-collapse:collapse;background:transparent!important;border:none!important;}' +
                    '.fhlc-full-results th{font-family:Oswald,sans-serif;font-size:0.7rem;color:rgba(201,168,76,0.6);text-transform:uppercase;letter-spacing:0.15em;padding:8px 10px;text-align:left;border-bottom:1px solid rgba(201,168,76,0.25);background:transparent!important;}' +
                    '.fhlc-full-results td{padding:8px 10px;font-family:Special Elite,cursive;font-size:0.8rem;border-bottom:1px solid rgba(255,255,255,0.06);color:rgba(255,255,255,0.55);background:transparent!important;}' +
                    '.fhlc-full-results tr{background:transparent!important;}' +
                    '.fhlc-full-results .fhlc-row-mine td{color:#c9a84c;font-weight:600;}' +
                    '.fhlc-full-results .fhlc-round-hdr td{padding:10px 8px 4px;color:rgba(201,168,76,0.5);font-family:Oswald,sans-serif;font-size:0.7rem;letter-spacing:0.15em;border-bottom:1px solid rgba(201,168,76,0.2);font-weight:600;background:transparent!important;}' +
                    '@media(max-width:768px){.fhlc-card-stage{display:none!important;}.fhlc-mobile-table{display:block!important;}}' +
                    '@media(min-width:769px){.fhlc-mobile-table{display:none!important;}.fhlc-card-stage{display:block!important;}}' +
                    '@media(max-width:600px){.fhlc-card-grid{grid-template-columns:repeat(3,1fr);gap:10px;}}' +
                    '@media(prefers-reduced-motion:reduce){.fhlc-deal-card.fhlc-entering{animation:none!important;opacity:1;transform:none;}.fhlc-card-inner{transition:none!important;}.fhlc-mobile-table .fhlc-row-entering td{animation:none!important;}}' +
                    '</style>' +
                    '<div id="fhlc-reveal-wrap">' +
                        '<div class="fhlc-reveal-controls" id="fhlc-reveal-controls">' +
                            '<div class="fhlc-speed">' +
                                '<button data-speed="3.5" class="fhlc-speed-btn">Slow</button>' +
                                '<button data-speed="2" class="fhlc-speed-btn fhlc-speed-active">Normal</button>' +
                                '<button data-speed="0.8" class="fhlc-speed-btn">Fast</button>' +
                            '</div>' +
                            '<button class="fhlc-skip" id="fhlc-skip">Skip to results &raquo;</button>' +
                        '</div>' +
                        '<div class="fhlc-card-stage" id="fhlc-card-stage"></div>' +
                        '<div class="fhlc-mobile-table" id="fhlc-mobile-table">' +
                            '<table><thead><tr><th>Rd</th><th>Customer</th><th>Fish</th><th>Qty</th></tr></thead>' +
                            '<tbody id="fhlc-mobile-tbody"></tbody></table>' +
                        '</div>' +
                        '<div class="fhlc-post-controls" id="fhlc-post-controls" style="display:none;">' +
                            '<button class="fhlc-filter-btn" id="fhlc-filter-mine">Your Fish</button>' +
                            '<button id="fhlc-view-all">View Full Results</button>' +
                            '<button id="fhlc-replay-btn">Replay</button>' +
                        '</div>' +
                        '<div class="fhlc-full-results" id="fhlc-full-results" style="display:none;"></div>' +
                    '</div>';

                /* Set global data and dynamically load draft-reveal.js */
                window.fhlcDraftData = draftData.config;
                var oldScript = document.querySelector('script[data-fhlc-draft]');
                if (oldScript) oldScript.remove();
                var s = document.createElement('script');
                s.src = draftData.scriptUrl;
                s.setAttribute('data-fhlc-draft', '1');
                document.body.appendChild(s);
            }

            /* ═══════════════════════════════════════════════
             *  SLOTS GAME
             * ═══════════════════════════════════════════════ */

            function renderSlotMachine(body) {
                const cabinetUrl = '<?php echo esc_url( plugins_url( "assists/casino/slots/FisHotel-Slot-Cabnet-Body-01.png", FISHOTEL_PLUGIN_FILE ) ); ?>?v=<?php echo FISHOTEL_VERSION; ?>';
                const symBase    = '<?php echo esc_url( plugins_url( "assists/casino/slots/", FISHOTEL_PLUGIN_FILE ) ); ?>';
                const SYMS = [
                    { id:'whale',    file:'Whale.png',    pay:50, label:'Whale' },
                    { id:'starfish', file:'Starfish.png', pay:20, label:'Starfish' },
                    { id:'shark',    file:'Shark.png',    pay:15, label:'Shark' },
                    { id:'puffer',   file:'Puffer.png',   pay:10, label:'Puffer' },
                    { id:'dolphin',  file:'Dolphin.png',  pay:8,  label:'Dolphin' },
                    { id:'octopus',  file:'Octopus.png',  pay:6,  label:'Octopus' },
                    { id:'squid',    file:'Squid.png',    pay:5,  label:'Squid' },
                    { id:'seahorse', file:'Seahorse.png', pay:5,  label:'Seahorse' },
                ];
                const symMap = {}; SYMS.forEach(s => symMap[s.id] = s);
                const pool = [];
                [8,7,6,5,4,3,2,1].forEach((w,i) => { for(let j=0;j<w;j++) pool.push(SYMS[SYMS.length-1-i]); });

                let bet = 50, spinning = false;

                /* ── Build HTML (hidden until cabinet loads) ── */
                body.innerHTML =
                    '<div class="fh-slot-loading" id="fh-slots-loader"><div class="fh-slot-spinner"></div><div class="fh-slot-loading-text">LOADING</div></div>' +
                    '<div class="fh-slots fh-slot-machine-hidden" id="fh-slots-wrap">' +
                        /* Cabinet image + reels + controls all inside aspect-ratio container */
                        '<div class="fh-slots-machine">' +
                            '<img src="' + cabinetUrl + '" alt="Slot Machine" id="fh-slots-cab-img">' +
                            /* Reel windows */
                            '<div class="fh-slots-rw" id="fh-sw-0"><div class="fh-slots-strip" id="fh-sr-0"><div class="fh-slots-sym"><img src="'+symBase+SYMS[2].file+'"></div></div></div>' +
                            '<div class="fh-slots-rw" id="fh-sw-1"><div class="fh-slots-strip" id="fh-sr-1"><div class="fh-slots-sym"><img src="'+symBase+SYMS[4].file+'"></div></div></div>' +
                            '<div class="fh-slots-rw" id="fh-sw-2"><div class="fh-slots-strip" id="fh-sr-2"><div class="fh-slots-sym"><img src="'+symBase+SYMS[3].file+'"></div></div></div>' +
                            /* LED Lightboard — dot-matrix display */
                            '<div class="fh-slots-result" id="fh-slots-res"><canvas id="fh-led-canvas"></canvas></div>' +
                            /* Chip balance — positioned on cabinet */
                            '<div class="fh-slots-chips"><img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" style="width:16px;height:16px"><span class="fh-arc-chip-mirror">' + Number(chips).toLocaleString() + '</span></div>' +
                            /* Bet + Spin buttons — each positioned exactly on cabinet */
                            '<button class="fh-slots-bet" id="fh-bet-10" data-bet="10">10</button>' +
                            '<button class="fh-slots-bet active" id="fh-bet-50" data-bet="50">50</button>' +
                            '<button class="fh-slots-bet" id="fh-bet-100" data-bet="100">100</button>' +
                            '<button class="fh-slots-bet" id="fh-bet-250" data-bet="250">250</button>' +
                            '<button class="fh-slots-spin" id="fh-slots-spin">SPIN</button>' +
                            /* Payouts link */
                            '<button class="fh-slots-payouts-btn" id="fh-slots-pay-btn">PAY TABLE</button>' +
                        '</div>' +
                    '</div>' +
                    /* Paytable modal (hidden) */
                    '<div class="fh-slots-pay-modal" id="fh-slots-pay" style="display:none;">' +
                        '<div class="fh-slots-pay-bd" id="fh-slots-pay-bd"></div>' +
                        '<div class="fh-slots-pay-card">' +
                            '<button class="fh-slots-pay-close" id="fh-slots-pay-x">&times;</button>' +
                            '<div class="fh-slots-pay-title">PAYOUTS</div>' +
                            '<div class="fh-slots-pay-sub">Match 3 of a kind</div>' +
                            '<div class="fh-slots-pay-grid">' +
                            SYMS.map(s =>
                                '<div class="fh-slots-pay-row">' +
                                    '<div class="fh-slots-pay-syms"><img src="'+symBase+s.file+'"><img src="'+symBase+s.file+'"><img src="'+symBase+s.file+'"></div>' +
                                    '<div class="fh-slots-pay-mult">'+s.pay+'x</div>' +
                                '</div>'
                            ).join('') +
                            '<div class="fh-slots-pay-footer">Any 2-Match: <span style="color:#ffd700;font-weight:700;">2x</span></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';

                /* ── Bet buttons ── */
                body.querySelectorAll('.fh-slots-bet').forEach(b => {
                    b.addEventListener('click', () => {
                        if (spinning) return;
                        body.querySelectorAll('.fh-slots-bet').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        bet = parseInt(b.dataset.bet);
                    });
                });

                /* ═══ LED DOT-MATRIX LIGHTBOARD ENGINE ═══ */
                const ledCanvas = document.getElementById('fh-led-canvas');
                const ledCtx = ledCanvas.getContext('2d');
                let ledAnim = null, ledScrollX = 0, ledText = '', ledColor = '#ffd700', ledMode = 'static';

                /* 5×7 dot-matrix font (uppercase + digits + symbols) */
                const LED_FONT = {
                    'A':[0x1F,0x24,0x44,0x24,0x1F],'B':[0x7F,0x49,0x49,0x49,0x36],'C':[0x3E,0x41,0x41,0x41,0x22],
                    'D':[0x7F,0x41,0x41,0x41,0x3E],'E':[0x7F,0x49,0x49,0x49,0x41],'F':[0x7F,0x48,0x48,0x48,0x40],
                    'G':[0x3E,0x41,0x49,0x49,0x2E],'H':[0x7F,0x08,0x08,0x08,0x7F],'I':[0x41,0x41,0x7F,0x41,0x41],
                    'J':[0x02,0x01,0x01,0x01,0x7E],'K':[0x7F,0x08,0x14,0x22,0x41],'L':[0x7F,0x01,0x01,0x01,0x01],
                    'M':[0x7F,0x20,0x10,0x20,0x7F],'N':[0x7F,0x10,0x08,0x04,0x7F],'O':[0x3E,0x41,0x41,0x41,0x3E],
                    'P':[0x7F,0x48,0x48,0x48,0x30],'Q':[0x3E,0x41,0x45,0x42,0x3D],'R':[0x7F,0x48,0x4C,0x4A,0x31],
                    'S':[0x32,0x49,0x49,0x49,0x26],'T':[0x40,0x40,0x7F,0x40,0x40],'U':[0x7E,0x01,0x01,0x01,0x7E],
                    'V':[0x7C,0x02,0x01,0x02,0x7C],'W':[0x7F,0x02,0x04,0x02,0x7F],'X':[0x63,0x14,0x08,0x14,0x63],
                    'Y':[0x60,0x10,0x0F,0x10,0x60],'Z':[0x43,0x45,0x49,0x51,0x61],
                    '0':[0x3E,0x45,0x49,0x51,0x3E],'1':[0x00,0x21,0x7F,0x01,0x00],'2':[0x23,0x45,0x49,0x49,0x31],
                    '3':[0x22,0x41,0x49,0x49,0x36],'4':[0x0C,0x14,0x24,0x7F,0x04],'5':[0x72,0x51,0x51,0x51,0x4E],
                    '6':[0x3E,0x49,0x49,0x49,0x26],'7':[0x40,0x47,0x48,0x50,0x60],'8':[0x36,0x49,0x49,0x49,0x36],
                    '9':[0x32,0x49,0x49,0x49,0x3E],
                    '+':[0x08,0x08,0x3E,0x08,0x08],'-':[0x08,0x08,0x08,0x08,0x08],'!':[0x00,0x00,0x7D,0x00,0x00],
                    'x':[0x22,0x14,0x08,0x14,0x22],'X':[0x63,0x14,0x08,0x14,0x63],
                    ' ':[0x00,0x00,0x00,0x00,0x00],'.':[0x00,0x01,0x00,0x00,0x00],',':[0x00,0x01,0x02,0x00,0x00],
                    ':':[0x00,0x14,0x00,0x00,0x00],'?':[0x20,0x40,0x4D,0x48,0x30],
                };

                function ledGetTextWidth(text) {
                    let w = 0;
                    for (let i = 0; i < text.length; i++) {
                        w += (LED_FONT[text[i]] ? 5 : 3) + 1;
                    }
                    return w - 1;
                }

                function ledDraw() {
                    const el = document.getElementById('fh-slots-res');
                    if (!el) { cancelAnimationFrame(ledAnim); return; }
                    const w = el.offsetWidth * 2, h = el.offsetHeight * 2;
                    if (ledCanvas.width !== w || ledCanvas.height !== h) {
                        ledCanvas.width = w; ledCanvas.height = h;
                    }
                    const ctx = ledCtx;
                    const dotSize = 3, gap = 1, pitch = dotSize + gap;
                    const rows = 7, charW = 5;
                    const totalH = rows * pitch;
                    const offsetY = Math.floor((h - totalH) / 2);
                    const textW = ledGetTextWidth(ledText);
                    const totalPxW = textW * pitch;
                    const visibleCols = Math.floor(w / pitch);

                    ctx.fillStyle = '#080808';
                    ctx.fillRect(0, 0, w, h);

                    /* Draw dim dot grid background */
                    const gridCols = Math.ceil(w / pitch), gridRows = rows;
                    for (let row = 0; row < gridRows; row++) {
                        for (let col = 0; col < gridCols; col++) {
                            ctx.fillStyle = 'rgba(30,30,30,.5)';
                            ctx.beginPath();
                            ctx.arc(col * pitch + dotSize/2, offsetY + row * pitch + dotSize/2, dotSize/2, 0, Math.PI*2);
                            ctx.fill();
                        }
                    }

                    /* Determine scroll offset */
                    let scrollOff = 0;
                    if (ledMode === 'scroll') {
                        scrollOff = Math.floor(ledScrollX);
                    } else {
                        scrollOff = -Math.floor((w - totalPxW) / 2 / pitch);
                    }

                    /* Parse color for glow */
                    const isGold = ledColor === '#ffd700';
                    const glowR = isGold ? 255 : 204, glowG = isGold ? 215 : 68, glowB = isGold ? 0 : 68;

                    /* Draw lit dots */
                    let colPos = 0;
                    for (let ci = 0; ci < ledText.length; ci++) {
                        const ch = ledText[ci];
                        const glyph = LED_FONT[ch] || LED_FONT[' '];
                        const cw = glyph.length;
                        for (let gc = 0; gc < cw; gc++) {
                            const screenCol = colPos - scrollOff;
                            if (screenCol >= -1 && screenCol < gridCols + 1) {
                                const colData = glyph[gc];
                                for (let row = 0; row < rows; row++) {
                                    if (colData & (1 << (rows - 1 - row))) {
                                        const px = screenCol * pitch + dotSize/2;
                                        const py = offsetY + row * pitch + dotSize/2;
                                        /* Glow */
                                        ctx.fillStyle = 'rgba('+glowR+','+glowG+','+glowB+',.15)';
                                        ctx.beginPath();
                                        ctx.arc(px, py, dotSize, 0, Math.PI*2);
                                        ctx.fill();
                                        /* Bright dot */
                                        ctx.fillStyle = ledColor;
                                        ctx.beginPath();
                                        ctx.arc(px, py, dotSize/2, 0, Math.PI*2);
                                        ctx.fill();
                                    }
                                }
                            }
                            colPos++;
                        }
                        colPos++; /* 1-col gap between chars */
                    }

                    if (ledMode === 'scroll') {
                        ledScrollX += 0.3;
                        if (ledScrollX > textW + visibleCols) {
                            ledScrollX = -visibleCols;
                        }
                        ledAnim = requestAnimationFrame(ledDraw);
                    }
                }

                function ledShow(text, color, flash) {
                    if (ledAnim) { cancelAnimationFrame(ledAnim); ledAnim = null; }
                    ledText = text.toUpperCase();
                    ledColor = color || '#ffd700';
                    const el = document.getElementById('fh-slots-res');
                    const visW = el ? el.offsetWidth * 2 : 300;
                    const pitch = 4;
                    const textPxW = ledGetTextWidth(ledText) * pitch;

                    if (textPxW > visW) {
                        ledMode = 'scroll';
                        ledScrollX = -Math.floor(visW / pitch);
                        ledDraw();
                        ledAnim = requestAnimationFrame(ledDraw);
                    } else {
                        ledMode = 'static';
                        ledScrollX = 0;
                        ledDraw();
                        if (flash) {
                            /* Flash effect — redraw brighter a few times */
                            let flashes = 0;
                            const origColor = ledColor;
                            const flashInterval = setInterval(() => {
                                ledColor = flashes % 2 === 0 ? '#fff' : origColor;
                                ledDraw();
                                flashes++;
                                if (flashes >= 6) {
                                    clearInterval(flashInterval);
                                    ledColor = origColor;
                                    ledDraw();
                                }
                            }, 150);
                        }
                    }
                }

                function ledClear() {
                    if (ledAnim) { cancelAnimationFrame(ledAnim); ledAnim = null; }
                    ledText = '';
                    ledMode = 'static';
                    const el = document.getElementById('fh-slots-res');
                    if (el) {
                        const w = el.offsetWidth * 2, h = el.offsetHeight * 2;
                        ledCanvas.width = w; ledCanvas.height = h;
                        ledCtx.fillStyle = '#080808';
                        ledCtx.fillRect(0, 0, w, h);
                        /* Draw dim dots */
                        const pitch = 4, dotSize = 3, rows = 7;
                        const offsetY = Math.floor((h - rows * pitch) / 2);
                        for (let row = 0; row < rows; row++) {
                            for (let col = 0; col < Math.ceil(w / pitch); col++) {
                                ledCtx.fillStyle = 'rgba(30,30,30,.5)';
                                ledCtx.beginPath();
                                ledCtx.arc(col * pitch + dotSize/2, offsetY + row * pitch + dotSize/2, dotSize/2, 0, Math.PI*2);
                                ledCtx.fill();
                            }
                        }
                    }
                }

                /* Initialize lightboard with dim dots */
                setTimeout(ledClear, 50);

                /* ── Paytable modal toggle ── */
                const payModal = document.getElementById('fh-slots-pay');
                const closePay = () => { payModal.style.display = 'none'; };
                document.getElementById('fh-slots-pay-btn').addEventListener('click', () => { payModal.style.display = 'flex'; });
                document.getElementById('fh-slots-pay-x').addEventListener('click', closePay);
                document.getElementById('fh-slots-pay-bd').addEventListener('click', closePay);

                /* ── Build reel strip: final result first, then N random (scrolls top-to-bottom) ── */
                function buildStrip(finalId, count) {
                    const f = symMap[finalId] || SYMS[7];
                    let html = '<div class="fh-slots-sym"><img src="' + symBase + f.file + '"></div>';
                    for (let i = 0; i < count; i++) {
                        const s = pool[Math.floor(Math.random() * pool.length)];
                        html += '<div class="fh-slots-sym"><img src="' + symBase + s.file + '"></div>';
                    }
                    return html;
                }

                /* ── Spin one reel — 1 symbol visible per window ── */
                function spinReel(idx, finalId, duration) {
                    return new Promise(resolve => {
                        const strip = document.getElementById('fh-sr-' + idx);
                        const win   = document.getElementById('fh-sw-' + idx);
                        const symH  = win.offsetHeight;
                        const count = 22 + idx * 6;
                        strip.innerHTML = buildStrip(finalId, count);
                        strip.querySelectorAll('.fh-slots-sym').forEach(s => { s.style.height = symH + 'px'; });
                        strip.style.transition = 'none';
                        strip.style.transform = 'translateY(-' + (count * symH) + 'px)';
                        strip.offsetHeight;
                        strip.style.transition = 'transform ' + duration + 'ms cubic-bezier(.15,.85,.25,1)';
                        strip.style.transform = 'translateY(0)';
                        setTimeout(resolve, duration + 60);
                    });
                }

                /* ── Spin button ── */
                document.getElementById('fh-slots-spin').addEventListener('click', async () => {
                    if (spinning) return;
                    if (bet > chips) {
                        ledShow('NOT ENOUGH CHIPS', '#cc4444', false);
                        return;
                    }
                    spinning = true;
                    document.getElementById('fh-slots-spin').disabled = true;
                    ledClear();
                    [0,1,2].forEach(i => document.getElementById('fh-sw-' + i).classList.remove('winning'));

                    const res = await casinoPost('fishotel_casino_slots_spin', { bet: bet });
                    if (!res.success) {
                        ledShow(res.data.message || 'ERROR', '#cc4444', false);
                        spinning = false;
                        document.getElementById('fh-slots-spin').disabled = false;
                        return;
                    }
                    const d = res.data;

                    /* All reels start at once, stop left-to-right */
                    await Promise.all([
                        spinReel(0, d.reels[0], 1400),
                        spinReel(1, d.reels[1], 1900),
                        spinReel(2, d.reels[2], 2400)
                    ]);

                    updateChips(d.chips);

                    if (d.payout > 0) {
                        if (d.multiplier >= 5) {
                            [0,1,2].forEach(i => document.getElementById('fh-sw-' + i).classList.add('winning'));
                        } else {
                            if (d.reels[0]===d.reels[1]) { document.getElementById('fh-sw-0').classList.add('winning'); document.getElementById('fh-sw-1').classList.add('winning'); }
                            if (d.reels[1]===d.reels[2]) { document.getElementById('fh-sw-1').classList.add('winning'); document.getElementById('fh-sw-2').classList.add('winning'); }
                            if (d.reels[0]===d.reels[2]) { document.getElementById('fh-sw-0').classList.add('winning'); document.getElementById('fh-sw-2').classList.add('winning'); }
                        }
                        ledShow(d.multiplier + 'x WIN! +' + d.payout.toLocaleString(), '#ffd700', true);
                        fhChipFloat(d.payout, true);
                        if (d.multiplier >= 15) {
                            const flash = document.createElement('div');
                            flash.style.cssText = 'position:fixed;inset:0;background:radial-gradient(circle,rgba(255,215,0,.5) 0%,transparent 70%);pointer-events:none;z-index:99998;opacity:1;transition:opacity 1s';
                            document.body.appendChild(flash);
                            setTimeout(() => { flash.style.opacity = '0'; }, 100);
                            setTimeout(() => flash.remove(), 1200);
                        }
                        if (window.fhArcadeHandleWin) window.fhArcadeHandleWin(d);
                    } else {
                        ledShow('NO MATCH', '#cc4444', false);
                        fhChipFloat(-bet, false);
                    }
                    spinning = false;
                    document.getElementById('fh-slots-spin').disabled = false;
                });

                /* ── Reveal machine once cabinet image loads ── */
                const fsCabImg = document.getElementById('fh-slots-cab-img');
                const fsWrap = document.getElementById('fh-slots-wrap');
                const fsLoader = document.getElementById('fh-slots-loader');
                function fsReveal() {
                    fsLoader.remove();
                    fsWrap.classList.remove('fh-slot-machine-hidden');
                    fsWrap.classList.add('fh-slot-machine-reveal');
                    /* Size initial symbols to fill reel windows (must run after reveal so offsetHeight is accurate) */
                    document.querySelectorAll('.fh-slots-rw').forEach(function(win) {
                        var symH = win.offsetHeight;
                        win.querySelector('.fh-slots-sym').style.height = symH + 'px';
                    });
                }
                if (fsCabImg.complete) { fsReveal(); } else { fsCabImg.addEventListener('load', fsReveal); }
            }

            /* ═══════════════════════════════════════════════
             *  SLOT SELECTION MENU
             * ═══════════════════════════════════════════════ */

            function renderSlotSelection(body) {
                const cab1 = '<?php echo esc_url( plugins_url( "assists/casino/slots/FisHotel-Slot-Cabnet-Body-01.png", FISHOTEL_PLUGIN_FILE ) ); ?>';
                const cab2 = '<?php echo esc_url( plugins_url( "assists/casino/slots/FisHotel-Slot-Cabnet-Body-02.png", FISHOTEL_PLUGIN_FILE ) ); ?>';
                body.innerHTML =
                    '<div class="fh-slot-select">' +
                        '<div class="fh-slot-select-card" id="fh-sel-fish">' +
                            '<img class="fh-slot-select-img" src="' + cab1 + '" alt="Fish Slots">' +
                            '<div class="fh-slot-select-name">Fish Slots</div>' +
                            '<div class="fh-slot-select-sub">3-Reel Classic</div>' +
                        '</div>' +
                        '<div class="fh-slot-select-card" id="fh-sel-sapphire">' +
                            '<img class="fh-slot-select-img" src="' + cab2 + '" alt="Sapphire Poker">' +
                            '<div class="fh-slot-select-name">Sapphire Poker</div>' +
                            '<div class="fh-slot-select-sub">4-Reel Cards</div>' +
                        '</div>' +
                    '</div>';
                document.getElementById('fh-sel-fish').addEventListener('click', () => {
                    renderSlotMachine(body);
                });
                document.getElementById('fh-sel-sapphire').addEventListener('click', () => {
                    renderSapphirePokerSlots(body);
                });
            }

            /* ═══════════════════════════════════════════════
             *  BINGO HALL (classic 75-ball)
             * ═══════════════════════════════════════════════ */

            function renderBingoHall(body) {
                const COLS = ['B','I','N','G','O'];
                const RANGES = [[1,15],[16,30],[31,45],[46,60],[61,75]];
                const MAX_BALLS = 35;

                /* ── Mini bingo card SVG for paytable ── */
                function bingoPatSVG(hitCells) {
                    const S = 9, G = 1, W = S * 5 + G * 4;
                    const hitSet = new Set(hitCells.map(function(c){ return c[0]+','+c[1]; }));
                    let svg = '<svg viewBox="0 0 ' + W + ' ' + W + '" width="38" height="38" style="flex-shrink:0;border-radius:3px;border:1px solid #96885f">';
                    for (let r = 0; r < 5; r++) {
                        for (let c = 0; c < 5; c++) {
                            const x = c * (S + G), y = r * (S + G);
                            const isHit = hitSet.has(c + ',' + r);
                            const isFree = (c === 2 && r === 2);
                            let fill = '#f5f0e8';
                            if (isHit) fill = '#4a90d9';
                            else if (isFree) fill = '#d4c9a8';
                            svg += '<rect x="' + x + '" y="' + y + '" width="' + S + '" height="' + S + '" rx="1" fill="' + fill + '"/>';
                            if (isFree && !isHit) {
                                svg += '<text x="' + (x + S/2) + '" y="' + (y + S/2 + 2) + '" text-anchor="middle" font-size="4" fill="#96885f" font-weight="700">★</text>';
                            }
                        }
                    }
                    svg += '</svg>';
                    return svg;
                }

                function buildPaytableEntries() {
                    var patterns = [
                        { name: 'Any Line', mult: '0.75x', cells: [[0,2],[1,2],[2,2],[3,2],[4,2]] },
                        { name: 'Diagonal', mult: '1.5x', cells: [[0,0],[1,1],[2,2],[3,3],[4,4]] },
                        { name: 'Postage Stamp', mult: '1.5x', cells: [[0,0],[1,0],[0,1],[1,1]] },
                        { name: '4 Corners', mult: '2.5x', cells: [[0,0],[4,0],[0,4],[4,4]] },
                        { name: 'Wine Glass', mult: '5x', cells: [[0,0],[1,0],[2,0],[3,0],[4,0],[1,1],[3,1],[2,2],[2,3],[1,4],[2,4],[3,4]] },
                        { name: 'Lucky 7', mult: '8x', cells: [[0,0],[1,0],[2,0],[3,0],[4,0],[3,1],[2,2],[1,3],[0,4]] },
                        { name: 'X Pattern', mult: '15x', jack: true, cells: [[0,0],[1,1],[2,2],[3,3],[4,4],[4,0],[3,1],[1,3],[0,4]] }
                    ];
                    var html = '';
                    for (var i = 0; i < patterns.length; i++) {
                        var p = patterns[i];
                        var cls = p.jack ? ' jackpot' : '';
                        html += '<div class="fh-bingo-pay-entry' + cls + '">' +
                            bingoPatSVG(p.cells) +
                            '<span class="fh-bingo-pay-name">' + p.name + '</span>' +
                            '<span class="fh-bingo-pay-mult">' + p.mult + '</span>' +
                        '</div>';
                    }
                    return html;
                }
                let bet = 50, playing = false, autoDaub = true, cashedOut = false;
                let card = [], daubed = [], calledNumbers = [], callerInterval = null;
                let patternsWon = {}, totalWinnings = 0;
                let audioCtx = null, muted = false;

                /* ── Sound effects ── */
                function playSound(freq, dur) {
                    if (muted) return;
                    try {
                        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                        const osc = audioCtx.createOscillator();
                        const gain = audioCtx.createGain();
                        osc.frequency.value = freq;
                        gain.gain.value = 0.15;
                        osc.connect(gain);
                        gain.connect(audioCtx.destination);
                        osc.start();
                        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
                        osc.stop(audioCtx.currentTime + dur);
                    } catch(e) {}
                }
                function sfxCall() { playSound(440, 0.1); }
                function sfxWin()  { playSound(660, 0.15); setTimeout(() => playSound(880, 0.2), 150); }
                function sfxDaub() { playSound(520, 0.06); }

                /* ── Letter for number ── */
                function numLetter(n) {
                    if (n <= 15) return 'B';
                    if (n <= 30) return 'I';
                    if (n <= 45) return 'N';
                    if (n <= 60) return 'G';
                    return 'O';
                }

                /* ── Generate random 5x5 card (column-major: card[col][row]) ── */
                function generateCard() {
                    const c = [];
                    for (let col = 0; col < 5; col++) {
                        const [min, max] = RANGES[col];
                        const nums = [];
                        while (nums.length < 5) {
                            const n = min + Math.floor(Math.random() * (max - min + 1));
                            if (!nums.includes(n)) nums.push(n);
                        }
                        c.push(nums);
                    }
                    c[2][2] = 0; /* FREE */
                    return c;
                }

                /* ── Get call speed based on auto-daub ── */
                function getCallSpeed() { return autoDaub ? 2500 : 4000; }

                /* ── Restart interval at new speed ── */
                function restartInterval() {
                    if (!playing || !callerInterval) return;
                    clearInterval(callerInterval);
                    callerInterval = setInterval(callNumber, getCallSpeed());
                }

                /* ── Build HTML ── */
                body.innerHTML =
                    '<div class="fh-bingo" style="position:relative">' +
                        '<div class="fh-bingo-caller" id="fh-bingo-caller">SELECT BET &amp; BUY A CARD</div>' +
                        '<div class="fh-bingo-history" id="fh-bingo-history"></div>' +
                        '<div class="fh-bingo-card-wrap">' +
                            '<div class="fh-bingo-headers">' + COLS.map(c => '<span>' + c + '</span>').join('') + '</div>' +
                            '<div class="fh-bingo-grid" id="fh-bingo-grid"></div>' +
                        '</div>' +
                        '<div class="fh-bingo-controls">' +
                            '<div class="fh-bingo-controls-bets">' +
                                '<button class="fh-bingo-bet active" data-bet="10">10</button>' +
                                '<button class="fh-bingo-bet" data-bet="50">50</button>' +
                                '<button class="fh-bingo-bet" data-bet="100">100</button>' +
                                '<button class="fh-bingo-bet" data-bet="250">250</button>' +
                            '</div>' +
                            '<div class="fh-bingo-controls-row">' +
                                '<button class="fh-bingo-btn fh-bingo-btn-buy" id="fh-bingo-buy" style="flex:1">BUY CARD</button>' +
                                '<button class="fh-bingo-btn fh-bingo-btn-cashout" id="fh-bingo-cashout" style="flex:1">CASH OUT</button>' +
                            '</div>' +
                            '<div class="fh-bingo-controls-utils">' +
                                '<label class="fh-bingo-autodaub"><input type="checkbox" id="fh-bingo-auto" checked> AUTO-DAUB</label>' +
                                '<label class="fh-bingo-autodaub"><input type="checkbox" id="fh-bingo-mute"> MUTE</label>' +
                                '<button class="fh-bingo-btn" id="fh-bingo-pay-btn" style="background:rgba(0,0,0,.5);color:#96885f;font-size:10px;padding:4px 10px;border:1px solid rgba(150,136,95,.4)">PAY TABLE</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="fh-bingo-stats">' +
                            '<span><img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" style="width:14px;height:14px;vertical-align:middle"> <span class="fh-arc-chip-mirror">' + Number(chips).toLocaleString() + '</span></span>' +
                            '<span>Winnings: <span class="bingo-winnings" id="fh-bingo-winnings">0</span></span>' +
                            '<span>Balls: <span id="fh-bingo-ballcount">0</span>/' + MAX_BALLS + '</span>' +
                        '</div>' +
                    '</div>' +
                    /* Paytable modal */
                    '<div id="fh-bingo-paytable" style="display:none;position:fixed;inset:0;z-index:999999;align-items:center;justify-content:center">' +
                        '<div style="position:absolute;inset:0;background:rgba(0,0,0,.8);cursor:pointer" id="fh-bingo-pay-bd"></div>' +
                        '<div style="position:relative;background:linear-gradient(135deg,#2e2418,#1a1410);border:3px solid #96885f;border-radius:14px;padding:20px 16px;max-width:440px;width:92%;box-shadow:0 16px 50px rgba(0,0,0,.6)">' +
                            '<button style="position:absolute;top:8px;right:12px;width:28px;height:28px;background:rgba(255,255,255,.08);border:2px solid rgba(150,136,95,.4);border-radius:50%;color:#96885f;font-size:16px;cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center" id="fh-bingo-pay-x">&times;</button>' +
                            '<div style="text-align:center;font-family:Special Elite,monospace;font-size:18px;color:#ffd700;margin:0 0 4px">BINGO HALL PAYOUTS</div>' +
                            '<div style="text-align:center;font-family:Oswald,sans-serif;font-size:11px;color:#96885f;margin:0 0 10px">35 Balls Called Per Game</div>' +
                            buildPaytableEntries() +
                            '<div style="text-align:center;font-size:10px;color:#96885f;margin-top:10px;font-family:Oswald,sans-serif">Patterns stack — win multiple bonuses per game!<br>Cash out anytime to collect winnings.</div>' +
                        '</div>' +
                    '</div>';

                bet = 10;
                const grid = document.getElementById('fh-bingo-grid');
                const caller = document.getElementById('fh-bingo-caller');
                const history = document.getElementById('fh-bingo-history');
                const buyBtn = document.getElementById('fh-bingo-buy');
                const cashBtn = document.getElementById('fh-bingo-cashout');
                const winDisp = document.getElementById('fh-bingo-winnings');
                const ballCount = document.getElementById('fh-bingo-ballcount');

                /* ── Paytable modal ── */
                const payModal = document.getElementById('fh-bingo-paytable');
                document.getElementById('fh-bingo-pay-btn').addEventListener('click', () => { payModal.style.display = 'flex'; });
                document.getElementById('fh-bingo-pay-bd').addEventListener('click', () => { payModal.style.display = 'none'; });
                document.getElementById('fh-bingo-pay-x').addEventListener('click', () => { payModal.style.display = 'none'; });

                /* ── Render empty grid ── */
                function renderGrid() {
                    let html = '';
                    for (let row = 0; row < 5; row++) {
                        for (let col = 0; col < 5; col++) {
                            const val = card.length ? card[col][row] : '';
                            const isFree = col === 2 && row === 2;
                            const cls = isFree ? 'fh-bingo-cell free daubed' : 'fh-bingo-cell';
                            const txt = isFree ? 'FREE' : (val || '');
                            html += '<div class="' + cls + '" data-col="' + col + '" data-row="' + row + '">' + txt + '</div>';
                        }
                    }
                    grid.innerHTML = html;
                    grid.querySelectorAll('.fh-bingo-cell:not(.free)').forEach(cell => {
                        cell.addEventListener('click', function() {
                            if (!playing) return;
                            const c = parseInt(this.dataset.col);
                            const r = parseInt(this.dataset.row);
                            const num = card[c][r];
                            if (calledNumbers.includes(num) && !daubed[c][r]) {
                                daubed[c][r] = true;
                                this.classList.add('daubed');
                                sfxDaub();
                                checkPatterns();
                            }
                        });
                    });
                }
                card = generateCard();
                renderGrid();

                /* ── Bet buttons ── */
                body.querySelectorAll('.fh-bingo-bet').forEach(b => {
                    b.addEventListener('click', function() {
                        if (playing) return;
                        body.querySelectorAll('.fh-bingo-bet').forEach(x => x.classList.remove('active'));
                        this.classList.add('active');
                        bet = parseInt(this.dataset.bet);
                    });
                });

                /* ── Auto-daub toggle — changes call speed ── */
                document.getElementById('fh-bingo-auto').addEventListener('change', function() {
                    autoDaub = this.checked;
                    restartInterval();
                });

                document.getElementById('fh-bingo-mute').addEventListener('change', function() {
                    muted = this.checked;
                });

                /* ── BUY CARD ── */
                buyBtn.addEventListener('click', async function() {
                    if (playing) return;
                    if (bet > chips) { caller.textContent = 'NOT ENOUGH CHIPS!'; return; }
                    this.disabled = true;
                    const res = await casinoPost('fishotel_casino_bingo_buy', { bet: bet });
                    if (!res.success) {
                        caller.textContent = res.data?.message || 'Error!';
                        this.disabled = false;
                        return;
                    }
                    updateChips(res.data.chips);

                    /* Clear any leftover hyper mode interval */
                    if (callerInterval) { clearInterval(callerInterval); callerInterval = null; }

                    playing = true;
                    cashedOut = false;
                    calledNumbers = [];
                    patternsWon = {};
                    totalWinnings = 0;
                    winDisp.textContent = '0';
                    ballCount.textContent = '0';
                    history.innerHTML = '';

                    card = generateCard();
                    daubed = Array.from({length:5}, () => Array(5).fill(false));
                    daubed[2][2] = true; /* FREE */
                    renderGrid();

                    cashBtn.style.display = 'inline-block';
                    cashBtn.disabled = false;
                    cashBtn.textContent = 'CASH OUT';
                    this.style.display = 'none';

                    caller.textContent = 'GET READY...';
                    setTimeout(() => {
                        callerInterval = setInterval(callNumber, getCallSpeed());
                    }, 1500);
                });

                /* ── Call a number ── */
                function callNumber() {
                    /* 35-ball limit */
                    if (calledNumbers.length >= MAX_BALLS) {
                        caller.textContent = 'ALL ' + MAX_BALLS + ' BALLS CALLED!';
                        setTimeout(endGame, 2000);
                        clearInterval(callerInterval);
                        callerInterval = null;
                        return;
                    }
                    let n;
                    do { n = 1 + Math.floor(Math.random() * 75); } while (calledNumbers.includes(n));
                    calledNumbers.push(n);
                    ballCount.textContent = calledNumbers.length;

                    const letter = numLetter(n);
                    caller.textContent = letter + '-' + n;
                    sfxCall();

                    /* Update history (last 12) — show letter prefix */
                    const ball = document.createElement('div');
                    ball.className = 'fh-bingo-history-ball latest';
                    ball.textContent = letter + '-' + n;
                    history.querySelectorAll('.latest').forEach(b => b.classList.remove('latest'));
                    history.appendChild(ball);
                    if (history.children.length > 12) history.removeChild(history.firstChild);

                    /* Highlight matching cell on card */
                    for (let col = 0; col < 5; col++) {
                        for (let row = 0; row < 5; row++) {
                            if (card[col][row] === n) {
                                const cell = grid.querySelector('[data-col="'+col+'"][data-row="'+row+'"]');
                                if (cell) cell.classList.add('called');
                                if (autoDaub) {
                                    daubed[col][row] = true;
                                    if (cell) cell.classList.add('daubed');
                                    sfxDaub();
                                    checkPatterns();
                                }
                            }
                        }
                    }
                }

                /* ── Pattern detection ── */
                function checkPatterns() {
                    let newWins = [];

                    /* Rows */
                    if (!patternsWon.row) {
                        for (let r = 0; r < 5; r++) {
                            if (daubed[0][r] && daubed[1][r] && daubed[2][r] && daubed[3][r] && daubed[4][r]) {
                                patternsWon.row = true;
                                newWins.push({ name: 'LINE!', mult: 0.75 });
                                break;
                            }
                        }
                    }

                    /* Columns */
                    if (!patternsWon.col) {
                        for (let c = 0; c < 5; c++) {
                            if (daubed[c][0] && daubed[c][1] && daubed[c][2] && daubed[c][3] && daubed[c][4]) {
                                patternsWon.col = true;
                                newWins.push({ name: 'COLUMN!', mult: 0.75 });
                                break;
                            }
                        }
                    }

                    /* Diagonals (either one) */
                    if (!patternsWon.diag) {
                        const d1 = daubed[0][0] && daubed[1][1] && daubed[2][2] && daubed[3][3] && daubed[4][4];
                        const d2 = daubed[0][4] && daubed[1][3] && daubed[2][2] && daubed[3][1] && daubed[4][0];
                        if (d1 || d2) {
                            patternsWon.diag = true;
                            newWins.push({ name: 'DIAGONAL!', mult: 1.5 });
                        }
                    }

                    /* Postage Stamp — any 2x2 block in a corner */
                    if (!patternsWon.stamp) {
                        const stamps = [
                            [[0,0],[1,0],[0,1],[1,1]],
                            [[3,0],[4,0],[3,1],[4,1]],
                            [[0,3],[1,3],[0,4],[1,4]],
                            [[3,3],[4,3],[3,4],[4,4]]
                        ];
                        for (const sq of stamps) {
                            if (sq.every(([c,r]) => daubed[c][r])) {
                                patternsWon.stamp = true;
                                newWins.push({ name: 'POSTAGE STAMP!', mult: 1.5 });
                                break;
                            }
                        }
                    }

                    /* 4 Corners */
                    if (!patternsWon.corners) {
                        if (daubed[0][0] && daubed[4][0] && daubed[0][4] && daubed[4][4]) {
                            patternsWon.corners = true;
                            newWins.push({ name: '4 CORNERS!', mult: 2.5 });
                        }
                    }

                    /* Wine Glass — rim + V-sides + stem + base */
                    if (!patternsWon.wine) {
                        const wc = [[0,0],[1,0],[2,0],[3,0],[4,0],[1,1],[3,1],[2,2],[2,3],[1,4],[2,4],[3,4]];
                        if (wc.every(([c,r]) => daubed[c][r])) {
                            patternsWon.wine = true;
                            newWins.push({ name: 'WINE GLASS!', mult: 5 });
                        }
                    }

                    /* Lucky 7 — top row + diagonal down-left */
                    if (!patternsWon.lucky7) {
                        const lc = [[0,0],[1,0],[2,0],[3,0],[4,0],[3,1],[2,2],[1,3],[0,4]];
                        if (lc.every(([c,r]) => daubed[c][r])) {
                            patternsWon.lucky7 = true;
                            newWins.push({ name: 'LUCKY 7!', mult: 8 });
                        }
                    }

                    /* X Pattern — both diagonals complete (JACKPOT) */
                    if (!patternsWon.xpattern) {
                        const d1 = daubed[0][0] && daubed[1][1] && daubed[2][2] && daubed[3][3] && daubed[4][4];
                        const d2 = daubed[0][4] && daubed[1][3] && daubed[2][2] && daubed[3][1] && daubed[4][0];
                        if (d1 && d2) {
                            patternsWon.xpattern = true;
                            newWins.push({ name: 'X PATTERN JACKPOT!', mult: 15 });
                        }
                    }

                    /* Award wins */
                    newWins.forEach(w => {
                        const payout = Math.floor(bet * w.mult);
                        totalWinnings += payout;
                        winDisp.textContent = Number(totalWinnings).toLocaleString();
                        showWinBanner(w.name + ' ' + w.mult + 'x');
                        sfxWin();
                        fhChipFloat(payout, true);
                    });
                }

                /* ── Win banner ── */
                function showWinBanner(text) {
                    const banner = document.createElement('div');
                    banner.className = 'fh-bingo-win-banner';
                    banner.textContent = text;
                    body.querySelector('.fh-bingo').appendChild(banner);
                    setTimeout(() => banner.remove(), 2500);
                }

                /* ── End game / Cash out ── */
                async function endGame() {
                    if (cashedOut) return;
                    cashedOut = true;
                    if (callerInterval) { clearInterval(callerInterval); callerInterval = null; }
                    playing = false;

                    if (totalWinnings > 0) {
                        const res = await casinoPost('fishotel_casino_bingo_cashout', { bet: bet, winnings: totalWinnings });
                        if (res.success) updateChips(res.data.chips);
                        caller.textContent = 'WON ' + Number(totalWinnings).toLocaleString() + ' CHIPS!';
                        sfxWin();
                    } else {
                        caller.textContent = 'GAME OVER \u2014 NO WINS';
                    }

                    cashBtn.style.display = 'none';
                    cashBtn.disabled = false;
                    cashBtn.textContent = 'CASH OUT';
                    buyBtn.style.display = 'inline-block';
                    buyBtn.disabled = false;
                }

                cashBtn.addEventListener('click', function() {
                    if (!playing) return;
                    /* Enter hyper mode — 1 ball/sec, auto-daub forced on */
                    if (callerInterval) clearInterval(callerInterval);
                    autoDaub = true;
                    const autoChk = document.getElementById('fh-bingo-auto');
                    if (autoChk) autoChk.checked = true;
                    cashBtn.disabled = true;
                    cashBtn.textContent = 'HYPER MODE!';
                    caller.textContent = '⚡ HYPER MODE ⚡';
                    callerInterval = setInterval(callNumber, 800);
                });
            }

            /* ═══════════════════════════════════════════════
             *  SAPPHIRE POKER SLOTS (4-reel card machine)
             * ═══════════════════════════════════════════════ */

            function renderSapphirePokerSlots(body) {
                const cabinetUrl = '<?php echo esc_url( plugins_url( "assists/casino/slots/FisHotel-Slot-Cabnet-Body-02.png", FISHOTEL_PLUGIN_FILE ) ); ?>?v=<?php echo FISHOTEL_VERSION; ?>';
                const cardBase   = '<?php echo esc_url( plugins_url( "assists/casino/slots/", FISHOTEL_PLUGIN_FILE ) ); ?>';

                const CARDS = [
                    { id:'7',  file:'7-Card-2.png',  pay4:100, pay3:25, label:'Lucky 7' },
                    { id:'A',  file:'A-Card-2.png',  pay4:50,  pay3:15, label:'Ace' },
                    { id:'K',  file:'K-Card-2.png',  pay4:25,  pay3:6,  label:'King' },
                    { id:'Q',  file:'Q-Card-2.png',  pay4:15,  pay3:4,  label:'Queen' },
                    { id:'J',  file:'J-Card-2.png',  pay4:10,  pay3:3,  label:'Jack' },
                    { id:'10', file:'10-Card.png', pay4:8,   pay3:2,  label:'Ten' },
                ];
                const cardMap = {}; CARDS.forEach(c => cardMap[c.id] = c);

                /* Build weighted pool matching backend: 10=8, J=6, Q=5, K=3, A=2, 7=1 */
                const pool = [];
                [1,2,3,5,6,8].forEach((w,i) => { for(let j=0;j<w;j++) pool.push(CARDS[i]); });

                let bet = 50, spinning = false;

                /* ── Build HTML ── */
                const facePay =
                    /* Left column: 4-of-a-Kind */
                    '<span style="grid-column:1;color:rgba(150,136,95,.7);font-weight:700">4-OF-A-KIND</span>' +
                    /* Right column: 3-of-a-Kind */
                    '<span style="grid-column:2;color:rgba(150,136,95,.7);font-weight:700">3-OF-A-KIND</span>' +
                    '<span>7-7-7-7 <em class="sp-mult">100x</em></span>' +
                    '<span>7-7-7 <em class="sp-mult">25x</em></span>' +
                    '<span>A-A-A-A <em class="sp-mult">50x</em></span>' +
                    '<span>A-A-A <em class="sp-mult">15x</em></span>' +
                    '<span>K-K-K-K <em class="sp-mult">25x</em></span>' +
                    '<span>K-K-K <em class="sp-mult">6x</em></span>' +
                    '<span>Q-Q-Q-Q <em class="sp-mult">15x</em></span>' +
                    '<span>Q-Q-Q <em class="sp-mult">4x</em></span>' +
                    '<span>J-J-J-J <em class="sp-mult">10x</em></span>' +
                    '<span>J-J-J <em class="sp-mult">3x</em></span>' +
                    '<span>10-10-10-10 <em class="sp-mult">8x</em></span>' +
                    '<span>10-10-10 <em class="sp-mult">2x</em></span>' +
                    '<span>TWO PAIR <em class="sp-mult">2x</em></span>' +
                    '<span>K+ PAIR <em class="sp-mult">1x</em></span>';

                body.innerHTML =
                    '<div class="fh-slot-loading" id="fh-sapphire-loader"><div class="fh-slot-spinner"></div><div class="fh-slot-loading-text">LOADING</div></div>' +
                    '<div class="fh-sapphire fh-slot-machine-hidden" id="fh-sapphire-wrap">' +
                        '<div class="fh-sapphire-machine">' +
                            '<img src="' + cabinetUrl + '" alt="Sapphire Poker Slots" id="fh-sapphire-cab-img">' +
                            /* 4 reel windows */
                            '<div class="fh-sapphire-rw" id="fh-spw-0"><div class="fh-sapphire-strip" id="fh-spr-0"><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[2].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[0].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[4].file+'"></div></div></div>' +
                            '<div class="fh-sapphire-rw" id="fh-spw-1"><div class="fh-sapphire-strip" id="fh-spr-1"><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[1].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[3].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[5].file+'"></div></div></div>' +
                            '<div class="fh-sapphire-rw" id="fh-spw-2"><div class="fh-sapphire-strip" id="fh-spr-2"><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[4].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[2].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[0].file+'"></div></div></div>' +
                            '<div class="fh-sapphire-rw" id="fh-spw-3"><div class="fh-sapphire-strip" id="fh-spr-3"><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[3].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[1].file+'"></div><div class="fh-sapphire-sym"><img src="'+cardBase+CARDS[5].file+'"></div></div></div>' +
                            /* LED Lightboard */
                            '<canvas class="fh-sapphire-result" id="fh-sapphire-led" width="400" height="50"></canvas>' +
                            /* SPIN button (round) */
                            '<button class="fh-sapphire-spin" id="fh-sapphire-spin">SPIN</button>' +
                            /* Chip balance */
                            '<div class="fh-sapphire-chips"><img src="<?php echo esc_url( $chip_url ); ?>" alt="chips" style="width:16px;height:16px"><span class="fh-arc-chip-mirror">' + Number(chips).toLocaleString() + '</span></div>' +
                            /* Bet buttons */
                            '<button class="fh-sapphire-bet" id="fh-spbet-10" data-bet="10">10</button>' +
                            '<button class="fh-sapphire-bet active" id="fh-spbet-50" data-bet="50">50</button>' +
                            '<button class="fh-sapphire-bet" id="fh-spbet-100" data-bet="100">100</button>' +
                            '<button class="fh-sapphire-bet" id="fh-spbet-250" data-bet="250">250</button>' +
                            /* Pay Table button */
                            '<button class="fh-sapphire-payouts-btn" id="fh-sapphire-pay-btn">PAY TABLE</button>' +
                            /* On-cabinet paytable */
                            '<div class="fh-sapphire-face-pay">' + facePay + '</div>' +
                        '</div>' +
                    '</div>' +
                    /* Paytable modal (hidden) */
                    '<div class="fh-sapphire-pay-modal" id="fh-sapphire-pay" style="display:none;">' +
                        '<div class="fh-sapphire-pay-bd" id="fh-sapphire-pay-bd"></div>' +
                        '<div class="fh-sapphire-pay-card">' +
                            '<button class="fh-sapphire-pay-close" id="fh-sapphire-pay-x">&times;</button>' +
                            '<div class="fh-sapphire-pay-title">SAPPHIRE POKER PAYOUTS</div>' +
                            '<div class="fh-sapphire-pay-sub">4-of-a-Kind &amp; 3-of-a-Kind</div>' +
                            '<div class="fh-sapphire-pay-grid">' +
                            CARDS.map(c =>
                                '<div class="fh-sapphire-pay-row">' +
                                    '<div class="fh-sapphire-pay-syms"><img src="'+cardBase+c.file+'"><img src="'+cardBase+c.file+'"><img src="'+cardBase+c.file+'"><img src="'+cardBase+c.file+'"></div>' +
                                    '<div class="fh-sapphire-pay-mult">'+c.pay4+'x</div>' +
                                '</div>'
                            ).join('') +
                            '<div class="fh-sapphire-pay-section">3-of-a-Kind</div>' +
                            CARDS.map(c =>
                                '<div class="fh-sapphire-pay-row">' +
                                    '<div class="fh-sapphire-pay-syms"><img src="'+cardBase+c.file+'"><img src="'+cardBase+c.file+'"><img src="'+cardBase+c.file+'"></div>' +
                                    '<div class="fh-sapphire-pay-mult">'+c.pay3+'x</div>' +
                                '</div>'
                            ).join('') +
                            '<div class="fh-sapphire-pay-section">Two Pair: <span style="color:#ffd700;font-weight:700;">2x</span> &nbsp;&bull;&nbsp; K+ Pair: <span style="color:#ffd700;font-weight:700;">1x</span></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';

                /* ── Size initial symbols: 3 cards visible per window ── */
                document.querySelectorAll('.fh-sapphire-rw').forEach(function(win) {
                    var symH = Math.floor(win.offsetHeight / 3);
                    win.querySelectorAll('.fh-sapphire-sym').forEach(function(s) { s.style.height = symH + 'px'; });
                });

                /* ── Bet buttons ── */
                body.querySelectorAll('.fh-sapphire-bet').forEach(b => {
                    b.addEventListener('click', () => {
                        if (spinning) return;
                        body.querySelectorAll('.fh-sapphire-bet').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        bet = parseInt(b.dataset.bet);
                    });
                });

                /* ═══ LED DOT-MATRIX LIGHTBOARD ENGINE (Sapphire) ═══ */
                const spLedCanvas = document.getElementById('fh-sapphire-led');
                const spLedCtx = spLedCanvas.getContext('2d');
                let spLedAnim = null, spLedScrollX = 0, spLedText = '', spLedColor = '#ffd700', spLedMode = 'static';

                /* 5x7 dot-matrix font — same as Fish Slots */
                const SP_LED_FONT = {
                    'A':[0x1F,0x24,0x44,0x24,0x1F],'B':[0x7F,0x49,0x49,0x49,0x36],'C':[0x3E,0x41,0x41,0x41,0x22],
                    'D':[0x7F,0x41,0x41,0x41,0x3E],'E':[0x7F,0x49,0x49,0x49,0x41],'F':[0x7F,0x48,0x48,0x48,0x40],
                    'G':[0x3E,0x41,0x49,0x49,0x2E],'H':[0x7F,0x08,0x08,0x08,0x7F],'I':[0x41,0x41,0x7F,0x41,0x41],
                    'J':[0x02,0x01,0x01,0x01,0x7E],'K':[0x7F,0x08,0x14,0x22,0x41],'L':[0x7F,0x01,0x01,0x01,0x01],
                    'M':[0x7F,0x20,0x10,0x20,0x7F],'N':[0x7F,0x10,0x08,0x04,0x7F],'O':[0x3E,0x41,0x41,0x41,0x3E],
                    'P':[0x7F,0x48,0x48,0x48,0x30],'Q':[0x3E,0x41,0x45,0x42,0x3D],'R':[0x7F,0x48,0x4C,0x4A,0x31],
                    'S':[0x32,0x49,0x49,0x49,0x26],'T':[0x40,0x40,0x7F,0x40,0x40],'U':[0x7E,0x01,0x01,0x01,0x7E],
                    'V':[0x7C,0x02,0x01,0x02,0x7C],'W':[0x7F,0x02,0x04,0x02,0x7F],'X':[0x63,0x14,0x08,0x14,0x63],
                    'Y':[0x60,0x10,0x0F,0x10,0x60],'Z':[0x43,0x45,0x49,0x51,0x61],
                    '0':[0x3E,0x45,0x49,0x51,0x3E],'1':[0x00,0x21,0x7F,0x01,0x00],'2':[0x23,0x45,0x49,0x49,0x31],
                    '3':[0x22,0x41,0x49,0x49,0x36],'4':[0x0C,0x14,0x24,0x7F,0x04],'5':[0x72,0x51,0x51,0x51,0x4E],
                    '6':[0x3E,0x49,0x49,0x49,0x26],'7':[0x40,0x47,0x48,0x50,0x60],'8':[0x36,0x49,0x49,0x49,0x36],
                    '9':[0x32,0x49,0x49,0x49,0x3E],
                    '+':[0x08,0x08,0x3E,0x08,0x08],'-':[0x08,0x08,0x08,0x08,0x08],'!':[0x00,0x00,0x7D,0x00,0x00],
                    'x':[0x22,0x14,0x08,0x14,0x22],
                    ' ':[0x00,0x00,0x00,0x00,0x00],'.':[0x00,0x01,0x00,0x00,0x00],',':[0x00,0x01,0x02,0x00,0x00],
                    ':':[0x00,0x14,0x00,0x00,0x00],'?':[0x20,0x40,0x4D,0x48,0x30],
                };

                function spLedGetTextWidth(text) {
                    let w = 0;
                    for (let i = 0; i < text.length; i++) {
                        w += (SP_LED_FONT[text[i]] ? 5 : 3) + 1;
                    }
                    return w - 1;
                }

                function spLedDraw() {
                    const el = document.getElementById('fh-sapphire-led');
                    if (!el) { cancelAnimationFrame(spLedAnim); return; }
                    const w = el.offsetWidth * 2, h = el.offsetHeight * 2;
                    if (spLedCanvas.width !== w || spLedCanvas.height !== h) {
                        spLedCanvas.width = w; spLedCanvas.height = h;
                    }
                    const ctx = spLedCtx;
                    const dotSize = 3, gap = 1, pitch = dotSize + gap;
                    const rows = 7, charW = 5;
                    const totalH = rows * pitch;
                    const offsetY = Math.floor((h - totalH) / 2);
                    const textW = spLedGetTextWidth(spLedText);
                    const totalPxW = textW * pitch;
                    const visibleCols = Math.floor(w / pitch);

                    ctx.fillStyle = '#080808';
                    ctx.fillRect(0, 0, w, h);

                    /* Draw dim dot grid background */
                    const gridCols = Math.ceil(w / pitch), gridRows = rows;
                    for (let row = 0; row < gridRows; row++) {
                        for (let col = 0; col < gridCols; col++) {
                            ctx.fillStyle = 'rgba(30,30,30,.5)';
                            ctx.beginPath();
                            ctx.arc(col * pitch + dotSize/2, offsetY + row * pitch + dotSize/2, dotSize/2, 0, Math.PI*2);
                            ctx.fill();
                        }
                    }

                    /* Determine scroll offset */
                    let scrollOff = 0;
                    if (spLedMode === 'scroll') {
                        scrollOff = Math.floor(spLedScrollX);
                    } else {
                        scrollOff = -Math.floor((w - totalPxW) / 2 / pitch);
                    }

                    /* Parse color for glow */
                    const isGold = spLedColor === '#ffd700';
                    const glowR = isGold ? 255 : 204, glowG = isGold ? 215 : 68, glowB = isGold ? 0 : 68;

                    /* Draw lit dots */
                    let colPos = 0;
                    for (let ci = 0; ci < spLedText.length; ci++) {
                        const ch = spLedText[ci];
                        const glyph = SP_LED_FONT[ch] || SP_LED_FONT[' '];
                        const cw = glyph.length;
                        for (let gc = 0; gc < cw; gc++) {
                            const screenCol = colPos - scrollOff;
                            if (screenCol >= -1 && screenCol < gridCols + 1) {
                                const colData = glyph[gc];
                                for (let row = 0; row < rows; row++) {
                                    if (colData & (1 << (rows - 1 - row))) {
                                        const px = screenCol * pitch + dotSize/2;
                                        const py = offsetY + row * pitch + dotSize/2;
                                        /* Glow */
                                        ctx.fillStyle = 'rgba('+glowR+','+glowG+','+glowB+',.15)';
                                        ctx.beginPath();
                                        ctx.arc(px, py, dotSize, 0, Math.PI*2);
                                        ctx.fill();
                                        /* Bright dot */
                                        ctx.fillStyle = spLedColor;
                                        ctx.beginPath();
                                        ctx.arc(px, py, dotSize/2, 0, Math.PI*2);
                                        ctx.fill();
                                    }
                                }
                            }
                            colPos++;
                        }
                        colPos++; /* 1-col gap between chars */
                    }

                    if (spLedMode === 'scroll') {
                        spLedScrollX += 0.3;
                        if (spLedScrollX > textW + visibleCols) {
                            spLedScrollX = -visibleCols;
                        }
                        spLedAnim = requestAnimationFrame(spLedDraw);
                    }
                }

                function spLedShow(text, color, flash) {
                    if (spLedAnim) { cancelAnimationFrame(spLedAnim); spLedAnim = null; }
                    spLedText = text.toUpperCase();
                    spLedColor = color || '#ffd700';
                    const el = document.getElementById('fh-sapphire-led');
                    const visW = el ? el.offsetWidth * 2 : 300;
                    const pitch = 4;
                    const textPxW = spLedGetTextWidth(spLedText) * pitch;

                    if (textPxW > visW) {
                        spLedMode = 'scroll';
                        spLedScrollX = -Math.floor(visW / pitch);
                        spLedDraw();
                        spLedAnim = requestAnimationFrame(spLedDraw);
                    } else {
                        spLedMode = 'static';
                        spLedScrollX = 0;
                        spLedDraw();
                        if (flash) {
                            let flashes = 0;
                            const origColor = spLedColor;
                            const flashInterval = setInterval(() => {
                                spLedColor = flashes % 2 === 0 ? '#fff' : origColor;
                                spLedDraw();
                                flashes++;
                                if (flashes >= 6) {
                                    clearInterval(flashInterval);
                                    spLedColor = origColor;
                                    spLedDraw();
                                }
                            }, 150);
                        }
                    }
                }

                function spLedClear() {
                    if (spLedAnim) { cancelAnimationFrame(spLedAnim); spLedAnim = null; }
                    spLedText = '';
                    spLedMode = 'static';
                    const el = document.getElementById('fh-sapphire-led');
                    if (el) {
                        const w = el.offsetWidth * 2, h = el.offsetHeight * 2;
                        spLedCanvas.width = w; spLedCanvas.height = h;
                        spLedCtx.fillStyle = '#080808';
                        spLedCtx.fillRect(0, 0, w, h);
                        const pitch = 4, dotSize = 3, rows = 7;
                        const offsetY = Math.floor((h - rows * pitch) / 2);
                        for (let row = 0; row < rows; row++) {
                            for (let col = 0; col < Math.ceil(w / pitch); col++) {
                                spLedCtx.fillStyle = 'rgba(30,30,30,.5)';
                                spLedCtx.beginPath();
                                spLedCtx.arc(col * pitch + dotSize/2, offsetY + row * pitch + dotSize/2, dotSize/2, 0, Math.PI*2);
                                spLedCtx.fill();
                            }
                        }
                    }
                }

                /* Initialize lightboard with dim dots */
                setTimeout(spLedClear, 50);

                /* ── Paytable modal toggle ── */
                const spPayModal = document.getElementById('fh-sapphire-pay');
                const spClosePay = () => { spPayModal.style.display = 'none'; };
                document.getElementById('fh-sapphire-pay-btn').addEventListener('click', () => { spPayModal.style.display = 'flex'; });
                document.getElementById('fh-sapphire-pay-x').addEventListener('click', spClosePay);
                document.getElementById('fh-sapphire-pay-bd').addEventListener('click', spClosePay);

                /* ── Build reel strip: 1 trailing + winner + N random + 1 trailing (scrolls top-to-bottom) ── */
                function spBuildStrip(finalId, count) {
                    let html = '';
                    /* 1 trailing card above winner */
                    const t1 = pool[Math.floor(Math.random() * pool.length)];
                    html += '<div class="fh-sapphire-sym"><img src="' + cardBase + t1.file + '"></div>';
                    /* Winner in second position */
                    const f = cardMap[finalId] || CARDS[5];
                    html += '<div class="fh-sapphire-sym"><img src="' + cardBase + f.file + '"></div>';
                    /* 1 trailing card below winner */
                    const t2 = pool[Math.floor(Math.random() * pool.length)];
                    html += '<div class="fh-sapphire-sym"><img src="' + cardBase + t2.file + '"></div>';
                    /* Random cards for the spin animation */
                    for (let i = 0; i < count; i++) {
                        const c = pool[Math.floor(Math.random() * pool.length)];
                        html += '<div class="fh-sapphire-sym"><img src="' + cardBase + c.file + '"></div>';
                    }
                    return html;
                }

                /* ── Spin one reel — 3 cards visible, winner in middle, scrolls top-to-bottom ── */
                function spSpinReel(idx, finalId, duration) {
                    return new Promise(resolve => {
                        const strip = document.getElementById('fh-spr-' + idx);
                        const win   = document.getElementById('fh-spw-' + idx);
                        const symH  = Math.floor(win.offsetHeight / 3);
                        const count = 22 + idx * 6;
                        strip.innerHTML = spBuildStrip(finalId, count);
                        strip.querySelectorAll('.fh-sapphire-sym').forEach(s => { s.style.height = symH + 'px'; });
                        strip.style.transition = 'none';
                        strip.style.transform = 'translateY(-' + ((count + 2) * symH) + 'px)';
                        strip.offsetHeight;
                        strip.style.transition = 'transform ' + duration + 'ms cubic-bezier(.15,.85,.25,1)';
                        strip.style.transform = 'translateY(0)';
                        setTimeout(resolve, duration + 60);
                    });
                }

                /* ── Win message builder ── */
                function spWinMessage(d) {
                    const mt = d.match_type || '';
                    if (mt === 'four') {
                        return 'FOUR OF A KIND! ' + d.multiplier + 'x';
                    }
                    if (mt === 'three') {
                        return 'THREE OF A KIND! ' + d.multiplier + 'x';
                    }
                    if (mt === 'twopair') {
                        return 'TWO PAIR! ' + d.multiplier + 'x';
                    }
                    if (mt === 'pair') {
                        return 'PAIR! ' + d.multiplier + 'x';
                    }
                    return 'NO MATCH';
                }

                /* ── Spin button ── */
                document.getElementById('fh-sapphire-spin').addEventListener('click', async () => {
                    if (spinning) return;
                    if (bet > chips) {
                        spLedShow('NOT ENOUGH CHIPS', '#cc4444', false);
                        return;
                    }
                    spinning = true;
                    document.getElementById('fh-sapphire-spin').disabled = true;
                    spLedClear();
                    [0,1,2,3].forEach(i => document.getElementById('fh-spw-' + i).classList.remove('winning'));

                    const res = await casinoPost('fishotel_casino_poker_slots_spin', { bet: bet });
                    if (!res.success) {
                        spLedShow(res.data.message || 'ERROR', '#cc4444', false);
                        spinning = false;
                        document.getElementById('fh-sapphire-spin').disabled = false;
                        return;
                    }
                    const d = res.data;

                    /* All reels start at once, stop left-to-right */
                    await Promise.all([
                        spSpinReel(0, d.reels[0], 1400),
                        spSpinReel(1, d.reels[1], 1800),
                        spSpinReel(2, d.reels[2], 2200),
                        spSpinReel(3, d.reels[3], 2600)
                    ]);

                    updateChips(d.chips);

                    if (d.payout > 0) {
                        /* Highlight matching reels */
                        const mt = d.match_type || '';
                        if (mt === 'four') {
                            [0,1,2,3].forEach(i => document.getElementById('fh-spw-' + i).classList.add('winning'));
                        } else if (mt === 'three') {
                            /* Find which 3 match */
                            const reels = d.reels;
                            for (let i = 0; i < 4; i++) {
                                let matchCount = 0;
                                for (let j = 0; j < 4; j++) { if (reels[i] === reels[j]) matchCount++; }
                                if (matchCount >= 3) document.getElementById('fh-spw-' + i).classList.add('winning');
                            }
                        } else if (mt === 'twopair' || mt === 'pair') {
                            const reels = d.reels;
                            for (let i = 0; i < 4; i++) {
                                for (let j = i + 1; j < 4; j++) {
                                    if (reels[i] === reels[j]) {
                                        document.getElementById('fh-spw-' + i).classList.add('winning');
                                        document.getElementById('fh-spw-' + j).classList.add('winning');
                                    }
                                }
                            }
                        }
                        spLedShow(spWinMessage(d), '#ffd700', true);
                        fhChipFloat(d.payout, true);
                        if (d.multiplier >= 25) {
                            const flash = document.createElement('div');
                            flash.style.cssText = 'position:fixed;inset:0;background:radial-gradient(circle,rgba(255,215,0,.5) 0%,transparent 70%);pointer-events:none;z-index:99998;opacity:1;transition:opacity 1s';
                            document.body.appendChild(flash);
                            setTimeout(() => { flash.style.opacity = '0'; }, 100);
                            setTimeout(() => flash.remove(), 1200);
                        }
                        if (window.fhArcadeHandleWin) window.fhArcadeHandleWin(d);
                    } else {
                        spLedShow('NO MATCH', '#cc4444', false);
                        fhChipFloat(-bet, false);
                    }
                    spinning = false;
                    document.getElementById('fh-sapphire-spin').disabled = false;
                });

                /* ── Reveal machine once cabinet image loads ── */
                const spCabImg = document.getElementById('fh-sapphire-cab-img');
                const spWrap = document.getElementById('fh-sapphire-wrap');
                const spLoader = document.getElementById('fh-sapphire-loader');
                function spReveal() {
                    spLoader.remove();
                    spWrap.classList.remove('fh-slot-machine-hidden');
                    spWrap.classList.add('fh-slot-machine-reveal');
                }
                if (spCabImg.complete) { spReveal(); } else { spCabImg.addEventListener('load', spReveal); }
            }

            /* ─── Shared helpers (for future game rebuilds) ─── */
            function fhCard(c, faceDown, isHeld) {
                if (faceDown) return '<div class="fh-card fh-card-back"></div>';
                const isRed = c.suit === '♥' || c.suit === '♦';
                const color = isRed ? 'red' : 'black';
                const held = isHeld ? ' fh-card-held' : '';
                return '<div class="fh-card ' + color + held + '">' +
                    '<div class="fh-card-tl">' + c.rank + '<span class="fh-card-suit-sm">' + c.suit + '</span></div>' +
                    '<div class="fh-card-center">' + c.suit + '</div>' +
                    '<div class="fh-card-br">' + c.rank + '<span class="fh-card-suit-sm">' + c.suit + '</span></div>' +
                '</div>';
            }
            function fhChipFloat(amount, win) {
                const el = document.createElement('div');
                el.className = 'fh-chip-float ' + (win ? 'win' : 'lose');
                el.textContent = (win ? '+' : '') + Number(Math.abs(amount)).toLocaleString();
                /* Position near chip balance (RED zone) on cabinet */
                const machine = document.querySelector('.fh-slots-machine') || document.querySelector('.fh-sapphire-machine');
                if (machine) {
                    el.style.position = 'absolute';
                    el.style.zIndex = '4';
                    if (machine.classList.contains('fh-sapphire-machine')) {
                        el.style.left = 'calc(660/784*100%)';
                        el.style.top = 'calc(610/1168*100%)';
                    } else {
                        el.style.left = 'calc(547/784*100%)';
                        el.style.top = 'calc(800/1168*100%)';
                    }
                    machine.appendChild(el);
                } else {
                    el.style.position = 'fixed';
                    el.style.left = '50%'; el.style.top = '65%';
                    document.body.appendChild(el);
                }
                setTimeout(() => el.remove(), 1600);
            }

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

            /* Prize Shop removed from top bar — will be accessible via a casino room */

        })();
        </script>
        <?php
        return ob_get_clean();
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
            'player'    => 'Player Stats',
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
            case 'player':    $this->render_admin_player_stats(); break;
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
                                <?php foreach ( [ 'whale' => 'Whale (50x)', 'starfish' => 'Starfish (20x)', 'shark' => 'Shark (15x)', 'puffer' => 'Puffer (10x)', 'dolphin' => 'Dolphin (8x)', 'octopus' => 'Octopus (6x)', 'squid' => 'Squid (5x)', 'seahorse' => 'Seahorse (5x)' ] as $sk => $sl ) : ?>
                                    <option value="<?php echo $sk; ?>" <?php selected( $p['symbol'] ?? '', $sk ); ?>><?php echo esc_html( $sl ); ?></option>
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

    /* ─── Tab 6: Player Stats Debug Panel ─── */
    private function render_admin_player_stats() {
        $page = sanitize_text_field( $_GET['page'] ?? 'fishotel-batch-hq' );
        $base_url = admin_url( "admin.php?page={$page}&tab=casino&casino_tab=player" );

        /* ── Handle POST actions ── */
        if ( isset( $_POST['fh_player_action'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'fh_player_debug' ) ) {
            $target = (int) $_POST['target_user_id'];
            $act    = sanitize_text_field( $_POST['fh_player_action'] );

            if ( $target ) {
                $stats  = get_user_meta( $target, '_fishotel_casino_stats', true );
                $stats  = is_array( $stats ) ? $stats : [];
                $chips  = (int) get_user_meta( $target, '_fishotel_casino_chips', true );

                switch ( $act ) {
                    case 'adjust_chips':
                        $op  = sanitize_text_field( $_POST['chip_op'] ?? 'add' );
                        $amt = max( 0, (int) $_POST['chip_amt'] );
                        if ( $op === 'add' )      $chips += $amt;
                        elseif ( $op === 'subtract' ) $chips = max( 0, $chips - $amt );
                        elseif ( $op === 'set' )   $chips = $amt;
                        update_user_meta( $target, '_fishotel_casino_chips', $chips );
                        echo '<div class="notice notice-success"><p>Chips updated to ' . number_format( $chips ) . '.</p></div>';
                        break;

                    case 'reset_streak':
                        $game = sanitize_text_field( $_POST['streak_game'] ?? '' );
                        if ( $game === 'blackjack' ) update_user_meta( $target, '_fishotel_bj_streak', 0 );
                        if ( $game === 'roulette' ) {
                            update_user_meta( $target, '_fishotel_roul_streak', 0 );
                            update_user_meta( $target, '_fishotel_roul_color_streak', 0 );
                        }
                        echo '<div class="notice notice-success"><p>' . ucfirst( $game ) . ' streak reset.</p></div>';
                        break;

                    case 'reset_game_count':
                        $game = sanitize_text_field( $_POST['reset_game'] ?? '' );
                        $map = [ 'blackjack' => 'blackjack_hands', 'roulette' => 'roulette_spins', 'slots' => 'slots_spins', 'poker' => 'poker_hands' ];
                        if ( $game === 'all' ) {
                            foreach ( $map as $k ) $stats[ $k ] = 0;
                            $stats['games_played'] = 0;
                            $stats['total_wagered'] = 0;
                            $stats['total_won'] = 0;
                            $stats['total_lost'] = 0;
                            $stats['biggest_win'] = 0;
                            update_user_meta( $target, '_fishotel_bj_streak', 0 );
                            update_user_meta( $target, '_fishotel_roul_streak', 0 );
                            update_user_meta( $target, '_fishotel_roul_color_streak', 0 );
                            update_user_meta( $target, '_fishotel_roul_last_label', '' );
                        } elseif ( isset( $map[ $game ] ) ) {
                            $stats[ $map[ $game ] ] = 0;
                        }
                        update_user_meta( $target, '_fishotel_casino_stats', $stats );
                        echo '<div class="notice notice-success"><p>Game counts reset.</p></div>';
                        break;

                    case 'award_badge':
                        $sid    = (int) $_POST['badge_sticker_id'];
                        $earned = get_user_meta( $target, '_fishotel_earned_stickers', true );
                        $earned = is_array( $earned ) ? $earned : [];
                        if ( ! in_array( $sid, $earned ) ) {
                            $earned[] = $sid;
                            update_user_meta( $target, '_fishotel_earned_stickers', $earned );
                        }
                        echo '<div class="notice notice-success"><p>Badge awarded.</p></div>';
                        break;

                    case 'remove_badge':
                        $sid    = (int) $_POST['badge_sticker_id'];
                        $earned = get_user_meta( $target, '_fishotel_earned_stickers', true );
                        $earned = is_array( $earned ) ? $earned : [];
                        $earned = array_values( array_diff( $earned, [ $sid ] ) );
                        update_user_meta( $target, '_fishotel_earned_stickers', $earned );
                        echo '<div class="notice notice-success"><p>Badge removed.</p></div>';
                        break;

                    case 'award_prize':
                        $sid    = (int) $_POST['prize_sticker_id'];
                        $source = sanitize_text_field( $_POST['prize_source'] ?? 'shop' );
                        $s      = get_post( $sid );
                        if ( $s ) {
                            $statuses   = get_option( 'fishotel_batch_statuses', [] );
                            $batch_name = '';
                            foreach ( $statuses as $n => $st ) { if ( $st === 'casino' ) { $batch_name = $n; break; } }
                            $prizes = get_user_meta( $target, '_fishotel_physical_prizes', true );
                            $prizes = is_array( $prizes ) ? $prizes : [];
                            $prizes[] = [
                                'sticker_id' => $sid, 'sticker_name' => $s->post_title,
                                'source' => $source, 'game_type' => $source === 'jackpot' ? 'admin' : null,
                                'earned_at' => time(), 'batch_name' => $batch_name,
                                'chip_cost' => 0, 'added_to_box' => false,
                            ];
                            update_user_meta( $target, '_fishotel_physical_prizes', $prizes );
                        }
                        echo '<div class="notice notice-success"><p>Prize awarded.</p></div>';
                        break;

                    case 'remove_prize':
                        $idx    = (int) $_POST['prize_index'];
                        $prizes = get_user_meta( $target, '_fishotel_physical_prizes', true );
                        if ( is_array( $prizes ) && isset( $prizes[ $idx ] ) ) {
                            array_splice( $prizes, $idx, 1 );
                            update_user_meta( $target, '_fishotel_physical_prizes', array_values( $prizes ) );
                        }
                        echo '<div class="notice notice-success"><p>Prize removed.</p></div>';
                        break;

                    case 'mark_prize_added':
                        $idx    = (int) $_POST['prize_index'];
                        $prizes = get_user_meta( $target, '_fishotel_physical_prizes', true );
                        if ( is_array( $prizes ) && isset( $prizes[ $idx ] ) ) {
                            $prizes[ $idx ]['added_to_box'] = true;
                            update_user_meta( $target, '_fishotel_physical_prizes', $prizes );
                        }
                        echo '<div class="notice notice-success"><p>Prize marked as added.</p></div>';
                        break;

                    case 'nuclear_reset':
                        $scope = sanitize_text_field( $_POST['reset_scope'] ?? '' );
                        if ( $scope === 'all' || $scope === 'chips' ) {
                            update_user_meta( $target, '_fishotel_casino_chips', 1000 );
                        }
                        if ( $scope === 'all' || $scope === 'games' ) {
                            delete_user_meta( $target, '_fishotel_casino_stats' );
                            update_user_meta( $target, '_fishotel_bj_streak', 0 );
                            update_user_meta( $target, '_fishotel_roul_streak', 0 );
                            update_user_meta( $target, '_fishotel_roul_color_streak', 0 );
                            update_user_meta( $target, '_fishotel_roul_last_label', '' );
                            delete_user_meta( $target, '_fishotel_casino_total_winnings' );
                            delete_user_meta( $target, '_fishotel_casino_last_daily' );
                        }
                        if ( $scope === 'all' || $scope === 'stickers' ) {
                            delete_user_meta( $target, '_fishotel_earned_stickers' );
                            delete_user_meta( $target, '_fishotel_physical_prizes' );
                        }
                        echo '<div class="notice notice-warning"><p>Reset complete (' . $scope . ').</p></div>';
                        break;
                }
            }
        }

        /* ── Player dropdown ── */
        global $wpdb;
        $players = $wpdb->get_results(
            "SELECT DISTINCT u.ID, u.display_name
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             WHERE um.meta_key = '_fishotel_casino_stats'
             ORDER BY u.display_name ASC
             LIMIT 200"
        );

        $sel_uid = (int) ( $_GET['player_id'] ?? $_POST['target_user_id'] ?? 0 );
        ?>
        <h3>Player Stats &amp; Debug Panel</h3>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>">
            <input type="hidden" name="tab" value="casino">
            <input type="hidden" name="casino_tab" value="player">
            <label><strong>Player:</strong>
            <select name="player_id" style="min-width:250px;">
                <option value="0">— Select a player —</option>
                <?php foreach ( $players as $p ) : ?>
                    <option value="<?php echo $p->ID; ?>" <?php selected( $sel_uid, $p->ID ); ?>><?php echo esc_html( $p->display_name ); ?> (ID: <?php echo $p->ID; ?>)</option>
                <?php endforeach; ?>
            </select></label>
            <input type="submit" class="button" value="Load Stats">
        </form>

        <?php if ( ! $sel_uid ) { echo '<p style="color:#888;">Select a player to view their stats.</p>'; return; }

        $user = get_user_by( 'ID', $sel_uid );
        if ( ! $user ) { echo '<p style="color:#e74c3c;">User not found.</p>'; return; }

        $chips   = (int) get_user_meta( $sel_uid, '_fishotel_casino_chips', true );
        $stats   = get_user_meta( $sel_uid, '_fishotel_casino_stats', true );
        $stats   = is_array( $stats ) ? $stats : [];
        $earned  = get_user_meta( $sel_uid, '_fishotel_earned_stickers', true );
        $earned  = is_array( $earned ) ? $earned : [];
        $prizes  = get_user_meta( $sel_uid, '_fishotel_physical_prizes', true );
        $prizes  = is_array( $prizes ) ? $prizes : [];
        $bj_streak     = (int) get_user_meta( $sel_uid, '_fishotel_bj_streak', true );
        $roul_streak   = (int) get_user_meta( $sel_uid, '_fishotel_roul_streak', true );
        $roul_last     = get_user_meta( $sel_uid, '_fishotel_roul_last_label', true );
        $roul_color_sk = (int) get_user_meta( $sel_uid, '_fishotel_roul_color_streak', true );
        $daily_last    = get_user_meta( $sel_uid, '_fishotel_casino_last_daily', true );
        $leaderboard   = (int) get_user_meta( $sel_uid, '_fishotel_casino_total_winnings', true );

        $nonce_field = wp_nonce_field( 'fh_player_debug', '_wpnonce', true, false );
        $hidden_uid  = '<input type="hidden" name="target_user_id" value="' . $sel_uid . '">';

        $box = 'style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px;"';
        $btn = 'style="background:#FF7F00;color:#fff;border:none;border-radius:4px;padding:6px 14px;cursor:pointer;font-weight:600;"';
        $btn_danger = 'style="background:#c0392b;color:#fff;border:none;border-radius:4px;padding:6px 14px;cursor:pointer;font-weight:600;"';
        ?>

        <!-- PLAYER INFO -->
        <div <?php echo $box; ?>>
            <h4 style="margin:0 0 8px 0;color:#1a3a5c;">Player Info</h4>
            <table style="border-collapse:collapse;">
                <tr><td style="padding:3px 12px 3px 0;color:#888;">Username:</td><td><strong><?php echo esc_html( $user->display_name ); ?></strong></td></tr>
                <tr><td style="padding:3px 12px 3px 0;color:#888;">User ID:</td><td><?php echo $sel_uid; ?></td></tr>
                <tr><td style="padding:3px 12px 3px 0;color:#888;">Registered:</td><td><?php echo date( 'M j, Y', strtotime( $user->user_registered ) ); ?></td></tr>
                <tr><td style="padding:3px 12px 3px 0;color:#888;">Last Daily Bonus:</td><td><?php echo $daily_last ?: '—'; ?></td></tr>
                <tr><td style="padding:3px 12px 3px 0;color:#888;">Leaderboard Winnings:</td><td><?php echo number_format( $leaderboard ); ?> chips</td></tr>
            </table>
        </div>

        <!-- CHIP BALANCE -->
        <div <?php echo $box; ?>>
            <h4 style="margin:0 0 8px 0;color:#1a3a5c;">Chip Balance: <span style="color:#96885f;font-size:1.2em;"><?php echo number_format( $chips ); ?></span></h4>
            <form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                <?php echo $nonce_field . $hidden_uid; ?>
                <input type="hidden" name="fh_player_action" value="adjust_chips">
                <label><input type="radio" name="chip_op" value="add" checked> Add</label>
                <label><input type="radio" name="chip_op" value="subtract"> Subtract</label>
                <label><input type="radio" name="chip_op" value="set"> Set To</label>
                <input type="number" name="chip_amt" value="100" min="0" style="width:100px;"> chips
                <button type="submit" <?php echo $btn; ?>>Apply</button>
            </form>
            <div style="color:#666;font-size:.9em;line-height:1.6;">
                Total Wagered: <?php echo number_format( (int) ( $stats['total_wagered'] ?? 0 ) ); ?> |
                Total Won: +<?php echo number_format( (int) ( $stats['total_won'] ?? 0 ) ); ?> |
                Total Lost: -<?php echo number_format( (int) ( $stats['total_lost'] ?? 0 ) ); ?> |
                Biggest Win: <?php echo number_format( (int) ( $stats['biggest_win'] ?? 0 ) ); ?>
            </div>
        </div>

        <!-- GAME STATISTICS -->
        <div <?php echo $box; ?>>
            <h4 style="margin:0 0 12px 0;color:#1a3a5c;">Game Statistics — Total Played: <?php echo number_format( (int) ( $stats['games_played'] ?? 0 ) ); ?></h4>
            <?php
            $games = [
                'blackjack' => [ 'key' => 'blackjack_hands', 'label' => 'Blackjack', 'streak' => $bj_streak, 'streak_label' => 'Win Streak' ],
                'roulette'  => [ 'key' => 'roulette_spins',  'label' => 'Roulette',  'streak' => $roul_streak, 'streak_label' => 'Same Number Streak', 'extra' => 'Last: ' . ( $roul_last ?: '—' ) . ' | Color Streak: ' . $roul_color_sk ],
                'slots'     => [ 'key' => 'slots_spins',     'label' => 'Slots',     'streak' => null ],
                'poker'     => [ 'key' => 'poker_hands',     'label' => 'Video Poker', 'streak' => null ],
            ];
            foreach ( $games as $gk => $gi ) :
                $count = (int) ( $stats[ $gi['key'] ] ?? 0 );
            ?>
            <div style="padding:8px 0;border-bottom:1px solid #eee;">
                <strong><?php echo $gi['label']; ?>:</strong> <?php echo $count; ?> games
                <?php if ( $gi['streak'] !== null ) : ?>
                    | <?php echo $gi['streak_label']; ?>: <strong><?php echo $gi['streak']; ?></strong>
                    <form method="post" style="display:inline;">
                        <?php echo $nonce_field . $hidden_uid; ?>
                        <input type="hidden" name="fh_player_action" value="reset_streak">
                        <input type="hidden" name="streak_game" value="<?php echo $gk; ?>">
                        <button type="submit" class="button button-small" onclick="return confirm('Reset streak?');">Reset Streak</button>
                    </form>
                <?php endif; ?>
                <?php if ( ! empty( $gi['extra'] ) ) : ?>
                    | <?php echo $gi['extra']; ?>
                <?php endif; ?>
                <form method="post" style="display:inline;margin-left:8px;">
                    <?php echo $nonce_field . $hidden_uid; ?>
                    <input type="hidden" name="fh_player_action" value="reset_game_count">
                    <input type="hidden" name="reset_game" value="<?php echo $gk; ?>">
                    <button type="submit" class="button button-small" onclick="return confirm('Reset <?php echo $gi['label']; ?> count?');">Reset Count</button>
                </form>
            </div>
            <?php endforeach; ?>
            <div style="margin-top:12px;">
                <form method="post" style="display:inline;">
                    <?php echo $nonce_field . $hidden_uid; ?>
                    <input type="hidden" name="fh_player_action" value="reset_game_count">
                    <input type="hidden" name="reset_game" value="all">
                    <button type="submit" <?php echo $btn; ?> onclick="return confirm('Reset ALL game counts and streaks?');">Reset All Game Counts</button>
                </form>
            </div>
        </div>

        <!-- STICKERS & ACHIEVEMENTS -->
        <div <?php echo $box; ?>>
            <h4 style="margin:0 0 12px 0;color:#1a3a5c;">Virtual Badges (<?php echo count( $earned ); ?>)</h4>
            <?php if ( empty( $earned ) ) : ?>
                <p style="color:#888;">No badges earned.</p>
            <?php else : ?>
                <?php foreach ( $earned as $sid ) :
                    $s = get_post( $sid );
                    if ( ! $s ) continue;
                ?>
                <div style="display:inline-flex;align-items:center;gap:6px;margin:0 12px 8px 0;">
                    <span><?php echo esc_html( $s->post_title ); ?></span>
                    <form method="post" style="display:inline;">
                        <?php echo $nonce_field . $hidden_uid; ?>
                        <input type="hidden" name="fh_player_action" value="remove_badge">
                        <input type="hidden" name="badge_sticker_id" value="<?php echo $sid; ?>">
                        <button type="submit" class="button button-small" onclick="return confirm('Remove this badge?');">Remove</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div style="margin-top:10px;">
                <form method="post" style="display:flex;align-items:center;gap:8px;">
                    <?php echo $nonce_field . $hidden_uid; ?>
                    <input type="hidden" name="fh_player_action" value="award_badge">
                    <select name="badge_sticker_id">
                        <?php
                        $all_stickers = get_posts( [ 'post_type' => 'fishotel_sticker', 'numberposts' => -1, 'post_status' => 'publish' ] );
                        foreach ( $all_stickers as $s ) {
                            if ( ! in_array( $s->ID, $earned ) ) {
                                echo '<option value="' . $s->ID . '">' . esc_html( $s->post_title ) . '</option>';
                            }
                        }
                        ?>
                    </select>
                    <button type="submit" <?php echo $btn; ?>>Award Badge</button>
                </form>
            </div>

            <h4 style="margin:20px 0 12px 0;color:#1a3a5c;">Physical Prizes (<?php echo count( $prizes ); ?>)</h4>
            <?php if ( empty( $prizes ) ) : ?>
                <p style="color:#888;">No prizes.</p>
            <?php else : ?>
                <table class="widefat striped" style="margin-bottom:12px;">
                    <thead><tr><th>Prize</th><th>Source</th><th>Cost</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ( $prizes as $idx => $pr ) : ?>
                        <tr>
                            <td><?php echo esc_html( $pr['sticker_name'] ); ?></td>
                            <td><?php echo $pr['source'] === 'jackpot' ? '<span style="color:#ffd700;">JACKPOT</span>' : '<span style="color:#96885f;">SHOP</span>'; ?></td>
                            <td><?php echo $pr['source'] === 'shop' ? number_format( $pr['chip_cost'] ?? 0 ) : 'Free'; ?></td>
                            <td><?php echo $pr['earned_at'] ? date( 'M j g:ia', $pr['earned_at'] ) : '—'; ?></td>
                            <td><?php echo ! empty( $pr['added_to_box'] ) ? '<span style="color:#27ae60;">Added</span>' : 'Pending'; ?></td>
                            <td>
                                <?php if ( empty( $pr['added_to_box'] ) ) : ?>
                                <form method="post" style="display:inline;">
                                    <?php echo $nonce_field . $hidden_uid; ?>
                                    <input type="hidden" name="fh_player_action" value="mark_prize_added">
                                    <input type="hidden" name="prize_index" value="<?php echo $idx; ?>">
                                    <button type="submit" class="button button-small">Mark Added</button>
                                </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline;">
                                    <?php echo $nonce_field . $hidden_uid; ?>
                                    <input type="hidden" name="fh_player_action" value="remove_prize">
                                    <input type="hidden" name="prize_index" value="<?php echo $idx; ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('Remove this prize?');">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="margin-top:10px;">
                <form method="post" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php echo $nonce_field . $hidden_uid; ?>
                    <input type="hidden" name="fh_player_action" value="award_prize">
                    <select name="prize_sticker_id">
                        <?php foreach ( $all_stickers as $s ) : ?>
                            <option value="<?php echo $s->ID; ?>"><?php echo esc_html( $s->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label><input type="radio" name="prize_source" value="shop" checked> Shop</label>
                    <label><input type="radio" name="prize_source" value="jackpot"> Jackpot</label>
                    <button type="submit" <?php echo $btn; ?>>Award Prize</button>
                </form>
            </div>
        </div>

        <!-- DANGER ZONE -->
        <div style="background:#fff5f5;border:2px solid #e74c3c;border-radius:8px;padding:16px;margin-bottom:16px;">
            <h4 style="margin:0 0 12px 0;color:#c0392b;">Danger Zone</h4>
            <p style="color:#888;font-size:.9em;margin:0 0 12px 0;">These actions cannot be undone. Player will need to re-earn everything.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php foreach ( [ 'all' => 'Reset Everything', 'chips' => 'Reset Chips (to 1000)', 'games' => 'Reset Games &amp; Streaks', 'stickers' => 'Reset Badges &amp; Prizes' ] as $scope => $label ) : ?>
                <form method="post" style="display:inline;">
                    <?php echo $nonce_field . $hidden_uid; ?>
                    <input type="hidden" name="fh_player_action" value="nuclear_reset">
                    <input type="hidden" name="reset_scope" value="<?php echo $scope; ?>">
                    <button type="submit" <?php echo $btn_danger; ?> onclick="return confirm('Are you sure? This cannot be undone!');"><?php echo $label; ?></button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  ARCADE BUILDING — cross-section view for resort map
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function render_arcade_building( $batch_name = '' ) {
        $building_url = plugins_url( 'assists/arcade/Arcade-Building.png', FISHOTEL_PLUGIN_FILE );
        $st_base_url  = plugins_url( 'assists/arcade/strength-tester-base.png', FISHOTEL_PLUGIN_FILE );
        $logged_in    = is_user_logged_in();
        $login_url    = esc_url( wp_login_url( get_permalink() ) );

        // Get game HTML (also enqueues arcade assets via wp_enqueue)
        $game_html = $logged_in ? $this->render_arcade_public( $batch_name ) : '';

        ob_start();
        ?>
        <!-- ═══════════ FisHotel Arcade Building ═══════════ -->
        <div class="fh-arcbld">

            <!-- Building cross-section -->
            <div class="fh-arcbld-wrap">
                <img src="<?php echo esc_url( $building_url ); ?>"
                     class="fh-arcbld-img<?php echo $logged_in ? '' : ' fh-arcbld-img--locked'; ?>"
                     alt="Arcade Building">

                <?php if ( ! $logged_in ) : ?>
                <div class="fh-arcbld-login-overlay">
                    <p class="fh-arcbld-login-msg">Please log in to play arcade games.</p>
                    <a href="<?php echo $login_url; ?>" class="fh-arc-btn-gold">Log In</a>
                </div>
                <?php else : ?>
                <!-- Strength Tester machine overlay on left deck -->
                <div class="fh-arcbld-game-overlay" data-game="strength_tester">
                    <img src="<?php echo esc_url( $st_base_url ); ?>" alt="Strength Tester" class="fh-arcbld-game-img">
                    <span class="fh-arcbld-hotspot-label">Strength Tester</span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( $logged_in ) : ?>
            <!-- Strength Tester game modal -->
            <div class="fh-arcbld-modal" id="fh-arcbld-modal" style="display:none;">
                <div class="fh-arcbld-modal-inner">
                    <button class="fh-arcbld-modal-close" id="fh-arcbld-modal-close">&#10005; Close</button>
                    <?php echo $game_html; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <style>
        .fh-arcbld{font-family:'Segoe UI',system-ui,sans-serif;color:#fff;max-width:1000px;margin:0 auto}
        .fh-arcbld-wrap{position:relative;width:100%;aspect-ratio:1360/1020;background:#111;overflow:hidden;border-radius:0 0 12px 12px}
        .fh-arcbld-img{width:100%;height:100%;object-fit:cover;display:block}
        .fh-arcbld-img--locked{filter:blur(4px) brightness(0.4)}
        .fh-arcbld-login-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px}
        .fh-arcbld-login-msg{font-family:'Oswald',sans-serif;font-size:clamp(16px,3vw,24px);color:#f5f0e8;text-align:center;letter-spacing:1px}
        /* ─── Game overlays on building ─── */
        .fh-arcbld-game-overlay{position:absolute;left:16.3%;top:55.5%;width:7.9%;cursor:pointer;transition:all .3s ease;z-index:5}
        .fh-arcbld-game-overlay:hover{transform:scale(1.05);filter:drop-shadow(0 0 12px rgba(150,136,95,.6))}
        .fh-arcbld-game-img{width:100%;height:auto;display:block;filter:drop-shadow(0 2px 6px rgba(0,0,0,.5))}
        .fh-arcbld-hotspot-label{display:none;position:absolute;bottom:-24px;left:50%;transform:translateX(-50%);background:rgba(26,58,92,.95);color:#96885f;padding:4px 12px;border-radius:4px;font-family:'Oswald',sans-serif;font-size:clamp(9px,1.2vw,12px);white-space:nowrap;z-index:10;pointer-events:none}
        .fh-arcbld-game-overlay:hover .fh-arcbld-hotspot-label{display:block}
        /* ─── Game modal ─── */
        .fh-arcbld-modal{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px}
        .fh-arcbld-modal-inner{position:relative;background:#1a1a1a;border:2px solid #96885f;border-radius:12px;max-width:460px;width:100%;max-height:70vh;overflow-y:auto;padding:16px}
        .fh-arcbld-modal-close{display:block;margin:0 0 16px auto;font-family:'Oswald',sans-serif;font-size:14px;color:#96885f;background:transparent;border:1px solid #96885f;border-radius:6px;padding:6px 16px;cursor:pointer;letter-spacing:1px}
        .fh-arcbld-modal-close:hover{background:rgba(150,136,95,.15)}
        @media(max-width:640px){.fh-arcbld-modal{padding:10px}.fh-arcbld-modal-inner{padding:14px}}
        </style>

        <script>
        (function(){
            var wrap = document.querySelector('.fh-arcbld-wrap');
            if (!wrap) return;
            wrap.querySelectorAll('.fh-arcbld-game-overlay').forEach(function(hs){
                hs.addEventListener('click', function(){
                    var modal = document.getElementById('fh-arcbld-modal');
                    if (modal) modal.style.display = 'flex';
                });
            });
            var closeBtn = document.getElementById('fh-arcbld-modal-close');
            if (closeBtn) closeBtn.addEventListener('click', function(){
                document.getElementById('fh-arcbld-modal').style.display = 'none';
            });
            var modal = document.getElementById('fh-arcbld-modal');
            if (modal) modal.addEventListener('click', function(e){
                if (e.target === this) this.style.display = 'none';
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  PUBLIC ARCADE — Strength Tester (frontend, embeds in resort map)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function render_arcade_public( $batch_name = '' ) {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#96885f;font-family:Oswald,sans-serif;">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to play arcade games.</p>';
        }

        $uid     = get_current_user_id();
        $chips   = (int) get_user_meta( $uid, '_fishotel_casino_chips', true );
        $tickets = (int) get_user_meta( $uid, 'fishotel_arcade_tickets', true );
        $base    = plugins_url( 'assists/arcade/strength-tester-base.png', FISHOTEL_PLUGIN_FILE );
        $puck    = plugins_url( 'assists/arcade/strength-tester-puck.png', FISHOTEL_PLUGIN_FILE );
        $nonce   = wp_create_nonce( 'fishotel_arcade_nonce' );
        $ajax    = admin_url( 'admin-ajax.php' );

        /* Enqueue assets via WP — safe to call from within shortcode content */
        wp_enqueue_style(
            'fishotel-arcade-styles',
            plugins_url( 'assists/arcade/arcade-styles.css', FISHOTEL_PLUGIN_FILE ),
            [],
            FISHOTEL_VERSION
        );
        wp_enqueue_script(
            'fishotel-arcade-scripts',
            plugins_url( 'assists/arcade/arcade-scripts.js', FISHOTEL_PLUGIN_FILE ),
            [ 'jquery' ],
            FISHOTEL_VERSION,
            true
        );
        wp_localize_script( 'fishotel-arcade-scripts', 'fishotelArcade', [
            'ajaxUrl' => $ajax,
            'nonce'   => $nonce,
        ] );

        ob_start();
        ?>
        <div class="fh-arcade-public">
            <div class="fh-arcade-chip-bar">
                <div class="fh-arcade-chip-display">
                    <span class="fh-chip-icon">&#127922;</span>
                    Nickels: <span id="fh-arcade-chips"><?php echo (int) $chips; ?></span>
                </div>
                <div class="fh-arcade-ticket-display">
                    <span class="fh-ticket-icon">&#127915;</span>
                    Tickets: <span id="fh-arcade-tickets"><?php echo (int) $tickets; ?></span>
                </div>
            </div>

            <div class="fh-arcade-game" id="fh-strength-tester">
                <h2>Strength Tester</h2>

                <div class="fh-st-machine">
                    <img src="<?php echo esc_url( $base ); ?>" class="fh-st-base" alt="Strength Tester">
                    <div class="fh-st-bell-glow" id="fh-st-bell-glow"></div>
                    <div class="fh-st-puck-track">
                        <img src="<?php echo esc_url( $puck ); ?>" class="fh-st-puck" id="fh-st-puck" alt="Puck">
                    </div>
                    <div class="fh-st-track">
                        <div class="fh-st-fill" id="fh-st-fill"></div>
                    </div>
                </div>

                <div class="fh-st-controls">
                    <div class="fh-st-bet-label">Bet Amount</div>
                    <div class="fh-st-bet-selector">
                        <button type="button" class="fh-st-bet active" data-bet="5">5</button>
                        <button type="button" class="fh-st-bet" data-bet="10">10</button>
                        <button type="button" class="fh-st-bet" data-bet="25">25</button>
                    </div>
                    <button type="button" id="fh-st-action" class="fh-st-action">SWING!</button>
                    <div class="fh-st-error" id="fh-st-error"></div>
                </div>

                <div class="fh-st-result">
                    <div class="fh-st-result-zone" id="fh-st-result-zone"></div>
                    <div class="fh-st-captures" id="fh-st-captures"></div>
                    <div class="fh-st-result-payout" id="fh-st-result-payout"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  ARCADE ADMIN PAGE — Strength Tester
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function register_arcade_admin_menu() {
        add_submenu_page(
            'fishotel-batch-hq',
            'Arcade',
            'Arcade',
            'read',
            'fishotel-arcade',
            [ $this, 'render_arcade_admin_page' ]
        );
    }

    public function enqueue_arcade_admin_assets( $hook ) {
        if ( strpos( $hook, 'fishotel-arcade' ) === false ) return;

        wp_enqueue_style(
            'fishotel-arcade-styles',
            plugins_url( 'assists/arcade/arcade-styles.css', FISHOTEL_PLUGIN_FILE ),
            [],
            FISHOTEL_VERSION
        );
        wp_enqueue_script(
            'fishotel-arcade-scripts',
            plugins_url( 'assists/arcade/arcade-scripts.js', FISHOTEL_PLUGIN_FILE ),
            [ 'jquery' ],
            FISHOTEL_VERSION,
            true
        );
        wp_localize_script( 'fishotel-arcade-scripts', 'fishotelArcade', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'fishotel_arcade_nonce' ),
        ] );
    }

    public function render_arcade_admin_page() {
        if ( ! is_user_logged_in() ) {
            echo '<div class="wrap"><p>Please log in to play arcade games.</p></div>';
            return;
        }

        $uid   = get_current_user_id();
        $chips   = (int) get_user_meta( $uid, '_fishotel_casino_chips', true );
        $tickets = (int) get_user_meta( $uid, 'fishotel_arcade_tickets', true );
        $base    = plugins_url( 'assists/arcade/strength-tester-base.png', FISHOTEL_PLUGIN_FILE );
        $puck    = plugins_url( 'assists/arcade/strength-tester-puck.png', FISHOTEL_PLUGIN_FILE );
        ?>
        <div class="wrap fishotel-admin">
            <div class="fh-arcade-page">
                <h1>FisHotel Arcade</h1>
                <p class="fh-arcade-subtitle">Classic Boardwalk Games</p>

                <div class="fh-arcade-chip-bar">
                    <div class="fh-arcade-chip-display">
                        <span class="fh-chip-icon">&#127922;</span>
                        Nickels: <span id="fh-arcade-chips"><?php echo $chips; ?></span>
                    </div>
                    <div class="fh-arcade-ticket-display">
                        <span class="fh-ticket-icon">&#127915;</span>
                        Tickets: <span id="fh-arcade-tickets"><?php echo $tickets; ?></span>
                    </div>
                </div>

                <div class="fh-arcade-game" id="fh-strength-tester">
                    <h2>Strength Tester</h2>

                    <div class="fh-st-machine">
                        <img src="<?php echo esc_url( $base ); ?>" class="fh-st-base" alt="Strength Tester">
                        <div class="fh-st-bell-glow" id="fh-st-bell-glow"></div>
                        <div class="fh-st-puck-track">
                            <img src="<?php echo esc_url( $puck ); ?>" class="fh-st-puck" id="fh-st-puck" alt="Puck">
                        </div>
                        <div class="fh-st-track">
                            <div class="fh-st-fill" id="fh-st-fill"></div>
                        </div>
                        <!-- zone labels removed — power bar only -->
                    </div>

                    <div class="fh-st-controls">
                        <div class="fh-st-bet-label">Bet Amount</div>
                        <div class="fh-st-bet-selector">
                            <button type="button" class="fh-st-bet active" data-bet="5">5</button>
                            <button type="button" class="fh-st-bet" data-bet="10">10</button>
                            <button type="button" class="fh-st-bet" data-bet="25">25</button>
                        </div>
                        <button type="button" id="fh-st-action" class="fh-st-action">SWING!</button>
                        <div class="fh-st-error" id="fh-st-error"></div>
                    </div>

                    <div class="fh-st-result">
                        <div class="fh-st-result-zone" id="fh-st-result-zone"></div>
                        <div class="fh-st-result-payout" id="fh-st-result-payout"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_strength_tester_play() {
        check_ajax_referer( 'fishotel_arcade_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $bet   = (int) ( $_POST['bet'] ?? 0 );
        $power = (int) ( $_POST['power'] ?? -1 );

        if ( ! in_array( $bet, [ 5, 10, 25 ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid bet amount.' ] );
        }
        if ( $power < 0 || $power > 100 ) {
            wp_send_json_error( [ 'message' => 'Invalid power value.' ] );
        }

        $chips = (int) get_user_meta( $uid, '_fishotel_casino_chips', true );
        if ( $chips < $bet ) {
            wp_send_json_error( [ 'message' => 'Not enough nickels.' ] );
        }

        if ( $power >= 97 ) {
            $zone       = 'bell';
            $multiplier = 2.5;
            $label      = 'RING THE BELL!';
        } elseif ( $power >= 90 ) {
            $zone       = 'super';
            $multiplier = 1.5;
            $label      = 'SUPER STRONG!';
        } elseif ( $power >= 75 ) {
            $zone       = 'strong';
            $multiplier = 1;
            $label      = 'STRONG';
        } elseif ( $power >= 50 ) {
            $zone       = 'good';
            $multiplier = 0;
            $label      = 'GOOD TRY';
        } else {
            $zone       = 'miss';
            $multiplier = 0;
            $label      = 'MISS';
        }

        $payout    = (int) floor( $bet * $multiplier );
        $new_chips = max( 0, $chips - $bet );
        update_user_meta( $uid, '_fishotel_casino_chips', $new_chips );

        $tickets     = (int) get_user_meta( $uid, 'fishotel_arcade_tickets', true );
        $new_tickets = $tickets + $payout;
        update_user_meta( $uid, 'fishotel_arcade_tickets', $new_tickets );

        wp_send_json_success( [
            'zone'       => $zone,
            'label'      => $label,
            'power'      => $power,
            'bet'        => $bet,
            'multiplier' => $multiplier,
            'payout'     => $payout,
            'chips'      => $new_chips,
            'tickets'    => $new_tickets,
        ] );
    }

} /* end class FisHotel_Arcade */
