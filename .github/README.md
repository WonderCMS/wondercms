<h1 align="center">
<a href="https://wondercms.com" target="_blank" title="Official WonderCMS website">
    <img src="https://github.com/WonderCMS/wondercms-cdn-files/blob/main/logo.svg?raw=true" alt="WonderCMS logo" title="WonderCMS" align="center" height="150" />
</a>
 <br>WonderCMS - small flat file CMS<br>
    <sup>5 files • ∼50KB zip - 1 step install</sup>
</h1>

<p align="center">
<a href="https://github.com/sponsors/robiso">
    <img src="https://img.shields.io/badge/sponsor-30363D?style=for-the-badge&logo=GitHub-Sponsors&logoColor=#white" />
  </a><br>
<a href="https://wondercms.com/docs"><img src="https://img.shields.io/readthedocs/pip/stable.svg?longCache=true&amp;v=100" alt="Docs" data-canonical-src="https://img.shields.io/readthedocs/pip/stable.svg?longCache=true&amp;v=100" style="max-width:100%;"></a>
<a href="https://www.wondercms.com/about" rel="nofollow"><img src="https://img.shields.io/badge/project%20founded%20-%20in%202008-%25600?longCache=true&amp;v=100" alt="Project " data-canonical-src="https://img.shields.io/badge/project%20founded%20-%20in%202008-%25600?longCache=true&amp;v=100" style="max-width:100%;"></a>
<a target="_blank" rel="noopener noreferrer" href="https://camo.githubusercontent.com/21e44ed76cd7c7d861a97c415edebdc421e8fcee/68747470733a2f2f696d672e736869656c64732e696f2f6d61696e74656e616e63652f7965732f323032302e7376673f6c6f6e6743616368653d74727565"><img src="https://img.shields.io/maintenance/yes/2025.svg?longCache=true" alt="Maintained" data-canonical-src="https://img.shields.io/maintenance/yes/2024.svg?longCache=true" style="max-width:100%;"></a>
<a href="https://github.com/WonderCMS/wondercms/blob/master/license"><img src="https://img.shields.io/github/license/mashape/apistatus.svg?longCache=true" alt="License" data-canonical-src="https://img.shields.io/github/license/mashape/apistatus.svg?longCache=true" style="max-width:100%;"></a>
<a href="https://paypal.me/WonderCMS" rel="nofollow"><img src="https://img.shields.io/badge/donate-PayPal-11AABB.svg?longCache=true" alt="Donate" data-canonical-src="https://img.shields.io/badge/donate-PayPal-11AABB.svg?longCache=true" style="max-width:100%;"></a>
</p>

<p align="center">WonderCMS is an <b>extremely small</b> flat file CMS. It's fast, responsive and <b>doesn't require any configuration</b>.</p>

<p align="center"> It provides a simple way for creating and editing websites.</li>
    <br />Includes features such as: <b>1-step install</b>, <b>1-click updates</b>, <b>1-click backups</b>, <b>theme/plugin installer</b> and much more.
</p>

## <div align="center">**[Demo](https://www.wondercms.com/demo) • [Download](https://www.wondercms.com/latest) • [Community](https://www.wondercms.com/community) • [News](https://www.wondercms.com/news) • [Donate](https://www.wondercms.com/donate)**  • [Buy merch](https://swag.wondercms.com)</div>


