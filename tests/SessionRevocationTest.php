<?php

use PHPUnit\Framework\TestCase;

/**
 * A session is bound to the password hash it logged in with: a password change, or a hash edited by hand in database.js,
 * ends every session from before it, and a later login brings none of them back.
 * Each session is a saved copy of $_SESSION, restored before the request it makes; each request is a new Wcms object,
 * reading the database from disk the way the next page load does.
 */
final class SessionRevocationTest extends TestCase
{
	private const DB_PATH = __DIR__ . '/../data_test/database_test.js';

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SESSION = [];
		@unlink(self::DB_PATH);
		wcmsSetPasswordOnDisk($this->newWcms(), 'old-secret');
	}

	protected function tearDown(): void
	{
		$_POST = $_GET = $_REQUEST = [];
		$_SESSION = [];
		@unlink(self::DB_PATH);
	}

	private function newWcms(): Wcms
	{
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->currentPage = 'loginURL';
		return $wcms;
	}

	/**
	 * A login from a new browser; the admin page it lands on mints the CSRF token
	 * @param string $password
	 * @return array the session afterwards
	 */
	private function login(string $password): array
	{
		$_SESSION = [];
		$_POST = ['password' => $password];
		$wcms = $this->newWcms();
		$wcms->loginAction();
		$_POST = [];
		if (isset($_SESSION['loggedIn'])) {
			$wcms->getToken();
		}
		return $_SESSION;
	}

	/**
	 * One page load with this session; the session is updated with what the request left in it
	 * @param array $session
	 * @return bool
	 */
	private function isAdmin(array &$session): bool
	{
		$_SESSION = $session;
		$wcms = $this->newWcms();
		$wcms->currentPage = 'home';
		$wcms->loginStatus();
		$session = $_SESSION;
		return $wcms->loggedIn;
	}

	private function changePassword(array $session, string $old, string $new): array
	{
		$_SESSION = $session;
		$wcms = $this->newWcms();
		$wcms->currentPage = 'home';
		$wcms->loginStatus();
		$_POST = ['old_password' => $old, 'new_password' => $new, 'repeat_password' => $new, 'token' => $wcms->getToken()];
		$wcms->changePasswordAction();
		$_POST = [];
		return $_SESSION;
	}

	private function diskHash(): string
	{
		return json_decode(file_get_contents(self::DB_PATH))->config->password;
	}

	public function testALaterLoginDoesNotRestoreSessionsEndedByAPasswordChange(): void
	{
		$a = $this->login('old-secret');
		$b = $this->login('old-secret');
		$bToken = $b['token'];
		$this->assertTrue($this->isAdmin($a));
		$this->assertTrue($this->isAdmin($b));

		$this->changePassword($a, 'old-secret', 'new-secret-123');
		$this->assertTrue(password_verify('new-secret-123', $this->diskHash()), 'the change is on disk');
		$this->assertFalse($this->isAdmin($b), 'B is out once the password changes');
		$this->assertArrayNotHasKey('loggedIn', $b, 'and its session is emptied, so a plugin reading $_SESSION directly sees a visitor');
		$this->assertArrayNotHasKey('authStamp', $b);
		$this->assertArrayNotHasKey('token', $b);

		$c = $this->login('new-secret-123');
		$this->assertTrue($this->isAdmin($c), 'the new password logs in');
		$this->assertFalse($this->isAdmin($b), 'C logging in does not bring B back (3.7.1 did: forceLogout was one switch for every session)');
		$this->assertFalse($this->isAdmin($a), 'nor A');

		// B cannot write either, with the CSRF token it held before the change.
		$_SESSION = $b;
		$wcms = $this->newWcms();
		$wcms->currentPage = 'home';
		$wcms->loginStatus();
		$_POST = ['fieldname' => 'siteTitle', 'content' => 'old session wrote this', 'target' => 'config', 'token' => $bToken];
		$wcms->saveAction();
		$_POST = [];
		$this->assertNotSame('old session wrote this', json_decode(file_get_contents(self::DB_PATH))->config->siteTitle);

		$d = $this->login('new-secret-123');
		$this->assertTrue($this->isAdmin($c), 'another login with the current password does not end C');
		$this->assertTrue($this->isAdmin($d));
		$this->assertFalse($this->isAdmin($b));
	}

	public function testThePasswordChangeLogsTheChangingSessionOutToo(): void
	{
		$a = $this->login('old-secret');
		$aBefore = $a; // the same session as a parallel request had loaded it before the change
		$a = $this->changePassword($a, 'old-secret', 'new-secret-123');
		$this->assertArrayNotHasKey('loggedIn', $a);
		$this->assertStringContainsString('Password changed. Log in again.', $_SESSION['alert']['success'][0]['message'] ?? '');
		$this->assertFalse($this->isAdmin($aBefore), 'the stamp of the old hash no longer matches');

		$this->login('new-secret-123');
		$this->assertFalse($this->isAdmin($aBefore), 'and a later login does not make it match again');
		$a = $this->login('new-secret-123');
		$this->assertTrue($this->isAdmin($a), 'logging in again with the new password works');
	}

	public function testTheLoginPageShowsPasswordChangedOnceAfterTheChange(): void
	{
		// The real redirect() ends the request: an alert queued after logoutAction(true) was never queued (3.7.1, 372 at 9ff7fad).
		$_SESSION = $this->login('old-secret');
		$wcms = $this->newWcms();
		$wcms->currentPage = 'home';
		$wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$wcms->loginStatus();
		$_POST = ['old_password' => 'old-secret', 'new_password' => 'new-secret-123', 'repeat_password' => 'new-secret-123', 'token' => $wcms->getToken()];
		try {
			$wcms->changePasswordAction();
			$this->fail('the change redirects');
		} catch (RuntimeException $e) {
			$this->assertSame('redirected', $e->getMessage());
		}
		$_POST = [];
		$a = $_SESSION;
		$this->assertArrayNotHasKey('loggedIn', $a);
		$this->assertArrayNotHasKey('token', $a);

		// The redirect lands the same session, a visitor now, on the login page.
		$wcms = $this->newWcms();
		$wcms->loginStatus();
		$this->assertFalse($wcms->loggedIn);
		$page = $wcms->alerts();
		$this->assertSame(1, substr_count($page, 'Password changed. Log in again.'), 'shown once');
		$this->assertStringContainsString('<div class="alert alert-success"><center><b>Password changed. Log in again.</b></center></div>', $page, 'sticky as before, and closed without the admin hide link');
		$this->assertStringNotContainsString('Password changed. Log in again.', $wcms->alerts(), 'a second render of the same request does not repeat it');

		$a = $_SESSION;
		$wcms = $this->newWcms();
		$wcms->loginStatus();
		$this->assertStringNotContainsString('Password changed. Log in again.', $wcms->alerts(), 'the reload shows it no more');
		$this->assertArrayNotHasKey('alert', $_SESSION);
		$this->assertFalse($this->isAdmin($a));
	}

	public function testAFailedSaveNeitherClaimsTheChangeNorLogsOut(): void
	{
		// A full disk or a read-only data folder: save() queues "Could not write" and the old hash stays on disk.
		if (DIRECTORY_SEPARATOR !== '/' || (function_exists('posix_getuid') && posix_getuid() === 0)) {
			$this->markTestSkipped('needs a POSIX permission check (not Windows, not root)');
		}
		$_SESSION = $this->login('old-secret');
		$before = $this->diskHash();
		$wcms = $this->newWcms();
		$wcms->currentPage = 'home';
		$wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$wcms->loginStatus();
		$_POST = ['old_password' => 'old-secret', 'new_password' => 'new-secret-123', 'repeat_password' => 'new-secret-123', 'token' => $wcms->getToken()];
		chmod(self::DB_PATH, 0444);
		chmod(dirname(self::DB_PATH), 0555);
		try {
			$wcms->changePasswordAction();
			$this->fail('the change redirects');
		} catch (RuntimeException $e) {
			$this->assertSame('redirected', $e->getMessage());
		} finally {
			chmod(dirname(self::DB_PATH), 0755);
			chmod(self::DB_PATH, 0644);
		}
		$_POST = [];
		$alerts = json_encode($_SESSION['alert'] ?? []);
		$this->assertStringContainsString('Could not write database_test.js', $alerts);
		$this->assertStringNotContainsString('Password changed', $alerts);
		$this->assertArrayHasKey('loggedIn', $_SESSION, 'the admin stays logged in');
		$this->assertSame($before, $this->diskHash(), 'the hash on disk is unchanged');
		$this->assertTrue(password_verify('old-secret', $this->diskHash()), 'the old password still works');
		unset($_SESSION['alert']);
		$a = $_SESSION;
		$this->assertTrue($this->isAdmin($a), 'the next page load is still an admin');
	}

	public function testAHandEditedHashLogsEverySessionOut(): void
	{
		// The documented recovery after a compromise: replace the hash in database.js by hand.
		$a = $this->login('old-secret');
		$b = $this->login('old-secret');
		$db = json_decode(file_get_contents(self::DB_PATH));
		$db->config->password = password_hash('recovered-pw', PASSWORD_DEFAULT);
		file_put_contents(self::DB_PATH, json_encode($db, JSON_PRETTY_PRINT));

		$this->assertFalse($this->isAdmin($a));
		$this->assertFalse($this->isAdmin($b));
		$c = $this->login('recovered-pw');
		$this->assertTrue($this->isAdmin($c));
		$this->assertFalse($this->isAdmin($a), 'the login with the recovered password does not revive them');
	}

	public function testASessionFromBeforeTheUpdateIsLoggedOutOnceAndKeepsItsAlert(): void
	{
		// 3.6.0 to 3.7.1 sessions carry loggedIn, rootDir and token, no stamp; their databases forceLogout true or false.
		foreach ([false, true] as $forceLogout) {
			$db = json_decode(file_get_contents(self::DB_PATH));
			$db->config->forceLogout = $forceLogout;
			file_put_contents(self::DB_PATH, json_encode($db, JSON_PRETTY_PRINT));
			$before = file_get_contents(self::DB_PATH);
			$legacy = ['loggedIn' => true, 'rootDir' => $this->newWcms()->rootDir, 'token' => str_repeat('ab', 32), 'alert' => ['success' => [['class' => 'success', 'message' => 'WonderCMS successfully updated. Wohoo!', 'sticky' => false]]]];

			$this->assertFalse($this->isAdmin($legacy), 'forceLogout ' . var_export($forceLogout, true) . ': a session without a stamp is a visitor');
			$this->assertSame(['alert'], array_keys($legacy), 'its admin keys are gone; the update alert stays for the page it lands on');
			$this->assertSame($before, file_get_contents(self::DB_PATH), 'nothing was written to the database');

			$fresh = $this->login('old-secret');
			$this->assertTrue($this->isAdmin($fresh), 'one login and the admin is back');
		}
	}

	public function testAMalformedStampIsAVisitorAndNotAnError(): void
	{
		$rootDir = $this->newWcms()->rootDir;
		foreach ([['x'], 123, null, '', true] as $stamp) {
			$session = ['loggedIn' => true, 'rootDir' => $rootDir, 'authStamp' => $stamp];
			$this->assertFalse($this->isAdmin($session), var_export($stamp, true));
		}
	}

	public function testAnotherInstallsSessionIsNotEmptied(): void
	{
		// Two installs on one host share one PHP session; a session that belongs to the other one is not this install's to clear.
		$session = ['loggedIn' => true, 'rootDir' => '/somewhere/else', 'authStamp' => str_repeat('0', 64), 'token' => 'other'];
		$this->assertFalse($this->isAdmin($session));
		$this->assertSame(['loggedIn', 'rootDir', 'authStamp', 'token'], array_keys($session));
	}

	public function testTheSessionHoldsAStampNotTheHash(): void
	{
		$a = $this->login('old-secret');
		$this->assertIsString($a['authStamp'] ?? null);
		$this->assertSame(64, strlen($a['authStamp']));
		$this->assertStringNotContainsString($this->diskHash(), serialize($a), 'the session file never holds the password hash');
	}

	public function testAVisitorRequestNeverWritesTheDatabase(): void
	{
		// get() creates and saves a missing key; a 3.7.2 read of a key a database lacks would write on a visitor's page view.
		$shapes = [
			'fresh' => static function ($db) {
				return $db;
			},
			'without forceLogout (older database)' => static function ($db) {
				unset($db->config->forceLogout);
				return $db;
			},
			'without password (hand-edited)' => static function ($db) {
				unset($db->config->password);
				return $db;
			},
		];
		@unlink(self::DB_PATH);
		new Wcms('data_test', 'files_test', 'database_test.js'); // the install itself writes the file
		$fresh = json_decode(file_get_contents(self::DB_PATH));
		foreach ($shapes as $name => $shape) {
			file_put_contents(self::DB_PATH, json_encode($shape(json_decode(json_encode($fresh))), JSON_PRETTY_PRINT)); // a deep copy per shape
			touch(self::DB_PATH, time() - 3600);
			clearstatcache();
			$before = [hash_file('sha256', self::DB_PATH), filemtime(self::DB_PATH)];

			$_SESSION = [];
			$_GET = ['page' => 'home'];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$visitor = new Wcms('data_test', 'files_test', 'database_test.js');
			$visitor->loginStatus();
			$visitor->getSiteLanguage();
			$visitor->pageStatus();
			$this->assertFalse($visitor->loggedIn, $name);

			clearstatcache();
			$this->assertSame($before, [hash_file('sha256', self::DB_PATH), filemtime(self::DB_PATH)], $name . ': database.js untouched');
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	public function testAStaleSaveDoesNotRevertAPasswordChange(): void
	{
		// A request that read database.js before the change saves its whole snapshot after it.
		$stale = $this->newWcms();
		$this->changePassword($this->login('old-secret'), 'old-secret', 'new-secret-123');
		$stale->set('config', 'siteTitle', 'saved after the change');

		$this->assertTrue(password_verify('new-secret-123', $this->diskHash()), 'the new password stays on disk');
		$this->assertTrue(password_verify('new-secret-123', $stale->get('config', 'password')), 'and the stale request now holds it too');
		$this->assertSame('saved after the change', json_decode(file_get_contents(self::DB_PATH))->config->siteTitle, 'its own change is saved');
	}

	public function testALoginThatVerifiedTheOldHashCannotRevertTheChangeNorStayIn(): void
	{
		// An attacker who knows the old password logs in; the owner's change lands between the read and the login's save.
		$_SESSION = [];
		$late = $this->newWcms(); // read the old hash
		$this->changePassword($this->login('old-secret'), 'old-secret', 'new-secret-123');

		$_SESSION = [];
		$_POST = ['password' => 'old-secret'];
		$late->loginAction(); // verifies against the hash it read, then saves lastLogins
		$_POST = [];
		$attacker = $_SESSION;

		$this->assertTrue(password_verify('new-secret-123', $this->diskHash()), 'the login did not write the old hash back');
		$this->assertFalse(password_verify('old-secret', $this->diskHash()));
		$this->assertFalse($this->isAdmin($attacker), 'its session carries the stamp of the old hash, which is no longer on disk');
		$owner = $this->login('new-secret-123');
		$this->assertTrue($this->isAdmin($owner), 'the owner logs in with the new password');
	}

	public function testChangePasswordStillReplacesTheHash(): void
	{
		$before = $this->diskHash();
		$this->changePassword($this->login('old-secret'), 'old-secret', 'new-secret-123');
		$this->assertNotSame($before, $this->diskHash());
		$this->assertTrue(password_verify('new-secret-123', $this->diskHash()));

		$wcms = $this->newWcms();
		$wcms->set('config', 'password', password_hash('not-through-the-form', PASSWORD_DEFAULT));
		$this->assertTrue(password_verify('new-secret-123', $this->diskHash()), 'any other save keeps the hash on disk');
	}
}
