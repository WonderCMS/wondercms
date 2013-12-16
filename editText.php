<?php
	session_start();
	
	$fieldname = $_REQUEST['fieldname'];
	$encrypt_pass = @file_get_contents('files/password');
	if($_SESSION['l']!=$encrypt_pass){
		echo 'Please login first. ;)';
		exit;
	}
	
	$content = trim(rtrim(stripslashes($_REQUEST['content'])));

	$file = @fopen("files/$fieldname", "w");
	if(!$file){
		echo "<h2>Unable to open $fieldname</h2>".
		"Set correct permissions (755) to the 'files' folder.<br />
		<a href='http://wondercms.com/forum'>For support, click here.</a>";
		exit;
	}

	fwrite($file, $content);
	fclose($file);
	echo $content;
?>
