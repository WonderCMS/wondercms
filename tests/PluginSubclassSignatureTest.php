<?php

use PHPUnit\Framework\TestCase;

/**
 * A plugin written against 3.7.1 may subclass Wcms and override the public createUniqueSlug(string, ?string): string.
 * PHP checks that override against the parent's declaration when the subclass is loaded, at loadPlugins(), before the
 * site renders: a changed parent signature is a fatal error on every page of such a site. The signature stays.
 */
final class PluginSubclassSignatureTest extends TestCase
{
	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		unset($_SERVER['HTTP_HOST']);
		@unlink(__DIR__ . '/../data_test/database_test.js');
	}

	public function testThePublicSignatureIsThe371One(): void
	{
		$method = new ReflectionMethod(Wcms::class, 'createUniqueSlug');
		$this->assertTrue($method->isPublic());
		$this->assertSame(2, $method->getNumberOfParameters());
		$this->assertSame('string', $method->getReturnType()->getName());
		[$slug, $menu] = $method->getParameters();
		$this->assertSame('string', $slug->getType()->getName());
		$this->assertFalse($slug->getType()->allowsNull());
		$this->assertSame('string', $menu->getType()->getName()); // (string) of a nullable type reads '?string' only from PHP 8.0; getName() plus allowsNull() says the same on 7.4
		$this->assertTrue($menu->getType()->allowsNull());
		$this->assertTrue($menu->isDefaultValueAvailable());
		$this->assertNull($menu->getDefaultValue());
	}

	public function testA371StylePluginSubclassLoadsAndWorks(): void
	{
		// Declared in the test's own process: an incompatible declaration would be a fatal error here, not a failed assertion
		if (!class_exists('LegacyPluginWcms', false)) {
			eval('class LegacyPluginWcms extends Wcms { public function createUniqueSlug(string $slug, ?string $menu = null): string { return parent::createUniqueSlug($slug, $menu); } }');
		}
		$wcms = new LegacyPluginWcms('data_test', 'files_test', 'database_test.js');

		$this->assertInstanceOf(Wcms::class, $wcms);
		$this->assertSame('how-to-2', $wcms->createUniqueSlug('How to'), 'unique among the top-level menu items');
		$this->assertSame('fresh', $wcms->createUniqueSlug('Fresh', null));
		$this->assertSame('404', $wcms->createUniqueSlug('404'), 'as in 3.7.1, a page without a menu entry does not count here');
	}

	public function testAnOverrideIsAppliedToEveryCreateAndRename(): void
	{
		// In 3.7.1 createMenuItem() and updateMenuItem() called the public createUniqueSlug(), so a subclass's slug policy reached every create and rename.
		if (!class_exists('PrefixingPluginWcms', false)) {
			eval('class PrefixingPluginWcms extends Wcms { public function createUniqueSlug(string $slug, ?string $menu = null): string { return "custom-" . parent::createUniqueSlug($slug, $menu); } }');
		}
		$wcms = new PrefixingPluginWcms('data_test', 'files_test', 'database_test.js');
		$wcms->loggedIn = true;
		$dbPath = __DIR__ . '/../data_test/database_test.js';

		$wcms->createMenuItem('Panel Probe', null, 'show', true);

		$menuItems = (array)json_decode(file_get_contents($dbPath))->config->menuItems;
		$this->assertContains('custom-panel-probe', array_column($menuItems, 'slug'), 'the override names the created item');
		$this->assertTrue(isset(json_decode(file_get_contents($dbPath))->pages->{'custom-panel-probe'}), 'and its page');
		$this->assertSame('custom-panel-probe', $_SESSION['redirect_to'] ?? null);
		$key = (string)max(array_keys($menuItems));
		$this->assertSame('custom-panel-probe', $menuItems[$key]->slug, 'the new item is the last top-level entry');

		$wcms->updateMenuItem('Renamed Probe', $key, 'show');

		$db = json_decode(file_get_contents($dbPath));
		$this->assertSame('custom-renamed-probe', $db->config->menuItems->{$key}->slug, 'the override names the renamed item');
		$this->assertTrue(isset($db->pages->{'custom-renamed-probe'}), 'the page moved with it');
		$this->assertFalse(isset($db->pages->{'custom-panel-probe'}));
		// The #410 context of a create or rename does not leak into a later direct call: a page without a menu entry does not count there, as in 3.7.1
		$this->assertSame('custom-404', $wcms->createUniqueSlug('404'));
	}

	public function testTheDeclarationIsAcceptedInAFreshProcess(): void
	{
		// The same check with nothing else loaded: the bite on a changed signature is exit code 255 (PHP fatal error)
		if (!function_exists('proc_open')) {
			$this->markTestSkipped('needs proc_open');
		}
		$script = 'define("PHPUNIT_TESTING", true); require ' . var_export(realpath(__DIR__ . '/../index.php'), true) . ';'
			. ' class LegacyPluginWcms extends Wcms { public function createUniqueSlug(string $slug, ?string $menu = null): string { return parent::createUniqueSlug($slug, $menu); } }'
			. ' exit(0);';
		$process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, realpath(__DIR__ . '/..'));
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);

		$this->assertSame(0, $exit, "the subclass must load against the shipped index.php\n" . $stdout . $stderr);
		$this->assertStringNotContainsString('must be compatible', $stderr);
	}
}
