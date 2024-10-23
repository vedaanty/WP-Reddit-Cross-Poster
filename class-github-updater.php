if ( ! class_exists( 'GitHub_Updater' ) ) {
    class GitHub_Updater {

        private $slug;
        private $repo;
        private $version;
        private $transient_name;

        public function __construct( $slug, $repo ) {
            $this->slug = $slug;
            $this->repo = $repo;
            $this->transient_name = 'github_update_' . $slug;

            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
            add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
            add_action( 'upgrader_process_complete', array( $this, 'delete_transient' ), 10, 2 );
        }

        public function check_for_update( $transient ) {
            if ( empty( $transient->checked ) ) {
                return $transient;
            }

            // Fetch the latest release from GitHub
            $response = wp_remote_get( "https://api.github.com/repos/{$this->repo}/releases/latest" );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                return $transient;
            }

            $data = json_decode( wp_remote_retrieve_body( $response ) );

            // If there is a new version, set it in the transient
            if ( version_compare( $this->version, $data->tag_name, '<' ) ) {
                $transient->response[ $this->slug ] = array(
                    'slug'        => $this->slug,
                    'new_version' => $data->tag_name,
                    'url'        => $data->html_url,
                    'package'    => $data->zipball_url,
                );
            }

            return $transient;
        }

        public function plugin_info( $false, $action, $response ) {
            if ( $action === 'plugin_information' && $response->slug === $this->slug ) {
                $response->version = $this->version;
                $response->download_link = "https://github.com/{$this->repo}/archive/refs/heads/main.zip"; // Adjust the branch if necessary
                // Add any additional information if needed
            }

            return $response;
        }

        public function delete_transient( $upgrader_object, $hook_extra ) {
            if ( isset( $hook_extra['type'] ) && $hook_extra['type'] === 'plugin' ) {
                delete_transient( $this->transient_name );
            }
        }
    }
}
