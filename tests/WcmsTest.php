<?php
ob_start();
define('PHPUNIT_TESTING', true);

use PHPUnit\Framework\TestCase;

require '../index.php';

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
			->setMethods(['redirect'])
			->getMock();

		$this->wcms->setPaths('data_test', 'files_test', 'database_test.js');
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

	/**
	 * @depends testGetDb
	 */
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

		// Test right password
		$hashPass = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
		$this->wcms->set('config', 'password', $hashPass);
		$password = $this->wcms->get('config', 'password');

		$this->assertSame($hashPass, $password);

		$_POST['password'] = self::PASSWORD;

		$this->wcms->loginAction();
		$this->wcms->loginStatus();

		$this->assertTrue($_SESSION['loggedIn']);
		$this->assertEquals($_SESSION['rootDir'], $this->wcms->rootDir);
		$this->assertTrue($this->wcms->loggedIn);
	}

	/**
	 * @depends testLoginAction
	 */
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

		$hashPass = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
		$this->wcms->set('config', 'password', $hashPass);
		$_POST['password'] = self::PASSWORD;

		$this->wcms->loginAction();

		$_POST['token'] = $this->wcms->getToken();
		$_POST['old_password'] = self::PASSWORD;
		$_POST['new_password'] = 'test';

		$this->wcms->loginStatus();
		$this->wcms->changePasswordAction();

		$this->assertEquals($_SESSION['alert']['success'][0]['message'], 'Password changed.');
	}
}
