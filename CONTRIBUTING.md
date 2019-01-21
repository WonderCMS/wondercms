## Contributing guidelines and rewards
1. Possible contributions should be compact/smart/clean in terms of code.
2. Make sure you have not created any vulnerabilities in the process of contributing new code/functionality.
3. Code must follow [PSR-2](https://www.php-fig.org/psr/psr-2/) formatting standard.
- Awesome solutions are rewarded with an honorable mention on the official [WonderCMS website](https://wondercms.com/special-contributors) and the [WonderCMS download page](https://wondercms.com/latest).
- If the WonderCMS donation fund isn't empty, contributors will be rewarded with a small donation as a token of appreciation.

### Core (index.php) contribution guidelines
1. Tested pull requests can be made to **dev** branch.
2. Ensure pull requests don't break backwards version compatibility.

### Theme contribution guidelines
1. Ensure your theme ZIP file can be installed through the WonderCMS theme installer (Settings->Themes & Plugins).
2. Ensure styles don't override the settings panel, unless it is wanted behaviour.
3. Do not to input hard coded values.
   - The users should not have to edit the theme.php to make it usable.
4. Include a simple file called **version**, which indicates the version of your theme (example: 1.0.0).
5. Create a release on GitHub. Example: github.com/yourUsername/yourThemeName/releases - change yourUsername to your actual GitHub username and yourThemeName to your theme name. Visit your completed URL to create a release.

### Plugin contributions guidelines
1. Ensure your plugin ZIP file can be installed through the WonderCMS theme installer (Settings->Themes & Plugins).
2. Ensure plugin doesn't cause incompatibility with other plugins.
3. Include a simple file called **version**, which indicates the version of your plugin (example: 1.0.0).
5. Create a release on GitHub. Example: github.com/yourUsername/yourPluginName/releases - change yourUsername to your actual GitHub username and yourPluginName to your actual plugin name. Visit your completed URL to create a release.

## Minification of assets
When editing the `.js` or `.css` files, you'll need to minify them before testing your changes, as the minified version is the one included in the pages.

For that you'll need to have [Yarn](https://yarnpkg.com/en/) installed. This tool allows you to install the javascript dependencies necessary for minification.

Once installed, execute those commands in the root directory of the project:

~~~bash
yarn install
grunt
~~~

Everytime you want to minify the files, just run `grunt`. Note: the minified files are commited to the source code tracking to avoid a build step to users, as this is one of the features of WonderCMS.
