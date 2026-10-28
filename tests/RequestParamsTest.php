<?php

use PHPUnit\Framework\TestCase;

/**
 * Anonymous requests with array parameters (?page[]=x, token[]=x, password[]=x) used to hit
 * a TypeError and answer HTTP 500. They must be treated as missing or invalid instead.
 */
final class RequestParamsTest extends TestCase
{
	/** @var Wcms */
	private $wcms;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
	}

	protected function tearDown(): void
	{
		$_GET = $_POST = [];
	}

	public function testArrayPageFallsBackToDefaultPage(): void
	{
		$_GET['page'] = ['x'];
		$this->assertSame('home', $this->wcms->parseUrl());
	}

	public function testArrayTokenIsNotValid(): void
	{
		$this->assertFalse($this->wcms->hashVerify(['x']));
		$this->assertFalse($this->wcms->hashVerify(null));
		$this->assertTrue($this->wcms->hashVerify($this->wcms->getToken()));
	}

	public function testArrayPasswordDoesNotCrashLogin(): void
	{
		$this->wcms->currentPage = 'loginURL';
		$_POST['password'] = ['x'];
		$this->wcms->loginAction();
		$this->assertFalse(isset($_SESSION['loggedIn']));
	}

	public function testArraySearchQueryIsIgnored(): void
	{
		$_POST['query'] = ['x'];
		$_POST['token'] = $this->wcms->getToken();
		$method = new ReflectionMethod(Wcms::class, 'searchAction');
		$method->invoke($this->wcms);
		$this->assertTrue(true); // no TypeError
	}
}
