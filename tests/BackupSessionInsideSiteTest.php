<?php

use PHPUnit\Framework\TestCase;

/**
 * A host may keep PHP's session files inside the site (session.save_path = data/sessions, or a depth prefix, or data/ itself).
 * The backup walk reads every file under a shared lock, the request's own session file included, while PHP's file session
 * handler holds that file's exclusive lock for the open session: before 3.7.2 the backup waited on itself until the time limit
 * and answered a fatal page, and a session file could still reach the archive where it sat below the save path but not directly
 * in it. Every session file (sess_<id>) anywhere below the save path is now left out, its folder entries kept.
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
	}

	/** Start php -S over the copied site with the given ini settings (always session save path related here); sets $this->base */
	private function startServer(array $ini): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
		fclose($socket);
		$this->base = 'http://127.0.0.1:' . $port;
		$command = [PHP_BINARY];
		foreach ($ini as $key => $value) {
			$command[] = '-d';
			$command[] = $key . '=' . $value;
		}
		array_push($command, '-d', 'max_execution_time=5', '-S', '127.0.0.1:' . $port, '-t', $this->root);
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

	/** Log in as the admin, download the backup and return [the zip entry names, the elapsed seconds] */
	private function loginAndBackup(): array
	{
		$home = (string)curl_exec($this->handle('/'));
		$this->assertSame(1, preg_match('~everything is: <b>([^<]+)</b>~', $home, $m), 'the install password is on the first page');
		$this->assertSame(1, preg_match('~^Set-Cookie: (PHPSESSID=[^;\r\n]*)~mi', $home, $visitor));
		$login = (string)curl_exec($this->handle('/?page=loginURL', $visitor[1], ['password' => $m[1]]));
		$this->assertSame(1, preg_match('~^Set-Cookie: (PHPSESSID=[^;\r\n]*)~mi', $login, $admin), 'the login sends a new session id');
		$page = (string)curl_exec($this->handle('/', $admin[1]));
		$this->assertStringContainsString('id="settingsModal"', $page, 'the session is the admin');
		$this->assertSame(1, preg_match('~name="backup".*?name="token" value="([0-9a-f]+)"~s', $page, $token));

		$download = $this->handle('/', $admin[1], ['backup' => '', 'token' => $token[1]]);
		curl_setopt($download, CURLOPT_HEADER, false);
		$started = microtime(true);
		$body = (string)curl_exec($download);
		$elapsed = microtime(true) - $started;
		$this->assertStringContainsString('application/zip', (string)curl_getinfo($download, CURLINFO_CONTENT_TYPE), sprintf('the backup is a download (answered after %.1f s: %s)', $elapsed, curl_error($download) ?: substr(strip_tags($body), 0, 200)));
		$this->assertLessThan(5.0, $elapsed);

		$zipPath = $this->tmp . '/backup-' . bin2hex(random_bytes(4)) . '.zip';
		file_put_contents($zipPath, $body);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zipPath, ZipArchive::CHECKCONS), 'the archive is complete');
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}
		$zip->close();
		exec('unzip -tq ' . escapeshellarg($zipPath) . ' 2>&1', $output, $status);
		$this->assertTrue($status === 0 || $status === 127, 'unzip -t: ' . implode(' ', $output)); // 127: no unzip on this machine, CHECKCONS above stands
		return [$names, $elapsed];
	}

	public function testTheBackupDownloadsWhenTheSessionFilesLiveInsideTheSite(): void
	{
		$this->startServer(['session.save_path' => $this->root . '/data/sessions']);
		@mkdir($this->root . '/data/files');
		// A session-shaped upload outside the save path: the skip must need the file below the save path too, not the name alone
		file_put_contents($this->root . '/data/files/sess_00112233445566778899aabbccddeeff', 'an upload with a session-like name');
		// Owner files the host put in the session folder: the directory-only skip of #431 dropped these; the two-condition rule keeps them
		file_put_contents($this->root . '/data/sessions/.htaccess', "deny from all\n");
		file_put_contents($this->root . '/data/sessions/keep.txt', 'keep');

		[$names] = $this->loginAndBackup();
		$this->assertNotEmpty(glob($this->root . '/data/sessions/sess_*'), 'the sessions are stored inside the site');
		$this->assertContains('index.php', $names);
		$this->assertContains('data/database.js', $names);
		$this->assertSame([], array_values(preg_grep('~^data/sessions/sess_[A-Za-z0-9,-]{22,256}$~', $names)), 'no session file is archived: each holds a live session id');
		$this->assertContains('data/sessions/', $names, 'the session folder itself is kept');
		$this->assertContains('data/sessions/.htaccess', $names, 'an owner file in the session folder is kept: the skip is by session-file shape, not by folder');
		$this->assertContains('data/sessions/keep.txt', $names, 'another owner file in the session folder is kept');
		$this->assertContains('data/files/sess_00112233445566778899aabbccddeeff', $names, 'an upload outside the save path is archived, even with a session-shaped name: condition 1 (below the save path) is not met');
	}

	public function testADepthOneSavePathLeavesOutSessionFilesOneFolderDown(): void
	{
		foreach (str_split('0123456789abcdef') as $c) {
			mkdir($this->root . '/data/sessions/' . $c); // PHP's files handler with depth 1 writes data/sessions/<c>/sess_... and never creates <c> itself; sid_bits_per_character=4 makes the 16 first-level names the hex digits
		}
		// The value is ini-quoted so PHP's -d parser keeps the ';' (unquoted, it treats ';' as the start of an ini comment and stores only "1")
		$this->startServer(['session.save_path' => '"1;' . $this->root . '/data/sessions"', 'session.sid_bits_per_character' => '4']);
		@mkdir($this->root . '/data/files');
		file_put_contents($this->root . '/data/files/photo.jpg', 'jpeg');

		[$names] = $this->loginAndBackup();
		$this->assertNotEmpty(glob($this->root . '/data/sessions/*/sess_*'), 'PHP stored the session one folder below the save path');
		$this->assertContains('data/database.js', $names);
		$this->assertContains('data/files/photo.jpg', $names);
		$this->assertSame([], array_values(preg_grep('~(^|/)sess_[A-Za-z0-9,-]{22,256}$~', $names)), 'no PHP session file is archived, however deep below the save path it sits');
	}

	public function testASavePathOfDataItselfLeavesOutSessionFilesBesideTheDatabase(): void
	{
		$this->startServer(['session.save_path' => $this->root . '/data']);
		@mkdir($this->root . '/data/files');
		file_put_contents($this->root . '/data/files/photo.jpg', 'jpeg');

		[$names] = $this->loginAndBackup();
		$this->assertNotEmpty(glob($this->root . '/data/sess_*'), 'PHP stored the session files in data/ beside database.js');
		$this->assertContains('data/database.js', $names);
		$this->assertContains('data/files/photo.jpg', $names);
		$this->assertSame([], array_values(preg_grep('~(^|/)sess_[A-Za-z0-9,-]{22,256}$~', $names)), 'no PHP session file is archived, not even in data/ itself');
	}
}
