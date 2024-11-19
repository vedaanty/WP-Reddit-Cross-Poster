<?php
/*
Plugin Name: Reddit Cross-Post Plugin
Plugin URI: https://github.com/vedaanty/reddit-crosspost-plugin/
Description: Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.
Version: 1.1.3
Author: Vedaant
Author URI: https://github.com/vedaanty/
*/

// Suppress output to prevent unintended whitespace
ob_start();

// Define the redirect URI dynamically
define('REDDIT_REDIRECT_URI', admin_url('admin.php?page=reddit-cross-poster'));

// Add the admin menu for plugin settings
add_action('admin_menu', 'reddit_cross_poster_menu');
function reddit_cross_poster_menu() {
    add_menu_page('Reddit Cross Poster', 'Reddit Cross Poster', 'manage_options', 'reddit-cross-poster', 'reddit_cross_poster_admin_page');
}

// Display the admin settings page
function reddit_cross_poster_admin_page() {
    if (isset($_GET['reddit_oauth'])) {
        reddit_cross_poster_start_oauth();
    }

    if (isset($_GET['code']) && isset($_GET['state'])) {
        reddit_cross_poster_handle_oauth_response($_GET['code']);
    }
    ?>
    <div class="wrap">
        <h1>Reddit Cross Poster Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('reddit_cross_poster_options');
            do_settings_sections('reddit-cross-poster');
            submit_button();
            ?>
        </form>
        <h2>OAuth Authentication</h2>
        <a href="<?php echo esc_url(add_query_arg('reddit_oauth', '1')); ?>" class="button button-primary">Authenticate with Reddit</a>
        <p><?php echo esc_html(get_option('reddit_access_token') ? 'Logged in' : 'Not logged in'); ?></p>
    </div>
    <?php
}

// Start Reddit OAuth process
function reddit_cross_poster_start_oauth() {
    $client_id = get_option('reddit_client_id');
    $state = wp_generate_uuid4();

    $oauth_url = "https://www.reddit.com/api/v1/authorize?client_id={$client_id}&response_type=code&state={$state}&redirect_uri=" . urlencode(REDDIT_REDIRECT_URI) . "&duration=permanent&scope=submit";

    wp_redirect($oauth_url);
    exit;
}

// Handle Reddit OAuth response
function reddit_cross_poster_handle_oauth_response($code) {
    $client_id = get_option('reddit_client_id');
    $client_secret = get_option('reddit_client_secret');

    $response = wp_remote_post('https://www.reddit.com/api/v1/access_token', array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'User-Agent' => 'YourAppName/0.1 by YourUsername'
        ),
        'body' => array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => REDDIT_REDIRECT_URI
        )
    ));

    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['access_token'])) {
            update_option('reddit_access_token', $body['access_token']);
            update_option('reddit_refresh_token', $body['refresh_token']);
        }
    }
}

// Register plugin settings
add_action('admin_init', 'reddit_cross_poster_settings');
function reddit_cross_poster_settings() {
    register_setting('reddit_cross_poster_options', 'reddit_client_id');
    register_setting('reddit_cross_poster_options', 'reddit_client_secret');
    register_setting('reddit_cross_poster_options', 'reddit_category_subreddit_map');

    add_settings_section('reddit_cross_poster_main', 'Reddit API Settings', null, 'reddit-cross-poster');
    add_settings_field('reddit_client_id', 'Client ID', 'reddit_client_id_callback', 'reddit-cross-poster', 'reddit_cross_poster_main');
    add_settings_field('reddit_client_secret', 'Client Secret', 'reddit_client_secret_callback', 'reddit-cross-poster', 'reddit_cross_poster_main');
    add_settings_field('reddit_category_subreddit_map', 'Category to Subreddit Mapping', 'reddit_category_subreddit_map_callback', 'reddit-cross-poster', 'reddit_cross_poster_main');
}

// Ensure data deletion on plugin removal:
// Register the uninstall hook
register_uninstall_hook(__FILE__, 'reddit_cross_poster_uninstall');

// Uninstall function to clean up plugin data
function reddit_cross_poster_uninstall() {
    // Delete plugin options
    delete_option('reddit_client_id');
    delete_option('reddit_client_secret');
    delete_option('reddit_access_token');
    delete_option('reddit_refresh_token');
    delete_option('reddit_category_subreddit_map');

    // Delete all user meta related to the plugin
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
            '_reddit_cross_post_enabled',
            '_reddit_cross_post_manual_subreddit'
        )
    );

    // Log uninstall event (useful for debugging)
    error_log('Reddit Cross Poster plugin uninstalled and data cleaned up.');
}

function reddit_client_id_callback() {
    echo '<input type="text" name="reddit_client_id" value="' . esc_attr(get_option('reddit_client_id')) . '" />';
}

function reddit_client_secret_callback() {
    echo '<input type="text" name="reddit_client_secret" value="' . esc_attr(get_option('reddit_client_secret')) . '" />';
}

function reddit_category_subreddit_map_callback() {
    echo '<textarea name="reddit_category_subreddit_map" rows="5" cols="50">' . esc_textarea(get_option('reddit_category_subreddit_map')) . '</textarea>';
    echo '<p>Enter in the format: category:subreddit1,subreddit2</p>';
}

