<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * Never type a launch command into a surface that already hosts a live agent.
 *
 * This is the guard for a real incident. Resurrect's fallback path called
 * Cmux::newWorkspace(), which creates a workspace and then resolves it with
 * findWorkspaceByTitle() — returning the FIRST workspace with that title. The
 * tombstone's workspace_title was the title of the workspace the buried tab had
 * lived *inside*, which still existed, so resolution landed on that pre-existing
 * workspace, took panes[0].surfaces[0], and typed
 * `cd … && codex resume …` straight into a running Claude Code REPL.
 *
 * Two independent fixes, because either alone would have prevented it:
 *  - newWorkspace() identifies the workspace it actually created by diffing ids,
 *    instead of trusting a title that may not be unique.
 *  - launchSessionIntoSurface() refuses to send into a surface where a live agent
 *    is bound. A launch target must be an idle shell; anything else means the
 *    resolution was wrong, and typing into someone's REPL is unrecoverable noise.
 */
final class GraveyardLaunchSafetyTest extends TestCase
{
	private function treeWithTwoSameTitledWorkspaces(): array
	{
		return ['windows' => [[
			'ref' => 'window:2', 'id' => 'EEEEEEEE-0000-4000-8000-000000000002',
			'workspaces' => [
				// The pre-existing workspace the buried tab lived inside.
				['ref' => 'workspace:32', 'id' => 'F62E7243-D094-42CD-A9C5-F23CBFC52CD7', 'title' => 'my work',
					'panes' => [['ref' => 'pane:59', 'id' => 'AAAAAAAA-0000-4000-8000-000000000059',
						'surfaces' => [['ref' => 'surface:155', 'id' => 'CCCCCCCC-0000-4000-8000-000000000155', 'title' => 'agent', 'type' => 'terminal']]]]],
				// The one just created, same title.
				['ref' => 'workspace:40', 'id' => '11111111-0000-4000-8000-000000000040', 'title' => 'my work',
					'panes' => [['ref' => 'pane:70', 'id' => 'BBBBBBBB-0000-4000-8000-000000000070',
						'surfaces' => [['ref' => 'surface:200', 'id' => 'DDDDDDDD-0000-4000-8000-000000000200', 'title' => 'zsh', 'type' => 'terminal']]]]],
			],
		]]];
	}

	// ── identify the workspace we actually created ────────────────────────────

	public function testFirstNewWorkspacePicksTheOneThatDidNotExistBefore(): void
	{
		$before = ['F62E7243-D094-42CD-A9C5-F23CBFC52CD7' => true];

		$ws = $this->cmux->firstNewWorkspace($this->treeWithTwoSameTitledWorkspaces(), $before, 'my work');

		$this->assertNotNull($ws);
		$this->assertSame('workspace:40', $ws['ref'], 'must be the NEW workspace, not the pre-existing same-titled one');
	}

	public function testFirstNewWorkspaceIgnoresANewWorkspaceWithADifferentTitle(): void
	{
		// Something else opening a workspace concurrently must not be mistaken for ours.
		$before = ['F62E7243-D094-42CD-A9C5-F23CBFC52CD7' => true];

		$this->assertNull($this->cmux->firstNewWorkspace($this->treeWithTwoSameTitledWorkspaces(), $before, 'different title'));
	}

	public function testFirstNewWorkspaceNullWhenNothingIsNew(): void
	{
		$before = [
			'F62E7243-D094-42CD-A9C5-F23CBFC52CD7' => true,
			'11111111-0000-4000-8000-000000000040' => true,
		];

		$this->assertNull($this->cmux->firstNewWorkspace($this->treeWithTwoSameTitledWorkspaces(), $before, 'my work'));
	}

	// ── refuse to launch into an occupied surface ────────────────────────────

