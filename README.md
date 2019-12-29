# WonderCMS <sup> • 5 <sup>files</sup> • 20<sup>KB zip (one of the smallest CMS)</sup></sup>

[![Docs](https://img.shields.io/readthedocs/pip/stable.svg?longCache=true&v=100)](https://github.com/robiso/wondercms/wiki#wondercms-documentation)
[![Project ](https://img.shields.io/badge/project%20founded%20-%20in%202008-%25600?longCache=true&v=100)](https://www.wondercms.com/about)
![Maintained](https://img.shields.io/maintenance/yes/2019.svg?longCache=true)
[![License](https://img.shields.io/github/license/mashape/apistatus.svg?longCache=true)](https://github.com/robiso/wondercms/blob/master/license)
[![Number of downloads since first release on GitHub](https://img.shields.io/github/downloads/robiso/wondercms/total.svg?label=downloads%20since%202017&longCache=true)](https://github.com/robiso/wondercms/releases)
[![Donate](https://img.shields.io/badge/donate-PayPal-green.svg?longCache=true)](https://paypal.me/WonderCMS)

WonderCMS is an **extremely small** flat file CMS. It's fast, responsive and **doesn't require any configuration**.
- Provides a simple way for creating and editing websites.
- Includes features such as: **1-step install**, **1-click updates**, **1-click backups**, **theme/plugin installer** and many more.

### **[Demo](https://www.wondercms.com/demo) • [Download](https://www.wondercms.com/latest) • [Community](https://www.wondercms.com/community) • [News/Changelog](https://www.wondercms.com/whatsnew) • [Donate](https://www.wondercms.com/donate)**

## Small and simple flat file CMS
  - **No configuration needed - unzip and upload.**
  - 5 files: [database.js](https://github.com/robiso/wondercms/wiki/Default-database.js#default-databasejs) (JSON format), [index.php](https://github.com/robiso/wondercms/blob/master/index.php), [theme.php](https://github.com/robiso/wondercms/blob/master/themes/default/theme.php), [style.css](https://github.com/robiso/wondercms/blob/master/themes/default/css/style.css) and [htaccess](https://github.com/robiso/wondercms/blob/master/.htaccess).
    - Transferring your website to a new host/server is done by only copy/pasting all files.
 - Privacy oriented: no cookies, tracking or "powered by" links.
 - Includes plugins ([via hooks/listeners](https://github.com/robiso/wondercms/wiki/List-of-hooks)), [themes](https://github.com/robiso/wondercms/wiki/Create-theme-in-8-easy-steps), [backups](https://github.com/robiso/wondercms/wiki/Backup-all-files) installer, [1 click updates](https://github.com/robiso/wondercms/wiki/One-click-update).
 - Supports most server types (Apache, NGINX, IIS).
  - Project goal: keep it simple, tiny, hassle free (infrequent-ish 1 click updates).

## 1 step install
- Unzip and upload [latest version](https://www.wondercms.com/latest) to your server.

### Other install options
  - Option 2: Clone from GitHub: `git clone https://github.com/robiso/wondercms.git`
  - Option 3: [Get hosting with WonderCMS pre-installed](https://www.wondercms.com/latest)
  - Option 4: [Docker image](https://github.com/robiso/docker-wondercms)
  - Option 5: [Install with cPanel (and Softaculous) - video tutorial](https://www.youtube.com/watch?v=5tykBmKAUkA&feature=youtu.be&t=25)
  - Option 6: [Deploy on Microsoft Azure](https://azure.microsoft.com/en-gb/try/app-service/web/wondercms/?Language=php&Step=template)
     - Option 6a: [Watch video - installing WonderCMS on Microsoft Azure](https://channel9.msdn.com/Blogs/Open/A-PHP-CMS-in-the-cloud-no-signup-needed-in-2-minutes)

## Requirements
- PHP 7.2 or greater
  - cURL extension
  - mbstring extension
  - Zip extension
- mod_rewrite module
- any type of server (Apache, NGINX or IIS)

*For setting up WonderCMS on NGINX or IIS servers, there is one additional step required. Read more: [NGINX setup](https://github.com/robiso/wondercms/wiki/NGINX-server-config) or [IIS setup](https://github.com/robiso/wondercms/wiki/IIS-server-config).*

**WonderCMS works on most Apache servers/hosts (even free ones) by default.**

## Libraries used (6)
Libraries are loaded from Content Delivery Networks (CDNs) and include [SRI tags](https://github.com/robiso/wondercms/wiki/Add-SRI-tags-to-your-theme-libraries#3-steps-for-more-security).
- 3 libraries located in theme.php, always included:
  - <sup>`jquery.min.js (1.12.4)`, `bootstrap.min.js (3.3.7)`, `bootstrap.min.css (3.3.7)`.</sup>
- 3 libraries located in index.php, included only when logged in:
  - <sup>`autosize.min.js (4.0.2)`, `taboverride.min.js (4.0.3)`, `jquery.taboverride.min.js (4.0.0)`.</sup>

## Security features
- Track free and transparent - WonderCMS doesn't track users or store any personal cookies, there is only one session state cookie. Your WonderCMS installation is completely detached from WonderCMS servers. One click updates are pushed through GitHub.
- Supports HTTPS out of the box.
  - [Check how to turn on "improved security" mode](https://github.com/robiso/wondercms/wiki/Better-security-mode-(HTTPS-and-other-features)).
- All CSS and JS libraries include SubResource Integrity (SRI) tags. This prevents any changes to the libraries being loaded. If any changes are made, the libraries won't load for your and your visitors protection.
  - [Check how to add SRI tags to your custom theme](https://github.com/robiso/wondercms/wiki/Add-SRI-tags-to-your-theme-libraries#sri-subresource-integrity---3-steps-for-more-security). This step isn't necessary if you're using a theme from the official website.
- WonderCMS encourages you to change your default login URL. **Consider your custom login URL as your private username**.
  - Choosing a good login URL can prevent brute force attacks.
  - Your login page will always return a 404 header response. Search engines do not (and should not) cache your login URL.
- The admin password is hashed using PHP's `password_hash` and `password_verify`.
  - Choosing a strong password will prevent malicious actors from gaining any further admin access (if they would have guessed your login URL).
- WonderCMS includes CSRF verification tokens for each user action and additionally uses the hash_equals function to prevent CSRF token timing attacks.
- No known vulnerabilities.
   - Special thanks to yassineaddi, hypnito and other security researchers.

## Other features
 - no configuration required, unzip and upload
 - extremely fast
 - simple inline click and edit functionality
 - theme and plugin installer/updater
 - 1 click updates
 - 1 click backups
 - [easy to theme](https://github.com/robiso/wondercms/wiki/Create-theme-in-8-easy-steps)
 - [custom editable blocks](https://github.com/robiso/wondercms/wiki/Create-new-editable-areas-or-editable-blocks#difference-between-editable-blocks-and-editable-areas)
 - custom theme and plugin repositories
 - log of last 5 logged in IPs
 - file uploader
 - lightweight
 - responsive
 - clean URLs
 - custom homepage
 - menu reordering and visibility
   - note: hiding a page from the menu only hides it from the actual menu (and not from search engines)
 - highlighted current page in menu
 - custom 404 page
 - basic SEO support
   - custom title, keywords and description for each page
 - [optional] functions.php file for loading your custom code
   - note 1: functions.php file includes itself when you create it
   - note 2: the location of functions.php file should be inside the current active theme folder (same location as theme.php)

## List of donors
Also listed on the official [WonderCMS website](https://www.wondercms.com/donors). Thank you for the support!
- Martin King
- Darley Wilson
- Josef Kmínek
- Mikula Beutl
- Martin Jablonka
- Veselin Kamenarov
- Håkon Wium Lie (creator of CSS)
- Kenneth Rasmussen
- David G.
- Victor Onofrei
- Matthew
- James Campbell
- Kirsten Hogan
- Denis Volin
- Jonathan Jacks
- Bizibul
- Bikespain
- Aleksandr

## What to (or not to) expect from WonderCMS
- WonderCMS is meant to be a small gift to the internet and a simple alternative to website creating. It's 100% free and doesn't not include any "powered by" links.
- WonderCMS doesn't track users and is not interested in any user data.
- WonderCMS is not a fast-pace development project. Unless there is a critical vulnerability, updates will not be rushed.
- WonderCMS is meant to be extremely simple and will not be over-bloated with features.
  - Specific features are added only if the majority of the WonderCMS community signals a wanted change.
  - Pull requests are welcome and appreciated.
- To make WonderCMS sustainable and compact, a maximum number of 20 plugins and 50 themes will be supported.
  - Once this limit is reached in each category, a simple voting system will be established. Users will be free to vote for their favorite plugins and themes to ensure they stay in the top 20 and top 50 pool. Votes will be held on a 6-month basis/twice per year (subject to change).
  - The voting system comes in handy when users feel one of the top plugins or themes can be replaced by better ones with similar functionality or when a plugin/theme is no longer actively maintained.
  - This is a good way to ensure a small but good quality set of themes/plugins. The "top 10 and top 25" of each category will be easier to maintain and watch over by the whole community.
- WonderCMS doesn't include an "auto-update" feature.
  - In the unlikely event of this GitHub account being compromised, malicious actors would be able to deploy updates to all sites.
  - These type of malicious attacks are currently prevented with the built in one click updater. This minimizes possible damage as users are encouraged to review code before using the 1 click update, so no damage is done automatically.
  -  There is a possibility of an auto-update if/when WonderCMS establishes its own hosting platform.
- If you run into any issues when using WonderCMS, you can always expect someone to *try* to help you in the [WonderCMS community](https://www.wondercms.com/community).
  - Since WonderCMS is completely free and no one is paid to provide support, it's important to remain patient and respectful while asking for help.

## Links
#### Website links
- [Official website](https://www.wondercms.com)
- [News/Changelog](https://www.wondercms.com/whatsnew)
- [Community](https://www.wondercms.com/community)
- [Donate](https://www.wondercms.com/donate)
- [Donors Hall of Fame](https://www.wondercms.com/donors)
- [List of contributors](https://www.wondercms.com/special-contributors)
- [All WonderCMS related links](https://www.wondercms.com/links)

#### Social links
- [Twitter](https://twitter.com/wondercms)
- [Reddit](https://reddit.com/r/WonderCMS)

#### Github links
- [Docs](https://github.com/robiso/wondercms/wiki#wondercms-documentation)
   - [Common questions](https://github.com/robiso/wondercms/wiki#common-questions--help)
   - [List of common errors](https://github.com/robiso/wondercms/wiki/List-of-common-errors#troubleshooting-common-errors)
- [How to create a theme](https://github.com/robiso/wondercms/wiki/Create-theme-in-8-easy-steps)
- [How to create a plugin](https://github.com/robiso/wondercms/wiki/List-of-hooks)

#### Hosting and install tutorial links
- [Hosting with WonderCMS pre-installed](https://www.wondercms.com/hosting)
- [Install via cPanel - video tutorial](https://www.youtube.com/watch?v=5tykBmKAUkA&feature=youtu.be&t=25)
- [Deploy on Microsoft Azure](https://azure.microsoft.com/en-gb/try/app-service/web/wondercms/?Language=php&Step=template) ([2 minute Azure video tutorial](https://channel9.msdn.com/Blogs/Open/A-PHP-CMS-in-the-cloud-no-signup-needed-in-2-minutes))</sup>
