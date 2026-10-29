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
		$this->assertContains('games/board', $slugs);
		$this->assertCount(1, array_keys($slugs, 'games/board'), 'the legacy top-level duplicate must not be a second hit');
		$this->assertSame(['games/board/chess'], array_column($this->search('Шахматы'), 'slug'));
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
