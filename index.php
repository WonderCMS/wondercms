<?php // WonderCMS - MIT license: wondercms.com/license

session_start();
define('version', '2.3.1');
mb_internal_encoding('UTF-8');

class wCMS {
	public static $loggedIn = false;
	public static $currentPage;
	public static $currentPageExists = false;
	public static $_listeners = [];
	public static $db = false;
	public static function _updateOtherFiles() {
		$olddb = wCMS::db();
		if ( ! isset($olddb->{'config'}->{'dbVersion'})) {
			if (file_exists(__DIR__ . '/themes/default/theme.php')) file_put_contents(__DIR__ . '/themes/default/theme.php', wCMS::_getExternalFile('https://raw.githubusercontent.com/robiso/wondercms/master/themes/default/theme.php'));
			if (file_exists(__DIR__ . '/themes/default/css/style.css')) file_put_contents(__DIR__ . '/themes/default/css/style.css', wCMS::_getExternalFile('https://raw.githubusercontent.com/robiso/wondercms/master/themes/default/css/style.css'));
			if (file_exists('.htaccess')) file_put_contents('.htaccess', wCMS::_getExternalFile('https://raw.githubusercontent.com/robiso/wondercms/master/.htaccess'));
			unlink('database.js');
			wCMS::_createDatabase();
			$newdb = wCMS::db();
			$newdb->{'config'}->{'siteTitle'} = $olddb->{'config'}->{'siteTitle'};
			$newdb->{'config'}->{'theme'} = 'default';
			$newdb->{'config'}->{'defaultPage'} = $olddb->{'config'}->{'defaultPage'};
			$newdb->{'config'}->{'login'} = $olddb->{'config'}->{'login'};
			$newdb->{'config'}->{'password'} = $olddb->{'config'}->{'password'};
			$newdb->{'config'}->{'menuItems'} = $olddb->{'config'}->{'menuItems'};
			$newdb->{'pages'} = $olddb->{'pages'};
			$newdb->{'blocks'}->{'subside'}->{'content'} = $olddb->{'config'}->{'subside'};
			$newdb->{'blocks'}->{'footer'}->{'content'} = $olddb->{'config'}->{'copyright'};
			wCMS::save($newdb);
		}
		if ($olddb->config->dbVersion < '2.1.0') {
			$newMenu = new stdClass();
			$newPages = new stdClass();
			$i = 0;
			foreach ($olddb->pages as $pageName => $page) {
				$slugPage = wCMS::_slugify($pageName);
				$newPages->$slugPage = $page;
				$newMenu->{$i} = new stdClass;
				$newMenu->{$i}->name = $page->title;
				$newMenu->{$i}->slug = $slugPage;
				$newMenu->{$i}->visibility = "show";
				$i++;
			}
			unset($olddb->config->menuItems);
			unset($olddb->pages);
			$olddb->config->menuItems = $newMenu;
			$olddb->pages = $newPages;
			$olddb->config->dbVersion = '2.1.0';
			wCMS::save($olddb);
		} elseif ($olddb->config->dbVersion < '2.3.0') {
			if (file_exists(__DIR__ . '/themes/default/theme.php')) file_put_contents(__DIR__ . '/themes/default/theme.php', wCMS::_getExternalFile('https://raw.githubusercontent.com/robiso/wondercms/master/themes/default/theme.php'));
			if (file_exists(__DIR__ . '/themes/default/css/style.css')) file_put_contents(__DIR__ . '/themes/default/css/style.css', wCMS::_getExternalFile('https://raw.githubusercontent.com/robiso/wondercms/master/themes/default/css/style.css'));
			$olddb->config->dbVersion = '2.3.0';
			wCMS::save($olddb);
		}
	}
	public static function init() {
		wCMS::_loadPlugins();
		wCMS::_createDatabase();
		wCMS::_updateOtherFiles();
		wCMS::_installThemePlugin();
		if (isset($_SESSION['l'], $_SESSION['i']) && $_SESSION['i'] == __DIR__) wCMS::$loggedIn = true;
		wCMS::$currentPage = empty(wCMS::parseUrl()) ? wCMS::get('config','defaultPage') : wCMS::parseUrl();
		if (isset(wCMS::get('pages')->{wCMS::$currentPage})) wCMS::$currentPageExists = true;
		if (isset($_GET['page']) && ! wCMS::$loggedIn) if (wCMS::$currentPage !== wCMS::_slugify($_GET['page'])) wCMS::$currentPageExists = false;
		wCMS::_logoutAction(); wCMS::_loginAction(); wCMS::_saveAction(); wCMS::_changePasswordAction(); wCMS::_deleteAction(); wCMS::_upgradeAction(); wCMS::_notify(); wCMS::_removeFile(); wCMS::_uploadFile(); wCMS::_backUp();
		if ( ! wCMS::$loggedIn && ! wCMS::$currentPageExists) header("HTTP/1.0 404 Not Found");
		if (file_exists(__DIR__ . '/themes/' . wCMS::get('config','theme') . '/functions.php')) require_once __DIR__ . '/themes/' . wCMS::get('config','theme') . '/functions.php';
		require_once __DIR__ . '/themes/' . wCMS::get('config','theme') . '/theme.php';
	}
	public static function editable($id, $content, $dataTarget = '') {
		return '<div' . ($dataTarget != '' ? ' data-target="' . $dataTarget . '"' : '') . ' id="' . $id . '" class="editText editable">' . $content . '</div>';
	}
	public static function page($key) {
		$segments = wCMS::$currentPageExists ? wCMS::get('pages', wCMS::$currentPage) : (wCMS::get('config','login') == wCMS::$currentPage ? (object) wCMS::_loginView() : (object) wCMS::_notFoundView());
		$segments->content = isset($segments->content) ? $segments->content: '<h2>Click here to create some content</h2> <p>Once you do that, this page will be eventually visited by search engines.</p>';
		$keys = ['title' => mb_convert_case(str_replace("-", " ", $segments->title), MB_CASE_TITLE), 'description' => $segments->description, 'keywords' => $segments->keywords, 'content' => (wCMS::$loggedIn ? wCMS::editable('content', $segments->content, 'pages') : $segments->content)];
		$content = isset($keys[$key]) ? $keys[$key] : '';
		return wCMS::_hook('page', $content, $key)[0];
	}
	public static function block($key) {
		$blocks = wCMS::get('blocks');
		return isset($blocks->{$key}) ? (wCMS::$loggedIn ? wCMS::editable($key, $blocks->{$key}->content, 'blocks') : $blocks->{$key}->content) : '';
	}
	public static function menu() {
		$output = '';
		foreach (wCMS::get('config','menuItems') as $key => $value) {
			if ($value->visibility == "hide") continue;
			$output .= '<li' . (wCMS::$currentPage === $value->slug ? ' class="active"' : '') . '><a href="' . wCMS::url($value->slug) . '">' . $value->name . '</a></li>';
		}
		return wCMS::_hook('menu', $output)[0];
	}
	public static function footer() {
		$output = wCMS::get('blocks','footer')->content . ( ! wCMS::$loggedIn ? ((wCMS::get('config','login') == 'loginURL') ? ' &bull; <a href="' . wCMS::url('loginURL') . '">Login</a>' : '') : '');
		return wCMS::_hook('footer', $output)[0];
	}
	public static function alerts() {
		if ( ! isset($_SESSION['alert'])) return;
		$session = $_SESSION['alert'];
		$output = '';
		unset($_SESSION['alert']);
		foreach ($session as $key => $value) foreach ($value as $key => $val) $output .= '<div class="alert alert-'.$val['class'].( ! $val['sticky'] ? ' alert-dismissible' : '').'">'.( ! $val['sticky'] ? '<button type="button" class="close" data-dismiss="alert">&times;</button>' : '').$val['message'].'</div>';
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
		return 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] . ((($_SERVER['SERVER_PORT'] == '80') || ($_SERVER['SERVER_PORT'] == '443'))? '' : ':' . $_SERVER['SERVER_PORT']) . ((dirname($_SERVER['SCRIPT_NAME']) == '/')? '' : dirname($_SERVER['SCRIPT_NAME'])) . '/' . $location;
	}
	public static function parseUrl() {
		if (isset($_GET['page']) && $_GET['page'] == wCMS::get('config','login')) return htmlspecialchars($_GET['page'], ENT_QUOTES);
		return isset($_GET['page']) ? wCMS::_slugify($_GET['page']) : '';
	}
	public static function get() {
		$numArgs = func_num_args();
		$args = func_get_args();
		if ( ! wCMS::$db) wCMS::$db = wCMS::db();
		switch ($numArgs) {
			case 1: return wCMS::$db->{$args[0]}; break;
			case 2: return wCMS::$db->{$args[0]}->{$args[1]}; break;
			case 3: return wCMS::$db->{$args[0]}->{$args[1]}->{$args[2]}; break;
			case 4: return wCMS::$db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]}; break;
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
			case 5: $db->{$args[0]}->{$args[1]}->{$args[2]}->{$args[3]} = $args[4]; break;
		}
		wCMS::save($db);
	}
	public static function db() {
		return file_exists(__DIR__ . '/database.js') ? json_decode(file_get_contents(__DIR__ . '/database.js')) : false;
	}
	public static function save($db) {
		file_put_contents(__DIR__ . '/database.js', json_encode($db, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	}
	public static function addListener($hook, $functionName) {
		wCMS::$_listeners[$hook][] = $functionName;
	}
	public static function getMenuSettings() {
		if ( ! wCMS::$loggedIn) return;
		$items = wCMS::get('config','menuItems');
		reset($items);
		$first = key($items);
		end($items);
		$end = key($items);
		$output = '<p class="subTitle">Menu</p><div><div id="menuSettings" class="container-fluid">';
		foreach ($items as $key => $value) {
			$output .= '<div class="row marginTop5"><div class="col-xs-1 col-sm-1 text-right"><i class="btn menu-toggle glyphicon' . ($value->visibility == "show" ? ' glyphicon-eye-open menu-item-hide' : ' glyphicon-eye-close menu-item-show') . '" data-toggle="tooltip" title="' . ($value->visibility == "show" ? 'Hide page from menu' : 'Show page in menu') . '" data-menu="' . $key . '"></i></div><div class="col-xs-4 col-sm-8"><div data-target="menuItem" data-menu="' . $key . '" data-visibility="' . ($value->visibility) . '" id="menuItems" class="editText">' . $value->name . '</div></div><div class="col-xs-2 col-sm-1 text-left">';
			$output .= ($key === $first) ? '' : '<a class="glyphicon glyphicon-arrow-up toolbar menu-item-up cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move up"></a>';
			$output .= ($key === $end) ?'' : '<a class="glyphicon glyphicon-arrow-down toolbar menu-item-down cursorPointer" data-toggle="tooltip" data-menu="' . $key . '" title="Move down"></a>';
			$output .= '</div><div class="col-xs-2 col-sm-1 text-left"><a class="glyphicon glyphicon-link" href="' . wCMS::url($value->slug) . '" title="Visit page">visit</a></div><div class="col-xs-2 col-sm-1 text-right"><a href="' . wCMS::url('?delete=' . $value->slug.'&token='.wCMS::_generateToken()) . '" title="Delete page" class="btn btn-xs btn-danger" data-menu="' . $key . '" onclick="return confirm(\'Delete ' . $value->slug . '?\')">&times;</a></div></div>';
		}
		$output .= '<a class="menu-item-add btn btn-info marginTop20" data-toggle="tooltip" title="Add new page">Add page</a></div></div>'; 
		return wCMS::_hook('getMenuSettings', $output)[0];
	}
	public static function settings() {
		if ( ! wCMS::$loggedIn) return;
		$fileList = array_slice(scandir(__DIR__ . '/files/'), 2);
		$fileImagesList = array_slice(scandir(__DIR__ . '/files/images/'), 2);
		$fileDocsList = array_slice(scandir(__DIR__ . '/files/docs/'), 2);
		$themeList = array_slice(scandir(__DIR__ . '/themes/'), 2);
		$pluginList = array_slice(scandir(__DIR__ . '/plugins/'), 2);
		$output ='<div id="save"><h2>Saving...</h2></div><div id="adminPanel" class="container-fluid"><div class="text-right padding20"><a data-toggle="modal" class="padding20" href="#settingsModal"><b>Settings</b></a><a href="' . wCMS::url('logout&token='.wCMS::_generateToken()).'">Logout</a></div><div class="modal" id="settingsModal"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button></div><div class="modal-body col-xs-12"><ul class="nav nav-tabs text-center" role="tablist"><li role="presentation" class="active"><a href="#currentPage" aria-controls="currentPage" role="tab" data-toggle="tab">Current page</a></li><li role="presentation"><a href="#general" aria-controls="general" role="tab" data-toggle="tab">General</a></li><li role="presentation"><a href="#files" aria-controls="files" role="tab" data-toggle="tab">Files</a></li><li role="presentation"><a href="#themesAndPlugins" aria-controls="themesAndPlugins" role="tab" data-toggle="tab">Themes & plugins</a></li><li role="presentation"><a href="#security" aria-controls="security" role="tab" data-toggle="tab">Security</a></li></ul><div class="tab-content col-md-8 col-md-offset-2"><div role="tabpanel" class="tab-pane active" id="currentPage">';
		if (wCMS::$currentPageExists) { $output .= '<p class="subTitle">Page title</p><div class="change"><div data-target="pages" id="title" class="editText">' . (wCMS::get('pages', wCMS::$currentPage)->title != '' ? wCMS::get('pages' , wCMS::$currentPage)->title : '') . '</div></div><p class="subTitle">Page keywords</p><div class="change"><div data-target="pages" id="keywords" class="editText">' . (wCMS::get('pages', wCMS::$currentPage)->keywords != '' ? wCMS::get('pages', wCMS::$currentPage)->keywords : '') . '</div></div><p class="subTitle">Page description</p><div class="change"><div data-target="pages" id="description" class="editText">' . (wCMS::get('pages', wCMS::$currentPage)->description != '' ? wCMS::get('pages', wCMS::$currentPage)->description : '') . '</div></div><a href="' . wCMS::url('?delete=' . wCMS::$currentPage . '&token=' . wCMS::_generateToken()) . '" class="btn btn-danger marginTop20" title="Delete page" onclick="return confirm(\'Delete ' . wCMS::$currentPage . '?\')">Delete page (' . wCMS::$currentPage . ')</a>'; } else { $output .= 'This page does not exist. After this page is created, more settings will be displayed here.';}
		$output .= '</div><div role="tabpanel" class="tab-pane" id="general">'.wCMS::getMenuSettings().'<p class="subTitle">Main website title</p><div class="change"><div data-target="config" id="siteTitle" class="editText">' . (wCMS::get('config','siteTitle') != '' ? wCMS::get('config','siteTitle') : '') . '</div></div><p class="subTitle">Theme</p><div class="form-group"><div class="change"><select class="form-control" name="themeSelect" onchange="fieldSave(\'theme\',this.value,\'config\');">';
		foreach (glob(__DIR__ . '/themes/*', GLOB_ONLYDIR) as $dir) $output .= '<option value="' . basename($dir) . '"' . (basename($dir) == wCMS::get('config','theme') ? ' selected' : '') . '>' . basename($dir) . ' theme'.'</option>';
		$output .= '</select></div></div><p class="subTitle">Page to display on homepage</p><div class="change"><div data-target="config" id="defaultPage" class="editText">' . wCMS::get('config','defaultPage') . '</div></div><p class="subTitle">Footer</p> <div class="change"><div data-target="blocks" id="footer" class="editText">' . (wCMS::get('blocks','footer')->content != '' ? wCMS::get('blocks','footer')->content : '') . '</div></div></div><div role="tabpanel" class="tab-pane" id="files"><p class="subTitle">Upload</p><div class="change"><form action="' . wCMS::url(wCMS::$currentPage) . '" method="post" enctype="multipart/form-data"><div class="input-group"><input type="file" name="uploadFile" class="form-control"><span class="input-group-btn"><button type="submit" class="btn btn-info">Upload</button></span><input type="hidden" name="token" value="' . wCMS::_generateToken() . '"></div></form></div><p class="subTitle marginTop20">Remove files</p><div class="change">';
		foreach ($fileList as $file) { if($file != 'images' && $file != 'docs') { $output .= '<a href="' . wCMS::url('?deleteFile='.$file.'&token='.wCMS::_generateToken()).'" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $file . '?\')" title="Delete file">&times;</a><span class="marginLeft5"><a href="'. wCMS::url('files/'). $file.'" class="normalFont" target="_blank">'.wCMS::url('files/').'<b class="fontSize21">'.$file.'</b></a></span><p></p>'; } }
		foreach ($fileImagesList as $fileImage) { $output .= '<a href="' . wCMS::url('?deleteFile='.$fileImage.'&token='.wCMS::_generateToken()).'" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $fileImage . '?\')" title="Delete file">&times;</a><span class="marginLeft5"><a href="'. wCMS::url('files/images/'). $fileImage.'" class="normalFont" target="_blank">'.wCMS::url('files/images/').'<b class="fontSize21">'.$fileImage.'</b></a></span><p></p>'; }
		foreach ($fileDocsList as $fileDoc) { $output .= '<a href="' . wCMS::url('?deleteFile='.$fileDoc.'&token='.wCMS::_generateToken()).'" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $fileDoc . '?\')" title="Delete file">&times;</a><span class="marginLeft5"><a href="'. wCMS::url('files/docs/'). $fileDoc.'" class="normalFont" target="_blank">'.wCMS::url('files/docs/').'<b class="fontSize21">'.$fileDoc.'</b></a></span><p></p>'; }
		$output .= '</div></div><div role="tabpanel" class="tab-pane" id="security"><p class="subTitle">Admin login URL</p><div class="change"><div data-target="config" id="login" class="editText">' . wCMS::get('config','login') . '</div><p class="text-right"><b>Important: save/remember your URL after changing<br><span class="normalFont">' . wCMS::url(wCMS::get('config','login')) . '</span></b></</p></div><p class="subTitle">Password</p><div class="change"><form action="' . wCMS::url(wCMS::$currentPage) . '" method="post"><div class="form-group"><input type="password" name="old_password" class="form-control" placeholder="Old password"></div><div class="form-group"><input type="password" name="new_password" class="form-control" placeholder="New password"></div><input type="hidden" name="fieldname" value="password"><input type="hidden" name="token" value="' . wCMS::_generateToken() . '"><button type="submit" class="btn btn-info">Change password</button></form></div><p class="subTitle">Backup</p><div class="change"><form action="' . wCMS::url(wCMS::$currentPage) . '" method="post"><button type="submit" class="btn btn-block btn-info" name="backup">Backup website</button><input type="hidden" name="token" value="' . wCMS::_generateToken() . '"></form></div><p class="text-right marginTop5"><a href="https://github.com/robiso/wondercms/wiki/Restore-backup#how-to-restore-a-backup-in-3-steps" target="_blank">How to restore backups?</a></p></div><div role="tabpanel" class="tab-pane" id="themesAndPlugins"><p class="subTitle">Install or update</p><div class="change"><form action="' . wCMS::url(wCMS::$currentPage) . '" method="post"><div class="form-group"><label class="radio-inline"><input type="radio" name="installLocation" value="themes">Theme</label><label class="radio-inline"><input type="radio" name="installLocation" value="plugins">Plugin</label><p></p><div class="input-group"><input type="text" name="addonURL" class="form-control normalFont" placeholder="Paste link/URL to ZIP file"><span class="input-group-btn"><button type="submit" class="btn btn-info">Install/Update</button></span></div></div><input type="hidden" value="true" name="installAddon"><input type="hidden" name="token" value="' . wCMS::_generateToken() . '"></form></div><p class="text-right"><a href="https://github.com/robiso/wondercms-themes#list-of-approved-themes" target="_blank">Get themes</a> &bull; <a href="https://github.com/robiso/wondercms-plugins#approved-plugins" target="_blank">Get plugins</a></p><p class="subTitle">Remove themes</p><div class="change">';
		foreach ($themeList as $theme) { $output .= '<a href="' . wCMS::url('?deleteTheme='.$theme.'&token='.wCMS::_generateToken()).'" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $theme . '?\')" title="Delete theme">&times;</a> '.$theme.'<p></p>'; }
		$output .= '</div><p class="subTitle">Remove plugins</p><div class="change">';
		foreach ($pluginList as $plugin) { $output .= '<a href="' . wCMS::url('?deletePlugin='.$plugin.'&token='.wCMS::_generateToken()).'" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete ' . $plugin . '?\')" title="Delete plugin">&times;</a> '.$plugin.'<p></p>'; }
		$output .= '</div></div></div></div><div class="modal-footer clear"><p><small>WonderCMS '. version . ' &bull; <a href="https://wondercms.com/community" target="_blank">Community</a> &bull; <a href="https://github.com/robiso/wondercms/wiki#wondercms-documentation" target="_blank">Documentation</a> &bull; <a href="https://wondercms.com/donate" target="_blank">Donate</a></small></p></div></div></div></div></div>';
		return wCMS::_hook('settings', $output)[0];
	}
	public static function css() {
		if (wCMS::$loggedIn) {
			$styles = <<<'EOT'
<style>#adminPanel{background:#e5e5e5;color:#aaa;font-family:"Lucida Sans Unicode",Verdana;font-size:14px;text-align:left;font-variant:small-caps}#adminPanel .fontSize21{font-size:21px}.alert{margin-bottom:0}#adminPanel a{color:#aaa;outline:0;border:0}#adminpanel .alert a{color:#fff}#adminPanel a.btn{color:#fff}#adminPanel div.editText{color:#555;font-variant:normal}#adminPanel .normalFont{font-variant:normal}div.editText{cursor:pointer;border:2px dashed #ccc;display:block}.cursorPointer{cursor:pointer}div.editText textarea{outline:0;border:none;width:100%;resize:none;color:inherit;font-size:inherit;font-family:inherit;background-color:transparent;overflow:hidden;box-sizing:content-box}div.editText:empty{min-height:20px}#save{color:#ccc;left:0;width:100%;height:100%;display:none;position:fixed;text-align:center;padding-top:100px;background:rgba(51,51,51,.8);z-index:2448}.change{padding-left:15px}.marginTop5{margin-top:5px}.marginTop20{margin-top:20px}.marginLeft5{margin-left:5px}.padding20{padding:20px}.subTitle{color:#aaa;font-size:24px;margin:10px 0 5px;font-variant:all-small-caps}.note-editor{border:2px dashed #ccc}.menu-item-hide{color:#5bc0de}.menu-item-delete,.menu-item-hide,.menu-item-show{padding:0 10%}#adminPanel .nav-tabs{border-bottom:2px solid #ddd}#adminPanel .nav-tabs>li>a::after{content:"";background:#1ab;height:2px;position:absolute;width:100%;left:0;bottom:-1px;transition:all 250ms ease 0s;transform:scale(0)}#adminPanel .nav-tabs>li>a::hover{border-bottom: 1px solid #1ab !important:}#adminPanel .nav-tabs>li.active>a::after, #adminPanel .nav-tabs>li:hover>a::after{transform:scale(1)}.tab-content{padding:20px}#adminPanel .modal-content{background-color:#eee}#adminPanel .modal-header{border:0}#adminPanel .nav li{font-size:28px;float:none;display:inline-block}#adminPanel .tab-pane.active a.btn{color:#fff}#adminPanel .nav-tabs>li.active a,#adminPanel .tab-pane.active{background:0 !important;border:0!important;color:#aaa!important;}#adminPanel .clear{clear:both}@media(min-width:768px){#adminPanel .modal-xl{width:90%;max-width:1200px}}</style>
EOT;
			return wCMS::_hook('css', $styles)[0];
		}
		return wCMS::_hook('css', '')[0];
	}
	public static function js() {
		if (wCMS::$loggedIn) {
			$scripts = <<<'EOT'
<script src="https://cdn.jsdelivr.net/jquery.autosize/3.0.17/autosize.min.js"></script>
<script src="https://cdn.jsdelivr.net/taboverride/4.0.2/taboverride.min.js"></script>
<script src="https://cdn.jsdelivr.net/jquery.taboverride/4.0.0/jquery.taboverride.min.js"></script>
<script>$(document).tabOverride(!0,"textarea");function nl2br(a){return(a+"").replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g,"$1<br>$2")}function fieldSave(a,b,c,d,e){$("#save").show(),$.post("",{fieldname:a,token:token,content:b,target:c,menu:d,visibility:e},function(a){}).always(function(){window.location.reload()})}var changing=!1;$(document).ready(function(){$('body').on('click','div.editText',function(){changing||(a=$(this),title=a.attr("title")?title='"'+a.attr("title")+'" ':"",a.hasClass("editable")?a.html("<textarea "+title+' id="'+a.attr("id")+'_field" onblur="fieldSave(a.attr(\'id\'),this.value,a.data(\'target\'),a.data(\'menu\'),a.data(\'visibility\'));">'+a.html()+"</textarea>"):a.html("<textarea "+title+' id="'+a.attr("id")+'_field" onblur="fieldSave(a.attr(\'id\'),nl2br(this.value),a.data(\'target\'),a.data(\'menu\'),a.data(\'visibility\'));">'+a.html().replace(/<br>/gi,"\n")+"</textarea>"),a.children(":first").focus(),autosize($("textarea")),changing=!0)});$('body').on('click','i.menu-toggle',function(){var a=$(this),c=(setTimeout(function(){window.location.reload()},500),a.attr("data-menu"));a.hasClass("menu-item-hide")?(a.removeClass("glyphicon-eye-open menu-item-hide").addClass("glyphicon-eye-close menu-item-show"),a.attr("title","Hide page from menu").attr("data-visibility","hide"),$.post("",{fieldname:"menuItems", token:token, content:" ",target:"menuItemVsbl",menu:c,visibility:"hide"},function(a){})):a.hasClass("menu-item-show")&&(a.removeClass("glyphicon-eye-close menu-item-show").addClass("glyphicon-eye-open menu-item-hide"),a.attr("title","Show page in menu").attr("data-visibility","show"),$.post("",{fieldname:"menuItems",token:token,content:" ",target:"menuItemVsbl",menu:c,visibility:"show"},function(a){}))}),$('body').on('click','.menu-item-add',function(){$.post("",{fieldname:"menuItems",token:token,content:"New page",target:"menuItem",menu:"none",visibility:"show"},function(a){}).done(setTimeout(function(){window.location.reload()},500))});$('body').on('click','.menu-item-up,.menu-item-down',function(){var a=$(this),b=(a.hasClass('menu-item-up'))?'-1':'1',c=a.attr("data-menu");$.post("",{fieldname:"menuItems",token:token,content:b,target:"menuItemOrder",menu:c,visibility:""},function(a){}).done(function(){$('#menuSettings').parent().load("index.php #menuSettings",{func:"getMenuSettings"})})})});</script>
EOT;
			$scripts .= '<script>var token = "'.wCMS::_generateToken().'";</script>';
			return wCMS::_hook('js', $scripts)[0];
		}
		return wCMS::_hook('js', '')[0];
	}
	public static function _loginAction() {
		if (wCMS::$currentPage !== wCMS::get('config','login')) return;
		if (wCMS::$loggedIn) wCMS::redirect();
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
		$password = isset($_POST['password']) ? $_POST['password'] : '';
		if (password_verify($password, wCMS::get('config','password'))) { $_SESSION['l'] = true; $_SESSION['i'] = __DIR__; wCMS::redirect(); }
		wCMS::alert('danger', 'Wrong password.'); wCMS::redirect(wCMS::get('config','login'));
	}
	public static function _logoutAction() {
		if (wCMS::$currentPage === 'logout' && $_REQUEST['token'] == wCMS::_generateToken()) { unset($_SESSION['l'], $_SESSION['i'], $_SESSION['u'], $_SESSION['token']); wCMS::redirect(); }
	}
	public static function _newMenuItem($content, $menu, $visibility) {
		$conf = 'config';
		$field = 'menuItems';
		$exist = is_numeric($menu);
		$visibility = (isset($visibility) && $visibility == "show") ? "show" : "hide";
		$content = empty($content) ? "empty" : str_replace(array(PHP_EOL,'<br>'), '', $content);
		$slug = wCMS::_slugify($content);
		$menuCount = count(get_object_vars(wCMS::get($conf, $field)));
		if ( ! $exist) { $db=wCMS::db(); $slug.= ($menu) ? "-" . $menuCount : ""; foreach($db->config->{$field} as $key=>$value) if ($value->slug == $slug) $slug.= "-extra"; $db->config->{$field}->{$menuCount} = new stdClass; wCMS::save($db); wCMS::set($conf, $field, $menuCount, 'name', $content); wCMS::set($conf, $field, $menuCount, 'slug', $slug); wCMS::set($conf, $field, $menuCount, 'visibility', $visibility); if ($menu) { wCMS::_createPage($slug);} } else { $oldSlug = wCMS::get($conf, $field, $menu, 'slug'); wCMS::set($conf, $field, $menu, 'name', $content);
		wCMS::set($conf, $field, $menu, 'slug', $slug);
		wCMS::set($conf, $field, $menu, 'visibility', $visibility);
		if ($slug !== $oldSlug) {wCMS::_createPage($slug); wCMS::_deleteAction($oldSlug, false);}}
	}
	public static function _orderMenuItem($content, $menu) {
		$conf = 'config';
		$field = 'menuItems';
		$content = (int) trim(htmlentities($content, ENT_QUOTES, 'UTF-8'));
		$move = wCMS::get($conf, $field, $menu);
		$menu += $content;
		$tmp = wCMS::get($conf, $field, $menu);
		wCMS::set($conf, $field, $menu, 'name', $move->name);
		wCMS::set($conf, $field, $menu, 'slug', $move->slug);
		wCMS::set($conf, $field, $menu, 'visibility', $move->visibility);
		$menu -= $content;
		wCMS::set($conf, $field, $menu, 'name', $tmp->name);
		wCMS::set($conf, $field, $menu, 'slug', $tmp->slug);
		wCMS::set($conf, $field, $menu, 'visibility', $tmp->visibility);
	}
	public static function _saveAction() {
		if ( ! wCMS::$loggedIn || ! isset($_POST['fieldname']) || ! isset($_POST['content']) || ! isset($_POST['target']) || ! isset($_REQUEST['token']) || ($_REQUEST['token'] != wCMS::_generateToken())) return;
		list($fieldname, $content, $target, $menu, $visibility) = wCMS::_hook('save', $_POST['fieldname'], $_POST['content'], $_POST['target'], $_POST['menu'], $_POST['visibility']);
		if ($target === 'menuItem') wCMS::_newMenuItem($content, $menu, $visibility);
		if ($target === 'menuItemVsbl') wCMS::set('config', $fieldname, $menu, 'visibility', $visibility);
		if ($target === 'menuItemOrder') wCMS::_orderMenuItem($content, $menu);
		if ($fieldname === 'defaultPage') if ( ! isset(wCMS::get('pages')->$content)) return;
		if ($fieldname === 'login') if (empty($content) || isset(wCMS::get('pages')->$content)) return;
		if ($fieldname === 'theme') if ( ! is_dir(__DIR__ . '/themes/' . $content)) return;
		if ($target === 'config') wCMS::set('config', $fieldname, $content); elseif ($target === 'blocks') wCMS::set('blocks', $fieldname, 'content', $content); elseif ($target === 'pages') { if ( ! isset(wCMS::get('pages')->{wCMS::$currentPage})) wCMS::_createPage(); wCMS::set('pages', wCMS::$currentPage, $fieldname, $content); }
	}
	public static function _generateToken() {
		return (isset($_SESSION["token"])) ? $_SESSION["token"] : $_SESSION["token"] = bin2hex(openssl_random_pseudo_bytes(32));
	}
	public static function _changePasswordAction() {
		if ( ! wCMS::$loggedIn || ! isset($_POST['old_password']) || ! isset($_POST['new_password'])) return;
		if ($_SESSION['token'] === $_REQUEST['token'] && ($_REQUEST['token'] == wCMS::_generateToken())) {
			if ( ! password_verify($_POST['old_password'], wCMS::get('config','password'))) { wCMS::alert('danger', 'Wrong password.'); wCMS::redirect(wCMS::$currentPage); }
			if (strlen($_POST['new_password']) < 4) { wCMS::alert('danger', 'Password must be longer than 4 characters.'); wCMS::redirect(wCMS::$currentPage); }
			wCMS::set('config', 'password', password_hash($_POST['new_password'], PASSWORD_DEFAULT)); wCMS::alert('success', 'Password changed.'); wCMS::redirect(wCMS::$currentPage);
		}
	}
	public static function _deleteAction($needle = false, $menu = true) {
		if ( ! $needle) { if ( ! wCMS::$loggedIn || ! isset($_GET['delete']) || ($_REQUEST['token'] != wCMS::_generateToken())) return; $needle = $_GET['delete']; } $db=wCMS::db();
		if (isset(wCMS::get('pages')->{$needle})) unset($db->pages->{$needle});
		if ($menu) {$menuItems = json_decode(json_encode(wCMS::get('config','menuItems')), TRUE);
		if (false === ($index = array_search($needle, array_column($menuItems, "slug")))) return; unset($menuItems[$index]); $newMenu=array_values($menuItems); $db->config->menuItems = json_decode(json_encode($newMenu)); }
		wCMS::save($db); wCMS::alert('success', 'Page deleted.'); wCMS::redirect();
	}
	public static function _upgradeAction() {
		if ( ! wCMS::$loggedIn || ! isset($_POST['upgrade'])) return;
		if ($_REQUEST['token'] == wCMS::_generateToken()) {
			$contents = wCMS::_getExternalFile('https://raw.githubusercontent.com/robiso/wondercms/master/index.php');
			if ($contents) file_put_contents(__FILE__, $contents);	wCMS::alert('success', 'WonderCMS successfully updated. Wohoo!'); wCMS::redirect(wCMS::$currentPage);
		}
	}
	public static function _notFoundView() {
		if (wCMS::$loggedIn) return ['title' => str_replace("-", " ", wCMS::$currentPage), 'description' => '', 'keywords' => '', 'content' => '<h2>Click here to create some content</h2><p>Once you do that, this page will be eventually visited by search engines.</p>']; return ['title' => 'Page not found', 'description' => '', 'keywords' => '', 'content' => '<h4>Sorry, page not found. :(</h4>'];
	}
	public static function _loginView() {
		return ['title' => 'Login', 'description' => '', 'keywords' => '', 'content' => '<form action="' . wCMS::url(wCMS::get('config','login')) . '" method="post"><div class="input-group"><input type="password" class="form-control" id="password" name="password"><span class="input-group-btn"><button type="submit" class="btn btn-info">Login</button></span></div></form>'];
	}
	public static function _notify() {
		if ( ! wCMS::$loggedIn) return;
		if ( ! wCMS::$currentPageExists) wCMS::alert('info', '<b>This page (' . wCMS::$currentPage . ') doesn\'t exist yet.</b> Click inside the content below to create it.');
		if (wCMS::get('config','login') === 'loginURL') wCMS::alert('warning', 'Change the default admin login URL. (<i>Settings -> Security</i>)', true); if (password_verify('admin', wCMS::get('config','password'))) wCMS::alert('danger', 'Change the default password. (<i>Settings -> Security</i>)', true);
		$repoVersion = wCMS::_getOfficialVersion(); if ($repoVersion != version) { wCMS::alert('info', '<b>New WonderCMS update available.</b><p>- Backup your website and check <a href="https://www.wondercms.com/whatsnew" target="_blank">what\'s new</a> before updating.</p><form action="' . wCMS::url(wCMS::$currentPage) . '" method="post" class="marginTop5"><button type="submit" class="btn btn-info" name="backup">Create backup</button><input type="hidden" name="token" value="' . wCMS::_generateToken() . '"></form><form action="" method="post" class="marginTop5"><button class="btn btn-info" name="upgrade">Update WonderCMS</button><input type="hidden" name="token" value="' . wCMS::_generateToken() . '"></form>', true);};
	}
	private static function _getOfficialVersion() {
		$data = trim(wCMS::_getExternalFile('https://raw.githubusercontent.com/robiso/wondercms/master/version'));
		return $data;
	}
	public static function _getExternalFile($url) {
		$ch = curl_init(); curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); curl_setopt($ch, CURLOPT_URL, $url);
		$data = curl_exec($ch); curl_close($ch); return $data;
	}
	public static function _loadPlugins() {
		if ( ! is_dir(__DIR__ . '/plugins')) mkdir(__DIR__ . '/plugins');
		if ( ! is_dir(__DIR__ . '/files/images') || ! is_dir(__DIR__ . '/files/docs') || ! is_dir(__DIR__ . '/files/docs')) { mkdir(__DIR__ . '/files'); mkdir(__DIR__ . '/files/docs'); mkdir(__DIR__ . '/files/images'); }
		foreach (glob(__DIR__ . '/plugins/*', GLOB_ONLYDIR) as $dir) if (file_exists($dir . '/' . basename($dir) . '.php')) include $dir . '/' . basename($dir) . '.php';
	}
	public static function _createPage($slug = false) {
		$db = wCMS::db();
		$db->pages->{( ! $slug) ? wCMS::$currentPage : $slug} = new stdClass;
		wCMS::save($db);
		wCMS::set('pages', ( ! $slug) ? wCMS::_slugify(wCMS::$currentPage) : $slug, 'title', ( ! $slug) ? mb_convert_case(str_replace("-", " ", wCMS::$currentPage), MB_CASE_TITLE) : mb_convert_case(str_replace("-", " ", $slug), MB_CASE_TITLE));
		wCMS::set('pages', ( ! $slug) ? wCMS::_slugify(wCMS::$currentPage) : $slug, 'keywords', 'Keywords, are, good, for, search, engines');
		wCMS::set('pages', ( ! $slug) ? wCMS::_slugify(wCMS::$currentPage) : $slug, 'description', 'A short description is also good.');
		if ( ! $slug) { wCMS::_newMenuItem(wCMS::_slugify(wCMS::$currentPage), null, "show");}
	}
	public static function _slugify($text) {
		$text = preg_replace('~[^\\pL\d]+~u','-', $text);
		$text = trim(htmlspecialchars(mb_strtolower($text), ENT_QUOTES), '/');
		$text = trim($text,'-');
		return empty($text) ? "-" : $text;
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
	public static function _installThemePlugin() {
		if ( ! wCMS::$loggedIn && ! isset($_POST['installAddon'])) return;
		if ($_REQUEST['token'] == wCMS::_generateToken()) {
			$installLocation = $_POST['installLocation'];
			$addonURL = $_POST['addonURL'];
			if ($installLocation == 'themes' || $installLocation == 'plugins' && ! empty($addonURL)) {
				$zipFile = __DIR__ . '/files/ZIPFromURL.zip';
				$zipResource = fopen($zipFile, "w");
				$ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $addonURL); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_FILE, $zipResource); curl_exec($ch); curl_close($ch);
				$zip = new ZipArchive;
				$extractPath = __DIR__ . '/' . $installLocation . '/';
				if ($zip->open($zipFile) != 'true' || (stripos($addonURL,'.zip') != true)) {
					wCMS::_recursiveDelete(__DIR__ . '/files/ZIPFromURL.zip'); wCMS::alert('danger', 'Error openning ZIP file.'); wCMS::redirect();
				}
				$zip->extractTo($extractPath); $zip->close(); wCMS::_recursiveDelete(__DIR__ . '/files/ZIPFromURL.zip'); wCMS::alert('success', 'Installed successfully.'); wCMS::redirect();
			} else {
				wCMS::alert('danger', 'Choose between theme or plugin and enter link to ZIP file.'); wCMS::redirect();
			}
		}
	}
	public static function _uploadFile() {
		if ( ! wCMS::$loggedIn && ! isset($_FILES['uploadFile']) && ! isset($_REQUEST['token'])) return;
		if ($_REQUEST['token'] == wCMS::_generateToken() && isset($_FILES['uploadFile'])) {
			try {
				if ( ! isset($_FILES['uploadFile']['error']) ||	is_array($_FILES['uploadFile']['error'])) {
					wCMS::alert('danger', 'Invalid parameters.'); wCMS::redirect(wCMS::$currentPage);
				}
				switch ($_FILES['uploadFile']['error']) {
					case UPLOAD_ERR_OK:
						break;
					case UPLOAD_ERR_NO_FILE:
						wCMS::alert('danger', 'No file selected.'); wCMS::redirect(wCMS::$currentPage);
					case UPLOAD_ERR_INI_SIZE:
					case UPLOAD_ERR_FORM_SIZE:
						wCMS::alert('danger', 'File too large. Change maximum upload size limit or contact your host.'); wCMS::redirect(wCMS::$currentPage);
					default:
						wCMS::alert('danger', 'Unknown error.'); wCMS::redirect(wCMS::$currentPage);
				}
				$mimeType = '';
				if (class_exists('finfo')) {
					$finfo = new finfo(FILEINFO_MIME_TYPE);
					$mimeType = $finfo->file($_FILES['uploadFile']['tmp_name']);
				} else if (function_exists('mime_content_type')) {
					$mimeType = mime_content_type($_FILES['uploadFile']['tmp_name']);
				} else {
					$ext = strtolower(array_pop(explode('.', $_FILES['uploadFile']['name'])));
				}
				if ( ! move_uploaded_file($_FILES['uploadFile']['tmp_name'], sprintf(__DIR__ . '/files/%s', $_FILES['uploadFile']['name']))) {
					wCMS::alert('danger', 'Failed to move uploaded file.');	wCMS::redirect(wCMS::$currentPage);
				}
				wCMS::alert('success', 'File uploaded.'); wCMS::redirect(wCMS::$currentPage);
			} catch (RuntimeException $e) {
				wCMS::alert('danger', 'Error: ' . $e->getMessage()); wCMS::redirect(wCMS::$currentPage);
			}
		}
	}
	public static function _recursiveDelete($file) {
		if (is_dir($file)) {
			$list = glob($file . '*', GLOB_MARK);
			foreach ($list as $dir)	wCMS::_recursiveDelete($dir);
			rmdir($file);
		} elseif (is_file($file)) unlink($file);
	}
	public static function _removeFile() {
		if ( ! wCMS::$loggedIn && ! isset($_REQUEST['deleteFile']) && ! isset($_REQUEST['deleteTheme']) && ! isset($_REQUEST['deletePlugin']) && ! isset($_REQUEST['token'])) return;
		if ($_REQUEST['token'] != wCMS::_generateToken()) return;
		$fileList = array_slice(scandir(__DIR__ . '/files/'), 2);
		foreach ($fileList as $file) {
			if ($_REQUEST['deleteFile'] == $file) { wCMS::_recursiveDelete(__DIR__ . '/files/' . $file); wCMS::alert('success', 'File removed.'); wCMS::redirect(); }
		}
		$fileImagesList = array_slice(scandir(__DIR__ . '/files/images/'), 2);
		foreach ($fileImagesList as $fileImage) {
			if ($_REQUEST['deleteFile'] == $fileImage) { wCMS::_recursiveDelete(__DIR__ . '/files/images/' . $fileImage); wCMS::alert('success', 'File removed.'); wCMS::redirect(); }
		}
		$fileDocsList = array_slice(scandir(__DIR__ . '/files/docs/'), 2);
		foreach ($fileDocsList as $fileDoc) {
			if ($_REQUEST['deleteFile'] == $fileDoc) { wCMS::_recursiveDelete(__DIR__ . '/files/docs/' . $fileDoc); wCMS::alert('success', 'File removed.'); wCMS::redirect(); }
		}
		$themeList = array_slice(scandir(__DIR__ . '/themes'), 2);
		foreach ($themeList as $theme) {
			if (wCMS::get('config','theme') == $_REQUEST['deleteTheme']) { wCMS::alert('danger', 'Cannot remove currently active theme.'); wCMS::redirect(); }
			elseif ($_REQUEST['deleteTheme'] == $theme) { wCMS::_recursiveDelete(__DIR__ . '/themes/' . $theme . '/'); wCMS::alert('success', 'Theme removed.'); wCMS::redirect(); }
		}
		$pluginList = array_slice(scandir(__DIR__ . '/plugins'), 2);
		foreach ($pluginList as $plugin) {
			if ($_REQUEST['deletePlugin'] == $plugin) { wCMS::_recursiveDelete(__DIR__ . '/plugins/' . $plugin . '/'); wCMS::alert('success', 'Plugin removed.'); wCMS::redirect(); }
		}
	}
	public static function _backUp() {
		if ( ! wCMS::$loggedIn) return;
		$backups = glob(__DIR__ . '/files/backup-*.zip');
		if ( ! empty($backups)) {
			$backups = implode(' and ', array_map('basename', $backups));
			wCMS::alert('danger', 'Remove backup files. (<i>Settings -> Files -> Remove files</i>)');
		}
		$backup = 'backup-' . date('Y-m-d-') . substr(md5(microtime()), rand(0, 26), 5) . '.zip';
		if ( ! isset($_POST['backup'])) return;
		if ($_POST['token'] == wCMS::_generateToken()) {
			if (wCMS::zipBackUp(__DIR__, __DIR__ . '/files/' . $backup) !== false) wCMS::redirect('files/'.$backup);
		}
	}
	public static function zipBackUp($source, $destination) {
		if (extension_loaded('zip')) {
			if (file_exists($source)) {
				$zip = new ZipArchive();
				if ($zip->open($destination, ZIPARCHIVE::CREATE)) {
					$source = realpath($source);
					if (is_dir($source)) {
						$iterator = new RecursiveDirectoryIterator($source);
						$iterator->setFlags(RecursiveDirectoryIterator::SKIP_DOTS);
						$files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST);
						foreach ($files as $file) {
							$file = realpath($file);
							if (is_dir($file)) {
								$zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
							} else if (is_file($file)) {
								$zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
							}
						}
					} else if (is_file($source)) {
						$zip->addFromString(basename($source), file_get_contents($source));
					}
				}
				return $zip->close();
			}
		}
		return false;
	}
	public static function _createDatabase() {
		if (wCMS::db() !== false) return;
		wCMS::save([
			'config' => [
				'dbVersion' => '2.3.0',
				'siteTitle' => 'Website title',
				'theme' => 'default',
				'defaultPage' => 'home',
				'login' => 'loginURL',
				'password' => password_hash('admin', PASSWORD_DEFAULT),
				'menuItems'=> [
					'0'=> [
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
				'home' => [
					'title' => 'Home',
					'keywords' => 'Keywords, are, good, for, search, engines',
					'description' => 'A short description is also good.',
					'content' => '<h1>It\'s alive!</h1>

<h4>Welcome to your WonderCMS powered website.</h4>
<p><a href="' . wCMS::url('loginURL') . '">Click here to login, the password is <b>admin</b>.</a></p>'
				],
				'example' => [
					'title' => 'Example',
					'keywords' => 'Keywords, are, good, for, search, engines',
					'description' => 'A short description is also good.',
					'content' => '<h3>How to create new pages</h3>
<p><i>Settings -> General -> Add page</i></p>

<h3>How to edit anything</h3>
<p>Click anywhere inside the gray dashed area to edit. Click outside the area to save.</p>

<h3>How to install/update themes and plugins</h3>
<p>1. Copy link/URL to ZIP file.</p>
<p>2. Paste link in <i>Settings -> Themes and plugins</i> and click <i>Install/update</i>.</p>
<p><a href="https://github.com/robiso/wondercms-themes#list-of-approved-themes" target="_blank">WonderCMS themes</a> | <a href="https://github.com/robiso/wondercms-plugins#approved-plugins" target="_blank">WonderCMS plugins</a></p>'
				]
			],
			'blocks' => [
				'footer' => [
					'content' => '&copy;' . date('Y') . ' Your website'
				],
				'subside' => [
					'content' => '<h3>About your website</h3>

<p>Photo, website description, contact information, mini map or anything else.</p>
<p>This content is static and visible on all pages.</p>'
				]
			]
		]);
	}
}
wCMS::init();
