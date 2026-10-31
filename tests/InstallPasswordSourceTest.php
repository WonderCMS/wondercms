<?php

use PHPUnit\Framework\TestCase;

/**
 * generatePassword() builds the first-install password: MIN_PASSWORD_LENGTH characters from 0-9A-Za-z, each drawn with
 * random_int(), so the result does not follow the Mersenne Twister state that str_shuffle() and mt_rand() share.
 */
final class InstallPasswordSourceTest extends TestCase
{
	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		@unlink($this->db());
	}

	protected function tearDown(): void
	{
		@unlink($this->db());
	}

	private function db(): string
	{
		return __DIR__ . '/../data_test/database_installpwsource.js';
	}

	private function site(): Wcms
	{
		return new Wcms('data_test', 'files_test', 'database_installpwsource.js');
	}

	public function testTheInstallPasswordIsEightAlphanumericCharactersFromACsprng(): void
	{
		$wcms = $this->site();

		for ($i = 0; $i < 200; $i++) {
			$password = $wcms->generatePassword();
			$this->assertSame(Wcms::MIN_PASSWORD_LENGTH, strlen($password));
			$this->assertSame(1, preg_match('~^[0-9A-Za-z]+$~', $password), 'only the 62-character alphabet: ' . $password);
		}

		// str_shuffle() replays the same password after the same mt_srand() seed; random_int() ignores that seed.
		// Two independent CSPRNG draws collide with probability 62^-8 (about 1 in 2e14); a false failure here is accepted.
		mt_srand(1234);
		$first = $wcms->generatePassword();
		mt_srand(1234);
		$second = $wcms->generatePassword();
		$this->assertNotSame($first, $second, 'the install password must not follow the Mersenne Twister state');
	}
}
