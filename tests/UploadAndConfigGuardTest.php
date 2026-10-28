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
		// Runs uploadFileAction() on a PHP without the fileinfo extension (3.6.0 refused every upload there, #356).
		// disable_classes does not remove the class (class_exists() stays true and new finfo warns), so this needs a
		// PHP built or configured without fileinfo: the "no-fileinfo" CI job. Everywhere else it skips.
		if (extension_loaded('fileinfo')) {
			$this->markTestSkipped('needs a PHP without the fileinfo extension (the no-fileinfo CI job)');
		}
		$file = tempnam(sys_get_temp_dir(), 'wcms');
		file_put_contents($file, 'hello');
		$alerts = [];
		foreach (['note.txt', 'shell.php'] as $name) {
			$cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=mime_content_type '
				. escapeshellarg(__DIR__ . '/probe.php') . ' upload ' . escapeshellarg($file) . ' ' . escapeshellarg($name);
			$out = (string)shell_exec($cmd . ' 2>&1');
			$result = json_decode($out, true);
			if (!is_array($result) || $result['fileinfo']) {
				unlink($file);
				$this->fail('probe must run without fileinfo and print JSON; output: ' . $out);
			}
			$alerts[$name] = implode(' ', array_column($result['alert']['danger'] ?? [], 'message'));
		}
		unlink($file);
		$this->assertStringNotContainsString('File format is not allowed', $alerts['note.txt'], 'an allowed extension passes on its own');
		$this->assertStringContainsString('File format is not allowed', $alerts['shell.php'], 'a disallowed extension is still refused');
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

	public function testDeleteRefusesAnEmptyOrDotDotName(): void
	{
		// In production redirect() dies; here it throws, so the test proves the guard stops the action.
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		@mkdir(__DIR__ . '/../data_test/files_test', 0777, true);
		file_put_contents(__DIR__ . '/../data_test/files_test/keep.txt', 'x');
		foreach (['', '..', '../'] as $name) {
			unset($_SESSION['alert']);
			$_REQUEST = ['deleteModule' => $name, 'type' => 'files', 'token' => $this->wcms->getToken()];
			try {
				$this->wcms->deleteFileModuleAction();
				$this->fail('no redirect for ' . var_export($name, true));
			} catch (RuntimeException $e) {
				$this->assertSame('Invalid file name.', $_SESSION['alert']['danger'][0]['message'] ?? '', var_export($name, true));
			}
		}
		$this->assertFileExists(__DIR__ . '/../data_test/files_test/keep.txt');
		$this->assertDirectoryExists(__DIR__ . '/../data_test/files_test');
	}
}
