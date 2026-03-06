<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub Auto-Updater for FisHotel Batch Manager.
 * Checks the raw plugin file on GitHub for a newer version and wires it into
 * the standard WordPress update flow — no external updater plugin required.
 */
class FisHotel_GitHub_Updater {

    private $plugin_slug;
    private $plugin_file;
    private $github_raw_url = 'https://raw.githubusercontent.com/Dierks27/Fishotel-Batch-Plugin/main/fishotel-batch-manager.php';
    private $github_zip_url = 'https://github.com/Dierks27/Fishotel-Batch-Plugin/archive/refs/heads/main.zip';
    private $github_repo    = 'Dierks27/Fishotel-Batch-Plugin';
    private $transient_key  = 'fishotel_github_updater_version';
    private $cache_hours    = 12;

    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection',             [ $this, 'fix_folder_name' ], 10, 4 );
    }

    /**
     * Fetch the version string from the raw GitHub file.
     * Results are cached for $cache_hours to avoid hammering GitHub.
     */
    private function get_remote_version() {
        $cached = get_transient( $this->transient_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $response = wp_remote_get( $this->github_raw_url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );

        // Parse "Version: X.X.X" from the plugin header block.
        if ( preg_match( '/^\s*\*\s*Version:\s*([\d.]+)/m', $body, $matches ) ) {
            $version = trim( $matches[1] );
            set_transient( $this->transient_key, $version, $this->cache_hours * HOUR_IN_SECONDS );
            return $version;
        }

        return false;
    }

    /**
     * Inject update data into the update_plugins transient when GitHub is ahead.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $installed_version = $transient->checked[ $this->plugin_slug ] ?? null;
        if ( ! $installed_version ) {
            return $transient;
        }

        $remote_version = $this->get_remote_version();
        if ( ! $remote_version ) {
            return $transient;
        }

        if ( version_compare( $remote_version, $installed_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'slug'        => 'fishotel-batch-manager',
                'plugin'      => $this->plugin_slug,
                'new_version' => $remote_version,
                'package'     => $this->github_zip_url,
                'url'         => 'https://github.com/' . $this->github_repo,
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View version X.X.X details" popup.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== 'fishotel-batch-manager' ) {
            return $result;
        }

        $remote_version = $this->get_remote_version();

        return (object) [
            'name'          => 'FisHotel Batch Manager',
            'slug'          => 'fishotel-batch-manager',
            'version'       => $remote_version ?: 'unknown',
            'author'        => 'Dierks & Claude',
            'homepage'      => 'https://github.com/' . $this->github_repo,
            'download_link' => $this->github_zip_url,
            'sections'      => [
                'description' => 'Self-hosted plugin. Updates delivered via GitHub.',
            ],
        ];
    }

    /**
     * GitHub zips extract to "Fishotel-Batch-Plugin-main/" but WordPress expects
     * "fishotel-batch-manager/". Rename the folder after extraction.
     */
    public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $source;
        }

        $corrected = trailingslashit( dirname( $source ) ) . 'fishotel-batch-manager/';

        if ( $source !== $corrected ) {
            global $wp_filesystem;
            $wp_filesystem->move( $source, $corrected );
            return $corrected;
        }

        return $source;
    }
}
