<?php

use PHPUnit\Framework\TestCase;

/**
 * The session cookie's flags, read from the Set-Cookie header of a real php -S serving a fresh copy of the site:
 * session_start() runs before PHPUnit could see a header, and the CLI sends none.
 */
final class SessionCookieTest extends TestCase
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
		if (!function_exists('curl_init')) {
			$this->markTestSkipped('needs curl to read the response headers');
		}
		$this->root = sys_get_temp_dir() . '/wcms-cookie-' . bin2hex(random_bytes(4));
		mkdir($this->root);
		copy(__DIR__ . '/../index.php', $this->root . '/index.php');
		$themes = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../themes/sky', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		mkdir($this->root . '/themes/sky', 0777, true);
		foreach ($themes as $item) {
			$target = $this->root . '/themes/sky/' . $themes->getSubPathName();
			$item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
		}
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
		fclose($socket);
		$this->base = 'http://127.0.0.1:' . $port;
		$this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->root);
		for ($i = 0; $i < 50 && @file_get_contents($this->base . '/') === false; $i++) {
			usleep(100000);
		}
	}

	protected function tearDown(): void
	{
		proc_terminate($this->server);
		proc_close($this->server);
		$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($items as $item) {
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($this->root);
	}

	/**
	 * @param string $path
	 * @param string[] $headers
	 * @param array|null $post
	 * @return array [the PHPSESSID Set-Cookie line or '', the body]
	 */
	private function request(string $path, array $headers = [], ?array $post = null): array
	{
		$curl = curl_init($this->base . $path);
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
		if ($post !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		}
		$response = (string)curl_exec($curl);
		$headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		if (PHP_VERSION_ID < 80000) {
			curl_close($curl);
		}
		preg_match('~^Set-Cookie: (PHPSESSID=[^\r\n]*)~mi', substr($response, 0, $headerSize), $m);
		return [$m[1] ?? '', substr($response, $headerSize)];
	}

	public function testTheSessionCookieIsHttpOnlyAndLaxAndSecureOnlyOverHttps(): void
	{
		[$cookie] = $this->request('/');
		$this->assertNotSame('', $cookie, 'a visitor gets a session cookie');
		$this->assertStringContainsStringIgnoringCase('; HttpOnly', $cookie);
		$this->assertStringContainsStringIgnoringCase('; SameSite=Lax', $cookie);
		$this->assertStringNotContainsStringIgnoringCase('; secure', $cookie, 'plain HTTP: a Secure cookie would never come back');

		[$cookie] = $this->request('/', ['X-Forwarded-Proto: https']);
		$this->assertStringContainsStringIgnoringCase('; secure', $cookie, 'over HTTPS (as isCurrentlyOnSSL() sees it) the browser never sends it in clear');
		$this->assertStringContainsStringIgnoringCase('; HttpOnly', $cookie);
		$this->assertStringContainsStringIgnoringCase('; SameSite=Lax', $cookie);
	}

	public function testASessionIdTheServerNeverIssuedIsReplaced(): void
	{
		$planted = bin2hex(random_bytes(16)); // a fresh one: a fixed id would exist in the shared session folder after one run without strict mode
		[$cookie] = $this->request('/', ['Cookie: PHPSESSID=' . $planted]);
		$this->assertStringStartsWith('PHPSESSID=', $cookie, 'a new id is sent (without strict mode the planted one is adopted and no cookie is sent)');
		$this->assertStringNotContainsString($planted, $cookie);
	}

	public function testTheLoginIssuesTheCookieWithTheFlags(): void
	{
		[$cookie, $home] = $this->request('/');
		$this->assertSame(1, preg_match('~everything is: <b>([^<]+)</b>~', $home, $m), 'the install password is on the first page');
		$visitorId = explode(';', $cookie)[0];
		[$login] = $this->request('/?page=loginURL', ['Cookie: ' . $visitorId], ['password' => $m[1]]);
		$this->assertStringStartsWith('PHPSESSID=', $login, 'the login sends a new session id');
		$this->assertNotSame($visitorId, explode(';', $login)[0]);
		$this->assertStringContainsStringIgnoringCase('; HttpOnly', $login);
		$this->assertStringContainsStringIgnoringCase('; SameSite=Lax', $login);
		[, $admin] = $this->request('/', ['Cookie: ' . explode(';', $login)[0]]);
		$this->assertStringContainsString('id="settingsModal"', $admin, 'and that session is the admin');
	}
}
