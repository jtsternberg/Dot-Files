<?php
namespace JT\Tests\Helpers;

use JT\Tests\TestCase;

/**
 * Codex-session machinery in JT\Helpers\Cmux (dotfiles-zcm).
 *
 * Codex needs its own session↔surface join because it cannot use Claude's:
 * Claude bridges via the unique `cmux-{surface,agent}-resume/claude-<UUID>.zsh`
 * script each surface launches, but a codex TUI is started by hand in a plain
 * shell — its ancestry is `-/bin/zsh` → login → cmux, with no resume script to
 * match. Instead we use the surface UUID cmux exports into every surface's
 * environment (CMUX_SURFACE_ID), which the tree already reports per surface as
 * `id` (Cmux::tree() passes --id-format both).
 *
 * Session ids come from the rollout file the live codex holds open (lsof), not
 * from screen-scraping and not from "newest file in ~/.codex/sessions" — a
 * mtime guess would mis-pair two codex sessions started in the same minute.
 *
 * Every parser here is PURE (fed captured text/arrays); the shell I/O lives in
 * thin wrappers that aren't unit tested.
 */
final class CmuxCodexTest extends TestCase
{
	/**
	 * A proc table with every codex-adjacent process shape seen on a live box:
	 * two real cmux TUIs, three VS Code `app-server` children, the
	 * `codex-code-mode-host` sidecars, and the Chrome extension host whose path
	 * contains a space.
	 */
	private function codexProcTable(): array
	{
		$psRaw = "  PID  PPID COMMAND\n"
			. " 1105 99969 /Users/JT/.local/bin/codex --enable hooks --dangerously-bypass-hook-trust -c hooks.Stop=[{hooks=[{type=\"command\",command='''/Users/JT/.cmux/hooks/cmux-codex-hook-stop.sh''',timeout=10000}]}]\n"
			. " 4789  1105 /Users/JT/.local/bin/codex-code-mode-host\n"
			. "39834 38110 /Users/JT/.local/bin/codex --enable hooks --dangerously-bypass-hook-trust -c hooks.SessionStart=[{hooks=[{type=\"command\",command='''/Users/JT/.cmux/hooks/cmux-codex-hook-session-start.sh''',timeout=10000}]}]\n"
			. "45017 39834 /Users/JT/.local/bin/codex-code-mode-host\n"
			. " 8418  8261 /Users/JT/.vscode/extensions/openai.chatgpt-26.721.41059-darwin-arm64/bin/macos-aarch64/codex -c features.code_mode_host=true app-server --analytics-default-enabled\n"
			. "22376 22318 /Users/JT/.vscode/extensions/openai.chatgpt-26.721.41059-darwin-arm64/bin/macos-aarch64/codex -c features.code_mode_host=true app-server --analytics-default-enabled\n"
			. "96036   715 /Users/JT/.codex/plugins/cache/openai-bundled/chrome/latest/extension-host/macos/arm64/ChatGPT for Chrome chrome-extension://hehggadaopoacecdllhhajmbjkdcmajg/\n"
			. "38110 38109 -/bin/zsh\n";

		return $this->cmux->parseProcTable($psRaw);
	}

	// ── isCodexCommand / codexProcPids ────────────────────────────────────────

	public function testIsCodexCommandOnlyMatchesTheCodexBinary(): void
	{
		$this->assertTrue($this->cmux->isCodexCommand('/Users/JT/.local/bin/codex --enable hooks'));
		$this->assertTrue($this->cmux->isCodexCommand('codex'));

		// The sidecar shares the prefix but is a different binary.
		$this->assertFalse($this->cmux->isCodexCommand('/Users/JT/.local/bin/codex-code-mode-host'));
		// A path *containing* "codex" is not the codex binary.
		$this->assertFalse($this->cmux->isCodexCommand('/Users/JT/.codex/plugins/cache/whatever/ChatGPT for Chrome'));
		$this->assertFalse($this->cmux->isCodexCommand(''));
	}

