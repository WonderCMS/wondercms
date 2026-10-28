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

	public function testTheFailureAlertNamesTheCause(): void
	{
		// A refused download must not send the admin to check disk space; a failed write must.
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['redirect', 'getFileFromRepo'])
			->getMock();
		$wcms->method('getFileFromRepo')->willReturn('<!DOCTYPE html>Error');
		$wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$wcms->loggedIn = true;
		$_POST = ['update' => '1', 'token' => $wcms->getToken()];
		try {
			$wcms->updateAction();
		} catch (RuntimeException $e) {
		}
		$message = $_SESSION['alert']['danger'][0]['message'] ?? '';
		$this->assertStringContainsString('not a complete, newer WonderCMS core', $message);
		$this->assertStringNotContainsString('disk space', $message);
		$_POST = [];
		unset($_SESSION['alert']);
	}

	public function testRefusesACoreThatDoesNotStartWithTheOpeningTag(): void
	{
		// Bytes before <?php (a BOM, a space, an HTML comment) would be sent to the browser before the session starts.
		$core = $this->core($this->newer());
		$this->assertFalse($this->wcms->isValidCoreUpdate("\xEF\xBB\xBF" . $core), 'byte-order mark');
		$this->assertFalse($this->wcms->isValidCoreUpdate(' ' . $core), 'leading space');
		$this->assertFalse($this->wcms->isValidCoreUpdate('<!-- x -->' . $core), 'leading HTML');
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
		$this->assertSame(['success'], $result['alerts'], 'one alert, even though the probe\'s redirect() does not exit');
		$this->removeDir($site);

		// 2. A truncated download leaves index.php untouched.
		$site = $this->siteCopy();
		$before = hash_file('sha256', $site . '/index.php');
		$result = $this->probe('', 'update-run', $site, $truncated);
		$this->assertSame($before, $result['sha'], 'index.php unchanged');
		$this->assertFalse($result['tmpLeft']);
		$this->assertNotContains('success', $result['alerts']);
		$this->assertStringContainsString('not a complete, newer WonderCMS core', $result['message'], 'a refused download names the download, not the disk');
		$this->removeDir($site);

		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			unlink($newCore);
			unlink($truncated);
			$this->markTestIncomplete('running as root: read-only files and folders cannot be simulated');
		}

		// 3. The temp path cannot be written (a folder sits there; what a full disk looks like to file_put_contents): index.php untouched.
		$site = $this->siteCopy();
		$before = hash_file('sha256', $site . '/index.php');
		mkdir($site . '/index.php.tmp');
		$result = $this->probe('', 'update-run', $site, $newCore);
		$this->assertSame($before, $result['sha'], 'a failed temp write must never fall through to the direct write');
		$this->assertNotContains('success', $result['alerts']);
		$this->removeDir($site);

		// 3b. A stale temp file from an earlier run (even read-only) is removed first, so the update goes through.
		$site = $this->siteCopy();
		file_put_contents($site . '/index.php.tmp', 'stale');
		if (DIRECTORY_SEPARATOR === '/') {
			chmod($site . '/index.php.tmp', 0444); // Windows cannot unlink a read-only file: there such a leftover still blocks the update, and index.php is untouched
		}
		$result = $this->probe('', 'update-run', $site, $newCore);
		$this->assertSame(hash('sha256', $real), $result['sha'], 'the stale temp file does not block the update');
		$this->assertFalse($result['tmpLeft']);
		$this->removeDir($site);

		// 3c. A symlink planted at the temp path is removed, never written through: its target is untouched and index.php stays a regular file.
		if (DIRECTORY_SEPARATOR === '/') {
			$site = $this->siteCopy();
			file_put_contents($site . '/victim.txt', 'keep');
			symlink($site . '/victim.txt', $site . '/index.php.tmp');
			$result = $this->probe('', 'update-run', $site, $newCore);
			$this->assertSame(hash('sha256', $real), $result['sha'], 'index.php is the downloaded core');
			$this->assertSame('keep', file_get_contents($site . '/victim.txt'), 'the link target is untouched');
			$this->assertFalse(is_link($site . '/index.php'), 'index.php is a regular file, not the planted link');
			$this->assertFalse($result['tmpLeft']);
			$this->removeDir($site);
		}

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

		// 4b. A dangling symlink at the temp path is removed like any leftover; the update goes through and nothing is created behind the link.
		if (DIRECTORY_SEPARATOR === '/') {
			$site = $this->siteCopy();
			symlink($site . '/no-such-dir/index.php.tmp', $site . '/index.php.tmp');
			$result = $this->probe('', 'update-run', $site, $newCore);
			$this->assertSame(hash('sha256', $real), $result['sha'], 'index.php is the downloaded core');
			$this->assertDirectoryDoesNotExist($site . '/no-such-dir');
			$this->assertFalse($result['tmpLeft']);
			$this->removeDir($site);
		}

		// 5. A host that disables rename(), chmod() or the stat functions (disable_functions) still updates, in place, with no temp file left.
		// Every function writeCore() calls besides file_put_contents, one at a time (is_writable is pinned by step 6b, where its branch runs).
		// A symlinked index.php needs no branch: __FILE__ is the resolved target on every SAPI, so the temp file and the rename happen in the
		// target's folder and the link stays (the server rehearsal's symlink leg checks it; a stale opcache compile once made it look otherwise).
		foreach (['rename', 'chmod', 'fileowner', 'filegroup', 'fileperms', 'unlink', 'is_writable'] as $disabled) {
			$site = $this->siteCopy();
			$this->probe('', 'update-run', $site, $truncated); // an installed site (checkFolder() needs is_writable only on first install)
			chmod($site . '/index.php', 0644);
			$result = $this->probe('-d disable_functions=' . $disabled, 'update-run', $site, $newCore);
			$this->assertSame(hash('sha256', $real), $result['sha'], 'updated although ' . $disabled . '() is disabled');
			$this->assertFalse($result['tmpLeft']);
			if (DIRECTORY_SEPARATOR === '/') {
				clearstatcache();
				$this->assertSame(0644, fileperms($site . '/index.php') & 0777, 'mode kept (a null fileperms must never chmod to 000)');
			}
			$this->removeDir($site);
		}

		// 5b. unlink() disabled on a host where the file's group differs (the in-place branch unlinks the temp): still updates.
		if ($otherGroups !== []) {
			$site = $this->siteCopy();
			if (@chgrp($site . '/index.php', reset($otherGroups))) {
				$result = $this->probe('-d disable_functions=unlink', 'update-run', $site, $newCore);
				$this->assertSame(hash('sha256', $real), $result['sha'], 'updated although unlink() is disabled and the group differs');
				$this->assertFalse($result['tmpLeft']);
			}
			$this->removeDir($site);
		}

		// 6. A folder that is not writable (temp file impossible) still updates through the direct write.
		$site = $this->siteCopy();
		$this->probe('', 'update-run', $site, $truncated); // a first run creates the folders the constructor needs
		chmod($site, 0555);
		$result = $this->probe('', 'update-run', $site, $newCore);
		$this->assertSame(hash('sha256', $real), $result['sha'], 'direct write fallback');
		$this->assertFalse($result['tmpLeft']);
		$this->removeDir($site);

		// 6c. A read-only folder that still holds a writable temp from an interrupted run: the in-place decision is made before the
		//     temp is touched, so the update lands in index.php and the stale temp is left as it was (3.6.0 updated this shape too).
		$site = $this->siteCopy();
		$this->probe('', 'update-run', $site, $truncated);
		file_put_contents($site . '/index.php.tmp', 'stale');
		chmod($site, 0555);
		$result = $this->probe('', 'update-run', $site, $newCore);
		$this->assertSame(hash('sha256', $real), $result['sha'], 'the stale temp must not divert a read-only folder from the direct write');
		$this->assertSame('stale', file_get_contents($site . '/index.php.tmp'), 'nothing was written into the stale temp');
		$this->removeDir($site);

		// 6e. Read-only folder AND read-only index.php: the in-place write fails and the admin gets the alert, with no PHP warning ahead of it
		//     (the probe merges stderr into stdout and decodes JSON, so any printed warning fails the decode).
		$site = $this->siteCopy();
		$this->probe('', 'update-run', $site, $truncated);
		$before = hash_file('sha256', $site . '/index.php');
		chmod($site . '/index.php', 0444);
		chmod($site, 0555);
		$result = $this->probe('-d display_errors=1', 'update-run', $site, $newCore);
		$this->assertSame($before, $result['sha'], 'nothing written');
		$this->assertSame(['danger'], $result['alerts'], 'the failure alert, and only that');
		$this->assertStringContainsString('Could not write the new index.php', $result['message'], 'a failed write names the write, not the download');
		chmod($site, 0755);
		chmod($site . '/index.php', 0644);
		$this->removeDir($site);

		// 6d. The host allows writing index.php but forbids replacing it (a deny-delete ACL here; NTFS write-without-delete and a
		//     single-file bind mount behave the same): the rename fails and the update lands in place instead of being refused forever.
		if (PHP_OS_FAMILY === 'Darwin') {
			$site = $this->siteCopy();
			$this->probe('', 'update-run', $site, $truncated);
			exec('chmod +a ' . escapeshellarg(get_current_user() . ' deny delete') . ' ' . escapeshellarg($site . '/index.php'), $o, $rc);
			if ($rc === 0) {
				$inode = fileinode($site . '/index.php');
				$result = $this->probe('', 'update-run', $site, $newCore);
				clearstatcache();
				$this->assertSame(hash('sha256', $real), $result['sha'], 'a host that refuses the rename still updates, in place');
				$this->assertSame($inode, fileinode($site . '/index.php'), 'in place, no rename');
				$this->assertFalse($result['tmpLeft']);
				exec('chmod -a ' . escapeshellarg(get_current_user() . ' deny delete') . ' ' . escapeshellarg($site . '/index.php'));
			}
			$this->removeDir($site);
		}

		// 6b. The same read-only folder with is_writable() disabled: the guard list must route in place before the temp write
		//     (is_writable is only reached on this branch, so this is the probe that pins its list entry).
		$site = $this->siteCopy();
		$this->probe('', 'update-run', $site, $truncated);
		chmod($site, 0555);
		$result = $this->probe('-d disable_functions=is_writable', 'update-run', $site, $newCore);
		$this->assertSame(hash('sha256', $real), $result['sha'], 'updated although is_writable() is disabled in a read-only folder');
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

		// An empty module cache (the list could not be fetched) is refreshed on every request; the core check still keeps to once a day.
		file_put_contents(__DIR__ . '/../data_test/cache.json', '{}');
		$wcms->checkModulesCache();
		$this->assertFalse($notice(), 'an empty cache refreshes the modules, not the core check, on a day that is stamped');

		// The stamp is written BEFORE the core check: a request that dies during that fetch must not repeat it on every admin page.
		$stamped = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['getOfficialVersion', 'getFileFromRepo'])
			->getMock();
		$stamped->method('getFileFromRepo')->willReturn('');
		$stamped->method('getOfficialVersion')->willReturnCallback(static function () use ($stamped): ?string {
			if ($stamped->get('config', 'lastModulesSync') !== date('Y/m/d')) {
				throw new RuntimeException('the core check ran before the stamp was written');
			}
			throw new RuntimeException('killed during the fetch'); // what a timeout looks like
		});
		$stamped->loggedIn = true;
		$stamped->set('config', 'lastModulesSync', date('Y/m/d', strtotime('-2 days')));
		$caught = null;
		try {
			$stamped->checkModulesCache();
		} catch (RuntimeException $e) {
			$caught = $e->getMessage();
		}
		$this->assertSame('killed during the fetch', $caught, 'the core check must run, after the stamp');
		$this->assertSame(date('Y/m/d'), $stamped->get('config', 'lastModulesSync'), 'stamped before the fetch');

		// The same version, or an older one, is never offered (the comparison is strictly greater).
		$same = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['getOfficialVersion', 'getFileFromRepo'])
			->getMock();
		$same->method('getOfficialVersion')->willReturn(VERSION);
		$same->method('getFileFromRepo')->willReturn('');
		$same->loggedIn = true;
		$same->set('config', 'lastModulesSync', date('Y/m/d', strtotime('-2 days')));
		unset($_SESSION['alert']);
		$same->checkModulesCache();
		$this->assertFalse($notice(), 'the running version is not offered as an update');
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
