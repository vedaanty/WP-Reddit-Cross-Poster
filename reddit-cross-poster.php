<?php
/*
Plugin Name: WP Reddit Cross-Poster
Plugin URI: https://github.com/vedaanty/reddit-crosspost-plugin/
Description: A plugin for cross-posting WordPress posts to Reddit with dynamic flair support, presets, logging, and user-friendly UI.
Version: 3.0.0
Author: Vedaant
Author URI: https://github.com/vedaanty/
*/

if (!defined('ABSPATH')) {
    exit;
}

ob_start();

define('ARCP_REDIRECT_URI', admin_url('admin.php?page=arcp-settings'));
define('ARCP_TRANSIENT_FLAIR_PREFIX', 'arcp_flairs_');

// Activate/Deactivate
register_activation_hook(__FILE__, 'arcp_activate_plugin');
register_deactivation_hook(__FILE__, 'arcp_deactivate_plugin');

function arcp_activate_plugin() {
    if (!wp_next_scheduled('arcp_refresh_token_cron')) {
        wp_schedule_event(time(), 'hourly', 'arcp_refresh_token_cron');
    }
    if (!get_option('arcp_submission_log')) {
        update_option('arcp_submission_log', []);
    }
}

function arcp_deactivate_plugin() {
    wp_clear_scheduled_hook('arcp_refresh_token_cron');
}

add_action('arcp_refresh_token_cron', 'arcp_refresh_access_token');

// Admin notice if credentials missing
add_action('admin_notices', 'arcp_admin_notices');
function arcp_admin_notices() {
    $client_id = get_option('arcp_client_id');
    $client_secret = get_option('arcp_client_secret');
    if (empty($client_id) || empty($client_secret)) {
        echo '<div class="notice notice-error"><p><strong>Reddit Cross-Poster:</strong> Please configure your Reddit API credentials in ARCP Settings.</p></div>';
    }
}

// Add settings page
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