	public function testCodexProcPidsFindsOnlyTheInteractiveTuis(): void
	{
		$pids = $this->cmux->codexProcPids($this->codexProcTable());

		sort($pids);
		$this->assertSame([1105, 39834], $pids);
	}

	public function testCodexProcPidsRejectsNonTuiSubcommands(): void
	{
		// `app-server` (VS Code), `exec` (headless) and `mcp` never own a surface.
		foreach (['app-server', 'exec', 'mcp', 'login'] as $sub) {
			$proc = $this->cmux->parseProcTable("  PID  PPID COMMAND\n  700     1 /usr/local/bin/codex {$sub} --whatever\n");
			$this->assertSame([], $this->cmux->codexProcPids($proc), "codex {$sub} must not count as a TUI");
		}
	}

	public function testCodexProcPidsDoesNotMistakeAFlagValueForASubcommand(): void
	{
		// `--enable hooks` puts a bare word in argv that is NOT a subcommand; the
		// live TUIs above are launched exactly this way, so a naive
		// "first bare word is the subcommand" parse would drop every real session.
		$proc = $this->cmux->parseProcTable("  PID  PPID COMMAND\n  800     1 /usr/local/bin/codex --enable hooks --dangerously-bypass-hook-trust\n");
		$this->assertSame([800], $this->cmux->codexProcPids($proc));
	}

	// ── parseLsofRollout ──────────────────────────────────────────────────────

	/** Real `lsof -p <pid>` output, trimmed to the interesting rows. */
	private function lsofRaw(): string
	{
		return "COMMAND   PID USER   FD   TYPE DEVICE  SIZE/OFF     NODE NAME\n"
			. "codex   39834   JT  txt    REG   1,13 271134288 238011011 /Users/JT/.codex/packages/standalone/releases/0.145.0-aarch64-apple-darwin/bin/codex\n"
			. "codex   39834   JT   15u   REG   1,13 172908544 163349265 /Users/JT/.codex/logs_2.sqlite\n"
			. "codex   39834   JT   16u   REG   1,13   5174752 237790516 /Users/JT/.codex/logs_2.sqlite-wal\n"
			. "codex   39834   JT   50u   REG   1,13   4244500 238015915 /Users/JT/.codex/sessions/2026/07/27/rollout-2026-07-27T18-02-02-019fa599-6b5f-7de1-9822-52643135bb95.jsonl\n";
	}

	public function testParseLsofRolloutExtractsTheSessionUuid(): void
	{
		$this->assertSame(
			'019fa599-6b5f-7de1-9822-52643135bb95',
			$this->cmux->parseLsofRollout($this->lsofRaw())
		);
	}

	public function testParseLsofRolloutIgnoresTheSqliteAndBinaryRows(): void
	{
		$noRollout = "COMMAND   PID USER   FD   TYPE DEVICE  SIZE/OFF     NODE NAME\n"
			. "codex   39834   JT   15u   REG   1,13 172908544 163349265 /Users/JT/.codex/logs_2.sqlite\n"
			. "codex   39834   JT  txt    REG   1,13 271134288 238011011 /Users/JT/.codex/packages/standalone/releases/0.145.0-aarch64-apple-darwin/bin/codex\n";

		$this->assertNull($this->cmux->parseLsofRollout($noRollout));
		$this->assertNull($this->cmux->parseLsofRollout(''));
	}

	public function testParseLsofRolloutRequiresTheTimestampedRolloutShape(): void
	{
		// A jsonl under sessions/ that isn't a rollout-<ts>-<uuid>.jsonl must not
		// be mistaken for one — the uuid is positional, so a loose match would
		// happily return the timestamp fragment as a session id.
		$raw = "codex 1 JT 9u REG 1,13 10 1 /Users/JT/.codex/sessions/2026/07/27/notes.jsonl\n";
		$this->assertNull($this->cmux->parseLsofRollout($raw));
	}

	public function testParseLsofRolloutPathReturnsTheWholePath(): void
	{
		$this->assertSame(
			'/Users/JT/.codex/sessions/2026/07/27/rollout-2026-07-27T18-02-02-019fa599-6b5f-7de1-9822-52643135bb95.jsonl',
			$this->cmux->parseLsofRolloutPath($this->lsofRaw())
		);
	}

