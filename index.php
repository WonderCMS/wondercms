<?php
session_start();
define('ROOT', dirname(__FILE__));
define('DS', DIRECTORY_SEPARATOR);
class WonderCMS
{
	public static $loggedIn = false;
	public static $title;
	public static $content;
	public static $subside;
	public static $webSiteTitle;
	public static $description;
	public static $keywords;
	public static $copyright;
	public static function init() {
		if (isset($_SESSION['l']) && $_SESSION['l']) self::$loggedIn = true;
		if (isset($_POST['login'])) self::login();
		if (isset($_POST['label'], $_POST['content']) && self::$loggedIn) self::save();
		foreach (self::get() as $item => $value) property_exists(__class__, $item) ? self::$$item = $value : null;
		$newObject = false;
		$objects = json_decode(file_get_contents(ROOT.DS.'db.js'));
		$object = (empty(self::parseUrl()) || is_null(self::parseUrl())) ? self::get('defaultObject') : self::parseUrl();
		if ($object == 'login' && self::$loggedIn) self::redirect();
		if ($object == 'logout' && self::$loggedIn) self::logout();
		if (!isset($objects->{$object})) if (self::$loggedIn) $newObject = true; else $object = self::get('error404');
		self::$title = ucfirst($object);
		self::$content = $newObject ? self::editable("<b>{$object}</b> doesn't exist. But you can click here to create it and start editing!", $object) : self::escapeScriptTags(self::editable($objects->{$object}->content, $object));
		self::$subside = self::editable(self::$subside, 'subside');
		require ROOT.DS.'themes'.DS.self::get('theme').DS.'layout.php';
	}
	public static function save() {
		$label = $_POST['label'];
		$content = trim($_POST['content']);
		if (in_array($label, ['theme', 'subside', 'menuItems', 'webSiteTitle', 'description', 'keywords', 'copyright'])) self::set($label, $content);
		elseif ($label == 'password') self::set($label, password_hash($content, PASSWORD_DEFAULT));
		else {
			$db = json_decode(file_get_contents(ROOT.DS.'db.js'));
			$db->{$label}->content = $content;
			file_put_contents(ROOT.DS.'db.js', json_encode($db));
		}
		exit();
	}
	public static function editable($toEdit, $label) {
		if (self::$loggedIn) return '<textarea id="'.$label.'">'.$toEdit.'</textarea>';
		return $toEdit;
	}
	public static function login() {
		if (password_verify(@$_POST['password'], self::get('password'))) {
			$_SESSION['l'] = true;
			self::redirect();
		}
		else echo "<script>alert('Wrong password!');</script>";
	}
	public static function logout() {
		unset($_SESSION['l']);
		self::redirect();
	}
	public static function redirect($location = null) {
		header('Location: '.self::url($location));
		exit();
	}
	public static function get($item = false) {
		$config = json_decode(file_get_contents(ROOT.DS.'config.js'));
		if (!$item) return $config;
		return isset($config->{$item}) ? $config->{$item} : false;
	}
	public static function set($item, $value) {
		$config = json_decode(file_get_contents(ROOT.DS.'config.js'));
		$config->{$item} = $value;
		file_put_contents(ROOT.DS.'config.js', json_encode($config));
	}
	public static function menuItems() {
		$items = [];
		$getItems = explode("\n", self::get('menuItems'));
		foreach ($getItems as $item)
			$items[] = [
				'title' => $item,
				'url' => self::url(strtolower($item)),
				'isCurrent' => (strtolower(self::$title) == $item)
			];
		return json_decode(json_encode($items));
	}
	public static function url($location = null) {
		return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].str_replace($_SERVER['DOCUMENT_ROOT'], '', str_replace('\\', '/', ROOT))."/{$location}";
	}
	public static function themeUrl($location) {
		return self::url('themes/'.self::get('theme').'/'.$location);
	}
	public static function escapeScriptTags($html) {
		$dom = new DOMDocument();
		@$dom->loadHTML($html);
		$script = $dom->getElementsByTagName('script');
		foreach ($script as $item) $item->parentNode->removeChild($item);
		return $dom->saveHTML();
	}
	public static function escape($string) {
		return htmlentities($string, ENT_QUOTES, 'UTF-8');
	}
	public static function parseUrl() {
		if (isset($_GET['object']))
			return array_slice(explode('/', filter_var($_GET['object'], FILTER_SANITIZE_URL)), -1)[0];
	}
}
WonderCMS::init();
