<?php global $Wcms ?>

<!DOCTYPE html>
<html lang="en">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">

		<title><?= $Wcms->get('config', 'siteTitle') ?> - <?= $Wcms->page('title') ?></title>
		<meta name="description" content="<?= $Wcms->page('description') ?>">
		<meta name="keywords" content="<?= $Wcms->page('keywords') ?>">

		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
		<?= $Wcms->css() ?>
		<link rel="stylesheet" href="<?= $Wcms->asset('css/style.css') ?>">
	</head>

	<body>
		<?= $Wcms->settings() ?>
		<?= $Wcms->alerts() ?>

		<nav class="navbar navbar-expand-lg navbar-light navbar-default">
			<div class="container">
				<a class="navbar-brand" href="<?= $Wcms->url() ?>">
					<?= $Wcms->get('config', 'siteTitle') ?>
				</a>

				<div class="navbar-header">
				<button type="button" class="navbar-toggler navbar-toggle" data-toggle="collapse" data-target="#menu-collapse">
					<span class="navbar-toggler-icon">
						<span class="icon-bar"></span>
						<span class="icon-bar"></span>
						<span class="icon-bar"></span>
					</span>
				</button>
				</div>

				<div class="collapse navbar-collapse" id="menu-collapse">
					<ul class="nav navbar-nav navbar-right ml-auto">
						<?= $Wcms->menu() ?>
					</ul>
				</div>
			</div>
		</nav>

		<div class="container">
			<div class="row">
				<div class="col-lg-12 text-center padding40">
					<?= $Wcms->page('content') ?>

				</div>
			</div>
		</div>

		<div class="container-fluid blueBackground whiteFont">
			<div class="row">
				<div class="col-lg-12 text-center padding40">
					<?= $Wcms->block('subside') ?>

				</div>
			</div>
		</div>

		<footer class="container-fluid">
			<div class="text-right padding20">
				<?= $Wcms->footer() ?>

			</div>
		</footer>

		<?= $Wcms->js() ?>
		<script src="https://code.jquery.com/jquery-1.12.4.min.js" integrity="sha384-nvAa0+6Qg9clwYCGGPpDQLVpLNn0fRaROjHqs13t4Ggj3Ez50XnGQqc/r8MhnRDZ" crossorigin="anonymous"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous" type="text/javascript"></script>
	</body>
</html>