function arcp_render_settings_page() {
    // Create nonce here, after WordPress is loaded
    $arcp_nonce = wp_create_nonce('arcp_ajax_nonce');

    // Handle OAuth start if requested
    if (isset($_GET['arcp_auth']) && $_GET['arcp_auth'] === '1') {
        arcp_start_oauth();
    }

    // Handle OAuth callback
    if (isset($_GET['code'])) {
        arcp_handle_oauth_callback($_GET['code']);
    }

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

    $access_token = get_option('arcp_access_token');
    $reddit_username = get_option('arcp_reddit_username', '');
    $presets = get_option('arcp_subreddit_presets', []);

    ?>
    <div class="wrap">
        <h1>Advanced Reddit Cross-Poster</h1>
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('admin.php?page=arcp-settings&tab=general')); ?>" class="nav-tab <?php if($tab==='general') echo 'nav-tab-active'; ?>">General</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=arcp-settings&tab=advanced')); ?>" class="nav-tab <?php if($tab==='advanced') echo 'nav-tab-active'; ?>">Advanced</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=arcp-settings&tab=logs')); ?>" class="nav-tab <?php if($tab==='logs') echo 'nav-tab-active'; ?>">Logs</a>
        </h2>
        <div class="arcp-tab-content">
        <?php if ($tab === 'general'): ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('arcp_settings_group');
                do_settings_sections('arcp-settings-general');
                submit_button('Save Settings');
                ?>

                <hr>
                <h2>OAuth Authentication</h2>
                <p>Authenticate with Reddit to enable posting.</p>
                <?php if ($access_token) : ?>
                    <p style="color:green;"><strong>Logged in:</strong> Yes</p>
                    <?php if ($reddit_username): ?>
                        <p><strong>Reddit Username:</strong> <?php echo esc_html($reddit_username); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color:red;"><strong>Logged in:</strong> No</p>
                <?php endif; ?>
                <a href="<?php echo esc_url(add_query_arg('arcp_auth', '1', admin_url('admin.php?page=arcp-settings'))); ?>" class="button button-primary">Authenticate with Reddit</a>
                <button type="button" id="arcp-test-connection" class="button">Test Connection</button>
                <p id="arcp-test-connection-result"></p>

                <hr>
                <h2>Manage Presets</h2>
                <?php if(!empty($presets)): ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Preset Name</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($presets as $name => $data): ?>
                                <tr>
                                    <td><?php echo esc_html($name); ?></td>
                                    <td>
                                        <button type="button" class="button arcp-edit-preset" data-preset="<?php echo esc_attr($name); ?>">Edit</button>
                                        <button type="button" class="button arcp-delete-preset" data-preset="<?php echo esc_attr($name); ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No presets saved yet.</p>
                <?php endif; ?>

                <p style="margin-top:20px;">
                    <button type="button" id="arcp-add-new-preset-btn" class="button button-primary">Add New Preset</button>
                </p>

                <!-- Edit Preset Section -->
                <div id="arcp-edit-preset-section" style="display:none; margin-top:20px;">
                    <h3>Edit Preset: <span id="arcp-edit-preset-name"></span></h3>
                    <div id="arcp-edit-preset-container"></div>
                    <button type="button" id="arcp-edit-preset-add" class="button">+ Add Subreddit</button>
                    <p style="margin-top:10px;">
                        <button type="button" id="arcp-edit-preset-save" class="button button-primary">Save Changes</button>
                        <button type="button" id="arcp-edit-preset-cancel" class="button">Cancel</button>
                    </p>
                </div>

                <!-- Add New Preset Section -->
                <div id="arcp-new-preset-section" style="display:none; margin-top:20px;">
                    <h3>Add New Preset</h3>
                    <input type="text" id="arcp-new-preset-name" placeholder="Preset Name" style="width:60%; margin-bottom:10px;"><br>
                    <div id="arcp-new-preset-container"></div>
                    <button type="button" id="arcp-new-preset-add" class="button">+ Add Subreddit</button>
                    <p style="margin-top:10px;">
                        <button type="button" id="arcp-new-preset-save" class="button button-primary">Save New Preset</button>
                        <button type="button" id="arcp-new-preset-cancel" class="button">Cancel</button>
                    </p>
                </div>

                <script>
                (function($){
                    var ajaxNonce = '<?php echo $arcp_nonce; ?>';

                    $('#arcp-test-connection').on('click', function(){
                        $.post(ajaxurl, {
                            action: 'arcp_test_connection',
                            _ajax_nonce: ajaxNonce
                        }, function(response){
                            if (response.success) {
                                $('#arcp-test-connection-result').html('<span style="color:green;">Connection successful!</span>');
                            } else {
                                $('#arcp-test-connection-result').html('<span style="color:red;">Connection failed: '+response.data+'</span>');
                            }
                        });
                    });

                    // EDIT PRESET
                    var editingPreset = '';
                    $('.arcp-edit-preset').on('click', function(){
                        editingPreset = $(this).data('preset');
                        $.post(ajaxurl, {
                            action: 'arcp_load_preset',
                            preset_name: editingPreset,
                            _ajax_nonce: ajaxNonce
                        }, function(response){
                            if(response.success) {
                                $('#arcp-edit-preset-container').empty();
                                $('#arcp-edit-preset-name').text(editingPreset);
                                $.each(response.data, function(i,row){
                                    var checked = row.put_excerpt_in_comment ? 'checked' : '';
                                    $('#arcp-edit-preset-container').append(
                                        '<div class="arcp-edit-preset-row" style="margin-bottom:5px;">'+
                                        '<input type="text" class="arcp-edit-subreddit" value="'+row.subreddit+'" style="width:30%; margin-right:2%;" placeholder="Subreddit">'+
                                        '<input type="text" class="arcp-edit-flair-text" value="'+(row.flair_text||"No Flair")+'" style="width:30%; margin-right:2%;" placeholder="Flair Text">'+
                                        '<input type="text" class="arcp-edit-flair-id" value="'+(row.flair_id||"")+'" style="width:20%; margin-right:2%;" placeholder="Flair ID">'+
                                        '<label style="display:inline-block; width:10%;"><input type="checkbox" class="arcp-edit-put-excerpt" value="1" '+checked+'>Comment</label>'+
                                        '<button type="button" class="arcp-edit-remove button button-secondary">Remove</button>'+
                                        '</div>'
                                    );
                                });
                                $('#arcp-new-preset-section').hide();
                                $('#arcp-edit-preset-section').show();
                            } else {
                                alert('Failed to load preset for editing: ' + response.data);
                            }
                        });
                    });

                    $('#arcp-edit-preset-add').on('click', function(){
                        $('#arcp-edit-preset-container').append(
                            '<div class="arcp-edit-preset-row" style="margin-bottom:5px;">'+
                            '<input type="text" class="arcp-edit-subreddit" style="width:30%; margin-right:2%;" placeholder="Subreddit">'+
                            '<input type="text" class="arcp-edit-flair-text" style="width:30%; margin-right:2%;" placeholder="Flair Text">'+
                            '<input type="text" class="arcp-edit-flair-id" style="width:20%; margin-right:2%;" placeholder="Flair ID">'+
                            '<label style="display:inline-block; width:10%;"><input type="checkbox" class="arcp-edit-put-excerpt" value="1">Comment</label>'+
                            '<button type="button" class="arcp-edit-remove button button-secondary">Remove</button>'+
                            '</div>'
                        );
                    });

                    $('#arcp-edit-preset-container').on('click', '.arcp-edit-remove', function(){
                        $(this).closest('.arcp-edit-preset-row').remove();
                    });

                    $('#arcp-edit-preset-save').on('click', function(){
                        var rowsData = [];
                        $('.arcp-edit-preset-row').each(function(){
                            var subreddit = $(this).find('.arcp-edit-subreddit').val().trim();
                            var flairText = $(this).find('.arcp-edit-flair-text').val().trim();
                            var flairId = $(this).find('.arcp-edit-flair-id').val().trim();
                            var putExcerpt = $(this).find('.arcp-edit-put-excerpt').is(':checked') ? 1 : 0;
                            if(subreddit) {
                                rowsData.push({
                                    subreddit: subreddit,
                                    flair_text: flairText || 'No Flair',
                                    flair_id: flairId || null,
                                    put_excerpt_in_comment: putExcerpt
                                });
                            }
                        });
                        if(rowsData.length === 0) {
                            alert('Cannot save empty preset.');
                            return;
                        }
                        $.post(ajaxurl, {
                            action: 'arcp_update_preset',
                            preset_name: editingPreset,
                            preset_data: JSON.stringify(rowsData),
                            _ajax_nonce: ajaxNonce
                        }, function(response){
                            if(response.success) {
                                alert('Preset updated successfully!');
                                location.reload();
                            } else {
                                alert('Failed to update preset: ' + response.data);
                            }
                        });
                    });

                    $('#arcp-edit-preset-cancel').on('click', function(){
                        $('#arcp-edit-preset-section').hide();
                        $('#arcp-edit-preset-container').empty();
                        editingPreset = '';
                    });

                    $('.arcp-delete-preset').on('click', function(){
                        var name = $(this).data('preset');
                        if(!confirm('Delete preset "'+name+'"?')) return;
                        $.post(ajaxurl, {
                            action: 'arcp_delete_preset',
                            preset_name: name,
                            _ajax_nonce: ajaxNonce
                        }, function(response){
                            if(response.success){
                                alert('Preset deleted.');
                                location.reload();
                            } else {
                                alert('Failed to delete preset: ' + response.data);
                            }
                        });
                    });

                    // ADD NEW PRESET
                    $('#arcp-add-new-preset-btn').on('click', function(){
                        $('#arcp-edit-preset-section').hide();
                        $('#arcp-new-preset-container').empty();
                        $('#arcp-new-preset-name').val('');
                        $('#arcp-new-preset-section').show();
                    });

                    $('#arcp-new-preset-add').on('click', function(){
                        $('#arcp-new-preset-container').append(
                            '<div class="arcp-new-preset-row" style="margin-bottom:5px;">'+
                            '<input type="text" class="arcp-new-subreddit" style="width:30%; margin-right:2%;" placeholder="Subreddit">'+
                            '<input type="text" class="arcp-new-flair-text" style="width:30%; margin-right:2%;" placeholder="Flair Text">'+
                            '<input type="text" class="arcp-new-flair-id" style="width:20%; margin-right:2%;" placeholder="Flair ID">'+
                            '<label style="display:inline-block; width:10%;"><input type="checkbox" class="arcp-new-put-excerpt" value="1">Comment</label>'+
                            '<button type="button" class="arcp-new-remove button button-secondary">Remove</button>'+
                            '</div>'
                        );
                    });

                    $('#arcp-new-preset-container').on('click', '.arcp-new-remove', function(){
                        $(this).closest('.arcp-new-preset-row').remove();
                    });

                    $('#arcp-new-preset-save').on('click', function(){
                        var presetName = $('#arcp-new-preset-name').val().trim();
                        if(!presetName) {
                            alert('Please enter a preset name.');
                            return;
                        }
                        var rowsData = [];
                        $('.arcp-new-preset-row').each(function(){
                            var subreddit = $(this).find('.arcp-new-subreddit').val().trim();
                            var flairText = $(this).find('.arcp-new-flair-text').val().trim();
                            var flairId = $(this).find('.arcp-new-flair-id').val().trim();
                            var putExcerpt = $(this).find('.arcp-new-put-excerpt').is(':checked') ? 1 : 0;
                            if(subreddit) {
                                rowsData.push({
                                    subreddit: subreddit,
                                    flair_text: flairText || 'No Flair',
                                    flair_id: flairId || null,
                                    put_excerpt_in_comment: putExcerpt
                                });
                            }
                        });
                        if(rowsData.length === 0) {
                            alert('Cannot save empty preset.');
                            return;
                        }

                        $.post(ajaxurl, {
                            action: 'arcp_save_preset',
                            preset_name: presetName,
                            preset_data: JSON.stringify(rowsData),
                            _ajax_nonce: ajaxNonce
                        }, function(response){
                            if(response.success) {
                                alert('New Preset created successfully!');
                                location.reload();
                            } else {
                                alert('Failed to create new preset: ' + response.data);
                            }
                        });
                    });

                    $('#arcp-new-preset-cancel').on('click', function(){
                        $('#arcp-new-preset-section').hide();
                        $('#arcp-new-preset-container').empty();
                        $('#arcp-new-preset-name').val('');
                    });

                })(jQuery);
                </script>

        <?php elseif ($tab === 'advanced'): ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('arcp_settings_group');
                do_settings_sections('arcp-settings-advanced');
                submit_button('Save Advanced Settings');
                ?>
        <?php elseif ($tab === 'logs'): ?>
            <h2>Submission Logs</h2>
            <p>All Reddit submission attempts are recorded here.</p>
            <?php
            $logs = get_option('arcp_submission_log', []);
            if (!empty($logs)): ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Post ID</th>
                            <th>Subreddit</th>
                            <th>Reddit Link</th>
                            <th>Status</th>
                            <th>Excerpt in Comment?</th>
                            <th>Type</th>
                            <th>Excerpt Used?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach(array_reverse($logs) as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['date']); ?></td>
                                <td><a href="<?php echo get_edit_post_link($log['post_id']); ?>" target="_blank"><?php echo (int)$log['post_id']; ?></a></td>
                                <td><?php echo esc_html($log['subreddit']); ?></td>
                                <td><?php if(!empty($log['reddit_link'])): ?><a href="<?php echo esc_url($log['reddit_link']); ?>" target="_blank">View</a><?php endif; ?></td>
                                <td><?php echo esc_html($log['status']); ?></td>
                                <td><?php echo $log['put_excerpt_in_comment'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo esc_html($log['post_type']); ?></td>
                                <td><?php echo $log['excerpt_used'] ? 'Yes' : 'No'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No logs recorded yet.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($tab === 'general' || $tab === 'advanced'): ?>
            </form>
        <?php endif; ?>
        </div>
    </div>
    <?php
}

