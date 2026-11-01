<?php

use PHPUnit\Framework\TestCase;

/**
 * Since 3.7.2 the backup streams through PHP, and PHP's file session handler keeps the session locked for the whole request:
 * while a large backup downloaded, every other request of the same admin waited for it to end. Pinned against a real php -S
 * with several workers (one worker serves one request at a time, session or not) and a download read slowly by curl.
 */
final class BackupSessionLockTest extends TestCase
{
	/** @var resource|null */
	private $server;
	/** @var string */
	private $base;
	/** @var string|null */
	private $tmp;
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('the local server writes its output to /dev/null and has no workers on Windows');
		}
		if (!function_exists('proc_open') || !function_exists('exec') || !function_exists('curl_multi_init') || !class_exists('ZipArchive')) {
			$this->markTestSkipped('needs proc_open, exec, curl and the zip extension');
		}
		if (PHP_VERSION_ID < 80000) {
			// PHP 7.4's php -S forks its workers before it listens, so each worker has its own SO_REUSEPORT socket and the kernel hands a new connection to one of them by hash: about one request in five lands behind the worker that is streaming the backup, lock or no lock. PHP 8.0 listens first and the idle workers accept. The lines under test are the same on every version, so the 8.x legs pin them; on the maintainer's PHP 7.4 web stack the same-session request answered in about half a second during a 20 s download, but that server buffers the whole response, so it cannot tell the fixed core from the old one.
			$this->markTestSkipped('php -S on PHP 7.4 queues a request behind a busy worker; not a session lock');
		}
		$this->tmp = sys_get_temp_dir() . '/wcms-backup-lock-' . bin2hex(random_bytes(4));
		$this->root = $this->tmp . '/site';
		mkdir($this->tmp . '/sessions', 0777, true); // outside the site: the backup would archive the session files
		mkdir($this->root . '/themes/sky', 0777, true);
		copy(__DIR__ . '/../index.php', $this->root . '/index.php');
		$themes = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../themes/sky', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($themes as $item) {
			$target = $this->root . '/themes/sky/' . $themes->getSubPathName();
			$item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
		}
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
		fclose($socket);
		$this->base = 'http://127.0.0.1:' . $port;
		$command = [PHP_BINARY, '-d', 'session.save_path=' . $this->tmp . '/sessions', '-S', '127.0.0.1:' . $port, '-t', $this->root];
		$env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']);
		$this->server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->root, $env);
		for ($i = 0; $i < 50 && @file_get_contents($this->base . '/') === false; $i++) {
			usleep(100000);
		}
	}

	protected function tearDown(): void
	{
		if ($this->server) {
			exec('pkill -P ' . (int)proc_get_status($this->server)['pid']); // the workers outlive a terminated master and keep the test runner's output open
			proc_terminate($this->server);
			proc_close($this->server);
		}
		if ($this->tmp !== null && is_dir($this->tmp)) {
			exec('rm -rf ' . escapeshellarg($this->tmp));
		}
	}

	/** @return resource|CurlHandle */
	private function handle(string $path, string $cookie = '', ?array $post = null)
	{
		$curl = curl_init($this->base . $path);
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
		if ($cookie !== '') {
			curl_setopt($curl, CURLOPT_COOKIE, $cookie);
		}
		if ($post !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		}
		return $curl;
	}

	/** @return string the admin's PHPSESSID=... cookie */
	private function logIn(): string
	{
		$curl = $this->handle('/');
		$home = (string)curl_exec($curl);
		$this->assertSame(1, preg_match('~everything is: <b>([^<]+)</b>~', $home, $m), 'the install password is on the first page');
		$this->assertSame(1, preg_match('~^Set-Cookie: (PHPSESSID=[^;\r\n]*)~mi', $home, $visitor));
		$curl = $this->handle('/?page=loginURL', $visitor[1], ['password' => $m[1]]);
		$this->assertSame(1, preg_match('~^Set-Cookie: (PHPSESSID=[^;\r\n]*)~mi', (string)curl_exec($curl), $admin), 'the login sends a new session id');
		return $admin[1];
	}

	public function testTheBackupDownloadDoesNotHoldTheAdminsOtherRequests(): void
	{
		$cookie = $this->logIn();
		$page = (string)curl_exec($this->handle('/', $cookie));
		$this->assertStringContainsString('id="settingsModal"', $page, 'the session is the admin');
		$this->assertSame(1, preg_match('~name="backup".*?name="token" value="([0-9a-f]+)"~s', $page, $token));
		if (!is_dir($this->root . '/data/files')) {
			mkdir($this->root . '/data/files', 0777, true);
		}
		file_put_contents($this->root . '/data/files/padding.bin', random_bytes(8 << 20)); // random, so the archive stays about 8 MB: 8 s at the rate below, a few MB more than the loopback socket buffers take in at once

		$multi = curl_multi_init();
		$download = $this->handle('/', $cookie, ['backup' => '', 'token' => $token[1]]);
		$received = 0;
		curl_setopt_array($download, [
			CURLOPT_MAX_RECV_SPEED_LARGE => 1 << 20,
			CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$received): int {
				$received += strlen($data);
				return strlen($data);
			},
		]);
		curl_multi_add_handle($multi, $download);
		$downloadDone = false;
		$get = null;
		$getDone = false;
		$started = 0.0;
		$elapsed = 0.0;
		$until = microtime(true) + 30;
		while (!$getDone && microtime(true) < $until) {
			curl_multi_exec($multi, $running);
			curl_multi_select($multi, 0.05);
			while ($info = curl_multi_info_read($multi)) {
				if ($info['handle'] === $download) {
					$downloadDone = true;
				} elseif ($info['handle'] === $get) {
					$getDone = true;
					$elapsed = microtime(true) - $started;
				}
			}
			if ($get === null && $received > 0) { // the attachment is streaming: the backup request is past its session writes
				$get = $this->handle('/', $cookie);
				curl_multi_add_handle($multi, $get);
				$started = microtime(true);
			}
			if ($get === null && $downloadDone) {
				break;
			}
		}
		$this->assertNotNull($get, 'the download started streaming');
		$this->assertTrue($getDone, 'the same-session request answered');
		$this->assertStringContainsString('application/zip', (string)curl_getinfo($download, CURLINFO_CONTENT_TYPE), 'the backup is a download');
		$this->assertStringContainsString('id="settingsModal"', (string)curl_multi_getcontent($get), 'the same-session request is the admin page');
		$this->assertLessThan(1.0, $elapsed, sprintf('the same-session request waited %.1f s, with %d bytes of the archive received', $elapsed, $received));
		$this->assertFalse($downloadDone, 'and the download was still running');
		curl_multi_remove_handle($multi, $download);
		curl_multi_remove_handle($multi, $get);
		curl_multi_close($multi);
	}

	/**
	 * sendBackup() holds a shared lock on the archive for the whole send: that lock is what keeps removeStaleBackupArchives()
	 * in another request from removing or emptying an archive still being streamed. Pinned from outside the server: while the
	 * download streams, this process cannot take an exclusive lock on the archive under data/ without waiting, and can take a
	 * shared one; once the download ends the archive is gone. Without the sender's LOCK_SH the exclusive lock succeeds here.
	 */
	public function testTheSenderHoldsASharedLockOnTheArchiveWhileItStreams(): void
	{
		$cookie = $this->logIn();
		$page = (string)curl_exec($this->handle('/', $cookie));
		$this->assertStringContainsString('id="settingsModal"', $page, 'the session is the admin');
		$this->assertSame(1, preg_match('~name="backup".*?name="token" value="([0-9a-f]+)"~s', $page, $token));
		if (!is_dir($this->root . '/data/files')) {
			mkdir($this->root . '/data/files', 0777, true);
		}
		file_put_contents($this->root . '/data/files/padding.bin', random_bytes(8 << 20)); // about 8 MB, 8 s at the rate below

		$multi = curl_multi_init();
		$download = $this->handle('/', $cookie, ['backup' => '', 'token' => $token[1]]);
		$received = 0;
		curl_setopt_array($download, [
			CURLOPT_MAX_RECV_SPEED_LARGE => 1 << 20,
			CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$received): int {
				$received += strlen($data);
				return strlen($data);
			},
		]);
		curl_multi_add_handle($multi, $download);
		$downloadDone = false;
		$archive = null;
		$exclusive = null;
		$shared = null;
		$until = microtime(true) + 30;
		while (!$downloadDone && microtime(true) < $until) {
			curl_multi_exec($multi, $running);
			curl_multi_select($multi, 0.05);
			while ($info = curl_multi_info_read($multi)) {
				if ($info['handle'] === $download) {
					$downloadDone = true;
				}
			}
			if ($archive === null && $received > 0) { // the attachment is streaming: the sender's lock is held
				$archives = glob($this->root . '/data/*-backup-*.zip') ?: [];
				$this->assertCount(1, $archives, 'the archive being sent is under data/');
				$archive = $archives[0];
				$handle = fopen($archive, 'rb');
				$exclusive = flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
				$shared = flock($handle, LOCK_SH | LOCK_NB);
				flock($handle, LOCK_UN);
				fclose($handle);
			}
		}
		$this->assertNotNull($archive, 'the download started streaming');
		$this->assertFalse($exclusive, 'mid-stream, an exclusive lock on the archive is refused without waiting (the sender holds its shared lock)');
		$this->assertSame(1, $wouldBlock, 'refused because it would block, which is what the sweep treats as "being sent"');
		$this->assertTrue($shared, 'a shared lock is granted alongside the sender\'s');
		$this->assertTrue($downloadDone, 'the download ended');
		$this->assertStringContainsString('application/zip', (string)curl_getinfo($download, CURLINFO_CONTENT_TYPE), 'the backup is a download');
		clearstatcache();
		$this->assertFileDoesNotExist($archive, 'once sent, the archive is removed');
		curl_multi_remove_handle($multi, $download);
		curl_multi_close($multi);
	}
}
