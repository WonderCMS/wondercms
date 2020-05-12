<?php
/**
 * @package WonderCMS
 * @author Robert Isoski
 * @see https://www.wondercms.com
 * @license MIT
 */

session_start();
define('VERSION', '3.0.7');
mb_internal_encoding('UTF-8');

if (defined('PHPUNIT_TESTING') === false) {
	$Wcms = new Wcms();
	$Wcms->init();
	$Wcms->render();
}

class Wcms
{
	private const THEMES_DIR = 'themes';
	private const PLUGINS_DIR = 'plugins';
	private const VALID_DIRS = [self::THEMES_DIR, self::PLUGINS_DIR];
	private const THEME_PLUGINS_TYPES = [
		'installs' => 'install',
		'updates' => 'update',
		'exists' => 'exist',
	];

	/** @var int MIN_PASSWORD_LENGTH minimum number of characters */
	public const MIN_PASSWORD_LENGTH = 8;

	/** @var string WCMS_REPO - repo URL */
	public const WCMS_REPO = 'https://raw.githubusercontent.com/robiso/wondercms/master/';

	/** @var string WCMS_CDN_REPO - CDN repo URL */
	public const WCMS_CDN_REPO = 'https://raw.githubusercontent.com/robiso/wondercms-cdn-files/master/';

	/** @var string $currentPage - current page */
	public $currentPage = '';

	/** @var bool $currentPageExists - check if current page exists */
	public $currentPageExists = false;

	/** @var object $db - content of database.js */
	protected $db;

	/** @var bool $loggedIn - check if admin is logged in */
	public $loggedIn = false;

	/** @var array $listeners for hooks */
	public $listeners = [];

	/** @var string $dataPath path to data folder */
	public $dataPath;

	/** @var string $themesPluginsCachePath path to cached json file with Themes/Plugins data */
	protected $themesPluginsCachePath;

	/** @var string $dbPath path to database.js */
	protected $dbPath;

	/** @var string $filesPath path to uploaded files */
	public $filesPath;

	/** @var string $rootDir root dir of the install (where index.php is) */
	public $rootDir;

	/** @var bool $headerResponseDefault read default header response */
	public $headerResponseDefault = true;

	/** @var string $headerResponse header status */
	public $headerResponse = 'HTTP/1.0 200 OK';

	/**
	 * Constructor
	 *
	 * @param string $dataFolder
	 * @param string $filesFolder
	 * @param string $dbName
	 * @param string $rootDir
	 * @throws Exception
	 */
	public function __construct(
		string $dataFolder = 'data',
		string $filesFolder = 'files',
		string $dbName = 'database.js',
		string $rootDir = __DIR__
	) {
		$this->rootDir = $rootDir;
		$this->setPaths($dataFolder, $filesFolder, $dbName);
		$this->db = $this->getDb();
		$this->loggedIn = $this->get('config', 'loggedIn');
	}

	/**
	 * Setting default paths
	 *
	 * @param string $dataFolder
	 * @param string $filesFolder
	 * @param string $dbName
	 */
	public function setPaths(
		string $dataFolder = 'data',
		string $filesFolder = 'files',
		string $dbName = 'database.js'
	): void {
		$this->dataPath = sprintf('%s/%s', $this->rootDir, $dataFolder);
		$this->dbPath = sprintf('%s/%s', $this->dataPath, $dbName);
		$this->filesPath = sprintf('%s/%s', $this->dataPath, $filesFolder);
		$this->themesPluginsCachePath = sprintf('%s/%s', $this->dataPath, 'cache.json');
	}

	/**
	 * Init function called on each page load
	 *
	 * @return void
	 * @throws Exception
	 */
	public function init(): void
	{
		$this->pageStatus();
		$this->loginStatus();
		$this->logoutAction();
		$this->loginAction();
		$this->notFoundResponse();
		$this->loadPlugins();
		if ($this->get('config', 'loggedIn')) {
			$this->manuallyRefreshCacheData();
			$this->addCustomThemePluginRepository();
			$this->installUpdateThemePluginAction();
			$this->changePasswordAction();
			$this->deleteFileThemePluginAction();
			$this->changePageThemeAction();
			$this->backupAction();
			$this->betterSecurityAction();
			$this->deletePageAction();
			$this->saveAction();
			$this->updateAction();
			$this->uploadFileAction();
			$this->notifyAction();
		}
	}

	/**
	 * Display the HTML. Called after init()
	 * @return void
	 */
	public function render(): void
	{
		header($this->headerResponse);

		// Alert admin that page is hidden
		if ($this->get('config', 'loggedIn')) {
			$loadingPage = null;
			foreach ($this->get('config', 'menuItems') as $item) {
				if ($this->currentPage === $item->slug) {
					$loadingPage = $item;
				}
			}
			if ($loadingPage && $loadingPage->visibility === 'hide') {
				$this->alert('info',
					'This page (' . $this->currentPage . ') is currently hidden from the menu. <a data-toggle="modal" href="#settingsModal" data-target-tab="#menu"><b>Open menu visibility settings</b></a>');
			}
		}

		$this->loadThemeAndFunctions();
	}

	/**
	 * Function used by plugins to add a hook
	 *
	 * @param string $hook
	 * @param callable $functionName
	 */
	public function addListener(string $hook, callable $functionName): void
	{
		$this->listeners[$hook][] = $functionName;
	}

	/**
	 * Add alert message for admin
	 *
	 * @param string $class see bootstrap alerts classes
	 * @param string $message the message to display
	 * @param bool $sticky can it be closed?
	 * @return void
	 */
	public function alert(string $class, string $message, bool $sticky = false): void
	{
		if (isset($_SESSION['alert'][$class])) {
			foreach ($_SESSION['alert'][$class] as $v) {
				if ($v['message'] === $message) {
					return;
				}
			}
		}
		$_SESSION['alert'][$class][] = ['class' => $class, 'message' => $message, 'sticky' => $sticky];
	}

	/**
	 * Display alert message to the admin
	 * @return string
	 */
	public function alerts(): string
	{
		if (!isset($_SESSION['alert'])) {
			return '';
		}
		$output = '';
		$output .= '<div class="alertWrapper">';
		foreach ($_SESSION['alert'] as $alertClass) {
			foreach ($alertClass as $alert) {
				$output .= '<div class="alert alert-'
					. $alert['class']
					. (!$alert['sticky'] ? ' alert-dismissible' : '')
					. '">'
					. (!$alert['sticky'] ? '<button type="button" class="close" data-dismiss="alert">&times;</button>' : '')
					. $alert['message']
					. '</div>';
			}
		}
		$output .= '</div>';
		unset($_SESSION['alert']);
		return $output;
	}

	/**
	 * Get an asset (returns URL of the asset)
	 *
	 * @param string $location
	 * @return string
	 */
	public function asset(string $location): string
	{
		return self::url('themes/' . $this->get('config', 'theme') . '/' . $location);
	}

	/**
	 * Backup whole WonderCMS installation
	 *
	 * @return void
	 * @throws Exception
	 */
	public function backupAction(): void
	{
		if (!$this->get('config', 'loggedIn')) {
			return;
		}
		$backupList = glob($this->filesPath . '/*-backup-*.zip');
		if (!empty($backupList)) {
			$this->alert('danger',
				'Backup files detected. <a data-toggle="modal" href="#settingsModal" data-target-tab="#files"><b>View and delete unnecessary backup files</b></a>');
		}
		if (isset($_POST['backup']) && $this->verifyFormActions()) {
			$this->zipBackup();
		}
	}

	/**
	 * Replace the .htaccess with one adding security settings
	 * @return void
	 */
	public function betterSecurityAction(): void
	{
		if (isset($_POST['betterSecurity']) && $this->verifyFormActions()) {
			if ($_POST['betterSecurity'] === 'on') {
				if ($contents = $this->getFileFromRepo('htaccess-ultimate', self::WCMS_CDN_REPO)) {
					file_put_contents('.htaccess', trim($contents));
				}
				$this->alert('success', 'Improved security turned ON.');
				$this->redirect();
			} elseif ($_POST['betterSecurity'] === 'off') {
				if ($contents = $this->getFileFromRepo('htaccess', self::WCMS_CDN_REPO)) {
					file_put_contents('.htaccess', trim($contents));
				}
				$this->alert('success', 'Improved security turned OFF.');
				$this->redirect();
			}
		}
	}

	/**
	 * Get a static block
	 *
	 * @param string $key name of the block
	 * @return string
	 */
	public function block(string $key): string
	{
		$blocks = $this->get('blocks');
		$content = '';

		if (isset($blocks->{$key})) {
			$content = $this->get('config', 'loggedIn')
				? $this->editable($key, $blocks->{$key}->content, 'blocks')
				: $blocks->{$key}->content;
		}
		return $this->hook('block', $content, $key)[0];
	}

	/**
	 * Change password
	 * @return void
	 */
	public function changePasswordAction(): void
	{
		if (isset($_POST['old_password'], $_POST['new_password'])
			&& $_SESSION['token'] === $_POST['token']
			&& $this->get('config', 'loggedIn')
			&& $this->hashVerify($_POST['token'])) {
			if (!password_verify($_POST['old_password'], $this->get('config', 'password'))) {
				$this->alert('danger',
					'Wrong password. <a data-toggle="modal" href="#settingsModal" data-target-tab="#security"><b>Re-open security settings</b></a>');
				$this->redirect();
			}
			if (strlen($_POST['new_password']) < self::MIN_PASSWORD_LENGTH) {
				$this->alert('danger',
					sprintf('Password must be longer than %d characters. <a data-toggle="modal" href="#settingsModal" data-target-tab="#security"><b>Re-open security settings</b></a>',
						self::MIN_PASSWORD_LENGTH));
				$this->redirect();
			}
			$this->set('config', 'password', password_hash($_POST['new_password'], PASSWORD_DEFAULT));
			$this->alert('success', 'Password changed. Please log in again.');
			$this->set('config', 'forceLogout', true);
			$this->logoutAction(true);
		}
	}

	/**
	 * Check if we can run WonderCMS properly
	 * Executed once before creating the database file
	 *
	 * @param string $folder the relative path of the folder to check/create
	 * @return void
	 * @throws Exception
	 */
	public function checkFolder(string $folder): void
	{
		if (!is_dir($folder) && !mkdir($folder, 0755) && !is_dir($folder)) {
			throw new Exception('Could not create data folder.');
		}
		if (!is_writable($folder)) {
			throw new Exception('Could write to data folder.');
		}
	}

	/**
	 * Initialize the JSON database if doesn't exist
	 * @return void
	 */
	public function createDb(): void
	{
		// Check php requirements
		$this->checkMinimumRequirements();
		$password = $this->generatePassword();
		$this->db = (object)[
			'config' => [
				'siteTitle' => 'Website title',
				'theme' => 'default',
				'defaultPage' => 'home',
				'login' => 'loginURL',
				'loggedIn' => false,
				'forceLogout' => false,
				'password' => password_hash($password, PASSWORD_DEFAULT),
				'lastLogins' => [],
				'defaultRepos' => [
					'themes' => [],
					'plugins' => [],
					'lastSync' => null,
				],
				'customRepos' => [
					'themes' => [],
					'plugins' => []
				],
				'menuItems' => [
					'0' => [
						'name' => 'Home',
						'slug' => 'home',
						'visibility' => 'show'
					],
					'1' => [
						'name' => 'Example',
						'slug' => 'example',
						'visibility' => 'show'
					]
				]
			],
			'pages' => [
				'404' => [
					'title' => '404',
					'keywords' => '404',
					'description' => '404',
					'content' => '<h1>Sorry, page not found. :(</h1>'
				],
				'home' => [
					'title' => 'Home',
					'keywords' => 'Keywords, are, good, for, search, engines',
					'description' => 'A short description is also good.',
					'content' => '<h1>It\'s alive!</h1>

<h4><a href="' . self::url('loginURL') . '">Click here to login.</a> Your password is: <b>' . $password . '</b></a></h4>'
				],
				'example' => [
					'title' => 'Example',
					'keywords' => 'Keywords, are, good, for, search, engines',
					'description' => 'A short description is also good.',
					'content' => '<h1 class="mb-3">Editing is easy</h1>
<p>Click anywhere to edit and click outside the area to save. Changes are shown immediately.</p>
<p>There are more options in the Settings.</p>

<h2 class="mt-5 mb-3">Creating new pages</h2>
<p>Pages can be created easily in the Settings, Menu tab.</p>


<h2 class="mt-5 mb-3">Installing themes and plugins</h2>
<p>By opening the Settings panel, you can install, update or remove themes or plugins.</p>
<p>A simple editor can be found in the plugins section which makes editing even easier.</p>'
				]
			],
			'blocks' => [
				'subside' => [
					'content' => '<h2>About your website</h2>

<br>
<p>Website description, contact form, mini map or anything else.</p>
<p>This blue editable area is visible on all pages.</p>'
				],
				'footer' => [
					'content' => '&copy;' . date('Y') . ' Your website'
				]
			]
		];
		$this->save();
	}

	/**
	 * Create menu item
	 *
	 * @param string $content
	 * @param string $menu
	 * @param string $visibility show or hide
	 * @return void
	 * @throws Exception
	 */
	public function createMenuItem(string $content, string $menu, string $visibility = 'hide'): void
	{
		$conf = 'config';
		$field = 'menuItems';
		$exist = is_numeric($menu);
		$content = empty($content) ? 'empty' : str_replace([PHP_EOL, '<br>'], '', $content);
		$slug = $this->slugify($content);
		$menuCount = count(get_object_vars($this->get($conf, $field)));

		$db = $this->getDb();
		foreach ($db->config->{$field} as $value) {
			if ($value->slug === $slug) {
				$slug .= '-' . $menuCount;
				break;
			}
		}
		if (!$exist) {
			$this->set($conf, $field, $menuCount, new StdClass);
			$this->set($conf, $field, $menuCount, 'name', str_replace('-', ' ', $content));
			$this->set($conf, $field, $menuCount, 'slug', $slug);
			$this->set($conf, $field, $menuCount, 'visibility', $visibility);
			if ($menu) {
				$this->createPage($slug);
				$_SESSION['redirect_to_name'] = $content;
				$_SESSION['redirect_to'] = $slug;
			}
		} else {
			$oldSlug = $this->get($conf, $field, $menu, 'slug');
			$this->set($conf, $field, $menu, 'name', $content);
			$this->set($conf, $field, $menu, 'slug', $slug);
			$this->set($conf, $field, $menu, 'visibility', $visibility);

			$oldPageContent = $this->get('pages', $oldSlug);
			$this->unset('pages', $oldSlug);
			$this->set('pages', $slug, $oldPageContent);
			$this->set('pages', $slug, 'title', $content);
			if ($this->get('config', 'defaultPage') === $oldSlug) {
				$this->set('config', 'defaultPage', $slug);
			}
		}
	}

	/**
	 * Create new page
	 *
	 * @param string $slug the name of the page in URL
	 * @return void
	 * @throws Exception
	 */
	public function createPage($slug = ''): void
	{
		$this->db->pages->{$slug ?: $this->currentPage} = new stdClass;
		$this->save();
		$pageName = $slug ?: $this->slugify($this->currentPage);
		$this->set('pages', $pageName, 'title', (!$slug)
			? mb_convert_case(str_replace('-', ' ', $this->currentPage), MB_CASE_TITLE)
			: mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE));
		$this->set('pages', $pageName, 'keywords',
			'Keywords, are, good, for, search, engines');
		$this->set('pages', $pageName, 'description',
			'A short description is also good.');
		if (!$slug) {
			$this->createMenuItem($this->slugify($this->currentPage), '');
		}
	}

	/**
	 * Load CSS and enable plugins to load CSS
	 * @return string
	 */
	public function css(): string
	{
		if ($this->get('config', 'loggedIn')) {
			$styles = <<<'EOT'
<style>

/*!
 * Bootstrap v3.3.7 (http://getbootstrap.com)
 * Copyright 2011-2016 Twitter, Inc.
 * Licensed under MIT (https://github.com/twbs/bootstrap/blob/master/LICENSE)
 */

#adminPanel .fade-scale>div {
    -webkit-transform: scale(1);
    -moz-transform: scale(1);
    -ms-transform: scale(1);
    transform: scale(1);
    -webkit-transition: all .3s;
    -moz-transition: all .3s;
    transition: all .3s
}

#adminPanel .fade-scale.in>div {
    opacity: 1;
    transform: scale(5)
}

.settingsIcon {
    background-image: url("data:image/svg+xml,%3Csvg aria-hidden='true' focusable='false' data-prefix='fas' data-icon='cog' role='img' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512' %3E%3Cpath fill='%23ffffff' d='M487.4 315.7l-42.6-24.6c4.3-23.2 4.3-47 0-70.2l42.6-24.6c4.9-2.8 7.1-8.6 5.5-14-11.1-35.6-30-67.8-54.7-94.6-3.8-4.1-10-5.1-14.8-2.3L380.8 110c-17.9-15.4-38.5-27.3-60.8-35.1V25.8c0-5.6-3.9-10.5-9.4-11.7-36.7-8.2-74.3-7.8-109.2 0-5.5 1.2-9.4 6.1-9.4 11.7V75c-22.2 7.9-42.8 19.8-60.8 35.1L88.7 85.5c-4.9-2.8-11-1.9-14.8 2.3-24.7 26.7-43.6 58.9-54.7 94.6-1.7 5.4.6 11.2 5.5 14L67.3 221c-4.3 23.2-4.3 47 0 70.2l-42.6 24.6c-4.9 2.8-7.1 8.6-5.5 14 11.1 35.6 30 67.8 54.7 94.6 3.8 4.1 10 5.1 14.8 2.3l42.6-24.6c17.9 15.4 38.5 27.3 60.8 35.1v49.2c0 5.6 3.9 10.5 9.4 11.7 36.7 8.2 74.3 7.8 109.2 0 5.5-1.2 9.4-6.1 9.4-11.7v-49.2c22.2-7.9 42.8-19.8 60.8-35.1l42.6 24.6c4.9 2.8 11 1.9 14.8-2.3 24.7-26.7 43.6-58.9 54.7-94.6 1.5-5.5-.7-11.3-5.6-14.1zM256 336c-44.1 0-80-35.9-80-80s35.9-80 80-80 80 35.9 80 80-35.9 80-80 80z'%3E%3C/path%3E%3C/svg%3E");
    display: block;
    width: 1em;
    height: 1em;
    float: left;
    padding: 8px 8px 11px 17px;
    background-repeat: no-repeat;
    margin: 1px 1px 1px -2px
}

.logoutIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-box-arrow-right' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M11.646 11.354a.5.5 0 010-.708L14.293 8l-2.647-2.646a.5.5 0 01.708-.708l3 3a.5.5 0 010 .708l-3 3a.5.5 0 01-.708 0z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M4.5 8a.5.5 0 01.5-.5h9a.5.5 0 010 1H5a.5.5 0 01-.5-.5z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M2 13.5A1.5 1.5 0 01.5 12V4A1.5 1.5 0 012 2.5h7A1.5 1.5 0 0110.5 4v1.5a.5.5 0 01-1 0V4a.5.5 0 00-.5-.5H2a.5.5 0 00-.5.5v8a.5.5 0 00.5.5h7a.5.5 0 00.5-.5v-1.5a.5.5 0 011 0V12A1.5 1.5 0 019 13.5H2z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: block;
    width: 1.3em;
    height: 1.3em;
    float: left;
    background-repeat: no-repeat
}

.animationLoader {
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 45 45' xmlns='http://www.w3.org/2000/svg' stroke='%2311aabb'%3E%3Cg fill='none' fill-rule='evenodd' transform='translate(1 1)' stroke-width='2'%3E%3Ccircle cx='22' cy='22' r='6' stroke-opacity='0'%3E%3Canimate attributeName='r' begin='1.5s' dur='3s' values='6;22' calcMode='linear' repeatCount='indefinite' /%3E%3Canimate attributeName='stroke-opacity' begin='1.5s' dur='3s' values='1;0' calcMode='linear' repeatCount='indefinite' /%3E%3Canimate attributeName='stroke-width' begin='1.5s' dur='3s' values='2;0' calcMode='linear' repeatCount='indefinite' /%3E%3C/circle%3E%3Ccircle cx='22' cy='22' r='6' stroke-opacity='0'%3E%3Canimate attributeName='r' begin='3s' dur='3s' values='6;22' calcMode='linear' repeatCount='indefinite' /%3E%3Canimate attributeName='stroke-opacity' begin='3s' dur='3s' values='1;0' calcMode='linear' repeatCount='indefinite' /%3E%3Canimate attributeName='stroke-width' begin='3s' dur='3s' values='2;0' calcMode='linear' repeatCount='indefinite' /%3E%3C/circle%3E%3Ccircle cx='22' cy='22' r='8'%3E%3Canimate attributeName='r' begin='0s' dur='1.5s' values='6;1;2;3;4;5;6' calcMode='linear' repeatCount='indefinite' /%3E%3C/circle%3E%3C/g%3E%3C/svg%3E");
    width: 5em;
    height: 5em;
    background-repeat: no-repeat;
    display: block;
    text-align: center;
    margin: 0 auto
}

.editIcon{
background-image:url("data:image/svg+xml,%3Csvg class='bi bi-pencil-square' viewBox='0 0 16 16' stroke='%23555555' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M15.502 1.94a.5.5 0 010 .706L14.459 3.69l-2-2L13.502.646a.5.5 0 01.707 0l1.293 1.293zm-1.75 2.456l-2-2L4.939 9.21a.5.5 0 00-.121.196l-.805 2.414a.25.25 0 00.316.316l2.414-.805a.5.5 0 00.196-.12l6.813-6.814z'/%3E%3Cpath fill-rule='evenodd' d='M1 13.5A1.5 1.5 0 002.5 15h11a1.5 1.5 0 001.5-1.5v-6a.5.5 0 00-1 0v6a.5.5 0 01-.5.5h-11a.5.5 0 01-.5-.5v-11a.5.5 0 01.5-.5H9a.5.5 0 000-1H2.5A1.5 1.5 0 001 2.5v11z' clip-rule='evenodd'/%3E%3C/svg%3E");
background-repeat:no-repeat;
width: 1.5em;
height: 1.5em;
display: inline-block;
margin: 10px 0px 0px -4px;
float: left;
}
.editIcon a, a:active, a:focus {
outline: none !important;
}

#save,
.loader-overlay {
    color: #ccc;
    left: 0;
    width: 100%;
    height: 100%;
    display: none;
    position: fixed;
    text-align: center;
    padding-top: 100px;
    background: rgba(51, 51, 51, .8);
    z-index: 2448
}

#adminPanel .deleteIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-trash' width='1em' height='1em' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M5.5 5.5A.5.5 0 016 6v6a.5.5 0 01-1 0V6a.5.5 0 01.5-.5zm2.5 0a.5.5 0 01.5.5v6a.5.5 0 01-1 0V6a.5.5 0 01.5-.5zm3 .5a.5.5 0 00-1 0v6a.5.5 0 001 0V6z'/%3E%3Cpath fill-rule='evenodd' d='M14.5 3a1 1 0 01-1 1H13v9a2 2 0 01-2 2H5a2 2 0 01-2-2V4h-.5a1 1 0 01-1-1V2a1 1 0 011-1H6a1 1 0 011-1h2a1 1 0 011 1h3.5a1 1 0 011 1v1zM4.118 4L4 4.059V13a1 1 0 001 1h6a1 1 0 001-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: block;
    width: 1.1em;
    height: 1.1em;
    float: left;
    background-repeat: no-repeat
}

#adminPanel .deleteIconInButton {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-trash' width='1em' height='1em' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M5.5 5.5A.5.5 0 016 6v6a.5.5 0 01-1 0V6a.5.5 0 01.5-.5zm2.5 0a.5.5 0 01.5.5v6a.5.5 0 01-1 0V6a.5.5 0 01.5-.5zm3 .5a.5.5 0 00-1 0v6a.5.5 0 001 0V6z'/%3E%3Cpath fill-rule='evenodd' d='M14.5 3a1 1 0 01-1 1H13v9a2 2 0 01-2 2H5a2 2 0 01-2-2V4h-.5a1 1 0 01-1-1V2a1 1 0 011-1H6a1 1 0 011-1h2a1 1 0 011 1h3.5a1 1 0 011 1v1zM4.118 4L4 4.059V13a1 1 0 001 1h6a1 1 0 001-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: block;
    width: 1.1em;
    height: 1.1em;
    float: left;
    background-repeat: no-repeat;
    margin: 4px 6px 0 0
}

#adminPanel .eyeShowIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-eye' viewBox='0 0 16 16' fill='%2311aabb' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.134 13.134 0 001.66 2.043C4.12 11.332 5.88 12.5 8 12.5c2.12 0 3.879-1.168 5.168-2.457A13.134 13.134 0 0014.828 8a13.133 13.133 0 00-1.66-2.043C11.879 4.668 10.119 3.5 8 3.5c-2.12 0-3.879 1.168-5.168 2.457A13.133 13.133 0 001.172 8z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M8 5.5a2.5 2.5 0 100 5 2.5 2.5 0 000-5zM4.5 8a3.5 3.5 0 117 0 3.5 3.5 0 01-7 0z' clip-rule='evenodd'/%3E%3C/svg%3E");
    width: 1.4em;
    height: 1.4em;
    float: right;
    background-repeat: no-repeat;
    padding: 10px!important;
    margin-top: 5px
}

#adminPanel .eyeHideIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-eye-slash'  viewBox='0 0 16 16' fill='%23aaaaaa' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 00-2.79.588l.77.771A5.944 5.944 0 018 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0114.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z'/%3E%3Cpath d='M11.297 9.176a3.5 3.5 0 00-4.474-4.474l.823.823a2.5 2.5 0 012.829 2.829l.822.822zm-2.943 1.299l.822.822a3.5 3.5 0 01-4.474-4.474l.823.823a2.5 2.5 0 002.829 2.829z'/%3E%3Cpath d='M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 001.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 018 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709z'/%3E%3Cpath fill-rule='evenodd' d='M13.646 14.354l-12-12 .708-.708 12 12-.708.708z' clip-rule='evenodd'/%3E%3C/svg%3E");
    width: 1.4em;
    height: 1.4em;
    float: right;
    background-repeat: no-repeat;
    padding: 10px!important;
    margin-top: 5px
}

#adminPanel .addNewIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-plus-circle' width='1em' height='1em' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M8 3.5a.5.5 0 01.5.5v4a.5.5 0 01-.5.5H4a.5.5 0 010-1h3.5V4a.5.5 0 01.5-.5z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M7.5 8a.5.5 0 01.5-.5h4a.5.5 0 010 1H8.5V12a.5.5 0 01-1 0V8z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M8 15A7 7 0 108 1a7 7 0 000 14zm0 1A8 8 0 108 0a8 8 0 000 16z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: inline-block;
    width: 1.2em;
    height: 1.2em;
    background-repeat: no-repeat;
    margin: -2px 4px -4px 0
}

#adminPanel .checkmarkIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-check' width='1em' height='1em' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M13.854 3.646a.5.5 0 010 .708l-7 7a.5.5 0 01-.708 0l-3.5-3.5a.5.5 0 11.708-.708L6.5 10.293l6.646-6.647a.5.5 0 01.708 0z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: inline-block;
    width: 1.2em;
    height: 1.2em;
    background-repeat: no-repeat;
    margin: -2px 4px -2px 0
}

#adminPanel .installIcon,
.alertWrapper .installIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-download' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M.5 8a.5.5 0 01.5.5V12a1 1 0 001 1h12a1 1 0 001-1V8.5a.5.5 0 011 0V12a2 2 0 01-2 2H2a2 2 0 01-2-2V8.5A.5.5 0 01.5 8z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M5 7.5a.5.5 0 01.707 0L8 9.793 10.293 7.5a.5.5 0 11.707.707l-2.646 2.647a.5.5 0 01-.708 0L5 8.207A.5.5 0 015 7.5z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M8 1a.5.5 0 01.5.5v8a.5.5 0 01-1 0v-8A.5.5 0 018 1z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: inline-block;
    width: 1.5em;
    height: 1.5em;
    background-repeat: no-repeat;
    margin: 0 8px -6px 0
}

#adminPanel .refreshIcon,
.alertWrapper .refreshIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-arrow-repeat' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M2.854 7.146a.5.5 0 00-.708 0l-2 2a.5.5 0 10.708.708L2.5 8.207l1.646 1.647a.5.5 0 00.708-.708l-2-2zm13-1a.5.5 0 00-.708 0L13.5 7.793l-1.646-1.647a.5.5 0 00-.708.708l2 2a.5.5 0 00.708 0l2-2a.5.5 0 000-.708z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M8 3a4.995 4.995 0 00-4.192 2.273.5.5 0 01-.837-.546A6 6 0 0114 8a.5.5 0 01-1.001 0 5 5 0 00-5-5zM2.5 7.5A.5.5 0 013 8a5 5 0 009.192 2.727.5.5 0 11.837.546A6 6 0 012 8a.5.5 0 01.501-.5z' clip-rule='evenodd'/%3E%3C/svg%3E");
    width: 1.5em;
    height: 1.5em;
    background-repeat: no-repeat;
    margin: 0 8px -6px 0;
    float: left
}

#adminPanel .lockIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-lock' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M11.5 8h-7a1 1 0 00-1 1v5a1 1 0 001 1h7a1 1 0 001-1V9a1 1 0 00-1-1zm-7-1a2 2 0 00-2 2v5a2 2 0 002 2h7a2 2 0 002-2V9a2 2 0 00-2-2h-7zm0-3a3.5 3.5 0 117 0v3h-1V4a2.5 2.5 0 00-5 0v3h-1V4z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: inline-block;
    width: 1.2em;
    height: 1.2em;
    background-repeat: no-repeat;
    margin: -2px
}

#adminPanel .uploadIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-upload' width='1em' height='1em' viewBox='0 0 16 16' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M.5 8a.5.5 0 01.5.5V12a1 1 0 001 1h12a1 1 0 001-1V8.5a.5.5 0 011 0V12a2 2 0 01-2 2H2a2 2 0 01-2-2V8.5A.5.5 0 01.5 8zM5 4.854a.5.5 0 00.707 0L8 2.56l2.293 2.293A.5.5 0 1011 4.146L8.354 1.5a.5.5 0 00-.708 0L5 4.146a.5.5 0 000 .708z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M8 2a.5.5 0 01.5.5v8a.5.5 0 01-1 0v-8A.5.5 0 018 2z' clip-rule='evenodd'/%3E%3C/svg%3E");
    display: inline-block;
    width: 1.2em;
    height: 1.2em;
    background-repeat: no-repeat;
    margin: -2px 7px -3px 0
}

#adminPanel .linkIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-link-45deg' width='1em' height='1em' viewBox='0 0 16 16' fill='%23aaaaaa' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M4.715 6.542L3.343 7.914a3 3 0 104.243 4.243l1.828-1.829A3 3 0 008.586 5.5L8 6.086a1.001 1.001 0 00-.154.199 2 2 0 01.861 3.337L6.88 11.45a2 2 0 11-2.83-2.83l.793-.792a4.018 4.018 0 01-.128-1.287z'/%3E%3Cpath d='M5.712 6.96l.167-.167a1.99 1.99 0 01.896-.518 1.99 1.99 0 01.518-.896l.167-.167A3.004 3.004 0 006 5.499c-.22.46-.316.963-.288 1.46z'/%3E%3Cpath d='M6.586 4.672A3 3 0 007.414 9.5l.775-.776a2 2 0 01-.896-3.346L9.12 3.55a2 2 0 012.83 2.83l-.793.792c.112.42.155.855.128 1.287l1.372-1.372a3 3 0 00-4.243-4.243L6.586 4.672z'/%3E%3Cpath d='M10 9.5a2.99 2.99 0 00.288-1.46l-.167.167a1.99 1.99 0 01-.896.518 1.99 1.99 0 01-.518.896l-.167.167A3.004 3.004 0 0010 9.501z'/%3E%3C/svg%3E");
    width: 1.2em;
    height: 1.2em;
    background-repeat: no-repeat;
    padding: 3px 3px 0 12px;
    margin-top: 5px;
    display: inline-block
}

#adminPanel .upArrowIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-arrow-up-short' viewBox='0 0 16 16' fill='%23aaaaaa' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M8 5.5a.5.5 0 01.5.5v5a.5.5 0 01-1 0V6a.5.5 0 01.5-.5z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M7.646 4.646a.5.5 0 01.708 0l3 3a.5.5 0 01-.708.708L8 5.707 5.354 8.354a.5.5 0 11-.708-.708l3-3z' clip-rule='evenodd'/%3E%3C/svg%3E");
    width: 2.5em;
    height: 2.5em;
    background-repeat: no-repeat;
    margin: 0 3px 0 -15px;
    display: inline-block;
    float: left
}

#adminPanel .downArrowIcon {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-arrow-down-short' viewBox='0 0 16 16' fill='%23aaaaaa' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' d='M4.646 7.646a.5.5 0 01.708 0L8 10.293l2.646-2.647a.5.5 0 01.708.708l-3 3a.5.5 0 01-.708 0l-3-3a.5.5 0 010-.708z' clip-rule='evenodd'/%3E%3Cpath fill-rule='evenodd' d='M8 4.5a.5.5 0 01.5.5v5a.5.5 0 01-1 0V5a.5.5 0 01.5-.5z' clip-rule='evenodd'/%3E%3C/svg%3E");
    width: 2.5em;
    height: 2.5em;
    background-repeat: no-repeat;
    margin: 0 3px 0 -15px;
    display: inline-block;
    float: left
}

.alertWrapper {
    position: fixed;
    left: 50%;
    transform: translate(-50%);
    z-index: 501;
    max-width: 50%;
    font-size: 14px;
    top: 0px;
}

.editText {
    word-wrap: break-word;
    border: 2px dashed #ccc;
    display: block;
    padding: 4px;
    min-height: 100%;
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-pencil-square' viewBox='0 0 16 16' stroke='%23555555' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M15.502 1.94a.5.5 0 010 .706L14.459 3.69l-2-2L13.502.646a.5.5 0 01.707 0l1.293 1.293zm-1.75 2.456l-2-2L4.939 9.21a.5.5 0 00-.121.196l-.805 2.414a.25.25 0 00.316.316l2.414-.805a.5.5 0 00.196-.12l6.813-6.814z'/%3E%3Cpath fill-rule='evenodd' d='M1 13.5A1.5 1.5 0 002.5 15h11a1.5 1.5 0 001.5-1.5v-6a.5.5 0 00-1 0v6a.5.5 0 01-.5.5h-11a.5.5 0 01-.5-.5v-11a.5.5 0 01.5-.5H9a.5.5 0 000-1H2.5A1.5 1.5 0 001 2.5v11z' clip-rule='evenodd'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: 99.8% 4px;
    background-size: 20px 20px;
    padding: 10px 25px 10px 10px;
}

