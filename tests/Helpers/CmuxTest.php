<?php
namespace JT\Tests\Helpers;

use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ported from tests/graveyard/run.php — every assertion that targets a
 * JT\Helpers\Cmux method ($cmux->...).
 */
final class CmuxTest extends TestCase
{
	/** @var string[] temp paths to clean up */
	private array $tmpPaths = [];

	/** @var string[] temp directories to clean up */
	private array $tmpDirs = [];

	protected function tearDown(): void
	{
		foreach ($this->tmpPaths as $p) {
			if (is_file($p)) {
				@unlink($p);
			}
		}
		foreach ($this->tmpDirs as $dir) {
			foreach ((array) glob($dir . '/*') as $f) {
				@unlink($f);
			}
			@rmdir($dir);
		}
		$this->tmpPaths = [];
		$this->tmpDirs  = [];

		parent::tearDown();
	}

	public function testEncodeProjectKey(): void
	{
		$this->assertSame('-Users-JT--dotfiles', $this->cmux->encodeProjectKey('/Users/JT/.dotfiles'));
		$this->assertSame('-Users-JT-Code-claude-plugins', $this->cmux->encodeProjectKey('/Users/JT/Code/claude-plugins'));
		$this->assertSame('-Users-JT-Documents-Southport-UDO', $this->cmux->encodeProjectKey('/Users/JT/Documents/Southport UDO'));
		$this->assertSame('-Users-JT-Local-Sites-gatehouse-app-public-wp-content', $this->cmux->encodeProjectKey('/Users/JT/Local Sites/gatehouse/app/public/wp-content'));
	}

	public function testBuildResumeCommand(): void
	{
		$this->assertSame('claude --resume abc', $this->cmux->buildResumeCommand('abc', false, null));
		$this->assertSame('claude --dangerously-skip-permissions --resume abc --model=opus', $this->cmux->buildResumeCommand('abc', true, 'opus'));
	}

	public function testCmdHasSkipPerms(): void
	{
		// Bare flag and the yolo/yr/yc aliases all expand to the literal flag in argv.
		$this->assertTrue($this->cmux->cmdHasSkipPerms('claude --dangerously-skip-permissions'));
		$this->assertTrue($this->cmux->cmdHasSkipPerms('/opt/homebrew/bin/claude --resume abc --dangerously-skip-permissions'));
		$this->assertTrue($this->cmux->cmdHasSkipPerms('claude --dangerously-skip-permissions --model fable'));
		// Absent, and a lookalike prefix must not match.
		$this->assertFalse($this->cmux->cmdHasSkipPerms('/opt/homebrew/bin/claude --resume abc'));
		$this->assertFalse($this->cmux->cmdHasSkipPerms('claude --dangerously-skip-permissions-not-really'));
	}

	public function testResolveSkipPerms(): void
	{
		// jsonl permission-mode is authoritative when present (pid ignored).
		$this->assertTrue($this->cmux->resolveSkipPerms('bypassPermissions', null));
		$this->assertFalse($this->cmux->resolveSkipPerms('default', null));
		$this->assertFalse($this->cmux->resolveSkipPerms('acceptEdits', 999999));
		// Null mode (unreadable jsonl) with no pid falls back to a safe false.
		$this->assertFalse($this->cmux->resolveSkipPerms(null, null));
	}

	public function testCmdModelArg(): void
	{
		$this->assertSame('claude-fable-5', $this->cmux->cmdModelArg('claude --resume abc --model=claude-fable-5'));
		$this->assertSame('fable', $this->cmux->cmdModelArg('claude --dangerously-skip-permissions --model fable'));
		$this->assertNull($this->cmux->cmdModelArg('claude --resume abc'));
	}

	public function testResolveModel(): void
	{
		// jsonl model wins when present (pid ignored).
		$this->assertSame('claude-opus-4-8', $this->cmux->resolveModel('claude-opus-4-8', null));
		// Null jsonl model with no pid means no override (default model).
		$this->assertNull($this->cmux->resolveModel(null, null));
	}

	public function testNewWorkspaceExists(): void
	{
		$this->assertTrue(method_exists($this->cmux, 'newWorkspace'));
	}

	public function testWindowRefExists(): void
	{
		$tree = ['windows' => [['ref' => 'window:1'], ['ref' => 'window:2']]];
		$this->assertTrue($this->cmux->windowRefExists($tree, 'window:2'));
		$this->assertFalse($this->cmux->windowRefExists($tree, 'window:9'));
		$this->assertFalse($this->cmux->windowRefExists($tree, ''));
		$this->assertFalse($this->cmux->windowRefExists([], 'window:1'));
	}

	/** Collect all lines via eachLineReverse for a given content string. */
	private function reverseLines(string $content): array
	{
		$path = sys_get_temp_dir() . '/gy-rev-' . getmypid() . '-' . uniqid();
		file_put_contents($path, $content);
		$this->tmpPaths[] = $path;
		$seen = [];
		$this->cmux->eachLineReverse($path, function (string $l) use (&$seen) { $seen[] = $l; return true; });
		return $seen;
	}

	public function testEachLineReverseOrderingAndBoundaries(): void
	{
		// Basic reverse order, no trailing newline.
		$this->assertSame(['C', 'B', 'A'], $this->reverseLines("A\nB\nC"));
		// Trailing newline: blank final line skipped, order preserved.
		$this->assertSame(['C', 'B', 'A'], $this->reverseLines("A\nB\nC\n"));
		// Blank interior lines are skipped.
		$this->assertSame(['C', 'A'], $this->reverseLines("A\n\nC\n"));
		// Empty file yields nothing.
		$this->assertSame([], $this->reverseLines(''));
	}

	public function testEachLineReverseAcrossChunkBoundaries(): void
	{
		// Lines far larger than the 64KB chunk must still reassemble correctly.
		$big = str_repeat('x', 200000);
		$lines = $this->reverseLines("first\n{$big}\nlast");
		$this->assertSame(['last', $big, 'first'], $lines);
	}