add_action('wp_ajax_arcp_test_connection', 'arcp_test_connection_handler');
function arcp_test_connection_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');
    $token = get_option('arcp_access_token');
    if (!$token) {
        wp_send_json_error('No access token available.');
    }
    $info = arcp_fetch_reddit_user_info($token);
    if (is_wp_error($info)) {
        wp_send_json_error($info->get_error_message());
    } else {
        wp_send_json_success();
    }
}

function arcp_fetch_reddit_user_info($token) {
    $response = wp_remote_get('https://oauth.reddit.com/api/v1/me', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'User-Agent'    => 'AdvancedRedditCrossPoster/2.0',
        ],
    ]);

    if (is_wp_error($response)) {
        return $response;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['name'])) {
        update_option('arcp_reddit_username', $body['name']);
        return $body;
    } else {
        return new WP_Error('arcp_no_user', 'No username returned from Reddit.');
    }
}

add_action('admin_init', 'arcp_register_settings');
function arcp_register_settings() {
    // GENERAL TAB
    add_settings_section('arcp_main_settings', 'API Credentials', null, 'arcp-settings-general');
    add_settings_field('arcp_client_id', 'Reddit Client ID', 'arcp_render_client_id_field', 'arcp-settings-general', 'arcp_main_settings');
    add_settings_field('arcp_client_secret', 'Reddit Client Secret', 'arcp_render_client_secret_field', 'arcp-settings-general', 'arcp_main_settings');

    register_setting('arcp_settings_group', 'arcp_client_id');
    register_setting('arcp_settings_group', 'arcp_client_secret');

    add_settings_section('arcp_post_settings', 'Post Settings', null, 'arcp-settings-general');
    add_settings_field('arcp_post_type', 'Post Type', 'arcp_render_post_type_field', 'arcp-settings-general', 'arcp_post_settings');
    add_settings_field('arcp_custom_text', 'Custom Text', 'arcp_render_custom_text_field', 'arcp-settings-general', 'arcp_post_settings');
    add_settings_field('arcp_disable_excerpt', 'Disable Excerpt', 'arcp_render_disable_excerpt_field', 'arcp-settings-general', 'arcp_post_settings');
    add_settings_field('arcp_link_text', 'Link Text', 'arcp_render_link_text_field', 'arcp-settings-general', 'arcp_post_settings');
    add_settings_field('arcp_disable_scheduled_auto_post', 'Disable Scheduled Auto-Posting', 'arcp_render_disable_scheduled_auto_post_field', 'arcp-settings-general', 'arcp_post_settings');
    add_settings_field('arcp_default_preset', 'Default Preset', 'arcp_render_default_preset_field', 'arcp-settings-general', 'arcp_post_settings');

    register_setting('arcp_settings_group', 'arcp_post_type');
    register_setting('arcp_settings_group', 'arcp_custom_text', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('arcp_settings_group', 'arcp_disable_excerpt', ['sanitize_callback' => 'rest_sanitize_boolean']);
    register_setting('arcp_settings_group', 'arcp_link_text', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('arcp_settings_group', 'arcp_disable_scheduled_auto_post', ['sanitize_callback' => 'rest_sanitize_boolean']);
    register_setting('arcp_settings_group', 'arcp_default_preset', ['sanitize_callback' => 'sanitize_text_field']);

    // ADVANCED TAB
    add_settings_section('arcp_advanced_settings', 'Advanced Settings', null, 'arcp-settings-advanced');
    add_settings_field('arcp_debug_mode', 'Debug Mode', 'arcp_render_debug_mode_field', 'arcp-settings-advanced', 'arcp_advanced_settings');
    add_settings_field('arcp_retry_count', 'Retry Count', 'arcp_render_retry_count_field', 'arcp-settings-advanced', 'arcp_advanced_settings');
    add_settings_field('arcp_retry_delay', 'Retry Delay (seconds)', 'arcp_render_retry_delay_field', 'arcp-settings-advanced', 'arcp_advanced_settings');

    register_setting('arcp_settings_group', 'arcp_debug_mode', ['sanitize_callback' => 'rest_sanitize_boolean']);
    register_setting('arcp_settings_group', 'arcp_retry_count', ['sanitize_callback' => 'absint']);
    register_setting('arcp_settings_group', 'arcp_retry_delay', ['sanitize_callback' => 'absint']);
}

function arcp_render_client_id_field() {
    $client_id = get_option('arcp_client_id');
    echo '<input type="text" name="arcp_client_id" value="' . esc_attr($client_id) . '" style="width:100%;">';
    echo '<p class="description">From <a href="https://www.reddit.com/prefs/apps" target="_blank">Reddit Developer Portal</a>.</p>';
}

function arcp_render_client_secret_field() {
    $client_secret = get_option('arcp_client_secret');
    echo '<input type="text" name="arcp_client_secret" value="' . esc_attr($client_secret) . '" style="width:100%;">';
    echo '<p class="description">From Reddit Developer Portal. Redirect URI: '.esc_html(ARCP_REDIRECT_URI).'</p>';
}

function arcp_render_post_type_field() {
    $post_type = get_option('arcp_post_type', 'image');
    ?>
    <select name="arcp_post_type">
        <option value="image" <?php selected($post_type, 'image'); ?>>Featured Image Link</option>
        <option value="link" <?php selected($post_type, 'link'); ?>>Article Link</option>
    </select>
    <p class="description">Choose whether to post the featured image link or the article link as the main URL.</p>
    <?php
}

function arcp_render_custom_text_field() {
    $custom_text = get_option('arcp_custom_text', '');
    ?>
    <input type="text" name="arcp_custom_text" value="<?php echo esc_attr($custom_text); ?>" style="width:100%;" placeholder="Optional custom text">
    <p class="description">Replace the excerpt with custom text if provided.</p>
    <?php
}

function arcp_render_disable_excerpt_field() {
    $disable_excerpt = get_option('arcp_disable_excerpt', false);
    ?>
    <input type="checkbox" name="arcp_disable_excerpt" value="1" <?php checked($disable_excerpt, true); ?>>
    <p class="description">If checked, no excerpt text is posted (unless put in comment).</p>
    <?php
}

function arcp_render_link_text_field() {
    $link_text = get_option('arcp_link_text', 'Read more');
    ?>
    <input type="text" name="arcp_link_text" value="<?php echo esc_attr($link_text); ?>" style="width:100%;">
    <p class="description">Link text to accompany the excerpt with a link in the post body.</p>
    <?php
}

function arcp_render_disable_scheduled_auto_post_field() {
    $disabled = get_option('arcp_disable_scheduled_auto_post', false);
    ?>
    <input type="checkbox" name="arcp_disable_scheduled_auto_post" value="1" <?php checked($disabled, true); ?>>
    <p class="description">Disable automatic posting for scheduled posts when published.</p>
    <?php
}

function arcp_render_default_preset_field() {
    $default_preset = get_option('arcp_default_preset', '');
    $presets = get_option('arcp_subreddit_presets', []);
    ?>
    <select name="arcp_default_preset">
        <option value="">None</option>
        <?php foreach($presets as $name => $data): ?>
            <option value="<?php echo esc_attr($name); ?>" <?php selected($default_preset, $name); ?>><?php echo esc_html($name); ?></option>
        <?php endforeach; ?>
    </select>
    <p class="description">If set, this preset will be automatically loaded when creating a new post.</p>
    <?php
}

function arcp_render_debug_mode_field() {
    $debug_mode = get_option('arcp_debug_mode', false);
    ?>
    <input type="checkbox" name="arcp_debug_mode" value="1" <?php checked($debug_mode, true); ?>>
    <p class="description">If enabled, logs requests and responses to the error log for debugging.</p>
    <?php
}

function arcp_render_retry_count_field() {
    $retry_count = get_option('arcp_retry_count', 3);
    ?>
    <input type="number" name="arcp_retry_count" value="<?php echo esc_attr($retry_count); ?>" min="0" style="width:60px;">
    <p class="description">Number of retries on transient errors or rate limits.</p>
    <?php
}

function arcp_render_retry_delay_field() {
    $retry_delay = get_option('arcp_retry_delay', 5);
    ?>
    <input type="number" name="arcp_retry_delay" value="<?php echo esc_attr($retry_delay); ?>" min="0" style="width:60px;">
    <p class="description">Delay in seconds between retries.</p>
    <?php
}

function arcp_start_oauth() {
    if (!function_exists('wp_redirect')) {
        require_once(ABSPATH . 'wp-includes/pluggable.php');
    }
    $client_id = get_option('arcp_client_id');
    $state = wp_generate_uuid4();
    $auth_url = "https://www.reddit.com/api/v1/authorize?client_id={$client_id}&response_type=code&state={$state}&redirect_uri=" . urlencode(ARCP_REDIRECT_URI) . "&duration=permanent&scope=submit,flair,identity";

    wp_redirect($auth_url);
    exit;
}

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
        wp_die('OAuth failed. Check error logs.');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['access_token'])) {
        update_option('arcp_access_token', $body['access_token']);
        update_option('arcp_refresh_token', $body['refresh_token']);
        error_log("ARCP: OAuth Success - Access token saved.");
        // Fetch username
        arcp_fetch_reddit_user_info($body['access_token']);
        wp_redirect(admin_url('admin.php?page=arcp-settings'));
        exit;
    } else {
        error_log("ARCP: OAuth Error - " . print_r($body, true));
        wp_die('OAuth failed. Check error logs.');
    }
}

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
        arcp_fetch_reddit_user_info($body['access_token']);
        return $body['access_token'];
    } else {
        error_log("ARCP: Token Refresh Failed - " . print_r($body, true));
        return false;
    }
}

