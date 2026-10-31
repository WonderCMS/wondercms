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
		file_put_contents($this->tmp . '/router.php', '<?php
			define("PHPUNIT_TESTING", true);
			require ' . var_export(realpath(__DIR__ . '/../index.php'), true) . ';
			$wcms = new Wcms("data", "files", "database.js", ' . var_export($this->site, true) . ');
			$wcms->loggedIn = true;
			$_SESSION = [];
			$_POST = ["backup" => "", "token" => $wcms->getToken()];
			$wcms->backupAction();
			echo "no download";');
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

	/** Start php -S with $ini settings and request a backup; return [status line and headers, body] */
	private function download(array $ini = []): array
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
		$context = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true, 'follow_location' => 0]]);
		for ($i = 0; $i < 50; $i++) {
			$fp = @fsockopen('127.0.0.1', $port);
			if ($fp) {
				fclose($fp);
				break;
			}
			usleep(100000);
		}
		$body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $context);
		$this->assertNotFalse($body, 'the local server answers');
		return [$http_response_header ?? [], $body];
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
		$this->assertSame([self::LEGACY, 'photo.jpg'], array_values(array_diff(scandir($this->site . '/data/files'), ['.', '..'])), 'nothing new in data/files');
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
		foreach (['index.php', 'data/database.js', 'data/files/photo.jpg', 'themes/sky/theme.php'] as $name) {
			$this->assertContains($name, $entries);
		}
		$this->assertNotContains('data/files/' . self::LEGACY, $entries, 'an old backup is not nested in the new one');
		$this->assertSame([], preg_grep('~-backup-[0-9a-f]+\.zip$~', $entries), 'nor the archive itself');
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
}
