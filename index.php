<?php // v0.9.8 WonderCMS • wondercms.com • license: MIT

@ini_set('session.cookie_httponly', 1);
@ini_set('session.use_only_cookies', 1);
@ini_set('session.cookie_secure', 1);

session_start();

if (file_exists('config.php')) {
	include 'config.php';
	$wCMS = json_decode(urldecode($wCMS));
} else {
	$wCMS = new stdClass();
	$wCMS->config = new stdClass();
	$wCMS->config->newPage = new stdClass();
	$wCMS->config->pageMeta = new stdClass();

	$wCMS->config->loggedIn = false;
	$wCMS->config->loginURL = "loginURL";
	$wCMS->config->password = password_hash('admin', PASSWORD_DEFAULT, ['cost' => 11]); 
	$wCMS->config->page = "home";
	$wCMS->config->themeSelect = "responsive-blue";
	$wCMS->config->menu = "home<br />\nexample";
	$wCMS->config->title = "Website title";
	$wCMS->config->home = "<h3>It's alive! Your website is now powered by WonderCMS.</h3><br />\n<a href='?" . $wCMS->config->loginURL . "'>Click here to login (password = <b>admin</b>).</a><br /><br />\n\nClick on content to edit it, click outside to save it.<br />\nImportant: change your password as soon as possible.";
	$wCMS->config->example = "Example page.<br /><br />\n\nTo add a new page, click on the existing pages in settings panel and enter a new one.";
	$wCMS->config->subside = "<h3>ABOUT YOUR WEBSITE</h3><br />\nYour photo, website description, contact information, mini map or anything else.<br /><br />\n\nThis content is static and always visible.";
	$wCMS->config->description = "Page description, unique for each page.";
	$wCMS->config->keywords = "Page, keywords, unique, for, each, page.";
	$wCMS->config->copyright = '&copy;' . date('Y') . ' Your website';
	$wCMS->config->newPage->admin = "Click here to create some content.<br /><br />\n\nOnce you do that, this page will be eventually visited by search engines.";
	$wCMS->config->newPage->visitor = "Sorry, page doesn't exist. :(";

	save($wCMS);
}

host();
edit();

$plugin['admin-richTextEditor'] = "richTextEditor.php";

if (!is_dir('plugins')) mkdir('plugins', 0755, true);
if (file_exists('functions.php')) include 'functions.php';

foreach ($wCMS->config as $key => $val) {
	switch ($key) {
		case 'loggedIn':
			$wCMS->config->$key = (isset($_SESSION['l']) && ($_SESSION['l'] == $wCMS->config->password)) ? true : false;

			if (isset($_REQUEST[$wCMS->config->loginURL])) {
				if (isLoggedIn() && !isset($_POST['new'])) header('Location: ./');
				if (isset($_POST['sub'])) login();
				$wCMS->config->content = "
				<form action='' method='POST'>
					<div class='col-xs-5'><div class='form-group'><input class='form-control' type='password' name='password'></div></div>
					<button class='btn btn-info' type='submit' name='" . $wCMS->config->loginURL . "'>Login</button>
					<input type='hidden' name='sub' value='sub'>
				</form>";
			}

			$loginStatus = (isLoggedIn()) ? " • Powered by <a href='http://wondercms.com'>WonderCMS</a> • <a href='$wCMS->host?logout'>Logout</a>" : " • Powered by <a href='http://wondercms.com'>WonderCMS</a> • <a href='$wCMS->host?" . $wCMS->config->loginURL . "'>Login</a>";
			if ($wCMS->config->loginURL != "loginURL" && !isLoggedIn()) $loginStatus = " • Powered by <a href='http://wondercms.com'>WonderCMS</a>";
			break;

		case 'page':
			if (isset($_REQUEST[$wCMS->config->loginURL])) continue;

			if (isset($_REQUEST['logout'])) {
				session_destroy();
				header('Location: ./');
				exit;
			}

			if (isset($_REQUEST['delete']) && !empty($_REQUEST['delete'])) {
				if (isLoggedIn()) {
					deletePage($_GET['delete']);
					header('Location: ./');
					exit;
				} else {
					header('Location: ./');
					exit;
				}
			}

			if (strpos($_SERVER['REQUEST_URI'], 'password') !== false || strpos($_SERVER['REQUEST_URI'], 'PASSWORD') !== false)  {
				header('Location: ./');
				exit;
			}

			if (!isset($wCMS->config->content)) {
				if (empty($wCMS->config->menu)) $wCMS->config->menu = "undeletable page";
				if (!isset($wCMS->config->{$wCMS->config->currentPage})) {
					header('HTTP/1.1 404 Not Found');
					if (isLoggedIn()) echo "<div class='alert alert-danger' role='alert' style='margin-bottom: 0;'><b>This page (" . $wCMS->config->currentPage . ") doesn't exist yet.</b> Click inside the content below and make your first edit to create it.</div>";
					$wCMS->config->content = (isLoggedIn()) ? $wCMS->config->newPage->admin : $wCMS->config->newPage->visitor;
			} else {
					$wCMS->config->content = $wCMS->config->{$wCMS->config->currentPage};
				}
			}
			break;

		default:
			break;
	}
}

