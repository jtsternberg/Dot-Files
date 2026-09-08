<?php
namespace JT\Tests\Transport;

use JT\Transport\CmuxTransport;
use JT\Transport\NullTransport;
use JT\Transport\SessionTransport;
use JT\Tests\TestCase;

/**
 * The seam's contract, stated once so a second transport inherits it rather than
 * re-deriving it. What is asserted here is deliberately narrow: the identity, the
 * row stamp, and the refusals. Everything cmux-specific belongs in CmuxTest.
 */
final class SessionTransportContractTest extends TestCase
{
	public function test_cmux_transport_reports_its_name(): void
	{
		$this->assertSame('cmux', $this->transport->name());
	}

	public function test_cmux_transport_supports_non_terminal_surfaces(): void
	{
		$this->assertTrue($this->transport->supportsNonTerminalSurfaces());
	}

	/**
	 * A caller holding a row must be able to tell which transport to hand it back to,
	 * without consulting anything else. CMUX_BIN points at TestCase's empty-tree stub,
	 * so this asserts the stamp over whatever rows the join produces (including none).
	 */
	public function test_live_session_rows_are_stamped_with_their_transport(): void
	{
		$rows = $this->transport->liveSessions();
		$this->assertIsArray($rows);
		foreach ($rows as $row) {
			$this->assertSame('cmux', $row['transport']);
		}
	}

	/**
	 * The row shape IS the contract — bury reads these keys, and a dropped one silently
	 * changes what a resurrected session gets back (`opts` carries codex's
	 * sandbox/approval). Pin the key set against the interface's documented shape.
	 */
	public function test_the_documented_row_keys_are_the_ones_the_join_emits(): void
	{
		$expected = [
			'transport', 'session_id', 'agent', 'cwd', 'model', 'skip_perms', 'opts',
			'pid', 'tty', 'surface_ref', 'surface_id', 'home_workspace_id', 'home_pane_id',
			'pane_ref', 'home_index_in_pane', 'workspace_ref', 'window_ref',
			'workspace_title', 'tab_title', 'idle_seconds', 'targetable', 'reason',
			'no_bridge',
		];

		$transport = new class ($this->cli, $this->cmux) extends CmuxTransport {
			public function rowFor(array $join): array {
				return $this->liveSessionRow($join, $this->treeIndex([]), time());
			}
		};

		$this->assertSame($expected, array_keys($transport->rowFor([
			'session_id' => 'a', 'agent' => 'claude', 'cwd' => '/x', 'model' => null,
			'skip_perms' => false, 'pid' => null, 'tty' => null, 'surface_ref' => '',
			'workspace_ref' => '', 'title' => '', 'targetable' => false, 'reason' => null,
		])));
	}

	public function test_null_transport_reports_nothing_live_and_refuses_to_drive(): void
	{
		$t = new NullTransport($this->cli);
		$this->assertSame('null', $t->name());
		$this->assertFalse($t->available());
		$this->assertSame([], $t->liveSessions());

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('sendText is unavailable in the transport-free page server.');
		$t->sendText('s1', 'w1', 'hello');
	}

	/**
	 * A silent no-op here would look like a successful restore, so every verb that
	 * could create or tear something down has to fail loudly.
	 */
	public function test_null_transport_refuses_every_create_and_teardown_verb(): void
	{
		$t = new NullTransport($this->cli);
		$calls = [
			fn() => $t->sendKey('s1', 'w1', 'Return'),
			fn() => $t->selectSurface('w1', 's1'),
			fn() => $t->newSurface('w1', null, 'terminal', null),
			fn() => $t->newSplit('w1', 's1', 'right'),
			fn() => $t->newWorkspace('t', null),
			fn() => $t->newWorkspaceWithLayout('t', null, []),
			fn() => $t->closeWorkspace('w1'),
			fn() => $t->closeSurface('s1'),
		];
		foreach ($calls as $i => $call) {
			try {
				$call();
				$this->fail("call #{$i} should have refused");
			} catch (\LogicException $e) {
				$this->assertStringContainsString('unavailable in the transport-free page server.', $e->getMessage());
			}
		}
	}

