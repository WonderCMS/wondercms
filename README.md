[![Docs](https://img.shields.io/readthedocs/pip/stable.svg?style=for-the-badge)](https://github.com/robiso/wondercms/wiki#wondercms-documentation) ![Number of downloads since first release on GitHub](https://img.shields.io/github/downloads/robiso/wondercms/total.svg?style=for-the-badge) ![Maintaned](https://img.shields.io/maintenance/yes/2018.svg?style=for-the-badge) [![License](https://img.shields.io/github/license/mashape/apistatus.svg?style=for-the-badge)](https://github.com/robiso/wondercms/blob/master/license)

# WonderCMS 2.4.2 <sup>13<sup>KB zipped,</sup> 45<sup>KB unzipped</sup></sup>
## <sup>[Demo](https://www.wondercms.com/demo) • [Download](https://www.wondercms.com/latest) • [Requirements](https://www.wondercms.com/requirements) • [Community](https://www.wondercms.com/community) • [Themes](https://www.wondercms.com/themes) • [Plugins](https://www.wondercms.com/plugins) • [Changelog](https://www.wondercms.com/whatsnew) • [Donate](https://www.wondercms.com/donate) / [Patreon](https://www.wondercms.com/patron)</sup>

Single user, simple, responsive, fast and small flat file CMS built with PHP and jQuery. Alive and kicking since 2008.

- **1 step install:  unzip and upload anywhere on server.**
- Runs on less than [50 functions](https://github.com/robiso/wondercms/wiki/List-of-all-functions) and a couple hundred lines of code.
- 5 file structure: [database.js](https://github.com/robiso/wondercms/wiki/Default-database.js#default-databasejs) (JSON format), [index.php](https://github.com/robiso/wondercms/blob/master/index.php), [theme.php](https://github.com/robiso/wondercms/blob/master/themes/default/theme.php), [style.css](https://github.com/robiso/wondercms/blob/master/themes/default/css/style.css) and [htaccess](https://github.com/robiso/wondercms/blob/master/.htaccess).
- Supports plugins ([hooks/listeners](https://github.com/robiso/wondercms/wiki/List-of-hooks)), [themes](https://github.com/robiso/wondercms/wiki/Create-theme-in-8-easy-steps), [backups](https://github.com/robiso/wondercms/wiki/Backup-all-files), [1 click updates](https://github.com/robiso/wondercms/wiki/One-click-update).
- Project goal: keep it simple, tiny, hassle free (infrequent-ish 1 click updates).

### <sup>[Hosting with WonderCMS pre-installed](https://www.wondercms.com/hosting) • [Install via cPanel video tutorial](https://www.youtube.com/watch?v=5tykBmKAUkA&feature=youtu.be&t=25) • [Deploy on Microsoft Azure](https://azure.microsoft.com/en-gb/try/app-service/web/wondercms/?Language=php&Step=template) ([2 minute video tutorial](https://channel9.msdn.com/Blogs/Open/A-PHP-CMS-in-the-cloud-no-signup-needed-in-2-minutes))</sup>

<a href="https://www.wondercms.com" title="WonderCMS website"><img src="https://www.wondercms.com/WonderCMS-intro.png?v=5" alt="WonderCMS quick intro" /></a>


## Libraries (6)
Libraries are loaded from Content Delivery Networks (CDNs) and include [SRI tags](https://github.com/robiso/wondercms/wiki/Add-SRI-tags-to-your-theme-libraries#3-steps-for-more-security). SRI tags ensure that the content of these libraires hasn't changed. If the content of the libraries changes, they won't be loaded (to protect you and your visitors).
- 3 libraries located in theme.php, always included:
  - <sup>jquery.min.js (1.12.4), bootstrap.min.js (3.3.7), bootstrap.min.css (3.3.7).</sup>
- 3 libraries located in index.php, included only when logged in:
  - <sup>autosize.min.js (4.0.0), taboverride.min.js (4.0.3), jquery.taboverride.min.js (4.0.0).</sup>
  
## Features
 - no configuration required, unzip and upload
 - simple inline click and edit functionality
 - theme and plugin installer/updater
 - 1 click update and backup
 - custom login URL
   - a good login URL prevents brute force attacks
   - search engines don't find/index your login URL as it's set to always return a 404 status
   - the login URL is your private username
 - admin password is hashed using PHP's password_hash and password_verify functions
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
 - CSRF tokens and hash_equals function applied to each token check, prevents malicious redirects and token guessing
 - no tracking
 - no known vulnerabilities
   - special thanks to yassineaddi, hypnito and other security researchers

## Links
#### Website links
- [Official website](https://www.wondercms.com)
- [News/Changelog](https://www.wondercms.com/whatsnew)
- [Community](https://www.wondercms.com/community)
- [Donate](https://www.wondercms.com/donate)
- [Donors and patrons Hall of Fame](https://www.wondercms.com/donors)


#### Social links
- [Twitter](https://twitter.com/wondercms)
- [Reddit](https://reddit.com/r/WonderCMS)

#### Github links
- [Docs](https://github.com/robiso/wondercms/wiki#wondercms-documentation)
   - [Common questions](https://github.com/robiso/wondercms/wiki#common-questions--help)
   - [List of common errors](https://github.com/robiso/wondercms/wiki/List-of-common-errors#troubleshooting-common-errors)
- [Themes](https://github.com/robiso/wondercms-themes)
- [Plugins](https://github.com/robiso/wondercms-plugins)

## What to (or not to) expect from WonderCMS
- WonderCMS is meant to be a small gift to the world and a really simple alternative to website creating.
- WonderCMS is 100% free and will not include or require any "powered by" links.
- WonderCMS is not a fast-pace development project. Unless there is a critical vulnerability, there is no point in rushing updates.
- WonderCMS is meant to be extremely simple and will not be over-bloated with features.
  - Specific features are added only if the majority of the WonderCMS community signals a wanted change.
  - Pull requests are welcome and appreciated.
- To make WonderCMS sustainable and compact, we will support 25 awesome plugins and 25 themes.
  - Once this "25 limit" is reached in each category, a simple voting system will be established. Users will be free to vote for their favorite plugins and themes to ensure they stay in the "chosen 25" pool. Votes will be held on a 6-month basis/twice per year (subject to change).
  - The voting system comes in handy when users feel one of the 25 plugins or themes can be replaced by a better one with similar functionality or when a plugin/theme is no longer actively maintained.
  - This is a good way to ensure a small but good quality set of themes/plugins. The "25 chosen ones" of each category will be easier to maintain and watch over by the whole community.
- WonderCMS doesn't track users and is not interested in any user data.
- WonderCMS doesn't include an "auto-update" feature.
  - In the unlikely event of this GitHub account being compromised, hackers would be able to deploy updates to all sites simultaneously.
  - These type of malicious attacks are currently prevented with the built in one click updater. This minimizes possible damage as users are encouraged to review code before using the 1 click update, so in theory, no damage is done automatically.
- If any issues arise when trying to use WonderCMS, you can always expect someone to *try* to help you in the [WonderCMS community](https://www.wondercms.com/community).
  - Since WonderCMS is completely free and nobody is paid to provide support, it's important to remain patient and respectful while asking for help.