// Add meta box for post editor
add_action('add_meta_boxes', 'reddit_cross_poster_meta_box');
function reddit_cross_poster_meta_box() {
    add_meta_box(
        'reddit_cross_poster_meta', // Meta box ID
        'Reddit Cross Poster', // Title of the meta box
        'reddit_cross_poster_meta_callback', // Callback function to render the meta box
        'post', // Post type
        'side', // Location of the meta box
        'default' // Priority
    );
}


function reddit_cross_poster_meta_callback($post) {
    // Retrieve existing meta data
    $enabled = get_post_meta($post->ID, '_reddit_cross_post_enabled', true);
    $manual_subreddit = get_post_meta($post->ID, '_reddit_cross_post_manual_subreddit', true);
    ?>
    <div style="margin-bottom: 10px;">
        <label for="reddit_cross_post_enabled">
            <input type="checkbox" name="reddit_cross_post_enabled" id="reddit_cross_post_enabled" value="1" <?php checked($enabled, '1'); ?>>
            <strong>Auto Cross-Post</strong>
        </label>
        <p style="margin: 5px 0 0; font-size: 12px; color: #555;">
            When enabled, this post will be submitted to Reddit automatically upon saving or publishing using the categories defined in the settings.
        </p>
    </div>

    <div style="margin-bottom: 10px;">
        <label for="reddit_cross_post_manual_subreddit">
            <strong>Override and Post Manually:</strong>
        </label>
        <input type="text" name="reddit_cross_post_manual_subreddit" id="reddit_cross_post_manual_subreddit" value="<?php echo esc_attr($manual_subreddit); ?>" placeholder="e.g., pics" style="width: 100%;">
        <p style="margin: 5px 0 0; font-size: 12px; color: #555;">
            Specify the subreddit where this post should be submitted. Separate multiple subreddits with commas.
        </p>
    </div>

    <div style="margin-bottom: 10px;">
        <button type="button" id="reddit_cross_poster_send_now" class="button button-primary">
            Manual Submit Now
        </button>
    </div>

    <script>
        document.getElementById('reddit_cross_poster_send_now').addEventListener('click', function() {
            const postId = <?php echo $post->ID; ?>;
            const data = {
                action: 'reddit_cross_post',
                post_id: postId,
                _ajax_nonce: '<?php echo wp_create_nonce("reddit_cross_post_nonce"); ?>'
            };
            jQuery.post(ajaxurl, data, function(response) {
                if (response.success) {
                    alert('Post successfully submitted to Reddit!');
                } else {
                    alert('Failed to submit post: ' + response.data);
                }
            });
        });
    </script>
    <?php
}

// AJAX handler for the "Send Now" button
add_action('wp_ajax_reddit_cross_post', 'reddit_cross_poster_ajax_handler');
function reddit_cross_poster_ajax_handler() {
    check_ajax_referer('reddit_cross_post_nonce', '_ajax_nonce');

    $post_id = intval($_POST['post_id']);
    if (!$post_id) wp_send_json_error('Invalid post ID.');

    $token = get_option('reddit_access_token');
    if (!$token) wp_send_json_error('No OAuth token available.');

    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    if (!$image_url) wp_send_json_error('No featured image found.');

    $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
    $post_url = get_permalink($post_id);
    $title = get_the_title($post_id);
    $manual_subreddit = get_post_meta($post_id, '_reddit_cross_post_manual_subreddit', true);
    $target_subreddits = $manual_subreddit ? explode(',', $manual_subreddit) : [];

    if (empty($target_subreddits)) wp_send_json_error('No subreddit specified.');

    // Initialize an array to store errors for each subreddit
    $submission_errors = [];

    foreach ($target_subreddits as $subreddit) {
        $success = reddit_cross_poster_submit_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit);

        if (!$success) {
            $submission_errors[] = "Failed to post to r/$subreddit.";
        }
    }

    // If there are any errors, send them back
    if (!empty($submission_errors)) {
        wp_send_json_error(implode(' ', $submission_errors));
    }

    wp_send_json_success('Post successfully submitted to Reddit.');
}


// Submit post to Reddit
function reddit_cross_poster_submit_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit) {
    // Decode HTML entities in the title
    $decoded_title = html_entity_decode($title, ENT_QUOTES);

    $combined_text = $excerpt . " [Continue reading on our website]($post_url)";

    $response = wp_remote_post('https://oauth.reddit.com/api/submit', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'User-Agent' => 'YourAppName/1.0'
        ),
        'body' => array(
            'title' => $decoded_title, // Use the decoded title
            'url' => $image_url,
            'kind' => 'link',
            'sr' => $subreddit,
            'text' => $combined_text
        ),
    ));

    if (is_wp_error($response)) {
        error_log("Reddit Cross Poster: WP Error - " . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['json']['errors']) && !empty($body['json']['errors'])) {
        error_log("Reddit Cross Poster: API Errors - " . print_r($body['json']['errors'], true));
        return implode(', ', array_map(function ($error) {
            return $error[1];
        }, $body['json']['errors']));
    }

    if (isset($body['json']['data']['url'])) {
        error_log("Reddit Cross Poster: Successfully posted to r/$subreddit - URL: " . $body['json']['data']['url']);
        return true;
    }

    return false;
}