loadPlugins();
require("themes/" . $wCMS->config->themeSelect . "/theme.php");

function loadPlugins() {
	global $wCMS, $plugin;
	$cwd = getcwd();
	if (chdir("./plugins/")) {
		$dirs = glob('*', GLOB_ONLYDIR);
		if (is_array($dirs))
			foreach ($dirs as $dir) {
				require_once($cwd . '/plugins/' . $dir . '/index.php');
			}
	}

	chdir($cwd);
	$plugin['admin-head'][] = "\n\t\t<script src='".$wCMS->host."js/editInplace.php?page=" . $wCMS->config->currentPage . "&plugin=" . $plugin['admin-richTextEditor'] . "'></script>";
}

function getSlug($p) {
	return mb_convert_case(preg_replace('!\s+!', '-', $p), MB_CASE_LOWER, "UTF-8");
}

function isLoggedIn() {
	global $wCMS;
	return $wCMS->config->loggedIn;
}

function editTags() {
	global $plugin, $wCMS;
	if (!isLoggedIn() && !isset($_REQUEST[$wCMS->config->loginURL])) return;
	foreach ($plugin['admin-head'] as $o) {
		echo $o . "\n";
	}
}

function content($id, $content) {
	global $wCMS;
	echo (isLoggedIn()) ? "<span id='" . $id . "' class='editText richTextEditor'>\n" . $content . "</span>" : $content;
}

function edit() {
	global $wCMS, $metaTags;
	$metaTags = ["keywords", "description"];
	$fieldname = isset($_REQUEST['fieldname']) ? $_REQUEST['fieldname'] : false;
	$content = isset($_REQUEST['content']) ? trim(stripslashes($_REQUEST['content'])) : false;
	if ($fieldname && $content) {
		if (!isset($_SESSION['l']) && ($_SESSION['l'] != $wCMS->config->password)) {
			header('HTTP/1.1 401 Unauthorized');
			exit;
		}
		if (in_array($fieldname, $metaTags)) {
			if (!isset($wCMS->config->pageMeta->{$wCMS->config->currentPage})) $wCMS->config->pageMeta->{$wCMS->config->currentPage} = new stdClass();
			$pageMeta = $wCMS->config->pageMeta->{$wCMS->config->currentPage};
			$pageMeta->$fieldname = $content;
		} else {
			$wCMS->config->$fieldname = $content;
		}

		$wCMS->config->menu = mb_convert_case($wCMS->config->menu, MB_CASE_LOWER, "UTF-8");

		save($wCMS);
		echo $content;
		exit;
	}
}

function menu() {
	global $wCMS;
	$mlist = explode("<br />\n", $wCMS->config->menu);
	foreach ($mlist as $cp) { ?>
		<li<?php if ($wCMS->config->currentPage == getSlug($cp)) echo " id='active'"; ?>><a href='<?php echo $wCMS->host . getSlug($cp); ?>'><?php echo $cp; ?></a></li>
	<?php }
}

function login() {
	global $wCMS;
	if (password_verify($_POST['password'], $wCMS->config->password)) {
		if ($_POST['new']) {
			if (isLoggedIn()) {
				echo "<script>alert('Password changed. Login with your new password.'); window.location = '?" . $wCMS->config->loginURL . "';</script>";
				savePassword($_POST['new']);
				exit;
			} else {
				exit;
			}
		}

		$_SESSION['l'] = $wCMS->config->password;
		header('Location: ./');
		exit;
	} else {
		echo "<script>alert('Wrong password.'); window.location = window.location.href;</script>";
		exit;
	}
}

function savePassword($password) {
	global $wCMS;
	$wCMS->config->password = password_hash($password, PASSWORD_DEFAULT, ['cost' => 11]);
	session_destroy();
	save($wCMS);
}

function save($data) {
	unset($data->n);
	file_put_contents('config.php', '<?php $wCMS="' . urlencode(json_encode($data)) . '"; ?>');
}

function deletePage($page) {
	global $wCMS;
	unset($wCMS->config->pageMeta->{$page});
	unset($wCMS->config->{$page});
	$page = str_replace('-', ' ', $page);
	unset($wCMS->config->{$page});

	$e = explode("<br />\n", $wCMS->config->menu);
	$index = array_search($page, $e);
	if ($index !== false) {
		unset($e[$index]);
		$wCMS->config->menu = implode("<br />\n", $e);
	}
	save($wCMS);
}

