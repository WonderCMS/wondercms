<!doctype html>
<html lang="en">
<head>
<?php
	echo "	<script src='//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js'></script>
	<title>$title - $page</title>
	<meta charset='utf-8'>
	<meta name='description' content='$description'>
	<meta name='keywords' content='$keywords'>
	<meta name='generator' content='WonderCMS'>
	<link rel='stylesheet' href='themes/$themeSelect/style.css'>";
	editTags();
?>
</head>
<body>
	<header>
		<nav id="nav">
			<h1 id="main-title"><a href='./'><?php echo $title;?></a></h1>
			<ul>
				<?php menu("<li><a","</a></li>");?>
			</ul>
		</nav>
	</header>

	<?php if(is_loggedin()) settings();?>

	<div class="clear"></div>
	<div id="wrapper">
			<?php mainContent();?>
	</div>
	
	<div id="side">
			<?php subContent();?>
	</div>

	<div class="clear"></div>
	<footer><p><?php echo "$copyright | $mess | $lstatus";?></p></footer>
</body>
</html>