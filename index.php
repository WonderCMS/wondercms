<?php
/**
 * @package WonderCMS
 * @author Robert Isoski
 * @see https://www.wondercms.com
 * @license MIT
 */

session_start();
define('VERSION', '3.4.1');
mb_internal_encoding('UTF-8');

if (defined('PHPUNIT_TESTING') === false) {
	$Wcms = new Wcms();
	$Wcms->init();
	$Wcms->render();
}

class Wcms
{
	private const MODULES_JSON_VERSION = 1;
	private const THEMES_DIR = 'themes';
	private const PLUGINS_DIR = 'plugins';
	private const VALID_DIRS = [self::THEMES_DIR, self::PLUGINS_DIR];
	private const THEME_PLUGINS_TYPES = [
		'installs' => 'install',
		'updates' => 'update',
		'exists' => 'exist',
	];

	/** Database main keys */
	public const DB_CONFIG = 'config';
	public const DB_MENU_ITEMS = 'menuItems';
	public const DB_MENU_ITEMS_SUBPAGE = 'subpages';
	public const DB_PAGES_KEY = 'pages';
	public const DB_PAGES_SUBPAGE_KEY = 'subpages';

	/** @var int MIN_PASSWORD_LENGTH minimum number of characters */
	public const MIN_PASSWORD_LENGTH = 8;

	/** @var string WCMS_REPO - repo URL */
	public const WCMS_REPO = 'https://raw.githubusercontent.com/WonderCMS/wondercms/main/';

	/** @var string WCMS_CDN_REPO - CDN repo URL */
	public const WCMS_CDN_REPO = 'https://raw.githubusercontent.com/WonderCMS/wondercms-cdn-files/main/';

	/** @var string $currentPage - current page */
	public $currentPage = '';

	/** @var array $currentPageTree - Tree hierarchy of the current page */
	public $currentPageTree = [];

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

	/** @var string $modulesCachePath path to cached json file with Themes/Plugins data */
	protected $modulesCachePath;