// Add meta box
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

// Save subreddit data on post save to ensure persistence
add_action('save_post', 'arcp_save_subreddit_data_on_save_post', 10, 2);
function arcp_save_subreddit_data_on_save_post($post_id, $post) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    if (isset($_POST['arcp_subreddits'])) {
        $subreddits = $_POST['arcp_subreddits'];
        $flairs = isset($_POST['arcp_flairs']) ? $_POST['arcp_flairs'] : [];
        $put_excerpts = isset($_POST['arcp_put_excerpt_in_comment']) ? $_POST['arcp_put_excerpt_in_comment'] : [];

        $subreddit_data = [];
        for ($i = 0; $i < count($subreddits); $i++) {
            $subreddit = sanitize_text_field($subreddits[$i]);
            if (!$subreddit) continue;
            $flair_id = isset($flairs[$i]) ? sanitize_text_field($flairs[$i]) : '';
            $put_excerpt = isset($put_excerpts[$i]) ? 1 : 0;
            $subreddit_data[] = [
                'subreddit' => $subreddit,
                'flair_id' => $flair_id,
                'flair_text' => 'No Flair',
                'put_excerpt_in_comment' => $put_excerpt
            ];
        }
        update_post_meta($post_id, '_arcp_subreddit_data', $subreddit_data);
    }
}

