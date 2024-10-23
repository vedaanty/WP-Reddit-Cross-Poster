<?php
/*
Plugin Name: Reddit Cross-Post Plugin
Plugin URI: https://github.com/vestrainteractive/reddit-crosspost-plugin
Description: Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.
Version: 1.2
Author: Vestra Interactive
Author URI: https://vestrainteractive.com
*/

// Add the meta box for the post page
add_action('add_meta_boxes', 'reddit_crosspost_add_meta_box');
function reddit_crosspost_add_meta_box() {
    add_meta_box('crosspost_meta_box', 'Reddit Cross-Post', 'reddit_render_crosspost_meta_box', 'post', 'side');
}

// Render the meta box content
function reddit_render_crosspost_meta_box($post) {
    // Retrieve existing meta data
    $crosspost_enable = get_post_meta($post->ID, '_crosspost_enable', true);
    $crosspost_subreddits = get_post_meta($post->ID, '_crosspost_subreddits', true);
    $crosspost_error = get_post_meta($post->ID, '_crosspost_error', true);

    // Display the toggle, text box for custom subreddits, and any errors
    ?>
    <label for="crosspost_enable">
        <input type="checkbox" name="crosspost_enable" id="crosspost_enable" value="1" <?php checked($crosspost_enable, '1'); ?> />
        Enable Reddit Cross-Post
    </label><br><br>
    <label for="crosspost_subreddits">Subreddits (comma-separated):</label><br>
    <input type="text" name="crosspost_subreddits" id="crosspost_subreddits" value="<?php echo esc_attr($crosspost_subreddits); ?>" /><br><br>

    <?php if (!empty($crosspost_error)) : ?>
        <div style="color: red;">
            <strong>Error:</strong> <?php echo esc_html($crosspost_error); ?>
        </div>
    <?php endif; ?>
    <?php
}

// Save the meta box data
add_action('save_post', 'reddit_save_crosspost_meta_data');
function reddit_save_crosspost_meta_data($post_id) {
    if (array_key_exists('crosspost_enable', $_POST)) {
        update_post_meta($post_id, '_crosspost_enable', $_POST['crosspost_enable']);
    } else {
        delete_post_meta($post_id, '_crosspost_enable');
    }

    if (array_key_exists('crosspost_subreddits', $_POST)) {
        update_post_meta($post_id, '_crosspost_subreddits', sanitize_text_field($_POST['crosspost_subreddits']));
    }

    // Clear any previous errors
    delete_post_meta($post_id, '_crosspost_error');
}

// Add settings page for Reddit API credentials and category-to-subreddit mapping
add_action('admin_menu', 'reddit_crosspost_settings_menu');
function reddit_crosspost_settings_menu() {
    add_options_page('Reddit Cross-Post Settings', 'Reddit Cross-Post', 'manage_options', 'reddit-crosspost-settings', 'reddit_crosspost_settings_page');
}

