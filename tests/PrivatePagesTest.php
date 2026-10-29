<?php

use PHPUnit\Framework\TestCase;

/**
 * #366 private pages: the flag lives on the menu item and is synced to the page; a visitor gets the 404 page for the page
 * and for every subpage under it, the public menu and search skip them; the logged-in admin sees everything.
 */
final class PrivatePagesTest extends TestCase
{
	private const DB = __DIR__ . '/../data_test/database_test.js';

	/** @var Wcms */
	private $wcms;

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
		@unlink(self::DB);
	}

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		@unlink(self::DB);
		$this->wcms = $this->admin();
		$this->wcms->createPage(['games'], true);
		$this->wcms->createPage(['games', 'board'], true);
		$this->wcms->createPage(['diary'], true);
		$db = json_decode(file_get_contents(self::DB));
		$db->pages->diary->content = 'dear diary, the secret word is zanzibar';
		$db->pages->games->subpages->board->content = 'board games night';
		foreach ($db->config->menuItems as $item) { // createPage() adds menu items hidden; the public menu must show them for the test
			$item->visibility = 'show';
			foreach ((array)($item->subpages ?? []) as $subpage) {
				$subpage->visibility = 'show';
			}
		}
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		$this->wcms = $this->admin();
	}

	private function admin(): Wcms
	{
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->loggedIn = true;
		return $wcms;
	}

	private function visitor(): Wcms
	{
		$wcms = new Wcms('data_test', 'files_test', 'database_test.js');
		$wcms->loggedIn = false;
		return $wcms;
	}

	private function menuKey(Wcms $wcms, string $slug): string
	{
		foreach ($wcms->get('config', 'menuItems') as $key => $item) {
			if ($item->slug === $slug) {
				return (string)$key;
			}
		}
		$this->fail('no menu item ' . $slug);
	}

	private function toggle(string $menuKeyTree): void
	{
		$_REQUEST = $_GET = ['privatePage' => $menuKeyTree, 'token' => $this->wcms->getToken()];
		$this->wcms->privatePageAction();
		$_REQUEST = $_GET = [];
	}

	private function search(Wcms $wcms, string $query): array
	{
		$method = new ReflectionMethod(Wcms::class, 'searchPages');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		return array_column($method->invoke($wcms, $wcms->get('pages'), $query), 'slug');
	}

	public function testAPrivatePageAndItsSubpagesExistOnlyForTheAdmin(): void
	{
		$this->toggle($this->menuKey($this->wcms, 'games'));
		$this->assertStringContainsString('is now private', json_encode($_SESSION['alert']));
		$db = json_decode(file_get_contents(self::DB));
		$this->assertTrue($db->pages->games->private, 'synced to the page');
		$this->assertObjectNotHasProperty('private', $db->pages->diary, 'a public page carries no flag, so a database without private pages keeps its shape');
		$this->assertObjectNotHasProperty('private', $db->pages->games->subpages->board, 'the subpage inherits at read time, nothing is written to it');

		$visitor = $this->visitor();
		$this->assertNull($visitor->getPageData('games'), 'the visitor gets the 404 page');
		$this->assertNull($visitor->getPageData('games/board'), 'and for the subpage under it');
		$this->assertNotNull($visitor->getPageData('diary'));
		$this->assertStringNotContainsString('games', $visitor->menu());
		$this->assertStringContainsString('diary', $visitor->menu());
		$this->assertSame([], $this->search($visitor, 'board games'), 'search skips the private subtree');
		$this->assertSame(['diary'], $this->search($visitor, 'zanzibar'));

		$admin = $this->admin();
		$this->assertNotNull($admin->getPageData('games/board'));
		$this->assertStringContainsString('games', $admin->menu());
		$this->assertSame(['games/board'], $this->search($admin, 'board games'));
		$settings = $admin->settings();
		$this->assertStringContainsString('?privatePage=' . $this->menuKey($admin, 'games') . '&', $settings, 'the menu settings carry the toggle');
		$this->assertMatchesRegularExpression('~privatePage=' . $this->menuKey($admin, 'games') . '&[^>]*><i class="lockIcon"></i> private</a>~', $settings, 'and the row shows the state at a glance');
		$this->assertMatchesRegularExpression('~privatePage=' . $this->menuKey($admin, 'diary') . '&[^>]*><i class="lockIcon"></i> public</a>~', $settings);
	}

	public function testTogglingBackMakesThePagePublicAgain(): void
	{
		$key = $this->menuKey($this->wcms, 'diary');
		$this->toggle($key);
		$this->assertNull($this->visitor()->getPageData('diary'));
		$this->toggle($key);
		$this->assertStringContainsString('is now public', json_encode($_SESSION['alert']));
		$this->assertNotNull($this->visitor()->getPageData('diary'));
		$db = json_decode(file_get_contents(self::DB));
		$this->assertObjectNotHasProperty('private', $db->pages->diary, 'the flag is removed, not set to false');
		$this->assertObjectNotHasProperty('private', $db->config->menuItems->{$key}, 'and from the menu item too');
	}

	public function testAPrivatePageStaysHiddenBehindAnUnknownParentInTheAddress(): void
	{
		$this->toggle($this->menuKey($this->wcms, 'diary'));
		$this->assertNull($this->visitor()->getPageData('missing/diary'));
		$this->assertNull($this->admin()->getPageData('missing/diary'), 'an unknown first segment is a missing page, not the top-level diary');
		$this->assertNotNull($this->admin()->getPageData('diary'));
	}

	public function testTheHomePageThePagesAboveItAndThe404PageCannotBePrivate(): void
	{
		$this->wcms->createPage(['404'], true);
		$this->wcms->set('config', 'defaultPage', 'games/board');
		$this->wcms = $this->admin();
		$menu = $this->wcms->get('config', 'menuItems');
		$gamesKey = $this->menuKey($this->wcms, 'games');
		$boardKey = (string)array_key_first((array)$menu->{$gamesKey}->subpages);
		$before = file_get_contents(self::DB);
		foreach ([$gamesKey . '-' . $boardKey, $gamesKey, $this->menuKey($this->wcms, '404')] as $keyTree) {
			$this->toggle($keyTree);
			$this->assertStringContainsString('cannot be private', json_encode($_SESSION['alert']), $keyTree);
			unset($_SESSION['alert']);
			$this->assertSame($before, file_get_contents(self::DB), $keyTree);
		}
		$settings = $this->wcms->settings();
		$this->assertStringNotContainsString('?privatePage=' . $gamesKey . '&', $settings, 'the rows above the home page show no toggle');
		$this->assertStringNotContainsString('?privatePage=' . $gamesKey . '-' . $boardKey . '&', $settings, 'nor the home page row');
		$this->assertStringContainsString('?privatePage=' . $this->menuKey($this->wcms, 'diary') . '&', $settings);
		$this->assertStringContainsString('class="wbtn wbtn-sm wbtn-secondary"', $settings, 'a visible button, not the white lock glyph alone');
		$this->toggle($this->menuKey($this->wcms, 'home'));
		$this->assertTrue($this->wcms->get('config', 'menuItems')->{$this->menuKey($this->wcms, 'home')}->private ?? false, 'the old home page is an ordinary page now');
	}

	public function testAPageWhoseSlugStartsLikeTheHomePageCanStillBePrivate(): void
	{
		$this->wcms->createPage(['public'], true);
		$this->wcms->createPage(['pub'], true);
		$this->wcms->set('config', 'defaultPage', 'public');
		$this->toggle($this->menuKey($this->wcms, 'pub'));
		$this->assertTrue($this->wcms->get('config', 'menuItems')->{$this->menuKey($this->wcms, 'pub')}->private ?? false, 'pub is not a page above public');
		$this->toggle($this->menuKey($this->wcms, 'public'));
		$this->assertObjectNotHasProperty('private', $this->wcms->get('config', 'menuItems')->{$this->menuKey($this->wcms, 'public')}, 'the home page itself still cannot');
	}

	public function testAPrivatePageCannotBecomeTheHomePage(): void
	{
		$this->toggle($this->menuKey($this->wcms, 'diary'));
		unset($_SESSION['alert']);
		$_POST = ['fieldname' => 'defaultPage', 'content' => 'diary', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame('home', $this->wcms->get('config', 'defaultPage'));
		$this->assertStringContainsString('cannot be the home page', json_encode($_SESSION['alert']));
		$_POST['content'] = 'how-to';
		$this->wcms->saveAction();
		$this->assertSame('how-to', $this->wcms->get('config', 'defaultPage'), 'a public page still can');
	}

	public function testAnAlertRaisedByTheEditorsBackgroundSaveShowsOnTheReloadThatFollows(): void
	{
		$this->toggle($this->menuKey($this->wcms, 'diary'));
		unset($_SESSION['alert']);
		$_POST = ['fieldname' => 'defaultPage', 'content' => 'diary', 'target' => 'config', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertSame('', $this->wcms->alerts(), 'the save request answers without the alert: the admin JS discards that response');
		$_POST = [];
		$this->assertStringContainsString('A private page cannot be the home page', $this->wcms->alerts(), 'the reload shows it');
		$this->assertArrayNotHasKey('alert', $_SESSION, 'and then it is gone');
	}

	public function testDeletingTheHomePageNeverHandsTheHomeToAPrivatePage(): void
	{
		$this->wcms->set('config', 'defaultPage', 'diary');
		$this->assertSame('0', $this->menuKey($this->wcms, 'home'));
		$this->toggle('0');
		$_REQUEST = $_GET = ['delete' => 'diary', 'token' => $this->wcms->getToken()];
		$this->wcms->deletePageAction();
		$_REQUEST = $_GET = [];

		$this->assertSame('how-to', $this->wcms->get('config', 'defaultPage'), 'the first menu item that is not private, hidden or shown as before');
		$visitor = $this->visitor();
		$visitor->pageStatus();
		$this->assertTrue($visitor->currentPageExists, 'the visitor gets the home page at the site address, not the 404 page');
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
	}

	public function testDeletingTheHomePageWhenEveryOtherPageIsPrivateIsRefused(): void
	{
		$this->wcms->set('config', 'defaultPage', 'diary');
		foreach (['home', 'how-to', 'games'] as $slug) {
			$this->toggle($this->menuKey($this->wcms, $slug));
		}
		unset($_SESSION['alert']);
		$before = file_get_contents(self::DB);
		$_REQUEST = $_GET = ['delete' => 'diary', 'token' => $this->wcms->getToken()];
		$this->wcms->deletePageAction();
		$_REQUEST = $_GET = [];

		$this->assertSame(['Make another page public first: the home page cannot be private.'], array_column($_SESSION['alert']['danger'] ?? [], 'message'));
		$this->assertArrayNotHasKey('success', $_SESSION['alert']);
		$this->assertSame($before, file_get_contents(self::DB), 'nothing deleted, nothing saved');

		$this->toggle($this->menuKey($this->wcms, 'games'));
		$this->wcms->set('config', 'defaultPage', 'games/board');
		$this->toggle($this->menuKey($this->wcms, 'diary'));
		unset($_SESSION['alert']);
		$_REQUEST = $_GET = ['delete' => 'games/board', 'token' => $this->wcms->getToken()];
		$this->wcms->deletePageAction();
		$_REQUEST = $_GET = [];
		$this->assertSame('games', $this->wcms->get('config', 'defaultPage'), 'a subpage home page: its public parent stays and takes over');
		$this->assertArrayNotHasKey('danger', $_SESSION['alert']);
	}

	public function testAnArrayInsteadOfAMenuKeyChangesNothing(): void
	{
		$before = file_get_contents(self::DB);
		$_REQUEST = $_GET = ['privatePage' => ['1', '2'], 'token' => $this->wcms->getToken()];
		$this->wcms->privatePageAction();
		$this->assertSame($before, file_get_contents(self::DB));
	}

	public function testThePrivateNoticeShowsOnAPrivateSubpageToo(): void
	{
		$gamesKey = $this->menuKey($this->wcms, 'games');
		$boardKey = $gamesKey . '-' . (string)array_key_first((array)$this->wcms->get('config', 'menuItems')->{$gamesKey}->subpages);
		$notice = function (): string {
			unset($_SESSION['alert']);
			$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['loadThemeAndFunctions'])->getMock();
			$wcms->loggedIn = true;
			$_GET = ['page' => 'games/board'];
			$wcms->pageStatus(); // currentPage is 'board', currentPageTree ['games', 'board'], as in a real request
			$_GET = [];
			@$wcms->render(); // header() after PHPUnit's own output only warns
			return implode("\n", array_column($_SESSION['alert']['info'] ?? [], 'message'));
		};
		$this->assertStringNotContainsString('is private', $notice());
		$this->toggle($gamesKey);
		$this->assertStringContainsString('This page (games/board) is private', $notice(), 'a public subpage under a private parent');
		$this->toggle($gamesKey);
		$this->toggle($boardKey);
		$this->assertStringContainsString('This page (games/board) is private', $notice(), 'a private subpage under a public parent');
	}

	public function testASubpageCanBePrivateOnItsOwn(): void
	{
		$menu = $this->wcms->get('config', 'menuItems');
		$gamesKey = $this->menuKey($this->wcms, 'games');
		$boardKey = (string)array_key_first((array)$menu->{$gamesKey}->subpages);
		$this->toggle($gamesKey . '-' . $boardKey);
		$visitor = $this->visitor();
		$this->assertNotNull($visitor->getPageData('games'), 'the parent stays public');
		$this->assertNull($visitor->getPageData('games/board'));
		$this->assertStringNotContainsString('games/board', $visitor->menu());
		$this->assertStringNotContainsString('subPageDropdown', $visitor->menu(), 'no empty dropdown for a parent whose only shown subpage is private');
		$this->assertStringNotContainsString('subpage-nav', $visitor->menu());
		$this->assertStringContainsString('subPageDropdown', $this->admin()->menu(), 'the admin still gets it');
	}

	public function testTheVisitorDropdownListsThePublicSubpageAndNotThePrivateOne(): void
	{
		$this->wcms->createPage(['games', 'chess'], true);
		$db = json_decode(file_get_contents(self::DB));
		$gamesKey = $this->menuKey($this->wcms, 'games');
		$chessKey = null;
		foreach ($db->config->menuItems->{$gamesKey}->subpages as $key => $subpage) {
			$subpage->visibility = 'show';
			$chessKey = $subpage->slug === 'chess' ? (string)$key : $chessKey;
		}
		file_put_contents(self::DB, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		$this->wcms = $this->admin();
		$this->toggle($gamesKey . '-' . $chessKey);

		$visitor = $this->visitor()->menu();
		$this->assertStringContainsString('subPageDropdown', $visitor, 'one public subpage is shown, so the parent keeps its dropdown');
		$this->assertStringContainsString('games/board', $visitor);
		$this->assertStringNotContainsString('games/chess', $visitor, 'no link to the private subpage');
		$this->assertStringNotContainsString('Chess', $visitor, 'nor its title');
		$admin = $this->admin()->menu();
		$this->assertStringContainsString('games/board', $admin);
		$this->assertStringContainsString('games/chess', $admin);
	}

	public function testAWrongTokenOrAnUnknownMenuKeyChangesNothing(): void
	{
		$before = file_get_contents(self::DB);
		$_REQUEST = $_GET = ['privatePage' => $this->menuKey($this->wcms, 'diary'), 'token' => 'nope'];
		$this->wcms->privatePageAction();
		$this->assertSame($before, file_get_contents(self::DB));
		$this->toggle('99-7');
		$this->assertStringContainsString('Page not found', json_encode($_SESSION['alert']));
		$this->assertSame($before, file_get_contents(self::DB));
	}

	public function testAnAdminPageViewThatChangesNothingDoesNotRewriteTheDatabase(): void
	{
		$cache = __DIR__ . '/../data_test/cache.json';
		$cacheBefore = @file_get_contents($cache);
		file_put_contents($cache, '{"themes":{},"plugins":{}}'); // with today's stamp: no module or core check (no network, no stamp write)
		$this->wcms->set('config', 'lastModulesSync', date('Y/m/d'));
		$_SESSION['loggedIn'] = true;
		$_SESSION['rootDir'] = $this->wcms->rootDir;
		try {
			$this->admin()->init(); // setUp showed the menu items without syncing the pages: this view syncs and saves
			$this->assertSame('show', json_decode(file_get_contents(self::DB))->pages->games->visibility, 'a view that syncs a change still saves');
			touch(self::DB, time() - 100);
			clearstatcache();
			$before = [file_get_contents(self::DB), filemtime(self::DB), fileinode(self::DB)];
			$this->admin()->init();
			clearstatcache();
			$this->assertSame($before, [file_get_contents(self::DB), filemtime(self::DB), fileinode(self::DB)], 'bytes, mtime and inode unchanged: no save (a save renames a new file into place)');
		} finally {
			$cacheBefore === false ? @unlink($cache) : file_put_contents($cache, $cacheBefore);
		}
	}
}
