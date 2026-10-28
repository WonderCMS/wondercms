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
}