// Render the settings page
function reddit_crosspost_settings_page() {
    if (isset($_POST['reddit_crosspost_save_settings'])) {
        update_option('reddit_crosspost_client_id', sanitize_text_field($_POST['client_id']));
        update_option('reddit_crosspost_client_secret', sanitize_text_field($_POST['client_secret']));

        // Sanitize and save category-to-subreddit mappings with new lines
        $category_mapping = sanitize_textarea_field($_POST['category_mapping']);
        update_option('reddit_crosspost_category_mapping', $category_mapping);

        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Retrieve saved category mapping for display
    $saved_mapping = get_option('reddit_crosspost_category_mapping', '');
    ?>
    <div class="wrap">
        <h2>Reddit Cross-Post Settings</h2>
        <form method="post" action="">
            <h3>Reddit API Credentials</h3>
            <label for="client_id">Client ID:</label>
            <input type="text" name="client_id" value="<?php echo esc_attr(get_option('reddit_crosspost_client_id')); ?>" /><br>
            <label for="client_secret">Client Secret:</label>
            <input type="text" name="client_secret" value="<?php echo esc_attr(get_option('reddit_crosspost_client_secret')); ?>" /><br><br>

            <h3>Category to Subreddit Mapping</h3>
            <p>Each category to subreddit mapping should be on a new line (e.g., `category:subreddit1,subreddit2`).</p>
            <textarea name="category_mapping" rows="10" cols="50"><?php echo esc_textarea($saved_mapping); ?></textarea><br><br>

            <input type="submit" name="reddit_crosspost_save_settings" value="Save Settings" class="button-primary" />
        </form>
    </div>
    <?php
}

// Hook to post to Reddit on publish/update if the toggle is set
add_action('save_post', 'reddit_crosspost_on_publish');
function reddit_crosspost_on_publish($post_id) {
    $crosspost_enable = get_post_meta($post_id, '_crosspost_enable', true);
    if ($crosspost_enable) {
        $subreddits = get_post_meta($post_id, '_crosspost_subreddits', true);

        if (empty($subreddits)) {
            // Use category mapping if no custom subreddits were entered
            $categories = get_the_category($post_id);
            $category_mapping = get_option('reddit_crosspost_category_mapping');

            // Split the category mapping by new line for cleaner separation
            $mappings = explode("\n", $category_mapping);

            foreach ($categories as $category) {
                foreach ($mappings as $map) {
                    list($cat, $subs) = array_map('trim', explode(':', $map));
                    
                    // Check if category matches and add the subreddits
                    if ($category->name === $cat) {
                        $subreddits .= $subs . ',';
                    }
                }
            }
            $subreddits = rtrim($subreddits, ',');
        }

        // Get post details
        $post_title = get_the_title($post_id);
        $post_excerpt = get_the_excerpt($post_id);
        $featured_image = get_the_post_thumbnail_url($post_id);

        // Post to each subreddit
        $subreddits = explode(',', $subreddits);
        foreach ($subreddits as $subreddit) {
            $result = reddit_crosspost_submit_post($post_title, $post_excerpt, $featured_image, trim($subreddit));
            
            if ($result !== true) {
                // Save error message as post meta to display it later
                update_post_meta($post_id, '_crosspost_error', $result);
                break; // Stop on first error
            }
        }
    }
}

// Submit a post to Reddit
function reddit_crosspost_submit_post($title, $text, $image_url, $subreddit) {
    $access_token = get_option('reddit_crosspost_access_token');
    $url = "https://oauth.reddit.com/api/submit";
    
    $response = wp_remote_post($url, array(
        'body' => array(
            'sr' => $subreddit,
            'kind' => 'link',
            'title' => $title,
            'url' => $image_url, // Can also use 'self' and 'text' for text posts
        ),
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/x-www-form-urlencoded'
        )
    ));

    if (is_wp_error($response)) {
        // Return the error message
        return $response->get_error_message();
    }

    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    // Check for errors in Reddit's response
    if (isset($response_body['error'])) {
        return 'Reddit API error: ' . $response_body['error'];
    }

    return true;
}

// Include the GitHub Updater class
add_action('plugins_loaded', function() {
    $file = plugin_dir_path( __FILE__ ) . 'class-github-updater.php';

    if ( file_exists( $file ) ) {
        require_once $file;
        error_log( 'GitHub Updater file included successfully.' );
    } else {
        error_log( 'GitHub Updater file not found at: ' . $file );
    }

    // Ensure the class exists before instantiating
    if ( class_exists( 'GitHub_Updater' ) ) {
        // Initialize the updater
        new GitHub_Updater( 'reddit-crosspost-plugin', 'https://github.com/vestrainteractive/reddit-crosspost-plugin', '1.0.0' ); // Replace with actual values
        error_log( 'GitHub Updater class instantiated.' );
    } else {
        error_log( 'GitHub_Updater class not found.' );
    }
});

?>