[![What is WonderCMS? Introduction](https://www.wondercms.com/data/files/wondercms-introduction.png)](https://www.youtube.com/watch?v=gtkoi9X1L3g)


## Small and simple flat file CMS
  - **No configuration needed - unzip and upload.**
  - 5 files: [database.js](https://www.wondercms.com/docs/#default-generated-database) (JSON format), [index.php](https://github.com/WonderCMS/wondercms/blob/master/index.php), [theme.php](https://github.com/WonderCMS/wondercms/blob/master/themes/sky/theme.php), [style.css](https://github.com/WonderCMS/wondercms/blob/master/themes/sky/css/style.css) and [htaccess](https://github.com/WonderCMS/wondercms/blob/master/.htaccess).
    - Transferring your website to a new host/server is done by only copy/pasting all files (no additional configuration/migration)
 - Privacy oriented: no cookies, tracking or "powered by" links.
 - Includes plugins ([via hooks/listeners](https://www.wondercms.com/docs/#hooks)), [themes](https://www.wondercms.com/docs/#themes)/plugins installer, [backups](https://www.wondercms.com/docs/#backup-and-restore), [1 click updates](https://www.wondercms.com/docs/#do-and-dont).
 - Supports most server types (Apache, NGINX, IIS, Caddy).
  - Project goal: keep it simple, tiny, hassle free (infrequent-ish 1 click updates).

<br>

## 1 step install
- Unzip and upload [latest version](https://www.wondercms.com/latest) to your server.

<br>

### Other install options
  - Option 2: Clone from GitHub: `git clone https://github.com/WonderCMS/wondercms.git`
  - Option 3: [Get hosting with WonderCMS pre-installed](https://www.wondercms.com/hosting)
  - Option 4: [Docker image](https://github.com/robiso/docker-wondercms)
  - Option 5: [Install with cPanel (and Softaculous) - video tutorial](https://www.youtube.com/watch?v=5tykBmKAUkA&feature=youtu.be&t=25)

<br>

## Requirements
- PHP 7.4 or greater
  - cURL extension
  - mbstring extension
  - Zip extension
- mod_rewrite module
- any type of server (Apache, NGINX, IIS, Caddy)

*For setting up WonderCMS on NGINX or IIS servers, there is one additional step required. Read more: [NGINX setup](https://www.wondercms.com/docs/#serverConfigs) or [IIS setup](https://www.wondercms.com/docs/#serverConfigs).*

**WonderCMS works on most Apache servers/hosts (even free ones) by default.**

<br>

## Libraries used (3)
- 3 libraries located in index.php, **included only when admin is logged in**:
  - <sup>`wcms-admin.min.js`, `autosize.min.js (4.0.2)`, `taboverride.min.js (4.0.3)`.</sup>

Note: Some plugins also include other libraries such as jQuery, default WonderCMS out-of-the box includes only the above libraries through CDNs.

<br>

## Security features
- Track free and transparent - WonderCMS doesn't track users or store any personal cookies, there is only one session state cookie.
- Your WonderCMS installation is completely detached from WonderCMS servers. One click updates are pushed through GitHub.
- Supports HTTPS out of the box.
  - [Check how to further improve security](https://www.wondercms.com/docs/#security-settings)).
- All CSS and JS libraries include SubResource Integrity (SRI) tags. This prevents any changes to the libraries being loaded. If any changes are made, the libraries won't load for your and your visitors protection.
- WonderCMS encourages you to change your default login URL. **Consider your custom login URL as your private username**.
  - Choosing a good login URL can prevent brute force attacks.
  - Your login page will always return a 404 header response. Search engines do not (and should not) cache your login URL.
- The admin password is hashed using PHP's `password_hash` and `password_verify`.
  - Choosing a strong password will prevent malicious actors from gaining any further admin access (if they would have guessed your login URL).
- WonderCMS includes CSRF verification tokens for each user action and additionally uses the hash_equals function to prevent CSRF token timing attacks.
- No known vulnerabilities.
   - Special thanks to yassineaddi, hypnito and other security researchers.

<br>

## Other features
 - no configuration required, unzip and upload
 - extremely fast
 - subpages
 - simple inline click and edit functionality
 - theme and plugin installer/updater
 - 1 click updates
 - 1 click backups
 - [easy to theme](https://www.wondercms.com/docs/#themes)
 - [custom editable blocks](https://www.wondercms.com/docs/#editable-blocks)
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

<br>

## List of donors
Also listed on the official [WonderCMS website](https://www.wondercms.com/donors). Thank you for supporting WonderCMS!
- Håkon Wium Lie (also the creator of CSS)
- Tjaša Jelačič (BigSheep)
- Otis Schmakel
- Mohamad Hegazy
- Ulf Bro
- Kim Fajdiga
- John Greene
- Sara Stojanovski
- Peter Černuta
- Jasmina Fabiani
- Primož Cankar
- Andraž Zvonar
- Martin Jablonka
- Martin King
- Ben Gilbey
- Darley Wilson
- Josef Kmínek
- Mikula Beutl
- David Bojanovič
- Kenneth Rasmussen
- Victor Onofrei
- Matthev
- Veselin Kamenarov
- James Campbell
- Kirsten Hogan
- Denis Volin
- Jonathan Jacks
- Bizibul
- Bikespain
- Aleksandr
- Impavid Pty Ltd
- Mohamad Hegazy
- Happy Monsters Studios
- Derek (Random Fandom Media Group)
- Paweł Krużel
- Netroid
- Fabian Winder
- Václav Piták

<br>

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

<br>

## Links
#### Website
- [Official website](https://www.wondercms.com)
- [News/Changelog](https://www.wondercms.com/news)
- [Donate](https://www.wondercms.com/donate)
- [Get merch](https://swag.wondercms.com)
- [Donors Hall of Fame](https://www.wondercms.com/donors)
- [List of contributors](https://www.wondercms.com/contributors)
- [All WonderCMS related links](https://www.wondercms.com/links)


#### Community
- [Community](https://www.wondercms.com/community)

#### Social
- [Discord](https://discord.gg/2MVubVBCry)
- [Twitter](https://twitter.com/wondercms)
- [Reddit](https://reddit.com/r/WonderCMS)

#### Hosting and install tutorial
- [Hosting with WonderCMS pre-installed](https://www.wondercms.com/hosting)
- [Install via cPanel - video tutorial](https://www.youtube.com/watch?v=5tykBmKAUkA&feature=youtu.be&t=25)


#### Documentation
- [Docs](https://wondercms.com/docs)