	public function testEachLineReverseStopsEarly(): void
	{
		$path = sys_get_temp_dir() . '/gy-rev-stop-' . getmypid() . '-' . uniqid();
		file_put_contents($path, "A\nB\nC\nD\n");
		$this->tmpPaths[] = $path;
		$seen = [];
		$this->cmux->eachLineReverse($path, function (string $l) use (&$seen) {
			$seen[] = $l;
			return $l !== 'C'; // stop once we hit C
		});
		$this->assertSame(['D', 'C'], $seen);
	}

	public function testReadSessionJsonlTakesLastModelAndPermissionMode(): void
	{
		$tmpCwd = sys_get_temp_dir() . '/gy-test-cwd-' . getmypid();
		$tmpSid = 'test-sess-rsj-' . getmypid() . '-' . uniqid();
		$path = $this->cmux->jsonlPathFor($tmpSid, $tmpCwd);
		@mkdir(dirname($path), 0755, true);
		$this->tmpPaths[] = $path;

		// Model and permission mode both change over the conversation; the LAST wins.
		file_put_contents($path, implode("\n", [
			json_encode(['type' => 'permission-mode', 'permissionMode' => 'default']),
			json_encode(['type' => 'assistant', 'message' => ['model' => 'claude-opus-4-8']]),
			json_encode(['type' => 'assistant', 'message' => ['model' => '<synthetic>']]),
			json_encode(['type' => 'permission-mode', 'permissionMode' => 'bypassPermissions']),
			json_encode(['type' => 'assistant', 'message' => ['model' => 'claude-fable-5']]),
		]) . "\n");

		$meta = $this->cmux->readSessionJsonl($tmpSid, $tmpCwd);
		$this->assertSame('bypassPermissions', $meta['permission_mode']);
		$this->assertSame('claude-fable-5', $meta['model']); // synthetic ignored, last real wins
	}

	public function testReadSessionJsonlMissingFile(): void
	{
		$this->assertSame(['permission_mode' => null, 'model' => null], $this->cmux->readSessionJsonl('nope', '/no/such'));
	}

	public function testLastRealActivityReturnsLastUserOrAssistantTimestamp(): void
	{
		$tmpCwd = sys_get_temp_dir() . '/gy-test-cwd-' . getmypid();
		$tmpSid = 'test-sess-' . getmypid() . '-' . uniqid();
		$path = $this->cmux->jsonlPathFor($tmpSid, $tmpCwd);
		@mkdir(dirname($path), 0755, true);
		$this->tmpPaths[] = $path;

		$t1 = '2026-07-01T10:00:00Z';
		$t2 = '2026-07-01T10:05:00Z';
		$t3 = '2026-07-01T10:09:00Z';
		file_put_contents(
			$path,
			json_encode(['type' => 'user', 'timestamp' => $t1]) . "\n"
			. json_encode(['type' => 'assistant', 'timestamp' => $t2]) . "\n"
			. json_encode(['type' => 'system', 'timestamp' => $t3]) . "\n"
		);
		$this->assertSame(strtotime($t2), $this->cmux->lastRealActivity($tmpSid, $tmpCwd));
	}

	public function testLastRealActivityNullForMissingFile(): void
	{
		$this->assertNull($this->cmux->lastRealActivity('nope', '/no/such'));
	}

	public function testLastRealActivityIgnoresUnparseableTimestamp(): void
	{
		$tmpCwd = sys_get_temp_dir() . '/gy-test-cwd-' . getmypid();
		$tmpSid = 'test-sess-bad-ts-' . getmypid() . '-' . uniqid();
		$path = $this->cmux->jsonlPathFor($tmpSid, $tmpCwd);
		@mkdir(dirname($path), 0755, true);
		$this->tmpPaths[] = $path;

		$goodTs = '2026-02-01T09:00:00Z';
		file_put_contents(
			$path,
			json_encode(['type' => 'user', 'timestamp' => $goodTs]) . "\n"
			. json_encode(['type' => 'assistant', 'timestamp' => 'not-a-date']) . "\n"
		);
		$this->assertSame(strtotime($goodTs), $this->cmux->lastRealActivity($tmpSid, $tmpCwd));
	}

