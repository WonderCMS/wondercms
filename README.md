### WonderCMS 1.1.0 beta
WonderCMS is simple, small and secure. [You can download it here (GitHub release ZIP file)](https://github.com/robiso/wondercms/releases/download/1.1.0-beta/WonderCMS-1.1.0-beta.zip).

The smallest flat file CMS which enables you to create a website in seconds.

### Whats new
- **WonderCMS one click update functionality** - thanks to Yassine Addi
- fixed bug - removed issue of the characters (b, r, < and >) being trimmed in the menu list - thanks to Yassine Addi
- additional plugins available as standalone downloads

### WonderCMS demo
- https://wondercms.com/demo

### Installation
1.  Unzip.
2.  Upload the files wherever you wish WonderCMS to be installed at.

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

### Requirements
 - PHP 5.5 or higher
 - .htaccess support

### WonderCMS community
- https://wondercms.com/forum/

### Features
 - better plugin support + working WYSIWYG editor (available as a standalone plugin)
 - simple click and edit functionality
 - no configuration required, unzip and upload
 - lightweight - runs on less than 500 lines of code and has less than 10 files
 - simplified code
 - custom login URL
 - custom homepage
 - rebuilt mostly from scratch + MIT license
 - better password protection
 - no other known vulnerabilities (special thanks to yassineaddi and hypnito)
 - highlighted current page
 - mobile responsive, easy to theme, 404 pages, clean URLs
 - page deleting easier than ever
 - better SEO support (title, keywords and description for each page)
 - (optional) functions.php file includes itself when you create it
 - made with PHP, jQuery, HTML, CSS and a flat JSON database

### How to update from older versions?
Upgrading from previous versions is not possible by rewriting the old version with the new one. To have the WonderCMS latest version with your current website content, you will have to:
 - Make a fresh installation of the latest WonderCMS somewhere on your server.
 - Paste your old content into the new installation.
 - Remove the old installation.
 - Move the new installation to the old WonderCMS installation location.

Future releases of WonderCMS will be backwards compatible.

### Links
- WonderCMS website: https://wondercms.com/
- WonderCMS community: https://wondercms.com/forum
- WonderCMS Twitter: https://twitter.com/wondercms
- WonderCMS donations: https://wondercms.com/donate
- Special contributors: https://wondercms.com/special-contributors
- WonderCMS themes repository: https://github.com/robiso/wondercms-themes
- WonderCMS plugins repository: https://github.com/robiso/wondercms-plugins
