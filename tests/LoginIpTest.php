<?php

use PHPUnit\Framework\TestCase;

/**
 * The login IP in "Last 5 logins" and in the login hooks is the connection address, validated; request headers are not trusted.
 */
final class LoginIpTest extends TestCase
{
	private const PASSWORD = 'testPass';

	/** @var Wcms */
	private $wcms;

	/** @var array */
	private $server;

	protected function setUp(): void
	{
		$this->server = $_SERVER;
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$this->wcms->currentPage = 'loginURL';
		$this->wcms->set('config', 'password', password_hash(self::PASSWORD, PASSWORD_DEFAULT));
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->server;
		$_POST = $_GET = $_REQUEST = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
		$this->wcms->loggedIn = false;
	}

	private function logIn(): void
	{
		$_POST['password'] = self::PASSWORD;
		$this->wcms->loginAction();
		$this->assertTrue($_SESSION['loggedIn'] ?? false, 'the login succeeded');
	}

	private function storedIps(): array
	{
		return array_values((array)$this->wcms->get('config', 'lastLogins'));
	}

	public function testForgedForwardingHeadersDoNotReplaceTheConnectionAddress(): void
	{
		$_SERVER['HTTP_CLIENT_IP'] = '<img src=x onerror=alert(1)>';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$this->logIn();
		$this->assertSame(['203.0.113.5'], $this->storedIps());

		$_SERVER['HTTP_CLIENT_IP'] = '192.0.2.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$this->assertSame('203.0.113.5', $this->recordedBy(function () {
			$this->wcms->loggedIn = false;
			unset($_SESSION['loggedIn']);
			$this->logIn();
		}), 'well-formed Client-IP and X-Forwarded-For addresses are not trusted either');

		unset($_SERVER['HTTP_CLIENT_IP']);
		$this->assertSame('203.0.113.5', $this->recordedBy(function () {
			$this->wcms->loggedIn = false;
			unset($_SESSION['loggedIn']);
			$this->logIn();
		}), 'nor a well-formed X-Forwarded-For on its own');
	}

	public function testAnAddressThatIsNotAnIpIsRecordedAsUnknown(): void
	{
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip <b>';
		$this->logIn();
		$this->assertSame(['unknown'], $this->storedIps());

		unset($_SERVER['REMOTE_ADDR']);
		$this->assertSame('unknown', $this->recordedBy(function () {
			$this->wcms->loggedIn = false;
			unset($_SESSION['loggedIn']);
			$this->logIn();
		}), 'no connection address at all');
	}

	public function testTheLoginHooksGetTheSameValidatedAddress(): void
	{
		$seen = [];
		foreach (['login_success', 'login_failed'] as $hook) {
			$this->wcms->addListener($hook, static function ($args) use (&$seen, $hook) {
				$seen[$hook] = $args[0]['ip'];
				return $args;
			});
		}
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$_POST['password'] = 'wrongPass';
		$this->wcms->loginAction();
		$this->logIn();
		$this->assertSame(['login_failed' => 'unknown', 'login_success' => 'unknown'], $seen);

		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
		$this->wcms->loggedIn = false;
		unset($_SESSION['loggedIn']);
		$this->logIn();
		$this->assertSame('2001:db8::1', $seen['login_success'], 'a valid IPv6 connection address reaches the hook unchanged');
		$this->assertContains('2001:db8::1', $this->storedIps(), 'and is the one stored');
	}

	public function testAStoredValueThatIsNotAnIpIsPrintedAsText(): void
	{
		// An entry written before 3.7.2 from a request header can still be in the database.
		$this->wcms->loggedIn = true;
		$_SESSION['loggedIn'] = true;
		$this->wcms->set('config', 'lastLogins', ['2026/10/28 10:00:00' => '"><script>alert(1)</script>&']);
		$html = $this->wcms->settings();
		$this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;&amp;', $html);
		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
	}

	/**
	 * The address the next login adds to "Last 5 logins" (entries are keyed by the second, so a second login in the same second replaces the first).
	 */
	private function recordedBy(callable $login): string
	{
		$this->wcms->set('config', 'lastLogins', []);
		$login();
		$stored = $this->storedIps();
		$this->assertCount(1, $stored);
		return $stored[0];
	}
}