	public function testParseLsofCwdReadsTheProcessWorkingDirectoryRow(): void
	{
		// One `lsof -p <pid>` yields both the rollout and the cwd, so the join
		// needs a single shell call per codex pid.
		$raw = "COMMAND   PID USER   FD   TYPE DEVICE SIZE/OFF      NODE NAME\n"
			. "codex   39834   JT  cwd    DIR   1,13      672  69836678 /Users/JT/Code/claude-plugins\n"
			. "codex   39834   JT   50u   REG   1,13  4244500 238015915 /Users/JT/.codex/sessions/2026/07/27/rollout-2026-07-27T18-02-02-019fa599-6b5f-7de1-9822-52643135bb95.jsonl\n";

		$this->assertSame('/Users/JT/Code/claude-plugins', $this->cmux->parseLsofCwd($raw));
		$this->assertNull($this->cmux->parseLsofCwd(''));
	}

	public function testParseLsofCwdKeepsSpacesInThePath(): void
	{
		$raw = "codex 39834 JT cwd DIR 1,13 672 69836678 /Users/JT/Documents/Southport UDO\n";
		$this->assertSame('/Users/JT/Documents/Southport UDO', $this->cmux->parseLsofCwd($raw));
	}

	// ── codexSessionCwd ───────────────────────────────────────────────────────

	public function testCodexSessionCwdReadsTheRolloutSessionMeta(): void
	{
		// The rollout's own session_meta cwd is what restore should cd into — it is
		// where the conversation actually ran, even if the live process has since
		// been cd'd elsewhere.
		$path = sys_get_temp_dir() . '/cmuxbak-rollout-' . getmypid() . '.jsonl';
		file_put_contents($path, implode("\n", [
			json_encode(['timestamp' => '2026-07-27T22:04:40.525Z', 'type' => 'session_meta', 'payload' => [
				'session_id' => '019fa599-6b5f-7de1-9822-52643135bb95',
				'cwd'        => '/Users/JT/Code/claude-plugins',
				'originator' => 'codex-tui',
			]]),
			json_encode(['type' => 'turn_context', 'payload' => ['cwd' => '/somewhere/else', 'model' => 'gpt-5.6-terra']]),
		]) . "\n");

		try {
			$this->assertSame('/Users/JT/Code/claude-plugins', $this->cmux->codexSessionCwd($path));
		} finally {
			unlink($path);
		}
	}

	public function testCodexSessionCwdNullForMissingOrHeaderlessRollout(): void
	{
		$this->assertNull($this->cmux->codexSessionCwd('/no/such/rollout.jsonl'));

		$path = sys_get_temp_dir() . '/cmuxbak-empty-' . getmypid() . '.jsonl';
		file_put_contents($path, "not json\n");
		try {
			$this->assertNull($this->cmux->codexSessionCwd($path));
		} finally {
			unlink($path);
		}
	}

	// ── parseSurfaceIdFromEnv ─────────────────────────────────────────────────

	/**
	 * `ps -wwEp <pid>` appends the environment to the command column on ONE line,
	 * so the parser sees cmd and env run together.
	 */
	private function psEnvRaw(): string
	{
		return "  PID TTY           TIME CMD\n"
			. "39834 ttys035    2:11.81 /Users/JT/.local/bin/codex --enable hooks"
			. " CMUX_BUNDLE_ID=com.cmuxterm.app"
			. " CMUX_SURFACE_ID=141F01E9-5A32-4ACD-A519-EDFB7B21FE7F"
			. " CMUX_WORKSPACE_ID=635FF6DC-7B9A-4B87-BD2E-DC0C9C4BD7D7"
			. " CMUX_SOCKET_CAPABILITY=v1.KaZu4WogSyhsRHjRQFc5fMprZNwI-mNBJ2wJ4Zp4Biw.kRKnNUnAs2HHPWocidJ"
			. " CMUX_CODEX_PID=39834\n";
	}

