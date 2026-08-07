<?php
namespace JT\Tests\CmuxBak;

use JT\Tests\TestCase;
use JT\CmuxBak;

/**
 * First tests for `cmux-bak` — the pure/no-I-O helpers only:
 *  - normalizeTitle() strips Claude Code's leading status glyph (✳ / braille spinner)
 *    plus following whitespace, for backup↔restore title matching
 *  - firstCwdFromBakWs() finds the first non-empty, still-existing cwd across
 *    panes[].surfaces[]
 *  - allSurfacesFromBakWs() flattens panes[].surfaces[] in pane order
 *
 * The helpers are protected, so tests reach them through a small reflection
 * helper (no existing reflection pattern in the suite — established here).
 */
final class CmuxBakTest extends TestCase
{
	protected CmuxBak $bak;

	protected function setUp(): void
	{
		parent::setUp();
		$this->bak = new CmuxBak($this->cli);
	}

	/** Invoke a protected CmuxBak method. */
	protected function invokeProtected(string $method, array $args = [])
	{
		$ref = new \ReflectionMethod(CmuxBak::class, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($this->bak, $args);
	}

	public function testInstantiates(): void
	{
		$this->assertInstanceOf(CmuxBak::class, new CmuxBak($this->cli));
	}

	public function testNormalizeTitleStripsLeadingStatusGlyph(): void
	{
		$this->assertSame('my tab', $this->invokeProtected('normalizeTitle', ['✳ my tab']));
		$this->assertSame('working', $this->invokeProtected('normalizeTitle', ['⠂ working']));
	}

	public function testNormalizeTitleLeavesPlainTitlesAlone(): void
	{
		$this->assertSame('plain title', $this->invokeProtected('normalizeTitle', ['plain title']));
	}

	public function testNormalizeTitleLeavesNonAsciiNotAtStartAlone(): void
	{
		$this->assertSame('my ✳ tab', $this->invokeProtected('normalizeTitle', ['my ✳ tab']));
	}

	public function testNormalizeTitleGlyphOnlyBecomesEmpty(): void
	{
		$this->assertSame('', $this->invokeProtected('normalizeTitle', ['✳']));
	}

	public function testFirstCwdReturnsNullForEmptyWorkspace(): void
	{
		$this->assertNull($this->invokeProtected('firstCwdFromBakWs', [[]]));
	}

	public function testFirstCwdReturnsNullWhenAllCwdsEmpty(): void
	{
		$ws = ['panes' => [
			['surfaces' => [['cwd' => null], ['cwd' => '']]],
			['surfaces' => [['ref' => 's:3']]],
		]];
		$this->assertNull($this->invokeProtected('firstCwdFromBakWs', [$ws]));
	}

	public function testFirstCwdReturnsFirstNonEmptyIncludingLaterPane(): void
	{
		$first  = $this->existingDir('first');
		$second = $this->existingDir('second');
		$ws = ['panes' => [
			['surfaces' => [['cwd' => ''], ['cwd' => null]]],
			['surfaces' => [['cwd' => $first], ['cwd' => $second]]],
		]];
		$this->assertSame($first, $this->invokeProtected('firstCwdFromBakWs', [$ws]));
	}

	/**
	 * A recorded cwd can be deleted between backup and restore. Handing a gone
	 * directory to `workspace create --cwd` either fails the create or opens the
	 * workspace somewhere unexpected, so stale entries are passed over.
	 */
	public function testFirstCwdSkipsDirectoriesThatNoLongerExist(): void
	{
		$live = $this->existingDir('live');
		$ws = ['panes' => [
			['surfaces' => [['cwd' => $this->graveyardRoot . '/gone-for-good'], ['cwd' => $live]]],
		]];
		$this->assertSame($live, $this->invokeProtected('firstCwdFromBakWs', [$ws]));
	}

	public function testFirstCwdReturnsNullWhenEveryRecordedCwdIsGone(): void
	{
		$ws = ['panes' => [
			['surfaces' => [['cwd' => $this->graveyardRoot . '/gone-a'], ['cwd' => $this->graveyardRoot . '/gone-b']]],
		]];
		$this->assertNull($this->invokeProtected('firstCwdFromBakWs', [$ws]));
	}

	/** A directory that really exists, inside this test's throwaway root. */
	private function existingDir(string $name): string
	{
		$dir = $this->graveyardRoot . '/' . $name;
		mkdir($dir, 0777, true);

		return $dir;
	}

	public function testAllSurfacesEmptyWorkspace(): void
	{
		$this->assertSame([], $this->invokeProtected('allSurfacesFromBakWs', [[]]));
		$this->assertSame([], $this->invokeProtected('allSurfacesFromBakWs', [['panes' => []]]));
	}

	public function testAllSurfacesFlattensInPaneOrder(): void
	{
		$ws = ['panes' => [
			['surfaces' => [['ref' => 's:1'], ['ref' => 's:2']]],
			['surfaces' => [['ref' => 's:3']]],
		]];
		$surfs = $this->invokeProtected('allSurfacesFromBakWs', [$ws]);
		$this->assertSame(['s:1', 's:2', 's:3'], array_column($surfs, 'ref'));
	}

	// ── buildWorkspacesData: bind sessions to surfaces by surface_ref, not tty ──
	//
	// Regression for dotfiles-e5g: two surfaces that share a recycled tty must not
	// both inherit the same Claude session id. The backup binds each session to the
	// exact surface it launched, via the deterministic join's surface_ref.

	/** A tree with two terminal surfaces that share one recycled tty (ttys052). */
	private function collidingTree(): array
	{
		return [[
			'workspaces' => [[
				'title' => 'ws-a',
				'ref'   => 'workspace:1',
				'panes' => [[
					'ref'   => 'pane:1',
					'index' => 0,
					'surfaces' => [
						['ref' => 'surface:59', 'title' => '✳ alpha', 'type' => 'terminal', 'tty' => 'ttys052', 'index_in_pane' => 0],
						['ref' => 'surface:70', 'title' => '✳ beta',  'type' => 'terminal', 'tty' => 'ttys052', 'index_in_pane' => 1],
					],
				]],
			]],
		]];
	}

	public function testBuildWorkspacesDataBindsEachSessionToItsOwnSurface(): void
	{
		$joinRows = [
			['session_id' => 'AAA', 'agent' => 'claude', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => 'opus', 'skip_perms' => true],
			['session_id' => 'BBB', 'agent' => 'claude', 'surface_ref' => 'surface:70', 'cwd' => '/b', 'model' => null,   'skip_perms' => false],
		];

		$workspaces = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, []]);

