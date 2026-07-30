<?php
namespace JT\Helpers;

# =============================================================================
# Cmux — shared cmux + Claude-session machinery for bin/cmux-bak and bin/graveyard.
# Extracted from bin/cmux-bak (v0.1.0). Behavior-preserving.
# =============================================================================

class Cmux {

	use TitleGlyphTrait;
	use ReverseLinesTrait;

	const SESSIONS_DIR = '~/.claude/sessions';

	/** Codex rollout transcripts live under <root>/YYYY/MM/DD/rollout-<ts>-<uuid>.jsonl. */
	const CODEX_SESSIONS_DIR = '~/.codex/sessions';

	/**
	 * codex subcommands that never own a cmux surface: the VS Code extension's
	 * `app-server`, headless `exec`, and the various one-shot utilities. Only an
	 * interactive TUI is a session cmux-bak can back up and resume.
	 */
	const CODEX_NON_TUI_SUBCOMMANDS = [
		'app-server', 'exec', 'mcp', 'mcp-server', 'login', 'logout',
		'completion', 'apply', 'sandbox', 'debug', 'generate-ts',
	];

	protected $cli;
	protected $dryRun;

	public function __construct($cli, bool $dryRun = false) {
		$this->cli    = $cli;
		$this->dryRun = $dryRun;
	}

	/**
	 * The cmux binary this class shells out to. CMUX_BIN overrides it — set in tests to
	 * a stub script so no test reaches the real cmux (mirrors Godo's GODO_DIRMAP_BIN;
	 * see CLAUDE.md's shelling-seam rule). Every cmux invocation below routes through
	 * this; call sites escapeshellcmd() it. Public so tests and Graveyard share one hook.
	 */
	public function cmuxBin(): string {
		return getenv('CMUX_BIN') ?: 'cmux';
	}

	public function ping(): bool {
		$result = shell_exec(escapeshellcmd($this->cmuxBin()) . ' ping 2>/dev/null');
		return trim((string) $result) === 'PONG';
	}

