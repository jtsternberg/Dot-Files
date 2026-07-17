<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-pnl — `graveyard page` verb: self-contained HTML overview of all
 * tombstones. JT's v2 contract (wide-screen overview + JIT data):
 *
 *  - the page is a full-viewport FIELD of compact headstones (auto-fill grid),
 *    one per tombstone: title, buried date, short id
 *  - clicking a stone opens a <dialog> modal with the full card
 *  - transcripts are NOT embedded in page.html — the modal injects
 *    page-data/<id>.js (written at generation time; JSONP-style, because
 *    fetch() is CORS-blocked on file://) on first open, caches it, and
 *    scrolls the transcript to the latest end
 *  - stone display strings ride in escaped data-* attributes; the modal fills
 *    via textContent (no HTML injection from titles/cwds/transcripts)
 *  - page() prunes page-data/*.js files for ids no longer in the store
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

	protected function tomb(string $sid, string $title, string $buried = '2026-07-15T10:00:00Z'): array
	{
		return [
			'session_id' => $sid, 'workspace_title' => 'WS', 'tab_title' => 'Tab',
			'cwd' => '/home/x/proj', 'summary' => $title, 'model' => 'opus',
			'buried_at' => $buried, 'last_active' => '2026-07-14T09:59:00Z',
		];
	}

	public function testPageHtmlRendersAStonePerTombstone(): void
	{
		$html = $this->gy->pageHtml([
			$this->tomb('abc12345-full', 'fix the bug'),
			$this->tomb('def67890-full', 'write the docs', '2026-07-14T10:00:00Z'),
		], '2026-07-17');

		$this->assertSame(2, substr_count($html, 'class="stone"'));
		$this->assertStringContainsString('id="yard"', $html);       // the field
		$this->assertStringContainsString('auto-fill', $html);       // fills wide screens
		$this->assertStringContainsString('fix the bug', $html);
		$this->assertStringContainsString('abc12345', $html);        // short id on the stone
		$this->assertStringContainsString('2026-07-15', $html);      // buried date on the stone
	}

	public function testPageHtmlEscapesStoneContentAndAttributes(): void
	{
		// Note: summaries get tag-stripped at titleize time, so the title payload is
		// quote-based (attribute injection); angle brackets arrive via the cwd.
		$t = $this->tomb('esc00001-full', 'x" onmouseover="alert(1)');
		$t['cwd'] = '/tmp/<weird>';
		$html = $this->gy->pageHtml([$t], '2026-07-17');

		$this->assertStringNotContainsString('onmouseover="alert', $html);
		$this->assertStringContainsString('x&quot; onmouseover=&quot;alert(1)', $html); // attr-escaped
		$this->assertStringContainsString('&lt;weird&gt;', $html);
	}

	public function testPageHtmlLoadsTranscriptsJustInTime(): void
	{
		$html = $this->gy->pageHtml([$this->tomb('jit00001-full', 'jit test')], '2026-07-17');

		$this->assertStringContainsString('<dialog', $html);               // modal for the full card
		$this->assertStringContainsString('page-data/', $html);            // per-id transcript files
		$this->assertStringContainsString('createElement("script")', $html); // script-injection loader
		$this->assertStringContainsString('scrollTop', $html);             // opens scrolled…
		$this->assertStringContainsString('scrollHeight', $html);          // …to the latest end
		$this->assertStringNotContainsString('<details', $html);           // v1 expander is gone
	}

	public function testPageHtmlEmptyGraveyard(): void
	{
		$html = $this->gy->pageHtml([], '2026-07-17T20:00:00Z');
		$this->assertStringStartsWith('<!DOCTYPE html>', $html);
		$this->assertStringContainsString('<style', $html); // self-contained: inline CSS, no assets
		$this->assertStringContainsString('2026-07-17T20:00:00Z', $html); // generated stamp
		$this->assertStringContainsString('graveyard is empty', strtolower($html));
		$this->assertStringNotContainsString('class="stone"', $html);
	}

	public function testPageHtmlStaggersStoneReveal(): void
	{
		$html = $this->gy->pageHtml([
			$this->tomb('s1000000-full', 'one'),
			$this->tomb('s2000000-full', 'two'),
		], '2026-07-17');
		$this->assertStringContainsString('--i:0', $html);
		$this->assertStringContainsString('--i:1', $html);
		$this->assertStringContainsString('prefers-reduced-motion', $html);
	}

	public function testPageTranscriptJsEscapesForSafeInjection(): void
	{
		$js = $this->gy->pageTranscriptJs('id1', "a</script>b\n\"q\"");
		$this->assertStringContainsString('GYT["id1"]=', $js);
		$this->assertStringContainsString('\u003C/script\u003E', $js); // escaped: no script-block breakout
		$this->assertStringNotContainsString('</script>', $js);
		$this->assertStringContainsString('\n', $js); // newline JSON-encoded, not literal
	}

	public function testPageWritesPageDataFilesAndKeepsHtmlLean(): void
	{
		$root = $this->makeRoot([
			$this->tomb('old11111-aaaa', 'older session', '2026-07-01T00:00:00Z'),
			$this->tomb('new22222-bbbb', 'newer session', '2026-07-09T00:00:00Z'),
		]);
		@mkdir($root . '/sessions/old11111-aaaa', 0755, true);
		file_put_contents($root . '/sessions/old11111-aaaa/transcript.txt', 'old transcript body');
		// Stale page-data file from some previous generation — should be pruned.
		@mkdir($root . '/page-data', 0755, true);
		file_put_contents($root . '/page-data/stale999.js', 'window.GYT={};');

		$gy = new Graveyard($this->cli, $this->cmux);
		$path = $gy->page(false); // false: do not open a browser
		$this->assertSame($root . '/page.html', $path);

		$html = (string) file_get_contents($path);
		$this->assertStringNotContainsString('old transcript body', $html); // JIT, not embedded
		$this->assertStringContainsString('page-data/', $html);

		$jsFile = $root . '/page-data/old11111-aaaa.js';
		$this->assertFileExists($jsFile);
		$this->assertStringContainsString('old transcript body', (string) file_get_contents($jsFile));
		$this->assertFileDoesNotExist($root . '/page-data/new22222-bbbb.js'); // no transcript archived
		$this->assertFileDoesNotExist($root . '/page-data/stale999.js');      // pruned

		$this->assertLessThan(
			strpos($html, 'older session'),
			strpos($html, 'newer session'),
			'newest tombstone renders before the older one'
		);
	}
}
