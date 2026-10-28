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
	}

	public function testLocalPluginsNeedTheirEntryFileAndNamesAreEscaped(): void
	{
		$listed = $this->wcms->listAllModules('plugins');
		$this->assertArrayHasKey('hello', $listed['exist']);
		$this->assertSame('1.2.3', $listed['exist']['hello']['currentVersion']);
		$this->assertSame('broken&lt;b&gt;', $listed['exist']['broken<b>']['name']);
	}
}
