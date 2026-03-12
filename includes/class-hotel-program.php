<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait FisHotel_HotelProgram {

    /* ─────────────────────────────────────────────
     *  BOOTSTRAP
     * ───────────────────────────────────────────── */

    public function hotel_program_init() {
        add_shortcode( 'fishotel_hotel_postcard', [ $this, 'hotel_postcard_shortcode' ] );
        add_action( 'update_option_fishotel_batch_statuses', [ $this, 'hotel_maybe_init_schedule' ], 10, 2 );
        add_action( 'admin_post_fishotel_hotel_save_category',   [ $this, 'hotel_save_category' ] );
        add_action( 'admin_post_fishotel_hotel_delete_category', [ $this, 'hotel_delete_category' ] );
        add_action( 'admin_post_fishotel_hotel_save_activity',   [ $this, 'hotel_save_activity' ] );
        add_action( 'admin_post_fishotel_hotel_delete_activity', [ $this, 'hotel_delete_activity' ] );
        add_action( 'admin_post_fishotel_hotel_save_schedule',   [ $this, 'hotel_save_schedule' ] );
        add_action( 'admin_post_fishotel_hotel_init_schedule',   [ $this, 'hotel_init_schedule_handler' ] );
        add_action( 'admin_post_fishotel_hotel_save_graduation', [ $this, 'hotel_save_graduation_handler' ] );
        $this->hotel_seed_defaults();
    }

    /* ─────────────────────────────────────────────
     *  SEED DEFAULTS
     * ───────────────────────────────────────────── */

    private function hotel_seed_defaults() {
        $cats = get_option( 'fishotel_hotel_categories', [] );
        if ( ! empty( $cats ) ) return;

        $default_cats = [
            [ 'id' => 'cat_arrival',    'name' => 'ARRIVAL',    'color' => '#1a3a5c', 'exclude_random' => true,  'label' => 'ARRIVAL' ],
            [ 'id' => 'cat_graduation', 'name' => 'GRADUATION', 'color' => '#96885f', 'exclude_random' => true,  'label' => 'GRAD' ],
            [ 'id' => 'cat_medication', 'name' => 'MEDICATION', 'color' => '#8b0000', 'exclude_random' => true,  'label' => 'MED' ],
            [ 'id' => 'cat_pool',       'name' => 'POOL & SPA', 'color' => '#2a7fba', 'exclude_random' => false, 'label' => 'SPA' ],
            [ 'id' => 'cat_dining',     'name' => 'DINING',     'color' => '#4a7c3f', 'exclude_random' => false, 'label' => 'DINING' ],
            [ 'id' => 'cat_rest',       'name' => 'REST',       'color' => '#6b5a3e', 'exclude_random' => false, 'label' => 'REST' ],
            [ 'id' => 'cat_social',     'name' => 'SOCIAL',     'color' => '#b5651d', 'exclude_random' => false, 'label' => 'SOCIAL' ],
        ];
        update_option( 'fishotel_hotel_categories', $default_cats );

        $default_acts = [
            [
                'id'               => 'act_welcome',
                'name'             => 'Welcome Reception',
                'category_id'      => 'cat_arrival',
                'time_of_day'      => 'morning',
                'scene_type'       => 'lobby',
                'scene_number'     => '01',
                'postcard_message' => 'Your guests have arrived and are being personally escorted to their accommodations. Welcome to The FisHotel.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'First day welcome reception at the lobby.',
            ],
            [
                'id'               => 'act_checkout',
                'name'             => 'Checkout Day',
                'category_id'      => 'cat_graduation',
                'time_of_day'      => 'morning',
                'scene_type'       => 'graduation',
                'scene_number'     => '01',
                'postcard_message' => 'After an exceptional stay, your fish have been cleared for departure. It has been our honor to host them.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Graduation day checkout.',
            ],
            [
                'id'               => 'act_pool_afternoon',
                'name'             => 'Afternoon Poolside Lounging',
                'category_id'      => 'cat_pool',
                'time_of_day'      => 'afternoon',
                'scene_type'       => 'pool',
                'scene_number'     => '04',
                'postcard_message' => 'Your fish are currently enjoying the thermal pools. Do not disturb.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Afternoon poolside relaxation with ocean breezes.',
            ],
            [
                'id'               => 'act_pool_evening',
                'name'             => 'Evening at the Pool',
                'category_id'      => 'cat_pool',
                'time_of_day'      => 'evening',
                'scene_type'       => 'pool',
                'scene_number'     => '01',
                'postcard_message' => 'As the sun sets over the FisHotel grounds, your guests are taking a final evening swim before dinner.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Evening swim session.',
            ],
        ];
        update_option( 'fishotel_hotel_activities', $default_acts );
    }

    /* ─────────────────────────────────────────────
     *  HELPERS — categories & activities
     * ───────────────────────────────────────────── */

    private function hotel_get_categories() {
        return get_option( 'fishotel_hotel_categories', [] );
    }

    private function hotel_get_activities() {
        return get_option( 'fishotel_hotel_activities', [] );
    }

    private function hotel_get_category_by_id( $id ) {
        foreach ( $this->hotel_get_categories() as $c ) {
            if ( $c['id'] === $id ) return $c;
        }
        return null;
    }

    private function hotel_get_activity_by_id( $id ) {
        foreach ( $this->hotel_get_activities() as $a ) {
            if ( $a['id'] === $id ) return $a;
        }
        return null;
    }

    private function hotel_schedule_option_key( $batch_name ) {
        return 'fishotel_hotel_schedule_' . sanitize_key( $batch_name );
    }

    private function hotel_get_schedule( $batch_name ) {
        return get_option( $this->hotel_schedule_option_key( $batch_name ), [] );
    }

    /* ─────────────────────────────────────────────
     *  SCENE IMAGE RESOLUTION
     * ───────────────────────────────────────────── */

    private function hotel_scene_url( $scene_type, $scene_number ) {
        $filename = 'hotel-' . sanitize_key( $scene_type ) . '-scene-' . $scene_number . '.jpg';
        $path     = plugin_dir_path( FISHOTEL_PLUGIN_FILE ) . 'assists/scene/' . $filename;
        if ( file_exists( $path ) ) {
            return plugins_url( 'assists/scene/' . $filename, FISHOTEL_PLUGIN_FILE );
        }
        return false;
    }

    /* ─────────────────────────────────────────────
     *  ACTIVITY RESOLUTION
     * ───────────────────────────────────────────── */

    public function hotel_get_resolved_activity( $batch_name, $day_number ) {
        $schedule = $this->hotel_get_schedule( $batch_name );
        $days     = $schedule['days'] ?? [];
        $slot     = $days[ $day_number ] ?? [ 'assignment_type' => 'random' ];
        $type     = $slot['assignment_type'] ?? 'random';

        // Built-in first day
        if ( $type === 'first_day' ) {
            return [
                'name'             => 'Welcome Reception',
                'category_id'      => 'cat_arrival',
                'time_of_day'      => 'morning',
                'scene_type'       => 'lobby',
                'scene_number'     => '01',
                'postcard_message' => 'Your guests have arrived and are being personally escorted to their accommodations. Welcome to The FisHotel.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Check-in day.',
            ];
        }

        // Built-in graduation
        if ( $type === 'graduation' ) {
            return [
                'name'             => 'Checkout Day',
                'category_id'      => 'cat_graduation',
                'time_of_day'      => 'morning',
                'scene_type'       => 'graduation',
                'scene_number'     => '01',
                'postcard_message' => 'After an exceptional stay, your fish have been cleared for departure. It has been our honor to host them.',
                'postmark_city'    => 'CHAMPLIN, MN',
                'description'      => 'Graduation day.',
            ];
        }

        // Specific activity
        if ( $type === 'activity' && ! empty( $slot['activity_id'] ) ) {
            $act = $this->hotel_get_activity_by_id( $slot['activity_id'] );
            if ( $act ) return $act;
        }

        // Category-seeded random
        if ( $type === 'category' && ! empty( $slot['category_id'] ) ) {
            $acts = array_values( array_filter( $this->hotel_get_activities(), function( $a ) use ( $slot ) {
                return $a['category_id'] === $slot['category_id'];
            } ) );
            if ( ! empty( $acts ) ) {
                $seed = crc32( $batch_name . $day_number );
                return $acts[ abs( $seed ) % count( $acts ) ];
            }
        }

        // Random from all non-excluded categories
        $cats        = $this->hotel_get_categories();
        $excluded    = [];
        foreach ( $cats as $c ) {
            if ( ! empty( $c['exclude_random'] ) ) $excluded[] = $c['id'];
        }
        $pool = array_values( array_filter( $this->hotel_get_activities(), function( $a ) use ( $excluded ) {
            return ! in_array( $a['category_id'], $excluded, true );
        } ) );
        if ( ! empty( $pool ) ) {
            $seed = crc32( $batch_name . $day_number );
            return $pool[ abs( $seed ) % count( $pool ) ];
        }

        // Graceful fallback — no activities exist yet
        return [
            'name'             => 'Settling In',
            'category_id'      => '',
            'time_of_day'      => 'morning',
            'scene_type'       => 'lobby',
            'scene_number'     => '01',
            'postcard_message' => 'Your guests are settling into their rooms at The FisHotel. More activities coming soon!',
            'postmark_city'    => 'CHAMPLIN, MN',
            'description'      => 'Default placeholder.',
        ];
    }

    /* ─────────────────────────────────────────────
     *  STAGE TRANSITION HOOK
     * ───────────────────────────────────────────── */

    public function hotel_maybe_init_schedule( $old_value, $new_value ) {
        if ( ! is_array( $new_value ) ) return;
        $old_value = is_array( $old_value ) ? $old_value : [];

        foreach ( $new_value as $batch_name => $stage ) {
            $prev = $old_value[ $batch_name ] ?? '';
            if ( $stage === 'in_quarantine' && $prev !== 'in_quarantine' ) {
                $existing = $this->hotel_get_schedule( $batch_name );
                if ( empty( $existing ) ) {
                    $this->hotel_create_default_schedule( $batch_name );
                }
            }
        }
    }

    private function hotel_create_default_schedule( $batch_name ) {
        $days = [];
        for ( $d = 1; $d <= 21; $d++ ) {
            $days[ $d ] = [
                'assignment_type' => ( $d === 1 ) ? 'first_day' : 'random',
                'category_id'     => null,
                'activity_id'     => null,
            ];
        }
        $schedule = [
            'batch_name'      => $batch_name,
            'stage4_start'    => current_time( 'Y-m-d' ),
            'graduation_date' => '',
            'days'            => $days,
        ];
        update_option( $this->hotel_schedule_option_key( $batch_name ), $schedule );
    }

    /* ─────────────────────────────────────────────
     *  SHORTCODE — [fishotel_hotel_postcard]
     * ───────────────────────────────────────────── */

    public function hotel_postcard_shortcode( $batch_name = null ) {
        if ( is_admin() ) return '';

        if ( ! $batch_name ) {
            $current_slug = get_post_field( 'post_name', get_the_ID() );
            $assignments  = get_option( 'fishotel_batch_page_assignments', [] );
            $batch_name   = array_search( $current_slug, $assignments );
        }

        if ( ! $batch_name ) return '';
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $status   = $statuses[ $batch_name ] ?? '';
        if ( $status !== 'in_quarantine' ) return '';

        $schedule = $this->hotel_get_schedule( $batch_name );
        if ( empty( $schedule ) ) return '';

        $start_ts   = strtotime( $schedule['stage4_start'] ?? 'today' );
        $now_ts     = strtotime( current_time( 'Y-m-d' ) );
        $day_number = max( 1, min( 21, (int) floor( ( $now_ts - $start_ts ) / 86400 ) + 1 ) );

        $activity  = $this->hotel_get_resolved_activity( $batch_name, $day_number );
        $scene_url = $this->hotel_scene_url( $activity['scene_type'] ?? 'lobby', $activity['scene_number'] ?? '01' );

        $postcard_message = esc_html( $activity['postcard_message'] ?? '' );
        $activity_name    = esc_html( $activity['name'] ?? '' );
        $postmark_city    = esc_html( $activity['postmark_city'] ?? 'CHAMPLIN, MN' );
        $postmark_date    = strtoupper( date_i18n( 'M j, Y' ) );

        // Building data — all rooms keyed by tank number
        // Must match exactly how class-admin.php save_arrival_data_handler() and
        // class-ajax.php ajax_save_arrival_field() write: post meta '_arrival_tank'
        // on post_type 'fish_batch', with values like '101', '102', etc.
        $all_room_ids = [ '101', '102', '103', '104', '201', '202', '203' ];
        $room_map     = array_fill_keys( $all_room_ids, null ); // null = unassigned

        $batch_name_trimmed = trim( $batch_name );
        $batch_fish_q = new WP_Query( [
            'post_type'              => 'fish_batch',
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [ 'key' => '_batch_name', 'value' => $batch_name_trimmed, 'compare' => '=' ],
            ],
        ] );
        $batch_fish = $batch_fish_q->posts;
        foreach ( $batch_fish as $bf ) {
            wp_cache_delete( $bf->ID, 'post_meta' );
            $tank = trim( (string) get_post_meta( $bf->ID, '_arrival_tank', true ) );
            if ( $tank === '' || ! isset( $room_map[ $tank ] ) ) continue;
            $arr_status = get_post_meta( $bf->ID, '_arrival_status', true );
            $qty_recv   = intval( get_post_meta( $bf->ID, '_arrival_qty_received', true ) );
            $qty_doa    = intval( get_post_meta( $bf->ID, '_arrival_qty_doa', true ) );
            $master_id  = get_post_meta( $bf->ID, '_master_id', true );
            $common     = $master_id ? get_the_title( $master_id ) : $bf->post_title;
            $sci_name   = $master_id ? get_post_meta( $master_id, '_scientific_name', true ) : '';
            $room_map[ $tank ] = [
                'fish_id'    => $bf->ID,
                'species'    => $common,
                'sci_name'   => $sci_name,
                'qty'        => $qty_recv,
                'qty_doa'    => $qty_doa,
                'status'     => $arr_status,
                'master_id'  => $master_id,
            ];
        }

        // Determine which rooms belong to logged-in customer
        $logged_in      = is_user_logged_in();
        $customer_rooms = [];
        if ( $logged_in ) {
            $uid      = get_current_user_id();
            $my_reqs  = get_posts( [
                'post_type'   => 'fish_request',
                'numberposts' => -1,
                'post_status' => 'any',
                'meta_query'  => [
                    'relation' => 'AND',
                    [ 'key' => '_customer_id', 'value' => $uid,        'compare' => '=' ],
                    [ 'key' => '_batch_name',  'value' => $batch_name, 'compare' => '=' ],
                ],
            ] );
            $my_batch_ids = [];
            foreach ( $my_reqs as $req ) {
                $items = json_decode( get_post_meta( $req->ID, '_cart_items', true ), true ) ?: [];
                foreach ( $items as $item ) {
                    $bid = intval( $item['batch_id'] ?? 0 );
                    if ( $bid ) $my_batch_ids[ $bid ] = true;
                }
            }
            foreach ( $room_map as $tank => $data ) {
                if ( $data && isset( $my_batch_ids[ $data['fish_id'] ] ) ) {
                    $customer_rooms[ $tank ] = true;
                }
            }
        }

        ob_start();
        ?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Klee+One&display=swap" rel="stylesheet">
<style>
/* ── FisHotel Postcard ─────────────────────────────────── */
.fh-hotel-postcard-wrap{max-width:900px;width:100%;margin:0 auto;font-family:'Oswald',sans-serif;-webkit-font-smoothing:antialiased}
.fh-hotel-postcard-wrap *{box-sizing:border-box}

/* Card container */
.fh-hotel-card{width:100%;aspect-ratio:3/2;perspective:1200px;margin:0 auto;cursor:pointer}
.fh-hotel-card-inner{position:relative;width:100%;height:100%;transition:transform 0.6s ease;transform-style:preserve-3d}
.fh-hotel-card[data-flipped="true"] .fh-hotel-card-inner{transform:rotateY(180deg)}
.fh-hotel-postcard-front,.fh-hotel-postcard-back{position:absolute;top:0;left:0;width:100%;height:100%;-webkit-backface-visibility:hidden;backface-visibility:hidden;border-radius:6px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.4)}

