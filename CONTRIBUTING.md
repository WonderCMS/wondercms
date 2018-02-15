# General guidelines for contributing
1. Possible contributions should be compact/smart/clean in terms of code.
2. Make sure you have not created any vulnerabilities (unintentionally) in the process of contributing new code/functionality.

## Contributing to the core (index.php)
1. Tested pull requests can be made directly to the master branch.
2. Ensure pull requests don't break backwards version compatibility.

## Theme contribution guidelines
1. Ensure theme ZIP file can be installed from Settings->Themes & Plugins.
2. Ensure styles don't override the settings panel, unless it is wanted behaviour for.
3. Do not to input hard coded values.
   - The users should not have to edit the theme.php to make it 100% usable.
4. Include a simple file called **version**, which indicates the version of your theme (example: 1.0.0).
5. Create a release on GitHub. (github.com/yourUsername/yourThemeName/releases - change yourUsername to your username and yourThemeName to your theme name. Visit the URL and a release can be created).

## Plugin contributions guidelines
1. Ensure plugin ZIP file can be installed from Settings->Themes & Plugins.
2. Ensure plugin doesn't cause incompatibility with other plugins.
3. Include a simple file called **version**. which indicates the version of your plugin (example: 1.0.0).
5. Create a release on GitHub. (github.com/yourUsername/yourPluginName/releases - change yourUsername to your username and yourPluginName to your plugin name. Visit the URL and a release can be created).

## Contribution attribution/rewards
- Awesome solutions are rewarded with a honorable mention on the official WonderCMS website - https://wondercms.com/special-contributors and the WonderCMS download page https://wondercms.com/latest.

- When the WonderCMS donation fund isn't empty, we always and gladly reward contributors with a small donation as a token of appreciation.
