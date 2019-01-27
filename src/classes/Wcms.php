<?php
/**
 * @package WonderCMS
 * @author Robert Isoski
 * @see https://www.wondercms.com - offical website
 * @license MIT
 */
namespace Robiso\Wondercms;

use Symfony\Component\HttpFoundation\Request;

/**
 * One and only class doing everything
 */
class Wcms
{
    /** @var int MIN_PASSWORD_LENGTH minimum number of characters for password */
    private const MIN_PASSWORD_LENGTH = 8;

    /** @var string VERSION current version of WonderCMS */
    public const VERSION = '2.6.0';

    /** @var string $currentPage the current page */
    public $currentPage = '';

    /** @var bool $currentPageExists does the current page exist? */
    public $currentPageExists = false;

    /** @var object $db content of the database.js */
    private $db;

    /** @var bool $loggedIn is the user logged in? */
    public $loggedIn = false;

    /** @var array $listeners for hooks */
    public $listeners = [];

    /** @var string $dbPath path to database.js */
    private $dbPath;

    /** @var string $filesPath path to uploaded files */
    public $filesPath;

    /** @var string $rootDir root dir of the install (where index.php is) */
    public $rootDir;

    /**
     * Constructor
     *
     */
    public function __construct()
    {
        $this->rootDir = dirname(__DIR__, 2);
        $this->dbPath = $this->rootDir . '/data/database.js';
        $this->filesPath = $this->rootDir . '/data/files';
        $this->db = $this->getDb();
    }

