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
		$_GET = $_POST = $_REQUEST = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
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

	public function testAnEmptySearchQueryListsNothing(): void
	{
		// A hand-made POST with an empty query must not list every page; a one-character query (a CJK word) still searches.
		foreach (['', '  '] as $query) {
			$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' search ' . escapeshellarg($query));
			$this->assertSame(['error' => 'No matching content found'], json_decode($out, true), var_export($query, true));
		}
		foreach (['Home', 'H'] as $query) {
			$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' search ' . escapeshellarg($query));
			$this->assertNotEmpty(json_decode($out, true)[0]['slug'] ?? null, var_export($query, true) . ' still finds the home page');
		}
		// The highlight is case-insensitive for non-Latin text too (the /u flag on the regex).
		$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' search ' . escapeshellarg('шахматы') . ' ' . escapeshellarg('<p>Шахматы для всех</p>'));
		$this->assertStringContainsString('<mark>Шахматы</mark>', json_decode($out, true)[0]['excerpt'] ?? '', 'a lowercase Cyrillic query highlights the capitalised word');
		// A query that is not valid UTF-8 (raw bytes, passed as hex past escapeshellarg) answers like the empty one. On PHP 8.x mb_stripos()
		// already finds nothing for it; on 7.4 to 8.0 mbstring folds the byte, the page matches and the /u highlight regex would return null
		// with a warning, so the guard is pinned by the 7.4 CI leg (the warning would break this JSON).
		$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg(__DIR__ . '/probe.php') . ' search ' . escapeshellarg('hex:' . bin2hex("a\xff")) . ' ' . escapeshellarg('<p>a? page</p>') . ' 2>&1');
		$this->assertSame(['error' => 'No matching content found'], json_decode($out, true), 'invalid UTF-8 query: ' . $out);
	}

	public function testArraySearchQueryIsIgnored(): void
	{
		$_POST['query'] = ['x'];
		$_POST['token'] = $this->wcms->getToken();
		$method = new ReflectionMethod(Wcms::class, 'searchAction');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		$method->invoke($this->wcms);
		$this->assertTrue(true); // no TypeError
	}
}
