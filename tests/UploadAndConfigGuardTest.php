<?php

use PHPUnit\Framework\TestCase;

/**
 * #356 (upload without fileinfo), #344 protected config keys, logout ?to[] and a hand-edited subpages array.
 */
final class UploadAndConfigGuardTest extends TestCase
{
	/** @var Wcms */
	private $wcms;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$this->wcms->loggedIn = true;
		$_SESSION['loggedIn'] = true;
	}

	protected function tearDown(): void
	{
		$_POST = $_GET = $_REQUEST = $_FILES = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
	}

	public function testUploadIsAcceptedByExtensionWhenMimeDetectionIsUnavailable(): void
	{
		// Runs uploadFileAction() on a PHP without the fileinfo extension (3.6.0 refused every upload there, #356).
		// disable_classes does not remove the class (class_exists() stays true and new finfo warns), so this needs a
		// PHP built or configured without fileinfo: the "no-fileinfo" CI job. Everywhere else it skips.
		if (extension_loaded('fileinfo')) {
			$this->markTestSkipped('needs a PHP without the fileinfo extension (the no-fileinfo CI job)');
		}
		$file = tempnam(sys_get_temp_dir(), 'wcms');
		file_put_contents($file, 'hello');
		$alerts = [];
		foreach (['note.txt', 'shell.php'] as $name) {
			$cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=mime_content_type '
				. escapeshellarg(__DIR__ . '/probe.php') . ' upload ' . escapeshellarg($file) . ' ' . escapeshellarg($name);
			$out = (string)shell_exec($cmd . ' 2>&1');
			$result = json_decode($out, true);
			if (!is_array($result) || $result['fileinfo']) {
				unlink($file);
				$this->fail('probe must run without fileinfo and print JSON; output: ' . $out);
			}
			$alerts[$name] = implode(' ', array_column($result['alert']['danger'] ?? [], 'message'));
		}
		unlink($file);
		$this->assertStringNotContainsString('File format is not allowed', $alerts['note.txt'], 'an allowed extension passes on its own');
		$this->assertStringContainsString('File format is not allowed', $alerts['shell.php'], 'a disallowed extension is still refused');
	}

	public function testGenericSaveCannotTouchProtectedConfigKeys(): void
	{
		$before = $this->wcms->get('config', 'password');
		$_POST = ['fieldname' => 'password', 'content' => 'owned', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame($before, $this->wcms->get('config', 'password'));

		$_POST = ['fieldname' => 'menuItems', 'content' => '[]', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertNotSame('[]', $this->wcms->get('config', 'menuItems'));

		$_POST = ['fieldname' => 'lastLogins', 'content' => '{"2026/01/01 00:00:00":"1.2.3.4"}', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame([], (array)$this->wcms->get('config', 'lastLogins'), 'the login history is not editable through the generic save');

		$_POST = ['fieldname' => 'forceLogout', 'content' => 'true', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertNotSame('true', $this->wcms->get('config', 'forceLogout'));

		$_POST = ['fieldname' => 'disabledPlugins', 'content' => '["summernote-editor"]', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame([], (array)$this->wcms->get('config', 'disabledPlugins'), 'the disabled-plugins list is not editable through the generic save');

		$_POST = ['fieldname' => 'customModules', 'content' => '{"plugins":["https://evil.example/list.json"]}', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertStringNotContainsString('evil.example', json_encode($this->wcms->get('config', 'customModules')), 'the custom repository list is not editable through the generic save');

		$_POST = ['fieldname' => 'siteTitle', 'content' => 'Allowed', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame('Allowed', $this->wcms->get('config', 'siteTitle'));
	}

	public function testLoginIpsAreEscapedInTheSettingsPanel(): void
	{
		// Before 3.7.2 a proxy header was stored as sent and can still be in the database; the settings panel must print it as text.
		$this->wcms->set('config', 'lastLogins', ['2026/10/28 10:00:00' => '<img src=x onerror=alert(1)>']);
		$html = $this->wcms->settings();
		$this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
		$this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
	}

	public function testTheFilesTabKeepsTheLinkToAnOldBackup(): void
	{
		// Backups made before 3.7.2 sit in data/files. The owner is the only one who knows the name, so the tab keeps its link
		// and its delete button and notes that it is an old backup; an unfinished one (libzip's temp file) is not listed.
		$backup = '2026-01-01-backup-0123456789abcdef.zip';
		$temp = $backup . '.R4t5Y6.part'; // libzip's unfinished archive from a 3.7.1 backup killed during close()
		$uploads = ['notes-backup-plan.zip', 'holiday-backup-2024.zip', 'site-backup-1.zip', 'film.mp4.part'];
		file_put_contents($this->wcms->filesPath . '/' . $backup, 'old');
		file_put_contents($this->wcms->filesPath . '/' . $temp, 'partial');
		foreach ($uploads as $upload) {
			file_put_contents($this->wcms->filesPath . '/' . $upload, 'an upload');
		}
		try {
			$html = $this->wcms->settings();
			$this->assertStringContainsString('deleteModule=' . $backup . '&type=files', $html, 'the delete button stays');
			$this->assertStringContainsString('/data/files/' . $backup . '" class="normalFont"', $html, 'the download link stays');
			$this->assertSame(1, substr_count($html, '(old backup: download it if you need it, then delete it)'), 'only the real backup carries the note');
			$this->assertStringNotContainsString($temp, $html, 'an unfinished backup is not listed at all');
			foreach ($uploads as $upload) {
				$this->assertStringContainsString('data/files/' . $upload, $html, 'an upload named like a backup but without the date and the random suffix keeps its link');
			}
		} finally {
			@unlink($this->wcms->filesPath . '/' . $temp);
			@unlink($this->wcms->filesPath . '/' . $backup);
			foreach ($uploads as $upload) {
				@unlink($this->wcms->filesPath . '/' . $upload);
			}
		}
	}

	public function testOnlyTheExactBackupNameShapeIsABackup(): void
	{
		// Every backup since 3.1.0 is <date>-backup-<hex>.zip with 16 hex (random_bytes(8)), 42 (the microtime fallback) or 32
		// (3.7.2). A looser pattern took an admin's own uploads for backups: left out of the archive.
		foreach ([
			'2026-01-01-backup-0123456789abcdef.zip',
			'2026-01-01-BACKUP-0123456789ABCDEF.ZIP',
			'2024-05-06-backup-' . str_repeat('0', 42) . '.zip',
			'2026-10-31-backup-' . str_repeat('f', 32) . '.zip',
			'/srv/site/data/2026-10-31-backup-' . str_repeat('f', 32) . '.zip',
			'C:\\site\\data\\2026-10-31-backup-' . str_repeat('f', 32) . '.zip',
		] as $name) {
			$this->assertTrue($this->wcms->isBackupArchiveName($name), $name);
		}
		foreach ([
			'holiday-backup-2024.zip',
			'site-backup-1.zip',
			'db-backup-cafe.zip',
			'notes-backup-plan.zip',
			'2026-01-01-backup-0123456789abcde.zip', // 15 hex
			'2026-01-01-backup-0123456789abcdef.zip.tmp',
			'x2026-01-01-backup-0123456789abcdef.zip',
			'photos.zip',
		] as $name) {
			$this->assertFalse($this->wcms->isBackupArchiveName($name), $name);
		}
	}

	public function testOnlyLibzipsTempNamesOfABackupAreBackupTempFiles(): void
	{
		$backup = '2026-10-31-backup-' . str_repeat('f', 32) . '.zip';
		foreach ([
			$backup . '.2uyoxo.part', // libzip 1.10 and later
			$backup . '.Ab12Cd', // older libzip
			$backup . '.0a1b2c3d', // libzip on Windows
			'2026-01-01-backup-0123456789abcdef.zip.R4t5Y6.part', // 3.7.1's name in data/files
			'/srv/site/data/' . $backup . '.2uyoxo.part',
			'C:\\site\\data\\' . $backup . '.0a1b2c3d',
		] as $name) {
			$this->assertTrue($this->wcms->isBackupTempName($name), $name);
		}
		foreach ([
			$backup, // the finished archive is isBackupArchiveName()'s
			$backup . '.tmp',
			$backup . '.abc12.part', // 5 characters
			$backup . '.2uyoxo.part.php',
			$backup . ".2uyoxo.part\n",
			'film.mp4.part',
			'holiday-backup-2024.zip.2uyoxo.part',
			'x' . $backup . '.2uyoxo.part',
		] as $name) {
			$this->assertFalse($this->wcms->isBackupTempName($name), $name);
		}
	}

	public function testACustomModuleUrlMustBeHttpOrHttps(): void
	{
		$dir = sys_get_temp_dir() . '/wcms-module-' . bin2hex(random_bytes(3));
		mkdir($dir);
		file_put_contents($dir . '/wcms-modules.json', json_encode(['themes' => ['local' => ['name' => 'Local', 'repo' => 'file://' . $dir, 'zip' => 'file://' . $dir . '/local.zip', 'summary' => 's', 'version' => '1.0.0', 'image' => '']]]));
		wcmsSetPasswordOnDisk($this->wcms, 'secret');
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$_POST = ['pluginThemeUrl' => 'file://' . $dir . '/wcms-modules.json', 'pluginThemeType' => 'themes', 'password_recheck' => 'secret', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->addCustomModule();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertStringStartsWith('Invalid URL.', $_SESSION['alert']['danger'][0]['message'] ?? '', 'a readable file:// list is still refused by its scheme');
		}
		$this->assertSame([], (array)$this->wcms->get('config', 'customModules', 'themes'));
		unlink($dir . '/wcms-modules.json');
		rmdir($dir);
	}

	public function testOnlyAThemeFolderCanBecomeTheActiveTheme(): void
	{
		$name = 'tt-' . bin2hex(random_bytes(3));
		mkdir(__DIR__ . '/../themes/' . $name);
		file_put_contents(__DIR__ . '/../themes/' . $name . '/theme.php', '<?php');
		$before = $this->wcms->get('config', 'theme');

		$_REQUEST = ['selectModule' => $name, 'type' => 'plugins', 'token' => $this->wcms->getToken()];
		$this->wcms->changePageThemeAction();
		$this->assertSame($before, $this->wcms->get('config', 'theme'), 'the plugins tab cannot pick a theme');

		$_REQUEST = ['selectModule' => 'no-such-' . $name, 'type' => 'themes', 'token' => $this->wcms->getToken()];
		$this->wcms->changePageThemeAction();
		$this->assertSame($before, $this->wcms->get('config', 'theme'), 'a folder that does not exist is refused');

		$_REQUEST = ['selectModule' => $name, 'type' => 'themes', 'token' => $this->wcms->getToken()];
		$this->wcms->changePageThemeAction();
		$this->assertSame($name, $this->wcms->get('config', 'theme'));
		$this->wcms->recursiveDelete(__DIR__ . '/../themes/' . $name);
	}

	public function testDisablingAPluginRefusesANameThatTheCleaningWouldChange(): void
	{
		// Two hand-uploaded plugins differing only in characters the cleaning strips: Disable on one must not toggle the other.
		foreach (['foobar', 'foo.bar'] as $name) {
			mkdir(__DIR__ . '/../plugins/' . $name, 0777, true);
			file_put_contents(__DIR__ . '/../plugins/' . $name . '/' . $name . '.php', '<?php');
		}
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$_REQUEST = ['togglePlugin' => 'foo.bar', 'state' => 'disable', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->togglePluginAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertSame('Invalid plugin name.', $_SESSION['alert']['danger'][0]['message'] ?? '');
		}
		$this->assertSame([], (array)$this->wcms->get('config', 'disabledPlugins'), 'foobar was not disabled in its place');
		unset($_SESSION['alert']);
		$_REQUEST = ['togglePlugin' => ' foobar', 'state' => 'disable', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->togglePluginAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertSame('Invalid plugin name.', $_SESSION['alert']['danger'][0]['message'] ?? '', 'a leading space is refused too, not trimmed away');
		}
		$this->assertSame([], (array)$this->wcms->get('config', 'disabledPlugins'));
		$this->wcms->recursiveDelete(__DIR__ . '/../plugins/foobar');
		$this->wcms->recursiveDelete(__DIR__ . '/../plugins/foo.bar');
		$_REQUEST = [];
	}

	public function testAThemeFolderWithoutThemePhpIsNotATheme(): void
	{
		// 3.6.0 (and PHP 7.4) fataled on require of the missing file; now the folder is not a theme and Sky stands in.
		$name = 'empty-' . bin2hex(random_bytes(2));
		mkdir(__DIR__ . '/../themes/' . $name);
		$this->assertFalse($this->wcms->isThemeFolder($name));
		$this->wcms->set('config', 'theme', $name);
		$this->assertSame('sky', $this->wcms->activeTheme());
		rmdir(__DIR__ . '/../themes/' . $name);
	}

	public function testAssetUrlsEncodeTheThemeFolderName(): void
	{
		$name = 'a#b ' . bin2hex(random_bytes(2));
		mkdir(__DIR__ . '/../themes/' . $name);
		file_put_contents(__DIR__ . '/../themes/' . $name . '/theme.php', '<?php');
		$this->wcms->set('config', 'theme', $name);
		$this->assertStringEndsWith('/themes/' . rawurlencode($name) . '/css/style.css', $this->wcms->asset('css/style.css'));
		$this->assertStringNotContainsString('#', $this->wcms->asset('css/style.css'), 'a # would cut the URL into a fragment');
		$this->wcms->recursiveDelete(__DIR__ . '/../themes/' . $name);
	}

	public function testSkyCannotBeRemovedWhileItStandsInForAMissingTheme(): void
	{
		// Sky is the fallback for a missing AND for a broken theme; the broken case is only known at render time, so Sky is never removable from the UI.
		// This test runs against the checkout's real themes/sky: a regression in the guard deletes it (git restores it; the mutant runs did exactly that).
		$broken = 'broken-' . bin2hex(random_bytes(2));
		mkdir(__DIR__ . '/../themes/' . $broken);
		file_put_contents(__DIR__ . '/../themes/' . $broken . '/theme.php', '<?php throw new RuntimeException("boom");');
		$this->wcms->set('config', 'theme', $broken);
		$this->assertSame($broken, $this->wcms->activeTheme(), 'before render the broken theme still counts as active');
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$_REQUEST = ['deleteModule' => 'sky', 'type' => 'themes', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->deleteFileModuleAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Sky is the fallback theme', $_SESSION['alert']['danger'][0]['message'] ?? '');
		}
		$this->assertFileExists(__DIR__ . '/../themes/sky/theme.php');
		$this->wcms->recursiveDelete(__DIR__ . '/../themes/' . $broken);
		unset($_SESSION['alert']);

		$this->wcms->set('config', 'theme', 'gone-' . bin2hex(random_bytes(2)));
		$this->assertSame('sky', $this->wcms->activeTheme());
		$_REQUEST = ['deleteModule' => 'sky', 'type' => 'themes', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->deleteFileModuleAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Sky is the fallback theme', $_SESSION['alert']['danger'][0]['message'] ?? '');
		}
		$this->assertFileExists(__DIR__ . '/../themes/sky/theme.php');
		unset($_SESSION['alert']);
		$_REQUEST = ['deleteModule' => 'Sky', 'type' => 'themes', 'token' => $this->wcms->getToken()]; // on Windows and macOS Sky/ is sky/
		try {
			$this->wcms->deleteFileModuleAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Sky is the fallback theme', $_SESSION['alert']['danger'][0]['message'] ?? '', 'the guard compares case-insensitively');
		}
		$this->assertFileExists(__DIR__ . '/../themes/sky/theme.php');

		// The active theme is guarded the same way, in any letter case.
		$mine = 'mine-ſ' . bin2hex(random_bytes(2)); // 'ſ' folds to 's': a byte-wise or ASCII-only compare would not match the spelling below
		mkdir(__DIR__ . '/../themes/' . $mine);
		file_put_contents(__DIR__ . '/../themes/' . $mine . '/theme.php', '<?php');
		$this->wcms->set('config', 'theme', $mine);
		foreach ([$mine, strtoupper($mine), str_replace('ſ', 'S', $mine)] as $spelling) {
			unset($_SESSION['alert']);
			$_REQUEST = ['deleteModule' => $spelling, 'type' => 'themes', 'token' => $this->wcms->getToken()];
			try {
				$this->wcms->deleteFileModuleAction();
				$this->fail('no redirect');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('Cannot delete currently active theme', $_SESSION['alert']['danger'][0]['message'] ?? '', 'refused as ' . $spelling);
			}
			$this->assertFileExists(__DIR__ . '/../themes/' . $mine . '/theme.php');
		}
		$this->wcms->set('config', 'theme', 'sky');
		$this->wcms->recursiveDelete(__DIR__ . '/../themes/' . $mine);

		// The active-theme guard applies to themes only: a FILE that happens to share the theme's name can be deleted.
		@mkdir(__DIR__ . '/../data_test/files_test', 0777, true);
		file_put_contents(__DIR__ . '/../data_test/files_test/sky', 'x');
		$_REQUEST = ['deleteModule' => 'sky', 'type' => 'files', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->deleteFileModuleAction();
		} catch (RuntimeException $e) {
		}
		$this->assertFileDoesNotExist(__DIR__ . '/../data_test/files_test/sky');
		$_REQUEST = [];
	}

	public function testTrailingDotsAndSpacesOnTheSkyName(): void
	{
		// Windows drops trailing dots and spaces from a path, so 'sky.' there is the sky folder; elsewhere it is a different name.
		// A trailing space never gets that far on any platform: trim() changes the name, so it is refused as cleaned.
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$_REQUEST = ['deleteModule' => 'sky ', 'type' => 'themes', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->deleteFileModuleAction();
			$this->fail('no redirect for "sky "');
		} catch (RuntimeException $e) {
			$this->assertSame('Invalid file name.', $_SESSION['alert']['danger'][0]['message'] ?? '');
		}
		unset($_SESSION['alert']);
		$_REQUEST = ['deleteModule' => 'sky.', 'type' => 'themes', 'token' => $this->wcms->getToken()];
		if (DIRECTORY_SEPARATOR === '\\') {
			try {
				$this->wcms->deleteFileModuleAction();
				$this->fail('no redirect for "sky."');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('Sky is the fallback theme', $_SESSION['alert']['danger'][0]['message'] ?? '', 'refused with the Sky sentence, not the active-theme one');
			}
		} else {
			mkdir(__DIR__ . '/../themes/sky.'); // a distinct folder here
			try {
				$this->wcms->deleteFileModuleAction();
			} catch (RuntimeException $e) {
			} finally {
				$left = is_dir(__DIR__ . '/../themes/sky.');
				@rmdir(__DIR__ . '/../themes/sky.');
			}
			$this->assertFalse($left, 'deleted as the distinct folder it is');
			$this->assertSame('Deleted sky..', $_SESSION['alert']['success'][0]['message'] ?? '');
		}
		$this->assertFileExists(__DIR__ . '/../themes/sky/theme.php');
	}

	public function testTheDeletedAlertPrintsTheFileNameAsText(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('Windows refuses < > and " in file names');
		}
		$name = '<img src=x onerror="alert(1)">.png'; // no slash, no dots or tilde to clean: it passes the name check and is deleted
		$path = $this->wcms->filesPath . '/' . $name;
		file_put_contents($path, 'x');
		$_REQUEST = ['deleteModule' => $name, 'type' => 'files', 'token' => $this->wcms->getToken()];
		$this->wcms->deleteFileModuleAction();
		$this->assertFileDoesNotExist($path);
		$this->assertSame('Deleted &lt;img src=x onerror=&quot;alert(1)&quot;&gt;.png.', $_SESSION['alert']['success'][0]['message'] ?? '');
	}

	public function testTheHtaccessDeniesIgnoreCase(): void
	{
		// On a case-insensitive disk (Windows, macOS) Apache served /DATABASE.JS while /database.js was refused.
		$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
		preg_match_all('~^RewriteRule (\S+) - \[([^\]]*)\]\r$~m', $htaccess, $m);
		$this->assertSame(['database\.js', '^tests/', 'cache\.json', '^data/logs/'], $m[1], 'dots escaped: with NC an unescaped dot also refused data/files/Database_JS_Tutorial.pdf');
		$this->assertSame(['F,NC', 'F,NC', 'F,NC', 'F,NC'], $m[2]);
		$denied = static function (string $path) use ($m): bool {
			foreach ($m[1] as $pattern) {
				if (preg_match('~' . $pattern . '~i', $path)) {
					return true;
				}
			}
			return false;
		};
		foreach (['data/database.js', 'data/DATABASE.JS', 'data/database.js.lock', 'data/database.js.1a2b3c4d.tmp', 'data/database.js.89abcdef.tmp', 'data/cache.json', 'data/Cache.Json.0f0f0f0f.tmp', 'tests/probe.php', 'data/logs/x.log'] as $path) {
			$this->assertTrue($denied($path), $path);
		}
		foreach (['data/files/Database_JS_Tutorial.pdf', 'data/files/cache_json-notes.pdf', 'data/files/photo.jpg', 'data/files/photos.zip', 'data/files/notes-backup-plan.zip', 'data/files/holiday-backup-2024.zip', 'data/files/site-backup-1.zip', 'data/files/2026-01-01-backup-0123456789abcdef.zip'] as $path) {
			$this->assertFalse($denied($path), $path); // an old backup too: the Files tab links it for its owner
		}
		$this->assertSame(substr_count($htaccess, "\n"), substr_count($htaccess, "\r\n"), 'CRLF line ends kept');
	}

	public function testLogoutIgnoresAnArrayRedirectTarget(): void
	{
		$this->wcms->set('config', 'logoutToLoginScreen', false); // the default (true) never reads ?to, so the guard is only reached with it off
		$this->wcms->currentPage = 'logout';
		$_REQUEST['token'] = $this->wcms->getToken();
		$_GET['to'] = ['https://evil.example'];
		$this->wcms->expects($this->once())->method('redirect')->with($this->wcms->get('config', 'login'));
		$this->wcms->logoutAction();
	}

	public function testSearchToleratesAHandEditedSubpagesArray(): void
	{
		$this->wcms->createPage(['games'], true);
		$db = json_decode(file_get_contents(__DIR__ . '/../data_test/database_test.js'));
		$db->pages->games->subpages = [];
		$db->pages->games->content = 'board games';
		file_put_contents(__DIR__ . '/../data_test/database_test.js', json_encode($db));
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$method = new ReflectionMethod(Wcms::class, 'searchPages');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		$this->assertSame(['games'], array_column($method->invoke($wcms, $wcms->get('pages'), 'board'), 'slug'));
	}

	public function testAFailedUploadMoveReportsFailureAndNothingElse(): void
	{
		// #356 follow-up: the move fails (a CLI file is not an upload), the admin gets the failure and never the old "File uploaded".
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$file = tempnam(sys_get_temp_dir(), 'wcms');
		file_put_contents($file, 'hello');
		$_POST['token'] = $this->wcms->getToken();
		$_FILES['uploadFile'] = ['name' => 'note.txt', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => 5];
		try {
			$this->wcms->uploadFileAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Failed to move', $_SESSION['alert']['danger'][0]['message'] ?? '');
			$this->assertFalse(isset($_SESSION['alert']['success']), 'no success alert after a failed move');
		}
		unlink($file);
	}

	public function testAlertsSitAboveTheSettingsModalAndVisitPageClosesIt(): void
	{
		// #346: the alert wrapper's z-index beats the modal's 1050. #353: the Visit link closes the modal so it does not reopen on arrival.
		$this->wcms->alert('info', 'hello');
		$this->assertStringContainsString('class="alertWrapper" style="z-index:1060"', $this->wcms->alerts());
		$this->assertStringContainsString("querySelector('#settingsModal .close')", $this->wcms->settings());
	}

	public function testSearchSkipsAPageThatIsNotAnObject(): void
	{
		$this->wcms->createPage(['games'], true);
		$db = json_decode(file_get_contents(__DIR__ . '/../data_test/database_test.js'));
		$db->pages->games->content = 'board games';
		$db->pages->broken = null; // a hand-edited database
		file_put_contents(__DIR__ . '/../data_test/database_test.js', json_encode($db));
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$method = new ReflectionMethod(Wcms::class, 'searchPages');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		$this->assertSame(['games'], array_column($method->invoke($wcms, $wcms->get('pages'), 'board'), 'slug'));
	}

	public function testDeleteRefusesAnEmptyOrDotDotName(): void
	{
		// In production redirect() dies; here it throws, so the test proves the guard stops the action.
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		@mkdir(__DIR__ . '/../data_test/files_test', 0777, true);
		file_put_contents(__DIR__ . '/../data_test/files_test/keep.txt', 'x');
		foreach (['', '..', '../'] as $name) {
			unset($_SESSION['alert']);
			$_REQUEST = ['deleteModule' => $name, 'type' => 'files', 'token' => $this->wcms->getToken()];
			try {
				$this->wcms->deleteFileModuleAction();
				$this->fail('no redirect for ' . var_export($name, true));
			} catch (RuntimeException $e) {
				$this->assertSame('Invalid file name.', $_SESSION['alert']['danger'][0]['message'] ?? '', var_export($name, true));
			}
		}
		$this->assertFileExists(__DIR__ . '/../data_test/files_test/keep.txt');
		$this->assertDirectoryExists(__DIR__ . '/../data_test/files_test');
	}
}
