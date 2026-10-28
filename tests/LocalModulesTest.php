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
		mkdir($this->root . '/plugins/broken<b>', 0777, true);
		file_put_contents($this->root . '/plugins/broken<b>/broken<b>.php', '<?php');
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

	public function testLocalPluginsNeedTheirEntryFileAndNamesAreEscaped(): void
	{
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