.editTextOpen:focus-within {
    background-image: url("data:image/svg+xml,%3Csvg class='bi bi-pencil-square' viewBox='0 0 16 16' stroke='%2314cde1' fill='%23ffffff' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M15.502 1.94a.5.5 0 010 .706L14.459 3.69l-2-2L13.502.646a.5.5 0 01.707 0l1.293 1.293zm-1.75 2.456l-2-2L4.939 9.21a.5.5 0 00-.121.196l-.805 2.414a.25.25 0 00.316.316l2.414-.805a.5.5 0 00.196-.12l6.813-6.814z'/%3E%3Cpath fill-rule='evenodd' d='M1 13.5A1.5 1.5 0 002.5 15h11a1.5 1.5 0 001.5-1.5v-6a.5.5 0 00-1 0v6a.5.5 0 01-.5.5h-11a.5.5 0 01-.5-.5v-11a.5.5 0 01.5-.5H9a.5.5 0 000-1H2.5A1.5 1.5 0 001 2.5v11z' clip-rule='evenodd'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: 99.8% 4px;
    background-size: 20px 20px
}

.editText textarea {
    outline: 0;
    border: none;
    width: 100%;
    color: inherit;
    font-size: inherit;
    font-family: inherit;
    background-color: transparent;
    padding-bottom: 10px;
    resize: none;
    text-align: inherit;
}

.editTextOpen {
    border: 2px dashed #0eb9cc
}

.editText:empty {
    min-height: 35px
}

#adminPanel .change {
    padding-left: 20px
}

#adminPanel .marginTop5,
.alertWrapper .marginTop5 {
    margin-top: 5px
}

#adminPanel .marginTop20,
.alertWrapper .marginTop20 {
    margin-top: 20px
}

#adminPanel .marginTop40 {
    margin-top: 40px
}

#adminPanel .marginBottom20 {
    margin-bottom: 20px!important
}

#adminPanel .marginLeft5 {
    margin-left: 5px
}

#adminPanel .subTitle {
    color: #aaa;
    font-size: 24px;
    margin: 30px 0 8px;
    font-variant: all-small-caps
}

#adminpanel select,
.btn,
.form-control {
    font-variant: all-small-caps
}

#adminPanel .menu-item-hide {
    color: #5bc0de
}

#adminPanel .menu-item-delete,
.menu-item-hide,
.menu-item-show {
    padding: 0 10%
}

#adminPanel .tab-content {
    margin-top: 20px
}

#adminPanel .fas,
.cursorPointer,
.editText {
    cursor: pointer
}

#adminPanel .fontSize21 {
    font-size: 21px
}

#adminPanel a {
    color: #aaa;
    outline: 0;
    border: 0;
    text-decoration: none
}

#adminPanel a.btn,
#adminpanel .alert a {
    color: #fff
}

#adminPanel .editText {
    color: #555;
    font-variant: normal
}

#adminPanel .normalFont {
    font-variant: normal;
    word-wrap: break-word
}

#adminPanel .nav-tabs>li>a:after {
    content: "";
    background: #1ab;
    height: 2px;
    position: absolute;
    width: 100%;
    left: 0;
    bottom: -2px;
    transition: all .25s ease 0s;
    transform: scale(0)
}

#adminPanel .nav-tabs>li.active>a:after,
#adminPanel .nav-tabs>li:hover>a,
#adminPanel .nav-tabs>li:hover>a:after,
#adminPanel .nav-tabs>li>a {
    transform: scale(1)
}

#adminPanel .nav-tabs>li>a.active {
    border-bottom: 2px solid #1ab!important
}

#adminPanel .modal-content {
    background-color: #eee
}

#adminPanel .modal-header {
    border: 0
}

#adminPanel .nav li {
    font-size: 30px;
    float: none;
    display: inline-block
}

#adminPanel .tab-pane.active a.btn {
    color: #fff
}

#adminPanel .nav-link.active,
#adminPanel .nav-tabs>li.active a,
#adminPanel .tab-pane.active {
    background: 0!important;
    border: 0!important;
    color: #aaa!important
}

#adminPanel .clear {
    clear: both
}

#adminPanel .custom-cards>div {
    margin: 15px 0
}

#adminPanel .custom-cards>div>div {
    box-shadow: -1px -1px 6px 0 rgba(0, 0, 0, .1);
    padding: .8em;
    font-size: .9em
}

#adminPanel .custom-cards>div>div h4 {
    font-weight: 700;
    font-size: 1.5em;
    margin-bottom: .2em;
    line-height: 1em;
    min-height: 40px;
    margin-top: 1em;
}

#adminPanel .custom-cards .btn {
    font-size: 1em
}

#adminPanel .custom-cards .deleteIcon {
    font-size: 1.1em
}

#adminPanel .custom-cards .col-sm-4:nth-child(3n+1) {
    clear: left
}

#adminPanel .button {
    position: fixed;
    z-index: 1040;
    padding: 5px 10px 5px 10px;
    font-size: 15px!important
}

#adminPanel .settings {
    top: 20px;
    right: 105px;
    min-height: 36px;
    max-height: 36px
}

#adminPanel .logout {
    top: 20px;
    right: 50px;
    min-height: 36px;
    max-height: 36px
}

#adminPanel footer,
header,
nav,
section,
summary {
    display: block
}

#adminPanel textarea {
    overflow: auto
}

#adminPanel * {
    box-sizing: border-box
}

#adminPanel::after,
::before {
    box-sizing: border-box
}

#adminPanel {
    font-family: Lucida Sans Unicode, Verdana, "Helvetica Neue", Helvetica, Arial, sans-serif;
    font-size: 14px;
    line-height: 1.42857;
    color: #333;
    background-color: #fff;
    font-variant: small-caps;
    text-shadow: none
}

#adminPanel button,
input,
select,
textarea {
    font-family: inherit;
    font-size: inherit;
    line-height: inherit
}

#adminPanel p {
    margin: 0 0 10px
}

#adminPanel .small {
    font-size: 85%
}

#adminPanel .text-left {
    text-align: left
}

#adminPanel .text-right {
    text-align: right
}

#adminPanel .text-center {
    text-align: center!important
}

#adminPanel .container {
    padding-right: 15px;
    padding-left: 15px;
    margin-right: auto;
    margin-left: auto
}

#adminPanel .container-fluid {
    padding-right: 15px;
    padding-left: 15px;
    margin-right: auto;
    margin-left: auto
}

#adminPanel .row {
    margin-right: -15px;
    margin-left: -15px
}

#adminPanel .col-xs-1,
.col-lg-1,
.col-lg-10,
.col-lg-11,
.col-lg-12,
.col-lg-2,
.col-lg-3,
.col-lg-4,
.col-lg-5,
.col-lg-6,
.col-lg-7,
.col-lg-8,
.col-lg-9,
.col-md-1,
.col-md-10,
.col-md-11,
.col-md-12,
.col-md-2,
.col-md-3,
.col-md-4,
.col-md-5,
.col-md-6,
.col-md-7,
.col-md-8,
.col-md-9,
.col-sm-1,
.col-sm-10,
.col-sm-11,
.col-sm-12,
.col-sm-2,
.col-sm-3,
.col-sm-4,
.col-sm-5,
.col-sm-6,
.col-sm-7,
.col-sm-8,
.col-sm-9,
.col-xs-10,
.col-xs-11,
.col-xs-12,
.col-xs-2,
.col-xs-3,
.col-xs-4,
.col-xs-5,
.col-xs-6,
.col-xs-7,
.col-xs-8,
.col-xs-9 {
    position: relative;
    min-height: 1px;
    padding-right: 15px;
    padding-left: 15px
}

#adminPanel .col-xs-1,
.col-xs-10,
.col-xs-11,
.col-xs-12,
.col-xs-2,
.col-xs-3,
.col-xs-4,
.col-xs-5,
.col-xs-6,
.col-xs-7,
.col-xs-8,
.col-xs-9 {
    float: left
}

#adminPanel .col-xs-12 {
    width: 100%
}

#adminPanel .col-xs-11 {
    width: 91.66666667%
}

#adminPanel .col-xs-10 {
    width: 83.33333333%
}

#adminPanel .col-xs-9 {
    width: 75%
}

#adminPanel .col-xs-8 {
    width: 66.66666667%
}

#adminPanel .col-xs-7 {
    width: 58.33333333%
}

#adminPanel .col-xs-6 {
    width: 50%
}

#adminPanel .col-xs-5 {
    width: 41.66666667%
}

#adminPanel .col-xs-4 {
    width: 33.33333333%
}

#adminPanel .col-xs-3 {
    width: 25%
}

#adminPanel .col-xs-2 {
    width: 16.66666667%
}

#adminPanel .col-xs-1 {
    width: 8.33333333%
}

#adminPanel .col-xs-pull-12 {
    right: 100%
}

#adminPanel .col-xs-pull-11 {
    right: 91.66666667%
}

#adminPanel .col-xs-pull-10 {
    right: 83.33333333%
}

#adminPanel .col-xs-pull-9 {
    right: 75%
}

#adminPanel .col-xs-pull-8 {
    right: 66.66666667%
}

#adminPanel .col-xs-pull-7 {
    right: 58.33333333%
}

#adminPanel .col-xs-pull-6 {
    right: 50%
}

#adminPanel .col-xs-pull-5 {
    right: 41.66666667%
}

#adminPanel .col-xs-pull-4 {
    right: 33.33333333%
}

#adminPanel .col-xs-pull-3 {
    right: 25%
}

#adminPanel .col-xs-pull-2 {
    right: 16.66666667%
}

#adminPanel .col-xs-pull-1 {
    right: 8.33333333%
}

#adminPanel .col-xs-pull-0 {
    right: auto
}

#adminPanel .col-xs-push-12 {
    left: 100%
}

#adminPanel .col-xs-push-11 {
    left: 91.66666667%
}

#adminPanel .col-xs-push-10 {
    left: 83.33333333%
}

#adminPanel .col-xs-push-9 {
    left: 75%
}

#adminPanel .col-xs-push-8 {
    left: 66.66666667%
}

#adminPanel .col-xs-push-7 {
    left: 58.33333333%
}

#adminPanel .col-xs-push-6 {
    left: 50%
}

#adminPanel .col-xs-push-5 {
    left: 41.66666667%
}

#adminPanel .col-xs-push-4 {
    left: 33.33333333%
}

#adminPanel .col-xs-push-3 {
    left: 25%
}

#adminPanel .col-xs-push-2 {
    left: 16.66666667%
}

#adminPanel .col-xs-push-1 {
    left: 8.33333333%
}

#adminPanel .col-xs-push-0 {
    left: auto
}

#adminPanel .col-xs-offset-12 {
    margin-left: 100%
}

#adminPanel .col-xs-offset-11 {
    margin-left: 91.66666667%
}

#adminPanel .col-xs-offset-10 {
    margin-left: 83.33333333%
}

#adminPanel .col-xs-offset-9 {
    margin-left: 75%
}

#adminPanel .col-xs-offset-8 {
    margin-left: 66.66666667%
}

#adminPanel .col-xs-offset-7 {
    margin-left: 58.33333333%
}

#adminPanel .col-xs-offset-6 {
    margin-left: 50%
}

#adminPanel .col-xs-offset-5 {
    margin-left: 41.66666667%
}

#adminPanel .col-xs-offset-4 {
    margin-left: 33.33333333%
}

#adminPanel .col-xs-offset-3 {
    margin-left: 25%
}

#adminPanel .col-xs-offset-2 {
    margin-left: 16.66666667%
}

#adminPanel .col-xs-offset-1 {
    margin-left: 8.33333333%
}

#adminPanel .col-xs-offset-0 {
    margin-left: 0
}

@media (min-width:768px) {
    #adminPanel .col-sm-1,
    .col-sm-10,
    .col-sm-11,
    .col-sm-12,
    .col-sm-2,
    .col-sm-3,
    .col-sm-4,
    .col-sm-5,
    .col-sm-6,
    .col-sm-7,
    .col-sm-8,
    .col-sm-9 {
        float: left
    }
    #adminPanel .col-sm-12 {
        width: 100%
    }
    #adminPanel .col-sm-11 {
        width: 91.66666667%
    }
    #adminPanel .col-sm-10 {
        width: 83.33333333%
    }
    #adminPanel .col-sm-9 {
        width: 75%
    }
    #adminPanel .col-sm-8 {
        width: 66.66666667%
    }
    #adminPanel .col-sm-7 {
        width: 58.33333333%
    }
    #adminPanel .col-sm-6 {
        width: 50%
    }
    #adminPanel .col-sm-5 {
        width: 41.66666667%
    }
    #adminPanel .col-sm-4 {
        width: 33.33333333%
    }
    #adminPanel .col-sm-3 {
        width: 25%
    }
    #adminPanel .col-sm-2 {
        width: 16.66666667%
    }
    #adminPanel .col-sm-1 {
        width: 8.33333333%
    }
    #adminPanel .col-sm-pull-12 {
        right: 100%
    }
    #adminPanel .col-sm-pull-11 {
        right: 91.66666667%
    }
    #adminPanel .col-sm-pull-10 {
        right: 83.33333333%
    }
    #adminPanel .col-sm-pull-9 {
        right: 75%
    }
    #adminPanel .col-sm-pull-8 {
        right: 66.66666667%
    }
    #adminPanel .col-sm-pull-7 {
        right: 58.33333333%
    }
    #adminPanel .col-sm-pull-6 {
        right: 50%
    }
    #adminPanel .col-sm-pull-5 {
        right: 41.66666667%
    }
    #adminPanel .col-sm-pull-4 {
        right: 33.33333333%
    }
    #adminPanel .col-sm-pull-3 {
        right: 25%
    }
    #adminPanel .col-sm-pull-2 {
        right: 16.66666667%
    }
    #adminPanel .col-sm-pull-1 {
        right: 8.33333333%
    }
    #adminPanel .col-sm-pull-0 {
        right: auto
    }
    #adminPanel .col-sm-push-12 {
        left: 100%
    }
    #adminPanel .col-sm-push-11 {
        left: 91.66666667%
    }
    #adminPanel .col-sm-push-10 {
        left: 83.33333333%
    }
    #adminPanel .col-sm-push-9 {
        left: 75%
    }
    #adminPanel .col-sm-push-8 {
        left: 66.66666667%
    }
    #adminPanel .col-sm-push-7 {
        left: 58.33333333%
    }
    #adminPanel .col-sm-push-6 {
        left: 50%
    }
    #adminPanel .col-sm-push-5 {
        left: 41.66666667%
    }
    #adminPanel .col-sm-push-4 {
        left: 33.33333333%
    }
    #adminPanel .col-sm-push-3 {
        left: 25%
    }
    #adminPanel .col-sm-push-2 {
        left: 16.66666667%
    }
    #adminPanel .col-sm-push-1 {
        left: 8.33333333%
    }
    #adminPanel .col-sm-push-0 {
        left: auto
    }
    #adminPanel .col-sm-offset-12 {
        margin-left: 100%
    }
    #adminPanel .col-sm-offset-11 {
        margin-left: 91.66666667%
    }
    #adminPanel .col-sm-offset-10 {
        margin-left: 83.33333333%
    }
    #adminPanel .col-sm-offset-9 {
        margin-left: 75%
    }
    #adminPanel .col-sm-offset-8 {
        margin-left: 66.66666667%
    }
    #adminPanel .col-sm-offset-7 {
        margin-left: 58.33333333%
    }
    #adminPanel .col-sm-offset-6 {
        margin-left: 50%
    }
    #adminPanel .col-sm-offset-5 {
        margin-left: 41.66666667%
    }
    #adminPanel .col-sm-offset-4 {
        margin-left: 33.33333333%
    }
    #adminPanel .col-sm-offset-3 {
        margin-left: 25%
    }
    #adminPanel .col-sm-offset-2 {
        margin-left: 16.66666667%
    }
    #adminPanel .col-sm-offset-1 {
        margin-left: 8.33333333%
    }
    #adminPanel .col-sm-offset-0 {
        margin-left: 0
    }
}

@media (min-width:992px) {
    #adminPanel .col-md-1,
    .col-md-10,
    .col-md-11,
    .col-md-12,
    .col-md-2,
    .col-md-3,
    .col-md-4,
    .col-md-5,
    .col-md-6,
    .col-md-7,
    .col-md-8,
    .col-md-9 {
        float: left
    }
    #adminPanel .col-md-12 {
        width: 100%
    }
    #adminPanel .col-md-11 {
        width: 91.66666667%
    }
    #adminPanel .col-md-10 {
        width: 83.33333333%
    }
    #adminPanel .col-md-9 {
        width: 75%
    }
    #adminPanel .col-md-8 {
        width: 66.66666667%
    }
    #adminPanel .col-md-7 {
        width: 58.33333333%
    }
    #adminPanel .col-md-6 {
        width: 50%
    }
    #adminPanel .col-md-5 {
        width: 41.66666667%
    }
    #adminPanel .col-md-4 {
        width: 33.33333333%
    }
    #adminPanel .col-md-3 {
        width: 25%
    }
    #adminPanel .col-md-2 {
        width: 16.66666667%
    }
    #adminPanel .col-md-1 {
        width: 8.33333333%
    }
    #adminPanel .col-md-pull-12 {
        right: 100%
    }
    #adminPanel .col-md-pull-11 {
        right: 91.66666667%
    }
    #adminPanel .col-md-pull-10 {
        right: 83.33333333%
    }
    #adminPanel .col-md-pull-9 {
        right: 75%
    }
    #adminPanel .col-md-pull-8 {
        right: 66.66666667%
    }
    #adminPanel .col-md-pull-7 {
        right: 58.33333333%
    }
    #adminPanel .col-md-pull-6 {
        right: 50%
    }
    #adminPanel .col-md-pull-5 {
        right: 41.66666667%
    }
    #adminPanel .col-md-pull-4 {
        right: 33.33333333%
    }
    #adminPanel .col-md-pull-3 {
        right: 25%
    }
    #adminPanel .col-md-pull-2 {
        right: 16.66666667%
    }
    #adminPanel .col-md-pull-1 {
        right: 8.33333333%
    }
    #adminPanel .col-md-push-12 {
        left: 100%
    }
    #adminPanel .col-md-push-11 {
        left: 91.66666667%
    }
    #adminPanel .col-md-push-10 {
        left: 83.33333333%
    }
    #adminPanel .col-md-push-9 {
        left: 75%
    }
    #adminPanel .col-md-push-8 {
        left: 66.66666667%
    }
    #adminPanel .col-md-push-7 {
        left: 58.33333333%
    }
    #adminPanel .col-md-push-6 {
        left: 50%
    }
    #adminPanel .col-md-push-5 {
        left: 41.66666667%
    }
    #adminPanel .col-md-push-4 {
        left: 33.33333333%
    }
    #adminPanel .col-md-push-3 {
        left: 25%
    }
    #adminPanel .col-md-push-2 {
        left: 16.66666667%
    }
    #adminPanel .col-md-push-1 {
        left: 8.33333333%
    }
    #adminPanel .col-md-push-0 {
        left: auto
    }
    #adminPanel .col-md-offset-12 {
        margin-left: 100%
    }
    #adminPanel .col-md-offset-11 {
        margin-left: 91.66666667%
    }
    #adminPanel .col-md-offset-10 {
        margin-left: 83.33333333%
    }
    #adminPanel .col-md-offset-9 {
        margin-left: 75%
    }
    #adminPanel .col-md-offset-8 {
        margin-left: 66.66666667%
    }
    #adminPanel .col-md-offset-7 {
        margin-left: 58.33333333%
    }
    #adminPanel .col-md-offset-6 {
        margin-left: 50%
    }
    #adminPanel .col-md-offset-5 {
        margin-left: 41.66666667%
    }
    #adminPanel .col-md-offset-4 {
        margin-left: 33.33333333%
    }
    #adminPanel .col-md-offset-3 {
        margin-left: 25%
    }
    #adminPanel .col-md-offset-2 {
        margin-left: 16.66666667%
    }
    #adminPanel .col-md-offset-1 {
        margin-left: 8.33333333%
    }
    #adminPanel .col-md-offset-0 {
        margin-left: 0
    }
}

