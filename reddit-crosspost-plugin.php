<?php
/*
Plugin Name: Reddit Cross-Post Plugin
Plugin URI: https://github.com/vestrainteractive/reddit-crosspost-plugin
Description: Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.
Version: 1.0.8
Author: Vestra Interactive
Author URI: https://vestrainteractive.com
*/

// Register actions
add_action('admin_menu', 'reddit_crosspost_add_admin_menu');
add_action('admin_init', 'reddit_crosspost_settings_init');
add_action('add_meta_boxes', 'reddit_crosspost_add_meta_box');
add_action('save_post', 'reddit_crosspost_save_postdata');
add_action('wp_ajax_reddit_crosspost_manual', 'reddit_crosspost_manual_post');

// Add options page
function reddit_crosspost_add_admin_menu() {
    add_options_page('Reddit Cross-Post', 'Reddit Cross-Post', 'manage_options', 'reddit-crosspost-settings', 'reddit_crosspost_options_page');
}

// Initialize plugin settings
function reddit_crosspost_settings_init() {
    register_setting('reddit_crosspost_settings', 'reddit_crosspost_client_id');
    register_setting('reddit_crosspost_settings', 'reddit_crosspost_client_secret');
    register_setting('reddit_crosspost_settings', 'reddit_crosspost_access_token');
    register_setting('reddit_crosspost_settings', 'reddit_crosspost_refresh_token');
    register_setting('reddit_crosspost_settings', 'reddit_crosspost_category_subreddit_mapping');

    // Add settings section
    add_settings_section(
        'reddit_crosspost_settings_section',
        __('Reddit API Configuration', 'wordpress'),
        null,
        'reddit-crosspost-settings'
    );

    // Add settings fields
    add_settings_field(
        'reddit_crosspost_client_id',
        __('Reddit Client ID', 'wordpress'),
        'reddit_crosspost_client_id_render',
        'reddit-crosspost-settings',
        'reddit_crosspost_settings_section'
    );

    add_settings_field(
        'reddit_crosspost_client_secret',
        __('Reddit Client Secret', 'wordpress'),
        'reddit_crosspost_client_secret_render',
        'reddit-crosspost-settings',
        'reddit_crosspost_settings_section'
    );

    add_settings_field(
        'reddit_crosspost_category_subreddit_mapping',
        __('Category to Subreddit Mapping', 'wordpress'),
        'reddit_crosspost_mapping_render',
        'reddit-crosspost-settings',
        'reddit_crosspost_settings_section'
    );
}

// Render client ID field
function reddit_crosspost_client_id_render() {
    $client_id = get_option('reddit_crosspost_client_id');
    echo '<input type="text" name="reddit_crosspost_client_id" value="' . esc_attr($client_id) . '">';
}

// Render client secret field
function reddit_crosspost_client_secret_render() {
    $client_secret = get_option('reddit_crosspost_client_secret');
    echo '<input type="text" name="reddit_crosspost_client_secret" value="' . esc_attr($client_secret) . '">';
}

// Render category to subreddit mapping field
function reddit_crosspost_mapping_render() {
    $mapping = get_option('reddit_crosspost_category_subreddit_mapping');
    echo '<textarea name="reddit_crosspost_category_subreddit_mapping" rows="6" cols="50">' . esc_textarea($mapping) . '</textarea>';
    echo '<p>Enter one category to subreddit mapping per line in the format: category: subreddit1,subreddit2</p>';
}

// Render settings page
function reddit_crosspost_options_page() {
    ?>
    <form action="options.php" method="post">
        <h1>Reddit Cross-Post Plugin</h1>
        <?php
        settings_fields('reddit_crosspost_settings');
        do_settings_sections('reddit-crosspost-settings');
        submit_button();
        ?>
        <button type="button" onclick="window.location.href='<?php echo reddit_crosspost_get_authorization_url(); ?>'">Authorize with Reddit</button>
    </form>
    <?php
}