	public function test_null_transport_read_only_verbs_answer_empty(): void
	{
		$t = new NullTransport($this->cli);
		$this->assertSame('', $t->readScreen('s1', 'w1'));
		$this->assertSame([], $t->surfaces());
		$this->assertSame([], $t->surfaces('w1'));
		$this->assertFalse($t->windowExists('window:1'));
		$this->assertNull($t->resolveWorkspace('anything'));
		$this->assertSame(0, $t->workspaceSurfaceCount('w1'));
		$this->assertNull($t->paneRefForSurface('w1', 's1'));
		$this->assertNull($t->captureLayoutTree('w1'));
		$this->assertFalse($t->supportsNonTerminalSurfaces());
	}

	/**
	 * The seam must not grow cmux-shaped methods. Task 3c asserts this exhaustively;
	 * this is the standing guard for the worst offenders, which would each force every
	 * other transport to fabricate a cmux data structure. The list grows as bury's
	 * re-seating retires each one: surfaces() is what replaced the tree walk, the
	 * debug-terminals dump and the surface-UUID map, so none of the three may come back.
	 */
	public function test_the_interface_exposes_no_cmux_shaped_method(): void
	{
		$methods = array_map(
			fn(\ReflectionMethod $m) => $m->getName(),
			(new \ReflectionClass(SessionTransport::class))->getMethods()
		);
		$leaks = [
			'tree', 'debugTerminals', 'parseDebugTerminals', 'mapSurfaceUuids', 'cmuxBin', 'cmux',
			'treeIndex', 'joinSessionsToSurfaces', 'joinCodexToSurfaces',
			'loadClaudeSessionsByPid', 'loadCodexSessionsByPid', 'codexSurfaceIdsByPid',
			'windowRefExists',
			// Not cmux-shaped, but not the transport's business either: Claude Code
			// writes ~/.claude/sessions/<pid>.json whatever multiplexer it runs under,
			// so this is an artifact read (AgentArtifacts::claudeSessionIdForPid). On
			// the seam, every transport reimplements it — and one answering null fails
			// bury's GATE 3 closed.
			'sessionIdForPid',
		];
		foreach ($leaks as $leak) {
			$this->assertNotContains($leak, $methods);
		}
	}

	/**
	 * And the seam must not be routed AROUND. Graveyard held a private cmux()/
	 * cmuxTransport() pair through 3a/3b so the un-re-seated verbs could keep walking a
	 * cmux tree; with resurrect moved onto surfaces() nothing above the seam needs a cmux
	 * client, and a re-added hatch would re-couple graveyard to cmux without changing the
	 * interface the test above guards — so the leak would go unnoticed.
	 */
	public function test_graveyard_holds_no_escape_hatch_to_the_concrete_transport(): void
	{
		$methods = array_map(
			fn(\ReflectionMethod $m) => $m->getName(),
			(new \ReflectionClass(\JT\Graveyard::class))->getMethods()
		);
		$this->assertNotContains('cmux', $methods);
		$this->assertNotContains('cmuxTransport', $methods);

		// Reflection only sees a NAMED hatch; an inline instanceof or a new CmuxTransport
		// would slip past it. Comments are stripped first — prose about the transport is
		// not a dependency on it.
		$code = '';
		foreach (token_get_all(file_get_contents(dirname(__DIR__, 2) . '/src/Graveyard.php')) as $tok) {
			if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
			$code .= is_array($tok) ? $tok[1] : $tok;
		}
		$this->assertStringNotContainsString('CmuxTransport', $code);
		$this->assertStringNotContainsString('Helpers\\Cmux', $code);
	}

	/**
	 * A stale window handle must read as gone rather than be handed to a create call:
	 * cmux refs are only stable within one running cmux, so a ref recorded before a
	 * restart names whatever occupies that slot now.
	 */
	public function test_windowExists_answers_off_the_transports_own_world(): void
	{
		$cmux = new class ($this->cli) extends \JT\Helpers\Cmux {
			public function tree(): array {
				return ['windows' => [['ref' => 'window:2', 'workspaces' => []]]];
			}
		};
		$t = new CmuxTransport($this->cli, $cmux);

		$this->assertTrue($t->windowExists('window:2'));
		$this->assertFalse($t->windowExists('window:9'));
		$this->assertFalse($t->windowExists(''));
	}

