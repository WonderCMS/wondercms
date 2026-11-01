<?php

use PHPUnit\Framework\TestCase;

/**
 * zipBackup() closes the session before it builds the archive (#427): the build of a big site takes seconds, and PHP's
 * file session handler would hold the admin's other requests for all of it. Pinned in process: the archive builder is
 * replaced by a stub that records session_status() at the moment it is called.
 */
final class BackupSessionClosedBeforeArchiveTest extends TestCase
{
	/** @var string */
	private $tmp;
	/** @var bool the bootstrap's session (index.php starts one at load) was active when the test began */
	private $wasActive = false;
	/** @var string|false */
	private $previousUseCookies = false;

	protected function setUp(): void
	{
		if (!function_exists('readfile') && !function_exists('fopen')) {
			$this->markTestSkipped('zipBackup() refuses the backup before the session close where no file sender exists');
		}
		$this->tmp = sys_get_temp_dir() . '/wcms-backup-close-' . bin2hex(random_bytes(4));
		mkdir($this->tmp, 0777, true);
		$this->wasActive = session_status() === PHP_SESSION_ACTIVE;
		if (!$this->wasActive) {
			$this->previousUseCookies = ini_get('session.use_cookies');
			ini_set('session.use_cookies', '0'); // the CLI has printed by now; without a cookie to send, session_start() does not mind
			session_save_path($this->tmp);
			@session_start();
		}
		if (session_status() !== PHP_SESSION_ACTIVE) {
			$this->markTestSkipped('a session could not be started in this CLI');
		}
	}

	protected function tearDown(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		if ($this->wasActive) { // leave the suite the session it had: $_SESSION stays in memory either way, the later tests read and write it
			ini_set('session.use_cookies', '0'); // PHPUnit has printed by now, so a cookie could not be sent; nothing in the suite reads this setting, and it cannot be changed back while the session is active
			@session_start();
		} elseif ($this->previousUseCookies !== false) {
			ini_set('session.use_cookies', (string)$this->previousUseCookies);
		}
		if ($this->tmp !== null && is_dir($this->tmp)) {
			foreach (glob($this->tmp . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($this->tmp);
		}
	}

	public function testTheSessionIsClosedBeforeTheArchiveIsBuilt(): void
	{
		$this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'the request has an open session when the backup starts');
		$archive = $this->tmp . '/2026-01-01-backup-0123456789abcdef.zip';
		$statusAtBuild = null;
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['createBackupArchive', 'sendBackup'])->getMock();
		$wcms->expects($this->once())->method('createBackupArchive')->willReturnCallback(static function () use (&$statusAtBuild, $archive): string {
			$statusAtBuild = session_status();
			file_put_contents($archive, 'a small archive');
			return $archive;
		});
		$wcms->expects($this->once())->method('sendBackup')->with($archive);

		$wcms->zipBackup();

		$this->assertSame(PHP_SESSION_NONE, $statusAtBuild, 'the session is closed before the archive is built, so the build does not hold the admin\'s other requests');
		$this->assertSame(PHP_SESSION_NONE, session_status(), 'and it stays closed for the send');
	}
}
