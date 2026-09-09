<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Helpers\AgentArtifacts;
use JT\Helpers\Proc;
use JT\Tests\TestCase;
use JT\Tests\Transport\FakeTransport;
use JT\Transport\TransportRegistry;

/**
 * The self-bury guard: graveyard must never target the agent that invoked it.
 *
 * Two independent identifications feed filterSelf(), and the bug this file pins
 * (dotfiles-8wh) was that they were one: selfSessionId() short-circuited on
 * selfSurfaceId(), so a caller with no surface handle — every agent in a herdr pane,
 * since CMUX_SURFACE_ID is a cmux variable — got NEITHER, and `bury --idle` happily
 * listed the session running it. bury kills the pid tree, so it died mid-command.
 *
 * So each path is asserted with the OTHER one unavailable. A test that supplies both
 * would pass again the moment they re-collapse.
 */
final class GraveyardSelfGuardTest extends TestCase
{
	/** The caller's own pid, and the session Claude Code published for it. */
	public const CALLER_PID = 999001;
	public const CALLER_SID = 'cafe0001-0000-0000-0000-000000000000';

	protected function setUp(): void
	{
		parent::setUp();
		// No surface handle from EITHER multiplexer unless a test sets one: the whole
		// point is what happens when the env says nothing.
		putenv('CMUX_SURFACE_ID');
		putenv('HERDR_PANE_ID');
	}

	protected function tearDown(): void
	{
		putenv('CMUX_SURFACE_ID');
		putenv('HERDR_PANE_ID');
		putenv('CLAUDE_SESSIONS_DIR');
		parent::tearDown();
	}

	/**
	 * A Proc whose world is "this php process was launched by a claude at CALLER_PID".
	 *
	 * Stubbing Proc rather than AgentArtifacts keeps the artifact read real — the
	 * <pid>.json parse and the liveness gate both run — so only the two OS primitives
	 * are faked. No method here shadows one that has moved, so this double cannot go
	 * quietly dead the way a Cmux subclass would.
	 */
	private function callerProc(): Proc
	{
		return new class ($this->cli) extends Proc {
			public function psProcTable(): string
			{
				return "  PID  PPID COMMAND\n"
					. sprintf("%d %d php bin/graveyard candidates\n", getmypid(), GraveyardSelfGuardTest::CALLER_PID)
					. sprintf("%d 1 /opt/homebrew/bin/claude --dangerously-skip-permissions\n", GraveyardSelfGuardTest::CALLER_PID);
			}

			public function pidIsAlive(int $pid)
			{
				return $pid === GraveyardSelfGuardTest::CALLER_PID;
			}
		};
	}

	/** Claude Code's per-pid publication, which it writes under any multiplexer. */
	private function publishCallerSession(): void
	{
		$dir = $this->graveyardRoot . '/claude-sessions';
		mkdir($dir, 0777, true);
		file_put_contents(
			$dir . '/' . self::CALLER_PID . '.json',
			json_encode(['pid' => self::CALLER_PID, 'sessionId' => self::CALLER_SID, 'cwd' => '/tmp'])
		);
		putenv('CLAUDE_SESSIONS_DIR=' . $dir);
	}

	/** A graveyard whose live world is the caller's row plus one other session. */
	private function graveyardOverFakeHerdr(?string $selfSurfaceRef, ?Proc $proc = null): Graveyard
	{
		$transport = new FakeTransport('herdr', [
			FakeTransport::row('herdr', self::CALLER_SID, ['surface_ref' => 'wF:p3', 'surface_id' => 'wF:p3']),
			FakeTransport::row('herdr', 'beef0002-0000-0000-0000-000000000000', ['surface_ref' => 'wF:p2', 'surface_id' => 'wF:p2']),
		], true, false, [], null, $selfSurfaceRef);

		$artifacts = new AgentArtifacts($this->cli, $proc ?: $this->callerProc());
		$registry  = new TransportRegistry($this->cli, [$transport], $artifacts);

		return new Graveyard($this->cli, $transport, $artifacts, $registry);
	}

