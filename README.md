[![License](https://img.shields.io/github/license/mashape/apistatus.svg?style=for-the-badge)](https://github.com/robiso/wondercms/blob/master/license)  [![Docs](https://img.shields.io/readthedocs/pip/stable.svg?style=for-the-badge)](https://github.com/robiso/wondercms/wiki#wondercms-documentation)


# WonderCMS 2.4.2
Simple, responsive and small flat file CMS built with PHP. Runs on less than [50 functions](https://github.com/robiso/wondercms/wiki/List-of-all-functions) and 1000 lines of code.

- 5 file structure: [database.js](https://github.com/robiso/wondercms/wiki/Default-database.js#default-databasejs) (JSON database), [index.php](https://github.com/robiso/wondercms/blob/master/index.php), [theme.php](https://github.com/robiso/wondercms/blob/master/themes/default/theme.php), [style.css](https://github.com/robiso/wondercms/blob/master/themes/default/css/style.css) and [htaccess](https://github.com/robiso/wondercms/blob/master/.htaccess).

## <sup>[Demo](https://www.wondercms.com/demo) • [Download](https://wondercms.com/latest) • [Community](https://wondercms.com/community) • [Themes](https://wondercms.com/themes) • [Plugins](https://wondercms.com/plugins) • [Donate](https://wondercms.com/donate) • [Changelog](https://wondercms.com/whatsnew)</sup>

- <sub>Libraries (6):</sub>
   - <sub>jquery.min.js (1.12.4), bootstrap.min.js (3.3.7), bootstrap.min.css (3.3.7) - located in theme.php, always included.</sub>
   - <sub>autosize.min.js (4.0.0), taboverride.min.js (4.0.3), jquery.taboverride.min.js (4.0.0) located in index.php, included only when logged in.</sub>
- <sub>Supports plugins ([hooks/listeners](https://github.com/robiso/wondercms/wiki/List-of-hooks)), [themes](https://github.com/robiso/wondercms/wiki/Create-theme-in-8-easy-steps), [backups](https://github.com/robiso/wondercms/wiki/Backup-all-files), [1 click updates](https://github.com/robiso/wondercms/wiki/One-click-update).</sub>
- <sub>Project goal: keep it simple, tiny, hassle free (infrequent-ish 1 click updates).</sub>

## 1 step install - unzip and upload anywhere on server

<a href="https://www.wondercms.com" title="WonderCMS website"><img src="https://www.wondercms.com/WonderCMS-intro.png?v=5" alt="WonderCMS quick intro" /></a>

## Requirements
WonderCMS works on most hosting packages (and **even on some free hosting providers**).
- PHP 5.5 or higher
  - cURL extension (for local servers, install a certificate to avoid [the persistent "Update" message error](https://github.com/robiso/wondercms/wiki/Persistent-%22New-WonderCMS-update-available%22-message))
  - mbstring extension
  - ZipArchive extension
- htaccess Apache (for NGINX or IIS, check [these 1 step changes](https://github.com/robiso/wondercms/wiki/One-step-install#additional-steps-for-nginx-and-iis))

## Features
 - no configuration required, unzip and upload
 - simple click and edit functionality
 - theme and plugin installer
 - 1 click update and backup
 - easy to theme
 - lightweight, runs on a couple hundred lines of code and 5 files
 - responsive
 - clean URLs
 - custom login URL
 - custom homepage
 - highlighted current page in menu
 - custom 404 page
 - SEO support (custom title, keywords and description for each page)
 - optional functions.php file - includes itself when you create it (the location of the functions.php should be inside the current active theme folder)
 - no known vulnerabilities - special thanks to yassineaddi, hypnito, and other security researchers

## Links
#### Website links
- [Official website](https://wondercms.com)
- [Community](https://wondercms.com/forum)
- [Donate](https://wondercms.com/donate)
- [News/Changelog](https://wondercms.com/whatsnew)

#### Social links
- [Twitter](https://twitter.com/wondercms)
- [Reddit](https://reddit.com/r/WonderCMS)

#### Github links
- [Docs](https://github.com/robiso/wondercms/wiki#wondercms-documentation)
   - [Common questions](https://github.com/robiso/wondercms/wiki#common-questions--help)
   - [List of common errors](https://github.com/robiso/wondercms/wiki/List-of-common-errors#troubleshooting-common-errors)
- [Themes](https://github.com/robiso/wondercms-themes)
- [Plugins](https://github.com/robiso/wondercms-plugins)


<sub>NOTE: To make this project sustainable for the future to come, there is a maximum cap of 25 plugins and 25 themes.
Once this "25 limit" is reached in each category, a simple voting system will be established.
Users will be able to vote for their favorite plugins and themes to ensure they stay in the "chosen 25" pool.</sub>

<sub>The voting system could be used in situations where users feel one of the 25 plugins or themes can be replaced by a better one with similar functionality, or when a plugin/theme is no longer actively maintained.</sub>

<sub>This is a good way to ensure we have a small and quality set of themes/plugins. The "25 chosen ones" of each category (themes and plugins) will be easier to maintain and watch over.</sub>
