<?php

use PHPUnit\Framework\TestCase;

/**
 * A save of database.js holds an exclusive lock on database.js.lock from the moment it re-reads the password hash on disk
 * until its file is renamed into place, so a save from a snapshot read before a password change can never write the old
 * hash back after the change landed: its re-read sees the whole file from before the change or the whole file after it.
 */
final class SaveLockTest extends TestCase
{
	private const DB = __DIR__ . '/../data_test/database_probe.js';
	private const TRIALS = 30;
	private const WRITERS = 6;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SESSION = [];
		@mkdir(dirname(self::DB));
		$lock = @fopen(self::DB . '.lock', 'c');
		$locks = $lock !== false && flock($lock, LOCK_EX | LOCK_NB);
		if ($lock !== false) {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
		if (!$locks) {
			$this->markTestSkipped('this filesystem does not support flock()');
		}
	}

	protected function tearDown(): void
	{
		$_POST = $_GET = $_REQUEST = [];
		$_SESSION = [];
		@unlink(self::DB);
		@unlink(self::DB . '.lock');
	}

	private function newWcms(): Wcms
	{
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_probe.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->currentPage = 'loginURL';
		return $wcms;
	}

	private function freshDatabase(string $password): void
	{
		@unlink(self::DB);
		wcmsSetPasswordOnDisk($this->newWcms(), $password);
	}

	/** @return array{0: resource, 1: array} a probe that has read the database and printed "ready" */
	private function startStaleSaver(string $go, int $milliseconds): array
	{
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' locked-stale-saves ' . escapeshellarg($go) . ' ' . $milliseconds;
		$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
		$this->assertIsResource($process);
		$this->assertSame("ready\n", fgets($pipes[1]), 'the probe read the database');
		return [$process, $pipes];
	}

	private function finishStaleSaver($process, array $pipes): int
	{
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$this->assertSame('', $err, 'the probe printed nothing on stderr');
		$json = json_decode($out, true);
		$this->assertIsArray($json, 'probe output: ' . $out);
		return $json['saves'];
	}

	private function diskHash(): string
	{
		return json_decode(file_get_contents(self::DB))->config->password;
	}

	public function testStaleSavesRacingAPasswordChangeNeverRestoreTheOldHash(): void
	{
		// Without the lock a stale save could re-read the old hash, the change rename the new file over it, and the stale
		// save rename its file (old hash inside) over that: about one trial in seven with six writers.
		$restored = [];
		for ($trial = 1; $trial <= self::TRIALS; $trial++) {
			$this->freshDatabase('old-secret');
			$_SESSION = [];
			$_POST = ['password' => 'old-secret'];
			$this->newWcms()->loginAction();
			$admin = $_SESSION;

			$go = self::DB . '.go';
			@unlink($go);
			$writers = [];
			for ($i = 0; $i < self::WRITERS; $i++) {
				$writers[] = $this->startStaleSaver($go, 5000); // they save until the go file is removed below; the cap is a safety net
			}
			touch($go);

			$_SESSION = $admin;
			$wcms = $this->newWcms();
			$wcms->currentPage = 'home';
			$wcms->loginStatus();
			$_POST = ['old_password' => 'old-secret', 'new_password' => 'new-secret-123', 'repeat_password' => 'new-secret-123', 'token' => $wcms->getToken()];
			$wcms->changePasswordAction();
			$_POST = [];
			@unlink($go); // the change has landed: the writers stop after their current save

			$saves = 0;
			foreach ($writers as [$process, $pipes]) {
				$saves += $this->finishStaleSaver($process, $pipes);
			}
			// Every writer saved from before the change (the hash is computed before the lock is taken) until after it, so
			// together they saved more than once each whatever the runner's speed.
			$this->assertGreaterThan(self::WRITERS, $saves, 'the writers kept saving around the change');
			if (!password_verify('new-secret-123', $this->diskHash())) {
				$restored[] = $trial;
			}
		}
		$this->assertSame([], $restored, 'trials that ended with the old hash on disk, out of ' . self::TRIALS);
	}

	public function testASaveWaitsForTheDatabaseLockAndKeepsTheHashWrittenUnderIt(): void
	{
		$this->freshDatabase('old-secret');
		$lock = fopen(self::DB . '.lock', 'c');
		$this->assertTrue(flock($lock, LOCK_EX), 'this filesystem locks');
		try {
			[$process, $pipes] = $this->startStaleSaver('-', 0);
			usleep(500000);
			$this->assertTrue(proc_get_status($process)['running'], 'the stale save waits while another save holds the lock');
			// What the password change does while it holds the lock: the new hash lands on disk.
			$db = json_decode(file_get_contents(self::DB));
			$db->config->password = password_hash('new-secret-123', PASSWORD_DEFAULT);
			file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT));
		} finally {
			flock($lock, LOCK_UN); // also on a failed assertion: the probe inherited this handle and would otherwise wait on it forever
			fclose($lock);
		}
		$this->assertSame(1, $this->finishStaleSaver($process, $pipes));
		$this->assertTrue(password_verify('new-secret-123', $this->diskHash()), 'the stale save re-read the hash after the lock was released');
		$this->assertStringStartsWith('stale ', json_decode(file_get_contents(self::DB))->config->siteTitle, 'and its own change landed');
	}
}