		$surfaces = $workspaces[0]['panes'][0]['surfaces'];
		$this->assertSame('AAA', $surfaces[0]['agent_session_id']);
		$this->assertSame('claude', $surfaces[0]['agent']);
		$this->assertSame('/a',  $surfaces[0]['cwd']);
		$this->assertTrue($surfaces[0]['agent_skip_permissions']);
		$this->assertSame('opus', $surfaces[0]['agent_model']);

		$this->assertSame('BBB', $surfaces[1]['agent_session_id']);
		$this->assertSame('/b',  $surfaces[1]['cwd']);
		$this->assertFalse($surfaces[1]['agent_skip_permissions']);
		$this->assertNull($surfaces[1]['agent_model']);
	}

	public function testBuildWorkspacesDataNeverDuplicatesASessionAcrossSurfaces(): void
	{
		// The tty-keyed backup stamped whichever session shared ttys052 onto BOTH
		// surfaces. Bound by surface_ref, each id appears exactly once.
		$joinRows = [
			['session_id' => 'AAA', 'agent' => 'claude', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => null, 'skip_perms' => false],
			['session_id' => 'BBB', 'agent' => 'claude', 'surface_ref' => 'surface:70', 'cwd' => '/b', 'model' => null, 'skip_perms' => false],
		];

		$workspaces = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, []]);

		$ids = [];
		foreach ($workspaces[0]['panes'][0]['surfaces'] as $s) {
			if ($s['agent_session_id'] !== null) {
				$ids[] = $s['agent_session_id'];
			}
		}
		$this->assertSame($ids, array_values(array_unique($ids)), 'no session id may appear on two surfaces');
		$this->assertCount(2, $ids);
	}

	public function testBuildWorkspacesDataTerminalWithoutSessionFallsBackToCwdMap(): void
	{
		// A plain shell surface (no live agent) has no join row; its cwd comes
		// from the debug-terminals cwd map, keyed by surface_ref.
		$joinRows  = [['session_id' => 'AAA', 'agent' => 'claude', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => null, 'skip_perms' => false]];
		$cwdBySurf = ['surface:70' => '/plain/shell'];

		$workspaces = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, $cwdBySurf]);

		$plain = $workspaces[0]['panes'][0]['surfaces'][1];
		$this->assertNull($plain['agent_session_id']);
		$this->assertNull($plain['agent']);
		$this->assertSame('/plain/shell', $plain['cwd']);
	}

	// ── schema v2: one generic agent per surface (dotfiles-zcm) ────────────────

	public function testBuildWorkspacesDataRecordsCodexAndClaudeSideBySide(): void
	{
		// A workspace can host both agents at once; each surface records which one
		// it is, so restore knows whether to send `claude --resume` or `codex resume`.
		$joinRows = [
			['session_id' => 'AAA', 'agent' => 'claude', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => 'opus', 'skip_perms' => true],
			['session_id' => '019fa599-6b5f-7de1-9822-52643135bb95', 'agent' => 'codex', 'surface_ref' => 'surface:70', 'cwd' => '/b', 'model' => null, 'skip_perms' => false],
		];

		$surfaces = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, []])[0]['panes'][0]['surfaces'];

		$this->assertSame('claude', $surfaces[0]['agent']);
		$this->assertSame('codex', $surfaces[1]['agent']);
		$this->assertSame('019fa599-6b5f-7de1-9822-52643135bb95', $surfaces[1]['agent_session_id']);
	}

	public function testBuildWorkspacesDataDropsTheLegacyClaudeKeys(): void
	{
		// v2 replaces claude_* rather than shadowing it — a stale reader picking up
		// claude_session_id on a codex surface would send `claude --resume <uuid>`.
		$joinRows = [['session_id' => 'X', 'agent' => 'codex', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => null, 'skip_perms' => false]];

		$surf = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, []])[0]['panes'][0]['surfaces'][0];

		$this->assertArrayNotHasKey('claude_session_id', $surf);
		$this->assertArrayNotHasKey('claude_model', $surf);
		$this->assertArrayNotHasKey('claude_skip_permissions', $surf);
	}

	// ── normalizeBakSurface: read v2, and still read v1 ────────────────────────

	public function testNormalizeBakSurfaceReadsV2Fields(): void
	{
		$got = $this->invokeProtected('normalizeBakSurface', [[
			'agent'                  => 'codex',
			'agent_session_id'       => '019fa599-6b5f-7de1-9822-52643135bb95',
			'agent_model'            => null,
			'agent_skip_permissions' => false,
		]]);

		$this->assertSame('codex', $got['agent']);
		$this->assertSame('019fa599-6b5f-7de1-9822-52643135bb95', $got['session_id']);
	}

	public function testNormalizeBakSurfaceReadsLegacyV1AsClaude(): void
	{
		// bak.json is a cache regenerated wholesale by every run, so this shim only
		// has to cover a file already on disk at upgrade time.
		$got = $this->invokeProtected('normalizeBakSurface', [[
			'claude_session_id'       => 'AAA',
			'claude_model'            => 'opus',
			'claude_skip_permissions' => true,
		]]);

		$this->assertSame('claude', $got['agent']);
		$this->assertSame('AAA', $got['session_id']);
		$this->assertSame('opus', $got['model']);
		$this->assertTrue($got['skip_perms']);
	}

	public function testNormalizeBakSurfaceOnAPlainShellHasNoSession(): void
	{
		$got = $this->invokeProtected('normalizeBakSurface', [['cwd' => '/x', 'type' => 'terminal']]);

		$this->assertNull($got['session_id']);
		$this->assertNull($got['agent']);
	}

	public function testNormalizeBakSurfaceDefaultsAMissingAgentToClaude(): void
	{
		// A v2 surface written before `agent` was populated (or hand-edited) must
		// not silently become a codex resume.
		$got = $this->invokeProtected('normalizeBakSurface', [['agent_session_id' => 'AAA']]);

		$this->assertSame('claude', $got['agent']);
	}

	// ── surfaceAgentStatus: restore liveness by surface_ref, not tty ───────────
	//
	// Also dotfiles-e5g: restore must decide "is an agent live on THIS surface?"
	// by surface_ref. A tty key false-positives when a *different* surface shares
	// the tty, making restore skip a surface it should have resumed.

	public function testSurfaceStatusResumeWhenNoLiveAgentOnSurface(): void
	{
		// A sibling surface (surface:70) shares the tty and IS live, but our target
		// surface:59 has none — must resume, not be fooled by the shared tty.
		$liveBySurf = ['surface:70' => ['session_id' => 'BBB']];
		$this->assertSame('resume', $this->invokeProtected('surfaceAgentStatus', [$liveBySurf, 'surface:59', 'AAA']));
	}

	public function testSurfaceStatusSameWhenOurSessionIsLiveOnSurface(): void
	{
		$liveBySurf = ['surface:59' => ['session_id' => 'AAA']];
		$this->assertSame('same', $this->invokeProtected('surfaceAgentStatus', [$liveBySurf, 'surface:59', 'AAA']));
	}

	public function testSurfaceStatusOtherWhenADifferentSessionIsLiveOnSurface(): void
	{
		$liveBySurf = ['surface:59' => ['session_id' => 'ZZZ']];
		$this->assertSame('other', $this->invokeProtected('surfaceAgentStatus', [$liveBySurf, 'surface:59', 'AAA']));
	}

	public function testSurfaceStatusOtherWhenADifferentAgentIsLiveOnSurface(): void
	{
		// A codex sitting on the surface a Claude session was backed up from is
		// still "someone else's terminal" — leave it alone.
		$liveBySurf = ['surface:59' => ['session_id' => '019fa599-6b5f-7de1-9822-52643135bb95', 'agent' => 'codex']];
		$this->assertSame('other', $this->invokeProtected('surfaceAgentStatus', [$liveBySurf, 'surface:59', 'AAA']));
	}

	// ── per-agent counts for the backup summary ────────────────────────────────

	public function testCountSessionsByAgentTalliesEachAgentSeparately(): void
	{
		$workspaces = [[
			'panes' => [[
				'surfaces' => [
					['agent' => 'claude', 'agent_session_id' => 'AAA'],
					['agent' => 'codex',  'agent_session_id' => 'BBB'],
					['agent' => 'codex',  'agent_session_id' => 'CCC'],
					['agent' => null,     'agent_session_id' => null],
				],
			]],
		]];

		$this->assertSame(
			['claude' => 1, 'codex' => 2],
			$this->invokeProtected('countSessionsByAgent', [$workspaces])
		);
	}

	public function testCountSessionsByAgentIsEmptyWithNoSessions(): void
	{
		$this->assertSame([], $this->invokeProtected('countSessionsByAgent', [[]]));
	}
}
