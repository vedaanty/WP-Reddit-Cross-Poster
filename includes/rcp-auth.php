<?php
function rcp_start_oauth() {
    $client_id = get_option('rcp_client_id');
    $state = wp_generate_uuid4();

    $redirect_uri = admin_url('admin.php?page=reddit-cross-poster');
    $oauth_url = "https://www.reddit.com/api/v1/authorize?client_id=$client_id&response_type=code&state=$state&redirect_uri=" . urlencode($redirect_uri) . "&duration=permanent&scope=submit";

    wp_redirect($oauth_url);
    exit;
}

function rcp_handle_oauth_response($code) {
    $client_id = get_option('rcp_client_id');
    $client_secret = get_option('rcp_client_secret');
    $redirect_uri = admin_url('admin.php?page=reddit-cross-poster');

    $response = wp_remote_post('https://www.reddit.com/api/v1/access_token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode("$client_id:$client_secret"),
        ],
        'body' => [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri,
        ],
    ]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['access_token'])) {
        update_option('rcp_access_token', $body['access_token']);
        update_option('rcp_refresh_token', $body['refresh_token']);
    }
}

function rcp_refresh_access_token() {
    $client_id = get_option('rcp_client_id');
    $client_secret = get_option('rcp_client_secret');
    $refresh_token = get_option('rcp_refresh_token');

    if (!$refresh_token) {
        return false;
    }

    $response = wp_remote_post('https://www.reddit.com/api/v1/access_token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode("$client_id:$client_secret"),
        ],
        'body' => [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
        ],
    ]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['access_token'])) {
        update_option('rcp_access_token', $body['access_token']);
    }
}
?>
