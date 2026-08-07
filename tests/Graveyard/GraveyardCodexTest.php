<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * Codex awareness in graveyard (dotfiles-nvf, partial).
 *
 * graveyard DISCOVERS codex sessions — `candidates` shows them, with real idle
 * times — but deliberately refuses to BURY them yet. Bury is destructive (it
 * types /export into a surface, kills a process tree, and closes the surface)
 * and every one of its safety gates is Claude-shaped: GATE 1 matches the Claude
 * REPL's statusline cwd, the busy check greps for a Claude active-turn spinner,
 * and the /status probe reads a Claude Session ID back off the screen. None of
 * those have a codex analogue yet, so a codex bury would be running blind past
 * the checks that exist specifically to avoid destroying the wrong session.
 *
 * Showing a session you cannot bury is a small annoyance; burying one through
 * unaudited gates loses work. So discovery lands now and bury refuses loudly.
 */
final class GraveyardCodexTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		// MUST redirect the store. Without this, any test reaching a working bury
		// writes into ~/.claude-graveyard and tears down whatever real session the
		// fixture names. That is exactly what happened here: this class originally
		// carried a live pid because codex bury was refused unconditionally, and it
		// destroyed a real session the moment bury started working. Fixtures below
		// also use deliberately synthetic ids/pids as a second line of defence.
		$this->root = $this->graveyardRoot;
		putenv('GRAVEYARD_ROOT=' . $this->root);
		$this->gy = new Graveyard($this->cli, $this->cmux);
	}

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
		parent::tearDown();
	}

	// ── codexLastActivity: idle time from a rollout ────────────────────────────

	private function writeRollout(string $path, array $records): void
	{
		$lines = [];
		foreach ($records as $r) {
			$lines[] = json_encode($r);
		}
		file_put_contents($path, implode("\n", $lines) . "\n");
	}

	public function testCodexLastActivityTakesTheLastTimestampedRecord(): void
	{
		$path = sys_get_temp_dir() . '/gy-codex-act-' . getmypid() . '.jsonl';
		$this->writeRollout($path, [
			['timestamp' => '2026-07-27T22:00:00.000Z', 'type' => 'session_meta', 'payload' => ['cwd' => '/x']],
			['timestamp' => '2026-07-27T22:05:00.000Z', 'type' => 'response_item', 'payload' => []],
			['timestamp' => '2026-07-27T22:30:00.000Z', 'type' => 'response_item', 'payload' => []],
		]);

		try {
			$this->assertSame(
				strtotime('2026-07-27T22:30:00Z'),
				$this->gy->codexLastActivity($path)
			);
		} finally {
			unlink($path);
		}
	}

	public function testCodexLastActivitySkipsUnparseableTailLines(): void
	{
		// A rollout being appended to can end mid-write; the scan must fall back to
		// the last COMPLETE record rather than reporting "no activity" (which would
		// read as infinitely idle and make a live session look buryable).
		$path = sys_get_temp_dir() . '/gy-codex-act2-' . getmypid() . '.jsonl';
		file_put_contents(
			$path,
			json_encode(['timestamp' => '2026-07-27T22:05:00.000Z', 'type' => 'response_item']) . "\n"
			. '{"timestamp":"2026-07-27T22:31:00.0' // truncated tail
		);

		try {
			$this->assertSame(
				strtotime('2026-07-27T22:05:00Z'),
				$this->gy->codexLastActivity($path)
			);
		} finally {
			unlink($path);
		}
	}

	public function testCodexLastActivityNullForMissingRollout(): void
	{
		$this->assertNull($this->gy->codexLastActivity('/no/such/rollout.jsonl'));
	}

	// ── bury: codex goes through the CODEX gates ──────────────────────────────
	//
	// Codex used to be refused outright. It is now buryable via buryCodexOne(), so
	// what these pin is that it still cannot be buried WITHOUT its gates passing.

	private function codexSess(): array
	{
		return [
			// Synthetic id/pid on purpose: never a value that could match a live
			// session or process on the machine running the suite.
			'session_id' => 'zztest-codex-0000-0000-000000000000',
			'agent'      => 'codex',
			'cwd'        => '/zztest/cwd',
			'surface_ref' => 'surface:99999', 'workspace_ref' => 'workspace:99999',
			'targetable' => true, 'reason' => '', 'idle_seconds' => 999999,
			'tab_title'  => 'zztest codex', 'workspace_title' => 'zztest',
			'pid'        => 0, 'model' => null, 'skip_perms' => false,
		];
	}

	public function testBuryCodexRefusesWhenNoLiveCodexOccupiesTheSurface(): void
	{
		// GATE 1 (codex): nothing is running there, so there is nothing to bury and
		// certainly nothing to kill.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveCodexBySurfaceRef(): array { return []; }
			public function readLastScreen(string $s, string $w, int $lines = 6): string { return ''; }
		};

		$this->assertFalse($stub->buryOne($this->codexSess(), true, true));
		$this->assertFileDoesNotExist($stub->metaPath('zztest-codex-0000-0000-000000000000'));
	}

	public function testBuryCodexRefusesWhenAnotherSessionOccupiesTheSurface(): void
	{
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveCodexBySurfaceRef(): array { return ['surface:99999' => 'someone-else']; }
			public function readLastScreen(string $s, string $w, int $lines = 6): string { return ''; }
		};

		$this->assertFalse($stub->buryOne($this->codexSess(), true, true));
	}

	public function testBuryRefusesAnAgentWithNoGatesAtAll(): void
	{
		$sess = $this->codexSess();
		$sess['agent'] = 'opencode';

		$this->assertFalse($this->gy->buryOne($sess, true, true));
	}

	public function testCodexGate1RunsBeforeAnySurfaceIsRead(): void
	{
		// A refusal must cost no screen read and, above all, never type into a surface.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public array $screenReads = [];
			public function liveCodexBySurfaceRef(): array { return []; }
			public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string
			{
				$this->screenReads[] = $surfaceRef;
				return '';
			}
		};

		$this->assertFalse($stub->buryOne($this->codexSess(), true, true));
		$this->assertSame([], $stub->screenReads);
	}

	public function testClaudeSessionsAreStillSubjectToTheNormalGates(): void
	{
		// Regression guard: the agent split must not divert claude rows.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public array $screenReads = [];
			public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string
			{
				$this->screenReads[] = $surfaceRef;
				return '[Opus] | 📁 /totally-different-dir | 🌿 main';
			}
		};

		$sess = $this->codexSess();
		$sess['agent']      = 'claude';
		$sess['session_id'] = 'zztest-codexguard';

		$this->assertFalse($stub->buryOne($sess, true, true));
		$this->assertSame(['surface:99999'], $stub->screenReads, 'a claude row must still reach the gates');
	}

	public function testAMissingAgentIsTreatedAsClaude(): void
	{
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public array $screenReads = [];
			public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string
			{
				$this->screenReads[] = $surfaceRef;
				return '[Opus] | 📁 /totally-different-dir | 🌿 main';
			}
		};

		$sess = $this->codexSess();
		unset($sess['agent']);
		$sess['session_id'] = 'zztest-noagent';

		$this->assertFalse($stub->buryOne($sess, true, true));
		$this->assertSame(['surface:99999'], $stub->screenReads);
	}

	// ── candidates carry the agent through ────────────────────────────────────

	public function testCandidateRowKeepsTheAgentAndBuryableFlag(): void
	{
		$row = $this->gy->candidateRowFor([
			'session_id' => 'abc', 'agent' => 'codex', 'idle_seconds' => 100,
			'cwd' => '/x', 'workspace_title' => 'w', 'tab_title' => 't',
			'surface_ref' => 'surface:1', 'workspace_ref' => 'ws:1', 'pid' => 1,
			'model' => null, 'skip_perms' => false, 'targetable' => true, 'reason' => '',
		], false);

		$this->assertSame('codex', $row['agent']);
		$this->assertTrue($row['buryable'], 'codex is buryable now (dotfiles-nvf)');
	}

	public function testClaudeCandidateRowIsBuryable(): void
	{
		$row = $this->gy->candidateRowFor([
			'session_id' => 'abc', 'agent' => 'claude', 'idle_seconds' => 100,
			'cwd' => '/x', 'workspace_title' => 'w', 'tab_title' => 't',
			'surface_ref' => 'surface:1', 'workspace_ref' => 'ws:1', 'pid' => 1,
			'model' => null, 'skip_perms' => false, 'targetable' => true, 'reason' => '',
		], false);

		$this->assertSame('claude', $row['agent']);
		$this->assertTrue($row['buryable']);
	}

	public function testCandidateLineMarksNonClaudeAgents(): void
	{
		// Listing a codex session that looks exactly like a buryable Claude one
		// teaches the refusal only by trial. Mark it in the listing instead.
		$line = $this->gy->candidateLine([
			'session_id' => '019fa599-6b5f', 'agent' => 'codex', 'idle_seconds' => 90000,
			'busy' => false, 'targetable' => true, 'tab_title' => 'p81u worker',
			'workspace_title' => 'cp', 'cwd' => '/Users/JT/Code/claude-plugins',
		], 120, '/Users/JT');

		$this->assertStringContainsString('[codex]', $line);
	}

	public function testCandidateLineLeavesClaudeRowsUnmarked(): void
	{
		$line = $this->gy->candidateLine([
			'session_id' => 'abcdef12-3456', 'agent' => 'claude', 'idle_seconds' => 90000,
			'busy' => false, 'targetable' => true, 'tab_title' => 'some work',
			'workspace_title' => 'w', 'cwd' => '/Users/JT/x',
		], 120, '/Users/JT');

		$this->assertStringNotContainsString('[claude]', $line);
		$this->assertStringNotContainsString('[', $line);
	}

	public function testCandidatesJsonExposesAgentAndBuryable(): void
	{
		$json = $this->gy->candidatesJson([[
			'session_id' => 'abc', 'agent' => 'codex', 'idle_seconds' => 10, 'busy' => false,
			'buryable' => false, 'targetable' => true, 'reason' => '',
			'workspace_title' => 'w', 'tab_title' => 't', 'cwd' => '/x',
		]]);

		$this->assertSame('codex', $json[0]['agent']);
		$this->assertFalse($json[0]['buryable']);
	}

	public function testCandidatesJsonDefaultsToBuryableClaude(): void
	{
		$json = $this->gy->candidatesJson([[
			'session_id' => 'abc', 'idle_seconds' => 10, 'busy' => false,
			'targetable' => true, 'reason' => '', 'workspace_title' => 'w',
			'tab_title' => 't', 'cwd' => '/x',
		]]);

		$this->assertSame('claude', $json[0]['agent']);
		$this->assertTrue($json[0]['buryable']);
	}

	public function testPorcelainColumnCountIsUnchanged(): void
	{
		// The porcelain line is a documented tab format that scripts parse; adding
		// codex must not shift or add columns.
		$row = $this->gy->candidateRowFor([
			'session_id' => 'abc', 'agent' => 'codex', 'idle_seconds' => 100,
			'cwd' => '/x', 'workspace_title' => 'w', 'tab_title' => 't',
			'surface_ref' => 'surface:1', 'workspace_ref' => 'ws:1', 'pid' => 1,
			'model' => null, 'skip_perms' => false, 'targetable' => true, 'reason' => '',
		], false);

		$this->assertCount(7, explode("\t", $this->gy->formatCandidatePorcelain($row)));
	}
}
