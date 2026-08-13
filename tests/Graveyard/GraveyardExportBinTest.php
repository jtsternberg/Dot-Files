<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * The export seam (dotfiles / claude-plugins-anz6): exportTranscript() shells out to
 * export-session.mjs (reads the session JSONL directly — works on dead sessions, ~100ms,
 * appends nothing) and falls back to typing /export into the live REPL when that binary
 * is absent or fails.
 *
 * GRAVEYARD_EXPORT_BIN is the injectable seam (same shape as GODO_DIRMAP_BIN in
 * src/Godo.php), so the shelling path is unit-testable against a stub binary.
 *
 * The load-bearing test here is testGate2PassesAgainstRealExportBin(): swapping the
 * renderer can silently break GATE 2 (bury verifies the exported transcript against the
 * session's recent genuine turns before any teardown), and a broken gate does not fail
 * loudly — it just refuses to bury.
 */
final class GraveyardExportBinTest extends TestCase
{
	/** @var list<string> */
	private array $cleanup = [];
	private ?string $oldHome = null;

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_EXPORT_BIN');
		putenv('GRAVEYARD_ROOT');
		if ($this->oldHome !== null) {
			putenv('HOME=' . $this->oldHome);
			$this->oldHome = null;
		}
		foreach ($this->cleanup as $path) {
			is_dir($path) ? $this->rmrf($path) : @unlink($path);
		}
		$this->cleanup = [];
	}

	private function rmrf(string $dir): void
	{
		foreach (glob($dir . '/*') ?: [] as $p) {
			is_dir($p) ? $this->rmrf($p) : @unlink($p);
		}
		@rmdir($dir);
	}

	private function tmpName(string $slug): string
	{
		return sys_get_temp_dir() . '/gy-' . $slug . '-' . getmypid() . '-' . uniqid();
	}

	/**
	 * Write a stub export-session.mjs: emits $stdout on stdout and exits $code,
	 * recording its argv to "<bin>.argv" so command construction can be asserted.
	 */
	private function stubExportBin(string $stdout, int $code = 0): string
	{
		$bin = $this->tmpName('export-stub');
		$script = "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($bin . '.argv') . "\n"
			. ($stdout !== '' ? 'cat <<\'GYEOF\'' . "\n" . $stdout . "\nGYEOF\n" : '')
			. "exit {$code}\n";
		file_put_contents($bin, $script);
		chmod($bin, 0755);
		putenv('GRAVEYARD_EXPORT_BIN=' . $bin);
		$this->cleanup[] = $bin;
		$this->cleanup[] = $bin . '.argv';

		return $bin;
	}

	/**
	 * Point HOME at a throwaway dir and clear the env override, so exportBinPath()
	 * exercises its real filesystem auto-detection against a layout we control.
	 */
	private function fakeHome(): string
	{
		putenv('GRAVEYARD_EXPORT_BIN');
		$home = $this->tmpName('export-home');
		@mkdir($home, 0755, true);
		$this->cleanup[] = $home;
		$this->oldHome = getenv('HOME') ?: null;
		putenv('HOME=' . $home);

		return $home;
	}

	/** Write an executable stub export-session.mjs at $path, creating parent dirs. */
	private function installStubMjs(string $path): void
	{
		@mkdir(dirname($path), 0755, true);
		file_put_contents($path, "#!/usr/bin/env node\nprocess.stdout.write('stub');\n");
		chmod($path, 0755);
	}

	/** A graveyard rooted in a throwaway store dir, so transcriptPath() is disposable. */
	private function makeGraveyard(): Graveyard
	{
		$root = $this->tmpName('export-root');
		putenv('GRAVEYARD_ROOT=' . $root);
		$this->cleanup[] = $root;

		return new Graveyard($this->cli, $this->cmux);
	}

	// =====================================================================
	// Locating the binary
	// =====================================================================

	public function testExportBinPathUsesEnvOverride(): void
	{
		$bin = $this->stubExportBin('anything');

		$this->assertSame($bin, $this->makeGraveyard()->exportBinPath());
	}

	public function testExportBinPathIsEmptyWhenOverrideDoesNotExist(): void
	{
		// An override that points at nothing means "absent" — never silently fall
		// through to the machine's real install, or the REPL-fallback branch could
		// not be tested (and a typo'd override would run something unexpected).
		putenv('GRAVEYARD_EXPORT_BIN=' . $this->tmpName('nope'));

		$this->assertSame('', $this->makeGraveyard()->exportBinPath());
	}

	/**
	 * The escape hatch. export-session.mjs is the faster, non-mutating path, but it is a
	 * DIFFERENT renderer — markdown source rather than Claude Code's rendered TUI text
	 * (the archive-fidelity gap that first motivated this switch is fixed upstream; see
	 * dotfiles-6bx). GRAVEYARD_EXPORT_BIN=off pins bury back to /export without a code edit.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('offValues')]
	public function testExportBinPathHonorsAnOffSwitch(string $off): void
	{
		putenv('GRAVEYARD_EXPORT_BIN=' . $off);

		$this->assertSame('', $this->makeGraveyard()->exportBinPath());
	}

	public static function offValues(): array
	{
		return [['off'], ['OFF'], ['0'], ['none'], ['false'], ['repl']];
	}

	public function testExportBinPathIsEmptyWhenOverrideIsNotExecutable(): void
	{
		$bin = $this->tmpName('export-noexec');
		file_put_contents($bin, "#!/bin/sh\nexit 0\n");
		chmod($bin, 0644);
		putenv('GRAVEYARD_EXPORT_BIN=' . $bin);
		$this->cleanup[] = $bin;

		$this->assertSame('', $this->makeGraveyard()->exportBinPath());
	}

	// ---------------------------------------------------------------------
	// Auto-detection of the installed binary (no env override).
	//
	// These pin the on-disk LEAF PATH the plugin ships. The real-binary GATE 2
	// tests below only run when exportBinPath() already resolves — so when the
	// script moves inside the plugin and the leaf goes stale, those tests
	// silently SKIP and bury quietly reverts to typing /export into the REPL.
	// A stale leaf must fail loudly here instead.
	// ---------------------------------------------------------------------

	public function testExportBinPathFindsTheClaudePluginsWorkingCheckout(): void
	{
		$home = $this->fakeHome();
		$bin  = $home . '/Code/claude-plugins/plugins/session-tools/scripts/export-session.mjs';
		$this->installStubMjs($bin);

		$this->assertSame($bin, $this->makeGraveyard()->exportBinPath());
	}

	public function testExportBinPathFindsAMarketplaceCacheInstall(): void
	{
		$home = $this->fakeHome();
		$bin  = $home . '/.claude/plugins/cache/jtsternberg/session-tools/0.1.0/scripts/export-session.mjs';
		$this->installStubMjs($bin);

		$this->assertSame($bin, $this->makeGraveyard()->exportBinPath());
	}

	public function testExportBinPathPrefersNewestCacheVersion(): void
	{
		$home = $this->fakeHome();
		$base = $home . '/.claude/plugins/cache/jtsternberg/session-tools';
		$this->installStubMjs($base . '/0.1.0/scripts/export-session.mjs');
		$new = $base . '/0.2.0/scripts/export-session.mjs';
		$this->installStubMjs($new);

		$this->assertSame($new, $this->makeGraveyard()->exportBinPath());
	}

	public function testExportBinPathPrefersWorkingCheckoutOverCache(): void
	{
		$home = $this->fakeHome();
		$checkout = $home . '/Code/claude-plugins/plugins/session-tools/scripts/export-session.mjs';
		$this->installStubMjs($checkout);
		$this->installStubMjs($home . '/.claude/plugins/cache/jtsternberg/session-tools/9.9.9/scripts/export-session.mjs');

		$this->assertSame($checkout, $this->makeGraveyard()->exportBinPath());
	}

	// =====================================================================
	// Command construction — full fidelity, no clipping flags
	// =====================================================================

	public function testExportBinCommandAsksForFullFidelityMarkdown(): void
	{
		$cmd = $this->makeGraveyard()->exportBinCommand('/x/export-session.mjs', 'sess-1', '/Users/JT/.dotfiles');

		$this->assertStringContainsString('--format md', $cmd);
		$this->assertStringContainsString("'sess-1'", $cmd);
		$this->assertStringContainsString("--cwd '/Users/JT/.dotfiles'", $cmd);
		// md renders no bead block, so resolving them is a wasted `bd show` round-trip.
		$this->assertStringContainsString('--no-beads', $cmd);

		// The archive is permanent and GATE 2 matches on turn text: nothing that
		// windows, budgets, or clips turns may appear here.
		foreach (['--truncate', '--max-chars', '--window', '--fast', '--digest'] as $clipper) {
			$this->assertStringNotContainsString($clipper, $cmd, "{$clipper} must not be passed");
		}
	}

	public function testExportBinCommandOmitsCwdWhenUnknown(): void
	{
		$cmd = $this->makeGraveyard()->exportBinCommand('/x/export-session.mjs', 'sess-1', '');

		$this->assertStringNotContainsString('--cwd', $cmd);
	}

	// =====================================================================
	// Shelling out
	// =====================================================================

	public function testExportTranscriptViaBinWritesRenderedTranscript(): void
	{
		$bin = $this->stubExportBin("# session\n\n**You:** hello\n\n**Claude:** hi");
		$gy  = $this->makeGraveyard();
		$sid = 'sess-viabin';

		$this->assertTrue($gy->exportTranscriptViaBin($this->sess($sid), $bin));
		$this->assertSame(
			"# session\n\n**You:** hello\n\n**Claude:** hi\n",
			file_get_contents($gy->transcriptPath($sid))
		);
		// The session id and its cwd reached the binary.
		$argv = (string) file_get_contents($bin . '.argv');
		$this->assertStringContainsString($sid, $argv);
		$this->assertStringContainsString('/Users/JT/.dotfiles', $argv);
		// No temp file left behind.
		$this->assertSame([], glob($gy->sessionDir($sid) . '/.transcript.*'));
	}

	public function testExportTranscriptViaBinFailsOnNonZeroExit(): void
	{
		$bin = $this->stubExportBin("partial output", 1);
		$gy  = $this->makeGraveyard();
		$sid = 'sess-binfail';

		$this->assertFalse($gy->exportTranscriptViaBin($this->sess($sid), $bin));
		$this->assertFileDoesNotExist($gy->transcriptPath($sid));
	}

	public function testExportTranscriptViaBinFailsOnEmptyOutput(): void
	{
		// Exit 0 with nothing rendered must not archive an empty transcript: bury
		// would then tear the session down against a file with no turns in it.
		$bin = $this->stubExportBin('');
		$gy  = $this->makeGraveyard();
		$sid = 'sess-binempty';

		$this->assertFalse($gy->exportTranscriptViaBin($this->sess($sid), $bin));
		$this->assertFileDoesNotExist($gy->transcriptPath($sid));
	}

	// =====================================================================
	// exportTranscript(): prefer the binary, fall back to the REPL
	// =====================================================================

	public function testExportTranscriptPrefersBinAndNeverTouchesTheRepl(): void
	{
		$this->stubExportBin('# rendered by export-session.mjs');
		$gy  = $this->spyGraveyard();
		$sid = 'sess-prefers-bin';

		$this->assertTrue($gy->exportTranscript($this->sess($sid)));
		$this->assertSame(0, $gy->replCalls, '/export must not be typed when the binary works');
		$this->assertStringContainsString('export-session.mjs', (string) file_get_contents($gy->transcriptPath($sid)));
	}

	public function testExportTranscriptFallsBackToReplWhenBinAbsent(): void
	{
		putenv('GRAVEYARD_EXPORT_BIN=' . $this->tmpName('absent'));
		$gy  = $this->spyGraveyard();
		$sid = 'sess-repl-fallback';

		$this->assertTrue($gy->exportTranscript($this->sess($sid), 5));
		$this->assertSame(1, $gy->replCalls);
		$this->assertSame("typed into the repl\n", file_get_contents($gy->transcriptPath($sid)));
	}

	public function testExportTranscriptFallsBackToReplWhenBinFails(): void
	{
		// The binary being broken must not cost graveyard a capability it had before
		// the seam existed — a live session is still buryable via the REPL.
		$this->stubExportBin('', 1);
		$gy  = $this->spyGraveyard();
		$sid = 'sess-repl-after-binfail';

		ob_start();
		$ok = $gy->exportTranscript($this->sess($sid), 5);
		$notice = (string) ob_get_clean();

		$this->assertTrue($ok);
		$this->assertSame(1, $gy->replCalls);
		$this->assertStringContainsString('export-session.mjs', $notice, 'the fallback must be reported, not silent');
	}

	// =====================================================================
	// GATE 2 against the REAL renderer
	// =====================================================================

	/**
	 * The regression this whole task risks: bury's GATE 2 pulls the last genuine turns
	 * out of the JSONL (recentTurnNeedles) and requires at least one of them to appear
	 * in the rendered transcript. export-session.mjs is a different renderer than
	 * Claude Code's /export, so this drives the real binary over a real JSONL and
	 * asserts the gate still opens.
	 */
	public function testGate2PassesAgainstRealExportBin(): void
	{
		$bin = $this->requireRealExportBin();

		[$gy, $sid, $cwd] = $this->fixtureSession($bin);

		$this->assertTrue($gy->exportTranscriptViaBin(
			['session_id' => $sid, 'cwd' => $cwd, 'pid' => getmypid()],
			$bin
		), 'the real binary must render the fixture session');

		$exported = (string) file_get_contents($gy->transcriptPath($sid));
		$needles  = $gy->recentTurnNeedles($sid, $cwd);

		$this->assertNotEmpty($needles, 'fixture must produce genuine turns to match on');
		$this->assertTrue(
			$gy->transcriptBelongsToSession($exported, $needles),
			"GATE 2 rejected real export-session.mjs output.\nNeedles: " . var_export($needles, true)
				. "\nTranscript:\n" . $exported
		);
	}

	/**
	 * Why GATE 2's "ANY of the last 6 turns" rule is load-bearing rather than
	 * incidental: the two parsers disagree on harness noise. graveyard's genuineTurns()
	 * strips <tags> but KEEPS the text inside them, so a turn carrying a
	 * <system-reminder> yields a needle containing the reminder's body; the .mjs deletes
	 * such blocks wholesale. That single needle cannot match — and must not be allowed
	 * to become the only needle the gate relies on.
	 */
	public function testSystemReminderNeedleAloneCannotMatchRealExportOutput(): void
	{
		$bin = $this->requireRealExportBin();

		[$gy, $sid, $cwd] = $this->fixtureSession($bin);
		$gy->exportTranscriptViaBin(['session_id' => $sid, 'cwd' => $cwd, 'pid' => getmypid()], $bin);
		$exported = (string) file_get_contents($gy->transcriptPath($sid));
		$needles  = $gy->recentTurnNeedles($sid, $cwd);

		$reminderNeedles = array_values(array_filter($needles, fn($n) => str_contains((string) $n, 'REMINDER-BODY-MARKER')));
		$this->assertCount(1, $reminderNeedles, 'fixture must contain exactly one system-reminder turn');

		$this->assertFalse(
			$gy->transcriptBelongsToSession($exported, $reminderNeedles),
			'if this starts passing the .mjs stopped stripping harness noise — re-check the whole gate'
		);
		// …and the plain prose turns still carry the gate.
		$this->assertTrue($gy->transcriptBelongsToSession($exported, $needles));
	}

	/** md must not clip turn text: a >2500-char turn survives whole in the archive. */
	public function testRealExportBinKeepsLongTurnsWhole(): void
	{
		$bin = $this->requireRealExportBin();

		[$gy, $sid, $cwd] = $this->fixtureSession($bin);
		$gy->exportTranscriptViaBin(['session_id' => $sid, 'cwd' => $cwd, 'pid' => getmypid()], $bin);
		$exported = (string) file_get_contents($gy->transcriptPath($sid));

		$this->assertStringContainsString('LONG-TURN-HEAD', $exported);
		$this->assertStringContainsString('LONG-TURN-TAIL', $exported, 'the tail of a long turn was truncated away');
	}

	// =====================================================================
	// Fixtures
	// =====================================================================

	private function sess(string $sid): array
	{
		return [
			'session_id'    => $sid,
			'cwd'           => '/Users/JT/.dotfiles',
			'pid'           => getmypid(),
			'surface_ref'   => 'surface:1',
			'workspace_ref' => 'workspace:1',
		];
	}

	/**
	 * A Graveyard whose REPL path is stubbed: sendExportCommand() records the call and
	 * writes the temp file /export would have produced, so exportTranscript()'s polling
	 * loop completes without cmux or a live session.
	 */
	private function spyGraveyard(): Graveyard
	{
		$root = $this->tmpName('export-root');
		putenv('GRAVEYARD_ROOT=' . $root);
		$this->cleanup[] = $root;

		return new class ($this->cli, $this->cmux) extends Graveyard {
			public int $replCalls = 0;
			public function sendExportCommand(array $sess, string $tmp): void {
				$this->replCalls++;
				file_put_contents($tmp, "typed into the repl\n");
			}
		};
	}

	/** What exportBinPath() resolves to with no override — '' when it finds nothing. */
	private function realExportBin(): string
	{
		putenv('GRAVEYARD_EXPORT_BIN');

		return (new Graveyard($this->cli, $this->cmux))->exportBinPath();
	}

	/**
	 * Locate an installed, executable export-session.mjs by scanning the plugin roots
	 * DIRECTLY — deliberately not through exportBinPath()'s leaf constant, so it acts as
	 * an independent cross-check of that constant rather than trusting it. Returns ''
	 * only when the plugin genuinely is not installed on this machine.
	 */
	private function installedExportBinOnDisk(): string
	{
		$home = getenv('HOME') ?: '';
		if ($home === '') { return ''; }

		$roots = array_merge(
			[$home . '/Code/claude-plugins/plugins/session-tools'],
			glob($home . '/.claude/plugins/cache/*/session-tools') ?: []
		);
		foreach ($roots as $root) {
			if (!is_dir($root)) { continue; }
			$out = [];
			exec('find ' . escapeshellarg($root) . ' -type f -name export-session.mjs 2>/dev/null', $out);
			foreach ($out as $path) {
				if (is_file($path) && is_executable($path)) { return $path; }
			}
		}
		return '';
	}

	/**
	 * Gate for the real-renderer tests. When the plugin genuinely is not installed
	 * (fresh Linux box, CI without the checkout), skip — there is nothing to render.
	 * But when export-session.mjs IS on disk and exportBinPath() still cannot find it,
	 * that is the detection regression this file exists to catch (a stale leaf path
	 * silently reverts bury to typing /export) — FAIL loudly, never skip. The detection
	 * check runs before the node check so a stale leaf is caught even without node.
	 */
	private function requireRealExportBin(): string
	{
		$onDisk   = $this->installedExportBinOnDisk();
		$resolved = $this->realExportBin();

		if ($onDisk === '') {
			$this->markTestSkipped('session-tools plugin not installed on this machine');
		}
		$this->assertNotSame(
			'',
			$resolved,
			"export-session.mjs is installed at {$onDisk} but Graveyard::exportBinPath() did not "
				. 'find it — its auto-detect leaf path is stale, so bury silently reverts to /export.'
		);
		if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
			$this->markTestSkipped('node not available');
		}

		return $resolved;
	}

	/**
	 * A throwaway session JSONL under a throwaway HOME, so the real binary (which finds
	 * sessions by scanning ~/.claude/projects) reads our fixture and nothing of JT's.
	 * Turn shapes are the ones that decide GATE 2: plain prose, markdown-led text, a
	 * <system-reminder>-bearing turn, and a turn longer than any truncation cap.
	 *
	 * @return array{0: Graveyard, 1: string, 2: string}
	 */
	private function fixtureSession(string $bin): array
	{
		$home = $this->tmpName('export-home');
		$this->cleanup[] = $home;
		$this->oldHome = getenv('HOME') ?: null;
		putenv('HOME=' . $home);
		putenv('GRAVEYARD_EXPORT_BIN=' . $bin); // survive the HOME swap

		$gy  = $this->makeGraveyard();
		$sid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$cwd = '/Users/JT/.dotfiles';

		$long = 'LONG-TURN-HEAD ' . str_repeat('the seam is exercised end to end. ', 90) . ' LONG-TURN-TAIL';
		$turns = [
			['user', 'Swap the export seam so bury stops typing into a live REPL to archive a transcript.'],
			['assistant', "**Done.** `exportTranscript()` now shells out to _export-session.mjs_ and keeps the REPL path as a fallback."],
			['user', "looks right\n<system-reminder>REMINDER-BODY-MARKER background context the harness injected</system-reminder>"],
			['assistant', $long],
			['user', 'Now prove GATE 2 still opens against the real renderer output.'],
		];

		$jsonl = $this->cmux->jsonlPathFor($sid, $cwd);
		@mkdir(dirname($jsonl), 0755, true);
		$lines = '';
		foreach ($turns as $i => [$role, $text]) {
			$lines .= json_encode([
				'type'      => $role,
				'uuid'      => sprintf('uuid-%04d', $i),
				'sessionId' => $sid,
				'cwd'       => $cwd,
				'timestamp' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-07-25T10:00:00Z') + $i * 60),
				'message'   => [
					'role'    => $role,
					'model'   => $role === 'assistant' ? 'claude-opus-5' : null,
					'content' => [['type' => 'text', 'text' => $text]],
				],
			]) . "\n";
		}
		file_put_contents($jsonl, $lines);

		return [$gy, $sid, $cwd];
	}
}