	/**
	 * THE regression guard for dotfiles-8wh. With no surface env var at all — the shape
	 * every herdr-hosted agent had — the caller's row must still be dropped, off the
	 * ancestry path alone.
	 */
	public function test_with_no_surface_env_var_filterSelf_still_drops_the_callers_row(): void
	{
		$this->publishCallerSession();
		$gy = $this->graveyardOverFakeHerdr(null);

		$this->assertNull($gy->selfSurfaceId(), 'no multiplexer claims this caller');
		$this->assertSame(self::CALLER_SID, $gy->selfSessionId(), 'and yet the caller is identified');

		$kept = $gy->filterSelf($gy->liveSessions(), $gy->selfSurfaceId(), $gy->selfSessionId());
		$this->assertSame(
			['beef0002-0000-0000-0000-000000000000'],
			array_column($kept, 'session_id'),
			"the caller's own session survived the self-filter"
		);
	}

	/**
	 * The surface path, asserted with the ancestry path dead: a pane handle alone is
	 * enough, exactly as CMUX_SURFACE_ID always was. This is the half that a
	 * registry-blind selfSurfaceId() breaks — a herdr caller asking cmux gets null.
	 */
	public function test_a_pane_handle_alone_drops_the_callers_row(): void
	{
		putenv('HERDR_PANE_ID=wF:p3');
		$blind = new class ($this->cli) extends Proc {
			public function psProcTable(): string { return ''; }
		};
		$gy = $this->graveyardOverFakeHerdr(getenv('HERDR_PANE_ID') ?: null, $blind);

		$this->assertSame('wF:p3', $gy->selfSurfaceId());

		$kept = $gy->filterSelf($gy->liveSessions(), $gy->selfSurfaceId(), $gy->selfSessionId());
		$this->assertSame(['beef0002-0000-0000-0000-000000000000'], array_column($kept, 'session_id'));
	}

	/**
	 * filterSelf()'s two SURFACE clauses, pinned on their own.
	 *
	 * The test above passes a resolved session id in alongside the handle, so the
	 * session-id clause does all the work and both surface clauses could be deleted
	 * with the whole suite still green. They are load-bearing exactly when the surface
	 * matches a row whose session id does NOT — a row with a null sid, or two agents
	 * sharing one surface — which is the case a stale handle produces, so it is worth
	 * a guard of its own.
	 */
	public function test_a_surface_handle_alone_drops_that_row_even_with_no_session_id(): void
	{
		$rows = [
			['session_id' => null,   'surface_ref' => 'wF:p3', 'surface_id' => 'wF:p3'],
			['session_id' => 'keep', 'surface_ref' => 'wF:p9', 'surface_id' => 'wF:p9'],
		];

		// selfSessionId deliberately null: only the surface clauses can do this.
		$kept = $this->gy->filterSelf($rows, 'wF:p3', null);
		$this->assertSame(['keep'], array_column($kept, 'session_id'));

		// The second clause matches on surface_id, which for cmux is the stable UUID
		// while surface_ref is positional — a caller may hold either handle.
		$byId = $this->gy->filterSelf([
			['session_id' => null,   'surface_ref' => 'surface:7', 'surface_id' => 'UUID-A'],
			['session_id' => 'keep', 'surface_ref' => 'surface:8', 'surface_id' => 'UUID-B'],
		], 'UUID-A', null);
		$this->assertSame(['keep'], array_column($byId, 'session_id'));
	}

	/**
	 * selfSurfaceId() asks the REGISTRY, not the primary transport. The primary is
	 * cmux-first, so a herdr-hosted caller asking only the primary gets null — which
	 * is the bug one layer up.
	 */
	public function test_selfSurfaceId_asks_every_transport_not_just_the_primary(): void
	{
		putenv('HERDR_PANE_ID=wF:p3');
		$cmux  = new FakeTransport('cmux', [], true, true, [], null, null);
		$herdr = new FakeTransport('herdr', [], true, false, [], null, 'wF:p3');

		$blind = new class ($this->cli) extends Proc {
			public function psProcTable(): string { return ''; }
		};
		$artifacts = new AgentArtifacts($this->cli, $blind);
		$registry  = new TransportRegistry($this->cli, [$cmux, $herdr], $artifacts);
		$gy        = new Graveyard($this->cli, $cmux, $artifacts, $registry);

		$this->assertSame('cmux', $registry->primary()->name(), 'the primary is the incumbent');
		$this->assertSame('wF:p3', $gy->selfSurfaceId());
		$this->assertSame('herdr', $registry->selfTransport()->name());
	}

