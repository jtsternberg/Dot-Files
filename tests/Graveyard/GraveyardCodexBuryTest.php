<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * Codex bury/resurrect in graveyard (dotfiles-nvf, dotfiles-51b).
 *
 * Codex does NOT reuse Claude's bury gates — it gets stronger ones. Claude's
 * GATE 1 scrapes the REPL statusline for a cwd because its session↔surface join
 * is heuristic; codex can be proved outright. The live codex on a surface is
 * identified by CMUX_SURFACE_ID (read from the process's own environment) and its
 * session id by the rollout it holds open, both from the OS rather than the
 * screen. So GATE 1 becomes "the codex process on this surface IS this session".
 *
 * Archiving is a file copy of the rollout, not `/export` typed into a REPL, so
 * GATE 2 becomes an exact check — the archived copy's session_meta must carry the
 * target session id — instead of matching turn text.
 *
 * Schema is additive (dotfiles-51b): codex tombstones carry kind='codex'; every
 * pre-existing archive is untouched and reads as claude, since old code never
 * wrote that kind.
 */
final class GraveyardCodexBuryTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = $this->graveyardRoot;
		putenv('GRAVEYARD_ROOT=' . $this->root);
		$this->gy = new Graveyard($this->cli, $this->cmux);
	}

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
		putenv('CODEX_SESSIONS_DIR');
		parent::tearDown();
	}

	private const SID = '019fadf9-449a-74c0-aac4-dc767affc6ea';

	/**
	 * Write a rollout into a fake CODEX_SESSIONS_DIR and point the helper at it.
	 *
	 * $withTurnContext=false reproduces a Codex Desktop rollout: ~45% of real
	 * rollouts carry no turn_context record at all, so sandbox/approval simply are
	 * not recorded anywhere (dotfiles-f1n).
	 */
	private function liveRollout(string $sid, array $extra = [], bool $withTurnContext = true): string
	{
		$dir = $this->root . '/codex-sessions/2026/07/29';
		if (!is_dir($dir)) { mkdir($dir, 0777, true); }
		putenv('CODEX_SESSIONS_DIR=' . $this->root . '/codex-sessions');

		$path  = "{$dir}/rollout-2026-07-29T09-03-42-{$sid}.jsonl";
		$lines = [json_encode(['timestamp' => '2026-07-29T09:03:42.000Z', 'type' => 'session_meta', 'payload' => array_merge([
			'session_id' => $sid,
			'cwd'        => '/Users/JT/x',
			'originator' => 'codex-tui',
		], $extra)])];
		if ($withTurnContext) {
			$lines[] = json_encode(['timestamp' => '2026-07-29T09:05:00.000Z', 'type' => 'turn_context', 'payload' => [
				'model'           => 'gpt-5.6-terra',
				'approval_policy' => 'never',
				'sandbox_policy'  => ['type' => 'read-only'],
			]]);
		}
		$lines[] = json_encode(['timestamp' => '2026-07-29T09:06:00.000Z', 'type' => 'response_item', 'payload' => ['x' => 1]]);
		file_put_contents($path, implode("\n", $lines) . "\n");

		return $path;
	}

	private function codexSess(): array
	{
		return [
			'session_id' => self::SID, 'agent' => 'codex', 'cwd' => '/Users/JT/x',
			'surface_ref' => 'surface:86', 'workspace_ref' => 'workspace:17',
			'targetable' => true, 'reason' => '', 'idle_seconds' => 999999,
			'tab_title' => 'codex: worker', 'workspace_title' => 'ws', 'pid' => 4242,
			'model' => 'gpt-5.6-terra', 'skip_perms' => false,
			'opts' => ['sandbox' => 'read-only', 'approval' => 'never', 'effort' => null],
		];
	}

	// ── archiving is a copy, not a REPL round-trip ────────────────────────────

	public function testArchiveCodexRolloutCopiesTheRolloutIntoTheStore(): void
	{
		$this->liveRollout(self::SID);

		$this->assertTrue($this->gy->archiveCodexRollout($this->codexSess()));

		$archived = $this->gy->codexRolloutArchivePath(self::SID);
		$this->assertFileExists($archived);
		$first = json_decode(strtok(file_get_contents($archived), "\n"), true);
		$this->assertSame(self::SID, $first['payload']['session_id']);
	}

	public function testArchiveCodexRolloutFailsWhenTheRolloutIsGone(): void
	{
		putenv('CODEX_SESSIONS_DIR=' . $this->root . '/nope');

		// Must be a FAILURE, not an empty archive — bury would otherwise tear the
		// session down having preserved nothing.
		$this->assertFalse($this->gy->archiveCodexRollout($this->codexSess()));
		$this->assertFileDoesNotExist($this->gy->codexRolloutArchivePath(self::SID));
	}

	public function testArchiveCodexRolloutFailsOnAnEmptyRollout(): void
	{
		$path = $this->liveRollout(self::SID);
		file_put_contents($path, '');

		$this->assertFalse($this->gy->archiveCodexRollout($this->codexSess()));
	}

	// ── GATE 2 equivalent: exact session_meta match ───────────────────────────

	public function testArchivedRolloutBelongsToSessionAcceptsTheRightSession(): void
	{
		$this->liveRollout(self::SID);
		$this->gy->archiveCodexRollout($this->codexSess());

		$this->assertTrue($this->gy->codexArchiveBelongsToSession(
			$this->gy->codexRolloutArchivePath(self::SID),
			self::SID
		));
	}

	public function testArchivedRolloutBelongsToSessionRejectsAnotherSession(): void
	{
		$this->liveRollout(self::SID);
		$this->gy->archiveCodexRollout($this->codexSess());

		$this->assertFalse($this->gy->codexArchiveBelongsToSession(
			$this->gy->codexRolloutArchivePath(self::SID),
			'00000000-0000-0000-0000-000000000000'
		));
	}

	public function testArchivedRolloutBelongsToSessionRejectsMissingOrHeaderless(): void
	{
		$this->assertFalse($this->gy->codexArchiveBelongsToSession('/no/such.jsonl', self::SID));

		$p = $this->root . '/headerless.jsonl';
		file_put_contents($p, "not json\n");
		$this->assertFalse($this->gy->codexArchiveBelongsToSession($p, self::SID));
	}

	/**
	 * A RESUMED or forked session must pass. Codex writes a new rollout per resume whose
	 * session_meta carries `id` = this thread and `session_id` = the ANCESTOR, and whose
	 * records begin with a verbatim copy of that ancestor — so the first session_meta in the
	 * file belongs to somebody else. Matching on the first record's `session_id` (as this
	 * gate first did) refused every resumed session: bury → resurrect → bury could never
	 * complete, and the refusal read as "the archive is not this session's".
	 */
	public function testArchivedRolloutBelongsToSessionAcceptsAResumedSessionWhoseSessionIdNamesItsAncestor(): void
	{
		$ancestor = '019fa586-a9b7-7df0-a430-49907c5193f6';
		$p = $this->root . '/resumed.jsonl';
		file_put_contents($p, implode("\n", [
			json_encode(['type' => 'session_meta', 'payload' => ['id' => $ancestor, 'session_id' => $ancestor, 'cwd' => '/x']]),
			json_encode(['type' => 'response_item', 'payload' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'before the resume']]]]),
			json_encode(['type' => 'session_meta', 'payload' => ['id' => self::SID, 'session_id' => $ancestor, 'thread_source' => 'user', 'cwd' => '/x']]),
		]) . "\n");

		$this->assertTrue($this->gy->codexArchiveBelongsToSession($p, self::SID));
	}

	/**
	 * The mirror image, and the reason this gate cannot just accept any session_meta hit: a
	 * SUBAGENT thread's rollout embeds its parent's header, so an archive of the child stored
	 * under the parent's id would otherwise pass. Only the file's own thread — the last
	 * session_meta — counts.
	 */
	public function testArchivedRolloutBelongsToSessionRejectsASubagentThreadArchivedUnderItsParentId(): void
	{
		$p = $this->root . '/subagent.jsonl';
		file_put_contents($p, implode("\n", [
			json_encode(['type' => 'session_meta', 'payload' => ['id' => self::SID, 'session_id' => self::SID, 'cwd' => '/x']]),
			json_encode(['type' => 'session_meta', 'payload' => [
				'id' => '019faf55-3260-7cb0-9479-45c53799dfa6', 'session_id' => self::SID,
				'parent_thread_id' => self::SID, 'thread_source' => 'subagent', 'cwd' => '/x',
			]]),
		]) . "\n");

		$this->assertFalse($this->gy->codexArchiveBelongsToSession($p, self::SID));
	}

	// ── GATE 3: pid → its OWN session, not a subagent's ───────────────────────

	/**
	 * GATE 3 re-derives the pid's session from the rollout it holds open. A codex that has
	 * spawned a subagent holds two rollouts open, and lsof order is fd order — so reading
	 * "the first rollout" made teardown of a perfectly healthy session abort with
	 * "pid N maps to session <subagent>", and made a subagent's id look killable.
	 */
	private function gateThreeStub(string $lsofRaw): Graveyard
	{
		$cmux = new class($this->cli, $lsofRaw) extends \JT\Helpers\Cmux {
			private string $raw;
			public function __construct($cli, string $raw) { parent::__construct($cli); $this->raw = $raw; }
			public function lsofForPid(int $pid): string { return $this->raw; }
		};

		return new class($this->cli, $cmux) extends Graveyard {
			public array $killedPids = [];
			public function kill(array $sess): bool { return $this->killMember($sess); }
			protected function killPidTree(int $pid): bool { $this->killedPids[] = $pid; return true; }
		};
	}

	private function lsofFor(array $paths): string
	{
		$raw = "COMMAND   PID USER   FD   TYPE DEVICE SIZE/OFF      NODE NAME\n";
		$fd  = 60;
		foreach ($paths as $p) { $raw .= "codex   4242   JT   {$fd}u   REG   1,13 323058 2392 {$p}\n"; $fd++; }
		return $raw;
	}

	public function testGateThreeAcceptsASessionWhoseSubagentRolloutIsAlsoOpen(): void
	{
		$sub  = $this->liveRollout('019faf55-3260-7cb0-9479-45c53799dfa6', [
			'id' => '019faf55-3260-7cb0-9479-45c53799dfa6', 'session_id' => self::SID,
			'parent_thread_id' => self::SID, 'thread_source' => 'subagent',
		]);
		$main = $this->liveRollout(self::SID, ['id' => self::SID, 'thread_source' => 'user']);

		$stub = $this->gateThreeStub($this->lsofFor([$sub, $main]));

		$this->assertTrue($stub->kill($this->codexSess()));
		$this->assertSame([4242], $stub->killedPids);
	}

	public function testGateThreeStillRefusesAPidHostingADifferentSession(): void
	{
		$other = $this->liveRollout('019fbbbb-0000-74c0-aac4-dc767affc6ea', [
			'id' => '019fbbbb-0000-74c0-aac4-dc767affc6ea', 'thread_source' => 'user',
		]);

		$stub = $this->gateThreeStub($this->lsofFor([$other]));

		$this->assertFalse($stub->kill($this->codexSess()));
		$this->assertSame([], $stub->killedPids, 'a mismatched pid must never be killed');
	}

	// ── archive freshness ─────────────────────────────────────────────────────

	public function testCodexArchiveUpToDateFalseWithNoArchive(): void
	{
		$this->liveRollout(self::SID);
		$this->assertFalse($this->gy->codexArchiveUpToDate(self::SID));
	}

	public function testCodexArchiveUpToDateTrueWhenArchiveIsNewerThanRollout(): void
	{
		$live = $this->liveRollout(self::SID);
		$this->gy->archiveCodexRollout($this->codexSess());
		touch($live, time() - 600);

		$this->assertTrue($this->gy->codexArchiveUpToDate(self::SID));
	}

	public function testCodexArchiveUpToDateFalseWhenRolloutGrewSinceArchiving(): void
	{
		$live = $this->liveRollout(self::SID);
		$this->gy->archiveCodexRollout($this->codexSess());
		touch($this->gy->codexRolloutArchivePath(self::SID), time() - 600);
		touch($live, time());

		$this->assertFalse($this->gy->codexArchiveUpToDate(self::SID));
	}

	// ── tombstone: additive kind='codex' (dotfiles-51b) ───────────────────────

	public function testCodexTombstoneCarriesKindAndResumeContext(): void
	{
		$this->liveRollout(self::SID);
		$tomb = $this->gy->buildTombstone($this->codexSess(), ['workspace_title' => 'ws', 'tab_title' => 't'], 'sum', '2026-07-29T00:00:00Z');

		$this->assertSame('codex', $tomb['kind']);
		$this->assertSame(self::SID, $tomb['session_id']);
		$this->assertSame('read-only', $tomb['agent_opts']['sandbox']);
		$this->assertSame('gpt-5.6-terra', $tomb['model']);
	}

	public function testClaudeTombstoneShapeIsUnchanged(): void
	{
		// Additive means additive: a claude tombstone must not gain a kind key, so
		// existing archives and readers are untouched.
		$sess = $this->codexSess();
		$sess['agent'] = 'claude';
		$sess['session_id'] = 'aaaaaaaa-1111-2222-3333-444444444444';

		$tomb = $this->gy->buildTombstone($sess, ['workspace_title' => 'ws', 'tab_title' => 't'], 'sum', '2026-07-29T00:00:00Z');

		$this->assertArrayNotHasKey('kind', $tomb);
		$this->assertArrayNotHasKey('agent_opts', $tomb);
	}

	public function testTombstoneAgentReadsKindAndDefaultsToClaude(): void
	{
		$this->assertSame('codex', $this->gy->tombstoneAgent(['kind' => 'codex']));
		$this->assertSame('claude', $this->gy->tombstoneAgent(['kind' => 'claude']));
		// Every archive written before this feature existed.
		$this->assertSame('claude', $this->gy->tombstoneAgent([]));
		// A group-manifest kind that isn't an agent must not read as one.
		$this->assertSame('claude', $this->gy->tombstoneAgent(['kind' => 'shell']));
	}

	// ── GATE 1 equivalent: prove the surface hosts this exact session ─────────

	public function testCodexSurfaceIdentityGateAcceptsTheMatchingSession(): void
	{
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public array $seen = [];
			public function liveCodexBySurfaceRef(): array
			{
				$this->seen[] = 'called';
				return ['surface:86' => '019fadf9-449a-74c0-aac4-dc767affc6ea'];
			}
		};

		$this->assertTrue($stub->codexSurfaceHostsSession('surface:86', self::SID));
	}

	public function testCodexSurfaceIdentityGateRejectsADifferentSessionOnThatSurface(): void
	{
		// The join pointed at the wrong tab: something else is running there. Never
		// kill it.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveCodexBySurfaceRef(): array
			{
				return ['surface:86' => '99999999-9999-9999-9999-999999999999'];
			}
		};

		$this->assertFalse($stub->codexSurfaceHostsSession('surface:86', self::SID));
	}

	public function testCodexSurfaceIdentityGateRejectsAnEmptySurface(): void
	{
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveCodexBySurfaceRef(): array { return []; }
		};

		$this->assertFalse($stub->codexSurfaceHostsSession('surface:86', self::SID));
	}

	// ── buryOne: codex now allowed, but only through the codex gates ──────────

	/** A Graveyard whose live-codex view and surface I/O are stubbed. */
	private function buryStub(array $liveBySurf, bool $rolloutExists = true, bool $withTurnContext = true): Graveyard
	{
		if ($rolloutExists) { $this->liveRollout(self::SID, [], $withTurnContext); }

		return new class($this->cli, $this->cmux, $liveBySurf) extends Graveyard {
			public array $sent = [];
			public array $killed = [];
			private array $live;
			public function __construct($cli, $cmux, array $live)
			{
				parent::__construct($cli, $cmux);
				$this->live = $live;
			}
			public function liveCodexBySurfaceRef(): array { return $this->live; }
			public function readLastScreen(string $s, string $w, int $lines = 6): string { return ''; }
			public function teardown(array $sess): bool { $this->killed[] = $sess['session_id']; return true; }
			protected function killMember(array $sess): bool { $this->killed[] = $sess['session_id']; return true; }
		};
	}

	public function testBuryCodexRefusesWhenTheSurfaceHostsADifferentSession(): void
	{
		$stub = $this->buryStub(['surface:86' => '99999999-9999-9999-9999-999999999999']);

		$this->assertFalse($stub->buryOne($this->codexSess(), true, true));
		$this->assertFileDoesNotExist($stub->metaPath(self::SID));
		$this->assertSame([], $stub->killed, 'nothing may be killed when identity fails');
	}

	public function testBuryCodexRefusesWhenTheRolloutIsGone(): void
	{
		$stub = $this->buryStub(['surface:86' => self::SID], false);
		putenv('CODEX_SESSIONS_DIR=' . $this->root . '/nope');

		$this->assertFalse($stub->buryOne($this->codexSess(), true, true));
		$this->assertSame([], $stub->killed, 'no archive => nothing destroyed');
	}

	public function testBuryCodexArchivesAndWritesATombstone(): void
	{
		$stub = $this->buryStub(['surface:86' => self::SID]);

		$this->assertTrue($stub->buryOne($this->codexSess(), true, true));

		$this->assertFileExists($stub->codexRolloutArchivePath(self::SID));
		$this->assertFileExists($stub->metaPath(self::SID));
		$tomb = json_decode(file_get_contents($stub->metaPath(self::SID)), true);
		$this->assertSame('codex', $tomb['kind']);
		$this->assertSame([self::SID], $stub->killed);
	}

	public function testBuryCodexStillRefusesAnUntargetableRow(): void
	{
		$stub = $this->buryStub(['surface:86' => self::SID]);
		$sess = $this->codexSess();
		$sess['targetable'] = false;
		$sess['reason'] = 'CMUX_SURFACE_ID not found among cmux surfaces';

		$this->assertFalse($stub->buryOne($sess, true, true));
	}

	// ── the recorded context must survive the whole bury→resurrect round trip ──

	public function testBuriedCodexKeepsItsRecordedContextForResurrect(): void
	{
		// Regression: liveSessions() built its row without 'opts', so the sandbox /
		// approval / effort the join had resolved were dropped between discovery and
		// the tombstone. Resurrect then replayed nothing and `codex resume` fell back
		// to config — silently widening a read-only session to full access. Caught by
		// a live bury whose tombstone came out with agent_opts: [].
		$stub = $this->buryStub(['surface:86' => self::SID]);

		$this->assertTrue($stub->buryOne($this->codexSess(), true, true));

		$tomb = json_decode(file_get_contents($stub->metaPath(self::SID)), true);
		$this->assertSame('read-only', $tomb['agent_opts']['sandbox'], 'sandbox must survive into the tombstone');
		$this->assertSame('never', $tomb['agent_opts']['approval']);

		// …and come back out on the resume command.
		$cmd = $stub->buildTombstoneLaunch($tomb, false);
		$this->assertStringContainsString('--sandbox=read-only', $cmd);
		$this->assertStringContainsString('--ask-for-approval=never', $cmd);
	}

	public function testLiveSessionRowCarriesOptsThrough(): void
	{
		// The join row's opts must reach the candidate/liveSessions row shape, since
		// that is what bury reads.
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function candidatePassthrough(array $joinRow): array
			{
				return $this->candidateRowFor($joinRow + ['idle_seconds' => 1, 'workspace_title' => '', 'tab_title' => ''], false);
			}
		};

		$row = $stub->candidatePassthrough([
			'session_id' => self::SID, 'agent' => 'codex', 'cwd' => '/x',
			'model' => 'gpt-5.6-terra', 'skip_perms' => false,
			'opts' => ['sandbox' => 'read-only'],
			'surface_ref' => 'surface:86', 'workspace_ref' => 'ws:1', 'pid' => 1,
			'targetable' => true, 'reason' => '',
		]);

		$this->assertSame('read-only', $row['opts']['sandbox'] ?? null);
	}

	// ── resurrect ─────────────────────────────────────────────────────────────

	public function testCodexResurrectUsesCodexResumeWithRecordedContext(): void
	{
		$this->liveRollout(self::SID);
		$cmd = $this->gy->buildTombstoneLaunch([
			'kind' => 'codex', 'session_id' => self::SID, 'cwd' => '/Users/JT/x',
			'model' => 'gpt-5.6-terra', 'agent_opts' => ['sandbox' => 'read-only', 'approval' => 'never'],
		], false);

		$this->assertStringContainsString('codex resume', $cmd);
		$this->assertStringContainsString('--sandbox=read-only', $cmd);
		$this->assertStringNotContainsString('claude', $cmd);
	}

	public function testClaudeResurrectLaunchIsUnchanged(): void
	{
		$cmd = $this->gy->buildTombstoneLaunch([
			'session_id' => 'aaaa1111-2222-3333-4444-555555555555', 'cwd' => '/x',
			'model' => 'opus', 'skip_perms' => true,
		], false);

		$this->assertSame($this->cmux->buildResumeCommand('aaaa1111-2222-3333-4444-555555555555', true, 'opus'), $cmd);
	}

	# =========================================================================
	# An honest archive when the rollout never recorded sandbox/approval
	# (dotfiles-f1n)
	#
	# sandbox/approval come from turn_context records, and ~45% of real rollouts
	# have none — every Codex Desktop (source=vscode) session, permanently, not a
	# version quirk that ages out. Such a session used to resurrect with null
	# agent_opts and come back on whatever ~/.codex/config.toml defaults to, with
	# nothing recorded and nothing said. The archive now says so, and resurrect
	# warns and proceeds.
	# =========================================================================

	/** A codex row as the live join produces it for a rollout with NO turn_context. */
	private function codexSessNoContext(): array
	{
		$sess         = $this->codexSess();
		$sess['opts'] = ['sandbox' => null, 'approval' => null, 'effort' => null];
		return $sess;
	}

	public function testBuryStampsTheHonestyFlagWhenTheRolloutHasNoTurnContext(): void
	{
		$stub = $this->buryStub(['surface:86' => self::SID], true, false);

		$this->assertTrue($stub->buryOne($this->codexSessNoContext(), true, true));

		$tomb = json_decode(file_get_contents($stub->metaPath(self::SID)), true);
		$this->assertTrue(
			$tomb['agent_opts_unknown'] ?? false,
			'a rollout with no turn_context must record that sandbox/approval could not be preserved'
		);
	}

	public function testBuryLeavesTheHonestyFlagOffANormalCodexRollout(): void
	{
		// No regression: a rollout that DOES carry turn_context keeps exactly the
		// tombstone shape it had, flag absent rather than false.
		$stub = $this->buryStub(['surface:86' => self::SID]);

		$this->assertTrue($stub->buryOne($this->codexSess(), true, true));

		$tomb = json_decode(file_get_contents($stub->metaPath(self::SID)), true);
		$this->assertArrayNotHasKey('agent_opts_unknown', $tomb);
		$this->assertSame('read-only', $tomb['agent_opts']['sandbox']);
	}

	public function testBuildTombstoneLeavesClaudeTombstonesUnflagged(): void
	{
		$sess               = $this->codexSess();
		$sess['agent']      = 'claude';
		$sess['session_id'] = 'aaaaaaaa-1111-2222-3333-444444444444';

		$tomb = $this->gy->buildTombstone($sess, ['workspace_title' => 'ws', 'tab_title' => 't'], 'sum', '2026-07-29T00:00:00Z');

		$this->assertArrayNotHasKey('agent_opts_unknown', $tomb);
	}

	public function testAgentOptsUnknownWarningFiresForAFlaggedTombstoneAndNotAPreservedOne(): void
	{
		$flagged = ['kind' => 'codex', 'session_id' => self::SID, 'agent_opts_unknown' => true, 'agent_opts' => []];
		$this->assertNotNull($this->gy->agentOptsUnknownWarning($flagged));

		$preserved = ['kind' => 'codex', 'session_id' => self::SID, 'agent_opts' => ['sandbox' => 'read-only', 'approval' => 'never']];
		$this->assertNull($this->gy->agentOptsUnknownWarning($preserved));

		// Claude has no sandbox/approval to lose; --resume rehydrates its own mode.
		$this->assertNull($this->gy->agentOptsUnknownWarning(['session_id' => self::SID, 'skip_perms' => true]));
	}

	public function testAgentOptsUnknownWarningAlsoCoversArchivesBuriedBeforeTheFlagExisted(): void
	{
		// The ~45% already in the graveyard carry no flag — they were buried before
		// it existed — but their empty agent_opts is the same silent widening, so the
		// warning is driven by the consequence and not only by the stamp.
		$legacy = ['kind' => 'codex', 'session_id' => self::SID, 'agent_opts' => ['sandbox' => null, 'approval' => null]];

		$this->assertNotNull($this->gy->agentOptsUnknownWarning($legacy));
	}

	public function testResurrectWarnsAndStillProceedsWhenSandboxWasNotPreserved(): void
	{
		$this->liveRollout(self::SID, [], false);

		$cmux = new class($this->cli) extends \JT\Helpers\Cmux {
			public array $sent = [];
			public function sendToSurface(string $surfRef, string $wsRef, string $text): void { $this->sent[] = $text; }
			public function sendKeyToSurface(string $surfRef, string $wsRef, string $key): void {}
		};
		$gy = new class($this->cli, $cmux) extends Graveyard {
			public function launchTargetIsSafe(string $surfRef): bool { return true; }
			public function ensureTranscript(array $t): string { return '/dev/null/transcript.md'; }
			public function launch(array $t): string
			{
				return $this->launchSessionIntoSurface($t, 'surface:1', 'workspace:1', false);
			}
		};

		$tomb = [
			'kind' => 'codex', 'session_id' => self::SID, 'cwd' => '/Users/JT/x',
			'model' => 'gpt-5.6-terra', 'agent_opts' => [], 'agent_opts_unknown' => true,
		];

		ob_start();
		$mode = $gy->launch($tomb);
		$out  = (string) ob_get_clean();

		// Warns…
		$this->assertStringContainsString('sandbox', $out);
		$this->assertStringContainsString('config.toml', $out);
		// …and PROCEEDS: the resume still went out, unchanged.
		$this->assertSame('resume', $mode);
		$this->assertStringContainsString('codex resume', implode("\n", $cmux->sent));
	}

	public function testResurrectSaysNothingExtraForAPreservedCodexSession(): void
	{
		$this->liveRollout(self::SID);

		$cmux = new class($this->cli) extends \JT\Helpers\Cmux {
			public array $sent = [];
			public function sendToSurface(string $surfRef, string $wsRef, string $text): void { $this->sent[] = $text; }
			public function sendKeyToSurface(string $surfRef, string $wsRef, string $key): void {}
		};
		$gy = new class($this->cli, $cmux) extends Graveyard {
			public function launchTargetIsSafe(string $surfRef): bool { return true; }
			public function ensureTranscript(array $t): string { return '/dev/null/transcript.md'; }
			public function launch(array $t): string
			{
				return $this->launchSessionIntoSurface($t, 'surface:1', 'workspace:1', false);
			}
		};

		ob_start();
		$mode = $gy->launch([
			'kind' => 'codex', 'session_id' => self::SID, 'cwd' => '/Users/JT/x',
			'model' => 'gpt-5.6-terra', 'agent_opts' => ['sandbox' => 'read-only', 'approval' => 'never'],
		]);
		$out = (string) ob_get_clean();

		$this->assertSame('resume', $mode);
		$this->assertStringNotContainsString('config.toml', $out);
	}

	public function testStructuredOutputCarriesTheHonestyFlag(): void
	{
		// A view is a view (AGENTS.md): a fact the text output warns about and the
		// JSON omits is the same bug in a machine-readable coat.
		$row = $this->gy->searchRowJson(['kind' => 'codex', 'session_id' => self::SID, 'agent_opts_unknown' => true, 'agent_opts' => []]);
		$this->assertTrue($row['agent_opts_unknown']);

		$clean = $this->gy->searchRowJson(['kind' => 'codex', 'session_id' => self::SID, 'agent_opts' => ['sandbox' => 'read-only']]);
		$this->assertArrayNotHasKey('agent_opts_unknown', $clean);
	}
}
