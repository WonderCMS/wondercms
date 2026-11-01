<?php

use PHPUnit\Framework\TestCase;

/**
 * The bundled Sky theme renders on a host that disables filemtime(): its stylesheet cache-buster falls back to the theme
 * version. Pinned against a real php -S serving a fresh copy of the site with Sky active (3.7.14 fataled on every page there).
 */
final class SkyThemeTest extends TestCase
{
	/** @var resource|null */
	private $server;
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('the local server writes its output to /dev/null');
		}
		$this->root = sys_get_temp_dir() . '/wcms-sky-' . bin2hex(random_bytes(4));
		mkdir($this->root);
		copy(__DIR__ . '/../index.php', $this->root . '/index.php');
		$themes = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../themes/sky', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		mkdir($this->root . '/themes/sky', 0777, true);
		foreach ($themes as $item) {
			$target = $this->root . '/themes/sky/' . $themes->getSubPathName();
			$item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
		}
	}

	protected function tearDown(): void
	{
		if ($this->server) {
			proc_terminate($this->server);
			proc_close($this->server);
		}
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	/** Start php -S with $ini over the site root; return [status line and headers, body] of a GET of / */
	private function home(array $ini): array
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$name = stream_socket_get_name($socket, false);
		$port = (int)substr($name, strrpos($name, ':') + 1);
		fclose($socket);
		$command = [PHP_BINARY];
		foreach ($ini as $key => $value) {
			array_push($command, '-d', $key . '=' . $value);
		}
		array_push($command, '-S', '127.0.0.1:' . $port, '-t', $this->root);
		$this->server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->root);
		for ($i = 0; $i < 50; $i++) {
			$fp = @fsockopen('127.0.0.1', $port);
			if ($fp) {
				fclose($fp);
				break;
			}
			usleep(100000);
		}
		$context = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
		$body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $context);
		$this->assertNotFalse($body, 'the local server answers');
		return [$http_response_header ?? [], $body];
	}

	public function testSkyRendersWhereFilemtimeIsDisabled(): void
	{
		[$headers, $body] = $this->home(['disable_functions' => 'filemtime', 'display_errors' => '1']);
		$this->assertStringContainsString(' 200 ', $headers[0] ?? '', substr($body, 0, 300));
		$this->assertStringNotContainsString('Fatal error', $body);
		$this->assertSame(1, preg_match('~<link rel="stylesheet"[^>]*href="([^"]+)"~', $body, $m), 'Sky prints its stylesheet link');
		$this->assertStringEndsWith('css/style.css?v=3.7.15', $m[1], 'the cache-buster falls back to the theme version');
		$version = json_decode((string)file_get_contents(__DIR__ . '/../themes/sky/wcms-modules.json'), true)['themes']['sky']['version'] ?? null;
		$this->assertSame('3.7.15', $version, 'theme.php and wcms-modules.json name the same version');
	}

	public function testSkyKeepsTheStylesheetMtimeWhereFilemtimeIsAvailable(): void
	{
		[$headers, $body] = $this->home(['display_errors' => '1']);
		$this->assertStringContainsString(' 200 ', $headers[0] ?? '');
		$this->assertSame(1, preg_match('~css/style\.css\?v=(\d+)"~', $body, $m), 'the cache-buster is the stylesheet mtime');
		$this->assertSame((string)filemtime($this->root . '/themes/sky/css/style.css'), $m[1]);
	}
}
