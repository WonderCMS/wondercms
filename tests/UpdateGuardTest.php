<?php

use PHPUnit\Framework\TestCase;

/**
 * The updater overwrites index.php with whatever GitHub returns.
 * These tests pin what is accepted and what is refused before that write happens.
 */
final class UpdateGuardTest extends TestCase
{
	/** @var Wcms */
	private $wcms;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$this->wcms = new Wcms('data_test', 'files_test', 'database_test.js');
	}

	private function core(string $version): string
	{
		return "<?php\ndefine('VERSION', '" . $version . "');\nclass Wcms {}\n";
	}

	private function newer(): string
	{
		$parts = explode('.', VERSION);
		$parts[2]++;
		return implode('.', $parts);
	}

	public function testAcceptsCompleteNewerCore(): void
	{
		$this->assertTrue($this->wcms->isValidCoreUpdate($this->core($this->newer())));
	}

	public function testRefusesSameOrOlderVersion(): void
	{
		$this->assertFalse($this->wcms->isValidCoreUpdate($this->core(VERSION)));
		$this->assertFalse($this->wcms->isValidCoreUpdate($this->core('3.0.0')));
	}

	public function testRefusesGitHubErrorBodies(): void
	{
		$this->assertFalse($this->wcms->isValidCoreUpdate('404: Not Found'));
		$this->assertFalse($this->wcms->isValidCoreUpdate('429: Too Many Requests'));
		$this->assertFalse($this->wcms->isValidCoreUpdate('<!DOCTYPE html><html><body>Error</body></html>'));
		$this->assertFalse($this->wcms->isValidCoreUpdate(''));
	}

	public function testRefusesTruncatedCore(): void
	{
		$truncated = substr($this->core($this->newer()), 0, 20);
		$this->assertFalse($this->wcms->isValidCoreUpdate($truncated), 'cut before VERSION');

		$body = "<?php\ndefine('VERSION', '" . $this->newer() . "');\nclass Wcms {\n\tpublic function a() { return 1; }\n";
		$this->assertFalse($this->wcms->isValidCoreUpdate($body), 'cut after class Wcms, unbalanced brace');

		$real = str_replace("'" . VERSION . "'", "'" . $this->newer() . "'", file_get_contents(__DIR__ . '/../index.php'));
		$this->assertTrue($this->wcms->isValidCoreUpdate($real), 'the complete current core, bumped, is accepted');
		$this->assertFalse($this->wcms->isValidCoreUpdate(substr($real, 0, 50000)), 'the same file cut at 50 KB is refused');
	}

	/** Runs tests/probe.php in a separate PHP process so an extension's functions can be disabled. */
	private function probe(string $iniFlags, string $mode, string ...$args): array
	{
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . $iniFlags . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' ' . $mode;
		foreach ($args as $arg) {
			$cmd .= ' ' . escapeshellarg($arg);
		}
		$out = shell_exec($cmd . ' 2>&1');
		$json = json_decode((string)$out, true);
		$this->assertIsArray($json, 'probe output: ' . $out);
		return $json;
	}

	public function testWithoutTokenizerATruncationEndingAtAnInnerBraceIsRefused(): void
	{
		$real = str_replace("'" . VERSION . "'", "'" . $this->newer() . "'", file_get_contents(__DIR__ . '/../index.php'));
		$innerBrace = strpos($real, "\n\t}\n", 60000); // the end of some method deep in the class
		$this->assertNotFalse($innerBrace);
		$files = [
			'complete' => $real,
			'inner brace' => substr($real, 0, $innerBrace + 3),
			'50 KB' => substr($real, 0, 50000),
		];
		$expected = ['complete' => true, 'inner brace' => false, '50 KB' => false];
		foreach ($files as $label => $contents) {
			$path = tempnam(sys_get_temp_dir(), 'wcms');
			file_put_contents($path, $contents);
			$result = $this->probe('-d disable_functions=token_get_all', 'update', $path);
			unlink($path);
			$this->assertFalse($result['tokenizer'], 'the probe must run without the tokenizer');
			$this->assertSame($expected[$label], $result['valid'], $label);
		}
	}

	public function testDailyCoreUpdateNoticeIsShownOnceADayToTheAdminOnly(): void
	{
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['getOfficialVersion', 'getFileFromRepo'])
			->getMock();
		$wcms->method('getOfficialVersion')->willReturn('99.0.0');
		$wcms->method('getFileFromRepo')->willReturn('');
		$notice = static function (): bool {
			foreach ($_SESSION['alert']['info'] ?? [] as $alert) {
				if (strpos($alert['message'], 'New WonderCMS update available') !== false) {
					return true;
				}
			}
			return false;
		};

		$wcms->set('config', 'lastModulesSync', date('Y/m/d', strtotime('-2 days')));
		$wcms->checkModulesCache();
		$this->assertFalse($notice(), 'a visitor never sees the notice');

		$wcms->loggedIn = true;
		$wcms->set('config', 'lastModulesSync', date('Y/m/d', strtotime('-2 days')));
		$wcms->checkModulesCache();
		$this->assertTrue($notice(), 'the admin sees it when the daily check is due');
		$this->assertSame(date('Y/m/d'), $wcms->get('config', 'lastModulesSync'), 'the check is stamped');

		unset($_SESSION['alert']);
		$wcms->checkModulesCache();
		$this->assertFalse($notice(), 'not again the same day');
	}

	protected function tearDown(): void
	{
		unset($_SESSION['alert']);
	}

	public function testRefusesPhpThatIsNotWonderCms(): void
	{
		$this->assertFalse($this->wcms->isValidCoreUpdate("<?php\ndefine('VERSION', '9.9.9');\necho 'hello';\n"));
	}

	public function testOfficialVersionMustBeStrictSemver(): void
	{
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['getCheckFileFromRepo'])
			->getMock();
		$wcms->method('getCheckFileFromRepo')->willReturnOnConsecutiveCalls(
			'3.7.0',
			'429: Too Many Requests',
			'<!DOCTYPE html>',
			'3.7',
			null
		);

		$this->assertSame('3.7.0', $wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
	}
}