	/**
	 * Single-transport construction (the page server's shape, and every test's) has no
	 * registry, and must still answer rather than throw — see the tripwire in the work
	 * order. It answers from the one transport it has.
	 */
	public function test_a_registryless_graveyard_answers_from_its_only_transport(): void
	{
		putenv('CMUX_SURFACE_ID=surface:7');
		$gy = new Graveyard($this->cli, $this->transport);
		$this->assertSame('surface:7', $gy->selfSurfaceId());

		$nullGy = new Graveyard($this->cli, new \JT\Transport\NullTransport($this->cli));
		$this->assertNull($nullGy->selfSurfaceId(), 'the page server hosts nobody');
	}

	/**
	 * Tripwire 3: with the ancestry unreadable and no surface handle, the answer is
	 * null — today's cmux behavior. Guessing a session id here would filter out
	 * SOMEBODY ELSE'S session and hide it from bury, which is worse than no guard.
	 */
	public function test_an_unidentifiable_caller_yields_null_rather_than_a_guess(): void
	{
		$blind = new class ($this->cli) extends Proc {
			public function psProcTable(): string { return ''; }
		};
		$gy = $this->graveyardOverFakeHerdr(null, $blind);

		$this->assertNull($gy->selfSurfaceId());
		$this->assertNull($gy->selfSessionId());
		$this->assertCount(2, $gy->filterSelf($gy->liveSessions(), null, null));
	}

	/**
	 * A dead claude pid must not vouch for a session: pids are reused, and
	 * claudeSessionIdForPid() gates on liveness for exactly that reason. An ancestor
	 * that has exited is no identification at all.
	 */
	public function test_a_dead_ancestor_identifies_nobody(): void
	{
		$this->publishCallerSession();
		$dead = new class ($this->cli) extends Proc {
			public function psProcTable(): string
			{
				return "  PID  PPID COMMAND\n"
					. sprintf("%d %d php bin/graveyard candidates\n", getmypid(), GraveyardSelfGuardTest::CALLER_PID)
					. sprintf("%d 1 /opt/homebrew/bin/claude\n", GraveyardSelfGuardTest::CALLER_PID);
			}
			public function pidIsAlive(int $pid) { return false; }
		};
		$gy = $this->graveyardOverFakeHerdr(null, $dead);

		$this->assertNull($gy->selfSessionId());
	}

	/** PURE walk: the NEAREST claude ancestor, not the outermost one. */
	public function test_ancestorClaudePid_takes_the_nearest_claude_ancestor(): void
	{
		$artifacts = new AgentArtifacts($this->cli);
		$proc = [
			50 => ['ppid' => 40, 'cmd' => 'php bin/graveyard candidates'],
			40 => ['ppid' => 30, 'cmd' => '/bin/zsh -c source snapshot.sh'],
			30 => ['ppid' => 20, 'cmd' => '/opt/homebrew/bin/claude --resume abc'],
			20 => ['ppid' => 10, 'cmd' => 'bash /tmp/hotline-launch'],
			10 => ['ppid' => 1,  'cmd' => 'claude'],
			70 => ['ppid' => 1,  'cmd' => 'php bin/graveyard candidates'],
		];
		$this->assertSame(30, $artifacts->ancestorClaudePid($proc, 50), 'the inner claude, not the outer');
		$this->assertSame(30, $artifacts->ancestorClaudePid($proc, 30), 'inclusive of the pid itself');
		$this->assertSame(10, $artifacts->ancestorClaudePid($proc, 20), 'and the outer one for a pid below only it');
		$this->assertNull($artifacts->ancestorClaudePid($proc, 70), 'a pid under no agent at all');
		$this->assertNull($artifacts->ancestorClaudePid($proc, 999), 'an unknown pid has no ancestry');
	}

	/** A wrapper script named claude-*.zsh is not the claude binary. */
	public function test_ancestorClaudePid_ignores_a_resume_wrapper(): void
	{
		$artifacts = new AgentArtifacts($this->cli);
		$proc = [
			50 => ['ppid' => 40, 'cmd' => 'php bin/graveyard candidates'],
			40 => ['ppid' => 1,  'cmd' => '/bin/zsh /Users/JT/.cmux-surface-resume/claude-boss.zsh'],
		];
		$this->assertNull($artifacts->ancestorClaudePid($proc, 50));
	}

	/** A cycle in a hand-rolled table must not hang the walk. */
	public function test_ancestorClaudePid_survives_a_cyclic_table(): void
	{
		$artifacts = new AgentArtifacts($this->cli);
		$proc = [
			50 => ['ppid' => 60, 'cmd' => 'php x'],
			60 => ['ppid' => 50, 'cmd' => 'zsh y'],
		];
		$this->assertNull($artifacts->ancestorClaudePid($proc, 50));
	}
}
