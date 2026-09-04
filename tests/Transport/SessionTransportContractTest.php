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
		$this->assertNull($t->resolveWorkspace('anything'));
		$this->assertSame(0, $t->workspaceSurfaceCount('w1'));
		$this->assertNull($t->paneRefForSurface('w1', 's1'));
		$this->assertNull($t->captureLayoutTree('w1'));
		$this->assertFalse($t->supportsNonTerminalSurfaces());
	}

	/**
	 * The seam must not grow cmux-shaped methods. Task 3c asserts this exhaustively;
	 * this is the standing guard for the four worst offenders, which would each force
	 * every other transport to fabricate a cmux data structure.
	 */
	public function test_the_interface_exposes_no_cmux_shaped_method(): void
	{
		$methods = array_map(
			fn(\ReflectionMethod $m) => $m->getName(),
			(new \ReflectionClass(SessionTransport::class))->getMethods()
		);
		foreach (['tree', 'debugTerminals', 'parseDebugTerminals', 'mapSurfaceUuids', 'cmuxBin', 'cmux'] as $leak) {
			$this->assertNotContains($leak, $methods);
		}
	}
}