@media (min-width:1200px) {
    #adminPanel .col-lg-1,
    .col-lg-10,
    .col-lg-11,
    .col-lg-12,
    .col-lg-2,
    .col-lg-3,
    .col-lg-4,
    .col-lg-5,
    .col-lg-6,
    .col-lg-7,
    .col-lg-8,
    .col-lg-9 {
        float: left
    }
    #adminPanel .col-lg-12 {
        width: 100%
    }
    #adminPanel .col-lg-11 {
        width: 91.66666667%
    }
    #adminPanel .col-lg-10 {
        width: 83.33333333%
    }
    #adminPanel .col-lg-9 {
        width: 75%
    }
    #adminPanel .col-lg-8 {
        width: 66.66666667%
    }
    #adminPanel .col-lg-7 {
        width: 58.33333333%
    }
    #adminPanel .col-lg-6 {
        width: 50%
    }
    #adminPanel .col-lg-5 {
        width: 41.66666667%
    }
    #adminPanel .col-lg-4 {
        width: 33.33333333%
    }
    #adminPanel .col-lg-3 {
        width: 25%
    }
    #adminPanel .col-lg-2 {
        width: 16.66666667%
    }
    #adminPanel .col-lg-1 {
        width: 8.33333333%
    }
    #adminPanel .col-lg-pull-12 {
        right: 100%
    }
    #adminPanel .col-lg-pull-11 {
        right: 91.66666667%
    }
    #adminPanel .col-lg-pull-10 {
        right: 83.33333333%
    }
    #adminPanel .col-lg-pull-9 {
        right: 75%
    }
    #adminPanel .col-lg-pull-8 {
        right: 66.66666667%
    }
    #adminPanel .col-lg-pull-7 {
        right: 58.33333333%
    }
    #adminPanel .col-lg-pull-6 {
        right: 50%
    }
    #adminPanel .col-lg-pull-5 {
        right: 41.66666667%
    }
    #adminPanel .col-lg-pull-4 {
        right: 33.33333333%
    }
    #adminPanel .col-lg-pull-3 {
        right: 25%
    }
    #adminPanel .col-lg-pull-2 {
        right: 16.66666667%
    }
    #adminPanel .col-lg-pull-1 {
        right: 8.33333333%
    }
    #adminPanel .col-lg-pull-0 {
        right: auto
    }
    #adminPanel .col-lg-push-12 {
        left: 100%
    }
    #adminPanel .col-lg-push-11 {
        left: 91.66666667%
    }
    #adminPanel .col-lg-push-10 {
        left: 83.33333333%
    }
    #adminPanel .col-lg-push-9 {
        left: 75%
    }
    #adminPanel .col-lg-push-8 {
        left: 66.66666667%
    }
    #adminPanel .col-lg-push-7 {
        left: 58.33333333%
    }
    #adminPanel .col-lg-push-6 {
        left: 50%
    }
    #adminPanel .col-lg-push-5 {
        left: 41.66666667%
    }
    #adminPanel .col-lg-push-4 {
        left: 33.33333333%
    }
    #adminPanel .col-lg-push-3 {
        left: 25%
    }
    #adminPanel .col-lg-push-2 {
        left: 16.66666667%
    }
    #adminPanel .col-lg-push-1 {
        left: 8.33333333%
    }
    #adminPanel .col-lg-push-0 {
        left: auto
    }
    #adminPanel .col-lg-offset-12 {
        margin-left: 100%
    }
    #adminPanel .col-lg-offset-11 {
        margin-left: 91.66666667%
    }
    #adminPanel .col-lg-offset-10 {
        margin-left: 83.33333333%
    }
    #adminPanel .col-lg-offset-9 {
        margin-left: 75%
    }
    #adminPanel .col-lg-offset-8 {
        margin-left: 66.66666667%
    }
    #adminPanel .col-lg-offset-7 {
        margin-left: 58.33333333%
    }
    #adminPanel .col-lg-offset-6 {
        margin-left: 50%
    }
    #adminPanel .col-lg-offset-5 {
        margin-left: 41.66666667%
    }
    #adminPanel .col-lg-offset-4 {
        margin-left: 33.33333333%
    }
    #adminPanel .col-lg-offset-3 {
        margin-left: 25%
    }
    #adminPanel .col-lg-offset-2 {
        margin-left: 16.66666667%
    }
    #adminPanel .col-lg-offset-1 {
        margin-left: 8.33333333%
    }
    #adminPanel .col-lg-offset-0 {
        margin-left: 0
    }
}

#adminPanel input[type=file]:focus,
input[type=checkbox]:focus,
input[type=radio]:focus {
    outline: -webkit-focus-ring-color auto 5px;
    outline-offset: -2px;
    background-color: #fff
}

#adminPanel .form-control {
    display: block;
    width: 100%;
    height: 34px;
    padding: 6px 12px;
    font-size: 14px;
    line-height: 1.42857;
    color: #555;
    background-color: #fff!important;
    background-image: none;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-shadow: rgba(0, 0, 0, .075) 0 1px 1px inset;
    transition: border-color .15s ease-in-out 0s, box-shadow .15s ease-in-out 0s
}

#adminPanel .form-control:focus {
    border-color: #66afe9;
    outline: 0;
    box-shadow: rgba(0, 0, 0, .075) 0 1px 1px inset, rgba(102, 175, 233, .6) 0 0 8px
}

#adminPanel .form-control::-webkit-input-placeholder {
    color: #999
}

#adminPanel .form-control[disabled],
.form-control[readonly],
fieldset[disabled] .form-control {
    background-color: #eee;
    opacity: 1
}

#adminPanel .form-control[disabled],
fieldset[disabled] .form-control {
    cursor: not-allowed
}

#adminPanel textarea.form-control {
    height: auto
}

#adminPanel .form-group {
    margin-bottom: 15px
}

#adminPanel .form-group-sm .form-control {
    height: 30px;
    padding: 5px 10px;
    font-size: 12px;
    line-height: 1.5;
    border-radius: 3px
}

#adminPanel .form-group-sm select.form-control {
    height: 30px;
    line-height: 30px
}

#adminPanel .form-group-sm select[multiple].form-control,
.form-group-sm textarea.form-control {
    height: auto
}

#adminPanel .form-group-lg .form-control {
    height: 46px;
    padding: 10px 16px;
    font-size: 18px;
    line-height: 1.33333;
    border-radius: 6px
}

#adminPanel .form-group-lg select.form-control {
    height: 46px;
    line-height: 46px
}

#adminPanel .form-group-lg select[multiple].form-control,
.form-group-lg textarea.form-control {
    height: auto
}

#adminPanel .form-group-lg .form-control+.form-control-feedback,
.input-group-lg+.form-control-feedback,
.input-lg+.form-control-feedback {
    width: 46px;
    height: 46px;
    line-height: 46px
}

#adminPanel .form-group-sm .form-control+.form-control-feedback,
.input-group-sm+.form-control-feedback,
.input-sm+.form-control-feedback {
    width: 30px;
    height: 30px;
    line-height: 30px
}

#adminPanel .form-horizontal .form-group {
    margin-right: -15px;
    margin-left: -15px
}

#adminPanel .btn.disabled,
.btn[disabled],
fieldset[disabled] .btn {
    cursor: not-allowed!important;
    box-shadow: none;
    opacity: .65
}

#adminPanel .btn,
.alertWrapper .btn {
    display: inline-block;
    padding: 6px 12px;
    margin-bottom: 0;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.42857;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    touch-action: manipulation;
    cursor: pointer;
    user-select: none;
    border: 1px solid transparent;
    border-radius: 4px
}

#adminPanel button.close,
.alertWrapper button.close {
    -webkit-appearance: none;
    padding: 0;
    cursor: pointer;
    background: 0 0;
    border: 0
}

#adminPanel .btn-default.active,
.btn-default:active,
.open>.dropdown-toggle.btn-default {
    color: #333;
    background-color: #e6e6e6;
    border-color: #adadad
}

#adminPanel .btn-default.active.focus,
.btn-default.active:focus,
.btn-default.active:hover,
.btn-default:active.focus,
.btn-default:active:focus,
.btn-default:active:hover,
.open>.dropdown-toggle.btn-default.focus,
.open>.dropdown-toggle.btn-default:focus,
.open>.dropdown-toggle.btn-default:hover {
    color: #333;
    background-color: #d4d4d4;
    border-color: #8c8c8c
}

#adminPanel .btn-default.active,
.btn-default:active,
.open>.dropdown-toggle.btn-default {
    background-image: none
}

#adminPanel .btn-primary {
    color: #fff;
    background-color: #337ab7;
    border-color: #2e6da4
}

#adminPanel .btn-primary.focus,
.btn-primary:focus {
    color: #fff;
    background-color: #286090;
    border-color: #122b40
}

#adminPanel .btn-primary:hover {
    color: #fff;
    background-color: #286090;
    border-color: #204d74
}

#adminPanel .btn-primary.active,
.btn-primary:active,
.open>.dropdown-toggle.btn-primary {
    color: #fff;
    background-color: #286090;
    border-color: #204d74
}

#adminPanel .btn-primary.active.focus,
.btn-primary.active:focus,
.btn-primary.active:hover,
.btn-primary:active.focus,
.btn-primary:active:focus,
.btn-primary:active:hover,
.open>.dropdown-toggle.btn-primary.focus,
.open>.dropdown-toggle.btn-primary:focus,
.open>.dropdown-toggle.btn-primary:hover {
    color: #fff;
    background-color: #204d74;
    border-color: #122b40
}

#adminPanel .btn-primary.active,
.btn-primary:active,
.open>.dropdown-toggle.btn-primary {
    background-image: none
}

#adminPanel .btn-primary.disabled.focus,
.btn-primary.disabled:focus,
.btn-primary.disabled:hover,
.btn-primary[disabled].focus,
.btn-primary[disabled]:focus,
.btn-primary[disabled]:hover,
fieldset[disabled] .btn-primary.focus,
fieldset[disabled] .btn-primary:focus,
fieldset[disabled] .btn-primary:hover {
    background-color: #337ab7;
    border-color: #2e6da4
}

#adminPanel .btn-primary .badge {
    color: #337ab7;
    background-color: #fff
}

#adminPanel .btn-success {
    color: #fff;
    background-color: #5cb85c;
    border-color: #4cae4c
}

#adminPanel .btn-success.focus,
.btn-success:focus {
    color: #fff;
    background-color: #449d44;
    border-color: #255625
}

#adminPanel .btn-success:hover {
    color: #fff;
    background-color: #449d44;
    border-color: #398439
}

#adminPanel .btn-success.active,
.btn-success:active,
.open>.dropdown-toggle.btn-success {
    color: #fff;
    background-color: #449d44;
    border-color: #398439
}

#adminPanel .btn-success.active.focus,
.btn-success.active:focus,
.btn-success.active:hover,
.btn-success:active.focus,
.btn-success:active:focus,
.btn-success:active:hover,
.open>.dropdown-toggle.btn-success.focus,
.open>.dropdown-toggle.btn-success:focus,
.open>.dropdown-toggle.btn-success:hover {
    color: #fff;
    background-color: #398439;
    border-color: #255625
}

#adminPanel .btn-success.active,
.btn-success:active,
.open>.dropdown-toggle.btn-success {
    background-image: none
}

#adminPanel .btn-success.disabled.focus,
.btn-success.disabled:focus,
.btn-success.disabled:hover,
.btn-success[disabled].focus,
.btn-success[disabled]:focus,
.btn-success[disabled]:hover,
fieldset[disabled] .btn-success.focus,
fieldset[disabled] .btn-success:focus,
fieldset[disabled] .btn-success:hover {
    background-color: #5cb85c;
    border-color: #4cae4c
}

#adminPanel .btn-success .badge {
    color: #5cb85c;
    background-color: #fff
}

#adminPanel .btn-info,
.alertWrapper .btn-info {
    color: #fff;
    background-color: #5bc0de;
    border-color: #46b8da
}

#adminPanel .btn-info.focus,
.alertWrapper .btn-info.focus,
.btn-info:focus {
    color: #fff;
    background-color: #31b0d5;
    border-color: #1b6d85
}

#adminPanel .btn-info:hover,
.alertWrapper .btn-info:hover {
    color: #fff;
    background-color: #31b0d5;
    border-color: #269abc
}

#adminPanel .btn-info.active,
.btn-info:active,
.open>.dropdown-toggle.btn-info {
    color: #fff;
    background-color: #31b0d5;
    border-color: #269abc
}

#adminPanel .btn-info.active.focus,
.btn-info.active:focus,
.btn-info.active:hover,
.btn-info:active.focus,
.btn-info:active:focus,
.btn-info:active:hover,
.open>.dropdown-toggle.btn-info.focus,
.open>.dropdown-toggle.btn-info:focus,
.open>.dropdown-toggle.btn-info:hover {
    color: #fff;
    background-color: #269abc;
    border-color: #1b6d85
}

#adminPanel .btn-info.active,
.btn-info:active,
.open>.dropdown-toggle.btn-info {
    background-image: none
}

#adminPanel .btn-info.disabled.focus,
.btn-info.disabled:focus,
.btn-info.disabled:hover,
.btn-info[disabled].focus,
.btn-info[disabled]:focus,
.btn-info[disabled]:hover,
fieldset[disabled] .btn-info.focus,
fieldset[disabled] .btn-info:focus,
fieldset[disabled] .btn-info:hover {
    background-color: #5bc0de;
    border-color: #46b8da
}

#adminPanel .btn-info .badge {
    color: #5bc0de;
    background-color: #fff
}

#adminPanel .btn-danger {
    color: #fff;
    background-color: #d9534f;
    border-color: #d43f3a
}

#adminPanel .btn-danger.focus,
.btn-danger:focus {
    color: #fff;
    background-color: #c9302c;
    border-color: #761c19
}

#adminPanel .btn-danger:hover {
    color: #fff;
    background-color: #c9302c;
    border-color: #ac2925
}

#adminPanel .btn-danger.active,
.btn-danger:active,
.open>.dropdown-toggle.btn-danger {
    color: #fff;
    background-color: #c9302c;
    border-color: #ac2925
}

#adminPanel .btn-danger.active.focus,
.btn-danger.active:focus,
.btn-danger.active:hover,
.btn-danger:active.focus,
.btn-danger:active:focus,
.btn-danger:active:hover,
.open>.dropdown-toggle.btn-danger.focus,
.open>.dropdown-toggle.btn-danger:focus,
.open>.dropdown-toggle.btn-danger:hover {
    color: #fff;
    background-color: #ac2925;
    border-color: #761c19
}

#adminPanel .btn-danger.active,
.btn-danger:active,
.open>.dropdown-toggle.btn-danger {
    background-image: none
}

#adminPanel .btn-danger.disabled.focus,
.btn-danger.disabled:focus,
.btn-danger.disabled:hover,
.btn-danger[disabled].focus,
.btn-danger[disabled]:focus,
.btn-danger[disabled]:hover,
fieldset[disabled] .btn-danger.focus,
fieldset[disabled] .btn-danger:focus,
fieldset[disabled] .btn-danger:hover {
    background-color: #d9534f;
    border-color: #d43f3a
}

#adminPanel .btn-danger .badge {
    color: #d9534f;
    background-color: #fff
}

#adminPanel .btn-link,
.btn-link.active,
.btn-link:active,
.btn-link[disabled],
fieldset[disabled] .btn-link {
    background-color: transparent;
    box-shadow: none
}

#adminPanel .btn-block {
    display: block;
    width: 100%
}

#adminPanel .btn-block+.btn-block {
    margin-top: 5px
}

#adminPanel input[type=button].btn-block,
input[type=reset].btn-block,
input[type=submit].btn-block {
    width: 100%
}

