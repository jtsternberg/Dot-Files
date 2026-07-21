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
					'skip_perms'   => $this->resolveSkipPerms($jsonl['permission_mode'], (int) $pid),
					'model'        => $this->resolveModel($jsonl['model'], (int) $pid),
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

	# =========================================================================
	# Deterministic session <-> surface join (see graveyard dotfiles-yt2).
	#
	# cmux exposes no pid per surface, and tty numbers are recycled across live
	# surfaces (many surfaces share one tty), so a tty join mis-pairs sessions
	# with surfaces. Instead we bridge surface <-> process via the unique
	# resume-script path each surface launches (cmux-{surface,agent}-resume/
	# claude-<UUID>.zsh), which also appears verbatim in the live shell's args.
	# The functions below are PURE (take injected text/tables) so they are unit
	# testable; the *Live() wrappers do the shell I/O.
	# =========================================================================

	/** Raw `cmux debug-terminals` output. */
	public function debugTerminals(): string {
		return (string) shell_exec('cmux debug-terminals 2>/dev/null');
	}

	/**
	 * PURE. Parse `cmux debug-terminals` into
	 * [ surface_ref => ['tty','cwd','workspace_ref','title','script'] ].
	 * 'script' is the resume-script basename (claude-<UUID>.zsh) or null.
	 */
	public function parseDebugTerminals(string $raw): array {
		$out = [];
		// Each record starts with "[N] surface:M "TITLE" ...".
		$records = preg_split('/\n(?=\[\d+\]\s+surface:\d+\b)/', $raw) ?: [];
		foreach ($records as $rec) {
			if (!preg_match('/\[\d+\]\s+(surface:\d+)\s+"((?:[^"\\\\]|\\\\.)*)"/', $rec, $h)) { continue; }
			$ref   = $h[1];
			$title = $h[2];
			$tty   = preg_match('/\btty=(ttys\d+)/', $rec, $m) ? $m[1] : null;
			$cwd   = preg_match('/\bcwd=(.+?)\s+branch=/', $rec, $m) ? $m[1] : null;
			$ws    = preg_match('/\bworkspace=(workspace:\d+)/', $rec, $m) ? $m[1] : null;
			$script = preg_match('#cmux-(?:surface|agent)-resume/(claude-[A-Za-z0-9._-]+\.zsh)#', $rec, $m) ? $m[1] : null;
			$out[$ref] = ['tty' => $tty, 'cwd' => $cwd, 'workspace_ref' => $ws, 'title' => $title, 'script' => $script];
		}
		return $out;
	}

	/** Raw `ps -Ao pid,ppid,command` output. */
	public function psProcTable(): string {
		return (string) shell_exec('ps -Ao pid,ppid,command 2>/dev/null');
	}

	/**
	 * PURE. Parse `ps -Ao pid,ppid,command` into [ pid => ['ppid'=>int,'cmd'=>string] ].
	 */
	public function parseProcTable(string $raw): array {
		$proc = [];
		$lines = preg_split('/\n/', trim($raw)) ?: [];
		foreach ($lines as $i => $line) {
			if ($i === 0 && stripos($line, 'PID') !== false) { continue; } // header
			$p = preg_split('/\s+/', trim($line), 3);
			if (count($p) < 3 || !ctype_digit($p[0]) || !ctype_digit($p[1])) { continue; }
			$proc[(int) $p[0]] = ['ppid' => (int) $p[1], 'cmd' => $p[2]];
		}
		return $proc;
	}

	/** PURE. Children index: [ ppid => [pid,...] ]. */
	public function childIndex(array $proc): array {
		$kids = [];
		foreach ($proc as $pid => $info) { $kids[$info['ppid']][] = $pid; }
		return $kids;
	}

	/** PURE. Is this command the claude binary (not a claude-*.zsh wrapper arg)? */
	public function isClaudeCommand(string $cmd): bool {
		$first = preg_split('/\s+/', trim($cmd))[0] ?? '';
		return $first === 'claude' || substr($first, -7) === '/claude';
	}

	/** PURE. First descendant pid running the claude binary, searching down from $root. */
	public function descendantClaudePid(array $proc, int $root): ?int {
		$kids = $this->childIndex($proc);
		$stack = [$root]; $seen = [];
		while ($stack) {
			$cur = array_pop($stack);
			foreach ($kids[$cur] ?? [] as $c) {
				if (isset($seen[$c])) { continue; }
				$seen[$c] = true;
				if ($this->isClaudeCommand($proc[$c]['cmd'] ?? '')) { return $c; }
				$stack[] = $c;
			}
		}
		return null;
	}

	/** PURE. All descendant pids of $root (inclusive) — used to kill a claude + its subagents. */
	public function descendantPids(array $proc, int $root): array {
		$kids = $this->childIndex($proc);
		$acc = [$root]; $stack = [$root]; $seen = [$root => true];
		while ($stack) {
			$cur = array_pop($stack);
			foreach ($kids[$cur] ?? [] as $c) {
				if (isset($seen[$c])) { continue; }
				$seen[$c] = true; $acc[] = $c; $stack[] = $c;
			}
		}
		return $acc;
	}

	/** PURE. Walk up from $pid; return the resume-script basename found in an ancestor's args, or null. */
	public function ancestorResumeScript(array $proc, int $pid): ?string {
		$guard = 0;
		while (isset($proc[$pid]) && $guard++ < 64) {
			if (preg_match('#cmux-(?:surface|agent)-resume/(claude-[A-Za-z0-9._-]+\.zsh)#', $proc[$pid]['cmd'], $m)) {
				return $m[1];
			}
			$pid = $proc[$pid]['ppid'];
			if ($pid <= 1) { break; }
		}
		return null;
	}

	/** PURE. The session id a claude process was resumed with, from its --resume arg. */
	public function claudeResumeArg(string $cmd): ?string {
		return preg_match('/--resume(?:=|\s+)([0-9a-fA-F-]{36})/', $cmd, $m) ? $m[1] : null;
	}

	/**
	 * PURE. Does a command line carry the --dangerously-skip-permissions flag?
	 * Catches the yolo/yr/yc aliases too: zsh expands them to the literal flag
	 * before exec, so it's the flag (never the alias name) that lands in argv.
	 */
	public function cmdHasSkipPerms(string $cmd): bool {
		return (bool) preg_match('/(?:^|\s)--dangerously-skip-permissions(?![\w-])/', $cmd);
	}

	/** The live process argv for a pid (empty string if the pid is gone). */
	public function pidCommand(int $pid): string {
		return trim((string) shell_exec('ps -p ' . $pid . ' -o command= 2>/dev/null'));
	}

	/**
	 * Resolve a session's skip_perms (yolo mode). The jsonl permission-mode is the
	 * source of truth — it reflects the mode at the END of the conversation, so it
	 * captures mid-session shift+tab toggles that a launch flag never would. Only
	 * when the jsonl can't be read (null: missing/unflushed transcript) do we fall
	 * back to the live process argv's launch flag, so a session buried without a
	 * readable jsonl no longer false-negatives to "not yolo" (graveyard dotfiles-yolo).
	 */
	public function resolveSkipPerms(?string $permissionMode, ?int $pid): bool {
		if ($permissionMode !== null) {
			return $permissionMode === 'bypassPermissions';
		}
		return $pid !== null ? $this->cmdHasSkipPerms($this->pidCommand($pid)) : false;
	}

	/** PURE. The value of a --model flag in a command line (--model=X or --model X), or null. */
	public function cmdModelArg(string $cmd): ?string {
		return preg_match('/--model(?:=|\s+)(\S+)/', $cmd, $m) ? $m[1] : null;
	}

	/**
	 * Resolve a session's model. As with skip_perms, the jsonl is the source of
	 * truth (the resolved model recorded on assistant turns); only when it's null
	 * (unreadable jsonl) do we fall back to the live process argv's --model launch
	 * flag. Null from both means no override was in play (default model).
	 */
	public function resolveModel(?string $jsonlModel, ?int $pid): ?string {
		if ($jsonlModel !== null) {
			return $jsonlModel;
		}
		return $pid !== null ? $this->cmdModelArg($this->pidCommand($pid)) : null;
	}

	/**
	 * Load active Claude sessions keyed by pid (companion to loadClaudeSessions,
	 * which keys by tty). [ pid => [session_id, cwd, skip_perms, model, status] ].
	 */
	public function loadClaudeSessionsByPid(): array {
		$dir = $this->cli->convertPathToAbsolute(self::SESSIONS_DIR);
		$out = [];
		if (!is_dir($dir)) { return $out; }
		foreach (glob($dir . '/*.json') ?: [] as $file) {
			$pid = pathinfo($file, PATHINFO_FILENAME);
			if (!ctype_digit($pid) || !$this->pidIsAlive((int) $pid)) { continue; }
			$data = json_decode((string) @file_get_contents($file), true);
			if (!$data) { continue; }
			$sid  = $data['sessionId'] ?? null;
			$cwd  = $data['cwd'] ?? null;
			$meta = $this->readSessionJsonl($sid, $cwd);
			$out[(int) $pid] = [
				'session_id' => $sid,
				'cwd'        => $cwd,
				'status'     => $data['status'] ?? null,
				'skip_perms' => $this->resolveSkipPerms($meta['permission_mode'] ?? null, (int) $pid),
				'model'      => $this->resolveModel($meta['model'] ?? null, (int) $pid),
			];
		}
		return $out;
	}

	/** Generate a v4 UUID (for graveyard group ids). */
	public function uuidv4(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}

	/**
	 * PURE. Normalize a workspace/tab title for equality comparison: strip the leading
	 * status-glyph / punctuation junk cmux tab titles carry (e.g. "⠂ ", "✳ ", quotes),
	 * collapse whitespace, and lowercase. Used so an exact title match can beat mere
	 * substring matches.
	 */
	public function normalizeTitle(string $title): string {
		$s = preg_replace('/^[^\p{L}\p{N}]+/u', '', $title); // leading glyphs / punctuation
		$s = preg_replace('/\s+/u', ' ', (string) $s);       // collapse internal whitespace
		return mb_strtolower(trim((string) $s));
	}

	/**
	 * PURE. Resolve a workspace node from a cmux tree by exact ref (workspace:N), an
	 * exact (normalized, case-insensitive) title match, or a case-insensitive title
	 * substring. Returns ['ref','title','node','window_ref'] or null (none) / throws
	 * \RuntimeException on ambiguous match.
	 *
	 * # graveyard workspace-resolver exact-match tiebreak (dotfiles-w7k): cmux auto-titles
	 * a workspace after its running command, so the workspace the bury command is typed in
	 * gets titled with the literal command line — which CONTAINS the query as a substring
	 * and makes the command ambiguous against itself. An exact normalized-title match wins
	 * outright (no ambiguity check), and only when there's no exact match do we fall back to
	 * substring matching (still rejecting genuine ambiguity).
	 */
	public function resolveWorkspaceNode(array $tree, string $nameOrRef): ?array {
		$matches = [];       // substring matches
		$exact   = [];       // normalized-title exact matches
		$needle  = $this->normalizeTitle($nameOrRef);
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				$ref   = $ws['ref'] ?? '';
				$title = $ws['title'] ?? '';
				if ($ref === $nameOrRef) {
					return ['ref' => $ref, 'title' => $title, 'node' => $ws, 'window_ref' => $window['ref'] ?? ''];
				}
				$hit = ['ref' => $ref, 'title' => $title, 'node' => $ws, 'window_ref' => $window['ref'] ?? ''];
				if ($needle !== '' && $this->normalizeTitle((string) $title) === $needle) {
					$exact[] = $hit;
				}
				if ($nameOrRef !== '' && stripos((string) $title, $nameOrRef) !== false) {
					$matches[] = $hit;
				}
			}
		}
		// Exact normalized-title match wins over substring noise.
		if (count($exact) === 1) { return $exact[0]; }
		if (count($exact) > 1) {
			$titles = implode(', ', array_map(fn($m) => "{$m['ref']} \"{$m['title']}\"", $exact));
			throw new \RuntimeException("Ambiguous workspace '{$nameOrRef}' — matches: {$titles}");
		}
		if (count($matches) === 1) { return $matches[0]; }
		if (count($matches) > 1) {
			$titles = implode(', ', array_map(fn($m) => "{$m['ref']} \"{$m['title']}\"", $matches));
			throw new \RuntimeException("Ambiguous workspace '{$nameOrRef}' — matches: {$titles}");
		}
		return null;
	}

	/** sessionId recorded in ~/.claude/sessions/<pid>.json for a live pid, or null. */
	public function sessionIdForPid(int $pid): ?string {
		if ($pid <= 0 || !$this->pidIsAlive($pid)) { return null; }
		$dir  = $this->cli->convertPathToAbsolute(self::SESSIONS_DIR);
		$file = "{$dir}/{$pid}.json";
		if (!is_file($file)) { return null; }
		$data = json_decode((string) @file_get_contents($file), true);
		return $data['sessionId'] ?? null;
	}

	/**
	 * PURE. Deterministically bind each live Claude session to its cmux surface via
	 * process ancestry, tty-free. Returns rows:
	 *   [ session_id, pid, cwd, model, skip_perms, surface_ref, workspace_ref, tty,
	 *     title, targetable(bool), reason(string) ]
	 *
	 * A session is targetable only when its claude pid walks up to exactly one
	 * surface's resume script AND (when the claude was launched with --resume) that
	 * id matches the session id. Any ambiguity (no bridge, unknown surface, script
	 * shared by >1 surface, >1 session on a surface, --resume mismatch) yields
	 * targetable=false with a reason — never a guess.
	 *
	 * @param array $sessions  pid => [session_id,cwd,model,skip_perms,...]
	 * @param array $proc      pid => [ppid,cmd]              (parseProcTable)
	 * @param array $debug     surface_ref => [tty,cwd,workspace_ref,title,script] (parseDebugTerminals)
	 */
	public function joinSessionsToSurfaces(array $sessions, array $proc, array $debug): array {
		// script basename -> [surface_ref,...]
		$scriptSurfaces = [];
		foreach ($debug as $ref => $d) {
			if (!empty($d['script'])) { $scriptSurfaces[$d['script']][] = $ref; }
		}

		$rows = [];
		$surfaceClaimants = []; // surface_ref -> [session_id,...] to detect collisions

		foreach ($sessions as $pid => $s) {
			$claude = $this->descendantClaudePid($proc, (int) $pid) ?? (int) $pid;
			$row = [
				'session_id'    => $s['session_id'] ?? null,
				'pid'           => $claude,
				'cwd'           => $s['cwd'] ?? '',
				'model'         => $s['model'] ?? null,
				'skip_perms'    => (bool) ($s['skip_perms'] ?? false),
				'surface_ref'   => '',
				'workspace_ref' => '',
				'tty'           => '',
				'title'         => '',
				'targetable'    => false,
				'reason'        => '',
			];

			$script = $this->ancestorResumeScript($proc, (int) $pid);
			if ($script === null) {
				$row['reason'] = 'no resume-script ancestor (not a resumed cmux surface)';
				$rows[] = $row; continue;
			}
			$surfaces = $scriptSurfaces[$script] ?? [];
			if (count($surfaces) === 0) {
				$row['reason'] = 'resume script not found among cmux surfaces';
				$rows[] = $row; continue;
			}
			if (count($surfaces) > 1) {
				$row['reason'] = 'resume script shared by ' . count($surfaces) . ' surfaces (ambiguous)';
				$rows[] = $row; continue;
			}
			$ref = $surfaces[0];
			$d   = $debug[$ref];

			// Integrity: if the claude proc carries --resume, it must equal this session id.
			$resumeArg = $this->claudeResumeArg($proc[$claude]['cmd'] ?? '');
			if ($resumeArg !== null && $row['session_id'] !== null && $resumeArg !== $row['session_id']) {
				$row['reason'] = "claude --resume ({$resumeArg}) != session id";
				$rows[] = $row; continue;
			}

			$row['surface_ref']   = $ref;
			$row['workspace_ref'] = $d['workspace_ref'] ?? '';
			$row['tty']           = $d['tty'] ?? '';
			$row['title']         = $d['title'] ?? '';
			$row['targetable']    = true;
			$surfaceClaimants[$ref][] = $row['session_id'];
			$rows[] = $row;
		}

		// Collision: a surface claimed by >1 session -> all such rows untargetable.
		foreach ($rows as &$r) {
			$ref = $r['surface_ref'];
			if ($ref && count(array_unique($surfaceClaimants[$ref] ?? [])) > 1) {
				$r['targetable'] = false;
				$r['reason'] = 'surface claimed by multiple sessions (collision)';
			}
		}
		unset($r);

		return $rows;
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

	/** [surface_ref => pane_ref] for every surface in a workspace (current tree). */
	private function surfacePaneMap(string $wsRef): array {
		$ws = $this->findWorkspaceByRef($this->tree(), $wsRef);
		$map = [];
		foreach ($ws['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $s) {
				if (isset($s['ref'])) { $map[$s['ref']] = $pane['ref'] ?? ''; }
			}
		}
		return $map;
	}

	/** The one surface ref present now but absent in $before (the just-created one). */
	private function firstNewSurface(string $wsRef, array $before): ?string {
		foreach (array_keys($this->surfacePaneMap($wsRef)) as $ref) {
			if (!isset($before[$ref])) { return $ref; }
		}
		return null;
	}

	/** The cmux pane ref that currently owns $surfRef, or null. */
	public function paneRefForSurface(string $wsRef, string $surfRef): ?string {
		return $this->surfacePaneMap($wsRef)[$surfRef] ?? null;
	}

	/**
	 * Bring $surfRef to the front of its pane (make it the visible tab) without moving
	 * it. cmux has no dedicated select-surface verb, but a `move-surface` to the
	 * surface's OWN pane + current index selects it as a side effect and preserves tab
	 * order (verified). No-op if the surface can't be located. Returns success.
	 */
	public function selectSurface(string $wsRef, string $surfRef): bool {
		if ($this->dryRun) { return false; }

		$ws = $this->findWorkspaceByRef($this->tree(), $wsRef);
		foreach ($ws['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $s) {
				if (($s['ref'] ?? '') !== $surfRef) { continue; }
				$res = $this->cli->getCommandOutputAndExitCode(
					'cmux move-surface --surface ' . escapeshellarg($surfRef)
					. ' --pane ' . escapeshellarg((string) ($pane['ref'] ?? ''))
					. ' --index ' . (int) ($s['index_in_pane'] ?? 0)
					. ' --workspace ' . escapeshellarg($wsRef)
				);
				return ($res['exitCode'] ?? 1) === 0;
			}
		}
		return false;
	}

	public function createSurface(string $wsRef, ?string $paneRef, string $type, ?string $url): ?string {
		if ($this->dryRun) { return null; }

		$before = $this->surfacePaneMap($wsRef);

		$cmd = 'cmux new-surface --type ' . escapeshellarg($type)
			. ' --workspace ' . escapeshellarg($wsRef);

		if ($type === 'browser' && $url) {
			$cmd .= ' --url ' . escapeshellarg($url);
		}
		// A tab lives inside a pane; without --pane cmux drops it into the focused
		// pane, collapsing a restored multi-pane layout into one pane.
		if ($paneRef) {
			$cmd .= ' --pane ' . escapeshellarg($paneRef);
		}

		shell_exec($cmd . ' 2>/dev/null');
		usleep(400000);

		// Identify the new surface by diffing the tree — end()-of-list is unreliable
		// once multiple panes exist (surface order is not creation order).
		return $this->firstNewSurface($wsRef, $before);
	}

	/**
	 * Split $fromSurfRef's pane in $direction, creating a NEW pane with a fresh
	 * terminal surface. Returns that new surface's ref (or null). cmux exposes no
	 * stored split geometry, so callers pick the direction.
	 */
	public function newSplit(string $wsRef, string $fromSurfRef, string $direction): ?string {
		if ($this->dryRun) { return null; }

		$before = $this->surfacePaneMap($wsRef);
		$cmd = 'cmux new-split ' . escapeshellarg($direction)
			. ' --surface ' . escapeshellarg($fromSurfRef)
			. ' --workspace ' . escapeshellarg($wsRef);
		shell_exec($cmd . ' 2>/dev/null');
		usleep(400000);

		return $this->firstNewSurface($wsRef, $before);
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

	/**
	 * Capture a live workspace's full split geometry (orientation, divider ratio,
	 * nesting, per-pane tab order) via cmux's own layout store. cmux's `system.tree`
	 * flattens all of this away, but `layout get` returns the recursive definition —
	 * the only faithful source. Round-trips through a throwaway named layout (save →
	 * get → delete) so no cruft is left behind. Returns the `workspace.layout` subtree
	 * (`{direction,split,children}` or a bare `{pane:{surfaces}}`), or null if cmux has
	 * no layout API / the capture fails (caller falls back to a manual rebuild).
	 */
	public function captureLayoutTree(string $wsRef): ?array {
		if ($this->dryRun) { return null; }

		$name = 'graveyard-capture-' . bin2hex(random_bytes(4));
		$save = $this->cli->getCommandOutputAndExitCode(
			'cmux layout save ' . escapeshellarg($name) . ' --workspace ' . escapeshellarg($wsRef) . ' --overwrite'
		);
		if (($save['exitCode'] ?? 1) !== 0) { return null; }

		$get = $this->cli->getCommandOutputAndExitCode('cmux layout get ' . escapeshellarg($name));
		$this->cli->getCommandOutputAndExitCode('cmux layout delete ' . escapeshellarg($name));
		if (($get['exitCode'] ?? 1) !== 0) { return null; }

		$data = json_decode((string) ($get['output'] ?? ''), true);
		$tree = $data['workspace']['layout'] ?? null;
		return is_array($tree) ? $tree : null;
	}

	/**
	 * Create a workspace from a cmux layout definition (inline JSON), rebuilding the
	 * exact splits/tabs. Returns the new workspace's full tree node (panes[].surfaces[])
	 * so the caller can join surfaces to sessions positionally, or null on failure.
	 */
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree): ?array {
		if ($this->dryRun) { return null; }

		$cmd = 'cmux new-workspace --name ' . escapeshellarg($title)
			. ' --layout ' . escapeshellarg((string) json_encode($layoutTree));
		if ($cwd) { $cmd .= ' --cwd ' . escapeshellarg($cwd); }

		$res = $this->cli->getCommandOutputAndExitCode($cmd);
		if (($res['exitCode'] ?? 1) !== 0) { return null; }
		usleep(600000);

		return $this->findWorkspaceByTitle($this->tree(), $title);
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
	 * Invoke $fn($line) on each line of $path from LAST to FIRST, reading fixed
	 * chunks backward from EOF so the whole file is never held in memory. A line
	 * straddling a chunk boundary is carried into the next (earlier) chunk before
	 * it's emitted. $fn returning false stops the scan early. Blank lines are
	 * skipped. Lets tail-only scans avoid touching the head of a large transcript.
	 */
	public function eachLineReverse(string $path, callable $fn): void {
		$h = @fopen($path, 'rb');
		if (!$h) { return; }
		$chunkSize = 65536;
		$stat = fstat($h);
		$pos  = $stat ? (int) $stat['size'] : 0;
		$carry = ''; // fragment that belongs AFTER the current chunk (start of a later line)
		while ($pos > 0) {
			$read = (int) min($chunkSize, $pos);
			$pos -= $read;
			fseek($h, $pos);
			$buf   = (string) fread($h, $read) . $carry;
			$lines = explode("\n", $buf);
			// Unless we've reached the file start, the first fragment continues into
			// the earlier chunk — hold it back rather than emit a partial line.
			$carry = $pos > 0 ? array_shift($lines) : '';
			for ($i = count($lines) - 1; $i >= 0; $i--) {
				if ($lines[$i] === '') { continue; }
				if ($fn($lines[$i]) === false) { fclose($h); return; }
			}
		}
		fclose($h);
	}

	/**
	 * Read the last permission-mode and model from a session's JSONL transcript.
	 * These reflect the state at the END of the conversation (either can change
	 * mid-run), so we scan backward and take the first of each we encounter,
	 * stopping as soon as both are known instead of decoding the whole file.
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

		$this->eachLineReverse($jsonlPath, function (string $line) use (&$result) {
			$entry = json_decode($line, true);
			if (!$entry || !isset($entry['type'])) {
				return true;
			}
			if ($result['permission_mode'] === null
				&& $entry['type'] === 'permission-mode' && isset($entry['permissionMode'])
			) {
				$result['permission_mode'] = $entry['permissionMode'];
			}
			if ($result['model'] === null
				&& $entry['type'] === 'assistant'
				&& isset($entry['message']['model'])
				&& $entry['message']['model'] !== '<synthetic>'
			) {
				$result['model'] = $entry['message']['model'];
			}
			// Keep scanning backward until BOTH are known.
			return $result['permission_mode'] === null || $result['model'] === null;
		});

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

		// The latest genuine turn is the last real user/assistant entry — scan backward
		// and take the first parseable one, stopping there instead of reading the head.
		$lastTs = null;
		$this->eachLineReverse($jsonlPath, function (string $line) use (&$lastTs) {
			$entry = json_decode($line, true);
			if (!$entry || !isset($entry['type'], $entry['timestamp'])) {
				return true;
			}
			if (($entry['type'] === 'user' || $entry['type'] === 'assistant')
				&& !$this->isSyntheticEntry($entry)
			) {
				$parsed = strtotime($entry['timestamp']);
				if ($parsed !== false) { $lastTs = $parsed; return false; }
			}
			return true;
		});

		return $lastTs;
	}
}