	public function testParseSurfaceIdFromEnvExtractsTheSurfaceUuid(): void
	{
		$this->assertSame(
			'141F01E9-5A32-4ACD-A519-EDFB7B21FE7F',
			$this->cmux->parseSurfaceIdFromEnv($this->psEnvRaw())
		);
	}

	public function testParseSurfaceIdFromEnvIsNotFooledByTheNeighbouringIdVars(): void
	{
		// CMUX_WORKSPACE_ID / CMUX_PANEL_ID / CMUX_TAB_ID sit right beside it and
		// hold different UUIDs; only the surface id may be returned.
		$this->assertNotSame(
			'635FF6DC-7B9A-4B87-BD2E-DC0C9C4BD7D7',
			$this->cmux->parseSurfaceIdFromEnv($this->psEnvRaw())
		);
	}

	public function testParseSurfaceIdFromEnvReturnsNullOutsideACmuxSurface(): void
	{
		$raw = "  PID TTY           TIME CMD\n700 ttys009 0:01.00 /usr/local/bin/codex PATH=/usr/bin HOME=/Users/JT\n";
		$this->assertNull($this->cmux->parseSurfaceIdFromEnv($raw));
		$this->assertNull($this->cmux->parseSurfaceIdFromEnv(''));
	}

	// ── mapSurfaceUuids ───────────────────────────────────────────────────────

	private function uuidTree(): array
	{
		return ['windows' => [[
			'ref'        => 'window:2',
			'workspaces' => [[
				'title' => 'claudeplugins',
				'ref'   => 'workspace:17',
				'id'    => 'WS-UUID-17',
				'panes' => [[
					'ref'      => 'pane:33',
					'surfaces' => [
						[
							'ref'   => 'surface:86',
							'id'    => '141F01E9-5A32-4ACD-A519-EDFB7B21FE7F',
							'title' => 'codex: p81u worker',
							'type'  => 'terminal',
							'tty'   => 'ttys035',
						],
						[
							'ref'   => 'surface:85',
							'id'    => 'EC17A533-9375-4DD9-86AE-3DD748642B65',
							'title' => '✳ claudeplugins codex friendly',
							'type'  => 'terminal',
							'tty'   => 'ttys033',
						],
					],
				]],
			]],
		]]];
	}

	public function testMapSurfaceUuidsKeysSurfacesByTheirStableUuid(): void
	{
		$map = $this->cmux->mapSurfaceUuids($this->uuidTree());

		$this->assertArrayHasKey('141F01E9-5A32-4ACD-A519-EDFB7B21FE7F', $map);
		$entry = $map['141F01E9-5A32-4ACD-A519-EDFB7B21FE7F'];
		$this->assertSame('surface:86', $entry['surface_ref']);
		$this->assertSame('workspace:17', $entry['workspace_ref']);
		$this->assertSame('ttys035', $entry['tty']);
		$this->assertSame('codex: p81u worker', $entry['title']);
		$this->assertCount(2, $map);
	}

	public function testMapSurfaceUuidsSkipsSurfacesWithNoUuid(): void
	{
		$tree = $this->uuidTree();
		unset($tree['windows'][0]['workspaces'][0]['panes'][0]['surfaces'][1]['id']);

		$this->assertCount(1, $this->cmux->mapSurfaceUuids($tree));
	}

	// ── joinCodexToSurfaces ───────────────────────────────────────────────────

	private function codexSessions(): array
	{
		return [
			39834 => [
				'session_id' => '019fa599-6b5f-7de1-9822-52643135bb95',
				'cwd'        => '/Users/JT/Code/claude-plugins',
				'surface_id' => '141F01E9-5A32-4ACD-A519-EDFB7B21FE7F',
			],
			1105  => [
				'session_id' => '019fa565-3e6a-7152-ae33-80ce8bf4879c',
				'cwd'        => '/Users/JT/Sites/lindris-monorepo/lindris-cli',
				'surface_id' => 'EC17A533-9375-4DD9-86AE-3DD748642B65',
			],
		];
	}

