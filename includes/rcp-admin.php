<?php
// Admin menu and settings page
add_action('admin_menu', 'rcp_add_admin_menu');
add_action('admin_init', 'rcp_register_settings');

function rcp_add_admin_menu() {
    add_menu_page(
        'Reddit Cross Poster',
        'Reddit Cross Poster',
        'manage_options',
        'reddit-cross-poster',
        'rcp_render_admin_page',
        'dashicons-share'
    );
}

function rcp_render_admin_page() {
    if (isset($_GET['reddit_oauth'])) {
        rcp_start_oauth();
    }

    ?>
    <div class="wrap">
        <h1>Reddit Cross Poster Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('rcp_settings_group');
            do_settings_sections('reddit-cross-poster');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function rcp_register_settings() {
    register_setting('rcp_settings_group', 'rcp_client_id');
    register_setting('rcp_settings_group', 'rcp_client_secret');
    register_setting('rcp_settings_group', 'rcp_category_subreddit_map');
    register_setting('rcp_settings_group', 'rcp_default_subreddit');

    add_settings_section(
        'rcp_api_settings',
        'API Settings',
        function() {
            echo '<p>Enter your Reddit API credentials.</p>';
        },
        'reddit-cross-poster'
    );

    add_settings_field(
        'rcp_client_id',
        'Client ID',
        function() {
            echo '<input type="text" name="rcp_client_id" value="' . esc_attr(get_option('rcp_client_id')) . '">';
        },
        'reddit-cross-poster',
        'rcp_api_settings'
    );

    add_settings_field(
        'rcp_client_secret',
        'Client Secret',
        function() {
            echo '<input type="text" name="rcp_client_secret" value="' . esc_attr(get_option('rcp_client_secret')) . '">';
        },
        'reddit-cross-poster',
        'rcp_api_settings'
    );

    add_settings_section(
        'rcp_post_settings',
        'Post Settings',
        function() {
            echo '<p>Configure default and category-specific subreddits.</p>';
        },
        'reddit-cross-poster'
    );

    add_settings_field(
        'rcp_category_subreddit_map',
        'Category to Subreddit Mapping',
        function() {
            echo '<textarea name="rcp_category_subreddit_map">' . esc_textarea(get_option('rcp_category_subreddit_map')) . '</textarea>';
        },
        'reddit-cross-poster',
        'rcp_post_settings'
    );

    add_settings_field(
        'rcp_default_subreddit',
        'Default Subreddit',
        function() {
            echo '<input type="text" name="rcp_default_subreddit" value="' . esc_attr(get_option('rcp_default_subreddit')) . '">';
        },
        'reddit-cross-poster',
        'rcp_post_settings'
    );
}
function rcp_enqueue_inline_admin_assets($hook) {
    // Inline CSS for the admin panel
    $admin_css = "
        .wrap {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #333;
        }
        button.button-primary {
            background-color: #ff4500;
            border-color: #ff4500;
        }
        button.button-primary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        #rcp-meta-box {
            background-color: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        #rcp-meta-box label {
            font-weight: bold;
            margin-bottom: 10px;
        }
        #rcp-meta-box input[type='text'] {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    ";
    wp_add_inline_style('wp-admin', $admin_css);

    // Inline JavaScript for admin functionality
    $admin_js = "
        document.addEventListener('DOMContentLoaded', function () {
            const submitButton = document.getElementById('rcp_manual_submit');
            if (submitButton) {
                submitButton.addEventListener('click', function () {
                    const postId = this.dataset.postId;
                    const subredditInput = document.getElementById('rcp_manual_subreddit');
                    const subreddit = subredditInput ? subredditInput.value : '';

                    if (!subreddit) {
                        alert('Please specify a subreddit before submitting.');
                        return;
                    }

                    const data = {
                        action: 'rcp_manual_submit',
                        post_id: postId,
                        manual_subreddit: subreddit,
                        _ajax_nonce: rcp_ajax_object.nonce,
                    };

                    fetch(rcp_ajax_object.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data),
                    })
                        .then((response) => response.json())
                        .then((result) => {
                            if (result.success) {
                                alert(result.data);
                            } else {
                                alert('Submission failed: ' + result.data);
                            }
                        })
                        .catch((error) => {
                            console.error('Error:', error);
                            alert('An error occurred while submitting the post.');
                        });
                });
            }
        });
    ";

    if ('post.php' === $hook || 'post-new.php' === $hook) {
        wp_localize_script('jquery', 'rcp_ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rcp_nonce'),
        ]);
        wp_add_inline_script('jquery', $admin_js);
    }
}
add_action('admin_enqueue_scripts', 'rcp_enqueue_inline_admin_assets');

?>
