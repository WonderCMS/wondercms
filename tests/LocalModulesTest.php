<?php

use PHPUnit\Framework\TestCase;

/**
 * Themes and plugins copied by hand into themes/ or plugins/ are listed in Settings (from PR #345, Almorca).
 */
final class LocalModulesTest extends TestCase
{
	/** @var Wcms */
	private $wcms;
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$this->root = sys_get_temp_dir() . '/wcms-modules-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/themes/my.theme', 0777, true);
		file_put_contents($this->root . '/themes/my.theme/theme.php', '<?php');
		mkdir($this->root . '/themes/.git', 0777, true);
		mkdir($this->root . '/themes/no-entry-file', 0777, true);
		mkdir($this->root . '/plugins/hello', 0777, true);
		file_put_contents($this->root . '/plugins/hello/hello.php', '<?php');
		file_put_contents($this->root . '/plugins/hello/version', '1.2.3');
		if (DIRECTORY_SEPARATOR === '/') { // < and > are not allowed in a Windows file name
			mkdir($this->root . '/plugins/broken<b>', 0777, true);
			file_put_contents($this->root . '/plugins/broken<b>/broken<b>.php', '<?php');
		}
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$this->wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$this->wcms->method('getModulesCachedData')->willReturn([]);
		$this->wcms->loggedIn = true;
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testLocalThemesWithAnEntryFileAreListedAsInstalled(): void
	{
		$listed = $this->wcms->listAllModules('themes');
		$this->assertSame(['my.theme'], array_keys($listed['exist']));
		$this->assertFalse($listed['exist']['my.theme']['install']);
		$this->assertSame('', $listed['exist']['my.theme']['repo']);
		$this->assertStringContainsString('the official list could not be loaded', $listed['exist']['my.theme']['summary'], 'with no official list nothing is called unofficial');
	}

	public function testAnOfficialThemeFolderWithoutThemePhpIsOfferedForInstallAgain(): void
	{
		mkdir($this->root . '/themes/sky', 0777, true); // a theme folder without theme.php: a broken download or a half-finished FTP upload
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$official = ['name' => 'Sky', 'summary' => 'default', 'repo' => 'https://github.com/robiso/sky', 'zip' => 'z', 'group' => null, 'version' => '3.7.0', 'image' => null];
		$wcms->method('getModulesCachedData')->willReturn(['sky' => $official, 'my.theme' => $official + ['name' => 'Mine']]);
		$wcms->loggedIn = true;
		$listed = $wcms->listAllModules('themes');
		$this->assertTrue($listed['install']['sky']['install'], 'activating it would only fall back to Sky; Install repairs it');
		$this->assertArrayNotHasKey('sky', $listed['exist'] ?? []);
		$this->assertFalse($listed['exist']['my.theme']['install'], 'a folder with theme.php stays installed');
		$this->assertStringContainsString('not in the official list', $wcms->listAllModules('plugins')['exist']['hello']['summary'], 'with a loaded list a stray folder is unofficial');
	}

	public function testAnOfficialThemeFolderWithoutThemePhpCanAlsoBeRemoved(): void
	{
		mkdir($this->root . '/themes/parallax', 0777, true); // no theme.php
		mkdir($this->root . '/themes/sky', 0777, true); // no theme.php either: Sky is the fallback and keeps no Remove button
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$official = ['summary' => 's', 'repo' => 'https://github.com/robiso/x', 'zip' => 'z', 'group' => null, 'version' => '1.0.0', 'image' => null];
		$wcms->method('getModulesCachedData')->willReturn(['parallax' => $official + ['name' => 'Parallax'], 'sky' => $official + ['name' => 'Sky'], 'clean' => $official + ['name' => 'Clean']]);
		$wcms->loggedIn = true;
		$html = $wcms->settings();
		$this->assertStringContainsString('installModule=parallax&type=themes', $html, 'Install repairs it');
		$this->assertStringContainsString('deleteModule=parallax&type=themes', $html, 'Remove clears it');
		$this->assertStringNotContainsString('selectModule=parallax', $html, 'it cannot be activated');
		$this->assertStringNotContainsString('deleteModule=sky&', $html);
		$this->assertStringNotContainsString('deleteModule=clean&', $html, 'an official theme with no folder offers Install only');

		$_REQUEST = ['deleteModule' => 'parallax', 'type' => 'themes', 'token' => $wcms->getToken()];
		try {
			$wcms->deleteFileModuleAction();
		} finally {
			$_REQUEST = [];
			unset($_SESSION['alert'], $_SESSION['token']);
		}
		$this->assertDirectoryDoesNotExist($this->root . '/themes/parallax');
		$this->assertDirectoryExists($this->root . '/themes/sky');
		$this->assertFileExists($this->root . '/themes/my.theme/theme.php');
	}

	public function testLocalPluginsNeedTheirEntryFileAndNamesAreEscaped(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('< and > are not allowed in a Windows file name');
		}
		$listed = $this->wcms->listAllModules('plugins');
		$this->assertArrayHasKey('hello', $listed['exist']);
		$this->assertSame('1.2.3', $listed['exist']['hello']['currentVersion']);
		$this->assertSame('broken<b>', $listed['exist']['broken<b>']['name'], 'the list carries the raw name; settings() escapes it where it prints');
		mkdir($this->root . "/plugins/o'neil", 0777, true);
		file_put_contents($this->root . "/plugins/o'neil/o'neil.php", '<?php');
		file_put_contents($this->root . '/plugins/hello/version', '1.2.3<img src=x onerror=alert(1)>'); // the installed module's own file
		$html = $this->wcms->settings();
		$this->assertStringContainsString('Installed version: 1.2.3&lt;img src=x onerror=alert(1)&gt;', $html, 'the installed version is text too');
		$this->assertStringContainsString('<h4>broken&lt;b&gt;</h4>', $html, 'the card title is escaped at render');
		$this->assertStringContainsString('<h4>o&#039;neil</h4>', $html, 'quotes too, on every PHP version');
		$this->assertStringContainsString('data-confirm="Disable o&#039;neil?"', $html, 'the toggle text is built from the raw name and escaped once');
	}

	public function testTheFilesTabListsNamesThatSortBeforeTheDotEntries(): void
	{
		// scandir() sorts byte-wise: '!' and '-' come before '.', so dropping the first two entries dropped these files instead.
		file_put_contents($this->root . '/data_test/files_test/!x.pdf', 'x'); // the constructor created the folder
		file_put_contents($this->root . '/data_test/files_test/-dash.png', 'x');
		$html = $this->wcms->settings();
		$this->assertStringContainsString('deleteModule=%21x.pdf&type=files', $html);
		$this->assertStringContainsString('deleteModule=-dash.png&type=files', $html);
		$this->assertStringNotContainsString('deleteModule=.&type=files', $html);
		$this->assertStringNotContainsString('deleteModule=..&type=files', $html);
	}

	public function testAVersionThatIsNotTextReadsAsNoVersion(): void
	{
		file_put_contents($this->root . '/plugins/hello/wcms-modules.json', '{"plugins": {"hello": {"version": {"major": 1}}}}');
		$this->assertNull($this->wcms->getModuleVersion('plugins', 'hello'), 'an object there was a TypeError on the ?string return');
		file_put_contents($this->root . '/plugins/hello/wcms-modules.json', '{"plugins": {"hello": {"version": 2.1}}}');
		$this->assertSame('2.1', $this->wcms->getModuleVersion('plugins', 'hello'), 'a number still reads as before');
		$this->assertStringContainsString('hello', $this->wcms->settings());
		file_put_contents($this->root . '/plugins/hello/wcms-modules.json', '{"plugins": {"hello": {"version": ["9.9.9"]}}}');
		$this->assertNull($this->wcms->getModuleVersion('plugins', 'hello'), 'nor a list');
	}

	public function testAListEntryWhoseVersionIsNotTextOffersNoUpdate(): void
	{
		file_put_contents($this->root . '/plugins/hello/wcms-modules.json', '{"plugins": {"hello": {"version": "1.0.0"}}}');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$hostile = ['name' => 'Hello', 'summary' => 's', 'repo' => 'https://example.test/hello', 'zip' => 'z', 'group' => null, 'version' => ['9.9.9'], 'image' => null];
		$wcms->method('getModulesCachedData')->willReturn(['hello' => $hostile]);
		$wcms->loggedIn = true;
		$listed = $wcms->listAllModules('plugins');
		$this->assertFalse($listed['exist']['hello']['update'] ?? false, 'no update offered and no Array to string conversion');
		$this->assertStringContainsString('Hello', $wcms->settings());
	}

	private function probe(string ...$args): array
	{
		return $this->probeDisabling('is_link', ...$args);
	}

	/** Runs tests/probe.php with the functions in $disabled (a comma list) disabled; returns its JSON */
	private function probeDisabling(string $disabled, string ...$args): array
	{
		$cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $disabled . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' ' . implode(' ', array_map('escapeshellarg', $args));
		$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$this->assertStringNotContainsString('Fatal error', $out . $err);
		$json = json_decode($out, true);
		$this->assertIsArray($json, 'probe output: ' . $out . $err);
		return $json;
	}

	public function testAHostThatDisablesIsLinkStillDeletesAndUpdatesModules(): void
	{
		// The link checks on delete and install are guarded; such a host deletes and replaces folders as 3.6.0 did.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		$root = realpath($this->root);
		$result = $this->probe('module-delete', $root, 'plugins', 'hello');
		$this->assertSame('Deleted hello.', $result['alert']['success'][0]['message'] ?? '');
		$this->assertDirectoryDoesNotExist($root . '/plugins/hello');

		mkdir($root . '/themes/probetheme');
		file_put_contents($root . '/themes/probetheme/theme.php', 'old');
		$zipPath = $root . '/probetheme.zip';
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE);
		$zip->addFromString('probetheme-master/theme.php', 'new');
		$zip->close();
		$result = $this->probe('module-install', $root, 'themes', 'probetheme', 'file://' . $zipPath);
		$this->assertArrayNotHasKey('danger', $result['alert'], json_encode($result['alert']));
		$this->assertSame('new', file_get_contents($root . '/themes/probetheme/theme.php'), 'the old folder was replaced');
	}

	/**
	 * Writes a ZIP of name => content (STORE, so the bytes can be doctored) and returns its path.
	 */
	private function moduleZip(array $entries, ?callable $doctor = null): string
	{
		$zipPath = $this->root . '/module-' . bin2hex(random_bytes(3)) . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
			$zip->setCompressionName($name, ZipArchive::CM_STORE);
		}
		$zip->close();
		if ($doctor !== null) {
			file_put_contents($zipPath, $doctor(file_get_contents($zipPath)));
		}
		return $zipPath;
	}

	/**
	 * Runs installUpdateModuleAction() for the theme $name with the module ZIP at $zipPath; returns the alerts it left.
	 * redirect() throws as the real one exits, so a failure branch does not run on into the next one. $tempPath and
	 * $deviceOf, when given, stand in for tempPath() and deviceOf().
	 */
	private function install(string $name, string $zipPath, ?callable $tempPath = null, ?callable $deviceOf = null): array
	{
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(array_merge(['redirect', 'getSingleModuleCachedData'], $tempPath === null ? [] : ['tempPath'], $deviceOf === null ? [] : ['deviceOf']))
			->getMock();
		$wcms->method('getSingleModuleCachedData')->willReturn(['zip' => 'file://' . $zipPath]);
		$wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		if ($tempPath !== null) {
			$wcms->method('tempPath')->willReturnCallback($tempPath);
		}
		if ($deviceOf !== null) {
			$wcms->method('deviceOf')->willReturnCallback($deviceOf);
		}
		$wcms->loggedIn = true;
		unset($_SESSION['alert']);
		$_REQUEST = $_GET = ['installModule' => $name, 'type' => 'themes', 'token' => $wcms->getToken()];
		try {
			$wcms->installUpdateModuleAction();
			$this->fail('the action redirects after its alert');
		} catch (RuntimeException $e) {
			$this->assertSame('redirected', $e->getMessage());
		} finally {
			$_REQUEST = $_GET = [];
		}
		$alerts = $_SESSION['alert'] ?? [];
		unset($_SESSION['alert']);
		return $alerts;
	}

	public function testAnUpdateWhoseArchiveCannotBeExtractedLeavesTheInstalledVersionInPlace(): void
	{
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo/css', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		file_put_contents($this->root . '/themes/foo/css/style.css', 'oldcss');
		// foo-master/css is a file, so the entry under it cannot be created: extractTo() stops there and returns false
		$zipPath = $this->moduleZip(['foo-master/theme.php' => 'new', 'foo-master/css' => 'a file', 'foo-master/css/style.css' => 'newcss']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Could not extract the update. The installed version was left in place.', $alerts['danger'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('success', $alerts);
		$this->assertSame('old', file_get_contents($this->root . '/themes/foo/theme.php'));
		$this->assertSame('oldcss', file_get_contents($this->root . '/themes/foo/css/style.css'));
		$this->assertSame(['css', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), 'nothing merged in');
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo-master', 'the partial copy is removed');
		$this->assertFileDoesNotExist($this->root . '/data_test/files_test/ZIPFromURL.zip');
	}

	public function testAnUpdateWhoseFileLandsShorterThanTheArchiveSaysLeavesTheInstalledVersionInPlace(): void
	{
		// What a disk that fills during the extract looks like: extractTo() returns true and the file is cut short. The ZIP's
		// central directory says theme.php is 20 bytes while its data is 10; libzip hands over the 10 and reports the 20.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		$zipPath = $this->moduleZip(['foo-master/theme.php' => '0123456789'], static function (string $bytes): string {
			$central = strpos($bytes, "PK\x01\x02");
			return substr_replace($bytes, pack('V', 20), $central + 24, 4); // the uncompressed size field of the one entry
		});
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zipPath));
		$this->assertSame(20, $zip->statIndex(0)['size'], 'the doctored size is what statIndex() reports');
		$scratch = $this->root . '/scratch';
		mkdir($scratch);
		if (@$zip->extractTo($scratch) !== true || filesize($scratch . '/foo-master/theme.php') !== 10) {
			$this->markTestSkipped('this libzip refuses the doctored size itself, so the return value already covers it');
		}
		$zip->close();

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Could not extract the update. The installed version was left in place.', $alerts['danger'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('success', $alerts);
		$this->assertSame('old', file_get_contents($this->root . '/themes/foo/theme.php'));
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo-master', 'the truncated copy is removed');
	}

	public function testAnArchiveWhoseTopFolderIsTheModuleNameReplacesTheFolderThroughAStagingFolder(): void
	{
		// Not a GitHub archive: extracting it beside the installed folder would merge into it, so it goes through foo.update.tmp.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		file_put_contents($this->root . '/themes/foo/stale.txt', 'gone after the update');
		$zipPath = $this->moduleZip(['foo/theme.php' => 'new', 'foo/css/style.css' => 'newcss']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('danger', $alerts);
		$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'));
		$this->assertSame('newcss', file_get_contents($this->root . '/themes/foo/css/style.css'));
		$this->assertFileDoesNotExist($this->root . '/themes/foo/stale.txt', 'replaced, not merged');
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo-master');
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo.update.tmp', 'the staging folder is gone');
	}

	public function testAnArchiveWhoseTopFolderIsTheModuleNameAndCannotBeExtractedLeavesTheInstalledVersionInPlace(): void
	{
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo/css', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		file_put_contents($this->root . '/themes/foo/css/style.css', 'oldcss');
		// foo/css is a file, so the entry under it cannot be created: extractTo() stops there and returns false
		$zipPath = $this->moduleZip(['foo/theme.php' => 'new', 'foo/css' => 'a file', 'foo/css/style.css' => 'newcss']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Could not extract the update. The installed version was left in place.', $alerts['danger'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('success', $alerts);
		$this->assertSame('old', file_get_contents($this->root . '/themes/foo/theme.php'));
		$this->assertSame('oldcss', file_get_contents($this->root . '/themes/foo/css/style.css'));
		$this->assertSame(['css', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), 'nothing merged in');
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo.update.tmp', 'the partial copy is removed');
		$this->assertFileDoesNotExist($this->root . '/data_test/files_test/ZIPFromURL.zip');
	}

	public function testAnArchiveWhoseTopFolderIsTheModuleNameAndLandsShortLeavesTheInstalledVersionInPlace(): void
	{
		// The full-disk shape of the test above: extractTo() returns true and the file is cut short.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		$zipPath = $this->moduleZip(['foo/theme.php' => '0123456789'], static function (string $bytes): string {
			$central = strpos($bytes, "PK\x01\x02");
			return substr_replace($bytes, pack('V', 20), $central + 24, 4); // the uncompressed size field of the one entry
		});
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zipPath));
		$this->assertSame(20, $zip->statIndex(0)['size'], 'the doctored size is what statIndex() reports');
		$scratch = $this->root . '/scratch';
		mkdir($scratch);
		if (@$zip->extractTo($scratch) !== true || filesize($scratch . '/foo/theme.php') !== 10) {
			$this->markTestSkipped('this libzip refuses the doctored size itself, so the return value already covers it');
		}
		$zip->close();

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Could not extract the update. The installed version was left in place.', $alerts['danger'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('success', $alerts);
		$this->assertSame('old', file_get_contents($this->root . '/themes/foo/theme.php'));
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo.update.tmp', 'the truncated copy is removed');
	}

	public function testAStaleStagingFolderFromAKilledRequestIsClearedBeforeTheOwnNameArchiveIsExtracted(): void
	{
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		mkdir($this->root . '/themes/foo.update.tmp/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo.update.tmp/foo/leftover.php', 'from a request that was cut short');
		file_put_contents($this->root . '/themes/foo.update.tmp/leftover.php', 'beside it');
		$zipPath = $this->moduleZip(['foo/theme.php' => 'new']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('danger', $alerts);
		$this->assertSame(['theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), 'only the archive\'s files');
		$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'));
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo.update.tmp');
	}

	public function testAHostThatDisablesFilesizeStillMeasuresAnExtractedFileAgainstTheArchive(): void
	{
		// With filesize() in disable_functions every entry used to pass unmeasured, so a truncated archive installed under a
		// success alert. stat() takes over, and file_get_contents() where stat() is disabled too.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		$root = realpath($this->root);
		mkdir($root . '/themes/foo', 0777, true);
		file_put_contents($root . '/themes/foo/theme.php', 'old');
		$zipPath = $this->moduleZip(['foo-master/theme.php' => '0123456789'], static function (string $bytes): string {
			$central = strpos($bytes, "PK\x01\x02");
			return substr_replace($bytes, pack('V', 20), $central + 24, 4); // the uncompressed size field of the one entry
		});
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zipPath));
		$scratch = $root . '/scratch';
		mkdir($scratch);
		if (@$zip->extractTo($scratch) !== true || filesize($scratch . '/foo-master/theme.php') !== 10) {
			$this->markTestSkipped('this libzip refuses the doctored size itself, so the return value already covers it');
		}
		$zip->close();

		foreach (['filesize', 'filesize,stat'] as $disabled) {
			$result = $this->probeDisabling($disabled, 'module-install', $root, 'themes', 'foo', 'file://' . $zipPath);

			$this->assertSame('Could not extract the update. The installed version was left in place.', $result['alert']['danger'][0]['message'] ?? '', $disabled . ': ' . json_encode($result));
			$this->assertArrayNotHasKey('success', $result['alert'], $disabled);
			$this->assertSame('old', file_get_contents($root . '/themes/foo/theme.php'), $disabled . ': the installed version is intact');
			$this->assertDirectoryDoesNotExist($root . '/themes/foo-master', $disabled . ': the truncated copy is removed');
		}
	}

	public function testLinksAtTheStagingAndAsidePathsAreRemovedAndTheirTargetNeverTouched(): void
	{
		// The staging path has no trailing slash and glob() returns a remnant's without one, so is_link() sees the link itself;
		// with one it would follow it and empty the target. A remnant of an earlier update that is a plain folder is cleared.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		mkdir($this->root . '/outside', 0777, true);
		file_put_contents($this->root . '/outside/keep.txt', 'not part of any module');
		foreach (['foo.update.tmp', 'foo.old.0123abcd.tmp'] as $link) {
			symlink($this->root . '/outside', $this->root . '/themes/' . $link);
			$this->assertTrue(is_link($this->root . '/themes/' . $link));
		}
		mkdir($this->root . '/themes/foo.old.89abcdef.tmp');
		file_put_contents($this->root . '/themes/foo.old.89abcdef.tmp/theme.php', 'older');
		$zipPath = $this->moduleZip(['foo/theme.php' => 'new']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertFileExists($this->root . '/outside/keep.txt', 'the link target is never followed');
		$this->assertSame('not part of any module', file_get_contents($this->root . '/outside/keep.txt'));
		$this->assertSame(['keep.txt'], array_values(array_diff(scandir($this->root . '/outside'), ['.', '..'])), 'nothing extracted into the target');
		$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', json_encode($alerts));
		$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'));
		foreach (['foo.update.tmp', 'foo.old.0123abcd.tmp'] as $link) {
			$this->assertFalse(is_link($this->root . '/themes/' . $link), $link . ': the link is removed');
			$this->assertFileDoesNotExist($this->root . '/themes/' . $link);
		}
		$this->assertSame([], glob($this->root . '/themes/foo.old*'), 'the remnant folder and this update\'s aside copy are gone too');
	}

	public function testAnInstalledFolderThatCannotBeEmptiedIsRenamedAsideAndTheUpdateLands(): void
	{
		// A subfolder the PHP user cannot empty (uploaded by FTP as another user, or made read-only) stopped the delete halfway,
		// and rename() then met a non-empty folder and threw, leaving a half-deleted module. Renaming the installed folder aside
		// needs write on themes/ only, so the subfolder moves with it and stays behind in foo.old.<hex>.tmp. 0000: a folder it cannot
		// even open, where the removal of the aside copy stops early and the update still lands.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		foreach ([['foo/', 0555], ['foo-master/', 0555], ['foo/', 0000]] as [$top, $mode]) {
			$case = $top . ' ' . decoct($mode) . ': ';
			mkdir($this->root . '/themes/foo/locked', 0777, true);
			file_put_contents($this->root . '/themes/foo/theme.php', 'old');
			file_put_contents($this->root . '/themes/foo/locked/keep.txt', 'cannot be removed');
			chmod($this->root . '/themes/foo/locked', $mode);
			try {
				if (is_writable($this->root . '/themes/foo/locked')) {
					$this->markTestSkipped('this user writes into a read-only folder (root)');
				}
				$alerts = $this->install('foo', $this->moduleZip([$top . 'theme.php' => 'new', $top . 'css/style.css' => 'newcss']));

				$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', $case . json_encode($alerts));
				$this->assertArrayNotHasKey('danger', $alerts, $case);
				$this->assertSame(['css', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), $case . 'the new copy only');
				$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'), $case);
				$remnants = glob($this->root . '/themes/foo.old.*.tmp') ?: [];
				$this->assertCount(1, $remnants, $case . 'the remnant stays aside');
				$this->assertDirectoryExists($remnants[0] . '/locked', $case);
				if ($mode === 0555) {
					$this->assertSame(['locked'], array_values(array_diff(scandir($remnants[0]), ['.', '..'])), $case . 'the rest of the old copy is gone');
					$this->assertSame('cannot be removed', file_get_contents($remnants[0] . '/locked/keep.txt'), $case);
				}
				$this->assertDirectoryDoesNotExist($this->root . '/themes/foo.update.tmp', $case);
				$this->assertDirectoryDoesNotExist($this->root . '/themes/foo-master', $case);
			} finally {
				$this->removeLockedModule();
			}
		}
	}

	public function testEachUpdateLandsWhileTheRemnantsOfEarlierOnesStayAside(): void
	{
		// The locked folder is uploaded again after each update. The aside name is new for every update, so the remnant of the
		// one before never blocks the next (with one fixed name the second rename aside failed, the delete-first fallback stopped
		// at the locked folder and the rename in threw).
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		try {
			foreach ([1, 2] as $update) {
				mkdir($this->root . '/themes/foo/locked');
				file_put_contents($this->root . '/themes/foo/locked/keep.txt', 'upload ' . $update);
				chmod($this->root . '/themes/foo/locked', 0555);
				if (is_writable($this->root . '/themes/foo/locked')) {
					$this->markTestSkipped('this user writes into a read-only folder (root)');
				}
				$alerts = $this->install('foo', $this->moduleZip(['foo/theme.php' => 'new ' . $update]));

				$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', 'update ' . $update . ': ' . json_encode($alerts));
				$this->assertSame(['theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), 'update ' . $update);
				$this->assertSame('new ' . $update, file_get_contents($this->root . '/themes/foo/theme.php'));
			}
			$kept = [];
			foreach (glob($this->root . '/themes/foo.old.*.tmp') ?: [] as $remnant) {
				$this->assertSame(['locked'], array_values(array_diff(scandir($remnant), ['.', '..'])), 'a remnant holds only what could not be removed');
				$kept[] = file_get_contents($remnant . '/locked/keep.txt');
			}
			sort($kept);
			$this->assertSame(['upload 1', 'upload 2'], $kept, 'each upload stays aside in its own remnant');
		} finally {
			$this->removeLockedModule();
		}
	}

	/** Removes themes/foo and its remnants, a locked subfolder in any of them unlocked first */
	private function removeLockedModule(): void
	{
		foreach (array_merge([$this->root . '/themes/foo'], glob($this->root . '/themes/foo.old*') ?: []) as $folder) {
			if (is_dir($folder . '/locked')) {
				chmod($folder . '/locked', 0755);
			}
			exec('rm -rf ' . escapeshellarg($folder));
		}
	}

	public function testAnInstalledFolderThatCannotBeRenamedAsideIsLeftExactlyAsItWas(): void
	{
		// A locked or immutable folder (chflags uchg, chattr +i), a mount point or a file held open in it on Windows refuses
		// the rename aside; deleting the folder first instead emptied its subfolders before the same refusal stopped the rename
		// in. An aside path in a folder that does not exist is refused on every platform, root included; a tempPath() that
		// throws is random_bytes() with no source of randomness.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		$refusals = [
			'refused rename' => function (string $path): string {
				return $this->root . '/no-such-folder/' . basename($path) . '.tmp';
			},
			'no aside name' => static function (): string {
				throw new Exception('Could not gather sufficient random data');
			},
		];
		foreach (['foo/', 'foo-master/'] as $top) {
			foreach ($refusals as $refusal => $tempPath) {
				$case = $top . ' ' . $refusal . ': ';
				mkdir($this->root . '/themes/foo/css', 0777, true);
				file_put_contents($this->root . '/themes/foo/theme.php', 'old');
				file_put_contents($this->root . '/themes/foo/css/style.css', 'oldcss');

				$alerts = $this->install('foo', $this->moduleZip([$top . 'theme.php' => 'new', $top . 'js/app.js' => 'newjs']), $tempPath);

				$this->assertSame('Could not move the installed version aside. Nothing was changed. Check that themes/foo is not locked or in use, then try again.', $alerts['danger'][0]['message'] ?? '', $case . json_encode($alerts));
				$this->assertArrayNotHasKey('success', $alerts, $case);
				$this->assertSame(['css', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), $case . 'nothing removed or merged in');
				$this->assertSame(['style.css'], array_values(array_diff(scandir($this->root . '/themes/foo/css'), ['.', '..'])), $case);
				$this->assertSame('old', file_get_contents($this->root . '/themes/foo/theme.php'), $case);
				$this->assertSame('oldcss', file_get_contents($this->root . '/themes/foo/css/style.css'), $case);
				$this->assertSame([], array_merge(glob($this->root . '/themes/foo.*') ?: [], glob($this->root . '/themes/foo-*') ?: []), $case . 'no staging, extracted or aside folder is left');
				exec('rm -rf ' . escapeshellarg($this->root . '/themes/foo'));
			}
		}
	}

	public function testAnInstalledFolderThatCannotBeRenamedAcrossDevicesIsDeletedAndReplaced(): void
	{
		// A folder from a lower layer of an overlay filesystem (a Docker image's content) refuses the rename aside with EXDEV, and a
		// delete always works there. /dev is a filesystem of its own on Linux and macOS, so an aside path there gets the same EXDEV.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		foreach (['foo/', 'foo-master/'] as $top) {
			mkdir($this->root . '/themes/foo/css', 0777, true);
			file_put_contents($this->root . '/themes/foo/theme.php', 'old');
			file_put_contents($this->root . '/themes/foo/css/style.css', 'oldcss');

			$alerts = $this->install('foo', $this->moduleZip([$top . 'theme.php' => 'new', $top . 'js/app.js' => 'newjs']), static function (string $path): string {
				return '/dev/' . basename($path) . '.tmp';
			});

			$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', $top . ' ' . json_encode($alerts));
			$this->assertArrayNotHasKey('danger', $alerts, $top);
			$this->assertSame(['js', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), $top . ' the old copy is gone, the new one in place');
			$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'), $top);
			$this->assertSame([], array_merge(glob($this->root . '/themes/foo.*') ?: [], glob($this->root . '/themes/foo-*') ?: []), $top . ' no staging, extracted or aside folder is left');
			exec('rm -rf ' . escapeshellarg($this->root . '/themes/foo'));
		}
	}

	public function testAnInstalledFolderThatCannotBeRenamedAcrossDevicesOrEmptiedIsLeftExactlyAsItWas(): void
	{
		// On the cross-device path nothing is moved aside first, so a subfolder this user cannot empty (a Docker image's content
		// owned by root) stopped the delete halfway: the rename in then met it and threw, with theme.php gone. 0000: one it cannot
		// even open, where the delete threw at once.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		foreach ([['foo/', 0555], ['foo-master/', 0555], ['foo/', 0000]] as [$top, $mode]) {
			$case = $top . ' ' . decoct($mode) . ': ';
			mkdir($this->root . '/themes/foo/locked', 0777, true);
			file_put_contents($this->root . '/themes/foo/theme.php', 'old');
			file_put_contents($this->root . '/themes/foo/locked/keep.txt', 'cannot be removed');
			chmod($this->root . '/themes/foo/locked', $mode);
			try {
				if (is_writable($this->root . '/themes/foo/locked')) {
					$this->markTestSkipped('this user writes into a read-only folder (root)');
				}
				$alerts = $this->install('foo', $this->moduleZip([$top . 'theme.php' => 'new', $top . 'css/style.css' => 'newcss']), static function (string $path): string {
					return '/dev/' . basename($path) . '.tmp';
				});
				chmod($this->root . '/themes/foo/locked', 0755);

				$this->assertSame('Could not move the installed version aside. Nothing was changed. Check that themes/foo is not locked or in use and that the web server can delete everything in it, then try again.', $alerts['danger'][0]['message'] ?? '', $case . json_encode($alerts));
				$this->assertArrayNotHasKey('success', $alerts, $case);
				$this->assertSame(['locked', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), $case . 'nothing removed or merged in');
				$this->assertSame('old', file_get_contents($this->root . '/themes/foo/theme.php'), $case);
				$this->assertSame(['keep.txt'], array_values(array_diff(scandir($this->root . '/themes/foo/locked'), ['.', '..'])), $case);
				$this->assertSame('cannot be removed', file_get_contents($this->root . '/themes/foo/locked/keep.txt'), $case);
				$this->assertSame([], array_merge(glob($this->root . '/themes/foo.*') ?: [], glob($this->root . '/themes/foo-*') ?: []), $case . 'no staging, extracted or aside folder is left');
			} finally {
				$this->removeLockedModule();
			}
		}
	}

	public function testAPartTheCrossDeviceDeleteCannotRemoveIsMergedOverNeverLeftHalfDeleted(): void
	{
		// A subfolder with write but no search permission (0666) passes the writability check, yet nothing in it can be
		// unlinked, so the delete stops halfway (a mount point or another user's file in a sticky folder in a Docker image's
		// module does the same): the rename in then met the folder and threw, with theme.php gone. The new copy is moved in over
		// what is left, as 3.7.1's extract did; an entry it cannot place (one under the stuck folder) is reported.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		foreach ([['foo/', []], ['foo-master/', []], ['foo/', ['locked/new.txt' => 'cannot be placed']]] as [$top, $stuck]) {
			$case = $top . ($stuck ? ' with an entry under the stuck folder: ' : ': ');
			mkdir($this->root . '/themes/foo/locked', 0777, true);
			mkdir($this->root . '/themes/foo/css');
			file_put_contents($this->root . '/themes/foo/theme.php', 'old');
			file_put_contents($this->root . '/themes/foo/css/style.css', 'oldcss');
			file_put_contents($this->root . '/themes/foo/locked/keep.txt', 'cannot be removed');
			chmod($this->root . '/themes/foo/locked', 0666);
			try {
				if (@file_get_contents($this->root . '/themes/foo/locked/keep.txt') !== false) {
					$this->markTestSkipped('this user reads through a folder without search permission (root)');
				}
				$files = [$top . 'theme.php' => 'new', $top . 'css/style.css' => 'newcss', $top . 'js/app.js' => 'newjs'];
				foreach ($stuck as $name => $content) {
					$files[$top . $name] = $content;
				}
				$alerts = $this->install('foo', $this->moduleZip($files), static function (string $path): string {
					return '/dev/' . basename($path) . '.tmp';
				});
				chmod($this->root . '/themes/foo/locked', 0755);

				$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', $case . json_encode($alerts));
				if ($stuck) {
					$this->assertSame('Some of the update could not be moved into themes/foo; the rest is in place. Check that the web server can replace everything in it, then update again.', $alerts['danger'][0]['message'] ?? '', $case . json_encode($alerts));
				} else {
					$this->assertArrayNotHasKey('danger', $alerts, $case);
				}
				$this->assertSame(['css', 'js', 'locked', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])), $case . 'the new copy in place beside the part that stayed');
				$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'), $case);
				$this->assertSame('newcss', file_get_contents($this->root . '/themes/foo/css/style.css'), $case);
				$this->assertSame('newjs', file_get_contents($this->root . '/themes/foo/js/app.js'), $case);
				$this->assertSame(['keep.txt'], array_values(array_diff(scandir($this->root . '/themes/foo/locked'), ['.', '..'])), $case . 'the stuck folder as it was');
				$this->assertSame('cannot be removed', file_get_contents($this->root . '/themes/foo/locked/keep.txt'), $case);
				$this->assertSame([], array_merge(glob($this->root . '/themes/foo.*') ?: [], glob($this->root . '/themes/foo-*') ?: []), $case . 'no staging, extracted or aside folder is left');
			} finally {
				$this->removeLockedModule();
			}
		}
	}

	public function testAFolderOfTheUpdateThatCannotBeRenamedAcrossAMountPointIsMadeThereAndFilled(): void
	{
		// A volume or bind mount on a module subfolder (themes/sky/css) survives the cross-device delete, and rename() moves a file
		// across it (PHP copies, then unlinks) but never a folder: a folder the archive carries under it (css/fonts/) was dropped
		// with the partial alert. A read-only css/ in the new copy refuses the rename of its fonts/ the same way while the files in
		// fonts/ can still move; a stuck subfolder keeps themes/foo/css in place as the mount does.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo/css/locked', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		file_put_contents($this->root . '/themes/foo/css/style.css', 'oldcss');
		file_put_contents($this->root . '/themes/foo/css/locked/keep.txt', 'cannot be removed');
		chmod($this->root . '/themes/foo/css/locked', 0666);
		$newCss = $this->root . '/themes/foo-master/css';
		try {
			if (@file_get_contents($this->root . '/themes/foo/css/locked/keep.txt') !== false) {
				$this->markTestSkipped('this user reads through a folder without search permission (root)');
			}
			$alerts = $this->install('foo', $this->moduleZip(['foo-master/theme.php' => 'new', 'foo-master/css/fonts/a.woff2' => 'newfont']), static function (string $path) use ($newCss): string {
				chmod($newCss, 0555); // runs once the new copy is extracted, before the delete and the merge
				return '/dev/' . basename($path) . '.tmp';
			});
			chmod($this->root . '/themes/foo/css/locked', 0755);

			$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', json_encode($alerts));
			$this->assertArrayNotHasKey('danger', $alerts, 'nothing of the update was left out');
			$this->assertSame(['css', 'theme.php'], array_values(array_diff(scandir($this->root . '/themes/foo'), ['.', '..'])));
			$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'));
			$this->assertSame(['fonts', 'locked'], array_values(array_diff(scandir($this->root . '/themes/foo/css'), ['.', '..'])), 'the folder made beside the part that stayed');
			$this->assertSame('newfont', file_get_contents($this->root . '/themes/foo/css/fonts/a.woff2'));
			$this->assertSame(['keep.txt'], array_values(array_diff(scandir($this->root . '/themes/foo/css/locked'), ['.', '..'])));
		} finally {
			foreach ([$newCss, $this->root . '/themes/foo/css/locked'] as $folder) {
				if (is_dir($folder)) {
					chmod($folder, 0755);
				}
			}
			exec('rm -rf ' . escapeshellarg($this->root . '/themes/foo') . ' ' . escapeshellarg($this->root . '/themes/foo-master'));
		}
	}

	public function testAMergeReplacesALinkAtItsDestinationAndNeverWritesThroughIt(): void
	{
		// What the cross-device delete left in the installed folder can be a link to a folder outside the module: the merge
		// replaces it with the new folder and never merges into it, so nothing of the update lands outside the module.
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		mkdir($this->root . '/outside');
		file_put_contents($this->root . '/outside/keep.txt', 'not part of any module');
		mkdir($this->root . '/themes/foo');
		symlink($this->root . '/outside', $this->root . '/themes/foo/css');
		mkdir($this->root . '/themes/foo-master/css', 0777, true);
		file_put_contents($this->root . '/themes/foo-master/css/style.css', 'newcss');
		$merge = new ReflectionMethod(Wcms::class, 'mergeInto');
		if (PHP_VERSION_ID < 80100) {
			$merge->setAccessible(true);
		}

		$this->assertTrue($merge->invoke($this->wcms, $this->root . '/themes/foo-master', $this->root . '/themes/foo'));

		$this->assertFalse(is_link($this->root . '/themes/foo/css'), 'the link is replaced by the folder');
		$this->assertSame('newcss', file_get_contents($this->root . '/themes/foo/css/style.css'));
		$this->assertSame(['keep.txt'], array_values(array_diff(scandir($this->root . '/outside'), ['.', '..'])), 'nothing written through the link');
		$this->assertSame('not part of any module', file_get_contents($this->root . '/outside/keep.txt'));
	}

	public function testAVolumeMountedInsideTheModuleStaysWhereItIsAndTheUpdateMergesIntoIt(): void
	{
		// A volume mounted on a module subfolder (a Docker -v on themes/sky/css) went with the rename aside, was emptied there and
		// stayed mounted inside the remnant: after a remount the theme had an empty css/. A subfolder on another device now sends
		// the update down the cross-device path, so the mount keeps its place and gets the new files. deviceOf() puts css/ on a
		// device of its own; a stuck subfolder keeps css/ in place through the delete, as a mount point does.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		$css = $this->root . '/themes/foo/css';
		foreach (['foo/', 'foo-master/'] as $top) {
			mkdir($css . '/locked', 0777, true);
			file_put_contents($this->root . '/themes/foo/theme.php', 'old');
			file_put_contents($css . '/style.css', 'oldcss');
			file_put_contents($css . '/locked/keep.txt', 'cannot be removed');
			chmod($css . '/locked', 0666);
			try {
				if (@file_get_contents($css . '/locked/keep.txt') !== false) {
					$this->markTestSkipped('this user reads through a folder without search permission (root)');
				}
				$inode = fileinode($css);
				$alerts = $this->install('foo', $this->moduleZip([$top . 'theme.php' => 'new', $top . 'css/style.css' => 'newcss', $top . 'css/print.css' => 'newprint']), null, static function (string $path) use ($css): int {
					return $path === $css ? 2 : 1;
				});
				clearstatcache();

				$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', $top . ' ' . json_encode($alerts));
				$this->assertArrayNotHasKey('danger', $alerts, $top . ' nothing of the update was left out');
				$this->assertSame($inode, fileinode($css), $top . ' css/ is the same folder, never moved aside');
				$this->assertSame(['locked', 'print.css', 'style.css'], array_values(array_diff(scandir($css), ['.', '..'])), $top);
				$this->assertSame('newcss', file_get_contents($css . '/style.css'), $top);
				$this->assertSame('newprint', file_get_contents($css . '/print.css'), $top);
				$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'), $top);
				$this->assertSame([], array_merge(glob($this->root . '/themes/foo.*') ?: [], glob($this->root . '/themes/foo-*') ?: []), $top . ' no aside copy holding css/, no staging or extracted folder is left');
			} finally {
				foreach (glob($this->root . '/themes/foo*/css/locked') ?: [] as $folder) {
					chmod($folder, 0755);
				}
				exec('rm -rf ' . escapeshellarg($this->root . '/themes') . '/foo*');
			}
		}
	}

	public function testAVolumeMountedInsideTheModuleOnARamDiskStaysWhereItIsAndALinkOnItIsNeverWrittenThrough(): void
	{
		// The same through the real action on a real mount: an HFS+ RAM disk at themes/foo/css (macOS; CI has no hdiutil). Then a
		// link on the volume that cannot be removed: rename() across a mount point copies through a link, so the merge skips it
		// and reports it.
		if (!class_exists('ZipArchive') || !is_executable('/usr/bin/hdiutil') || !is_executable('/sbin/newfs_hfs') || !is_executable('/usr/sbin/diskutil')) {
			$this->markTestSkipped('needs the zip extension, hdiutil, newfs_hfs and diskutil (macOS)');
		}
		$css = $this->root . '/themes/foo/css';
		mkdir($css, 0777, true);
		$disk = trim((string) shell_exec('/usr/bin/hdiutil attach -nomount ram://8192 2>/dev/null'));
		try {
			if (strncmp($disk, '/dev/disk', 9) !== 0) {
				$this->markTestSkipped('no RAM disk could be attached');
			}
			exec('/sbin/newfs_hfs -v wcmscss ' . escapeshellarg($disk) . ' 2>&1 && /usr/sbin/diskutil mount -mountPoint ' . escapeshellarg($css) . ' ' . escapeshellarg($disk) . ' 2>&1', $out, $rc);
			clearstatcache();
			if ($rc !== 0 || stat($css)['dev'] === stat($this->root . '/themes/foo')['dev']) {
				$this->markTestSkipped('the RAM disk could not be mounted: ' . implode(' ', $out));
			}
			foreach (['foo/', 'foo-master/'] as $top) {
				file_put_contents($this->root . '/themes/foo/theme.php', 'old');
				file_put_contents($css . '/style.css', 'oldcss');
				clearstatcache();
				[$inode, $device] = [fileinode($css), stat($css)['dev']];
				$alerts = $this->install('foo', $this->moduleZip([$top . 'theme.php' => 'new', $top . 'css/style.css' => 'newcss', $top . 'css/print.css' => 'newprint']));
				clearstatcache();

				$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', $top . ' ' . json_encode($alerts));
				$this->assertArrayNotHasKey('danger', $alerts, $top . ' nothing of the update was left out');
				$this->assertSame($inode, fileinode($css), $top . ' css/ is the same folder, never moved aside');
				$this->assertSame($device, stat($css)['dev'], $top . ' the volume is still mounted at css/');
				$this->assertSame('newcss', file_get_contents($css . '/style.css'), $top);
				$this->assertSame('newprint', file_get_contents($css . '/print.css'), $top);
				$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'), $top);
				$this->assertSame([], array_merge(glob($this->root . '/themes/foo.*') ?: [], glob($this->root . '/themes/foo-*') ?: []), $top . ' no aside copy holding css/, no staging or extracted folder is left');
			}

			mkdir($this->root . '/outside');
			file_put_contents($this->root . '/outside/passwd', 'secret');
			symlink($this->root . '/outside/passwd', $css . '/link.css');
			exec('chflags -h uchg ' . escapeshellarg($css . '/link.css'));
			mkdir($this->root . '/new');
			file_put_contents($this->root . '/new/link.css', 'newcss');
			$merge = new ReflectionMethod(Wcms::class, 'mergeInto');
			if (PHP_VERSION_ID < 80100) {
				$merge->setAccessible(true);
			}

			$this->assertFalse($merge->invoke($this->wcms, $this->root . '/new', $css), 'the link it cannot remove is reported');
			$this->assertTrue(is_link($css . '/link.css'));
			$this->assertSame('secret', file_get_contents($this->root . '/outside/passwd'), 'nothing written through the link');
		} finally {
			if (strncmp($disk, '/dev/disk', 9) === 0) {
				// a mount carried along by a rename of its parent refuses a detach as busy until it is unmounted by force
				exec('chflags -h nouchg ' . escapeshellarg($css . '/link.css') . ' 2>/dev/null; /sbin/umount -f ' . escapeshellarg($disk) . ' 2>/dev/null; /usr/bin/hdiutil detach ' . escapeshellarg($disk) . ' 2>&1');
			}
		}
	}

	public function testASitePathWithGlobCharactersSweepsOnlyItsOwnLeftovers(): void
	{
		// The sweeps of an update's aside copies and of a save's temp files build their glob() pattern on the site's path:
		// read as a pattern, site[1] missed its own leftovers and matched site1's.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		$root = realpath($this->root);
		$site = $root . '/site[1]';
		mkdir($site . '/themes/foo', 0777, true);
		file_put_contents($site . '/themes/foo/theme.php', 'old');
		mkdir($site . '/themes/foo.old.11111111.tmp');
		mkdir($root . '/site1/themes/foo.old.22222222.tmp', 0777, true);

		$result = $this->probeDisabling('', 'module-install', $site, 'themes', 'foo', 'file://' . $this->moduleZip(['foo/theme.php' => 'new']));

		$this->assertSame('Successfully installed/updated foo.', $result['alert']['success'][0]['message'] ?? '', json_encode($result));
		$this->assertSame('new', file_get_contents($site . '/themes/foo/theme.php'));
		$this->assertDirectoryDoesNotExist($site . '/themes/foo.old.11111111.tmp', 'its own remnant is swept');
		$this->assertDirectoryExists($root . '/site1/themes/foo.old.22222222.tmp', 'another site\'s is not matched');

		$own = $site . '/data_test/database_probe.js.0123abcd.tmp';
		$other = $root . '/site1/data_test/database_probe.js.0123abcd.tmp';
		mkdir(dirname($other));
		foreach ([$own, $other] as $temp) {
			file_put_contents($temp, 'old copy');
			touch($temp, time() - 7200);
		}
		(new Wcms('data_test', 'files_test', 'database_probe.js', $site))->set('config', 'siteTitle', 'swept');
		clearstatcache();
		$this->assertFileDoesNotExist($own, 'its own leftover temp file is swept');
		$this->assertFileExists($other, 'another site\'s is not matched');
	}

	public function testAFreshInstallWhoseArchiveCannotBeExtractedInstallsNothingAndSaysSo(): void
	{
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		$zipPath = $this->moduleZip(['foo-master/theme.php' => 'new', 'foo-master/css' => 'a file', 'foo-master/css/style.css' => 'newcss']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Could not extract the download. Nothing was installed.', $alerts['danger'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('success', $alerts);
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo');
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo-master');
	}

	public function testAFolderUnderTheOtherSuffixBesideAMainArchiveIsLeftAloneAndTheExtractedFolderIsTheOneRenamedIn(): void
	{
		// A folder under the other suffix (the module's default branch was renamed since, or a hand-made upload) must not be
		// the folder that ends up live, and it is not this archive's to remove.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		mkdir($this->root . '/themes/foo-master', 0777, true);
		file_put_contents($this->root . '/themes/foo-master/theme.php', 'other');
		$zipPath = $this->moduleZip(['foo-main/theme.php' => 'new']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('danger', $alerts);
		$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'), 'the extracted folder is the one renamed in');
		$this->assertSame('other', file_get_contents($this->root . '/themes/foo-master/theme.php'), 'the folder under the other suffix is left intact');
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo-main');
	}

	public function testAModuleLivingUnderTheOtherSuffixSurvivesAnUpdateOfTheModuleItIsNamedAfter(): void
	{
		// themes/foo-main is a theme of its own (a GitHub ZIP uploaded by hand and never renamed works under that name);
		// updating foo from a -master archive must not touch it.
		if (!class_exists('ZipArchive') || DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('needs the zip extension and a file:// path without a drive letter');
		}
		mkdir($this->root . '/themes/foo', 0777, true);
		file_put_contents($this->root . '/themes/foo/theme.php', 'old');
		mkdir($this->root . '/themes/foo-main/css', 0777, true);
		file_put_contents($this->root . '/themes/foo-main/theme.php', 'live under the other suffix');
		file_put_contents($this->root . '/themes/foo-main/css/style.css', 'its css');
		$zipPath = $this->moduleZip(['foo-master/theme.php' => 'new']);

		$alerts = $this->install('foo', $zipPath);

		$this->assertSame('Successfully installed/updated foo.', $alerts['success'][0]['message'] ?? '', json_encode($alerts));
		$this->assertArrayNotHasKey('danger', $alerts);
		$this->assertSame('new', file_get_contents($this->root . '/themes/foo/theme.php'), 'foo is updated');
		$this->assertSame('live under the other suffix', file_get_contents($this->root . '/themes/foo-main/theme.php'), 'foo-main is untouched');
		$this->assertSame('its css', file_get_contents($this->root . '/themes/foo-main/css/style.css'));
		$this->assertDirectoryDoesNotExist($this->root . '/themes/foo-master');
	}

	public function testTheFilesTabSortsNaturallyAndIgnoresCase(): void
	{
		foreach (['photo10.png', 'Photo2.png', 'photo1.png', 'apple.png'] as $name) {
			file_put_contents($this->root . '/data_test/files_test/' . $name, 'x');
		}
		preg_match_all('~deleteModule=([^&]+)&type=files~', $this->wcms->settings(), $m);
		$this->assertSame(['apple.png', 'photo1.png', 'Photo2.png', 'photo10.png'], $m[1], 'scandir() alone gives Photo2, apple, photo1, photo10');
	}

	public function testTheFilesTabSortsAnAccentedNameAmongItsLetter(): void
	{
		if (!extension_loaded('intl')) {
			$this->markTestSkipped('without intl the Files tab sorts byte-wise past ASCII, as 3.7.0 did');
		}
		foreach (['zebra.png', 'Édith.png', 'apple.png', 'photo10.png', 'photo2.png'] as $name) {
			file_put_contents($this->root . '/data_test/files_test/' . $name, 'x');
		}
		preg_match_all('~deleteModule=([^&]+)&type=files~', $this->wcms->settings(), $m);
		$this->assertSame(['apple.png', 'Édith.png', 'photo2.png', 'photo10.png', 'zebra.png'], array_map('rawurldecode', $m[1]), 'natcasesort() alone puts Édith after zebra');
	}

	/**
	 * The stub Collator cannot be undeclared, so it lives and dies in its own process (the annotations for PHPUnit 9.6, the attributes for 11.5)
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function testAPolyfillCollatorWithoutIntlIsNotUsed(): void
	{
		if (extension_loaded('intl')) {
			$this->markTestSkipped('intl is loaded, so no stub Collator can be declared');
		}
		if (!class_exists('Collator')) {
			eval('class Collator { public function __construct($locale) { throw new RuntimeException("polyfill Collator"); } }');
		}
		foreach (['zebra.png', 'Édith.png', 'apple.png'] as $name) {
			file_put_contents($this->root . '/data_test/files_test/' . $name, 'x');
		}
		preg_match_all('~deleteModule=([^&]+)&type=files~', $this->wcms->settings(), $m);
		$this->assertSame(['apple.png', 'zebra.png', 'Édith.png'], array_map('rawurldecode', $m[1]), 'natcasesort(), as 3.7.0');
	}

	public function testAFileNameThatIsNotUtf8SortsTheListAsNatcasesort(): void
	{
		$names = ['zebra.png', "\xE9dith.png", 'apple.png', 'photo10.png', 'Photo2.png'];
		foreach ($names as $name) {
			if (@file_put_contents($this->root . '/data_test/files_test/' . $name, 'x') === false) {
				$this->markTestSkipped('this file system refuses a name that is not UTF-8');
			}
		}
		if (!in_array("\xE9dith.png", scandir($this->root . '/data_test/files_test'), true)) {
			$this->markTestSkipped('this filesystem rewrites non-UTF-8 names');
		}
		natcasesort($names);
		preg_match_all('~deleteModule=([^&]+)&type=files~', $this->wcms->settings(), $m);
		$this->assertSame(array_values($names), array_map('rawurldecode', $m[1]));
	}

	public function testAThemeFolderNameThatFoldsToSkyIsRefusedLikeSky(): void
	{
		// macOS resolves 'ſky' and 'SKY' with a Kelvin sign (U+212A) to sky/; strcasecmp() compares bytes and let both through.
		$name = "S\u{212A}Y";
		mkdir($this->root . '/themes/' . $name, 0777, true);
		file_put_contents($this->root . '/themes/' . $name . '/theme.php', '<?php');
		$html = $this->wcms->settings();
		$this->assertStringContainsString('<h4>' . $name . '</h4>', $html, 'listed');
		$this->assertStringNotContainsString('deleteModule=' . rawurlencode($name) . '&', $html);
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		foreach ([$name, 'ſky'] as $request) {
			unset($_SESSION['alert']);
			$_REQUEST = ['deleteModule' => $request, 'type' => 'themes', 'token' => $this->wcms->getToken()];
			try {
				$this->wcms->deleteFileModuleAction();
				$this->fail('no redirect');
			} catch (RuntimeException $e) {
				$this->assertStringStartsWith('Sky is the fallback theme', $_SESSION['alert']['danger'][0]['message'] ?? '', $request);
			}
		}
		$this->assertFileExists($this->root . '/themes/' . $name . '/theme.php');
		$_REQUEST = [];
		unset($_SESSION['alert']);
	}

	public function testADanglingSymlinkIsRemovedLikeAnyEntry(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		foreach (['themes' => '/themes/', 'plugins' => '/plugins/', 'files' => '/data_test/files_test/'] as $type => $folder) {
			$link = $this->root . $folder . 'gone-' . $type;
			symlink($this->root . '/no-such-target', $link); // realpath() is false for it
			unset($_SESSION['alert']);
			$_REQUEST = ['deleteModule' => basename($link), 'type' => $type, 'token' => $this->wcms->getToken()];
			$this->wcms->deleteFileModuleAction();
			$this->assertFalse(is_link($link), $type);
			$this->assertSame('Deleted ' . basename($link) . '.', $_SESSION['alert']['success'][0]['message'] ?? '', $type);
		}
		$this->assertDirectoryDoesNotExist($this->root . '/no-such-target', 'nothing is created behind the link');
		$_REQUEST = [];
		unset($_SESSION['alert']);
	}

	public function testADanglingSymlinkIsListedWithARemoveButtonOnly(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		symlink($this->root . '/no-such-target', $this->root . '/themes/gone-theme');
		symlink($this->root . '/no-such-target', $this->root . '/plugins/gone-plugin');
		symlink($this->root . '/no-such-target', $this->root . '/themes/sky');
		foreach (['themes' => 'gone-theme', 'plugins' => 'gone-plugin'] as $type => $name) {
			$entry = $this->wcms->listAllModules($type)['exist'][$name] ?? null;
			$this->assertIsArray($entry, $type);
			$this->assertFalse($entry['install'], $type);
			$this->assertNull($entry['currentVersion'], $type);
			$this->assertSame('A link whose target is gone (found in the ' . $type . ' folder).', $entry['summary']);
		}
		$html = $this->wcms->settings();
		$this->assertStringContainsString('deleteModule=gone-theme&type=themes', $html);
		$this->assertStringContainsString('deleteModule=gone-plugin&type=plugins', $html);
		$this->assertStringNotContainsString('selectModule=gone-theme&', $html, 'nothing to activate');
		$this->assertStringNotContainsString('togglePlugin=gone-plugin&', $html, 'nothing to enable or disable');
		$this->assertStringContainsString('<h4>sky</h4>', $html, 'a dangling sky link is listed');
		$this->assertStringNotContainsString('deleteModule=sky&', $html, 'and keeps the Sky refusal');
		$this->assertStringNotContainsString('no-entry-file', $html, 'a folder without its entry file is still not listed');

		foreach (['themes' => 'gone-theme', 'plugins' => 'gone-plugin'] as $type => $name) {
			$_REQUEST = ['deleteModule' => $name, 'type' => $type, 'token' => $this->wcms->getToken()];
			$this->wcms->deleteFileModuleAction();
			$this->assertFalse(is_link($this->root . '/' . $type . '/' . $name), $type);
		}
		$_REQUEST = [];
		unset($_SESSION['alert']);
		$this->assertSame(['.git', 'my.theme', 'no-entry-file', 'sky'], array_values(array_diff(scandir($this->root . '/themes'), ['.', '..'])));
		$this->assertTrue(is_link($this->root . '/themes/sky'));
		$this->assertFileExists($this->root . '/themes/my.theme/theme.php');
		$this->assertFileExists($this->root . '/plugins/hello/hello.php');
		$this->assertFileDoesNotExist($this->root . '/no-such-target');
	}

	public function testADanglingSymlinkOnTheOfficialListOffersInstallAndRemove(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		symlink($this->root . '/no-such-target', $this->root . '/themes/clean');
		mkdir($this->root . '/themes/parallax', 0777, true); // no theme.php
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$official = ['summary' => 's', 'repo' => 'https://github.com/robiso/x', 'zip' => 'z', 'group' => null, 'version' => '1.0.0', 'image' => null];
		$wcms->method('getModulesCachedData')->willReturn(['clean' => $official + ['name' => 'Clean'], 'parallax' => $official + ['name' => 'Parallax'], 'other' => $official + ['name' => 'Other']]);
		$wcms->loggedIn = true;
		$listed = $wcms->listAllModules('themes');
		$this->assertTrue($listed['install']['clean']['install'], 'Install replaces the link');
		$this->assertArrayNotHasKey('clean', $listed['exist']);
		$this->assertTrue($listed['install']['parallax']['install'], 'a folder without theme.php still gets Install');
		$html = $wcms->settings();
		$this->assertStringContainsString('installModule=clean&type=themes', $html);
		$this->assertStringContainsString('deleteModule=clean&type=themes', $html);
		$this->assertStringContainsString('installModule=parallax&type=themes', $html);
		$this->assertStringNotContainsString('deleteModule=other&', $html, 'an official theme with nothing on disk offers Install only');
	}

	public function testAHostThatDisablesIsLinkListsModulesAsBefore(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		symlink($this->root . '/no-such-target', $this->root . '/themes/gone-theme');
		$list = $this->probe('module-list', realpath($this->root), 'themes')['list'];
		$this->assertSame(['my.theme'], array_keys($list['exist']), 'without is_link() a dangling link is skipped, as 3.7.0 did');
	}

	public function testAThemeFolderNamedSkyInAnyCaseHasNoRemoveButton(): void
	{
		mkdir($this->root . '/themes/Sky', 0777, true); // a distinct folder on a case-sensitive disk, the sky folder on Windows and macOS
		file_put_contents($this->root . '/themes/Sky/theme.php', '<?php');
		if (@mkdir($this->root . '/themes/ſky', 0777, true)) { // folds to sky under Unicode case folding; a byte-wise compare lets it through. macOS and Windows resolve the name to sky/ (mkdir fails), so this part bites on a case-sensitive disk, which is what CI runs
			file_put_contents($this->root . '/themes/ſky/theme.php', '<?php');
		}
		$html = $this->wcms->settings();
		$this->assertStringContainsString('<h4>Sky</h4>', $html, 'listed');
		$this->assertStringNotContainsString('deleteModule=Sky&', $html);
		$this->assertStringNotContainsString('deleteModule=' . rawurlencode('ſky') . '&', $html, 'a folder that folds to sky has no Remove button either');
		$this->assertStringContainsString('deleteModule=my.theme&', $html, 'another hand-uploaded theme keeps its Remove button');
	}

	public function testThePageDeleteConfirmsAreDataAttributesTheTranslationPluginsMatch(): void
	{
		// The translation plugins' rows match data-confirm="Delete (.*?)\?"; the inline confirm('Delete ...?') they also
		// carry rows for is gone, so a broken row of that shape can no longer strip the confirmation.
		$this->wcms->currentPage = 'how-to';
		$this->wcms->currentPageTree = ['how-to'];
		$this->wcms->currentPageExists = true;
		$html = $this->wcms->settings();
		$this->assertStringContainsString('title="Delete page" data-confirm="Delete how-to?" onclick="return confirm(this.dataset.confirm)"', $html, 'the Delete page button');
		$this->assertStringContainsString('data-confirm="Delete home?" onclick="return confirm(this.dataset.confirm)"', $html, 'the menu row delete');
		$this->assertStringNotContainsString("return confirm('Delete", $html);
	}

	public function testAFolderThatIsAlsoOnTheOfficialListIsListedOnceAsOfficial(): void
	{
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$wcms->method('getModulesCachedData')->willReturn(['hello' => ['name' => 'Hello (official)', 'summary' => 'says <b>hi</b>', 'repo' => 'https://github.com/x/hello', 'zip' => 'z', 'group' => null, 'version' => '1.2.3', 'image' => null]]);
		$wcms->loggedIn = true;
		$listed = $wcms->listAllModules('plugins');
		$this->assertSame('Hello (official)', $listed['exist']['hello']['name'], 'the official entry wins; the folder scan does not add a second card');
		$this->assertSame('https://github.com/x/hello', $listed['exist']['hello']['repo']);
		$html = $wcms->settings();
		$this->assertStringContainsString("<p class='normalFont'>says &lt;b&gt;hi&lt;/b&gt;</p>", $html, 'the CDN summary is text, not markup');
	}

	public function testExclusiveGroupConfirmAndImageAltAreBuiltFromTheRawNameAndEscapedOnce(): void
	{
		mkdir($this->root . '/plugins/hello-editor', 0777, true);
		file_put_contents($this->root . '/plugins/hello-editor/hello-editor.php', '<?php');
		mkdir($this->root . '/plugins/rich-editor', 0777, true);
		file_put_contents($this->root . '/plugins/rich-editor/rich-editor.php', '<?php');
		file_put_contents($this->root . '/plugins/rich-editor/version', '1.0.0');
		mkdir($this->root . "/plugins/o'two-editor", 0777, true);
		file_put_contents($this->root . "/plugins/o'two-editor/o'two-editor.php", '<?php');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$wcms->method('getModulesCachedData')->willReturn([
			'o-editor' => ['name' => "O'Reilly Editor", 'summary' => 's', 'repo' => 'https://github.com/x/o-editor', 'zip' => 'z', 'group' => 'editor', 'version' => '1.0.0', 'image' => 'https://example.com/p.png'],
			'rich-editor' => ['name' => 'Rich & Co Editor', 'summary' => 's', 'repo' => 'https://github.com/x/rich-editor', 'zip' => 'z', 'group' => 'editor', 'version' => '2.0.0', 'image' => null],
		]);
		$wcms->loggedIn = true;
		$wcms->set('config', 'disabledPlugins', ["o'two-editor"]);
		$wcms->installedPlugins = ['hello-editor', 'rich-editor', "o'two-editor"]; // hello-editor and rich-editor enabled, o'two-editor disabled; groups from the name or the list
		$html = $wcms->settings();
		$this->assertStringContainsString('data-confirm="This is an editor plugin. Installing O&#039;Reilly Editor will disable ', $html, 'the install confirm is escaped once');
		$this->assertStringContainsString('alt="O&#039;Reilly Editor"', $html, 'the image alt is escaped once');
		$this->assertStringContainsString('data-confirm="This is an editor plugin. Updating Rich &amp; Co Editor will keep it enabled and disable hello-editor. Continue?"', $html, 'the update confirm is escaped once');
		$this->assertStringContainsString('data-confirm="This is an editor plugin. Enabling o&#039;two-editor will disable ', $html, 'the enable confirm is built from the raw name and escaped once');
	}

	public function testHostileListMetadataIsPrintedAsTextAndOnlyHttpLinksAreLinked(): void
	{
		// Two list entries come from third-party repositories; their fields are text and links, never markup or script.
		mkdir($this->root . '/plugins/a&b', 0777, true);
		file_put_contents($this->root . '/plugins/a&b/a&b.php', '<?php');
		file_put_contents($this->root . '/plugins/a&b/version', '1.0.0');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect', 'getModulesCachedData'])
			->getMock();
		$wcms->method('getModulesCachedData')->willReturn([
			'evil' => ['name' => 'Evil', 'summary' => 's', 'repo' => 'javascript:alert(1)', 'zip' => 'z', 'group' => null, 'version' => '1.0.0', 'image' => 'x" onerror="alert(1)'],
			'a&b' => ['name' => 'A and B', 'summary' => 's', 'repo' => 'https://github.com/x/a?b=1&c=2', 'zip' => 'z', 'group' => null, 'version' => '2.0.0"><script>x</script>', 'image' => 'https://example.com/p.png?a=1&b=2'],
			'up&case' => ['name' => 'Up', 'summary' => 's', 'repo' => 'HTTPS://github.com/x/up', 'zip' => 'z', 'group' => null, 'version' => '1.0.0', 'image' => 'HTTP://github.com/x/up.png'],
			'anchored' => ['name' => 'Anchored', 'summary' => 's', 'repo' => 'javascript:alert(1)//https://x', 'zip' => 'z', 'group' => null, 'version' => '1.0.0', 'image' => 'javascript:alert(1)//https://x.png'],
		]);
		$wcms->loggedIn = true;
		$html = $wcms->settings();
		$this->assertStringNotContainsString('javascript:', $html, 'a non-http repo URL is not linked');
		$this->assertStringNotContainsString('onerror=', $html, 'a non-http image URL is dropped');
		$this->assertStringNotContainsString('<script>x</script>', $html, 'the version is text');
		$this->assertStringContainsString('href="https://github.com/x/a?b=1&amp;c=2"', $html, 'an http repo URL is linked, escaped');
		$this->assertStringContainsString('src="https://example.com/p.png?a=1&amp;b=2"', $html, 'an http image URL is shown, escaped');
		$this->assertStringContainsString('installModule=a%26b&type=plugins', $html, 'the list key is URL-encoded in the Update link');
		$this->assertStringContainsString('installModule=up%26case&type=plugins', $html, 'and in the Install link');
		$this->assertStringContainsString('href="HTTPS://github.com/x/up"', $html, 'the scheme check is case-insensitive');
		$this->assertStringContainsString('src="HTTP://github.com/x/up.png"', $html, 'for the image too');
		$this->assertStringNotContainsString('alert(1)//https', $html, 'the scheme must be at the start of the URL');
		$this->assertStringContainsString('Update to 2.0.0&quot;&gt;&lt;script&gt;x&lt;/script&gt;<', $html, 'the version is escaped where it is printed');
	}
}
