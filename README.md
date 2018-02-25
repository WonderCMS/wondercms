[![Docs](https://img.shields.io/readthedocs/pip/stable.svg?style=for-the-badge)](https://github.com/robiso/wondercms/wiki#wondercms-documentation) [![License](https://img.shields.io/github/license/mashape/apistatus.svg?style=for-the-badge)](https://github.com/robiso/wondercms/blob/master/license) [![Number of downloads since first release on GitHub](https://img.shields.io/github/downloads/robiso/wondercms/total.svg?style=for-the-badge)](https://github.com/robiso/wondercms)

# WonderCMS 2.4.2<sup>13kb zip, 45kb unzipped</sup>
Single user, simple, responsive, fast and small flat file CMS built with PHP and jQuery.
This project has been alive and kicking since 2008.

- 1 step install:  unzip and upload anywhere on server.
- Runs on less than [50 functions](https://github.com/robiso/wondercms/wiki/List-of-all-functions) and a couple hundred lines of code.
- 5 file structure: [database.js](https://github.com/robiso/wondercms/wiki/Default-database.js#default-databasejs) (JSON format), [index.php](https://github.com/robiso/wondercms/blob/master/index.php), [theme.php](https://github.com/robiso/wondercms/blob/master/themes/default/theme.php), [style.css](https://github.com/robiso/wondercms/blob/master/themes/default/css/style.css) and [htaccess](https://github.com/robiso/wondercms/blob/master/.htaccess).
- Supports plugins ([hooks/listeners](https://github.com/robiso/wondercms/wiki/List-of-hooks)), [themes](https://github.com/robiso/wondercms/wiki/Create-theme-in-8-easy-steps), [backups](https://github.com/robiso/wondercms/wiki/Backup-all-files), [1 click updates](https://github.com/robiso/wondercms/wiki/One-click-update).
- Project goal: keep it simple, tiny, hassle free (infrequent-ish 1 click updates).

## <sup>[Demo](https://www.wondercms.com/demo) • [Requirements](https://www.wondercms.com/requirements) • [Download](https://wondercms.com/latest) • [Community](https://wondercms.com/community) • [Themes](https://wondercms.com/themes) • [Plugins](https://wondercms.com/plugins) • [Donate](https://wondercms.com/donate) • [Changelog](https://wondercms.com/whatsnew)</sup>
<a href="https://www.wondercms.com" title="WonderCMS website"><img src="https://www.wondercms.com/WonderCMS-intro.png?v=5" alt="WonderCMS quick intro" /></a>

## Libraries (6)
Libraries are loaded from Content Delivery Networks (CDNs) and include [SRI tags](https://github.com/robiso/wondercms/wiki/Add-SRI-tags-to-your-theme-libraries#3-steps-for-more-security). SRI tags ensure that the content of these libraires hasn't changed. If the content of the libraries changes/gets hacked, they won't be loaded.
- 3 libraries located in theme.php, always included:
  - <sup>jquery.min.js (1.12.4), bootstrap.min.js (3.3.7), bootstrap.min.css (3.3.7).</sup>
- 3 libraries located in index.php, included only when logged in:
  - <sup>autosize.min.js (4.0.0), taboverride.min.js (4.0.3), jquery.taboverride.min.js (4.0.0).</sup>
  
## Features
 - no configuration required, unzip and upload
 - simple inline click and edit functionality
 - custom login URL
   - a good login URL prevents brute force attacks
   - search engines don't find/index your login URL as it always returns a 404 status
   - the login URL is your private username
 - admin password is hashed using PHP's password_hash and password_verify functions
 - theme and plugin installer/updater
 - 1 click update and backup
 - [easy to theme](https://github.com/robiso/wondercms/wiki/Create-theme-in-8-easy-steps)
 - file uploader
 - lightweight
 - responsive
 - clean URLs
 - custom homepage
 - menu reordering and visibility
 - highlighted current page in menu
 - custom 404 page
 - SEO support (custom title, keywords and description for each page)
 - optional functions.php file
   - includes itself when you create it
   - location of the functions.php should be inside the current active theme folder (same as theme.php)
 - no known vulnerabilities
   - special thanks to yassineaddi, hypnito and other security researchers

## Links
#### Website links
- [Official website](https://wondercms.com)
- [News/Changelog](https://wondercms.com/whatsnew)
- [Community](https://wondercms.com/community)
- [Donate](https://wondercms.com/donate)

#### Social links
- [Twitter](https://twitter.com/wondercms)
- [Reddit](https://reddit.com/r/WonderCMS)

#### Github links
- [Docs](https://github.com/robiso/wondercms/wiki#wondercms-documentation)
   - [Common questions](https://github.com/robiso/wondercms/wiki#common-questions--help)
   - [List of common errors](https://github.com/robiso/wondercms/wiki/List-of-common-errors#troubleshooting-common-errors)
- [Themes](https://github.com/robiso/wondercms-themes)
- [Plugins](https://github.com/robiso/wondercms-plugins)


<sub>NOTE: To make WonderCMS sustainable and prepared for the future, there is a maximum cap of 25 plugins and 25 themes.
Once this "25 limit" is reached in each category, a simple voting system will be established (suggestions welcome).
Users will be able to vote for their favorite plugins and themes to ensure they stay in the "chosen 25" pool.</sub>

<sub>The voting system could be used in situations where users feel one of the 25 plugins or themes can be replaced by a better one with similar functionality or when a plugin/theme is no longer actively maintained.</sub>

<sub>This is a good way to ensure a small and a good quality set of themes/plugins. The "25 chosen ones" of each category will be easier to maintain and watch over by the whole community.</sub>
