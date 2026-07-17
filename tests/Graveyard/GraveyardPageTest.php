<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-pnl — `graveyard page` verb: self-contained HTML overview of all
 * tombstones (card per session: title, dates, cwd, click-to-expand transcript).
 *
 * Behavior contract:
 *  - pageHtml() is a PURE emitter: takes tombstones + transcripts + generated stamp
 *  - one card per session, order preserved (caller sorts newest-first)
 *  - transcript embedded in a native <details> expander, HTML-escaped
 *  - all metadata escaped (transcripts/cwds/titles are arbitrary user content)
 *  - missing transcript renders a note, not a fatal
 *  - page() wrapper reads the store, sorts newest-first, writes page.html
 */
final class GraveyardPageTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	/** Build an isolated graveyard root with tombstones seeded (same pattern as search test). */
	protected function makeRoot(array $tombs): string
	{
		$root = sys_get_temp_dir() . '/gy-page-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);
		$gy = new Graveyard($this->cli, $this->cmux);
		foreach ($tombs as $t) { $gy->upsertIndex($t); }
		return $root;
	}

	public function testPageHtmlEmptyGraveyard(): void
	{
		$html = $this->gy->pageHtml([], [], '2026-07-17T20:00:00Z');
		$this->assertStringStartsWith('<!DOCTYPE html>', $html);
		$this->assertStringContainsString('<style', $html); // self-contained: inline CSS, no assets
		$this->assertStringContainsString('2026-07-17T20:00:00Z', $html); // generated stamp
		$this->assertStringContainsString('graveyard is empty', strtolower($html));
	}

	public function testPageHtmlCardFields(): void
	{
		$tombs = [[
			'session_id' => 'abc12345-6789-full-uuid', 'workspace_title' => 'WS', 'tab_title' => 'Tab',
			'cwd' => '/home/x/proj', 'summary' => 'fix the bug', 'model' => 'opus',
			'buried_at' => '2026-07-15T10:00:00Z', 'last_active' => '2026-07-14T09:59:00Z',
		]];
		$html = $this->gy->pageHtml($tombs, ['abc12345-6789-full-uuid' => "first line\nsecond line"], '2026-07-17');

		$this->assertStringContainsString('fix the bug', $html);          // title from summary
		$this->assertStringContainsString('abc12345', $html);             // short session id
		$this->assertStringContainsString('/home/x/proj', $html);         // cwd
		$this->assertStringContainsString('2026-07-15', $html);           // buried date
		$this->assertStringContainsString('2026-07-14', $html);           // last-active date
		$this->assertStringContainsString('opus', $html);                 // model
		$this->assertStringContainsString('<details', $html);             // click-to-expand
		$this->assertStringContainsString("first line\nsecond line", $html); // transcript body
	}

	public function testPageHtmlEscapesTranscriptAndMetadata(): void
	{
		$tombs = [[
			'session_id' => 'esc00001-full', 'workspace_title' => '<b>WS</b>', 'tab_title' => 'Tab',
			'cwd' => '/tmp/<weird>', 'summary' => 'plain summary', 'buried_at' => '2026-07-15',
		]];
		$html = $this->gy->pageHtml($tombs, ['esc00001-full' => "<script>alert('x')</script>"], '2026-07-17');

		$this->assertStringContainsString('&lt;script&gt;', $html);
		$this->assertStringNotContainsString("<script>alert", $html);
		$this->assertStringContainsString('&lt;b&gt;WS&lt;/b&gt;', $html);
		$this->assertStringNotContainsString('<b>WS</b>', $html);
		$this->assertStringContainsString('&lt;weird&gt;', $html);
	}

	public function testPageHtmlMissingTranscriptRendersNote(): void
	{
		$tombs = [[
			'session_id' => 'none0001-full', 'workspace_title' => 'WS', 'summary' => 's',
			'buried_at' => '2026-07-15', 'cwd' => '/x',
		]];
		$html = $this->gy->pageHtml($tombs, [], '2026-07-17');
		$this->assertStringContainsString('(no transcript archived)', $html);
		$this->assertStringNotContainsString('<details', $html);
	}

	public function testPageHtmlTranscriptStartsScrolledToBottom(): void
	{
		// Expanding a card's transcript shows the LATEST end first (most recent turns),
		// not the top of a 300KB scrollback.
		$html = $this->gy->pageHtml(
			[['session_id' => 's1', 'summary' => 'x', 'buried_at' => '2026-07-15']],
			['s1' => 'body'],
			'2026-07-17'
		);
		$this->assertStringContainsString("addEventListener('toggle'", $html);
		$this->assertStringContainsString('scrollTop', $html);
		$this->assertStringContainsString('scrollHeight', $html);
	}

	public function testPageHtmlStaggersCardReveal(): void
	{
		$tombs = [
			['session_id' => 's1', 'summary' => 'one', 'buried_at' => '2026-07-15'],
			['session_id' => 's2', 'summary' => 'two', 'buried_at' => '2026-07-14'],
		];
		$html = $this->gy->pageHtml($tombs, [], '2026-07-17');
		$this->assertStringContainsString('--i:0', $html);
		$this->assertStringContainsString('--i:1', $html);
		$this->assertStringContainsString('prefers-reduced-motion', $html);
	}

	public function testPageWritesSortedNewestFirst(): void
	{
		$root = $this->makeRoot([
			['session_id' => 'old11111-aaaa', 'workspace_title' => 'older ws', 'summary' => 'older session', 'buried_at' => '2026-07-01T00:00:00Z', 'cwd' => '/x'],
			['session_id' => 'new22222-bbbb', 'workspace_title' => 'newer ws', 'summary' => 'newer session', 'buried_at' => '2026-07-09T00:00:00Z', 'cwd' => '/y'],
		]);
		@mkdir($root . '/sessions/old11111-aaaa', 0755, true);
		file_put_contents($root . '/sessions/old11111-aaaa/transcript.txt', 'old transcript body');

		$gy = new Graveyard($this->cli, $this->cmux);
		$path = $gy->page(false); // false: do not open a browser
		$this->assertSame($root . '/page.html', $path);
		$this->assertFileExists($path);

		$html = (string) file_get_contents($path);
		$this->assertStringContainsString('old transcript body', $html);      // loaded from disk
		$this->assertStringContainsString('(no transcript archived)', $html); // new one has none
		$this->assertLessThan(
			strpos($html, 'older session'),
			strpos($html, 'newer session'),
			'newest tombstone renders before the older one'
		);
	}
}
