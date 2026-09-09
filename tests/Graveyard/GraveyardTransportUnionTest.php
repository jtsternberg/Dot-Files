<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Helpers\Herdr;
use JT\Tests\TestCase;
use JT\Tests\Transport\FakeTransport;
use JT\Transport\HerdrTransport;
use JT\Transport\TransportRegistry;

/**
 * graveyard sees sessions under cmux AND herdr, and a restore into herdr says what
 * it cannot bring back.
 *
 * No Graveyard subclass doubles here. The union is driven through a real
 * TransportRegistry holding fake TRANSPORTS (which implement the interface, so they
 * cannot silently stop doubling anything), and the degraded-restore refusal is
 * driven through the real cli by making the prompt unanswerable — which is the
 * production behavior too: with no tty and no -y, a lossy restore refuses rather
 * than dropping surfaces quietly.
 */
final class GraveyardTransportUnionTest extends TestCase
{
	private const HERDR_SID = 'aaaa1111-2222-3333-4444-555555555555';

	private function registryGraveyard(array $transports, ?string $primary = null): Graveyard
	{
		$reg = new TransportRegistry($this->cli, $transports);
		return new Graveyard($this->cli, $reg->primary($primary) ?: $transports[0], null, $reg);
	}

	// ── Job 2: the union is annotated in ONE place, so every view inherits it ──

	/**
	 * The R2 regression guard, extended across transports.
	 *
	 * `↑` once appeared in ls and not in search because each view annotated liveness
	 * itself. Both now read tombstones(), which reads liveSessions() — so a session
	 * hosted by herdr has to show live in BOTH, with nothing at either call site
	 * knowing a second transport exists.
	 */
	public function testAHerdrHostedSessionShowsTheLiveMarkerInLsAndSearchAlike(): void
	{
		file_put_contents($this->graveyardRoot . '/index.json', json_encode(['tombstones' => [
			['session_id' => self::HERDR_SID, 'tab_title' => 'tailscale notes', 'workspace_title' => 'net',
			 'cwd' => '/x', 'summary' => 's', 'buried_at' => '2026-07-10'],
		]]));

		$gy = $this->registryGraveyard([
			new FakeTransport('cmux', []),
			new FakeTransport('herdr', [FakeTransport::row('herdr', self::HERDR_SID)]),
		]);

		$fromLs     = $gy->tombstones();
		$fromSearch = $gy->searchTombstones('tailscale');

		$this->assertTrue($fromLs[0]['live'], 'ls source must see the herdr-hosted session as live');
		$this->assertTrue($fromSearch[0]['live'], 'search source must carry the SAME liveness');
		$this->assertSame($fromLs[0]['live_agent'], $fromSearch[0]['live_agent']);
		$this->assertTrue($gy->searchRowJson($fromSearch[0])['live'], 'and it must reach the JSON view');
	}

	public function testTheUnionIsEmptyWhenNoTransportIsReachable(): void
	{
		$gy = $this->registryGraveyard([
			new FakeTransport('cmux', [FakeTransport::row('cmux', 'a')], false),
			new FakeTransport('herdr', [FakeTransport::row('herdr', 'b')], false),
		]);

		$this->assertSame([], $gy->liveSessions());
	}

	/**
	 * A row carries its own transport, which is why bury needs no flag — and why it
	 * must DRIVE that transport. cmux is the primary here, so a bury that read
	 * `$this->transport` would send `/export` to a cmux surface whose ref happens to
	 * match, i.e. into some other agent's REPL.
	 *
	 * Driven through the real buryOne(), which reads the target's screen before it
	 * types anything and then refuses at gate 1 (a fake screen shows no Claude
	 * statusline) — so the assertion is about which transport was asked, with nothing
	 * sent and nothing killed.
	 */
	public function testBuryDrivesTheTransportThatReportedTheRow(): void
	{
		$cmux  = new FakeTransport('cmux', [FakeTransport::row('cmux', 'a')]);
		$herdr = new FakeTransport('herdr', [FakeTransport::row('herdr', 'b', ['surface_ref' => 'wF:p3', 'workspace_ref' => 'wF', 'pid' => 0])]);
		$gy    = $this->registryGraveyard([$cmux, $herdr]);
		$this->cli->forceSilent = true;

		$this->assertFalse($gy->buryOne($herdr->liveSessions()[0], false, true), 'gate 1 refuses a fake screen');

		$this->assertSame(['readScreen'], $herdr->calledMethods());
		$this->assertSame([], $cmux->calledMethods(), 'the primary transport must never be touched for a herdr row');
	}

