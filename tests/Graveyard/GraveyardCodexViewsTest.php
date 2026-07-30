<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;
use JT\Helpers\Cmux;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Codex rendering in every VIEW (dotfiles-6me, part 2).
 *
 * Bury already preserved codex losslessly, but nothing downstream could read what
 * it preserved: deriveSummary read a Claude jsonl that does not exist (so a codex
 * headstone was named after its directory — the live store's one buried codex
 * session came out ".dotfiles" when its opening prompt was "/system-watchdog"),
 * `show`/`search --full-text`/the page modal all looked for a transcript nobody
 * wrote, resurrect handed a fresh agent the raw .jsonl to read, and peek printed
 * "(no genuine conversation turns found)".
 *
 * The fix is one interchange format, not five special cases: CodexRollout renders
 * the rollout into the same markdown archive the Claude path writes, and every
 * reader keeps its single input. These tests pin each view against that, plus the
 * lock-step rule (AGENTS.md) that a field visible in text output must also be in
 * --json.
 */
final class GraveyardCodexViewsTest extends TestCase
{
	/** The real store's one buried codex session, whose summary was ".dotfiles". */
	private const SID = '019fa586-a9b7-7df0-a430-49907c5193f6';

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		// TestCase already created an isolated root and reliably removes it in tearDown.
		// Reusing it avoids leaking a second randomly named tree between suite runs.
		$this->root = $this->graveyardRoot;
		// Point the live-rollout lookup at an EMPTY tree by default, so a test that
		// wants the archived copy read really gets it (and nothing here can ever
		// resolve a real codex session on the machine running the suite).
		putenv('CODEX_SESSIONS_DIR=' . $this->root . '/codex-sessions');
		mkdir($this->root . '/codex-sessions', 0777, true);
		$this->gy = new Graveyard($this->cli, $this->cmux);
	}

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
		putenv('CODEX_SESSIONS_DIR');
		parent::tearDown();
	}

	# =========================================================================
	# Fixtures
	# =========================================================================

	/** A small but realistic rollout: slash-command prompt, a shell call, an answer. */
	private function rolloutBody(string $sid, string $prompt = '/system-watchdog'): string
	{
		$recs = [
			['timestamp' => '2026-07-28T20:00:00.000Z', 'type' => 'session_meta', 'payload' => [
				'session_id' => $sid, 'cwd' => '/Users/JT/.dotfiles', 'originator' => 'codex-tui',
			]],
			['timestamp' => '2026-07-28T20:00:01.000Z', 'type' => 'turn_context', 'payload' => ['model' => 'gpt-5.6-terra']],
			// The prompt arrives WRAPPED, exactly as a slash command does — that wrapper is
			// what summarizeUserText() turns into the title.
			['timestamp' => '2026-07-28T20:00:02.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'message', 'role' => 'user',
				'content' => [['type' => 'input_text', 'text' =>
					"<command-message>watchdog is running…</command-message>\n<command-name>{$prompt}</command-name>"]],
			]],
			['timestamp' => '2026-07-28T20:00:03.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call', 'name' => 'shell', 'call_id' => 'call-1',
				'arguments' => json_encode(['command' => ['bash', '-lc', 'ps aux | sort -nrk3']]),
			]],
			['timestamp' => '2026-07-28T20:00:04.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'function_call_output', 'call_id' => 'call-1',
				'output' => "kernelbrigand 402% cpu\n",
			]],
			['timestamp' => '2026-07-28T20:00:05.000Z', 'type' => 'response_item', 'payload' => [
				'type' => 'message', 'role' => 'assistant',
				'content' => [['type' => 'output_text', 'text' => "The offender is kernelbrigand.\nIt is pegged at 402%."]],
			]],
		];

		return implode("\n", array_map(fn($r) => json_encode($r), $recs)) . "\n";
	}

	/** Write the ARCHIVED rollout a buried codex tombstone carries. */
	private function archiveRollout(string $sid = self::SID, string $prompt = '/system-watchdog'): string
	{
		$path = $this->gy->codexRolloutArchivePath($sid);
		@mkdir(dirname($path), 0777, true);
		file_put_contents($path, $this->rolloutBody($sid, $prompt));
		return $path;
	}

	/** Write a LIVE rollout into the fake CODEX_SESSIONS_DIR tree. */
	private function liveRollout(string $sid = self::SID, string $prompt = '/live-prompt'): string
	{
		$dir = $this->root . '/codex-sessions/2026/07/28';
		@mkdir($dir, 0777, true);
		$path = "{$dir}/rollout-2026-07-28T20-00-00-{$sid}.jsonl";
		file_put_contents($path, $this->rolloutBody($sid, $prompt));
		return $path;
	}

	/** A live-session row as bury sees it (carries `agent`). */
	private function codexSess(array $extra = []): array
	{
		return $extra + [
			'session_id' => self::SID, 'agent' => 'codex', 'cwd' => '/Users/JT/.dotfiles',
			'surface_ref' => 'surface:86', 'workspace_ref' => 'workspace:17',
			'targetable' => true, 'reason' => '', 'idle_seconds' => 999999,
			'tab_title' => '.dotfiles', 'workspace_title' => 'dotfiles', 'pid' => 4242,
			'model' => 'gpt-5.6-terra', 'skip_perms' => false,
			'opts' => ['sandbox' => 'read-only', 'approval' => 'never', 'effort' => null],
		];
	}

	/** A buried codex tombstone as every view sees it (carries `kind`). */
	private function codexTomb(array $extra = []): array
	{
		return $extra + [
			'session_id' => self::SID, 'kind' => 'codex', 'cwd' => '/Users/JT/.dotfiles',
			'summary' => '/system-watchdog', 'tab_title' => '.dotfiles',
			'workspace_title' => 'dotfiles', 'buried_at' => '2026-07-28T20:10:00Z',
			'last_active' => '2026-07-28T20:00:05Z', 'model' => 'gpt-5.6-terra',
		];
	}

	# =========================================================================
	# A. deriveSummary — the headstone's title
	# =========================================================================

	public function testRowAgentReadsBothTheLiveAgentKeyAndTheTombstoneKind(): void
	{
		$this->assertSame('codex', $this->gy->rowAgent(['agent' => 'codex']));
		$this->assertSame('codex', $this->gy->rowAgent(['kind' => 'codex']));
		$this->assertSame('claude', $this->gy->rowAgent(['agent' => 'claude']));
		$this->assertSame('claude', $this->gy->rowAgent([]));
	}

	public function testDeriveSummaryReadsTheCodexRolloutInsteadOfTheTabTitle(): void
	{
		// THE bug: with no Claude jsonl to read, deriveSummary fell through to the tab
		// title, so the live store's codex tombstone is named ".dotfiles".
		$this->archiveRollout();

		$this->assertSame('/system-watchdog', $this->gy->deriveSummary($this->codexSess()));
	}

	public function testDeriveSummaryAcceptsATombstoneShapedRow(): void
	{
		// A repair/re-read pass hands it a tombstone, which carries `kind`, not `agent`.
		$this->archiveRollout();
		$row = $this->codexTomb();
		unset($row['summary']);

		$this->assertSame('/system-watchdog', $this->gy->deriveSummary($row));
	}

	public function testDeriveSummaryPrefersTheLiveRolloutOverTheArchivedCopy(): void
	{
		// Bury derives the summary while the session is still alive, and the live file
		// is the one with the latest content in it.
		$this->archiveRollout(self::SID, '/stale-archive');
		$this->liveRollout(self::SID, '/fresh-live');

		$this->assertSame('/fresh-live', $this->gy->deriveSummary($this->codexSess()));
	}

	public function testCodexRolloutReadPathFallsBackToTheArchiveThenToNothing(): void
	{
		$this->assertSame('', $this->gy->codexRolloutReadPath(self::SID));

		$archived = $this->archiveRollout();
		$this->assertSame($archived, $this->gy->codexRolloutReadPath(self::SID));

		$live = $this->liveRollout();
		$this->assertSame($live, $this->gy->codexRolloutReadPath(self::SID));
	}

	/**
	 * The page server builds Graveyard with NO cmux (bin/graveyard_router.php), so every
	 * cmux dereference on a store-only path is a latent fatal. Found by driving the real
	 * store rather than a fixture — the unit tests all had a cmux.
	 */
	public function testCodexPathsWorkWithoutACmux(): void
	{
		$this->archiveRollout();
		// The transcript endpoint resolves the session from the store, so it needs a record
		// to resolve — without one, null is the right answer and says nothing about cmux.
		$this->gy->upsertIndex($this->codexTomb());
		$gy = new Graveyard($this->cli, new \JT\Helpers\NullCmux($this->cli));

		$this->assertSame($this->gy->codexRolloutArchivePath(self::SID), $gy->codexRolloutReadPath(self::SID));
		$this->assertSame('/system-watchdog', $gy->deriveSummary($this->codexTomb()));
		$this->assertNotNull($gy->renderTranscriptJs(self::SID));
	}

	public function testDeriveSummaryFallsBackToTheTabTitleWithNoRolloutAtAll(): void
	{
		$this->assertSame('.dotfiles', $this->gy->deriveSummary($this->codexSess()));
	}

	public function testDeriveSummaryLeavesClaudeRowsOnTheClaudeJsonl(): void
	{
		// Regression guard: a claude row must not start reading rollouts, even when one
		// happens to be archived under the same id.
		$this->archiveRollout();
		$sess = $this->codexSess(['agent' => 'claude']);

		$this->assertSame('.dotfiles', $this->gy->deriveSummary($sess));
	}

	# =========================================================================
	# B. ensureTranscript — one markdown archive every reader already understands
	# =========================================================================

	public function testEnsureTranscriptRendersACodexRolloutIntoTheMarkdownArchive(): void
	{
		$this->archiveRollout();

		$path = $this->gy->ensureTranscript($this->codexTomb());

		$this->assertSame($this->gy->transcriptMdPath(self::SID), $path);
		$md = (string) file_get_contents($path);
		$this->assertStringContainsString('**You:** /system-watchdog', $md);
		$this->assertStringContainsString('The offender is kernelbrigand.', $md);
		$this->assertStringContainsString('shell: bash -lc ps aux', $md, 'tool calls must survive');
		$this->assertStringContainsString('kernelbrigand 402% cpu', $md, 'and so must their output');
	}

	public function testEnsureTranscriptTitlesTheArchiveWithTheTombstonesDisplayName(): void
	{
		$this->archiveRollout();

		$md = (string) file_get_contents($this->gy->ensureTranscript($this->codexTomb(['name' => 'watchdog triage'])));

		$this->assertStringStartsWith('# watchdog triage', $md);
	}

	public function testEnsureTranscriptIsANoOpForAClaudeTombstone(): void
	{
		$t = $this->codexTomb();
		unset($t['kind']);

		$this->assertSame($this->gy->transcriptPath(self::SID), $this->gy->ensureTranscript($t));
		$this->assertFileDoesNotExist($this->gy->transcriptMdPath(self::SID));
	}

	public function testEnsureTranscriptDoesNotClobberAnArchiveThatAlreadyExists(): void
	{
		$this->archiveRollout();
		@mkdir($this->gy->sessionDir(self::SID), 0777, true);
		file_put_contents($this->gy->transcriptMdPath(self::SID), "# already here\n");

		$this->gy->ensureTranscript($this->codexTomb());

		$this->assertSame("# already here\n", file_get_contents($this->gy->transcriptMdPath(self::SID)));
	}

	public function testEnsureTranscriptRespectsALegacyTxtArchive(): void
	{
		// Codex will not have one, but the resolver prefers .md, so writing one next to a
		// surviving .txt would leave readers on whichever the resolver picks. Don't.
		$this->archiveRollout();
		@mkdir($this->gy->sessionDir(self::SID), 0777, true);
		file_put_contents($this->gy->transcriptTxtPath(self::SID), "tui text\n");

		$this->assertSame($this->gy->transcriptTxtPath(self::SID), $this->gy->ensureTranscript($this->codexTomb()));
		$this->assertFileDoesNotExist($this->gy->transcriptMdPath(self::SID));
	}

	public function testEnsureTranscriptWritesNothingWithoutARollout(): void
	{
		// Never an empty archive: with nothing to render, the caller must keep its
		// existing "transcript missing" behaviour.
		$path = $this->gy->ensureTranscript($this->codexTomb());

		$this->assertSame($this->gy->transcriptPath(self::SID), $path);
		$this->assertFileDoesNotExist($path);
	}

	public function testEnsureTranscriptWritesNothingForAnUnreadableRollout(): void
	{
		$p = $this->gy->codexRolloutArchivePath(self::SID);
		@mkdir(dirname($p), 0777, true);
		file_put_contents($p, "not json at all\nnor this\n");

		$this->gy->ensureTranscript($this->codexTomb());

		$this->assertFileDoesNotExist($this->gy->transcriptMdPath(self::SID));
	}

	public function testEnsureTranscriptLandsAtomicallyAndLeavesNoTempFile(): void
	{
		$this->archiveRollout();
		$this->gy->ensureTranscript($this->codexTomb());

		$stray = glob($this->gy->sessionDir(self::SID) . '/.transcript.*') ?: [];
		$this->assertSame([], $stray);
	}

	public function testSearchFullTextMatchesACodexRolloutBody(): void
	{
		$this->archiveRollout();
		$this->gy->upsertIndex($this->codexTomb());
		$gy = new Graveyard($this->cli, $this->cmux);

		// "kernelbrigand" appears only in the rollout's tool output — nowhere in the
		// tombstone metadata — so this can only match through a rendered transcript.
		$this->assertSame([], $gy->searchTombstones('kernelbrigand', false));
		$hits = $gy->searchTombstones('kernelbrigand', true);
		$this->assertCount(1, $hits);
		$this->assertSame(self::SID, $hits[0]['session_id']);
	}

	public function testRenderTranscriptJsRendersACodexRolloutOnDemand(): void
	{
		$this->archiveRollout();
		$this->gy->upsertIndex($this->codexTomb());
		$gy = new Graveyard($this->cli, $this->cmux);

		// Returned null before this landed, so the page modal said
		// "(no transcript lies here)" for every codex headstone.
		$js = $gy->renderTranscriptJs(self::SID);
		$this->assertNotNull($js);
		$this->assertStringContainsString('window.GYT', $js);
		$this->assertStringContainsString('kernelbrigand', $js);
	}

	public function testRenderTranscriptJsStillReturnsNullForAnUnknownId(): void
	{
		$this->assertNull($this->gy->renderTranscriptJs('no-such-session'));
		$this->assertNull($this->gy->renderTranscriptJs(''));
	}

	public function testPageMarkupPointsACodexHeadstoneAtTheRenderedMarkdown(): void
	{
		$this->archiveRollout();
		$this->gy->upsertIndex($this->codexTomb());
		$gy = new Graveyard($this->cli, $this->cmux);

		$this->assertStringContainsString(
			$gy->transcriptMdPath(self::SID),
			$gy->renderStorePageHtml(),
			"the stone's data-tpath must be the rendered archive, not the raw rollout"
		);
	}

	public function testTombstonesResolveWithoutACmux(): void
	{
		// bin/graveyard_router.php builds Graveyard with NO cmux, and the page + the
		// transcript endpoint both go through tombstones() now. Nothing to ask about
		// liveness means nothing to annotate — not a fatal.
		$this->gy->upsertIndex($this->codexTomb());
		$gy = new Graveyard($this->cli, new \JT\Helpers\NullCmux($this->cli));

		$tombs = $gy->tombstones();
		$this->assertCount(1, $tombs);
		$this->assertFalse($tombs[0]['live']);
		// The page itself renders. Before this, EVERY page request fataled on
		// "loadClaudeSessionsByPid() on null" — `graveyard page` was dead on master.
		$html = $gy->renderStorePageHtml();
		$this->assertStringContainsString(substr(self::SID, 0, 8), $html);
	}

	public function testBuryCodexWritesTheRenderedTranscriptImmediately(): void
	{
		// A fresh codex bury must be greppable at once, without waiting for a later
		// read to heal it.
		$this->liveRollout(self::SID, '/system-watchdog');
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public array $killed = [];
			public function liveCodexBySurfaceRef(): array
			{
				return ['surface:86' => '019fa586-a9b7-7df0-a430-49907c5193f6'];
			}
			public function readLastScreen(string $s, string $w, int $lines = 6): string { return ''; }
			public function teardown(array $sess): bool { $this->killed[] = $sess['session_id']; return true; }
		};

		$this->assertTrue($stub->buryOne($this->codexSess(), true, true));

		$this->assertFileExists($stub->transcriptMdPath(self::SID));
		$this->assertStringContainsString(
			'kernelbrigand',
			(string) file_get_contents($stub->transcriptMdPath(self::SID))
		);
	}

	public function testBuryCodexStillSucceedsWhenTheTranscriptCannotBeRendered(): void
	{
		// The lossless rollout is already safe; a failed RENDER must never cost the
		// session its bury.
		$this->liveRollout(self::SID, '/system-watchdog');
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function liveCodexBySurfaceRef(): array
			{
				return ['surface:86' => '019fa586-a9b7-7df0-a430-49907c5193f6'];
			}
			public function readLastScreen(string $s, string $w, int $lines = 6): string { return ''; }
			public function teardown(array $sess): bool { return true; }
			public function ensureTranscript(array $t): string { throw new \RuntimeException('render exploded'); }
		};

		$this->assertTrue($stub->buryOne($this->codexSess(), true, true));
		$this->assertFileExists($stub->codexRolloutArchivePath(self::SID));
	}

	public function testResurrectHandsOverTheRenderedMarkdownNotTheRawRollout(): void
	{
		// Costs ~3s: the transcript launch path waits for the REPL to come up. Worth it
		// — this is the view that was actively telling a fresh agent to read a machine
		// log it cannot make sense of.
		$this->archiveRollout();

		$cmux = new class($this->cli) extends Cmux {
			public array $sent = [];
			public function sendToSurface(string $surfRef, string $wsRef, string $text): void { $this->sent[] = $text; }
			public function sendKeyToSurface(string $surfRef, string $wsRef, string $key): void {}
		};
		$gy = new class($this->cli, $cmux) extends Graveyard {
			public function launchTargetIsSafe(string $surfRef): bool { return true; }
			public function launch(array $t): string
			{
				return $this->launchSessionIntoSurface($t, 'surface:1', 'workspace:1', true);
			}
		};

		$this->assertSame('transcript', $gy->launch($this->codexTomb()));
		$handoff = implode("\n", $cmux->sent);
		$this->assertStringContainsString($gy->transcriptMdPath(self::SID), $handoff);
		$this->assertStringNotContainsString('rollout.jsonl', $handoff);
	}

	# =========================================================================
	# C. peek — the same renderer over already-normalized turns
	# =========================================================================

	public function testRenderNormalizedTurnsRendersPreNormalizedTurns(): void
	{
		$this->assertSame(
			"❯ hello\n⏺ hi back\n",
			$this->gy->renderNormalizedTurns([
				['role' => 'user', 'text' => 'hello'],
				['role' => 'assistant', 'text' => 'hi back'],
			], 6)
		);
		$this->assertSame('', $this->gy->renderNormalizedTurns([], 6));
	}

	public function testRenderNormalizedTurnsFlattensMultilineCodexProse(): void
	{
		// Codex turn text is markdown with real newlines; peek is one line per turn.
		$this->assertSame(
			"⏺ line one line two\n",
			$this->gy->renderNormalizedTurns([['role' => 'assistant', 'text' => "line one\n\nline two"]], 6)
		);
	}

	public function testRenderTurnsStillRoutesThroughTheSharedRenderer(): void
	{
		$entries = [
			['type' => 'user', 'message' => ['content' => 'first prompt']],
			['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'an answer']]]],
		];

		$this->assertSame(
			$this->gy->renderNormalizedTurns($this->gy->genuineTurns($entries), 6),
			$this->gy->renderTurns($entries, 6)
		);
	}

	public function testPeekRendersALiveCodexSessionsTurns(): void
	{
		$this->liveRollout(self::SID, '/system-watchdog');
		$gy = new class($this->cli, $this->cmux) extends Graveyard {
			public function resolveLiveByIdentifier(string $id): array
			{
				return [[
					'session_id' => '019fa586-a9b7-7df0-a430-49907c5193f6', 'agent' => 'codex',
					'cwd' => '/Users/JT/.dotfiles', 'model' => 'gpt-5.6-terra', 'idle_seconds' => 12,
					'tab_title' => '.dotfiles', 'workspace_title' => 'dotfiles', 'targetable' => true,
				]];
			}
		};

		ob_start();
		$gy->peekSession(self::SID, 6);
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString('no genuine conversation turns found', $out);
		$this->assertStringContainsString('❯ /system-watchdog', $out);
		$this->assertStringContainsString('The offender is kernelbrigand.', $out);
	}

	# =========================================================================
	# D. lock-step: the agent must be in the JSON too
	# =========================================================================

	public function testSearchRowJsonCarriesTheAgent(): void
	{
		// `ls --json` / `search --json` could not tell a codex row from a Claude one —
		// only `live_agent`, and only while the session happened to be running.
		$this->assertSame('codex', $this->gy->searchRowJson($this->codexTomb())['agent']);

		$claude = $this->codexTomb();
		unset($claude['kind']);
		$this->assertSame('claude', $this->gy->searchRowJson($claude)['agent']);
	}

	public function testLsAndSearchJsonAgreeOnTheAgent(): void
	{
		$this->gy->upsertIndex($this->codexTomb());
		$gy = new Graveyard($this->cli, $this->cmux);

		$fromLs     = $gy->lsJson($gy->tombstones())['sessions'][0];
		$fromSearch = $gy->searchRowJson($gy->searchTombstones('system-watchdog')[0]);

		$this->assertSame('codex', $fromLs['agent']);
		$this->assertSame($fromLs['agent'], $fromSearch['agent']);
	}

	public function testLsEntryMarksABuriedCodexRow(): void
	{
		// `candidates` already tags live codex rows "[codex]"; a buried one was
		// indistinguishable from a Claude headstone.
		$line = $this->gy->lsEntryLines($this->codexTomb(['name' => 'watchdog triage']), 120, '/Users/JT', 0)['primary'];
		$this->assertStringContainsString('[codex] watchdog triage', $line);

		$claude = $this->codexTomb(['name' => 'watchdog triage']);
		unset($claude['kind']);
		$this->assertStringNotContainsString('[codex]', $this->gy->lsEntryLines($claude, 120, '/Users/JT', 0)['primary']);
	}

	#[DataProvider('widths')]
	public function testACodexLsEntryStillFitsTheWidth(int $w): void
	{
		foreach ([0, 4] as $indent) {
			$e = $this->gy->lsEntryLines($this->codexTomb([
				'name' => 'a rather long codex session name that will not fit anywhere',
				'cwd'  => '/Users/JT/Sites/lindris-monorepo/local-frontend/lindris-frontend',
			]), $w, '/Users/JT', $indent);
			$this->assertLessThanOrEqual($w, mb_strlen($e['primary']));
			if ($e['secondary'] !== null) {
				$this->assertLessThanOrEqual($w, mb_strlen($e['secondary']));
			}
		}
	}


	public static function widths(): array
	{
		return [[60], [80], [120]];
	}
}
