<?php

use PHPUnit\Framework\TestCase;

/**
 * The updater overwrites index.php with whatever GitHub returns.
 * These tests pin what is accepted and what is refused before that write happens.
 */
final class UpdateGuardTest extends TestCase
{
	/** @var Wcms */
	private $wcms;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$this->wcms = new Wcms('data_test', 'files_test', 'database_test.js');
	}

	private function core(string $version): string
	{
		return "<?php\ndefine('VERSION', '" . $version . "');\nclass Wcms {}\n";
	}

	private function newer(): string
	{
		$parts = explode('.', VERSION);
		$parts[2]++;
		return implode('.', $parts);
	}

	public function testAcceptsCompleteNewerCore(): void
	{
		$this->assertTrue($this->wcms->isValidCoreUpdate($this->core($this->newer())));
	}

	public function testRefusesSameOrOlderVersion(): void
	{
		$this->assertFalse($this->wcms->isValidCoreUpdate($this->core(VERSION)));
		$this->assertFalse($this->wcms->isValidCoreUpdate($this->core('3.0.0')));
	}

	public function testRefusesGitHubErrorBodies(): void
	{
		$this->assertFalse($this->wcms->isValidCoreUpdate('404: Not Found'));
		$this->assertFalse($this->wcms->isValidCoreUpdate('429: Too Many Requests'));
		$this->assertFalse($this->wcms->isValidCoreUpdate('<!DOCTYPE html><html><body>Error</body></html>'));
		$this->assertFalse($this->wcms->isValidCoreUpdate(''));
	}

	public function testRefusesTruncatedCore(): void
	{
		$truncated = substr($this->core($this->newer()), 0, 20);
		$this->assertFalse($this->wcms->isValidCoreUpdate($truncated));
	}

	public function testRefusesPhpThatIsNotWonderCms(): void
	{
		$this->assertFalse($this->wcms->isValidCoreUpdate("<?php\ndefine('VERSION', '9.9.9');\necho 'hello';\n"));
	}

	public function testOfficialVersionMustBeStrictSemver(): void
	{
		$wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['getCheckFileFromRepo'])
			->getMock();
		$wcms->method('getCheckFileFromRepo')->willReturnOnConsecutiveCalls(
			'3.7.0',
			'429: Too Many Requests',
			'<!DOCTYPE html>',
			'3.7',
			null
		);

		$this->assertSame('3.7.0', $wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
		$this->assertNull($wcms->getOfficialVersion());
	}
}