	/**
	 * A row says which multiplexer hosts it, in the view JT actually reads.
	 *
	 * It rides the same `kind` column as the agent axis, and the incumbent stays blank —
	 * which is what keeps a cmux-only install's `candidates` output unchanged.
	 */
	public function testACandidateLineNamesANonCmuxRowAndLeavesCmuxBlank(): void
	{
		$row = FakeTransport::row('herdr', 'b', ['tab_title' => 'Callee lifecycle', 'idle_seconds' => 300]);

		$line = $this->gy->candidateLine($row, 120, '/Users/JT', 5);
		$this->assertStringContainsString('herdr', $line);
		$this->assertStringContainsString('Callee lifecycle', $line);
		$this->assertStringNotContainsString('[herdr]', $line);
		$this->assertLessThan(mb_strpos($line, 'Callee lifecycle'), mb_strpos($line, 'herdr'));

		$row['transport'] = 'cmux';
		$this->assertStringNotContainsString('cmux', $this->gy->candidateLine($row, 120, '/Users/JT', 5));

		// A row from before the union names no transport and must read as cmux.
		unset($row['transport']);
		$this->assertSame(0, $this->gy->candidateKindWidth([$row]));
	}

	/**
	 * candidates() is a VIEW of the union, so the narrowed candidate row has to carry
	 * the transport through — otherwise the tag and the --json field both quietly
	 * report cmux for every session, whoever hosts it.
	 */
	public function testACandidateRowCarriesTheTransportThroughTheNarrowing(): void
	{
		$row = $this->gy->candidateRowFor(FakeTransport::row('herdr', 'b'), false);
		$this->assertSame('herdr', $row['transport']);
		$this->assertSame('herdr', $this->gy->candidatesJson([$row])[0]['transport']);

		$cmux = $this->gy->candidateRowFor(FakeTransport::row('cmux', 'a'), false);
		$this->assertSame('cmux', $cmux['transport']);
	}

	/**
	 * candidates() decides `busy` from a SCREEN, so that read has to go to the row's
	 * own transport. Read the wrong one and the answer to "is this session busy?" comes
	 * off somebody else's pane — and bury's --force prompt is built on it.
	 */
	public function testTheCandidatesBusyProbeReadsTheRowsOwnTransport(): void
	{
		$cmux  = new FakeTransport('cmux', [FakeTransport::row('cmux', 'a', ['surface_ref' => 'surface:1', 'workspace_ref' => 'workspace:1'])]);
		$herdr = new FakeTransport('herdr', [FakeTransport::row('herdr', 'b', ['surface_ref' => 'wF:p3', 'workspace_ref' => 'wF'])]);
		$gy    = $this->registryGraveyard([$cmux, $herdr]);

		$rows = $gy->candidates();

		$this->assertSame(['a', 'b'], array_column($rows, 'session_id'));
		$this->assertSame([['readScreen', ['surface:1', 'workspace:1', 6]]], $cmux->calls);
		$this->assertSame([['readScreen', ['wF:p3', 'wF', 6]]], $herdr->calls);
	}

	// ── Job 3: the degraded restore names what it drops ───────────────────────

	private function mixedLayout(): array
	{
		return [
			['group_pos' => 0, 'pane_index' => 0, 'index_in_pane' => 0, 'type' => 'terminal', 'title' => 'claude', 'kind' => 'claude', 'claude_session_id' => self::HERDR_SID, 'cwd' => '/x'],
			['group_pos' => 1, 'pane_index' => 0, 'index_in_pane' => 1, 'type' => 'browser',  'title' => 'PR #2393', 'kind' => 'browser', 'url' => 'https://example.test/pr', 'cwd' => null],
			['group_pos' => 2, 'pane_index' => 1, 'index_in_pane' => 0, 'type' => 'markdown', 'title' => 'plan.md', 'kind' => 'shell', 'cwd' => '/x'],
		];
	}

	public function testDegradedRestoreNamesEveryDroppedSurfaceByTypeAndTitle(): void
	{
		$herdr = new FakeTransport('herdr', []);
		$gy    = $this->registryGraveyard([$herdr]);

		$warnings = implode("\n", $gy->degradedRestoreWarnings($this->mixedLayout(), ['window_ref' => 'wF:t1'], $herdr));

		$this->assertStringContainsString('[browser] PR #2393', $warnings);
		$this->assertStringContainsString('[markdown] plan.md', $warnings);
		// Not a bare count — the point is knowing WHICH surfaces you are giving up.
		$this->assertStringNotContainsString('[terminal] claude', $warnings);
	}

