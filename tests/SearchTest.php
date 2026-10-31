<?php

use PHPUnit\Framework\TestCase;

/**
 * #358 search must cover subpages, #359 excerpts must stay valid UTF-8, #360 legacy duplicates are skipped.
 */
final class SearchTest extends TestCase
{
	/** @var Wcms */
	private $wcms;

	protected function tearDown(): void
	{
		$_GET = $_POST = $_REQUEST = [];
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token'], $_SESSION['rootDir']);
	}

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		@unlink(__DIR__ . '/../data_test/database_test.js');
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$this->wcms->loggedIn = true;
		$this->wcms->createPage(['games'], true);
		$this->wcms->createPage(['games', 'board'], true);
		$this->wcms->createPage(['games', 'board', 'chess'], true);
		$this->wcms->createPage(['hidden-page'], true);
		$db = json_decode(file_get_contents(__DIR__ . '/../data_test/database_test.js'));
		$db->pages->games->subpages->board->content = 'Board games night';
		$db->pages->games->subpages->board->subpages->chess->content = 'Шахматы and 東京 and Café crème with “curly quotes” are played here on a very long sentence that keeps going';
		$db->pages->{'hidden-page'}->visibility = 'hide';
		$db->pages->{'hidden-page'}->content = 'secret garden';
		$db->pages->{'games/board'} = $db->pages->games->subpages->board; // legacy duplicate written by the #360 bug
		file_put_contents(__DIR__ . '/../data_test/database_test.js', json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
	}

	private function search(string $query): array
	{
		$method = new ReflectionMethod(Wcms::class, 'searchPages');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		return $method->invoke($this->wcms, $this->wcms->get('pages'), $query);
	}

	public function testFindsSubpagesAtAnyDepthWithFullSlugPath(): void
	{
		$slugs = array_column($this->search('games'), 'slug');
		$this->assertContains('games/board/', $slugs);
		$this->assertCount(1, array_keys($slugs, 'games/board/'), 'the legacy top-level duplicate must not be a second hit');
		$this->assertSame(['games/board/chess'], array_column($this->search('Шахматы'), 'slug'));
	}

