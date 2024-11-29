<?php
/*
Plugin Name: Wordpress Reddit Cross-Poster
Plugin URI: https://github.com/vedaanty/reddit-crosspost-plugin/
Description: An plugin for cross-posting WordPress posts to Reddit with dynamic flair support.
Version: 2.0.0
Author: Vedaant
Author URI: https://github.com/vedaanty/
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

ob_start();

// Define constants
define('ARCP_REDIRECT_URI', admin_url('admin.php?page=arcp-settings'));

// Plugin activation and deactivation hooks
register_activation_hook(__FILE__, 'arcp_activate_plugin');
register_deactivation_hook(__FILE__, 'arcp_deactivate_plugin');

function arcp_activate_plugin() {
    if (!wp_next_scheduled('arcp_refresh_token_cron')) {
        wp_schedule_event(time(), 'hourly', 'arcp_refresh_token_cron');
    }
}

function arcp_deactivate_plugin() {
    wp_clear_scheduled_hook('arcp_refresh_token_cron');
}

// Cron job for refreshing the OAuth token
add_action('arcp_refresh_token_cron', 'arcp_refresh_access_token');

// Enqueue admin scripts and styles
add_action('admin_enqueue_scripts', 'arcp_enqueue_admin_scripts');
function arcp_enqueue_admin_scripts($hook_suffix) {
    if ($hook_suffix === 'post.php' || $hook_suffix === 'post-new.php' || $hook_suffix === 'settings_page_arcp-settings') {
        wp_enqueue_script('arcp-admin-script', plugins_url('assets/js/admin.js', __FILE__), ['jquery'], '2.0.0', true);
        wp_enqueue_style('arcp-admin-style', plugins_url('assets/css/admin.css', __FILE__), [], '2.0.0');
        wp_localize_script('arcp-admin-script', 'arcpAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('arcp_ajax_nonce'),
        ]);
    }
}

// Add settings page to the admin menu
add_action('admin_menu', 'arcp_add_settings_page');
function arcp_add_settings_page() {
    add_menu_page(
        'ARCP Settings',
        'ARCP Settings',
        'manage_options',
        'arcp-settings',
        'arcp_render_settings_page',
        'dashicons-share',
        80
    );
}

// Render settings page
function arcp_render_settings_page() {
    if (isset($_GET['arcp_auth']) && $_GET['arcp_auth'] === '1') {
        arcp_start_oauth();
    }

    if (isset($_GET['code'])) {
        arcp_handle_oauth_callback($_GET['code']);
    }

    ?>
    <div class="wrap">
        <h1>Advanced Reddit Cross-Poster Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('arcp_settings_group');
            do_settings_sections('arcp-settings');
            submit_button();
            ?>
        </form>
        <h2>OAuth Authentication</h2>
        <p>Authenticate with Reddit to enable posting.</p>
        <a href="<?php echo esc_url(add_query_arg('arcp_auth', '1')); ?>" class="button button-primary">Authenticate with Reddit</a>
    </div>
    <?php
}

// Register plugin settings
add_action('admin_init', 'arcp_register_settings');
function arcp_register_settings() {
    add_settings_section('arcp_main_settings', 'API Credentials', null, 'arcp-settings');

    add_settings_field('arcp_client_id', 'Reddit Client ID', 'arcp_render_client_id_field', 'arcp-settings', 'arcp_main_settings');
    add_settings_field('arcp_client_secret', 'Reddit Client Secret', 'arcp_render_client_secret_field', 'arcp-settings', 'arcp_main_settings');

    register_setting('arcp_settings_group', 'arcp_client_id');
    register_setting('arcp_settings_group', 'arcp_client_secret');
}

function arcp_render_client_id_field() {
    $client_id = get_option('arcp_client_id');
    echo '<input type="text" name="arcp_client_id" value="' . esc_attr($client_id) . '" style="width: 100%;">';
	echo '<p class="description">Your Reddit API Client ID. Get this from the <a href="https://www.reddit.com/prefs/apps" target="_blank">Reddit Developer Portal</a>.</p>';
}

function arcp_render_client_secret_field() {
    $client_secret = get_option('arcp_client_secret');
    echo '<input type="text" name="arcp_client_secret" value="' . esc_attr($client_secret) . '" style="width: 100%;">';
	echo '<p class="description">Your Reddit API Client Secret. Get this from the <a href="https://www.reddit.com/prefs/apps" target="_blank">Reddit Developer Portal</a>.</p>';
	echo '<p>Ensure that the redirect URI in the <a href="https://www.reddit.com/prefs/apps" target="_blank">Reddit Developer Portal</a> is set to:</p>';
    echo esc_html(ARCP_REDIRECT_URI);
}

// Start OAuth process
function arcp_start_oauth() {
    $client_id = get_option('arcp_client_id');
    $state = wp_generate_uuid4();

    $auth_url = "https://www.reddit.com/api/v1/authorize?client_id={$client_id}&response_type=code&state={$state}&redirect_uri=" . urlencode(ARCP_REDIRECT_URI) . "&duration=permanent&scope=submit,flair";

    wp_redirect($auth_url);
    exit;
}

// Handle OAuth callback
function arcp_handle_oauth_callback($code) {
    $client_id = get_option('arcp_client_id');
    $client_secret = get_option('arcp_client_secret');

    $response = wp_remote_post('https://www.reddit.com/api/v1/access_token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode("{$client_id}:{$client_secret}"),
            'User-Agent'    => 'AdvancedRedditCrossPoster/2.0'
        ],
        'body' => [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => ARCP_REDIRECT_URI,
        ]
    ]);

    if (is_wp_error($response)) {
        error_log("ARCP: OAuth Error - " . $response->get_error_message());
        wp_die('OAuth failed. Check error logs for details.');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['access_token'])) {
        update_option('arcp_access_token', $body['access_token']);
        update_option('arcp_refresh_token', $body['refresh_token']);
        error_log("ARCP: OAuth Success - Access token saved.");
        wp_redirect(admin_url('admin.php?page=arcp-settings'));
        exit;
    } else {
        error_log("ARCP: OAuth Error - " . print_r($body, true));
        wp_die('OAuth failed. Check error logs for details.');
    }
}

// Refresh access token
function arcp_refresh_access_token() {
    $client_id = get_option('arcp_client_id');
    $client_secret = get_option('arcp_client_secret');
    $refresh_token = get_option('arcp_refresh_token');

    if (!$refresh_token) {
        error_log("ARCP: No refresh token available.");
        return false;
    }

    $response = wp_remote_post('https://www.reddit.com/api/v1/access_token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode("{$client_id}:{$client_secret}"),
            'User-Agent'    => 'AdvancedRedditCrossPoster/2.0'
        ],
        'body' => [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ]
    ]);

    if (is_wp_error($response)) {
        error_log("ARCP: Token Refresh Error - " . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['access_token'])) {
        update_option('arcp_access_token', $body['access_token']);
        error_log("ARCP: Access token refreshed successfully.");
        return $body['access_token'];
    } else {
        error_log("ARCP: Token Refresh Failed - " . print_r($body, true));
        return false;
    }
}
// Add meta box for posts
add_action('add_meta_boxes', 'arcp_add_meta_box');
function arcp_add_meta_box() {
    add_meta_box(
        'arcp_meta_box',
        'Reddit Cross-Poster',
        'arcp_render_meta_box',
        'post',
        'side'
    );
}

// Render meta box
function arcp_render_meta_box($post) {
    $subreddit_data = get_post_meta($post->ID, '_arcp_subreddit_data', true) ?: [];
    ?>
    <div id="arcp-meta-box">
        <h4>Manual Submission</h4>
        <div id="arcp-subreddit-container">
            <?php foreach ($subreddit_data as $entry): ?>
                <div class="arcp-subreddit-row">
                    <input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" value="<?php echo esc_attr($entry['subreddit']); ?>" style="width: 45%; margin-right: 5%;">
                    <select name="arcp_flairs[]" class="arcp-flair" style="width: 45%;">
                        <option value="<?php echo esc_attr($entry['flair_id']); ?>"><?php echo esc_html($entry['flair_text'] ?? 'No Flair'); ?></option>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="arcp-add-subreddit" class="button">+ Add Subreddit</button>
        <p style="margin-top: 10px;">
            <button type="button" id="arcp-manual-submit" class="button button-primary">Submit to Reddit</button>
        </p>
    </div>
    <script>
        jQuery(document).ready(function ($) {
            // Add new subreddit row
            $('#arcp-add-subreddit').on('click', function () {
                $('#arcp-subreddit-container').append(`
                    <div class="arcp-subreddit-row">
                        <input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" style="width: 45%; margin-right: 5%;">
                        <select name="arcp_flairs[]" class="arcp-flair" style="width: 45%;">
                            <option value="">Loading...</option>
                        </select>
                    </div>
                `);
            });

            // Fetch flairs dynamically
            $('#arcp-subreddit-container').on('change', '.arcp-subreddit', function () {
                const subreddit = $(this).val();
                const flairDropdown = $(this).siblings('.arcp-flair');

                if (!subreddit) {
                    flairDropdown.html('<option value="">No flair available</option>');
                    return;
                }

                flairDropdown.html('<option>Loading...</option>');

                $.post(arcpAjax.ajaxurl, {
                    action: 'arcp_fetch_flairs',
                    subreddit: subreddit,
                    _ajax_nonce: arcpAjax.nonce
                }, function (response) {
                    if (response.success) {
                        flairDropdown.html('<option value="">No flair</option>');
                        response.data.forEach(flair => {
                            flairDropdown.append(`<option value="${flair.id}">${flair.text || 'No Flair'}</option>`);
                        });
                    } else {
                        flairDropdown.html('<option value="">No flair available</option>');
                    }
                }).fail(function () {
                    flairDropdown.html('<option value="">Failed to load</option>');
                });
            });

            // Manual submit button
            $('#arcp-manual-submit').on('click', function () {
                const postId = <?php echo $post->ID; ?>;
                const subredditData = [];

                $('.arcp-subreddit-row').each(function () {
                    const subreddit = $(this).find('.arcp-subreddit').val();
                    const flairId = $(this).find('.arcp-flair').val();

                    if (subreddit) {
                        subredditData.push({
                            subreddit: subreddit,
                            flair_id: flairId || null
                        });
                    }
                });

                if (subredditData.length === 0) {
                    alert('Please add at least one subreddit.');
                    return;
                }

                $.post(arcpAjax.ajaxurl, {
                    action: 'arcp_manual_submit',
                    post_id: postId,
                    subreddit_data: JSON.stringify(subredditData),
                    _ajax_nonce: arcpAjax.nonce
                }, function (response) {
                    if (response.success) {
                        alert('Post successfully submitted:\n' + response.data);
                    } else {
                        alert('Failed to submit:\n' + response.data);
                    }
                }).fail(function () {
                    alert('Failed to communicate with the server.');
                });
            });
        });
    </script>
    <?php
}
// AJAX handler to fetch flairs for a subreddit
add_action('wp_ajax_arcp_fetch_flairs', 'arcp_fetch_flairs_handler');
function arcp_fetch_flairs_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');

    $subreddit = sanitize_text_field($_POST['subreddit']);
    if (empty($subreddit)) {
        wp_send_json_error('Subreddit is required.');
    }

    $token = get_option('arcp_access_token');
    if (!$token) {
        wp_send_json_error('No OAuth token available.');
    }

    // API request to fetch subreddit flairs
    $response = wp_remote_get("https://oauth.reddit.com/r/$subreddit/api/link_flair_v2", [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'User-Agent'    => 'AdvancedRedditCrossPoster/2.0',
        ],
    ]);

    if (is_wp_error($response)) {
        error_log("ARCP: Failed to fetch flairs for r/$subreddit - " . $response->get_error_message());
        wp_send_json_error('Failed to fetch flairs.');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Handle API errors
    if (!is_array($body)) {
        wp_send_json_error('Invalid response from Reddit.');
    }

    // Format flair options
    $flairs = array_map(function ($flair) {
        return [
            'id'   => $flair['id'] ?? '',
            'text' => $flair['text'] ?? 'No Flair',
        ];
    }, $body);

    wp_send_json_success($flairs);
}
// AJAX handler for manual submission
add_action('wp_ajax_arcp_manual_submit', 'arcp_manual_submit_handler');
function arcp_manual_submit_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');

    $post_id = intval($_POST['post_id']);
    $subreddit_data_raw = $_POST['subreddit_data'] ?? '';

    // Debug raw input
    error_log("ARCP: Raw subreddit data received: $subreddit_data_raw");

    // Decode subreddit data
    $subreddit_data = json_decode(stripslashes($subreddit_data_raw), true);

    // Debug parsed input
    error_log("ARCP: Parsed subreddit data: " . print_r($subreddit_data, true));

    if (!$post_id || empty($subreddit_data)) {
        wp_send_json_error('Invalid post ID or subreddit data.');
    }

    $token = get_option('arcp_access_token');
    if (!$token) {
        wp_send_json_error('No OAuth token available.');
    }

    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
    $post_url = get_permalink($post_id);
    $title = get_the_title($post_id);

    $results = [];
    foreach ($subreddit_data as $entry) {
        $subreddit = sanitize_text_field($entry['subreddit']);
        $flair_id = sanitize_text_field($entry['flair_id'] ?? '');

        if (empty($subreddit)) {
            $results[] = "Subreddit is missing.";
            continue;
        }

        $response = arcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit, $flair_id);

        if ($response['success']) {
            $results[] = "Successfully posted to r/$subreddit: " . $response['url'];
        } else {
            $results[] = "Failed to post to r/$subreddit: " . $response['error'];
        }
    }

    wp_send_json_success(implode("\n", $results));
}
// Submit post to Reddit
function arcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit, $flair_id = null) {
    $post_data = [
        'title' => html_entity_decode($title, ENT_QUOTES),
        'kind'  => 'link',
        'url'   => $image_url,
        'sr'    => $subreddit,
        'text'  => $excerpt . "\n\n[Read more]($post_url)",
    ];

    if ($flair_id) {
        $post_data['flair_id'] = $flair_id;
    }

    // Make the API request
    $response = wp_remote_post('https://oauth.reddit.com/api/submit', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'User-Agent'    => 'AdvancedRedditCrossPoster/2.0',
        ],
        'body' => $post_data,
    ]);

    if (is_wp_error($response)) {
        error_log("ARCP: Post submission error for r/$subreddit - " . $response->get_error_message());
        return [
            'success' => false,
            'error'   => $response->get_error_message(),
        ];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Handle API response
    if (isset($body['json']['data']['url'])) {
        return [
            'success' => true,
            'url'     => $body['json']['data']['url'],
        ];
    }

    // Log unexpected responses
    error_log("ARCP: Unexpected response from Reddit for r/$subreddit - " . print_r($body, true));
    return [
        'success' => false,
        'error'   => 'Unexpected response from Reddit.',
    ];
}
// Hook into post publishing
add_action('publish_post', 'arcp_auto_cross_post');
function arcp_auto_cross_post($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    $enabled = get_post_meta($post_id, '_arcp_auto_post_enabled', true);
    if (!$enabled) {
        return;
    }

    $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
    $mapping = get_option('arcp_category_mapping', []);
    $subreddits = [];

    foreach ($categories as $category) {
        if (isset($mapping[$category])) {
            $subreddits = array_merge($subreddits, $mapping[$category]);
        }
    }

    if (empty($subreddits)) {
        return;
    }

    $token = get_option('arcp_access_token');
    if (!$token) {
        return;
    }

    $title = get_the_title($post_id);
    $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
    $post_url = get_permalink($post_id);
    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';

    foreach ($subreddits as $subreddit) {
        arcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit);
    }
}
