<?php
namespace JT\Helpers;

# =============================================================================
# Cmux — shared cmux + Claude-session machinery for bin/cmux-bak and bin/graveyard.
# Extracted from bin/cmux-bak (v0.1.0). Behavior-preserving.
# =============================================================================

class Cmux {

	use TitleGlyphTrait;
	use ReverseLinesTrait;

	// Aliases; the values live with the artifact reader that owns them.
	const SESSIONS_DIR       = AgentArtifacts::SESSIONS_DIR;
	const CODEX_SESSIONS_DIR = AgentArtifacts::CODEX_SESSIONS_DIR;

	/**
	 * codex subcommands that never own a cmux surface: the VS Code extension's
	 * `app-server`, headless `exec`, and the various one-shot utilities. Only an
	 * interactive TUI is a session cmux-bak can back up and resume.
	 */
	const CODEX_NON_TUI_SUBCOMMANDS = AgentArtifacts::CODEX_NON_TUI_SUBCOMMANDS;

	protected $cli;
	protected $dryRun;

	/**
	 * OS process/tty/lsof primitives. The methods below that forward here used to
	 * have their bodies in this class; cmux-bak still calls them off Cmux, so the
	 * forwarders stay until it moves onto Proc directly.
	 */
	protected Proc $proc;

	/**
	 * Claude/Codex on-disk artifacts and argv semantics. Same story as $proc: the
	 * bodies used to live here, and cmux-bak still calls a few off Cmux, so the
	 * forwarders stay until it moves onto AgentArtifacts directly.
	 */
	protected AgentArtifacts $artifacts;

	/** pid => CMUX_SURFACE_ID|null, memoised (see surfaceIdForPid). */
	protected array $surfaceIdByPid = [];

	public function __construct($cli, bool $dryRun = false, ?Proc $proc = null, ?AgentArtifacts $artifacts = null) {
		$this->cli    = $cli;
		$this->dryRun = $dryRun;
		$this->proc   = $proc ?: new Proc($cli);
		$this->artifacts = $artifacts ?: new AgentArtifacts($cli, $this->proc);
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
		return $this->artifacts->encodeProjectKey($cwd);
	}


	public function jsonlPathFor(string $sessionId, string $cwd): string {
		return $this->artifacts->jsonlPathFor($sessionId, $cwd);
	}


	public function claudeSessionsDir(): string {
		return $this->artifacts->claudeSessionsDir();
	}


	public function loadClaudeSessions(): array {
		return $this->artifacts->loadClaudeSessions();
	}


	public function pidIsAlive(int $pid) {
		return $this->proc->pidIsAlive($pid);
	}

	public function getTtyForPid(int $pid) {
		return $this->proc->getTtyForPid($pid);
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
		return $this->proc->psProcTable();
	}

	/**
	 * PURE. Parse `ps -Ao pid,ppid,command` into [ pid => ['ppid'=>int,'cmd'=>string] ].
	 */
	public function parseProcTable(string $raw): array {
		return $this->proc->parseProcTable($raw);
	}

	/** PURE. Children index: [ ppid => [pid,...] ]. */
	public function childIndex(array $proc): array {
		return $this->proc->childIndex($proc);
	}

	public function isClaudeCommand(string $cmd): bool {
		return $this->artifacts->isClaudeCommand($cmd);
	}


	public function descendantClaudePid(array $proc, int $root): ?int {
		return $this->artifacts->descendantClaudePid($proc, $root);
	}


	/** PURE. All descendant pids of $root (inclusive) — used to kill a claude + its subagents. */
	public function descendantPids(array $proc, int $root): array {
		return $this->proc->descendantPids($proc, $root);
	}

	public function ancestorResumeScript(array $proc, int $pid): ?string {
		return $this->artifacts->ancestorResumeScript($proc, $pid);
	}


	public function claudeResumeArg(string $cmd): ?string {
		return $this->artifacts->claudeResumeArg($cmd);
	}


