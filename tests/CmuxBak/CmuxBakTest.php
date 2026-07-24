<?php
namespace JT\Tests\CmuxBak;

use JT\Tests\TestCase;
use JT\CmuxBak;

/**
 * First tests for `cmux-bak` — the pure/no-I-O helpers only:
 *  - normalizeTitle() strips Claude Code's leading status glyph (✳ / braille spinner)
 *    plus following whitespace, for backup↔restore title matching
 *  - firstCwdFromBakWs() finds the first non-empty cwd across panes[].surfaces[]
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
		$ws = ['panes' => [
			['surfaces' => [['cwd' => ''], ['cwd' => null]]],
			['surfaces' => [['cwd' => '/first'], ['cwd' => '/second']]],
		]];
		$this->assertSame('/first', $this->invokeProtected('firstCwdFromBakWs', [$ws]));
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
			['session_id' => 'AAA', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => 'opus', 'skip_perms' => true],
			['session_id' => 'BBB', 'surface_ref' => 'surface:70', 'cwd' => '/b', 'model' => null,   'skip_perms' => false],
		];

		$workspaces = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, []]);

		$surfaces = $workspaces[0]['panes'][0]['surfaces'];
		$this->assertSame('AAA', $surfaces[0]['claude_session_id']);
		$this->assertSame('/a',  $surfaces[0]['cwd']);
		$this->assertTrue($surfaces[0]['claude_skip_permissions']);
		$this->assertSame('opus', $surfaces[0]['claude_model']);

		$this->assertSame('BBB', $surfaces[1]['claude_session_id']);
		$this->assertSame('/b',  $surfaces[1]['cwd']);
		$this->assertFalse($surfaces[1]['claude_skip_permissions']);
		$this->assertNull($surfaces[1]['claude_model']);
	}

	public function testBuildWorkspacesDataNeverDuplicatesASessionAcrossSurfaces(): void
	{
		// The tty-keyed backup stamped whichever session shared ttys052 onto BOTH
		// surfaces. Bound by surface_ref, each id appears exactly once.
		$joinRows = [
			['session_id' => 'AAA', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => null, 'skip_perms' => false],
			['session_id' => 'BBB', 'surface_ref' => 'surface:70', 'cwd' => '/b', 'model' => null, 'skip_perms' => false],
		];

		$workspaces = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, []]);

		$ids = [];
		foreach ($workspaces[0]['panes'][0]['surfaces'] as $s) {
			if ($s['claude_session_id'] !== null) {
				$ids[] = $s['claude_session_id'];
			}
		}
		$this->assertSame($ids, array_values(array_unique($ids)), 'no session id may appear on two surfaces');
		$this->assertCount(2, $ids);
	}

	public function testBuildWorkspacesDataTerminalWithoutSessionFallsBackToCwdMap(): void
	{
		// A plain shell surface (no live Claude) has no join row; its cwd comes
		// from the debug-terminals cwd map, keyed by surface_ref.
		$joinRows  = [['session_id' => 'AAA', 'surface_ref' => 'surface:59', 'cwd' => '/a', 'model' => null, 'skip_perms' => false]];
		$cwdBySurf = ['surface:70' => '/plain/shell'];

		$workspaces = $this->invokeProtected('buildWorkspacesData', [$this->collidingTree(), $joinRows, $cwdBySurf]);

		$plain = $workspaces[0]['panes'][0]['surfaces'][1];
		$this->assertNull($plain['claude_session_id']);
		$this->assertSame('/plain/shell', $plain['cwd']);
	}

	// ── surfaceClaudeStatus: restore liveness by surface_ref, not tty ──────────
	//
	// Also dotfiles-e5g: restore must decide "is a Claude live on THIS surface?"
	// by surface_ref. A tty key false-positives when a *different* surface shares
	// the tty, making restore skip a surface it should have resumed.

	public function testSurfaceStatusResumeWhenNoLiveClaudeOnSurface(): void
	{
		// A sibling surface (surface:70) shares the tty and IS live, but our target
		// surface:59 has none — must resume, not be fooled by the shared tty.
		$liveBySurf = ['surface:70' => ['session_id' => 'BBB']];
		$this->assertSame('resume', $this->invokeProtected('surfaceClaudeStatus', [$liveBySurf, 'surface:59', 'AAA']));
	}

	public function testSurfaceStatusSameWhenOurSessionIsLiveOnSurface(): void
	{
		$liveBySurf = ['surface:59' => ['session_id' => 'AAA']];
		$this->assertSame('same', $this->invokeProtected('surfaceClaudeStatus', [$liveBySurf, 'surface:59', 'AAA']));
	}

	public function testSurfaceStatusOtherWhenADifferentSessionIsLiveOnSurface(): void
	{
		$liveBySurf = ['surface:59' => ['session_id' => 'ZZZ']];
		$this->assertSame('other', $this->invokeProtected('surfaceClaudeStatus', [$liveBySurf, 'surface:59', 'AAA']));
	}
}
