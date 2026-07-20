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

	protected function tearDown(): void
	{
		foreach ($this->tmpPaths as $p) {
			if (is_file($p)) {
				@unlink($p);
			}
		}
		$this->tmpPaths = [];
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
}
