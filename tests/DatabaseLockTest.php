<?php

use PHPUnit\Framework\TestCase;

/**
 * database.js is written under an exclusive lock (file_put_contents() with LOCK_EX locks before it truncates; pinned here
 * so that never changes) and, since 3.7.0, read under a shared lock, so a request that reads while another saves sees
 * the whole old file or the whole new one, never an emptied or half-written one.
 */
final class DatabaseLockTest extends TestCase
{
	private const DB = __DIR__ . '/../data_test/database_probe.js';

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		@unlink(self::DB);
		$wcms = new Wcms('data_test', 'files_test', 'database_probe.js');
		$wcms->set('config', 'siteTitle', 'before');
	}

	protected function tearDown(): void
	{
		@unlink(self::DB);
	}

	/** @return array{0: resource, 1: array} the process and its pipes */
	private function startProbe(string $mode, string $arg = ''): array
	{
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' ' . $mode . ($arg === '' ? '' : ' ' . escapeshellarg($arg));
		$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
		$this->assertIsResource($process);
		return [$process, $pipes];
	}

	private function finishProbe($process, array $pipes): array
	{
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$this->assertSame('', $err, 'the probe printed nothing on stderr');
		$json = json_decode($out, true);
		$this->assertIsArray($json, 'probe output: ' . $out);
		return $json;
	}

	public function testASaveWaitsForAReaderAndNeverEmptiesTheFileFirst(): void
	{
		$before = file_get_contents(self::DB);
		$reader = fopen(self::DB, 'rb');
		$this->assertTrue(flock($reader, LOCK_SH), 'this filesystem locks');
		[$process, $pipes] = $this->startProbe('locked-save', 'after');
		usleep(500000);
		try {
			$this->assertSame($before, file_get_contents(self::DB), 'while a reader holds the file, the writer has not touched it (the exclusive lock comes before the truncate)');
		} finally {
			flock($reader, LOCK_UN); // also on a failed assertion: the probe inherited this handle and would otherwise wait on it forever
			fclose($reader);
		}
		$result = $this->finishProbe($process, $pipes);
		// No timing assertion: the file-untouched check above and the finished title below pin the invariant; a loaded runner
		// (24 suites at once in a review round) booted the probe in over 200 ms and failed a 'waited at least 300 ms' check.
		$this->assertSame('after', $result['title']);
		$this->assertSame('after', json_decode(file_get_contents(self::DB))->config->siteTitle, 'and landed whole');
	}

	public function testAHostThatDisablesTheStreamFunctionsStillReadsAndSaves(): void
	{
		// Round-14 gpt-7 and gpt-3: with flock (or fopen, stream_get_contents, fclose) in disable_functions every request fataled on the new read; 3.6.0 called none of them at boot.
		// Each function alone, then all four: a guard that checked only one of them would pass the combined run (gate F-2).
		foreach (['flock', 'fopen', 'stream_get_contents', 'fclose', 'flock,fopen,stream_get_contents,fclose'] as $disabled) {
			$cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $disabled . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' locked-save after-' . strlen($disabled);
			$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
			$result = $this->finishProbe($process, $pipes);
			$this->assertSame('after-' . strlen($disabled), $result['title'], 'read and saved with ' . $disabled . ' disabled');
		}
		$this->assertSame('after-38', json_decode(file_get_contents(self::DB))->config->siteTitle); // the last run, all four names
	}

	public function testAVanishedDatabaseFileReadsAsEmptyLikeTheOldRead(): void
	{
		// lockedRead() returns '' when fopen() fails (the file removed between the existence check and the read); 3.6.0's
		// file_get_contents() gave false there, and both end in the same TypeError on getDb()'s return type (round-15 opus-4).
		$wcms = new Wcms('data_test', 'files_test', 'database_probe.js');
		$read = new ReflectionMethod(Wcms::class, 'lockedRead');
		if (PHP_VERSION_ID < 80100) {
			$read->setAccessible(true);
		}
		$this->assertSame('', @$read->invoke($wcms, self::DB . '.gone'));
		$this->assertStringContainsString('"siteTitle": "before"', $read->invoke($wcms, self::DB), 'and the real file reads whole');
	}

	public function testAReadWaitsForAWriterInsteadOfSeeingAHalfWrittenFile(): void
	{
		// The writer holds the lock with the file truncated and half rewritten, exactly the state a save() passes through.
		// A read that waits for the lock sees the finished file; a read taken before the lock would see the half.
		$new = str_replace('"before"', '"after"', file_get_contents(self::DB));
		$this->assertStringContainsString('"after"', $new);
		$writer = fopen(self::DB, 'cb');
		$this->assertTrue(flock($writer, LOCK_EX));
		ftruncate($writer, 0);
		fwrite($writer, substr($new, 0, intdiv(strlen($new), 2)));
		fflush($writer);
		[$process, $pipes] = $this->startProbe('locked-read');
		usleep(500000);
		try {
			$this->assertTrue(proc_get_status($process)['running'], 'the reader is still waiting for the lock');
		} finally {
			fwrite($writer, substr($new, intdiv(strlen($new), 2)));
			fflush($writer);
			flock($writer, LOCK_UN);
			fclose($writer);
		}
		$result = $this->finishProbe($process, $pipes);
		$this->assertSame('after', $result['title'], 'it read the whole new file, not the half the writer had put down before the lock was released');
	}
}