	public function testLastRealActivitySkipsSyntheticResumePair(): void
	{
		$tmpCwd = sys_get_temp_dir() . '/gy-test-cwd-' . getmypid();
		$tmpSid = 'test-sess-synthetic-' . getmypid() . '-' . uniqid();
		$path = $this->cmux->jsonlPathFor($tmpSid, $tmpCwd);
		@mkdir(dirname($path), 0755, true);
		$this->tmpPaths[] = $path;

		$genuineTs = '2026-06-05T12:00:00Z';
		$resumeTs  = '2026-06-14T16:19:17.795Z';
		file_put_contents(
			$path,
			json_encode(['type' => 'user', 'timestamp' => $genuineTs, 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'do the real work']]]]) . "\n"
			. json_encode(['type' => 'user', 'timestamp' => $resumeTs, 'isMeta' => true, 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]]) . "\n"
			. json_encode(['type' => 'assistant', 'timestamp' => $resumeTs, 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'No response requested.']]]]) . "\n"
		);
		$this->assertSame(strtotime($genuineTs), $this->cmux->lastRealActivity($tmpSid, $tmpCwd));
		$this->assertNotSame(strtotime($resumeTs), $this->cmux->lastRealActivity($tmpSid, $tmpCwd));
	}

	#[DataProvider('syntheticEntryProvider')]
	public function testIsSyntheticEntry(bool $expected, array $entry, string $label): void
	{
		$this->assertSame($expected, $this->cmux->isSyntheticEntry($entry), $label);
	}

	public static function syntheticEntryProvider(): array
	{
		return [
			'synthetic user marker' => [true, ['type' => 'user', 'isMeta' => true, 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]], 'synthetic user marker'],
			'synthetic assistant (model + text)' => [true, ['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'No response requested.']]]], 'synthetic assistant'],
			'synthetic model alone' => [true, ['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => []]], 'synthetic model alone'],
			'string-content marker (isMeta)' => [true, ['type' => 'user', 'isMeta' => true, 'message' => ['content' => 'No response requested.']], 'string-content marker'],
			'genuine user entry' => [false, ['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'do the real work']]]], 'genuine user'],
			'genuine human turn with literal marker text (no isMeta)' => [false, ['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]], 'genuine literal marker'],
			'genuine assistant entry' => [false, ['type' => 'assistant', 'message' => ['model' => 'claude-opus-4', 'content' => [['type' => 'text', 'text' => 'Here is the answer.']]]], 'genuine assistant'],
			'command-name invocation turn' => [true, ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => "<command-name>/export</command-name>\n            <command-message>export</command-message>"]]]], 'command-name turn'],
			'local-command-stdout turn' => [true, ['type' => 'user', 'message' => ['content' => '<local-command-stdout>Conversation exported to: /tmp/x.txt</local-command-stdout>']], 'local-command-stdout'],
			'local-command-caveat turn (isMeta)' => [true, ['type' => 'user', 'isMeta' => true, 'message' => ['content' => [['type' => 'text', 'text' => '<local-command-caveat>Caveat: The messages below were generated by the user while running local commands.</local-command-caveat>']]]], 'local-command-caveat'],
			'command tag not at start' => [false, ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'please run <command-name>/export</command-name> for me']]]], 'command tag mid-sentence'],
		];
	}

	public function testEncodeProjectKeyAndJsonlPathHandleSpaces(): void
	{
		$this->assertSame('-Users-JT-Documents-Southport-UDO', $this->cmux->encodeProjectKey('/Users/JT/Documents/Southport UDO'));
		$this->assertStringContainsString('Southport-UDO/sid.jsonl', $this->cmux->jsonlPathFor('sid', '/Users/JT/Documents/Southport UDO'));
	}

	public function testParseProcTableAndDescendants(): void
	{
		$psRaw = "  PID  PPID COMMAND\n"
			. "  100     1 /usr/bin/login -flp JT /bin/bash --noprofile --norc -c exec -l /bin/zsh '/tmp/cmux-surface-resume/claude-UUID1.zsh'\n"
			. "  200   100 -/bin/zsh /tmp/cmux-surface-resume/claude-UUID1.zsh\n"
			. "  300   200 /opt/homebrew/bin/claude --resume 11111111-1111-1111-1111-111111111111 --dangerously-skip-permissions\n"
			. "  400     1 /usr/bin/login -flp JT /bin/bash -c exec -l /bin/zsh '/tmp/cmux-agent-resume/claude-22222222-abc-UUID2.zsh'\n"
			. "  500   400 /opt/homebrew/bin/claude --resume 22222222-2222-2222-2222-222222222222\n";
		$proc = $this->cmux->parseProcTable($psRaw);

		$this->assertArrayHasKey(300, $proc);
		$this->assertSame(200, $proc[300]['ppid']);
		$this->assertTrue($this->cmux->isClaudeCommand($proc[300]['cmd']));
		$this->assertFalse($this->cmux->isClaudeCommand($proc[200]['cmd']));
		$this->assertSame(300, $this->cmux->descendantClaudePid($proc, 100));
		$this->assertSame([100, 200, 300], $this->cmux->descendantPids($proc, 100));
		$this->assertSame('claude-UUID1.zsh', $this->cmux->ancestorResumeScript($proc, 300));
		$this->assertSame('11111111-1111-1111-1111-111111111111', $this->cmux->claudeResumeArg($proc[300]['cmd']));
		$this->assertNull($this->cmux->claudeResumeArg('/opt/homebrew/bin/claude'));
	}

	public function testParseDebugTerminals(): void
	{
		$dtRaw = "[0] surface:59 \"lg\" mapped=1 tree=1 window=window:2 workspace=workspace:22 pane=pane:38 ctx=split\n"
			. "    runtime=1 focused=0\n"
			. "    tty=ttys052 cwd=/Users/JT/Code/asana-cli branch=main* ports=[]\n"
			. "    created=1s initialCommand=/bin/zsh '/tmp/cmux-surface-resume/claude-UUID1.zsh' portalHost=nil\n"
			. "[1] surface:70 \"other\" mapped=1 tree=1 window=window:1 workspace=workspace:9 pane=pane:5 ctx=split\n"
			. "    tty=ttys052 cwd=/Users/JT/Boss branch=nil ports=[]\n"
			. "    created=2s initialCommand=/bin/zsh '/tmp/cmux-agent-resume/claude-22222222-abc-UUID2.zsh' portalHost=nil\n";
		$dbg = $this->cmux->parseDebugTerminals($dtRaw);

		$this->assertArrayHasKey('surface:59', $dbg);
		$this->assertSame('ttys052', $dbg['surface:59']['tty']);
		$this->assertSame('/Users/JT/Code/asana-cli', $dbg['surface:59']['cwd']);
		$this->assertSame('workspace:22', $dbg['surface:59']['workspace_ref']);
		$this->assertSame('claude-UUID1.zsh', $dbg['surface:59']['script']);
		$this->assertSame('claude-22222222-abc-UUID2.zsh', $dbg['surface:70']['script']);
	}

	public function testJoinSessionsToSurfaces(): void
	{
		$psRaw = "  PID  PPID COMMAND\n"
			. "  100     1 /usr/bin/login -flp JT /bin/bash --noprofile --norc -c exec -l /bin/zsh '/tmp/cmux-surface-resume/claude-UUID1.zsh'\n"
			. "  200   100 -/bin/zsh /tmp/cmux-surface-resume/claude-UUID1.zsh\n"
			. "  300   200 /opt/homebrew/bin/claude --resume 11111111-1111-1111-1111-111111111111 --dangerously-skip-permissions\n"
			. "  400     1 /usr/bin/login -flp JT /bin/bash -c exec -l /bin/zsh '/tmp/cmux-agent-resume/claude-22222222-abc-UUID2.zsh'\n"
			. "  500   400 /opt/homebrew/bin/claude --resume 22222222-2222-2222-2222-222222222222\n";
		$proc = $this->cmux->parseProcTable($psRaw);
		$dtRaw = "[0] surface:59 \"lg\" mapped=1 tree=1 window=window:2 workspace=workspace:22 pane=pane:38 ctx=split\n"
			. "    tty=ttys052 cwd=/Users/JT/Code/asana-cli branch=main* ports=[]\n"
			. "    created=1s initialCommand=/bin/zsh '/tmp/cmux-surface-resume/claude-UUID1.zsh' portalHost=nil\n"
			. "[1] surface:70 \"other\" mapped=1 tree=1 window=window:1 workspace=workspace:9 pane=pane:5 ctx=split\n"
			. "    tty=ttys052 cwd=/Users/JT/Boss branch=nil ports=[]\n"
			. "    created=2s initialCommand=/bin/zsh '/tmp/cmux-agent-resume/claude-22222222-abc-UUID2.zsh' portalHost=nil\n";
		$dbg = $this->cmux->parseDebugTerminals($dtRaw);

		$sessions = [
			300 => ['session_id' => '11111111-1111-1111-1111-111111111111', 'cwd' => '/Users/JT/Code/asana-cli', 'model' => 'opus', 'skip_perms' => true],
			500 => ['session_id' => '22222222-2222-2222-2222-222222222222', 'cwd' => '/Users/JT/Boss', 'model' => null, 'skip_perms' => false],
		];
		$joined = $this->cmux->joinSessionsToSurfaces($sessions, $proc, $dbg);
		$bySid = [];
		foreach ($joined as $r) {
			$bySid[$r['session_id']] = $r;
		}
		$this->assertSame('surface:59', $bySid['11111111-1111-1111-1111-111111111111']['surface_ref']);
		$this->assertTrue($bySid['11111111-1111-1111-1111-111111111111']['targetable']);
		$this->assertSame(300, $bySid['11111111-1111-1111-1111-111111111111']['pid']);
		$this->assertSame('surface:70', $bySid['22222222-2222-2222-2222-222222222222']['surface_ref']);

		// --resume mismatch => untargetable
		$badProc = $proc;
		$badProc[300]['cmd'] = '/opt/homebrew/bin/claude --resume 99999999-9999-9999-9999-999999999999';
		$joinedBad = $this->cmux->joinSessionsToSurfaces($sessions, $badProc, $dbg);
		$badRow = null;
		foreach ($joinedBad as $r) {
			if ($r['session_id'] === '11111111-1111-1111-1111-111111111111') {
				$badRow = $r;
			}
		}
		$this->assertFalse($badRow['targetable']);
		$this->assertStringContainsString('--resume', $badRow['reason']);

		// shared resume script => ambiguous/untargetable
		$dupDbg = $dbg;
		$dupDbg['surface:70']['script'] = 'claude-UUID1.zsh';
		$joinedDup = $this->cmux->joinSessionsToSurfaces($sessions, $proc, $dupDbg);
		$dupRow = null;
		foreach ($joinedDup as $r) {
			if ($r['session_id'] === '11111111-1111-1111-1111-111111111111') {
				$dupRow = $r;
			}
		}
		$this->assertFalse($dupRow['targetable']);
		$this->assertStringContainsString('ambiguous', $dupRow['reason']);
	}

	# =========================================================================
	# CMUX_SURFACE_ID fallback for the Claude join (dotfiles-dr9).
	#
	# Fixtures below are the REAL argv shapes cmux launches Claude with — no
	# resume-script wrapper anywhere, a bare login zsh as the only ancestor, and
	# the surface identity carried solely in CMUX_SURFACE_ID. Every live session
	# on the machine looked like this and the script-only join bound none of them.
	# =========================================================================

	/** The four launch shapes seen live, all as children of a plain login zsh. */
	private function envJoinProc(): array
	{
		$settings = '--settings {"preferredNotifChannel":"notifications_disabled","hooks":{}}';

		return $this->cmux->parseProcTable(
			"  PID  PPID COMMAND\n"
			// fresh session — no session flag at all
			. "  200     1 -/bin/zsh\n"
			. "  300   200 /opt/homebrew/bin/claude {$settings}\n"
			// --session-id launched
			. "  400     1 -/bin/zsh\n"
			. "  500   400 /opt/homebrew/bin/claude --session-id 22222222-2222-2222-2222-222222222222 {$settings}\n"
			// --resume launched, via a wrapper shim the session file is keyed on
			. "  600     1 -/bin/zsh\n"
			. "  700   600 /bin/zsh -c exec claude --dangerously-skip-permissions --resume 33333333-3333-3333-3333-333333333333\n"
			. "  710   700 /opt/homebrew/bin/claude --dangerously-skip-permissions --resume 33333333-3333-3333-3333-333333333333 --model=opus\n"
			// hand-launched outside cmux entirely
			. "  800     1 /opt/homebrew/bin/zsh -il\n"
			. "  900   800 claude --dangerously-skip-permissions\n"
		);
	}

	/** debug-terminals with NO resume script on any surface — the live shape. */
	private function envJoinDebug(): array
	{
		return $this->cmux->parseDebugTerminals(
			"[0] surface:28 \"lg\" mapped=1 tree=1 window=window:1 workspace=workspace:9 pane=pane:5 ctx=split\n"
			. "    tty=ttys052 cwd=/Users/JT/Sites/lindris-monorepo branch=main* ports=[]\n"
			. "    created=1s initialCommand=nil portalHost=nil\n"
		);
	}

	/** uuid => surface map, as mapSurfaceUuids() reads it off a live tree. */
	private function envJoinSurfaceUuids(): array
	{
		return $this->cmux->mapSurfaceUuids([
			'windows' => [[
				'ref'        => 'window:1',
				'workspaces' => [[
					'ref'   => 'workspace:9',
					'title' => 'cmb security',
					'panes' => [[
						'ref'      => 'pane:5',
						'surfaces' => [
							['id' => '17F50BA2-D5AC-4B23-B3CD-B1554F56B253', 'ref' => 'surface:28', 'tty' => 'ttys052', 'title' => 'fresh', 'type' => 'terminal'],
							['id' => 'FDD57310-4984-47C8-9279-02296D09C575', 'ref' => 'surface:76', 'tty' => 'ttys053', 'title' => 'by-id', 'type' => 'terminal'],
							['id' => 'AEF5B611-0B13-4BDD-AFB8-B3FC73CB410A', 'ref' => 'surface:30', 'tty' => 'ttys054', 'title' => 'resumed', 'type' => 'terminal'],
						],
					]],
				]],
			]],
		]);
	}

	/** @return array<string,array> join rows keyed by session id */
	private function envJoinRows(array $sessions): array
	{
		$rows = $this->cmux->joinSessionsToSurfaces(
			$sessions,
			$this->envJoinProc(),
			$this->envJoinDebug(),
			$this->envJoinSurfaceUuids()
		);
		$bySid = [];
		foreach ($rows as $r) {
			$bySid[(string) $r['session_id']] = $r;
		}

		return $bySid;
	}

	public function testJoinSessionsToSurfacesBindsViaSurfaceIdEnvWithNoResumeScript(): void
	{
		$rows = $this->envJoinRows([
			300 => ['session_id' => '11111111-1111-1111-1111-111111111111', 'cwd' => '/Users/JT/Sites/lindris-monorepo', 'model' => null, 'skip_perms' => false, 'surface_id' => '17F50BA2-D5AC-4B23-B3CD-B1554F56B253'],
			500 => ['session_id' => '22222222-2222-2222-2222-222222222222', 'cwd' => '/Users/JT/Code/claude-plugins', 'model' => null, 'skip_perms' => false, 'surface_id' => 'FDD57310-4984-47C8-9279-02296D09C575'],
			700 => ['session_id' => '33333333-3333-3333-3333-333333333333', 'cwd' => '/Users/JT/.dotfiles', 'model' => 'opus', 'skip_perms' => true, 'surface_id' => 'AEF5B611-0B13-4BDD-AFB8-B3FC73CB410A'],
		]);

		// Fresh session, no session flag in argv.
		$fresh = $rows['11111111-1111-1111-1111-111111111111'];
		$this->assertTrue($fresh['targetable'], "fresh session unbound: {$fresh['reason']}");
		$this->assertSame('surface:28', $fresh['surface_ref']);
		$this->assertSame('workspace:9', $fresh['workspace_ref']);
		$this->assertSame('ttys052', $fresh['tty']);
		$this->assertSame('fresh', $fresh['title']);
		$this->assertSame('claude', $fresh['agent']);

		// --session-id launched.
		$byId = $rows['22222222-2222-2222-2222-222222222222'];
		$this->assertTrue($byId['targetable'], "--session-id session unbound: {$byId['reason']}");
		$this->assertSame('surface:76', $byId['surface_ref']);

		// --resume launched behind a wrapper shim: binds, and the row carries the
		// claude pid (710), not the shim the session file is keyed on (700).
		$resumed = $rows['33333333-3333-3333-3333-333333333333'];
		$this->assertTrue($resumed['targetable'], "--resume session unbound: {$resumed['reason']}");
		$this->assertSame('surface:30', $resumed['surface_ref']);
		$this->assertSame(710, $resumed['pid']);
	}

	public function testJoinSessionsToSurfacesReportsWhySurfaceIdBindFailed(): void
	{
		$rows = $this->envJoinRows([
			// Hand-launched outside cmux: no CMUX_SURFACE_ID to bind with.
			900 => ['session_id' => '44444444-4444-4444-4444-444444444444', 'cwd' => '/Users/JT', 'surface_id' => null],
			// Surface closed since the session started (pr-swarm teardown).
			500 => ['session_id' => '55555555-5555-5555-5555-555555555555', 'cwd' => '/Users/JT', 'surface_id' => 'D5B0E391-C8D5-4F81-8BB9-938BCD6A3DFD'],
		]);

		// No bridge at all: the only row a screen-scraping fallback may still guess at.
		$noEnv = $rows['44444444-4444-4444-4444-444444444444'];
		$this->assertFalse($noEnv['targetable']);
		$this->assertStringContainsString('CMUX_SURFACE_ID', $noEnv['reason']);
		$this->assertTrue($noEnv['no_bridge']);

		// Names a surface that has since closed — it is somewhere else, so it is NOT a
		// content-probe candidate; guessing it onto another surface would mis-bind it.
		$gone = $rows['55555555-5555-5555-5555-555555555555'];
		$this->assertFalse($gone['targetable']);
		$this->assertStringContainsString('not found among cmux surfaces', $gone['reason']);
		$this->assertFalse($gone['no_bridge']);
	}

	public function testBoundRowsAreNeverContentProbeCandidates(): void
	{
		$rows = $this->envJoinRows([
			300 => ['session_id' => '11111111-1111-1111-1111-111111111111', 'cwd' => '/Users/JT', 'surface_id' => '17F50BA2-D5AC-4B23-B3CD-B1554F56B253'],
		]);

		$this->assertFalse($rows['11111111-1111-1111-1111-111111111111']['no_bridge']);
	}

	public function testEnvBindKeepsThePidFilesSessionIdOverAStaleResumeArg(): void
	{
		// Resuming a transcript another live session already holds forks a NEW session
		// id, leaving --resume in argv pointing at the ORIGINAL — measured live, a
		// process launched `--resume 33333333…` whose own pid file said 99999999…,
		// both transcripts growing. The pid file is the process's own report of what
		// it is now, and CMUX_SURFACE_ID already pins the surface exactly, so the row
		// binds and carries the pid file's id. Vetoing here (as the script bridge
		// rightly does) drops a live session from the backup.
		$rows = $this->envJoinRows([
			700 => ['session_id' => '99999999-9999-9999-9999-999999999999', 'cwd' => '/Users/JT/.dotfiles', 'surface_id' => 'AEF5B611-0B13-4BDD-AFB8-B3FC73CB410A'],
		]);

		$row = $rows['99999999-9999-9999-9999-999999999999'];
		$this->assertTrue($row['targetable'], "stale --resume arg blocked the env bind: {$row['reason']}");
		$this->assertSame('surface:30', $row['surface_ref']);
		$this->assertSame('99999999-9999-9999-9999-999999999999', $row['session_id']);
	}

	public function testJoinSessionsToSurfacesEnvBindDetectsSurfaceCollision(): void
	{
		$rows = $this->envJoinRows([
			300 => ['session_id' => '11111111-1111-1111-1111-111111111111', 'cwd' => '/Users/JT', 'surface_id' => '17F50BA2-D5AC-4B23-B3CD-B1554F56B253'],
			500 => ['session_id' => '22222222-2222-2222-2222-222222222222', 'cwd' => '/Users/JT', 'surface_id' => '17F50BA2-D5AC-4B23-B3CD-B1554F56B253'],
		]);

		foreach ($rows as $r) {
			$this->assertFalse($r['targetable']);
			$this->assertStringContainsString('collision', $r['reason']);
		}
	}

	public function testLoadClaudeSessionsByPidCarriesTheSurfaceId(): void
	{
		// The join can only bind by CMUX_SURFACE_ID if the session loader reads it
		// off the live process. Stub the two shell-outs; keep the real file walk.
		$dir = sys_get_temp_dir() . '/cmux-sessions-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($dir, 0777, true);
		$this->tmpDirs[] = $dir;
		file_put_contents($dir . '/4242.json', json_encode([
			'pid' => 4242, 'sessionId' => '11111111-1111-1111-1111-111111111111', 'cwd' => '/Users/JT/.dotfiles',
		]));
		putenv('CLAUDE_SESSIONS_DIR=' . $dir);

		$cmux = new class ($this->cli) extends \JT\Helpers\Cmux {
			public function pidIsAlive(int $pid): bool { return true; }
			public function pidEnv(int $pid): string { return "  {$pid} claude CMUX_SURFACE_ID=17F50BA2-D5AC-4B23-B3CD-B1554F56B253 TERM=xterm"; }
			public function readSessionJsonl(?string $sid, ?string $cwd): array { return []; }
		};

		try {
			$sessions = $cmux->loadClaudeSessionsByPid();
		} finally {
			putenv('CLAUDE_SESSIONS_DIR');
		}

		$this->assertArrayHasKey(4242, $sessions);
		$this->assertSame('17F50BA2-D5AC-4B23-B3CD-B1554F56B253', $sessions[4242]['surface_id']);
	}

	public function testUuidv4(): void
	{
		$uu = $this->cmux->uuidv4();
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uu);
		$this->assertNotSame($this->cmux->uuidv4(), $this->cmux->uuidv4());
	}

	public function testResolveWorkspaceNode(): void
	{
		$wtree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:9', 'title' => 'asana-skill update', 'panes' => []],
			['ref' => 'workspace:12', 'title' => 'boss backend', 'panes' => []],
			['ref' => 'workspace:13', 'title' => 'boss frontend', 'panes' => []],
		]]]];
		$this->assertSame('boss backend', $this->cmux->resolveWorkspaceNode($wtree, 'workspace:12')['title']);
		$this->assertSame('workspace:9', $this->cmux->resolveWorkspaceNode($wtree, 'asana')['ref']);
		$this->assertNull($this->cmux->resolveWorkspaceNode($wtree, 'nope'));

		$this->expectException(\RuntimeException::class);
		$this->cmux->resolveWorkspaceNode($wtree, 'boss');
	}

	/**
	 * Reported bug: cmux auto-titles the workspace a bury command runs in after the
	 * literal command line, so the query becomes a substring of that title and the
	 * command makes itself ambiguous. An exact normalized-title match must win.
	 */
	public function testResolveWorkspaceNodeExactBeatsSubstring(): void
	{
		$query = 'wpforms migration script issues';
		$wtree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:10', 'title' => $query, 'panes' => []],
			['ref' => 'workspace:12', 'title' => "graveyard bury --workspace '{$query}' -y", 'panes' => []],
		]]]];
		// Exact match wins outright despite the substring hit on workspace:12.
		$this->assertSame('workspace:10', $this->cmux->resolveWorkspaceNode($wtree, $query)['ref']);
	}

	/** Exact match still wins when the real title carries a leading cmux status glyph. */
	public function testResolveWorkspaceNodeExactWithGlyphPrefix(): void
	{
		$query = 'wpforms migration script issues';
		$wtree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:10', 'title' => "⠂ {$query}", 'panes' => []],
			['ref' => 'workspace:12', 'title' => "✳ graveyard bury --workspace '{$query}' -y", 'panes' => []],
		]]]];
		$this->assertSame('workspace:10', $this->cmux->resolveWorkspaceNode($wtree, $query)['ref']);
	}

	/** Case-insensitive exact match. */
	public function testResolveWorkspaceNodeExactIsCaseInsensitive(): void
	{
		$wtree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:10', 'title' => 'WPForms Migration', 'panes' => []],
			['ref' => 'workspace:12', 'title' => 'other running WPForms Migration job', 'panes' => []],
		]]]];
		$this->assertSame('workspace:10', $this->cmux->resolveWorkspaceNode($wtree, 'wpforms migration')['ref']);
	}

	/** No exact match: still falls back to substring resolution. */
	public function testResolveWorkspaceNodeFallsBackToSubstring(): void
	{
		$wtree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:9', 'title' => 'asana-skill update', 'panes' => []],
			['ref' => 'workspace:12', 'title' => 'boss backend', 'panes' => []],
		]]]];
		$this->assertSame('workspace:12', $this->cmux->resolveWorkspaceNode($wtree, 'backend')['ref']);
	}

	/** Genuine ambiguity: two exact normalized-title matches still throw. */
	public function testResolveWorkspaceNodeTwoExactStillAmbiguous(): void
	{
		$wtree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:10', 'title' => 'deploy', 'panes' => []],
			['ref' => 'workspace:11', 'title' => '⠂ deploy', 'panes' => []],
		]]]];
		$this->expectException(\RuntimeException::class);
		$this->cmux->resolveWorkspaceNode($wtree, 'deploy');
	}

	private function twoWindowTree(): array
	{
		return ['windows' => [
			['ref' => 'window:1', 'workspaces' => [
				['ref' => 'workspace:23', 'title' => '~/Sites/lindris-monorepo'],
				['ref' => 'workspace:27', 'title' => 'levamo cloudflare setup'],
			]],
			['ref' => 'window:2', 'workspaces' => [
				['ref' => 'workspace:18', 'title' => '⠐ graveyard resurrect naming'],
				['ref' => 'workspace:17', 'title' => 'claudeplugins codex friendly'],
				['ref' => 'workspace:5',  'title' => 'cmb security'],
			]],
		]];
	}

	/** A ref alone is not a location: the description leads with the workspace NAME. */
	public function testDescribeWorkspaceRefNamesTheWorkspace(): void
	{
		$this->assertSame(
			'"levamo cloudflare setup" (window 1, workspace 2 of 2, workspace:27)',
			$this->cmux->describeWorkspaceRef($this->twoWindowTree(), 'workspace:27')
		);
	}

	/** Sidebar position is per-window and 1-based, so it matches what you count on screen. */
	public function testDescribeWorkspaceRefPositionIsPerWindow(): void
	{
		$this->assertSame(
			'"cmb security" (window 2, workspace 3 of 3, workspace:5)',
			$this->cmux->describeWorkspaceRef($this->twoWindowTree(), 'workspace:5')
		);
	}

	/** cmux's live status glyph is noise in a "go look here" message. */
	public function testDescribeWorkspaceRefStripsStatusGlyph(): void
	{
		$this->assertSame(
			'"graveyard resurrect naming" (window 2, workspace 1 of 3, workspace:18)',
			$this->cmux->describeWorkspaceRef($this->twoWindowTree(), 'workspace:18')
		);
	}

	/** One window means the window number carries no information — drop it. */
	public function testDescribeWorkspaceRefOmitsWindowWhenOnlyOne(): void
	{
		$tree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:9',  'title' => 'asana-skill update'],
			['ref' => 'workspace:12', 'title' => 'boss backend'],
		]]]];
		$this->assertSame(
			'"boss backend" (workspace 2 of 2, workspace:12)',
			$this->cmux->describeWorkspaceRef($tree, 'workspace:12')
		);
	}

	/** Ref not in the tree: still name it from the caller's fallback title. */
	public function testDescribeWorkspaceRefFallsBackToGivenTitle(): void
	{
		$this->assertSame(
			'"levamo cloudflare setup" (workspace:99)',
			$this->cmux->describeWorkspaceRef($this->twoWindowTree(), 'workspace:99', 'levamo cloudflare setup')
		);
		$this->assertSame(
			'workspace:99',
			$this->cmux->describeWorkspaceRef($this->twoWindowTree(), 'workspace:99')
		);
	}

	/**
	 * stripGlyph() comes from TitleGlyphTrait — one implementation shared with
	 * Graveyard (which renders the served page with no cmux at all, so it can't
	 * borrow the method off this class). Keeps ASCII-leading titles (paths) intact.
	 */
	public function testStripGlyph(): void
	{
		$this->assertSame('~/Sites/lindris-monorepo', $this->cmux->stripGlyph('~/Sites/lindris-monorepo'));
		$this->assertSame('deploy', $this->cmux->stripGlyph('⠂ deploy'));
		$this->assertSame('deploy', $this->cmux->stripGlyph('  ✳ deploy  '));
		// Same method, same result, in the class that has no cmux.
		$this->assertSame($this->gy->stripGlyph('⠂ deploy'), $this->cmux->stripGlyph('⠂ deploy'));
	}

	/**
	 * Write a stub cmux at CMUX_BIN whose `tree` subcommand emits $treeOutput (nothing
	 * when '', as an absent/unreachable cmux would); every other subcommand is a no-op.
	 */
	private function stubCmux(string $treeOutput): void
	{
		$bin  = sys_get_temp_dir() . '/cmux-stub-' . getmypid() . '-' . uniqid();
		$body = $treeOutput === '' ? ':' : "cat <<'CMUXEOF'\n{$treeOutput}\nCMUXEOF";
		file_put_contents($bin, "#!/bin/sh\nif [ \"\$1\" = tree ]; then\n{$body}\nfi\n");
		chmod($bin, 0755);
		$this->tmpPaths[] = $bin;
		putenv('CMUX_BIN=' . $bin);
	}

	/**
	 * dotfiles-3qa: tree() must THROW when cmux yields nothing (unreachable/absent),
	 * not exit() — exit() inside this shelling seam killed PHPUnit mid-run wherever cmux
	 * was absent. CMUX_BIN is the GODO_DIRMAP_BIN-style hook that lets a test drive it.
	 */
	public function testTreeThrowsInsteadOfExitingWhenCmuxYieldsNothing(): void
	{
		$this->stubCmux('');
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('cmux tree returned no output.');
		$this->cmux->tree();
	}

	public function testTreeThrowsOnUnparseableOutput(): void
	{
		$this->stubCmux('not json {');
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to parse cmux tree JSON.');
		$this->cmux->tree();
	}

	public function testTreeParsesOutputFromTheCmuxBinHook(): void
	{
		$this->stubCmux('{"windows":[{"ref":"w1"}]}');
		$this->assertSame([['ref' => 'w1']], $this->cmux->tree()['windows']);
	}

	// ── layout trees: the only faithful source of split geometry ───────────────

	/**
	 * A nested tree: left column, right column split top/bottom, the top-right pane
	 * holding two tabs. Both cmux-bak and graveyard join a flat surface list onto
	 * this by walking it depth-first.
	 */
	private function nestedLayoutTree(): array
	{
		return [
			'direction' => 'horizontal',
			'split'     => 0.4,
			'children'  => [
				['pane' => ['surfaces' => [['type' => 'terminal', 'cwd' => '/a', 'command' => 'claude --resume x']]]],
				[
					'direction' => 'vertical',
					'split'     => 0.65,
					'children'  => [
						['pane' => ['surfaces' => [
							['type' => 'terminal', 'cwd' => '/b'],
							['type' => 'browser', 'url' => 'https://example.test'],
						]]],
						['pane' => ['surfaces' => [['type' => 'terminal', 'cwd' => '/c', 'command' => 'npm start']]]],
					],
				],
			],
		];
	}

	public function testLayoutTreePanesWalksLeavesDepthFirst(): void
	{
		$panes = $this->cmux->layoutTreePanes($this->nestedLayoutTree());

		$this->assertCount(3, $panes);
		$this->assertSame([1, 2, 1], array_map('count', $panes));
		$this->assertSame(['/a', '/b', '/c'], [$panes[0][0]['cwd'], $panes[1][0]['cwd'], $panes[2][0]['cwd']]);
		$this->assertSame('browser', $panes[1][1]['type']);
	}

	public function testLayoutTreePanesHandlesABarePaneAndAnEmptyTree(): void
	{
		$this->assertSame(
			[[['type' => 'terminal']]],
			$this->cmux->layoutTreePanes(['pane' => ['surfaces' => [['type' => 'terminal']]]])
		);
		$this->assertSame([], $this->cmux->layoutTreePanes([]));
	}

	public function testLayoutTreeSurfaceCountSumsEveryPanesTabs(): void
	{
		$this->assertSame(4, $this->cmux->layoutTreeSurfaceCount($this->nestedLayoutTree()));
		$this->assertSame(0, $this->cmux->layoutTreeSurfaceCount([]));
	}

	/** Graveyard reads the same implementation, so its count can never drift from cmux's. */
	public function testGraveyardCountsLayoutSurfacesThroughTheSameHelper(): void
	{
		$this->assertSame(
			$this->cmux->layoutTreeSurfaceCount($this->nestedLayoutTree()),
			$this->gy->layoutTreeSurfaceCount($this->nestedLayoutTree())
		);
	}

	/**
	 * `command` is stripped by default: replaying a captured layout would otherwise
	 * re-run whatever each surface was launched with (double-launching an agent),
	 * and both callers drive their own launches afterwards.
	 */
	public function testSanitizeLayoutTreeStripsCommandsButKeepsGeometry(): void
	{
		$clean = $this->cmux->sanitizeLayoutTree($this->nestedLayoutTree());

		$this->assertSame(0.4, $clean['split']);
		$this->assertSame('vertical', $clean['children'][1]['direction']);
		foreach ($this->cmux->layoutTreePanes($clean) as $surfaces) {
			foreach ($surfaces as $surface) {
				$this->assertArrayNotHasKey('command', $surface);
			}
		}
		// cwd and url survive — only the caller can say whether they should.
		$this->assertSame('/a', $this->cmux->layoutTreePanes($clean)[0][0]['cwd']);
		$this->assertSame('https://example.test', $this->cmux->layoutTreePanes($clean)[1][1]['url']);
	}

	public function testSanitizeLayoutTreeCanDropFurtherSurfaceKeys(): void
	{
		$panes = $this->cmux->layoutTreePanes(
			$this->cmux->sanitizeLayoutTree($this->nestedLayoutTree(), ['command', 'cwd'])
		);

		foreach ($panes as $surfaces) {
			foreach ($surfaces as $surface) {
				$this->assertArrayNotHasKey('command', $surface);
				$this->assertArrayNotHasKey('cwd', $surface);
				$this->assertArrayHasKey('type', $surface);
			}
		}
	}

	/**
	 * A layout replay must return the workspace it CREATED. Resolving by title returns
	 * the first match, so a pre-existing workspace of the same name (cmux titles are
	 * not unique — one backup held four identically titled husks) would hand the caller
	 * a stranger's panes and it would type resume commands into live surfaces.
	 */
	public function testNewWorkspaceWithLayoutReturnsTheWorkspaceItCreatedNotASameTitledOne(): void
	{
		$existing = [
			'ref'   => 'workspace:1',
			'id'    => 'old-uuid',
			'title' => 'dotfiles',
			'panes' => [['ref' => 'pane:1', 'surfaces' => [['ref' => 'surface:1']]]],
		];
		$created = [
			'ref'   => 'workspace:2',
			'id'    => 'new-uuid',
			'title' => 'dotfiles',
			'panes' => [
				['ref' => 'pane:2', 'surfaces' => [['ref' => 'surface:2']]],
				['ref' => 'pane:3', 'surfaces' => [['ref' => 'surface:3'], ['ref' => 'surface:4']]],
			],
		];
		$log = $this->stubCmuxSequence([
			['windows' => [['workspaces' => [$existing]]]],
			['windows' => [['workspaces' => [$existing, $created]]]],
		]);

		$node = $this->cmux->newWorkspaceWithLayout('dotfiles', null, $this->nestedLayoutTree());

		$this->assertSame('workspace:2', $node['ref'] ?? null);
		$this->assertSame(
			['pane:2', 'pane:3'],
			array_column($node['panes'], 'ref'),
			'The full node comes back so the caller can join surfaces positionally.'
		);

		$invocations = (string) file_get_contents($log);
		$this->assertStringContainsString('--layout', $invocations);
		$this->assertStringContainsString('"split":0.4', $invocations);
		$this->assertStringNotContainsString('--cwd', $invocations);
	}

	/**
	 * Write a stub cmux whose Nth `tree` call emits the Nth given tree (the last one
	 * repeating), logging every invocation. Lets a test drive a create-then-diff seam
	 * without a real cmux. Returns the log path.
	 */
	private function stubCmuxSequence(array $trees): string
	{
		$dir = sys_get_temp_dir() . '/cmux-seq-' . getmypid() . '-' . uniqid();
		mkdir($dir, 0777, true);
		foreach (array_values($trees) as $i => $tree) {
			file_put_contents($dir . '/tree.' . ($i + 1), json_encode($tree));
		}
		file_put_contents($dir . '/tree.last', json_encode(end($trees)));

		$bin = $dir . '/cmux';
		$body = "#!/bin/sh\n"
			. "echo \"\$*\" >> '{$dir}/log'\n"
			. "if [ \"\$1\" = tree ]; then\n"
			. "	n=\$(cat '{$dir}/count' 2>/dev/null || echo 0)\n"
			. "	n=\$((n + 1))\n"
			. "	echo \"\$n\" > '{$dir}/count'\n"
			. "	cat \"{$dir}/tree.\$n\" 2>/dev/null || cat '{$dir}/tree.last'\n"
			. "fi\n";
		file_put_contents($bin, $body);
		chmod($bin, 0755);
		putenv('CMUX_BIN=' . $bin);
		$this->tmpDirs[] = $dir;

		return $dir . '/log';
	}
}
