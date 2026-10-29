<?php

use PHPUnit\Framework\TestCase;

/**
 * A custom module repository can be removed from the list again (Settings, Custom module); what was installed from it stays.
 */
final class CustomModulesTest extends TestCase
{
	private const DB = __DIR__ . '/../data_test/database_test.js';
	private const CACHE = __DIR__ . '/../data_test/cache.json';

	/** @var Wcms */
	private $wcms;

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
		@unlink(self::DB);
		@unlink(self::CACHE);
	}

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		@unlink(self::DB);
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'getFileFromRepo'])->getMock();
		$this->wcms->method('getFileFromRepo')->willReturn('{"version":1,"themes":{},"plugins":{}}'); // the official list, fetched when the cache is rebuilt; no network
		$this->wcms->loggedIn = true;
		$this->wcms->set('config', 'customModules', 'plugins', ['https://example.test/one/wcms-modules.json', 'https://example.test/two/wcms-modules.json']);
		$this->wcms->set('config', 'customModules', 'themes', ['https://example.test/theme/wcms-modules.json']);
	}

	private function remove(string $index, string $type): void
	{
		$_REQUEST = $_GET = ['removeCustomModule' => $index, 'type' => $type, 'token' => $this->wcms->getToken()];
		$this->wcms->removeCustomModuleAction();
		$_REQUEST = $_GET = [];
	}

	public function testAnOldDatabaseWithoutCustomModulesOpensSettingsAfterTheCacheRebuild(): void
	{
		$db = json_decode(file_get_contents(self::DB));
		unset($db->config->customModules, $db->config->lastModulesSync); // a database written by 3.0.4 or 3.2.0
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT));
		@unlink(self::CACHE);
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'getFileFromRepo'])->getMock();
		$wcms->method('getFileFromRepo')->willReturn('{"version":1,"themes":{},"plugins":{}}');
		$wcms->loggedIn = true;

		$settings = $wcms->settings(); // the cache rebuild writes the customModules defaults, then the list reads them in the same request

		$this->assertStringContainsString('Custom module', $settings);
		$this->assertSame(['themes' => [], 'plugins' => []], json_decode(file_get_contents(self::DB), true)['config']['customModules']);
		$_REQUEST = $_GET = ['removeCustomModule' => 'https://example.test/none/wcms-modules.json', 'type' => 'plugins', 'token' => $wcms->getToken()];
		$wcms->removeCustomModuleAction();
		$this->assertSame(['themes' => [], 'plugins' => []], json_decode(file_get_contents(self::DB), true)['config']['customModules'], 'an unknown repository changes nothing, in the same request too');
	}

	public function testAnOldDatabaseWithAFreshCacheAndNoCustomModulesOpensSettings(): void
	{
		$this->wcms->settings(); // builds cache.json and stamps lastModulesSync
		$this->assertFileExists(self::CACHE);
		$db = json_decode(file_get_contents(self::DB));
		unset($db->config->customModules); // created on 3.2.0 or older, then run on 3.3.0 to 3.4.0: a fresh stamp and cache, no customModules
		$db->config->lastModulesSync = date('Y/m/d');
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT));
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'getFileFromRepo'])->getMock();
		$wcms->method('getFileFromRepo')->willReturn('{"version":1,"themes":{},"plugins":{}}');
		$wcms->loggedIn = true;

		$this->assertStringContainsString('Custom module', $wcms->settings(), 'no rebuild runs, and the list still reads');
		unset($_SESSION['alert']);
		$_REQUEST = $_GET = ['removeCustomModule' => 'https://example.test/one/wcms-modules.json', 'type' => 'plugins', 'token' => $wcms->getToken()];
		$wcms->removeCustomModuleAction();
		$this->assertSame('Custom module not found.', $_SESSION['alert']['danger'][0]['message'] ?? '');
	}

	public function testAnOldDatabaseWithAFreshCacheAndNoCustomModulesCanAddOne(): void
	{
		$this->wcms->settings();
		$db = json_decode(file_get_contents(self::DB));
		unset($db->config->customModules); // the shape of the test above
		$db->config->lastModulesSync = date('Y/m/d');
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT));
		$url = $this->addPluginFromALocalList();

		$this->assertStringStartsWith('Module successfully added', $_SESSION['alert']['success'][0]['message'] ?? '');
		$this->assertSame(['themes' => [], 'plugins' => [$url]], json_decode(file_get_contents(self::DB), true)['config']['customModules']);
		$this->assertSame('Probe', json_decode(file_get_contents(self::CACHE), true)['plugins']['probe']['name'] ?? null);
	}

	public function testAddingARepositoryKeepsTheOnesAlreadyListed(): void
	{
		$url = $this->addPluginFromALocalList();

		$this->assertStringStartsWith('Module successfully added', $_SESSION['alert']['success'][0]['message'] ?? '');
		$this->assertSame([
			'themes' => ['https://example.test/theme/wcms-modules.json'],
			'plugins' => ['https://example.test/one/wcms-modules.json', 'https://example.test/two/wcms-modules.json', $url],
		], json_decode(file_get_contents(self::DB), true)['config']['customModules']);
	}

	public function testAWrongModuleTypeIsRefused(): void
	{
		$this->wcms->set('config', 'password', password_hash('secret', PASSWORD_DEFAULT));
		$before = file_get_contents(self::DB);
		unset($_SESSION['alert']);
		$_POST = ['pluginThemeUrl' => 'https://example.test/files/wcms-modules.json', 'pluginThemeType' => 'files', 'password_recheck' => 'secret', 'token' => $this->wcms->getToken()];
		$this->wcms->addCustomModule();

		$this->assertSame(['Invalid module type.'], array_column($_SESSION['alert']['danger'] ?? [], 'message'), 'refused before the list is fetched, and nothing after it runs');
		$this->assertSame($before, file_get_contents(self::DB));
	}

	/**
	 * Adds a plugin repository through a wcms-modules.json served by a local server: the list is fetched for real
	 * @return string the repository URL
	 */
	private function addPluginFromALocalList(): string
	{
		$dir = sys_get_temp_dir() . '/wcms-custom-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/wcms-modules.json', json_encode(['plugins' => ['probe' => ['name' => 'Probe', 'repo' => 'https://example.test/probe', 'zip' => 'https://example.test/probe.zip', 'summary' => 's', 'version' => '1.0.0', 'image' => '']]]));
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
		$server = proc_open([PHP_BINARY, '-S', $address, '-t', $dir], [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
		$url = 'http://' . $address . '/wcms-modules.json';
		for ($i = 0; $i < 50 && @file_get_contents($url) === false; $i++) {
			usleep(100000);
		}
		try {
			$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'getFileFromRepo'])->getMock();
			$wcms->method('getFileFromRepo')->willReturn('{"version":1,"themes":{},"plugins":{}}');
			$wcms->loggedIn = true;
			$wcms->set('config', 'password', password_hash('secret', PASSWORD_DEFAULT));
			unset($_SESSION['alert']);
			$_POST = ['pluginThemeUrl' => $url, 'pluginThemeType' => 'plugins', 'password_recheck' => 'secret', 'token' => $wcms->getToken()];
			$wcms->addCustomModule();
		} finally {
			proc_terminate($server);
			proc_close($server);
			unlink($dir . '/wcms-modules.json');
			rmdir($dir);
		}
		return $url;
	}

	public function testAHandEditedEntryThatIsNotAUrlIsSkipped(): void
	{
		$db = json_decode(file_get_contents(self::DB));
		$db->config->customModules->plugins = ['https://example.test/one/wcms-modules.json', ['not', 'a', 'url'], 'https://example.test/two/wcms-modules.json'];
		unset($db->config->lastModulesSync); // the settings panel rebuilds the cache too
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT));
		@unlink(self::CACHE);
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'getFileFromRepo'])->getMock();
		$wcms->method('getFileFromRepo')->willReturn('{"version":1,"themes":{},"plugins":{}}');
		$wcms->loggedIn = true;

		$settings = $wcms->settings();
		$this->assertStringContainsString('https://example.test/two/wcms-modules.json</p>', $settings);
		$_REQUEST = $_GET = ['removeCustomModule' => 'https://example.test/one/wcms-modules.json', 'type' => 'plugins', 'token' => $wcms->getToken()];
		$wcms->removeCustomModuleAction();
		$this->assertStringContainsString('removed from', json_encode($_SESSION['alert']));
		$this->assertSame(['https://example.test/two/wcms-modules.json'], json_decode(file_get_contents(self::DB), true)['config']['customModules']['plugins']);
	}

	public function testTheListShowsEachRepositoryWithARemoveLinkAndRemovingOneKeepsTheOthers(): void
	{
		$settings = $this->wcms->settings();
		$this->assertStringContainsString('?removeCustomModule=' . rawurlencode('https://example.test/one/wcms-modules.json') . '&type=plugins&token=', $settings);
		$this->assertStringContainsString('?removeCustomModule=' . rawurlencode('https://example.test/two/wcms-modules.json') . '&type=plugins&token=', $settings);
		$this->assertStringContainsString('?removeCustomModule=' . rawurlencode('https://example.test/theme/wcms-modules.json') . '&type=themes&token=', $settings);
		$this->assertStringContainsString('https://example.test/two/wcms-modules.json</p>', $settings);
		$this->assertMatchesRegularExpression('~<a class="wbtn wbtn-sm wbtn-danger" href="[^"]*\?removeCustomModule=' . preg_quote(rawurlencode('https://example.test/one/wcms-modules.json'), '~') . '[^>]*><i class="deleteIcon"></i></a>~', $settings, 'a red button like the Files tab delete: the bare deleteIcon glyph is white on the modal');
		$this->assertStringContainsString('data-confirm="Remove https://example.test/one/wcms-modules.json? Anything installed from it stays."', $settings, 'the translation plugins match Remove (.*?)\? on the first sentence');

		@unlink(self::CACHE);
		$this->assertFileDoesNotExist(self::CACHE);
		$this->remove('https://example.test/one/wcms-modules.json', 'plugins');
		$this->assertStringContainsString('removed from', json_encode($_SESSION['alert']));
		$db = json_decode(file_get_contents(self::DB));
		$this->assertSame(['https://example.test/two/wcms-modules.json'], array_values((array)$db->config->customModules->plugins), 'the other plugin repository stays, re-indexed');
		$this->assertSame(['https://example.test/theme/wcms-modules.json'], array_values((array)$db->config->customModules->themes), 'the theme list is untouched');
		$this->assertFileExists(self::CACHE, 'the module cache was rebuilt');
	}

	public function testAnUnknownIndexAWrongTypeOrABadTokenChangesNothing(): void
	{
		$before = file_get_contents(self::DB);
		$this->remove('https://example.test/seven/wcms-modules.json', 'plugins');
		$this->assertStringContainsString('not found', json_encode($_SESSION['alert']));
		unset($_SESSION['alert']);
		$this->remove('https://example.test/one/wcms-modules.json', 'files');
		$this->assertStringContainsString('not found', json_encode($_SESSION['alert']));
		$this->remove('https://example.test/theme/wcms-modules.json', 'plugins'); // the right URL under the wrong type
		unset($_SESSION['alert']);
		$this->remove('https://example.test/one/wcms-modules.json/', 'plugins'); // exact match only: the Remove link carries the URL as listed
		$this->assertSame(['Custom module not found.'], array_column($_SESSION['alert']['danger'] ?? [], 'message'));
		$_REQUEST = $_GET = ['removeCustomModule' => ['https://example.test/one/wcms-modules.json'], 'type' => 'plugins', 'token' => $this->wcms->getToken()];
		$this->wcms->removeCustomModuleAction();
		$_REQUEST = $_GET = ['removeCustomModule' => 'https://example.test/one/wcms-modules.json', 'type' => 'plugins', 'token' => 'nope'];
		$this->wcms->removeCustomModuleAction();
		$this->assertSame($before, file_get_contents(self::DB));
	}
}
