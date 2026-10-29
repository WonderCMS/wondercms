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
		$_REQUEST = $_GET = ['removeCustomModule' => ['https://example.test/one/wcms-modules.json'], 'type' => 'plugins', 'token' => $this->wcms->getToken()];
		$this->wcms->removeCustomModuleAction();
		$_REQUEST = $_GET = ['removeCustomModule' => 'https://example.test/one/wcms-modules.json', 'type' => 'plugins', 'token' => 'nope'];
		$this->wcms->removeCustomModuleAction();
		$this->assertSame($before, file_get_contents(self::DB));
	}
}
