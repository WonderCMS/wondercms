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

	private function probeSearch(string $page, ?string $urlPage = null): string
	{
		return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/probe.php') . ' search zebra ' . escapeshellarg('<p>zebra crossing</p>') . ' ' . escapeshellarg($page) . ($urlPage !== null ? ' ' . escapeshellarg($urlPage) : ''));
	}

	public function testASearchRequestSkipsThePageItsWidgetRunsOn(): void
	{
		// searchAction() runs before init() resolves the page; the widget sends the page it runs on as a field
		$none = '{"error":"No matching content found"}';
		$this->assertSame($none, $this->probeSearch('home'));
		$this->assertSame($none, $this->probeSearch('home/'), 'a trailing slash names the same page');
		$this->assertSame('home', json_decode($this->probeSearch('how-to'), true)[0]['slug'] ?? null);
		$this->assertSame('home', json_decode($this->probeSearch(''), true)[0]['slug'] ?? null, 'a request that names no page skips nothing: code that posts to the site root still finds the home page');
	}

	public function testAPageFieldThatIsNotAnExistingPageSkipsNothing(): void
	{
		foreach (['nope', 'home/nope', 'home//x', '/home/x', 'array:'] as $page) {
			$this->assertSame('home', json_decode($this->probeSearch($page), true)[0]['slug'] ?? null, $page);
		}
		$this->assertSame('home', json_decode($this->probeSearch('', 'home'), true)[0]['slug'] ?? null, 'the URL the search is posted to does not choose the skip');
	}

	public function testTheWidgetPostsToTheSearchEndpointAndSendsThePageItRunsOn(): void
	{
		// #407 at /, /home, /home/ and a nested page: the server's own path of an existing page, never the URL as typed
		$cases = ['' => '"home"', 'home' => '"home"', 'home/' => '"home"', 'games/board/' => '"games\/board"', 'games/board/chess' => '"games\/board\/chess"'];
		foreach ($cases as $urlPage => $field) {
			$_GET = $urlPage === '' ? [] : ['page' => $urlPage];
			$this->wcms->pageStatus();
			$widget = $this->wcms->search();
			$this->assertStringContainsString("fetch('" . Wcms::url('search') . "', {", $widget, (string)$urlPage);
			$this->assertStringContainsString(", page: $field })", $widget, (string)$urlPage);
		}
		$this->assertSame($this->probeSearch('home'), $this->probeSearch(json_decode('"home"')), 'the field as the browser sends it skips home');
		$this->assertSame('home', json_decode($this->probeSearch(json_decode('"games\/board"')), true)[0]['slug'] ?? null);
	}

	public function testACrafted404UrlDoesNotReachTheWidgetScript(): void
	{
		// the fetch target and the page field come from server state only: a 404's path is the visitor's, the login page is no result
		$widgets = [];
		foreach (['nope', 'other/evil.txt?', 'other/evil.txt#', 'a\\x27b', "foo\nbar", "foo\u{2028}bar", '../x', "a'b\"c</script>", 'loginURL'] as $urlPage) {
			$_GET = ['page' => $urlPage];
			$this->wcms->pageStatus();
			$this->assertFalse($this->wcms->currentPageExists, $urlPage);
			$widgets[$urlPage] = $this->wcms->search();
		}
		foreach ($widgets as $urlPage => $widget) {
			$this->assertSame($widgets['nope'], $widget, 'the script on ' . json_encode($urlPage) . ' is the plain 404 script');
		}
		$this->assertStringContainsString("fetch('" . Wcms::url('search') . "', {", $widgets['nope']);
		$this->assertStringContainsString(', page: "" })', $widgets['nope']);
		foreach (['evil', 'foo', 'x27', 'loginURL'] as $visitorText) {
			$this->assertStringNotContainsString($visitorText, $widgets['nope']);
		}
	}

	public function testJsStringIsSafeInsideAnInlineScript(): void
	{
		$method = new ReflectionMethod(Wcms::class, 'jsString');
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		$value = "a\nb\r\u{2028}\u{2029}'\"</script>&\\";
		$literal = $method->invoke($this->wcms, $value);
		$this->assertSame($value, json_decode($literal), 'the literal reads back as the value');
		$inner = substr($literal, 1, -1);
		$this->assertDoesNotMatchRegularExpression("/[\n\r'\"<>&\u{2028}\u{2029}]/u", $inner);
		$this->assertStringStartsWith('"', $literal);
		$this->assertStringEndsWith('"', $literal);
		$this->assertSame('"\u2028"', $method->invoke($this->wcms, "\u{2028}"));
		$this->assertSame('"\ufffd"', $method->invoke($this->wcms, "\xff"), 'invalid UTF-8 is a literal, not false');
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