	public function testJoinCodexToSurfacesBindsEachSessionToItsOwnSurface(): void
	{
		$rows = $this->cmux->joinCodexToSurfaces($this->codexSessions(), $this->cmux->mapSurfaceUuids($this->uuidTree()));

		$bySid = [];
		foreach ($rows as $r) {
			$bySid[$r['session_id']] = $r;
		}

		$a = $bySid['019fa599-6b5f-7de1-9822-52643135bb95'];
		$this->assertSame('surface:86', $a['surface_ref']);
		$this->assertSame('workspace:17', $a['workspace_ref']);
		$this->assertSame('ttys035', $a['tty']);
		$this->assertSame(39834, $a['pid']);
		$this->assertSame('/Users/JT/Code/claude-plugins', $a['cwd']);
		$this->assertSame('codex', $a['agent']);
		$this->assertTrue($a['targetable']);
		$this->assertSame('', $a['reason']);

		$this->assertSame('surface:85', $bySid['019fa565-3e6a-7152-ae33-80ce8bf4879c']['surface_ref']);
	}

	public function testJoinCodexRowsMatchTheClaudeRowShape(): void
	{
		// CmuxBak merges claude and codex rows into one list and reads them with
		// one code path, so the two joins must agree on their keys.
		$rows = $this->cmux->joinCodexToSurfaces($this->codexSessions(), $this->cmux->mapSurfaceUuids($this->uuidTree()));

		foreach (['session_id', 'pid', 'cwd', 'model', 'skip_perms', 'surface_ref', 'workspace_ref', 'tty', 'title', 'targetable', 'reason', 'agent', 'opts'] as $key) {
			$this->assertArrayHasKey($key, $rows[0], "codex join row is missing '{$key}'");
		}
	}

	public function testJoinCodexToSurfacesCarriesTheRecordedContextThrough(): void
	{
		$sessions = $this->codexSessions();
		$sessions[39834]['model'] = 'gpt-5.6-pro';
		$sessions[39834]['opts']  = ['sandbox' => 'read-only', 'approval' => 'on-request', 'effort' => 'low'];

		$rows = $this->cmux->joinCodexToSurfaces($sessions, $this->cmux->mapSurfaceUuids($this->uuidTree()));
		$row  = null;
		foreach ($rows as $r) {
			if ($r['pid'] === 39834) { $row = $r; }
		}

		$this->assertSame('gpt-5.6-pro', $row['model']);
		$this->assertSame('read-only', $row['opts']['sandbox']);
	}

	public function testJoinCodexToSurfacesUntargetableWithoutASurfaceId(): void
	{
		$sessions = $this->codexSessions();
		$sessions[39834]['surface_id'] = null;

		$rows  = $this->cmux->joinCodexToSurfaces($sessions, $this->cmux->mapSurfaceUuids($this->uuidTree()));
		$row   = null;
		foreach ($rows as $r) {
			if ($r['pid'] === 39834) { $row = $r; }
		}

		$this->assertFalse($row['targetable']);
		$this->assertSame('', $row['surface_ref']);
		$this->assertStringContainsString('cmux surface', $row['reason']);
	}

	public function testJoinCodexToSurfacesUntargetableWhenSurfaceIsGoneFromTheTree(): void
	{
		$sessions = $this->codexSessions();
		$sessions[39834]['surface_id'] = 'DEADBEEF-0000-0000-0000-000000000000';

		$rows = $this->cmux->joinCodexToSurfaces($sessions, $this->cmux->mapSurfaceUuids($this->uuidTree()));
		$row  = null;
		foreach ($rows as $r) {
			if ($r['pid'] === 39834) { $row = $r; }
		}

		$this->assertFalse($row['targetable']);
		$this->assertStringContainsString('not found', $row['reason']);
	}