#adminPanel .input-group {
    position: relative;
    display: table;
    border-collapse: separate
}

#adminPanel .input-group .form-control {
    position: relative;
    z-index: 2;
    float: left;
    width: 100%;
    margin-bottom: 0
}

#adminPanel .input-group .form-control:focus {
    z-index: 3
}

#adminPanel .input-group-lg>.form-control,
.input-group-lg>.input-group-addon,
.input-group-lg>.input-group-btn>.btn {
    height: 46px;
    padding: 10px 16px;
    font-size: 18px;
    line-height: 1.33333;
    border-radius: 6px
}

#adminPanel .input-group-sm>.form-control,
.input-group-sm>.input-group-addon,
.input-group-sm>.input-group-btn>.btn {
    height: 30px;
    padding: 5px 10px;
    font-size: 12px;
    line-height: 1.5;
    border-radius: 3px
}

#adminPanel .input-group .form-control,
.input-group-addon,
.input-group-btn {
    display: table-cell
}

#adminPanel .input-group .form-control:not(:first-child):not(:last-child),
.input-group-addon:not(:first-child):not(:last-child),
.input-group-btn:not(:first-child):not(:last-child) {
    border-radius: 0
}

#adminPanel .input-group-addon,
.input-group-btn {
    width: 1%;
    white-space: nowrap;
    vertical-align: middle
}

#adminPanel .input-group .form-control:first-child,
.input-group-addon:first-child,
.input-group-btn:first-child>.btn,
.input-group-btn:first-child>.btn-group>.btn,
.input-group-btn:first-child>.dropdown-toggle,
.input-group-btn:last-child>.btn-group:not(:last-child)>.btn,
.input-group-btn:last-child>.btn:not(:last-child):not(.dropdown-toggle) {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0
}

#adminPanel .input-group .form-control:last-child,
.input-group-addon:last-child,
.input-group-btn:first-child>.btn-group:not(:first-child)>.btn,
.input-group-btn:first-child>.btn:not(:first-child),
.input-group-btn:last-child>.btn,
.input-group-btn:last-child>.btn-group>.btn,
.input-group-btn:last-child>.dropdown-toggle {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0
}

#adminPanel .input-group-btn {
    position: relative;
    white-space: nowrap
}

#adminPanel .input-group-btn>.btn {
    position: relative
}

#adminPanel .input-group-btn>.btn+.btn {
    margin-left: -1px
}

#adminPanel .input-group-btn>.btn:active,
.input-group-btn>.btn:focus,
.input-group-btn>.btn:hover {
    z-index: 2
}

#adminPanel .input-group-btn:first-child>.btn,
.input-group-btn:first-child>.btn-group {
    margin-right: -1px
}

#adminPanel .input-group-btn:last-child>.btn,
.input-group-btn:last-child>.btn-group {
    z-index: 2;
    margin-left: -1px
}

#adminPanel .nav {
    padding-left: 0;
    margin-bottom: 0;
    list-style: none
}

#adminPanel .nav>li>a {
    position: relative;
    display: block;
    padding: 10px 15px
}

#adminPanel .nav>li>a:focus,
.nav>li>a:hover {
    text-decoration: none;
    background: 0 0!important
}

#adminPanel .nav .nav-divider {
    height: 1px;
    margin: 9px 0;
    overflow: hidden;
    background-color: #e5e5e5;
    border: none
}

#adminPanel .nav-tabs {
    border-bottom: 1px solid #ddd
}

#adminPanel .nav-tabs>li {
    margin-bottom: -1px;
    margin-left: 10px;
}

#adminPanel .nav-tabs>li>a {
    margin-right: 2px;
    line-height: 1.42857;
    border-radius: 4px 4px 0 0
}

#adminPanel .nav-tabs>li.active>a,
.nav-tabs>li.active>a:focus,
.nav-tabs>li.active>a:hover {
    color: #555;
    cursor: default;
    background-color: #fff;
    border: none
}

#adminPanel .tab-content>.tab-pane {
    display: none
}

#adminPanel .tab-content>.active {
    display: block
}

#adminPanel .nav-tabs {
    margin-top: -1px;
    border-top-left-radius: 0;
    border-top-right-radius: 0
}

#adminPanel .btn-group-xs>.btn,
.alertWrapper .btn-group-xs>.btn,
.btn-xs {
    top: 0;
    padding: 1px 5px!important
}

#adminPanel .container .container-fluid {
    padding-right: 15px;
    padding-left: 15px;
    border-radius: 6px
}

.alertWrapper .alert {
    top: 70px;
    z-index: 14;
    padding: 20px 40px 20px 30px;
    position: relative;
    border-radius: 5px;
    font-size: .9em!important;
    margin-bottom: 20px
}

.alertWrapper h3 {
    font-size: 1.4em!important;
    font-weight: 700
}

.alertWrapper .alert a {
    border: 0;
    color: #337ab7;
    text-decoration: none
}

#adminPanel .alert-dismissible,
.alertWrapper .alert-dismissable {
    padding-right: 35px
}

.alertWrapper .alert-danger {
    color: #a94442;
    background-color: #f2dede;
    border-color: #ebccd1
}

.alertWrapper .alert-danger .alert-link {
    color: #843534
}

.alertWrapper .alert-info {
    color: #0c5460;
    background-color: #d1ecf1;
    border-color: #bee5eb
}

.alertWrapper .alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb
}

#adminPanel .close,
.alertWrapper .close {
    float: right;
    font-size: 21px;
    font-weight: 700;
    line-height: 1;
    color: #000;
    text-shadow: #fff 0 1px 0;
    opacity: .2
}

.modal {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 1050;
    display: none;
    overflow: hidden;
    outline: 0;
    overflow-y: auto;
}

#adminPanel .modal.fade .modal-dialog {
    transition: transform .3s ease-out 0s;
    transform: translate(0, -25%)
}

#adminPanel .modal-open .modal {
    overflow: hidden auto;
    background-color: rgba(0, 0, 0, .6)
}

#adminPanel .modal.in .modal-dialog {
    transform: translate(0, 0)
}

#adminPanel .modal-dialog {
    position: relative;
    width: auto;
    margin: 10px
}

#adminPanel .modal-content {
    position: relative;
    background-clip: padding-box;
    border: 1px solid rgba(0, 0, 0, .2);
    border-radius: 6px;
    outline: 0;
    box-shadow: rgba(0, 0, 0, .5) 0 3px 9px;
    background: #f5f5f5
}

#adminPanel .modal-header {
    padding: 15px
}

#adminPanel .modal-header .close {
    margin: 0rem 0rem 0rem auto !important;
}

.alertWrapper .close {
    position: relative;
    top: -2px;
    right: -21px;
    color: inherit;
    font-family: unset;
    line-height: 1 !important;
}

#adminPanel .modal-body {
    position: relative;
    padding: 30px!important;
    margin-bottom: 20px
}

#adminPanel .modal-footer {
    padding: 15px;
    text-align: right;
    border-top: 1px solid #e5e5e5
}

#adminPanel .modal-footer .btn+.btn {
    margin-bottom: 0;
    margin-left: 5px
}

#adminPanel .modal-footer .btn-group .btn+.btn {
    margin-left: -1px
}

#adminPanel .modal-footer .btn-block+.btn-block {
    margin-left: 0
}

#adminPanel .btn-group-vertical>.btn-group::after,
.btn-group-vertical>.btn-group::before,
.btn-toolbar::after,
.btn-toolbar::before,
.clearfix::after,
.clearfix::before,
.container-fluid::after,
.container-fluid::before,
.container::after,
.container::before,
.dl-horizontal dd::after,
.dl-horizontal dd::before,
.form-horizontal .form-group::after,
.form-horizontal .form-group::before,
.modal-footer::after,
.modal-footer::before,
.modal-header::after,
.modal-header::before,
.nav::after,
.nav::before,
.navbar-collapse::after,
.navbar-collapse::before,
.navbar-header::after,
.navbar-header::before,
.navbar::after,
.navbar::before,
.pager::after,
.pager::before,
.panel-body::after,
.panel-body::before,
.row::after,
.row::before {
    display: table;
    content: " "
}

#adminPanel .btn-group-vertical>.btn-group::after,
.btn-toolbar::after,
.clearfix::after,
.container-fluid::after,
.container::after,
.dl-horizontal dd::after,
.form-horizontal .form-group::after,
.modal-footer::after,
.modal-header::after,
.nav::after,
.navbar-collapse::after,
.navbar-header::after,
.navbar::after,
.pager::after,
.panel-body::after,
.row::after {
    clear: both
}

#adminPanel .pull-right {
    float: right!important
}

#adminPanel .pull-left {
    float: left!important
}

#adminPanel .btn-group>.btn:not(:first-child):not(:last-child):not(.dropdown-toggle) {
    border-radius: 0
}

#adminPanel .btn-group>.btn:first-child {
    margin-left: 0
}

#adminPanel .btn-group>.btn:first-child:not(:last-child):not(.dropdown-toggle) {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0
}

#adminPanel btn-group>.btn:last-child:not(:first-child),
.btn-group>.dropdown-toggle:not(:first-child) {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0
}

#adminPanel .btn-group>.btn-group {
    float: left
}

#adminPanel .btn-group>.btn-group:not(:first-child):not(:last-child)>.btn {
    border-radius: 0
}

#adminPanel .btn-group>.btn-group:first-child:not(:last-child)>.btn:last-child,
.btn-group>.btn-group:first-child:not(:last-child)>.dropdown-toggle {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0
}

#adminPanel .btn-group>.btn-group:last-child:not(:first-child)>.btn:first-child {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0
}

#adminPanel .btn-group-justified {
    display: table;
    width: 100%;
    table-layout: fixed;
    border-collapse: separate
}

#adminPanel .btn-group-justified>.btn,
#adminPanel .btn-group-justified>.btn-group {
    display: table-cell;
    float: none;
    width: 1%
}

#adminPanel .btn-group-justified>.btn-group .btn {
    width: 100%
}

@media (min-width:768px) {
    #adminPanel .modal-xl {
        width: 90%;
        max-width: 1200px;
        margin: 30px auto;
        height: 100%;
    }
}

@media (max-width:768px) {
    .alertWrapper {
        min-width: 80%!important
    }
}
</style>

