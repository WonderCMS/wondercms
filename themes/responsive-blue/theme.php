<!doctype html>
<html lang="en">
<head>
<?php
	echo "	<meta charset='utf-8'>
	<title>".$c['title']." - ".$c['page']."</title>
	<base href='$host'>
	<meta name='viewport' content='width=device-width, initial-scale=1'>
	<link rel='stylesheet' href='themes/".$c['themeSelect']."/style.css'>
	<meta name='description' content='".$c['description']."'>
	<meta name='keywords' content='".$c['keywords']."'>
	<script src='//ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js'></script>";
	editTags();
?>

</head>
<body>
	<nav id="nav">
		<h1><a href='./'><?php echo $c['title'];?></a></h1>
		<?php menu(); ?>
		<div class="clear"></div>
	</nav>
	<?php if(is_loggedin()) settings();?>

	<div id="wrapper" class="border">
		<div class="pad">
			<?php content($c['page'],$c['content']);?>

		</div>
	</div>
	
	<div id="side" class="border">
		<div class="pad">
			<?php content('subside',$c['subside']);?>

		</div>
	</div>

	<div class="clear"></div>
	<footer><p><?php echo $c['copyright'] ." | $sig | $lstatus";?></p></footer>
</body>
</html>
