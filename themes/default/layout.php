<?php defined('ROOT') || die(); ?>
<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<title><?php echo WonderCMS::$webSiteTitle; ?> - <?php echo WonderCMS::$title; ?></title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link rel="stylesheet" href="<?php echo WonderCMS::themeUrl('style.css'); ?>">
		<?php if (WonderCMS::$loggedIn): ?>
			<link rel="stylesheet" href="<?php echo WonderCMS::url('trumbowyg/ui/trumbowyg.min.css'); ?>">
			<link rel="stylesheet" href="<?php echo WonderCMS::url('trumbowyg/ui/trumbowyg.colors.min.css'); ?>">
		<?php endif; ?>
		<meta name="description" content="<?php echo WonderCMS::$description; ?>">
		<meta name="keywords" content="<?php echo WonderCMS::$keywords; ?>">
	</head>
	<body>
		<nav id="nav">
			<h1 id="main-title"><a href="<?php echo WonderCMS::url(); ?>"><?php echo WonderCMS::$webSiteTitle; ?></a></h1>
			<ul>
				<?php foreach(WonderCMS::menuItems() as $item): ?>
					<li class="<?php echo $item->isCurrent ? 'active' : null; ?>"><a href="<?php echo $item->url; ?>"><?php echo $item->title; ?></a></li>
				<?php endforeach; ?>
			</ul>
			<div class="clear"></div>
		</nav>
		<?php if (WonderCMS::$loggedIn): ?>
			<div class="settings">
				<h3 class="toggle">↕ Settings ↕</h3>
				<div class="hide">
					<div class="change border"><b>Password</b>&nbsp;
						<input type="password" id="password">&nbsp;<button id="changePassword">Change</button>
					</div>
					<div class="change border"><b>Theme</b>&nbsp;
						<span id="theme">
							<select id="theme">
								<?php foreach (glob(ROOT.DS.'themes'.DS.'*', GLOB_ONLYDIR) as $dir): ?>
									<?php $dir = basename($dir); ?>
									<option value="<?php echo $dir; ?>"<?php echo (WonderCMS::get('theme') == $dir) ? ' selected' : null; ?>><?php echo $dir; ?></option>
								<?php endforeach; ?>
							</select>
						</span>
					</div>
					<div class="change border"><b>Navigation <small>(add, remove, sort page(s) below and <a href="javascript:location.reload(true);">refresh</a>)</small></b>
						<span id="menuItems" class="editText"><?php echo str_replace("\n", "<br>", WonderCMS::get('menuItems')); ?></span>
					</div>
					<?php foreach (['webSiteTitle', 'description', 'keywords', 'copyright'] as $key): ?>
						<div class="change border">
							<span id="<?php echo $key; ?>" class="editText"><?php echo WonderCMS::get($key); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<div id="wrapper" class="border">
			<div class="pad">
				<?php echo WonderCMS::$content; ?>
			</div>
		</div>
		
		<div id="side" class="border">
			<div class="pad">
				<?php echo WonderCMS::$subside; ?>
			</div>
		</div>

		<div class="clear"></div>
		<footer><p><?php echo WonderCMS::$copyright ?> | Powered by <a href='http://wondercms.com'>WonderCMS</a> | <?php echo WonderCMS::$loggedIn ? '<a href="'.WonderCMS::url('logout').'">Logout</a>' : '<a href="'.WonderCMS::url('login').'">Login</a>'; ?></p></footer>
		<?php if (WonderCMS::$loggedIn): ?>
			<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.0/jquery.min.js"></script>
			<script>window.jQuery || document.write('<script src="js/vendor/jquery-1.11.3.min.js"><\/script>')</script>
			<script src="<?php echo WonderCMS::url('trumbowyg/trumbowyg.min.js'); ?>"></script>
			<script src="<?php echo WonderCMS::url('trumbowyg/plugins/colors/trumbowyg.colors.min.js'); ?>"></script>
			<script src="<?php echo WonderCMS::url('trumbowyg/plugins/insertaudio/trumbowyg.insertaudio.min.js'); ?>"></script>
			<script src="<?php echo WonderCMS::url('trumbowyg/plugins/table/trumbowyg.table.min.js'); ?>"></script>
			<script src="<?php echo WonderCMS::url('trumbowyg/plugins/preformatted/trumbowyg.preformatted.min.js'); ?>"></script>
			<script>
				$(document).ready(function() {
					$('textarea').trumbowyg({
						btns: [
							['viewHTML'],
							['undo', 'redo'],
							['formatting'],
							'btnGrp-design',
							['link'],
							['insertImage'],
							['insertAudio'],
							'btnGrp-justify',
							'btnGrp-lists',
							['foreColor', 'backColor'],
							['preformatted'],
							['horizontalRule'],
							['fullscreen']
		                ]
					}).on('tbwblur', function () {
						$.post("<?php echo WonderCMS::url('index.php'); ?>", {label: $(this).attr('id'), content: $(this).siblings('.trumbowyg-editor').html()});
					});
					$(document).on('click', 'span.editText', function () {
						$(this).replaceWith($('<textarea class="editText"></textarea>').attr('id', $(this).attr('id')).html($(this).html().replace(/<br>/g, "\n")));
					});
					$(document).on('blur', 'textarea.editText', function () {
						$(this).replaceWith($('<span class="editText"></span>').attr('id', $(this).attr('id')).html($(this).val().replace(/\n/g, "<br>")));
						$.post("<?php echo WonderCMS::url('index.php'); ?>", {label: $(this).attr('id'), content: $(this).val()});
					});
					$('#theme').on('change', function () {
						var optionSelected = $("option:selected", this);
						var valueSelected = this.value;
						$.post("<?php echo WonderCMS::url('index.php'); ?>", {label: 'theme', content: valueSelected});
					});
					$('#changePassword').on('click', function () {
						if ($('#password').val().length < 5) {
							alert('Password is too short, it must be at least 6 characters long');
						} else {
							$.post("<?php echo WonderCMS::url('index.php'); ?>", {label: 'password', content: $('#password').val()}, function () {
								alert('Password changed');
							});
						}
					});
					$('.toggle').click(function(){
						$('.hide').toggle('200');
					});
				});
			</script>
		<?php endif; ?>
	</body>
</html>
