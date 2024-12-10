
# WP Reddit Cross-Poster Plugin

The **WordPress Reddit Cross-Poster Plugin** allows seamless cross-posting of your WordPress blog content to Reddit. Whether you're managing a personal blog or a large-scale publication, this plugin ensures your content reaches Reddit's vast audience with ease.

## 🌟 Features

- **Seamless Integration**: Automatically cross-post your WordPress posts to multiple Reddit subreddits.
- **OAuth Authentication**: Securely connect your Reddit account using OAuth 2.0 for smooth API operations.
- **Manual and Scheduled Posting**:
  - Manually post to Reddit with custom configurations.
  - Automatically post when scheduled WordPress posts are published.
- **Dynamic Flair Management**: Fetch subreddit-specific flairs directly in the post editor.
- **Preset System**:
  - Save subreddit configurations as presets.
  - Easily load presets for new posts or reuse across multiple posts.
- **Customizable Post Options**:
  - Post featured images or article links.
  - Include excerpts, custom text, or append a "Read more" link.
  - Option to post excerpts in comments.
- **Error Handling and Debugging**:
  - Retry mechanisms for transient errors or rate limits.
  - Debug mode for detailed logging.
- **Advanced Admin Dashboard**:
  - View detailed submission logs with links, status, and settings used.
  - Edit, delete, and manage presets.
  - Test Reddit API connections directly from the settings.

## 🚀 Installation

1. Download the plugin files and upload them to your WordPress `wp-content/plugins/` directory.
2. Activate the plugin from the **Plugins** section in the WordPress admin dashboard.
3. Navigate to **ARCP Settings** under the WordPress admin menu to configure the plugin.

## 🛠️ Setup and Usage

### 1. Connect Your Reddit Account
- Obtain a Reddit API Client ID and Client Secret from the [Reddit Developer Portal](https://www.reddit.com/prefs/apps).
- Add the credentials in the **ARCP Settings** page under the **API Credentials** section.
- Click the **Authenticate with Reddit** button to securely connect your Reddit account.

### 2. Configure Cross-Posting
- Use the **Meta Box** in the post editor to:
  - Add subreddits, select flairs, and customize post options.
  - Save the configuration as a preset for future use.
- Presets can also be managed in the **ARCP Settings** page for global reuse.

### 3. Automatic Posting
- Enable automatic posting for scheduled posts via the **ARCP Settings** page.
- Posts will be published to Reddit automatically when they go live on WordPress.

## 🛡️ Debugging and Support

- Enable **Debug Mode** in the settings for detailed logs of API requests and responses.
- Use the **Test Connection** button in the settings to verify Reddit API connectivity.
- All errors and retry attempts are logged for easy troubleshooting.

## 🤔 FAQ

### Q: Can I post to multiple subreddits at once?
Yes, the plugin supports posting to multiple subreddits simultaneously, with individual flair and comment settings for each subreddit.

### Q: How are presets managed?
Presets can be created, edited, and deleted directly from the settings page or the post editor. These presets save subreddit, flair, and comment configurations for quick reuse.

### Q: What types of posts are supported?
- Featured images as "image" (posting the featured image directly as a link, emulating an image post) posts on Reddit.
- Article links with excerpts or custom text.

### Q: How is scheduling handled?
The plugin automatically detects scheduled WordPress posts turning from scheduled to published and thencross-posts them to Reddit based on the subreddit configurations in the post.

## 🎉 Contributing/Future Roadmap

We welcome contributions! Feel free to open issues or submit pull requests on our [GitHub repository](https://github.com/vedaanty/reddit-crosspost-plugin).
This plugin is used in one of my active projects, so if it ever breaks I'll probably end up fixing it. Not exactly sure what additional features can be added, but I am happy to listen to suggestions and squash bugs if you find them!
I'm quite new to making wordpress plugins, so please ignore any wordpress faux pas that may have been made.

### Known Issues/Wanted Improvements: 
- No flair dropdown in ARCP admin menu preset editing
- Flair dropdown doesn't work if a preset is imported
- Maybe some kind of category based automation?
  
## Credits
This plugin has been forked from [VestraInteractive's Plugin](https://github.com/vestrainteractive/reddit-crosspost-plugin.) <br>
It is updated in almost every way and shares little to no similarity with the original.