	public function tree(): array {
		// --id-format both so every node carries its stable UUID (`id`) alongside
		// the positional `ref`. cmux-bak matches by tty/title (unaffected); graveyard
		// needs the UUID to compare against CMUX_SURFACE_ID for the self-bury guard.
		//
		// Failure is a RuntimeException, NOT exitErr()/exit(): exit() inside this shelling
		// seam killed PHPUnit mid-run wherever cmux is absent (dotfiles-3qa). The bin/
		// entry seam (bin/graveyard, bin/cmux-bak) catches it and calls exitErr(), keeping
		// process-exit plumbing at the entry where CLAUDE.md says it belongs — and letting
		// a test assert the failure without dying.
		$output = shell_exec(escapeshellcmd($this->cmuxBin()) . ' tree --all --json --id-format both 2>/dev/null');
		if (!$output) {
			throw new \RuntimeException('cmux tree returned no output.');
		}
		$tree = json_decode($output, true);
		if (!$tree) {
			throw new \RuntimeException('Failed to parse cmux tree JSON.');
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
		return (string) shell_exec(escapeshellcmd($this->cmuxBin()) . ' debug-terminals 2>/dev/null');
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

	# =========================================================================
	# Codex sessions (dotfiles-zcm).
	#
	# Codex cannot reuse Claude's session<->surface join. Claude bridges through
	# the unique cmux-{surface,agent}-resume/claude-<UUID>.zsh script each surface
	# launches; a codex TUI is started by hand in a plain shell, so its ancestry is
	# `-/bin/zsh` -> login -> cmux with no resume script to match on. What every
	# process in a surface *does* have is CMUX_SURFACE_ID in its environment, and
	# the tree already reports that same UUID per surface as `id` (tree() passes
	# --id-format both). That pairing is exact and, unlike tty, never recycled.
	#
	# Session ids come from the rollout file the live codex holds open, so two
	# codex sessions started in the same minute can't be confused — which any
	# "newest file in ~/.codex/sessions" heuristic would do.
	#
	# NOTE — cmux already knows some of this, and we deliberately don't rely on it:
	# `cmux surface resume get --surface <uuid>` returns a resume_binding cmux's own
	# agent hooks wrote (kind, checkpoint_id = the session id, cwd, and the command
	# cmux would relaunch). It agrees with what we derive here and survives the
	# process dying. But measured coverage is incomplete — 8 of 26 live terminal
	# surfaces had no binding, 7 of them hosting live Claude sessions this join does
	# find — so it could only ever be a second source, and as a fallback it buys
	# nothing these joins miss (beads dotfiles-0ue, closed).
	#
	# That binding tracks the agent process's LAUNCH ARGV, not the session's
	# history: relaunch a session bare and the binding loses the sandbox flag;
	# relaunch it with --sandbox=… and the hook rewrites the binding to carry that.
	# So cmux faithfully replays how a process was started, and buildCodexResume-
	# Command() replaying the rollout's recorded context is what makes cmux's own
	# next restore correct too — the hook picks our flags up. No binding writes
	# needed (they're clobbered by the next hook firing anyway, and gated behind a
	# GUI approval prompt). dotfiles-0u4 asserted cmux hardcodes --yolo and widens
	# the sandbox; that was wrong and is closed.
	# =========================================================================

	/** Absolute path of the codex sessions root (CODEX_SESSIONS_DIR overrides, for tests). */
	public function codexSessionsDir(): string {
		$override = getenv('CODEX_SESSIONS_DIR');
		return $this->cli->convertPathToAbsolute($override !== false && $override !== '' ? $override : self::CODEX_SESSIONS_DIR);
	}

	/** PURE. Is argv[0] the codex binary itself (not codex-code-mode-host, etc.)? */
	public function isCodexCommand(string $cmd): bool {
		$first = preg_split('/\s+/', trim($cmd))[0] ?? '';
		return $first !== '' && basename($first) === 'codex';
	}

	/**
	 * PURE. The first argv word that looks like a subcommand rather than a flag or
	 * a flag's value — i.e. the first non-flag word not immediately preceded by a
	 * flag. Callers only ever REJECT known names with this (never require a match),
	 * because a value-carrying flag can leave arbitrary junk in the position:
	 * `--enable hooks` must not read as the `hooks` subcommand, while
	 * `-c features.x=true app-server` must still read as `app-server`.
	 */
	public function codexSubcommand(string $cmd): ?string {
		$words = preg_split('/\s+/', trim($cmd)) ?: [];
		array_shift($words); // argv[0]
		$prevWasFlag = false;
		foreach ($words as $w) {
			if ($w === '') { continue; }
			if ($w[0] === '-') { $prevWasFlag = true; continue; }
			if ($prevWasFlag) { $prevWasFlag = false; continue; } // a flag's value
			return $w;
		}
		return null;
	}

	/** PURE. Does this command line run a non-interactive codex subcommand? */
	public function isCodexNonTuiCommand(string $cmd): bool {
		$sub = $this->codexSubcommand($cmd);
		return $sub !== null && in_array($sub, self::CODEX_NON_TUI_SUBCOMMANDS, true);
	}

	/**
	 * PURE. Pids of interactive codex TUIs in a parseProcTable() table. Cheap
	 * pre-filter only — the caller still confirms each pid with an open rollout
	 * and a CMUX_SURFACE_ID before treating it as a backable session.
	 */
	public function codexProcPids(array $proc): array {
		$pids = [];
		foreach ($proc as $pid => $info) {
			$cmd = $info['cmd'] ?? '';
			if ($this->isCodexCommand($cmd) && !$this->isCodexNonTuiCommand($cmd)) {
				$pids[] = (int) $pid;
			}
		}
		return $pids;
	}

	/** Raw `lsof -p <pid>` output — yields the open rollout AND the cwd in one call. */
	public function lsofForPid(int $pid): string {
		return (string) shell_exec('lsof -p ' . (int) $pid . ' 2>/dev/null');
	}

	/**
	 * Raw `ps -wwEp <pid>`. NOTE: -E appends the environment to the command
	 * column on the same line, so this output carries every env var of the
	 * process — including CMUX_SOCKET_CAPABILITY, a live auth token. Feed it
	 * straight to parseSurfaceIdFromEnv() and never log or persist it.
	 */
	public function pidEnv(int $pid): string {
		return (string) shell_exec('ps -wwEp ' . (int) $pid . ' 2>/dev/null');
	}

	/** PURE. Path of the FIRST rollout jsonl an lsof dump shows open, or null. */
	public function parseLsofRolloutPath(string $raw): ?string {
		return $this->parseLsofRolloutPaths($raw)[0] ?? null;
	}

	/**
	 * PURE. EVERY rollout jsonl an lsof dump shows open, in lsof's own order.
	 *
	 * A codex TUI holds more than one: its own, plus one per subagent thread it has spawned
	 * (they stay open for the life of the process). So "the rollout this pid has open" is a
	 * set, and lsof's order is fd order — which is why picking the first silently returned a
	 * SUBAGENT as the session. See ownRolloutPathFromLsof().
	 */
	public function parseLsofRolloutPaths(string $raw): array {
		return preg_match_all('#(\S*/rollout-\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}-[0-9a-fA-F-]{36}\.jsonl)#', $raw, $m)
			? array_values(array_unique($m[1]))
			: [];
	}

	/**
	 * The rollout of the SESSION a pid is running, out of every rollout it holds open.
	 *
	 * Subagent threads are excluded by reading each candidate's own session_meta
	 * (CodexRollout::selfMeta — the LAST one in the file, since a spawned thread's rollout
	 * opens with a copy of its parent's records). What remains is the conversation a human is
	 * sitting in front of: the one to bury, to resume, and to prove a pid against.
	 *
	 * Measured: pid 53691 held rollout-…-019faf33 (thread_source=user) and
	 * rollout-…-019faf55 (thread_source=subagent, "Wegener") open, with the subagent listed
	 * FIRST — so graveyard offered a subagent thread as a buriable session, and its bury
	 * then failed the archive gate because the file's ids belong to two different threads.
	 *
	 * Ties (more than one real thread open, e.g. after a fork) go to the most recently
	 * written, which is the one still being appended to. Fails SOFT: if nothing can be read
	 * — an unreadable file, or a codex old enough not to emit thread_source — the first path
	 * is returned, so discovery never loses a session it used to find.
	 */
	public function ownRolloutPathFromLsof(string $raw): ?string {
		$paths = $this->parseLsofRolloutPaths($raw);
		if (count($paths) < 2) { return $paths[0] ?? null; }

		$reader = new CodexRollout();
		$own    = array_values(array_filter($paths, fn($p) => !$reader->selfMeta($p)['is_subagent']));
		if (!$own) { return $paths[0]; }

		usort($own, function ($a, $b) {
			clearstatcache(true, $a);
			clearstatcache(true, $b);
			return (int) @filemtime($b) <=> (int) @filemtime($a);
		});
		return $own[0];
	}

	/** The session id of the rollout ownRolloutPathFromLsof() picks, or null. */
	public function ownSessionIdFromLsof(string $raw): ?string {
		$path = $this->ownRolloutPathFromLsof($raw);
		return $path !== null ? $this->rolloutUuidFromPath($path) : null;
	}

	/**
	 * The codex session a pid is running — the counterpart of sessionIdForPid() for Claude,
	 * which reads ~/.claude/sessions/<pid>.json. Codex publishes nothing, so this is derived
	 * from the rollouts the process holds open. Used by bury's GATE 3.
	 */
	public function codexSessionIdForPid(int $pid): ?string {
		return $this->ownSessionIdFromLsof($this->lsofForPid($pid));
	}

	/**
	 * PURE. The codex session id (uuid) from an lsof dump, or null. The uuid is
	 * positional — it trails a `rollout-<ISO-ish timestamp>-` prefix — so the
	 * shape is matched strictly rather than grabbing any uuid-looking run.
	 *
	 * FIRST match only, and therefore NOT "which session is this pid running" when the
	 * process has subagent threads open — use ownSessionIdFromLsof()/codexSessionIdForPid()
	 * for that. This stays as the pure parser it says it is.
	 */
	public function parseLsofRollout(string $raw): ?string {
		$path = $this->parseLsofRolloutPath($raw);
		return $path !== null ? $this->rolloutUuidFromPath($path) : null;
	}

	/** PURE. The session uuid embedded in a rollout filename, or null. */
	public function rolloutUuidFromPath(string $path): ?string {
		return preg_match('/rollout-\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}-([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\.jsonl$/', $path, $m)
			? $m[1]
			: null;
	}

	/** PURE. The process working directory from an lsof dump (the FD=cwd row), or null. */
	public function parseLsofCwd(string $raw): ?string {
		// NAME is the last column and may contain spaces, so it's "rest of line".
		return preg_match('/^\S+\s+\d+\s+\S+\s+cwd\s+\S+\s+\S+\s+\S+\s+\S+\s+(.+)$/m', $raw, $m)
			? rtrim($m[1])
			: null;
	}

	/** PURE. CMUX_SURFACE_ID out of a `ps -wwEp` dump (and nothing else from it), or null. */
	public function parseSurfaceIdFromEnv(string $raw): ?string {
		return preg_match('/\bCMUX_SURFACE_ID=([0-9A-Fa-f-]{36})\b/', $raw, $m) ? $m[1] : null;
	}

	/**
	 * The cwd recorded in a rollout's `session_meta` header — where the
	 * conversation actually ran, which is what restore should cd into even if the
	 * live process has since been cd'd elsewhere.
	 */
	public function codexSessionCwd(string $rolloutPath): ?string {
		$h = @fopen($rolloutPath, 'rb');
		if (!$h) { return null; }
		$cwd = null;
		// The header is the first record, but scan a few lines in case of a
		// leading blank or a writer that emits a preamble.
		for ($i = 0; $i < 5 && ($line = fgets($h)) !== false; $i++) {
			$rec = json_decode(trim($line), true);
			if (is_array($rec) && ($rec['type'] ?? '') === 'session_meta') {
				$cwd = $rec['payload']['cwd'] ?? null;
				break;
			}
		}
		fclose($h);
		return $cwd !== null && $cwd !== '' ? $cwd : null;
	}

	/**
	 * Path of the rollout for a codex session id, or null if it's gone. Globbed
	 * rather than reconstructed: the YYYY/MM/DD directories aren't derivable from
	 * a bare uuid, and this is what audit's "resumable" check rests on.
	 */
	public function codexRolloutPathFor(string $sessionId): ?string {
		if (!preg_match('/^[0-9a-fA-F-]{36}$/', $sessionId)) { return null; }
		$hits = glob($this->codexSessionsDir() . "/*/*/*/rollout-*-{$sessionId}.jsonl") ?: [];
		return $hits ? $hits[0] : null;
	}

	/**
	 * The model / sandbox / approval / reasoning-effort a codex session was last
	 * running under, from the LAST turn_context record in its rollout — the state
	 * at the END of the conversation, so a mid-session change wins. Same principle
	 * as resolveModel()/resolveSkipPerms() treating Claude's jsonl as truth.
	 *
	 * This exists because `codex resume` does NOT rehydrate them. Measured in the
	 * real interactive TUI, not just under `codex exec`: a session created with
	 * `-s read-only` and resumed bare reports `Permissions: Full Access` in
	 * /status; resumed with these values replayed it reports
	 * `Permissions: Read Only (never)`. Restoring bare silently widens the sandbox.
	 * (Test on a FRESH session — one that was previously resumed WITH explicit
	 * flags reports Read Only on a later bare resume, which looks like rehydration
	 * and isn't.)
	 *
	 * Scanned backward — rollouts run to megabytes, so the head is never read.
	 */
	public function codexRolloutContext(string $rolloutPath): array {
		$ctx = ['model' => null, 'sandbox' => null, 'approval' => null, 'effort' => null];

		$this->eachLineReverse($rolloutPath, function (string $line) use (&$ctx) {
			$rec = json_decode(trim($line), true);
			if (!is_array($rec) || ($rec['type'] ?? '') !== 'turn_context') {
				return true;
			}
			$pl  = $rec['payload'] ?? [];
			$ctx = [
				'model'    => $pl['model'] ?? ($pl['settings']['model'] ?? null),
				'sandbox'  => $pl['sandbox_policy']['type'] ?? null,
				'approval' => $pl['approval_policy'] ?? null,
				'effort'   => $pl['reasoning_effort'] ?? ($pl['settings']['reasoning_effort'] ?? null),
			];
			return false; // last one wins; stop at the first hit scanning backward
		});

		return $ctx;
	}

	/**
	 * Live codex sessions keyed by pid — the codex counterpart of
	 * loadClaudeSessionsByPid().
	 * [ pid => [session_id, cwd, surface_id, model, opts] ].
	 * A codex with no open rollout (starting up, or a subcommand that slipped the
	 * pre-filter) is skipped: there's nothing to resume.
	 *
	 * The session is the process's OWN thread, not whichever rollout lsof happens to list
	 * first — see ownRolloutPathFromLsof(); a codex with a subagent running holds several
	 * open, and a subagent thread is not a session anyone can sit in.
	 */
	public function loadCodexSessionsByPid(): array {
		$proc = $this->parseProcTable($this->psProcTable());
		$out  = [];
		foreach ($this->codexProcPids($proc) as $pid) {
			$lsof = $this->lsofForPid($pid);
			$path = $this->ownRolloutPathFromLsof($lsof);
			if ($path === null) { continue; }
			$sid = $this->rolloutUuidFromPath($path);
			if ($sid === null) { continue; }
			$ctx = $this->codexRolloutContext($path);
			$out[$pid] = [
				'session_id' => $sid,
				'cwd'        => $this->codexSessionCwd($path) ?? $this->parseLsofCwd($lsof) ?? '',
				'surface_id' => $this->parseSurfaceIdFromEnv($this->pidEnv($pid)),
				'model'      => $ctx['model'],
				'opts'       => [
					'sandbox'  => $ctx['sandbox'],
					'approval' => $ctx['approval'],
					'effort'   => $ctx['effort'],
				],
			];
		}
		return $out;
	}

	/**
	 * PURE. surface UUID => [surface_ref, workspace_ref, tty, title, type] over a
	 * cmux tree. tree() already requests --id-format both, so no extra shell call.
	 */
	public function mapSurfaceUuids(array $tree): array {
		$map = [];
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				foreach ($ws['panes'] ?? [] as $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$id = $surf['id'] ?? null;
						if (!$id) { continue; }
						$map[$id] = [
							'surface_ref'   => $surf['ref'] ?? '',
							'workspace_ref' => $ws['ref'] ?? '',
							'pane_ref'      => $pane['ref'] ?? '',
							'tty'           => $surf['tty'] ?? '',
							'title'         => $surf['title'] ?? '',
							'type'          => $surf['type'] ?? 'terminal',
						];
					}
				}
			}
		}
		return $map;
	}

	/**
	 * PURE. Bind each live codex session to its cmux surface by CMUX_SURFACE_ID.
	 * Emits the SAME row shape as joinSessionsToSurfaces() (plus agent => 'codex')
	 * so CmuxBak reads both agents through one code path.
	 *
	 * Ambiguity mirrors the Claude join and always yields targetable=false with a
	 * reason, never a guess: no CMUX_SURFACE_ID (not inside a cmux surface), an id
	 * absent from the tree (surface closed), or two sessions claiming one surface.
	 *
	 * @param array $codexSessions  pid => [session_id, cwd, surface_id]  (loadCodexSessionsByPid)
	 * @param array $surfaceUuids   uuid => [surface_ref, workspace_ref, tty, title] (mapSurfaceUuids)
	 */
	public function joinCodexToSurfaces(array $codexSessions, array $surfaceUuids): array {
		$rows    = [];
		$claimed = []; // surface_ref => [session_id,...]

		foreach ($codexSessions as $pid => $s) {
			$row = [
				'session_id'    => $s['session_id'] ?? null,
				'pid'           => (int) $pid,
				'cwd'           => $s['cwd'] ?? '',
	'model'         => $s['model'] ?? null,
				// Claude's skip_perms has no codex analogue; codex expresses the
				// same idea through sandbox/approval, which ride in opts.
				'skip_perms'    => false,
				'opts'          => $s['opts'] ?? [],
				'surface_ref'   => '',
				'workspace_ref' => '',
				'tty'           => '',
				'title'         => '',
				'targetable'    => false,
				'reason'        => '',
				'agent'         => 'codex',
			];

			$surfaceId = $s['surface_id'] ?? null;
			if (!$surfaceId) {
				$row['reason'] = 'no CMUX_SURFACE_ID (not running in a cmux surface)';
				$rows[] = $row; continue;
			}
			if (!isset($surfaceUuids[$surfaceId])) {
				$row['reason'] = 'CMUX_SURFACE_ID not found among cmux surfaces';
				$rows[] = $row; continue;
			}

			$d = $surfaceUuids[$surfaceId];
			$row['surface_ref']   = $d['surface_ref'];
			$row['workspace_ref'] = $d['workspace_ref'];
			$row['tty']           = $d['tty'];
			$row['title']         = $d['title'];
			$row['targetable']    = true;
			$claimed[$d['surface_ref']][] = $row['session_id'];
			$rows[] = $row;
		}

		foreach ($rows as &$r) {
			$ref = $r['surface_ref'];
			if ($ref && count(array_unique($claimed[$ref] ?? [])) > 1) {
				$r['targetable'] = false;
				$r['reason']     = 'surface claimed by multiple codex sessions (collision)';
			}
		}
		unset($r);

		return $rows;
	}

	/**
	 * The relaunch command for a session, dispatched on agent. Wraps rather than
	 * replaces buildResumeCommand() so graveyard's callers stay put.
	 *
	 * $opts carries agent-specific knobs with no Claude equivalent — for codex,
	 * sandbox/approval/effort. skip_perms is Claude-only and never reaches codex.
	 */
	public function buildAgentResumeCommand(string $agent, string $sessionId, bool $skipPerms = false, ?string $model = null, array $opts = []): string {
		if ($agent === 'codex') {
			return $this->buildCodexResumeCommand($sessionId, $model, $opts);
		}
		return $this->buildResumeCommand($sessionId, $skipPerms, $model);
	}

	/**
	 * `codex resume <uuid>` replaying the session's recorded context.
	 *
	 * The flags are NOT redundant with the rollout: resume re-reads
	 * ~/.codex/config.toml rather than rehydrating turn_context — measured, a
	 * read-only session resumed bare came back danger-full-access. Omitting a flag
	 * we don't know still falls back to config, which is the old behaviour; what we
	 * must never do is quietly widen a sandbox.
	 *
	 * Values originate in a file on disk and land on a shell command line, so each
	 * is whitelisted to a plain token and anything else is dropped, not quoted.
	 */
	public function buildCodexResumeCommand(string $sessionId, ?string $model, array $opts = []): string {
		$safe = fn($v) => is_string($v) && $v !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $v) ? $v : null;

		// Flags go BEFORE the session id, matching the documented
		// `codex resume [OPTIONS] [SESSION_ID] [PROMPT]` shape — the positional is
		// what the parser is lenient about, not the options.
		$flags = '';
		if ($m = $safe($model)) {
			$flags .= " --model={$m}";
		}
		if ($s = $safe($opts['sandbox'] ?? null)) {
			$flags .= " --sandbox={$s}";
		}
		if ($a = $safe($opts['approval'] ?? null)) {
			$flags .= " --ask-for-approval={$a}";
		}
		if ($e = $safe($opts['effort'] ?? null)) {
			// reasoning effort has no dedicated flag; it's a config override.
			$flags .= " -c model_reasoning_effort=\"{$e}\"";
		}
		return "codex resume{$flags} {$sessionId}";
	}

	/**
	 * Transcript path for a session, dispatched on agent — what "is this still
	 * resumable?" is decided on. Claude's is derivable from (id, cwd); codex's
	 * must be globbed by id, so this can return null where jsonlPathFor() always
	 * returns a (possibly nonexistent) path.
	 */
	public function transcriptPathFor(string $agent, string $sessionId, string $cwd): ?string {
		if ($agent === 'codex') {
			return $this->codexRolloutPathFor($sessionId);
		}
		return $cwd !== '' ? $this->jsonlPathFor($sessionId, $cwd) : null;
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
	 * PURE. Where a workspace ref actually IS, phrased so it can be found on screen:
	 * `"levamo cloudflare setup" (window 1, workspace 2 of 7, workspace:27)`. A bare
	 * "workspace:27" is an internal handle — it says nothing about where to look, so
	 * user-facing messages name the workspace and its 1-based sidebar slot instead.
	 * The window clause is dropped when there's only one window (no information in it).
	 * Ref not in the tree: falls back to `"$fallbackTitle" (ref)`, or the bare ref.
	 */
	public function describeWorkspaceRef(array $tree, string $handle, string $fallbackTitle = ''): string {
		$windows = array_values($tree['windows'] ?? []);
		foreach ($windows as $wi => $window) {
			$spaces = array_values($window['workspaces'] ?? []);
			foreach ($spaces as $i => $ws) {
				// Ref OR uuid, same reason as findWorkspaceByRef: callers holding a uuid
				// otherwise fell through to the fallback and printed a bare uuid at the
				// user, which is exactly the internal handle this function exists to hide.
				if ((string) ($ws['ref'] ?? '') !== $handle && (string) ($ws['id'] ?? '') !== $handle) { continue; }
				return sprintf('"%s" (%sworkspace %d of %d, %s)',
					$this->stripGlyph((string) ($ws['title'] ?? '')),
					count($windows) > 1 ? sprintf('window %d, ', $wi + 1) : '',
					$i + 1, count($spaces), $ws['ref'] ?? $handle);
			}
		}
		return $fallbackTitle !== '' ? sprintf('"%s" (%s)', $this->stripGlyph($fallbackTitle), $handle) : $handle;
	}

	/** Live-tree wrapper around describeWorkspaceRef(). */
	public function describeWorkspace(string $handle, string $fallbackTitle = ''): string {
		return $this->describeWorkspaceRef($this->tree(), $handle, $fallbackTitle);
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
				// Tagged so CmuxBak can merge these rows with joinCodexToSurfaces()
				// output and read both through one code path. 'opts' holds
				// agent-specific knobs; Claude expresses everything it needs through
				// model + skip_perms, so it stays empty here.
				'agent'         => 'claude',
				'opts'          => [],
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
				escapeshellcmd($this->cmuxBin()) . ' send --surface ' . escapeshellarg($surfRef)
				. ' --workspace ' . escapeshellarg($wsRef)
				. ' ' . escapeshellarg($text)
				. ' 2>/dev/null'
			);
		}
	}

	public function sendKeyToSurface(string $surfRef, string $wsRef, string $key): void {
		if (!$this->dryRun) {
			shell_exec(
				escapeshellcmd($this->cmuxBin()) . ' send-key --surface ' . escapeshellarg($surfRef)
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
					escapeshellcmd($this->cmuxBin()) . ' move-surface --surface ' . escapeshellarg($surfRef)
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

		$cmd = escapeshellcmd($this->cmuxBin()) . ' new-surface --type ' . escapeshellarg($type)
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
		$cmd = escapeshellcmd($this->cmuxBin()) . ' new-split ' . escapeshellarg($direction)
			. ' --surface ' . escapeshellarg($fromSurfRef)
			. ' --workspace ' . escapeshellarg($wsRef);
		shell_exec($cmd . ' 2>/dev/null');
		usleep(400000);

		return $this->firstNewSurface($wsRef, $before);
	}

	/** PURE. Whether a window with this ref is present in the given tree. */
	public function windowRefExists(array $tree, string $ref): bool {
		if ($ref === '') { return false; }
		foreach ($tree['windows'] ?? [] as $w) {
			if (($w['ref'] ?? '') === $ref) { return true; }
		}
		return false;
	}

	/**
	 * Create a workspace and return handles into the one we ACTUALLY created.
	 *
	 * Identified by diffing workspace uuids across the call, not by
	 * findWorkspaceByTitle(): titles are not unique, and resolving by title returned
	 * the FIRST match — so when a workspace with this title already existed (e.g. the
	 * workspace a buried tab had lived inside), callers got that pre-existing
	 * workspace and its panes[0].surfaces[0]. graveyard then typed a launch command
	 * into a stranger's surface, which in one case was a running Claude Code REPL.
	 */
	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): array {
		$before = [];
		foreach ($this->tree()['windows'] ?? [] as $w) {
			foreach ($w['workspaces'] ?? [] as $ws) {
				if (!empty($ws['id'])) { $before[$ws['id']] = true; }
			}
		}

		$cmd = escapeshellcmd($this->cmuxBin()) . ' new-workspace --name ' . escapeshellarg($title);
		if ($cwd) { $cmd .= ' --cwd ' . escapeshellarg($cwd); }
		if ($windowRef) { $cmd .= ' --window ' . escapeshellarg($windowRef); }
		$res = $this->cli->getCommandOutputAndExitCode($cmd);
		if ($res['exitCode'] !== 0) { $this->cli->exitErr('new-workspace failed: ' . $res['error']); }
		usleep(500000);

		$ws = $this->firstNewWorkspace($this->tree(), $before, $title);
		if (!$ws) { $this->cli->exitErr("Could not find the workspace just created for '{$title}'."); }
		return [
			'ref'          => $ws['ref'] ?? '',
			'id'           => $ws['id'] ?? null,
			'firstPaneRef' => $ws['panes'][0]['ref'] ?? null,
			'firstSurfRef' => $ws['panes'][0]['surfaces'][0]['ref'] ?? null,
		];
	}

	/**
	 * PURE. The workspace present now, absent from $beforeIds, and carrying $title —
	 * i.e. the one this process just created. Title still has to match so a workspace
	 * someone else opened concurrently isn't mistaken for ours.
	 */
	public function firstNewWorkspace(array $tree, array $beforeIds, string $title): ?array {
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				$id = $ws['id'] ?? '';
				if ($id === '' || isset($beforeIds[$id])) { continue; }
				if (($ws['title'] ?? '') !== $title) { continue; }
				return $ws;
			}
		}
		return null;
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
			escapeshellcmd($this->cmuxBin()) . ' layout save ' . escapeshellarg($name) . ' --workspace ' . escapeshellarg($wsRef) . ' --overwrite'
		);
		if (($save['exitCode'] ?? 1) !== 0) { return null; }

		$get = $this->cli->getCommandOutputAndExitCode(escapeshellcmd($this->cmuxBin()) . ' layout get ' . escapeshellarg($name));
		$this->cli->getCommandOutputAndExitCode(escapeshellcmd($this->cmuxBin()) . ' layout delete ' . escapeshellarg($name));
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
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array {
		if ($this->dryRun) { return null; }

		$cmd = escapeshellcmd($this->cmuxBin()) . ' new-workspace --name ' . escapeshellarg($title)
			. ' --layout ' . escapeshellarg((string) json_encode($layoutTree));
		if ($cwd) { $cmd .= ' --cwd ' . escapeshellarg($cwd); }
		if ($windowRef) { $cmd .= ' --window ' . escapeshellarg($windowRef); }

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

	/**
	 * Find a workspace by positional ref OR stable UUID.
	 *
	 * Both, because cmux itself accepts either anywhere a workspace handle is taken,
	 * so callers legitimately hold one or the other — and anything that outlives a
	 * single command SHOULD hold the uuid, since refs get reassigned. Matching only
	 * `ref` made every uuid caller silently get null: createSurface() then saw an
	 * empty before-map, failed to spot the surface cmux had just created for it, and
	 * reported failure after succeeding — leaving a stray tab behind and sending the
	 * caller down its fallback path.
	 */
	public function findWorkspaceByRef(array $tree, string $handle): ?array {
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				if (($ws['ref'] ?? '') === $handle || ($ws['id'] ?? '') === $handle) {
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
