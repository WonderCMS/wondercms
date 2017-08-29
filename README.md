# WonderCMS 2.3.1
## [Demo](https://www.wondercms.com/demo) • [Download](https://github.com/robiso/wondercms/releases/download/2.3.1/WonderCMS-2.3.1.zip) • [Documentation](https://github.com/robiso/wondercms/wiki#wondercms-documentation) • [Themes](https://github.com/robiso/wondercms-themes#list-of-approved-themes) • [Plugins](https://github.com/robiso/wondercms-plugins#approved-plugins)

<a href="https://www.wondercms.com" title="WonderCMS website"><img src="https://www.wondercms.com/WonderCMS-intro.png?v=2" alt="WonderCMS intro" /></a>

### Installation (1 step)
- unzip and upload anywhere you wish to install WonderCMS

### Requirements
- PHP 5.5 or higher
  - cURL extension
  - mbstring extension
  - ZipArchive extension
- htaccess support (on Apache)
  - have NGINX instead of Apache? [Use this NGINX server config](https://github.com/robiso/wondercms/wiki/NGINX-server-config)
  - have IIS instead of Apache? [Use this IIS server config](https://github.com/robiso/wondercms/wiki/IIS-server-config)

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

### If any errors occur (500 internal server error), change all file permissions to 644 and all folder permissions to 755.

### Links
- [WonderCMS website](https://wondercms.com)
- [Community](https://wondercms.com/forum)
- [Twitter](https://twitter.com/wondercms)
- [Donate](https://wondercms.com/donate)
