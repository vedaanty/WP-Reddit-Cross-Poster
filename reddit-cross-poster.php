<?php
/*
Plugin Name: Reddit Cross-Post Plugin
Plugin URI: https://github.com/vedaanty/reddit-crosspost-plugin/
Description: Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.
Version: 1.2.0
Author: Vedaant
Author URI: https://github.com/vedaanty/
*/

// Suppress output to prevent unintended whitespace
ob_start();

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/rcp-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/rcp-auth.php';
require_once plugin_dir_path(__FILE__) . 'includes/rcp-post-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/rcp-helpers.php';

// Activation and deactivation hooks
register_activation_hook(__FILE__, 'rcp_activate_plugin');
register_deactivation_hook(__FILE__, 'rcp_deactivate_plugin');

// Activation callback
function rcp_activate_plugin() {
    if (!wp_next_scheduled('rcp_refresh_token_cron')) {
        wp_schedule_event(time(), 'hourly', 'rcp_refresh_token_cron');
    }
}

// Deactivation callback
function rcp_deactivate_plugin() {
    wp_clear_scheduled_hook('rcp_refresh_token_cron');
}

// Token refresh cron action
add_action('rcp_refresh_token_cron', 'rcp_refresh_access_token');
?>