function arcp_render_meta_box($post) {
    // Nonce here
    $arcp_nonce = wp_create_nonce('arcp_ajax_nonce');

    $subreddit_data = get_post_meta($post->ID, '_arcp_subreddit_data', true) ?: [];
    $presets = get_option('arcp_subreddit_presets', []);
    $default_preset = get_option('arcp_default_preset', '');
    $is_new_post = ( 'auto-draft' === get_post_status($post->ID) && !wp_is_post_revision($post->ID) );
    ?>
    <div class="arcp-meta-box">
        <h4>Manual Reddit Submission</h4>
        <p>Select subreddits, flairs, and whether to put excerpt in a comment.</p>
        <div id="arcp-subreddit-container">
            <?php if (!empty($subreddit_data)) :
                foreach ($subreddit_data as $index => $entry):
                    $checked = !empty($entry['put_excerpt_in_comment']) ? 'checked' : '';
                    ?>
                    <div class="arcp-subreddit-row">
                        <input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" value="<?php echo esc_attr($entry['subreddit']); ?>" style="width:30%; margin-right:2%;">
                        <select name="arcp_flairs[]" class="arcp-flair" style="width:30%; margin-right:2%;">
                            <option value="<?php echo esc_attr($entry['flair_id']); ?>"><?php echo esc_html($entry['flair_text'] ?? 'No Flair'); ?></option>
                        </select>
                        <label style="display:inline-block; width:30%;">
                            <input type="checkbox" name="arcp_put_excerpt_in_comment[<?php echo $index; ?>]" value="1" <?php echo $checked; ?>> Excerpt in Comment
                        </label>
                        <button type="button" class="arcp-remove-subreddit button button-secondary">Remove</button>
                    </div>
                <?php endforeach; 
            else: ?>
                <div class="arcp-subreddit-row">
                    <input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" style="width:30%; margin-right:2%;">
                    <select name="arcp_flairs[]" class="arcp-flair" style="width:30%; margin-right:2%;">
                        <option value="">No Flair</option>
                    </select>
                    <label style="display:inline-block; width:30%;">
                        <input type="checkbox" name="arcp_put_excerpt_in_comment[0]" value="1"> Excerpt in Comment
                    </label>
                    <button type="button" class="arcp-remove-subreddit button button-secondary">Remove</button>
                </div>
            <?php endif; ?>
        </div>
        <button type="button" id="arcp-add-subreddit" class="button">+ Add Subreddit</button>

        <p style="margin-top:10px;"><button type="button" id="arcp-manual-submit" class="button button-primary">Submit to Reddit</button></p>

        <hr>
        <h4>Presets</h4>
        <p>Load and save common subreddit configurations.</p>
        <select id="arcp-preset-selector">
            <option value="">Select a preset</option>
            <?php foreach ($presets as $preset_name => $p_data) {
                echo '<option value="'.esc_attr($preset_name).'">'.esc_html($preset_name).'</option>';
            } ?>
        </select>
        <button type="button" id="arcp-load-preset" class="button">Load</button><br><br>
        <input type="text" id="arcp-preset-name" placeholder="Preset Name" style="width:60%; margin-right:2%;">
        <button type="button" id="arcp-save-preset" class="button">Save Current as Preset</button>
    </div>

    <script>
    (function($){
        var ajaxNonce = '<?php echo $arcp_nonce; ?>';
        var defaultPreset = '<?php echo esc_js($default_preset); ?>';
        var isNewPost = <?php echo $is_new_post ? 'true' : 'false'; ?>;
        if (defaultPreset && isNewPost) {
            loadPreset(defaultPreset);
        }

        function loadPreset(name) {
            $.post(ajaxurl, {
                action: 'arcp_load_preset',
                preset_name: name,
                _ajax_nonce: ajaxNonce
            }, function(response) {
                if (response.success) {
                    $('#arcp-subreddit-container').empty();
                    $.each(response.data, function(i,row){
                        var checked = row.put_excerpt_in_comment ? 'checked' : '';
                        $('#arcp-subreddit-container').append(
                            '<div class="arcp-subreddit-row">'+
                            '<input type="text" name="arcp_subreddits[]" class="arcp-subreddit" value="'+row.subreddit+'" style="width:30%; margin-right:2%;">'+
                            '<select name="arcp_flairs[]" class="arcp-flair" style="width:30%; margin-right:2%;"><option value="'+(row.flair_id||"")+'">'+(row.flair_text||"No Flair")+'</option></select>'+
                            '<label style="display:inline-block; width:30%;"><input type="checkbox" name="arcp_put_excerpt_in_comment['+i+']" value="1" '+checked+'> Excerpt in Comment</label>'+
                            '<button type="button" class="arcp-remove-subreddit button button-secondary">Remove</button>'+
                            '</div>'
                        );
                    });
                } else {
                    alert('Failed to load preset: ' + response.data);
                }
            });
        }

        $('#arcp-load-preset').on('click', function(){
            var presetName = $('#arcp-preset-selector').val();
            if (!presetName) return;
            loadPreset(presetName);
        });

        $('#arcp-save-preset').on('click', function(){
            var presetName = $('#arcp-preset-name').val().trim();
            if (!presetName) {
                alert('Please enter a preset name.');
                return;
            }

            var subredditData = [];
            $('.arcp-subreddit-row').each(function (i) {
                var subreddit = $(this).find('.arcp-subreddit').val().trim();
                var flairId = $(this).find('.arcp-flair').val();
                var flairText = $(this).find('.arcp-flair option:selected').text();
                var putExcerpt = $(this).find('input[name^="arcp_put_excerpt_in_comment"]').is(':checked') ? 1 : 0;

                if (subreddit) {
                    subredditData.push({
                        subreddit: subreddit,
                        flair_id: flairId || null,
                        flair_text: flairText || 'No Flair',
                        put_excerpt_in_comment: putExcerpt
                    });
                }
            });

            if (subredditData.length === 0) {
                alert('Cannot save an empty preset.');
                return;
            }

            $.post(ajaxurl, {
                action: 'arcp_save_preset',
                preset_name: presetName,
                preset_data: JSON.stringify(subredditData),
                _ajax_nonce: ajaxNonce
            }, function(response){
                if (response.success) {
                    alert('Preset saved successfully!');
                    location.reload();
                } else {
                    alert('Failed to save preset: ' + response.data);
                }
            });
        });

        $('#arcp-add-subreddit').on('click', function () {
            var idx = $('.arcp-subreddit-row').length;
            $('#arcp-subreddit-container').append(
                '<div class="arcp-subreddit-row">'+
                '<input type="text" name="arcp_subreddits[]" class="arcp-subreddit" placeholder="Subreddit" style="width:30%; margin-right:2%;">'+
                '<select name="arcp_flairs[]" class="arcp-flair" style="width:30%; margin-right:2%;"><option value="">No Flair</option></select>'+
                '<label style="display:inline-block; width:30%;"><input type="checkbox" name="arcp_put_excerpt_in_comment['+idx+']" value="1"> Excerpt in Comment</label>'+
                '<button type="button" class="arcp-remove-subreddit button button-secondary">Remove</button>'+
                '</div>'
            );
        });

        $('#arcp-subreddit-container').on('click', '.arcp-remove-subreddit', function () {
            $(this).closest('.arcp-subreddit-row').remove();
        });

        // Fetch flairs on change
        $('#arcp-subreddit-container').on('change', '.arcp-subreddit', function () {
            var subreddit = $(this).val().trim();
            var flairDropdown = $(this).siblings('.arcp-flair');

            if (!subreddit) {
                flairDropdown.html('<option value="">No Flair</option>');
                return;
            }

            var subredditValid = /^[A-Za-z0-9_]+$/.test(subreddit);
            if(!subredditValid){
                alert('Invalid subreddit name: '+subreddit);
                flairDropdown.html('<option value="">No Flair</option>');
                return;
            }

            flairDropdown.html('<option>Loading...</option>');
            $.post(ajaxurl, {
                action: 'arcp_fetch_flairs',
                subreddit: subreddit,
                _ajax_nonce: ajaxNonce
            }, function (response) {
                if (response.success) {
                    flairDropdown.html('<option value="">No Flair</option>');
                    $.each(response.data, function(i, flair){
                        flairDropdown.append('<option value="'+flair.id+'">'+flair.text+'</option>');
                    });
                } else {
                    flairDropdown.html('<option value="">No Flair Available</option>');
                }
            }).fail(function () {
                flairDropdown.html('<option value="">Failed to load flairs</option>');
            });
        });

        $('#arcp-manual-submit').on('click', function () {
            var postId = <?php echo (int)$post->ID; ?>;
            var subredditData = [];

            $('.arcp-subreddit-row').each(function (i) {
                var subreddit = $(this).find('.arcp-subreddit').val().trim();
                var flairId = $(this).find('.arcp-flair').val();
                var flairText = $(this).find('.arcp-flair option:selected').text();
                var putExcerpt = $(this).find('input[name^="arcp_put_excerpt_in_comment"]').is(':checked') ? 1 : 0;

                if (subreddit) {
                    subredditData.push({
                        subreddit: subreddit,
                        flair_id: flairId || null,
                        flair_text: flairText || 'No Flair',
                        put_excerpt_in_comment: putExcerpt
                    });
                }
            });

            if (subredditData.length === 0) {
                alert('Please add at least one subreddit.');
                return;
            }

            $.post(ajaxurl, {
                action: 'arcp_manual_submit',
                post_id: postId,
                subreddit_data: JSON.stringify(subredditData),
                _ajax_nonce: ajaxNonce
            }, function (response) {
                if (response.success) {
                    alert('Post successfully submitted:\n' + response.data);
                    // Data saved on save_post, but we do an AJAX save here to ensure persistence
                    $.post(ajaxurl, {
                        action: 'arcp_save_subreddit_data',
                        post_id: postId,
                        subreddit_data: JSON.stringify(subredditData),
                        _ajax_nonce: ajaxNonce
                    });
                } else {
                    alert('Failed to submit:\n' + response.data);
                }
            }).fail(function () {
                alert('Failed to communicate with the server.');
            });
        });

    })(jQuery);
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

// AJAX handlers
add_action('wp_ajax_arcp_fetch_flairs', 'arcp_fetch_flairs_handler');
function arcp_fetch_flairs_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');
    $subreddit = sanitize_text_field($_POST['subreddit']);
    if (empty($subreddit)) {
        wp_send_json_error('Subreddit is required.');
    }

    $cached = get_transient(ARCP_TRANSIENT_FLAIR_PREFIX . $subreddit);
    if ($cached !== false) {
        wp_send_json_success($cached);
    }

    $token = get_option('arcp_access_token');
    if (!$token) {
        wp_send_json_error('No OAuth token available.');
    }

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

    if (!is_array($body)) {
        wp_send_json_error('Invalid response from Reddit.');
    }

    $flairs = array_map(function ($flair) {
        return [
            'id'   => $flair['id'] ?? '',
            'text' => $flair['text'] ?? 'No Flair',
        ];
    }, $body);

    set_transient(ARCP_TRANSIENT_FLAIR_PREFIX . $subreddit, $flairs, HOUR_IN_SECONDS);

    wp_send_json_success($flairs);
}