	public function testJoinCodexToSurfacesCollisionMakesBothUntargetable(): void
	{
		// Two codex processes claiming one surface is not a thing we can act on:
		// resuming into it would clobber whichever is really there. Never guess.
		$sessions = $this->codexSessions();
		$sessions[1105]['surface_id'] = $sessions[39834]['surface_id'];

		$rows = $this->cmux->joinCodexToSurfaces($sessions, $this->cmux->mapSurfaceUuids($this->uuidTree()));

		foreach ($rows as $r) {
			$this->assertFalse($r['targetable']);
			$this->assertStringContainsString('collision', $r['reason']);
		}
	}

	// ── agent dispatchers ─────────────────────────────────────────────────────

	/**
	 * MEASURED, not assumed: a session created with `-s read-only` and resumed with
	 * no flags came back `danger-full-access` — resume re-reads ~/.codex/config.toml
	 * rather than rehydrating the rollout's turn_context. Restoring bare would
	 * silently WIDEN a read-only session's sandbox, so the recorded context is
	 * replayed explicitly.
	 */
	public function testBuildAgentResumeCommandForCodexReplaysTheRecordedContext(): void
	{
		$this->assertSame(
			'codex resume --model=gpt-5.6-terra --sandbox=read-only --ask-for-approval=on-request -c model_reasoning_effort="high" 019fa599-6b5f-7de1-9822-52643135bb95',
			$this->cmux->buildAgentResumeCommand('codex', '019fa599-6b5f-7de1-9822-52643135bb95', false, 'gpt-5.6-terra', [
				'sandbox'  => 'read-only',
				'approval' => 'on-request',
				'effort'   => 'high',
			])
		);
	}

	public function testBuildAgentResumeCommandForCodexOmitsWhatItDoesNotKnow(): void
	{
		// An unreadable/partial rollout must not produce `--sandbox=` with no value;
		// omitting a flag falls back to config, which is the pre-existing behaviour.
		$this->assertSame(
			'codex resume 019fa599-6b5f-7de1-9822-52643135bb95',
			$this->cmux->buildAgentResumeCommand('codex', '019fa599-6b5f-7de1-9822-52643135bb95', false, null, [])
		);
		$this->assertSame(
			'codex resume --sandbox=read-only 019fa599-6b5f-7de1-9822-52643135bb95',
			$this->cmux->buildAgentResumeCommand('codex', '019fa599-6b5f-7de1-9822-52643135bb95', false, null, ['sandbox' => 'read-only'])
		);
	}

	public function testBuildAgentResumeCommandForCodexIgnoresClaudeSkipPerms(): void
	{
		// skip_perms is a Claude concept; codex expresses the same idea through
		// sandbox/approval, so the flag must never leak into a codex command line.
		$this->assertStringNotContainsString(
			'--dangerously-skip-permissions',
			$this->cmux->buildAgentResumeCommand('codex', 'abc-123', true, null, ['sandbox' => 'read-only'])
		);
	}

	public function testBuildAgentResumeCommandRejectsUnsafeCodexContextValues(): void
	{
		// These values come out of a rollout on disk and land on a shell command
		// line, so anything that isn't a plain policy token is dropped rather than
		// interpolated.
		$cmd = $this->cmux->buildAgentResumeCommand('codex', 'abc-123', false, 'gpt; rm -rf /', [
			'sandbox'  => 'read-only; echo pwned',
			'approval' => 'never',
			'effort'   => 'high"; echo pwned',
		]);

		$this->assertSame('codex resume --ask-for-approval=never abc-123', $cmd);
	}

	// ── codexRolloutContext ───────────────────────────────────────────────────

	private function writeRollout(string $path, array $turnContexts): void
	{
		$lines = [json_encode(['type' => 'session_meta', 'payload' => ['session_id' => 'sid', 'cwd' => '/x']])];
		foreach ($turnContexts as $tc) {
			$lines[] = json_encode(['type' => 'turn_context', 'payload' => $tc]);
		}
		file_put_contents($path, implode("\n", $lines) . "\n");
	}

