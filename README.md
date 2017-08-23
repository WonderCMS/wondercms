# WonderCMS 2.3.1  • [Demo](https://www.wondercms.com/demo) • [Download](https://github.com/robiso/wondercms/releases/download/2.3.1/WonderCMS-2.3.1.zip)

<a href="https://www.wondercms.com" title="WonderCMS website"><img src="https://www.wondercms.com/WonderCMS-intro.png?v=2" alt="WonderCMS intro" /></a>

### Installation
- unzip and upload the files wherever you wish WonderCMS to be installed

or

- clone from GitHub

### Requirements
1. PHP 5.5 or higher (cURL, mb_string, zip/unzip extensions required - usually installed by default)
2. htaccess support (or in case of NGINX or  IIS, editing one file is required instead of htaccess support - links below)

#### What's new in 2.3.0 + 2.3.1 patch
- re-designed settings panel
- theme installer + updater + remover
- plugin installer + updater + remover
- file uploader + remover
- tab/indentation support
- additional security token checks
- "Visit page" link next to each page in menu
- added success message when deleting a page
- logout link moved to top right corner
- fixed title case when creating new pages
- files autosize.js, taboverride.min.js and taboverride.jquery.min.js are now loaded after the admin is logged in - resulting in faster website loading
- additional token verifications
- minor code logic fixes
- minor text fixes
- added two additional checks if the request for token is set (2.3.1 patch)
- double space removal / converted to tabs (2.3.1 patch)

What's new history: https://wondercms.com/whatsnew

### Features
 - no configuration required, unzip and upload
 - simple click and edit functionality
 - one click update and backup
 - lightweight - runs on a couple hundred lines of code and 5 files
 - easy plugin/theme install and update
 - custom login URL
 - custom homepage
 - better password protection
 - highlighted current page
 - mobile responsive, easy to theme, 404 pages, clean URLs
 - easy page creating and deleting
 - better SEO support - custom title, keywords and description for each page
 - optional functions.php file - includes itself when you create it (the location of the functions.php should be inside your theme folder)
 - no known vulnerabilities - special thanks to yassineaddi, hypnito, and other security researchers

### WonderCMS works by default on Apache. To run WonderCMS on NGINX or IIS, editing of 1 file is required
- NGINX 1 step instructions - https://github.com/robiso/wondercms/wiki/NGINX-server-config
- IIS 1 step instructions - https://github.com/robiso/wondercms/wiki/IIS-server-config

### If any errors occur (500 internal server error), change all file permissions to 644 and all folder permissions to 755.

### How to update from older versions?
- Updating from 1.1.0+
  - Use the one click update from your WonderCMS settings panel.

- Updating from 1.0.0
  - Replace your old index.php with the new one from the above download.

- Updating from 1.0.0 and older
  - Backup all your WonderCMS files.
  - Make a fresh installation of the latest WonderCMS anywhere on your server.
  - Copy your old content and paste it into the new installation.
  - Remove the old installation.
  - Move the new installation to the old WonderCMS installation location.

### Links
- WonderCMS website: https://wondercms.com
- WonderCMS community: https://wondercms.com/forum
- WonderCMS documentation: https://github.com/robiso/wondercms/wiki
- WonderCMS Twitter: https://twitter.com/wondercms
- WonderCMS donations: https://wondercms.com/donate
- WonderCMS themes repository: https://github.com/robiso/wondercms-themes
- WonderCMS plugins repository: https://github.com/robiso/wondercms-plugins
