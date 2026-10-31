<?php global $Wcms ?>

<!DOCTYPE html>
<html lang="<?= method_exists($Wcms, 'getSiteLanguage') ? $Wcms->getSiteLanguage() : 'en' ?>">
	<head>
		<!-- Encoding, browser compatibility, viewport -->
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">

		<!-- Search Engine Optimization (SEO) -->
		<meta name="title" content="<?= $Wcms->get('config', 'siteTitle') ?> - <?= $Wcms->page('title') ?>" />
		<meta name="description" content="<?= $Wcms->page('description') ?>">
		<meta name="keywords" content="<?= $Wcms->page('keywords') ?>">
		<meta property="og:url" content="<?= $this->url() ?>" />
		<meta property="og:type" content="website" />
		<meta property="og:site_name" content="<?= $Wcms->get('config', 'siteTitle') ?>" />
		<meta property="og:title" content="<?= $Wcms->page('title') ?>" />
		<meta name="twitter:site" content="<?= $this->url() ?>" />
		<meta name="twitter:title" content="<?= $Wcms->get('config', 'siteTitle') ?> - <?= $Wcms->page('title') ?>" />
		<meta name="twitter:description" content="<?= $Wcms->page('description') ?>" />

		<!-- Website and page title -->
		<title>
			<?= $Wcms->get('config', 'siteTitle') ?> - <?= $Wcms->page('title') ?>

		</title>

		<!-- Admin CSS -->
		<?= $Wcms->css() ?>
		
		<!-- Theme CSS, its URL changes with the file, so an update never pairs this theme.php with a cached old stylesheet -->
		<link rel="stylesheet" rel="preload" as="style" href="<?= $Wcms->asset('css/style.css') ?>?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
	</head>

	<body>
		<!-- Admin settings panel and alerts -->
		<?= $Wcms->settings() ?>

		<?= $Wcms->alerts() ?>

		<section id="topMenu">
			<div class="inner">
				<nav>
					<ul class="menu">
						<!-- Menu -->
						<?= $Wcms->menu() ?>

					</ul>
				</nav>
			</div>
		</section>

		<div id="wrapper">
			<section id="intro" class="wrapper style1 fullscreen">
				<div class="inner">
					<!-- Main content for each page -->
					<?= $Wcms->page('content') ?>

				</div>
			</section>

			<section class="wrapper style2">
					<div class="inner">
						<!-- Static editable block, same on each page -->
						<?= $Wcms->block('subside') ?>

					</div>
			</section>
		</div>

		<footer class="wrapper style2">
			<div class="inner">
				<!-- Footer -->
				<?= $Wcms->footer() ?>

			</div>
		</footer>

		<!-- Admin JavaScript. More JS libraries can be added below -->
		<?= $Wcms->js() ?>
		<script>
			// Touch: the first tap on a menu item with subpages opens them (and closes its siblings), the second tap follows the link.
			// Decided per tap, so touch laptops, Chromebooks and tablets with a trackpad get it too, while a mouse keeps hover.
			// A mouse on a browser without hover (a tablet with a Bluetooth mouse) gets tap mode, since CSS hover cannot open anything there,
			// and so does a mouse in a window of phone width (zoomed or split screen), where only the tapped layout stacks inside the screen
			(function () {
				var canHover = function () {
					return !!window.matchMedia && window.matchMedia('(hover: hover)').matches;
				};
				var touch = !canHover();
				var closeOpen = function (scope) {
					scope.querySelectorAll('.subpage-nav.open').forEach(function (item) {
						item.classList.remove('open');
						item.querySelector('a').setAttribute('aria-expanded', 'false');
					});
				};
				var onPress = function (event) {
					touch = event.pointerType !== 'mouse' || !canHover() || window.matchMedia('(max-width: 736px)').matches;
					if (!event.target.closest('#topMenu')) {
						closeOpen(document);
					}
				};
				document.addEventListener('pointerdown', onPress);
				document.addEventListener('touchstart', onPress);
				document.addEventListener('keydown', function (event) {
					touch = !canHover();
					if (event.key === 'Escape') {
						closeOpen(document);
						if (document.activeElement && document.activeElement.closest('#topMenu')) {
							document.activeElement.blur();
						}
					}
				});
				document.addEventListener('focusout', function (event) {
					var menu = document.getElementById('topMenu');
					if (menu && menu.contains(event.target) && !(event.relatedTarget && menu.contains(event.relatedTarget))) {
						closeOpen(menu);
					}
				});
				document.querySelectorAll('#topMenu .subpage-nav > a').forEach(function (link) {
					if (touch) {
						link.setAttribute('aria-expanded', 'false');
					}
					link.addEventListener('click', function (event) {
						var item = link.parentNode;
						if (touch && !item.classList.contains('open')) {
							event.preventDefault();
							closeOpen(item.parentNode);
							item.classList.add('open');
							link.setAttribute('aria-expanded', 'true');
						}
					});
				});
			})();
		</script>

	</body>
</html>