// Generate Reddit OAuth authorization URL
function reddit_crosspost_get_authorization_url() {
    $client_id = get_option('reddit_crosspost_client_id');
    $redirect_uri = urlencode(admin_url('options-general.php?page=reddit-crosspost-settings&action=oauth_callback'));

    $state = wp_create_nonce('reddit_crosspost_oauth');
    $scopes = 'submit identity';
    $authorization_url = "https://www.reddit.com/api/v1/authorize?client_id={$client_id}&response_type=code&state={$state}&redirect_uri={$redirect_uri}&duration=permanent&scope={$scopes}";

    return $authorization_url;
}

// Meta box for Reddit Cross-Post
function reddit_crosspost_add_meta_box() {
    add_meta_box('reddit_crosspost_section', 'Reddit Cross-Post Options', 'reddit_crosspost_meta_box_callback', 'post', 'side');
}

// Render meta box with button for manual cross-posting
function reddit_crosspost_meta_box_callback($post) {
    $value = get_post_meta($post->ID, '_reddit_crosspost', true);
    $subreddit = get_post_meta($post->ID, '_reddit_crosspost_subreddit', true);
    wp_nonce_field('reddit_crosspost_meta_box', 'reddit_crosspost_meta_box_nonce');
    ?>
    <p>
        <label for="reddit_crosspost_toggle">Cross-Post to Reddit?</label>
        <select name="reddit_crosspost_toggle" id="reddit_crosspost_toggle">
            <option value="no" <?php selected($value, 'no'); ?>>No</option>
            <option value="yes" <?php selected($value, 'yes'); ?>>Yes</option>
        </select>
    </p>
    <p>
        <label for="reddit_crosspost_subreddit">Subreddit (comma-separated):</label>
        <input type="text" name="reddit_crosspost_subreddit" id="reddit_crosspost_subreddit" value="<?php echo esc_attr($subreddit); ?>" />
    </p>
    <p>
        <button id="reddit_crosspost_manual_button" data-post-id="<?php echo $post->ID; ?>" class="button button-primary">Post to Reddit Now</button>
    </p>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#reddit_crosspost_manual_button').click(function(e) {
                e.preventDefault();
                var post_id = $(this).data('post-id');
                var data = {
                    'action': 'reddit_crosspost_manual',
                    'post_id': post_id,
                    'nonce': '<?php echo wp_create_nonce("reddit_crosspost_manual_nonce"); ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    alert(response.data ? response.data : response);
                });
            });
        });
    </script>
    <?php
}

// Save post data when the post is saved
function reddit_crosspost_save_postdata($post_id) {
    if (!isset($_POST['reddit_crosspost_meta_box_nonce']) || !wp_verify_nonce($_POST['reddit_crosspost_meta_box_nonce'], 'reddit_crosspost_meta_box')) {
        return;
    }

    if (isset($_POST['reddit_crosspost_toggle'])) {
        update_post_meta($post_id, '_reddit_crosspost', sanitize_text_field($_POST['reddit_crosspost_toggle']));
    }

    if (isset($_POST['reddit_crosspost_subreddit'])) {
        update_post_meta($post_id, '_reddit_crosspost_subreddit', sanitize_text_field($_POST['reddit_crosspost_subreddit']));
    }

    if ($_POST['reddit_crosspost_toggle'] === 'yes') {
        reddit_crosspost_to_reddit($post_id);
    }
}

// Handle AJAX request for manual cross-posting
function reddit_crosspost_manual_post() {
    check_ajax_referer('reddit_crosspost_manual_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('You do not have permission to perform this action.');
    }

    $post_id = intval($_POST['post_id']);
    $subreddit = get_post_meta($post_id, '_reddit_crosspost_subreddit', true);
    
    // Directly cross-post to the specified subreddit without category mappings
    $result = reddit_crosspost_to_reddit($post_id, $subreddit);

    if (strpos($result, 'Success') !== false) {
        wp_send_json_success('Post successfully cross-posted to Reddit!');
    } else {
        wp_send_json_error($result);
    }
}

