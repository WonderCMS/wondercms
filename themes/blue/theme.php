<!doctype html>
<html lang="en">
<head>
<?php
	echo "	<script src='//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js'></script>
	<title>".$c['title']." - ".$c['page']."</title>
	<meta charset='utf-8'>
	<meta name='description' content='".$c['description']."'>
	<meta name='keywords' content='".$c['keywords']."'>
	<link rel='stylesheet' href='themes/".$c['themeSelect']."/style.css'>";
	editTags();
?>
</head>
<body<?php if(is_loggedin())echo ' id="admin"';?>>
	<header>
		<nav id="nav">
			<h1 id="main-title"><a href='./'><?php echo $c['title'];?></a></h1>
			<ul>
				<?php menu("<li><a","</a></li>");?>
			</ul>
		</nav>
	</header>

	<?php if(is_loggedin()) settings();?>

	<div class="clear"></div>
	<div id="wrapper">
			<?php content($c['page'],$c['content']);?>
	</div>
	
	<div id="side">
			<?php content('subside',$c['subside']);?>
	</div>

	<div class="clear"></div>
	<footer><p><?php echo $c['copyright'] ." | $sig | $lstatus";?></p></footer>
</body>
</html>
