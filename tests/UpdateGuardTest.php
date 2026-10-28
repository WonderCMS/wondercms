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
		$real = str_replace(["\r\n", "'" . VERSION . "'"], ["\n", "'" . $this->newer() . "'"], file_get_contents(__DIR__ . '/../index.php')); // LF even on a CRLF checkout
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

	/** A throwaway copy of the site in a temp folder; returns its path. */
	private function siteCopy(): string
	{
		$dir = sys_get_temp_dir() . '/wcms-update-' . bin2hex(random_bytes(4));
		mkdir($dir);
		copy(__DIR__ . '/../index.php', $dir . '/index.php');
		return $dir;
	}

	private function removeDir(string $dir): void
	{
		chmod($dir, 0755);
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
			$f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
		}
		rmdir($dir);
	}

	public function testUpdateReplacesTheWholeFileOrNothing(): void
	{
		$real = str_replace("'" . VERSION . "'", "'" . $this->newer() . "'", file_get_contents(__DIR__ . '/../index.php'));
		$newCore = tempnam(sys_get_temp_dir(), 'wcms');
		file_put_contents($newCore, $real);
		$truncated = tempnam(sys_get_temp_dir(), 'wcms');
		file_put_contents($truncated, substr($real, 0, 50000));

		// 1. A valid newer core replaces index.php; no temp file is left behind; the file keeps its mode.
		$site = $this->siteCopy();
		chmod($site . '/index.php', 0604);
		$inode = fileinode($site . '/index.php');
		$result = $this->probe('', 'update-run', $site, $newCore);
		$this->assertSame(hash('sha256', $real), $result['sha'], 'index.php is the downloaded core');
		$this->assertFalse($result['tmpLeft']);
		if (DIRECTORY_SEPARATOR === '/') {
			clearstatcache();
			$this->assertNotSame($inode, fileinode($site . '/index.php'), 'the rename ran, not the in-place write');
			$this->assertSame(0604, fileperms($site . '/index.php') & 0777, 'the mode survives the rename');
		}
		$this->assertContains('success', $result['alerts']); // the probe's redirect() is a no-op, so the generic failure alert follows
		$this->removeDir($site);

		// 2. A truncated download leaves index.php untouched.
		$site = $this->siteCopy();
		$before = hash_file('sha256', $site . '/index.php');
		$result = $this->probe('', 'update-run', $site, $truncated);
		$this->assertSame($before, $result['sha'], 'index.php unchanged');
		$this->assertFalse($result['tmpLeft']);
		$this->assertNotContains('success', $result['alerts']);
		$this->removeDir($site);

		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			unlink($newCore);
			unlink($truncated);
			$this->markTestIncomplete('running as root: read-only files and folders cannot be simulated');
		}

		// 3. The temp file exists but cannot be written (what a full disk looks like to file_put_contents): index.php untouched.
		$site = $this->siteCopy();
		$before = hash_file('sha256', $site . '/index.php');
		file_put_contents($site . '/index.php.tmp', '');
		chmod($site . '/index.php.tmp', 0444);
		$result = $this->probe('', 'update-run', $site, $newCore);
		$this->assertSame($before, $result['sha'], 'a failed temp write must never fall through to the direct write');
		$this->assertNotContains('success', $result['alerts']);
		if (DIRECTORY_SEPARATOR === '/') {
			$this->assertFalse($result['tmpLeft'], 'the unwritable temp file is removed'); // Windows cannot unlink a read-only file; a real full disk leaves a writable one
		} else {
			@chmod($site . '/index.php.tmp', 0644);
		}
		$this->removeDir($site);

		// 4. index.php in another group than the one a new file would get: written in place, group and inode kept.
		$site = $this->siteCopy();
		$otherGroups = function_exists('posix_getgroups') ? array_diff(posix_getgroups(), [filegroup($site . '/index.php')]) : [];
		if ($otherGroups !== [] && @chgrp($site . '/index.php', reset($otherGroups))) {
			clearstatcache();
			$group = filegroup($site . '/index.php');
			$inode = fileinode($site . '/index.php');
			$result = $this->probe('', 'update-run', $site, $newCore);
			clearstatcache();
			$this->assertSame(hash('sha256', $real), $result['sha'], 'updated in place');
			$this->assertSame($group, filegroup($site . '/index.php'), 'the group is kept');
			$this->assertSame($inode, fileinode($site . '/index.php'), 'in place, no rename');
			$this->assertFalse($result['tmpLeft']);
		}
		$this->removeDir($site);

		// 5. A folder that is not writable (temp file impossible) still updates through the direct write.
		$site = $this->siteCopy();
		$this->probe('', 'update-run', $site, $truncated); // a first run creates the folders the constructor needs
		chmod($site, 0555);
		$result = $this->probe('', 'update-run', $site, $newCore);
		$this->assertSame(hash('sha256', $real), $result['sha'], 'direct write fallback');
		$this->assertFalse($result['tmpLeft']);
		$this->removeDir($site);
		unlink($newCore);
		unlink($truncated);
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
