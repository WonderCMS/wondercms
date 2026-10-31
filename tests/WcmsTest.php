<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../index.php';

final class WcmsTest extends TestCase
{
	private const DB_PATH = __DIR__ . '/../data_test/database_test.js';
	private const PASSWORD = 'testPass';

	/** @var Wcms */
	private $wcms;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';

		$this->wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['redirect'])
			->getMock();
	}

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
	}

	public function testGetDb(): void
	{
		if (file_exists(self::DB_PATH)) {
			unlink(self::DB_PATH);
		}

		$return = $this->wcms->getDb();

		$this->assertFileExists(self::DB_PATH);

		$this->assertTrue(property_exists($return, 'config'));
		$this->assertTrue(property_exists($return, 'pages'));
		$this->assertTrue(property_exists($return, 'blocks'));

		$this->assertSame(strlen($return->config->password), 60);
		$this->assertSame('loginURL', $return->config->login);

		$this->assertSame('Home', $return->pages->home->title);
		$this->assertTrue(property_exists($return->pages->home, 'keywords'));
		$this->assertTrue(property_exists($return->pages->home, 'description'));
		$this->assertTrue(property_exists($return->pages->home, 'content'));
	}

	public function testLoginAction(): void
	{
		$this->assertFalse($this->wcms->loggedIn);

		// Setup
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->wcms->currentPage = 'loginURL';

		// Test Wrong password
		$_POST['password'] = 'wrongPass';

		$this->wcms->loginAction();
		$this->assertFalse(isset($_SESSION['loggedIn']));
		$this->assertFalse(isset($_SESSION['rootDir']));
		$this->assertEquals($_SESSION['alert']['danger'][0]['message'], 'Wrong password.');
		$visitorToken = $this->wcms->getToken(); // the token the failed attempt's session carries (round-14 opus-7: a planted session must not know the admin's token)

		// Test right password
		wcmsSetPasswordOnDisk($this->wcms, self::PASSWORD);
		$this->assertTrue(password_verify(self::PASSWORD, $this->wcms->get('config', 'password')));

		$_POST['password'] = self::PASSWORD;
		$visitorSessionId = session_id();
		$this->assertNotSame('', $visitorSessionId, 'a session is active');

		$this->wcms->loginAction();
		$this->assertNotSame($visitorSessionId, session_id(), 'a new session id at login: a session id planted before the login is worthless after it');
		$this->wcms->loginStatus();

		$this->assertTrue($_SESSION['loggedIn']);
		$this->assertEquals($_SESSION['rootDir'], $this->wcms->rootDir);
		$this->assertTrue($this->wcms->loggedIn);
		$this->assertNotSame($visitorToken, $this->wcms->getToken(), 'the admin gets a token minted after login');
	}

	public function testALoginSuccessListenerSeesTheAdminsToken(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->wcms->currentPage = 'loginURL';
		wcmsSetPasswordOnDisk($this->wcms, self::PASSWORD);
		$visitorToken = $this->wcms->getToken();
		$seen = null;
		$wcms = $this->wcms;
		$this->wcms->addListener('login_success', static function ($args) use (&$seen, $wcms) {
			$seen = $wcms->getToken();
			return $args;
		});
		$_POST['password'] = self::PASSWORD;

		$this->wcms->loginAction();

		$this->assertNotSame($visitorToken, $seen, 'the listener does not get the token the visitor session carried');
		$this->assertSame($this->wcms->getToken(), $seen, 'it gets the token the admin keeps');
	}

	public function testLogoutAction(): void
	{
		$_REQUEST['token'] = $this->wcms->getToken();
		$this->wcms->currentPage = 'logout';

		$this->wcms->logoutAction();
		$this->wcms->loginStatus();

		$this->assertFalse(isset($_SESSION['loggedIn']));
		$this->assertFalse(isset($_SESSION['rootDir']));
		$this->assertFalse(isset($_SESSION['token']));
		$this->assertFalse($this->wcms->loggedIn);
	}

	public function testChangePasswordAction(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->wcms->currentPage = 'loginURL';

		wcmsSetPasswordOnDisk($this->wcms, self::PASSWORD);
		$_POST['password'] = self::PASSWORD;

		$this->wcms->loginAction();

		$_POST['token'] = $this->wcms->getToken();
		$_POST['old_password'] = self::PASSWORD;
		$_POST['new_password'] = 'testPassword';
		$_POST['repeat_password'] = 'testPassword';

		$this->wcms->loginStatus();
		$this->wcms->changePasswordAction();

		$this->assertStringContainsString('Password changed.', $_SESSION['alert']['success'][0]['message']);
		$this->assertTrue(password_verify('testPassword', $this->wcms->get('config', 'password')));
	}
}
