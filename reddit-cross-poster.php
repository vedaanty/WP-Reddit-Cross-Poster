<?php
/*
Plugin Name: Wordpress Reddit Cross-Poster
Plugin URI: https://github.com/vedaanty/reddit-crosspost-plugin/
Description: An plugin for cross-posting WordPress posts to Reddit with dynamic flair support.
Version: 2.1.0
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
        <div class="arcp-settings">
            <form method="post" action="options.php">
                <?php
                // Register settings fields
                settings_fields('arcp_settings_group');
                ?>
                <!-- API Authentication Section -->
                <div class="field-group">
                    <h2>API Authentication</h2>
                    <p>Connect to Reddit using your API credentials. Ensure the redirect URI is correctly set in the <a href="https://www.reddit.com/prefs/apps" target="_blank">Reddit Developer Portal</a>.</p>
                    <?php do_settings_fields('arcp-settings', 'arcp_main_settings'); ?>
                    <h2>OAuth Authentication</h2>
                    <p>Authenticate with Reddit to enable posting.</p>
                    <a href="<?php echo esc_url(add_query_arg('arcp_auth', '1')); ?>" class="button button-primary">Authenticate with Reddit</a>
					<p style="margin-top: 15px; font-size: 14px;">
            			<strong>Status:</strong> 
            			<?php if (get_option('arcp_access_token')) : ?>
                			<span style="color: green;">Logged in</span>
            			<?php else : ?>
                			<span style="color: red;">Not logged in</span>
            			<?php endif; ?>
        			</p>
                </div>
                <!-- Post Settings Section -->
                <div class="field-group">
                    <h2>Post Settings</h2>
                    <p>Customize how your posts appear on Reddit.</p>
                    <?php do_settings_fields('arcp-settings', 'arcp_post_settings'); ?>
                </div>

                <!-- Save Button -->
                <div class="submit-section">
                    <?php submit_button('Save Settings', 'primary', 'submit', false, ['class' => 'submit-button']); ?>
                </div>
            </form>
        </div>
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

// Register new settings fields
add_action('admin_init', 'arcp_register_additional_settings');
function arcp_register_additional_settings() {
    add_settings_section('arcp_post_settings', 'Post Settings', null, 'arcp-settings');

    add_settings_field('arcp_post_type', 'Post Type', 'arcp_render_post_type_field', 'arcp-settings', 'arcp_post_settings');
    add_settings_field('arcp_custom_text', 'Custom Text', 'arcp_render_custom_text_field', 'arcp-settings', 'arcp_post_settings');
    add_settings_field('arcp_disable_excerpt', 'Disable Excerpt', 'arcp_render_disable_excerpt_field', 'arcp-settings', 'arcp_post_settings');
    add_settings_field('arcp_link_text', 'Link Text', 'arcp_render_link_text_field', 'arcp-settings', 'arcp_post_settings');

    register_setting('arcp_settings_group', 'arcp_post_type');
    register_setting('arcp_settings_group', 'arcp_custom_text', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('arcp_settings_group', 'arcp_disable_excerpt', ['sanitize_callback' => 'rest_sanitize_boolean']);
    register_setting('arcp_settings_group', 'arcp_link_text', ['sanitize_callback' => 'sanitize_text_field']);
}

// Render Post Type Field
function arcp_render_post_type_field() {
    $post_type = get_option('arcp_post_type', 'image');
    ?>
    <select name="arcp_post_type">
        <option value="image" <?php selected($post_type, 'image'); ?>>Featured Image</option>
        <option value="link" <?php selected($post_type, 'link'); ?>>Article Link</option>
    </select>
    <p class="description">Choose whether to post the featured image or the article link. Defaults to featured image.</p>
    <?php
}

// Render Custom Text Field
function arcp_render_custom_text_field() {
    $custom_text = get_option('arcp_custom_text', '');
    ?>
    <input type="text" name="arcp_custom_text" value="<?php echo esc_attr($custom_text); ?>" placeholder="Enter custom text">
    <p class="description">Replace the excerpt with custom text (if provided).</p>
    <?php
}

// Render Disable Excerpt Field
function arcp_render_disable_excerpt_field() {
    $disable_excerpt = get_option('arcp_disable_excerpt', false);
    ?>
    <input type="checkbox" name="arcp_disable_excerpt" value="1" <?php checked($disable_excerpt, true); ?>>
    <p class="description">Disable excerpt entirely. Only the custom text or link will be posted.</p>
    <?php
}

// Render Link Text Field
function arcp_render_link_text_field() {
    $link_text = get_option('arcp_link_text', 'Read more');
    ?>
    <input type="text" name="arcp_link_text" value="<?php echo esc_attr($link_text); ?>">
    <p class="description">Specify the link text to include with the post. Leave empty to omit the link.</p>
    <?php
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
    // Retrieve saved subreddit data
    $subreddit_data = get_post_meta($post->ID, '_arcp_subreddit_data', true) ?: [];
    ?>
    <div id="arcp-meta-box">
        <h4>Manual Submission</h4>
        <div id="arcp-subreddit-container">
            <?php if (!empty($subreddit_data)) : ?>
                <?php foreach ($subreddit_data as $entry): ?>
                    <div class="arcp-subreddit-row">
                        <input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" value="<?php echo esc_attr($entry['subreddit']); ?>" style="width: 40%; margin-right: 5%;">
                        <select name="arcp_flairs[]" class="arcp-flair" style="width: 40%; margin-right: 5%;">
                            <option value="<?php echo esc_attr($entry['flair_id']); ?>"><?php echo esc_html($entry['flair_text'] ?? 'No Flair'); ?></option>
                        </select>
                        <button type="button" class="arcp-remove-subreddit button button-secondary">Remove</button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="arcp-subreddit-row">
                    <input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" style="width: 40%; margin-right: 5%;">
                    <select name="arcp_flairs[]" class="arcp-flair" style="width: 40%; margin-right: 5%;">
                        <option value="">No Flair</option>
                    </select>
                    <button type="button" class="arcp-remove-subreddit button button-secondary">Remove</button>
                </div>
            <?php endif; ?>
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
                        <input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" style="width: 40%; margin-right: 5%;">
                        <select name="arcp_flairs[]" class="arcp-flair" style="width: 40%; margin-right: 5%;">
                            <option value="">No Flair</option>
                        </select>
                        <button type="button" class="arcp-remove-subreddit button button-secondary">Remove</button>
                    </div>
                `);
            });

            // Remove subreddit row
            $('#arcp-subreddit-container').on('click', '.arcp-remove-subreddit', function () {
                $(this).closest('.arcp-subreddit-row').remove();
            });

            // Fetch flairs dynamically
            $('#arcp-subreddit-container').on('change', '.arcp-subreddit', function () {
                const subreddit = $(this).val();
                const flairDropdown = $(this).siblings('.arcp-flair');

                if (!subreddit) {
                    flairDropdown.html('<option value="">No Flair</option>');
                    return;
                }

                flairDropdown.html('<option>Loading...</option>');

                $.post(arcpAjax.ajaxurl, {
                    action: 'arcp_fetch_flairs',
                    subreddit: subreddit,
                    _ajax_nonce: arcpAjax.nonce
                }, function (response) {
                    if (response.success) {
                        flairDropdown.html('<option value="">No Flair</option>');
                        response.data.forEach(flair => {
                            flairDropdown.append(`<option value="${flair.id}">${flair.text || 'No Flair'}</option>`);
                        });
                    } else {
                        flairDropdown.html('<option value="">No Flair Available</option>');
                    }
                }).fail(function () {
                    flairDropdown.html('<option value="">Failed to load flairs</option>');
                });
            });

            // Submit to Reddit
            $('#arcp-manual-submit').on('click', function () {
                const postId = <?php echo $post->ID; ?>;
                const subredditData = [];

                $('.arcp-subreddit-row').each(function () {
                    const subreddit = $(this).find('.arcp-subreddit').val();
                    const flairId = $(this).find('.arcp-flair').val();
                    const flairText = $(this).find('.arcp-flair option:selected').text();

                    if (subreddit) {
                        subredditData.push({
                            subreddit: subreddit,
                            flair_id: flairId || null,
                            flair_text: flairText || 'No Flair',
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

                        // Save subreddit data for persistence
                        $.post(arcpAjax.ajaxurl, {
                            action: 'arcp_save_subreddit_data',
                            post_id: postId,
                            subreddit_data: JSON.stringify(subredditData),
                            _ajax_nonce: arcpAjax.nonce
                        });
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

add_action('wp_ajax_arcp_save_subreddit_data', 'arcp_save_subreddit_data_handler');
function arcp_save_subreddit_data_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');

    $post_id = intval($_POST['post_id']);
    $subreddit_data_raw = $_POST['subreddit_data'] ?? '';

    if (!$post_id || empty($subreddit_data_raw)) {
        wp_send_json_error('Invalid post ID or subreddit data.');
    }

    $subreddit_data = json_decode(stripslashes($subreddit_data_raw), true);
    update_post_meta($post_id, '_arcp_subreddit_data', $subreddit_data);

    wp_send_json_success('Subreddit data saved.');
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

    // Decode subreddit data
    $subreddit_data = json_decode(stripslashes($subreddit_data_raw), true);

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
            $results[] = "Failed to post to r/$subreddit: Subreddit is missing.";
            continue;
        }

        $response = arcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit, $flair_id);

        if ($response['success']) {
            $results[] = "Successfully posted to r/$subreddit: " . $response['url'];
        } else {
            $results[] = "Failed to post to r/$subreddit: " . $response['error'];
        }
    }

    // Combine results into a plain text message
    $message = "Post Results:\n\n";
    foreach ($results as $result) {
        $message .= $result . "\n";
    }

    wp_send_json_success($message);
}

// Submit post to Reddit
function arcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit, $flair_id = null) {
    // Retrieve settings
    $post_type = get_option('arcp_post_type', 'image');
    $custom_text = get_option('arcp_custom_text', '');
    $disable_excerpt = get_option('arcp_disable_excerpt', false);
    $link_text = get_option('arcp_link_text', 'Read more');

    // Generate the post excerpt
    if ($disable_excerpt) {
        $post_excerpt = '';
    } elseif (!empty($custom_text)) {
        $post_excerpt = $custom_text;
    } else {
        $post_excerpt = $excerpt; // Use full excerpt without trimming
    }

    // Append the article link if the link text is enabled
    if (!empty($link_text) && !$disable_excerpt) {
        $post_excerpt .= " [$link_text]($post_url)";
    }

    // Determine whether to post as an image or a link
    $is_image_post = ($post_type === 'image' && !empty($image_url));
    $post_data = [
        'title' => html_entity_decode($title, ENT_QUOTES),
        'kind'  => $is_image_post ? 'link' : 'link',
        'url'   => $is_image_post ? $image_url : $post_url,
        'sr'    => $subreddit,
        'text'  => $post_excerpt,
    ];

    // Add flair if specified
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

    // Handle API response
    if (is_wp_error($response)) {
        error_log("ARCP: Post submission error - " . $response->get_error_message());
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Check for successful submission
    if (isset($body['success']) && $body['success'] == 1) {
        $reddit_post_url = $body['jquery'][10][3][0] ?? null;
        return ['success' => true, 'url' => $reddit_post_url ?: "https://www.reddit.com/r/$subreddit/"];
    }

    // Log and handle unexpected responses
    error_log("ARCP: Unexpected response from Reddit - " . print_r($body, true));
    return ['success' => false, 'error' => 'Unexpected response from Reddit.'];
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
