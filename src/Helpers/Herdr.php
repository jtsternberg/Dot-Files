<?php
namespace JT\Helpers;

use RuntimeException;

# =============================================================================
# Herdr — thin herdr CLI client for bin/graveyard's herdr transport.
#
# One shell-out per method, JSON in, arrays out, no interpretation: joining a
# snapshot into graveyard's session rows is Transport\HerdrTransport's job.
# =============================================================================

/**
 * Three things about herdr's CLI that the shapes below encode, because guessing
 * any of them costs a round trip against a running server:
 *
 * 1. Every JSON verb wraps its payload as `{"id":…,"result":{"<key>":…}}`, so a
 *    reader unwraps `.result.<key>` — `snapshot`, `process_info`, `pane`,
 *    `workspace`. An acknowledgement-only verb answers `{"result":{"type":"ok"}}`,
 *    and `pane send-text`/`send-keys`/`run` answer nothing at all.
 * 2. `pane read` is the exception that emits PLAIN TEXT, not JSON.
 * 3. Pane id passing is not uniform: `pane process-info` and `pane split` take
 *    `--pane <id>`, everything else a positional. Swap them and herdr answers
 *    `unknown option: wA:p1`. Verified against herdr 0.8.2 / protocol 20.
 *
 * Failures land on stderr as `{"error":{"code":…,"message":…}}` with exit 1, so
 * unlike Cmux (which is stuck with shell_exec) we keep stderr and quote herdr's
 * own message into the exception.
 */
class Herdr {

	protected $cli;

	public function __construct($cli) {
		$this->cli = $cli;
	}

	/**
	 * The herdr binary this class shells out to. HERDR_BIN overrides it — set in
	 * tests to a stub script so no test reaches a real herdr server (mirrors
	 * CMUX_BIN and Godo's GODO_DIRMAP_BIN; see CLAUDE.md's shelling-seam rule).
	 */
	public function herdrBin(): string {
		return getenv('HERDR_BIN') ?: 'herdr';
	}

	/**
	 * Is a herdr server running and speaking a protocol this client understands?
	 *
	 * `herdr status` prints text, not JSON, and exits 0 even for a stopped server,
	 * so both markers have to be present. Must never throw — SessionTransport's
	 * available() is called to decide whether the transport is usable at all.
	 */
	public function available(): bool {
		try {
			$res = $this->run(['status']);
		} catch (\Throwable $e) {
			return false;
		}
		if ($res['exitCode'] !== 0) {
			return false;
		}
		$out = $res['output'];
		return (bool) preg_match('/^\s*status:\s*running\b/m', $out)
			&& (bool) preg_match('/^\s*compatible:\s*yes\b/m', $out);
	}

	/** Whole-session state: agents, panes, tabs, workspaces, layouts. */
	public function snapshot(): array {
		return $this->callJson(['api', 'snapshot'], 'snapshot');
	}

	/**
	 * shell_pid, foreground_process_group_id and foreground_processes[] for a pane.
	 * Each process carries `argv` as a real array (absent on a process herdr could
	 * not read argv for), which is how HerdrTransport reads --model and
	 * --dangerously-skip-permissions without scraping ps text.
	 */
	public function paneProcessInfo(string $paneId): array {
		return $this->callJson(['pane', 'process-info', '--pane', $paneId], 'process_info');
	}

	/**
	 * Pane output as plain text. `recent` is scrolled-off history only, so a caller
	 * that wants what is on screen right now asks for `visible`.
	 */
	public function paneRead(string $paneId, string $source = 'recent', ?int $lines = null): string {
		$argv = ['pane', 'read', $paneId, '--source', $source];
		if ($lines !== null) {
			$argv[] = '--lines';
			$argv[] = (string) $lines;
		}
		return $this->callText($argv);
	}

	public function paneSendText(string $paneId, string $text): void {
		$this->callOk(['pane', 'send-text', $paneId, $text]);
	}

	public function paneSendKeys(string $paneId, string $key): void {
		$this->callOk(['pane', 'send-keys', $paneId, $key]);
	}

	public function paneRun(string $paneId, string $command): void {
		$this->callOk(['pane', 'run', $paneId, $command]);
	}

	/** @param string $direction `right` or `down` — herdr accepts no other value. */
	public function paneSplit(string $paneId, string $direction): ?string {
		try {
			$pane = $this->callJson(['pane', 'split', '--pane', $paneId, '--direction', $direction], 'pane');
		} catch (RuntimeException $e) {
			return null;
		}
		$id = $pane['pane_id'] ?? '';
		return $id === '' ? null : (string) $id;
	}

