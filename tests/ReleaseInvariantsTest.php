<?php

use PHPUnit\Framework\TestCase;

/**
 * Both version sources must agree: the `version` file triggers the update prompt on every site,
 * the VERSION constant is what the downloaded index.php reports. A mismatch means a popup loop.
 */
final class ReleaseInvariantsTest extends TestCase
{
	public function testVersionFileEqualsVersionConstant(): void
	{
		$this->assertSame(VERSION, trim(file_get_contents(__DIR__ . '/../version')));
	}

	public function testVersionIsStrictSemver(): void
	{
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', VERSION);
	}

	public function testTheCurrentIndexPhpWouldBeAcceptedByAnOlderUpdater(): void
	{
		// Simulate the old site: its VERSION is lower than the file it downloads.
		$source = file_get_contents(__DIR__ . '/../index.php');
		$this->assertTrue(strncmp($source, '<?php', 5) === 0);
		$this->assertStringContainsString('class Wcms', $source);
		$this->assertSame(1, preg_match("/define\('VERSION', '(\d+\.\d+\.\d+)'\);/", $source, $m));
		$this->assertSame(VERSION, $m[1]);
		// The tokenizer-less update guard relies on this: after "class Wcms" the only "}" at column 0 is the class's own.
		$this->assertSame(1, substr_count(substr($source, strpos($source, "\nclass Wcms")), "\n}"), 'keep the class closing brace the only column-0 brace');
	}

	public function testAdminCssAndJsArePinnedToTheSameCdnTagWithIntegrity(): void
	{
		$source = file_get_contents(__DIR__ . '/../index.php');
		preg_match_all('#wondercms-cdn-files@([0-9.]+)/wcms-admin\.min\.(css|js)"[^>]*integrity="sha384-[A-Za-z0-9+/=]+"#', $source, $m);
		$this->assertCount(2, $m[1], 'both admin assets need an integrity hash');
		$this->assertSame($m[1][0], $m[1][1], 'CSS and JS must use the same CDN tag');
	}
}