	public function testLaunchIsRefusedWhenTheTargetSurfaceHostsALiveAgent(): void
	{
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public array $sent = [];
			public function liveAgentSurfaceRefs(): array { return ['surface:155' => 'claude']; }
			public function sendLaunch(string $surfRef, string $wsRef, string $text): void { $this->sent[] = $surfRef; }
		};

		$this->assertFalse($stub->launchTargetIsSafe('surface:155'));
		$this->assertTrue($stub->launchTargetIsSafe('surface:200'));
	}

	public function testLaunchIsRefusedForTheCallersOwnSurface(): void
	{
		// Typing into the agent doing the resurrecting is the exact incident.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveAgentSurfaceRefs(): array { return ['surface:155' => 'claude']; }
		};

		$this->assertFalse($stub->launchTargetIsSafe('surface:155'));
	}

	public function testLaunchAllowedOnAPlainShell(): void
	{
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveAgentSurfaceRefs(): array { return []; }
		};

		$this->assertTrue($stub->launchTargetIsSafe('surface:200'));
	}

	// ── ls disambiguates buried vs back-alive ────────────────────────────────

	public function testLsMarksTombstonesWhoseSessionIsLiveAgain(): void
	{
		// resurrect deliberately keeps the tombstone (so you can re-resurrect), which
		// left `ls` claiming a running session was buried.
		$tombs = [
			['session_id' => 'aaaa1111-2222-3333-4444-555555555555', 'tab_title' => 'gone'],
			['session_id' => 'bbbb2222-3333-4444-5555-666666666666', 'tab_title' => 'back'],
		];

		$out = $this->gy->annotateLiveness($tombs, ['bbbb2222-3333-4444-5555-666666666666' => 'codex']);

		$this->assertFalse($out[0]['live']);
		$this->assertTrue($out[1]['live']);
		$this->assertSame('codex', $out[1]['live_agent']);
	}

	public function testLsLivenessDefaultsToBuriedWithNothingRunning(): void
	{
		$out = $this->gy->annotateLiveness([['session_id' => 'aaaa1111-2222-3333-4444-555555555555']], []);

		$this->assertFalse($out[0]['live']);
		$this->assertArrayNotHasKey('live_agent', $out[0]);
	}

	/**
	 * ls and search must stay in lock-step.
	 *
	 * They already shared the RENDERER (lsEntryLines/printLsEntry); what drifted was the
	 * DATA — ls annotated liveness and search didn't, so `↑` appeared in one and not the
	 * other. Both now read tombstones() and the annotation happens once, in there.
	 */
	public function testLsAndSearchReadTheSameAnnotatedSource(): void
	{
		$root = sys_get_temp_dir() . '/gy-parity-' . getmypid() . '-' . random_int(1000, 9999);
		mkdir($root . '/sessions', 0777, true);
		putenv('GRAVEYARD_ROOT=' . $root);

		file_put_contents($root . '/index.json', json_encode(['tombstones' => [
			['session_id' => 'aaaa1111-2222-3333-4444-555555555555', 'tab_title' => 'tailscale notes', 'workspace_title' => 'net', 'cwd' => '/x', 'summary' => 's', 'buried_at' => '2026-07-10'],
		]]));

		// Stub the live map so no cmux/lsof/ps is touched, and claim this session is live.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveSessionIdsByAgent(): array
			{
				return ['aaaa1111-2222-3333-4444-555555555555' => 'codex'];
			}
		};

		$fromLs     = $stub->tombstones();
		$fromSearch = $stub->searchTombstones('tailscale');

		$this->assertTrue($fromLs[0]['live'], 'ls source must carry liveness');
		$this->assertTrue($fromSearch[0]['live'], 'search source must carry the SAME liveness');
		$this->assertSame($fromLs[0]['live_agent'], $fromSearch[0]['live_agent']);

		// …and it must reach the JSON both verbs emit.
		$this->assertTrue($stub->searchRowJson($fromSearch[0])['live']);
		$this->assertSame('codex', $stub->searchRowJson($fromSearch[0])['live_agent']);
	}
}
