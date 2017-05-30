# WonderCMS 2.1.0  • [Demo](https://www.wondercms.com/demo) • [Download](https://github.com/robiso/wondercms/releases/download/2.1.0/WonderCMS-2.1.0.zip)

<a href="https://www.wondercms.com" title="WonderCMS website"><img src="https://www.wondercms.com/WonderCMS-intro.png?v=2" alt="WonderCMS intro" /></a>

### Installation
- unzip and upload the files wherever you wish WonderCMS to be installed

or

- clone from GitHub

### Requirements
 - PHP 5.5 or higher
 - .htaccess support

#### What's new in 2.1.0
1. New page functionality
	1a. Easy page adding and hiding | thanks to Pascal Jordin.
	1b. Easy page re-ordering | thanks to Pascal Jordin.
	1c. Cleaner URLs | another huge thanks to Pascal Jordin.
2. Improved URL function | thanks to Luka Mrovlje.
3. Minor code improvements.
- Additional thanks to turboblack (Dannis Danylenko) for all the testing.

See what was new in previous versions: https://wondercms.com/whatsnew

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

### WonderCMS works by default on Apache and Windows IIS. To make it work with NGINX, put the following code into your NGINX server config:
```
location ~ database.js {
	return 403;
}

autoindex off;

location / {
	if (!-e $request_filename) {
		rewrite ^/(.+)$ /index.php?page=$1 last;
	}
}
```

### If any errors occur (500 internal server error), correct file permissions to 644 and folder permissions to 755. You can do this manually or with the short script below (added by Bill Carson)
  - `find ./ -type d -exec chmod 755 {} \;`
  - `find ./ -type f -exec chmod 644 {} \;`

### How to update from older versions?
- Updating from 1.1.0+ - use the one click update from your WonderCMS settings panel.
- Updating from 1.0.0 - replace your old index.php with the new one from the above download.

- Updating from 1.0.0 and older
 - Backup all your WonderCMS files.
 - Make a fresh installation of the latest WonderCMS anywhere on your server.
 - Copy your old content and paste it into the new installation.
 - Remove the old installation.
 - Move the new installation to the old WonderCMS installation location.

Updating is really easy with our click updater - included in versions 1.1.0 and above.

### Links
- WonderCMS website: https://wondercms.com
- WonderCMS community: https://wondercms.com/forum
- WonderCMS documentation: https://github.com/robiso/wondercms/wiki
- WonderCMS Twitter: https://twitter.com/wondercms
- WonderCMS donations: https://wondercms.com/donate
- WonderCMS themes repository: https://github.com/robiso/wondercms-themes
- WonderCMS plugins repository: https://github.com/robiso/wondercms-plugins
