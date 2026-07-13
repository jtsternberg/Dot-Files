<?php
namespace JT\Helpers;

# =============================================================================
# Cmux — shared cmux + Claude-session machinery for bin/cmux-bak and bin/graveyard.
# Extracted from bin/cmux-bak (v0.1.0). Behavior-preserving.
# =============================================================================

class Cmux {

	const SESSIONS_DIR = '~/.claude/sessions';

	protected $cli;
	protected $dryRun;

	public function __construct($cli, bool $dryRun = false) {
		$this->cli    = $cli;
		$this->dryRun = $dryRun;
	}

	public function ping(): bool {
		$result = shell_exec('cmux ping 2>/dev/null');
		return trim((string) $result) === 'PONG';
	}

	public function tree(): array {
		// --id-format both so every node carries its stable UUID (`id`) alongside
		// the positional `ref`. cmux-bak matches by tty/title (unaffected); graveyard
		// needs the UUID to compare against CMUX_SURFACE_ID for the self-bury guard.
		$output = shell_exec('cmux tree --all --json --id-format both 2>/dev/null');
		if (!$output) {
			$this->cli->exitErr('cmux tree returned no output.');
		}
		$tree = json_decode($output, true);
		if (!$tree) {
			$this->cli->exitErr('Failed to parse cmux tree JSON.');
		}
		return $tree;
	}

	public function encodeProjectKey(string $cwd): string {
		return preg_replace('/[^a-zA-Z0-9]/', '-', $cwd);
	}

	public function jsonlPathFor(string $sessionId, string $cwd): string {
		$claudeDir = $this->cli->convertPathToAbsolute('~/.claude');
		return "{$claudeDir}/projects/{$this->encodeProjectKey($cwd)}/{$sessionId}.jsonl";
	}

	/**
	 * Load all active Claude sessions from ~/.claude/sessions/<pid>.json.
	 * Returns array keyed by tty: [ tty => [session_id, cwd, status, pid, skip_perms, model] ]
	 */
	public function loadClaudeSessions(): array {
		$sessionsDir = $this->cli->convertPathToAbsolute(self::SESSIONS_DIR);
		$sessions    = [];

		if (!is_dir($sessionsDir)) {
			return $sessions;
		}

		foreach (glob($sessionsDir . '/*.json') ?: [] as $file) {
			$pid = pathinfo($file, PATHINFO_FILENAME);
			if (!ctype_digit($pid)) {
				continue;
			}
			if (!$this->pidIsAlive((int) $pid)) {
				continue;
			}
			$raw = @file_get_contents($file);
			if ($raw === false) {
				continue;
			}
			$data = json_decode($raw, true);
			if (!$data) {
				continue;
			}
			$tty = $this->getTtyForPid((int) $pid);
			if ($tty) {
				$sessionId = $data['sessionId'] ?? null;
				$cwd       = $data['cwd'] ?? null;
				$jsonl     = $this->readSessionJsonl($sessionId, $cwd);

				$sessions[$tty] = [
					'session_id'   => $sessionId,
					'cwd'          => $cwd,
					'status'       => $data['status'] ?? null,
					'pid'          => (int) $pid,
					'skip_perms'   => $jsonl['permission_mode'] === 'bypassPermissions',
					'model'        => $jsonl['model'],
				];
			}
		}

		return $sessions;
	}

	public function pidIsAlive(int $pid) {
		if (function_exists('posix_kill')) {
			return posix_kill($pid, 0);
		}
		exec("kill -0 {$pid} 2>/dev/null", $out, $code);
		return $code === 0;
	}

	public function getTtyForPid(int $pid) {
		$tty = trim((string) shell_exec("ps -p {$pid} -o tty= 2>/dev/null"));
		return ($tty && $tty !== '??') ? $tty : null;
	}

	public function getCwdForTty(string $tty) {
		$psOut = shell_exec("ps -t {$tty} -o pid=,stat= 2>/dev/null");
		$pid   = null;

		// Prefer foreground process (stat contains +)
		foreach (explode("\n", trim((string) $psOut)) as $line) {
			$parts = preg_split('/\s+/', trim($line));
			if (count($parts) >= 2 && strpos($parts[1], '+') !== false) {
				$pid = $parts[0];
				break;
			}
		}

		if (!$pid) {
			$lines = array_filter(explode("\n", trim((string) $psOut)));
			if ($lines) {
				$pid = preg_split('/\s+/', trim(reset($lines)))[0];
			}
		}

		if (!$pid) {
			return null;
		}

		$lsofOut = shell_exec("lsof -p {$pid} 2>/dev/null");
		foreach (explode("\n", (string) $lsofOut) as $line) {
			// Fields: COMMAND PID USER FD TYPE DEVICE SIZE/OFF NODE NAME
			$parts = preg_split('/\s+/', $line, 9);
			if (isset($parts[3]) && $parts[3] === 'cwd') {
				return $parts[8] ?? null;
			}
		}

		return null;
	}

