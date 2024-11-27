<?php
// Parse target subreddits based on categories, category mapping, and default subreddit
function rcp_get_target_subreddits($categories, $category_map, $default_subreddit) {
    $target_subreddits = [];

    if (!empty($category_map)) {
        foreach (explode("\n", $category_map) as $mapping) {
            $mapping = trim($mapping);
            if (strpos($mapping, ':') !== false) {
                [$category, $subreddits] = explode(':', $mapping, 2);
                $subreddits = array_map('trim', explode(',', $subreddits));

                if (in_array(strtolower($category), array_map('strtolower', $categories))) {
                    $target_subreddits = array_merge($target_subreddits, $subreddits);
                }
            }
        }
    }

    if (empty($target_subreddits) && !empty($default_subreddit)) {
        $target_subreddits[] = $default_subreddit;
    }

    return array_unique($target_subreddits);
}

// Submit a post to Reddit
function rcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit) {
    $decoded_title = html_entity_decode($title, ENT_QUOTES);
    $combined_text = $excerpt . " [Continue reading on our website]($post_url)";

    $response = wp_remote_post('https://oauth.reddit.com/api/submit', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'User-Agent' => 'RCP/1.0',
        ],
        'body' => [
            'title' => $decoded_title,
            'url' => $image_url,
            'kind' => 'link',
            'sr' => $subreddit,
            'text' => $combined_text,
        ],
    ]);

    if (is_wp_error($response)) {
        return "HTTP Error: " . $response->get_error_message();
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['json']['errors'])) {
        $errors = implode(', ', array_column($body['json']['errors'], 1));
        return "API Error: $errors";
    }

    if (!empty($body['json']['data']['url'])) {
        return true;
    }

    return "Unexpected Response: " . wp_remote_retrieve_body($response);
}
?>
