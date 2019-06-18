<?php
ob_start();
define('PHPUNIT_TESTING', true);

use PHPUnit\Framework\TestCase;

require '../index.php';

final class WcmsTest extends TestCase
{
    private const DB_PATH = __DIR__ . '/../data_test/database_test.js';

    /** @var Wcms */
    private $wcms;

    protected function setUp(): void
    {
        $_SERVER['SERVER_NAME'] = 'wondercms.doc';
        $_SERVER['SERVER_PORT'] = '80';

        $this->wcms = new Wcms();
        $this->wcms->setPaths('data_test', 'files_test', 'database_test.js');
    }

    public function testGetDb(): void
    {
        if (file_exists(self::DB_PATH)) {
            unlink(self::DB_PATH);
        }

        $return = $this->wcms->getDb();

        $this->assertTrue(file_exists(self::DB_PATH));

        $this->assertTrue(property_exists($return, 'config'));
        $this->assertTrue(property_exists($return, 'pages'));
        $this->assertTrue(property_exists($return, 'blocks'));

        $this->assertTrue(strlen($return->config->password) === 60);
        $this->assertSame('loginURL', $return->config->login);

        $this->assertSame('Home', $return->pages->home->title);
        $this->assertTrue(property_exists($return->pages->home, 'keywords'));
        $this->assertTrue(property_exists($return->pages->home, 'description'));
        $this->assertTrue(property_exists($return->pages->home, 'content'));
    }

    public function testLoginAction(): void
    {
        $rawPassword = 'testPass';
        $hashPass = password_hash($rawPassword, PASSWORD_DEFAULT);
        $this->wcms->set('config', 'password', $hashPass);
        $password = $this->wcms->get('config', 'password');

        $this->assertSame($hashPass, $password);

//        $_POST['password'] = $rawPassword;
//
//        $_SERVER['REQUEST_METHOD'] = 'POST';
//        $_POST['password'] = $rawPassword;
//        $this->wcms->currentPage = 'loginURL';
//        $this->wcms->loginAction();
    }
}
