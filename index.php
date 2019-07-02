<?php
/**
 * @package WonderCMS
 * @author Robert Isoski
 * @see https://www.wondercms.com
 * @license MIT
 */

session_start();
define('VERSION', '3.0.0');
mb_internal_encoding('UTF-8');

if (defined('PHPUNIT_TESTING') === false) {
	$Wcms = new Wcms();
	$Wcms->init();
	$Wcms->render();
}

class Wcms
{
	/** @var int MIN_PASSWORD_LENGTH minimum number of characters for password */
	public const MIN_PASSWORD_LENGTH = 8;

	/** @var string WonderCMS repository URL */
	public const WCMS_REPO = 'https://raw.githubusercontent.com/robiso/wondercms/master/';

	/** @var string VERSION current version of WonderCMS */
	public const VERSION = '3.0.0';

	/** @var string $currentPage the current page */
	public $currentPage = '';

	/** @var bool $currentPageExists does the current page exist? */
	public $currentPageExists = false;

	/** @var object $db content of the database.js */
	protected $db;

	/** @var bool $loggedIn is the user logged in? */
	public $loggedIn = false;

	/** @var array $listeners for hooks */
	public $listeners = [];

	/** @var string $dbPath path to database.js */
	protected $dbPath;

	/** @var string $filesPath path to uploaded files */
	public $filesPath;