	public function testCodexRolloutContextTakesTheLastTurnContext(): void
	{
		// Mirrors how Claude's model/permission-mode are resolved: the END of the
		// conversation is the source of truth, so a mid-session mode change wins.
		$path = sys_get_temp_dir() . '/cmuxbak-ctx-' . getmypid() . '.jsonl';
		$this->writeRollout($path, [
			['model' => 'gpt-5.6-terra', 'approval_policy' => 'never', 'sandbox_policy' => ['type' => 'danger-full-access'], 'settings' => ['reasoning_effort' => 'high']],
			['model' => 'gpt-5.6-pro', 'approval_policy' => 'on-request', 'sandbox_policy' => ['type' => 'read-only'], 'settings' => ['reasoning_effort' => 'low']],
		]);

		try {
			$ctx = $this->cmux->codexRolloutContext($path);
			$this->assertSame('gpt-5.6-pro', $ctx['model']);
			$this->assertSame('read-only', $ctx['sandbox']);
			$this->assertSame('on-request', $ctx['approval']);
			$this->assertSame('low', $ctx['effort']);
		} finally {
			unlink($path);
		}
	}

	public function testCodexRolloutContextReadsTopLevelReasoningEffort(): void
	{
		$path = sys_get_temp_dir() . '/cmuxbak-ctx2-' . getmypid() . '.jsonl';
		$this->writeRollout($path, [
			['model' => 'gpt-5.6-terra', 'reasoning_effort' => 'medium', 'sandbox_policy' => ['type' => 'workspace-write']],
		]);

		try {
			$ctx = $this->cmux->codexRolloutContext($path);
			$this->assertSame('medium', $ctx['effort']);
			$this->assertSame('workspace-write', $ctx['sandbox']);
		} finally {
			unlink($path);
		}
	}

	public function testCodexRolloutContextAllNullWhenUnreadable(): void
	{
		$ctx = $this->cmux->codexRolloutContext('/no/such/rollout.jsonl');

		$this->assertSame(['model' => null, 'sandbox' => null, 'approval' => null, 'effort' => null], $ctx);
	}

	public function testBuildAgentResumeCommandDelegatesClaudeToTheExistingBuilder(): void
	{
		$this->assertSame(
			$this->cmux->buildResumeCommand('abc', true, 'opus'),
			$this->cmux->buildAgentResumeCommand('claude', 'abc', true, 'opus')
		);
		// An unknown/absent agent is treated as claude — v1 backups carry no agent.
		$this->assertSame(
			$this->cmux->buildResumeCommand('abc', false, null),
			$this->cmux->buildAgentResumeCommand('', 'abc', false, null)
		);
	}

	public function testTranscriptPathForClaudeMatchesJsonlPathFor(): void
	{
		$this->assertSame(
			$this->cmux->jsonlPathFor('sid', '/Users/JT/Code/x'),
			$this->cmux->transcriptPathFor('claude', 'sid', '/Users/JT/Code/x')
		);
	}

	public function testCodexRolloutPathForFindsARolloutByUuidRegardlessOfDate(): void
	{
		$root = sys_get_temp_dir() . '/cmuxbak-codex-' . getmypid();
		$dir  = "{$root}/2026/07/27";
		mkdir($dir, 0777, true);
		$uuid = '019fa599-6b5f-7de1-9822-52643135bb95';
		$path = "{$dir}/rollout-2026-07-27T18-02-02-{$uuid}.jsonl";
		file_put_contents($path, "{}\n");

		putenv("CODEX_SESSIONS_DIR={$root}");
		try {
			// The date directories are unknown to a caller holding only the uuid,
			// so the lookup must glob for it rather than reconstruct a path.
			$this->assertSame($path, $this->cmux->codexRolloutPathFor($uuid));
			$this->assertNull($this->cmux->codexRolloutPathFor('00000000-0000-0000-0000-000000000000'));
			// transcriptPathFor() routes codex through the same lookup.
			$this->assertSame($path, $this->cmux->transcriptPathFor('codex', $uuid, '/any/cwd'));
		} finally {
			putenv('CODEX_SESSIONS_DIR');
			unlink($path);
			rmdir($dir);
			rmdir(dirname($dir));
			rmdir(dirname(dirname($dir)));
		}
	}
}
