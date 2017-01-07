# WonderCMS - https://www.wondercms.com
WonderCMS is very simple and lightweight flat file CMS made with PHP, jQuery, HTML, CSS and a flat JSON database.
Runs on 5 files and a couple hundred lines of code.

### Demo
- https://wondercms.com/demo

### Installation
- Unzip and upload the files wherever you wish WonderCMS to be installed at.

### Download
[Download ZIP from GitHub](https://github.com/robiso/wondercms/releases/download/1.2.0-beta/WonderCMS-1.2.0-beta.zip).

### Requirements
 - PHP 5.5 or higher
 - .htaccess support

### Community, themes and plugins
- https://wondercms.com/forum

### Whats new in 1.2.0 beta
- custom functions.php file per theme - WonderCMS will automatically include your functions.php file if it exists in your themes folder (/themes/yourTheme/functions.php)
- added padding20 CSS class to the admin settings panel

### Features
 - no configuration required, unzip and upload
 - simple click and edit functionality
 - lightweight - runs on a couple hundred lines of code and 5 files
 - custom login URL
 - custom homepage
 - better password protection
 - highlighted current page
 - mobile responsive, easy to theme, 404 pages, clean URLs
 - easy page creating and deleting
 - better SEO support - custom title, keywords and description for each page
 - optional functions.php file - includes itself when you create it (the location of the functions.php should be inside your theme folder)
 - no known vulnerabilities - special thanks to yassineaddi, hypnito and other security researchers

### WonderCMS works by default on Apache. To make it work with NGINX, put the following code into your NGINX server config:
```
location ~ database.js {
	return 403;
}

autoindex off;

location / {
	if (!-e $request_filename) {
		rewrite ^(.+)$ /index.php?page=$1 break;
	}
}
```

### If any errors occur, please correct file permissions to 644 and folder permissions to 755. You can do this manually or with the script below (added by Bill Carson)
  - `find ./ -type d -exec chmod 755 {} \;`
  - `find ./ -type f -exec chmod 644 {} \;`

### How to update from older versions?
Updating from 1.1.0+ versions: use the one click update from your WonderCMS settings panel.
Updating from version 1.0.0: replace your old index.php with the new one from the above download.
Updating from previous versions - 1.0.0 and older:
 - Backup all your WonderCMS files.
 - Make a fresh installation of the latest WonderCMS anywhere on your server.
 - Paste your old content into the new installation.
 - Remove the old installation.
 - Move the new installation to the old WonderCMS installation location.

Future releases as of 1.1.0 are be backwards compatible by using the one click updater.

### Links
- WonderCMS website: https://wondercms.com
- WonderCMS community: https://wondercms.com/forum
- WonderCMS documentation: https://www.wondercms.com/forum/viewforum.php?f=27
- WonderCMS Twitter: https://twitter.com/wondercms
- WonderCMS donations: https://wondercms.com/donate
- WonderCMS themes repository: https://github.com/robiso/wondercms-themes
- WonderCMS plugins repository: https://github.com/robiso/wondercms-plugins
