<?php

if ( ! class_exists( 'GitHub_Updater' ) ) {

    class GitHub_Updater {

        private $slug;
        private $repo;
        private $version;
        private $transient_name;

        public function __construct( $slug, $repo, $version ) {
            $this->slug = $slug;
            $this->repo = $repo;
            $this->version = $version;
            $this->transient_name = 'github_update_' . $slug;

            // Hooks to trigger the update check
            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
            add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
            add_action( 'upgrader_process_complete', array( $this, 'delete_transient' ), 10, 2 );

            // Log that the constructor is working
            error_log( 'GitHub_Updater class constructed for plugin: ' . $slug );
        }

        public function check_for_update( $transient ) {
            error_log( 'Checking for updates...' );
            
            if ( empty( $transient->checked ) ) {
                error_log( 'No checked transient found.' );
                return $transient;
            }

            // Fetch the latest release from GitHub
            $response = wp_remote_get( "https://api.github.com/repos/{$this->repo}/releases/latest" );
            
            if ( is_wp_error( $response ) ) {
                error_log( 'Error fetching release: ' . $response->get_error_message() );
                return $transient;
            }

            if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
                error_log( 'Unexpected response code: ' . wp_remote_retrieve_response_code( $response ) );
                return $transient;
            }

            $data = json_decode( wp_remote_retrieve_body( $response ) );
            if ( ! isset( $data->tag_name ) ) {
                error_log( 'No tag_name in the release data' );
                return $transient;
            }

            // Compare versions
            error_log( 'Current version: ' . $this->version . ', Latest version: ' . $data->tag_name );
            if ( version_compare( $this->version, $data->tag_name, '<' ) ) {
                error_log( 'New version found: ' . $data->tag_name );
                
                $plugin_data = array(
                    'slug'        => $this->slug,
                    'new_version' => $data->tag_name,
                    'url'         => $data->html_url,
                    'package'     => $data->zipball_url,
                );
                
                $transient->response[ $this->slug ] = (object) $plugin_data;
            } else {
                error_log( 'No new version available.' );
            }

            return $transient;
        }

        public function plugin_info( $false, $action, $response ) {
            if ( $action === 'plugin_information' && isset( $response->slug ) && $response->slug === $this->slug ) {
                error_log( 'Fetching plugin information for: ' . $this->slug );

                // Fetch plugin information
                $response->version = $this->version;
                $response->download_link = "https://github.com/{$this->repo}/archive/refs/heads/main.zip"; // Adjust the branch if needed

                return $response;
            }

            return $false;
        }

        public function delete_transient( $upgrader_object, $options ) {
            if ( isset( $options['type'] ) && $options['type'] === 'plugin' ) {
                error_log( 'Deleting transient: ' . $this->transient_name );
                delete_transient( $this->transient_name );
            }
        }
    }
}
