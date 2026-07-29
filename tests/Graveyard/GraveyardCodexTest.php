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

	// ── bury refuses codex ────────────────────────────────────────────────────

	private function codexSess(): array
	{
		return [
			'session_id' => '019fa599-6b5f-7de1-9822-52643135bb95',
			'agent'      => 'codex',
			'cwd'        => '/Users/JT/Code/claude-plugins',
			'surface_ref' => 'surface:86', 'workspace_ref' => 'workspace:17',
			'targetable' => true, 'reason' => '', 'idle_seconds' => 999999,
			'tab_title'  => 'codex: p81u worker', 'workspace_title' => 'cp',
			'pid'        => 39834, 'model' => null, 'skip_perms' => false,
		];
	}

	public function testBuryRefusesACodexSession(): void
	{
		$this->assertFalse($this->gy->buryOne($this->codexSess(), false, true));
	}

	public function testForceDoesNotBypassTheCodexRefusal(): void
	{
		// --force exists to override "looks busy", not to override "we don't know how
		// to do this safely".
		$this->assertFalse($this->gy->buryOne($this->codexSess(), true, true));
	}

	public function testCodexRefusalWritesNoArchive(): void
	{
		$sess = $this->codexSess();
		$this->gy->buryOne($sess, true, true);

		$this->assertFileDoesNotExist($this->gy->metaPath($sess['session_id']));
	}

	public function testCodexRefusalHappensBeforeAnySurfaceIsTouched(): void
	{
		// The guard must sit ahead of every gate, so a refusal costs no screen reads
		// and — critically — never types into a surface.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public array $screenReads = [];
			public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string
			{
				$this->screenReads[] = $surfaceRef;
				return '';
			}
		};

		$this->assertFalse($stub->buryOne($this->codexSess(), true, true));
		$this->assertSame([], $stub->screenReads, 'a refused codex bury must not read the surface');
	}

	public function testClaudeSessionsAreStillSubjectToTheNormalGates(): void
	{
		// Regression guard: the agent check must not accidentally swallow claude rows.
		// This claude row fails GATE 1 (statusline cwd mismatch), so it returns false
		// too — but for the gate reason, having gotten past the agent check and read
		// the screen.
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
		$this->assertSame(['surface:86'], $stub->screenReads, 'a claude row must still reach the gates');
	}

	public function testAMissingAgentIsTreatedAsClaude(): void
	{
		// Rows built before `agent` existed (or by an older caller) must keep working.
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
		$this->assertSame(['surface:86'], $stub->screenReads);
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
		$this->assertFalse($row['buryable']);
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