	/** @var string $securityCachePath path to security json file with force https caching data */
	protected $securityCachePath;

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
		$this->modulesCachePath = sprintf('%s/%s', $this->dataPath, 'cache.json');
		$this->securityCachePath = sprintf('%s/%s', $this->dataPath, 'security.json');
	}

	/**
	 * Init function called on each page load
	 *
	 * @return void
	 * @throws Exception
	 */
	public function init(): void
	{
		$this->forceSSL();
		$this->loginStatus();
		$this->pageStatus();
		$this->logoutAction();
		$this->loginAction();
		$this->notFoundResponse();
		$this->loadPlugins();
		if ($this->loggedIn) {
			$this->manuallyRefreshCacheData();
			$this->addCustomModule();
			$this->installUpdateModuleAction();
			$this->changePasswordAction();
			$this->deleteFileModuleAction();
			$this->changePageThemeAction();
			$this->backupAction();
			$this->forceHttpsAction();
			$this->saveChangesPopupAction();
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
		if ($this->loggedIn) {
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
		$output .= '<div id="alertWrapperId" class="alertWrapper" style="">';
		$output .= '<script>
					const displayAlerts = localStorage.getItem("displayAlerts");
					if (displayAlerts === "false") {
						const alertWrapper = document.getElementById("alertWrapperId");
						if (alertWrapper) {
							alertWrapper.style.display = "none";
						}
					}
					</script>';
		foreach ($_SESSION['alert'] as $alertClass) {
			foreach ($alertClass as $alert) {
				$output .= '<div class="alert alert-'
					. $alert['class']
					. (!$alert['sticky'] ? ' alert-dismissible' : '')
					. '">'
					. (!$alert['sticky'] ? '<button type="button" class="close" data-dismiss="alert" onclick="parentNode.remove();">&times;</button>' : '')
					. $alert['message']
					. $this->hideAlerts();
			}
		}
		$output .= '</div>';
		unset($_SESSION['alert']);
		return $output;
	}

	/**
	 * Allow admin to dismiss alerts
	 * @return string
	 */
	public function hideAlerts(): string
	{
		if (!$this->loggedIn) {
			return '';
		}
		$output = '';
		$output .= '<br><a href="" onclick="localStorage.setItem(\'displayAlerts\', \'false\');"><small>Hide all alerts until next login</small></a></div>';
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
		if (!$this->loggedIn) {
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
	 * Save if WCMS should force https
	 * @return void
	 * @throws Exception
	 */
	public function forceHttpsAction(): void
	{
		if (isset($_POST['forceHttps']) && $this->verifyFormActions()) {
			$this->set('config', 'forceHttps', $_POST['forceHttps'] === 'true');
			$this->updateSecurityCache();

			$this->alert('success', 'Force HTTPs was successfully changed.');
			$this->redirect();
		}
	}

	/**
	 * Save if WCMS should show the popup before saving the page content changes
	 * @return void
	 * @throws Exception
	 */
	public function saveChangesPopupAction(): void
	{
		if (isset($_POST['saveChangesPopup']) && $this->verifyFormActions()) {
			$this->set('config', 'saveChangesPopup', $_POST['saveChangesPopup'] === 'true');
			$this->alert('success', 'Saving the confirmation popup settings changed.');
			$this->redirect();
		}
	}

	/**
	 * Update cache for security settings.
	 * @return void
	 */
	public function updateSecurityCache(): void
	{
		$content = ['forceHttps' => $this->isHttpsForced()];
		$json = json_encode($content, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		file_put_contents($this->securityCachePath, $json, LOCK_EX);
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
			$content = $this->loggedIn
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
		if (isset($_POST['old_password'], $_POST['new_password'], $_POST['repeat_password'])
			&& $_SESSION['token'] === $_POST['token']
			&& $this->loggedIn
			&& $this->hashVerify($_POST['token'])) {
			if (!password_verify($_POST['old_password'], $this->get('config', 'password'))) {
				$this->alert('danger',
					'Wrong password. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#security"><b>Re-open security settings</b></a>');
				$this->redirect();
				return;
			}
			if (strlen($_POST['new_password']) < self::MIN_PASSWORD_LENGTH) {
				$this->alert('danger',
					sprintf('Password must be longer than %d characters. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#security"><b>Re-open security settings</b></a>',
						self::MIN_PASSWORD_LENGTH));
				$this->redirect();
				return;
			}
			if ($_POST['new_password'] !== $_POST['repeat_password']) {
				$this->alert('danger',
					'New passwords do not match. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#security"><b>Re-open security settings</b></a>');
				$this->redirect();
				return;
			}
			$this->set('config', 'password', password_hash($_POST['new_password'], PASSWORD_DEFAULT));
			$this->set('config', 'forceLogout', true);
			$this->logoutAction(true);
			$this->alert('success', '<center><b>Password changed. Log in again.</b></center>', 1);
		}
	}

	/**
	 * Check if folders are writable
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
	 * Initialize the JSON database if it doesn't exist
	 * @return void
	 */
	public function createDb(): void
	{
		// Check php requirements
		$this->checkMinimumRequirements();
		$password = $this->generatePassword();
		$this->db = (object)[
			self::DB_CONFIG => [
				'siteTitle' => 'Website title',
				'theme' => 'sky',
				'defaultPage' => 'home',
				'login' => 'loginURL',
				'forceLogout' => false,
				'forceHttps' => false,
				'saveChangesPopup' => false,
				'password' => password_hash($password, PASSWORD_DEFAULT),
				'lastLogins' => [],
				'lastModulesSync' => null,
				'customModules' => $this->defaultCustomModules(),
				'menuItems' => [
					'0' => [
						'name' => 'Home',
						'slug' => 'home',
						'visibility' => 'show',
						self::DB_MENU_ITEMS_SUBPAGE => new stdClass()
					],
					'1' => [
						'name' => 'How to',
						'slug' => 'how-to',
						'visibility' => 'show',
						self::DB_MENU_ITEMS_SUBPAGE => new stdClass()
					]
				]
			],
			'pages' => [
				'404' => [
					'title' => '404',
					'keywords' => '404',
					'description' => '404',
					'content' => '<center><h1>404 - Page not found</h1></center>',
					self::DB_PAGES_SUBPAGE_KEY => new stdClass()
				],
				'home' => [
					'title' => 'Home',
					'keywords' => 'Enter, page, keywords, for, search, engines',
					'description' => 'A page description is also good for search engines.',
					'content' => '<h1>Welcome to your website</h1>

<p>Your password for editing everything is: <b>' . $password . '</b></p>

<p><a href="' . self::url('loginURL') . '" class="button">Click here to login</a></p>

<p>To install an awesome editor, open Settings/Plugins and click Install Summernote.</p>',
					self::DB_PAGES_SUBPAGE_KEY => new stdClass()
				],
				'how-to' => [
					'title' => 'How to',
					'keywords' => 'Enter, keywords, for, this page',
					'description' => 'A page description is also good for search engines.',
					'content' => '<h2>Easy editing</h2>
<p>After logging in, click anywhere to edit and click outside to save. Changes are live and shown immediately.</p>

<h2>Create new page</h2>
<p>Pages can be created in the Settings.</p>

<h2>Start a blog or change your theme</h2>
<p>To install, update or remove themes/plugins, visit the Settings.</p>

<h2><b>Support WonderCMS</b></h2>
<p>WonderCMS is free for over 12 years.<br>
<a href="https://swag.wondercms.com" target="_blank"><u>Click here to support us by getting a T-shirt</u></a> or <a href="https://www.wondercms.com/donate" target="_blank"><u>with a donation</u></a>.</p>',
					self::DB_PAGES_SUBPAGE_KEY => new stdClass()
				]
			],
			'blocks' => [
				'subside' => [
					'content' => '<h2>About your website</h2>

<br>
<p>Website description, contact form, mini map or anything else.</p>
<p>This editable area is visible on all pages.</p>'
				],
				'footer' => [
					'content' => '&copy;' . date('Y') . ' Your website'
				]
			]
		];
		$this->save();
	}

	/**
	 * Default data for the Custom Modules
	 * @return array[]
	 */
	private function defaultCustomModules(): array {
		return [
			'themes' => [],
			'plugins' => []
		];
	}

	/**
	 * Create menu item
	 *
	 * @param string $name
	 * @param string|null $menu
	 * @param bool $createPage
	 * @param string $visibility show or hide
	 * @return void
	 * @throws Exception
	 */
	public function createMenuItem(
		string $name,
		string $menu = null,
		string $visibility = 'hide',
		bool $createPage = false
	): void {
		if (!in_array($visibility, ['show', 'hide'], true)) {
			return;
		}
		$name = empty($name) ? 'empty' : str_replace([PHP_EOL, '<br>'], '', $name);
		$slug = $this->createUniqueSlug($name, $menu);

		$menuItems = $menuSelectionObject = clone $this->get(self::DB_CONFIG, self::DB_MENU_ITEMS);
		$menuTree = !empty($menu) || $menu === '0' ? explode('-', $menu) : [];
		$slugTree = [];
		if (count($menuTree)) {
			foreach ($menuTree as $childMenuKey) {
				$childMenu = $menuSelectionObject->{$childMenuKey};

				if (!property_exists($childMenu, self::DB_MENU_ITEMS_SUBPAGE)) {
					$childMenu->{self::DB_MENU_ITEMS_SUBPAGE} = new StdClass;
				}

				$menuSelectionObject = $childMenu->{self::DB_MENU_ITEMS_SUBPAGE};
				$slugTree[] = $childMenu->slug;
			}
		}
		$slugTree[] = $slug;

		$menuCount = count(get_object_vars($menuSelectionObject));

		$menuSelectionObject->{$menuCount} = new stdClass;
		$menuSelectionObject->{$menuCount}->name = $name;
		$menuSelectionObject->{$menuCount}->slug = $slug;
		$menuSelectionObject->{$menuCount}->visibility = $visibility;
		$menuSelectionObject->{$menuCount}->{self::DB_MENU_ITEMS_SUBPAGE} = new StdClass;
		$this->set(self::DB_CONFIG, self::DB_MENU_ITEMS, $menuItems);

		if ($createPage) {
			$this->createPage($slugTree);
			$_SESSION['redirect_to_name'] = $name;
			$_SESSION['redirect_to'] = implode('/', $slugTree);
		}
	}

	/**
	 * Update menu item
	 *
	 * @param string $name
	 * @param string $menu
	 * @param string $visibility show or hide
	 * @return void
	 * @throws Exception
	 */
	public function updateMenuItem(string $name, string $menu, string $visibility = 'hide'): void
	{
		if (!in_array($visibility, ['show', 'hide'], true)) {
			return;
		}
		$name = empty($name) ? 'empty' : str_replace([PHP_EOL, '<br>'], '', $name);
		$slug = $this->createUniqueSlug($name, $menu);

		$menuItems = $menuSelectionObject = clone $this->get(self::DB_CONFIG, self::DB_MENU_ITEMS);
		$menuTree = explode('-', $menu);
		$slugTree = [];
		$menuKey = array_pop($menuTree);
		if (count($menuTree) > 0) {
			foreach ($menuTree as $childMenuKey) {
				$childMenu = $menuSelectionObject->{$childMenuKey};

				if (!property_exists($childMenu, self::DB_MENU_ITEMS_SUBPAGE)) {
					$childMenu->{self::DB_MENU_ITEMS_SUBPAGE} = new StdClass;
				}

				$menuSelectionObject = $childMenu->{self::DB_MENU_ITEMS_SUBPAGE};
				$slugTree[] = $childMenu->slug;
			}
		}

		$slugTree[] = $menuSelectionObject->{$menuKey}->slug;
		$menuSelectionObject->{$menuKey}->name = $name;
		$menuSelectionObject->{$menuKey}->slug = $slug;
		$menuSelectionObject->{$menuKey}->visibility = $visibility;
		$menuSelectionObject->{$menuKey}->{self::DB_MENU_ITEMS_SUBPAGE} = $menuSelectionObject->{$menuKey}->{self::DB_MENU_ITEMS_SUBPAGE} ?? new StdClass;
		$this->set(self::DB_CONFIG, self::DB_MENU_ITEMS, $menuItems);

		$this->updatePageSlug($slugTree, $slug);
		if ($this->get(self::DB_CONFIG, 'defaultPage') === implode('/', $slugTree)) {
			// Change old slug with new one
			array_pop($slugTree);
			$slugTree[] = $slug;
			$this->set(self::DB_CONFIG, 'defaultPage', implode('/', $slugTree));
		}
	}

	/**
	 * Check if slug already exists and creates unique one
	 *
	 * @param string $slug
	 * @param string|null $menu
	 * @return string
	 */
	public function createUniqueSlug(string $slug, string $menu = null): string
	{
		$slug = $this->slugify($slug);
		$allMenuItems = $this->get(self::DB_CONFIG, self::DB_MENU_ITEMS);
		$menuCount = count(get_object_vars($allMenuItems));

		// Check if it is subpage
		$menuTree = $menu ? explode('-', $menu) : [];
		if (count($menuTree)) {
			foreach ($menuTree as $childMenuKey) {
				$allMenuItems = $allMenuItems->{$childMenuKey}->subpages;
			}
		}

		foreach ($allMenuItems as $value) {
			if ($value->slug === $slug) {
				$slug .= '-' . $menuCount;
				break;
			}
		}

		return $slug;
	}

	/**
	 * Create new page
	 *
	 * @param array|null $slugTree
	 * @param bool $createMenuItem
	 * @return void
	 * @throws Exception
	 */
	public function createPage(array $slugTree = null, bool $createMenuItem = false): void
	{
		$pageExists = false;
		$pageData = null;
		foreach ($slugTree as $parentPage) {
			if (!$pageData) {
				$pageData = $this->get(self::DB_PAGES_KEY)->{$parentPage};
				continue;
			}

			$pageData = $pageData->subpages->{$parentPage} ?? null;
			$pageExists = !empty($pageData);
		}

		if ($pageExists) {
			$this->alert('danger', 'Cannot create page with existing slug.');
			return;
		}

		$slug = array_pop($slugTree);
		$pageSlug = $slug ?: $this->slugify($this->currentPage);
		$allPages = $selectedPage = clone $this->get(self::DB_PAGES_KEY);
		$menuKey = null;
		if (!empty($slugTree)) {
			foreach ($slugTree as $childSlug) {
				// Find menu key tree
				if ($createMenuItem) {
					$menuKey = $this->findAndUpdateMenuKey($menuKey, $childSlug);
				}

				// Create new parent page if it doesn't exist
				if (!$selectedPage->{$childSlug}) {
					$parentTitle = mb_convert_case(str_replace('-', ' ', $childSlug), MB_CASE_TITLE);
					$selectedPage->{$childSlug}->title = $parentTitle;
					$selectedPage->{$childSlug}->keywords = 'Keywords, are, good, for, search, engines';
					$selectedPage->{$childSlug}->description = 'A short description is also good.';

					if ($createMenuItem) {
						$this->createMenuItem($parentTitle, $menuKey);
						$menuKey = $this->findAndUpdateMenuKey($menuKey, $childSlug); // Add newly added menu key
					}
				}

				if (!property_exists($selectedPage->{$childSlug}, self::DB_PAGES_SUBPAGE_KEY)) {
					$selectedPage->{$childSlug}->{self::DB_PAGES_SUBPAGE_KEY} = new StdClass;
				}

				$selectedPage = $selectedPage->{$childSlug}->{self::DB_PAGES_SUBPAGE_KEY};
			}
		}

		$pageTitle = !$slug ? str_replace('-', ' ', $pageSlug) : $pageSlug;

		$selectedPage->{$slug} = new stdClass;
		$selectedPage->{$slug}->title = mb_convert_case($pageTitle, MB_CASE_TITLE);
		$selectedPage->{$slug}->keywords = 'Keywords, are, good, for, search, engines';
		$selectedPage->{$slug}->description = 'A short description is also good.';
		$selectedPage->{$slug}->{self::DB_PAGES_SUBPAGE_KEY} = new StdClass;
		$this->set(self::DB_PAGES_KEY, $allPages);

		if ($createMenuItem) {
			$this->createMenuItem($pageTitle, $menuKey);
		}
	}

	/**
	 * Find and update menu key tree based on newly requested slug
	 * @param string|null $menuKey
	 * @param string $slug
	 * @return string
	 */
	private function findAndUpdateMenuKey(?string $menuKey, string $slug): string
	{
		$menuKeys = $menuKey !== null ? explode('-', $menuKey) : $menuKey;
		$menuItems = json_decode(json_encode($this->get(self::DB_CONFIG, self::DB_MENU_ITEMS)), true);
		foreach ($menuKeys as $key) {
			$menuItems = $menuItems[$key][self::DB_MENU_ITEMS_SUBPAGE] ?? [];
		}

		if (false !== ($index = array_search($slug, array_column($menuItems, 'slug'), true))) {
			$menuKey = $menuKey === null ? $index : $menuKey . '-' . $index;
		} elseif ($menuKey === null) {
			$menuKey = count($menuItems);
		}

		return $menuKey;
	}

	/**
	 * Update page data
	 *
	 * @param array $slugTree
	 * @param string $fieldname
	 * @param string $content
	 * @return void
	 * @throws Exception
	 */
	public function updatePage(array $slugTree, string $fieldname, string $content): void
	{
		$slug = array_pop($slugTree);
		$allPages = $selectedPage = clone $this->get(self::DB_PAGES_KEY);
		if (!empty($slugTree)) {
			foreach ($slugTree as $childSlug) {
				if (!property_exists($selectedPage->{$childSlug}, self::DB_PAGES_SUBPAGE_KEY)) {
					$selectedPage->{$childSlug}->{self::DB_PAGES_SUBPAGE_KEY} = new StdClass;
				}

				$selectedPage = $selectedPage->{$childSlug}->{self::DB_PAGES_SUBPAGE_KEY};
			}
		}

		$selectedPage->{$slug}->{$fieldname} = $content;
		$this->set(self::DB_PAGES_KEY, $allPages);
	}

	/**
	 * Delete page key
	 *
	 * @param array $slugTree
	 * @param string $fieldname
	 *
	 * @return void
	 * @throws Exception
	 */
	public function deletePageKey(array $slugTree, string $fieldname): void
	{
		$slug = array_pop($slugTree);
		$selectedPage = clone $this->get(self::DB_PAGES_KEY);
		if (!empty($slugTree)) {
			foreach ($slugTree as $childSlug) {
				if (!property_exists($selectedPage->{$childSlug}, self::DB_PAGES_SUBPAGE_KEY)) {
					$selectedPage->{$childSlug}->{self::DB_PAGES_SUBPAGE_KEY} = new StdClass;
				}

				$selectedPage = $selectedPage->{$childSlug}->{self::DB_PAGES_SUBPAGE_KEY};
			}
		}

		unset($selectedPage->{$slug}->{$fieldname});
		$this->save();
	}

	/**
	 * Delete existing page by slug
	 *
	 * @param array|null $slugTree
	 * @throws Exception
	 */
	public function deletePageFromDb(array $slugTree = null): void
	{
		$slug = array_pop($slugTree);

		$selectedPage = $this->db->{self::DB_PAGES_KEY};
		if (!empty($slugTree)) {
			foreach ($slugTree as $childSlug) {
				$selectedPage = $selectedPage->{$childSlug}->subpages;
			}
		}

		unset($selectedPage->{$slug});
		$this->save();
	}

	/**
	 * Update existing page slug
	 *
	 * @param array $slugTree
	 * @param string $newSlugName
	 * @throws Exception
	 */
	public function updatePageSlug(array $slugTree, string $newSlugName): void
	{
		$slug = array_pop($slugTree);

		$selectedPage = $this->db->{self::DB_PAGES_KEY};
		if (!empty($slugTree)) {
			foreach ($slugTree as $childSlug) {
				$selectedPage = $selectedPage->{$childSlug}->subpages;
			}
		}

		$selectedPage->{$newSlugName} = $selectedPage->{$slug};
		unset($selectedPage->{$slug});
		$this->save();
	}

	/**
	 * Load CSS and enable plugins to load CSS
	 * @return string
	 */
	public function css(): string
	{
		if ($this->loggedIn) {
			$styles = <<<'EOT'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/robiso/wondercms-cdn-files@3.2.25/wcms-admin.min.css" crossorigin="anonymous">
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
	public function deleteFileModuleAction(): void
	{
		if (!$this->loggedIn) {
			return;
		}
		if (isset($_REQUEST['deleteModule'], $_REQUEST['type']) && $this->verifyFormActions(true)) {
			$allowedDeleteTypes = ['files', 'plugins', 'themes'];
			$filename = str_ireplace(
				['/', './', '../', '..', '~', '~/', '\\'],
				null,
				trim($_REQUEST['deleteModule'])
			);
			$type = str_ireplace(
				['/', './', '../', '..', '~', '~/', '\\'],
				null,
				trim($_REQUEST['type'])
			);
			if (!in_array($type, $allowedDeleteTypes, true)) {
				$this->alert('danger',
					'Wrong delete folder path.');
				$this->redirect();
			}
			if ($filename === $this->get('config', 'theme')) {
				$this->alert('danger',
					'Cannot delete currently active theme. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#themes"><b>Re-open theme settings</b></a>');
				$this->redirect();
			}
			$folder = $type === 'files' ? $this->filesPath : sprintf('%s/%s', $this->rootDir, $type);
			$path = realpath("{$folder}/{$filename}");
			if (file_exists($path)) {
				$this->recursiveDelete($path);
				$this->alert('success', "Deleted {$filename}.");
				$this->redirect();
			}
		}
	}

	public function changePageThemeAction(): void
	{
		if (isset($_REQUEST['selectModule'], $_REQUEST['type']) && $this->verifyFormActions(true)) {
			$theme = $_REQUEST['selectModule'];
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
	 * @throws Exception
	 */
	public function deletePageAction(): void
	{
		if (!isset($_GET['delete']) || !$this->verifyFormActions(true)) {
			return;
		}
		$slugTree = explode('/', $_GET['delete']);
		$this->deletePageFromDb($slugTree);

		$allMenuItems = $selectedMenuItem = clone $this->get(self::DB_CONFIG, self::DB_MENU_ITEMS);
		if (count(get_object_vars($allMenuItems)) === 1 && count($slugTree) === 1) {
			$this->alert('danger', 'Last page cannot be deleted - at least one page must exist.');
			$this->redirect();
		}

		$selectedMenuItemParent = $selectedMenuItemKey = null;
		foreach ($slugTree as $slug) {
			$selectedMenuItemParent = $selectedMenuItem->{self::DB_MENU_ITEMS_SUBPAGE} ?? $selectedMenuItem;
			foreach ($selectedMenuItemParent as $menuItemKey => $menuItem) {
				if ($menuItem->slug === $slug) {
					$selectedMenuItem = $menuItem;
					$selectedMenuItemKey = $menuItemKey;
					break;
				}
			}
		}
		unset($selectedMenuItemParent->{$selectedMenuItemKey});
		$allMenuItems = $this->reindexObject($allMenuItems);

		$defaultPage = $this->get(self::DB_CONFIG, 'defaultPage');
		$defaultPageArray = explode('/', $defaultPage);
		$treeIntersect = array_intersect_assoc($defaultPageArray, $slugTree);
		if ($treeIntersect === $slugTree) {
			$this->set(self::DB_CONFIG, 'defaultPage', $allMenuItems->{0}->slug);
		}
		$this->set(self::DB_CONFIG, self::DB_MENU_ITEMS, $allMenuItems);

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
		if ($this->loggedIn) {
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
		if ($this->loggedIn) {
			$output = '<div data-target="blocks" id="footer" class="editText editable">' . $this->get('blocks',
					'footer')->content . '</div>';
		} else {
			$output = $this->get('blocks', 'footer')->content .
				(!$this->loggedIn && $this->get('config', 'login') === 'loginURL'
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
			if (!property_exists($object, $arg)) {
				$this->set(...array_merge($args, [new stdClass]));
			}

			$object = $object->{$arg};
		}

		return $object;
	}

	/**
	 * Download file content from url
	 * @param string $fileUrl
	 * @return string
	 */
	private function downloadFileFromUrl(string $fileUrl): string
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_URL, $fileUrl);
		$content = curl_exec($ch);
		if (false === $content) {
			$this->alert('danger', 'Cannot get content from url.');
		}
		curl_close($ch);

		return (string)$content;
	}

	/**
	 * Get content of a file from main branch
	 *
	 * @param string $file the file we want
	 * @param string $repo
	 * @return string
	 */
	public function getFileFromRepo(string $file, string $repo = self::WCMS_REPO): string
	{
		$repo = str_replace('https://github.com/', 'https://raw.githubusercontent.com/', $repo);
		return $this->downloadFileFromUrl($repo . $file);
	}

	/**
	 * Get the latest version from main branch
	 * @param string $repo
	 * @return null|string
	 */
	public function getOfficialVersion(string $repo = self::WCMS_REPO): ?string
	{
		return $this->getCheckFileFromRepo('version', $repo);
	}

	/**
	 * Get the files from main branch
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
	public function listAllModules(string $type = self::THEMES_DIR): array
	{
		$newData = [];
		if ($this->loggedIn) {
			$data = $this->getModulesCachedData($type);

			foreach ($data as $dirName => $addon) {
				$exists = is_dir($this->rootDir . "/$type/" . $dirName);
				$currentVersion = $exists ? $this->getModuleVersion($type, $dirName) : null;
				$newVersion = $addon['version'];
				$update = $newVersion !== null && $currentVersion !== null && $newVersion > $currentVersion;
				if ($update) {
					$this->alert('info',
						'New ' . $type . ' update available. <b><a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#' . $type . '">Open ' . $type . '</a></b>');
				}

				$addonType = $exists ? self::THEME_PLUGINS_TYPES['exists'] : self::THEME_PLUGINS_TYPES['installs'];
				$addonType = $update ? self::THEME_PLUGINS_TYPES['updates'] : $addonType;

				$newData[$addonType][$dirName] = $addon;
				$newData[$addonType][$dirName]['update'] = $update;
				$newData[$addonType][$dirName]['install'] = !$exists;
				$newData[$addonType][$dirName]['currentVersion'] = $currentVersion;
			}
		}

		return $newData;
	}

	/**
	 * Check modules for cache
	 * @return void
	 * @throws Exception
	 */
	public function checkModulesCache(): void
	{
		$db = $this->getDb();
		$data = $this->getJsonFileData($this->modulesCachePath);
		// Recreate cache if lastModulesSync is missing
		$lastSync = $db->config->lastModulesSync ?? strtotime('-2 days');
		if (empty($data) || strtotime($lastSync) < strtotime('-1 days')) {
			$this->updateAndCacheModules();
		}
	}

	/**
	 * Retrieve cached Themes/Plugins data
	 * @param string $type
	 * @return array|null
	 * @throws Exception
	 */
	public function getModulesCachedData(string $type = self::THEMES_DIR): array
	{
		$this->checkModulesCache();
		$data = $this->getJsonFileData($this->modulesCachePath);
		return $data !== null && array_key_exists($type, $data) ? $data[$type] : [];
	}

	/**
	 * Force cache refresh for updates
	 * @throws Exception
	 */
	public function manuallyRefreshCacheData(): void
	{
		if (!isset($_REQUEST['manuallyResetCacheData']) || !$this->verifyFormActions(true)) {
			return;
		}
		$this->updateAndCacheModules();
		$this->checkWcmsCoreUpdate();
		$this->set('config', 'lastModulesSync', date('Y/m/d'));
		$this->redirect();
	}

	/**
	 * Forces http to https
	 */
	private function forceSSL(): void
	{
		if ($this->isHttpsForced() && !Wcms::isCurrentlyOnSSL()) {
			$this->updateSecurityCache();
			$this->redirect();
		}
	}

	/**
	 * Method checks for new modules and caches them
	 * @throws Exception
	 */
	private function updateAndCacheModules(): void
	{
		$this->set('config', 'lastModulesSync', date('Y/m/d'));
		$this->cacheModulesData();
	}

	/**
	 * Fetch module config from url
	 * @param string $url
	 * @param string $type
	 * @return object|null
	 */
	private function fetchModuleConfig(string $url, string $type): ?object
	{
		$wcmsModules = json_decode(trim($this->downloadFileFromUrl($url)));
		$wcmsModulesData = $wcmsModules && property_exists($wcmsModules, $type)
			? $wcmsModules->{$type}
			: null;
		if (null === $wcmsModulesData) {
			$this->alert('danger', 'The wcms-modules.json file does not contain all the required information.');
			return null;
		}
		$wcmsModulesData = get_mangled_object_vars($wcmsModulesData);
		$returnData = reset($wcmsModulesData);
		$name = key($wcmsModulesData);
		$returnData->dirName = $name;
		return $returnData;
	}

	/**
	 * Update cache for default themes/plugins modules.
	 * @return void
	 * @throws Exception
	 */
	private function updateModulesCache(): void
	{
		$wcmsModules = trim($this->getFileFromRepo('wcms-modules.json', self::WCMS_CDN_REPO));
		$jsonObject = json_decode($wcmsModules);
		if (empty($jsonObject)) {
			return;
		}

		$parsedCache = $this->moduleCacheMapper($jsonObject);
		if (empty($parsedCache)) {
			return;
		}

		$this->save($this->modulesCachePath, $parsedCache);
	}

	/**
	 * Mapper between wcms-modules.json and applications cache.json
	 * @param object $wcmsModule
	 * @return object
	 */
	private function moduleCacheMapper(object $wcmsModule): object
	{
		$mappedModules = new stdClass;
		foreach ($wcmsModule as $type => $value) {
			if ($type === 'version') {
				if ($value !== self::MODULES_JSON_VERSION) {
					$this->alert('danger', 'The wcms-modules.json version is incorrect');
					break;
				}

				continue;
			}

			$mappedModules->{$type} = new stdClass();
			foreach ($value as $moduleName => $module) {
				$parsedModule = $this->moduleCacheParser($module, $moduleName);
				if (empty($parsedModule)) {
					continue;
				}

				$mappedModules->{$type}->{$moduleName} = new stdClass();
				$mappedModules->{$type}->{$moduleName} = $parsedModule;
			}
		}

		return $mappedModules;
	}

	/**
	 * Parse module cache to
	 * @param object $module
	 * @param string $moduleName
	 * @return object|null
	 */
	private function moduleCacheParser(object $module, string $moduleName): ?object {
		if (!$this->validateWcmsModuleStructure($module)) {
			return null;
		}

		return (object)[
			"name" => $module->name,
			"dirName" => $moduleName,
			"repo" => $module->repo,
			"zip" => $module->zip,
			"summary" => $module->summary,
			"version" => $module->version,
			"image" => $module->image,
		];
	}

	/**
	 * Cache themes and plugins data
	 * @throws Exception
	 */
	private function cacheModulesData(): void
	{
		$db = $this->getDb();

		// Download wcms-modules as cache
		$this->updateModulesCache();

		// Cache custom modules
		$returnArray = $this->getJsonFileData($this->modulesCachePath);

		// If custom modules is missing from the DB, we add it
		if (!property_exists($db->config, 'customModules')) {
			$this->set('config', 'customModules', $this->defaultCustomModules());
			$db = $this->getDb();
		}

		$arrayCustom = (array)$db->config->customModules;
		foreach ($arrayCustom as $type => $modules) {
			foreach ($modules as $url) {
				$wcmsModuleData = $this->fetchModuleConfig($url, $type);
				if (null === $wcmsModuleData) {
					continue;
				}

				$name = $wcmsModuleData->dirName;
				$wcmsModuleData = $this->moduleCacheParser($wcmsModuleData, $name);
				$returnArray[$type][$name] = $wcmsModuleData;
			}
		}

		$this->save($this->modulesCachePath, (object)$returnArray);
	}

	/**
	 * Cache single theme or plugin data
	 * @param string $url
	 * @param string $type
	 * @throws Exception
	 */
	private function cacheSingleCacheModuleData(string $url, string $type): void
	{
		$returnArray = $this->getJsonFileData($this->modulesCachePath);

		$wcmsModuleData = $this->fetchModuleConfig($url, $type);
		if (null === $wcmsModuleData) {
			return;
		}
		$name = $wcmsModuleData->dirName;
		$wcmsModuleData = $this->moduleCacheParser($wcmsModuleData, $name);
		$returnArray[$type][$name] = $wcmsModuleData;
		$this->save($this->modulesCachePath, (object)$returnArray);
	}

	/**
	 * Check if the module url already exists
	 * @param string $repo
	 * @param string $type
	 * @return bool
	 * @throws Exception
	 */
	private function checkIfModuleRepoExists(string $repo, string $type): bool
	{
		$data = $this->getModulesCachedData($type);
		return in_array($repo, array_column($data, 'repo'));
	}

	/**
	 * Validate structure of the wcms module json
	 * @param object $wcmsModule
	 * @return bool
	 */
	private function validateWcmsModuleStructure(object $wcmsModule): bool {
		return property_exists($wcmsModule, 'name')
			&& property_exists($wcmsModule, 'repo')
			&& property_exists($wcmsModule, 'zip')
			&& property_exists($wcmsModule, 'summary')
			&& property_exists($wcmsModule, 'version')
			&& property_exists($wcmsModule, 'image');
	}

	/**
	 * Add custom url links for themes and plugins
	 * @throws Exception
	 */
	public function addCustomModule(): void
	{
		if (!isset($_POST['pluginThemeUrl'], $_POST['pluginThemeType']) || !$this->verifyFormActions()) {
			return;
		}
		$type = $_POST['pluginThemeType'];
		$url = rtrim(trim($_POST['pluginThemeUrl']), '/');
		$customModules = (array)$this->get('config', 'customModules', $type);
		$wcmsModuleData = $this->fetchModuleConfig($url, $type);
		$errorMessage = null;
		switch (true) {
			case null === $wcmsModuleData || !$this->isValidModuleURL($url):
				$errorMessage = 'Invalid URL. The module URL needs to contain the full path to the raw wcms-modules.json file.';
				break;
			case !$this->validateWcmsModuleStructure($wcmsModuleData):
				$errorMessage = 'Module not added - the wcms-modules.json file does not contain all the required information.';
				break;
			case $this->checkIfModuleRepoExists($wcmsModuleData->repo, $type):
				$errorMessage = 'Module already exists.';
				break;
		}
		if ($errorMessage !== null) {
			$this->alert('danger', $errorMessage);
			$this->redirect();
		}

		$customModules[] = $url;
		$this->set('config', 'customModules', $type, $customModules);
		$this->cacheSingleCacheModuleData($url, $type);
		$this->alert('success',
			'Module successfully added to <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#' . $type . '">' . ucfirst($type) . '</b></a>.');
		$this->redirect();
	}

	/**
	 * Read plugin version
	 * @param string $type
	 * @param string $name
	 * @return string|null
	 */
	public function getModuleVersion(string $type, string $name): ?string
	{
		$version = null;
		$path = sprintf('%s/%s/%s', $this->rootDir, $type, $name);
		$wcmsModulesPath = $path . '/wcms-modules.json';
		$versionPath = $path . '/version';
		if (is_dir($path) && (is_file($wcmsModulesPath) || is_file($versionPath))) {
			if (is_file($wcmsModulesPath)) {
				$wcmsModules = json_decode(trim(file_get_contents($wcmsModulesPath)));
				$version = $wcmsModules->{$type}->{$name}->version;
			} else {
				$version = trim(file_get_contents($versionPath));
			}
		}

		return $version;
	}

	/**
	 * Install and update theme
	 * @throws Exception
	 */
	public function installUpdateModuleAction(): void
	{
		if (!isset($_REQUEST['installModule'], $_REQUEST['directoryName'], $_REQUEST['type']) || !$this->verifyFormActions(true)) {
			return;
		}
		$url = $_REQUEST['installModule'];
		$folderName = $_REQUEST['directoryName'];
		$type = $_REQUEST['type'];

		$path = sprintf('%s/%s/', $this->rootDir, $type);

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
			// First delete old plugin folder
			$this->recursiveDelete($path . $folderName);

			// Then extract new one
			$zip->extractTo($path);
			$zip->close();
			$this->recursiveDelete($this->rootDir . '/data/files/ZIPFromURL.zip');
			$moduleFolder = $path . $folderName . '-master';
			if (!is_dir($moduleFolder)) {
				$moduleFolder = $path . $folderName . '-main';
			}
			if (is_dir($moduleFolder) && !rename($moduleFolder, $path . $folderName)) {
				throw new Exception('Theme or plugin not installed. Possible cause: themes or plugins folder is not writable.');
			}
			$this->alert('success', 'Successfully installed/updated ' . $folderName . '.');
			$this->redirect();
		}
	}

	/**
	 * Validate if ZIP URL is from Git
	 * @param string $url
	 * @return boolean
	 */
	private function isValidGitURL(string $url): bool
	{
		return strpos($url, 'https://github.com/') !== false || strpos($url, 'https://gitlab.com/') !== false;
	}

	/**
	 * Validate if custom module url has wcms-modules.json
	 * @param string $url
	 * @return boolean
	 */
	private function isValidModuleURL(string $url): bool
	{
		return strpos($url, 'wcms-modules.json') !== false;
	}

	/**
	 * Verify if admin is logged in and has verified token for POST calls
	 * @param bool $isRequest
	 * @return bool
	 */
	public function verifyFormActions(bool $isRequest = false): bool
	{
		return ($isRequest ? isset($_REQUEST['token']) : isset($_POST['token']))
			&& $this->loggedIn
			&& $this->hashVerify($isRequest ? $_REQUEST['token'] : $_POST['token']);
	}

	/**
	 * Load JS and enable plugins to load JS
	 * @return string
	 * @throws Exception
	 */
	public function js(): string
	{
		if ($this->loggedIn) {
			$scripts = <<<EOT
<script src="https://cdn.jsdelivr.net/npm/autosize@4.0.2/dist/autosize.min.js" integrity="sha384-gqYjRLBp7SeF6PCEz2XeqqNyvtxuzI3DuEepcrNHbrO+KG3woVNa/ISn/i8gGtW8" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/taboverride@4.0.3/build/output/taboverride.min.js" integrity="sha384-fYHyZra+saKYZN+7O59tPxgkgfujmYExoI6zUvvvrKVT1b7krdcdEpTLVJoF/ap1" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/gh/robiso/wondercms-cdn-files@3.2.26/wcms-admin.min.js" integrity="sha384-lwdbkm/17hWy+Y4iBnY0iEp0FlaKvjdeTBZaRYM1DGPshGgxKoPaB87Xue26Wv1W" crossorigin="anonymous"></script>
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

		$customPageTemplate = sprintf('%s/%s.php', $location, $this->currentPage);
		require_once file_exists($customPageTemplate) ? $customPageTemplate : $location . '/theme.php';
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
		if ($this->loggedIn) {
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
		$this->alert('test', '<script>alert("Wrong password")</script>', 1);
		$this->redirect($this->get('config', 'login'));
	}

	/**
	 * Save admins last 5 IPs
	 */
	private function saveAdminLoginIP(): void
	{
		$getAdminIP = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
		if ($getAdminIP === null) {
			return;
		}

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
		$this->loggedIn = $this->get('config', 'forceLogout')
			? false
			: isset($_SESSION['loggedIn'], $_SESSION['rootDir']) && $_SESSION['rootDir'] === $this->rootDir;
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
			<style>.showUpdate{display: block !important}</style>
				<div class="wUpdate" style="display:none;color:#ccc;left:0;top:0;width:100%;height:100%;position:fixed;text-align:center;padding-top:100px;background:rgba(51,51,51,.8);z-index:2448"><h2>Logging in and checking for updates</h2><p>This might take a moment.</p></div>
				<form action="' . self::url($this->get('config', 'login')) . '" method="post">
					<div class="winput-group text-center">
						<h1>Login to your website</h1>
						<input type="password" class="wform-control" id="password" name="password" placeholder="Password" autofocus><br><br>
						<span class="winput-group-btn">
							<button type="submit" class="wbtn wbtn-info" onclick="document.getElementsByClassName(\'wUpdate\')[0].classList.toggle(\'showUpdate\'); localStorage.clear();">Login</button>
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
			$output .= $this->renderPageNavMenuItem($item);
		}
		return $this->hook('menu', $output)[0];
	}

	/**
	 * 404 header response
	 * @return void
	 */
	public function notFoundResponse(): void
	{
		if (!$this->loggedIn && !$this->currentPageExists && $this->headerResponseDefault) {
			$this->headerResponse = 'HTTP/1.1 404 Not Found';
		}
	}

	/**
	 * Return 404 page to visitors
	 * Admin can create a page that doesn't exist yet
	 */
	public function notFoundView()
	{
		if ($this->loggedIn) {
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
		if (!$this->loggedIn) {
			return;
		}
		if (!$this->currentPageExists) {
			$this->alert(
				'info',
				'<b>This page (' . $this->currentPage . ') doesn\'t exist.</b> Editing the content below will create it.'
			);
		}
		if ($this->get('config', 'login') === 'loginURL') {
			$this->alert('danger',
				'Change your login URL and save it for later use. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#security"><b>Open security settings</b></a>');
		}

		$this->checkModulesCache();
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
				<a href="https://wondercms.com/news" target="_blank"><u><b>Check what\'s new</b></u></a>
				and <b>backup your website</b> before updating.
				 <form action="' . $this->getCurrentPageUrl() . '" method="post" class="marginTop5">
					<button type="submit" class="wbtn wbtn-info marginTop20" name="backup"><i class="installIcon"></i>Download backup</button>
					<div class="clear"></div>
					<button class="wbtn wbtn-info marginTop5" name="update"><i class="refreshIcon"></i>Update WonderCMS ' . VERSION . ' to ' . $onlineVersion . '</button>
					<input type="hidden" name="token" value="' . $this->getToken() . '">
				</form>'
			);
		}
	}

	/**
	 * Update menu visibility state
	 *
	 * @param string $visibility - "show" for visible, "hide" for invisible
	 * @param string $menu
	 * @throws Exception
	 */
	public function updateMenuItemVisibility(string $visibility, string $menu): void
	{
		if (!in_array($visibility, ['show', 'hide'], true)) {
			return;
		}

		$menuTree = explode('-', $menu);
		$menuItems = $menuSelectionObject = clone $this->get(self::DB_CONFIG, self::DB_MENU_ITEMS);

		// Find sub menu item
		if ($menuTree) {
			$mainParentMenu = array_shift($menuTree);
			$menuSelectionObject = $menuItems->{$mainParentMenu};
			foreach ($menuTree as $childMenuKey) {
				$menuSelectionObject = $menuSelectionObject->subpages->{$childMenuKey};
			}
		}

		$menuSelectionObject->visibility = $visibility;
		$this->set(self::DB_CONFIG, self::DB_MENU_ITEMS, $menuItems);
	}

	/**
	 * Reorder the pages
	 *
	 * @param int $content 1 for down arrow or -1 for up arrow
	 * @param string $menu
	 * @return void
	 * @throws Exception
	 */
	public function orderMenuItem(int $content, string $menu): void
	{
		// check if content is 1 or -1 as only those values are acceptable
		if (!in_array($content, [1, -1], true)) {
			return;
		}
		$menuTree = explode('-', $menu);
		$mainParentMenu = $selectedMenuKey = array_shift($menuTree);
		$menuItems = $menuSelectionObject = clone $this->get(self::DB_CONFIG, self::DB_MENU_ITEMS);

		// Sorting of subpages in menu
		if ($menuTree) {
			$selectedMenuKey = array_pop($menuTree);
			$menuSelectionObject = $menuItems->{$mainParentMenu}->subpages;
			foreach ($menuTree as $childMenuKey) {
				$menuSelectionObject = $menuSelectionObject->{$childMenuKey}->subpages;
			}
		}

		$targetPosition = $selectedMenuKey + $content;

		// Find and switch target and selected menu position in DB
		$selectedMenu = $menuSelectionObject->{$selectedMenuKey};
		$targetMenu = $menuSelectionObject->{$targetPosition};
		$menuSelectionObject->{$selectedMenuKey} = $targetMenu;
		$menuSelectionObject->{$targetPosition} = $selectedMenu;

		$this->set(self::DB_CONFIG, self::DB_MENU_ITEMS, $menuItems);
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
		$segments = $this->getCurrentPageData();
		if (!$this->currentPageExists || !$segments) {
			$segments = $this->get('config', 'login') === $this->currentPage
				? (object)$this->loginView()
				: (object)$this->notFoundView();
		}

		$segments->content = $segments->content ?? '<h2>Click here add content</h2>';
		$keys = [
			'title' => $segments->title,
			'description' => $segments->description,
			'keywords' => $segments->keywords,
			'content' => $this->loggedIn
				? $this->editable('content', $segments->content, 'pages')
				: $segments->content
		];
		$content = $keys[$key] ?? '';
		return $this->hook('page', $content, $key)[0];
	}

	/**
	 * Return database data of current page
	 *
	 * @return object|null
	 */
	public function getCurrentPageData(): ?object
	{
		return $this->getPageData(implode('/', $this->currentPageTree));
	}

	/**
	 * Return database data of any page
	 *
	 * @param string $slugTree
	 * @return object|null
	 */
	public function getPageData(string $slugTree): ?object
	{
		$arraySlugTree = explode('/', $slugTree);
		$pageData = null;
		foreach ($arraySlugTree as $slug) {
			if ($pageData === null) {
				$pageData = $this->get(self::DB_PAGES_KEY)->{$slug} ?? null;
				continue;
			}

			$pageData = $pageData->{self::DB_PAGES_SUBPAGE_KEY}->{$slug} ?? null;
			if (!$pageData) {
				return null;
			}
		}

		return $pageData;
	}

	/**
	 * Get current page url
	 *
	 * @return string
	 */
	public function getCurrentPageUrl(): string
	{
		$path = '';
		foreach ($this->currentPageTree as $parentPage) {
			$path .= $parentPage . '/';
		}

		return self::url($path);
	}

	/**
	 * Page status (exists or doesn't exist)
	 * @return void
	 */
	public function pageStatus(): void
	{
		$this->currentPage = $this->parseUrl() ?: $this->get('config', 'defaultPage');
		$this->currentPageExists = !empty($this->getCurrentPageData());
	}

	/**
	 * URL parser
	 * @return string
	 */
	public function parseUrl(): string
	{
		$page = $_GET['page'] ?? null;

		if (!isset($page) || !$page) {
			$defaultPage = $this->get('config', 'defaultPage');
			$this->currentPageTree = explode('/', $defaultPage);
			return $defaultPage;
		}

		$this->currentPageTree = explode('/', rtrim($page, '/'));
		if ($page === $this->get('config', 'login')) {
			return htmlspecialchars($page, ENT_QUOTES);
		}

		$currentPage = end($this->currentPageTree);
		return $this->slugify($currentPage);
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
	 * @throws Exception
	 */
	public function save(string $path = null, object $content = null): void
	{
		$path = $path ?? $this->dbPath;
		$content = $content ?? $this->db;
		$json = json_encode($content, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		if (empty($content) || empty($json) || json_last_error() !== JSON_ERROR_NONE) {
			$errorMessage = sprintf(
				'%s - Error while trying to save in %s: %s',
				time(),
				$path,
				print_r($content, true)
			);
			try {
				$randomNumber = random_bytes(8);
			} catch (Exception $e) {
				$randomNumber = microtime(false);
			}
			$logName = date('Y-m-d H:i:s') . '-error-' . bin2hex($randomNumber) . '.log';
			$logsPath = sprintf('%s/data/logs', $this->rootDir);
			$this->checkFolder($logsPath);
			error_log(
				$errorMessage,
				3,
				sprintf('%s/%s', $logsPath, $logName)
			);
			return;
		}
		file_put_contents($path, $json, LOCK_EX);
	}

	/**
	 * Saving menu items, default page, login URL, theme, editable content
	 * @return void
	 * @throws Exception
	 */
	public function saveAction(): void
	{
		if (!$this->loggedIn) {
			return;
		}
		if (isset($_SESSION['redirect_to'])) {
			$newUrl = $_SESSION['redirect_to'];
			$newPageName = $_SESSION['redirect_to_name'];
			unset($_SESSION['redirect_to'], $_SESSION['redirect_to_name']);
			$this->alert('success',
				"Page <b>$newPageName</b> created. Click <a href=" . $newUrl . ">here</a> to open it.");
			$this->redirect($newUrl);
		}
		if (isset($_POST['fieldname'], $_POST['content'], $_POST['target'], $_POST['token'])
			&& $this->hashVerify($_POST['token'])) {
			[$fieldname, $content, $target, $menu, $visibility] = $this->hook('save', $_POST['fieldname'],
				$_POST['content'], $_POST['target'], $_POST['menu'] ?? null, ($_POST['visibility'] ?? 'hide'));
			if ($target === 'menuItemUpdate' && $menu !== null) {
				$this->updateMenuItem($content, $menu, $visibility);
				$_SESSION['redirect_to_name'] = $content;
				$_SESSION['redirect_to'] = $this->slugify($content);
			}
			if ($target === 'menuItemCreate' && $menu !== null) {
				$this->createMenuItem($content, $menu, $visibility, true);
			}
			if ($target === 'menuItemVsbl' && $menu !== null) {
				$this->updateMenuItemVisibility($visibility, $menu);
			}
			if ($target === 'menuItemOrder' && $menu !== null) {
				$this->orderMenuItem($content, $menu);
			}
			if ($fieldname === 'defaultPage' && $this->getPageData($content) === null) {
				return;
			}
			if ($fieldname === 'login' && (empty($content) || $this->getPageData($content) !== null)) {
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
				if (!$this->currentPageExists) {
					$this->createPage($this->currentPageTree, true);
				}
				$this->updatePage($this->currentPageTree, $fieldname, $content);
			}
		}
	}

	/**
	 * Set something to database
	 * @return void
	 * @throws Exception
	 */
	public function set(): void
	{
		$args = func_get_args();

		$value = array_pop($args);
		$lastKey = array_pop($args);
		$data = $this->db;
		foreach ($args as $arg) {
			$data = $data->{$arg};
		}
		$data->{$lastKey} = $value;

		$this->save();
	}

	/**
	 * Display admin settings panel
	 * @return string
	 * @throws Exception
	 */
	public function settings(): string
	{
		if (!$this->loggedIn) {
			return '';
		}
		$currentPageData = $this->getCurrentPageData();
		$fileList = array_slice(scandir($this->filesPath), 2);
		$output = '
		<script>var saveChangesPopup = ' . ($this->isSaveChangesPopupEnabled() ? "true" : "false") . '</script>
		<div id="save" class="loader-overlay"><h2><i class="animationLoader"></i><br />Saving</h2></div>
		<div id="cache" class="loader-overlay"><h2><i class="animationLoader"></i><br />Checking for updates</h2></div>
		<div id="adminPanel">
			<a data-toggle="wcms-modal" class="wbtn wbtn-secondary wbtn-sm settings button" href="#settingsModal"><i class="settingsIcon"></i> Settings </a> <a href="' . self::url('logout?token=' . $this->getToken()) . '" class="wbtn wbtn-danger wbtn-sm button logout" title="Logout" onclick="return confirm(\'Log out?\')"><i class="logoutIcon"></i></a>
			<div class="wcms-modal modal" id="settingsModal">
				<div class="modal-dialog modal-xl">
				 <div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="wcms-modal" aria-hidden="true">&times;</button></div>
					<div class="modal-body coll-xs-12 coll-12">
						<ul class="nav nav-tabs justify-content-center text-center" role="tablist">
							<li role="presentation" class="nav-item"><a href="#currentPage" aria-controls="currentPage" role="tab" data-toggle="tab" class="nav-link active">Current page</a></li>
							<li role="presentation" class="nav-item"><a href="#menu" aria-controls="menu" role="tab" data-toggle="tab" class="nav-link">Menu</a></li>
							<li role="presentation" class="nav-item"><a href="#files" aria-controls="files" role="tab" data-toggle="tab" class="nav-link">Files</a></li>
							<li role="presentation" class="nav-item"><a href="#themes" aria-controls="themes" role="tab" data-toggle="tab" class="nav-link">Themes</a></li>
							<li role="presentation" class="nav-item"><a href="#plugins" aria-controls="plugins" role="tab" data-toggle="tab" class="nav-link">Plugins</a></li>
							<li role="presentation" class="nav-item"><a href="#security" aria-controls="security" role="tab" data-toggle="tab" class="nav-link">Security</a></li>
						</ul>
						<div class="tab-content coll-md-8 coll-md-offset-2 offset-md-2">
							<div role="tabpanel" class="tab-pane active" id="currentPage">';
		if ($this->currentPageExists && $currentPageData) {
			$output .= '
								<p class="subTitle">Page title</p>
								<div class="change">
									<div data-target="pages" id="title" class="editText">' . ($currentPageData->title ?: '') . '</div>
								</div>
								<p class="subTitle">Page keywords</p>
								<div class="change">
									<div data-target="pages" id="keywords" class="editText">' . ($currentPageData->keywords ?: '') . '</div>
								</div>
								<p class="subTitle">Page description</p>
								<div class="change">
									<div data-target="pages" id="description" class="editText">' . ($currentPageData->description ?: '') . '</div>
								</div>
								<a href="' . self::url('?delete=' . implode('/',
						$this->currentPageTree) . '&token=' . $this->getToken()) . '" class="wbtn wbtn-danger pull-right marginTop40" title="Delete page" onclick="return confirm(\'Delete ' . $this->currentPage . '?\')"><i class="deleteIconInButton"></i> Delete page (' . $this->currentPage . ')</a>';
		} else {
			$output .= 'This page doesn\'t exist. More settings will be displayed here after this page is created.';
		}
		$output .= '
							</div>
							<div role="tabpanel" class="tab-pane" id="menu">';
		$items = get_mangled_object_vars($this->get('config', 'menuItems'));
		reset($items);
		$first = key($items);
		end($items);
		$end = key($items);
		$output .= '
							 <p class="subTitle">Website title</p>
							 <div class="change">
								<div data-target="config" id="siteTitle" class="editText">' . $this->get('config',
				'siteTitle') . '</div>
							 </div>
							 <p class="subTitle">Menu</p>
							 <div>
								<div id="menuSettings" class="container-fluid">
								<a class="menu-item-add wbtn wbtn-info cursorPointer" data-toggle="tooltip" id="menuItemAdd" title="Add new page"><i class="addNewIcon"></i> Add page</a><br><br>';
		foreach ($items as $key => $value) {
			$output .= '<div class="row">';
			$output .= $this->renderSettingsMenuItem($key, $value, ($key === $first), ($key === $end), $value->slug);
			if (property_exists($value, 'subpages')) {
				$output .= $this->renderSettingsSubMenuItem($value->subpages, $key, $value->slug);
			}
			$output .= '</div>';
		}
		$output .= '			</div>
							 </div>
							 <p class="subTitle">Page to display on homepage</p>
							 <div class="change">
								<select id="changeDefaultPage" class="wform-control" name="defaultPage">';
		$items = $this->get('config', 'menuItems');
		$defaultPage = $this->get('config', 'defaultPage');
		foreach ($items as $item) {
			$output .= $this->renderDefaultPageOptions($item, $defaultPage);
		}
		$output .= '
								</select>
							</div>
							</div>
							<div role="tabpanel" class="tab-pane" id="files">
							 <p class="subTitle">Upload</p>
							 <div class="change">
								<form action="' . $this->getCurrentPageUrl() . '" method="post" enctype="multipart/form-data">
									<div class="winput-group"><input type="file" name="uploadFile" class="wform-control">
										<span class="winput-group-btn"><button type="submit" class="wbtn wbtn-info"><i class="uploadIcon"></i>Upload</button></span>
										<input type="hidden" name="token" value="' . $this->getToken() . '">
									</div>
								</form>
							 </div>
							 <p class="subTitle marginTop20">Delete files</p>
							 <div class="change">';
		foreach ($fileList as $file) {
			$output .= '
									<a href="' . self::url('?deleteModule=' . $file . '&type=files&token=' . $this->getToken()) . '" class="wbtn wbtn-sm wbtn-danger" onclick="return confirm(\'Delete ' . $file . '?\')" title="Delete file"><i class="deleteIcon"></i></a>
									<span class="marginLeft5">
										<a href="' . self::url('data/files/') . $file . '" class="normalFont" target="_blank">' . self::url('data/files/') . '<b class="fontSize21">' . $file . '</b></a>
									</span>
									<p></p>';
		}
		$output .= '
							 </div>
							</div>';
		$output .= $this->renderModuleTab();
		$output .= $this->renderModuleTab('plugins');
		$output .= '		<div role="tabpanel" class="tab-pane" id="security">
							 <p class="subTitle">Admin login URL</p>
								<p class="change marginTop5 small danger">Important: save your login URL to log in to your website next time:<br/><b><span class="normalFont">' . self::url($this->get('config',
				'login')) . '</b></span>
							 <div class="change">
								<div data-target="config" id="login" class="editText">' . $this->get('config',
				'login') . '</div>
							 </div>
							 <p class="subTitle">Password</p>
							 <div class="change">
								<form action="' . $this->getCurrentPageUrl() . '" method="post">
									<input type="password" name="old_password" class="wform-control normalFont" placeholder="Old password"><br>
									<div class="winput-group">
										<input type="password" name="new_password" class="wform-control normalFont" placeholder="New password"><span class="winput-group-btn"></span>
										<input type="password" name="repeat_password" class="wform-control normalFont" placeholder="Repeat new password">
										<span class="winput-group-btn"><button type="submit" class="wbtn wbtn-info"><i class="lockIcon"></i> Change password</button></span>
									</div>
									<input type="hidden" name="fieldname" value="password"><input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
<p class="subTitle">Backup</p>
							 <div class="change">
								<form action="' . $this->getCurrentPageUrl() . '" method="post">
									<button type="submit" class="wbtn wbtn-block wbtn-info" name="backup"><i class="installIcon"></i> Backup website</button><input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Restore-backup#how-to-restore-a-backup-in-3-steps" target="_blank"><i class="linkIcon"></i> How to restore backup</a></p>
							 
							 <p class="subTitle">Save confirmation popup</p>
							 <p class="change small">If this is turned on, WonderCMS will always ask you to confirm any changes you make.</p>
							 <div class="change">
								<form method="post">
									<div class="wbtn-group wbtn-group-justified w-100">
										<div class="wbtn-group w-50"><button type="submit" class="wbtn wbtn-info" name="saveChangesPopup" value="true">ON</button></div>
										<div class="wbtn-group w-50"><button type="submit" class="wbtn wbtn-danger" name="saveChangesPopup" value="false">OFF</button></div>
									</div>
									<input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 
							 <p class="subTitle">Force HTTPS</p>
							 <p class="change small">WonderCMS automatically checks for SSL, this will force to always use HTTPS.</p>
							 <div class="change">
								<form method="post">
									<div class="wbtn-group wbtn-group-justified w-100">
										<div class="wbtn-group w-50"><button type="submit" class="wbtn wbtn-info" name="forceHttps" value="true" onclick="return confirm(\'Are you sure? This might break your website if you do not have SSL configured correctly.\')">ON</button></div>
										<div class="wbtn-group w-50"><button type="submit" class="wbtn wbtn-danger" name="forceHttps" value="false">OFF</button></div>
									</div>
									<input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Better-security-mode-(HTTPS-and-other-features)#important-read-before-turning-this-feature-on" target="_blank"><i class="linkIcon"></i> Read more before enabling</a></p>';
		$output .= $this->renderAdminLoginIPs();
		$output .= '
				 		 </div>
						</div>
					</div>
					<div class="modal-footer clear">
						<p class="small">
							<a href="https://wondercms.com" target="_blank">WonderCMS ' . VERSION . '</a> &nbsp;
							<b><a href="https://wondercms.com/news" target="_blank">News</a> &nbsp;
							<a href="https://wondercms.com/community" target="_blank">Community</a> &nbsp;
							<a href="https://github.com/robiso/wondercms/wiki#wondercms-documentation" target="_blank">Docs</a> &nbsp;
							<a href="https://wondercms.com/donate" target="_blank">Donate</a> &nbsp;
							<a href="https://swag.wondercms.com" target="_blank">Shop/Merch</a></b>
						</p>
					</div>
				 </div>
				</div>
			</div>
		</div>';
		return $this->hook('settings', $output)[0];
	}

	/**
	 * Render options for default page selection
	 *
	 * @param object $menuItem
	 * @param string $defaultPage
	 * @param string $parentSlug
	 * @param string $parentName
	 * @return string
	 */
	private function renderDefaultPageOptions(
		object $menuItem,
		string $defaultPage,
		string $parentSlug = '',
		string $parentName = ''
	): string {
		$slug = $parentSlug ? sprintf('%s/%s', $parentSlug, $menuItem->slug) : $menuItem->slug;
		$name = $parentName ? sprintf('%s | %s', $parentName, $menuItem->name) : $menuItem->name;
		$output = '<option value="' . $slug . '" ' . ($slug === $defaultPage ? 'selected' : '') . '>' . $name . '</option>';

		foreach ($menuItem->subpages ?? [] as $subpage) {
			$output .= $this->renderDefaultPageOptions($subpage, $defaultPage, $slug, $name);
		}

		return $output;
	}

	/**
	 * Render page navigation items
	 *
	 * @param object $item
	 * @param string $parentSlug
	 * @return string
	 */
	private function renderPageNavMenuItem(object $item, string $parentSlug = ''): string
	{
		$subpages = $visibleSubpage = false;
		if (property_exists($item, 'subpages') && !empty((array)$item->subpages)) {
			$subpages = $item->subpages;
			$visibleSubpage = $subpages && in_array('show', array_column((array)$subpages, 'visibility'));
		}

		$parentSlug .= $subpages ? $item->slug . '/' : $item->slug;
		$output = '<li class="nav-item ' . ($this->currentPage === $item->slug ? 'active ' : '') . ($visibleSubpage ? 'subpage-nav' : '') . '">
						<a class="nav-link" href="' . self::url($parentSlug) . '">' . $item->name . '</a>';

		// Recursive method for rendering infinite subpages
		if ($visibleSubpage) {
			$output .= '<ul class="subPageDropdown">';
			foreach ($subpages as $subpage) {
				if ($subpage->visibility === 'hide') {
					continue;
				}
				$output .= $this->renderPageNavMenuItem($subpage, $parentSlug);
			}
			$output .= '</ul>';
		}

		$output .= '</li>';

		return $output;
	}

	/**
	 * Render menu item for settings
	 *
	 * @param string $menuKeyTree
	 * @param object $value
	 * @param bool $isFirstEl
	 * @param bool $isLastEl
	 * @param string $slugTree
	 * @return string
	 * @throws Exception
	 */
	private function renderSettingsMenuItem(
		string $menuKeyTree,
		object $value,
		bool $isFirstEl,
		bool $isLastEl,
		string $slugTree
	): string {
		$arraySlugTree = explode('/', $slugTree);
		array_shift($arraySlugTree);
		$subMenuLevel = count($arraySlugTree);
		$output = '<div class="coll-xs-2 coll-sm-1">
						<i class="menu-toggle eyeIcon' . ($value->visibility === 'show' ? ' eyeShowIcon menu-item-hide' : ' eyeHideIcon menu-item-show') . '" data-toggle="tooltip" title="' . ($value->visibility === 'show' ? 'Hide page from menu' : 'Show page in menu') . '" data-menu="' . $menuKeyTree . '"></i>
					</div>
					<div class="coll-xs-4 coll-md-7">
						<div data-target="menuItemUpdate" data-menu="' . $menuKeyTree . '" data-visibility="' . $value->visibility . '" id="menuItems-' . $menuKeyTree . '" class="editText" style="margin-right: ' . (13.1 * $subMenuLevel) . 'px;">' . $value->name . '</div>
					</div>
					<div class="coll-xs-6 coll-md-4 text-right">';

		if (!$isFirstEl) {
			$output .= '<a class="arrowIcon upArrowIcon toolbar menu-item-up cursorPointer" data-toggle="tooltip" data-menu="' . $menuKeyTree . '" data-menu-slug="' . $value->slug . '" title="Move up"></a>';
		}
		if (!$isLastEl) {
			$output .= '<a class="arrowIcon downArrowIcon toolbar menu-item-down cursorPointer" data-toggle="tooltip" data-menu="' . $menuKeyTree . '" data-menu-slug="' . $value->slug . '" title="Move down"></a>';
		}
		$output .= '	<a class="linkIcon" href="' . self::url($slugTree) . '" title="Visit page" style="display: inline;">visit</a>
					</div>
					<div class="coll-xs-12 text-right marginTop5 marginBottom20">
						<a class="menu-item-add wbtn wbtn-sm wbtn-info cursorPointer" data-toggle="tooltip" data-menu="' . $menuKeyTree . '" title="Add new sub-page"><i class="addNewIcon"></i> Add subpage</a>
						<a href="' . self::url('?delete=' . urlencode($slugTree) . '&token=' . $this->getToken()) . '" title="Delete page" class="wbtn wbtn-sm wbtn-danger" data-menu="' . $menuKeyTree . '" onclick="return confirm(\'Delete ' . $value->slug . '?\')"><i class="deleteIcon"></i></a>
					</div>';

		return $output;
	}

	/**
	 * Render sub menu item for settings
	 *
	 * @param object $subpages
	 * @param string $parentKeyTree
	 * @param string $parentSlugTree
	 * @return string
	 * @throws Exception
	 */
	private function renderSettingsSubMenuItem(object $subpages, string $parentKeyTree, string $parentSlugTree): string
	{
		$subpages = get_mangled_object_vars($subpages);
		reset($subpages);
		$firstSubpage = key($subpages);
		end($subpages);
		$endSubpage = key($subpages);
		$output = '';

		foreach ($subpages as $subpageKey => $subpage) {
			$keyTree = $parentKeyTree . '-' . $subpageKey;
			$slugTree = $parentSlugTree . '/' . $subpage->slug;
			$output .= '<div class="coll-xs-offset-1 coll-xs-11">
							<div class="row marginTop5">';
			$firstElement = ($subpageKey === $firstSubpage);
			$lastElement = ($subpageKey === $endSubpage);
			$output .= $this->renderSettingsMenuItem($keyTree, $subpage, $firstElement, $lastElement, $slugTree);

			// Recursive method for rendering infinite subpages
			if (property_exists($subpage, 'subpages')) {
				$output .= $this->renderSettingsSubMenuItem($subpage->subpages, $keyTree, $slugTree);
			}
			$output .= '	</div>
						</div>';
		}

		return $output;
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
			$renderIPs .= sprintf('%s - %s<br />', date('M d, Y H:i:s', strtotime($time)), $adminIP);
		}
		return '<p class="subTitle">Last 5 logins</p>
				<div class="change">
					' . $renderIPs . '
				</div>';
	}

	/**
	 * Render Plugins/Themes cards
	 * @param string $type
	 * @return string
	 * @throws Exception
	 */
	private function renderModuleTab(string $type = 'themes'): string
	{
		$output = '<div role="tabpanel" class="tab-pane" id="' . $type . '">
					<a class="wbtn wbtn-info wbtn-sm pull-right float-right marginTop20 marginBottom20" data-loader-id="cache" href="' . self::url('?manuallyResetCacheData=true&token=' . $this->getToken()) . '" title="Check updates" onclick="localStorage.clear();"><i class="refreshIcon" aria-hidden="true"></i> Check for updates</a>
					<div class="clear"></div>
					<div class="change row custom-cards">';
		$defaultImage = '<svg style="max-width: 100%;" xmlns="http://www.w3.org/2000/svg" width="100%" height="140"><text x="50%" y="50%" font-size="18" text-anchor="middle" alignment-baseline="middle" font-family="monospace, sans-serif" fill="#ddd">No preview</text></svg>';
		$updates = $exists = $installs = '';
		foreach ($this->listAllModules($type) as $addonType => $addonModules) {
			foreach ($addonModules as $directoryName => $addon) {
				$name = $addon['name'];
				$info = $addon['summary'];
				$infoUrl = $addon['repo'];
				$currentVersion = $addon['currentVersion'] ? sprintf('Installed version: %s',
					$addon['currentVersion']) : '';
				$isThemeSelected = $this->get('config', 'theme') === $directoryName;

				$image = $addon['image'] !== null ? '<a class="text-center center-block" href="' . $addon['image'] . '" target="_blank"><img style="max-width: 100%; max-height: 250px;" src="' . $addon['image'] . '" alt="' . $name . '" /></a>' : $defaultImage;
				$installButton = $addon['install'] ? '<a class="wbtn wbtn-success wbtn-block wbtn-sm" href="' . self::url('?installModule=' . $addon['zip'] . '&directoryName=' . $directoryName . '&type=' . $type . '&token=' . $this->getToken()) . '" title="Install"><i class="installIcon"></i> Install</a>' : '';
				$updateButton = !$addon['install'] && $addon['update'] ? '<a class="wbtn wbtn-info wbtn-sm wbtn-block marginTop5" href="' . self::url('?installModule=' . $addon['zip'] . '&directoryName=' . $directoryName . '&type=' . $type . '&token=' . $this->getToken()) . '" title="Update"><i class="refreshIcon"></i> Update to ' . $addon['version'] . '</a>' : '';
				$removeButton = !$addon['install'] ? '<a class="wbtn wbtn-danger wbtn-sm marginTop5" href="' . self::url('?deleteModule=' . $directoryName . '&type=' . $type . '&token=' . $this->getToken()) . '" onclick="return confirm(\'Remove ' . $name . '?\')" title="Remove"><i class="deleteIcon"></i></a>' : '';
				$inactiveThemeButton = $type === 'themes' && !$addon['install'] && !$isThemeSelected ? '<a class="wbtn wbtn-primary wbtn-sm wbtn-block" href="' . self::url('?selectModule=' . $directoryName . '&type=' . $type . '&token=' . $this->getToken()) . '" onclick="return confirm(\'Activate ' . $name . ' theme?\')"><i class="checkmarkIcon"></i> Activate</a>' : '';
				$activeThemeButton = $type === 'themes' && !$addon['install'] && $isThemeSelected ? '<a class="wbtn wbtn-primary wbtn-sm wbtn-block" disabled>Active</a>' : '';

				$html = "<div class='coll-sm-4'>
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
					<p class="subTitle">Custom module</p>
					<form action="' . $this->getCurrentPageUrl() . '" method="post">
						<div class="wform-group">
							<div class="change winput-group marginTop5"><input type="text" name="pluginThemeUrl" class="wform-control normalFont" placeholder="Enter full URL to wcms-modules.json file">
								<span class="winput-group-btn"><button type="submit" class="wbtn wbtn-info" onclick="return confirm(\'Adding unknown modules can be VERY dangerous, are you sure you want to continue?\')"><i class="addNewIcon"></i> Add</button></span>
							</div>
						</div>
						<input type="hidden" name="token" value="' . $this->getToken() . '" /><input type="hidden" name="pluginThemeType" value="' . $type . '" />
					</form>
					<p class="text-right"><a href="https://github.com/robiso/wondercms/wiki/Custom-modules" target="_blank"><i class="linkIcon"></i> Read more about custom modules</a></p>
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
		$allowedMimeTypes = [
			'video/avi',
			'text/css',
			'text/x-asm',
			'application/vnd.ms-word',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'video/x-flv',
			'image/gif',
			'text/html',
			'image/x-icon',
			'image/jpeg',
			'application/octet-stream',
			'audio/mp4',
			'video/x-matroska',
			'video/quicktime',
			'audio/mpeg',
			'video/mp4',
			'video/mpeg',
			'application/vnd.oasis.opendocument.spreadsheet',
			'application/vnd.oasis.opendocument.text',
			'application/ogg',
			'video/ogg',
			'application/pdf',
			'image/png',
			'application/vnd.ms-powerpoint',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'application/photoshop',
			'application/rar',
			'image/svg',
			'image/svg+xml',
			'image/avif',
			'image/webp',
			'application/svg+xm',
			'text/plain',
			'application/vnd.ms-excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'video/webm',
			'video/x-ms-wmv',
			'application/zip',
		];

		$allowedExtensions = [
			'avi',
			'avif',
			'css',
			'doc',
			'docx',
			'flv',
			'gif',
			'htm',
			'html',
			'ico',
			'jpeg',
			'jpg',
			'kdbx',
			'm4a',
			'mkv',
			'mov',
			'mp3',
			'mp4',
			'mpg',
			'ods',
			'odt',
			'ogg',
			'ogv',
			'pdf',
			'png',
			'ppt',
			'pptx',
			'psd',
			'rar',
			'svg',
			'txt',
			'xls',
			'xlsx',
			'webm',
			'webp',
			'wmv',
			'zip',
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
		$fileName = basename(str_replace(
			['"', "'", '*', '<', '>', '%22', '&#39;', '%', ';', '#', '&', './', '../', '/', '+'],
			'',
			htmlspecialchars(strip_tags($_FILES['uploadFile']['name']))
		));
		$nameExploded = explode('.', $fileName);
		$ext = strtolower(array_pop($nameExploded));

		if (class_exists('finfo')) {
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$mimeType = $finfo->file($_FILES['uploadFile']['tmp_name']);
		} elseif (function_exists('mime_content_type')) {
			$mimeType = mime_content_type($_FILES['uploadFile']['tmp_name']);
		} elseif (array_key_exists($ext, $allowedExtensions)) {
			$mimeType = $allowedExtensions[$ext];
		}
		if (!in_array($mimeType, $allowedMimeTypes, true) || !in_array($ext, $allowedExtensions)) {
			$this->alert('danger',
				'File format is not allowed. <a data-toggle="wcms-modal" href="#settingsModal" data-target-tab="#files"><b>Re-open file options</b></a>');
			$this->redirect();
		}
		if (!move_uploaded_file($_FILES['uploadFile']['tmp_name'], $this->filesPath . '/' . $fileName)) {
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
		$showHttps = Wcms::isCurrentlyOnSSL();
		$dataPath = sprintf('%s/%s', __DIR__, 'data');
		$securityCachePath = sprintf('%s/%s', $dataPath, 'security.json');

		if (is_file($securityCachePath) && file_exists($securityCachePath)) {
			$securityCache = json_decode(file_get_contents($securityCachePath), true);
			$showHttps = $securityCache['forceHttps'] ?? false;
		}

		$serverPort = ((($_SERVER['SERVER_PORT'] == '80') || ($_SERVER['SERVER_PORT'] == '443')) ? '' : ':' . $_SERVER['SERVER_PORT']);
		return ($showHttps ? 'https' : 'http')
			. '://' . ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'])
			. ($_SERVER['HTTP_HOST'] ? '' : $serverPort)
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
	 * Check if currently user is on https
	 * @return bool
	 */
	public static function isCurrentlyOnSSL(): bool
	{
		return (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on')
			|| (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on')
			|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
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
				. '. Contact your host or configure your server to enable them with correct permissions.</p>');
		}
	}

	/**
	 * Helper for reseting the index key of the object
	 * @param stdClass $object
	 * @return stdClass
	 */
	private function reindexObject(stdClass $object): stdClass
	{
		$reindexObject = new stdClass;
		$index = 0;
		foreach ($object as $value) {
			$reindexObject->{$index} = $value;
			$index++;
		}
		return $reindexObject;
	}

	/**
	 * Check if user has forced https
	 * @return bool
	 */
	private function isHttpsForced(): bool
	{
		$value = $this->get('config', 'forceHttps');
		if (gettype($value) === 'object' && empty(get_object_vars($value))) {
			return false;
		}

		return $value ?? false;
	}

	/**
	 * Check if user has confirmation dialog enabled
	 * @return bool
	 */
	private function isSaveChangesPopupEnabled(): bool
	{
		$value = $this->get('config', 'saveChangesPopup');
		if (gettype($value) === 'object' && empty(get_object_vars($value))) {
			return false;
		}

		return $value ?? false;
	}
}