	public function testAPageWithSubpagesEndsWithASlashAsInTheMenu(): void
	{
		// #407: the menu links a parent as games/board/, so a relative link on that page resolves under it; search must agree
		$this->assertSame(['games/', 'games/board/'], array_column($this->search('games'), 'slug'));
		$this->assertSame(['games/board/chess'], array_column($this->search('Шахматы'), 'slug'), 'a page without subpages keeps the bare slug');

		$db = json_decode(file_get_contents(__DIR__ . '/../data_test/database_test.js'));
		$chess = (object)['name' => 'Chess', 'slug' => 'chess', 'visibility' => 'show', 'subpages' => new stdClass()];
		$board = (object)['name' => 'Board', 'slug' => 'board', 'visibility' => 'show', 'subpages' => (object)['0' => $chess]];
		$db->config->menuItems->{'9'} = (object)['name' => 'Games', 'slug' => 'games', 'visibility' => 'show', 'subpages' => (object)['0' => $board]];
		$db->pages->{'hidden-page'}->subpages = new stdClass(); // an emptied parent: the menu writes no slash for it
		file_put_contents(__DIR__ . '/../data_test/database_test.js', json_encode($db));
		$this->wcms = $this->getMockBuilder(Wcms::class)->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])->onlyMethods(['redirect'])->getMock();
		$menu = $this->wcms->menu();
		foreach (array_column($this->search('games'), 'slug') as $slug) {
			$this->assertStringContainsString('href="' . Wcms::url($slug) . '"', $menu, 'the menu links ' . $slug . ' the same way');
		}
		$this->assertStringContainsString('href="' . Wcms::url('games/board/chess') . '"', $menu);
		$this->assertSame(['hidden-page'], array_column($this->search('secret garden'), 'slug'));
	}

	public function testThePageTheSearchRunsOnIsNotAResult(): void
	{
		// #407: a theme's search page is an ordinary page and matched its own query
		$_GET['page'] = 'games/board';
		$this->wcms->pageStatus();
		$this->assertSame([], $this->search('Board games night'));
		$this->assertSame(['games/'], array_column($this->search('games'), 'slug'), 'its parent still comes back');

		$_GET['page'] = 'games';
		$this->wcms->pageStatus();
		$this->assertSame(['games/board/'], array_column($this->search('games'), 'slug'), 'the parent does not hide its subpage');

		$_GET['page'] = 'board';
		$this->wcms->pageStatus();
		$this->assertSame(['games/', 'games/board/'], array_column($this->search('games'), 'slug'), 'the full path is compared, not the last slug');
	}

	public function testASearchRequestSkipsThePageItIsPostedTo(): void
	{
		// searchAction() runs before init() resolves the page; the widget posts to the page it runs on
		$probe = static function (string $page): string {
			return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' search zebra ' . escapeshellarg('<p>zebra crossing</p>') . ' ' . escapeshellarg($page));
		};
		$this->assertSame('{"error":"No matching content found"}', $probe('home'));
		$this->assertSame('{"error":"No matching content found"}', $probe('home/'), 'the trailing slash the widget posts with resolves the same page');
		$this->assertSame('home', json_decode($probe('how-to'), true)[0]['slug'] ?? null);
		$this->assertSame('home', json_decode($probe(''), true)[0]['slug'] ?? null, 'a request that names no page skips nothing: code that posts to the site root still finds the home page');
	}

	public function testTheWidgetOnTheSiteRootPostsToTheDefaultPageSoItIsSkipped(): void
	{
		// #407 at /: the URL names no page, so a POST to window.location.href skipped nothing and the home page listed itself.
		// The widget posts to the page the server resolved instead (the theme calls search() after pageStatus()).
		unset($_GET['page']);
		$this->wcms->pageStatus();
		$widget = $this->wcms->search();
		$this->assertStringContainsString("fetch('" . Wcms::url('home/') . "'", $widget);
		$this->assertStringNotContainsString('window.location.href', $widget);

		$probe = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' search zebra ' . escapeshellarg('<p>zebra crossing</p>') . ' ' . escapeshellarg('home/'));
		$this->assertSame('{"error":"No matching content found"}', $probe, 'posted where the widget on / posts, the home page is not a result');

		$_GET['page'] = 'games/board/';
		$this->wcms->pageStatus();
		$this->assertStringContainsString("fetch('" . Wcms::url('games/board/') . "'", $this->wcms->search(), 'a nested page posts to its own path');
	}

	public function testHiddenPagesAreSearchableAnd404IsNot(): void
	{
		$this->assertSame(['hidden-page'], array_column($this->search('secret garden'), 'slug'));
		$this->assertSame([], array_column($this->search('404'), 'slug'));
	}

	public function testExcerptOnMultibyteTextIsValidJson(): void
	{
		foreach (['東京', 'Café', 'curly', 'Шахматы'] as $query) {
			$results = $this->search($query);
			$this->assertNotEmpty($results, $query);
			$json = json_encode($results);
			$this->assertNotFalse($json, 'json_encode failed for ' . $query . ': ' . json_last_error_msg());
			$this->assertStringContainsString('<mark>', $results[0]['excerpt']);
		}
	}

	public function testMatchingIsCaseInsensitiveForNonLatinText(): void
	{
		$this->assertNotEmpty($this->search('шахматы'));
		$this->assertNotEmpty($this->search('CAFÉ'));
	}

	public function testCreateExcerptDoesNotCutInsideAMultibyteCharacter(): void
	{
		$method = new ReflectionMethod(Wcms::class, 'createExcerpt');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		// 'a' + 9 bytes of 2-byte Cyrillic: a byte cut at 10 lands inside a character (3.6.0 returned invalid UTF-8 here)
		$excerpt = $method->invoke($this->wcms, 'aШахматы Шахматы', 'Шахматы', 10);
		$this->assertNotFalse(json_encode(['excerpt' => $excerpt]), json_last_error_msg());
		$this->assertSame('a<mark>Шахматы</mark> Ш', $excerpt);
		$excerpt = $method->invoke($this->wcms, 'x東京都東京都', '東京', 3);
		$this->assertNotFalse(json_encode(['excerpt' => $excerpt]), json_last_error_msg());
	}
}