	/**
	 * surfaces() is the shape bury classification reads. Its rows must be complete —
	 * a missing key reads as "not an agent surface" or "no tty", and the surface then
	 * gets closed as a shell instead of archived (dotfiles-5p5, data loss) — so pin
	 * the key set the way liveSessions()' is pinned.
	 */
	public function test_the_documented_surface_row_keys_are_the_ones_the_walk_emits(): void
	{
		$expected = [
			'position', 'pane_index', 'pane_ref', 'pane_id', 'selected_in_pane',
			'surface_ref', 'surface_id', 'workspace_ref', 'workspace_id', 'workspace_title',
			'window_ref',
			'type', 'title', 'url', 'tty', 'cwd', 'script',
			'session_id', 'agent', 'pid', 'targetable', 'reason',
		];

		// A cmux whose whole world is one workspace holding one terminal, so the walk
		// runs for real without shelling out anywhere.
		$cmux = new class ($this->cli) extends \JT\Helpers\Cmux {
			public function tree(): array {
				return ['windows' => [['ref' => 'window:1', 'workspaces' => [
					['ref' => 'workspace:2', 'id' => 'WS-UUID', 'title' => 'boss', 'panes' => [
						['ref' => 'pane:3', 'id' => 'PANE-UUID', 'index' => 0, 'surfaces' => [
							['ref' => 'surface:4', 'id' => 'SURF-UUID', 'type' => 'terminal', 'title' => 'zsh'],
						]],
					]],
				]]]];
			}
			public function debugTerminals(): string { return ''; }
			public function psProcTable(): string { return ''; }
			public function loadClaudeSessionsByPid(): array { return []; }
			public function loadCodexSessionsByPid(): array { return []; }
			public function codexSurfaceIdsByPid(): array { return []; }
		};

		$rows = (new CmuxTransport($this->cli, $cmux))->surfaces();
		$this->assertCount(1, $rows);
		$this->assertSame($expected, array_keys($rows[0]));
		$this->assertSame('surface:4', $rows[0]['surface_ref']);
		$this->assertSame('workspace:2', $rows[0]['workspace_ref']);
		// The stable id, not just the positional ref: resurrect matches a tombstone's
		// recorded home against this, because refs get reassigned.
		$this->assertSame('WS-UUID', $rows[0]['workspace_id']);
		$this->assertNull($rows[0]['agent'], 'a plain shell is bound to no agent');

		// Scoping is by workspace ref, and an unknown one yields nothing at all.
		$this->assertCount(1, (new CmuxTransport($this->cli, $cmux))->surfaces('workspace:2'));
		$this->assertSame([], (new CmuxTransport($this->cli, $cmux))->surfaces('workspace:99'));
	}

	/**
	 * A codex TUI that has not written a rollout yet is still a codex surface. It has
	 * no session id to report, and reading that as "no agent here" is what closes a
	 * live session unarchived (dotfiles-5p5).
	 */
	public function test_a_zero_turn_codex_still_claims_its_surface(): void
	{
		$cmux = new class ($this->cli) extends \JT\Helpers\Cmux {
			public function tree(): array {
				return ['windows' => [['ref' => 'window:1', 'workspaces' => [
					['ref' => 'workspace:2', 'title' => 'boss', 'panes' => [
						['ref' => 'pane:3', 'index' => 0, 'surfaces' => [
							['ref' => 'surface:4', 'id' => 'SURF-UUID', 'type' => 'terminal', 'title' => 'codex'],
						]],
					]],
				]]]];
			}
			public function debugTerminals(): string { return ''; }
			public function psProcTable(): string { return ''; }
			public function loadClaudeSessionsByPid(): array { return []; }
			public function loadCodexSessionsByPid(): array { return []; } // no rollout yet
			public function codexSurfaceIdsByPid(): array { return [4242 => 'SURF-UUID']; }
		};

		$row = (new CmuxTransport($this->cli, $cmux))->surfaces()[0];
		$this->assertSame('codex', $row['agent']);
		$this->assertNull($row['session_id']);
		$this->assertSame(4242, $row['pid']);
		$this->assertFalse($row['targetable']);
	}
}
