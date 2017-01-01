### WonderCMS demo
- https://wondercms.com/demo

### WonderCMS 1.2.0 beta download
WonderCMS is simple, small and secure. [You can download it here (GitHub release file - ZIP)](https://github.com/robiso/wondercms/releases/download/1.2.0-beta/WonderCMS-1.2.0-beta.zip).

### Installation
- Unzip and upload the files wherever you wish WonderCMS to be installed at.

### Requirements
 - PHP 5.5 or higher
 - .htaccess support

### Whats new in 1.2.0 beta
- custom functions.php file per theme - WonderCMS will automatically include your functions.php file if it exists in your themes folder (/themes/yourTheme/functions.php)
- added padding20 CSS class to the admin settings panel

### Features
 - no configuration required, unzip and upload
 - simple click and edit functionality
 - lightweight - runs on a couple hundred lines and 5 files
 - custom login URL
 - custom homepage
 - better password protection
 - highlighted current page
 - mobile responsive, easy to theme, 404 pages, clean URLs
 - page deleting easier than ever
 - better SEO support - custom title, keywords and description for each page
 - optional functions.php file - includes itself when you create it (the location of the functions.php should be inside your theme folder)
 - no known vulnerabilities - special thanks to yassineaddi, hypnito and other security researchers
 - made with PHP, jQuery, HTML, CSS and a flat JSON database

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
Upgrading from previous versions is not possible by rewriting the old version with the new one. To have the WonderCMS latest version with your current website content, you will have to:
 - Make a fresh installation of the latest WonderCMS somewhere on your server.
 - Paste your old content into the new installation.
 - Remove the old installation.
 - Move the new installation to the old WonderCMS installation location.

Future releases as of 1.1.0 are be backwards compatible by using the one click update functionality.

### Links
- WonderCMS website: https://wondercms.com/
- WonderCMS community: https://wondercms.com/forum
- WonderCMS Twitter: https://twitter.com/wondercms
- WonderCMS donations: https://wondercms.com/donate
- Special contributors: https://wondercms.com/special-contributors
- WonderCMS themes repository: https://github.com/robiso/wondercms-themes
- WonderCMS plugins repository: https://github.com/robiso/wondercms-plugins
