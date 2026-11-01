<?php

use PHPUnit\Framework\TestCase;

/**
 * #336 (subpage under a page created before 3.2.0), #348 (Windows URLs), #360 (visitor view writes duplicates).
 */
final class PagesAndUrlTest extends TestCase
{
	/** @var Wcms */
	private $wcms;
	/** @var string */
	private $dbPath;

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir'], $_SERVER['REQUEST_URI']);
	}

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		unset($_SERVER['HTTP_HOST']);
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$this->dbPath = __DIR__ . '/../data_test/database_test.js';
	}

	private function reload(): object
	{
		return json_decode(file_get_contents($this->dbPath));
	}

	public function testSubpageUnderLegacyParentIsCreatedAndPersisted(): void
	{
		// A page from before 3.2.0 has no 'subpages' property at all
		$legacy = new stdClass();
		$legacy->title = 'Legacy';
		$legacy->keywords = '';
		$legacy->description = '';
		$legacy->content = 'old';
		$db = $this->reload();
		$db->pages->legacy = $legacy;
		file_put_contents($this->dbPath, json_encode($db, JSON_PRETTY_PRINT));
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();

		$wcms->createPage(['legacy', 'child'], false);

		$db = $this->reload();
		$this->assertTrue(isset($db->pages->legacy->subpages->child), 'child must be attached to the legacy parent');
		$this->assertSame('Child', $db->pages->legacy->subpages->child->title);
		$this->assertFalse(isset($db->pages->{'legacy/child'}), 'no top-level duplicate');
	}

	public function testSavingUnderAMissingParentPageChangesNothing(): void
	{
		wcmsAdminSession($this->wcms);
		$this->wcms->loginStatus();
		$this->wcms->currentPage = 'child';
		$this->wcms->currentPageTree = ['missing', 'child'];
		$this->wcms->currentPageExists = false;
		$before = file_get_contents($this->dbPath);
		$_POST = ['fieldname' => 'content', 'content' => 'x', 'target' => 'pages', 'token' => $this->wcms->getToken()];

		$this->wcms->saveAction();

		$this->assertSame($before, file_get_contents($this->dbPath), 'no page and no stray top-level entry');
		$this->assertStringContainsString('parent page', $_SESSION['alert']['danger'][0]['message'] ?? '');
	}

	public function testAnUnknownFirstSegmentIsAMissingPageNotTheTopLevelPageOfTheSecond(): void
	{
		$this->wcms->createPage(['diary'], true);
		$this->wcms->createPage(['games'], true);
		$this->wcms->createPage(['games', 'board'], true);
		$visitor = new Wcms('data_test', 'files_test', 'database_test.js');
		foreach ([$this->wcms, $visitor] as $wcms) {
			$this->assertNull($wcms->getPageData('missing/diary'), 'it served diary before');
			$this->assertNull($wcms->getPageData('missing/games/board'));
			$this->assertNotNull($wcms->getPageData('diary'));
			$this->assertNotNull($wcms->getPageData('games/board'));
		}

		wcmsAdminSession($this->wcms);
		$this->wcms->loginStatus();
		$before = file_get_contents($this->dbPath);
		foreach ([['missing', 'diary'], ['missing', 'games', 'child']] as $tree) {
			unset($_SESSION['alert']);
			$this->wcms->currentPageTree = $tree;
			$this->wcms->currentPage = end($tree);
			$this->wcms->currentPageExists = !empty($this->wcms->getPageData(implode('/', $tree)));
			$_POST = ['fieldname' => 'content', 'content' => 'x', 'target' => 'pages', 'token' => $this->wcms->getToken()];
			$this->wcms->saveAction(); // missing/diary was a TypeError (HTTP 500) in updatePage()
			$this->assertSame($before, file_get_contents($this->dbPath), implode('/', $tree));
			$this->assertStringContainsString('parent page', $_SESSION['alert']['danger'][0]['message'] ?? '', implode('/', $tree));
		}
	}

	public function testLeadingAndTrailingSlashesInThePageParameterResolveLikeThePlainSlug(): void
	{
		// Caddy's try_files sends ?page={path}, which starts with a slash; since only the first segment is looked up at the
		// top level, /home would otherwise be the 404 page for a visitor.
		$this->wcms->createPage(['games'], true);
		$this->wcms->createPage(['games', 'board'], true);
		foreach (['home' => ['home', ['home']], '/home' => ['home', ['home']], 'home/' => ['home', ['home']], '/games/board/' => ['board', ['games', 'board']], '/' => ['home', ['home']]] as $param => [$page, $tree]) {
			$visitor = new Wcms('data_test', 'files_test', 'database_test.js');
			$_GET = ['page' => $param];
			$visitor->pageStatus();
			$this->assertSame($page, $visitor->currentPage, $param);
			$this->assertSame($tree, $visitor->currentPageTree, $param);
			$this->assertTrue($visitor->currentPageExists, $param . ' is not the 404 page');
		}
		$_GET = ['page' => '/loginURL'];
		$this->assertSame('loginURL', $this->wcms->parseUrl(), 'the login URL too');
	}

	public function testASubfolderInstallWhoseRewritePassesTheFolderInThePageParameterStillResolves(): void
	{
		// nginx `try_files $uri $uri/ /sub/index.php?page=$uri` sends the whole path, sub/about; 3.6.0 served about there
		// through its first-segment lookup. A folder .htaccess sends the part after the folder, which is never stripped.
		$this->wcms->createPage(['games'], true);
		$this->wcms->createPage(['games', 'board'], true);
		$this->wcms->createPage(['sub'], true);
		$this->wcms->createPage(['sub', 'x'], true);
		$_SERVER['SCRIPT_NAME'] = '/sub/index.php';
		$cases = [ // [page=, REQUEST_URI, currentPage, currentPageTree, exists]
			['sub/home', '/sub/home', 'home', ['home'], true],
			['sub/home', '/sub/h%6Fme', 'home', ['home'], true], // nginx decodes $uri; REQUEST_URI stays encoded
			['sub/страница', '/sub/%D1%81%D1%82%D1%80%D0%B0%D0%BD%D0%B8%D1%86%D0%B0', 'страница', ['страница'], false],
			['home', '/sub/home', 'home', ['home'], true], // folder .htaccess
			['/sub/games/board/', '/sub/games/board/?x=1', 'board', ['games', 'board'], true],
			['sub/sub', '/sub/sub', 'sub', ['sub'], true], // a page named like the folder
			['sub', '/sub/sub', 'sub', ['sub'], true],
			['sub/x', '/sub/x', 'x', ['x'], false], // the whole path: x of the install, not the folder-named page's subpage
			['sub/x', '/sub/sub/x', 'x', ['sub', 'x'], true], // folder .htaccess: the folder-named page's subpage
			['sub/sub/x', '/sub/sub/x', 'x', ['sub', 'x'], true],
			['sub/sub/sub/x', '/sub/sub/sub/x', 'x', ['sub', 'sub', 'x'], false], // once, not twice
			['sub/loginURL', '/sub/loginURL', 'loginURL', ['loginURL'], false],
			['sub/home', '/sub/index.php?page=sub/home', 'home', ['sub', 'home'], false], // no rewrite: page= is taken as given
			['sub/home', null, 'home', ['sub', 'home'], false], // no REQUEST_URI (CLI): nothing stripped
		];
		try {
			foreach ($cases as [$param, $uri, $page, $tree, $exists]) {
				if ($uri === null) {
					unset($_SERVER['REQUEST_URI']);
				} else {
					$_SERVER['REQUEST_URI'] = $uri;
				}
				$visitor = new Wcms('data_test', 'files_test', 'database_test.js');
				$_GET = ['page' => $param];
				$visitor->pageStatus();
				$this->assertSame($page, $visitor->currentPage, $param . ' at ' . $uri);
				$this->assertSame($tree, $visitor->currentPageTree, $param . ' at ' . $uri);
				$this->assertSame($exists, $visitor->currentPageExists, $param . ' at ' . $uri);
			}
			$this->wcms->createPage(['x'], true);
			$this->wcms->set('pages', 'sub', 'private', true); // the folder-named page private; a public top-level x exists too
			foreach (['sub/x' => '/sub/sub/x', 'sub/sub/x' => '/sub/sub/x'] as $param => $uri) {
				$_SERVER['REQUEST_URI'] = $uri;
				$visitor = new Wcms('data_test', 'files_test', 'database_test.js');
				$_GET = ['page' => $param];
				$visitor->pageStatus();
				$this->assertSame(['sub', 'x'], $visitor->currentPageTree, $param . ': a private sub/x is never the public x');
				$this->assertNull($visitor->getCurrentPageData(), $param . ': the visitor gets the 404 page');
				$this->assertFalse($visitor->currentPageExists);
			}

			$_SERVER['SCRIPT_NAME'] = '/index.php';
			$_SERVER['REQUEST_URI'] = '/sub/home';
			$_GET = ['page' => 'sub/home'];
			$visitor = new Wcms('data_test', 'files_test', 'database_test.js');
			$visitor->pageStatus();
			$this->assertSame(['sub', 'home'], $visitor->currentPageTree, 'an install at the web root strips nothing');
			$this->assertFalse($visitor->currentPageExists);
		} finally {
			$_SERVER['SCRIPT_NAME'] = '/index.php';
			unset($_SERVER['REQUEST_URI']);
			$_GET = [];
		}
	}

	public function testAFolderHtaccessInstallNamedLikeAPluginRouteOrAPageStripsNothing(): void
	{
		// A site in /blog/ with Simple Blog: /blog/blog/hello-world is the plugin's virtual URL, page=blog/hello-world.
		$_SERVER['SCRIPT_NAME'] = '/blog/index.php';
		try {
			$_SERVER['REQUEST_URI'] = '/blog/blog/hello-world';
			$_GET = ['page' => 'blog/hello-world'];
			$visitor = new Wcms('data_test', 'files_test', 'database_test.js');
			$visitor->pageStatus();
			$this->assertSame(['blog', 'hello-world'], $visitor->currentPageTree, 'the plugin sees its own URL');
			$this->assertSame('hello-world', $visitor->currentPage);

			$this->wcms->createPage(['blog'], true);
			wcmsAdminSession($this->wcms);
			$this->wcms->loginStatus();
			$_SERVER['REQUEST_URI'] = '/blog/blog/sub';
			$_GET = ['page' => 'blog/sub'];
			$this->wcms->pageStatus();
			$_POST = ['fieldname' => 'content', 'content' => 'new subpage', 'target' => 'pages', 'token' => $this->wcms->getToken()];
			$this->wcms->saveAction();
			$db = $this->reload();
			$this->assertSame('new subpage', $db->pages->blog->subpages->sub->content ?? null, 'created as blog/sub');
			$this->assertFalse(isset($db->pages->sub), 'no top-level sub');
		} finally {
			$_SERVER['SCRIPT_NAME'] = '/index.php';
			unset($_SERVER['REQUEST_URI']);
			$_GET = $_POST = [];
		}
	}

	public function testUrlOnWindowsDoesNotStartWithDoubleSlash(): void
	{
		$_SERVER['SCRIPT_NAME'] = '\\index.php';
		$this->assertSame('http://wondercms.doc/about', Wcms::url('about'));

		$_SERVER['SCRIPT_NAME'] = '/sub/index.php';
		$this->assertSame('http://wondercms.doc/sub/about', Wcms::url('about'));

		$_SERVER['SCRIPT_NAME'] = '/.cms/index.php';
		$this->assertSame('http://wondercms.doc/.cms/about', Wcms::url('about'), 'a folder name starting with a dot is kept');

		$_SERVER['SCRIPT_NAME'] = 'index.php';
		$this->assertSame('http://wondercms.doc/about', Wcms::url('about'), "dirname('index.php') is '.'");

		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['HTTP_HOST'] = 'example.org:8080';
		$this->assertSame('http://example.org:8080/about', Wcms::url('about'));
	}

	public function testUrlUsesServerPortOnlyWithoutHostHeader(): void
	{
		$_SERVER['SERVER_PORT'] = '8080';
		$this->assertSame('http://wondercms.doc:8080/', Wcms::url());
	}

	public function testViewingAnOldSubpageWritesNoTopLevelDuplicate(): void
	{
		$db = $this->reload();
		$db->pages->parent = (object) ['title' => 'Parent', 'keywords' => '', 'description' => '', 'content' => '',
			'subpages' => (object) ['child' => (object) ['title' => 'Child', 'keywords' => '', 'description' => '', 'content' => 'x']]];
		file_put_contents($this->dbPath, json_encode($db, JSON_PRETTY_PRINT));
		$before = md5_file($this->dbPath);
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();

		$page = $wcms->getPageData('parent/child');

		$this->assertSame('show', $page->visibility);
		$this->assertSame($before, md5_file($this->dbPath), 'a page view must not write database.js');
		$this->assertFalse(isset($this->reload()->pages->{'parent/child'}));
	}

	public function testLastPageIsNotDeletedAndStaleChildKeepsSiblings(): void
	{
		$_REQUEST['token'] = $this->wcms->getToken();
		$_SESSION['loggedIn'] = true;
		$this->wcms->loggedIn = true;
		$this->assertTrue($this->wcms->verifyFormActions(true), 'the test must reach deletePageAction()');

		$_GET['delete'] = 'how-to';
		$this->wcms->deletePageAction();
		$this->assertFalse(isset($this->reload()->pages->{'how-to'}));
		$_GET['delete'] = 'home';
		$this->wcms->deletePageAction();
		$this->assertTrue(isset($this->reload()->pages->home), 'the last page keeps its content');
		$this->assertCount(1, (array) $this->reload()->config->menuItems);

		$this->wcms->createPage(['games'], true);
		$this->wcms->createPage(['games', 'board'], true);
		$this->wcms->createPage(['games', 'table'], true);
		$_GET['delete'] = 'games/does-not-exist';
		$this->wcms->deletePageAction();
		$menu = $this->reload()->config->menuItems;
		$games = null;
		foreach ($menu as $item) {
			if ($item->slug === 'games') {
				$games = $item;
			}
		}
		$this->assertNotNull($games);
		$this->assertCount(2, (array) $games->subpages, 'a stale child slug must not remove a sibling');
		unset($_GET['delete'], $_REQUEST['token'], $_SESSION['loggedIn']);
	}

	public function testMovingTheLastMenuItemPastTheEndChangesNothing(): void
	{
		// The UI hides the arrow; a crafted request used to swap the item with a missing neighbour and store null, after which every page fataled.
		$items = (array)$this->wcms->get('config', 'menuItems');
		$last = (string)max(array_keys($items));
		$before = json_encode($this->wcms->get('config', 'menuItems'));

		$this->wcms->orderMenuItem(1, $last);
		$this->assertSame($before, json_encode($this->wcms->get('config', 'menuItems')), 'moving the last item down is ignored');
		$this->wcms->orderMenuItem(-1, '0');
		$this->assertSame($before, json_encode($this->wcms->get('config', 'menuItems')), 'moving the first item up is ignored');
		$this->wcms->orderMenuItem(1, '');
		$this->assertSame($before, json_encode($this->wcms->get('config', 'menuItems')), 'an empty key is ignored');
		$this->assertNotContains(null, (array)$this->wcms->get('config', 'menuItems'));

		$this->wcms->orderMenuItem(1, '0');
		$this->assertNotSame($before, json_encode($this->wcms->get('config', 'menuItems')), 'a real neighbour swap still happens');
	}

	public function testRenamingThroughACraftedMenuKeyChangesNothing(): void
	{
		$before = file_get_contents($this->dbPath);
		foreach (['99-7', 'a-b', '99', '0-99'] as $menu) {
			unset($_SESSION['alert']);
			$this->wcms->updateMenuItem('Renamed', $menu, 'show');
			$this->assertSame('Page not found.', $_SESSION['alert']['danger'][0]['message'] ?? '', $menu);
			$this->assertSame($before, file_get_contents($this->dbPath), $menu . ': database.js byte-identical');
		}
	}

	public function testAddingASubpageUnderACraftedParentKeyChangesNothing(): void
	{
		$before = file_get_contents($this->dbPath);
		foreach (['99-7', 'a-b', '99', '0-99'] as $menu) {
			unset($_SESSION['alert']);
			$this->wcms->createMenuItem('New page', $menu, 'show', true);
			$this->assertSame('Page not found.', $_SESSION['alert']['danger'][0]['message'] ?? '', $menu);
			$this->assertSame($before, file_get_contents($this->dbPath), $menu . ': database.js byte-identical');
		}
	}

	public function testShowingOrHidingThroughACraftedMenuKeyChangesNothing(): void
	{
		$before = file_get_contents($this->dbPath);
		foreach (['99-7', 'a-b', '99', '0-99'] as $menu) {
			unset($_SESSION['alert']);
			$this->wcms->updateMenuItemVisibility('hide', $menu);
			$this->assertSame('Page not found.', $_SESSION['alert']['danger'][0]['message'] ?? '', $menu);
			$this->assertSame($before, file_get_contents($this->dbPath), $menu . ': database.js byte-identical');
		}
	}

	public function testDeletingASubpageLeavesNoGapSoTheNextSubpageDoesNotReplaceASibling(): void
	{
		// Only the top level was renumbered after a delete; a gap in a parent's subpages made createMenuItem() reuse the key of the last sibling.
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Parent', '', 'show', true);
		$parentKey = (string)max(array_keys((array)$this->wcms->get('config', 'menuItems')));
		foreach (['Sub A', 'Sub B', 'Sub C'] as $name) {
			$this->wcms->createMenuItem($name, $parentKey, 'show', true);
		}
		$_GET['delete'] = 'parent/sub-b';
		$_REQUEST['token'] = $this->wcms->getToken();
		$this->wcms->deletePageAction();
		$this->wcms->createMenuItem('Sub D', $parentKey, 'show', true);

		$subpages = (array)$this->wcms->get('config', 'menuItems')->{$parentKey}->subpages;
		$this->assertSame([0, 1, 2], array_keys($subpages), 'keys are contiguous after the delete');
		$this->assertSame(['Sub A', 'Sub C', 'Sub D'], array_column($subpages, 'name'), 'the surviving sibling keeps its menu entry');
		unset($_GET['delete'], $_REQUEST['token']);
	}

	public function testAddingASubpageToAMenuWithAKeyGapKeepsEverySibling(): void
	{
		// A database written by an older version can carry a gap (keys 0 and 2); createMenuItem() used count() as the new key.
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Parent', '', 'show', true);
		$parentKey = (string)max(array_keys((array)$this->wcms->get('config', 'menuItems')));
		foreach (['Sub A', 'Sub B', 'Sub C'] as $name) {
			$this->wcms->createMenuItem($name, $parentKey, 'show', true);
		}
		$this->wcms->createMenuItem('Sub D', $parentKey, 'show', true);
		$this->wcms->createMenuItem('Sub E', $parentKey, 'show', true);
		$db = $this->reload();
		unset($db->config->menuItems->{$parentKey}->subpages->{'1'}, $db->config->menuItems->{$parentKey}->subpages->{'2'}); // two gaps: keys 0, 3, 4 remain
		file_put_contents($this->dbPath, json_encode($db, JSON_PRETTY_PRINT));
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->loggedIn = true;

		$wcms->createMenuItem('Sub F', $parentKey, 'show', true);

		$subpages = (array)$wcms->get('config', 'menuItems')->{$parentKey}->subpages;
		$this->assertSame(['Sub A', 'Sub D', 'Sub E', 'Sub F'], array_values(array_column($subpages, 'name')), 'every surviving sibling keeps its menu entry');
		$this->assertSame([0, 3, 4, 5], array_keys($subpages), 'the new item takes the first free key past every gap');
	}

	public function testDeletingAPageWithAStaleSlugPathChangesNoMenuEntry(): void
	{
		// A path whose first segment matches nothing must not remove a same-named top-level item found later in the tree.
		$this->wcms->loggedIn = true;
		$before = json_encode($this->wcms->get('config', 'menuItems'));
		$_GET['delete'] = 'nope/home';
		$_REQUEST['token'] = $this->wcms->getToken();
		set_error_handler(static function (): bool { return true; }); // deletePageFromDb() warns about the missing parent (pre-existing, crafted input only); this test is about the menu
		$this->wcms->deletePageAction();
		restore_error_handler();
		$this->assertSame($before, json_encode($this->wcms->get('config', 'menuItems')));
		unset($_GET['delete'], $_REQUEST['token']);
	}

	public function testTheSameSubpageNameThreeTimesGivesThreeSiblingsWithDistinctSlugs(): void
	{
		// createUniqueSlug() appended the top-level count once and never rechecked: the third add saved a second menu entry with the second's slug.
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Parent', '', 'show', true);
		$parentKey = (string)max(array_keys((array)$this->wcms->get('config', 'menuItems')));
		for ($i = 0; $i < 3; $i++) {
			$this->wcms->createMenuItem('Same', $parentKey, 'show', true);
		}

		$this->assertSame(['same', 'same-2', 'same-3'], array_column((array)$this->wcms->get('config', 'menuItems')->{$parentKey}->subpages, 'slug'));
		$this->assertSame(['same', 'same-2', 'same-3'], array_keys((array)$this->reload()->pages->parent->subpages), 'one page per menu entry');
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
	}

	public function testTheSameNameThreeTimesOnAThirdLevelGivesDistinctSlugs(): void
	{
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Parent', '', 'show', true);
		$parentKey = (string)max(array_keys((array)$this->wcms->get('config', 'menuItems')));
		$this->wcms->createMenuItem('Child', $parentKey, 'show', true);
		for ($i = 0; $i < 3; $i++) {
			$this->wcms->createMenuItem('Deep', $parentKey . '-0', 'show', true);
		}

		$this->assertSame(['deep', 'deep-2', 'deep-3'], array_column((array)$this->wcms->get('config', 'menuItems')->{$parentKey}->subpages->{'0'}->subpages, 'slug'));
		$this->assertSame(['deep', 'deep-2', 'deep-3'], array_keys((array)$this->reload()->pages->parent->subpages->child->subpages));
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
	}

	public function testATopLevelDuplicateNeverTakesTheSlugOfAnExistingPage(): void
	{
		// At the top level the appended count grows with every add, so it collided only when the generated -N already existed; createPage() then overwrote that page.
		$this->wcms->loggedIn = true;
		for ($i = 0; $i < 3; $i++) {
			$this->wcms->createMenuItem('Same', '', 'show', true);
		}
		$n = count((array)$this->wcms->get('config', 'menuItems')) + 2;
		$this->wcms->createMenuItem('Clash', '', 'show', true);
		$this->wcms->createMenuItem('Clash ' . $n, '', 'show', true);
		$this->wcms->set('pages', 'clash-' . $n, 'content', 'kept');
		$this->wcms->createMenuItem('Clash', '', 'show', true);

		$slugs = array_column((array)$this->wcms->get('config', 'menuItems'), 'slug');
		$this->assertSame('kept', $this->reload()->pages->{'clash-' . $n}->content ?? null, 'the existing page keeps its content');
		$this->assertSame(['clash', 'clash-' . $n, 'clash-2'], array_values(preg_grep('/^clash/', $slugs)));
		$this->assertSame(['same', 'same-2', 'same-3'], array_values(preg_grep('/^same/', $slugs)));
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
	}

	public function testANameThatAlreadyEndsInANumberStaysDistinctFromTheGeneratedSlugs(): void
	{
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Parent', '', 'show', true);
		$parentKey = (string)max(array_keys((array)$this->wcms->get('config', 'menuItems')));
		foreach (['Item', 'Item 2', 'Item 3', 'Item', 'Item', 'Item 2'] as $name) {
			$this->wcms->createMenuItem($name, $parentKey, 'show', true);
		}

		$this->assertSame(['item', 'item-2', 'item-3', 'item-4', 'item-5', 'item-2-2'], array_column((array)$this->wcms->get('config', 'menuItems')->{$parentKey}->subpages, 'slug'));
		$this->assertCount(6, (array)$this->reload()->pages->parent->subpages);
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
	}

	public function testTheSameSubpageNameUnderTheFirstMenuItemGivesDistinctSlugs(): void
	{
		// The first top-level item has the key '0', which PHP reads as false: createUniqueSlug() checked the top level instead of its subpages.
		$this->wcms->loggedIn = true;
		for ($i = 0; $i < 3; $i++) {
			$this->wcms->createMenuItem('Same', '0', 'show', true);
		}

		$this->assertSame(['same', 'same-2', 'same-3'], array_column((array)$this->wcms->get('config', 'menuItems')->{'0'}->subpages, 'slug'));
		$this->assertSame(['same', 'same-2', 'same-3'], array_keys((array)$this->reload()->pages->home->subpages), 'one page per menu entry');
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
	}

	public function testRenamingAPageToASiblingsNameKeepsBothPages(): void
	{
		// updateMenuItem() checked the renamed item's children instead of its siblings, so the rename took the sibling's slug and replaced its page.
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Alpha', '', 'show', true);
		$this->wcms->createMenuItem('Beta', '', 'show', true);
		$this->wcms->set('pages', 'alpha', 'content', 'alpha content');
		$this->wcms->set('pages', 'beta', 'content', 'beta content');
		$betaKey = (string)max(array_keys((array)$this->wcms->get('config', 'menuItems')));

		$this->wcms->updateMenuItem('Alpha', $betaKey, 'show');

		$slugs = array_column((array)$this->wcms->get('config', 'menuItems'), 'slug');
		$this->assertSame(['alpha', 'alpha-2'], array_values(preg_grep('/^alpha/', $slugs)));
		$pages = $this->reload()->pages;
		$this->assertSame('alpha content', $pages->alpha->content ?? null, 'the sibling keeps its page');
		$this->assertSame('beta content', $pages->{'alpha-2'}->content ?? null, 'the renamed page moved to the free slug');
		$this->assertFalse(isset($pages->beta), 'the old slug is gone');
	}

	public function testRenamingASubpageOfTheFirstPageToASiblingsNameKeepsBothPages(): void
	{
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Alpha', '0', 'show', true);
		$this->wcms->createMenuItem('Beta', '0', 'show', true);
		$this->wcms->set('pages', 'home', 'subpages', 'alpha', 'content', 'alpha content');
		$this->wcms->set('pages', 'home', 'subpages', 'beta', 'content', 'beta content');

		$this->wcms->updateMenuItem('Alpha', '0-1', 'show');

		$this->assertSame(['alpha', 'alpha-2'], array_column((array)$this->wcms->get('config', 'menuItems')->{'0'}->subpages, 'slug'));
		$subpages = $this->reload()->pages->home->subpages;
		$this->assertSame('alpha content', $subpages->alpha->content ?? null, 'the sibling keeps its page');
		$this->assertSame('beta content', $subpages->{'alpha-2'}->content ?? null, 'the renamed page moved to the free slug');
	}

	public function testRenamingAPageToANameWithItsOwnSlugKeepsTheSlugAndThePage(): void
	{
		// The item must not count as its own sibling: "How to!" slugifies to how-to and must not become how-to-2.
		$this->wcms->loggedIn = true;
		$this->wcms->set('pages', 'how-to', 'content', 'kept');

		$this->wcms->updateMenuItem('How to!', '1', 'show');

		$item = $this->wcms->get('config', 'menuItems')->{'1'};
		$this->assertSame('How to!', $item->name);
		$this->assertSame('how-to', $item->slug);
		$this->assertSame('kept', $this->reload()->pages->{'how-to'}->content ?? null, 'the page survives a rename that keeps its slug');
	}

	public function testAddingAMenuPageNamedLikeATopLevelPageWithoutAMenuEntryOverwritesNothing(): void
	{
		// createPage() had no clash check for a one-segment slug, and the slug was checked against menu entries only: a menu page named 404 replaced the 404 page.
		$this->wcms->loggedIn = true;
		$page404 = json_encode($this->reload()->pages->{'404'});

		$this->wcms->createMenuItem('404', '', 'show', true);

		$this->assertSame($page404, json_encode($this->reload()->pages->{'404'}), 'the existing page is untouched');
		$this->assertContains('404-2', array_column((array)$this->reload()->config->menuItems, 'slug'));
		$this->assertNotContains('404', array_column((array)$this->reload()->config->menuItems, 'slug'), 'no menu entry takes the unlisted page');
		$this->assertTrue(isset($this->reload()->pages->{'404-2'}), 'the new page has its own slug');
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
		$this->assertSame('404-2', $_SESSION['redirect_to'] ?? null);
	}

	public function testAddingASubpageNamedLikeAnUnlistedSubpageGetsAFreeSlug(): void
	{
		$this->wcms->loggedIn = true;
		$this->wcms->createPage(['home', 'unlisted']);
		$this->wcms->set('pages', 'home', 'subpages', 'unlisted', 'content', 'kept');

		$this->wcms->createMenuItem('Unlisted', '0', 'show', true);

		$this->assertSame('kept', $this->reload()->pages->home->subpages->unlisted->content ?? null);
		$this->assertSame(['unlisted-2'], array_column((array)$this->reload()->config->menuItems->{'0'}->subpages, 'slug'));
		$this->assertTrue(isset($this->reload()->pages->home->subpages->{'unlisted-2'}));
		$this->assertArrayNotHasKey('danger', $_SESSION['alert'] ?? []);
	}

	public function testRenamingAPageToTheNameOfAPageWithoutAMenuEntryOverwritesNothing(): void
	{
		$this->wcms->loggedIn = true;
		$this->wcms->createMenuItem('Beta', '', 'show', true);
		$this->wcms->set('pages', 'beta', 'content', 'beta content');
		$betaKey = (string)max(array_keys((array)$this->wcms->get('config', 'menuItems')));
		$page404 = json_encode($this->reload()->pages->{'404'});

		$this->wcms->updateMenuItem('404', $betaKey, 'show');

		$pages = $this->reload()->pages;
		$this->assertSame($page404, json_encode($pages->{'404'}), 'the unlisted page is untouched');
		$this->assertSame('404-2', $this->wcms->get('config', 'menuItems')->{$betaKey}->slug);
		$this->assertSame('beta content', $pages->{'404-2'}->content ?? null, 'the renamed page moved to the free slug');
	}

	public function testCreatePageRefusesAnExistingTopLevelPage(): void
	{
		$this->wcms->loggedIn = true;
		$page404 = json_encode($this->reload()->pages->{'404'});

		$this->wcms->createPage(['404']);

		$this->assertSame($page404, json_encode($this->reload()->pages->{'404'}));
		$this->assertStringContainsString('Cannot create page with existing slug.', json_encode($_SESSION['alert']['danger'] ?? []));
	}

	public function testACreateThatIsRefusedSavesNoMenuEntry(): void
	{
		// The page is validated before the menu entry is saved; the slug is forced onto an existing page to reach the refusal.
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'uniqueSlugAmong'])->getMock();
		$wcms->method('uniqueSlugAmong')->willReturn('404');
		$wcms->loggedIn = true;
		$before = json_encode($this->reload()->config->menuItems);
		$page404 = json_encode($this->reload()->pages->{'404'});
		unset($_SESSION['redirect_to'], $_SESSION['redirect_to_name']);

		$wcms->createMenuItem('Anything', '', 'show', true);

		$this->assertSame($before, json_encode($this->reload()->config->menuItems), 'no menu entry for a refused page');
		$this->assertSame($page404, json_encode($this->reload()->pages->{'404'}));
		$this->assertStringContainsString('Cannot create page with existing slug.', json_encode($_SESSION['alert']['danger'] ?? []));
		$this->assertArrayNotHasKey('redirect_to', $_SESSION, 'no "page created" redirect');
	}

	public function testSavingAMissingPageLinksAMenuEntryWithTheSameSlug(): void
	{
		// createPage(..., true) writes the page, then links its menu entry: the page it just wrote must not push the entry to -2.
		wcmsAdminSession($this->wcms);
		$this->wcms->loginStatus();
		$this->wcms->currentPage = 'fresh';
		$this->wcms->currentPageTree = ['fresh'];
		$this->wcms->currentPageExists = false;
		unset($_SESSION['redirect_to'], $_SESSION['redirect_to_name']);
		$_POST = ['fieldname' => 'content', 'content' => 'x', 'target' => 'pages', 'token' => $this->wcms->getToken()];

		$this->wcms->saveAction();

		$db = $this->reload();
		$this->assertSame('x', $db->pages->fresh->content ?? null);
		$this->assertSame(['fresh'], array_values(preg_grep('/^fresh/', array_column((array)$db->config->menuItems, 'slug'))));
		$this->assertFalse(isset($db->pages->{'fresh-2'}));
	}

	public function testTheRedirectAfterARenameIsThePagesRealAddress(): void
	{
		// save() sent the editor to slugify(name): no parent path and no generated suffix, so the redirect missed the renamed page.
		wcmsAdminSession($this->wcms);
		$this->wcms->loginStatus();
		$this->wcms->createMenuItem('Alpha', '0', 'show', true);
		$this->wcms->createMenuItem('Beta', '0', 'show', true);
		unset($_SESSION['redirect_to'], $_SESSION['redirect_to_name']);
		$_POST = ['fieldname' => 'menuItem', 'content' => 'Alpha', 'target' => 'menuItemUpdate', 'menu' => '0-1', 'visibility' => 'show', 'token' => $this->wcms->getToken()];

		$this->wcms->saveAction();

		$this->assertSame('home/alpha-2', $_SESSION['redirect_to'] ?? null);
		$this->assertTrue(isset($this->reload()->pages->home->subpages->{'alpha-2'}), 'the redirect target exists');

		unset($_SESSION['redirect_to'], $_SESSION['redirect_to_name']);
		$_POST = ['fieldname' => 'menuItem', 'content' => 'Renamed', 'target' => 'menuItemUpdate', 'menu' => '9-9', 'visibility' => 'show', 'token' => $this->wcms->getToken()];
		$this->wcms->saveAction();
		$this->assertArrayNotHasKey('redirect_to', $_SESSION, 'a rename that did not happen queues no redirect');
	}

	public function testRemoveRefusesANameThatTheCleaningWouldChange(): void
	{
		// A hand-uploaded folder named mytheme~ is listed with a Remove button; the cleaned name would have deleted mytheme.
		$name = 'guard-' . bin2hex(random_bytes(3));
		mkdir(__DIR__ . '/../themes/' . $name);
		file_put_contents(__DIR__ . '/../themes/' . $name . '/theme.php', '<?php');
		mkdir(__DIR__ . '/../themes/' . $name . '~');
		file_put_contents(__DIR__ . '/../themes/' . $name . '~/theme.php', '<?php');
		$this->wcms->method('redirect')->willThrowException(new RuntimeException('redirected'));
		$this->wcms->loggedIn = true;
		$_REQUEST = ['deleteModule' => $name . '~', 'type' => 'themes', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->deleteFileModuleAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertSame('Invalid file name.', $_SESSION['alert']['danger'][0]['message'] ?? '');
		}
		$this->assertDirectoryExists(__DIR__ . '/../themes/' . $name, 'the look-alike folder is untouched');
		$this->assertDirectoryExists(__DIR__ . '/../themes/' . $name . '~', 'the odd folder is untouched');

		// A leading space is trimmed by the cleaning too; the request is compared untrimmed.
		unset($_SESSION['alert']);
		$_REQUEST = ['deleteModule' => ' ' . $name, 'type' => 'themes', 'token' => $this->wcms->getToken()];
		try {
			$this->wcms->deleteFileModuleAction();
			$this->fail('no redirect');
		} catch (RuntimeException $e) {
			$this->assertSame('Invalid file name.', $_SESSION['alert']['danger'][0]['message'] ?? '');
		}
		$this->assertDirectoryExists(__DIR__ . '/../themes/' . $name);

		// The Remove and Activate links carry the folder name URL-encoded, so `victim&copy` cannot arrive as `victim`.
		mkdir(__DIR__ . '/../themes/' . $name . '&copy');
		file_put_contents(__DIR__ . '/../themes/' . $name . '&copy/theme.php', '<?php');
		$html = $this->wcms->settings();
		$this->assertStringContainsString('deleteModule=' . $name . '%26copy&type=themes', $html);
		$this->assertStringContainsString('selectModule=' . $name . '%26copy&type=themes', $html);
		$this->assertStringNotContainsString('deleteModule=' . $name . '&copy&', $html);
		mkdir(__DIR__ . '/../plugins/' . $name . '&copy');
		file_put_contents(__DIR__ . '/../plugins/' . $name . '&copy/' . $name . '&copy.php', '<?php');
		$html = $this->wcms->settings();
		$this->assertStringContainsString('togglePlugin=' . $name . '%26copy&state=', $html, 'the Disable link carries the plugin name URL-encoded');
		$this->assertStringContainsString('data-confirm="Remove ' . $name . '&amp;copy?" onclick="return confirm(this.dataset.confirm)"', $html, 'the confirm text is an attribute value, never JS source (a quote in a name used to break the handler and skip the confirm)');
		$this->assertStringNotContainsString("confirm('Remove", $html);
		$this->assertStringContainsString('data-confirm="Activate ' . $name . '&amp;copy theme?" onclick="return confirm(this.dataset.confirm)"', $html, 'the Activate confirm text is an attribute value too');
		$this->assertMatchesRegularExpression('#<h4>sky</h4>#i', $html, 'Sky is listed (anchors the next assertion; the folder scan prints the folder name when the official list is offline)');
		$this->assertStringNotContainsString('deleteModule=sky&type=themes', $html, 'Sky, the fallback, gets no Remove button (the action refuses it anyway)');
		$this->wcms->recursiveDelete(__DIR__ . '/../plugins/' . $name . '&copy');
		$this->wcms->recursiveDelete(__DIR__ . '/../themes/' . $name . '&copy');
		$this->wcms->recursiveDelete(__DIR__ . '/../themes/' . $name);
		$this->wcms->recursiveDelete(__DIR__ . '/../themes/' . $name . '~');
		$_REQUEST = [];
	}

	public function testDeletingAModuleRemovesSymlinksInsideItWithoutFollowingThem(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		$root = __DIR__ . '/../data_test';
		$outside = $root . '/outside-' . bin2hex(random_bytes(3));
		mkdir($outside, 0777, true);
		file_put_contents($outside . '/keep.txt', 'x');
		$plugin = __DIR__ . '/../plugins/doomed-' . bin2hex(random_bytes(3));
		mkdir($plugin . '/sub', 0777, true);
		file_put_contents($plugin . '/' . basename($plugin) . '.php', '<?php');
		symlink($outside, $plugin . '/sub/link-to-outside');
		symlink($outside . '/keep.txt', $plugin . '/link-to-file');

		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->recursiveDelete($plugin);

		$this->assertDirectoryDoesNotExist($plugin, 'the module folder is gone');
		$this->assertFileExists($outside . '/keep.txt', 'the symlink target outside the folder is untouched');
		unlink($outside . '/keep.txt');
		rmdir($outside);
	}

	public function testDeletingAModuleThatIsItselfASymlinkRemovesTheLinkOnly(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		$outside = __DIR__ . '/../data_test/shared-' . bin2hex(random_bytes(3));
		mkdir($outside, 0777, true);
		file_put_contents($outside . '/theme.php', '<?php');
		$link = __DIR__ . '/../themes/linked-' . bin2hex(random_bytes(3));
		symlink($outside, $link);

		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->loggedIn = true;
		$_REQUEST = ['deleteModule' => basename($link), 'type' => 'themes', 'token' => $wcms->getToken()];
		$wcms->deleteFileModuleAction();

		$this->assertFalse(is_link($link) || file_exists($link), 'the link is gone');
		$this->assertFileExists($outside . '/theme.php', 'the folder the link pointed at is untouched');
		unlink($outside . '/theme.php');
		rmdir($outside);
		$_REQUEST = [];
	}

	public function testInstallingOverAModuleThatIsASymlinkReplacesTheLinkAndLeavesItsTargetAlone(): void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			$this->markTestSkipped('symlinks need privileges on Windows');
		}
		$outside = __DIR__ . '/../data_test/shared-' . bin2hex(random_bytes(3));
		mkdir($outside, 0777, true);
		file_put_contents($outside . '/theme.php', 'old');
		$name = 'linked-' . bin2hex(random_bytes(3));
		$link = __DIR__ . '/../themes/' . $name;
		symlink($outside, $link);
		$zipPath = sys_get_temp_dir() . '/wcms-' . $name . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE);
		$zip->addFromString($name . '-master/theme.php', 'new');
		$zip->close();
		@mkdir(__DIR__ . '/../data_test/files_test', 0777, true);

		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect', 'getSingleModuleCachedData'])->getMock();
		$wcms->method('getSingleModuleCachedData')->willReturn(['zip' => 'file://' . $zipPath]);
		$wcms->loggedIn = true;
		$_REQUEST = ['installModule' => $name, 'type' => 'themes', 'token' => $wcms->getToken()];
		$wcms->installUpdateModuleAction();

		$this->assertSame('old', file_get_contents($outside . '/theme.php'), 'the folder the link pointed at is untouched');
		$this->assertFalse(is_link($link), 'the link is gone');
		$this->assertSame('new', file_get_contents($link . '/theme.php'), 'the module is a real folder with the new files');
		$wcms->recursiveDelete($link);
		unlink($outside . '/theme.php');
		rmdir($outside);
		unlink($zipPath);
		$_REQUEST = [];
	}

	public function testFilesTabShowsTheFilenameAndKeepsTheFullUrlOnTheLink(): void
	{
		// #357: the visible text is the filename; the full URL stays as the link target and tooltip, so copy-link still works.
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->loggedIn = true;
		@mkdir(__DIR__ . '/../data_test/files_test', 0777, true);
		file_put_contents(__DIR__ . '/../data_test/files_test/brochure.pdf', 'x');
		file_put_contents(__DIR__ . '/../data_test/files_test/a&b.pdf', 'x'); // an FTP-placed name; the delete link must not split on the &
		$html = $wcms->settings();
		$url = Wcms::url('data/files/') . 'brochure.pdf';
		$this->assertStringContainsString('href="' . $url . '" class="normalFont" target="_blank" rel="noopener" title="' . $url . '"><b class="fontSize21">brochure.pdf</b></a>', $html);
		$this->assertStringNotContainsString('>' . Wcms::url('data/files/') . '<b', $html, 'the URL prefix is no longer printed as text');
		$this->assertStringContainsString('deleteModule=a%26b.pdf&type=files', $html, 'the delete link carries the name URL-encoded');
		$this->assertStringContainsString('data-confirm="Delete a&amp;b.pdf?" onclick="return confirm(this.dataset.confirm)"', $html, 'the confirm text is an attribute value');
		$this->assertStringContainsString('href="' . Wcms::url('data/files/') . 'a%26b.pdf" class="normalFont" target="_blank" rel="noopener" title="' . Wcms::url('data/files/') . 'a&amp;b.pdf"><b class="fontSize21">a&amp;b.pdf</b></a>', $html, 'the view link encodes the href and escapes title and text');
		$wcms->currentPageTree = ['about'];
		$this->assertStringContainsString('about/?deleteModule=brochure.pdf&type=files', $wcms->settings(), 'the delete link returns to the current page (#347)');
		unlink(__DIR__ . '/../data_test/files_test/brochure.pdf');
		unlink(__DIR__ . '/../data_test/files_test/a&b.pdf');
	}

	public function testDeletingAFileRedirectsBackToTheCurrentPage(): void
	{
		$wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$wcms->loggedIn = true;
		$wcms->currentPageTree = ['about'];
		@mkdir(__DIR__ . '/../data_test/files_test', 0777, true);
		file_put_contents(__DIR__ . '/../data_test/files_test/note.txt', 'x');
		$_REQUEST['deleteModule'] = 'note.txt';
		$_REQUEST['type'] = 'files';
		$_REQUEST['token'] = $wcms->getToken();
		$wcms->expects($this->once())->method('redirect')->with('about/');

		$wcms->deleteFileModuleAction();

		$this->assertFileDoesNotExist(__DIR__ . '/../data_test/files_test/note.txt');
		$_REQUEST = [];
	}
}
