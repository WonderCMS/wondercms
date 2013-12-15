<?php
session_start();

$password = @file_get_contents('files/password');
if(!$password){
	$password = savePassword('admin');
}

$loggedin=false;
if($_SESSION['l']==$password)$loggedin = true;

$page = $_REQUEST['page'];
if(!$page) $page = 'home';
$contentfile = $page = getSlug($page);
$cleanname = str_replace('-',' ',$page);

$content[0] = @file_get_contents("files/$contentfile.txt");
if(!$content[0])switch($page){
	case 'home':	
	$content[0] = "<h3>Congratulations! Your website is now powered by WonderCMS.</h3><br />\nLogin to the admin panel with the 'Login' link in the footer. The default password is admin.<br />\nChange the password as soon as possible.<br /><br />\n\nOnce logged in, click anywhere on the content to edit it, and click outside to save it.<br />\nYou can see more settings at the top of the admin page.<br /><br />\n\nWonderCMS weights only around 14kB. (7kB zipped)";
	break;

	case 'example':
	$content[0] = "This is an example page.<br /><br />\n\nTo add a new page, open settings in the admin panel, click on the existing pages and add a new page one below the others.";
	break;

	default:
		if(is_loggedin()){
			$content[0] = "Page <b>$cleanname</b> created.<br /><br />\n\nClick here to start editing!";
		}
		else{
			$content[0] = "Sorry, but <b>$cleanname</b> doesn't exist.<br /><br /><b style='font-size: 50px;'>☹</b>";
		}
	}

$themeSelect = @file_get_contents('files/themeSelect.txt');
if(!$themeSelect) $themeSelect = 'blue';
$menu = @file_get_contents('files/menu.txt');
if(!$menu) $menu = "Home<br />\nExample";

$title = @file_get_contents('files/title.txt');
if(!$title) $title = 'Website title';

$subside = @file_get_contents('files/subside.txt');
if(!$subside) $subside = "<h3>ABOUT YOUR WEBSITE</h3><br />\nThis box can be used for your photo, website description, contact information, mini map or anything else.<br /><br />\n\n The content is static and visible on all pages.";

$description = @file_get_contents('files/description.txt');
if(!$description) $description = 'Enter website description.';

$keywords = @file_get_contents('files/keywords.txt');
if(!$keywords) $keywords = 'Enter, keywords, for, your, website';

$copyright = @file_get_contents('files/copyright.txt');
if(!$copyright) $copyright = '&copy;' . date('Y') . ' ' . $title;

$mess = "Powered by <a href='http://wondercms.com'>WonderCMS</a>";

//config
$hostname = $_SERVER['PHP_SELF'];
$hostname = str_replace('index.php', '', $hostname);
$hostname = str_replace($page, '', $hostname);

$theme = 'theme';
	
if(isset($_REQUEST['logout'])){
	session_destroy();
	header('Location: ./');
	exit;
}

if(is_loggedin()){
	$lstatus = "<a href='$hostname?logout'>Logout</a>";
}
else $lstatus = "<a href='$hostname?login'>Login</a>";

if(isset($_REQUEST['login'])){
	loginForm();
}
require("$theme.php");

//functions
function getSlug($page){
	$page = strip_tags($page);
	preg_match_all('/([a-z0-9A-Z-_]+)/', $page, $matches);
	$matches = array_map('strtolower', $matches[0]);
	$slug = implode('-', $matches);
	return $slug;
}

function is_loggedin(){
	global $loggedin;
	return $loggedin;
}

function editTags(){
	if(!is_loggedin()) return;
	echo "<script type='text/javascript' src='./js/editInplace.js'></script>";
}

function mainContent(){
	global $content, $page;
	
	if(is_loggedin()){
		echo "<span id='$page' class='editText'>$content[0]</span>";
	}
	else{
		echo $content[0];
	}
}

function subContent(){
	global $subside;
	
	if(is_loggedin()){
		echo "<span id='subside' class='editText'>$subside</span>";
	}
	else{
		echo $subside;
	}
}

function menu($stags,$etags){
	global $menu;
	$mlist = explode('<br />',$menu);
	for($i=0;$i<count($mlist);$i++){
		$page = getSlug($mlist[$i]);
		$clean = str_replace('-',' ',$page);
		if(!$page) continue;
		echo "$stags href='$page'>$clean $etags \n";
	}
}
	
function loginForm(){
	global $content, $msg;
	$msg = '';
	if (isset($_POST['sub'])) login();
	$content[0] = "
	<form action='' method='POST'>
	Password <input type='password' name='password' />
	<input type='submit' name='login' value='Login'> $msg
	<script src='js/editInplace.js'></script> 
	<br /><br /><b class='toggle'>Change password</b>
	<div class='hide'><br />Type your old password above and your new one below.<br />
	New Password <input type='password' name='new' />
	<input type='submit' name='login' value='Change'>
	<input type='hidden' name='sub' value='sub'>
	</div>
	</form>";
}
	
function login(){
	global $password, $msg, $submitted_pass;
	$submitted_pass = md5($_POST['password']);
	if ($submitted_pass<>$password)
	{
		$msg = "<b>Wrong Password</b>";
		return;
	}
	if($_POST['new'])
	{
		savePassword($_POST['new']);
		$msg = 'Password changed';
		return;
	}
	$_SESSION['l'] = $password;
	header('Location: ./');
	exit;
}
	
function savePassword($p){
	$password = md5($p);
	$file = @fopen('files/password', 'w');
	if(!$file)
	{
		echo "<h3>Unable to access password</h3>".
			"Set correct permissions (640) to the password file.<br /><br />
			<a href='http://wondercms.com/forum'>For support, click here.</a>";
		exit;
	}
	fwrite($file, $password);
	fclose($file);
	return $password;
}

function settings(){
	global $description, $keywords, $title, $copyright, $menu, $themeSelect;
	echo "<div class='settings'>
	<h3 class='toggle'>↕ Settings ↕</h3>
	<div class='hide'>
	<div class='change'><b>Theme</b>&nbsp;&nbsp;&nbsp;<span id='themeSelect'><select name='themeSelect' onchange='fieldSave(\"themeSelect\",this.value);'>";
	if (chdir("./themes/")) {
		$dirs = glob('*', GLOB_ONLYDIR);
		foreach($dirs as $val){
			$select = ($val == $themeSelect)? ' selected' : ''; 
			echo '<option value="'.$val.'"'.$select.'>'.$val."</option>\n";
		}
	}
	echo "</select></span></div>
	<div class='change'><b>Navigation <small>(hint: add your own page below and <a href='javascript:location.reload(true);'>click here to refresh</a>)</small></b><br /><span id='menu' class='editText'>$menu</span></div>
	<div class='change'><span id='title' class='editText'>$title</span></div>
	<div class='change'><span id='description' class='editText'>$description</span></div>
	<div class='change'><span id='keywords' class='editText'>$keywords</span></div>
	<div class='change'><span id='copyright' class='editText'>$copyright</span></div>
	</div></div>";
}
?>