// Manual submit
add_action('wp_ajax_arcp_manual_submit', 'arcp_manual_submit_handler');
function arcp_manual_submit_handler() {

    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');

    $post_id = intval($_POST['post_id']);
    $subreddit_data_raw = $_POST['subreddit_data'] ?? '';
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
    $post_type = get_option('arcp_post_type', 'image');

    $results = [];
    foreach ($subreddit_data as $entry) {
        $subreddit = sanitize_text_field($entry['subreddit']);
        $flair_id = sanitize_text_field($entry['flair_id'] ?? '');
        $put_excerpt_in_comment = !empty($entry['put_excerpt_in_comment']);
        $response = arcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit, $flair_id, $put_excerpt_in_comment, $post_type);
        $status = $response['success'] ? 'success' : 'failure';
        $results[] = ($response['success'] ? "Success" : "Failed") . " to post to r/$subreddit: " . ($response['success'] ? $response['url'] : $response['error']);
       
        // If excerpt in comment

        if ($response['success'] && $put_excerpt_in_comment && !empty($excerpt)) {
            $link_text = get_option('arcp_link_text', 'Read more');
            $comment_text = $excerpt;
            if (!empty($link_text)) {
                $comment_text .= " [$link_text]($post_url)";
            }
            $comment_response = arcp_post_comment_to_reddit($token, $response['thing_id'], $comment_text);
            if (!$comment_response['success']) {
                $results[] = "Failed to comment on r/$subreddit post: " . $comment_response['error'];
            } else {
                $results[] = "Comment posted successfully on r/$subreddit.";
            }
        }

        arcp_log_submission($post_id, $subreddit, $response['url'] ?? '', $status, $put_excerpt_in_comment, $post_type, $excerpt);
    }

    $message = "Post Results:\n\n" . implode("\n", $results);
    wp_send_json_success($message);
}