	public function testDegradedRestoreWarnsThatAMultiTabPaneBecomesSeveralPanes(): void
	{
		$herdr = new FakeTransport('herdr', []);
		$gy    = $this->registryGraveyard([$herdr]);

		$warnings = implode("\n", $gy->degradedRestoreWarnings($this->mixedLayout(), [], $herdr));

		$this->assertMatchesRegularExpression('/pane .* separate .*pane/i', $warnings);
	}

	public function testDegradedRestoreWarnsThatGeometryAndTheOriginalTabAreLost(): void
	{
		$herdr = new FakeTransport('herdr', []);
		$gy    = $this->registryGraveyard([$herdr]);

		$warnings = implode("\n", $gy->degradedRestoreWarnings($this->mixedLayout(), ['window_ref' => 'wF:t1'], $herdr));

		$this->assertMatchesRegularExpression('/ratio|orientation|geometr/i', $warnings);
		$this->assertMatchesRegularExpression('/tab it was buried from|original tab/i', $warnings);
	}

	public function testASinglePaneTerminalOnlyRestoreLosesNothingAndAsksNothing(): void
	{
		$herdr = new FakeTransport('herdr', []);
		$gy    = $this->registryGraveyard([$herdr]);

		$layout = [['group_pos' => 0, 'pane_index' => 0, 'index_in_pane' => 0, 'type' => 'terminal', 'title' => 'claude', 'kind' => 'claude', 'cwd' => '/x']];

		$this->assertSame([], $gy->degradedRestoreWarnings($layout, [], $herdr));
	}

	public function testCmuxRestoreWarnsAboutNothingBecauseItLosesNothing(): void
	{
		$cmux = new FakeTransport('cmux', [], true, true);
		$gy   = $this->registryGraveyard([$cmux]);

		$this->assertSame([], $gy->degradedRestoreWarnings($this->mixedLayout(), ['window_ref' => 'window:2'], $cmux));
	}

	/** A declined confirm must abort before anything is created — not half-restore. */
	public function testADeclinedDegradedRestoreCreatesNoWorkspace(): void
	{
		$herdr = new FakeTransport('herdr', []);
		$gy    = $this->registryGraveyard([$herdr]);
		$this->seedGroup($gy, 'gy-degraded-0001', $this->mixedLayout());

		// No -y and no tty: there is nobody to answer the prompt, so the restore refuses
		// rather than dropping the browser and markdown surfaces silently.
		$this->cli->forceInteractive = false;

		ob_start();
		$gy->resurrectWorkspace('gy-degraded-0001');
		$out = (string) ob_get_clean();

		$this->assertSame([], $herdr->calledMethods(), 'a refused restore must not create a workspace or a pane');
		// Asserted explicitly, because without it this test would pass on a closed
		// stdin — and HANG on a tty — instead of exercising the refusal.
		$this->assertStringContainsString('Nothing here can answer a prompt', $out);
		$this->assertStringContainsString('nothing was created', $out);
		$this->assertStringContainsString('[browser] PR #2393', $out, 'and it says what it would have dropped');
	}

	/**
	 * The cross-transport hazard: a workspace buried under cmux stores a CMUX layout
	 * tree, and herdr must not pretend to understand it.
	 *
	 * HerdrTransport::layoutTreeSurfaceCount() answers 0, which can never equal a
	 * member count, so Graveyard's geometry gate closes and the restore degrades to
	 * the manual pane rebuild instead of handing a cmux tree to `herdr workspace
	 * create` — which takes no layout at all.
	 */
	public function testHerdrReadsACmuxLayoutTreeAsNoSurfacesSoTheGeometryGateCloses(): void
	{
		$cmuxTree = ['type' => 'split', 'direction' => 'row', 'ratio' => 0.5, 'children' => [
			['pane' => ['surfaces' => [['ref' => 'surface:1'], ['ref' => 'surface:2']]]],
			['pane' => ['surfaces' => [['ref' => 'surface:3']]]],
		]];

		$herdr = new HerdrTransport($this->cli, new Herdr($this->cli));
		$gyHerdr = new Graveyard($this->cli, $herdr);

		$this->assertSame(0, $gyHerdr->layoutTreeSurfaceCount($cmuxTree));
		$this->assertSame(3, $this->gy->layoutTreeSurfaceCount($cmuxTree), 'cmux still counts its own tree');
		$this->assertNull($herdr->captureLayoutTree('wF'), 'and herdr never captures one to begin with');
	}

	private function seedGroup(Graveyard $gy, string $gid, array $layout): void
	{
		$dir = $this->graveyardRoot . '/workspaces/' . $gid;
		mkdir($dir, 0777, true);
		file_put_contents($dir . '/manifest.json', json_encode([
			'group_id' => $gid, 'group_title' => 'mixed plot', 'window_ref' => 'window:2',
			'layout' => $layout,
		]));
	}
}
