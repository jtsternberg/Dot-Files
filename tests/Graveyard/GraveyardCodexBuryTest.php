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
		$this->root = sys_get_temp_dir() . '/gy-codexbury-' . getmypid() . '-' . random_int(1000, 9999);
		mkdir($this->root, 0777, true);
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

	/** Write a rollout into a fake CODEX_SESSIONS_DIR and point the helper at it. */
	private function liveRollout(string $sid, array $extra = []): string
	{
		$dir = $this->root . '/codex-sessions/2026/07/29';
		mkdir($dir, 0777, true);
		putenv('CODEX_SESSIONS_DIR=' . $this->root . '/codex-sessions');

		$path  = "{$dir}/rollout-2026-07-29T09-03-42-{$sid}.jsonl";
		$lines = [json_encode(['timestamp' => '2026-07-29T09:03:42.000Z', 'type' => 'session_meta', 'payload' => array_merge([
			'session_id' => $sid,
			'cwd'        => '/Users/JT/x',
			'originator' => 'codex-tui',
		], $extra)])];
		$lines[] = json_encode(['timestamp' => '2026-07-29T09:05:00.000Z', 'type' => 'turn_context', 'payload' => [
			'model'           => 'gpt-5.6-terra',
			'approval_policy' => 'never',
			'sandbox_policy'  => ['type' => 'read-only'],
		]]);
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
	private function buryStub(array $liveBySurf, bool $rolloutExists = true): Graveyard
	{
		if ($rolloutExists) { $this->liveRollout(self::SID); }

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
}