// Delete preset
add_action('wp_ajax_arcp_delete_preset', 'arcp_delete_preset_handler');
function arcp_delete_preset_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');
    $preset_name = sanitize_text_field($_POST['preset_name']);
    $presets = get_option('arcp_subreddit_presets', []);
    if (!isset($presets[$preset_name])) {
        wp_send_json_error('Preset does not exist.');
    }
    unset($presets[$preset_name]);
    update_option('arcp_subreddit_presets', $presets);
    wp_send_json_success();
}

// Update preset
add_action('wp_ajax_arcp_update_preset', 'arcp_update_preset_handler');
function arcp_update_preset_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');
    $preset_name = sanitize_text_field($_POST['preset_name']);
    $preset_data = json_decode(stripslashes($_POST['preset_data']), true);

    if (empty($preset_name) || empty($preset_data)) {
        wp_send_json_error('Invalid preset data.');
    }

    $presets = get_option('arcp_subreddit_presets', []);
    $presets[$preset_name] = $preset_data;
    update_option('arcp_subreddit_presets', $presets);

    wp_send_json_success();
}

// Save preset
add_action('wp_ajax_arcp_save_preset', 'arcp_save_preset_handler');
function arcp_save_preset_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');
    $preset_name = sanitize_text_field($_POST['preset_name']);
    $preset_data = json_decode(stripslashes($_POST['preset_data']), true);
    if (empty($preset_name) || empty($preset_data)) {
        wp_send_json_error('Invalid preset data.');
    }
    $presets = get_option('arcp_subreddit_presets', []);
    $presets[$preset_name] = $preset_data;
    update_option('arcp_subreddit_presets', $presets);
    wp_send_json_success();
}

// Load preset
add_action('wp_ajax_arcp_load_preset', 'arcp_load_preset_handler');
function arcp_load_preset_handler() {
    check_ajax_referer('arcp_ajax_nonce', '_ajax_nonce');
    $preset_name = sanitize_text_field($_POST['preset_name']);
    $presets = get_option('arcp_subreddit_presets', []);
    if (isset($presets[$preset_name])) {
        wp_send_json_success($presets[$preset_name]);
    } else {
        wp_send_json_error('Preset not found.');
    }
}

// Log submissions
function arcp_log_submission($post_id, $subreddit, $reddit_link, $status, $put_excerpt_in_comment, $post_type, $excerpt) {
    $logs = get_option('arcp_submission_log', []);
    $logs[] = [
        'date' => current_time('mysql'),
        'post_id' => $post_id,
        'subreddit' => $subreddit,
        'reddit_link' => $reddit_link,
        'status' => $status,
        'put_excerpt_in_comment' => $put_excerpt_in_comment,
        'post_type' => $post_type,
        'excerpt_used' => !empty($excerpt)
    ];
    update_option('arcp_submission_log', $logs);
}