	public function cmdHasSkipPerms(string $cmd): bool {
		return $this->artifacts->cmdHasSkipPerms($cmd);
	}


	/** The live process argv for a pid (empty string if the pid is gone). */
	public function pidCommand(int $pid): string {
		return $this->proc->pidCommand($pid);
	}

	public function resolveSkipPerms(?string $permissionMode, ?int $pid): bool {
		return $this->artifacts->resolveSkipPerms($permissionMode, $pid);
	}


	public function cmdModelArg(string $cmd): ?string {
		return $this->artifacts->cmdModelArg($cmd);
	}


	public function resolveModel(?string $jsonlModel, ?int $pid): ?string {
		return $this->artifacts->resolveModel($jsonlModel, $pid);
	}


	/**
	 * Load active Claude sessions keyed by pid (companion to loadClaudeSessions,
	 * which keys by tty). [ pid => [session_id, cwd, skip_perms, model, status] ].
	 */
	public function loadClaudeSessionsByPid(): array {
		$dir = $this->claudeSessionsDir();
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
				// The surface this session sits in, straight from cmux's own
				// per-process env — what joinSessionsToSurfaces() binds on when
				// there is no resume script to bridge through.
				'surface_id' => $this->surfaceIdForPid((int) $pid),
			];
		}
		return $out;
	}

	/**
	 * CMUX_SURFACE_ID of a live pid, or null when the process isn't inside a cmux
	 * surface. Memoised per pid: the join reads it for every live session and the
	 * lookup costs a `ps` fork each.
	 */
	public function surfaceIdForPid(int $pid): ?string {
		if (!array_key_exists($pid, $this->surfaceIdByPid)) {
			$this->surfaceIdByPid[$pid] = $this->parseSurfaceIdFromEnv($this->pidEnv($pid));
		}
		return $this->surfaceIdByPid[$pid];
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

	public function codexSessionsDir(): string {
		return $this->artifacts->codexSessionsDir();
	}


	public function isCodexCommand(string $cmd): bool {
		return $this->artifacts->isCodexCommand($cmd);
	}


	public function codexSubcommand(string $cmd): ?string {
		return $this->artifacts->codexSubcommand($cmd);
	}


	public function isCodexNonTuiCommand(string $cmd): bool {
		return $this->artifacts->isCodexNonTuiCommand($cmd);
	}


	public function codexProcPids(array $proc): array {
		return $this->artifacts->codexProcPids($proc);
	}


	/** Raw `lsof -p <pid>` output — yields the open rollout AND the cwd in one call. */
	public function lsofForPid(int $pid): string {
		return $this->proc->lsofForPid($pid);
	}

	/**
	 * Raw `ps -wwEp <pid>`. NOTE: -E appends the environment to the command
	 * column on the same line, so this output carries every env var of the
	 * process — including CMUX_SOCKET_CAPABILITY, a live auth token. Feed it
	 * straight to parseSurfaceIdFromEnv() and never log or persist it.
	 */
	public function pidEnv(int $pid): string {
		return $this->proc->pidEnv($pid);
	}

	public function parseLsofRolloutPath(string $raw): ?string {
		return $this->artifacts->parseLsofRolloutPath($raw);
	}


	public function parseLsofRolloutPaths(string $raw): array {
		return $this->artifacts->parseLsofRolloutPaths($raw);
	}


	public function ownRolloutPathFromLsof(string $raw): ?string {
		return $this->artifacts->ownRolloutPathFromLsof($raw);
	}


	public function ownSessionIdFromLsof(string $raw): ?string {
		return $this->artifacts->ownSessionIdFromLsof($raw);
	}


	public function codexSessionIdForPid(int $pid): ?string {
		return $this->artifacts->codexSessionIdForPid($pid);
	}


	public function parseLsofRollout(string $raw): ?string {
		return $this->artifacts->parseLsofRollout($raw);
	}


	public function rolloutUuidFromPath(string $path): ?string {
		return $this->artifacts->rolloutUuidFromPath($path);
	}


	/** PURE. The process working directory from an lsof dump (the FD=cwd row), or null. */
	public function parseLsofCwd(string $raw): ?string {
		return $this->proc->parseLsofCwd($raw);
	}

	/** PURE. CMUX_SURFACE_ID out of a `ps -wwEp` dump (and nothing else from it), or null. */
	public function parseSurfaceIdFromEnv(string $raw): ?string {
		return preg_match('/\bCMUX_SURFACE_ID=([0-9A-Fa-f-]{36})\b/', $raw, $m) ? $m[1] : null;
	}

	public function codexSessionCwd(string $rolloutPath): ?string {
		return $this->artifacts->codexSessionCwd($rolloutPath);
	}


	public function codexRolloutPathFor(string $sessionId): ?string {
		return $this->artifacts->codexRolloutPathFor($sessionId);
	}


	public function codexRolloutContext(string $rolloutPath): array {
		return $this->artifacts->codexRolloutContext($rolloutPath);
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

	/** Codex process pid => CMUX_SURFACE_ID, including fresh zero-turn sessions with no rollout yet. */
	public function codexSurfaceIdsByPid(): array {
		$proc = $this->parseProcTable($this->psProcTable());
		$out  = [];
		foreach ($this->codexProcPids($proc) as $pid) {
			$surfaceId = $this->parseSurfaceIdFromEnv($this->pidEnv($pid));
			if ($surfaceId !== null) { $out[$pid] = $surfaceId; }
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

	public function buildAgentResumeCommand(string $agent, string $sessionId, bool $skipPerms = false, ?string $model = null, array $opts = []): string {
		return $this->artifacts->buildAgentResumeCommand($agent, $sessionId, $skipPerms, $model, $opts);
	}


	public function buildCodexResumeCommand(string $sessionId, ?string $model, array $opts = []): string {
		return $this->artifacts->buildCodexResumeCommand($sessionId, $model, $opts);
	}


	public function transcriptPathFor(string $agent, string $sessionId, string $cwd): ?string {
		return $this->artifacts->transcriptPathFor($agent, $sessionId, $cwd);
	}


	public function uuidv4(): string {
		return $this->artifacts->uuidv4();
	}


	public function normalizeTitle(string $title): string {
		return $this->artifacts->normalizeTitle($title);
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
				// Exact ref OR stable UUID — cmux's "Copy Ids" hands over workspace_id=<uuid>,
				// so a labelled paste resolves the same as the positional ref.
				if ($ref === $nameOrRef || (($ws['id'] ?? '') !== '' && $ws['id'] === $nameOrRef)) {
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
		return $this->artifacts->claudeSessionIdForPid($pid);
	}

	/**
	 * PURE. Deterministically bind each live Claude session to its cmux surface via
	 * process ancestry, tty-free. Returns rows:
	 *   [ session_id, pid, cwd, model, skip_perms, surface_ref, workspace_ref, tty,
	 *     title, targetable(bool), reason(string), no_bridge(bool) ]
	 *
	 * A session binds through whichever of two exact bridges is available:
	 *
	 *   1. the unique resume-script path a surface launched, found in an ancestor's
	 *      args (graveyard/cmux-bak resurrections), or
	 *   2. CMUX_SURFACE_ID from the session process's own environment, matched
	 *      against the tree's per-surface `id` — the same bridge the codex join
	 *      uses, and the ONLY one available for a Claude that cmux started itself.
	 *
	 * Bridge 2 is not a nicety: cmux launches Claude directly (`claude
	 * [--session-id <uuid>] --settings {…}` under a bare login zsh) and writes no
	 * resume script, so a script-only join binds none of those sessions — it bound
	 * 0 of 33 live ones (dotfiles-dr9). Both bridges also cover the shapes JT
	 * launches by hand: --resume, --session-id, and a fresh session with no session
	 * flag at all, wrapper shims included.
	 *
	 * Any ambiguity (no bridge, unknown surface, script shared by >1 surface, >1
	 * session on a surface, a script bridge whose --resume arg disagrees with the
	 * session id) yields targetable=false with a reason — never a guess.
	 *
	 * @param array $sessions      pid => [session_id,cwd,model,skip_perms,surface_id,...]
	 * @param array $proc          pid => [ppid,cmd]              (parseProcTable)
	 * @param array $debug         surface_ref => [tty,cwd,workspace_ref,title,script] (parseDebugTerminals)
	 * @param array $surfaceUuids  uuid => [surface_ref,workspace_ref,tty,title] (mapSurfaceUuids)
	 */
	public function joinSessionsToSurfaces(array $sessions, array $proc, array $debug, array $surfaceUuids = []): array {
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
				// True only when NEITHER bridge had anything to say: no resume-script
				// ancestor and no CMUX_SURFACE_ID. That is the one state where a
				// screen-scraping fallback (Graveyard's content probe) may still guess a
				// surface. A session naming a surface that has since CLOSED is not this
				// state — it is somewhere else, and binding it by on-screen cwd would be
				// wrong. Flagged rather than sniffed out of `reason`: a prefix match on
				// that human-facing string is what silently stopped firing the moment the
				// reasons grew an env clause.
				'no_bridge'     => false,
			];

			// Bridge 1: the resume script an ancestor launched.
			$ref    = null;
			$reason = '';
			$script = $this->ancestorResumeScript($proc, (int) $pid);
			if ($script !== null) {
				$surfaces = $scriptSurfaces[$script] ?? [];
				if (count($surfaces) === 1) {
					$ref = $surfaces[0];
				} elseif (count($surfaces) === 0) {
					$reason = 'resume script not found among cmux surfaces';
				} else {
					$reason = 'resume script shared by ' . count($surfaces) . ' surfaces (ambiguous)';
				}
			}

			// Bridge 2: CMUX_SURFACE_ID, the only bridge a cmux-launched Claude has.
			// A resume script that pinned a surface wins; anything less falls through
			// to the env, which is exact rather than merely name-matched.
			$d      = $ref !== null ? ($debug[$ref] ?? []) : [];
			$viaEnv = false;
			if ($ref === null) {
				$surfaceId = $s['surface_id'] ?? null;
				if ($surfaceId !== null && isset($surfaceUuids[$surfaceId])) {
					$ref    = $surfaceUuids[$surfaceId]['surface_ref'] ?? '';
					$d      = $surfaceUuids[$surfaceId];
					$viaEnv = true;
					if ($ref === '') { $ref = null; $viaEnv = false; }
				}
			}

			if ($ref === null) {
				$surfaceId = $s['surface_id'] ?? null;
				if ($reason === '') {
					$reason = $surfaceId === null
						? 'no CMUX_SURFACE_ID and no resume-script ancestor (not running in a cmux surface)'
						: 'CMUX_SURFACE_ID not found among cmux surfaces (surface closed)';
				}
				$row['no_bridge'] = $script === null && $surfaceId === null;
				$row['reason']    = $reason;
				$rows[] = $row; continue;
			}

			// Integrity check for the SCRIPT bridge only: the script filename embeds the
			// uuid it was written to resume, so a --resume arg that disagrees with the
			// session id means the pairing is stale — bind nothing.
			//
			// It must NOT gate the env bridge. --resume records how the process was
			// LAUNCHED, and Claude forks a new session id when it resumes a transcript
			// another live session already holds: measured, a pr-swarm orchestrator
			// launched `--resume 53385818…` was reported by its own pid file as
			// b603bfcb…, both transcripts live and growing. Trusting argv there would
			// stamp the OTHER session's id onto this surface, so the pid file wins and
			// CMUX_SURFACE_ID (exact, cmux's own) needs no corroboration.
			$resumeArg = $viaEnv ? null : $this->claudeResumeArg($proc[$claude]['cmd'] ?? '');
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
		return $this->proc->getCwdForTty($tty);
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

	/** Read the visible terminal buffer without sending input. */
	public function readScreen(string $surfRef, string $wsRef): string {
		return (string) shell_exec(
			escapeshellcmd($this->cmuxBin()) . ' read-screen --surface ' . escapeshellarg($surfRef)
			. ' --workspace ' . escapeshellarg($wsRef) . ' 2>/dev/null'
		);
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

	/** [pane_ref => true] for every pane in a workspace (current tree). */
	private function paneRefSet(string $wsRef): array {
		$ws  = $this->findWorkspaceByRef($this->tree(), $wsRef);
		$set = [];
		foreach ($ws['panes'] ?? [] as $pane) {
			if (!empty($pane['ref'])) { $set[$pane['ref']] = true; }
		}
		return $set;
	}

	/**
	 * Add a pane to a workspace, returning [pane_ref, surface_ref] for the pane and
	 * the terminal surface cmux opens inside it (null if either can't be identified).
	 *
	 * $direction is the caller's choice, not a recorded fact: cmux's `system.tree`
	 * reports panes as a flat list with no orientation or divider ratio, so a
	 * rebuild from tree data has no geometry to honour. (`layout get` does expose the
	 * real split tree — see captureLayoutTree() — for callers that captured a layout
	 * while the workspace was alive.)
	 */
	public function newPane(string $wsRef, string $direction = 'right'): ?array {
		if ($this->dryRun) { return null; }

		$panesBefore = $this->paneRefSet($wsRef);
		$surfsBefore = $this->surfacePaneMap($wsRef);

		$this->cli->getCommandOutputAndExitCode(
			escapeshellcmd($this->cmuxBin()) . ' new-pane --type terminal'
			. ' --direction ' . escapeshellarg($direction)
			. ' --workspace ' . escapeshellarg($wsRef)
		);
		usleep(400000);

		$newPaneRef = null;
		foreach (array_keys($this->paneRefSet($wsRef)) as $ref) {
			if (!isset($panesBefore[$ref])) { $newPaneRef = $ref; break; }
		}
		if ($newPaneRef === null) { return null; }

		return [
			'pane_ref'    => $newPaneRef,
			'surface_ref' => $this->firstNewSurface($wsRef, $surfsBefore),
		];
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
		$ws = $this->newWorkspaceOrNull($title, $cwd, $windowRef);
		if (!$ws) { $this->cli->exitErr("Could not create the workspace '{$title}'."); }

		return $ws;
	}

	/**
	 * newWorkspace() without the exit: returns null when the create fails or the new
	 * workspace can't be identified, for callers mid-loop that must carry on with the
	 * rest of their work instead of taking the whole run down.
	 */
	public function newWorkspaceOrNull(string $title, ?string $cwd, ?string $windowRef = null): ?array {
		$before = [];
		foreach ($this->tree()['windows'] ?? [] as $w) {
			foreach ($w['workspaces'] ?? [] as $ws) {
				if (!empty($ws['id'])) { $before[$ws['id']] = true; }
			}
		}

		$cmd = escapeshellcmd($this->cmuxBin()) . ' workspace create --name ' . escapeshellarg($title);
		if ($cwd) { $cmd .= ' --cwd ' . escapeshellarg($cwd); }
		if ($windowRef) { $cmd .= ' --window ' . escapeshellarg($windowRef); }
		$res = $this->cli->getCommandOutputAndExitCode($cmd);
		if ($res['exitCode'] !== 0) {
			$this->cli->err('workspace create failed: ' . $res['error']);

			return null;
		}
		usleep(500000);

		$ws = $this->firstNewWorkspace($this->tree(), $before, $title);
		if (!$ws) {
			$this->cli->err("Could not find the workspace just created for '{$title}'.");

			return null;
		}

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
	public function captureLayoutTree(string $wsRef, string $namePrefix = 'cmux-capture'): ?array {
		if ($this->dryRun) { return null; }

		$name = $namePrefix . '-' . bin2hex(random_bytes(4));
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
	 * PURE. A layout tree's leaf panes in depth-first order, each as its ordered
	 * `surfaces[]` (the pane's tab stack). This is the join key between a layout tree
	 * and any flat pane list: cmux's DFS leaf order matches `tree`'s panes[] order, so
	 * callers zip the two positionally — after checking the shapes agree, since cmux
	 * drops surface types it can't express in a layout (agent-session, markdown, …).
	 */
	public function layoutTreePanes(array $node): array {
		if (isset($node['pane'])) { return [array_values($node['pane']['surfaces'] ?? [])]; }

		$panes = [];
		foreach ($node['children'] ?? [] as $child) {
			foreach ($this->layoutTreePanes($child) as $pane) { $panes[] = $pane; }
		}
		return $panes;
	}

	/** PURE. Total surfaces across a layout tree's panes. */
	public function layoutTreeSurfaceCount(array $node): int {
		return array_sum(array_map('count', $this->layoutTreePanes($node)));
	}

	/**
	 * PURE. Copy of a captured layout tree with $dropSurfaceKeys removed from every
	 * surface. `command` always goes: cmux records what a surface was launched with,
	 * and replaying that would re-run it (double-launching an agent) when every caller
	 * here drives its own launches afterwards. Callers that also apply cwds themselves
	 * drop `cwd` too, leaving pure geometry. Geometry, type and url are never touched.
	 */
	public function sanitizeLayoutTree(array $node, array $dropSurfaceKeys = ['command']): array {
		if (isset($node['pane'])) {
			$surfaces = [];
			foreach ($node['pane']['surfaces'] ?? [] as $surface) {
				foreach ($dropSurfaceKeys as $key) { unset($surface[$key]); }
				$surfaces[] = $surface;
			}
			$node['pane']['surfaces'] = $surfaces;

			return $node;
		}

		if (isset($node['children'])) {
			$node['children'] = array_map(fn($child) => $this->sanitizeLayoutTree($child, $dropSurfaceKeys), $node['children']);
		}

		return $node;
	}

	/**
	 * Create a workspace from a cmux layout definition (inline JSON), rebuilding the
	 * exact splits/tabs. Returns the new workspace's full tree node (panes[].surfaces[])
	 * so the caller can join surfaces to sessions positionally, or null on failure.
	 *
	 * Identified by diffing workspace uuids across the call, for the same reason
	 * newWorkspaceOrNull() does: titles are not unique, so resolving by title returned
	 * the FIRST match and handed callers a pre-existing workspace's panes — which they
	 * then typed resume commands into.
	 */
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array {
		if ($this->dryRun) { return null; }

		$before = [];
		foreach ($this->tree()['windows'] ?? [] as $w) {
			foreach ($w['workspaces'] ?? [] as $ws) {
				if (!empty($ws['id'])) { $before[$ws['id']] = true; }
			}
		}

		$cmd = escapeshellcmd($this->cmuxBin()) . ' workspace create --name ' . escapeshellarg($title)
			. ' --layout ' . escapeshellarg((string) json_encode($layoutTree));
		if ($cwd) { $cmd .= ' --cwd ' . escapeshellarg($cwd); }
		if ($windowRef) { $cmd .= ' --window ' . escapeshellarg($windowRef); }

		$res = $this->cli->getCommandOutputAndExitCode($cmd);
		if (($res['exitCode'] ?? 1) !== 0) { return null; }
		usleep(600000);

		return $this->firstNewWorkspace($this->tree(), $before, $title);
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
		return $this->artifacts->buildResumeCommand($sessionId, $skipPerms, $model);
	}



	public function readSessionJsonl(?string $sessionId, ?string $cwd): array {
		return $this->artifacts->readSessionJsonl($sessionId, $cwd);
	}


	public function isSyntheticEntry(array $entry): bool {
		return $this->artifacts->isSyntheticEntry($entry);
	}


	public function lastRealActivity(string $sessionId, string $cwd): ?int {
		return $this->artifacts->lastRealActivity($sessionId, $cwd);
	}

}
