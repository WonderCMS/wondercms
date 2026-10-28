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
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
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

	public function testUrlOnWindowsDoesNotStartWithDoubleSlash(): void
	{
		$_SERVER['SCRIPT_NAME'] = '\\index.php';
		$this->assertSame('http://wondercms.doc/about', Wcms::url('about'));

		$_SERVER['SCRIPT_NAME'] = '/sub/index.php';
		$this->assertSame('http://wondercms.doc/sub/about', Wcms::url('about'));

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
