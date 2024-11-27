<?php
add_action('publish_post', 'rcp_publish_post');

// Auto-post logic for published posts
function rcp_publish_post($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return; // Skip revisions and auto-saves
    }

    $enabled = get_post_meta($post_id, '_rcp_auto_post_enabled', true);
    if ($enabled !== '1') {
        return; // Auto-posting is not enabled for this post
    }

    $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
    $category_map = get_option('rcp_category_subreddit_map');
    $default_subreddit = get_option('rcp_default_subreddit');

    $target_subreddits = rcp_get_target_subreddits($categories, $category_map, $default_subreddit);

    if (empty($target_subreddits)) {
        error_log("RCP: No subreddits found for post ID $post_id.");
        return;
    }

    $token = get_option('rcp_access_token');
    if (!$token) {
        $token = rcp_refresh_access_token();
        if (!$token) {
            error_log("RCP: Unable to refresh access token.");
            return;
        }
    }

    $title = get_the_title($post_id);
    $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    $post_url = get_permalink($post_id);

    foreach ($target_subreddits as $subreddit) {
        $result = rcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit);

        if ($result === true) {
            error_log("RCP: Successfully posted to r/$subreddit for post ID $post_id.");
        } else {
            error_log("RCP: Failed to post to r/$subreddit for post ID $post_id: $result");
        }
    }
}

// Manual submission logic via AJAX
add_action('wp_ajax_rcp_manual_submit', 'rcp_manual_submit');

function rcp_manual_submit() {
    check_ajax_referer('rcp_nonce', '_ajax_nonce');

    $post_id = intval($_POST['post_id']);
    if (!$post_id) {
        wp_send_json_error('Invalid post ID.');
    }

    $manual_subreddit = sanitize_text_field($_POST['manual_subreddit'] ?? '');
    if (empty($manual_subreddit)) {
        wp_send_json_error('No subreddit specified.');
    }

    $token = get_option('rcp_access_token');
    if (!$token) {
        $token = rcp_refresh_access_token();
        if (!$token) {
            wp_send_json_error('Unable to refresh access token.');
        }
    }

    $title = get_the_title($post_id);
    $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    $post_url = get_permalink($post_id);

    $subreddits = explode(',', $manual_subreddit);
    $results = [];

    foreach ($subreddits as $subreddit) {
        $subreddit = trim($subreddit);
        if (!$subreddit) continue;

        $result = rcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit);

        if ($result === true) {
            $results[] = "Successfully posted to r/$subreddit.";
        } else {
            $results[] = "Failed to post to r/$subreddit: $result";
        }
    }

    if (empty($results)) {
        wp_send_json_error('No valid subreddits to post.');
    } else {
        wp_send_json_success(implode("\n", $results));
    }
}
?>
