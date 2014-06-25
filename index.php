<?php
ob_start();
ini_set('session.cookie_httponly', 1);
session_start();
host();
edit();

$c['password'] = 'admin';
$c['loggedin'] = false;
$c['page'] = 'home';
$d['page']['home'] = "<h3>It's alive! Your website is now powered by WonderCMS.</h3><br />\nLogin with the 'Login' link below. The password is admin.<br />\nChange the password as soon as possible.<br /><br />\n\nClick on the content to edit and click outside to save it.<br /><br />\n\nWonderCMS weights only around 15kB. (8kB zipped)";
$d['page']['example'] = "This is an example page.<br /><br />\n\nTo add a new one, click on the existing pages (in the admin panel) and enter a new one below the others.";
$d['new_page']['admin'] = "Page <sb>".$rp."</b> created.<br /><br />\n\nClick here to start editing!";
$d['new_page']['visitor'] = "Sorry, but <b>".$rp."</b> doesn't exist. :(";
$d['default']['content'] = 'Click to edit!';
$c['themeSelect'] = 'responsive-blue';
$c['menu'] = "Home<br />\nExample";
$c['title'] = 'Website title';
$c['subside'] = "<h3>ABOUT YOUR WEBSITE</h3><br />\nYour photo, website description, contact information, mini map or anything else.<br /><br />\n\n This content is static and is visible on all pages.";
$c['description'] = 'Your website description.';
$c['keywords'] = 'enter, your website, keywords';
$c['copyright'] = '&copy;'.date('Y').' Your website';
$sig = "Powered by <a href='http://wondercms.com'>WonderCMS</a>";
$hook['admin-richText'] = "rte.php";

if(!file_exists('files')){
	mkdir('files', 0755, true);
	mkdir('plugins', 0755, true);
}

foreach($c as $key => $val){
	if($key == 'content') continue;
	$fval = @file_get_contents('files/'.$key);
	$d['default'][$key] = $c[$key];
	if($fval)
		$c[$key] = $fval;
	switch($key){
		case 'password':
			if(!$fval)
				$c[$key] = savePassword($val);
			break;
		case 'loggedin':
			if(isset($_SESSION['l']) and $_SESSION['l'] == $c['password'])
				$c[$key] = true;
			if(isset($_REQUEST['logout'])){
				session_destroy();
				header('Location: ./');
				exit;
			}
			if(isset($_REQUEST['login'])){
				if(is_loggedin())
					header('Location: ./');
				$msg = '';
				if(isset($_POST['sub']))
					login();
				$c['content'] = "<form action='' method='POST'>
				<input type='password' name='password'>
				<input type='submit' name='login' value='Login'> $msg
				<p class='toggle'>Change password</p>
				<div class='hide'>Type your old password above and your new one below.<br />
				<input type='password' name='new'>
				<input type='submit' name='login' value='Change'>
				<input type='hidden' name='sub' value='sub'>
				</div>
				</form>";
			}
			$lstatus = (is_loggedin()) ? "<a href='$host?logout'>Logout</a>" : "<a href='$host?login'>Login</a>";
			break;
		case 'page':
			if($rp)
				$c[$key] = $rp;
			$c[$key] = getSlug($c[$key]);
			if(isset($_REQUEST['login'])) continue;
			$c['content'] = @file_get_contents("files/".$c[$key]);
			if(!$c['content']){
				if(!isset($d['page'][$c[$key]])){
					header('HTTP/1.1 404 Not Found');
					$c['content'] = (is_loggedin()) ? $d['new_page']['admin'] : $c['content'] = $d['new_page']['visitor'];
				} else{
					$c['content'] = $d['page'][$c[$key]];
				}
			}
			break;
		default:
			break;
	}
}
loadPlugins();

require("themes/".$c['themeSelect']."/theme.php");

function loadPlugins(){
	global $hook, $c;
	$cwd = getcwd();
	if(chdir("./plugins/")){
		$dirs = glob('*', GLOB_ONLYDIR);
		if(is_array($dirs))
			foreach($dirs as $dir){
				require_once($cwd.'/plugins/'.$dir.'/index.php');
			}
	}
	chdir($cwd);
	$hook['admin-head'][] = "\n	<script type='text/javascript' src='./js/editInplace.php?hook=".$hook['admin-richText']."'></script>";
}

