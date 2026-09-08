<?php
namespace JT\Tests\Transport;

use JT\Helpers\Herdr;
use JT\Tests\TestCase;
use JT\Transport\HerdrTransport;

/**
 * Every test drives a stub script at HERDR_BIN, so nothing here reaches the real
 * herdr server (CLAUDE.md's shelling-seam rule; same hook as CMUX_BIN). The stub
 * dispatches on argv and logs every invocation, which is how the CLI shapes below
 * are pinned — `pane process-info` takes `--pane <id>` while `pane read`/
 * `send-text`/`send-keys`/`close` take a positional, and getting that backwards
 * makes herdr answer `unknown option: <pane id>`.
 *
 * There are no PHP class doubles in this file on purpose. A double that overrides
 * a method whose body has since moved doubles nothing and the test then exercises
 * the real implementation while still passing green — the trap this effort has hit
 * four times. Stubbing the BINARY instead cannot go quietly dead: unset HERDR_BIN
 * and the suite would shell out to a real herdr, which is what the off-PATH run
 * proves it does not.
 */
final class HerdrTransportTest extends TestCase
{
	private const CLAUDE_A = '11111111-1111-4111-8111-111111111111';
	private const CLAUDE_B = '22222222-2222-4222-8222-222222222222';
	private const CODEX    = '33333333-3333-4333-8333-333333333333';

	private string $fixture;
	private string $argvLog;
	private string $stubDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->fixture = dirname(__DIR__) . '/fixtures/herdr/snapshot-two-claude-one-codex.json';
		$this->stubDir = $this->graveyardRoot . '/herdr';
		$this->argvLog = $this->stubDir . '/argv.log';
		mkdir($this->stubDir, 0777, true);
		$this->installStub();

		// Codex reads are globbed out of a sessions root; point it at an empty temp dir
		// so no test can be answered by a REAL rollout on this machine.
		mkdir($this->stubDir . '/codex-sessions', 0777, true);
		putenv('CODEX_SESSIONS_DIR=' . $this->stubDir . '/codex-sessions');
	}

	protected function tearDown(): void
	{
		putenv('HERDR_BIN');
		putenv('CODEX_SESSIONS_DIR');
		parent::tearDown();
	}

	/**
	 * A stub herdr that answers each verb from a file under $stubDir, so a test
	 * changes one response by writing one file. A missing file for a JSON verb makes
	 * the stub fail the way herdr does (error JSON on stderr, exit 1).
	 */
	private function installStub(): void
	{
		$bin = $this->stubDir . '/herdr-stub';
		$dir = escapeshellarg($this->stubDir);
		$script = <<<SH
#!/bin/sh
printf "%s\\n" "\$*" >> {$dir}/argv.log
fail() {
  printf '%s' '{"error":{"code":"stub","message":"stub has no answer for '"\$1 \$2"'"}}' >&2
  exit 1
}
case "\$1 \$2" in
  "api snapshot")       cat {$dir}/snapshot.json 2>/dev/null || fail "\$1" "\$2" ;;
  "pane process-info")  cat {$dir}/procinfo-"\$4".json 2>/dev/null || fail "\$1" "\$2" ;;
  "pane read")          cat {$dir}/screen.txt 2>/dev/null || fail "\$1" "\$2" ;;
  "workspace create")   cat {$dir}/ws-create.json 2>/dev/null || fail "\$1" "\$2" ;;
  "pane split")         cat {$dir}/pane-split.json 2>/dev/null || fail "\$1" "\$2" ;;
  "status "*)           cat {$dir}/status.txt 2>/dev/null ;;
  *)                    echo '{"id":"stub","result":{"type":"ok"}}' ;;
