<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">

		<title><?= wCMS::get('config','siteTitle') ?> - <?= wCMS::page('title') ?></title>
		<meta name="description" content="<?= wCMS::page('description') ?>">
		<meta name="keywords" content="<?= wCMS::page('keywords') ?>">

		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
		<link rel="stylesheet" href="<?= wCMS::asset('css/style.css') ?>">
		<?= wCMS::css() ?>

	</head>
	
	<body>
		<?= wCMS::alerts() ?>
		<?= wCMS::settings() ?>

		<nav class="navbar navbar-default">
			<div class="container">
				<div class="navbar-header">
					<button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#menu-collapse">
						<span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
					</button>

					<a class="navbar-brand" href="<?= wCMS::url() ?>">
						<?= wCMS::get('config','siteTitle') ?>

					</a>
				</div>

				<div class="collapse navbar-collapse" id="menu-collapse">
					<ul class="nav navbar-nav navbar-right">
						<?= wCMS::menu() ?>

					</ul>
				</div>
			</div>
		</nav>

		<div class="container">
			<div class="row">
				<div class="col-lg-12 text-center padding40">
					<?= wCMS::page('content') ?>

				</div>
			</div>
		</div>

		<div class="container-fluid blueBackground whiteFont">
			<div class="row">
				<div class="col-lg-12 text-center padding40">
					<?= wCMS::block('subside') ?>

				</div>
			</div>
		</div>

		<footer class="container-fluid">
			<div class="text-right padding20">
				<?= wCMS::footer() ?>

			</div>
		</footer>

		<script src="https://code.jquery.com/jquery-1.12.4.min.js" integrity="sha384-nvAa0+6Qg9clwYCGGPpDQLVpLNn0fRaROjHqs13t4Ggj3Ez50XnGQqc/r8MhnRDZ" crossorigin="anonymous"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
		<?= wCMS::js() ?>

	</body>
</html>
