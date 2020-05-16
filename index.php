<?php
/**
 * @package WonderCMS
 * @author Robert Isoski
 * @see https://www.wondercms.com
 * @license MIT
 */

session_start();
define('VERSION', '3.0.8');
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
					'This page (' . $this->currentPage . ') is currently hidden from the menu. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#menu"><b>Open menu visibility settings</b></a>');
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
				'Backup files detected. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#files"><b>View and delete unnecessary backup files</b></a>');
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
					'Wrong password. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#security"><b>Re-open security settings</b></a>');
				$this->redirect();
			}
			if (strlen($_POST['new_password']) < self::MIN_PASSWORD_LENGTH) {
				$this->alert('danger',
					sprintf('Password must be longer than %d characters. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#security"><b>Re-open security settings</b></a>',
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
				'theme' => 'essence',
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/robiso/wondercms-cdn-files@3.1.8/wcms-admin.min.css" integrity="sha384-8eXsopCWgkcT3ix3mugkJX9Hrh0YJ5evW7O6vevD+W2Flrxne+vT2TQaoC3vBhOQ" crossorigin="anonymous">
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
					'Cannot delete currently active theme. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#themes"><b>Re-open theme settings</b></a>');
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
		 $output = $this->get('config', 'siteTitle');
		if ($this->get('config', 'loggedIn')) {
			 $output .= "<a data-toggle='wcms-modal' href='#settingsModal' data-target-tab='#menu'><i class='editIcon'></i></a>";
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
		$characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
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
						'New ' . $type . ' update available. <b><a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#' . $type . '">Open ' . $type . '</a></b>');
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
			'Repository successfully added to <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#' . $type . '">' . ucfirst($type) . '</b></a>.');
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
<script src="https://cdn.jsdelivr.net/gh/robiso/wondercms-cdn-files@3.1.8/wcms-admin.min.js" integrity="sha384-Hgory8HXpKm6VvI8PvRC808Vl87hLg8vqakmNbIFU9cNpZ6LA8ICxtVOABs2mDXX" crossorigin="anonymous"></script>
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
			 $output .= "<a data-toggle='wcms-modal' href='#settingsModal' data-target-tab='#menu'><i class='editIcon'></i></a>";
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
				'Change your default password and login URL. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#security"><b>Open security settings</b></a>');
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
			<a data-toggle="wcms-modal" class="btn btn-info btn-sm settings button" href="#settingsModal"><i class="settingsIcon"></i> Settings </a> <a href="' . self::url('logout&token=' . $this->getToken()) . '" class="btn btn-danger btn-sm button logout" title="Logout"><i class="logoutIcon"></i></a>
			<div class="wcms-modal modal" id="settingsModal">
				<div class="modal-dialog modal-xl">
				 <div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="wcms-modal" aria-hidden="true">&times;</button></div>
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
		$output .= '<a class="menu-item-add btn btn-info marginTop20 cursorPointer" data-toggle="tooltip" id="menuItemAdd" title="Add new page"><i class="addNewIcon"></i> Add page</a>
								</div>
							 </div>
							 <p class="subTitle">Website title</p>
							 <div class="change">
								<div data-target="config" id="siteTitle" class="editText">' . $this->get('config', 'siteTitle') . '</div>
							 </div>
							 <p class="subTitle">Page to display on homepage</p>
							 <div class="change">
								<select id="changeDefaultPage" class="form-control" name="defaultPage">';
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
					'No file selected. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#files"><b>Re-open file options</b></a>');
				$this->redirect();
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				$this->alert('danger',
					'File too large. Change maximum upload size limit or contact your host. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#files"><b>Re-open file options</b></a>');
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
				'File format is not allowed. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#files"><b>Re-open file options</b></a>');
			$this->redirect();
		}
		if (!move_uploaded_file($_FILES['uploadFile']['tmp_name'],
			$this->filesPath . '/' . basename($_FILES['uploadFile']['name']))) {
			$this->alert('danger', 'Failed to move uploaded file.');
		}
		$this->alert('success',
			'File uploaded. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#files"><b>Open file options to see your uploaded file</b></a>');
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
