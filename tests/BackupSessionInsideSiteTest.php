<?php

use PHPUnit\Framework\TestCase;

/**
 * A host may keep PHP's session files inside the site (session.save_path = data/sessions). The backup walk reads every file
 * under a shared lock, the request's own session file included, while PHP's file session handler holds that file's exclusive
 * lock for the open session: before 3.7.2 the backup waited on itself until the time limit and answered a fatal page.
 * Pinned against a real php -S whose session.save_path is inside the copied site.
 */
final class BackupSessionInsideSiteTest extends TestCase
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
		if (!function_exists('proc_open') || !function_exists('exec') || !function_exists('curl_init') || !class_exists('ZipArchive')) {
			$this->markTestSkipped('needs proc_open, exec, curl and the zip extension');
		}
		if (PHP_VERSION_ID < 80000) {
			// the same server as BackupSessionLockTest: PHP 7.4's php -S workers each hold their own SO_REUSEPORT socket and can queue a request behind a busy one
			$this->markTestSkipped('php -S on PHP 7.4 queues a request behind a busy worker; not a session lock');
		}
		$this->tmp = sys_get_temp_dir() . '/wcms-backup-insite-' . bin2hex(random_bytes(4));
		$this->root = $this->tmp . '/site';
		mkdir($this->root . '/data/sessions', 0777, true);
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
		$command = [PHP_BINARY, '-d', 'session.save_path=' . $this->root . '/data/sessions', '-d', 'max_execution_time=5', '-S', '127.0.0.1:' . $port, '-t', $this->root];
		$env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '2']);
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
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8]);
		if ($cookie !== '') {
			curl_setopt($curl, CURLOPT_COOKIE, $cookie);
		}
		if ($post !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		}
		return $curl;
	}

	public function testTheBackupDownloadsWhenTheSessionFilesLiveInsideTheSite(): void
	{
		$curl = $this->handle('/');
		$home = (string)curl_exec($curl);
		$this->assertSame(1, preg_match('~everything is: <b>([^<]+)</b>~', $home, $m), 'the install password is on the first page');
		$this->assertSame(1, preg_match('~^Set-Cookie: (PHPSESSID=[^;\r\n]*)~mi', $home, $visitor));
		$curl = $this->handle('/?page=loginURL', $visitor[1], ['password' => $m[1]]);
		$this->assertSame(1, preg_match('~^Set-Cookie: (PHPSESSID=[^;\r\n]*)~mi', (string)curl_exec($curl), $admin), 'the login sends a new session id');
		$page = (string)curl_exec($this->handle('/', $admin[1]));
		$this->assertStringContainsString('id="settingsModal"', $page, 'the session is the admin');
		$this->assertSame(1, preg_match('~name="backup".*?name="token" value="([0-9a-f]+)"~s', $page, $token));
		$this->assertNotEmpty(glob($this->root . '/data/sessions/sess_*'), 'the sessions are stored inside the site');
		@mkdir($this->root . '/data/files');
		file_put_contents($this->root . '/data/files/sess_0123456789abcdef', 'an upload with a session-like name');

		$download = $this->handle('/', $admin[1], ['backup' => '', 'token' => $token[1]]);
		curl_setopt($download, CURLOPT_HEADER, false);
		$started = microtime(true);
		$body = (string)curl_exec($download);
		$elapsed = microtime(true) - $started;
		$this->assertStringContainsString('application/zip', (string)curl_getinfo($download, CURLINFO_CONTENT_TYPE), sprintf('the backup is a download (answered after %.1f s: %s)', $elapsed, curl_error($download) ?: substr(strip_tags($body), 0, 200)));
		$this->assertLessThan(5.0, $elapsed);

		$zipPath = $this->tmp . '/backup.zip';
		file_put_contents($zipPath, $body);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zipPath, ZipArchive::CHECKCONS), 'the archive is complete');
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}
		$zip->close();
		$this->assertContains('index.php', $names);
		$this->assertContains('data/database.js', $names);
		$this->assertSame([], array_values(preg_grep('~^data/sessions/.~', $names)), 'no session file is archived: each holds a live session id');
		$this->assertContains('data/sessions/', $names, 'the session folder itself is kept');
		$this->assertContains('data/files/sess_0123456789abcdef', $names, 'the skip is by folder, not by name: an upload so named is archived');
		exec('unzip -tq ' . escapeshellarg($zipPath) . ' 2>&1', $output, $status);
		$this->assertTrue($status === 0 || $status === 127, 'unzip -t: ' . implode(' ', $output)); // 127: no unzip on this machine, CHECKCONS above stands
	}
}
