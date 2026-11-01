<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../index.php';

/**
 * Failed logins are counted per connecting address (REMOTE_ADDR only, IPv6 per /64; private and reserved addresses, loopback included, never): the 11th POST to
 * the login slug within 15 minutes gets HTTP 429 and the login page, without a password check, and a success clears the
 * address. The counts live in data/logs/, never in database.js, under a salted hash of the address, never the address;
 * nothing identifies a browser (no cookie but the session one).
 */
final class LoginThrottleTest extends TestCase
{
	private const DB_PATH = __DIR__ . '/../data_test/database_test.js';
	private const ATTEMPTS_PATH = __DIR__ . '/../data_test/logs/login-attempts.json';
	private const PASSWORD = 'correctPass1';
	private const TOO_MANY = 'Too many attempts. Try again in a few minutes.';

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		@unlink(self::DB_PATH);
		@unlink(self::ATTEMPTS_PATH);
		wcmsSetPasswordOnDisk($this->wcms(), self::PASSWORD);
	}

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = [];
		unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP']);
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
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

	/**
	 * The key the file stores for an address, learned the way the site learns it: one failed login on an empty file
	 */
	private function keyOf(string $address): string
	{
		$saved = is_file(self::ATTEMPTS_PATH) ? file_get_contents(self::ATTEMPTS_PATH) : null;
		@unlink(self::ATTEMPTS_PATH);
		$this->post('wrongPass', $address);
		$keys = array_keys($this->attempts());
		$this->assertCount(1, $keys, 'one failure, one key');
		if ($saved === null) {
			unlink(self::ATTEMPTS_PATH);
		} else {
			file_put_contents(self::ATTEMPTS_PATH, $saved);
		}
		return (string)$keys[0];
	}

	public function testTenFailuresThenTheEleventhIsA429EvenWithTheRightPassword(): void
	{
		$this->failTimes('203.0.113.9', 10);

		$this->assertRefused($this->post('wrongPass', '203.0.113.9'));
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testARefusedAttemptNeverChecksThePassword(): void
	{
		$db = json_decode(file_get_contents(self::DB_PATH));
		$db->config->password = '$2y$14$' . str_repeat('a', 22) . str_repeat('b', 31); // a cost-14 check: a measurable time; a hash, not a password, so not through wcmsSetPasswordOnDisk()
		file_put_contents(self::DB_PATH, json_encode($db, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		$start = microtime(true);
		$key = $this->keyOf('203.0.113.9');
		$checked = microtime(true) - $start;
		$this->assertGreaterThan(0.1, $checked, 'a checked password takes measurable time');
		file_put_contents(self::ATTEMPTS_PATH, json_encode([$key => array_fill(0, 10, time())]));
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
		$this->assertLessThan($checked / 4, microtime(true) - $start, 'no bcrypt check on the 429 path');
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
			$counted = array_sum(array_map('count', $this->attempts()));
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

	public function testARefusedAttemptIsNotCounted(): void
	{
		$this->failTimes('203.0.113.9', 10);
		$attempts = $this->attempts();
		$this->assertCount(1, $attempts, 'one address, one key');
		$key = (string)array_keys($attempts)[0];
		$this->assertCount(10, $attempts[$key]);
		$now = time();
		$times = array_fill(0, 9, $now - 600);
		array_unshift($times, $now - 800); // spread, so counting a refusal would move the oldest and the wait
		file_put_contents(self::ATTEMPTS_PATH, json_encode([$key => $times]));
		$before = file_get_contents(self::ATTEMPTS_PATH);
		$waitBefore = $now - 800 + 900 - time();

		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));

		$this->assertSame($before, file_get_contents(self::ATTEMPTS_PATH), 'a refused attempt is not counted');
		$this->assertCount(10, $this->attempts()[$key]);
		$method = new ReflectionMethod(Wcms::class, 'countLoginAttempt');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		$this->assertLessThanOrEqual($waitBefore, $method->invoke($this->wcms(), $key), 'Retry-After did not grow');
	}

	public function testTheWindowSlides(): void
	{
		$now = time();
		$times = array_fill(0, 9, $now - 600);
		$times[] = $now - 901; // one failure outside the window: 9 count
		file_put_contents(self::ATTEMPTS_PATH, json_encode([$this->keyOf('203.0.113.9') => $times]));

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
			$this->post('wrongPass', '2606:4700:1:2::' . dechex($i));
		}

		$this->assertRefused($this->post(self::PASSWORD, '2606:4700:1:2:ffff::1'));
		$this->assertLoggedIn($this->post(self::PASSWORD, '2606:4700:1:3::1'));
	}

	public function testIpv4MappedAddressesAreNotOneBucket(): void
	{
		$this->failTimes('::ffff:203.0.113.9', 10);

		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
		$this->assertLoggedIn($this->post(self::PASSWORD, '::ffff:198.51.100.20'));
	}

	/**
	 * An IPv6-only server behind a NAT64 translator sees every IPv4 client as 64:ff9b::<ipv4>, all in one /64
	 */
	public function testANat64AddressCountsAsItsEmbeddedIpv4(): void
	{
		$this->failTimes('64:ff9b::203.0.113.9', 10);

		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
		$this->assertRefused($this->post(self::PASSWORD, '64:ff9b::cb00:7109'));
		$this->assertLoggedIn($this->post(self::PASSWORD, '64:ff9b::198.51.100.20'));
	}

	public function testANat64PrivateAddressIsNeverThrottled(): void
	{
		for ($i = 0; $i < 12; $i++) {
			$this->post('wrongPass', '64:ff9b::10.0.0.5');
		}
		$this->assertLoggedIn($this->post(self::PASSWORD, '64:ff9b::10.0.0.5'));
		$this->assertFileDoesNotExist(self::ATTEMPTS_PATH, 'a private address is not counted either');
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

	public function testLoopbackIsNeverThrottled(): void
	{
		foreach (['127.0.0.1', '::1', '::ffff:127.0.0.1', '127.0.0.2'] as $address) {
			for ($i = 0; $i < 12; $i++) {
				$this->post('wrongPass', $address);
			}
			$this->assertLoggedIn($this->post(self::PASSWORD, $address));
		}
		$this->assertFileDoesNotExist(self::ATTEMPTS_PATH, 'loopback is not counted either');
	}

	/**
	 * Behind a remote proxy, inside a container (Docker Desktop's bridge gateway), on Tailscale or a cloud load balancer
	 * (shared address space 100.64.0.0/10) or on a host fronting PHP over a private address, every visitor reaches PHP
	 * from one such address
	 */
	public function testAPrivateAddressIsNeverThrottled(): void
	{
		foreach (['10.0.0.5', '172.18.0.2', '192.168.65.1', 'fc00::1', '::ffff:10.0.0.5', '100.64.0.1', '198.18.0.1', '192.0.0.9'] as $address) {
			for ($i = 0; $i < 12; $i++) {
				$this->post('wrongPass', $address);
			}
			$this->assertLoggedIn($this->post(self::PASSWORD, $address));
		}
		$this->assertFileDoesNotExist(self::ATTEMPTS_PATH, 'a private address is not counted either');

		$this->failTimes('203.0.113.9', 10);
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
	}

	/**
	 * The exempt list is the same on every PHP version: PHP 7.4's reserved-range filter left 2a02:10::1 unthrottled,
	 * and the addresses just below 100.64.0.0/10 and 198.18.0.0/15 are ordinary clients
	 */
	public function testAPublicAddressNextToAnExemptRangeIsThrottled(): void
	{
		foreach (['2a02:10::1', '100.63.255.255', '198.17.255.255'] as $address) {
			$this->failTimes($address, 10);
			$this->assertRefused($this->post(self::PASSWORD, $address));
		}
	}

	public function testASuccessClearsTheAddress(): void
	{
		$this->failTimes('203.0.113.9', 9);
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));
		$this->assertSame([], $this->attempts());

		$this->failTimes('203.0.113.9', 10);
		$this->assertRefused($this->post(self::PASSWORD, '203.0.113.9'));
	}

	public function testTheFileHoldsNoAddress(): void
	{
		$this->failTimes('203.0.113.9', 3);
		$this->failTimes('2606:4700:1:2::1', 3);

		$file = file_get_contents(self::ATTEMPTS_PATH);
		foreach (['203.0.113.9', '2606:4700:1:2::1', '2606:4700:1:2::/64', '203.0.113', '2606:4700'] as $address) {
			$this->assertStringNotContainsString($address, $file);
			$this->assertStringNotContainsString(hash('sha256', $address), $file, 'not an unsalted hash either');
			$this->assertStringNotContainsString(md5($address), $file);
		}
		$this->assertCount(2, $this->attempts());
		foreach (array_keys($this->attempts()) as $key) {
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$key);
		}
	}

	public function testTheKeyIsSaltedByTheInstall(): void
	{
		$before = $this->keyOf('203.0.113.9');
		$this->assertSame($before, $this->keyOf('203.0.113.9'), 'the same address keeps its key');

		wcmsSetPasswordOnDisk($this->wcms(), 'anotherPass1');

		$this->assertNotSame($before, $this->keyOf('203.0.113.9'), 'the salt comes from the install');
	}

	public function testALoginSetsNoBrowserIdentity(): void
	{
		$this->assertLoggedIn($this->post(self::PASSWORD, '203.0.113.9'));

		$config = json_decode(file_get_contents(self::DB_PATH), true)['config'];
		$this->assertArrayNotHasKey('knownBrowsers', $config);
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
			$stale[hash('sha256', (string)$i)] = [time() - 901, time() - 3600];
		}
		file_put_contents(self::ATTEMPTS_PATH, json_encode($stale));

		$this->failTimes('203.0.113.9', 10);
		$this->post('wrongPass', '203.0.113.9');

		$this->assertCount(1, $this->attempts());
		$this->assertCount(10, current($this->attempts()), 'a refused attempt is not stored');
	}

	public function testTheAttemptsFileIsCappedInAddresses(): void
	{
		$many = [];
		for ($i = 0; $i < 1005; $i++) {
			$many[hash('sha256', (string)$i)] = [time() - 60];
		}
		file_put_contents(self::ATTEMPTS_PATH, json_encode($many));

		$this->post('wrongPass', '203.0.113.9');

		$this->assertCount(1000, $this->attempts());
		$fresh = array_filter($this->attempts(), static function (array $times): bool {
			return max($times) > time() - 30;
		});
		$this->assertCount(1, $fresh, 'the newest address is kept');
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
	 * The same flow over HTTP against php -S: the status line, Retry-After, the login page body, no cookie but the
	 * session one, no address in the file. A router gives PHP a non-loopback REMOTE_ADDR (loopback is never throttled).
	 */
	public function testTheEleventhPostIsA429OverHttp(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('the local server writes its output to /dev/null');
		}
		$root = sys_get_temp_dir() . '/wcms-throttle-' . bin2hex(random_bytes(4));
		mkdir($root);
		copy(__DIR__ . '/../index.php', $root . '/index.php');
		file_put_contents($root . '/router.php', '<?php $_SERVER["REMOTE_ADDR"] = "203.0.113.9"; require __DIR__ . "/index.php";');
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
		$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, 'router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
		try {
			for ($i = 0; $i < 50 && @file_get_contents($base . '/') === false; $i++) {
				usleep(100000);
			}
			$this->assertFileExists($root . '/data/database.js', 'the first request installed the site');
			$this->assertFileDoesNotExist($root . '/data/logs/login-attempts.json', 'a visitor GET writes nothing');
			$site = new Wcms('data', 'files', 'database.js', $root);
			wcmsSetPasswordOnDisk($site, self::PASSWORD);

			$first = $this->request($base, self::PASSWORD);
			$this->assertStringContainsString(' 302 ', $first['status']);
			$this->assertNoCookieButTheSession($first['headers']);
			$this->assertStringNotContainsString('knownBrowsers', file_get_contents($root . '/data/database.js'));

			$before = file_get_contents($root . '/data/database.js');
			for ($i = 0; $i < 10; $i++) {
				$failed = $this->request($base, 'wrongPass');
				$this->assertStringContainsString(' 302 ', $failed['status']);
				$this->assertNoCookieButTheSession($failed['headers']);
			}
			$this->assertSame($before, file_get_contents($root . '/data/database.js'), 'failed logins never write database.js');

			$refused = $this->request($base, self::PASSWORD);
			$this->assertStringContainsString(' 429 ', $refused['status']);
			$this->assertMatchesRegularExpression('/^Retry-After: \d+\r?$/mi', $refused['headers']);
			$this->assertStringContainsString(self::TOO_MANY, $refused['body']);
			$this->assertStringContainsString('name="password"', $refused['body'], 'the normal login page');
			$this->assertNoCookieButTheSession($refused['headers']);

			$file = file_get_contents($root . '/data/logs/login-attempts.json');
			$this->assertStringNotContainsString('203.0.113.9', $file);
			$this->assertMatchesRegularExpression('/^\{"[0-9a-f]{64}":\[(\d+,){9}\d+\]\}$/', $file, 'one salted key and ten timestamps');
		} finally {
			proc_terminate($server);
			proc_close($server);
			$this->removeTree($root);
		}
	}

	private function assertNoCookieButTheSession(string $headers): void
	{
		preg_match_all('/^Set-Cookie: ([^=]+)=/mi', $headers, $names);
		$this->assertSame([], array_values(array_diff($names[1], ['PHPSESSID'])), 'no cookie but the session one');
	}

	private function request(string $base, string $password): array
	{
		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
			'content' => http_build_query(['password' => $password]),
			'follow_location' => 0,
			'ignore_errors' => true,
		]]);
		$body = (string)file_get_contents($base . '/?page=loginURL', false, $context);
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
