<?php
session_start();

$hostname = str_replace($_REQUEST['page'], '', str_replace('index.php', '', $_SERVER['PHP_SELF']));
$c['password'] = 'admin';
$c['loggedin'] = false;
$c['page'] = 'Home';
$d['page']['Home'] = "<h3>Congratulations! Your website is now powered by WonderCMS.</h3><br />\nLogin to the admin panel with the 'Login' link in the footer. The default password is admin.<br />\nChange the password as soon as possible.<br /><br />\n\nOnce logged in, click on the content to edit it and click outside to save it.<br /><br />\n\nWonderCMS weights only around 15kB. (8kB zipped)";
$d['page']['Example'] = "This is an example page.<br /><br />\n\nTo add a new one, click on the existing pages (in the admin panel) and enter a new one below the others.";
$d['new_page']['admin'] = "Page <b>".str_replace('-',' ',$_REQUEST['page'])."</b> created.<br /><br />\n\nClick here to start editing!";
$d['new_page']['visitor'] = "Sorry, but <b>".str_replace('-',' ',$_REQUEST['page'])."</b> doesn't exist. :(";
$d['default']['content'] = "Click here to edit!";
$c['themeSelect'] = "blue";
$c['menu'] = "Home<br />\nExample";
$c['title'] = 'Website title';
$c['subside'] = "<h3>ABOUT YOUR WEBSITE</h3><br />\nThis box can contain your photo, website description, contact information, mini map or anything else.<br /><br />\n\n The content is static and visible on all pages.";
$c['description'] = 'Enter website description.';
$c['keywords'] = 'Enter, your, website, keywords.';
$c['copyright'] = '&copy;'. date('Y') .' Your website';
$sig = "Powered by <a href='http://wondercms.com'>WonderCMS</a>";
$hook['admin-richText'] = "rte.php";

foreach($c as $key => $val){
	if($key == 'content')continue;
	$fval = @file_get_contents('files/'.$key);
	$d['default'][$key] = $c[$key];
	if($fval)$c[$key] = $fval;
	switch($key){
		case 'password':
			if(!$fval)$c[$key] = savePassword($val);
			break;
		case 'loggedin':
			if($_SESSION['l']==$c['password'])$c[$key] = true;
			if(isset($_REQUEST['logout'])){
				session_destroy();
				header('Location: ./');
				exit;
			}
			if(isset($_REQUEST['login'])){
				if(is_loggedin())header('Location: ./');
				loginForm();
			}
			$lstatus = (is_loggedin())? "<a href='$hostname?logout'>Logout</a>": "<a href='$hostname?login'>Login</a>";
			break;
		case 'page':
			if($_REQUEST['page'])$c[$key]=$_REQUEST['page'];
			$c[$key] = getSlug($c[$key]);
			if(isset($_REQUEST['login']))continue;
			$c['content'] = @file_get_contents("files/".$c[$key]);
			if(!$c['content']){
				if(!isset($d['page'][$c[$key]])){
						$c['content'] = (is_loggedin())? $d['new_page']['admin']:$c['content'] = $d['new_page']['visitor'];
				}else{
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
	global $hook,$hostname,$c;
	$cwd = getcwd();
	if(chdir("./plugins/")){
		$dirs = glob('*', GLOB_ONLYDIR);
		foreach($dirs as $dir){
			require_once($cwd.'/plugins/'.$dir.'/index.php');
		}
	}
	chdir($cwd);
	$hook['admin-head'][] = "<script type='text/javascript' src='./js/editInplace.php?hook=".$hook['admin-richText']."'></script>";
}

function getSlug($p){
	return $slug;
}

function is_loggedin(){
	global $c;
	return $c['loggedin'];
}

function editTags(){
	global $hook;
	if(!is_loggedin() && !isset($_REQUEST['login'])) return;
	foreach($hook['admin-head'] as $o){
		echo "\t".$o."\n";
	}
}

function content($id,$content){
	global $d;
	echo (is_loggedin())? "<span title='".$d['default']['content']."' id='".$id."' class='editText richText'>".$content."</span>": $content;
}

function menu($stags,$etags){
	global $c;
	$mlist = explode('<br />',$c['menu']);
	for($i=0;$i<count($mlist);$i++){
		$page = getSlug($mlist[$i]);
		if(!$page) continue;
		echo $stags." href='".$page."'>".str_replace('-',' ',$page)." ".$etags." \n";
	}
}
	
function loginForm(){
	global $c, $msg;
	$msg = '';
	if(isset($_POST['sub'])) login();
	$c['content'] = "<form action='' method='POST'>
	Password <input type='password' name='password'>
	<input type='submit' name='login' value='Login'> $msg
	<br /><br /><b class='toggle'>Change password</b>
	<div class='hide'><br />Type your old password above and your new one below.<br />
	New Password <input type='password' name='new'>
	<input type='submit' name='login' value='Change'>
	<input type='hidden' name='sub' value='sub'>
	</div>
	</form>";
}
	
function login(){
	global $c, $msg;
	if(md5($_POST['password'])<>$c['password']){
		$msg = "<b>Wrong Password</b>";
		return;
	}
	if($_POST['new']){
		savePassword($_POST['new']);
		$msg = 'Password changed';
		return;
	}
	$_SESSION['l'] = $c['password'];
	header('Location: ./');
	exit;
}
	
function savePassword($p){
	$file = @fopen('files/password', 'w');
	if(!$file){
		echo "<h3>Unable to access password</h3>".
			"Set correct permissions (640) to the password file (in the 'files' directory).<br /><br />
			<a href='http://wondercms.com/forum'>For support, click here.</a>";
		exit;
	}
	fwrite($file, md5($p));
	fclose($file);
	return md5($p);
}

function settings(){
	global $c,$d;
	echo "<div class='settings'>
	<h3 class='toggle'>↕ Settings ↕</h3>
	<div class='hide'>
	<div class='change'><b>Theme</b>&nbsp;<span id='themeSelect'><select name='themeSelect' onchange='fieldSave(\"themeSelect\",this.value);'>";
	if(chdir("./themes/")){
		$dirs = glob('*', GLOB_ONLYDIR);
		foreach($dirs as $val){
			$select = ($val == $c['themeSelect'])? ' selected' : ''; 
			echo '<option value="'.$val.'"'.$select.'>'.$val."</option>\n";
		}
	}
	echo "</select></span></div>
	<div class='change'><b>Navigation <small>(hint: add your own page below and <a href='javascript:location.reload(true);'>click here to refresh</a>)</small></b><br /><span id='menu' title='Home' class='editText'>".$c['menu']."</span></div>";
	foreach(array('title','description','keywords','copyright') as $key){
		echo "<div class='change'><span title='".$d['default'][$key]."' id='".$key."' class='editText'>".$c[$key]."</span></div>";
	}
	echo "</div></div>";
}
?>
