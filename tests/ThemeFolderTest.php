<?php

use PHPUnit\Framework\TestCase;

/**
 * The active theme name is used to build a require path and asset URLs.
 * A custom folder name must survive the update, and nothing outside themes/ may ever be loaded.
 */
final class ThemeFolderTest extends TestCase
{
	/** @var Wcms */
	private $wcms;
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$this->root = sys_get_temp_dir() . '/wcms-theme-' . bin2hex(random_bytes(4));
		foreach (['sky', 'my.theme', 'Dark Sky', 'underscore_theme'] as $dir) {
			mkdir($this->root . '/themes/' . $dir, 0777, true);
			touch($this->root . '/themes/' . $dir . '/theme.php');
		}
		mkdir($this->root . '/themes/no-theme-php', 0777, true);
		mkdir($this->root . '/plugins/notatheme', 0777, true);
		$this->wcms = new Wcms('data_test', 'files_test', 'database_test.js');
		$this->wcms->rootDir = $this->root;
	}

	protected function tearDown(): void
	{
		foreach (array_reverse(glob($this->root . '/{,*/,*/*/}*', GLOB_BRACE) ?: []) as $d) {
			is_dir($d) ? @rmdir($d) : @unlink($d);
		}
		@rmdir($this->root . '/themes');
		@rmdir($this->root . '/plugins');
		@rmdir($this->root);
	}

	public function testCustomFolderNamesWithDotsAndSpacesAreKept(): void
	{
		$this->assertTrue($this->wcms->isThemeFolder('my.theme'));
		$this->assertTrue($this->wcms->isThemeFolder('Dark Sky'));
		$this->assertTrue($this->wcms->isThemeFolder('underscore_theme'));
	}

	public function testAThemeThatEndsTheBufferItselfRendersWithoutANotice(): void
	{
		mkdir($this->root . '/themes/flusher');
		file_put_contents($this->root . '/themes/flusher/theme.php', '<?php echo "FLUSHED"; ob_end_flush();');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'flusher');
		$GLOBALS['Wcms'] = $wcms;
		$before = ob_get_level();
		ob_start();
		$wcms->loadThemeAndFunctions();
		$level = ob_get_level();
		$out = $level > $before ? ob_get_clean() : '';
		unset($GLOBALS['Wcms']);
		$this->assertSame($before + 1, $level, 'the core flushes only the buffer it opened, never the caller\'s');
		$this->assertSame('FLUSHED', $out);
	}

	public function testTraversalAndDotFoldersAreRejected(): void
	{
		foreach (['..', '.', '../plugins', 'sky/..', '../data', '', '/etc', 'themes/sky'] as $bad) {
			$this->assertFalse($this->wcms->isThemeFolder($bad), "accepted: $bad");
		}
	}

	public function testMissingFolderIsRejected(): void
	{
		$this->assertFalse($this->wcms->isThemeFolder('does-not-exist'));
		$this->assertFalse($this->wcms->isThemeFolder('notatheme'));
		$this->assertFalse($this->wcms->isThemeFolder('no-theme-php'), 'a folder without theme.php is not a theme');
	}

	public function testAMissingThemeFolderShowsSkyAndTellsTheAdmin(): void
	{
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php echo "SKY";');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'deleted-by-ftp<b>');
		$GLOBALS['Wcms'] = $wcms;
		unset($_SESSION['alert']);

		ob_start();
		$wcms->loadThemeAndFunctions();
		$this->assertSame('SKY', ob_get_clean(), 'a visitor sees Sky, not a white screen');
		$this->assertFalse(isset($_SESSION['alert']), 'visitors are not told');

		$wcms->loggedIn = true;
		ob_start();
		$wcms->loadThemeAndFunctions();
		ob_end_clean();
		$this->assertStringContainsString('deleted-by-ftp&lt;b&gt;</b> is not installed', $_SESSION['alert']['danger'][0]['message'] ?? '', 'the name is escaped');
		$this->assertSame('deleted-by-ftp<b>', json_decode(file_get_contents($this->root . '/data_test/database_test.js'))->config->theme, 'the setting is not changed behind the admin\'s back');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}

	public function testBrokenThemeFallsBackToSkyInsteadOfAWhiteScreen(): void
	{
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php global $Wcms; echo "SKY " . $Wcms->asset("css/style.css");');
		mkdir($this->root . '/themes/broken');
		file_put_contents($this->root . '/themes/broken/theme.php', '<?php ob_start(); echo "PARTIAL OUTPUT"; throw new RuntimeException("theme broke after printing");'); // the theme opened its own buffer too
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'broken');
		unset($_SESSION['alert']);

		$GLOBALS['Wcms'] = $wcms;

		// Anonymous visitor: Sky renders with Sky's assets, no alert leaks the error message
		$level = ob_get_level();
		ob_start();
		$wcms->loadThemeAndFunctions();
		$this->assertSame($level + 1, ob_get_level(), 'the buffer the broken theme opened is drained, nothing above the DOCTYPE leaks');
		$output = ob_get_clean();
		$this->assertStringStartsWith('SKY ', $output);
		$this->assertStringNotContainsString('PARTIAL OUTPUT', $output, 'what the broken theme printed before failing is discarded');
		$this->assertStringContainsString('/themes/sky/css/style.css', $output);
		$this->assertFalse(isset($_SESSION['alert']), 'visitors must not see the failure message');

		// Admin (fresh request; a second broken theme because require_once remembers the first file): alert names the theme
		mkdir($this->root . '/themes/broken2');
		file_put_contents($this->root . '/themes/broken2/theme.php', '<?php echo "partial"; echo "unterminated;');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'broken2');
		$GLOBALS['Wcms'] = $wcms;
		$wcms->loggedIn = true;
		ob_start();
		$wcms->loadThemeAndFunctions();
		ob_end_clean();
		$this->assertStringContainsString('failed to load', $_SESSION['alert']['danger'][0]['message']);
		$saved = json_decode(file_get_contents($this->root . '/data_test/database_test.js'));
		$this->assertSame('broken2', $saved->config->theme, 'the setting on disk is not changed behind the admin\'s back');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}

	public function testABrokenListenerRegisteredByTheThemeDoesNotFatalTheSkyFallback(): void
	{
		// functions.php registers a css listener that throws; the theme calls css(), so does Sky. Without dropping the
		// theme's listeners the fallback would throw the same error again, uncaught.
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php global $Wcms; echo "SKY " . $Wcms->css();');
		mkdir($this->root . '/themes/badlistener');
		file_put_contents($this->root . '/themes/badlistener/functions.php', '<?php global $Wcms; $Wcms->addListener("css", function ($args) { throw new RuntimeException("broken listener"); });');
		file_put_contents($this->root . '/themes/badlistener/theme.php', '<?php global $Wcms; echo "BAD " . $Wcms->css();');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'badlistener');
		$wcms->addListener('css', static function ($args) { $args[0] .= '<!-- plugin listener kept -->'; return $args; });
		$GLOBALS['Wcms'] = $wcms;

		ob_start();
		$wcms->loadThemeAndFunctions();
		$output = ob_get_clean();
		$this->assertStringStartsWith('SKY ', $output);
		$this->assertStringContainsString('plugin listener kept', $output, 'listeners registered before the theme survive');
		$this->assertCount(1, $wcms->listeners['css'], 'the theme\'s own listener is gone');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}

	public function testAlertsTheThemePrintedBeforeItBrokeAreShownAgainBySky(): void
	{
		// alerts() hands the session's alerts to the theme and forgets them; the theme's output is discarded on the fallback.
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php echo "SKY";');
		mkdir($this->root . '/themes/lateboom');
		file_put_contents($this->root . '/themes/lateboom/theme.php', '<?php global $Wcms; echo $Wcms->alerts(); throw new RuntimeException("after the alerts");');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'lateboom');
		$GLOBALS['Wcms'] = $wcms;
		unset($_SESSION['alert']);
		$wcms->alert('danger', 'Wrong password.'); // what a visitor at the login form sees
		ob_start();
		$wcms->loadThemeAndFunctions();
		$this->assertSame('SKY', ob_get_clean());
		$this->assertSame(['Wrong password.'], array_column($_SESSION['alert']['danger'] ?? [], 'message'), 'the visitor keeps the alert and learns nothing about the theme');

		// Admin (a fresh request and a second theme folder: require_once remembers the first file, the instance its fallback)
		mkdir($this->root . '/themes/lateboom2');
		copy($this->root . '/themes/lateboom/theme.php', $this->root . '/themes/lateboom2/theme.php');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'lateboom2');
		$GLOBALS['Wcms'] = $wcms;
		$wcms->loggedIn = true;
		$wcms->alert('success', 'Saved before the theme broke');
		ob_start();
		$wcms->loadThemeAndFunctions();
		ob_end_clean();
		$this->assertSame(['Saved before the theme broke'], array_column($_SESSION['alert']['success'] ?? [], 'message'));
		$this->assertStringContainsString('lateboom2</b> failed to load: after the alerts', end($_SESSION['alert']['danger'])['message'], 'the admin gets the failure after the kept alerts');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}

	public function testAlertsAreNeitherDuplicatedNorDroppedAroundAThemeFailure(): void
	{
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php echo "SKY";');
		// Fails BEFORE alerts(): the queued alert was never handed over, so nothing is restored and nothing doubles (Fable gate, f496214).
		mkdir($this->root . '/themes/earlyboom');
		file_put_contents($this->root . '/themes/earlyboom/theme.php', '<?php throw new RuntimeException("before alerts");');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'earlyboom');
		$wcms->loggedIn = true;
		$GLOBALS['Wcms'] = $wcms;
		unset($_SESSION['alert']);
		$wcms->alert('success', 'Theme switched');
		ob_start();
		$wcms->loadThemeAndFunctions();
		$this->assertSame('SKY', ob_get_clean());
		$this->assertSame(['Theme switched'], array_column($_SESSION['alert']['success'], 'message'), 'printed once, not twice');

		// Prints, queues one more, then fails: the printed one comes back, the queued one stays, each once.
		mkdir($this->root . '/themes/midboom');
		file_put_contents($this->root . '/themes/midboom/theme.php', '<?php global $Wcms; echo $Wcms->alerts(); $Wcms->alert("success", "queued after printing"); throw new RuntimeException("after both");'); // the same class as the printed one: a plain array_merge would drop the printed one
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'midboom');
		$wcms->loggedIn = true;
		$GLOBALS['Wcms'] = $wcms;
		unset($_SESSION['alert']);
		$wcms->alert('success', 'Saved');
		ob_start();
		$wcms->loadThemeAndFunctions();
		ob_end_clean();
		$this->assertSame(['Saved', 'queued after printing'], array_column($_SESSION['alert']['success'], 'message'), 'the printed one first, then the one queued since, each once');
		$this->assertCount(1, $_SESSION['alert']['danger'], 'plus the one failure alert');

		// Prints, queues, prints again, then fails: both printed batches come back, not only the last.
		mkdir($this->root . '/themes/twiceboom');
		file_put_contents($this->root . '/themes/twiceboom/theme.php', '<?php global $Wcms; echo $Wcms->alerts(); $Wcms->alert("success", "second batch"); echo $Wcms->alerts(); throw new RuntimeException("after two batches");');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'twiceboom');
		$wcms->loggedIn = true;
		$GLOBALS['Wcms'] = $wcms;
		unset($_SESSION['alert']);
		$wcms->alert('success', 'Saved');
		ob_start();
		$wcms->loadThemeAndFunctions();
		ob_end_clean();
		$this->assertSame(['Saved', 'second batch'], array_column($_SESSION['alert']['success'], 'message'), 'both batches the theme printed, each once');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}

	public function testTheFallbackRendersWithTheOutputBufferingFunctionsDisabled(): void
	{
		// Round 15: the four ob_* calls are guarded; a host that disables them renders unbuffered, so a theme that printed its
		// alerts and then threw must not get them printed again by Sky. Each name alone, then all four, then none (buffered).
		$root = realpath($this->root);
		file_put_contents($root . '/themes/sky/theme.php', '<?php global $Wcms; echo "SKY"; echo $Wcms->alerts();');
		mkdir($root . '/themes/lateboom');
		file_put_contents($root . '/themes/lateboom/theme.php', '<?php global $Wcms; echo $Wcms->alerts(); throw new RuntimeException("after alerts");');
		foreach (['ob_start', 'ob_get_level', 'ob_end_clean', 'ob_end_flush', 'ob_start,ob_get_level,ob_end_clean,ob_end_flush', ''] as $disabled) {
			$cmd = escapeshellarg(PHP_BINARY) . ($disabled === '' ? '' : ' -d disable_functions=' . $disabled) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' render ' . escapeshellarg($root) . ' lateboom';
			$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
			$out = stream_get_contents($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);
			$label = $disabled === '' ? 'buffered' : $disabled . ' disabled';
			$this->assertSame(1, substr_count($err, "\n"), $label . ': exactly one line on stderr, the fallback log line: ' . $err);
			$this->assertStringContainsString('WonderCMS: theme lateboom failed to load, showing Sky: after alerts in ', $err, $label . ': the cause reaches the server log');
			$this->assertStringNotContainsString('Fatal error', $out, $label);
			$this->assertStringContainsString('SKY', $out, $label . ': Sky rendered');
			$this->assertSame(1, substr_count($out, 'Wrong password.'), $label . ': the alert exactly once');
			$this->assertStringContainsString('failed to load', $out, $label . ': the admin is told');
		}
		@unlink(__DIR__ . '/../data_test/database_probe.js');
	}

	public function testAThemeThatFlushedTheCoreBufferThenThrowsDoesNotGetItsAlertsRepeated(): void
	{
		// Its output, the alerts with it, has already been sent; Sky follows it and must not print them a second time.
		$root = realpath($this->root);
		file_put_contents($root . '/themes/sky/theme.php', '<?php global $Wcms; echo "SKY"; echo $Wcms->alerts();');
		mkdir($root . '/themes/flushboom');
		file_put_contents($root . '/themes/flushboom/theme.php', '<?php global $Wcms; echo $Wcms->alerts(); ob_end_flush(); throw new RuntimeException("after flushing");');
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' render ' . escapeshellarg($root) . ' flushboom';
		$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
		$out = stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$this->assertStringNotContainsString('Fatal error', $out);
		$this->assertStringContainsString('SKY', $out);
		$this->assertSame(1, substr_count($out, 'Wrong password.'), 'the alert exactly once');
		$this->assertStringContainsString('failed to load', $out, 'the admin is told');
		@unlink(__DIR__ . '/../data_test/database_probe.js');
	}

	private function probeRender(string $theme, string $ini = '', string $extra = ''): array
	{
		$cmd = escapeshellarg(PHP_BINARY) . $ini . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' render ' . escapeshellarg(realpath($this->root)) . ' ' . $theme . $extra;
		$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		@unlink(__DIR__ . '/../data_test/database_probe.js');
		return [$out, $err];
	}

	public function testAThemeThatCallsExitEndsThePageAs360Did(): void
	{
		// exit inside the buffered require ends the request there: no catch runs, so no Sky and no log line, and PHP's
		// shutdown flushes the core buffer. The visitor gets exactly what the theme printed, as 3.6.0 sent it unbuffered.
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php echo "SKY";');
		mkdir($this->root . '/themes/exiter');
		file_put_contents($this->root . '/themes/exiter/theme.php', '<?php global $Wcms; echo "BEFORE "; echo $Wcms->alerts(); exit; echo "AFTER";');
		[$out, $err] = $this->probeRender('exiter');
		$this->assertStringStartsWith('BEFORE ', $out);
		$this->assertSame(1, substr_count($out, 'Wrong password.'), 'the alert once');
		$this->assertStringNotContainsString('AFTER', $out);
		$this->assertStringNotContainsString('SKY', $out, 'exit is not a failure: no fallback');
		$this->assertStringNotContainsString('Fatal error', $out . $err);
		$this->assertSame('', $err, 'nothing logged');
		[$unbuffered] = $this->probeRender('exiter', ' -d disable_functions=ob_start');
		$token = static function (string $html): string { return preg_replace('/[0-9a-f]{64}/', 'TOKEN', $html); }; // each run mints its own session token
		$this->assertSame($token($unbuffered), $token($out), 'identical to the unbuffered (3.6.0) render');
	}

	public function testAPluginsOwnOutputBufferSurvivesAThemeFailure(): void
	{
		// The drain after a failure stops at the level the core found: a buffer a plugin opened before the theme loaded
		// is not the core's to close, so it still wraps the page (Sky, once) and is still open afterwards.
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php global $Wcms; echo "SKY"; echo $Wcms->alerts();');
		mkdir($this->root . '/themes/halfboom');
		file_put_contents($this->root . '/themes/halfboom/theme.php', '<?php echo "HALF A THEME"; throw new RuntimeException("halfway");');
		[$out] = $this->probeRender('halfboom', '', ' plugin-ob');
		$this->assertStringStartsWith('[plugin]SKY', $out, 'the plugin buffer still wraps the page');
		$this->assertStringEndsWith(' level 1[/plugin]', $out, 'and is open after the fallback, at its own level');
		$this->assertSame(1, substr_count($out, 'SKY'), 'the page renders once');
		$this->assertSame(1, substr_count($out, 'Wrong password.'));
		$this->assertStringNotContainsString('HALF A THEME', $out, 'the broken theme output is discarded');
		$this->assertStringNotContainsString('Notice', $out);
		$this->assertStringNotContainsString('Fatal error', $out);
	}

	public function testAWorkingThemeRendersWithOnlyObEndFlushDisabled(): void
	{
		// The success path ends with ob_end_flush(); with that one function disabled the theme must render unbuffered, not fatal.
		$root = realpath($this->root);
		mkdir($root . '/themes/good');
		file_put_contents($root . '/themes/good/theme.php', '<?php global $Wcms; echo "GOOD"; echo $Wcms->alerts();');
		$cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=ob_end_flush ' . escapeshellarg(__DIR__ . '/probe.php') . ' render ' . escapeshellarg($root) . ' good';
		$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$this->assertStringNotContainsString('Fatal error', $out . $err);
		$this->assertSame('', $err);
		$this->assertStringContainsString('GOOD', $out, 'the theme rendered');
		$this->assertSame(1, substr_count($out, 'Wrong password.'));
		@unlink(__DIR__ . '/../data_test/database_probe.js');
	}

	public function testAPluginHookThatThrowsWhileTheThemeRendersIsBlamedOnThePlugin(): void
	{
		$root = realpath($this->root); // the thrown exception carries the resolved file path, as rootDir (__DIR__) is on a real site
		file_put_contents($root . '/themes/sky/theme.php', '<?php echo "SKY";'); // Sky does not call the hook here, so the fallback renders
		mkdir($root . '/plugins/badplug', 0777, true);
		file_put_contents($root . '/plugins/badplug/badplug.php', '<?php global $Wcms; $Wcms->addListener("css", function ($args) { throw new RuntimeException("plugin broke"); });');
		file_put_contents($root . '/themes/my.theme/theme.php', '<?php global $Wcms; echo $Wcms->css();');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'my.theme');
		$wcms->loggedIn = true;
		$GLOBALS['Wcms'] = $wcms;
		require $root . '/plugins/badplug/badplug.php'; // as loadPlugins() would, before the theme
		unset($_SESSION['alert']);
		ob_start();
		$wcms->loadThemeAndFunctions();
		$this->assertSame('SKY', ob_get_clean());
		$this->assertStringContainsString('Plugin <b>badplug</b> failed while theme <b>my.theme</b> rendered: plugin broke', $_SESSION['alert']['danger'][0]['message'] ?? '');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}

	public function testThePluginIsBlamedWhenTheSiteRootIsASymlink(): void
	{
		// rootDir is the path the site was reached by; PHP reports the resolved file path (and backslashes on Windows).
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		$root = realpath($this->root);
		$link = $root . '-link';
		symlink($root, $link);
		try {
			file_put_contents($root . '/themes/sky/theme.php', '<?php echo "SKY";');
			mkdir($root . '/plugins/linkplug', 0777, true);
			file_put_contents($root . '/plugins/linkplug/linkplug.php', '<?php global $Wcms; $Wcms->addListener("css", function ($args) { throw new RuntimeException("plugin broke"); });');
			file_put_contents($root . '/themes/underscore_theme/theme.php', '<?php global $Wcms; echo $Wcms->css();');
			$wcms = $this->getMockBuilder(Wcms::class)
				->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $link])
				->onlyMethods(['redirect'])
				->getMock();
			$wcms->set('config', 'theme', 'underscore_theme');
			$wcms->loggedIn = true;
			$GLOBALS['Wcms'] = $wcms;
			require $link . '/plugins/linkplug/linkplug.php';
			unset($_SESSION['alert']);
			ob_start();
			$wcms->loadThemeAndFunctions();
			$this->assertSame('SKY', ob_get_clean());
			$this->assertStringContainsString('Plugin <b>linkplug</b> failed while theme <b>underscore_theme</b> rendered: plugin broke', $_SESSION['alert']['danger'][0]['message'] ?? '');
		} finally {
			unset($_SESSION['alert'], $GLOBALS['Wcms']);
			unlink($link);
			exec('rm -rf ' . escapeshellarg($root . '/data_test'));
		}
	}

	public function testHidingAlertsLastsForTheLoginItWasClickedIn(): void
	{
		$_SESSION['alert'] = ['danger' => [['class' => 'danger', 'message' => 'Wrong password.', 'sticky' => false]]];
		$this->wcms->loggedIn = true;
		$token = $this->wcms->getToken();
		$html = $this->wcms->alerts();
		$this->assertStringContainsString('if (displayAlerts === "' . $token . '")', $html, 'the hide compares against this session\'s token');
		$this->assertStringContainsString("localStorage.setItem('displayAlerts', '" . $token . "')", $html, 'the link stores this session\'s token');
		$this->assertStringNotContainsString('=== "false"', $html, 'a value stored by an earlier login (or by 3.6.0) hides nothing');

		unset($_SESSION['token']); // a new session: the next login, or a visitor at the login form
		$_SESSION['alert'] = ['danger' => [['class' => 'danger', 'message' => 'Wrong password.', 'sticky' => false]]];
		$this->wcms->loggedIn = false;
		$html = $this->wcms->alerts();
		$this->assertStringNotContainsString($token, $html, 'the old token no longer hides anything');
		$this->assertStringNotContainsString('Hide all alerts', $html, 'a visitor is not offered the hide');
		$this->assertStringContainsString('Wrong password.', $html);
		unset($_SESSION['alert']);
	}

	public function testABrokenThemesAlertListenerDoesNotFatalTheAdminsFallback(): void
	{
		// The fallback tells the admin through alert(), which runs the alert hook: a theme whose functions.php registered a
		// throwing alert listener must have lost it before that call.
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php echo "SKY";');
		mkdir($this->root . '/themes/badalert');
		file_put_contents($this->root . '/themes/badalert/functions.php', '<?php global $Wcms; $Wcms->addListener("alert", function ($args) { throw new RuntimeException("alert listener broke"); });');
		file_put_contents($this->root . '/themes/badalert/theme.php', '<?php throw new RuntimeException("theme broke");');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'badalert');
		$wcms->loggedIn = true;
		$GLOBALS['Wcms'] = $wcms;
		unset($_SESSION['alert']);
		ob_start();
		$wcms->loadThemeAndFunctions();
		$output = ob_get_clean();
		$this->assertSame('SKY', $output, 'the admin gets Sky, not a second fatal from the alert hook');
		$this->assertStringContainsString('failed to load', $_SESSION['alert']['danger'][0]['message'] ?? '');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}
}
