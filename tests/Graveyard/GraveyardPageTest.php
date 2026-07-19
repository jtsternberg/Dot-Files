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
 *  - transcripts are NOT embedded in the HTML — the modal injects
 *    page-data/<id>.js on first open (served fresh by the router via
 *    renderTranscriptJs()), caches it, and scrolls to the latest end
 *  - stone display strings ride in escaped data-* attributes; the modal fills
 *    via textContent (no HTML injection from titles/cwds/transcripts)
 *
 * Serve-only (dotfiles-06t): the page is rendered fresh per request, never
 * written to disk, so these tests drive pageHtml()/renderStorePageHtml()
 * directly rather than a written index.html.
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

		$this->assertSame(2, substr_count($html, 'class="stone crown-'));
		$this->assertStringContainsString('id="yard"', $html);            // the field
		$this->assertStringContainsString('grid-auto-flow: row dense', $html); // masonry packing
		$this->assertStringContainsString('data-cols="1"', $html);        // width hint for the masonry
		$this->assertStringContainsString('relayout', $html);             // the masonry pass
		$this->assertStringContainsString('fix the bug', $html);
		$this->assertStringContainsString('abc12345', $html);        // short id on the stone
		$this->assertStringContainsString('2026-07-15', $html);      // buried date on the stone
	}

	public function testStoneCrackedIsSeededByTitle(): void
	{
		foreach (['fix the bug', 'FIX login', 'failing test', 'error handling', 'broken pipe'] as $t) {
			$this->assertTrue($this->gy->stoneCracked($t), "expected cracked: $t");
		}
		foreach (['write the docs', 'add feature', 'refactor layout'] as $t) {
			$this->assertFalse($this->gy->stoneCracked($t), "expected intact: $t");
		}
	}

	public function testPageHtmlMarksCrackedStones(): void
	{
		$html = $this->gy->pageHtml([
			$this->tomb('abc12345-full', 'fix the bug'),      // matches -> cracked
			$this->tomb('def67890-full', 'write the docs'),   // no match -> intact
		], '2026-07-17');

		$this->assertSame(1, substr_count($html, ' cracked"'));     // exactly one cracked stone
		$this->assertStringContainsString('.stone.cracked', $html); // the chipped CSS ships
		$this->assertSame(2, substr_count($html, 'class="stone crown-')); // both still carry a crown class
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

	public function testPageInlinesAlpineComponent(): void
	{
		// The page is driven by Alpine.js, inlined (self-contained — no external
		// <script src>), with a root graveyard() component wiring the modal.
		$html = $this->gy->pageHtml([$this->tomb('alp00001-full', 'alpine test')], '2026-07-17');
		$this->assertStringContainsString('x-data="graveyard()"', $html);    // root component
		$this->assertStringContainsString('Alpine.data("graveyard"', $html); // component defined
		$this->assertStringContainsString('alpine:init', $html);             // registered before init
		$this->assertStringNotContainsString('<script src=', $html);         // inlined, not linked
		$this->assertStringContainsString('@click.stop="show(', $html);      // declarative stone open
	}

	public function testPageHasSearchFilter(): void
	{
		// A search box live-filters stones AND plots by their presented title.
		// Loose stones toggle on their own title; a plot shows if its title OR
		// any member matches; members inside a title-matched plot all stay.
		$t1 = $this->tomb('srch0001-full', 'alpha bug');
		$g  = $this->tomb('srch0003-full', 'gamma member', '2026-07-13T10:00:00Z');
		$g['group_id'] = 'gg'; $g['group_title'] = 'Gamma Plot'; $g['group_pos'] = 0;
		$html = $this->gy->pageHtml([$t1, $g], '2026-07-17');

		$this->assertStringContainsString('x-model="search"', $html);           // the search box
		$this->assertStringContainsString('type="search"', $html);
		$this->assertStringContainsString('x-show="stoneVisible($el)"', $html); // stones filter
		$this->assertStringContainsString('x-show="plotVisible($el)"', $html);  // plots filter
		$this->assertStringContainsString('data-title="Gamma Plot"', $html);    // plot title for matching
	}

	public function testPageHtmlPlotDetailsAndResurrectCommand(): void
	{
		// Clicking a plot's background (not a member stone) opens a details dialog
		// with the group name, members, and a copyable whole-group resurrect
		// command (graveyard resurrect --workspace <8-char group id>). Member
		// clicks must NOT bubble to the plot handler.
		$t1 = $this->tomb('pd111111-full', 'member one', '2026-07-10T00:00:00Z');
		$t1['group_id'] = 'abcd1234-uuid-rest'; $t1['group_title'] = 'My Plot'; $t1['group_pos'] = 0;
		$t2 = $this->tomb('pd222222-full', 'member two', '2026-07-10T00:00:00Z');
		$t2['group_id'] = 'abcd1234-uuid-rest'; $t2['group_title'] = 'My Plot'; $t2['group_pos'] = 1;
		$html = $this->gy->pageHtml([$t1, $t2], '2026-07-17');

		$this->assertStringContainsString('data-gid8="abcd1234"', $html);   // short group id on the fieldset
		$this->assertStringContainsString('@click="showPlot($el)"', $html); // click plot bg → details
		$this->assertStringContainsString('@click.stop="show($el)"', $html); // member click doesn't bubble
		$this->assertStringContainsString('id="plotmodal"', $html);          // dedicated plot dialog
		$this->assertStringContainsString('resurrect --workspace', $html);   // whole-group command
	}

	public function testPageHtmlRenameAffordance(): void
	{
		// Serve-only: both modals expose an editable name that auto-saves via the
		// live JSON API (no copy-command button). The stone renames a session, the
		// plot renames the workspace group.
		$t = $this->tomb('rn000001-full', 'old name');
		$t['group_id'] = 'ggg11111-x'; $t['group_title'] = 'Old Plot'; $t['group_pos'] = 0;
		$html = $this->gy->pageHtml([$t], '2026-07-17');

		$this->assertStringContainsString('x-model="renameName"', $html);      // stone rename input
		$this->assertStringContainsString('x-model="renamePlotName"', $html);  // plot rename input
		$this->assertStringContainsString('apiRenameStone()', $html);          // session auto-save
		$this->assertStringContainsString('apiRenamePlot()', $html);           // group auto-save
		$this->assertStringContainsString('changes save automatically', $html);
		$this->assertStringNotContainsString('renameCmd()', $html);            // no static copy-command path
	}

	public function testPageHtmlDeleteAffordance(): void
	{
		// Serve-only: both modals expose a one-tap permanent-delete that calls the
		// live API (native confirm() gates it client-side, inside apiDelete*). No
		// static confirm-reveal step, no copy-command box.
		$t = $this->tomb('del00001-full', 'doomed');
		$t['group_id'] = 'ddd11111-x'; $t['group_title'] = 'Doomed Plot'; $t['group_pos'] = 0;
		$html = $this->gy->pageHtml([$t], '2026-07-17');

		$this->assertStringContainsString('@click="apiDeleteStone()"', $html); // one-tap session delete
		$this->assertStringContainsString('@click="apiDeletePlot()"', $html);  // one-tap group delete
		$this->assertStringContainsString('delete this session…', $html);
		$this->assertStringContainsString('delete this whole plot…', $html);
		$this->assertStringNotContainsString('confirmStone', $html);           // no static confirm gate
		$this->assertStringNotContainsString('deleteCmd()', $html);            // no static copy-command path
	}

	public function testPlotColumnsVaryDeterministically(): void
	{
		// A plot's internal shape (column count) derives from its group id + a
		// per-page seed: deterministic within a page, varying across plots (and
		// across regenerations as the seed changes), always 1..memberCount.
		$this->assertSame(1, $this->gy->plotColumns('g', 1, 'seedA')); // singletons are one column
		$c = $this->gy->plotColumns('g-x', 4, 'seedA');
		$this->assertSame($c, $this->gy->plotColumns('g-x', 4, 'seedA')); // deterministic
		$this->assertGreaterThanOrEqual(1, $c);
		$this->assertLessThanOrEqual(4, $c);

		// shapes vary across plots for a fixed seed (not all identical)
		$vals = array_map(fn($g) => $this->gy->plotColumns($g, 4, 'seedA'), ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
		$this->assertGreaterThan(1, count(array_unique($vals)));

		// rendered: the plot carries a --cols custom property driving its grid
		$t1 = $this->tomb('col11111-full', 'one', '2026-07-10T00:00:00Z');
		$t1['group_id'] = 'cols-gid'; $t1['group_title'] = 'C'; $t1['group_pos'] = 0;
		$t2 = $this->tomb('col22222-full', 'two', '2026-07-10T00:00:00Z');
		$t2['group_id'] = 'cols-gid'; $t2['group_title'] = 'C'; $t2['group_pos'] = 1;
		$html = $this->gy->pageHtml([$t1, $t2], '2026-07-17');
		$this->assertStringContainsString('--cols:', $html);
	}

	public function testServeOnlyMutationPathsAreTheOnlyOnes(): void
	{
		// Serve-only: rename auto-saves debounced; delete is a single button → the
		// live API. There is no `live` feature-detection and no file:// copy-command
		// fallback anywhere in the page anymore.
		$t = $this->tomb('lv000001-full', 'x');
		$t['group_id'] = 'lvgid-uuid'; $t['group_title'] = 'LV'; $t['group_pos'] = 0;
		$html = $this->gy->pageHtml([$t], '2026-07-17');

		$this->assertStringContainsString('@input.debounce.600ms="apiRenameStone()"', $html); // stone auto-save
		$this->assertStringContainsString('@input.debounce.600ms="apiRenamePlot()"', $html);  // plot auto-save
		$this->assertStringContainsString('@click="apiDeleteStone()"', $html);                // one-tap delete
		$this->assertStringContainsString('@click="apiDeletePlot()"', $html);
		$this->assertStringNotContainsString('!live', $html);        // no static-mode branches
		$this->assertStringNotContainsString('this.live', $html);    // no live feature-detection
	}

	public function testTombstoneModalHasPlotBacklink(): void
	{
		// A member session's tombstone modal shows a backlink to its plot modal;
		// stones carry data-group-title and the modal binds item.plotTitle.
		$t = $this->tomb('bk000001-full', 'member');
		$t['group_id'] = 'bkgid-uuid'; $t['group_title'] = 'Back Plot'; $t['group_pos'] = 0;
		$html = $this->gy->pageHtml([$t], '2026-07-17');
		$this->assertStringContainsString('data-group-title="Back Plot"', $html); // stone carries its plot title
		$this->assertStringContainsString('backToPlot', $html);                   // backlink handler
		$this->assertStringContainsString('item.plotTitle', $html);               // shown in the tombstone modal
	}

	public function testPageStylesCodeAndTemplateExtracted(): void
	{
		// <code> is styled, and the page is assembled from the template file
		// (footer resurrect hint is wrapped in <code>).
		$html = $this->gy->pageHtml([$this->tomb('code0001-full', 'x')], '2026-07-17');
		$this->assertStringContainsString('code {', $html);                 // <code> styling
		$this->assertStringContainsString('<code>graveyard resurrect', $html); // used in the footer
	}

	public function testPlotModalIsVisuallyDistinct(): void
	{
		// #plotmodal has its own card treatment (hue-tinted spine) separate from
		// the tombstone modal, and members are clickable to jump to the stone.
		$t = $this->tomb('pm000001-full', 'm', '2026-07-10T00:00:00Z');
		$t['group_id'] = 'pmgid-uuid'; $t['group_title'] = 'P'; $t['group_pos'] = 0;
		$html = $this->gy->pageHtml([$t], '2026-07-17');
		$this->assertStringContainsString('dialog#plotmodal .card {', $html); // distinct styling
		$this->assertStringContainsString('--pm-hue', $html);                 // hue-tinted
		$this->assertMatchesRegularExpression('/dialog#plotmodal \.card \{[^}]*dashed/s', $html); // chunky fence
		$this->assertStringContainsString('@click="openMember(', $html);      // member → tombstone modal
		$this->assertStringContainsString('openMember: function', $html);
	}

	public function testMasonryHooksPresent(): void
	{
		$t1 = $this->tomb('mas00001-full', 'a', '2026-07-10T00:00:00Z');
		$t1['group_id'] = 'masg-uuid'; $t1['group_title'] = 'M'; $t1['group_pos'] = 0;
		$t2 = $this->tomb('mas00002-full', 'b', '2026-07-10T00:00:00Z');
		$t2['group_id'] = 'masg-uuid'; $t2['group_title'] = 'M'; $t2['group_pos'] = 1;
		$html = $this->gy->pageHtml([$t1, $t2], '2026-07-17');
		$this->assertStringContainsString('grid-auto-flow: row dense', $html); // dense packing fills holes
		$this->assertStringContainsString('relayout: function', $html);        // the JS masonry pass
		$this->assertStringContainsString('--yard-cols', $html);
		$this->assertMatchesRegularExpression('/<fieldset class="plot"[^>]*data-cols="2"/', $html); // plot width hint
	}

	public function testHeadstonesGetVariedCrownShapes(): void
	{
		// Each headstone's top silhouette (crown) is seeded by session id —
		// deterministic (a grave keeps its shape), in range, and varied across graves.
		$c = $this->gy->stoneCrown('abc12345-full');
		$this->assertSame($c, $this->gy->stoneCrown('abc12345-full'));       // deterministic
		$this->assertGreaterThanOrEqual(0, $c);
		$this->assertLessThan(6, $c);
		$vals = array_map(fn($s) => $this->gy->stoneCrown($s), ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
		$this->assertGreaterThan(1, count(array_unique($vals)));              // graves differ

		$html = $this->gy->pageHtml([$this->tomb('crwn0001-full', 'x')], '2026-07-17');
		$this->assertMatchesRegularExpression('/class="stone crown-[0-5]"/', $html); // crown class on the stone
		$this->assertStringContainsString('corner-shape: round round square square', $html); // buried base
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

	public function testRenderKeepsHtmlLeanAndTranscriptsSeparate(): void
	{
		$root = $this->makeRoot([
			$this->tomb('old11111-aaaa', 'older session', '2026-07-01T00:00:00Z'),
			$this->tomb('new22222-bbbb', 'newer session', '2026-07-09T00:00:00Z'),
		]);
		@mkdir($root . '/sessions/old11111-aaaa', 0755, true);
		file_put_contents($root . '/sessions/old11111-aaaa/transcript.txt', 'old transcript body');

		$gy = new Graveyard($this->cli, $this->cmux);
		$html = $gy->renderStorePageHtml();

		$this->assertStringNotContainsString('old transcript body', $html); // JIT, not embedded
		$this->assertStringContainsString('page-data/', $html);             // modal loads transcripts here

		// Transcripts come from renderTranscriptJs() per request, not from disk snapshots.
		$js = $gy->renderTranscriptJs('old11111-aaaa');
		$this->assertNotNull($js);
		$this->assertStringContainsString('old transcript body', $js);
		$this->assertNull($gy->renderTranscriptJs('new22222-bbbb')); // no transcript archived

		$this->assertLessThan(
			strpos($html, 'older session'),
			strpos($html, 'newer session'),
			'newest tombstone renders before the older one'
		);
	}

	public function testPageHtmlSessionIdIsClickToCopy(): void
	{
		// The modal's session id copies the FULL id to the clipboard on click
		// (for `graveyard resurrect <id>`), with a "copied" confirmation.
		$html = $this->gy->pageHtml([$this->tomb('copy0001-full', 'copy me')], '2026-07-17');
		$this->assertStringContainsString('id="m-id"', $html);
		$this->assertStringContainsString('navigator.clipboard', $html);
		$this->assertStringContainsString('writeText', $html);
		$this->assertStringContainsString('copied', strtolower($html));
	}

	public function testPageHtmlPlotsGetDeterministicHues(): void
	{
		// Each family plot gets a muted accent hue (fence, legend, bg tint)
		// derived from its group id — stable across regenerations, so the same
		// family always wears the same color, and neighbors differ.
		$hue = $this->gy->plotHue('g-alpha');
		$this->assertSame($hue, $this->gy->plotHue('g-alpha')); // deterministic
		$this->assertContains($hue, [30, 60, 95, 160, 205, 255, 300, 345]); // muted palette
		$this->assertNotSame($this->gy->plotHue('g-alpha'), $this->gy->plotHue('g-beta'));

		$t1 = $this->tomb('hue11111-full', 'm1', '2026-07-10T00:00:00Z');
		$t1['group_id'] = 'g-alpha'; $t1['group_title'] = 'A'; $t1['group_pos'] = 0;
		$html = $this->gy->pageHtml([$t1], '2026-07-17');
		$this->assertStringContainsString('--plot-hue:' . $hue, $html); // fieldset inline style
	}

	public function testPageHtmlTranscriptPathIsCopyable(): void
	{
		// Below the transcript, the modal shows the transcript's file path —
		// displayed with the home dir collapsed to ~/, but copying yields the
		// FULL absolute path (same clipboard + "copied ✓" flash as the id).
		putenv('GRAVEYARD_ROOT=/tmp/gyhome/.claude-graveyard');
		$html = $this->gy->pageHtml([$this->tomb('tpath001-full', 'path test')], '2026-07-17', '/tmp/gyhome');

		$this->assertStringContainsString('data-tpath="/tmp/gyhome/.claude-graveyard/sessions/tpath001-full/transcript.txt"', $html);
		$this->assertStringContainsString('data-tpath-short="~/.claude-graveyard/sessions/tpath001-full/transcript.txt"', $html);
		$this->assertStringContainsString('id="m-tpath"', $html);
	}

	public function testPageHtmlCloseButtonIsAMedallion(): void
	{
		// The modal close button is a circular "wax seal" medallion (bordered
		// circle, glyph centered, moss hover) — not a bare text ✕.
		$html = $this->gy->pageHtml([$this->tomb('cls00001-full', 'close test')], '2026-07-17');
		$this->assertStringContainsString('id="m-close"', $html);
		$this->assertStringContainsString('class="medallion"', $html); // shared by both modals' close buttons
		$this->assertStringContainsString('.medallion {', $html);
		$this->assertStringContainsString('border-radius: 50%', $html);
		$this->assertStringContainsString('place-items: center', $html);
	}

	public function testPageHtmlContainsModalScroll(): void
	{
		// Wheel-scrolling the transcript to its end must NOT chain into the
		// background page: the body locks while the dialog is open, and the
		// scrollable regions contain their own overscroll.
		$html = $this->gy->pageHtml([$this->tomb('scr00001-full', 'scroll test')], '2026-07-17');
		$this->assertStringContainsString('body:has(dialog#plot[open])', $html);
		$this->assertStringContainsString('overscroll-behavior', $html);
	}

	public function testPageHtmlHasGothicFlourishes(): void
	{
		// JT's v1-header favorites, ported forward — the crest above the title and
		// the skull set into the divider — plus more in the same spirit: a coffin
		// divider in the footer, drifting ground mist, corner cobwebs, and an
		// "exhumed transcript" caption above the modal's transcript.
		$html = $this->gy->pageHtml([$this->tomb('flr00001-full', 'fancy')], '2026-07-17');

		$this->assertLessThan(strpos($html, '<h1'), strpos($html, 'class="crest"'));
		$this->assertStringContainsString('💀', $html);      // header divider
		$this->assertStringContainsString('⚰', $html);       // footer divider
		$this->assertStringContainsString('class="fog"', $html);
		$this->assertStringContainsString('@keyframes drift', $html);
		$this->assertStringContainsString('🕸', $html);       // cobweb corners
		$this->assertStringContainsString('crypt-cap', $html);
		$this->assertStringContainsString('⛏ exhuming…', $html);
	}

	public function testPageUnitsOrdersPlotsAndLooseNewestFirst(): void
	{
		$mk = function (string $sid, string $buried, ?string $gid = null, ?int $pos = null): array {
			$t = $this->tomb($sid, "title {$sid}", $buried . 'T00:00:00Z');
			if ($gid !== null) { $t['group_id'] = $gid; $t['group_title'] = 'Fam'; $t['group_pos'] = $pos; }
			return $t;
		};
		$units = $this->gy->pageUnits([
			$mk('looseA', '2026-07-17'),
			$mk('g1m2', '2026-07-15', 'g1', 1),
			$mk('looseB', '2026-07-16'),
			$mk('g1m1', '2026-07-15', 'g1', 0),
		]);

		$this->assertSame(['stone', 'stone', 'plot'], array_column($units, 'type'));
		$this->assertSame('looseA', $units[0]['tomb']['session_id']);
		$this->assertSame('looseB', $units[1]['tomb']['session_id']);
		// plot members render in original tab order (group_pos), not buried_at order
		$this->assertSame(['g1m1', 'g1m2'], array_column($units[2]['members'], 'session_id'));
		$this->assertSame('Fam', $units[2]['title']);
	}

	public function testManifestPositionsMapsClaudeMembersOnly(): void
	{
		$pos = $this->gy->manifestPositions([
			'layout' => [
				['pane_index' => 0, 'index_in_pane' => 2, 'kind' => 'claude', 'claude_session_id' => 'sid-a'],
				['pane_index' => 0, 'index_in_pane' => 3, 'kind' => 'shell', 'claude_session_id' => null],
				['pane_index' => 1, 'index_in_pane' => 0, 'kind' => 'browser', 'claude_session_id' => null],
			],
		]);
		$this->assertSame(['sid-a' => ['pane' => 0, 'tab' => 2]], $pos);
	}

	public function testPageHtmlRendersWorkspaceGroupAsPlot(): void
	{
		$t1 = $this->tomb('plt11111-full', 'member one', '2026-07-10T00:00:00Z');
		$t1['group_id'] = 'g1'; $t1['group_title'] = 'Fam Plot'; $t1['group_pos'] = 0;
		$t2 = $this->tomb('plt22222-full', 'member two', '2026-07-10T00:00:00Z');
		$t2['group_id'] = 'g1'; $t2['group_title'] = 'Fam Plot'; $t2['group_pos'] = 1;
		// Older than the plot: units sort newest-first, so the lone stone trails it.
		$loose = $this->tomb('loose001-full', 'lone stone', '2026-07-09T00:00:00Z');

		$html = $this->gy->pageHtml([$t1, $t2, $loose], '2026-07-17');

		$this->assertSame(1, substr_count($html, 'class="plot"'));
		$this->assertStringContainsString('<legend>Fam Plot</legend>', $html);
		// members are fenced inside the plot; the loose stone is outside it
		$this->assertLessThan(strpos($html, 'member one'), strpos($html, '<legend>Fam Plot</legend>'));
		$this->assertLessThan(strpos($html, '</fieldset>'), strpos($html, 'member two'));
		$this->assertGreaterThan(strpos($html, '</fieldset>'), strpos($html, 'lone stone'));
	}

	public function testPageHtmlShowsPaneTabSuffixWhenKnown(): void
	{
		$t = $this->tomb('plt00001-full', 'positioned', '2026-07-10T00:00:00Z');
		$t['group_id'] = 'g1'; $t['group_title'] = 'Fam'; $t['group_pos'] = 0;
		$t['plot_pos'] = ['pane' => 0, 'tab' => 1]; // 0-based storage → 1-based display
		$html = $this->gy->pageHtml([$t], '2026-07-17');
		$this->assertStringContainsString('[P1,T2]', $html);
	}

	public function testPageRendersPlotsFromManifestPositions(): void
	{
		$gid = 'grp-uuid-1';
		$t1 = $this->tomb('plt11111-full', 'plot member one', '2026-07-10T00:00:00Z');
		$t1['group_id'] = $gid; $t1['group_title'] = 'Fam'; $t1['group_pos'] = 0;
		$t2 = $this->tomb('plt22222-full', 'plot member two', '2026-07-10T00:00:00Z');
		$t2['group_id'] = $gid; $t2['group_title'] = 'Fam'; $t2['group_pos'] = 1;
		$root = $this->makeRoot([$t1, $t2]);
		@mkdir($root . '/workspaces/' . $gid, 0755, true);
		file_put_contents($root . '/workspaces/' . $gid . '/manifest.json', json_encode([
			'group_id' => $gid, 'group_title' => 'Fam',
			'layout' => [
				['group_pos' => 0, 'pane_index' => 0, 'index_in_pane' => 0, 'kind' => 'claude', 'claude_session_id' => 'plt11111-full'],
				['group_pos' => 1, 'pane_index' => 1, 'index_in_pane' => 2, 'kind' => 'claude', 'claude_session_id' => 'plt22222-full'],
				['group_pos' => 2, 'pane_index' => 1, 'index_in_pane' => 3, 'kind' => 'shell'],
			],
		]));

		$gy = new Graveyard($this->cli, $this->cmux);
		$html = $gy->renderStorePageHtml();

		$this->assertStringContainsString('class="plot"', $html);
		$this->assertStringContainsString('Fam', $html);
		$this->assertStringContainsString('[P1,T1]', $html); // member one: pane 0+1, tab 0+1
		$this->assertStringContainsString('[P2,T3]', $html); // member two: pane 1+1, tab 2+1
	}
}