	/**
	 * PIDs whose controlling terminal is $tty (accepts "ttys007" or "/dev/ttys007").
	 * This is the whole terminal process tree for a cmux surface — login shell,
	 * wrapper, and the Claude process beneath it. Read-only.
	 */
	public function pidsOnTty(string $tty): array {
		$tty = preg_replace('#^/dev/#', '', trim($tty));
		if ($tty === '') { return []; }
		$out  = (string) shell_exec('ps -t ' . escapeshellarg($tty) . ' -o pid= 2>/dev/null');
		$self = getmypid();
		$pids = [];
		foreach (preg_split('/\s+/', trim($out)) as $p) {
			if (ctype_digit($p) && (int) $p !== $self) { $pids[] = (int) $p; }
		}
		return $pids;
	}

	public function sendToSurface(string $surfRef, string $wsRef, string $text): void {
		if (!$this->dryRun) {
			shell_exec(
				'cmux send --surface ' . escapeshellarg($surfRef)
				. ' --workspace ' . escapeshellarg($wsRef)
				. ' ' . escapeshellarg($text)
				. ' 2>/dev/null'
			);
		}
	}

	public function sendKeyToSurface(string $surfRef, string $wsRef, string $key): void {
		if (!$this->dryRun) {
			shell_exec(
				'cmux send-key --surface ' . escapeshellarg($surfRef)
				. ' --workspace ' . escapeshellarg($wsRef)
				. ' ' . escapeshellarg($key)
				. ' 2>/dev/null'
			);
		}
	}

	public function createSurface(string $wsRef, ?string $paneRef, string $type, ?string $url): ?string {
		$cmd = 'cmux new-surface --type ' . escapeshellarg($type)
			. ' --workspace ' . escapeshellarg($wsRef);

		if ($type === 'browser' && $url) {
			$cmd .= ' --url ' . escapeshellarg($url);
		} elseif ($paneRef) {
			$cmd .= ' --pane ' . escapeshellarg($paneRef);
		}

		shell_exec($cmd . ' 2>/dev/null');
		usleep(400000);

		// Find the last surface in the workspace (the one we just created)
		$newTree = $this->tree();
		$newWs   = $this->findWorkspaceByRef($newTree, $wsRef);
		if (!$newWs) {
			return null;
		}

		$allSurfs = [];
		foreach ($newWs['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $s) {
				$allSurfs[] = $s['ref'];
			}
		}

		return $allSurfs ? end($allSurfs) : null;
	}

	public function newWorkspace(string $title, ?string $cwd): array {
		$cmd = 'cmux new-workspace --name ' . escapeshellarg($title);
		if ($cwd) { $cmd .= ' --cwd ' . escapeshellarg($cwd); }
		$res = $this->cli->getCommandOutputAndExitCode($cmd);
		if ($res['exitCode'] !== 0) { $this->cli->exitErr('new-workspace failed: ' . $res['error']); }
		usleep(500000);
		$ws = $this->findWorkspaceByTitle($this->tree(), $title);
		if (!$ws) { $this->cli->exitErr("Could not find new workspace '{$title}'."); }
		return [
			'ref'          => $ws['ref'] ?? '',
			'firstPaneRef' => $ws['panes'][0]['ref'] ?? null,
			'firstSurfRef' => $ws['panes'][0]['surfaces'][0]['ref'] ?? null,
		];
	}

