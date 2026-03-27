<?php
/**
 * Draft Night Broadcast — Asset discovery and script generation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Discover crowd reaction clips by category
 */
function fh_discover_crowd_clips() {
    $crowd_dir = plugin_dir_path( __FILE__ ) . '../assists/draft/crowd/';
    $clips     = glob( $crowd_dir . '*.mp4' );

    $library = [
        'cheer'  => [],
        'gasp'   => [],
        'murmur' => [],
        'mixed'  => [],
    ];

    foreach ( $clips as $clip ) {
        $basename = basename( $clip );
        $url      = plugins_url( '../assists/draft/crowd/' . $basename, __FILE__ );

        if ( stripos( $basename, 'cheer' ) !== false ) {
            $library['cheer'][] = $url;
        } elseif ( stripos( $basename, 'gasp' ) !== false ) {
            $library['gasp'][] = $url;
        } elseif ( stripos( $basename, 'murmur' ) !== false || stripos( $basename, 'anticipation' ) !== false ) {
            $library['murmur'][] = $url;
        } elseif ( stripos( $basename, 'mixed' ) !== false || stripos( $basename, 'boo' ) !== false ) {
            $library['mixed'][] = $url;
        }
    }

    return $library;
}

/**
 * Discover graphics (backgrounds, logos, overlays)
 */
function fh_discover_draft_graphics() {
    $graphics_dir = plugin_dir_path( __FILE__ ) . '../assists/draft/graphics/';
    $files        = glob( $graphics_dir . '*.*' );

    $graphics = [];
    foreach ( $files as $file ) {
        $basename          = basename( $file );
        $key               = pathinfo( $basename, PATHINFO_FILENAME );
        $graphics[ $key ]  = plugins_url( '../assists/draft/graphics/' . $basename, __FILE__ );
    }

    return $graphics;
}

/**
 * Discover b-roll clips
 */
function fh_discover_broll_clips() {
    $broll_dir = plugin_dir_path( __FILE__ ) . '../assists/draft/broll/';
    $clips     = glob( $broll_dir . '*.mp4' );

    $urls = [];
    foreach ( $clips as $clip ) {
        $basename = basename( $clip );
        $urls[]   = plugins_url( '../assists/draft/broll/' . $basename, __FILE__ );
    }

    return $urls;
}

/**
 * Generate broadcast script from draft results.
 * Returns the script array and saves it to a WP option for replay.
 */
function fh_generate_broadcast_script( $batch_name ) {
    $slug    = sanitize_title( $batch_name );
    $results = get_option( 'fishotel_lastcall_results_' . $slug );

    if ( ! $results || empty( $results['picks'] ) ) {
        return null;
    }

    $crowd_clips = fh_discover_crowd_clips();
    $graphics    = fh_discover_draft_graphics();
    $broll       = fh_discover_broll_clips();

    // Ensure every category has at least one fallback
    foreach ( $crowd_clips as $cat => $clips ) {
        if ( empty( $clips ) ) {
            $all = array_merge( ...array_values( $crowd_clips ) );
            $crowd_clips[ $cat ] = $all ?: [];
        }
    }

    $script = [
        'batch_slug'   => $slug,
        'batch_name'   => $batch_name,
        'generated_at' => time(),
        'assets'       => [
            'crowd'    => $crowd_clips,
            'graphics' => $graphics,
            'broll'    => $broll,
        ],
        'picks' => [],
    ];

    $positions  = [ 'Reef Cleaner', 'Show Fish', 'Peaceful Community', 'Apex Predator', 'Algae Control', 'Tank Mate', 'Centerpiece Fish' ];
    $colleges   = [ 'University of Fiji', 'Bali State', 'Red Sea Tech', 'Marshall Island Marine Institute', 'Indo-Pacific University', 'Coral Sea College' ];
    $nicknames  = [ 'The Tank', 'Speedy', 'Big Blue', 'Flash', 'The Cleaner', 'Splash', 'Rocket', 'Cruiser' ];
    $hot_takes  = [
        'STEAL OF THE DRAFT!',
        'GREAT VALUE at this spot!',
        'Questionable pick here...',
        'Bold move going early on this one',
        'They needed this badly',
        'Surprise pick!',
        'Best fish available',
        'Reaching a bit here',
        'Smart pick - fills a need',
        'Controversial choice',
    ];
    $grades = [ 'A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C' ];

    foreach ( $results['picks'] as $index => $pick ) {
        $pick_num = $index + 1;
        $seed     = abs( crc32( $slug . $pick_num ) );

        // Enrich display name
        $uid      = intval( $pick['user_id'] );
        $hf_name  = get_user_meta( $uid, '_fishotel_humble_username', true );
        if ( ! $hf_name ) {
            $user    = get_user_by( 'id', $uid );
            $hf_name = $user ? $user->display_name : 'User #' . $uid;
        }

        // Determine crowd reaction type
        if ( $pick_num <= 5 ) {
            $reaction_type = 'cheer';
        } elseif ( $seed % 3 === 0 ) {
            $reaction_type = 'gasp';
        } elseif ( $seed % 5 === 0 ) {
            $reaction_type = 'mixed';
        } else {
            $reaction_type = 'cheer';
        }

        $clips_in_category = $crowd_clips[ $reaction_type ];
        if ( empty( $clips_in_category ) ) {
            $clips_in_category = array_merge( ...array_values( $crowd_clips ) );
        }
        $crowd_clip    = ! empty( $clips_in_category ) ? $clips_in_category[ $seed % count( $clips_in_category ) ] : '';
        $slice_start   = ( $seed % 5 ) + 1;
        $slice_duration = ( $seed % 3 ) + 3;

        $position  = $positions[ $seed % count( $positions ) ];
        $college   = $colleges[ ( $seed + 1 ) % count( $colleges ) ];
        $nickname  = $nicknames[ ( $seed + 2 ) % count( $nicknames ) ];
        $dash_time = number_format( ( ( $seed % 20 ) + 30 ) / 10, 1 );

        $hot_take = $hot_takes[ ( $seed + 3 ) % count( $hot_takes ) ];
        $grade    = $grades[ ( $seed + 4 ) % count( $grades ) ];
        $broll_clip = ( count( $broll ) > 0 ) ? $broll[ $seed % count( $broll ) ] : null;

        $script['picks'][] = [
            'pick_num'      => $pick_num,
            'user_id'       => $pick['user_id'],
            'display_name'  => $hf_name,
            'fish_id'       => $pick['fish_id'],
            'fish_name'     => $pick['fish_name'],
            'qty'           => $pick['qty'],
            'crowd_clip'    => $crowd_clip,
            'clip_start'    => $slice_start,
            'clip_duration' => $slice_duration,
            'scouting'      => [
                'position'  => $position,
                'college'   => $college,
                'nickname'  => $nickname,
                'dash_time' => $dash_time,
            ],
            'hot_take'   => $hot_take,
            'grade'      => $grade,
            'broll_clip' => $broll_clip,
        ];
    }

    update_option( 'fishotel_draft_broadcast_' . $slug, $script );

    return $script;
}