EOT;
			return $this->hook('css', $styles)[0];
		}
		return $this->hook('css', '')[0];
	}

	/**
	 * Get database content
	 * @return stdClass
	 * @throws Exception
	 */
	public function getDb(): stdClass
	{
		// initialize database if it doesn't exist
		if (!file_exists($this->dbPath)) {
			// this code only runs one time (on first page load/install)
			$this->checkFolder(dirname($this->dbPath));
			$this->checkFolder($this->filesPath);
			$this->checkFolder($this->rootDir . '/' . self::THEMES_DIR);
			$this->checkFolder($this->rootDir . '/' . self::PLUGINS_DIR);
			$this->createDb();
		}
		return json_decode(file_get_contents($this->dbPath), false);
	}

	/**
	 * Get data from any json file
	 * @param string $path
	 * @return stdClass|null
	 */
	public function getJsonFileData(string $path): ?array
	{
		if (is_file($path) && file_exists($path)) {
			return json_decode(file_get_contents($path), true);
		}

		return null;
	}

	/**
	 * Delete theme
	 * @return void
	 */
	public function deleteFileThemePluginAction(): void
	{
		if (!$this->get('config', 'loggedIn')) {
			return;
		}
		if (isset($_REQUEST['deleteThemePlugin'], $_REQUEST['type']) && $this->verifyFormActions(true)) {
			$filename = str_ireplace(['/', './', '../', '..', '~', '~/', '\\'], null,
				trim($_REQUEST['deleteThemePlugin']));
			$type = $_REQUEST['type'];
			if ($filename === $this->get('config', 'theme')) {
				$this->alert('danger',
					'Cannot delete currently active theme. <a data-toggle="modal" href="#settingsModal" data-target-tab="#themes"><b>Re-open theme settings</b></a>');
				$this->redirect();
			}
			$folder = $type === 'files' ? $this->filesPath : sprintf('%s/%s', $this->rootDir, $type);
			if (file_exists("{$folder}/{$filename}")) {
				$this->recursiveDelete("{$folder}/{$filename}");
				$this->alert('success', "Deleted {$filename}.");
				$this->redirect();
			}
		}
	}

	public function changePageThemeAction(): void
	{
		if (isset($_REQUEST['selectThemePlugin'], $_REQUEST['type']) && $this->verifyFormActions(true)) {
			$theme = $_REQUEST['selectThemePlugin'];
			if (!is_dir($this->rootDir . '/' . $_REQUEST['type'] . '/' . $theme)) {
				return;
			}

			$this->set('config', 'theme', $theme);
			$this->redirect();
		}
	}

	/**
	 * Delete page
	 * @return void
	 */
	public function deletePageAction(): void
	{
		if (!isset($_GET['delete']) || !$this->verifyFormActions(true)) {
			return;
		}
		$slug = $_GET['delete'];
		if (isset($this->get('pages')->{$slug})) {
			$this->unset('pages', $slug);
		}
		$menuItems = json_decode(json_encode($this->get('config', 'menuItems')), true);
		if (false !== ($index = array_search($slug, array_column($menuItems, 'slug'), true))) {
			unset($menuItems[$index]);
			$newMenu = array_values($menuItems);
			$this->set('config', 'menuItems', json_decode(json_encode($newMenu), false));
			if ($this->get('config', 'defaultPage') === $slug) {
				$allMenuItems = $this->get('config', 'menuItems') ?? [];
				$firstMenuItem = reset($allMenuItems);
				$this->set('config', 'defaultPage', $firstMenuItem->slug ?? $slug);
			}
		}
		$this->alert('success', 'Page <b>' . $slug . '</b> deleted.');
		$this->redirect();
	}

	/**
	 * Get editable block
	 *
	 * @param string $id id for the block
	 * @param string $content html content
	 * @param string $dataTarget
	 * @return string
	 */
	public function editable(string $id, string $content, string $dataTarget = ''): string
	{
		return '<div' . ($dataTarget !== '' ? ' data-target="' . $dataTarget . '"' : '') . ' id="' . $id . '" class="editText editable">' . $content . '</div>';
	}

	/**
	 * Get main website title, show edit icon if logged in
	 * @return string
	 */
	public function siteTitle(): string
	{
	    $output =  $this->get('config', 'siteTitle');
		if ($this->get('config', 'loggedIn')) {
		    $output .= "<a data-toggle='modal' href='#settingsModal' data-target-tab='#menu'><i class='editIcon'></i></a>";
		}
		return $output;
	}

	/**
	 * Get footer, make it editable and show login link if it's set to default
	 * @return string
	 */
	public function footer(): string
	{
		if ($this->get('config', 'loggedIn')) {
    	    $output = '<div data-target="blocks" id="footer" class="editText editable">' . $this->get('blocks', 'footer')->content . '</div>';
		} else {
			$output .= $this->get('blocks', 'footer')->content .
			(!$this->get('config', 'loggedIn') && $this->get('config', 'login') === 'loginURL'
				? ' &bull; <a href="' . self::url('loginURL') . '">Login</a>'
				: '');
		}
		return $this->hook('footer', $output)[0];
	}

	/**
	 * Generate random password
	 * @return string
	 */
	public function generatePassword(): string
	{
		$characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcefghijklmnopqrstuvwxyz';
		return substr(str_shuffle($characters), 0, self::MIN_PASSWORD_LENGTH);
	}

	/**
	 * Get CSRF token
	 * @return string
	 * @throws Exception
	 */
	public function getToken(): string
	{
		return $_SESSION['token'] ?? $_SESSION['token'] = bin2hex(random_bytes(32));
	}

	/**
	 * Get something from database
	 */
	public function get()
	{
		$args = func_get_args();
		$object = $this->db;

		foreach ($args as $key => $arg) {
			$object = $object->{$arg} ?? $this->set(...array_merge($args, [null]));
		}

		return $object;
	}

	/**
	 * Get content of a file from master branch
	 *
	 * @param string $file the file we want
	 * @param string $repo
	 * @return string
	 */
	public function getFileFromRepo(string $file, string $repo = self::WCMS_REPO): string
	{
		$repo = str_replace('https://github.com/', 'https://raw.githubusercontent.com/', $repo);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_URL, $repo . $file);
		$content = curl_exec($ch);
		if (false === $content) {
			$this->alert('danger', 'Cannot get content from repository.');
		}
		curl_close($ch);

		return (string)$content;
	}

	/**
	 * Get the latest version from master branch
	 * @param string $repo
	 * @return null|string
	 */
	public function getOfficialVersion(string $repo = self::WCMS_REPO): ?string
	{
		return $this->getCheckFileFromRepo('version', $repo);
	}

	/**
	 * Get the files from master branch
	 * @param string $fileName
	 * @param string $repo
	 * @return null|string
	 */
	public function getCheckFileFromRepo(string $fileName, string $repo = self::WCMS_REPO): ?string
	{
		$version = trim($this->getFileFromRepo($fileName, $repo));
		return $version === '404: Not Found' || $version === '400: Invalid request' ? null : $version;
	}

	/**
	 * Compare token with hash_equals
	 *
	 * @param string $token
	 * @return bool
	 */
	public function hashVerify(string $token): bool
	{
		return hash_equals($token, $this->getToken());
	}

	/**
	 * Return hooks from plugins
	 * @return array
	 */
	public function hook(): array
	{
		$numArgs = func_num_args();
		$args = func_get_args();
		if ($numArgs < 2) {
			trigger_error('Insufficient arguments', E_USER_ERROR);
		}
		$hookName = array_shift($args);
		if (!isset($this->listeners[$hookName])) {
			return $args;
		}
		foreach ($this->listeners[$hookName] as $func) {
			$args = $func($args);
		}
		return $args;
	}

	/**
	 * Return array with all themes and their data
	 * @param string $type
	 * @return array
	 * @throws Exception
	 */
	public function listAllThemesPlugins(string $type = self::THEMES_DIR): array
	{
		$newData = [];
		if ($this->get('config', 'loggedIn')) {
			$data = $this->getThemePluginCachedData($type);

			foreach ($data as $repo => $addon) {
				$dirName = $addon['dirName'];
				$exists = is_dir($this->rootDir . "/$type/" . $dirName);
				$currentVersion = $exists ? $this->getThemePluginVersion($type, $dirName) : null;
				$newVersion = $addon['newVersion'];
				$update = $newVersion !== null && $currentVersion !== null && $newVersion > $currentVersion;
				if ($update) {
					$this->alert('info',
						'New ' . $type . ' update available. <b><a data-toggle="modal" href="#settingsModal" data-target-tab="#' . $type . '">Open ' . $type . '</a></b>');
				}

				$addonType = $exists ? self::THEME_PLUGINS_TYPES['exists'] : self::THEME_PLUGINS_TYPES['installs'];
				$addonType = $update ? self::THEME_PLUGINS_TYPES['updates'] : $addonType;

				$newData[$addonType][$repo] = $addon;
				$newData[$addonType][$repo]['update'] = $update;
				$newData[$addonType][$repo]['install'] = !$exists;
				$newData[$addonType][$repo]['currentVersion'] = $currentVersion;
			}
		}

		return $newData;
	}

	/**
	 * Get all repos from CDN
	 * @param string $type
	 * @return array
	 * @throws Exception
	 */
	public function getThemePluginRepos(string $type = self::THEMES_DIR): array
	{
		$db = $this->getDb();
		$array = (array)$db->config->defaultRepos->{$type};
		$arrayCustom = (array)$db->config->customRepos->{$type};
		$data = $this->getJsonFileData($this->themesPluginsCachePath);
		$lastSync = $db->config->defaultRepos->lastSync;
		if (empty($array) || empty($data) || strtotime($lastSync) < strtotime('-1 days')) {
			$this->updateAndCacheThemePluginRepos();
			$array = (array)$db->config->defaultRepos->{$type};
		}

		return array_merge($array, $arrayCustom);
	}

	/**
	 * Retrieve cached Themes/Plugins data
	 * @param string $type
	 * @return array|null
	 * @throws Exception
	 */
	public function getThemePluginCachedData(string $type = self::THEMES_DIR): array
	{
		$this->getThemePluginRepos($type);
		$data = $this->getJsonFileData($this->themesPluginsCachePath);
		return $data !== null && array_key_exists($type, $data) ? $data[$type] : [];
	}

	/**
	 * Force cache refresh for updates
	 */
	public function manuallyRefreshCacheData(): void
	{
		if (!isset($_REQUEST['manuallyResetCacheData']) || !$this->verifyFormActions(true)) {
			return;
		}
		$this->updateAndCacheThemePluginRepos();
		$this->checkWcmsCoreUpdate();
		$this->set('config', 'defaultRepos', 'lastSync', date('Y/m/d'));
		$this->redirect();
	}

	/**
	 * Method checks for new repos and caches them
	 */
	private function updateAndCacheThemePluginRepos(): void
	{
		$plugins = trim($this->getFileFromRepo('plugins-list.json', self::WCMS_CDN_REPO));
		$themes = trim($this->getFileFromRepo('themes-list.json', self::WCMS_CDN_REPO));
		if ($plugins !== '404: Not Found') {
			$plugins = explode("\n", $plugins);
			$this->set('config', 'defaultRepos', 'plugins', $plugins);
		}
		if ($themes !== '404: Not Found') {
			$themes = explode("\n", $themes);
			$this->set('config', 'defaultRepos', 'themes', $themes);
		}

		$this->set('config', 'defaultRepos', 'lastSync', date('Y/m/d'));
		$this->cacheThemesPluginsData();
	}

	/**
	 * Cache themes and plugins data
	 */
	private function cacheThemesPluginsData(): void
	{
		$returnArray = [];
		$db = $this->getDb();
		$array = (array)$db->config->defaultRepos;
		$arrayCustom = (array)$db->config->customRepos;
		$savedData = $this->getJsonFileData($this->themesPluginsCachePath);

		foreach ($array as $type => $repos) {
			if ($type === 'lastSync') {
				continue;
			}
			$concatenatedRepos = array_merge((array)$repos, (array)$arrayCustom[$type]);

			foreach ($concatenatedRepos as $repo) {
				$repoData = $this->downloadThemePluginsData($repo, $type, $savedData);
				if (null === $repoData) {
					continue;
				}

				$returnArray[$type][$repo] = $repoData;
			}
		}

		$this->save($this->themesPluginsCachePath, (object)$returnArray);
	}

	/**
	 * Cache single theme or plugin data
	 * @param string $repo
	 * @param string $type
	 */
	private function cacheSingleCacheThemePluginData(string $repo, string $type): void
	{
		$returnArray = $this->getJsonFileData($this->themesPluginsCachePath);

		$repoData = $this->downloadThemePluginsData($repo, $type, $returnArray);
		if (null === $repoData) {
			return;
		}

		$returnArray[$type][$repo] = $repoData;
		$this->save($this->themesPluginsCachePath, (object)$returnArray);
	}

	/**
	 * Gathers single theme/plugin data from repository
	 * @param string $repo
	 * @param string $type
	 * @param array $savedData
	 * @return array|null
	 */
	private function downloadThemePluginsData(string $repo, string $type, ?array $savedData = []): ?array
	{
		$branch = 'master';
		$repoParts = explode('/', $repo);
		$name = array_pop($repoParts);
		$repoReadmeUrl = sprintf('%s/blob/%s/README.md', $repo, $branch);
		$repoFilesUrl = sprintf('%s/%s/', $repo, $branch);
		$repoZipUrl = sprintf('%s/archive/%s.zip', $repo, $branch);
		$newVersion = $this->getOfficialVersion($repoFilesUrl);
		if (empty($repo) || empty($name) || $newVersion === null) {
			return null;
		}

		$image = $savedData[$type][$repo]['image'] ?? $this->getCheckFileFromRepo('preview.jpg', $repoFilesUrl);

		return [
			'name' => ucfirst(str_replace('-', ' ', $name)),
			'dirName' => $name,
			'repo' => $repo,
			'zip' => $repoZipUrl,
			'newVersion' => htmlentities($newVersion),
			'image' => $image !== null
				? str_replace('https://github.com/', 'https://raw.githubusercontent.com/',
					$repoFilesUrl) . 'preview.jpg'
				: null,
			'readme' => htmlentities($this->getCheckFileFromRepo('summary', $repoFilesUrl)),
			'readmeUrl' => $repoReadmeUrl,
		];
	}

	/**
	 * Add custom repository links for themes and plugins
	 */
	public function addCustomThemePluginRepository(): void
	{
		if (!isset($_POST['pluginThemeUrl'], $_POST['pluginThemeType']) || !$this->verifyFormActions()) {
			return;
		}
		$type = $_POST['pluginThemeType'];
		$url = rtrim(trim($_POST['pluginThemeUrl']), '/');
		$defaultRepositories = (array)$this->get('config', 'defaultRepos', $type);
		$customRepositories = (array)$this->get('config', 'customRepos', $type);
		$errorMessage = null;
		switch (true) {
			case strpos($url, 'https://github.com/') === false && strpos($url, 'https://gitlab.com/') === false:
				$errorMessage = 'Invalid repository URL. Only GitHub and GitLab are supported.';
				break;
			case in_array($url, $defaultRepositories, true) || in_array($url, $customRepositories, true):
				$errorMessage = 'Repository already exists.';
				break;
			case $this->getOfficialVersion(sprintf('%s/master/', $url)) === null:
				$errorMessage = 'Repository not added - missing version file.';
				break;
		}
		if ($errorMessage !== null) {
			$this->alert('danger', $errorMessage);
			$this->redirect();
		}

		$customRepositories[] = $url;
		$this->set('config', 'customRepos', $type, $customRepositories);
		$this->cacheSingleCacheThemePluginData($url, $type);
		$this->alert('success',
			'Repository successfully added to <a data-toggle="modal" href="#settingsModal" data-target-tab="#' . $type . '">' . ucfirst($type) . '</b></a>.');
		$this->redirect();
	}

	/**
	 * Read plugin version
	 * @param string $type
	 * @param string $name
	 * @return string|null
	 */
	public function getThemePluginVersion(string $type, string $name): ?string
	{
		$version = null;
		$path = sprintf('%s/%s/%s', $this->rootDir, $type, $name);
		$versionPath = $path . '/version';
		if (is_dir($path) && is_file($versionPath)) {
			$version = trim(file_get_contents($versionPath));
		}

		return $version;
	}

	/**
	 * Install and update theme
	 * @throws Exception
	 */
	public function installUpdateThemePluginAction(): void
	{
		if (!isset($_REQUEST['installThemePlugin'], $_REQUEST['type']) || !$this->verifyFormActions(true)) {
			return;
		}

		$url = $_REQUEST['installThemePlugin'];
		$type = $_REQUEST['type'];
		$path = sprintf('%s/%s/', $this->rootDir, $type);
		$folders = explode('/', str_replace('/archive/master.zip', '', $url));
		$folderName = array_pop($folders);

		if (in_array($type, self::VALID_DIRS, true)) {
			$zipFile = $this->filesPath . '/ZIPFromURL.zip';
			$zipResource = fopen($zipFile, 'w');
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_FILE, $zipResource);
			curl_exec($ch);
			$curlError = curl_error($ch);
			curl_close($ch);
			$zip = new \ZipArchive;
			if ($curlError || $zip->open($zipFile) !== true || (stripos($url, '.zip') === false)) {
				$this->recursiveDelete($this->rootDir . '/data/files/ZIPFromURL.zip');
				$this->alert('danger',
					'Error opening ZIP file.' . ($curlError ? ' Error description: ' . $curlError : ''));
				$this->redirect();
			}
			$zip->extractTo($path);
			$zip->close();
			$this->recursiveDelete($this->rootDir . '/data/files/ZIPFromURL.zip');
			$this->recursiveDelete($path . $folderName);
			if (!rename($path . $folderName . '-master', $path . $folderName)) {
				throw new Exception('Theme or plugin not installed. Possible cause: themes or plugins folder is not writable.');
			}
			$this->alert('success', 'Successfully installed/updated ' . $folderName . '.');
			$this->redirect();
		}
	}

	/**
	 * Verify if admin is logged in and has verified token for POST calls
	 * @param bool $isRequest
	 * @return bool
	 */
	public function verifyFormActions(bool $isRequest = false): bool
	{
		return ($isRequest ? isset($_REQUEST['token']) : isset($_POST['token'])) && $this->get('config',
				'loggedIn') && $this->hashVerify($isRequest ? $_REQUEST['token'] : $_POST['token']);
	}

	/**
	 * Load JS and enable plugins to load JS
	 * @return string
	 * @throws Exception
	 */
	public function js(): string
	{
		if ($this->get('config', 'loggedIn')) {
			$scripts = <<<EOT
<script src="https://cdn.jsdelivr.net/npm/autosize@4.0.2/dist/autosize.min.js" integrity="sha384-gqYjRLBp7SeF6PCEz2XeqqNyvtxuzI3DuEepcrNHbrO+KG3woVNa/ISn/i8gGtW8" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/taboverride@4.0.3/build/output/taboverride.min.js" integrity="sha384-fYHyZra+saKYZN+7O59tPxgkgfujmYExoI6zUvvvrKVT1b7krdcdEpTLVJoF/ap1" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery.taboverride@4.0.0/build/jquery.taboverride.min.js" integrity="sha384-RU4BFEU2qmLJ+oImSowhm+0Py9sT+HUD71kZz1i0aWjBfPx+15Y1jmC8gMk1+1W4" crossorigin="anonymous"></script>
<script type="text/javascript">

function fieldSave(id, newContent, dataTarget, dataMenu, dataVisibility, oldContent) {
    if (newContent !== oldContent) {
        $("#save").show(), $.post("", {
            fieldname: id,
            token: token,
            content: newContent,
            target: dataTarget,
            menu: dataMenu,
            visibility: dataVisibility
        }, function(a) {}).always(function() {
            window.location.reload()
        })
    } else {
        const target = $('#' + id);
        target.removeClass('editTextOpen');
        target.html(newContent)
    }
}

function editableTextArea(editableTarget) {
    const data = (target = editableTarget, content = target.html(), title = target.attr("title") ? '"' + target.attr("title") + '" ' : '', targetId = target.attr('id'), "<textarea " + title + ' id="' + targetId + "_field\" onblur=\"" + "fieldSave(targetId,this.value,target.data('target'),target.data('menu'),target.data('visibility'), content);" + "\">" + content + "</textarea>");
    editableTarget.html(data)
}
$("#settingsModal").on("show.bs.modal", function(t) {
    var e = $(t.relatedTarget);
    $("a[href='" + e.data("target-tab") + "']").tab("show")
});
$(document).tabOverride(!0, "textarea");
$(document).ready(function() {
    $("body").on("click", "[data-loader-id]", function(t) {
        $("#" + $(t.target).data("loader-id")).show()
    });
    $("body").on("click", "div.editText:not(.editTextOpen)", function() {
        const target = $(this);
        target.addClass('editTextOpen');
        editableTextArea(target);
        target.children(':first').focus();
        autosize($('textarea'))
    });
    $("body").on("click", "i.menu-toggle", function() {
        var t = $(this),
            e = (setTimeout(function() {
                window.location.reload()
            }, 500), t.attr("data-menu"));
        t.hasClass("menu-item-hide") ? (t.removeClass("eyeShowIcon menu-item-hide").addClass("eyeHideIcon menu-item-show"), t.attr("title", "Hide page from menu").attr("data-visibility", "hide"), $.post("", {
            fieldname: "menuItems",
            token: token,
            content: " ",
            target: "menuItemVsbl",
            menu: e,
            visibility: "hide"
        }, function(t) {})) : t.hasClass("menu-item-show") && (t.removeClass("eyeHideIcon menu-item-show").addClass("eyeShowIcon menu-item-hide"), t.attr("title", "Show page in menu").attr("data-visibility", "show"), $.post("", {
            fieldname: "menuItems",
            token: token,
            content: " ",
            target: "menuItemVsbl",
            menu: e,
            visibility: "show"
        }, function(t) {}))
    });
    $("body").on("click", ".menu-item-add", function() {
        var t = prompt("Enter page name");
        if (!t) return !1;
        t = t.replace(/[`~;:'",.<>\{\}\[\]\\\/]/gi, "").trim(), $.post("", {
            fieldname: "menuItems",
            token: token,
            content: t,
            target: "menuItem",
            menu: "none"
        }, function(t) {}).done(setTimeout(function() {
            window.location.reload()
        }, 500))
    });
    $("body").on("click", ".menu-item-up,.menu-item-down", function() {
        var t = $(this),
            e = t.hasClass("menu-item-up") ? "-1" : "1",
            n = t.attr("data-menu");
        $.post("", {
            fieldname: "menuItems",
            token: token,
            content: e,
            target: "menuItemOrder",
            menu: n
        }, function(t) {}).done(function() {
            $("#menuSettings").parent().load("index.php #menuSettings")
        })
    })
})
</script>
EOT;
			$scripts .= '<script>const token = "' . $this->getToken() . '";</script>';
			$scripts .= '<script>const rootURL = "' . $this->url() . '";</script>';

			return $this->hook('js', $scripts)[0];
		}
		return $this->hook('js', '')[0];
	}

	/**
	 * Load plugins (if any exist)
	 * @return void
	 */
	public function loadPlugins(): void
	{
		$plugins = $this->rootDir . '/plugins';
		if (!is_dir($plugins) && !mkdir($plugins) && !is_dir($plugins)) {
			return;
		}
		if (!is_dir($this->filesPath) && !mkdir($this->filesPath) && !is_dir($this->filesPath)) {
			return;
		}
		foreach (glob($plugins . '/*', GLOB_ONLYDIR) as $dir) {
			if (file_exists($dir . '/' . basename($dir) . '.php')) {
				include $dir . '/' . basename($dir) . '.php';
			}
		}
	}

	/**
	 * Loads theme files and functions.php file (if they exists)
	 * @return void
	 */
	public function loadThemeAndFunctions(): void
	{
		$location = $this->rootDir . '/themes/' . $this->get('config', 'theme');
		if (file_exists($location . '/functions.php')) {
			require_once $location . '/functions.php';
		}
		require_once $location . '/theme.php';
	}

	/**
	 * Admin login verification
	 * @return void
	 */
	public function loginAction(): void
	{
		if ($this->currentPage !== $this->get('config', 'login')) {
			return;
		}
		if ($this->get('config', 'loggedIn')) {
			$this->redirect();
		}
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			return;
		}
		$password = $_POST['password'] ?? '';
		if (password_verify($password, $this->get('config', 'password'))) {
			session_regenerate_id(true);
			$_SESSION['loggedIn'] = true;
			$_SESSION['rootDir'] = $this->rootDir;
			$this->set('config', 'forceLogout', false);
			$this->saveAdminLoginIP();
			$this->redirect();
		}
		$this->alert('danger', 'Wrong password.');
		$this->redirect($this->get('config', 'login'));
	}

	/**
	 * Save admins last 5 IPs
	 */
	private function saveAdminLoginIP(): void
	{
		$getAdminIP = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
		if (!$savedIPs = $this->get('config', 'lastLogins')) {
			$this->set('config', 'lastLogins', []);
			$savedIPs = [];
		}
		$savedIPs = (array)$savedIPs;
		$savedIPs[date('Y/m/d H:i:s')] = $getAdminIP;
		krsort($savedIPs);
		$this->set('config', 'lastLogins', array_slice($savedIPs, 0, 5));
	}

	/**
	 * Check if admin is logged in
	 * @return void
	 */
	public function loginStatus(): void
	{
		$loginStatus = $this->get('config', 'forceLogout')
			? false
			: isset($_SESSION['loggedIn'], $_SESSION['rootDir']) && $_SESSION['rootDir'] === $this->rootDir;
		$this->set('config', 'loggedIn', $loginStatus);
		$this->loggedIn = $this->get('config', 'loggedIn');
	}

	/**
	 * Login form view
	 * @return array
	 */
	public function loginView(): array
	{
		return [
			'title' => 'Login',
			'description' => '',
			'keywords' => '',
			'content' => '
				<div id="login" style="color:#ccc;left:0;top:0;width:100%;height:100%;display:none;position:fixed;text-align:center;padding-top:100px;background:rgba(51,51,51,.8);z-index:2448"><h2>Logging in and checking for updates</h2><p>This might take a minute, updates are checked once per day.</p></div>
				<form action="' . self::url($this->get('config', 'login')) . '" method="post">
					<div class="input-group">
						<input type="password" class="form-control" id="password" name="password" autofocus>
						<span class="input-group-btn input-group-append">
							<button type="submit" class="btn btn-info" onclick="$(\'#login\').show();">Login</button>
						</span>
					</div>
				</form>'
		];
	}

	/**
	 * Logout action
	 * @param bool $forceLogout
	 * @return void
	 */
	public function logoutAction(bool $forceLogout = false): void
	{
		if ($forceLogout
			|| ($this->currentPage === 'logout'
				&& isset($_REQUEST['token'])
				&& $this->hashVerify($_REQUEST['token']))) {
			unset($_SESSION['loggedIn'], $_SESSION['rootDir'], $_SESSION['token'], $_SESSION['alert']);
			$this->redirect($this->get('config', 'login'));
		}
	}

	/**
	 * Return menu items, if they are set to be visible
	 * @return string
	 */
	public function menu(): string
	{
		$output = '';
		foreach ($this->get('config', 'menuItems') as $item) {
			if ($item->visibility === 'hide') {
				continue;
			}
			$output .=
				'<li class="' . ($this->currentPage === $item->slug ? 'active ' : '') . 'nav-item">
					<a class="nav-link" href="' . self::url($item->slug) . '">' . $item->name . '</a>
				</li>';
		}
		if ($this->get('config', 'loggedIn')) {
		    $output .= "<a data-toggle='modal' href='#settingsModal' data-target-tab='#menu'><i class='editIcon'></i></a>";
		}
		return $this->hook('menu', $output)[0];
	}

	/**
	 * 404 header response
	 * @return void
	 */
	public function notFoundResponse(): void
	{
		if (!$this->get('config', 'loggedIn') && !$this->currentPageExists && $this->headerResponseDefault) {
			$this->headerResponse = 'HTTP/1.1 404 Not Found';
		}
	}

	/**
	 * Return 404 page to visitors
	 * Admin can create a page that doesn't exist yet
	 */
	public function notFoundView()
	{
		if ($this->get('config', 'loggedIn')) {
			return [
				'title' => str_replace('-', ' ', $this->currentPage),
				'description' => '',
				'keywords' => '',
				'content' => '<h2>Click to create content</h2>'
			];
		}
		return $this->get('pages', '404');
	}

	/**
	 * Admin notifications
	 * Alerts for non-existent pages, changing default settings, new version/update
	 * @return void
	 * @throws Exception
	 */
	public function notifyAction(): void
	{
		if (!$this->get('config', 'loggedIn')) {
			return;
		}
		if (!$this->currentPageExists) {
			$this->alert(
				'info',
				'<b>This page (' . $this->currentPage . ') doesn\'t exist.</b> Click inside the content below to create it.'
			);
		}
		if ($this->get('config', 'login') === 'loginURL') {
			$this->alert('danger',
				'Change your default password and login URL. <a data-toggle="modal" href="#settingsModal" data-target-tab="#security"><b>Open security settings</b></a>');
		}

		$db = $this->getDb();
		$lastSync = $db->config->defaultRepos->lastSync;
		if (strtotime($lastSync) < strtotime('-1 days')) {
			$this->checkWcmsCoreUpdate();
		}
	}

	/**
	 * Checks if there is new Wcms version
	 */
	private function checkWcmsCoreUpdate(): void
	{
		$onlineVersion = $this->getOfficialVersion();
		if ($onlineVersion > VERSION) {
			$this->alert(
				'info',
				'<h3>New WonderCMS update available</h3>
				<p>&nbsp;- Backup your website and
				<a href="https://wondercms.com/whatsnew" target="_blank"><u>check what\'s new</u></a> before updating.</p>
				 <form action="' . self::url($this->currentPage) . '" method="post" class="marginTop5">
					<button type="submit" class="btn btn-info marginTop20" name="backup"><i class="installIcon"></i>Download backup</button>
					<div class="clear"></div>
					<button class="btn btn-info marginTop5" name="update"><i class="refreshIcon"></i>Update WonderCMS ' . VERSION . ' to ' . $onlineVersion . '</button>
					<input type="hidden" name="token" value="' . $this->getToken() . '">
				</form>'
			);
		}
	}

	/**
	 * Reorder the pages
	 *
	 * @param int $content 1 for down arrow or -1 for up arrow
	 * @param int $menu
	 * @return void
	 */
	public function orderMenuItem(int $content, int $menu): void
	{
		// check if content is 1 or -1 as only those values are acceptable
		if (!in_array($content, [1, -1])) {
			return;
		}
		$conf = 'config';
		$field = 'menuItems';
		$targetPosition = $menu + $content;
		// save the target to avoid overwrite
		// use clone to copy the object entirely
		$tmp = clone $this->get($conf, $field, $targetPosition);
		$move = $this->get($conf, $field, $menu);
		// move the menu item to new position
		$this->set($conf, $field, $targetPosition, 'name', $move->name);
		$this->set($conf, $field, $targetPosition, 'slug', $move->slug);
		$this->set($conf, $field, $targetPosition, 'visibility', $move->visibility);
		// write the other menu item to the previous position
		$this->set($conf, $field, $menu, 'name', $tmp->name);
		$this->set($conf, $field, $menu, 'slug', $tmp->slug);
		$this->set($conf, $field, $menu, 'visibility', $tmp->visibility);
	}

	/**
	 * Return pages and display correct view (actual page or 404)
	 * Display different content and editable areas for admin
	 *
	 * @param string $key
	 * @return string
	 */
	public function page(string $key): string
	{
		$segments = $this->currentPageExists
			? $this->get('pages', $this->currentPage)
			: ($this->get('config', 'login') === $this->currentPage
				? (object)$this->loginView()
				: (object)$this->notFoundView());
		$segments->content = $segments->content ?? '<h2>Click here add content</h2>';
		$keys = [
			'title' => $segments->title,
			'description' => $segments->description,
			'keywords' => $segments->keywords,
			'content' => $this->get('config', 'loggedIn')
				? $this->editable('content', $segments->content, 'pages')
				: $segments->content
		];
		$content = $keys[$key] ?? '';
		return $this->hook('page', $content, $key)[0];
	}

	/**
	 * Page status (exists or doesn't exist)
	 * @return void
	 */
	public function pageStatus(): void
	{
		$this->currentPage = empty($this->parseUrl()) ? $this->get('config', 'defaultPage') : $this->parseUrl();
		if (isset($this->get('pages')->{$this->currentPage})) {
			$this->currentPageExists = true;
		}
		if (isset($_GET['page']) && !$this->get('config',
				'loggedIn') && $this->currentPage !== $this->slugify($_GET['page'])) {
			$this->currentPageExists = false;
		}
	}

	/**
	 * URL parser
	 * @return string
	 */
	public function parseUrl(): string
	{
		if (isset($_GET['page']) && $_GET['page'] === $this->get('config', 'login')) {
			return htmlspecialchars($_GET['page'], ENT_QUOTES);
		}
		return isset($_GET['page']) ? $this->slugify($_GET['page']) : '';
	}

	/**
	 * Recursive delete - used for deleting files, themes, plugins
	 *
	 * @param string $file
	 * @return void
	 */
	public function recursiveDelete(string $file): void
	{
		if (is_dir($file)) {
			$files = new DirectoryIterator($file);
			foreach ($files as $dirFile) {
				if (!$dirFile->isDot()) {
					$dirFile->isDir() ? $this->recursiveDelete($dirFile->getPathname()) : unlink($dirFile->getPathname());
				}
			}
			rmdir($file);
		} elseif (is_file($file)) {
			unlink($file);
		}
	}

	/**
	 * Redirect to any URL
	 *
	 * @param string $location
	 * @return void
	 */
	public function redirect(string $location = ''): void
	{
		header('Location: ' . self::url($location));
		die();
	}

	/**
	 * Save object to disk (default is set for DB)
	 * @param string|null $path
	 * @param object|null $content
	 * @return void
	 */
	public function save(string $path = null, object $content = null): void
	{
		$path = $path ?? $this->dbPath;
		$content = $content ?? $this->db;
		file_put_contents(
			$path,
			json_encode($content, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
		);
	}

	/**
	 * Saving menu items, default page, login URL, theme, editable content
	 * @return void
	 * @throws Exception
	 */
	public function saveAction(): void
	{
		if (!$this->get('config', 'loggedIn')) {
			return;
		}
		if (isset($_SESSION['redirect_to'])) {
			$newUrl = $_SESSION['redirect_to'];
			$newPageName = $_SESSION['redirect_to_name'];
			unset($_SESSION['redirect_to'], $_SESSION['redirect_to_name']);
			$this->alert('success', "Page <b>$newPageName</b> created.");
			$this->redirect($newUrl);
		}
		if (isset($_POST['fieldname'], $_POST['content'], $_POST['target'], $_POST['token'])
			&& $this->hashVerify($_POST['token'])) {
			[$fieldname, $content, $target, $menu, $visibility] = $this->hook('save', $_POST['fieldname'],
				$_POST['content'], $_POST['target'], $_POST['menu'], ($_POST['visibility'] ?? 'hide'));
			if ($target === 'menuItem') {
				$this->createMenuItem($content, $menu, $visibility);
				$_SESSION['redirect_to_name'] = $content;
				$_SESSION['redirect_to'] = $this->slugify($content);
			}
			if ($target === 'menuItemVsbl') {
				$this->set('config', $fieldname, $menu, 'visibility', $visibility);
			}
			if ($target === 'menuItemOrder') {
				$this->orderMenuItem($content, $menu);
			}
			if ($fieldname === 'defaultPage' && !isset($this->get('pages')->$content)) {
				return;
			}
			if ($fieldname === 'login' && (empty($content) || isset($this->get('pages')->$content))) {
				return;
			}
			if ($fieldname === 'theme' && !is_dir($this->rootDir . '/themes/' . $content)) {
				return;
			}
			if ($target === 'config') {
				$this->set('config', $fieldname, $content);
			} elseif ($target === 'blocks') {
				$this->set('blocks', $fieldname, 'content', $content);
			} elseif ($target === 'pages') {
				if (!isset($this->get('pages')->{$this->currentPage})) {
					$this->createPage();
				}
				$this->set('pages', $this->currentPage, $fieldname, $content);
			}
		}
	}

	/**
	 * Set something to database
	 * @return void
	 */
	public function set(): void
	{
		$numArgs = func_num_args();
		$args = func_get_args();
		switch ($numArgs) {
			case 2:
				$this->db->{$args[0]} = $args[1];
				break;
			case 3:
				$this->db->{$args[0]}->{$args[1]} = $args[2];
				break;
			case 4:
				$this->db->{$args[0]}->{$args[1]}->{$args[2]} = $args[3];
				break;
			case 5:
				$this->db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]} = $args[4];
				break;
		}
		$this->save();
	}

	/**
	 * Display admin settings panel
	 * @return string
	 * @throws Exception
	 */
	public function settings(): string
	{
		if (!$this->get('config', 'loggedIn')) {
			return '';
		}
		$fileList = array_slice(scandir($this->filesPath), 2);
		$output = '
		<div id="save" class="loader-overlay"><h2><i class="animationLoader"></i><br />Saving</h2></div>
		<div id="cache" class="loader-overlay"><h2><i class="animationLoader"></i><br />Checking for updates</h2></div>
		<div id="adminPanel">
			<a data-toggle="modal" class="btn btn-info btn-sm settings button" href="#settingsModal"><i class="settingsIcon"></i> Settings </a> <a href="' . self::url('logout&token=' . $this->getToken()) . '" class="btn btn-danger btn-sm button logout" title="Logout"><i class="logoutIcon"></i></a>
			<div class="modal fade-scale" id="settingsModal">
				<div class="modal-dialog modal-xl">
				 <div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button></div>
					<div class="modal-body col-xs-12 col-12">
						<ul class="nav nav-tabs justify-content-center text-center" role="tablist">
							<li role="presentation" class="nav-item"><a href="#currentPage" aria-controls="currentPage" role="tab" data-toggle="tab" class="nav-link active">Current page</a></li>
							<li role="presentation" class="nav-item"><a href="#menu" aria-controls="menu" role="tab" data-toggle="tab" class="nav-link">Menu</a></li>
							<li role="presentation" class="nav-item"><a href="#files" aria-controls="files" role="tab" data-toggle="tab" class="nav-link">Files</a></li>
							<li role="presentation" class="nav-item"><a href="#themes" aria-controls="themes" role="tab" data-toggle="tab" class="nav-link">Themes</a></li>
							<li role="presentation" class="nav-item"><a href="#plugins" aria-controls="plugins" role="tab" data-toggle="tab" class="nav-link">Plugins</a></li>
							<li role="presentation" class="nav-item"><a href="#security" aria-controls="security" role="tab" data-toggle="tab" class="nav-link">Security</a></li>
						</ul>
						<div class="tab-content col-md-8 col-md-offset-2 offset-md-2">
							<div role="tabpanel" class="tab-pane active" id="currentPage">';
		if ($this->currentPageExists) {
			$output .= '
									<p class="subTitle">Page title</p>
									<div class="change">
										<div data-target="pages" id="title" class="editText">' . ($this->get('pages',
					$this->currentPage)->title != '' ? $this->get('pages', $this->currentPage)->title : '') . '</div>
									</div>
									<p class="subTitle">Page keywords</p>
									<div class="change">
										<div data-target="pages" id="keywords" class="editText">' . ($this->get('pages',
					$this->currentPage)->keywords != '' ? $this->get('pages', $this->currentPage)->keywords : '') . '</div>
									</div>
									<p class="subTitle">Page description</p>
									<div class="change">
										<div data-target="pages" id="description" class="editText">' . ($this->get('pages',
					$this->currentPage)->description != '' ? $this->get('pages',
					$this->currentPage)->description : '') . '</div>
									</div>
									<a href="' . self::url('?delete=' . $this->currentPage . '&token=' . $this->getToken()) . '" class="btn btn-danger pull-right marginTop40" title="Delete page" onclick="return confirm(\'Delete ' . $this->currentPage . '?\')"><i class="deleteIconInButton"></i> Delete page</a>';
		} else {
			$output .= 'This page doesn\'t exist. More settings will be displayed here after this page is created.';
		}
		$output .= '
							</div>
							<div role="tabpanel" class="tab-pane" id="menu">';
		$items = $this->get('config', 'menuItems');
		reset($items);
		$first = key($items);
		end($items);
		$end = key($items);
		$output .= '
							 <p class="subTitle">Menu</p>
							 <div>
								<div id="menuSettings" class="container-fluid">';
		foreach ($items as $key => $value) {
			$output .= '
										<div class="row marginTop5">
											<div class="col-xs-1 col-sm-1 col-1 text-right">
											 <i class="btn menu-toggle ' . ($value->visibility === 'show' ? ' eyeShowIcon menu-item-hide' : ' eyeHideIcon menu-item-show') . '" data-toggle="tooltip" title="' . ($value->visibility === 'show' ? 'Hide page from menu' : 'Show page in menu') . '" data-menu="' . $key . '"></i>
											</div>
											<div class="col-xs-4 col-4 col-sm-8">
											 <div data-target="menuItem" data-menu="' . $key . '" data-visibility="' . $value->visibility . '" id="menuItems-' . $key . '" class="editText">' . $value->name . '</div>
											</div>
											<div class="col-xs-2 col-2 col-sm-1 text-left">';
			$output .= ($key === $first) ? '' : '<a class="upArrowIcon toolbar menu-item-up cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move up"></a>';
			$output .= ($key === $end) ? '' : ' <a class="downArrowIcon toolbar menu-item-down cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move down"></a>';
			$output .= '
											</div>
											<div class="col-xs-2 col-2 col-sm-1 text-left">
											 <a class="linkIcon" href="' . self::url($value->slug) . '" title="Visit page">visit</a>
											</div>
											<div class="col-xs-2 col-2 col-sm-1 text-right">
											 <a href="' . self::url('?delete=' . $value->slug . '&token=' . $this->getToken()) . '" title="Delete page" class="btn btn-sm btn-danger" data-menu="' . $key . '" onclick="return confirm(\'Delete ' . $value->slug . '?\')"><i class="deleteIcon"></i></a>
											</div>
										</div>';
		}
		$output .= '<a class="menu-item-add btn btn-info marginTop20 cursorPointer" data-toggle="tooltip" title="Add new page"><i class="addNewIcon"></i> Add page</a>
								</div>
							 </div>
							 <p class="subTitle">Website title</p>
							 <div class="change">
								<div data-target="config" id="siteTitle" class="editText">' . $this->get('config', 'siteTitle') . '</div>
							 </div>
							 <p class="subTitle">Page to display on homepage</p>
							 <div class="change">
								<select class="form-control" name="defaultPage" onchange="fieldSave(\'defaultPage\',this.value,\'config\');">';
		$items = $this->get('config', 'menuItems');
		foreach ($items as $key => $value) {
			$output .= '<option value="' . $value->slug . '"' . ($value->slug === $this->get('config',
					'defaultPage') ? ' selected' : '') . '>' . $value->name . '</option>';
		}
		$output .= '
								</select>
							</div>
							</div>
							<div role="tabpanel" class="tab-pane" id="files">
							 <p class="subTitle">Upload</p>
							 <div class="change">
								<form action="' . self::url($this->currentPage) . '" method="post" enctype="multipart/form-data">
									<div class="input-group"><input type="file" name="uploadFile" class="form-control">
										<span class="input-group-btn"><button type="submit" class="btn btn-info input-group-append"><i class="uploadIcon"></i>Upload</button></span>
										<input type="hidden" name="token" value="' . $this->getToken() . '">
									</div>
								</form>
							 </div>
							 <p class="subTitle marginTop20">Delete files</p>
							 <div class="change">';
		foreach ($fileList as $file) {
			$output .= '
									<a href="' . self::url('?deleteThemePlugin=' . $file . '&type=files&token=' . $this->getToken()) . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Delete ' . $file . '?\')" title="Delete file"><i class="deleteIcon"></i></a>
									<span class="marginLeft5">
										<a href="' . self::url('data/files/') . $file . '" class="normalFont" target="_blank">' . self::url('data/files/') . '<b class="fontSize21">' . $file . '</b></a>
									</span>
									<p></p>';
		}
		$output .= '
							 </div>
							</div>';
		$output .= $this->renderThemePluginTab();
		$output .= $this->renderThemePluginTab('plugins');
		$output .= '		<div role="tabpanel" class="tab-pane" id="security">
							 <p class="subTitle">Admin login URL</p>
							 <div class="change">
								<div data-target="config" id="login" class="editText">' . $this->get('config',
				'login') . '</div>
								<p class="marginTop5 small">Save your login URL to log into your website next time:<br/> <span class="normalFont"><b>' . self::url($this->get('config',
				'login')) . '</b></span>
							 </div>
							 <p class="subTitle">Password</p>
							 <div class="change">
								<form action="' . self::url($this->currentPage) . '" method="post">
									<div class="input-group">
										<input type="password" name="old_password" class="form-control normalFont" placeholder="Old password">
										<span class="input-group-btn"></span><input type="password" name="new_password" class="form-control normalFont" placeholder="New password">
										<span class="input-group-btn input-group-append"><button type="submit" class="btn btn-info"><i class="lockIcon"></i> Change password</button></span>
									</div>
									<input type="hidden" name="fieldname" value="password"><input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="subTitle">Improved security (Apache only)</p>
							 <p class="change small">HTTPS redirect, 30 day caching, iframes allowed only from same origin, mime type sniffing prevention, stricter cookie and refferer policy.</p>
							 <div class="change">
								<form method="post">
									<div class="btn-group btn-group-justified w-100">
										<div class="btn-group w-50"><button type="submit" class="btn btn-info" name="betterSecurity" value="on">ON (warning: may break your website)</button></div>
										<div class="btn-group w-50"><button type="submit" class="btn btn-danger" name="betterSecurity" value="off">OFF (reset htaccess to default)</button></div>
									</div>
									<input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Better-security-mode-(HTTPS-and-other-features)#important-read-before-turning-this-feature-on" target="_blank"><i class="linkIcon"></i> Read more before enabling</a></p>';
		$output .= $this->renderAdminLoginIPs();
		$output .= '
							 <p class="subTitle">Backup</p>
							 <div class="change">
								<form action="' . self::url($this->currentPage) . '" method="post">
									<button type="submit" class="btn btn-block btn-info" name="backup"><i class="installIcon"></i> Backup website</button><input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>	
							 <p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Restore-backup#how-to-restore-a-backup-in-3-steps" target="_blank"><i class="linkIcon"></i> How to restore backup</a></p>

		                   </div>

						</div>
					</div>
					<div class="modal-footer clear">
						<p class="small">
							<a href="https://wondercms.com" target="_blank">WonderCMS ' . VERSION . '</a> &nbsp;
							<b><a href="https://wondercms.com/whatsnew" target="_blank">News</a> &nbsp;
							<a href="https://wondercms.com/community" target="_blank">Community</a> &nbsp;
							<a href="https://github.com/robiso/wondercms/wiki#wondercms-documentation" target="_blank">Docs</a> &nbsp;
							<a href="https://wondercms.com/donate" target="_blank">Donate</a></b>
						</p>
					</div>
				 </div>
				</div>
			</div>
		</div>';
		return $this->hook('settings', $output)[0];
	}

	/**
	 * Render last login IPs
	 * @return string
	 */
	private function renderAdminLoginIPs(): string
	{
		$getIPs = $this->get('config', 'lastLogins') ?? [];
		$renderIPs = '';
		foreach ($getIPs as $time => $adminIP) {
			$renderIPs .= sprintf('<li>%s - %s</li>', date('M d, Y H:i:s', strtotime($time)), $adminIP);
		}
		return '<p class="subTitle">Last 5 logins</p>
				<div class="change">
					<ul>' . $renderIPs . '</ul>
				</div>';
	}

	/**
	 * Render Plugins/Themes cards
	 * @param string $type
	 * @return string
	 * @throws Exception
	 */
	private function renderThemePluginTab(string $type = 'themes'): string
	{
		$output = '<div role="tabpanel" class="tab-pane" id="' . $type . '">
					<a class="btn btn-info btn-sm pull-right float-right marginTop20 marginBottom20" data-loader-id="cache" href="' . self::url('?manuallyResetCacheData=true&token=' . $this->getToken()) . '" title="Check updates"><i class="refreshIcon" aria-hidden="true"></i> Check for updates</a>
					<div class="clear"></div>
					<div class="change row custom-cards">';
		$defaultImage = '<svg style="max-width: 100%;" xmlns="http://www.w3.org/2000/svg" width="100%" height="140"><text x="50%" y="50%" font-size="18" text-anchor="middle" alignment-baseline="middle" font-family="monospace, sans-serif" fill="#ddd">No preview</text></svg>';
		$updates = $exists = $installs = '';
		foreach ($this->listAllThemesPlugins($type) as $addonType => $addonRepos) {
			foreach ($addonRepos as $addon) {
				$name = $addon['name'];
				$info = $addon['readme'];
				$infoUrl = $addon['readmeUrl'];
				$currentVersion = $addon['currentVersion'] ? sprintf('Installed version: %s',
					$addon['currentVersion']) : '';
				$directoryName = $addon['dirName'];
				$isThemeSelected = $this->get('config', 'theme') === $directoryName;

				$image = $addon['image'] !== null ? '<a class="text-center center-block" href="' . $addon['image'] . '" target="_blank"><img style="max-width: 100%; max-height: 250px;" src="' . $addon['image'] . '" alt="' . $name . '" /></a>' : $defaultImage;
				$installButton = $addon['install'] ? '<a class="btn btn-success btn-block btn-sm" href="' . self::url('?installThemePlugin=' . $addon['zip'] . '&type=' . $type . '&token=' . $this->getToken()) . '" title="Install"><i class="installIcon"></i> Install</a>' : '';
				$updateButton = !$addon['install'] && $addon['update'] ? '<a class="btn btn-info btn-sm btn-block marginTop5" href="' . self::url('?installThemePlugin=' . $addon['zip'] . '&type=' . $type . '&token=' . $this->getToken()) . '" title="Update"><i class="refreshIcon"></i> Update to ' . $addon['newVersion'] . '</a>' : '';
				$removeButton = !$addon['install'] ? '<a class="btn btn-danger btn-sm marginTop5" href="' . self::url('?deleteThemePlugin=' . $directoryName . '&type=' . $type . '&token=' . $this->getToken()) . '" onclick="return confirm(\'Remove ' . $name . '?\')" title="Remove"><i class="deleteIcon"></i></a>' : '';
				$inactiveThemeButton = $type === 'themes' && !$addon['install'] && !$isThemeSelected ? '<a class="btn btn-primary btn-sm btn-block" href="' . self::url('?selectThemePlugin=' . $directoryName . '&type=' . $type . '&token=' . $this->getToken()) . '" onclick="return confirm(\'Activate ' . $name . ' theme?\')"><i class="checkmarkIcon"></i> Activate</a>' : '';
				$activeThemeButton = $type === 'themes' && !$addon['install'] && $isThemeSelected ? '<a class="btn btn-primary btn-sm btn-block" disabled>Active</a>' : '';

				$html = "<div class='col-sm-4'>
							<div>
								$image
								<h4>$name</h4>
								<p class='normalFont'>$info</p>
								<p class='text-right small normalFont marginTop20'>$currentVersion<br /><a href='$infoUrl' target='_blank'><i class='linkIcon'></i> More info</a></p>
								<div class='text-right'>$inactiveThemeButton $activeThemeButton</div>
								<div class='text-left'>$installButton</div>
								<div class='text-right'><span class='text-left bold'>$updateButton</span> <span class='text-right'>$removeButton</span></div>
							</div>
						</div>";

				switch ($addonType) {
					case self::THEME_PLUGINS_TYPES['updates']:
						$updates .= $html;
						break;
					case self::THEME_PLUGINS_TYPES['exists']:
						$exists .= $html;
						break;
					case self::THEME_PLUGINS_TYPES['installs']:
					default:
						$installs .= $html;
						break;
				}
			}
		}
		$output .= $updates;
		$output .= $exists;
		$output .= $installs;
		$output .= '</div>
					<p class="subTitle">Custom repository</p>
					<form action="' . self::url($this->currentPage) . '" method="post">
						<div class="form-group">
							<div class="change input-group marginTop5"><input type="text" name="pluginThemeUrl" class="form-control normalFont" placeholder="Enter URL to custom repository">
								<span class="input-group-btn input-group-append"><button type="submit" class="btn btn-info"><i class="addNewIcon"></i> Add</button></span>
							</div>
						</div>
						<input type="hidden" name="token" value="' . $this->getToken() . '" /><input type="hidden" name="pluginThemeType" value="' . $type . '" />
					</form>
					<p class="text-right"><a href="https://github.com/robiso/wondercms/wiki/Custom-repositories" target="_blank"><i class="linkIcon"></i> Read more about custom repositories</a></p>
				</div>';
		return $output;
	}

	/**
	 * Slugify page
	 *
	 * @param string $text for slugifying
	 * @return string
	 */
	public function slugify(string $text): string
	{
		$text = preg_replace('~[^\\pL\d]+~u', '-', $text);
		$text = trim(htmlspecialchars(mb_strtolower($text), ENT_QUOTES), '/');
		$text = trim($text, '-');
		return empty($text) ? '-' : $text;
	}

	/**
	 * Delete something from database
	 * Has variadic arguments
	 * @return void
	 */
	public function unset(): void
	{
		$numArgs = func_num_args();
		$args = func_get_args();
		switch ($numArgs) {
			case 1:
				unset($this->db->{$args[0]});
				break;
			case 2:
				unset($this->db->{$args[0]}->{$args[1]});
				break;
			case 3:
				unset($this->db->{$args[0]}->{$args[1]}->{$args[2]});
				break;
			case 4:
				unset($this->db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]});
				break;
		}
		$this->save();
	}

	/**
	 * Update WonderCMS
	 * Overwrites index.php with latest version from GitHub
	 * @return void
	 */
	public function updateAction(): void
	{
		if (!isset($_POST['update']) || !$this->verifyFormActions()) {
			return;
		}
		$contents = $this->getFileFromRepo('index.php');
		if ($contents) {
			file_put_contents(__FILE__, $contents);
			$this->alert('success', 'WonderCMS successfully updated. Wohoo!');
			$this->redirect();
		}
		$this->alert('danger', 'Something went wrong. Could not update WonderCMS.');
		$this->redirect();
	}

	/**
	 * Upload file to files folder
	 * List of allowed extensions
	 * @return void
	 */
	public function uploadFileAction(): void
	{
		if (!isset($_FILES['uploadFile']) || !$this->verifyFormActions()) {
			return;
		}
		$allowedExtensions = [
			'avi' => 'video/avi',
			'css' => 'text/css',
			'css' => 'text/x-asm',
			'doc' => 'application/vnd.ms-word',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'flv' => 'video/x-flv',
			'gif' => 'image/gif',
			'htm' => 'text/html',
			'html' => 'text/html',
			'ico' => 'image/x-icon',
			'jpg' => 'image/jpeg',
			'kdbx' => 'application/octet-stream',
			'm4a' => 'audio/mp4',
			'mkv' => 'video/x-matroska',
			'mov' => 'video/quicktime',
			'mp3' => 'audio/mpeg',
			'mp4' => 'video/mp4',
			'mpg' => 'video/mpeg',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
			'odt' => 'application/vnd.oasis.opendocument.text',
			'ogg' => 'application/ogg',
			'ogv' => 'video/ogg',
			'pdf' => 'application/pdf',
			'png' => 'image/png',
			'ppt' => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'psd' => 'application/photoshop',
			'rar' => 'application/rar',
			'svg' => 'image/svg',
			'svg' => 'image/svg+xml',
			'svg' => 'application/svg+xm',
			'txt' => 'text/plain',
			'xls' => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'webm' => 'video/webm',
			'wmv' => 'video/x-ms-wmv',
			'zip' => 'application/zip',
		];
		if (!isset($_FILES['uploadFile']['error']) || is_array($_FILES['uploadFile']['error'])) {
			$this->alert('danger', 'Invalid parameters.');
			$this->redirect();
		}
		switch ($_FILES['uploadFile']['error']) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_NO_FILE:
				$this->alert('danger',
					'No file selected. <a data-toggle="modal" href="#settingsModal" data-target-tab="#files"><b>Re-open file options</b></a>');
				$this->redirect();
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				$this->alert('danger',
					'File too large. Change maximum upload size limit or contact your host. <a data-toggle="modal" href="#settingsModal" data-target-tab="#files"><b>Re-open file options</b></a>');
				$this->redirect();
				break;
			default:
				$this->alert('danger', 'Unknown error.');
				$this->redirect();
		}
		$mimeType = '';
		if (class_exists('finfo')) {
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$mimeType = $finfo->file($_FILES['uploadFile']['tmp_name']);
		} elseif (function_exists('mime_content_type')) {
			$mimeType = mime_content_type($_FILES['uploadFile']['tmp_name']);
		} else {
			$nameExploded = explode('.', $_FILES['uploadFile']['name']);
			$ext = strtolower(array_pop($nameExploded));
			if (array_key_exists($ext, $allowedExtensions)) {
				$mimeType = $allowedExtensions[$ext];
			}
		}
		if (!in_array($mimeType, $allowedExtensions, true)) {
			$this->alert('danger',
				'File format is not allowed. <a data-toggle="modal" href="#settingsModal" data-target-tab="#files"><b>Re-open file options</b></a>');
			$this->redirect();
		}
		if (!move_uploaded_file($_FILES['uploadFile']['tmp_name'],
			$this->filesPath . '/' . basename($_FILES['uploadFile']['name']))) {
			$this->alert('danger', 'Failed to move uploaded file.');
		}
		$this->alert('success',
			'File uploaded. <a data-toggle="modal" href="#settingsModal" data-target-tab="#files"><b>Open file options to see your uploaded file</b></a>');
		$this->redirect();
	}

	/**
	 * Get canonical URL
	 *
	 * @param string $location
	 * @return string
	 */
	public static function url(string $location = ''): string
	{
		return 'http' . ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on')
			|| (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on')
			|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 's' : '')
			. '://' . $_SERVER['SERVER_NAME']
			. ((($_SERVER['SERVER_PORT'] == '80') || ($_SERVER['SERVER_PORT'] == '443')) ? '' : ':' . $_SERVER['SERVER_PORT'])
			. ((dirname($_SERVER['SCRIPT_NAME']) === '/') ? '' : dirname($_SERVER['SCRIPT_NAME']))
			. '/' . $location;
	}

	/**
	 * Create a ZIP backup of whole WonderCMS installation (all files)
	 *
	 * @return void
	 */
	public function zipBackup(): void
	{
		try {
			$randomNumber = random_bytes(8);
		} catch (Exception $e) {
			$randomNumber = microtime(false);
		}
		$zipName = date('Y-m-d') . '-backup-' . bin2hex($randomNumber) . '.zip';
		$zipPath = $this->rootDir . '/data/files/' . $zipName;
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
			$this->alert('danger', 'Cannot create ZIP archive.');
		}
		$iterator = new RecursiveDirectoryIterator($this->rootDir);
		$iterator->setFlags(RecursiveDirectoryIterator::SKIP_DOTS);
		$files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST);
		foreach ($files as $file) {
			$file = realpath($file);
			$source = realpath($this->rootDir);
			if (is_dir($file)) {
				$zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
			} elseif (is_file($file)) {
				$zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
			}
		}
		$zip->close();
		$this->redirect('data/files/' . $zipName);
	}

	/**
	 * Check compatibility
	 */
	private function checkMinimumRequirements(): void
	{
		if (PHP_VERSION_ID <= 70200) {
			die('<p>To run WonderCMS, PHP version 7.2 or greater is required.</p>');
		}
		$extensions = ['curl', 'zip', 'mbstring'];
		$missingExtensions = [];
		foreach ($extensions as $ext) {
			if (!extension_loaded($ext)) {
				$missingExtensions[] = $ext;
			}
		}
		if (!empty($missingExtensions)) {
			die('<p>The following extensions are required: '
				. implode(', ', $missingExtensions)
				. '. Please contact your host or configure your server to enable them.</p>');
		}
	}
}