	public function paneClose(string $paneId): void {
		$this->callOk(['pane', 'close', $paneId]);
	}

	/**
	 * @return ?array `{workspace, tab, root_pane}` — the whole envelope, because a
	 *                caller needs `root_pane.pane_id` as much as the workspace id.
	 *                --no-focus so a restore does not yank the user's focus.
	 */
	public function workspaceCreate(string $label, ?string $cwd, array $env = []): ?array {
		$argv = ['workspace', 'create', '--label', $label];
		if ($cwd !== null && $cwd !== '') {
			$argv[] = '--cwd';
			$argv[] = $cwd;
		}
		foreach ($env as $key => $value) {
			$argv[] = '--env';
			$argv[] = $key . '=' . $value;
		}
		$argv[] = '--no-focus';

		try {
			$res = $this->callResult($argv);
		} catch (RuntimeException $e) {
			return null;
		}
		unset($res['type']);
		return $res;
	}

	public function workspaceClose(string $workspaceId): array {
		return $this->callResult(['workspace', 'close', $workspaceId]);
	}

	public function workspaceFocus(string $workspaceId): bool {
		try {
			$this->callResult(['workspace', 'focus', $workspaceId]);
		} catch (RuntimeException $e) {
			return false;
		}
		return true;
	}

	# =========================================================================
	# Shell-out plumbing.
	#
	# Failure is a RuntimeException, NOT exitErr()/exit(): exit() inside a
	# shelling seam kills PHPUnit mid-run wherever the binary is absent
	# (dotfiles-3qa). The bin/ entry seam catches it and calls exitErr(), keeping
	# process-exit plumbing at the entry where CLAUDE.md says it belongs.
	# =========================================================================

	/** @return array{output:string,error:string,exitCode:int} */
	protected function run(array $argv): array {
		$cmd = escapeshellcmd($this->herdrBin());
		foreach ($argv as $arg) {
			$cmd .= ' ' . escapeshellarg((string) $arg);
		}
		$res = $this->cli->getCommandOutputAndExitCode($cmd);

		return [
			'output'   => (string) ($res['output'] ?? ''),
			'error'    => (string) ($res['error'] ?? ''),
			'exitCode' => (int) ($res['exitCode'] ?? 1),
		];
	}

	/** Run, or throw carrying herdr's own error message. */
	protected function runOrThrow(array $argv): array {
		$res = $this->run($argv);
		if ($res['exitCode'] !== 0) {
			throw new RuntimeException($this->failure($argv, $res));
		}
		return $res;
	}

	/** Plain-text verbs (`pane read`). */
	protected function callText(array $argv): string {
		return $this->runOrThrow($argv)['output'];
	}

	/** JSON verbs, unwrapped to `.result`. */
	protected function callResult(array $argv): array {
		$res  = $this->runOrThrow($argv);
		$data = json_decode(trim($res['output']), true);
		if (!is_array($data) || !isset($data['result']) || !is_array($data['result'])) {
			throw new RuntimeException($this->failure($argv, $res, 'unparseable response'));
		}
		return $data['result'];
	}

	/** JSON verbs, unwrapped to `.result.<key>`. */
	protected function callJson(array $argv, string $resultKey): array {
		$result = $this->callResult($argv);
		if (!isset($result[$resultKey]) || !is_array($result[$resultKey])) {
			throw new RuntimeException($this->failure($argv, ['output' => '', 'error' => ''], "no '{$resultKey}' in response"));
		}
		return $result[$resultKey];
	}

	/**
	 * Acknowledgement-only verbs. Some answer `{"result":{"type":"ok"}}` and some
	 * (send-text, send-keys, run) answer nothing, so only the exit code is checked.
	 */
	protected function callOk(array $argv): void {
		$this->runOrThrow($argv);
	}

	/** herdr's own `{"error":{"message":…}}` where it gave one, else its raw output. */
	protected function failure(array $argv, array $res, string $why = ''): string {
		$verb    = implode(' ', array_slice($argv, 0, 2));
		$stderr  = trim((string) ($res['error'] ?? ''));
		$decoded = json_decode($stderr, true);
		$detail  = is_array($decoded) && isset($decoded['error']['message'])
			? (string) $decoded['error']['message']
			: ($stderr !== '' ? $stderr : ($why !== '' ? $why : 'no output'));

		return "herdr {$verb} failed: {$detail}";
	}
}
