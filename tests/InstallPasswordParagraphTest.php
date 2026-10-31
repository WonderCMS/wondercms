<?php

use PHPUnit\Framework\TestCase;

/**
 * createDb() writes the install password into the public home page; the admin's first successful login removes that
 * paragraph (and an existing site's next login does), a paragraph edited by hand stays, a visitor never removes it.
 */
final class InstallPasswordParagraphTest extends TestCase
{
	private const DB = __DIR__ . '/../data_test/database_installpw.js';
	private const PREFIX = '<p>Your password for editing everything is: <b>';

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		@unlink(self::DB);
	}

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
		@unlink(self::DB);
	}

	private function site(): Wcms
	{
		return $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_installpw.js'])->onlyMethods(['redirect'])->getMock();
	}

	private function login(Wcms $wcms, string $password): void
	{
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['password'] = $password;
		$wcms->currentPage = 'loginURL';
		$wcms->loginAction();
		$_POST = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	private function onDisk(): stdClass
	{
		return json_decode(file_get_contents(self::DB));
	}

	private function installPassword(string $content): string
	{
		$this->assertSame(1, preg_match('~' . preg_quote(self::PREFIX, '~') . '([^<]*)</b></p>~', $content, $m), 'a fresh install carries the paragraph');
		return $m[1];
	}

	private function passwordAlerts(): array
	{
		return array_values(array_filter($_SESSION['alert']['info'] ?? [], static function ($alert) {
			return strpos($alert['message'], 'no longer shown on the home page') !== false;
		}));
	}

	public function testTheInstallPasswordParagraphIsRemovedAtTheFirstLogin(): void
	{
		$this->site(); // a fresh install, through the real createDb()
		$before = $this->onDisk()->pages->home->content;
		$password = $this->installPassword($before);
		$paragraph = self::PREFIX . $password . '</b></p>';

		$this->login($this->site(), $password);

		$this->assertTrue($_SESSION['loggedIn'] ?? false, 'the login itself is unchanged');
		$after = $this->onDisk()->pages->home->content;
		$this->assertStringNotContainsString('everything is:', $after);
		$this->assertStringNotContainsString($password, $after);
		$this->assertSame(str_replace($paragraph . "\n\n", '', $before), $after, 'only the paragraph and its blank line go; every other byte stays');
		$this->assertStringContainsString('Click here to login', $after);
		$this->assertStringStartsWith("<h1>Welcome to your website</h1>\n\n<p><a href=", $after);

		$alerts = $this->passwordAlerts();
		$this->assertCount(1, $alerts, 'the admin is told once');
		$this->assertStringContainsString('data-target-tab="#security"', $alerts[0]['message']);
	}

	public function testAnExistingSiteLosesTheParagraphAtItsNextLogin(): void
	{
		$this->site();
		$db = $this->onDisk();
		$db->config->password = password_hash('ownerPass1', PASSWORD_DEFAULT); // changed since the install: the old one still shows
		$db->pages->home->content = "<h1>My shop</h1>\n\n" . self::PREFIX . "Ab3dEf7h</b></p>\n\n<p>Opening hours: 9 to 5.</p>";
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

		$this->login($this->site(), 'ownerPass1');

		$this->assertSame("<h1>My shop</h1>\n\n<p>Opening hours: 9 to 5.</p>", $this->onDisk()->pages->home->content);
		$this->assertCount(1, $this->passwordAlerts());

		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['rootDir']);
		$this->login($this->site(), 'ownerPass1');
		$this->assertSame("<h1>My shop</h1>\n\n<p>Opening hours: 9 to 5.</p>", $this->onDisk()->pages->home->content, 'once: the next login finds nothing to remove');
		$this->assertCount(0, $this->passwordAlerts(), 'and says nothing');
	}

	public function testAHandEditedHomePageIsNotTouchedAtLogin(): void
	{
		foreach ([
			"<h1>Hi</h1>\n\n" . self::PREFIX . "Ab3dEf7h</b> (changed since)</p>\n\n<p>More.</p>",
			"<h1>Hi</h1>\n\n<p>Your password for editing everything is: <b><i>hidden</i></b></p>",
			"<h1>Hi</h1>\n\n<p>Your password for editing is: <b>Ab3dEf7h</b></p>",
			"<h1>Hi</h1>\n\n<p><a href=\"/loginURL\" class=\"button\">Click here to login</a></p>",
		] as $content) {
			@unlink(self::DB);
			$this->site();
			$db = $this->onDisk();
			$db->config->password = password_hash('ownerPass1', PASSWORD_DEFAULT);
			$db->pages->home->content = $content;
			file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			$pagesBefore = json_encode($this->onDisk()->pages);

			$this->login($this->site(), 'ownerPass1');

			$this->assertTrue($_SESSION['loggedIn'] ?? false);
			$this->assertSame($pagesBefore, json_encode($this->onDisk()->pages), 'no write to any page: ' . $content);
			$this->assertCount(0, $this->passwordAlerts(), $content);
			unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['rootDir']);
		}
	}

	public function testASiteWithoutAHomePageGetsNoneAtLogin(): void
	{
		$this->site();
		$db = $this->onDisk();
		$db->config->password = password_hash('ownerPass1', PASSWORD_DEFAULT);
		$db->config->defaultPage = 'how-to';
		unset($db->pages->home);
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

		$this->login($this->site(), 'ownerPass1');

		$this->assertTrue($_SESSION['loggedIn'] ?? false);
		$this->assertFalse(property_exists($this->onDisk()->pages, 'home'), 'the check does not recreate a deleted home page');
	}

	public function testAVisitorRequestNeverRemovesTheParagraph(): void
	{
		$this->site();
		$bytes = file_get_contents(self::DB);
		$password = $this->installPassword($this->onDisk()->pages->home->content);

		foreach ([['GET', null, null], ['GET', 'loginURL', null], ['POST', 'loginURL', 'wrong' . $password], ['GET', 'how-to', null]] as [$method, $page, $tried]) {
			$_SERVER['REQUEST_METHOD'] = $method;
			$_GET = $page === null ? [] : ['page' => $page];
			$_POST = $tried === null ? [] : ['password' => $tried];
			$visitor = $this->site();
			$visitor->init();
			$this->assertFalse($visitor->loggedIn);
			$this->assertSame($bytes, file_get_contents(self::DB), $method . ' ' . ($page ?? '/') . ': the database is not written');
		}
		$this->assertStringContainsString(self::PREFIX . $password, $this->onDisk()->pages->home->content);
	}
}