/* FRONT */
.fh-hotel-postcard-front{background:#f5f0e8 !important}
.fh-hotel-postcard-scene{width:100%;height:75%;background-size:cover !important;background-position:center !important;background-color:#2e2418 !important;position:relative}
.fh-hotel-postcard-scene-placeholder{width:100%;height:75%;display:flex;align-items:center;justify-content:center;background:#f5f0e8 !important;color:#96885f !important;font-family:'Oswald',sans-serif;font-size:18px;letter-spacing:0.05em;border-bottom:1px solid #d6cfc2}
.fh-hotel-postcard-day-badge{position:absolute;top:12px;left:12px;background:#1a3a5c !important;color:#fff !important;font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;padding:4px 12px;letter-spacing:0.15em;border-radius:2px}
.fh-hotel-postcard-front-strip{height:25%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 16px;background:#f5f0e8 !important}
.fh-hotel-postcard-hotel-name{font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;color:#96885f !important;letter-spacing:0.2em;text-transform:uppercase}
.fh-hotel-postcard-activity-name{font-family:'Oswald',sans-serif;font-size:13px;color:#2e2418 !important;margin-top:4px;letter-spacing:0.05em}
.fh-hotel-flip-btn{background:#1a3a5c !important;color:#fff !important;font-family:'Oswald',sans-serif;font-size:12px;letter-spacing:0.15em;padding:6px 16px;border:none;border-radius:20px;cursor:pointer;margin-top:8px}
.fh-hotel-flip-btn:hover{background:#2a5a8c !important}

/* BACK */
.fh-hotel-postcard-back{transform:rotateY(180deg);background:#f5f0e8 !important;display:flex;flex-direction:row}
.fh-hotel-postcard-back-left{flex:1;padding:28px 24px;display:flex;flex-direction:column;border-right:1px solid #96885f}
.fh-hotel-postcard-back-header{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;color:#1a3a5c !important;letter-spacing:0.15em;text-transform:uppercase}
.fh-hotel-postcard-back-divider{width:60px;height:2px;background:linear-gradient(90deg,#96885f,transparent) !important;margin:10px 0 16px}
.fh-hotel-postcard-back-message{font-family:'Klee One',cursive;font-size:15px;color:#2e2418 !important;line-height:1.7;flex:1}
.fh-hotel-postcard-back-signature{font-family:'Klee One',cursive;font-size:13px;color:#96885f !important;margin-top:12px}
.fh-hotel-postcard-back-right{width:240px;padding:20px;display:flex;flex-direction:column;position:relative}
.fh-hotel-postcard-stamp-area{display:flex;justify-content:flex-end}
.fh-hotel-postcard-stamp{width:52px;height:60px;border:2px solid #96885f;display:flex;align-items:center;justify-content:center;font-size:28px;background:#faf7f0 !important}
.fh-hotel-postcard-postmark{margin-top:12px;width:90px;height:90px;border:2px solid #8b0000;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotate(-12deg);opacity:0.7}
.fh-hotel-postcard-postmark-city{font-family:'Oswald',sans-serif;font-size:8px;font-weight:700;color:#8b0000 !important;letter-spacing:0.08em;text-align:center}
.fh-hotel-postcard-postmark-date{font-family:'Oswald',sans-serif;font-size:7px;color:#8b0000 !important;margin-top:2px}
.fh-hotel-postcard-address-lines{margin-top:auto}
.fh-hotel-postcard-address-label{font-family:'Courier New',monospace;font-size:11px;color:#2e2418 !important;margin-bottom:6px;letter-spacing:0.05em}
.fh-hotel-postcard-address-line{height:1px;background:#96885f !important;margin:8px 0;opacity:0.5}

/* HOTEL BUILDING */
.fh-hotel-building{background:#1a1a1a !important;border:1px solid #3a3a3a;border-radius:4px;margin-top:24px;overflow:hidden}
.fh-hotel-building-roof{background:#1a3a5c !important;padding:10px 16px;text-align:center}
.fh-hotel-building-sign{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;color:#c8a96e !important;letter-spacing:0.2em;text-transform:uppercase}
.fh-hotel-floor{display:flex}
.fh-hotel-floor + .fh-hotel-floor{border-top:2px solid #3a3a3a}
.fh-hotel-room{flex:1;min-height:120px;border-right:1px solid #3a3a3a;padding:12px 10px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;transition:background 0.2s;position:relative}
.fh-hotel-room:last-child{border-right:none}
.fh-hotel-room:hover{background:rgba(255,255,255,0.03) !important}
.fh-floor-2 .fh-hotel-room{min-height:160px}
.fh-hotel-room-number{font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;color:#96885f !important;letter-spacing:0.15em;position:absolute;top:8px;left:10px}
.fh-hotel-room-species{font-family:'Oswald',sans-serif;font-size:13px;color:#e1e1e1 !important;text-align:center;margin-top:4px;word-break:break-word}
.fh-hotel-room-fish{font-size:22px;margin-bottom:4px}
.fh-hotel-room-qty{font-family:'Oswald',sans-serif;font-size:10px;color:#777 !important;margin-top:4px}
.fh-hotel-room-yours{font-family:'Oswald',sans-serif;font-size:9px;color:#c8a96e !important;letter-spacing:0.1em;margin-bottom:2px}
/* States */
.fh-hotel-room--occupied{background:rgba(245,240,232,0.06) !important}
.fh-hotel-room--noarrival{background:#2a2a2a !important}
.fh-hotel-room--noarrival .fh-hotel-room-species{color:#cc4444 !important;font-size:10px}
.fh-hotel-room--unassigned{background:#1a1a1a !important}
.fh-hotel-room--unassigned .fh-hotel-room-species{color:#555 !important;font-size:16px}
.fh-hotel-room--mine{box-shadow:inset 0 0 12px rgba(150,136,95,0.4) !important}
.fh-hotel-room--mine .fh-hotel-room-number{color:#c8a96e !important}
.fh-hotel-building-base{background:#0a0a0a !important;height:8px}
/* Room detail expand */
.fh-hotel-room-detail{display:none;background:#111 !important;border-top:1px solid #3a3a3a;padding:20px 24px}
.fh-hotel-room-detail--open{display:block}
.fh-hotel-room-detail-name{font-family:'Oswald',sans-serif;font-size:20px;color:#e1e1e1 !important;font-weight:600}
.fh-hotel-room-detail-sci{font-family:'Oswald',sans-serif;font-size:13px;color:#888 !important;font-style:italic;margin-top:2px}
.fh-hotel-room-detail-meta{font-family:'Oswald',sans-serif;font-size:12px;color:#aaa !important;margin-top:10px;line-height:1.8}
.fh-hotel-room-detail-yours{font-family:'Oswald',sans-serif;font-size:13px;color:#c8a96e !important;margin-top:10px}
.fh-hotel-room-detail-close{position:absolute;top:12px;right:16px;background:none;border:none;color:#666;font-size:20px;cursor:pointer;font-family:'Oswald',sans-serif}
.fh-hotel-room-detail-close:hover{color:#fff}

/* RESPONSIVE */
@media(max-width:640px){
    .fh-hotel-postcard-wrap{width:100%}
    .fh-hotel-card{width:100%}
    .fh-hotel-card-inner{transform:none !important}
    .fh-hotel-postcard-front,.fh-hotel-postcard-back{position:relative;backface-visibility:visible;-webkit-backface-visibility:visible;transform:none !important}
    .fh-hotel-postcard-back{display:none;margin-top:0;border-radius:0 0 6px 6px}
    .fh-hotel-card[data-flipped="true"] .fh-hotel-postcard-front .fh-hotel-postcard-scene,
    .fh-hotel-card[data-flipped="true"] .fh-hotel-postcard-front .fh-hotel-postcard-front-strip{display:none}
    .fh-hotel-card[data-flipped="true"] .fh-hotel-postcard-back{display:flex;box-shadow:0 4px 20px rgba(0,0,0,0.4)}
    .fh-hotel-postcard-back-right{width:180px;padding:14px}
    .fh-hotel-postcard-back-left{padding:20px 16px}
    .fh-hotel-postcard-stamp{width:40px;height:48px;font-size:22px}
    .fh-hotel-postcard-postmark{width:70px;height:70px}
    .fh-floor-1{flex-wrap:wrap}
    .fh-floor-1 .fh-hotel-room{flex:1 1 48%;min-height:90px}
    .fh-floor-2{flex-direction:column}
    .fh-floor-2 .fh-hotel-room{min-height:120px;border-right:none;border-bottom:1px solid #3a3a3a}
    .fh-floor-2 .fh-hotel-room:last-child{border-bottom:none}
}
</style>

<div class="fh-hotel-postcard-wrap">
    <div class="fh-hotel-card" data-flipped="false" onclick="this.dataset.flipped=this.dataset.flipped==='true'?'false':'true'">
        <div class="fh-hotel-card-inner">
            <!-- FRONT -->
            <div class="fh-hotel-postcard-front">
                <?php if ( $scene_url ) : ?>
                    <div class="fh-hotel-postcard-scene" style="background-image:url('<?php echo esc_url( $scene_url ); ?>');">
                        <div class="fh-hotel-postcard-day-badge">DAY <?php echo intval( $day_number ); ?></div>
                    </div>
                <?php else : ?>
                    <div class="fh-hotel-postcard-scene-placeholder">
                        <div class="fh-hotel-postcard-day-badge">DAY <?php echo intval( $day_number ); ?></div>
                        <span>Scene Coming Soon</span>
                    </div>
                <?php endif; ?>
                <div class="fh-hotel-postcard-front-strip">
                    <div class="fh-hotel-postcard-hotel-name">THE FISHOTEL</div>
                    <div class="fh-hotel-postcard-activity-name"><?php echo $activity_name; ?></div>
                    <button class="fh-hotel-flip-btn">TURN OVER</button>
                </div>
            </div>

            <!-- BACK -->
            <div class="fh-hotel-postcard-back">
                <div class="fh-hotel-postcard-back-left">
                    <div class="fh-hotel-postcard-back-header">GREETINGS FROM THE FISHOTEL</div>
                    <div class="fh-hotel-postcard-back-divider"></div>
                    <div class="fh-hotel-postcard-back-message"><?php echo $postcard_message; ?></div>
                    <div class="fh-hotel-postcard-back-signature">— The FisHotel Concierge</div>
                </div>
                <div class="fh-hotel-postcard-back-right">
                    <div class="fh-hotel-postcard-stamp-area">
                        <div class="fh-hotel-postcard-stamp">&#x1F420;</div>
                    </div>
                    <div class="fh-hotel-postcard-postmark">
                        <div class="fh-hotel-postcard-postmark-city"><?php echo $postmark_city; ?></div>
                        <div class="fh-hotel-postcard-postmark-date"><?php echo esc_html( $postmark_date ); ?></div>
                    </div>
                    <div class="fh-hotel-postcard-address-lines">
                        <div class="fh-hotel-postcard-address-label">TO: OUR VALUED GUEST</div>
                        <div class="fh-hotel-postcard-address-line"></div>
                        <div class="fh-hotel-postcard-address-line"></div>
                        <div class="fh-hotel-postcard-address-line"></div>
                    </div>
                    <button class="fh-hotel-flip-btn">TURN OVER</button>
                </div>
            </div>
        </div>
    </div>

    <!-- HOTEL BUILDING -->
    <div class="fh-hotel-building">
        <div class="fh-hotel-building-roof">
            <div class="fh-hotel-building-sign">THE FISHOTEL</div>
        </div>
        <?php
        $floors = [
            1 => [ '101', '102', '103', '104' ],
            2 => [ '201', '202', '203' ],
        ];
        $floor_labels = [ 1 => '20 Gallon', 2 => '40 Gallon' ];
        foreach ( $floors as $fn => $floor_rooms ) :
        ?>
        <div class="fh-hotel-floor fh-floor-<?php echo $fn; ?>">
            <?php foreach ( $floor_rooms as $tank_id ) :
                $rd       = $room_map[ $tank_id ];
                $is_mine  = isset( $customer_rooms[ $tank_id ] );
                $state    = 'unassigned';
                if ( $rd ) {
                    $state = ( $rd['status'] === 'no_arrival' ) ? 'noarrival' : 'occupied';
                }
                $cls = 'fh-hotel-room fh-hotel-room--' . $state;
                if ( $is_mine ) $cls .= ' fh-hotel-room--mine';
            ?>
                <div class="<?php echo esc_attr( $cls ); ?>" data-room="<?php echo esc_attr( $tank_id ); ?>" onclick="fishotelHotelToggleRoom('<?php echo esc_js( $tank_id ); ?>')">
                    <div class="fh-hotel-room-number"><?php echo esc_html( $tank_id ); ?></div>
                    <?php if ( $rd && $state === 'occupied' ) : ?>
                        <?php if ( $is_mine ) : ?><div class="fh-hotel-room-yours">YOUR ROOM</div><?php endif; ?>
                        <div class="fh-hotel-room-fish">&#x1F420;</div>
                        <div class="fh-hotel-room-species"><?php echo esc_html( $rd['species'] ); ?></div>
                        <div class="fh-hotel-room-qty"><?php echo intval( $rd['qty'] ); ?> guest<?php echo $rd['qty'] !== 1 ? 's' : ''; ?></div>
                    <?php elseif ( $state === 'noarrival' ) : ?>
                        <div class="fh-hotel-room-species">NO ARRIVAL</div>
                    <?php else : ?>
                        <div class="fh-hotel-room-species">&mdash;</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <div class="fh-hotel-building-base"></div>

        <?php /* Room detail panels — one per room, hidden */ ?>
        <?php foreach ( $all_room_ids as $tank_id ) :
            $rd = $room_map[ $tank_id ];
            $is_mine  = isset( $customer_rooms[ $tank_id ] );
            $floor_num = ( $tank_id[0] === '2' ) ? 2 : 1;
            $floor_lbl = 'Floor ' . $floor_num . ' — ' . $floor_labels[ $floor_num ];
        ?>
        <div class="fh-hotel-room-detail" id="fh-room-detail-<?php echo esc_attr( $tank_id ); ?>" style="position:relative;">
            <button class="fh-hotel-room-detail-close" onclick="fishotelHotelToggleRoom('<?php echo esc_js( $tank_id ); ?>')">&times;</button>
            <?php if ( $rd ) : ?>
                <div class="fh-hotel-room-detail-name"><?php echo esc_html( $rd['species'] ); ?></div>
                <?php if ( ! empty( $rd['sci_name'] ) ) : ?>
                    <div class="fh-hotel-room-detail-sci"><?php echo esc_html( $rd['sci_name'] ); ?></div>
                <?php endif; ?>
                <div class="fh-hotel-room-detail-meta">
                    Tank: <?php echo esc_html( $tank_id ); ?> (<?php echo esc_html( $floor_lbl ); ?>)<br>
                    Received: <?php echo intval( $rd['qty'] ); ?> &bull; DOA: <?php echo intval( $rd['qty_doa'] ); ?><br>
                    Status: <?php echo esc_html( ucwords( str_replace( '_', ' ', $rd['status'] ) ) ); ?>
                </div>
                <?php if ( $is_mine ) : ?>
                    <div class="fh-hotel-room-detail-yours">Your <?php echo esc_html( $rd['species'] ); ?> is staying in Room <?php echo esc_html( $tank_id ); ?></div>
                <?php endif; ?>
            <?php else : ?>
                <div class="fh-hotel-room-detail-name">Room <?php echo esc_html( $tank_id ); ?></div>
                <div class="fh-hotel-room-detail-meta"><?php echo esc_html( $floor_lbl ); ?><br>No guest assigned.</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
    var fishotelHotelOpenRoom = null;
    function fishotelHotelToggleRoom(id) {
        var panels = document.querySelectorAll('.fh-hotel-room-detail');
        if (fishotelHotelOpenRoom === id) {
            document.getElementById('fh-room-detail-' + id).classList.remove('fh-hotel-room-detail--open');
            fishotelHotelOpenRoom = null;
            return;
        }
        panels.forEach(function(p){ p.classList.remove('fh-hotel-room-detail--open'); });
        var el = document.getElementById('fh-room-detail-' + id);
        if (el) { el.classList.add('fh-hotel-room-detail--open'); }
        fishotelHotelOpenRoom = id;
    }
    </script>
</div>
        <?php
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────────
     *  ADMIN — Hotel Program page render
     * ───────────────────────────────────────────── */

    public function hotel_program_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $tab = sanitize_key( $_GET['tab'] ?? 'categories' );
        $tabs = [
            'categories' => 'Categories',
            'activities' => 'Activities',
            'schedule'   => 'Schedule',
        ];
        ?>
        <div class="wrap" style="max-width:1100px;">
            <h1 style="font-size:24px;margin-bottom:4px;">Hotel Program</h1>
            <p style="color:#aaa;margin-top:0;">Manage the FisHotel guest experience — activities, categories &amp; daily schedule.</p>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=' . $key ) ); ?>"
                       class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            if ( $tab === 'categories' ) {
                $this->hotel_tab_categories();
            } elseif ( $tab === 'activities' ) {
                $this->hotel_tab_activities();
            } elseif ( $tab === 'schedule' ) {
                $this->hotel_tab_schedule();
            }
            ?>
        </div>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  TAB: Categories
     * ───────────────────────────────────────────── */

    private function hotel_tab_categories() {
        $categories = $this->hotel_get_categories();
        $activities = $this->hotel_get_activities();
        $editing_id = sanitize_text_field( $_GET['edit_cat'] ?? '' );
        $editing    = null;
        if ( $editing_id ) {
            $editing = $this->hotel_get_category_by_id( $editing_id );
        }

        // Success / error notices
        if ( isset( $_GET['cat_saved'] ) ) echo '<div class="notice notice-success"><p>Category saved.</p></div>';
        if ( isset( $_GET['cat_deleted'] ) ) echo '<div class="notice notice-success"><p>Category deleted.</p></div>';
        if ( isset( $_GET['cat_error'] ) ) echo '<div class="notice notice-error"><p>' . esc_html( urldecode( $_GET['cat_error'] ) ) . '</p></div>';
        ?>
        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th style="width:40px;">Color</th>
                    <th>Name</th>
                    <th>Label</th>
                    <th>Exclude Random</th>
                    <th>Activities</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $categories ) ) : ?>
                    <tr><td colspan="6" style="color:#aaa;text-align:center;padding:20px;">No categories yet. Add one below.</td></tr>
                <?php endif; ?>
                <?php foreach ( $categories as $cat ) :
                    $act_count = count( array_filter( $activities, function( $a ) use ( $cat ) { return $a['category_id'] === $cat['id']; } ) );
                ?>
                    <tr>
                        <td><span style="display:inline-block;width:20px;height:20px;border-radius:3px;background:<?php echo esc_attr( $cat['color'] ); ?>;"></span></td>
                        <td><?php echo esc_html( $cat['name'] ); ?></td>
                        <td><code><?php echo esc_html( $cat['label'] ); ?></code></td>
                        <td><?php echo ! empty( $cat['exclude_random'] ) ? 'Yes' : '—'; ?></td>
                        <td><?php echo intval( $act_count ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&edit_cat=' . $cat['id'] ) ); ?>">Edit</a>
                            <?php if ( $act_count === 0 ) : ?>
                                &nbsp;|&nbsp;
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_hotel_delete_category&cat_id=' . $cat['id'] ), 'fishotel_hotel_delete_category' ) ); ?>"
                                   onclick="return confirm('Delete this category?')" style="color:#a00;">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:24px;"><?php echo $editing ? 'Edit Category' : 'Add Category'; ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
            <?php wp_nonce_field( 'fishotel_hotel_save_category' ); ?>
            <input type="hidden" name="action" value="fishotel_hotel_save_category">
            <input type="hidden" name="cat_id" value="<?php echo esc_attr( $editing['id'] ?? '' ); ?>">
            <table class="form-table">
                <tr><th><label>Name</label></th>
                    <td><input type="text" name="cat_name" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" class="regular-text" required></td></tr>
                <tr><th><label>Short Label</label></th>
                    <td><input type="text" name="cat_label" value="<?php echo esc_attr( $editing['label'] ?? '' ); ?>" maxlength="10" style="width:120px;" required>
                        <p class="description">Max 10 chars — shown on schedule calendar.</p></td></tr>
                <tr><th><label>Color</label></th>
                    <td><input type="color" name="cat_color" value="<?php echo esc_attr( $editing['color'] ?? '#2a7fba' ); ?>"></td></tr>
                <tr><th><label>Exclude from Random</label></th>
                    <td><label><input type="checkbox" name="cat_exclude_random" value="1" <?php checked( ! empty( $editing['exclude_random'] ) ); ?>>
                        If checked, activities in this category will never be selected by the random scheduler.</label></td></tr>
            </table>
            <?php submit_button( $editing ? 'Update Category' : 'Add Category' ); ?>
        </form>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  TAB: Activities
     * ───────────────────────────────────────────── */

    private function hotel_tab_activities() {
        $activities  = $this->hotel_get_activities();
        $categories  = $this->hotel_get_categories();
        $editing_id  = sanitize_text_field( $_GET['edit_act'] ?? '' );
        $editing     = null;
        if ( $editing_id ) {
            $editing = $this->hotel_get_activity_by_id( $editing_id );
        }

        if ( isset( $_GET['act_saved'] ) ) echo '<div class="notice notice-success"><p>Activity saved.</p></div>';
        if ( isset( $_GET['act_deleted'] ) ) echo '<div class="notice notice-success"><p>Activity deleted.</p></div>';

        $scene_types = [ 'pool', 'spa', 'dining', 'lobby', 'beach', 'suite', 'bar', 'graduation' ];
        $times       = [ 'morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening', 'night' => 'Night' ];

        $plugin_base_url = plugins_url( 'assists/scene/', FISHOTEL_PLUGIN_FILE );
        ?>
        <table class="widefat striped" style="max-width:1000px;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Time</th>
                    <th>Scene</th>
                    <th>Message</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $activities ) ) : ?>
                    <tr><td colspan="6" style="color:#aaa;text-align:center;padding:20px;">No activities yet. Add one below.</td></tr>
                <?php endif; ?>
                <?php foreach ( $activities as $act ) :
                    $cat = $this->hotel_get_category_by_id( $act['category_id'] );
                ?>
                    <tr>
                        <td><?php echo esc_html( $act['name'] ); ?></td>
                        <td><?php if ( $cat ) : ?><span style="display:inline-block;padding:2px 8px;border-radius:3px;background:<?php echo esc_attr( $cat['color'] ); ?>;color:#fff;font-size:11px;"><?php echo esc_html( $cat['label'] ); ?></span><?php else : ?>—<?php endif; ?></td>
                        <td><?php echo esc_html( ucfirst( $act['time_of_day'] ?? '' ) ); ?></td>
                        <td><code><?php echo esc_html( ( $act['scene_type'] ?? '' ) . '-' . ( $act['scene_number'] ?? '' ) ); ?></code></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $act['postcard_message'] ?? '' ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=fishotel-hotel-program&tab=activities&edit_act=' . $act['id'] ) ); ?>">Edit</a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_hotel_delete_activity&act_id=' . $act['id'] ), 'fishotel_hotel_delete_activity' ) ); ?>"
                               onclick="return confirm('Delete this activity?')" style="color:#a00;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:24px;"><?php echo $editing ? 'Edit Activity' : 'Add Activity'; ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:600px;">
            <?php wp_nonce_field( 'fishotel_hotel_save_activity' ); ?>
            <input type="hidden" name="action" value="fishotel_hotel_save_activity">
            <input type="hidden" name="act_id" value="<?php echo esc_attr( $editing['id'] ?? '' ); ?>">
            <table class="form-table">
                <tr><th><label>Activity Name</label></th>
                    <td><input type="text" name="act_name" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" class="regular-text" required></td></tr>
                <tr><th><label>Category</label></th>
                    <td><select name="act_category_id" required>
                        <option value="">— select —</option>
                        <?php foreach ( $categories as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['id'] ); ?>" <?php selected( ( $editing['category_id'] ?? '' ), $c['id'] ); ?>><?php echo esc_html( $c['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label>Time of Day</label></th>
                    <td><select name="act_time_of_day">
                        <?php foreach ( $times as $k => $v ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( ( $editing['time_of_day'] ?? '' ), $k ); ?>><?php echo esc_html( $v ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label>Scene Type</label></th>
                    <td><select name="act_scene_type" id="fh-hotel-scene-type" onchange="fishotelHotelPreviewScene()">
                        <?php foreach ( $scene_types as $st ) : ?>
                            <option value="<?php echo esc_attr( $st ); ?>" <?php selected( ( $editing['scene_type'] ?? '' ), $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label>Scene Number</label></th>
                    <td><input type="text" name="act_scene_number" id="fh-hotel-scene-num" value="<?php echo esc_attr( $editing['scene_number'] ?? '01' ); ?>" style="width:60px;" oninput="fishotelHotelPreviewScene()">
                        <p class="description">Two-digit number matching the scene filename (e.g. 01, 03).</p></td></tr>
                <tr><th><label>Scene Preview</label></th>
                    <td><div id="fh-hotel-scene-preview" style="width:200px;height:133px;border:1px solid #ccc;background:#f5f0e8;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px;overflow:hidden;">Loading...</div></td></tr>
                <tr><th><label>Postcard Message</label></th>
                    <td><textarea name="act_postcard_message" rows="3" class="large-text"><?php echo esc_textarea( $editing['postcard_message'] ?? '' ); ?></textarea>
                        <p class="description">Written in hotel concierge voice. Shown on the back of the customer postcard.</p></td></tr>
                <tr><th><label>Postmark City</label></th>
                    <td><input type="text" name="act_postmark_city" value="<?php echo esc_attr( $editing['postmark_city'] ?? 'CHAMPLIN, MN' ); ?>" class="regular-text"></td></tr>
                <tr><th><label>Description</label></th>
                    <td><input type="text" name="act_description" value="<?php echo esc_attr( $editing['description'] ?? '' ); ?>" class="regular-text">
                        <p class="description">Admin reference only — not shown to customers.</p></td></tr>
            </table>
            <?php submit_button( $editing ? 'Update Activity' : 'Add Activity' ); ?>
        </form>

        <script>
        var fishotelHotelSceneBase = <?php echo wp_json_encode( $plugin_base_url ); ?>;
        function fishotelHotelPreviewScene() {
            var type = document.getElementById('fh-hotel-scene-type').value;
            var num  = document.getElementById('fh-hotel-scene-num').value.padStart(2, '0');
            var url  = fishotelHotelSceneBase + 'hotel-' + type + '-scene-' + num + '.jpg';
            var el   = document.getElementById('fh-hotel-scene-preview');
            var img  = new Image();
            img.onload = function() { el.innerHTML = ''; el.style.backgroundImage = 'url(' + url + ')'; el.style.backgroundSize = 'cover'; el.style.backgroundPosition = 'center'; };
            img.onerror = function() { el.style.backgroundImage = 'none'; el.innerHTML = '<span style="color:#999;font-size:12px;">No image found for that filename.</span>'; };
            img.src = url;
        }
        fishotelHotelPreviewScene();
        </script>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  TAB: Schedule
     * ───────────────────────────────────────────── */

    private function hotel_tab_schedule() {
        $statuses = get_option( 'fishotel_batch_statuses', [] );
        $batches  = array_filter( array_map( 'trim', explode( "\n", get_option( 'fishotel_batches', '' ) ) ) );

        $selected = sanitize_text_field( $_GET['batch'] ?? '' );
        if ( ! $selected && ! empty( $batches ) ) {
            // Default to first graduation batch, or first batch
            foreach ( $batches as $b ) {
                if ( ( $statuses[ $b ] ?? '' ) === 'in_quarantine' ) { $selected = $b; break; }
            }
            if ( ! $selected ) $selected = $batches[0];
        }

        if ( isset( $_GET['schedule_saved'] ) ) echo '<div class="notice notice-success"><p>Schedule saved.</p></div>';
        if ( isset( $_GET['schedule_init'] ) ) echo '<div class="notice notice-success"><p>Schedule initialized.</p></div>';
        if ( isset( $_GET['grad_saved'] ) ) echo '<div class="notice notice-success"><p>Graduation date updated.</p></div>';

        $valid_stages = [
            'open_ordering' => 'Open Ordering', 'orders_closed' => 'Orders Closed',
            'arrived' => 'Arrived', 'in_quarantine' => 'In QT',
            'graduation' => 'Graduation', 'verification' => 'Verification',
            'draft' => 'Draft', 'invoicing' => 'Invoicing',
        ];
        ?>
        <form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="fishotel-hotel-program">
            <input type="hidden" name="tab" value="schedule">
            <label><strong>Batch:</strong>&nbsp;
                <select name="batch" onchange="this.form.submit()">
                    <?php foreach ( $batches as $b ) :
                        $st = $statuses[ $b ] ?? '';
                        $badge = isset( $valid_stages[ $st ] ) ? ' [' . $valid_stages[ $st ] . ']' : '';
                    ?>
                        <option value="<?php echo esc_attr( $b ); ?>" <?php selected( $selected, $b ); ?>><?php echo esc_html( $b . $badge ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php
        if ( ! $selected ) {
            echo '<p style="color:#aaa;">No batches found.</p>';
            return;
        }

        $schedule = $this->hotel_get_schedule( $selected );

        if ( empty( $schedule ) ) {
            ?>
            <div style="background:#f0f0f1;border:1px solid #c3c4c7;padding:20px 24px;max-width:500px;border-radius:4px;">
                <p style="margin-top:0;">Stage 4 has not been initialized for <strong><?php echo esc_html( $selected ); ?></strong>.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'fishotel_hotel_init_schedule' ); ?>
                    <input type="hidden" name="action" value="fishotel_hotel_init_schedule">
                    <input type="hidden" name="batch_name" value="<?php echo esc_attr( $selected ); ?>">
                    <?php submit_button( 'Initialize Schedule', 'primary', 'submit', false ); ?>
                </form>
            </div>
            <?php
            return;
        }

        $start_date = $schedule['stage4_start'] ?? current_time( 'Y-m-d' );
        $grad_date  = $schedule['graduation_date'] ?? '';
        $days       = $schedule['days'] ?? [];
        $categories = $this->hotel_get_categories();
        $activities = $this->hotel_get_activities();

        // Build calendar grid
        $start_ts    = strtotime( $start_date );
        $start_dow   = (int) date( 'N', $start_ts ); // 1=Mon … 7=Sun
        $day_labels  = [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ];
        ?>

        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <!-- Left: schedule info -->
            <div style="flex:0 0 280px;">
                <p><strong>Stage 4 Start:</strong> <?php echo esc_html( date_i18n( 'M j, Y', $start_ts ) ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
                    <?php wp_nonce_field( 'fishotel_hotel_save_graduation' ); ?>
                    <input type="hidden" name="action" value="fishotel_hotel_save_graduation">
                    <input type="hidden" name="batch_name" value="<?php echo esc_attr( $selected ); ?>">
                    <label><strong>Graduation Date:</strong><br>
                        <input type="date" name="graduation_date" value="<?php echo esc_attr( $grad_date ); ?>"
                               min="<?php echo esc_attr( date( 'Y-m-d', strtotime( $start_date . ' +1 day' ) ) ); ?>"
                               max="<?php echo esc_attr( date( 'Y-m-d', strtotime( $start_date . ' +21 days' ) ) ); ?>">
                    </label>
                    <p class="description">QT ends on weekdays only.</p>
                    <?php submit_button( 'Set Graduation Date', 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <!-- Right: calendar grid -->
            <div style="flex:1;min-width:0;overflow-x:auto;">
                <table class="widefat" style="min-width:630px;border-collapse:separate;border-spacing:2px;" id="fh-hotel-schedule-grid">
                    <thead>
                        <tr>
                            <?php foreach ( $day_labels as $dl ) : ?>
                                <th style="text-align:center;padding:6px;font-size:12px;width:14.28%;"><?php echo $dl; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $col = 1;
                        echo '<tr>';
                        // Blank cells before Day 1
                        for ( $blank = 1; $blank < $start_dow; $blank++ ) {
                            echo '<td style="background:#f9f9f9;"></td>';
                            $col++;
                        }

                        for ( $d = 1; $d <= 21; $d++ ) {
                            if ( $col > 7 ) {
                                echo '</tr><tr>';
                                $col = 1;
                            }
                            $cell_date   = date( 'Y-m-d', strtotime( $start_date . ' +' . ( $d - 1 ) . ' days' ) );
                            $cell_dow    = (int) date( 'N', strtotime( $cell_date ) );
                            $is_weekend  = ( $cell_dow >= 6 );
                            $slot        = $days[ $d ] ?? [ 'assignment_type' => 'random' ];
                            $type        = $slot['assignment_type'] ?? 'random';
                            $is_grad_day = ( $grad_date && $cell_date === $grad_date );
                            $is_locked   = ( $d === 1 || $is_grad_day );

                            // Chip display
                            $chip_label = 'RANDOM';
                            $chip_color = '#888';
                            if ( $type === 'first_day' ) {
                                $chip_label = 'FIRST DAY';
                                $chip_color = '#1a3a5c';
                            } elseif ( $type === 'graduation' || $is_grad_day ) {
                                $chip_label = 'GRADUATION';
                                $chip_color = '#96885f';
                            } elseif ( $type === 'category' && ! empty( $slot['category_id'] ) ) {
                                $cat = $this->hotel_get_category_by_id( $slot['category_id'] );
                                if ( $cat ) { $chip_label = $cat['label']; $chip_color = $cat['color']; }
                            } elseif ( $type === 'activity' && ! empty( $slot['activity_id'] ) ) {
                                $act = $this->hotel_get_activity_by_id( $slot['activity_id'] );
                                if ( $act ) {
                                    $chip_label = substr( $act['name'], 0, 12 );
                                    $cat = $this->hotel_get_category_by_id( $act['category_id'] );
                                    if ( $cat ) $chip_color = $cat['color'];
                                }
                            }

                            $bg = $is_weekend ? '#f5f5f5' : '#fff';
                            $border = 'border:1px solid #ddd;';
                            ?>
                            <td style="<?php echo $border; ?>background:<?php echo $bg; ?>;padding:6px;vertical-align:top;cursor:<?php echo $is_locked ? 'default' : 'pointer'; ?>;min-height:70px;"
                                <?php if ( ! $is_locked ) : ?>onclick="fishotelHotelEditDay(<?php echo $d; ?>)"<?php endif; ?>>
                                <div style="font-size:10px;color:#999;"><?php echo esc_html( date( 'M j', strtotime( $cell_date ) ) ); ?></div>
                                <div style="font-size:12px;font-weight:600;margin:2px 0;">Day <?php echo $d; ?></div>
                                <span style="display:inline-block;padding:2px 6px;border-radius:3px;background:<?php echo esc_attr( $chip_color ); ?>;color:#fff;font-size:10px;font-weight:600;"><?php echo esc_html( $chip_label ); ?></span>
                                <?php if ( $is_locked ) : ?>
                                    <div style="font-size:9px;color:#bbb;margin-top:2px;">locked</div>
                                <?php endif; ?>
                            </td>
                            <?php
                            $col++;
                        }
                        // Fill remaining cells
                        while ( $col <= 7 ) {
                            echo '<td style="background:#f9f9f9;"></td>';
                            $col++;
                        }
                        echo '</tr>';
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Inline editor -->
        <div id="fh-hotel-day-editor" style="display:none;margin-top:16px;padding:16px 20px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;max-width:600px;">
            <h4 style="margin-top:0;">Edit Day <span id="fh-hotel-day-num"></span></h4>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'fishotel_hotel_save_schedule' ); ?>
                <input type="hidden" name="action" value="fishotel_hotel_save_schedule">
                <input type="hidden" name="batch_name" value="<?php echo esc_attr( $selected ); ?>">
                <input type="hidden" name="day_number" id="fh-hotel-day-input" value="">

                <fieldset style="margin-bottom:12px;">
                    <label style="display:block;margin:4px 0;"><input type="radio" name="assignment_type" value="random" checked onchange="fishotelHotelToggleAssign()"> Random</label>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="assignment_type" value="category" onchange="fishotelHotelToggleAssign()"> Category</label>
                    <label style="display:block;margin:4px 0;"><input type="radio" name="assignment_type" value="activity" onchange="fishotelHotelToggleAssign()"> Specific Activity</label>
                </fieldset>

                <div id="fh-hotel-assign-category" style="display:none;margin-bottom:12px;">
                    <select name="category_id">
                        <?php foreach ( $categories as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="fh-hotel-assign-activity" style="display:none;margin-bottom:12px;">
                    <select name="activity_id">
                        <?php
                        $grouped = [];
                        foreach ( $activities as $a ) {
                            $cat = $this->hotel_get_category_by_id( $a['category_id'] );
                            $group = $cat ? $cat['name'] : 'Uncategorized';
                            $grouped[ $group ][] = $a;
                        }
                        foreach ( $grouped as $group => $acts ) :
                        ?>
                            <optgroup label="<?php echo esc_attr( $group ); ?>">
                                <?php foreach ( $acts as $a ) : ?>
                                    <option value="<?php echo esc_attr( $a['id'] ); ?>"><?php echo esc_html( $a['name'] ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php submit_button( 'Save Day', 'primary', 'submit', false ); ?>
                <button type="button" class="button" onclick="document.getElementById('fh-hotel-day-editor').style.display='none'">Cancel</button>
            </form>
        </div>

        <script>
        function fishotelHotelEditDay(dayNum) {
            var editor = document.getElementById('fh-hotel-day-editor');
            editor.style.display = 'block';
            document.getElementById('fh-hotel-day-num').textContent = dayNum;
            document.getElementById('fh-hotel-day-input').value = dayNum;
            // Reset to random
            var radios = editor.querySelectorAll('input[name="assignment_type"]');
            radios[0].checked = true;
            fishotelHotelToggleAssign();
            editor.scrollIntoView({behavior:'smooth', block:'nearest'});
        }
        function fishotelHotelToggleAssign() {
            var val = document.querySelector('input[name="assignment_type"]:checked').value;
            document.getElementById('fh-hotel-assign-category').style.display = (val === 'category') ? 'block' : 'none';
            document.getElementById('fh-hotel-assign-activity').style.display = (val === 'activity') ? 'block' : 'none';
        }
        </script>
        <?php
    }

    /* ─────────────────────────────────────────────
     *  ADMIN POST HANDLERS
     * ───────────────────────────────────────────── */

    public function hotel_save_category() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_category' ) ) {
            wp_die( 'Security check failed.' );
        }
        $cats   = $this->hotel_get_categories();
        $id     = sanitize_text_field( $_POST['cat_id'] ?? '' );
        $data   = [
            'id'             => $id ?: 'cat_' . uniqid(),
            'name'           => sanitize_text_field( $_POST['cat_name'] ?? '' ),
            'label'          => strtoupper( sanitize_text_field( $_POST['cat_label'] ?? '' ) ),
            'color'          => sanitize_hex_color( $_POST['cat_color'] ?? '#2a7fba' ),
            'exclude_random' => ! empty( $_POST['cat_exclude_random'] ),
        ];

        if ( $id ) {
            foreach ( $cats as &$c ) {
                if ( $c['id'] === $id ) { $c = $data; break; }
            }
            unset( $c );
        } else {
            $cats[] = $data;
        }

        update_option( 'fishotel_hotel_categories', $cats );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&cat_saved=1' ) );
        exit;
    }

    public function hotel_delete_category() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'fishotel_hotel_delete_category' ) ) {
            wp_die( 'Security check failed.' );
        }
        $cat_id     = sanitize_text_field( $_GET['cat_id'] ?? '' );
        $activities = $this->hotel_get_activities();
        $has_acts   = false;
        foreach ( $activities as $a ) {
            if ( $a['category_id'] === $cat_id ) { $has_acts = true; break; }
        }
        if ( $has_acts ) {
            wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&cat_error=' . urlencode( 'Cannot delete — category has activities assigned.' ) ) );
            exit;
        }
        $cats = array_values( array_filter( $this->hotel_get_categories(), function( $c ) use ( $cat_id ) {
            return $c['id'] !== $cat_id;
        } ) );
        update_option( 'fishotel_hotel_categories', $cats );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=categories&cat_deleted=1' ) );
        exit;
    }

    public function hotel_save_activity() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_activity' ) ) {
            wp_die( 'Security check failed.' );
        }
        $acts = $this->hotel_get_activities();
        $id   = sanitize_text_field( $_POST['act_id'] ?? '' );
        $data = [
            'id'               => $id ?: 'act_' . uniqid(),
            'name'             => sanitize_text_field( $_POST['act_name'] ?? '' ),
            'category_id'      => sanitize_text_field( $_POST['act_category_id'] ?? '' ),
            'time_of_day'      => sanitize_key( $_POST['act_time_of_day'] ?? 'morning' ),
            'scene_type'       => sanitize_key( $_POST['act_scene_type'] ?? 'pool' ),
            'scene_number'     => str_pad( absint( $_POST['act_scene_number'] ?? 1 ), 2, '0', STR_PAD_LEFT ),
            'postcard_message' => sanitize_textarea_field( $_POST['act_postcard_message'] ?? '' ),
            'postmark_city'    => strtoupper( sanitize_text_field( $_POST['act_postmark_city'] ?? 'CHAMPLIN, MN' ) ),
            'description'      => sanitize_text_field( $_POST['act_description'] ?? '' ),
        ];

        if ( $id ) {
            foreach ( $acts as &$a ) {
                if ( $a['id'] === $id ) { $a = $data; break; }
            }
            unset( $a );
        } else {
            $acts[] = $data;
        }

        update_option( 'fishotel_hotel_activities', $acts );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=activities&act_saved=1' ) );
        exit;
    }

    public function hotel_delete_activity() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'fishotel_hotel_delete_activity' ) ) {
            wp_die( 'Security check failed.' );
        }
        $act_id = sanitize_text_field( $_GET['act_id'] ?? '' );
        $acts   = array_values( array_filter( $this->hotel_get_activities(), function( $a ) use ( $act_id ) {
            return $a['id'] !== $act_id;
        } ) );
        update_option( 'fishotel_hotel_activities', $acts );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=activities&act_deleted=1' ) );
        exit;
    }

    public function hotel_init_schedule_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_init_schedule' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch = sanitize_text_field( $_POST['batch_name'] ?? '' );
        if ( $batch ) {
            $this->hotel_create_default_schedule( $batch );
        }
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=schedule&batch=' . urlencode( $batch ) . '&schedule_init=1' ) );
        exit;
    }

    public function hotel_save_graduation_handler() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_graduation' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch    = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $new_grad = sanitize_text_field( $_POST['graduation_date'] ?? '' );
        $schedule = $this->hotel_get_schedule( $batch );
        if ( empty( $schedule ) ) { wp_die( 'No schedule found.' ); }

        $start  = $schedule['stage4_start'];
        $old_grad = $schedule['graduation_date'] ?? '';

        // Revert old graduation day to random
        if ( $old_grad ) {
            $old_day = (int) floor( ( strtotime( $old_grad ) - strtotime( $start ) ) / 86400 ) + 1;
            if ( isset( $schedule['days'][ $old_day ] ) && $schedule['days'][ $old_day ]['assignment_type'] === 'graduation' ) {
                $schedule['days'][ $old_day ]['assignment_type'] = 'random';
                $schedule['days'][ $old_day ]['category_id'] = null;
                $schedule['days'][ $old_day ]['activity_id'] = null;
            }
        }

        // Set new graduation day
        $schedule['graduation_date'] = $new_grad;
        if ( $new_grad ) {
            $new_day = (int) floor( ( strtotime( $new_grad ) - strtotime( $start ) ) / 86400 ) + 1;
            if ( $new_day >= 1 && $new_day <= 21 ) {
                $schedule['days'][ $new_day ] = [
                    'assignment_type' => 'graduation',
                    'category_id'     => null,
                    'activity_id'     => null,
                ];
            }
        }

        update_option( $this->hotel_schedule_option_key( $batch ), $schedule );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=schedule&batch=' . urlencode( $batch ) . '&grad_saved=1' ) );
        exit;
    }

    public function hotel_save_schedule() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'fishotel_hotel_save_schedule' ) ) {
            wp_die( 'Security check failed.' );
        }
        $batch    = sanitize_text_field( $_POST['batch_name'] ?? '' );
        $day_num  = intval( $_POST['day_number'] ?? 0 );
        $schedule = $this->hotel_get_schedule( $batch );
        if ( empty( $schedule ) || $day_num < 2 || $day_num > 21 ) { wp_die( 'Invalid parameters.' ); }

        $assign = sanitize_key( $_POST['assignment_type'] ?? 'random' );
        $slot   = [
            'assignment_type' => $assign,
            'category_id'     => ( $assign === 'category' ) ? sanitize_text_field( $_POST['category_id'] ?? '' ) : null,
            'activity_id'     => ( $assign === 'activity' ) ? sanitize_text_field( $_POST['activity_id'] ?? '' ) : null,
        ];

        $schedule['days'][ $day_num ] = $slot;
        update_option( $this->hotel_schedule_option_key( $batch ), $schedule );
        wp_redirect( admin_url( 'admin.php?page=fishotel-hotel-program&tab=schedule&batch=' . urlencode( $batch ) . '&schedule_saved=1' ) );
        exit;
    }
}
