<?php // WonderCMS - wondercms.com - license: MIT

session_start();
define('version', '2.0.0');
mb_internal_encoding('UTF-8');

class wCMS {
	public static $loggedIn = false;
	public static $currentPage;
	public static $currentPageExists = false;
	public static $_listeners = [];
	public function updateOtherFiles() {
		$db = wCMS::db();
		if ( ! isset($db->{'update'})) {
			unlink('database.js');
			wCMS::_install();
			$db = wCMS::db();
			$db->{'update'} = new stdClass;
			$db->{'update'}->{'yes'} = true;
			wCMS::save($db);
		}
		if (wCMS::get('update', 'yes') === true) {
			if (file_exists(__DIR__ . '/themes/default/theme.php')) file_put_contents(__DIR__ . '/themes/default/theme.php', file_get_contents('https://raw.githubusercontent.com/robiso/WonderCMS-TEST/master/themes/default/theme.php'));
			if (file_exists(__DIR__ . '/themes/default/css/style.css')) file_put_contents(__DIR__ . '/themes/default/css/style.css', file_get_contents('https://raw.githubusercontent.com/robiso/WonderCMS-TEST/master/themes/default/css/style.css'));
			if (file_exists('.htaccess')) file_put_contents('.htaccess', file_get_contents('https://raw.githubusercontent.com/robiso/WonderCMS-TEST/master/.htaccess'));
		}
	}
	public static function init() {
		wCMS::_loadPlugins();
		wCMS::_install();
		wCMS::updateOtherFiles();
		if (isset($_SESSION['l'], $_SESSION['i']) && $_SESSION['i'] == __DIR__) wCMS::$loggedIn = true;
		wCMS::$currentPage = empty(wCMS::parseUrl()) ? wCMS::get('config','defaultPage') : wCMS::parseUrl();
		if (isset(wCMS::get('pages')->{wCMS::$currentPage})) wCMS::$currentPageExists = true;
		wCMS::_logoutAction(); wCMS::_loginAction(); wCMS::_saveAction(); wCMS::_changePasswordAction(); wCMS::_deleteAction(); wCMS::_upgradeAction(); wCMS::_notify();
		if (wCMS::$loggedIn && ! wCMS::$currentPageExists) header("HTTP/1.0 404 Not Found");
		if (file_exists(__DIR__ . '/themes/' . wCMS::get('config','theme') . '/functions.php')) require_once __DIR__ . '/themes/' . wCMS::get('config','theme') . '/functions.php';
		require_once __DIR__ . '/themes/' . wCMS::get('config','theme') . '/theme.php';
	}
	public static function editable($id, $html, $dataTarget = '') {
		return '<span' . ($dataTarget != '' ? ' data-target="' . $dataTarget . '"' : '') . ' id="' . $id . '" class="editText editable">' . $html . '</span>';
	}
	public static function page($key) {
		$segments = wCMS::$currentPageExists ? wCMS::get('pages',wCMS::$currentPage) : (wCMS::get('config','login') == wCMS::$currentPage ? (object) wCMS::_loginView() : (object) wCMS::_notFoundView());
		$keys = ['title' => $segments->title, 'description' => $segments->description, 'keywords' => $segments->keywords, 'html' => (wCMS::$loggedIn ? wCMS::editable('html', $segments->html, 'pages') : $segments->html)];
		return isset($keys[$key]) ? $keys[$key] : '';
	}
	public static function block($key) {
		$blocks = wCMS::get('blocks');
		return isset($blocks->{$key}) ? (wCMS::$loggedIn ? wCMS::editable($key, $blocks->{$key}->html, 'blocks') : $blocks->{$key}->html) : '';
	}
	public static function menu() {
		$output = '';
		foreach (wCMS::get('config','menuItems') as $key) {
			$output .= '<li' . (wCMS::$currentPage === $key ? ' class="active"' : '') . '><a href="' . wCMS::url($key) . '">' . $key . '</a></li>';
		}
		return wCMS::_hook('menu', $output)[0];
	}
	public static function footer() {
		$output = wCMS::get('blocks','footer')->html . ( ! wCMS::$loggedIn ? ((wCMS::get('config','login') == 'loginURL') ? ' &bull; <a href="' . wCMS::url('loginURL') . '">Login</a>' : '') : ' &bull; <a href="' . wCMS::url('logout') . '">Logout</a>');
		return wCMS::_hook('footer', $output)[0];
	}
	public static function alerts() {
		if ( ! isset($_SESSION['alert'])) return;
		$session = $_SESSION['alert'];
		$output = '';
		unset($_SESSION['alert']);
		foreach ($session as $key => $value) foreach ($value as $key => $val) $output .= '<div style="margin-bottom:0" class="alert alert-'.$val['class'].( ! $val['sticky'] ? ' alert-dismissible' : '').'">'.( ! $val['sticky'] ? '<button type="button" class="close" data-dismiss="alert">&times;</button>' : '').$val['message'].'</div>';
		return $output;
	}
	public static function alert($class, $message, $sticky = false) {
		if (isset($_SESSION['alert'][$class])) foreach ($_SESSION['alert'][$class] as $k => $v) if ($v['message'] == $message) return;
		$_SESSION['alert'][$class][] = ['class' => $class, 'message' => $message, 'sticky' => $sticky];
	}
	public static function redirect($location = '') {
		header('Location: '.wCMS::url($location)); die();
	}
	public static function asset($location) {
		return wCMS::url('themes/' . wCMS::get('config','theme') . '/' . $location);
	}
	public static function url($location = '') {
		return 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . str_replace($_SERVER['DOCUMENT_ROOT'], '', str_replace('\\', '/', __DIR__)) . '/' . $location;
	}
	public static function parseUrl() {
		return isset($_GET['page']) ? trim(htmlentities($_GET['page'], ENT_QUOTES, 'UTF-8'), '/') : '';
	}
	public static function get() {
		$numArgs = func_num_args();
		$args = func_get_args();
		$db = wCMS::db();
		switch ($numArgs) {
			case 1: return $db->{$args[0]}; break;
			case 2: return $db->{$args[0]}->{$args[1]}; break;
			case 3: return $db->{$args[0]}->{$args[1]}->{$args[2]}; break;
			default: return false; break;
		}
	}
	public static function set() {
		$numArgs = func_num_args();
		$args = func_get_args();
		$db = wCMS::db();
		switch ($numArgs) {
			case 2: $db->{$args[0]} = $args[1]; break;
			case 3: $db->{$args[0]}->{$args[1]} = $args[2]; break;
			case 4: $db->{$args[0]}->{$args[1]}->{$args[2]} = $args[3]; break;
		}
		wCMS::save($db);
	}
	public static function db() {
		return file_exists(__DIR__ . '/database.js') ? json_decode(file_get_contents(__DIR__ . '/database.js')) : false;
	}
	public static function save($db) {
		file_put_contents(__DIR__ . '/database.js', json_encode($db, JSON_PRETTY_PRINT));
	}
	public static function addListener($hook, $functionName) {
		wCMS::$_listeners[$hook][] = $functionName;
	}
	public static function settings() {
		if ( ! wCMS::$loggedIn) return;
		$output ='<div id="save"><h2>Saving...</h2></div><div id="adminPanel" class="container-fluid"><div class="padding20 toggle text-center" data-toggle="collapse" data-target="#settings">Settings</div><div class="col-xs-12 col-sm-8 col-sm-offset-2"><div id="settings" class="collapse">';
		if (wCMS::$currentPageExists) $output .= '<p class="fontSize24">Current page (' . wCMS::$currentPage . ') settings</p><div class="change"><p class="subTitle">Page title</p><div class="change"><span data-target="pages" id="title" class="editText">' . (wCMS::get('pages',wCMS::$currentPage)->title != '' ? wCMS::get('pages',wCMS::$currentPage)->title : '') . '</span></div><p class="subTitle">Page keywords</p><div class="change"><span data-target="pages" id="keywords" class="editText">' . (wCMS::get('pages',wCMS::$currentPage)->keywords != '' ? wCMS::get('pages',wCMS::$currentPage)->keywords : '') . '</span></div><p class="subTitle">Page description</p><div class="change"><span data-target="pages" id="description" class="editText">' . (wCMS::get('pages',wCMS::$currentPage)->description != '' ? wCMS::get('pages',wCMS::$currentPage)->description : '') . '</span></div><a href="' . wCMS::url('?delete=' . wCMS::$currentPage) . '" class="btn btn-danger marginTop20" onclick="return confirm(\'Really delete page?\')">Delete current page</a></div>';
		$output .= '<p class="text-right marginTop20"><small><a href="https://www.wondercms.com/forum">Community</a> &bull; <a href="https://github.com/robiso/wondercms-themes">Themes</a> &bull; <a href="https://github.com/robiso/wondercms-plugins">Plugins</a> &bull; <a href="https://github.com/robiso/wondercms/wiki">Documentation</a> &bull; <a href="https://www.wondercms.com/donate">Donate</a></small></p><p class="fontSize24">General settings</p><div class="change"><div class="form-group"><select class="form-control" name="themeSelect" onchange="fieldSave(\'theme\',this.value,\'config\');">';
		foreach (glob(__DIR__ . '/themes/*', GLOB_ONLYDIR) as $dir) $output .= '<option value="' . basename($dir) . '"' . (basename($dir) == wCMS::get('config','theme') ? ' selected' : '') . '>' . basename($dir) . ' theme'.'</option>';
		$output .= '</select></div><p class="subTitle">Main website title</p><div class="change"><span data-target="config" id="siteTitle" class="editText">' . (wCMS::get('config','siteTitle') != '' ? wCMS::get('config','siteTitle') : '') . '</span></div>';
		$output .= '<p class="subTitle">Menu <small>(enter a new page in a new line)</small></p><div class="change"><span data-target="config" id="menuItems" class="editText">';
		foreach (wCMS::get('config','menuItems') as $key) $output .= $key.'<br>'; $output = preg_replace('/(<br>)+$/', '', $output);
		$output .= '</span></div><p class="subTitle">Footer</p><div class="change"><span data-target="blocks" id="footer" class="editText">' . (wCMS::get('blocks','footer')->html != '' ? wCMS::get('blocks','footer')->html : '') . '</span></div>';
		$output .= '<p class="subTitle">Default homepage <small>(what page to show on homepage)</small></p><div class="change"><span data-target="config" id="defaultPage" class="editText">' . wCMS::get('config','defaultPage') . '</span></div><p class="subTitle">Login URL <small>(save your URL: ' . wCMS::url('loginURL') . ')</small></p><div class="change"><span data-target="config" id="login" class="editText">' . wCMS::get('config','login') . '</span></div><p class="subTitle">Password</p><div class="change"><form action="' . wCMS::url(wCMS::$currentPage) . '" method="post"><div class="form-group"><input type="password" name="old_password" class="form-control" placeholder="Old password"></div><div class="form-group"><input type="password" name="new_password" class="form-control" placeholder="New password"></div><input type="hidden" name="fieldname" value="password"><button type="submit" class="btn btn-info">Change password</button></form></div></div><div class="padding20 toggle text-center" data-toggle="collapse" data-target="#settings">Close settings</div></div></div></div>';
		return wCMS::_hook('settings', $output)[0];
	}
	public static function css() {
		$styles = <<<'EOT'
<style>#adminPanel{background:#e5e5e5;color:#aaa;font-family:"Lucida Sans Unicode",Verdana;}#adminPanel span.editText{color:#555;}span.editText{border:2px dashed #ccc;}span.editText,.toggle{display:block;cursor:pointer}span.editText textarea{outline: 0;border:none;width:100%;resize:none;color:inherit;font-size:inherit;font-family:inherit;background-color:transparent;overflow:hidden;box-sizing:content-box;}span.editText:empty{min-height:20px;}#save{color: #ccc;left:0;width:100%;height:100%;display:none;position:fixed;text-align:center;padding-top:100px;background:rgba(51,51,51,.8);z-index:2448}.change{padding-left:15px}.marginTop20{margin-top:20px;}.padding20{padding:20px;}.subTitle{font-size:18px;margin:10px 0 5px;}.fontSize24{font-size:24px;}</style>
EOT;
		return wCMS::_hook('style', $styles)[0];
	}
	public static function js() {
		$scripts = <<<'EOT'
<script>function nl2br(a){return(a+"").replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g,"$1<br>$2")}function fieldSave(a,b,c){$("#save").show(),$.post("",{fieldname:a,content:b,target:c},function(a){}).always(function(){window.location.reload()})}var changing=!1;$(document).ready(function(){$("span.editText").click(function(){changing||(a=$(this),title=a.attr("title")?title='"'+a.attr("title")+'" ':"",a.hasClass("editable")?a.html("<textarea "+title+' id="'+a.attr("id")+'_field" onblur="fieldSave(a.attr(\'id\'),this.value,a.data(\'target\'));">'+a.html()+"</textarea>"):a.html("<textarea "+title+' id="'+a.attr("id")+'_field" onblur="fieldSave(a.attr(\'id\'),nl2br(this.value),a.data(\'target\'));">'+a.html().replace(/<br>/gi,"\n")+"</textarea>"),a.children(":first").focus(),autosize($("textarea")),changing=!0)})});</script>
EOT;
		return wCMS::_hook('JavaScript', $scripts)[0];
	}
	public static function _loginAction() {
		if (wCMS::$currentPage !== wCMS::get('config','login')) return;
		if (wCMS::$loggedIn) wCMS::redirect();
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
		$password = isset($_POST['password']) ? $_POST['password'] : '';
		if (password_verify($password, wCMS::get('config','password'))) { $_SESSION['l'] = true; $_SESSION['i'] = __DIR__; wCMS::redirect(); }
		wCMS::alert('danger', 'Wrong password.');
		wCMS::redirect(wCMS::get('config','login'));
	}
	public static function _logoutAction() {
		if (wCMS::$currentPage === 'logout') { unset($_SESSION['l'], $_SESSION['i'], $_SESSION['u']);wCMS::redirect(); }
	}
	public static function _saveAction() {
		if ( ! wCMS::$loggedIn || ! isset($_POST['fieldname']) || ! isset($_POST['content']) || ! isset($_POST['target'])) return;
		list($fieldname, $content, $target) = wCMS::_hook('save', $_POST['fieldname'], trim($_POST['content']), $_POST['target']);
		if ($fieldname === 'menuItems') $content = array_filter(array_map('trim', explode('<br>', $content)));
		if ($fieldname === 'defaultPage') if ( ! isset(wCMS::get('pages')->$content)) return;
		if ($fieldname === 'login') if (empty($content) || isset(wCMS::get('pages')->$content)) return;
		if ($fieldname === 'theme') if ( ! is_dir(__DIR__ . '/themes/' . $content)) return;
		if ($target === 'config') wCMS::set('config',$fieldname,$content); elseif ($target === 'blocks') wCMS::set('blocks',$fieldname,'html',$content); elseif ($target === 'pages') { if ( ! isset(wCMS::get('pages')->{wCMS::$currentPage})) wCMS::_create(); wCMS::set('pages',wCMS::$currentPage,$fieldname,$content); }
	}
	public static function _changePasswordAction() {
		if ( ! wCMS::$loggedIn || ! isset($_POST['old_password']) || ! isset($_POST['new_password'])) return;
		if ( ! password_verify($_POST['old_password'], wCMS::get('config','password'))) { wCMS::alert('danger', 'Wrong password.'); wCMS::redirect(wCMS::$currentPage); }
		if (strlen($_POST['new_password']) < 4) { wCMS::alert('danger', 'Password must be longer than 4 characters.'); wCMS::redirect(wCMS::$currentPage); }
		wCMS::set('config','password',password_hash($_POST['new_password'], PASSWORD_DEFAULT));
		wCMS::alert('success', 'Password changed.'); wCMS::redirect(wCMS::$currentPage);
	}
	public static function _deleteAction() {
		if ( ! wCMS::$loggedIn || ! isset($_GET['delete'])) return;
		if ( ! isset(wCMS::get('pages')->{$_GET['delete']})) return;
		$db = wCMS::db(); unset($db->pages->{$_GET['delete']});
		if (($key = array_search($_GET['delete'], $db->config->menuItems)) !== false) unset($db->config->menuItems[$key]); 
		wCMS::save($db); wCMS::redirect();
	}
	public static function _upgradeAction() {
		if ( ! wCMS::$loggedIn || ! isset($_POST['upgrade'])) return;
		$contents = file_get_contents('https://raw.githubusercontent.com/robiso/WonderCMS-TEST/master/index.php');
		if ($contents) {
			file_put_contents(__FILE__, $contents);
			wCMS::alert('success', 'WonderCMS successfully updated. Wohoo!'); wCMS::redirect(wCMS::$currentPage);
		}
	}
	public static function _notFoundView() {
		if (wCMS::$loggedIn) return ['title' => wCMS::$currentPage, 'description' => '', 'keywords' => '', 'html' => '<h2>Click here to create some content</h2><p>Search engines are more likely to visit your website once some content is created.</p>']; return ['title' => 'Page not found', 'description' => '', 'keywords' => '', 'html' => '<h4>Sorry, page not found. :(</h4>'];
	}
	public static function _loginView() {
		return ['title' => 'Login', 'description' => '', 'keywords' => '', 'html' => '<form action="' . wCMS::url(wCMS::get('config','login')) . '" method="post"><div class="input-group"><input type="password" class="form-control" id="password" name="password"><span class="input-group-btn"><button type="submit" class="btn btn-info">Login</button></span></div></form>'];
	}
	public static function _notify() {
		if ( ! wCMS::$loggedIn) return;
		if ( ! wCMS::$currentPageExists) wCMS::alert('info', '<b>This page (' . wCMS::$currentPage . ') doesn\'t exist yet.</b> Click inside the content below and make your first edit to create it.');
		if (wCMS::get('config','login') === 'loginURL') wCMS::alert('warning', 'Change your default login URL and bookmark/save it.', true); if (password_verify('admin', wCMS::get('config','password'))) wCMS::alert('danger', 'Change your default password.', true);
		if ( ! isset($_SESSION['u'])) {$_SESSION['u'] = true; {if (trim(file_get_contents('https://raw.githubusercontent.com/robiso/WonderCMS-TEST/master/version')) != version) {
			wCMS::alert('info', '<b>Your WonderCMS version is out of date.</b> <form style="display:inline" action="" method="post"><button class="btn btn-info" name="upgrade">Update WonderCMS</button></form><p>Before updating:</p><p>- <a href="https://github.com/robiso/wondercms/wiki/Backup-all-files" target="_blank">backup all files</a> </p><p>- <a href="https://www.wondercms.com/whatsnew" target="_blank">check what\'s new</a></p>', true);};}}
	}
	public static function _loadPlugins() {
		if ( ! is_dir(__DIR__ . '/plugins')) mkdir(__DIR__ . '/plugins');
		foreach (glob(__DIR__ . '/plugins/*', GLOB_ONLYDIR) as $dir) if (file_exists($dir . '/' . basename($dir) . '.php')) include $dir . '/' . basename($dir) . '.php';
	}
	public static function _create() {
		$db = wCMS::db();$db->pages->{wCMS::$currentPage} = new stdClass; wCMS::save($db);wCMS::set('pages',wCMS::$currentPage,'title',mb_convert_case(wCMS::$currentPage, MB_CASE_TITLE)); wCMS::set('pages',wCMS::$currentPage,'keywords','Keywords, are, good, for, search, engines'); wCMS::set('pages',wCMS::$currentPage,'description','A short description for this page.');
	}
	public static function _hook() {
		$numArgs = func_num_args();
		$args = func_get_args();
		if ($numArgs < 2) trigger_error('Insufficient arguments', E_USER_ERROR);
		$hookName = array_shift($args);
		if ( ! isset(wCMS::$_listeners[$hookName])) return $args;
		foreach (wCMS::$_listeners[$hookName] as $func) $args = $func($args);
		return $args;
	}
	public static function _install() {
		if (wCMS::db() !== false) return;
		wCMS::save([
			'config' =>  [
				'siteTitle' => 'Website title',
				'theme' => 'default',
				'defaultPage' => 'home',
				'login' => 'loginURL',
				'password' => password_hash('admin', PASSWORD_DEFAULT),
				'menuItems' => [
					'home',
					'example'
				]
			],
			'blocks' => [
				'footer' => [ 
					'html' => '&copy;' . date('Y') . ' Your website'
				],
				'subside' => [
					'html' => '<h3>About your website</h3> <p>Your photo, website description, contact information, mini map or anything else.</p> <p>This content is static and visible on all pages.</p>'
				]
			],
			'pages' => [
				'home' => [
					'title' => 'Home',
					'keywords' => 'Keywords, are, good, for, search, engines',
					'description' => 'A short description for this page.',
					'html' => '<h2>It\'s alive!</h2><p class="lightFont">Your website is now powered by WonderCMS.</p><p><a href="' . wCMS::url('loginURL') . '">Click here to login, the password is <b>admin</b>.</a></p> <p>Simply click on content to edit and click outside to save it.</p>'
				],
				'example' => [
					'title' => 'Example',
					'keywords' => 'Keywords, are, good, for, search, engines',
					'description' => 'A short description for this page.',
					'html' => '<h2>How to add new pages</h2> <p class="lightFont">How to add new pages</p> <p>Open the settings panel, click on the existing pages in the menu and enter a new page.</p>'
				]
			]
		]);
	}
}
wCMS::init();