	public function findWorkspaceByTitle(array $tree, string $title): ?array {
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				if (($ws['title'] ?? '') === $title) {
					return $ws;
				}
			}
		}
		return null;
	}

	public function findWorkspaceByRef(array $tree, string $ref): ?array {
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				if (($ws['ref'] ?? '') === $ref) {
					return $ws;
				}
			}
		}
		return null;
	}

	public function workspaceSurfaceCount(string $wsRef): int {
		$ws = $this->findWorkspaceByRef($this->tree(), $wsRef);
		if (!$ws) { return 0; }
		$n = 0;
		foreach ($ws['panes'] ?? [] as $pane) { $n += count($pane['surfaces'] ?? []); }
		return $n;
	}

	public function buildResumeCommand(string $sessionId, bool $skipPerms, ?string $model): string {
		$cmd = 'claude';
		if ($skipPerms) {
			$cmd .= ' --dangerously-skip-permissions';
		}
		$cmd .= " --resume {$sessionId}";
		if ($model) {
			$cmd .= " --model={$model}";
		}
		return $cmd;
	}

	/**
	 * Read the last permission-mode and model from a session's JSONL transcript.
	 * These reflect the state at the end of the conversation, not just launch flags.
	 */
	public function readSessionJsonl(?string $sessionId, ?string $cwd): array {
		$result = ['permission_mode' => null, 'model' => null];

		if (!$sessionId || !$cwd) {
			return $result;
		}

		$jsonlPath = $this->jsonlPathFor($sessionId, $cwd);

		if (!file_exists($jsonlPath)) {
			return $result;
		}

		$handle = fopen($jsonlPath, 'r');
		while (($line = fgets($handle)) !== false) {
			$entry = json_decode($line, true);
			if (!$entry || !isset($entry['type'])) {
				continue;
			}
			if ($entry['type'] === 'permission-mode' && isset($entry['permissionMode'])) {
				$result['permission_mode'] = $entry['permissionMode'];
			}
			if ($entry['type'] === 'assistant'
				&& isset($entry['message']['model'])
				&& $entry['message']['model'] !== '<synthetic>'
			) {
				$result['model'] = $entry['message']['model'];
			}
		}
		fclose($handle);

		return $result;
	}

	/**
	 * Whether a JSONL entry is SYNTHETIC / non-activity (not a real user/assistant
	 * conversation turn). Such entries must not count toward last-activity/idle.
	 * Three classes are detected, each per-entry (never by timestamp pairing):
	 *
	 *   1. cmux-bak mass-restore resume turns — a user "Continue from where you left
	 *      off." (isMeta) turn and an assistant turn whose model is '<synthetic>'
	 *      / text "No response requested.".
	 *   2. Slash-command turns — /export, /resume, etc. write command invocation,
	 *      stdout, and caveat turns (text begins with <command-name>, <command-args>,
	 *      <command-message>, <local-command-stdout>, <local-command-caveat>). These
	 *      freshen the JSONL without representing real work.
	 *
	 * @param array $entry Decoded JSONL entry.
	 * @return bool True if the entry is synthetic / non-activity.
	 */
	public function isSyntheticEntry(array $entry): bool {
		// Assistant marker: synthetic model.
		if (
			isset($entry['type'], $entry['message']['model'])
			&& $entry['type'] === 'assistant'
			&& $entry['message']['model'] === '<synthetic>'
		) {
			return true;
		}

		// Concatenate text content (array-of-parts or plain string).
		$content = $entry['message']['content'] ?? null;
		$text = '';
		if (is_string($content)) {
			$text = $content;
		} elseif (is_array($content)) {
			foreach ($content as $part) {
				if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
					$text .= $part['text'];
				}
			}
		}
		$text = trim($text);

		// Slash-command turns (invocation / stdout / caveat). Not gated on isMeta —
		// the invocation and stdout turns carry no isMeta flag.
		$commandPrefixes = ['<command-name>', '<command-args>', '<command-message>', '<local-command-stdout>', '<local-command-caveat>'];
		foreach ($commandPrefixes as $prefix) {
			if (strncmp($text, $prefix, strlen($prefix)) === 0) {
				return true;
			}
		}

		// Text-content resume marker. Gated on isMeta so a genuine human turn that
		// happens to type the same words isn't misclassified (the real synthetic user
		// resume turn always carries isMeta:true; the synthetic assistant turn is
		// already caught by the '<synthetic>' model check above).
		if (empty($entry['isMeta'])) {
			return false;
		}

		$markers = ['Continue from where you left off.', 'No response requested.'];
		return in_array($text, $markers, true);
	}

	/**
	 * Unix timestamp of the last JSONL entry whose type is 'user' or 'assistant'
	 * and that has a 'timestamp'. Null if the file is missing or has no such entry.
	 * JSONL mtime is unreliable (freshened by cron/housekeeping); this reflects the
	 * actual last real conversation turn. Synthetic resume turns (see
	 * isSyntheticEntry) are skipped so restored sessions don't look freshly active.
	 */
	public function lastRealActivity(string $sessionId, string $cwd): ?int {
		$jsonlPath = $this->jsonlPathFor($sessionId, $cwd);

		if (!is_file($jsonlPath)) {
			return null;
		}

		$lastTs = null;
		$handle = fopen($jsonlPath, 'r');
		while (($line = fgets($handle)) !== false) {
			$entry = json_decode($line, true);
			if (!$entry || !isset($entry['type'], $entry['timestamp'])) {
				continue;
			}
			if ($entry['type'] === 'user' || $entry['type'] === 'assistant') {
				if ($this->isSyntheticEntry($entry)) {
					continue;
				}
				$parsed = strtotime($entry['timestamp']);
				if ($parsed !== false) { $lastTs = $parsed; }
			}
		}
		fclose($handle);

		return $lastTs;
	}
}
