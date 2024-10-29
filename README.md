# reddit-crosspost-plugin

Plugin URI: https://github.com/vestrainteractive/reddit-crosspost-plugin

Description: Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.

Version: 1.0.8

Author: Vestra Interactive

Author URI: https://vestrainteractive.com

Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.

---

## To use:

Open the file reddit-crosspost-plugin.php in a text editor and edit line 12:

`code`define('REDDIT_REDIRECT_URI', 'https://interstate411.us/wp-admin/admin.php?page=reddit-cross-poster'); // Replace with your exact site URL


Upload and activate plugin.

WP Admin > Settings > Reddit Crosspost

Set API information -  Get Reddit API from https://www.reddit.com/prefs/apps/.  

If you want any new posts in certain categories to auto-post to a specific subreddit, enter them in the box below, one per line.  Omit the /r/  For example:

tech:technology
blog:blogs 

If you want to set the subreddit on a per-post basis, you can do that in the post-editor.

Note:  This plugin will NOT auto-update yet.  It is planned for a future version, however.  Check back in 2025 for this functionality.
