<?php

use PHPUnit\Framework\TestCase;

/**
 * downloadFileFromUrl() sets CURLOPT_FAILONERROR: an HTTP error (a GitHub 404 or 5xx, a rate-limit 429) comes back as an
 * empty string with the "Cannot get content from url." alert, never as the error page's body (which the version check
 * would compare as a version and the updater would parse as a core). Pinned against a real local server, not a mock.
 */
final class DownloadTest extends TestCase
{
	/** @var resource */
	private $server;
	/** @var string */
	private $base;
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('the local server writes its output to /dev/null');
		}
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$this->root = sys_get_temp_dir() . '/wcms-download-' . bin2hex(random_bytes(4));
		mkdir($this->root);
		file_put_contents($this->root . '/router.php', '<?php
			$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
			if ($path === "/ok") { echo "3.9.9"; return true; }
			if ($path === "/missing") { http_response_code(404); echo "<html>Not Found</html>"; return true; }
			if ($path === "/broken") { http_response_code(500); echo "<html>Server Error</html>"; return true; }
			if ($path === "/limited") { http_response_code(429); echo "API rate limit exceeded"; return true; }
			http_response_code(404); return true;');
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
		fclose($socket);
		$this->base = 'http://127.0.0.1:' . $port;
		$this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->root . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->root);
		for ($i = 0; $i < 50 && @file_get_contents($this->base . '/ok') !== '3.9.9'; $i++) {
			usleep(100000);
		}
		$this->assertSame('3.9.9', @file_get_contents($this->base . '/ok'), 'the local server answers');
		@unlink(__DIR__ . '/../data_test/database_test.js');
	}

	protected function tearDown(): void
	{
		proc_terminate($this->server);
		proc_close($this->server);
		@unlink($this->root . '/router.php');
		@rmdir($this->root);
		@unlink(__DIR__ . '/../data_test/database_test.js');
		unset($_SESSION['alert']);
	}

	public function testAnHttpErrorIsAnEmptyDownloadWithTheAlertNeverTheErrorPage(): void
	{
		$wcms = new Wcms('data_test', 'files_test', 'database_test.js');
		$this->assertSame('3.9.9', $wcms->getFileFromRepo('/ok', $this->base));
		$this->assertArrayNotHasKey('alert', $_SESSION);
		foreach (['/missing' => 404, '/broken' => 500, '/limited' => 429] as $path => $status) {
			unset($_SESSION['alert']);
			$this->assertSame('', $wcms->getFileFromRepo($path, $this->base), $path . ' must not return the error page as content');
			$this->assertSame('Cannot get content from url (HTTP ' . $status . ').', $_SESSION['alert']['danger'][0]['message'] ?? '', $path . ': the alert names the status');
		}
	}

	public function testARefusedConnectionIsAnEmptyDownloadWithThePlainAlert(): void
	{
		// A refused port answers at once, so this pins the empty result and the alert, not CURLOPT_CONNECTTIMEOUT.
		$wcms = new Wcms('data_test', 'files_test', 'database_test.js');
		$this->assertSame('', $wcms->getFileFromRepo('/ok', 'http://127.0.0.1:9')); // the discard port: nothing listens
		$this->assertSame('Cannot get content from url.', $_SESSION['alert']['danger'][0]['message'] ?? '', 'no HTTP status, so the sentence the translation plugins match');
	}

	public function testAModuleZipThatAnswers404IsReportedWithItsStatus(): void
	{
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'getSingleModuleCachedData'])->getMock();
		$wcms->method('getSingleModuleCachedData')->willReturn(['zip' => $this->base . '/missing/gone.zip']);
		$wcms->method('redirect')->willThrowException(new RuntimeException('redirected')); // the real redirect() exits
		$wcms->loggedIn = true;
		$_REQUEST = $_GET = ['installModule' => 'gone', 'type' => 'themes', 'token' => $wcms->getToken()];
		try {
			$wcms->installUpdateModuleAction();
			$this->fail('the action redirects after the alert');
		} catch (RuntimeException $e) {
			$this->assertSame('redirected', $e->getMessage());
		} finally {
			$_REQUEST = $_GET = [];
		}
		$this->assertStringContainsString('Error opening ZIP file. Error description: The requested URL returned error: 404', $_SESSION['alert']['danger'][0]['message'] ?? '');
		$this->assertDirectoryDoesNotExist(dirname(__DIR__) . '/themes/gone');
	}
}