// Submit post
function arcp_submit_post_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit, $flair_id = null, $put_excerpt_in_comment = false, $post_type = 'image') {
    $custom_text = get_option('arcp_custom_text', '');
    $disable_excerpt = get_option('arcp_disable_excerpt', false);
    $link_text = get_option('arcp_link_text', 'Read more');
    $debug_mode = get_option('arcp_debug_mode', false);
    $retry_count = get_option('arcp_retry_count', 3);
    $retry_delay = get_option('arcp_retry_delay', 5);

    if ($disable_excerpt) {
        $post_excerpt = '';
    } elseif (!empty($custom_text)) {
        $post_excerpt = $custom_text;
    } else {
        $post_excerpt = $excerpt;
    }

    if (!empty($link_text) && !$disable_excerpt && !$put_excerpt_in_comment) {
        $post_excerpt .= " [$link_text]($post_url)";
    }

    if ($put_excerpt_in_comment) {
        $post_excerpt = '';
    }

    $is_image_post = ($post_type === 'image' && !empty($image_url));
    $url_to_use = $is_image_post ? $image_url : $post_url;

    $post_data = [
        'title' => html_entity_decode($title, ENT_QUOTES),
        'kind'  => 'link',
        'url'   => $url_to_use,
        'sr'    => $subreddit,
        'api_type' => 'json',
    ];

    if (!$put_excerpt_in_comment && !empty($post_excerpt)) {
        $post_data['text'] = $post_excerpt;
    }

    if ($flair_id) {
        $post_data['flair_id'] = $flair_id;
    }

    for ($i = 0; $i <= $retry_count; $i++) {
        if ($debug_mode) {
            error_log("ARCP DEBUG: Posting attempt #$i to r/$subreddit with data: " . print_r($post_data, true));
        }

        $response = wp_remote_post('https://oauth.reddit.com/api/submit', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'    => 'AdvancedRedditCrossPoster/2.0',
            ],
            'body' => $post_data,
        ]);

        if (is_wp_error($response)) {
            if ($i == $retry_count) {
                return ['success' => false, 'error' => $response->get_error_message()];
            }
            sleep($retry_delay);
            continue; 
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($debug_mode) {
            error_log("ARCP DEBUG: Reddit Response: " . print_r($body, true));
        }

        if (isset($body['json']) && empty($body['json']['errors']) && !empty($body['json']['data']['name'])) {
            $reddit_post_url = $body['json']['data']['url'] ?? "https://www.reddit.com/r/$subreddit/";
            $thing_id = $body['json']['data']['name'];
            return ['success' => true, 'url' => $reddit_post_url, 'thing_id' => $thing_id];
        }

        if (!empty($body['json']['ratelimit'])) {
            if ($i < $retry_count) {
                $wait = intval($body['json']['ratelimit']) + $retry_delay;
                error_log("ARCP: Rate limit hit, waiting $wait seconds before retry.");
                sleep($wait);
                continue;
            } else {
                return ['success' => false, 'error' => 'Rate limit reached.'];
            }
        }

        if ($i == $retry_count) {
            error_log("ARCP: Unexpected response from Reddit - " . print_r($body, true));
            return ['success' => false, 'error' => 'Unexpected response from Reddit.'];
        }

        sleep($retry_delay);
    }

    return ['success' => false, 'error' => 'Failed after multiple retries.'];
}

function arcp_post_comment_to_reddit($token, $thing_id, $comment_text) {
    $debug_mode = get_option('arcp_debug_mode', false);
    $comment_data = [
        'api_type' => 'json',
        'thing_id' => $thing_id,
        'text' => $comment_text,
    ];

    if ($debug_mode) {
        error_log("ARCP DEBUG: Posting comment to $thing_id: $comment_text");
    }

    $response = wp_remote_post('https://oauth.reddit.com/api/comment', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'User-Agent'    => 'AdvancedRedditCrossPoster/2.0',
        ],
        'body' => $comment_data,
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($debug_mode) {
        error_log("ARCP DEBUG: Comment Response: " . print_r($body, true));
    }

    if (isset($body['json']) && empty($body['json']['errors'])) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'Unexpected response from Reddit comment API.'];
    }
}

// Auto cross post on publish
add_action('publish_post', 'arcp_auto_cross_post');
function arcp_auto_cross_post($post_id) {
    // Prevent auto-posting during revisions or autosaves
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    // Check if "Disable Scheduled Auto-Posting" is enabled in admin settings
    $disable_auto_posting = get_option('arcp_disable_scheduled_auto_post', false);
    if ($disable_auto_posting) {
        // Auto-posting is disabled globally; skip cross-posting
        return;
    }

    // Retrieve subreddit data from post meta
    $subreddit_data = get_post_meta($post_id, '_arcp_subreddit_data', true);
    if (empty($subreddit_data) || !is_array($subreddit_data)) {
        // No subreddit data found; skip auto-posting
        return;
    }

    // Retrieve the Reddit OAuth access token
    $token = get_option('arcp_access_token');
    if (!$token) {
        // No access token available; cannot auto-post
        return;
    }

    // Gather post information
    $title = get_the_title($post_id);
    $excerpt = get_the_excerpt($post_id);
    $post_url = get_permalink($post_id);
    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    $post_type = get_option('arcp_post_type', 'image');

    // Loop through each subreddit entry and submit the post
    foreach ($subreddit_data as $entry) {
        // Sanitize subreddit details
        $subreddit = sanitize_text_field($entry['subreddit']);
        $flair_id = isset($entry['flair_id']) ? sanitize_text_field($entry['flair_id']) : '';
        $put_excerpt_in_comment = !empty($entry['put_excerpt_in_comment']);

        // Submit the post to Reddit
        $response = arcp_submit_post_to_reddit(
            $token,
            $title,
            $image_url,
            $excerpt,
            $post_url,
            $subreddit,
            $flair_id,
            $put_excerpt_in_comment,
            $post_type
        );

        // Determine the status based on the response
        $status = isset($response['success']) && $response['success'] ? 'success' : 'failure';

        // Log the submission attempt
        arcp_log_submission(
            $post_id,
            $subreddit,
            isset($response['url']) ? $response['url'] : '',
            $status,
            $put_excerpt_in_comment,
            $post_type,
            $excerpt
        );

        // If the submission was successful and excerpt should be posted as a comment
        if ($response['success'] && $put_excerpt_in_comment && !empty($excerpt)) {
            $link_text = get_option('arcp_link_text', 'Read more');
            $comment_text = $excerpt;
            if (!empty($link_text)) {
                $comment_text .= " [$link_text]($post_url)";
            }

            // Post the comment to Reddit
            $comment_response = arcp_post_comment_to_reddit($token, $response['thing_id'], $comment_text);

            if ($comment_response['success']) {
                // Log successful comment
                arcp_log_submission(
                    $post_id,
                    $subreddit,
                    $response['url'] ?? '',
                    'comment_success',
                    $put_excerpt_in_comment,
                    $post_type,
                    $excerpt
                );
            } else {
                // Log failed comment
                arcp_log_submission(
                    $post_id,
                    $subreddit,
                    $response['url'] ?? '',
                    'comment_failure: ' . $comment_response['error'],
                    $put_excerpt_in_comment,
                    $post_type,
                    $excerpt
                );
            }
        }
    }
}
