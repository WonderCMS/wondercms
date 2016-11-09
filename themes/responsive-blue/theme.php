<!doctype html>
<html>
	<head>
	<?php echo "	<base href='$wCMS->host'>
		<meta charset='utf-8'>
		<meta http-equiv='X-UA-Compatible' content='IE=edge'>
		<meta name='viewport' content='width=device-width, initial-scale=1'>
		<title>".$wCMS->config->title." - ".$wCMS->config->currentPage."</title>
		<meta name='keywords' content='".$wCMS->config->pageMeta->{$wCMS->config->currentPage}->keywords."'>
		<meta name='description' content='".$wCMS->config->pageMeta->{$wCMS->config->currentPage}->description."'>
		<link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css'>
		<link rel='stylesheet' href='".$wCMS->host."themes/".$wCMS->config->themeSelect."/style.css'>";
	?>

	</head>
	<body>
		<?php if(isLoggedIn()) settings();?>

		<nav class="navbar navbar-default">
			<div class="container">
				<div class="col-sm-5 text-center">
					<div class="navbar-header">
						<button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#navMobile">&#9776;</button>
						<a href="./"><h1><?php echo $wCMS->config->title;?></h1></a>
					</div>
				</div>
				<div class="col-sm-7 text-center">
					<div class="collapse navbar-collapse" id="navMobile">
						<ul class="nav navbar-nav navbar-right">
							<?php menu(); ?>
						</ul>
					</div>
				</div>
			</div>
		</nav>

		<div class="container">
			<div class="col-xs-12 col-sm-8">
				<div class="whiteBackground grayFont padding20 rounded5">
					<?php content ($wCMS->config->currentPage, $wCMS->config->content);?>

				</div>
			</div>
			
			<div class="col-xs-12 col-sm-4">
				<div class="visible-xs spacer20"></div>
				<div class="blueBackground padding20 rounded5">
					<?php content ('subside', $wCMS->config->subside); ?>

				</div>
			</div>
		</div>

		<footer class="container-fluid">
			<div class="padding20 text-right">
				<?php echo $wCMS->config->copyright . $loginStatus; ?>

			</div>
		</footer>

		<script src="https://code.jquery.com/jquery-1.12.4.min.js" integrity="sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ=" crossorigin="anonymous"></script>
		<script src="https://cdn.jsdelivr.net/jquery.autosize/3.0.17/autosize.min.js"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
		<?php editTags(); ?>

	</body>
</html>
