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
		$cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=is_link ' . escapeshellarg(__DIR__ . '/probe.php') . ' ' . implode(' ', array_map('escapeshellarg', $args));
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

	public function testAThemeFolderNamedSkyInAnyCaseHasNoRemoveButton(): void
	{
		mkdir($this->root . '/themes/Sky', 0777, true); // a distinct folder on a case-sensitive disk, the sky folder on Windows and macOS
		file_put_contents($this->root . '/themes/Sky/theme.php', '<?php');
		$html = $this->wcms->settings();
		$this->assertStringContainsString('<h4>Sky</h4>', $html, 'listed');
		$this->assertStringNotContainsString('deleteModule=Sky&', $html);
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