// Cross-post to Reddit
function reddit_crosspost_to_reddit($post_id, $subreddit = null) {
    $client_id = get_option('reddit_crosspost_client_id');
    $client_secret = get_option('reddit_crosspost_client_secret');
    $access_token = get_option('reddit_crosspost_access_token');
    $post = get_post($post_id);
    $title = html_entity_decode(get_the_title($post_id), ENT_QUOTES, 'UTF-8');
    $url = get_permalink($post_id);
    $excerpt = html_entity_decode(get_the_excerpt($post_id), ENT_QUOTES, 'UTF-8'); // Get excerpt

    // Get category to subreddit mappings
    $category_mapping = get_option('reddit_crosspost_category_subreddit_mapping');
    $mappings = explode("\n", $category_mapping);
    $categories = [];

    foreach ($mappings as $mapping) {
        if (strpos($mapping, ':') !== false) {
            list($category, $subreddits) = explode(':', trim($mapping));
            $categories[trim($category)] = array_map('trim', explode(',', $subreddits));
        }
    }

    // Get post categories
    $post_categories = get_the_category($post_id);
    $subreddits_to_post = [];

    // Check category mappings if a subreddit is not provided
    if (empty($subreddit)) {
        foreach ($post_categories as $category) {
            if (isset($categories[$category->name])) {
                $subreddits_to_post = array_merge($subreddits_to_post, $categories[$category->name]);
            }
        }
        if (empty($subreddits_to_post)) {
            return 'Error: No subreddit available for this category.';
        }
        $subreddit = implode(',', $subreddits_to_post); // Use first available subreddit
    }

    // Prepare the post data
    $response = wp_remote_post('https://oauth.reddit.com/api/submit', array(
        'body' => array(
            'title' => $title,
            'url' => $url,
            'sr' => $subreddit,
            'kind' => 'link',
            'resubmit' => true,
            'text' => $excerpt, // Include the excerpt
        ),
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'User-Agent' => 'WordPressRedditCrossPost/1.0',
        ),
    ));

    if (is_wp_error($response)) {
        return 'Error posting to Reddit: ' . $response->get_error_message();
    }

    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($response_body['error'])) {
        return 'Reddit Error: ' . $response_body['error'];
    }

    return 'Success! Post shared on Reddit.';
}

// Handle OAuth callback
function reddit_crosspost_handle_oauth_callback() {
    if (!isset($_GET['state']) || !wp_verify_nonce($_GET['state'], 'reddit_crosspost_oauth')) {
        wp_die('Invalid OAuth state.');
    }

    if (isset($_GET['code'])) {
        $client_id = get_option('reddit_crosspost_client_id');
        $client_secret = get_option('reddit_crosspost_client_secret');
        $redirect_uri = admin_url('options-general.php?page=reddit-crosspost-settings&action=oauth_callback');
        $code = sanitize_text_field($_GET['code']);

        $token_url = 'https://www.reddit.com/api/v1/access_token';

        $response = wp_remote_post($token_url, array(
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect_uri,
            ),
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode("$client_id:$client_secret"),
                'Content-Type' => 'application/x-www-form-urlencoded',
            )
        ));

        if (is_wp_error($response)) {
            wp_die('Error retrieving access token: ' . $response->get_error_message());
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($response_body['access_token'])) {
            update_option('reddit_crosspost_access_token', $response_body['access_token']);
            update_option('reddit_crosspost_refresh_token', $response_body['refresh_token']);
            wp_redirect(admin_url('options-general.php?page=reddit-crosspost-settings&oauth_success=1'));
            exit;
        } else {
            wp_die('Error in OAuth process: ' . print_r($response_body, true));
        }
    }
}

// Handle OAuth callback action
if (isset($_GET['action']) && $_GET['action'] === 'oauth_callback') {
    add_action('init', 'reddit_crosspost_handle_oauth_callback');
}
?>
