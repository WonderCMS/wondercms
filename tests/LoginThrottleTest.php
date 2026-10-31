<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../index.php';

/**
 * Failed logins are counted per connecting address (REMOTE_ADDR only, IPv6 per /64): the 11th POST to the login slug
 * within 15 minutes gets HTTP 429 and the login page, without a password check. A success clears the address; a
 * browser that logged in before (its cookie's hash is in config) is never refused, so a visitor sharing the owner's
 * address cannot lock the owner out. The counts live in data/logs/, never in database.js.
 */
final class LoginThrottleTest extends TestCase
{
	private const DB_PATH = __DIR__ . '/../data_test/database_test.js';
	private const ATTEMPTS_PATH = __DIR__ . '/../data_test/logs/login-attempts.json';
	private const PASSWORD = 'correctPass1';
	private const TOO_MANY = 'Too many attempts. Try again in a few minutes.';
	private const COOKIE = 'wcms_known_browser';

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		@unlink(self::DB_PATH);
		@unlink(self::ATTEMPTS_PATH);
		$wcms = $this->wcms();
		$wcms->set('config', 'password', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
	}

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = $_COOKIE = [];
		unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP']);
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir'], $_SESSION['knownBrowserIssued']);
		@unlink(self::ATTEMPTS_PATH);
		@unlink(self::DB_PATH);
	}

	private function wcms(): Wcms
	{
		return $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['redirect'])
			->getMock();
	}

	private function post(string $password, ?string $address): Wcms
	{
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['rootDir']);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		if ($address === null) {
			unset($_SERVER['REMOTE_ADDR']);
		} else {
			$_SERVER['REMOTE_ADDR'] = $address;
		}
		$_POST['password'] = $password;
		$wcms = $this->wcms();
		$wcms->currentPage = 'loginURL';
		$wcms->loginAction();
		return $wcms;
	}

	private function failTimes(string $address, int $times): void
	{
		for ($i = 0; $i < $times; $i++) {
			$wcms = $this->post('wrongPass', $address);
			$this->assertNotSame('HTTP/1.1 429 Too Many Requests', $wcms->headerResponse, 'failure ' . ($i + 1) . ' is not refused yet');
		}
	}

	private function assertRefused(Wcms $wcms): void
	{
		$this->assertSame('HTTP/1.1 429 Too Many Requests', $wcms->headerResponse);
		$this->assertFalse(isset($_SESSION['loggedIn']), 'not logged in');
		$this->assertSame(self::TOO_MANY, $_SESSION['alert']['danger'][0]['message'] ?? null);
	}

	private function assertLoggedIn(Wcms $wcms): void
	{
		$this->assertNotSame('HTTP/1.1 429 Too Many Requests', $wcms->headerResponse);
		$this->assertTrue($_SESSION['loggedIn'] ?? false, 'logged in');
	}

	private function attempts(): array
	{
		return is_file(self::ATTEMPTS_PATH) ? json_decode(file_get_contents(self::ATTEMPTS_PATH), true) : [];
	}

	private function knownBrowser(Wcms $wcms): string
	{
		$value = bin2hex(random_bytes(32));
		$wcms->set('config', 'knownBrowsers', [hash('sha256', $value) => date('Y/m/d H:i:s')]);
		return $value;
	}

	public function testTenFailuresThenTheEleventhIsA429EvenWithTheRightPassword(): void
	{
		$this->failTimes('203.0.113.9', 10);

		$this->assertRefused($this->post('wrongPass', '203.0.113.9'));
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testARefusedAttemptNeverChecksThePassword(): void
	{
		$this->failTimes('203.0.113.9', 10);
		$this->wcms()->set('config', 'password', '$2y$16$' . str_repeat('a', 22) . str_repeat('b', 31)); // a cost-16 check takes seconds
		$failed = 0;
		$wcms = $this->wcms();
		$wcms->addListener('login_failed', static function ($args) use (&$failed) {
			$failed++;
			return $args;
		});

		unset($_SESSION['alert']);
		$start = microtime(true);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_POST['password'] = 'wrongPass';
		$wcms->currentPage = 'loginURL';
		$wcms->loginAction();

		$this->assertRefused($wcms);
		$this->assertLessThan(1.0, microtime(true) - $start, 'no bcrypt check on the 429 path');
		$this->assertSame(0, $failed, 'login_failed is for a checked password only');
	}

	public function testTheWrongPasswordPathKeepsTheLoginFailedHookAndAlert(): void
	{
		$seen = null;
		$wcms = $this->wcms();
		$wcms->addListener('login_failed', static function ($args) use (&$seen) {
			$seen = $args;
			return $args;
		});
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_POST['password'] = 'wrongPass';
		$wcms->currentPage = 'loginURL';
		$wcms->loginAction();

		$this->assertSame('203.0.113.9', $seen[0]['ip'] ?? null);
		$this->assertSame('Wrong password.', $_SESSION['alert']['danger'][0]['message']);
	}

	public function testTheAttemptIsCountedBeforeThePasswordIsChecked(): void
	{
		$counted = null;
		$wcms = $this->wcms();
		$wcms->addListener('login_failed', function ($args) use (&$counted) {
			$counted = count($this->attempts()['203.0.113.9'] ?? []);
			return $args;
		});
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_POST['password'] = 'wrongPass';
		$wcms->currentPage = 'loginURL';
		$wcms->loginAction();

		$this->assertSame(1, $counted, 'parallel requests cannot all pass the check before any is counted');
	}

	public function testAfterTheWindowTheAddressLogsIn(): void
	{
		$this->failTimes('203.0.113.9', 10);
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
		$aged = array_map(static function (array $times): array {
			return array_map(static function (int $time): int {
				return $time - 901;
			}, $times);
		}, $this->attempts());
		file_put_contents(self::ATTEMPTS_PATH, json_encode($aged));

		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testTheWindowSlides(): void
	{
		$now = time();
		$times = [];
		for ($i = 0; $i < 9; $i++) {
			$times[] = $now - 600;
		}
		$times[] = $now - 901; // one failure outside the window: 9 count
		file_put_contents(self::ATTEMPTS_PATH, json_encode(['203.0.113.9' => $times]));

		$this->failTimes('203.0.113.9', 1);
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testAnotherAddressIsNotAffected(): void
	{
		$this->failTimes('203.0.113.9', 10);
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));

		$this->assertLoggedIn($this->post(self::PASSWORD, '198.51.100.20'));
	}

	public function testForwardingHeadersDoNotChooseTheBucket(): void
	{
		for ($i = 0; $i < 10; $i++) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.' . $i;
			$_SERVER['HTTP_CLIENT_IP'] = '192.0.2.' . (100 + $i);
			$this->post('wrongPass', '203.0.113.9');
		}

		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testAnIpv6ClientIsCountedPerSlash64(): void
	{
		for ($i = 1; $i <= 10; $i++) {
			$this->post('wrongPass', '2001:db8:1:2::' . dechex($i));
		}

		$this->assertRefused($this->post(self::PASSWORD, '2001:db8:1:2:ffff::1'));
		$this->assertLoggedIn($this->post(self::PASSWORD, '2001:db8:1:3::1'));
	}

	public function testIpv4MappedAddressesAreNotOneBucket(): void
	{
		$this->failTimes('::ffff:203.0.113.9', 10);

		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
		$this->assertLoggedIn($this->post(self::PASSWORD, '::ffff:198.51.100.20'));
	}

	public function testAMissingAddressIsNeverOneSharedBucket(): void
	{
		for ($i = 0; $i < 12; $i++) {
			$this->post('wrongPass', null);
			$this->post('wrongPass', 'not-an-address');
		}

		$this->assertLoggedIn($this->post(self::PASSWORD, null));
		$this->assertSame([], $this->attempts());
	}

	public function testASuccessClearsTheAddress(): void
	{
		$this->failTimes('203.0.113.9', 9);
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
		$this->assertArrayNotHasKey('203.0.113.9', $this->attempts());

		$this->failTimes('203.0.113.9', 10);
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testAKnownBrowserLogsInWhileItsAddressIsThrottled(): void
	{
		$cookie = $this->knownBrowser($this->wcms());
		$this->failTimes('203.0.113.9', 10);
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));

		$_COOKIE[self::COOKIE] = $cookie;
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testAKnownBrowsersMistakesAreNotCounted(): void
	{
		$_COOKIE[self::COOKIE] = $this->knownBrowser($this->wcms());
		for ($i = 0; $i < 12; $i++) {
			$this->post('wrongPass', '203.0.113.9');
		}
		$this->assertSame([], $this->attempts());
		$this->assertSame('Wrong password.', $_SESSION['alert']['danger'][0]['message']);

		unset($_COOKIE[self::COOKIE]);
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testAForgedCookieDoesNotExempt(): void
	{
		$this->knownBrowser($this->wcms());
		$this->failTimes('203.0.113.9', 10);

		foreach ([bin2hex(random_bytes(32)), '', 'x', ['a']] as $forged) {
			$_COOKIE[self::COOKIE] = $forged;
			$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
		}
	}

	public function testTheCookieAloneDoesNotLogIn(): void
	{
		$_COOKIE[self::COOKIE] = $this->knownBrowser($this->wcms());

		$wcms = $this->post('wrongPass', '203.0.113.9');

		$this->assertFalse(isset($_SESSION['loggedIn']));
		$wcms->loginStatus();
		$this->assertFalse($wcms->loggedIn);
	}

	public function testALoginRemembersTheBrowserOnlyAsAHash(): void
	{
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));

		$known = (array)$this->wcms()->get('config', 'knownBrowsers');
		$this->assertCount(1, $known);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)array_key_first($known));
	}

	public function testAKnownBrowserKeepsItsEntryAndTheListIsCapped(): void
	{
		$wcms = $this->wcms();
		$cookie = $this->knownBrowser($wcms);
		$_COOKIE[self::COOKIE] = $cookie;
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
		$this->assertSame([hash('sha256', $cookie)], array_keys((array)$this->wcms()->get('config', 'knownBrowsers')), 'the same browser logging in again adds nothing');

		unset($_COOKIE[self::COOKIE]);
		for ($i = 0; $i < 12; $i++) {
			$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
		}
		$this->assertCount(10, (array)$this->wcms()->get('config', 'knownBrowsers'));
	}

	private function adminSession(): Wcms
	{
		$_SESSION['loggedIn'] = true;
		$_SESSION['rootDir'] = $this->wcms()->rootDir;
		$wcms = $this->wcms();
		$wcms->loginStatus();
		$this->assertTrue($wcms->loggedIn);
		return $wcms;
	}

	public function testASessionLoggedInBeforeTheUpdateGetsTheCookieOnce(): void
	{
		$this->assertSame([], (array)$this->wcms()->get('config', 'knownBrowsers'), 'upgrade day: no browser is known');

		$this->adminSession()->knownBrowserAction();

		$known = (array)$this->wcms()->get('config', 'knownBrowsers');
		$this->assertCount(1, $known);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)array_key_first($known), 'only a hash is stored');
		$database = file_get_contents(self::DB_PATH);
		$this->adminSession()->knownBrowserAction();
		$this->assertSame($database, file_get_contents(self::DB_PATH), 'once per session, not a database write per admin request');
	}

	public function testAKnownBrowsersSessionIsLeftAlone(): void
	{
		$_COOKIE[self::COOKIE] = $this->knownBrowser($this->wcms());
		$database = file_get_contents(self::DB_PATH);

		$this->adminSession()->knownBrowserAction();

		$this->assertSame($database, file_get_contents(self::DB_PATH));
	}

	public function testAVisitorIsNeverGivenTheCookie(): void
	{
		$wcms = $this->wcms();
		$wcms->loginStatus();
		$database = file_get_contents(self::DB_PATH);

		$wcms->knownBrowserAction();

		$this->assertSame($database, file_get_contents(self::DB_PATH));
		$this->assertArrayNotHasKey('knownBrowsers', (array)json_decode($database, true)['config']);
	}

	public function testASessionsCookieKeepsTheTenBrowserCap(): void
	{
		$known = [];
		for ($i = 0; $i < 10; $i++) {
			$known[hash('sha256', (string)$i)] = '2026/01/01 00:00:0' . (9 - $i); // stored newest first
		}
		$this->wcms()->set('config', 'knownBrowsers', $known);

		$this->adminSession()->knownBrowserAction();

		$after = (array)$this->wcms()->get('config', 'knownBrowsers');
		$this->assertCount(10, $after);
		$this->assertArrayNotHasKey(hash('sha256', '9'), $after, 'the oldest kept entry made room');
		$this->assertNotContains(array_key_first($after), array_keys($known), 'the new browser is first');
	}

	public function testTheEditorCannotWriteKnownBrowsers(): void
	{
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
		$wcms = $this->wcms();
		$wcms->loginStatus();
		$before = $wcms->get('config', 'knownBrowsers');
		$_POST = ['fieldname' => 'knownBrowsers', 'content' => 'x', 'target' => 'config', 'token' => $wcms->getToken()];

		$wcms->saveAction();

		$this->assertEquals($before, $this->wcms()->get('config', 'knownBrowsers'));
	}

	public function testAFailedLoginNeverWritesTheDatabase(): void
	{
		$before = file_get_contents(self::DB_PATH);

		$this->failTimes('203.0.113.9', 10);
		$this->post(self::PASSWORD, '203.0.113.9');

		$this->assertSame($before, file_get_contents(self::DB_PATH));
		$this->assertFileExists(self::ATTEMPTS_PATH);
	}

	public function testTheAttemptsFileKeepsOnlyTheWindow(): void
	{
		$stale = [];
		for ($i = 0; $i < 200; $i++) {
			$stale['198.51.100.' . $i] = [time() - 901, time() - 3600];
		}
		file_put_contents(self::ATTEMPTS_PATH, json_encode($stale));

		$this->failTimes('203.0.113.9', 10);
		$this->post('wrongPass', '203.0.113.9');

		$this->assertSame(['203.0.113.9'], array_keys($this->attempts()));
		$this->assertCount(10, $this->attempts()['203.0.113.9'], 'a refused attempt is not stored');
	}

	public function testTheAttemptsFileIsCappedInAddresses(): void
	{
		$many = [];
		for ($i = 0; $i < 1005; $i++) {
			$many['10.0.' . intdiv($i, 256) . '.' . ($i % 256)] = [time() - 60];
		}
		file_put_contents(self::ATTEMPTS_PATH, json_encode($many));

		$this->post('wrongPass', '203.0.113.9');

		$this->assertCount(1000, $this->attempts());
		$this->assertArrayHasKey('203.0.113.9', $this->attempts());
	}

	public function testAVisitorGetNeverWritesTheAttemptsFile(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		foreach (['loginURL', 'home', 'missing-page'] as $page) {
			$wcms = $this->wcms();
			$wcms->currentPage = $page;
			$wcms->loginAction();
		}

		$this->assertFileDoesNotExist(self::ATTEMPTS_PATH);
	}

	public function testAnUnwritableLogsFolderNeverBlocksALogin(): void
	{
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('root writes through any mode');
		}
		$logs = dirname(self::ATTEMPTS_PATH);
		if (!is_dir($logs)) {
			mkdir($logs);
		}
		chmod($logs, 0555);
		try {
			for ($i = 0; $i < 12; $i++) {
				$this->post('wrongPass', '203.0.113.9');
			}
			$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
		} finally {
			chmod($logs, 0755);
		}
	}

	/**
	 * The same flow over HTTP against php -S: the status line, Retry-After, the login page body, the cookie's flags.
	 */
	public function testTheEleventhPostIsA429OverHttp(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('the local server writes its output to /dev/null');
		}
		$root = sys_get_temp_dir() . '/wcms-throttle-' . bin2hex(random_bytes(4));
		mkdir($root);
		copy(__DIR__ . '/../index.php', $root . '/index.php');
		mkdir($root . '/themes');
		mkdir($root . '/themes/sky');
		foreach (glob(__DIR__ . '/../themes/sky/*') as $file) {
			if (is_file($file)) {
				copy($file, $root . '/themes/sky/' . basename($file));
			}
		}
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
		fclose($socket);
		$base = 'http://127.0.0.1:' . $port;
		$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
		try {
			for ($i = 0; $i < 50 && @file_get_contents($base . '/') === false; $i++) {
				usleep(100000);
			}
			$this->assertFileExists($root . '/data/database.js', 'the first request installed the site');
			$this->assertFileDoesNotExist($root . '/data/logs/login-attempts.json', 'a visitor GET writes nothing');
			$site = new Wcms('data', 'files', 'database.js', $root);
			$site->set('config', 'password', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));

			$first = $this->request($base, self::PASSWORD, null);
			$this->assertStringContainsString(' 302 ', $first['status']);
			$this->assertMatchesRegularExpression('/^Set-Cookie: ' . self::COOKIE . '=([0-9a-f]{64});.*expires=.*;.*HttpOnly/mi', $first['headers']);
			$this->assertMatchesRegularExpression('/^Set-Cookie: ' . self::COOKIE . '=.*SameSite=Lax/mi', $first['headers']);
			preg_match('/^Set-Cookie: ' . self::COOKIE . '=([0-9a-f]{64})/mi', $first['headers'], $match);
			$cookie = $match[1];
			$database = file_get_contents($root . '/data/database.js');
			$this->assertStringNotContainsString($cookie, $database, 'only the hash is stored');
			$this->assertStringContainsString(hash('sha256', $cookie), $database);

			$before = file_get_contents($root . '/data/database.js');
			for ($i = 0; $i < 10; $i++) {
				$this->assertStringContainsString(' 302 ', $this->request($base, 'wrongPass', null)['status']);
			}
			$this->assertSame($before, file_get_contents($root . '/data/database.js'), 'failed logins never write database.js');

			$refused = $this->request($base, self::PASSWORD, null);
			$this->assertStringContainsString(' 429 ', $refused['status']);
			$this->assertMatchesRegularExpression('/^Retry-After: \d+\r?$/mi', $refused['headers']);
			$this->assertStringContainsString(self::TOO_MANY, $refused['body']);
			$this->assertStringContainsString('name="password"', $refused['body'], 'the normal login page');
			$this->assertStringNotContainsString(self::COOKIE, $refused['headers']);

			$known = $this->request($base, self::PASSWORD, $cookie);
			$this->assertStringContainsString(' 302 ', $known['status'], 'the browser that logged in before gets through');

			// upgrade day: a session logged in before the update has no cookie; its next page view is given one
			preg_match_all('/^Set-Cookie: (PHPSESSID=[^;]+)/mi', $first['headers'], $sessions);
			$session = (string)end($sessions[1]); // the id regenerated at login
			$site = new Wcms('data', 'files', 'database.js', $root);
			$site->set('config', 'knownBrowsers', []);
			$page = $this->request($base, null, null, $session);
			$this->assertStringContainsString('id="settingsModal"', $page['body'], 'the session is the admin\'s');
			$this->assertMatchesRegularExpression('/^Set-Cookie: ' . self::COOKIE . '=([0-9a-f]{64});.*HttpOnly/mi', $page['headers']);
			preg_match('/^Set-Cookie: ' . self::COOKIE . '=([0-9a-f]{64})/mi', $page['headers'], $match);
			$this->assertStringContainsString(hash('sha256', $match[1]), file_get_contents($root . '/data/database.js'));
			$this->assertStringNotContainsString(self::COOKIE, $this->request($base, null, null, $session)['headers'], 'once per session');
			$this->assertStringContainsString(' 429 ', $this->request($base, self::PASSWORD, null)['status'], 'the address is still throttled');
			$this->assertStringContainsString(' 302 ', $this->request($base, self::PASSWORD, $match[1])['status'], 'the cookie the session got gets through');
		} finally {
			proc_terminate($server);
			proc_close($server);
			$this->removeTree($root);
		}
	}

	/**
	 * A POST of the password to the login page, or with no password a GET of the home page
	 */
	private function request(string $base, ?string $password, ?string $cookie, string $session = ''): array
	{
		$cookies = array_filter([$session, $cookie !== null ? self::COOKIE . '=' . $cookie : '']);
		$context = stream_context_create(['http' => [
			'method' => $password !== null ? 'POST' : 'GET',
			'header' => "Content-Type: application/x-www-form-urlencoded\r\n" . ($cookies ? 'Cookie: ' . implode('; ', $cookies) . "\r\n" : ''),
			'content' => $password !== null ? http_build_query(['password' => $password]) : '',
			'follow_location' => 0,
			'ignore_errors' => true,
		]]);
		$body = (string)file_get_contents($base . ($password !== null ? '/?page=loginURL' : '/'), false, $context);
		$headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []); // the variable is deprecated in PHP 8.5
		return ['status' => (string)($headers[0] ?? ''), 'headers' => implode("\r\n", $headers), 'body' => $body];
	}

	private function removeTree(string $path): void
	{
		if (is_dir($path) && !is_link($path)) {
			foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
				$this->removeTree($path . '/' . $entry);
			}
			@rmdir($path);
		} else {
			@unlink($path);
		}
	}
}
