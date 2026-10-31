<?php

use PHPUnit\Framework\TestCase;

/**
 * The backup is built under data/, streamed to the admin as an attachment in the same request and removed: 3.7.1 left it
 * in data/files/ and redirected to its public URL. Pinned over HTTP against a real local server (php -S), since the CLI
 * drops header(); the site is a temp copy, so the archive is checked entry by entry.
 */
final class BackupTest extends TestCase
{
	private const LEGACY = '2026-01-01-backup-0123456789abcdef.zip';
	private const UPLOAD = 'holiday-backup-2024.zip'; // an admin's own upload, named like a backup but without the date and the random suffix

	/** @var resource|null */
	private $server;
	/** @var string */
	private $tmp;
	/** @var string */
	private $site;

	protected function setUp(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('the local server writes its output to /dev/null');
		}
		if (!class_exists('ZipArchive')) {
			$this->markTestSkipped('needs the zip extension');
		}
		$this->tmp = sys_get_temp_dir() . '/wcms-backup-' . bin2hex(random_bytes(4));
		$this->site = $this->tmp . '/site';
		mkdir($this->site . '/data/files', 0777, true);
		mkdir($this->site . '/themes/sky', 0777, true);
		copy(__DIR__ . '/../index.php', $this->site . '/index.php');
		file_put_contents($this->site . '/themes/sky/theme.php', '<?php // sky');
		file_put_contents($this->site . '/data/files/photo.jpg', 'jpeg');
		file_put_contents($this->site . '/data/files/' . self::LEGACY, 'an old backup with an old password hash');
		file_put_contents($this->site . '/data/files/' . self::UPLOAD, 'holiday photos');
		$this->writeRouter();
	}

	/** The router constructs Wcms over the temp site and runs backupAction() as the admin; $prelude runs first (a plugin's doing) */
	private function writeRouter(string $prelude = ''): void
	{
		file_put_contents($this->tmp . '/router.php', '<?php
			define("PHPUNIT_TESTING", true);
			require ' . var_export(realpath(__DIR__ . '/../index.php'), true) . ';
			$wcms = new Wcms("data", "files", "database.js", ' . var_export($this->site, true) . ');
			$wcms->loggedIn = true;
			$_SESSION = [];
			$_POST = ["backup" => "", "token" => $wcms->getToken()];
			' . $prelude . '
			$wcms->backupAction();
			echo "no download " . json_encode($_SESSION["alert"] ?? []);');
	}

	protected function tearDown(): void
	{
		if ($this->server) {
			proc_terminate($this->server);
			proc_close($this->server);
		}
		if ($this->tmp !== null && is_dir($this->tmp)) {
			exec('rm -rf ' . escapeshellarg($this->tmp));
		}
	}

	/** Start php -S with $ini settings over the router; return its port once it accepts connections */
	private function startServer(array $ini = []): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$name = stream_socket_get_name($socket, false);
		$port = (int)substr($name, strrpos($name, ':') + 1);
		fclose($socket);
		$command = [PHP_BINARY];
		foreach ($ini as $key => $value) {
			$command[] = '-d';
			$command[] = $key . '=' . $value;
		}
		array_push($command, '-S', '127.0.0.1:' . $port, $this->tmp . '/router.php');
		$this->server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->tmp);
		for ($i = 0; $i < 50; $i++) {
			$fp = @fsockopen('127.0.0.1', $port);
			if ($fp) {
				fclose($fp);
				break;
			}
			usleep(100000);
		}
		return $port;
	}

	/** Start php -S with $ini settings and request a backup; return [status line and headers, body] */
	private function download(array $ini = []): array
	{
		return $this->request($this->startServer($ini));
	}

	/** Request a backup from the server already listening on $port; return [status line and headers, body] */
	private function request(int $port): array
	{
		$context = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true, 'follow_location' => 0]]);
		$body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $context);
		$this->assertNotFalse($body, 'the local server answers');
		return [$http_response_header ?? [], $body];
	}

	/** Poll data/ for up to $seconds until no zip is left; return what is left */
	private function zipsLeftUnderData(int $seconds): array
	{
		$until = microtime(true) + $seconds;
		do {
			$left = glob($this->site . '/data/*.zip');
			if ($left === []) {
				return [];
			}
			usleep(100000);
		} while (microtime(true) < $until);
		return $left;
	}

	private function header(array $headers, string $name): ?string
	{
		foreach ($headers as $line) {
			if (stripos($line, $name . ':') === 0) {
				return trim(substr($line, strlen($name) + 1));
			}
		}
		return null;
	}

	/** @return string[] the entry names of the zip in $body */
	private function entries(string $body): array
	{
		$file = $this->tmp . '/downloaded.zip';
		file_put_contents($file, $body);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($file), 'the body is a zip archive');
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}
		$zip->close();
		unlink($file);
		return $names;
	}

	private function assertNothingLeftOnDisk(): void
	{
		$this->assertSame([], glob($this->site . '/data/*.zip'), 'no archive left under data/');
		$this->assertSame([], array_values(array_diff(scandir($this->site . '/data'), ['.', '..', 'files', 'database.js'])), 'nothing else left under data/ either');
		$this->assertSame([self::LEGACY, self::UPLOAD, 'photo.jpg'], array_values(array_diff(scandir($this->site . '/data/files'), ['.', '..'])), 'nothing new in data/files');
	}

	public function testTheBackupIsStreamedToTheAdminAndNothingStaysOnDisk(): void
	{
		[$headers, $body] = $this->download();
		$this->assertStringContainsString(' 200', $headers[0] ?? '', implode("\n", $headers));
		$this->assertNull($this->header($headers, 'Location'), 'no redirect to a public URL');
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'));
		$this->assertMatchesRegularExpression('~^attachment; filename="\d{4}-\d{2}-\d{2}-backup-[0-9a-f]{32}\.zip"$~', (string)$this->header($headers, 'Content-Disposition'));
		$this->assertSame((string)strlen($body), $this->header($headers, 'Content-Length'));
		$this->assertSame('no-store', $this->header($headers, 'Cache-Control'));
		$entries = $this->entries($body);
		foreach (['index.php', 'data/database.js', 'data/files/photo.jpg', 'data/files/' . self::UPLOAD, 'themes/sky/theme.php'] as $name) {
			$this->assertContains($name, $entries);
		}
		$this->assertNotContains('data/files/' . self::LEGACY, $entries, 'an old backup is not nested in the new one');
		$this->assertSame([], preg_grep('~\d{4}-\d{2}-\d{2}-backup-[0-9a-f]+\.zip$~', $entries), 'nor the archive itself');
		$this->assertNothingLeftOnDisk();
	}

	public function testALargeUploadIsBackedUpWithoutReadingItIntoMemory(): void
	{
		$big = fopen($this->site . '/data/files/video.mp4', 'wb');
		$chunk = str_repeat("\0", 1 << 20);
		for ($i = 0; $i < 48; $i++) {
			fwrite($big, $chunk);
		}
		fclose($big);
		[$headers, $body] = $this->download(['memory_limit' => '32M']);
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), 'a 48 MB file under a 32 MB memory limit: ' . substr($body, 0, 300));
		$this->assertContains('data/files/video.mp4', $this->entries($body));
		unlink($this->site . '/data/files/video.mp4');
		$this->assertNothingLeftOnDisk();
	}

	public function testTheBackupDownloadsWhereReadfileIsDisabled(): void
	{
		[$headers, $body] = $this->download(['disable_functions' => 'readfile']);
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), substr($body, 0, 300));
		$this->assertContains('data/database.js', $this->entries($body));
		$this->assertNothingLeftOnDisk();
	}

	public function testTheBackupDownloadsWhereReadfileAndFpassthruAreDisabled(): void
	{
		// The last fallback is a plain fread loop; fclose is disabled too, so its guard is pinned as well
		[$headers, $body] = $this->download(['disable_functions' => 'readfile,fpassthru,fclose', 'display_errors' => '1']);
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), substr($body, 0, 300));
		$this->assertSame((string)strlen($body), $this->header($headers, 'Content-Length'), 'the whole archive and nothing after it');
		$this->assertStringNotContainsString('Fatal error', $body);
		$this->assertContains('data/database.js', $this->entries($body));
		$this->assertNothingLeftOnDisk();
	}

	public function testNoDownloadAndAnAlertWhereNoFileCanBeSent(): void
	{
		// readfile and fopen both disabled: the attachment headers went out first and the body was a fatal page saved as a .zip.
		// Now the backup is refused before it is built: no headers, no archive, the page renders with an alert.
		[$headers, $body] = $this->download(['disable_functions' => 'readfile,fopen,fclose', 'display_errors' => '1']);
		$this->assertStringContainsString(' 200', $headers[0] ?? '', implode("\n", $headers));
		$this->assertNotSame('application/zip', $this->header($headers, 'Content-Type'), substr($body, 0, 300));
		$this->assertNull($this->header($headers, 'Content-Disposition'));
		$this->assertStringNotContainsString('Fatal error', $body);
		$this->assertStringContainsString('no download', $body, 'the request goes on to render the page');
		$this->assertStringContainsString('Cannot send the backup: this server disables the PHP functions needed to send a file (readfile, fopen).', $body);
		$this->assertNothingLeftOnDisk();
	}

	public function testANonRemovableOutputBufferDoesNotHangTheBackup(): void
	{
		// A plugin (or auto_prepend_file) opened a buffer that ob_end_clean() cannot remove: each failed call returned false and
		// appended a notice to that very buffer, so the loop ran until the memory limit, a fatal, and the archive stayed on disk.
		$this->writeRouter('ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE);');
		[$headers, $body] = $this->download(['memory_limit' => '32M']);
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), substr($body, 0, 300));
		$this->assertContains('data/database.js', $this->entries($body), 'the body is the archive, nothing in front of it');
		$this->assertNothingLeftOnDisk();
	}

	public function testTheBackupDownloadsWhereTheOutputBufferFunctionsAreDisabled(): void
	{
		// The hardened-host configuration the CI boot check treats as supported
		[$headers, $body] = $this->download(['disable_functions' => 'ob_start,ob_get_level,ob_end_clean,ob_end_flush']);
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), substr($body, 0, 300));
		$this->assertContains('data/database.js', $this->entries($body));
		$this->assertNothingLeftOnDisk();
	}

	public function testOutputAlreadySentMeansNoDownloadAndNothingLeft(): void
	{
		// A plugin printed during loadPlugins() on a host with output_buffering off: the headers are gone, so the archive would
		// arrive as bytes inside an HTML page. The backup is not sent, the archive is removed and the page renders with an alert.
		$this->writeRouter('echo "<p>a plugin printed this</p>"; flush();');
		[$headers, $body] = $this->download(['output_buffering' => '0', 'display_errors' => '1']);
		$this->assertStringContainsString(' 200', $headers[0] ?? '', implode("\n", $headers));
		$this->assertNotSame('application/zip', $this->header($headers, 'Content-Type'), 'no download without its headers');
		$this->assertFalse(strpos($body, 'Cannot modify header information'), 'no header warnings: ' . substr($body, 0, 300));
		$this->assertFalse(strpos($body, 'PK'), 'no zip bytes inside the page');
		$this->assertStringContainsString('no download', $body, 'the request goes on to render the page');
		$this->assertNothingLeftOnDisk();
	}

	public function testACancelledDownloadLeavesNoArchiveBehind(): void
	{
		// The admin closes the tab or cancels the save dialog partway: PHP sees the dead socket on the next write and, unless told
		// to ignore the abort, bails out of the script without running finally; the whole archive then stayed under data/.
		$big = fopen($this->site . '/data/files/video.mp4', 'wb');
		for ($i = 0; $i < 24; $i++) {
			fwrite($big, random_bytes(1 << 20)); // random, so the archive is as large and the socket buffers fill
		}
		fclose($big);
		$port = $this->startServer();
		$client = fsockopen('127.0.0.1', $port, $errno, $errstr, 10);
		$this->assertNotFalse($client, 'connects: ' . $errstr);
		fwrite($client, "GET / HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
		$head = '';
		while (strpos($head, "\r\n\r\n") === false && !feof($client)) {
			$head .= fread($client, 1024);
		}
		$this->assertStringContainsString('Content-Type: application/zip', $head, 'the download started');
		$this->assertCount(1, glob($this->site . '/data/*.zip'), 'the archive is on disk while it is sent');
		fclose($client); // cancelled after the first bytes
		$this->assertSame([], $this->zipsLeftUnderData(20), 'the archive is removed although the admin went away');
		unlink($this->site . '/data/files/video.mp4');
		$this->assertNothingLeftOnDisk();
	}

	public function testTheNextBackupSweepsAStaleArchiveAndKeepsAFreshOne(): void
	{
		// A time limit or a fatal mid-stream skips finally and leaves an archive under data/: the next backup removes any that is
		// older than a request can live, and keeps a fresh one, which another admin's request may be about to send.
		$stale = $this->site . '/data/2026-01-01-backup-' . str_repeat('ab', 16) . '.zip';
		$fresh = $this->site . '/data/' . date('Y-m-d') . '-backup-' . str_repeat('cd', 16) . '.zip';
		$upload = $this->site . '/data/' . self::UPLOAD; // not a backup name: never swept, whatever its age
		file_put_contents($stale, 'left behind');
		file_put_contents($fresh, 'being sent by another request');
		file_put_contents($upload, 'an upload');
		touch($stale, time() - 3600);
		touch($upload, time() - 3600);
		[$headers, $body] = $this->download();
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), substr($body, 0, 300));
		$this->assertFileDoesNotExist($stale, 'the stale archive is swept');
		$this->assertFileExists($fresh, 'a fresh archive is kept');
		$this->assertFileExists($upload, 'a file that is not a backup is kept');
		unlink($fresh);
		unlink($upload);
		$this->assertNothingLeftOnDisk();
	}

	/** Two backups on one server with $ini, the first one's leftover aged past the sweep's threshold in between */
	private function assertTwoBackupsDownloadWithUnlinkDisabled(array $ini): void
	{
		$port = $this->startServer($ini);
		[$headers, $body] = $this->request($port);
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), substr($body, 0, 300));
		$this->assertSame((string)strlen($body), $this->header($headers, 'Content-Length'));
		$this->assertContains('data/database.js', $this->entries($body));
		$this->assertStringNotContainsString('Fatal error', $body);
		$left = glob($this->site . '/data/*.zip');
		$this->assertCount(1, $left, 'without unlink the archive stays under data/');
		touch($left[0], time() - 3600);
		[$headers, $body] = $this->request($port);
		$this->assertSame('application/zip', $this->header($headers, 'Content-Type'), 'the second backup still works: ' . substr($body, 0, 300));
		$entries = $this->entries($body);
		$this->assertContains('data/database.js', $entries);
		$this->assertNotContains('data/' . basename($left[0]), $entries, 'the first archive is not nested in the second');
		$this->assertCount(2, glob($this->site . '/data/*.zip'), 'both stay: nothing can remove them on this host');
		$this->assertSame([self::LEGACY, self::UPLOAD, 'photo.jpg'], array_values(array_diff(scandir($this->site . '/data/files'), ['.', '..'])), 'nothing new in data/files');
	}

	public function testWithUnlinkDisabledEveryBackupDownloadsAndNoneNestsTheLast(): void
	{
		// On PHP 8 a disabled function is undefined, so @unlink() threw: the first backup died after its body, and from the
		// second on the stale-archive sweep made every backup a fatal page until the leftover was deleted over FTP.
		$this->assertTwoBackupsDownloadWithUnlinkDisabled(['disable_functions' => 'unlink', 'display_errors' => '1']);
	}

	public function testTheBackupDownloadsTwiceOnTheCiHardenedHostList(): void
	{
		// The disable_functions list of the CI boot check ("Fresh install boots with the disable-able functions disabled")
		$this->assertTwoBackupsDownloadWithUnlinkDisabled(['disable_functions' => 'flock,fopen,stream_get_contents,fclose,ob_start,ob_get_level,ob_end_clean,ob_end_flush,chmod,rename,unlink,fileowner,filegroup,fileperms,getmypid,exec,shell_exec,system,passthru,proc_open,popen', 'display_errors' => '1']);
	}
}
