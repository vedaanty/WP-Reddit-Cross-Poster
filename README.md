# Wordpress Reddit Cross-Post Plugin

This plugin aims to allow easy cross posting of your wordpress blog to reddit. 
It does this by using the featured image on wordpress as the basis of an image post on reddit with a caption that contains an excerpt and a continue reading button that links to the original wordpress source. 

## Features 
- Mapping categories to automatically crosspost to multiple subreddits
- OAuth support for reddit account
- Manually posting from wordpress

## How to use
- Download the latest release and upload as plugin on wordpress
- Create a reddit application at: https://www.reddit.com/prefs/apps/
- Copy the redirect URI from the Reddit Cross Poster tab in your Wordpress dashboard and insert it into the Reddit developer application
- Paste the client ID and client secret from the Reddit developer application to the Wordpress dashboard
- Set the default subreddit or map required categories
- Post a new blog with the Auto Cross Post box checked in the sidebar to automatically post to Reddit

Most of the plugin is extremely self explanatory and the UI is user friendly with tooltips to help out. 

## Credits
This plugin has been forked from https://github.com/vestrainteractive/reddit-crosspost-plugin
It is updated and upgraded in the following ways:
- Much more user friendly UI
- Dynamic redirect url (no need to hardcode url before install)
- Solved issues with posting same thing 2-4 times
- Fixed OAuth not working in some installations
- Posts the featured image as an image post instead of just a link

## Future Development
This plugin is used in one of my active projects, so if it ever breaks I'll probably end up fixing it. 
Not exactly sure what additional features can be added, but I am happy to listen to suggestions and squash bugs if you find them! 
I'm quite new to making wordpress plugins, so please ignore any wordpress faux pas that may have been made