function getSlug($p){
	return mb_convert_case(str_replace(' ', '-', $p), MB_CASE_LOWER, "UTF-8");
}

function is_loggedin(){
	global $c;
	return $c['loggedin'];
}

function editTags(){
	global $hook;
	if(!is_loggedin() && !isset($_REQUEST['login']))
		return;
	foreach($hook['admin-head'] as $o){
		echo "\t".$o."\n";
	}
}

function content($id, $content){
	global $d;
	echo (is_loggedin()) ? "<span title='".$d['default']['content']."' id='".$id."' class='editText richText'>".$content."</span>" : $content;
}

function edit(){
	if(isset($_REQUEST['fieldname'], $_REQUEST['content'])){
		$fieldname = $_REQUEST['fieldname'];
		$content = trim(rtrim(stripslashes($_REQUEST['content'])));
		if(!isset($_SESSION['l'])){
			header('HTTP/1.1 401 Unauthorized');
			exit;
		}
		$file = @fopen("files/$fieldname", "w");
		if(!$file){
			echo 'Set 755 permission to the files folder.';
			exit;
		}
		fwrite($file, $content);
		fclose($file);
		echo $content;
		exit;
	}
}

function menu(){
	global $c, $host;
	$mlist = explode("<br />\n", $c['menu']);
	?><ul>
	<?php
	foreach ($mlist as $cp){?>
			<li<?php if($c['page'] == getSlug($cp)) echo ' id="active" '; ?>><a href='<?php echo getSlug($cp); ?>'><?php echo $cp; ?></a></li>
	<?php } ?>
	</ul>
<?php
}

function login(){
	global $c, $msg;
	if(md5($_POST['password']) <> $c['password']){
		$msg = 'wrong password';
		return;
	}
	if($_POST['new']){
		savePassword($_POST['new']);
		$msg = 'password changed';
		return;
	}
	$_SESSION['l'] = $c['password'];
	header('Location: ./');
	exit;
}

function savePassword($p){
	$file = @fopen('files/password', 'w');
	if(!$file){
		echo 'Set 644 permission to the password file.';
		exit;
	}
	fwrite($file, md5($p));
	fclose($file);
	return md5($p);
}

function host(){
	global $host, $rp;
	$rp = preg_replace('#/+#', '/', (isset($_REQUEST['page'])) ? urldecode($_REQUEST['page']) : '');
	$host = $_SERVER['HTTP_HOST'];
	$uri = preg_replace('#/+#', '/', urldecode($_SERVER['REQUEST_URI']));
	$host = (strrpos($uri, $rp) !== false) ? $host.'/'.substr($uri, 0, strlen($uri) - strlen($rp)) : $host.'/'.$uri;
	$host = explode('?', $host);
	$host = '//'.str_replace('//', '/', $host[0]);
	$strip = array('index.php','?','"','\'','>','<','=','(',')','\\');
	$rp = strip_tags(str_replace($strip, '', $rp));
	$host = strip_tags(str_replace($strip, '', $host));
}

function settings(){
	global $c, $d;
	echo "<div class='settings'>
	<h3 class='toggle'>↕ Settings ↕</h3>
	<div class='hide'>
	<div class='change border'><b>Theme</b>&nbsp;<span id='themeSelect'><select name='themeSelect' onchange='fieldSave(\"themeSelect\",this.value);'>";
	if(chdir("./themes/")){
		$dirs = glob('*', GLOB_ONLYDIR);
		foreach($dirs as $val){
			$select = ($val == $c['themeSelect']) ? ' selected' : '';
			echo '<option value="'.$val.'"'.$select.'>'.$val."</option>\n";
		}
	}
	echo "</select></span></div>
	<div class='change border'><b>Menu <small>(add a page below and <a href='javascript:location.reload(true);'>refresh</a>)</small></b><span id='menu' title='Home' class='editText'>".$c['menu']."</span></div>";
	foreach(array('title','description','keywords','copyright') as $key){
		echo "<div class='change border'><span title='".$d['default'][$key]."' id='".$key."' class='editText'>".$c[$key]."</span></div>";
	}
	echo "</div></div>";
}
ob_end_flush();
?>
