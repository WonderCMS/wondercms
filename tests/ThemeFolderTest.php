<?php

use PHPUnit\Framework\TestCase;

/**
 * The active theme name is used to build a require path and asset URLs.
 * A custom folder name must survive the update, and nothing outside themes/ may ever be loaded.
 */
final class ThemeFolderTest extends TestCase
{
	/** @var Wcms */
	private $wcms;
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$this->root = sys_get_temp_dir() . '/wcms-theme-' . bin2hex(random_bytes(4));
		foreach (['sky', 'my.theme', 'Dark Sky', 'underscore_theme'] as $dir) {
			mkdir($this->root . '/themes/' . $dir, 0777, true);
		}
		mkdir($this->root . '/plugins/notatheme', 0777, true);
		$this->wcms = new Wcms('data_test', 'files_test', 'database_test.js');
		$this->wcms->rootDir = $this->root;
	}

	protected function tearDown(): void
	{
		foreach (array_reverse(glob($this->root . '/{,*/,*/*/}*', GLOB_BRACE | GLOB_ONLYDIR) ?: []) as $d) {
			@rmdir($d);
		}
		@rmdir($this->root . '/themes');
		@rmdir($this->root . '/plugins');
		@rmdir($this->root);
	}

	public function testCustomFolderNamesWithDotsAndSpacesAreKept(): void
	{
		$this->assertTrue($this->wcms->isThemeFolder('my.theme'));
		$this->assertTrue($this->wcms->isThemeFolder('Dark Sky'));
		$this->assertTrue($this->wcms->isThemeFolder('underscore_theme'));
	}

	public function testTraversalAndDotFoldersAreRejected(): void
	{
		foreach (['..', '.', '../plugins', 'sky/..', '../data', '', '/etc', 'themes/sky'] as $bad) {
			$this->assertFalse($this->wcms->isThemeFolder($bad), "accepted: $bad");
		}
	}

	public function testMissingFolderIsRejected(): void
	{
		$this->assertFalse($this->wcms->isThemeFolder('does-not-exist'));
		$this->assertFalse($this->wcms->isThemeFolder('notatheme'));
	}

	public function testBrokenThemeFallsBackToSkyInsteadOfAWhiteScreen(): void
	{
		file_put_contents($this->root . '/themes/sky/theme.php', '<?php global $Wcms; echo "SKY " . $Wcms->asset("css/style.css");');
		mkdir($this->root . '/themes/broken');
		file_put_contents($this->root . '/themes/broken/theme.php', '<?php echo "partial"; echo "unterminated;');
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$wcms->set('config', 'theme', 'broken');
		unset($_SESSION['alert']);

		$GLOBALS['Wcms'] = $wcms;

		// Anonymous visitor: Sky renders with Sky's assets, no alert leaks the error message
		ob_start();
		$wcms->loadThemeAndFunctions();
		$output = ob_get_clean();
		$this->assertStringStartsWith('SKY ', $output);
		$this->assertStringContainsString('/themes/sky/css/style.css', $output);
		$this->assertFalse(isset($_SESSION['alert']), 'visitors must not see the failure message');

		// Admin (fresh request): same render plus an alert naming the broken theme
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js', $this->root])
			->onlyMethods(['redirect'])
			->getMock();
		$GLOBALS['Wcms'] = $wcms;
		$wcms->loggedIn = true;
		ob_start();
		$wcms->loadThemeAndFunctions();
		ob_end_clean();
		$this->assertStringContainsString('failed to load', $_SESSION['alert']['danger'][0]['message']);
		$saved = json_decode(file_get_contents($this->root . '/data_test/database_test.js'));
		$this->assertSame('broken', $saved->config->theme, 'the setting on disk is not changed behind the admin\'s back');
		unset($_SESSION['alert'], $GLOBALS['Wcms']);
		exec('rm -rf ' . escapeshellarg($this->root . '/data_test'));
	}
}