	/** @var string $rootDir root dir of the install (where index.php is) */
	public $rootDir;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->rootDir = __DIR__;
		$this->setPaths();
		$this->db = $this->getDb();
	}

	/**
	 * Setting default paths
	 * @param string $dataFolder
	 * @param string $filesFolder
	 * @param string $dbName
	 */
	public function setPaths(
		string $dataFolder = 'data',
		string $filesFolder = 'files',
		string $dbName = 'database.js'
	): void {
		$this->dbPath = sprintf('%s/%s/%s', $this->rootDir, $dataFolder, $dbName);
		$this->filesPath = sprintf('%s/%s/%s', $this->rootDir, $dataFolder, $filesFolder);
	}

	/**
	 * Init function called on each page load
	 *
	 * @return void
	 * @throws Exception
	 */
	public function init(): void
	{
		$this->installThemePluginAction();
		$this->loadPlugins();
		$this->loginStatus();
		$this->pageStatus();
		$this->updateDBVersion();
		$this->changePasswordAction();
		$this->deleteFileThemePluginAction();
		$this->backupAction();
		$this->betterSecurityAction();
		$this->loginAction();
		$this->deletePageAction();
		$this->logoutAction();
		$this->saveAction();
		$this->updateAction();
		$this->uploadFileAction();
		$this->notifyAction();
		$this->notFoundResponse();
	}

	/**
	 * Display the HTML. Called after init()
	 * @return void
	 */
	public function render(): void
	{
		$this->loadThemeAndFunctions();
	}

	/**
	 * Function used by plugins to add a hook
	 *
	 * @param string $hook
	 * @param string $functionName
	 */
	public function addListener(string $hook, string $functionName): void
	{
		$this->listeners[$hook][] = $functionName;
	}

	/**
	 * Add alert message for the user
	 *
	 * @param string $class see bootstrap alerts classes
	 * @param string $message the message to display
	 * @param bool $sticky can it be closed?
	 * @return void
	 */
	private function alert(string $class, string $message, bool $sticky = false): void
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
	 * Display alert message to the user
	 * @return string
	 */
	public function alerts(): string
	{
		if (!isset($_SESSION['alert'])) {
			return '';
		}
		$output = '';
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
	private function backupAction(): void
	{
		if (!$this->loggedIn) {
			return;
		}
		$backupList = glob($this->filesPath . '/*-backup-*.zip');
		if (!empty($backupList)) {
			$this->alert('danger', 'Delete backup files. (<i>Settings -> Files</i>)');
		}
		if (!isset($_POST['backup'])) {
			return;
		}
		if ($this->hashVerify($_POST['token'])) {
			$this->zipBackup();
		}
	}

	/**
	 * Replace the .htaccess with one adding security settings
	 * @return void
	 */
	private function betterSecurityAction(): void
	{
		if (isset($_POST['betterSecurity'], $_POST['token'])
			&& $this->loggedIn
			&& $this->hashVerify($_POST['token'])) {
			if ($_POST['betterSecurity'] === 'on') {
				if ($contents = $this->getFileFromRepo('.htaccess-ultimate')) {
					file_put_contents('.htaccess', trim($contents));
				}
				$this->alert('success', 'Better security turned ON.');
				$this->redirect();
			} elseif ($_POST['betterSecurity'] === 'off') {
				if ($contents = $this->getFileFromRepo('.htaccess')) {
					file_put_contents('.htaccess', trim($contents));
				}
				$this->alert('success', 'Better security turned OFF.');
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
		return isset($blocks->{$key})
			? ($this->loggedIn ? $this->editable($key, $blocks->{$key}->content, 'blocks') : $blocks->{$key}->content)
			: '';
	}

	/**
	 * Change password
	 * @return void
	 */
	public function changePasswordAction(): void
	{
		if (isset($_POST['old_password'], $_POST['new_password'])
			&& $this->loggedIn
			&& $_SESSION['token'] === $_POST['token']
			&& $this->hashVerify($_POST['token'])) {
			if (!password_verify($_POST['old_password'], $this->get('config', 'password'))) {
				$this->alert('danger', 'Wrong password.');
				$this->redirect();
			}
			if (strlen($_POST['new_password']) < self::MIN_PASSWORD_LENGTH) {
				$this->alert('danger',
					sprintf('Password must be longer than %d characters.', self::MIN_PASSWORD_LENGTH));
				$this->redirect();
			}
			$this->set('config', 'password', password_hash($_POST['new_password'], PASSWORD_DEFAULT));
			$this->alert('success', 'Password changed.');
			$this->redirect();
		}
	}

	/**
	 * Check if we can run WonderCMS properly
	 * Executed once before creating the database file
	 *
	 * @param string $folder the relative path of the folder to check/create
	 * @return void
	 */
	private function checkFolder(string $folder): void
	{
		if (!is_dir($folder) && !mkdir($folder, 0755) && !is_dir($folder)) {
			$this->alert('danger', 'Could not create the data folder.');
		}
		if (!is_writable($folder)) {
			$this->alert('danger', 'Could write to the data folder.');
		}
	}

	/**
	 * Initialize the JSON database if doesn't exist
	 * @return void
	 */
	private function createDb(): void
	{
		$password = $this->generatePassword();
		$this->db = (object)[
			'config' => [
				'dbVersion' => '3.0.0',
				'siteTitle' => 'Website title',
				'theme' => 'default',
				'defaultPage' => 'home',
				'login' => 'loginURL',
				'password' => password_hash($password, PASSWORD_DEFAULT),
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
					'content' => '<h1>Website alive!</h1>

<h4><a href="' . self::url('loginURL') . '">Click to login.</a> Your password is: <b>' . $password . '</b></a></h4>'
				],
				'example' => [
					'title' => 'Example',
					'keywords' => 'Keywords, are, good, for, search, engines',
					'description' => 'A short description is also good.',
					'content' => '<h1>How to create new pages</h1>
<p><i>Settings -> General -> Add page</i></p>

<h1>How to edit anything</h1>
<p>Click anywhere inside the gray dashed area to edit. Click outside the area to save.</p>

<h1>How to install/update themes and plugins</h1>
<p>1. Copy link/URL to ZIP file.</p>
<p>2. Paste link in <i>Settings -> Themes and plugins</i> and click <i>Install/update</i>.</p>
<p><a href="https://wondercms.com/themes" target="_blank">WonderCMS themes</a> | <a href="https://wondercms.com/plugins" target="_blank">WonderCMS plugins</a></p>'
				]
			],
			'blocks' => [
				'subside' => [
					'content' => '<h3>About your website</h3>

<p>Website description, photo, contact information, mini map or anything else.</p>
<p>This block is visible on all pages.</p>'
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
	 */
	private function createMenuItem(string $content, string $menu, string $visibility = 'show'): void
	{
		$conf = 'config';
		$field = 'menuItems';
		$exist = is_numeric($menu);
		$content = empty($content) ? 'empty' : str_replace([PHP_EOL, '<br>'], '', $content);
		$slug = $this->slugify($content);
		$menuCount = count(get_object_vars($this->get($conf, $field)));
		if (!$exist) {
			$db = $this->getDb();
			foreach ($db->config->{$field} as $value) {
				if ($value->slug === $slug) {
					$slug .= '-' . $menuCount;
				}
			}
			$db->config->{$field}->{$menuCount} = new \stdClass;
			$this->save();
			$this->set($conf, $field, $menuCount, 'name', str_replace('-', ' ', $content));
			$this->set($conf, $field, $menuCount, 'slug', $slug);
			$this->set($conf, $field, $menuCount, 'visibility', $visibility);
			if ($menu) {
				$this->createPage($slug);
			}
		} else {
			$oldSlug = $this->get($conf, $field, $menu, 'slug');
			$this->set($conf, $field, $menu, 'name', $content);
			$this->set($conf, $field, $menu, 'slug', $slug);
			$this->set($conf, $field, $menu, 'visibility', $visibility);
			if ($slug !== $oldSlug) {
				$this->createPage($slug);
				$this->deletePageAction($oldSlug, false);
			}
		}
	}

	/**
	 * Create new page
	 *
	 * @param string $slug the name of the page in URL
	 * @return void
	 */
	private function createPage($slug = ''): void
	{
		$this->db->pages->{$slug ?: $this->currentPage} = new \stdClass;
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
	 * Inject CSS into page
	 * @return string
	 */
	public function css(): string
	{
		if ($this->loggedIn) {
			$styles = <<<'EOT'
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/robiso/wondercms-files/wcms-admin.min.css" integrity="sha384-YmJRsgYqoll4KFTAbspI00K2gBpzVlEG9SFgK43fioyklBEdaCRuy+lXTWxSqam7" crossorigin="anonymous">
EOT;
			return $this->hook('css', $styles)[0];
		}
		return $this->hook('css', '')[0];
	}

	/**
	 * Get content of the database
	 * @return stdClass
	 */
	public function getDb(): stdClass
	{
		// initialize the database if it doesn't exist yet
		if (!file_exists($this->dbPath)) {
			// this code basically only runs one time, on first page load: install time
			$this->checkFolder(dirname($this->dbPath));
			$this->checkFolder($this->filesPath);
			$this->createDb();
		}
		return json_decode(file_get_contents($this->dbPath));
	}

	/**
	 * Delete theme
	 * @return void
	 */
	private function deleteFileThemePluginAction(): void
	{
		if (!$this->loggedIn) {
			return;
		}
		if ((isset($_REQUEST['deleteFile']) || isset($_REQUEST['deleteTheme']) || isset($_REQUEST['deletePlugin']))
			&& isset($_REQUEST['token'])
			&& $this->hashVerify($_REQUEST['token'])) {
			$deleteList = [
				[$this->filesPath, 'deleteFile'],
				[$this->rootDir . '/themes', 'deleteTheme'],
				[$this->rootDir . '/plugins', 'deletePlugin'],
			];
			foreach ($deleteList as [$folder, $request]) {
				$filename = isset($_REQUEST[$request])
					? str_ireplace(['/', './', '../', '..', '~', '~/', '\\'], null, trim($_REQUEST[$request]))
					: false;
				if (!$filename || empty($filename)) {
					continue;
				}
				if ($filename === $this->get('config', 'theme')) {
					$this->alert('danger', 'Cannot delete currently active theme.');
					$this->redirect();
					continue;
				}
				if (file_exists("{$folder}/{$filename}")) {
					$this->recursiveDelete("{$folder}/{$filename}");
					$this->alert('success', "Deleted {$filename}.");
					$this->redirect();
				}
			}
		}
	}

	/**
	 * Delete page
	 *
	 * @param bool $needle
	 * @param bool $menu
	 * @return void
	 */
	private function deletePageAction(bool $needle = false, bool $menu = true): void
	{
		if (!$needle
			&& $this->loggedIn
			&& isset($_GET['delete'])
			&& $this->hashVerify($_REQUEST['token'])) {
			$needle = $_GET['delete'];
		}
		if (isset($this->get('pages')->{$needle})) {
			unset($this->db->pages->{$needle});
		}
		if ($menu) {
			$menuItems = json_decode(json_encode($this->get('config', 'menuItems')), true);
			if (false === ($index = array_search($needle, array_column($menuItems, 'slug')))) {
				return;
			}
			unset($menuItems[$index]);
			$newMenu = array_values($menuItems);
			$this->db->config->menuItems = json_decode(json_encode($newMenu));
		}
		$this->save();
		$this->alert('success', 'Page deleted.');
		$this->redirect();
	}

	/**
	 * Get an editable block
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
	 * Get the footer
	 * @return string
	 */
	public function footer(): string
	{
		$output = $this->get('blocks', 'footer')->content . (!$this->loggedIn
				? (($this->get('config',
						'login') === 'loginURL') ? ' &bull; <a href="' . self::url('loginURL') . '">Login</a>' : '')
				: '');
		return $this->hook('footer', $output)[0];
	}

	/**
	 * Generate random password
	 * @return string
	 */
	private function generatePassword(): string
	{
		$characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcefghijklmnopqrstuvwxyz';
		return substr(str_shuffle($characters), 0, self::MIN_PASSWORD_LENGTH);
	}

	/**
	 * Get CSRF token
	 * @return string
	 */
	public function getToken(): string
	{
		return $_SESSION['token'] ?? $_SESSION['token'] = bin2hex(openssl_random_pseudo_bytes(32));
	}

	/**
	 * Get something from the database
	 */
	public function get()
	{
		$numArgs = func_num_args();
		$args = func_get_args();
		switch ($numArgs) {
			case 1:
				return $this->db->{$args[0]};
			case 2:
				return $this->db->{$args[0]}->{$args[1]};
			case 3:
				return $this->db->{$args[0]}->{$args[1]}->{$args[2]};
			case 4:
				return $this->db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]};
			default:
				$this->alert('danger', 'Too many arguments to get().');
		}
	}

	/**
	 * Get content of a file from master branch on GitHub
	 *
	 * @param string $file the file we want
	 * @return string
	 */
	public function getFileFromRepo(string $file): string
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_URL, self::WCMS_REPO . $file);
		$content = curl_exec($ch);
		if (false === $content) {
			$this->alert('danger', 'Cannot get content from repository.');
		}
		curl_close($ch);

		return (string)$content;
	}

	/**
	 * Get the latest version from master branch on GitHub
	 * @return string
	 */
	private function getOfficialVersion(): string
	{
		return trim($this->getFileFromRepo('version'));
	}

	/**
	 * Checks token with hash_equals
	 *
	 * @param string $token
	 * @return bool
	 */
	private function hashVerify(string $token): bool
	{
		return hash_equals($token, $this->getToken());
	}

	/**
	 * Returns hooks from plugins
	 * @return array
	 */
	private function hook(): array
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
	 * Theme/plugin installer and updater
	 * @return void
	 */
	private function installThemePluginAction(): void
	{
		if (!$this->loggedIn && !isset($_POST['installAddon'])) {
			return;
		}

		if ($this->hashVerify($_POST['token'])) {
			if (isset($_POST['installLocation'])) {
				$installLocation = strtolower(trim($_POST['installLocation']));
				$addonURL = $_POST['addonURL'];
				$validPaths = ['themes', 'plugins'];
			} else {
				$this->alert('danger', 'Choose between theme or plugin.');
				$this->redirect();
			}
			if (empty($addonURL)) {
				$this->alert('danger', 'Invalid theme/plugin URL.');
				$this->redirect();
			}
			if (in_array($installLocation, $validPaths)) {
				$zipFile = $this->filesPath . '/ZIPFromURL.zip';
				$zipResource = fopen($zipFile, 'w');
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $addonURL);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_FILE, $zipResource);
				curl_exec($ch);
				curl_close($ch);
				$zip = new \ZipArchive;
				$extractPath = $this->rootDir . '/' . $installLocation . '/';
				if ($zip->open($zipFile) !== true || (stripos($addonURL, '.zip') === false)) {
					$this->recursiveDelete($this->rootDir . '/data/files/ZIPFromURL.zip');
					$this->alert('danger', 'Error opening ZIP file.');
					$this->redirect();
				}
				$zip->extractTo($extractPath);
				$zip->close();
				$this->recursiveDelete($this->rootDir . '/data/files/ZIPFromURL.zip');
				$this->alert('success', 'Installed successfully.');
				$this->redirect();
			}
			$this->alert('danger', 'Enter URL to ZIP file.');
			$this->redirect();
		}
	}

	/**
	 * Insert JS if the user is logged in
	 * @return string
	 */
	public function js(): string
	{
		if ($this->loggedIn) {
			$scripts = <<<'EOT'
<script src="https://cdn.jsdelivr.net/npm/autosize@4.0.2/dist/autosize.min.js" integrity="sha384-gqYjRLBp7SeF6PCEz2XeqqNyvtxuzI3DuEepcrNHbrO+KG3woVNa/ISn/i8gGtW8" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/taboverride@4.0.3/build/output/taboverride.min.js" integrity="sha384-fYHyZra+saKYZN+7O59tPxgkgfujmYExoI6zUvvvrKVT1b7krdcdEpTLVJoF/ap1" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery.taboverride@4.0.0/build/jquery.taboverride.min.js" integrity="sha384-RU4BFEU2qmLJ+oImSowhm+0Py9sT+HUD71kZz1i0aWjBfPx+15Y1jmC8gMk1+1W4" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/gh/robiso/wondercms-cdn-files@3.0.2/wcms-admin.min.js" integrity="sha384-8UGfrafhPEcVc2EA31dY+HCuGEg4oQQWC4zA1BH4XdyBKX/BFtty01yTCUklqokk" crossorigin="anonymous"></script>
EOT;
			$scripts .= '<script>let token = "' . $this->getToken() . '";</script>';
			return $this->hook('js', $scripts)[0];
		}
		return $this->hook('js', '')[0];
	}

	/**
	 * Load plugins (if they exist)
	 * @return void
	 */
	private function loadPlugins(): void
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
	 * Loads theme files and the functions.php file, if they exists
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
	 * Hook for fetching custom menu settings
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
			session_regenerate_id();
			$_SESSION['loggedIn'] = true;
			$_SESSION['rootDir'] = $this->rootDir;
			$this->redirect();
		}
		$this->alert('danger', 'Wrong password.');
		$this->redirect($this->get('config', 'login'));
	}

	/**
	 * Check if the user is logged in
	 * @return void
	 */
	public function loginStatus(): void
	{
		$this->loggedIn = isset($_SESSION['loggedIn'], $_SESSION['rootDir']) && $_SESSION['rootDir'] === $this->rootDir;
	}

	/**
	 * Admin login form view
	 * @return array
	 */
	public function loginView(): array
	{
		return [
			'title' => 'Login',
			'description' => '',
			'keywords' => '',
			'content' => '<form action="'
				. self::url($this->get('config', 'login'))
				. '" method="post"><div class="input-group"><input type="password" class="form-control" id="password" name="password"><span class="input-group-btn input-group-append"><button type="submit" class="btn btn-info">Login</button></span></div></form>'
		];
	}

	/**
	 * Logout action
	 * @return void
	 */
	public function logoutAction(): void
	{
		if ($this->currentPage === 'logout'
			&& isset($_REQUEST['token'])
			&& $this->hashVerify($_REQUEST['token'])) {
			unset($_SESSION['loggedIn'], $_SESSION['rootDir'], $_SESSION['token']);
			$this->redirect();
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
		return $this->hook('menu', $output)[0];
	}

	/**
	 * 404 header response
	 * @return void
	 */
	public function notFoundResponse(): void
	{
		if (!$this->loggedIn && !$this->currentPageExists) {
			header('HTTP/1.1 404 Not Found');
		}
	}

	/**
	 * Returns 404 page to visitors
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
	 * Alerts for non-existent page, changing default settings, new version/update
	 * @return void
	 */
	private function notifyAction(): void
	{
		if (!$this->loggedIn) {
			return;
		}
		if (!$this->currentPageExists) {
			$this->alert(
				'info',
				'<b>This page (' . $this->currentPage . ') doesn\'t exist.</b> Click inside the content below to create it.'
			);
		}
		if ($this->get('config', 'login') === 'loginURL') {
			$this->alert('danger', 'Change your default password and login URL. (<i>Settings -> Security</i>)', true);
		}
		if ($this->getOfficialVersion() > self::VERSION) {
			$this->alert(
				'info',
				'<h4><b>New WonderCMS update available</b></h4>
						- Backup your website and <a href="https://wondercms.com/whatsnew" target="_blank"><u>check what\'s new</u></a> before updating.
						 <form action="' . self::url($this->currentPage) . '" method="post" class="marginTop5">
							<button type="submit" class="btn btn-info" name="backup">Download backup</button>
							<div class="clear"></div>
							<button class="btn btn-info marginTop5" name="update">Update WonderCMS ' . self::VERSION . ' to ' . $this->getOfficialVersion() . '</button>
							<input type="hidden" name="token" value="' . $this->getToken() . '">
						</form>',
				true
			);
		}
	}

	/**
	 * Reorder the pages
	 *
	 * @param int $content 1 for down arrow, or -1 for up arrow clicked
	 * @param int $menu
	 * @return void
	 */
	private function orderMenuItem(int $content, int $menu): void
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
		// now write the other menu item to the previous position
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
			'content' => $this->loggedIn
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
	private function pageStatus(): void
	{
		$this->currentPage = empty($this->parseUrl()) ? $this->get('config', 'defaultPage') : $this->parseUrl();
		if (isset($this->get('pages')->{$this->currentPage})) {
			$this->currentPageExists = true;
		}
		if (isset($_GET['page']) && !$this->loggedIn && $this->currentPage !== $this->slugify($_GET['page'])) {
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
	private function recursiveDelete(string $file): void
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
	 * Save database to disk
	 * @return void
	 */
	public function save(): void
	{
		file_put_contents(
			$this->dbPath,
			json_encode($this->db, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
		);
	}

	/**
	 * Saving menu items, default page, login URL, theme, editable content
	 * @return void
	 */
	private function saveAction(): void
	{
		if (!$this->loggedIn) {
			return;
		}
		if (isset($_POST['fieldname'], $_POST['content'], $_POST['target'], $_POST['token'])
			&& $this->hashVerify($_POST['token'])) {
			[$fieldname, $content, $target, $menu, $visibility] = $this->hook('save', $_POST['fieldname'],
				$_POST['content'], $_POST['target'], $_POST['menu'], $_POST['visibility']);
			if ($target === 'menuItem') {
				$this->createMenuItem($content, $menu, $visibility);
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
	 * Display the admin settings panel
	 * @return string
	 */
	public function settings(): string
	{
		if (!$this->loggedIn) {
			return '';
		}
		$fileList = array_slice(scandir($this->filesPath), 2);
		$themeList = array_slice(scandir($this->rootDir . '/themes/'), 2);
		$pluginList = array_slice(scandir($this->rootDir . '/plugins/'), 2);
		$output = '
		<div id="save">
			<h2>Saving...</h2>
		</div>
		<div id="adminPanel" class="container-fluid">
			<div class="text-right padding20">
				<a data-toggle="modal" class="padding20" href="#settingsModal"><b>Settings</b></a><a href="' . self::url('logout&token=' . $this->getToken()) . '">Logout</a>
			</div>
			<div class="modal" id="settingsModal">
				<div class="modal-dialog modal-xl">
				 <div class="modal-content">
					<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button></div>
					<div class="modal-body col-xs-12 col-12">
						<ul class="nav nav-tabs justify-content-center text-center" role="tablist">
							<li role="presentation" class="nav-item active"><a href="#currentPage" aria-controls="currentPage" role="tab" data-toggle="tab" class="nav-link">Current page</a></li>
							<li role="presentation" class="nav-item"><a href="#general" aria-controls="general" role="tab" data-toggle="tab" class="nav-link">General</a></li>
							<li role="presentation" class="nav-item"><a href="#files" aria-controls="files" role="tab" data-toggle="tab" class="nav-link">Files</a></li>
							<li role="presentation" class="nav-item"><a href="#themesAndPlugins" aria-controls="themesAndPlugins" role="tab" data-toggle="tab" class="nav-link">Themes & plugins</a></li>
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
									<a href="' . self::url('?delete=' . $this->currentPage . '&token=' . $this->getToken()) . '" class="btn btn-danger marginTop20" title="Delete page" onclick="return confirm(\'Delete ' . $this->currentPage . '?\')">Delete page (' . $this->currentPage . ')</a>';
		} else {
			$output .= 'This page doesn\'t exist. More settings will be displayed here after this page is created.';
		}
		$output .= '
							</div>
							<div role="tabpanel" class="tab-pane" id="general">';
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
											 <i class="btn menu-toggle fas' . ($value->visibility === 'show' ? ' fa-eye menu-item-hide' : ' fa-eye-slash menu-item-show') . '" data-toggle="tooltip" title="' . ($value->visibility === 'show' ? 'Hide page from menu' : 'Show page in menu') . '" data-menu="' . $key . '"></i>
											</div>
											<div class="col-xs-4 col-4 col-sm-8">
											 <div data-target="menuItem" data-menu="' . $key . '" data-visibility="' . $value->visibility . '" id="menuItems" class="editText">' . $value->name . '</div>
											</div>
											<div class="col-xs-2 col-2 col-sm-1 text-left">';
			$output .= ($key === $first) ? '' : '<a class="fas fa-arrow-up toolbar menu-item-up cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move up"></a>';
			$output .= ($key === $end) ? '' : ' <a class="fas fa-arrow-down toolbar menu-item-down cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move down"></a>';
			$output .= '
											</div>
											<div class="col-xs-2 col-2 col-sm-1 text-left">
											 <a class="fas fa-link" href="' . self::url($value->slug) . '" title="Visit page">visit</a>
											</div>
											<div class="col-xs-2 col-2 col-sm-1 text-right">
											 <a href="' . self::url('?delete=' . $value->slug . '&token=' . $this->getToken()) . '" title="Delete page" class="btn btn-xs btn-sm btn-danger" data-menu="' . $key . '" onclick="return confirm(\'Delete ' . $value->slug . '?\')">&times;</a>
											</div>
										</div>';
		}
		$output .= '<a class="menu-item-add btn btn-info marginTop20" data-toggle="tooltip" title="Add new page" type="button">Add page</a>
								</div>
							 </div>
							 <p class="subTitle">Theme</p>
							 <div class="form-group">
								<div class="change">
									<select class="form-control" name="themeSelect" onchange="fieldSave(\'theme\',this.value,\'config\');">';
		foreach (glob($this->rootDir . '/themes/*', GLOB_ONLYDIR) as $dir) {
			$output .= '<option value="' . basename($dir) . '"' . (basename($dir) === $this->get('config',
					'theme') ? ' selected' : '') . '>' . basename($dir) . ' theme</option>';
		}
		$output .= '
									</select>
								</div>
							 </div>
							 <p class="subTitle">Main website title</p>
							 <div class="change">
								<div data-target="config" id="siteTitle" class="editText">' . $this->get('config',
				'siteTitle') . '</div>
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
							 <p class="subTitle">Footer</p>
							 <div class="change">
								<div data-target="blocks" id="footer" class="editText">' . $this->get('blocks',
				'footer')->content . '</div>
							 </div>
							</div>
							<div role="tabpanel" class="tab-pane" id="files">
							 <p class="subTitle">Upload</p>
							 <div class="change">
								<form action="' . self::url($this->currentPage) . '" method="post" enctype="multipart/form-data">
									<div class="input-group"><input type="file" name="uploadFile" class="form-control">
										<span class="input-group-btn"><button type="submit" class="btn btn-info input-group-append">Upload</button></span>
										<input type="hidden" name="token" value="' . $this->getToken() . '">
									</div>
								</form>
							 </div>
							 <p class="subTitle marginTop20">Delete files</p>
							 <div class="change">';
		foreach ($fileList as $file) {
			$output .= '
									<a href="' . self::url('?deleteFile=' . $file . '&token=' . $this->getToken()) . '" class="btn btn-xs btn-sm btn-danger" onclick="return confirm(\'Delete ' . $file . '?\')" title="Delete file">&times;</a>
									<span class="marginLeft5">
										<a href="' . self::url('data/files/') . $file . '" class="normalFont" target="_blank">' . self::url('data/files/') . '<b class="fontSize21">' . $file . '</b></a>
									</span>
									<p></p>';
		}
		$output .= '
							 </div>
							</div>
							<div role="tabpanel" class="tab-pane" id="themesAndPlugins">
							 <p class="subTitle">Install or update</p>
							 <div class="change">
								<form action="' . self::url($this->currentPage) . '" method="post">
									<div class="form-group">
										<label class="radio-inline form-check-inline"><input type="radio" name="installLocation" value="themes" class="form-check-input">Theme</label>
										<label class="radio-inline form-check-inline"><input type="radio" name="installLocation" value="plugins" class="form-check-input">Plugin</label>
										<div class="input-group marginTop5"><input type="text" name="addonURL" class="form-control normalFont" placeholder="Paste link/URL to ZIP file">
											<span class="input-group-btn input-group-append"><button type="submit" class="btn btn-info">Install/Update</button></span>
										</div>
									</div>
									<input type="hidden" value="true" name="installAddon"><input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="subTitle">Delete themes</p>
							 <div class="change">';
		foreach ($themeList as $theme) {
			$output .= '<a href="' . self::url('?deleteTheme=' . $theme . '&token=' . $this->getToken()) . '" class="btn btn-xs btn-sm btn-danger" onclick="return confirm(\'Delete ' . $theme . '?\')" title="Delete theme">&times;</a> ' . $theme . '<p></p>';
		}
		$output .= '
							 </div>
							 <p class="subTitle">Delete plugins</p>
							 <div class="change">';
		foreach ($pluginList as $plugin) {
			$output .= '<a href="' . self::url('?deletePlugin=' . $plugin . '&token=' . $this->getToken()) . '" class="btn btn-xs btn-sm btn-danger" onclick="return confirm(\'Delete ' . $plugin . '?\')" title="Delete plugin">&times;</a> ' . $plugin . '
									<p></p>';
		}
		$output .= '
							 </div>
							</div>
							<div role="tabpanel" class="tab-pane" id="security">
							 <p class="subTitle">Admin login URL</p>
							 <div class="change">
								<div data-target="config" id="login" class="editText">' . $this->get('config',
				'login') . '</div>
								<p class="text-right marginTop5">Important: bookmark your login URL after changing<br /><span class="normalFont"><b>' . self::url($this->get('config',
				'login')) . '</b></span>
							 </div>
							 <p class="subTitle">Password</p>
							 <div class="change">
								<form action="' . self::url($this->currentPage) . '" method="post">
									<div class="input-group">
										<input type="password" name="old_password" class="form-control" placeholder="Old password">
										<span class="input-group-btn"></span><input type="password" name="new_password" class="form-control" placeholder="New password">
										<span class="input-group-btn input-group-append"><button type="submit" class="btn btn-info">Change password</button></span>
									</div>
									<input type="hidden" name="fieldname" value="password"><input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="subTitle">Backup</p>
							 <div class="change">
								<form action="' . self::url($this->currentPage) . '" method="post">
									<button type="submit" class="btn btn-block btn-info" name="backup">Backup website</button><input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Restore-backup#how-to-restore-a-backup-in-3-steps" target="_blank">How to restore backup</a></p>
							 <p class="subTitle">Better security (Apache only)</p>
							 <p>HTTPS redirect, 30 day caching, iframes allowed only from same origin, mime type sniffing prevention, stricter cookie and refferer policy.</p>
							 <div class="change">
								<form method="post">
									<div class="btn-group btn-group-justified w-100">
										<div class="btn-group w-50"><button type="submit" class="btn btn-success" name="betterSecurity" value="on">ON (warning: may break your website)</button></div>
										<div class="btn-group w-50"><button type="submit" class="btn btn-danger" name="betterSecurity" value="off">OFF (reset htaccess to default)</button></div>
									</div>
									<input type="hidden" name="token" value="' . $this->getToken() . '">
								</form>
							 </div>
							 <p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Better-security-mode-(HTTPS-and-other-features)#important-read-before-turning-this-feature-on" target="_blank">Read more before enabling</a></p>
							</div>
						</div>
					</div>
					<div class="modal-footer clear">
						<p class="small">
							<a href="https://wondercms.com" target="_blank">WonderCMS</a> ' . self::VERSION . ' &nbsp; 
							<b>
							 <a href="https://wondercms.com/whatsnew" target="_blank">News</a> &nbsp; 
							 <a href="https://wondercms.com/themes" target="_blank">Themes</a> &nbsp; 
							 <a href="https://wondercms.com/plugins" target="_blank">Plugins</a> &nbsp; 
							 <a href="https://wondercms.com/community" target="_blank">Community</a> &nbsp; 
							 <a href="https://github.com/robiso/wondercms/wiki#wondercms-documentation" target="_blank">Docs</a> &nbsp; 
							 <a href="https://wondercms.com/donate" target="_blank">Donate</a>
							</b>
						</p>
					</div>
				 </div>
				</div>
			</div>
		</div>';
		return $this->hook('settings', $output)[0];
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
	 * Delete something from the database
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
	 * Update WonderCMS function
	 * Overwrites index.php with latest from GitHub
	 * @return void
	 */
	private function updateAction(): void
	{
		if (!$this->loggedIn || !isset($_POST['update'])) {
			return;
		}
		if ($this->hashVerify($_POST['token'])) {
			$contents = $this->getFileFromRepo('index.php');
			if ($contents) {
				file_put_contents(__FILE__, $contents);
			}
			$this->alert('success', 'WonderCMS successfully updated. Wohoo!');
			$this->redirect();
		}
	}

	/**
	 * Update dbVersion parameter in database.js
	 * Overwrites dbVersion with latest WonderCMS version
	 * @return void
	 */
	private function updateDBVersion(): void
	{
		if ($this->get('config', 'dbVersion') < self::VERSION) {
			$this->set('config', 'dbVersion', self::VERSION);
		}
	}

	/**
	 * Upload file to files folder
	 * List of allowed extensions
	 *
	 * @return void
	 */
	private function uploadFileAction(): void
	{
		if (!$this->loggedIn && !isset($_FILES['uploadFile']) && !isset($_POST['token'])) {
			return;
		}
		$allowedExtensions = [
			'avi' => 'video/avi',
			'doc' => 'application/vnd.ms-word',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'flv' => 'video/x-flv',
			'gif' => 'image/gif',
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
			'svg' => 'image/svg+xml',
			'txt' => 'text/plain',
			'xls' => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'webm' => 'video/webm',
			'wmv' => 'video/x-ms-wmv',
			'zip' => 'application/zip',
		];
		if (isset($_POST['token'], $_FILES['uploadFile']) && $this->hashVerify($_POST['token'])) {
			if (!isset($_FILES['uploadFile']['error']) || is_array($_FILES['uploadFile']['error'])) {
				$this->alert('danger', 'Invalid parameters.');
				$this->redirect();
			}
			switch ($_FILES['uploadFile']['error']) {
				case UPLOAD_ERR_OK:
					break;
				case UPLOAD_ERR_NO_FILE:
					$this->alert('danger', 'No file selected.');
					$this->redirect();
					break;
				case UPLOAD_ERR_INI_SIZE:
				case UPLOAD_ERR_FORM_SIZE:
					$this->alert('danger', 'File too large. Change maximum upload size limit or contact your host.');
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
				$this->alert('danger', 'File format is not allowed.');
				$this->redirect();
			}
			if (!move_uploaded_file($_FILES['uploadFile']['tmp_name'],
				$this->filesPath . '/' . basename($_FILES['uploadFile']['name']))) {
				$this->alert('danger', 'Failed to move uploaded file.');
			}
			$this->alert('success', 'File uploaded.');
			$this->redirect();
		}
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
	 * Create a ZIP backup of all content
	 *
	 * @return void
	 * @throws Exception
	 */
	private function zipBackup(): void
	{
		$zipName = date('Y-m-d') . '-backup-' . bin2hex(random_bytes(8)) . '.zip';
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
}