esac
SH;
		file_put_contents($bin, $script);
		chmod($bin, 0755);
		putenv('HERDR_BIN=' . $bin);
		copy($this->fixture, $this->stubDir . '/snapshot.json');
	}

	/** Replace the snapshot the stub serves, after mutating the fixture's decoded form. */
	private function withSnapshot(callable $mutate): void
	{
		$data = json_decode((string) file_get_contents($this->fixture), true);
		$mutate($data['result']['snapshot']);
		file_put_contents($this->stubDir . '/snapshot.json', (string) json_encode($data));
	}

	private function write(string $name, string $body): void
	{
		file_put_contents($this->stubDir . '/' . $name, $body);
	}

	/**
	 * Answer `pane process-info --pane $paneId` with one foreground process.
	 * pid 1 is used wherever a test does NOT want the argv fallbacks to matter: it is
	 * alive on every platform and its argv carries no agent flags, so
	 * AgentArtifacts' ps fallback is deterministic instead of depending on whatever
	 * happens to hold a made-up pid on the machine running the suite.
	 */
	private function procInfo(string $paneId, string $argv0, array $argv, int $pid = 1): void
	{
		$this->write("procinfo-{$paneId}.json", (string) json_encode([
			'id'     => 'cli:pane:process_info',
			'result' => ['type' => 'pane_process_info', 'process_info' => [
				'pane_id'   => $paneId,
				'shell_pid' => 999,
				'foreground_processes' => [
					['pid' => $pid, 'argv0' => $argv0, 'argv' => $argv, 'name' => $argv0],
				],
			]],
		]));
	}

	/** The argv line of the stub's Nth (0-based) invocation. */
	private function loggedArgv(int $n = 0): string
	{
		$lines = array_values(array_filter(explode("\n", (string) @file_get_contents($this->argvLog)), 'strlen'));
		return $lines[$n] ?? '';
	}

	/** Every argv line the stub was called with, in order. */
	private function allArgv(): array
	{
		return array_values(array_filter(explode("\n", (string) @file_get_contents($this->argvLog)), 'strlen'));
	}

	private function transport(): HerdrTransport
	{
		return new HerdrTransport($this->cli, new Herdr($this->cli));
	}

	private function rowFor(array $rows, string $sid): array
	{
		foreach ($rows as $r) { if (($r['session_id'] ?? null) === $sid) { return $r; } }
		$this->fail("no row for {$sid}");
	}

	# =====================================================================
	# Identity.
	# =====================================================================

	public function testNameIsHerdr(): void
	{
		$this->assertSame('herdr', $this->transport()->name());
	}

	public function testAvailableDelegatesToTheHerdrClient(): void
	{
		$this->write('status.txt', "server:\n  status: running\n  compatible: yes\n");
		$this->assertTrue($this->transport()->available());

		$this->write('status.txt', "server:\n  status: stopped\n");
		$this->assertFalse($this->transport()->available());
	}

	/** herdr panes are terminals only — the F5 gap a grouped restore must confirm. */
	public function testDoesNotSupportNonTerminalSurfaces(): void
	{
		$this->assertFalse($this->transport()->supportsNonTerminalSurfaces());
	}

	# =====================================================================
	# liveSessions().
	# =====================================================================

	public function testLiveSessionsMapsAgentSessionValueToSessionId(): void
	{
		$ids = array_column($this->transport()->liveSessions(), 'session_id');

		$this->assertSame([self::CLAUDE_A, self::CLAUDE_B, self::CODEX], $ids);
	}

	public function testLiveSessionRowsAreStampedWithTheirTransport(): void
	{
		foreach ($this->transport()->liveSessions() as $row) {
			$this->assertSame('herdr', $row['transport']);
		}
	}

	/**
	 * herdr pane ids are stable for the pane's life, so ref and id carry the same
	 * handle everywhere cmux would have a positional ref and a separate UUID.
	 */
	public function testLiveSessionsCarriesPaneWorkspaceAndTabHandles(): void
	{
		$row = $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_B);

		$this->assertSame('wB:p2', $row['surface_ref']);
		$this->assertSame('wB:p2', $row['surface_id']);
		$this->assertSame('wB:p2', $row['pane_ref']);
		$this->assertSame('wB:p2', $row['home_pane_id']);
		$this->assertSame('wB', $row['workspace_ref']);
		$this->assertSame('wB', $row['home_workspace_id']);
		$this->assertSame('wB:t1', $row['window_ref']);
		// One terminal per herdr pane, so a surface is always its pane's first member.
		$this->assertSame(0, $row['home_index_in_pane']);
	}

	public function testLiveSessionsTakesTitlesFromTheWorkspaceLabelAndStrippedTerminalTitle(): void
	{
		$row = $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A);

		$this->assertSame('alpha', $row['workspace_title']);
		$this->assertSame('Alpha refactor', $row['tab_title']);
	}

	/** No label on the workspace: the id is what herdr's own UI falls back to. */
	public function testWorkspaceTitleFallsBackToTheWorkspaceId(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['workspaces'][0]['label'] = '';
		});

		$this->assertSame('wA', $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A)['workspace_title']);
	}

	public function testLiveSessionsCarriesTheAgentKind(): void
	{
		$rows = $this->transport()->liveSessions();

		$this->assertSame('claude', $this->rowFor($rows, self::CLAUDE_A)['agent']);
		$this->assertSame('codex', $this->rowFor($rows, self::CODEX)['agent']);
		$this->assertSame('/Users/tester/Sites/beta', $this->rowFor($rows, self::CODEX)['cwd']);
	}

	/** The fixture's bare shell pane (wB:p1) has no agent, so it is not a session. */
	public function testLiveSessionsSkipsPanesWithNoAgentSession(): void
	{
		$rows = $this->transport()->liveSessions();

		$this->assertNotContains('wB:p1', array_column($rows, 'surface_ref'));
		foreach ($rows as $row) { $this->assertNotSame('', $row['session_id']); }
	}

	/**
	 * herdr reports a pane it has DETECTED an agent in before its integration hook has
	 * published a session id. There is nothing to bury yet, and a row with an empty
	 * session_id reads as a targetable session to every caller.
	 */
	public function testLiveSessionsSkipsAnAgentWhoseSessionIsNotAnId(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['agents'][0]['agent_session']['kind']  = 'pending';
			$snap['agents'][1]['agent_session']['value'] = '';
		});

		$this->assertSame([self::CODEX], array_column($this->transport()->liveSessions(), 'session_id'));
	}

	/**
	 * graveyard archives claude and codex transcripts and no others, so an agent it
	 * cannot resume must never be presented as buryable.
	 */
	public function testLiveSessionsSkipsAgentKindsGraveyardCannotResume(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['agents'][0]['agent'] = 'gemini';
			$snap['agents'][0]['agent_session']['agent'] = 'gemini';
		});

		$this->assertSame([self::CLAUDE_B, self::CODEX], array_column($this->transport()->liveSessions(), 'session_id'));
	}

	/**
	 * Never a tty. herdr owns the PTY, and tty numbers are recycled across live
	 * surfaces, so a tty join mis-pairs sessions (CLAUDE.md's tty-recycling rule).
	 */
	public function testLiveSessionsNeverJoinsByTty(): void
	{
		foreach ($this->transport()->liveSessions() as $row) {
			$this->assertNull($row['tty']);
		}
	}

	/** herdr launches agents itself, so no row ever rides a resume-script bridge. */
	public function testLiveSessionsReportsNoBridgeFalse(): void
	{
		foreach ($this->transport()->liveSessions() as $row) {
			$this->assertFalse($row['no_bridge']);
		}
	}

	/**
	 * Same PHP_INT_MAX as CmuxTransport when the transcript cannot be read, so the two
	 * transports' idle clocks stay comparable in `graveyard candidates`. The fixture's
	 * cwds have no ~/.claude/projects entry, so this is the unreadable case.
	 */
	public function testIdleSecondsIsIntMaxWhenNoTranscriptIsReadable(): void
	{
		foreach ($this->transport()->liveSessions() as $row) {
			$this->assertSame(PHP_INT_MAX, $row['idle_seconds']);
		}
	}

	public function testIdleSecondsCountsFromTheClaudeTranscriptsLastRealTurn(): void
	{
		$cwd = $this->stubDir . '/proj';
		mkdir($cwd, 0777, true);
		$home = $this->stubDir . '/home';
		$key  = str_replace(['/', '.'], '-', $cwd);
		mkdir("{$home}/.claude/projects/{$key}", 0777, true);
		file_put_contents("{$home}/.claude/projects/{$key}/" . self::CLAUDE_A . '.jsonl',
			json_encode(['type' => 'user', 'timestamp' => gmdate('c', time() - 600), 'message' => ['content' => 'hi']]) . "\n");

		$this->withSnapshot(function (array &$snap) use ($cwd) {
			$snap['agents'][0]['cwd'] = $cwd;
		});

		$oldHome = getenv('HOME');
		putenv('HOME=' . $home);
		try {
			$idle = $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A)['idle_seconds'];
		} finally {
			putenv('HOME=' . $oldHome);
		}

		$this->assertGreaterThanOrEqual(595, $idle);
		$this->assertLessThan(700, $idle);
	}

	# =====================================================================
	# pid / model / skip_perms / opts, off `pane process-info`.
	# =====================================================================

	public function testPidAndLaunchFlagsComeFromThePanesForegroundProcess(): void
	{
		$this->procInfo('wA:p1', 'claude',
			['claude', '--model', 'opus', '--dangerously-skip-permissions'], 40645);

		$row = $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A);

		$this->assertSame(40645, $row['pid']);
		$this->assertSame('opus', $row['model']);
		$this->assertTrue($row['skip_perms']);
		// The flag form, not a positional — herdr answers `unknown option` otherwise.
		$this->assertContains('pane process-info --pane wA:p1', $this->allArgv());
	}

	/** `--model=opus` is the same flag; argv is a real array, so both forms are exact. */
	public function testModelIsReadFromTheEqualsFormToo(): void
	{
		$this->procInfo('wA:p1', 'claude', ['claude', '--model=sonnet']);

		$this->assertSame('sonnet', $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A)['model']);
	}

	/** A flag immediately followed by another flag took no value. */
	public function testModelIsNullWhenTheFlagCarriesNoValue(): void
	{
		$this->procInfo('wA:p1', 'claude', ['claude', '--model', '--dangerously-skip-permissions']);

		$row = $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A);
		$this->assertNull($row['model']);
		$this->assertTrue($row['skip_perms']);
	}

	/** Only the AGENT's process counts — a wrapper shell in the same pane does not. */
	public function testProcessLookupIgnoresNonAgentForegroundProcesses(): void
	{
		$this->write('procinfo-wA:p1.json', (string) json_encode([
			'result' => ['process_info' => ['foreground_processes' => [
				['pid' => 321, 'argv0' => 'zsh', 'argv' => ['zsh', '--model', 'nope']],
			]]],
		]));

		$row = $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A);
		$this->assertNull($row['pid']);
		$this->assertNull($row['model']);
	}

	/**
	 * A pane whose process-info herdr will not answer (it exited between the snapshot
	 * and the call) must degrade, not take the whole run down: the session is still
	 * real and still worth reporting.
	 */
	public function testAProcessInfoFailureDegradesToANullPidRatherThanThrowing(): void
	{
		$rows = $this->transport()->liveSessions();

		$this->assertCount(3, $rows);
		foreach ($rows as $row) { $this->assertNull($row['pid']); }
	}

	/** Claude expresses everything it needs through model + skip_perms. */
	public function testOptsIsEmptyForClaude(): void
	{
		$this->assertSame([], $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A)['opts']);
	}

	/**
	 * Codex's sandbox/approval/effort ARE the tombstone's agent_opts, and `codex
	 * resume` does not rehydrate them — dropping one silently widens a restored
	 * session's sandbox (dotfiles-f1n). The rollout is the source of truth; the launch
	 * flags only fill what it never recorded, which is what this fixture exercises.
	 */
	public function testOptsForCodexCarriesSandboxApprovalAndEffort(): void
	{
		$this->procInfo('wB:p3', 'codex',
			['codex', '--sandbox', 'read-only', '--ask-for-approval', 'never', '--model', 'gpt-5-codex']);

		$row = $this->rowFor($this->transport()->liveSessions(), self::CODEX);

		$this->assertSame(['sandbox', 'approval', 'effort'], array_keys($row['opts']));
		$this->assertSame('read-only', $row['opts']['sandbox']);
		$this->assertSame('never', $row['opts']['approval']);
		// No rollout to read an effort out of, and no launch flag for it either.
		$this->assertNull($row['opts']['effort']);
		$this->assertSame('gpt-5-codex', $row['model']);
		// Codex has no skip_perms; it says the same thing through sandbox/approval.
		$this->assertFalse($row['skip_perms']);
	}

	/** Codex's short flags are the same two knobs. */
	public function testCodexShortFlagsAreReadAsSandboxAndApproval(): void
	{
		$this->procInfo('wB:p3', 'codex', ['codex', '-s', 'workspace-write', '-a', 'on-request']);

		$opts = $this->rowFor($this->transport()->liveSessions(), self::CODEX)['opts'];
		$this->assertSame('workspace-write', $opts['sandbox']);
		$this->assertSame('on-request', $opts['approval']);
	}

	# =====================================================================
	# targetable / reason.
	# =====================================================================

	public function testASessionWithSessionCwdAndPaneIsTargetable(): void
	{
		$row = $this->rowFor($this->transport()->liveSessions(), self::CLAUDE_A);

		$this->assertTrue($row['targetable']);
		$this->assertNull($row['reason']);
	}

	/** The reason names the missing fact, so the abort report says what to fix. */
	public function testAMissingCwdOrPaneMakesASessionUntargetableWithAReason(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['agents'][0]['cwd'] = '';
			$snap['agents'][1]['pane_id'] = '';
		});

		$rows = $this->transport()->liveSessions();
		$a = $this->rowFor($rows, self::CLAUDE_A);
		$b = $this->rowFor($rows, self::CLAUDE_B);

		$this->assertFalse($a['targetable']);
		$this->assertSame('no cwd reported by herdr', $a['reason']);
		$this->assertFalse($b['targetable']);
		$this->assertSame('no pane reported by herdr', $b['reason']);
	}

	/** One row per session, as CmuxTransport closes too. */
	public function testLiveSessionsDedupesBySessionId(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$dup = $snap['agents'][0];
			$dup['pane_id'] = 'wA:p9';
			$snap['agents'][] = $dup;
		});

		$rows = $this->transport()->liveSessions();
		$this->assertCount(3, $rows);
		$this->assertSame('wA:p1', $this->rowFor($rows, self::CLAUDE_A)['surface_ref']);
	}

	/**
	 * An unreachable herdr must NOT read as "nothing is live". Reporting an empty
	 * world would tell JT there is nothing to bury when in fact graveyard cannot see;
	 * bin/graveyard's entry seam turns this throw into an exitErr.
	 */
	public function testAnUnreachableHerdrThrowsRatherThanReportingAnEmptyWorld(): void
	{
		unlink($this->stubDir . '/snapshot.json');

		$this->expectException(\RuntimeException::class);
		$this->transport()->liveSessions();
	}

	# =====================================================================
	# surfaces().
	# =====================================================================

	/** Every pane, including the bare shell liveSessions() skips. */
	public function testSurfacesReportsEveryPaneIncludingBareShells(): void
	{
		$rows = $this->transport()->surfaces();

		$this->assertSame(['wA:p1', 'wB:p1', 'wB:p2', 'wB:p3'], array_column($rows, 'pane_id'));
		$shell = $rows[1];
		$this->assertNull($shell['agent'], 'a bare shell is bound to no agent');
		$this->assertNull($shell['session_id']);
		$this->assertFalse($shell['targetable']);
	}

	public function testSurfacesAnnotatesEachPaneWithItsBoundAgent(): void
	{
		$rows = [];
		foreach ($this->transport()->surfaces() as $r) { $rows[$r['pane_id']] = $r; }

		$this->assertSame(self::CLAUDE_A, $rows['wA:p1']['session_id']);
		$this->assertSame('claude', $rows['wA:p1']['agent']);
		$this->assertSame(self::CODEX, $rows['wB:p3']['session_id']);
		$this->assertSame('codex', $rows['wB:p3']['agent']);
		$this->assertTrue($rows['wB:p3']['targetable']);
	}

	/**
	 * The order is load-bearing, not cosmetic: bury numbers a group's members by it
	 * and resurrect replays that numbering. herdr's own `layouts[].panes[]` is the
	 * geometric order, so it wins over whatever order `panes[]` arrives in.
	 */
	public function testSurfacesOrdersPanesByTheWorkspacesLayoutNotTheSnapshotOrder(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['panes'] = array_reverse($snap['panes']);
		});

		$this->assertSame(
			['wA:p1', 'wB:p1', 'wB:p2', 'wB:p3'],
			array_column($this->transport()->surfaces(), 'pane_id')
		);
	}

	/** A pane the layout omits is still part of the shape — appended, never dropped. */
	public function testSurfacesStillReportsAPaneMissingFromTheLayout(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['layouts'][1]['panes'] = array_values(array_filter(
				$snap['layouts'][1]['panes'],
				fn($p) => $p['pane_id'] !== 'wB:p2'
			));
		});

		$this->assertSame(
			['wA:p1', 'wB:p1', 'wB:p3', 'wB:p2'],
			array_column($this->transport()->surfaces(), 'pane_id')
		);
	}

	public function testSurfacesScopesByWorkspaceRef(): void
	{
		$this->assertCount(1, $this->transport()->surfaces('wA'));
		$this->assertCount(3, $this->transport()->surfaces('wB'));
		$this->assertSame([], $this->transport()->surfaces('wZZ'));
	}

	public function testSurfacesReportsPaneIndexPositionAndSelection(): void
	{
		$rows = $this->transport()->surfaces('wB');

		$this->assertSame([0, 1, 2], array_column($rows, 'pane_index'));
		foreach ($rows as $row) {
			// A herdr pane holds one terminal, so it is always at position 0 and always
			// the selected member of its pane.
			$this->assertSame(0, $row['position']);
			$this->assertTrue($row['selected_in_pane']);
			$this->assertSame('terminal', $row['type']);
			$this->assertNull($row['url']);
			$this->assertNull($row['tty']);
			$this->assertNull($row['script']);
		}
	}

	public function testSurfacesCarriesTheWorkspaceTitleTabRefAndPaneCwd(): void
	{
		$row = $this->transport()->surfaces('wB')[1];

		$this->assertSame('wB', $row['workspace_ref']);
		$this->assertSame('wB', $row['workspace_id']);
		$this->assertSame('beta', $row['workspace_title']);
		$this->assertSame('wB:t1', $row['window_ref']);
		$this->assertSame('/Users/tester/Sites/beta', $row['cwd']);
		$this->assertSame('Beta review', $row['title']);
	}

	# =====================================================================
	# Describe / resolve.
	# =====================================================================

	public function testWindowExistsAnswersOffTheSnapshotsOwnTabs(): void
	{
		$t = $this->transport();

		$this->assertTrue($t->windowExists('wA:t1'));
		$this->assertTrue($t->windowExists('wB:t1'));
		$this->assertFalse($t->windowExists('wZZ:t9'));
		$this->assertFalse($t->windowExists(''));
	}

	/** `tabs[]` is a convenience list; a pane naming the tab is proof enough. */
	public function testWindowExistsFallsBackToThePanesWhenTabsIsAbsent(): void
	{
		$this->withSnapshot(function (array &$snap) { unset($snap['tabs']); });

		$this->assertTrue($this->transport()->windowExists('wB:t1'));
	}

	public function testDescribeWorkspaceNamesTheWorkspaceAndItsSidebarSlot(): void
	{
		$this->assertSame('"beta" (workspace 2 of 2, wB)', $this->transport()->describeWorkspace('wB'));
	}

	public function testDescribeWorkspaceFallsBackForAHandleHerdrNoLongerHas(): void
	{
		$t = $this->transport();

		$this->assertSame('"gone" (wZZ)', $t->describeWorkspace('wZZ', 'gone'));
		$this->assertSame('wZZ', $t->describeWorkspace('wZZ'));
	}

	public function testResolveWorkspaceMatchesAnExactId(): void
	{
		$hit = $this->transport()->resolveWorkspace('wB');

		$this->assertSame('wB', $hit['ref']);
		$this->assertSame('beta', $hit['title']);
		$this->assertSame('wB:t1', $hit['window_ref']);
	}

	public function testResolveWorkspaceMatchesALabelExactlyThenBySubstring(): void
	{
		$t = $this->transport();

		$this->assertSame('wA', $t->resolveWorkspace('alpha')['ref']);
		$this->assertSame('wA', $t->resolveWorkspace('lph')['ref']);
		$this->assertNull($t->resolveWorkspace('nothing-like-this'));
	}

	/**
	 * The exact-title tiebreak: a workspace auto-labelled after its running command
	 * CONTAINS the query as a substring, which made the command ambiguous against
	 * itself (dotfiles-w7k). An exact label match wins outright.
	 */
	public function testResolveWorkspacePrefersAnExactLabelOverSubstringNoise(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['workspaces'][1]['label'] = 'graveyard bury alpha';
		});

		$this->assertSame('wA', $this->transport()->resolveWorkspace('alpha')['ref']);
	}

	/** Two substring hits and no exact label match is real ambiguity; callers report it. */
	public function testResolveWorkspaceThrowsOnGenuineAmbiguity(): void
	{
		$this->withSnapshot(function (array &$snap) {
			$snap['workspaces'][1]['label'] = 'alpha-two';
		});

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Ambiguous workspace 'alph'");
		$this->transport()->resolveWorkspace('alph');
	}

	public function testWorkspaceSurfaceCountCountsThePanesInTheWorkspace(): void
	{
		$t = $this->transport();

		$this->assertSame(1, $t->workspaceSurfaceCount('wA'));
		$this->assertSame(3, $t->workspaceSurfaceCount('wB'));
		$this->assertSame(0, $t->workspaceSurfaceCount('wZZ'));
	}

	# =====================================================================
	# Drive a surface.
	# =====================================================================

	public function testSendTextGoesToThePaneAsOneArgument(): void
	{
		$this->transport()->sendText('wA:p1', 'wA', '/export /tmp/x.md');

		$this->assertSame('pane send-text wA:p1 /export /tmp/x.md', $this->loggedArgv());
	}

	/**
	 * The two CLIs do not share a key vocabulary — cmux takes Return/Escape, herdr
	 * takes enter/esc — and a silently-wrong key name breaks bury with no error: the
	 * command sits in the REPL unsubmitted while bury waits for a transcript.
	 */
	public function testSendKeyTranslatesCmuxKeyNamesToHerdrs(): void
	{
		$t = $this->transport();
		$t->sendKey('wA:p1', 'wA', 'Return');
		$t->sendKey('wA:p1', 'wA', 'Escape');
		$t->sendKey('wA:p1', 'wA', 'ctrl+c');

		$this->assertSame([
			'pane send-keys wA:p1 enter',
			'pane send-keys wA:p1 esc',
			'pane send-keys wA:p1 ctrl+c',
		], $this->allArgv());
	}

	public function testHerdrKeyNameMapsEveryNameGraveyardActuallySends(): void
	{
		$t = $this->transport();

		$this->assertSame('enter', $t->herdrKeyName('Return'));
		$this->assertSame('enter', $t->herdrKeyName('enter'));
		$this->assertSame('enter', $t->herdrKeyName("\n"));
		$this->assertSame('esc', $t->herdrKeyName('Escape'));
		$this->assertSame('esc', $t->herdrKeyName('esc'));
		// Anything herdr already understands passes through, lowercased.
		$this->assertSame('tab', $t->herdrKeyName('Tab'));
		$this->assertSame('ctrl+c', $t->herdrKeyName('ctrl+c'));
	}

	/** $lines = 0 means "no limit", matching Cmux::readScreen(). */
	public function testReadScreenReadsTheRecentBufferAndHonoursALineBound(): void
	{
		$this->write('screen.txt', "line one\nline two\n");
		$t = $this->transport();

		$this->assertSame("line one\nline two", $t->readScreen('wA:p1', 'wA', 40));
		$this->assertSame('pane read wA:p1 --source recent --lines 40', $this->loggedArgv());

		$t->readScreen('wA:p1', 'wA');
		$this->assertSame('pane read wA:p1 --source recent', $this->loggedArgv(1));
	}

	/**
	 * '' rather than a throw, unlike the send verbs: bury POLLS this while waiting for
	 * a modal, so a transient read failure must cost one iteration, not the bury.
	 */
	public function testReadScreenAnswersEmptyRatherThanThrowingWhenTheReadFails(): void
	{
		$this->assertSame('', $this->transport()->readScreen('wZZ:p9', 'wZZ', 10));
	}

	# =====================================================================
	# Create.
	# =====================================================================

	public function testNewWorkspaceReturnsTheHandlesGraveyardReadsByName(): void
	{
		$this->write('ws-create.json', (string) json_encode(['result' => [
			'type'      => 'workspace_created',
			'workspace' => ['workspace_id' => 'wJ', 'label' => 'gy-restore'],
			'tab'       => ['tab_id' => 'wJ:t1'],
			'root_pane' => ['pane_id' => 'wJ:p1'],
		]]));

		$out = $this->transport()->newWorkspace('gy-restore', '/Users/tester/Sites/alpha');

		$this->assertSame(['ref', 'id', 'firstPaneRef', 'firstSurfRef'], array_keys($out));
		$this->assertSame('wJ', $out['ref']);
		$this->assertSame('wJ', $out['id']);
		// A herdr pane IS its surface, so both handles are the root pane.
		$this->assertSame('wJ:p1', $out['firstPaneRef']);
		$this->assertSame('wJ:p1', $out['firstSurfRef']);
	}

	/**
	 * herdr's `workspace create` takes only label/cwd/env/focus, so a restore cannot
	 * be aimed at the tab it was buried from — the ignored $windowRef is the honest
	 * shape of that gap, not an oversight.
	 */
	public function testNewWorkspaceIgnoresAWindowRefHerdrCannotHonour(): void
	{
		$this->write('ws-create.json', (string) json_encode(['result' => [
			'workspace' => ['workspace_id' => 'wJ'], 'root_pane' => ['pane_id' => 'wJ:p1'],
		]]));

		$this->transport()->newWorkspace('gy-restore', null, 'wA:t1');

		$this->assertSame('workspace create --label gy-restore --no-focus', $this->loggedArgv());
	}

	/** Null, never an exit: a mid-loop caller has to carry on with the rest. */
	public function testNewWorkspaceReturnsNullWhenTheCreateFails(): void
	{
		$this->assertNull($this->transport()->newWorkspace('gy-restore', null));
	}

	public function testNewWorkspaceReturnsNullWhenHerdrNamesNoRootPane(): void
	{
		$this->write('ws-create.json', (string) json_encode(['result' => [
			'workspace' => ['workspace_id' => 'wJ'],
		]]));

		$this->assertNull($this->transport()->newWorkspace('gy-restore', null));
	}

	/**
	 * A browser or markdown surface silently restored as a shell is a member the user
	 * thinks came back and did not, so it is refused rather than approximated.
	 */
	public function testNewSurfaceRefusesAnythingButATerminal(): void
	{
		$this->assertNull($this->transport()->newSurface('wA', 'wA:p1', 'browser', null));
		$this->assertNull($this->transport()->newSurface('wA', 'wA:p1', 'markdown', null));
		$this->assertSame([], $this->allArgv(), 'a refused type must not reach herdr at all');
	}

	/** herdr has no surface-inside-a-pane, so a new terminal is necessarily a split. */
	public function testNewSurfaceSplitsThePaneAndRunsTheCommand(): void
	{
		$this->write('pane-split.json', (string) json_encode([
			'result' => ['type' => 'pane_info', 'pane' => ['pane_id' => 'wA:p2']],
		]));

		$this->assertSame('wA:p2', $this->transport()->newSurface('wA', 'wA:p1', 'terminal', 'claude --resume abc'));
		$this->assertSame([
			'pane split --pane wA:p1 --direction right',
			'pane run wA:p2 claude --resume abc',
		], $this->allArgv());
	}

	public function testNewSurfaceWithNoPaneRefSplitsTheWorkspacesFirstPane(): void
	{
		$this->write('pane-split.json', (string) json_encode(['result' => ['pane' => ['pane_id' => 'wB:p4']]]));

		$this->assertSame('wB:p4', $this->transport()->newSurface('wB', null, 'terminal', null));
		$this->assertContains('pane split --pane wB:p1 --direction right', $this->allArgv());
	}

	public function testNewSurfaceIsNullWhenTheWorkspaceHasNoPaneToSplit(): void
	{
		$this->assertNull($this->transport()->newSurface('wZZ', null, 'terminal', null));
	}

	public function testNewSurfaceIsNullWhenTheSplitFails(): void
	{
		$this->assertNull($this->transport()->newSurface('wA', 'wA:p1', 'terminal', 'claude'));
		$this->assertSame(['pane split --pane wA:p1 --direction right'], $this->allArgv());
	}

	public function testNewSplitPassesTheSourcePaneAndDirection(): void
	{
		$this->write('pane-split.json', (string) json_encode(['result' => ['pane' => ['pane_id' => 'wA:p2']]]));

		$this->assertSame('wA:p2', $this->transport()->newSplit('wA', 'wA:p1', 'down'));
		$this->assertSame('pane split --pane wA:p1 --direction down', $this->loggedArgv());
	}

	/**
	 * herdr accepts only right and down. cmux's left/up are the same divider from the
	 * other side, so they fold onto their surviving axis — a restore that lost a pane
	 * would be worse than one whose split grew on the other side.
	 */
	public function testDirectionsHerdrDoesNotAcceptFoldOntoTheirAxis(): void
	{
		$t = $this->transport();

		$this->assertSame('right', $t->herdrDirection('right'));
		$this->assertSame('right', $t->herdrDirection('left'));
		$this->assertSame('right', $t->herdrDirection('anything-else'));
		$this->assertSame('down', $t->herdrDirection('down'));
		$this->assertSame('down', $t->herdrDirection('up'));
	}

	/**
	 * A herdr pane holds one terminal, so there is nothing to bring forward and the
	 * invariant this asks for already holds. It must not shell out: herdr's CLI has no
	 * focus-this-pane verb, and focusing the workspace instead would yank JT's focus
	 * mid-restore.
	 */
	public function testSelectSurfaceConfirmsThePaneWithoutDrivingAFocus(): void
	{
		$t = $this->transport();

		$this->assertTrue($t->selectSurface('wB', 'wB:p2'));
		$this->assertFalse($t->selectSurface('wB', 'wA:p1'), 'a pane in another workspace');
		$this->assertFalse($t->selectSurface('wB', 'wZZ:p9'));
		foreach ($this->allArgv() as $argv) {
			$this->assertSame('api snapshot', $argv, 'selectSurface may only read');
		}
	}

	public function testPaneRefForSurfaceIsThePaneItself(): void
	{
		$t = $this->transport();

		$this->assertSame('wB:p3', $t->paneRefForSurface('wB', 'wB:p3'));
		$this->assertNull($t->paneRefForSurface('wA', 'wB:p3'));
	}

	# =====================================================================
	# Teardown.
	# =====================================================================

	public function testCloseSurfaceAndCloseWorkspaceAnswerACommandResult(): void
	{
		$t = $this->transport();

		$this->assertSame(['output' => '', 'error' => '', 'exitCode' => 0], $t->closeSurface('wB:p3'));
		$this->assertSame(['output' => '', 'error' => '', 'exitCode' => 0], $t->closeWorkspace('wB'));
		$this->assertSame(['pane close wB:p3', 'workspace close wB'], $this->allArgv());
	}

	/** A failed close reads as a failure, not as an exception a mid-loop caller dies on. */
	public function testAFailedCloseReportsHerdrsOwnMessageWithANonZeroExit(): void
	{
		$bin = $this->stubDir . '/herdr-fail';
		file_put_contents($bin, "#!/bin/sh\nprintf '%s' '{\"error\":{\"code\":\"pane_not_found\",\"message\":\"pane wZZ:p9 not found\"}}' >&2\nexit 1\n");
		chmod($bin, 0755);
		putenv('HERDR_BIN=' . $bin);

		$res = $this->transport()->closeSurface('wZZ:p9');

		$this->assertSame(1, $res['exitCode']);
		$this->assertStringContainsString('pane wZZ:p9 not found', $res['error']);
	}

	# =====================================================================
	# Layout — unsupported, and null is the honest answer. See the docblock on
	# HerdrTransport::captureLayoutTree() for the three findings behind it.
	# =====================================================================

	public function testLayoutCaptureAndReplayReportThemselvesUnsupported(): void
	{
		$t = $this->transport();

		$this->assertNull($t->captureLayoutTree('wB'));
		$this->assertNull($t->newWorkspaceWithLayout('gy-restore', null, ['pane' => ['surfaces' => [[]]]]));
		// 0 never equals a workspace's member count, so Graveyard's geometry-restore
		// gate stays shut and the manual pane rebuild runs.
		$this->assertSame(0, $t->layoutTreeSurfaceCount(['pane' => ['surfaces' => [[], []]]]));
	}

	/**
	 * Identity rather than a throw: Graveyard sanitizes whatever tree a manifest
	 * carries, including one captured under cmux and re-read while herdr is the
	 * transport.
	 */
	public function testSanitizeLayoutTreeIsIdentityForHerdr(): void
	{
		$node = ['direction' => 'row', 'children' => [['pane' => ['surfaces' => [['command' => 'claude']]]]]];

		$this->assertSame($node, $this->transport()->sanitizeLayoutTree($node));
	}
}