function host() {
	global $wCMS;
	$req = isset($_REQUEST['page']) ? $_REQUEST['page'] : '';
	$rp = preg_replace('#/+#', '/', (isset($req)) ? urldecode($req) : '');
	$host = htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES);
	$uri = preg_replace('#/+#', '/', urldecode($_SERVER['REQUEST_URI']));
	$host = (strrpos($uri, $rp) !== false) ? $host . '/' . substr($uri, 0, strlen($uri) - strlen($rp)) : $host . '/' . $uri;
	$host = explode('?', $host);
	$host = '//' . str_replace('//', '/', $host[0]);
	$strip = array('index.php', '?', '"', '\'', '>', '<', '=', '(', ')', '\\','..','%3C','%3E','&gt;','&lt');
	$rp = strip_tags(str_replace($strip, '', $rp));
	$host = strip_tags(str_replace($strip, '', $host));

	$wCMS->host = $host;
	$wCMS->requestedPage = $rp;
	$wCMS->config->currentPage = getSlug(($wCMS->requestedPage) ? $wCMS->requestedPage : $wCMS->config->page);
}

function metaTags($key) {
	global $wCMS;
	if (isset($wCMS->config->pageMeta->{$wCMS->config->currentPage}->$key)) {
		return $wCMS->config->pageMeta->{$wCMS->config->currentPage}->$key;
	} else {
		return $wCMS->config->$key;
	}
}

function settings() {
	global $wCMS;
	if ($wCMS->config->loginURL == 'loginURL' && isLoggedIn()) echo "<div class='alert alert-danger' role='alert' style='margin-bottom: 0;'><b>Protect your website:</b> change your default login URL and password.</div>";
	echo "<style>#adminPanel{color:#fff;background:#1ab}.grayFont{color: #444;}span.editText,.toggle{display:block;cursor:pointer}span.editText textarea{border:0;width:100%;resize:none;color:inherit;font-size:inherit;font-family:inherit;background-color:transparent;overflow:hidden;box-sizing:content-box;}#save{left:0;width:100%;height:100%;display:none;position:fixed;text-align:center;padding-top:100px;background:rgba(51,51,51,.8);z-index:2448}.change{margin:5px 0;padding:20px;border-radius:5px;background:#1f2b33}.marginTop20{margin-top:20px;}</style>
		<div id='save'><h2>Saving...</h2></div>
		<div id='adminPanel' class='container-fluid'>
			<div class='padding20 toggle text-center' data-toggle='collapse' data-target='#settings'>&#9776;</div>
			<div class='col-xs-12 col-sm-8 col-sm-offset-2'>
				<div id='settings' class='collapse text-left'>
					<a href='?delete=" . $wCMS->config->currentPage . "' class='btn btn-danger' onclick='return confirm(\"Really delete page?\");' type='button'>Delete current page (".$wCMS->config->currentPage.")</a><div class='marginTop20'></div>
						<div class='form-group'><select class='form-control' name='themeSelect' onchange='fieldSave(\"themeSelect\",this.value);'>";
						if(chdir('./themes/')){
							$dirs = glob('*', GLOB_ONLYDIR);
							foreach($dirs as $val){
								$select = ($val == $wCMS->config->themeSelect) ? ' selected' : '';
								echo "
								<option value='".$val."'".$select.">".$val.'</option>';
							}
						}
						echo "
						</select></div>";
				foreach (['description', 'keywords','title','copyright'] as $key) {
					echo "
					<div class='change'>
						<span id='" . $key . "' class='editText'>" . metaTags($key) . "</span>
					</div>";
				}
				echo "
					<div class='change marginTop20'>
						<b style='font-size: 22px; text-align: right' class='glyphicon glyphicon-info-sign' aria-hidden='true' data-toggle='tooltip' data-placement='right' title='Menu: enter a new page name in a new line.'></b>
						<span id='menu' class='editText'>" . $wCMS->config->menu . "</span>
					</div>
					<div class='change'>
						<b style='font-size: 22px;' class='glyphicon glyphicon-info-sign' aria-hidden='true' data-toggle='tooltip' data-placement='right' title='Default homepage: to make another page your default homepage, rename this to another existing page.'></b>
						<span id='page' class='editText'>" . $wCMS->config->page . "</span>
					</div>
					<div class='change'>
						<span id='loginURL' class='editText'>" . $wCMS->config->loginURL . "</span>
						<br />Current login URL (once you change it, bookmark it):<br />http:" . $wCMS->host . "?<b>" . $wCMS->config->loginURL . "</b>
					</div>
					<div class='change grayFont marginTop20'>
						<form action='' method='POST'>
							<div class='form-group'><input class='form-control' type='password' name='password' placeholder='Old password'></div>
							<div class='form-group'><input class='form-control' type='password' name='new' placeholder='New password'></div>
							<button class='btn btn-info' type='submit' name='" . $wCMS->config->loginURL . "'>Change password</button>
							<input type='hidden' name='sub' value='sub'>
						</form>
					</div>
					<div class='padding20 toggle text-center' data-toggle='collapse' data-target='#settings'>Close settings</div>
				</div>
			</div>
		</div>";
}
?>
