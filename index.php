<?php // WonderCMS • wondercms.com • license: MIT
session_start();
define('INC_ROOT', dirname(__FILE__));
define('VERSION', '1.2.0');
mb_internal_encoding('UTF-8');
class wCMS
{
	public static $loggedIn = false;
	public static $currentPage;
	public static $_currentPage;
	public static $newPage = false;
	public static $listeners = [];
	public static function hook()
	{
		$numArgs = func_num_args();
		$args = func_get_args();
		if ($numArgs < 2) trigger_error('Insufficient arguments', E_USER_ERROR);
		$hookName = array_shift($args);
		if ( ! isset(self::$listeners[$hookName])) return $args;
		foreach (self::$listeners[$hookName] as $func) $args = $func($args);
		return $args;
	}
	public static function js()
	{
		$script = <<<'EOT'
<script>function nl2br(a){return(a+"").replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g,"$1<br>$2")}function fieldSave(a,b){$("#save").show(),$.post("",{fieldname:a,content:b},function(a){window.location.reload()})}var changing=!1;$(document).ready(function(){$('[data-toggle="tooltip"]').tooltip(),$("span.editText").click(function(){changing||(a=$(this),title=a.attr("title")?title='"'+a.attr("title")+'" ':"",a.hasClass("editable")?a.html("<textarea "+title+' id="'+a.attr("id")+'_field" onblur="fieldSave(a.attr(\'id\'),this.value);">'+a.html()+"</textarea>"):a.html("<textarea "+title+' id="'+a.attr("id")+'_field" onblur="fieldSave(a.attr(\'id\'),nl2br(this.value));">'+a.html().replace(/<br>/gi,"\n")+"</textarea>"),a.children(":first").focus(),autosize($("textarea")),changing=!0)})});</script>
EOT;
		$js = [$script];
		$js = self::hook('js', $js);
		if (is_array($js[0])) $js = $js[0];
		$output = '';
		foreach ($js as $j) $output .= $j;
		return $output;
	}
	public static function css()
	{
		$style = <<<'EOT'
<style>#adminPanel{color:#fff;background:#1ab}.grayFont{color: #444;}span.editText,.toggle{display:block;cursor:pointer}span.editText textarea{border:0;width:100%;resize:none;color:inherit;font-size:inherit;font-family:inherit;background-color:transparent;overflow:hidden;box-sizing:content-box;}#save{left:0;width:100%;height:100%;display:none;position:fixed;text-align:center;padding-top:100px;background:rgba(51,51,51,.8);z-index:2448}.change{margin:5px 0;padding:20px;border-radius:5px;background:#1f2b33}.marginTop20{margin-top:20px;}.padding20{padding:20px;}</style>
EOT;
		$css = [$style];
		$css = self::hook('css', $css);
		if (is_array($css[0])) $css = $css[0];
		$output = '';
		foreach ($css as $c) $output .= $c;
		return $output;
	}
	public static function addListener($hook, $functionName)
	{
		self::$listeners[$hook][] = $functionName;
	}
	public static function loadPlugins()
	{
		if ( ! is_dir(INC_ROOT.'/plugins')) mkdir(INC_ROOT.'/plugins');
		foreach (glob(INC_ROOT.'/plugins/*', GLOB_ONLYDIR) as $dir) foreach (glob($dir.'/*.php') as $plugin) require_once $plugin;
	}
	public static function init()
	{
		self::loadPlugins();
		self::createDatabase();
		if (isset($_SESSION['l'], $_SESSION['p']) && $_SESSION['p'] == INC_ROOT) self::$loggedIn = true;
		$loginPage = self::getConfig('login');
		$extracted = [];
		$pages = [];
		foreach (self::getPage() as $key => $value) $pages[$key] = $value;
		$_page = preg_replace('!-+!', ' ', (empty(self::parseUrl()) || is_null(self::parseUrl())) ? self::getConfig('defaultPage') : self::parseUrl());
		self::$_currentPage = $_page;
		$page = mb_strtolower($_page);
		if ($page == 'logout') self::logout();
		if ( ! in_array($page, array_keys($pages))) {
			if ($_page == $loginPage) $extracted = self::loginPage();
			elseif (self::$loggedIn) {
				$extracted = (array) self::newPage($page);
				self::$newPage = true;
				self::alert('info', '<b>This page ('.$page.') doesn\'t exist yet.</b> Click inside the content below and make your first edit to create it.');
			} else $extracted = self::notFoundPage();
		} else $extracted = (array) $pages[$page];
		$extracted = self::hook('extracted', $extracted);
		if (@is_array($extracted[0])) $extracted = $extracted[0];
		@extract($extracted);
		$blackList = ['password', 'login'];
		foreach (self::getConfig() as $key => $value) if ( ! in_array($key, $blackList)) $$key = $value;
		foreach (self::getConfig() as $key => $value) property_exists(__CLASS__, $key) ? self::$$key = $value : null;
		if (self::$loggedIn) {
			if (empty($content)) $content = 'Empty content.';
			if (empty($subside)) $subside = 'Empty content.';
			$content = self::editable('content', $content);
			$subside = self::editable('subside', $subside);
		}
		list($content, $subside) = self::hook('editable', $content, $subside);
		self::$currentPage = $page;
		self::delete();
		self::save();
		self::login();
		self::upgrade();
		self::notify();
		self::hook('before', []);
		if (file_exists(INC_ROOT.'/themes/'.self::getConfig('theme').'/functions.php')) require INC_ROOT.'/themes/'.self::getConfig('theme').'/functions.php';
		require INC_ROOT.'/themes/'.self::getConfig('theme').'/theme.php';
		self::hook('after', []);
	}
	public static function editable($id, $html)
	{
		return '<span id="'.$id.'" class="editText editable">'.$html.'</span>';
	}
	public static function createPage($name)
	{
		$details = self::newPage($name);
		$db = json_decode(self::db());
		$db->pages->$name = new stdClass();
		$db->pages->$name->title = $details['title'];
		$db->pages->$name->description = $details['description'];
		$db->pages->$name->keywords = $details['keywords'];
		$db->pages->$name->content = $details['content'];
		self::pushContents($db);
	}
	public static function alert($class, $message, $sticky = false)
	{
		if (isset($_SESSION['alert'][$class])) foreach ($_SESSION['alert'][$class] as $k => $v) if ($v['message'] == $message) return;
		$_SESSION['alert'][$class][] = ['class' => $class, 'message' => $message, 'sticky' => $sticky];
	}
	public static function displayMessages()
	{
		if ( ! isset($_SESSION['alert'])) return;
		$s = $_SESSION['alert'];
		$output = '';
		unset($_SESSION['alert']);
		foreach ($s as $key => $value)
			foreach ($value as $key => $val) $output .= '<div style="margin-bottom:0" class="alert alert-'.$val['class'].( ! $val['sticky'] ? ' alert-dismissible' : '').'">'.( ! $val['sticky'] ? '<button type="button" class="close" data-dismiss="alert">&times;</button>' : '').$val['message'].'</div>';
		return $output;
	}
	public static function notify()
	{
		if ( ! self::$loggedIn) return;
		if (self::getConfig('login') == 'loginURL') self::alert('warning', '<b>Warning:</b> change your default login URL.', true);
		if (password_verify('admin', self::getConfig('password'))) self::alert('danger', '<b>Protect your website:</b> change your default password.', true);
		if ( ! self::isConnected()) return;
		$version = trim(file_get_contents('https://raw.githubusercontent.com/robiso/wondercms/master/version'));
		if ($version == VERSION) return;
		self::alert('info', '<b>Your WonderCMS version is out of date:</b> backup your files before updating. <form style="display:inline" action="" method="post"><button class="btn btn-info" name="upgrade">Update WonderCMS</button></form>', true);
	}
	public static function upgrade()
	{
		if (is_null(self::p('upgrade')) || ! self::isConnected()) return;
		$content = file_get_contents('https://raw.githubusercontent.com/robiso/wondercms/master/index.php');
		file_put_contents(__FILE__, $content);
		self::alert('success', 'WonderCMS successfully updated. Wohoo!');
		self::redirect(self::$currentPage);
	}
	public static function isConnected()
	{
		$connected = @fsockopen('www.google.com', 80);
		if ($connected) {
			fclose($connected);
			return true;
		}
		return false;
	}
	public static function delete()
	{
		if (is_null(self::g('delete')) || ! self::getPage(self::g('delete'))) return;
		$db = json_decode(self::db());
		$page = self::g('delete');
		unset($db->pages->$page);
		$menuItems = self::getConfig('menuItems');
		$_menuItems = array_map('mb_strtolower', $menuItems);
		if (in_array($page, $_menuItems)) {
			$index = array_search($page, $_menuItems);
			unset($menuItems[$index]);
		}
		$db->config->menuItems = array_values($menuItems);
		self::pushContents($db);
		self::redirect();
	}
	public static function save()
	{
		if (is_null(self::p('fieldname')) || is_null(self::p('content')) || ! self::$loggedIn) return;
		$fieldname = self::p('fieldname');
		$content = trim(self::p('content'));
		if ($fieldname == 'menuItems') {
			$content = array_filter(array_map('trim', explode('<br>', $content)));
		}
		if ($fieldname == 'defaultPage') {
			if ( ! self::getPage($content) || empty($content)) return;
		}
		if ($fieldname == 'login') {
			if (empty($content)) return;
			if (self::getPage($content) !== false) return;
		}
		if ($fieldname == 'theme')
			if ( ! is_dir(INC_ROOT.'/themes/'.$content)) return;
		if ($fieldname == 'password') {
			$oldPassword = self::p('old_password');
			if ( ! password_verify($oldPassword, self::getConfig('password'))) {
				self::alert('danger', '<b>Password changing failed:</b> wrong password.');
				self::redirect(self::$currentPage);
			}
			if (strlen($content) < 4) {
				self::alert('danger', '<b>Password changing failed:</b> password must be longer than 4 characters.');
				self::redirect(self::$currentPage);	
			}
			$content = password_hash($content, PASSWORD_DEFAULT);
		}
		if (self::getConfig($fieldname) !== false) self::setConfig($fieldname, $content);
		else if (self::getPage(self::$currentPage) !== false) self::setPage($fieldname, $content);
		else {
			self::createPage(self::$currentPage);
			self::setPage($fieldname, $content);
		}
		if ($fieldname == 'password') {
			self::alert('success', 'Password changed.');
			self::redirect(self::$currentPage);
		}
	}
	public static function login()
	{
		if (self::$_currentPage == self::getConfig('login') && self::$loggedIn) self::redirect();
		if (is_null(self::p('password')) || self::$_currentPage != self::getConfig('login')) return;
		if (password_verify(self::p('password'), self::getConfig('password'))) {
			$_SESSION['l'] = true;
			$_SESSION['p'] = INC_ROOT;
			self::redirect();
		} else {
			self::alert('danger', 'Wrong password.');
			self::redirect(self::getConfig('login'));
		}
	}
	public static function logout()
	{
		unset($_SESSION['l']);
		self::redirect();
	}
	public static function getConfig($key = false)
	{
		if ( ! $key) return json_decode(self::db())->config;
		return isset(json_decode(self::db())->config->$key) ? json_decode(self::db())->config->$key : false;
	}
	public static function setConfig($key, $value)
	{
		$db = json_decode(self::db());
		$db->config->$key = $value;
		self::pushContents($db);
	}
	public static function getPage($page = false)
	{
		if ( ! $page) return json_decode(self::db())->pages;
		return isset(json_decode(self::db())->pages->$page) ? json_decode(self::db())->pages->$page : false;
	}
	public static function setPage($key, $value, $page = false)
	{
		if ( ! $page) $page = self::$currentPage;
		$db = json_decode(self::db());
		$db->pages->$page->$key = $value;
		self::pushContents($db);
	}
	public static function pushContents($db)
	{
		file_put_contents(INC_ROOT.'/database.js', json_encode($db));
	}
	public static function redirect($location = null) {
		header('Location: '.self::url($location));
		die();
	}
	public static function asset($location) {
		return self::url('themes/'.self::getConfig('theme').'/'.$location);
	}
	public static function url($location = null) {
		return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].str_replace($_SERVER['DOCUMENT_ROOT'], '', str_replace('\\', '/', INC_ROOT))."/{$location}";
	}
	public static function db()
	{
		return file_exists(INC_ROOT.'/database.js') ? file_get_contents(INC_ROOT.'/database.js') : false;
	}
	public static function getSlug($string)
	{
		return mb_strtolower(preg_replace('!\s+!', '-', $string));
	}
	public static function g($key)
	{
		return isset($_GET[$key]) ? $_GET[$key] : null;
	}
	public static function p($key)
	{
		return isset($_POST[$key]) ? $_POST[$key] : null;
	}
	public static function escape($string)
	{
		return htmlentities($string, ENT_QUOTES, 'UTF-8');
	}
	public static function parseUrl()
	{
		if ( ! is_null(self::g('page'))) return array_slice(explode('/', $_GET['page']), -1)[0];
	}
	public static function navigation($activeCode = ' class="active"')
	{
		$output = '';
		foreach (self::getConfig('menuItems') as $item) {
			$output .= '<li'.((mb_strtolower($item) == self::$currentPage) ? $activeCode : '').'><a href="'.self::url(self::getSlug($item)).'">'.$item.'</a></li>';
		}
		$output = self::hook('navigation', $output, $activeCode);
		if (@is_array($output)) $output = $output[0];
		return $output;
	}
	public static function footer()
	{
		$output = self::getConfig('copyright').' &bull; '.'Powered by <a href="https://wondercms.com">WonderCMS</a>'.( ! self::$loggedIn ? ((self::getConfig('login') == 'loginURL') ? ' &bull; <a href="'.self::url('loginURL').'">Login</a>' : '') : ' &bull; <a href="'.self::url('logout').'">Logout</a>');
		$output = self::hook('footer', $output);
		if (@is_array($output)) $output = $output[0];
		return $output;
	}
	public static function settings()
	{
		if ( ! self::$loggedIn) return;
		$output ='<div id="save"><h2>Saving...</h2></div><div id="adminPanel" class="container-fluid"><div class="padding20 toggle text-center" data-toggle="collapse" data-target="#settings">&#9776;</div><div class="col-xs-12 col-sm-8 col-sm-offset-2"><div id="settings" class="collapse text-left"><a href="'.self::url('?delete='.self::$currentPage).'" class="btn btn-danger'.(self::$newPage ? ' hide' : '').'" onclick="return confirm(\'Really delete page?\')">Delete current page ('.self::$currentPage.')</a><div class="marginTop20"></div><div class="form-group"><select class="form-control" name="themeSelect" onchange="fieldSave(\'theme\',this.value);">';
		foreach (glob(INC_ROOT.'/themes/*', GLOB_ONLYDIR) as $dir) $output .= '<option value="'.basename($dir).'"'.(basename($dir) == self::getConfig('theme') ? ' selected' : '').'>'.basename($dir).' theme'.'</option>';
		$output .= '</select></div>';
		$output .= '<div class="change"><span id="siteTitle" class="editText">'.(self::getConfig('siteTitle') != '' ? self::getConfig('siteTitle') : '').'</span></div><div class="change"><span id="copyright" class="editText">'.(self::getConfig('copyright') != '' ? self::getConfig('copyright') : '').'</span></div><div class="marginTop20"></div>';
		if ( ! self::$newPage)
		foreach (['title', 'description', 'keywords'] as $key) $output .= '<div class="change">'.(($key == 'title') ? '<h3 class="glyphicon glyphicon-info-sign" aria-hidden="true" data-toggle="tooltip" data-placement="right" title="Page title, unique for each page."></h3>' : '').'<span id="'.$key.'" class="editText">'.(@self::getPage(self::$currentPage)->$key != '' ? @self::getPage(self::$currentPage)->$key : 'Page '.$key.', unique for each page').'</span></div>';
		$output .= '<div class="marginTop20"></div><div class="change"><h3 class="glyphicon glyphicon-info-sign" aria-hidden="true" data-toggle="tooltip" data-placement="right" title="Menu: enter a new page name in a new line."></h3><span id="menuItems" class="editText">';
		if (empty(self::getConfig('menuItems'))) $output .= mb_convert_case(self::getConfig('defaultPage'), MB_CASE_TITLE);
		foreach (self::getConfig('menuItems') as $key) $output .= $key.'<br>';
		$output = preg_replace('/(<br>)+$/', '', $output);
		$output .= '</span></div><div class="change"><h3 class="glyphicon glyphicon-info-sign" aria-hidden="true" data-toggle="tooltip" data-placement="right" title="Default homepage: to make another page your default homepage, rename this to another existing page."></h3><span id="defaultPage" class="editText">'.self::getConfig('defaultPage').'</span></div><div class="change"><h3 class="glyphicon glyphicon-info-sign" aria-hidden="true" data-toggle="tooltip" data-placement="right" title="Login URL: change it and bookmark it (eg: your-domain.com/yourLoginURL)."></h3><span id="login" class="editText">'.self::getConfig('login').'</span></div><div class="change"><form action="'.self::url(self::$currentPage).'" method="post"><div class="form-group"><input type="password" name="old_password" class="form-control" placeholder="Old password"></div><div class="form-group"><input type="password" name="content" class="form-control" placeholder="New password"></div><input type="hidden" name="fieldname" value="password"><button type="submit" class="btn btn-info">Change password</button></form></div><div class="padding20 toggle text-center" data-toggle="collapse" data-target="#settings">Close settings</div></div></div></div></div>';
		$output = self::hook('settings', $output);
		if (@is_array($output)) $output = $output[0];
		return $output;
	}
	public static function notFoundPage()
	{
		$output = ['title' => 'Page not found', 'keywords' => '', 'description' => '', 'content' => 'Sorry, page not found. :('];
		$output = self::hook('not_found', $output);
		if (@is_array($output[0])) $output = $output[0];
		return $output;
	}
	public static function newPage($page)
	{
		$output = ['title' => mb_convert_case($page, MB_CASE_TITLE), 'description' => 'Page description, unique for each page', 'keywords' => 'Page, keywords, unique, for, each, page', 'content' => '<p>Click here to create some content.</p><br>Once you do that, this page will be eventually visited by search engines.'];
		$output = self::hook('new', $output);
		if (@is_array($output[0])) $output = $output[0];
		return $output;
	}
	public static function loginPage()
	{
		$output = ['title' => 'Login', 'keywords' => '', 'description' => '', 'content' => '<form action="'.self::url(self::getConfig('login')).'" method="post"><div class="col-xs-5"><div class="form-group"><input type="password" class="form-control" id="password" name="password"></div></div><button type="submit" class="btn btn-info">Login</button></form>'];
		$output = self::hook('login', $output);
		if (@is_array($output[0])) $output = $output[0];
		return $output;
	}
	public static function createDatabase()
	{
		if ( ! self::db()) {
			$db = new stdClass();
			$db->pages = new stdClass();
			$db->config = new stdClass();
			$db->pages->home = new stdClass();
			$db->pages->example = new stdClass();
			$db->pages->home->title = 'Home';
			$db->pages->home->keywords = 'Page, keywords, unqiue, for, each, page';
			$db->pages->home->description = 'Page description, unique for each page';
			$db->pages->home->content = '<h4>It\'s alive! Your website is now powered by WonderCMS.</h4><p><a href="'.self::url('loginURL').'">Click here to login: the password is <b>admin</b>.</a></p><p>Simply click on content to edit, click outside to save it.</p>';
			$db->pages->example->title = 'Example';
			$db->pages->example->keywords = 'Page, keywords, unique, for, each, page';
			$db->pages->example->description = 'Page description, unique for each page';
			$db->pages->example->content = '<p>Example page.</p> <p>To add a new page, click on the existing pages in settings panel and enter a new one.</p>';
			$db->config->siteTitle = 'Website title';
			$db->config->defaultPage = 'home';
			$db->config->theme = 'default';
			$db->config->menuItems = ['Home', 'Example'];
			$db->config->subside = '<h4>ABOUT YOUR WEBSITE</h4><p>Your photo, website description, contact information, mini map or anything else.</p><p>This content is static and visible on all pages.</p>';
			$db->config->copyright = '&copy; '.date('Y').' Your website';
			$db->config->password = password_hash('admin', PASSWORD_DEFAULT);
			$db->config->login = 'loginURL';
			self::pushContents($db);
		}
	}
}
wCMS::init();