    /**
     * Init function called on each page load
     *
     * @return void
     */
    public function init(): void
    {
        $this->installThemePluginAction();
        // TODO $this->loadPlugins();
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

    public function render(): void
    {
        $this->loadThemeAndFunctions();
    }

    /**
     * Function used by plugins to add a hook
     *
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
                if ($v['message'] == $message) {
                    return;
                }
            }
        }
        $_SESSION['alert'][$class][] = ['class' => $class, 'message' => $message, 'sticky' => $sticky];
    }

    /**
     * Display alert message to the user
     *
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
                $output .= '<div class="alert alert-' . $alert['class'] . (!$alert['sticky'] ? ' alert-dismissible' : '') . '">' . (!$alert['sticky'] ? '<button type="button" class="close" data-dismiss="alert">&times;</button>' : '') . $alert['message'] . '</div>';
            }
        }
        unset($_SESSION['alert']);
        return $output;
    }

    /**
     * Get an asset (returns URL of the asset)
     *
     * @return string
     */
    public function asset(string $location): string
    {
        return $this->url('themes/' . $this->get('config', 'theme') . '/' . $location);
    }

    /**
     * Backup whole WonderCMS installation
     *
     * @return void
     */
    private function backupAction(): void
    {
        if (!$this->loggedIn || !isset($_POST['backup'])) {
            return;
        }
        $backupList = \glob($this->filesPath . '/*wcms-backup-*.zip');
        if (!empty($backupList)) {
            $this->alert('danger', 'Delete backup files. (<i>Settings -> Files</i>)');
        }
        if (\hash_equals($_POST['token'], $this->getToken())) {
            $this->zipBackup();
        }
    }

    /**
     * Replace the .htaccess with one adding security settings
     *
     * @return void
     */
    private function betterSecurityAction(): void
    {
        if ($this->loggedIn && isset($_POST['betterSecurity']) && isset($_POST['token'])) {
            if (hash_equals($_POST['token'], $this->getToken())) {
                if ($_POST['betterSecurity'] == 'on') {
                    $contents = $this->getFileFromRepo('.htaccess-ultimate');
                    if ($contents) {
                        file_put_contents('.htaccess', trim($contents));
                    }
                    $this->alert('success', 'Better security turned ON.');
                    $this->redirect();
                } elseif ($_POST['betterSecurity'] == 'off') {
                    $contents = $this->getFileFromRepo('.htaccess');
                    if ($contents) {
                        file_put_contents('.htaccess', trim($contents));
                    }
                    $this->alert('success', 'Better security turned OFF.');
                    $this->redirect();
                }
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
        return isset($blocks->{$key}) ? ($this->loggedIn ? $this->editable($key, $blocks->{$key}->content, 'blocks') : $blocks->{$key}->content) : '';
    }

    /**
     * Change password
     *
     * @return void
     */
    private function changePasswordAction(): void
    {
        if ($this->loggedIn && isset($_POST['old_password']) && isset($_POST['new_password'])) {
            if ($_SESSION['token'] === $_POST['token'] && hash_equals($_POST['token'], $this->getToken())) {
                if (!password_verify($_POST['old_password'], $this->get('config', 'password'))) {
                    $this->alert('danger', 'Wrong password.');
                    $this->redirect();
                }
                if (strlen($_POST['new_password']) < self::MIN_PASSWORD_LENGTH) {
                    $this->alert('danger', sprintf('Password must be longer than %d characters.', self::MIN_PASSWORD_LENGTH));
                    $this->redirect();
                }
                $this->set('config', 'password', password_hash($_POST['new_password'], PASSWORD_DEFAULT));
                $this->alert('success', 'Password changed.');
                $this->redirect();
            }
        }
    }

    /**
     * Initialize the database if it's empty
     *
     * @return void
     */
    private function createDb(): void
    {
        $password = $this->generatePassword();
        $this->db = (object) [
            'config' => [
                'dbVersion' => '2.6.0',
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

<h4><a href="' . $this->url('loginURL') . '">Click to login.</a> Your password is: <b>' . $password . '</b></a></h4>'
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
        $content = empty($content) ? "empty" : str_replace(array(PHP_EOL, '<br>'), '', $content);
        $slug = $this->slugify($content);
        $menuCount = count(get_object_vars($this->get($conf, $field)));
        if (!$exist) {
            $db = $this->getDb();
            foreach ($db->config->{$field} as $value) {
                if ($value->slug == $slug) {
                    $slug .= "-" . $menuCount;
                }
            }
            $db->config->{$field}->{$menuCount} = new \stdClass;
            $this->save();
            $this->set($conf, $field, $menuCount, 'name', str_replace("-", " ", $content));
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
    private function createPage($slug = '')
    {
        $this->db->pages->{(!$slug) ? $this->currentPage : $slug} = new \stdClass;
        $this->save();
        $this->set('pages', (!$slug) ? $this->slugify($this->currentPage) : $slug, 'title', (!$slug) ? mb_convert_case(str_replace("-", " ", $this->currentPage), MB_CASE_TITLE) : mb_convert_case(str_replace("-", " ", $slug), MB_CASE_TITLE));
        $this->set('pages', (!$slug) ? $this->slugify($this->currentPage) : $slug, 'keywords', 'Keywords, are, good, for, search, engines');
        $this->set('pages', (!$slug) ? $this->slugify($this->currentPage) : $slug, 'description', 'A short description is also good.');
        if (!$slug) {
            $this->createMenuItem($this->slugify($this->currentPage), '', "show");
        }
    }

    /**
     * Inject CSS into page
     *
     * @return string
     */
    public function css(): string
    {
        if ($this->loggedIn) {
            // load the minified css file
            $wcmsCSS = file_get_contents($this->rootDir . '/assets/admin.min.css');
            $styles = '<style>' . $wcmsCSS . '</style>';
            return $this->hook('css', $styles)[0];
        }
        return $this->hook('css', '')[0];
    }

    /**
     * Get content of the database
     *
     * @return object
     */
    public function getDb()
    {
        // initialize the database if it doesn't exist yet
        if (!\file_exists($this->dbPath)) {
            $this->createDb();
        }
        //var_dump($this->rootDir . '/database.js');
        //var_dump(\json_decode(\file_get_contents($this->rootDir . '/database.js')));die;
        return \json_decode(\file_get_contents($this->dbPath));
    }

    /**
     * Delete theme
     *
     * @return void
     */
    private function deleteFileThemePluginAction(): void
    {
        if (!$this->loggedIn) {
            return;
        }
        if (isset($_REQUEST['deleteFile']) || isset($_REQUEST['deleteTheme']) || isset($_REQUEST['deletePlugin']) && isset($_REQUEST['token'])) {
            if (hash_equals($_REQUEST['token'], $this->getToken())) {
                $deleteList = [
                    [$this->filesPath, 'deleteFile'],
                    [$this->rootDir . '/themes', 'deleteTheme'],
                    [$this->rootDir . '/plugins', 'deletePlugin'],
                ];
                foreach ($deleteList as $entry) {
                    list($folder, $request) = $entry;
                    $filename = isset($_REQUEST[$request]) ? str_ireplace(['./', '../', '..', '~', '~/'], '', trim($_REQUEST[$request])) : '';
                    if (empty($filename)) {
                        continue;
                    }
                    if ($filename == $this->get('config', 'theme')) {
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
    }

    /**
     * Delete page
     *
     * @return void
     */
    private function deletePageAction(bool $needle = false, bool $menu = true)
    {
        if (!$needle) {
            if ($this->loggedIn && isset($_GET['delete']) && hash_equals($_REQUEST['token'], $this->getToken())) {
                $needle = $_GET['delete'];
            }
        }
        if (isset($this->get('pages')->{$needle})) {
            unset($this->db->pages->{$needle});
        }
        if ($menu) {
            $menuItems = json_decode(json_encode($this->get('config', 'menuItems')), true);
            if (false === ($index = array_search($needle, array_column($menuItems, "slug")))) {
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
        return '<div' . ($dataTarget != '' ? ' data-target="' . $dataTarget . '"' : '') . ' id="' . $id . '" class="editText editable">' . $content . '</div>';
    }

    /**
     * Get the footer
     *
     * @return string
     */
    public function footer(): string
    {
        $output = $this->get('blocks', 'footer')->content . (!$this->loggedIn ? (($this->get('config', 'login') == 'loginURL') ? ' &bull; <a href="' . $this->url('loginURL') . '">Login</a>' : '') : '');
        return $this->hook('footer', $output)[0];
    }

    /**
     * Generate random password
     *
     * @return string
     */
    private function generatePassword(): string
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcefghijklmnopqrstuvwxyz';
        return substr(str_shuffle($characters), 0, self::MIN_PASSWORD_LENGTH);
    }

    /**
     * Get CSRF token
     *
     * @return string
     */
    public function getToken(): string
    {
        return (isset($_SESSION["token"])) ? $_SESSION["token"] : $_SESSION["token"] = bin2hex(openssl_random_pseudo_bytes(32));
    }

    /**
     * Get something from the database
     *
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
                throw new \Exception('Too many arguments to get()');
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
        $repoUrl = 'https://raw.githubusercontent.com/robiso/wondercms/master/';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $repoUrl . $file);
        $content = curl_exec($ch);
        if ($content === false) {
            throw new \Exception('Cannot get content from repository!');
        }
        curl_close($ch);
        // cast to string because curl_exec() can return true
        // but it won't because of CURLOPT_RETURNTRANSFER
        return (string) $content;
    }

    /*
    private function getMenuSettings()
    {
        return $this->hook('getMenuSettings', $output)[0];
    }
     */

    /**
     * Get the latest version from master branch on GitHub
     *
     * @return string
     */
    private function getOfficialVersion(): string
    {
        return trim($this->getFileFromRepo('version'));
    }

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

    private function installThemePluginAction(): void
    {
        if (!$this->loggedIn && !isset($_POST['installAddon'])) {
            return;
        }

        if (!\hash_equals($_POST['token'], $this->getToken())) {
            throw new \Exception('Invalid token');
        }
        if (!\filter_var($_POST['addonURL'], FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid addon URL');
        }
        $addonURL = $_POST['addonURL'];

        $installLocation = trim(strtolower($_POST['installLocation']));
        $validPaths = array('themes', 'plugins');
        if (!\in_array($installLocation, $validPaths)) {
            $this->alert('danger', 'Choose between theme or plugin.');
            $this->redirect();
        }
        $zipFile = $this->filesPath . '/ZIPFromURL.zip';
        $zipResource = fopen($zipFile, "w");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $addonURL);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FILE, $zipResource);
        curl_exec($ch);
        curl_close($ch);
        $zip = new \ZipArchive;
        $extractPath = $this->rootDir . '/' . $installLocation . '/';
        if ($zip->open($zipFile) !== true) {
            unlink($zipFile);
            $this->alert('danger', 'Error opening ZIP file.');
            $this->redirect();
        }
        $zip->extractTo($extractPath);
        $zip->close();
        unlink($zipFile);
        $this->alert('success', 'Installed successfully.');
        $this->redirect();
    }

    /**
     * Insert javascript if user is logged in
     *
     * @return string
     */
    public function js(): string
    {
        if ($this->loggedIn) {
            $wcmsJS = file_get_contents($this->rootDir . '/assets/admin.min.js');
            $scripts = <<<'EOT'
<script src="https://cdn.jsdelivr.net/npm/autosize@4.0.2/dist/autosize.min.js" integrity="sha384-gqYjRLBp7SeF6PCEz2XeqqNyvtxuzI3DuEepcrNHbrO+KG3woVNa/ISn/i8gGtW8" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/taboverride@4.0.3/build/output/taboverride.min.js" integrity="sha384-fYHyZra+saKYZN+7O59tPxgkgfujmYExoI6zUvvvrKVT1b7krdcdEpTLVJoF/ap1" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery.taboverride@4.0.0/build/jquery.taboverride.min.js" integrity="sha384-RU4BFEU2qmLJ+oImSowhm+0Py9sT+HUD71kZz1i0aWjBfPx+15Y1jmC8gMk1+1W4" crossorigin="anonymous"></script>
EOT;
            $scripts .= '<script>' . $wcmsJS . '</script>';
            $scripts .= '<script>var token = "' . $this->getToken() . '";</script>';
            return $this->hook('js', $scripts)[0];
        }
        return $this->hook('js', '')[0];
    }

    /**
     * Load plugins (if they exist)
     *
     * @return void
     */
    private function loadPlugins(): void
    {
        if (!is_dir($this->rootDir . '/plugins')) {
            mkdir($this->rootDir . '/plugins');
        }
        if (!is_dir($this->filesPath)) {
            mkdir($this->filesPath);
        }
        foreach (glob($this->rootDir . '/plugins/*', GLOB_ONLYDIR) as $dir) {
            if (file_exists($dir . '/' . basename($dir) . '.php')) {
                include $dir . '/' . basename($dir) . '.php';
            }
        }
    }

    /**
     * Loads theme file and also loads the functions.php (if it exists)
     *
     * @return void
     */
    public function loadThemeAndFunctions(): void
    {
        if (file_exists($this->rootDir . '/themes/' . $this->get('config', 'theme') . '/functions.php')) {
            require_once $this->rootDir . '/themes/' . $this->get('config', 'theme') . '/functions.php';
        }
        require_once $this->rootDir . '/themes/' . $this->get('config', 'theme') . '/theme.php';
    }

    private function loginAction(): void
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
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        if (password_verify($password, $this->get('config', 'password'))) {
            session_regenerate_id();
            $_SESSION['l'] = true;
            $_SESSION['i'] = $this->rootDir;
            $this->redirect();
        }
        $this->alert('danger', 'Wrong password.');
        $this->redirect($this->get('config', 'login'));
    }

    /**
     * Check if the user is logged in
     *
     * @return void
     */
    private function loginStatus(): void
    {
        if (isset($_SESSION['l'], $_SESSION['i']) && $_SESSION['i'] == $this->rootDir) {
            $this->loggedIn = true;
        }
    }

    public function loginView(): array
    {
        return ['title' => 'Login', 'description' => '', 'keywords' => '', 'content' => '<form action="' . $this->url($this->get('config', 'login')) . '" method="post"><div class="input-group"><input type="password" class="form-control" id="password" name="password"><span class="input-group-btn"><button type="submit" class="btn btn-info">Login</button></span></div></form>'];
    }

    private function logoutAction(): void
    {
        if ($this->currentPage === 'logout' && hash_equals($_REQUEST['token'], $this->getToken())) {
            unset($_SESSION['l'], $_SESSION['i'], $_SESSION['token']);
            $this->redirect();
        }
    }

    public function menu(): string
    {
        $output = '';
        foreach ($this->get('config', 'menuItems') as $item) {
            if ($item->visibility === 'hide') {
                continue;
            }
            $output .= '<li' . ($this->currentPage === $item->slug ? ' class="active"' : '') . '><a href="' . $this->url($item->slug) . '">' . $item->name . '</a></li>';
        }
        return $this->hook('menu', $output)[0];
    }

    public function notFoundResponse(): void
    {
        if (!$this->loggedIn && !$this->currentPageExists) {
            header("HTTP/1.1 404 Not Found");
        }
    }

    public function notFoundView(): array
    {
        if ($this->loggedIn) {
            return ['title' => str_replace("-", " ", $this->currentPage), 'description' => '', 'keywords' => '', 'content' => '<h2>Click to create content</h2>'];
        }
        return $this->get('pages', '404');
    }

    private function notifyAction(): void
    {
        if (!$this->loggedIn) {
            return;
        }
        if (!$this->currentPageExists) {
            $this->alert('info', '<b>This page (' . $this->currentPage . ') doesn\'t exist.</b> Click inside the content below to create it.');
        }
        if ($this->get('config', 'login') === 'loginURL') {
            $this->alert('danger', 'Change your default password and login URL. (<i>Settings -> Security</i>)', true);
        }
        if ($this->getOfficialVersion() > self::VERSION) {
            $this->alert('info', '<h4><b>New WonderCMS update available</b></h4>- Backup your website and <a href="https://wondercms.com/whatsnew" target="_blank"><u>check what\'s new</u></a> before updating.<form action="' . $this->url($this->currentPage) . '" method="post" class="marginTop5"><button type="submit" class="btn btn-info" name="backup">Download backup</button><input type="hidden" name="token" value="' . $this->getToken() . '"></form><form method="post" class="marginTop5"><button class="btn btn-info" name="update">Update WonderCMS ' . self::VERSION . ' to ' . $this->getOfficialVersion() . '</button><input type="hidden" name="token" value="' . $this->getToken() . '"></form>', true);
        }
    }

    private function orderMenuItem(int $content, int $menu): void
    {
        $conf = 'config';
        $field = 'menuItems';
        $move = $this->get($conf, $field, $menu);
        $menu += $content;
        $tmp = $this->get($conf, $field, $menu);
        $this->set($conf, $field, $menu, 'name', $move->name);
        $this->set($conf, $field, $menu, 'slug', $move->slug);
        $this->set($conf, $field, $menu, 'visibility', $move->visibility);
        $menu -= $content;
        $this->set($conf, $field, $menu, 'name', $tmp->name);
        $this->set($conf, $field, $menu, 'slug', $tmp->slug);
        $this->set($conf, $field, $menu, 'visibility', $tmp->visibility);
    }

    public function page(string $key): string
    {
        $segments = $this->currentPageExists ? $this->get('pages', $this->currentPage) : ($this->get('config', 'login') == $this->currentPage ? (object) $this->loginView() : (object) $this->notFoundView());
        $segments->content = isset($segments->content) ? $segments->content : '<h2>Click here add content</h2>';
        $keys = ['title' => $segments->title, 'description' => $segments->description, 'keywords' => $segments->keywords, 'content' => ($this->loggedIn ? $this->editable('content', $segments->content, 'pages') : $segments->content)];
        $content = isset($keys[$key]) ? $keys[$key] : '';
        return $this->hook('page', $content, $key)[0];
    }

    private function pageStatus(): void
    {
        $this->currentPage = empty($this->parseUrl()) ? $this->get('config', 'defaultPage') : $this->parseUrl();
        if (isset($this->get('pages')->{$this->currentPage})) {
            $this->currentPageExists = true;
        }
        if (isset($_GET['page']) && !$this->loggedIn) {
            if ($this->currentPage !== $this->slugify($_GET['page'])) {
                $this->currentPageExists = false;
            }
        }
    }

    public function parseUrl(): string
    {
        if (isset($_GET['page']) && $_GET['page'] == $this->get('config', 'login')) {
            return htmlspecialchars($_GET['page'], ENT_QUOTES);
        }
        return isset($_GET['page']) ? $this->slugify($_GET['page']) : '';
    }

    private function recursiveDelete(string $file): void
    {
        if (is_dir($file)) {
            $list = glob($file . '*', GLOB_MARK);
            foreach ($list as $dir) {
                $this->recursiveDelete($dir);
            }
            if (file_exists($file)) {
                rmdir($file);
            }
        } elseif (is_file($file)) {
            unlink($file);
        }
    }

    public function redirect(string $location = ''): void
    {
        header('Location: ' . $this->url($location));
        die();
    }

    /**
     * Save database to disk
     *
     * @return void
     */
    public function save(): void
    {
        file_put_contents($this->dbPath, json_encode($this->db, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function saveAction(): void
    {
        if (!$this->loggedIn) {
            return;
        }
        if (isset($_POST['fieldname']) && isset($_POST['content']) && isset($_POST['target']) && isset($_POST['token']) && hash_equals($_POST['token'], $this->getToken())) {
            list($fieldname, $content, $target, $menu, $visibility) = $this->hook('save', $_POST['fieldname'], $_POST['content'], $_POST['target'], $_POST['menu'], $_POST['visibility']);
            if ($target === 'menuItem') {
                $this->createMenuItem($content, $menu, $visibility);
            }
            if ($target === 'menuItemVsbl') {
                $this->set('config', $fieldname, $menu, 'visibility', $visibility);
            }
            if ($target === 'menuItemOrder') {
                $this->orderMenuItem($content, $menu);
            }
            if ($fieldname === 'defaultPage') {
                if (!isset($this->get('pages')->$content)) {
                    return;
                }
            }
            if ($fieldname === 'login') {
                if (empty($content) || isset($this->get('pages')->$content)) {
                    return;
                }
            }
            if ($fieldname === 'theme') {
                if (!is_dir($this->rootDir . '/themes/' . $content)) {
                    return;
                }
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

    public function settings(): string
    {
        if (!$this->loggedIn) {
            return '';
        }
        $fileList = array_slice(scandir($this->filesPath), 2);
        $themeList = array_slice(scandir($this->rootDir . '/themes/'), 2);
        $pluginList = array_slice(scandir($this->rootDir . '/plugins/'), 2);
        $output = '<div id="save"><h2>Saving...</h2></div><div id="adminPanel" class="container-fluid"><div class="text-right padding20"><a data-toggle="modal" class="padding20" href="#settingsModal"><b>Settings</b></a><a href="' . $this->url('logout&token=' . $this->getToken()) . '">Logout</a></div><div class="modal" id="settingsModal"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button></div><div class="modal-body col-xs-12"><ul class="nav nav-tabs text-center" role="tablist"><li role="presentation" class="active"><a href="#currentPage" aria-controls="currentPage" role="tab" data-toggle="tab">Current page</a></li><li role="presentation"><a href="#general" aria-controls="general" role="tab" data-toggle="tab">General</a></li><li role="presentation"><a href="#files" aria-controls="files" role="tab" data-toggle="tab">Files</a></li><li role="presentation"><a href="#themesAndPlugins" aria-controls="themesAndPlugins" role="tab" data-toggle="tab">Themes & plugins</a></li><li role="presentation"><a href="#security" aria-controls="security" role="tab" data-toggle="tab">Security</a></li></ul><div class="tab-content col-md-8 col-md-offset-2"><div role="tabpanel" class="tab-pane active" id="currentPage">';
        if ($this->currentPageExists) {
            $output .= '<p class="subTitle">Page title</p><div class="change"><div data-target="pages" id="title" class="editText">' . ($this->get('pages', $this->currentPage)->title != '' ? $this->get('pages', $this->currentPage)->title : '') . '</div></div><p class="subTitle">Page keywords</p><div class="change"><div data-target="pages" id="keywords" class="editText">' . ($this->get('pages', $this->currentPage)->keywords != '' ? $this->get('pages', $this->currentPage)->keywords : '') . '</div></div><p class="subTitle">Page description</p><div class="change"><div data-target="pages" id="description" class="editText">' . ($this->get('pages', $this->currentPage)->description != '' ? $this->get('pages', $this->currentPage)->description : '') . '</div></div><a href="' . $this->url('?delete=' . $this->currentPage . '&token=' . $this->getToken()) . '" class="btn btn-danger marginTop20" title="Delete page" onclick="return confirm(\'Delete ' . $this->currentPage . '?\')">Delete page (' . $this->currentPage . ')</a>';
        } else {
            $output .= 'This page doesn\'t exist. More settings will be displayed here after this page is created.';
        }
        $output .= '</div><div role="tabpanel" class="tab-pane" id="general">';
        $items = $this->get('config', 'menuItems');
        reset($items);
        $first = key($items);
        end($items);
        $end = key($items);
        $output .= '<p class="subTitle">Menu</p><div><div id="menuSettings" class="container-fluid">';
        foreach ($items as $key => $value) {
            $output .= '<div class="row marginTop5"><div class="col-xs-1 col-sm-1 text-right"><i class="btn menu-toggle glyphicon' . ($value->visibility == "show" ? ' glyphicon-eye-open menu-item-hide' : ' glyphicon-eye-close menu-item-show') . '" data-toggle="tooltip" title="' . ($value->visibility == "show" ? 'Hide page from menu' : 'Show page in menu') . '" data-menu="' . $key . '"></i></div><div class="col-xs-4 col-sm-8"><div data-target="menuItem" data-menu="' . $key . '" data-visibility="' . ($value->visibility) . '" id="menuItems" class="editText">' . $value->name . '</div></div><div class="col-xs-2 col-sm-1 text-left">';
            $output .= ($key === $first) ? '' : '<a class="glyphicon glyphicon-arrow-up toolbar menu-item-up cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move up"></a>';
            $output .= ($key === $end) ? '' : '<a class="glyphicon glyphicon-arrow-down toolbar menu-item-down cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move down"></a>';
            $output .= '</div><div class="col-xs-2 col-sm-1 text-left"><a class="glyphicon glyphicon-link" href="' . $this->url($value->slug) . '" title="Visit page">visit</a></div><div class="col-xs-2 col-sm-1 text-right"><a href="' . $this->url('?delete=' . $value->slug . '&token=' . $this->getToken()) . '" title="Delete page" class="btn btn-xs btn-danger" data-menu="' . $key . '" onclick="return confirm(\'Delete ' . $value->slug . '?\')">&times;</a></div></div>';
        }
        $output .= '<a class="menu-item-add btn btn-info marginTop20" data-toggle="tooltip" title="Add new page">Add page</a></div></div><p class="subTitle">Theme</p><div class="form-group"><div class="change"><select class="form-control" name="themeSelect" onchange="fieldSave(\'theme\',this.value,\'config\');">';
        foreach (glob($this->rootDir . '/themes/*', GLOB_ONLYDIR) as $dir) {
            $output .= '<option value="' . basename($dir) . '"' . (basename($dir) == $this->get('config', 'theme') ? ' selected' : '') . '>' . basename($dir) . ' theme' . '</option>';
        }
        $output .= '</select></div></div><p class="subTitle">Main website title</p><div class="change"><div data-target="config" id="siteTitle" class="editText">' . ($this->get('config', 'siteTitle') != '' ? $this->get('config', 'siteTitle') : '') . '</div></div><p class="subTitle">Page to display on homepage</p><div class="change"><div data-target="config" id="defaultPage" class="editText">' . $this->get('config', 'defaultPage') . '</div></div><p class="subTitle">Footer</p><div class="change"><div data-target="blocks" id="footer" class="editText">' . ($this->get('blocks', 'footer')->content != '' ? $this->get('blocks', 'footer')->content : '') . '</div></div></div><div role="tabpanel" class="tab-pane" id="files"><p class="subTitle">Upload</p><div class="change"><form action="' . $this->url($this->currentPage) . '" method="post" enctype="multipart/form-data"><div class="input-group"><input type="file" name="uploadFile" class="form-control"><span class="input-group-btn"><button type="submit" class="btn btn-info">Upload</button></span><input type="hidden" name="token" value="' . $this->getToken() . '"></div></form></div><p class="subTitle marginTop20">Delete files</p><div class="change">';
        foreach ($fileList as $file) {
            $output .= '<a href="' . $this->url('?deleteFile=' . $file . '&token=' . $this->getToken()) . '" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $file . '?\')" title="Delete file">&times;</a><span class="marginLeft5"><a href="' . $this->url('files/') . $file . '" class="normalFont" target="_blank">' . $this->url('files/') . '<b class="fontSize21">' . $file . '</b></a></span><p></p>';
        }
        $output .= '</div></div><div role="tabpanel" class="tab-pane" id="themesAndPlugins"><p class="subTitle">Install or update</p><div class="change"><form action="' . $this->url($this->currentPage) . '" method="post"><div class="form-group"><label class="radio-inline"><input type="radio" name="installLocation" value="themes">Theme</label><label class="radio-inline"><input type="radio" name="installLocation" value="plugins">Plugin</label><p></p><div class="input-group"><input type="text" name="addonURL" class="form-control normalFont" placeholder="Paste link/URL to ZIP file"><span class="input-group-btn"><button type="submit" class="btn btn-info">Install/Update</button></span></div></div><input type="hidden" value="true" name="installAddon"><input type="hidden" name="token" value="' . $this->getToken() . '"></form></div><p class="subTitle">Delete themes</p><div class="change">';
        foreach ($themeList as $theme) {
            $output .= '<a href="' . $this->url('?deleteTheme=' . $theme . '&token=' . $this->getToken()) . '" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $theme . '?\')" title="Delete theme">&times;</a> ' . $theme . '<p></p>';
        }
        $output .= '</div><p class="subTitle">Delete plugins</p><div class="change">';
        foreach ($pluginList as $plugin) {
            $output .= '<a href="' . $this->url('?deletePlugin=' . $plugin . '&token=' . $this->getToken()) . '" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $plugin . '?\')" title="Delete plugin">&times;</a> ' . $plugin . '<p></p>';
        }
        $output .= '</div></div><div role="tabpanel" class="tab-pane" id="security"><p class="subTitle">Admin login URL</p><div class="change"><div data-target="config" id="login" class="editText">' . $this->get('config', 'login') . '</div><p class="text-right marginTop5">Important: bookmark your login URL after changing<br /><span class="normalFont text-right"><b>' . $this->url($this->get('config', 'login')) . '</b></span></div><p class="subTitle">Password</p><div class="change"><form action="' . $this->url($this->currentPage) . '" method="post"><div class="input-group"><input type="password" name="old_password" class="form-control" placeholder="Old password"><span class="input-group-btn"></span><input type="password" name="new_password" class="form-control" placeholder="New password"><span class="input-group-btn"><button type="submit" class="btn btn-info">Change password</button></span></div><input type="hidden" name="fieldname" value="password"><input type="hidden" name="token" value="' . $this->getToken() . '"></form></div><p class="subTitle">Backup</p><div class="change"><form action="' . $this->url($this->currentPage) . '" method="post">
            <button type="submit" class="btn btn-block btn-info" name="backup">Backup website</button><input type="hidden" name="token" value="' . $this->getToken() . '"></form></div><p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Restore-backup#how-to-restore-a-backup-in-3-steps" target="_blank">How to restore backup</a></p><p class="subTitle">Better security (Apache only)</p><p>HTTPS redirect, 30 day caching, iframes allowed only from same origin, mime type sniffing prevention, stricter refferer and cookie policy.</p><div class="change"><form method="post"><div class="btn-group btn-group-justified"><div class="btn-group"><button type="submit" class="btn btn-success" name="betterSecurity" value="on">ON (warning: may break your website)</button></div><div class="btn-group"><button type="submit" class="btn btn-danger" name="betterSecurity" value="off">OFF (reset htaccess to default)</button></div></div><input type="hidden" name="token" value="' . $this->getToken() . '"></form></div><p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Better-security-mode-(HTTPS-and-other-features)#important-read-before-turning-this-feature-on" target="_blank">Read more before enabling</a></p></div></div></div><div class="modal-footer clear"><p class="small"><a href="https://wondercms.com" target="_blank">WonderCMS</a> ' . self::VERSION . ' &nbsp; <b><a href="https://wondercms.com/whatsnew" target="_blank">News</a> &nbsp; <a href="https://wondercms.com/themes" target="_blank">Themes</a> &nbsp; <a href="https://wondercms.com/plugins" target="_blank">Plugins</a> &nbsp; <a href="https://wondercms.com/community" target="_blank">Community</a> &nbsp; <a href="https://github.com/robiso/wondercms/wiki#wondercms-documentation" target="_blank">Docs</a> &nbsp; <a href="https://wondercms.com/donate" target="_blank">Donate</a></b></p></div></div></div></div></div>';
        return $this->hook('settings', $output)[0];
    }

    public function slugify(string $text): string
    {
        $text = preg_replace('~[^\\pL\d]+~u', '-', $text);
        $text = trim(htmlspecialchars(mb_strtolower($text), ENT_QUOTES), '/');
        $text = trim($text, '-');
        return empty($text) ? "-" : $text;
    }

    private function updateAction(): void
    {
        if (!$this->loggedIn || !isset($_POST['update'])) {
            return;
        }
        if (hash_equals($_POST['token'], $this->getToken())) {
            $contents = $this->getFileFromRepo('index.php');
            if ($contents) {
                file_put_contents(__FILE__, $contents);
            }
            $this->alert('success', 'WonderCMS successfully updated. Wohoo!');
            $this->redirect();
        }
    }

    private function updateDBVersion(): void
    {
        if ($this->get('config', 'dbVersion') < self::VERSION) {
            $this->set('config', 'dbVersion', self::VERSION);
        }
    }

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
        if (isset($_POST['token']) && hash_equals($_POST['token'], $this->getToken()) && isset($_FILES['uploadFile'])) {
            if (!isset($_FILES['uploadFile']['error']) || is_array($_FILES['uploadFile']['error'])) {
                $this->alert('danger', 'Invalid parameters.');
                $this->redirect();
            }
            switch ($_FILES['uploadFile']['error']) {
                case \UPLOAD_ERR_OK:
                    break;
                case \UPLOAD_ERR_NO_FILE:
                    $this->alert('danger', 'No file selected.');
                    $this->redirect();
                    break;
                case \UPLOAD_ERR_INI_SIZE:
                case \UPLOAD_ERR_FORM_SIZE:
                    $this->alert('danger', 'File too large. Change maximum upload size limit or contact your host.');
                    $this->redirect();
                    break;
                default:
                    $this->alert('danger', 'Unknown error.');
                    $this->redirect();
            }
            $mimeType = '';
            if (class_exists('finfo')) {
                $finfo = new \finfo(\FILEINFO_MIME_TYPE);
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
            if (array_search($mimeType, $allowedExtensions, true) === false) {
                $this->alert('danger', 'File format is not allowed.');
                $this->redirect();
            }
            if (!move_uploaded_file($_FILES['uploadFile']['tmp_name'], $this->filesPath . '/' . basename($_FILES['uploadFile']['name']))) {
                throw new \Exception('Failed to move uploaded file!');
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
    public function url(string $location = ''): string
    {
        $Request = Request::createFromGlobals();
        return $Request->getScheme() . '://' . $Request->getHttpHost() . $Request->getBasePath() . '/' . $location;
    }

    /**
     * Create the zip backup of all content
     *
     */
    private function zipBackup(): void
    {
        if (!\extension_loaded('zip')) {
            throw new \Exception('Zip extension is not loaded!');
        }
        $zipName = date('Y-m-d') . '-wcms-backup-' . \bin2hex(\random_bytes(8)) . '.zip';
        $zipPath = $this->rootDir . '/files/' . $zipName;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \Exception('Cannot create the zip archive!');
        }
        $iterator = new \RecursiveDirectoryIterator($this->rootDir);
        $iterator->setFlags(\RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $file) {
            $file = \basename($file);
            if (is_dir($file)) {
                $zip->addEmptyDir($file);
            } elseif (is_file($file)) {
                $zip->addFile($file);
            }
        }
        $zip->close();
        $this->redirect('files/' . $zipName);
    }
}
