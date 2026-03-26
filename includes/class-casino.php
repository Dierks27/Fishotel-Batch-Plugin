<?php
/**
 * FisHotel Casino — Server-side game logic, chip wallet, and jackpot detection.
 * Game renderers live in class-arcade.php. This file handles AJAX only.
 *
 * @since 7.0
 * @updated 7.9 — Removed old casino floor shortcode, wallet/leaderboard widgets.
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
        /* AJAX — logged-in users only */
        $actions = [
            'fishotel_casino_get_chips',
            'fishotel_casino_claim_daily',
            'fishotel_casino_roulette_spin',
            'fishotel_casino_blackjack_action',
            'fishotel_casino_slots_spin',
            'fishotel_casino_poker_slots_spin',
            'fishotel_casino_poker_action',
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

    /* ─── Dynamic Jackpot Detection ────────────────────────── */

    /**
     * Check if a game result qualifies as a jackpot using dynamic triggers.
     * Triggers stored in WP option 'fishotel_jackpot_triggers'.
     *
     * @param int    $user_id     Current user ID.
     * @param string $game        Game slug: roulette, blackjack, slots, poker.
     * @param array  $result_data Game-specific result data.
     * @return array|false        Jackpot data or false.
     */
    public function check_jackpot( $user_id, $game, $result_data = [] ) {
        $triggers = get_option( 'fishotel_jackpot_triggers', [] );
        $cfg      = $triggers[ $game ] ?? [];

        if ( empty( $cfg['enabled'] ) ) return false;

        $type   = $cfg['trigger_type'] ?? '';
        $params = $cfg['parameters'] ?? [];
        $is_jackpot = false;

        switch ( $game ) {
            case 'blackjack':
                $is_jackpot = $this->check_blackjack_jackpot( $user_id, $type, $params, $result_data );
                break;
            case 'roulette':
                $is_jackpot = $this->check_roulette_jackpot( $user_id, $type, $params, $result_data );
                break;
            case 'slots':
                $is_jackpot = $this->check_slots_jackpot( $type, $params, $result_data );
                break;
            case 'poker':
                $is_jackpot = $this->check_poker_jackpot( $type, $params, $result_data );
                break;
        }

        if ( ! $is_jackpot ) return false;

        return $this->award_jackpot_prize( $user_id, $game );
    }

    /* ─── Per-game jackpot checkers ─────────────────────────── */

    private function check_blackjack_jackpot( $user_id, $type, $params, $data ) {
        $won = in_array( $data['result'] ?? '', [ 'win', 'blackjack' ], true );

        switch ( $type ) {
            case 'win_streak':
                $streak = (int) get_user_meta( $user_id, '_fishotel_bj_streak', true );
                if ( $won ) {
                    $streak++;
                    update_user_meta( $user_id, '_fishotel_bj_streak', $streak );
                    if ( $streak >= (int) ( $params['streak_length'] ?? 3 ) ) {
                        update_user_meta( $user_id, '_fishotel_bj_streak', 0 );
                        return true;
                    }
                } else {
                    update_user_meta( $user_id, '_fishotel_bj_streak', 0 );
                }
                return false;

            case 'natural_21':
                if ( ( $data['result'] ?? '' ) !== 'blackjack' ) return false;
                $variant = $params['variant'] ?? 'any';
                if ( $variant === 'any' ) return true;
                $cards = $data['player_cards'] ?? [];
                if ( count( $cards ) !== 2 ) return false;
                if ( $variant === 'suited' ) {
                    return $cards[0]['suit'] === $cards[1]['suit'];
                }
                if ( $variant === 'ace_of_spades' ) {
                    foreach ( $cards as $c ) {
                        if ( $c['rank'] === 'A' && $c['suit'] === '♠' ) return true;
                    }
                    return false;
                }
                return false;

            case 'chip_threshold':
                $net = ( $data['payout'] ?? 0 ) - ( $data['wager'] ?? 0 );
                return $net >= (int) ( $params['threshold'] ?? 5000 );

            case 'hand_value':
                $val   = $data['player_value'] ?? 0;
                $cards = $data['player_cards'] ?? [];
                $target_val   = (int) ( $params['target_value'] ?? 21 );
                $target_cards = (int) ( $params['card_count'] ?? 0 );
                if ( $val !== $target_val ) return false;
                return $target_cards <= 0 || count( $cards ) === $target_cards;
        }
        return false;
    }

    private function check_roulette_jackpot( $user_id, $type, $params, $data ) {
        $number = $data['number'] ?? -1;
        $label  = $data['label']  ?? '';
        $color  = $data['color']  ?? '';
        $payout = $data['payout'] ?? 0;
        $bet    = $data['bet']    ?? 0;

        switch ( $type ) {
            case 'specific_number':
                $target = $params['number'] ?? '00';
                return $label === (string) $target;

            case 'number_range':
                $range = $params['range'] ?? 'zeros';
                if ( $range === 'zeros' )  return $label === '0' || $label === '00';
                if ( $range === 'first' )  return $number >= 1 && $number <= 12 && $label !== '00';
                if ( $range === 'second' ) return $number >= 13 && $number <= 24;
                if ( $range === 'third' )  return $number >= 25 && $number <= 36;
                return false;

            case 'same_number_streak':
                $last   = get_user_meta( $user_id, '_fishotel_roul_last_label', true );
                $streak = (int) get_user_meta( $user_id, '_fishotel_roul_streak', true );
                if ( $label === $last ) {
                    $streak++;
                } else {
                    $streak = 1;
                }
                update_user_meta( $user_id, '_fishotel_roul_last_label', $label );
                update_user_meta( $user_id, '_fishotel_roul_streak', $streak );
                if ( $streak >= (int) ( $params['streak_length'] ?? 2 ) ) {
                    update_user_meta( $user_id, '_fishotel_roul_streak', 0 );
                    return true;
                }
                return false;

            case 'color_streak':
                $target_color = $params['color'] ?? 'red';
                $ckey  = '_fishotel_roul_color_streak';
                $count = (int) get_user_meta( $user_id, $ckey, true );
                if ( $color === $target_color ) {
                    $count++;
                    update_user_meta( $user_id, $ckey, $count );
                    if ( $count >= (int) ( $params['streak_length'] ?? 5 ) ) {
                        update_user_meta( $user_id, $ckey, 0 );
                        return true;
                    }
                } else {
                    update_user_meta( $user_id, $ckey, 0 );
                }
                return false;

            case 'chip_threshold':
                return ( $payout - $bet ) >= (int) ( $params['threshold'] ?? 5000 );
        }
        return false;
    }

    private function check_slots_jackpot( $type, $params, $data ) {
        switch ( $type ) {
            case 'multiplier_threshold':
                return ( $data['multiplier'] ?? 0 ) >= (int) ( $params['multiplier'] ?? 50 );

            case 'specific_symbol':
                $target = $params['symbol'] ?? '⭐';
                $reels  = $data['reels'] ?? [];
                $count  = 0;
                foreach ( $reels as $r ) { if ( $r === $target ) $count++; }
                return $count >= (int) ( $params['count'] ?? 3 );

            case 'chip_threshold':
                return ( $data['payout'] ?? 0 ) >= (int) ( $params['threshold'] ?? 5000 );
        }
        return false;
    }

    private function check_poker_jackpot( $type, $params, $data ) {
        switch ( $type ) {
            case 'specific_hand':
                $hand_map = [
                    'royal_flush'    => 250,
                    'straight_flush' => 50,
                    'four_of_a_kind' => 25,
                    'full_house'     => 9,
                    'flush'          => 6,
                    'straight'       => 4,
                ];
                $target = $params['hand_type'] ?? 'royal_flush';
                $min_mult = $hand_map[ $target ] ?? 250;
                return ( $data['multiplier'] ?? 0 ) >= $min_mult;

            case 'chip_threshold':
                return ( $data['payout'] ?? 0 ) >= (int) ( $params['threshold'] ?? 5000 );
        }
        return false;
    }

    /* ─── Award physical prize for jackpot ──────────────────── */

    private function award_jackpot_prize( $user_id, $game ) {
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

        $prizes = get_user_meta( $user_id, '_fishotel_physical_prizes', true );
        $prizes = is_array( $prizes ) ? $prizes : [];

        $statuses   = get_option( 'fishotel_batch_statuses', [] );
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
            'jackpot'       => true,
            'sticker_id'    => $sticker->ID,
            'sticker_name'  => $sticker->post_title,
            'sticker_image' => get_the_post_thumbnail_url( $sticker->ID, 'medium' ) ?: '',
            'game'          => $game,
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
     *  SECTION 2 — (Removed in v7.9 — casino floor moved to arcade)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  PLACEHOLDER — Games will be rebuilt as separate renderers
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
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

        $jackpot = $this->check_jackpot( $uid, 'roulette', [
            'number' => $winner['number'], 'color' => $winner['color'],
            'label'  => $winner['label'],  'payout' => $payout, 'bet' => $bet,
        ] );

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
                $jackpot = $this->check_jackpot( $uid, 'blackjack', [
                    'result' => 'blackjack', 'payout' => $payout, 'wager' => $bet,
                    'player_cards' => $player, 'player_value' => $pval,
                ] );
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

            $jackpot = $this->check_jackpot( $uid, 'blackjack', [
                'result' => $result, 'payout' => $payout, 'wager' => $bet,
                'player_cards' => $state['player'], 'player_value' => $pval,
            ] );

            wp_send_json_success( [
                'game_id' => $game_id,
                'state'   => $state,
                'result'  => $result,
                'payout'  => $payout,
                'wager'   => $bet,
                'chips'   => $this->get_chips( $uid ),
                'jackpot' => $jackpot ?: null,
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

        /* Weighted symbol pool — rarer symbols appear less often */
        $pool = [
            'seahorse', 'seahorse', 'seahorse', 'seahorse', 'seahorse', 'seahorse', 'seahorse', 'seahorse', // 8
            'squid',    'squid',    'squid',    'squid',    'squid',    'squid',    'squid',                 // 7
            'octopus',  'octopus',  'octopus',  'octopus',  'octopus',  'octopus',                          // 6
            'dolphin',  'dolphin',  'dolphin',  'dolphin',  'dolphin',                                      // 5
            'puffer',   'puffer',   'puffer',   'puffer',                                                   // 4
            'shark',    'shark',    'shark',                                                                 // 3
            'starfish', 'starfish',                                                                          // 2
            'whale',                                                                                         // 1
        ];
        $reels = [
            $pool[ wp_rand( 0, count( $pool ) - 1 ) ],
            $pool[ wp_rand( 0, count( $pool ) - 1 ) ],
            $pool[ wp_rand( 0, count( $pool ) - 1 ) ],
        ];

        // Payout table (3-of-a-kind multipliers)
        $triple_payouts = [
            'whale' => 50, 'starfish' => 20, 'shark' => 15, 'puffer' => 10,
            'dolphin' => 8, 'octopus' => 6, 'squid' => 5, 'seahorse' => 5,
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

        $jackpot = $this->check_jackpot( $uid, 'slots', [
            'multiplier' => $multiplier, 'reels' => $reels, 'payout' => $payout,
        ] );

        wp_send_json_success( [
            'reels'      => $reels,
            'multiplier' => $multiplier,
            'payout'     => $payout,
            'chips'      => $this->get_chips( $uid ),
            'jackpot'    => $jackpot ?: null,
        ] );
    }


    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     *  SECTION 5B — SAPPHIRE POKER SLOTS (4-reel card slot)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function fishotel_casino_poker_slots_spin() {
        check_ajax_referer( 'fishotel_casino_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

        $bet = max( 1, (int) sanitize_text_field( $_POST['bet'] ?? 0 ) );
        if ( $bet > $this->get_chips( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Not enough chips.' ] );
        }

        /* Weighted card pool — 7 is rarest, 10 is most common */
        $pool = [
            '10', '10', '10', '10', '10', '10', '10', '10',  // 8
            'J',  'J',  'J',  'J',  'J',  'J',               // 6
            'Q',  'Q',  'Q',  'Q',  'Q',                      // 5
            'K',  'K',  'K',                                   // 3
            'A',  'A',                                         // 2
            '7',                                               // 1
        ];

        /* Generate 4 reel results */
        $reels = [];
        for ( $i = 0; $i < 4; $i++ ) {
            $reels[] = $pool[ wp_rand( 0, count( $pool ) - 1 ) ];
        }

        /* Count occurrences of each card */
        $counts = array_count_values( $reels );
        arsort( $counts );
        $values = array_values( $counts );
        $keys   = array_keys( $counts );

        /* 4-of-a-kind payouts */
        $quad_payouts = [
            '7' => 100, 'A' => 50, 'K' => 25, 'Q' => 15, 'J' => 10, '10' => 8,
        ];
        /* 3-of-a-kind payouts */
        $triple_payouts = [
            '7' => 25, 'A' => 15, 'K' => 6, 'Q' => 4, 'J' => 3, '10' => 2,
        ];

        $multiplier = 0;
        $match_type = '';

        if ( $values[0] === 4 ) {
            /* Four of a kind */
            $multiplier = $quad_payouts[ $keys[0] ] ?? 8;
            $match_type = 'four';
        } elseif ( $values[0] === 3 ) {
            /* Three of a kind */
            $multiplier = $triple_payouts[ $keys[0] ] ?? 2;
            $match_type = 'three';
        } elseif ( $values[0] === 2 && isset( $values[1] ) && $values[1] === 2 ) {
            /* Two pair */
            $multiplier = 2;
            $match_type = 'twopair';
        } elseif ( $values[0] === 2 && in_array( $keys[0], [ '7', 'A', 'K' ], true ) ) {
            /* Pair of Kings or better (K, A, 7) */
            $multiplier = 1;
            $match_type = 'pair';
        }

        $payout = $bet * $multiplier;
        $this->deduct_chips( $uid, $bet );
        if ( $payout > 0 ) {
            $this->add_chips( $uid, $payout );
        }
        $this->record_game( $uid, 'poker-slots', $bet, $payout );

        $jackpot = $this->check_jackpot( $uid, 'poker-slots', [
            'multiplier' => $multiplier, 'reels' => $reels, 'payout' => $payout,
        ] );

        wp_send_json_success( [
            'reels'      => $reels,
            'multiplier' => $multiplier,
            'payout'     => $payout,
            'chips'      => $this->get_chips( $uid ),
            'match_type' => $match_type,
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

            $jackpot = $this->check_jackpot( $uid, 'poker', [
                'multiplier' => $multiplier, 'hand_name' => $hand_name, 'payout' => $payout,
            ] );

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

} /* end class FisHotel_Casino */
