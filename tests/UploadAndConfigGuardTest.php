<?php

use PHPUnit\Framework\TestCase;

/**
 * #356 (upload without fileinfo), #344 protected config keys, logout ?to[] and a hand-edited subpages array.
 */
final class UploadAndConfigGuardTest extends TestCase
{
	/** @var Wcms */
	private $wcms;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$this->wcms->loggedIn = true;
		$_SESSION['loggedIn'] = true;
	}

	protected function tearDown(): void
	{
		$_POST = $_GET = $_REQUEST = $_FILES = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
	}

	public function testUploadIsAcceptedByExtensionWhenMimeDetectionIsUnavailable(): void
	{
		// The upload gate's final check: an allowed extension with no detected MIME type passes, a disallowed extension does not.
		$method = new ReflectionMethod(Wcms::class, 'uploadFileAction');
		$source = file_get_contents(__DIR__ . '/../index.php');
		$this->assertStringContainsString("(\$mimeType !== null && !in_array(\$mimeType, \$allowedMimeTypes, true)) || !in_array(\$ext, \$allowedExtensions, true)", $source, 'without fileinfo only the extension is checked');
		$this->assertStringNotContainsString("elseif (array_key_exists(\$ext, \$allowedExtensions))", $source, 'the dead 3.6.0 fallback is gone');
		$this->assertTrue($method->isPublic());
	}

	public function testGenericSaveCannotTouchProtectedConfigKeys(): void
	{
		$before = $this->wcms->get('config', 'password');
		$_POST = ['fieldname' => 'password', 'content' => 'owned', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame($before, $this->wcms->get('config', 'password'));

		$_POST = ['fieldname' => 'menuItems', 'content' => '[]', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertNotSame('[]', $this->wcms->get('config', 'menuItems'));

		$_POST = ['fieldname' => 'siteTitle', 'content' => 'Allowed', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame('Allowed', $this->wcms->get('config', 'siteTitle'));
	}

	public function testLogoutIgnoresAnArrayRedirectTarget(): void
	{
		$this->wcms->currentPage = 'logout';
		$_REQUEST['token'] = $this->wcms->getToken();
		$_GET['to'] = ['https://evil.example'];
		$this->wcms->expects($this->once())->method('redirect')->with($this->wcms->get('config', 'login'));
		$this->wcms->logoutAction();
	}

	public function testSearchToleratesAHandEditedSubpagesArray(): void
	{
		$this->wcms->createPage(['games'], true);
		$db = json_decode(file_get_contents(__DIR__ . '/../data_test/database_test.js'));
		$db->pages->games->subpages = [];
		$db->pages->games->content = 'board games';
		file_put_contents(__DIR__ . '/../data_test/database_test.js', json_encode($db));
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$method = new ReflectionMethod(Wcms::class, 'searchPages');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		$this->assertSame(['games'], array_column($method->invoke($wcms, $wcms->get('pages'), 'board'), 'slug'));
	}

	public function testUpdateGuardWithoutTokenizerStillRequiresACompleteFile(): void
	{
		// Mirrors the function_exists('token_get_all') fallback: a complete class body ends with '}'.
		$source = file_get_contents(__DIR__ . '/../index.php');
		$this->assertStringContainsString("if (!function_exists('token_get_all'))", $source);
		$this->assertSame('}', substr(rtrim($source), -1));
		$this->assertNotSame('}', substr(rtrim(substr($source, 0, 50000)), -1));
	}
}
