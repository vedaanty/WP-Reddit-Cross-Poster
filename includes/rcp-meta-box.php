<?php

add_action('add_meta_boxes', 'rcp_add_meta_box');
function rcp_add_meta_box() {
    add_meta_box('rcp_meta', 'Reddit Cross Poster', 'rcp_meta_box_callback', 'post', 'side');
}

function rcp_meta_box_callback($post) {
    $enabled = get_post_meta($post->ID, '_reddit_cross_post_enabled', true);
    $manual_subreddit = get_post_meta($post->ID, '_reddit_cross_post_manual_subreddit', true);

    echo '<label for="rcp_enabled"><input type="checkbox" name="rcp_enabled" id="rcp_enabled" ' . checked($enabled, '1', false) . '> Enable Auto-Posting</label>';
    echo '<textarea name="rcp_manual_subreddit" placeholder="Enter subreddit(s)">' . esc_textarea($manual_subreddit) . '</textarea>';
}

function rcp_render_meta_box($post) {
    $enabled = get_post_meta($post->ID, '_rcp_auto_post', true);
    $manual_subreddit = get_post_meta($post->ID, '_rcp_manual_subreddit', true);

    echo '<div id="rcp-meta-box">';
    echo '<label for="rcp_auto_post"><input type="checkbox" name="rcp_auto_post" id="rcp_auto_post" value="1" ' . checked($enabled, '1', false) . '> Auto Cross-Post</label>';
    echo '<p>Automatically post to Reddit upon saving or publishing.</p>';
    echo '<label for="rcp_manual_subreddit">Manual Subreddit:</label>';
    echo '<input type="text" id="rcp_manual_subreddit" name="rcp_manual_subreddit" value="' . esc_attr($manual_subreddit) . '" placeholder="e.g., pics, news">';
    echo '<p>Enter subreddit names separated by commas (e.g., <code>pics, news</code>).</p>';
    echo '<button type="button" id="rcp_manual_submit" class="button button-primary" data-post-id="' . esc_attr($post->ID) . '">Submit Now</button>';
    echo '</div>';
}
