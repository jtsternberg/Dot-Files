<?php
namespace JT;

class Graveyard {

	// Active-turn markers Claude Code prints while a turn is running. Absence-based
	// detection (NOT prompt matching, which is fragile with custom/powerline prompts).
	const ACTIVE_TURN_RE = '/(esc to interrupt|\(\s*[\d.]+k?\s+tokens\s*\)|Cogitating|Thinking…|Pondering|Deciphering)/i';

	const IDLE_FLOOR_DEFAULT = 15; // seconds; JSONL must be quiet at least this long

	protected $cli;
	protected $cmux;

	public function __construct($cli, $cmux) {
		$this->cli  = $cli;
		$this->cmux = $cmux;
	}

	public function storeRoot(): string {
		$env = getenv('GRAVEYARD_ROOT');
		return $env ?: $this->cli->convertPathToAbsolute('~/.claude-graveyard');
	}

	public function sessionDir(string $id): string { return $this->storeRoot() . "/sessions/{$id}"; }
	public function transcriptPath(string $id): string { return $this->sessionDir($id) . '/transcript.txt'; }
	public function metaPath(string $id): string { return $this->sessionDir($id) . '/meta.json'; }
	public function workspaceGroupDir(string $group): string { return $this->storeRoot() . "/workspaces/{$group}"; }
	public function manifestPath(string $group): string { return $this->workspaceGroupDir($group) . '/manifest.json'; }
	public function indexPath(): string { return $this->storeRoot() . '/index.json'; }

	public function parseDuration(string $s): int {
		if (!preg_match('/^(\d+)([smhd]?)$/', trim($s), $m)) {
			throw new \InvalidArgumentException("Invalid duration: {$s}");
		}
		$n = (int) $m[1];
		switch ($m[2]) {
			case 'd': return $n * 86400;
			case 'h': return $n * 3600;
			case 'm': return $n * 60;
			default:  return $n; // 's' or bare
		}
	}

	public function readIndex(): array {
		$path = $this->indexPath();
		if (!file_exists($path)) {
			return ['version' => 1, 'tombstones' => []];
		}
		$data = json_decode((string) file_get_contents($path), true);
		return $data ?: ['version' => 1, 'tombstones' => []];
	}

	public function upsertIndex(array $entry): void {
		$idx = $this->readIndex();
		$idx['tombstones'] = array_values(array_filter(
			$idx['tombstones'],
			fn($t) => ($t['session_id'] ?? null) !== ($entry['session_id'] ?? null)
		));
		$idx['tombstones'][] = $entry;
		$dir = dirname($this->indexPath());
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		file_put_contents($this->indexPath(), json_encode($idx, JSON_PRETTY_PRINT));
	}

	public function buildTombstone(array $session, array $surface, string $summary, string $buriedAt, ?array $group = null): array {
		$sessionId = $session['session_id'];
		$cwd       = $session['cwd'] ?? '';
		$lastActive = null;
		$jsonl = $this->cmux->jsonlPathFor($sessionId, $cwd);
		if (is_file($jsonl)) {
			$lastActive = gmdate('Y-m-d\TH:i:s\Z', filemtime($jsonl));
		}
		$tomb = [
			'session_id'      => $sessionId,
			'workspace_title' => $surface['workspace_title'] ?? '',
			'tab_title'       => $surface['tab_title'] ?? '',
			'cwd'             => $cwd,
			'model'           => $session['model'] ?? null,
			'skip_perms'      => (bool) ($session['skip_perms'] ?? false),
			'summary'         => $summary,
			'buried_at'       => $buriedAt,
			'last_active'     => $lastActive,
		];
		// Grouped (workspace) bury: stamp the shared group id + layout position so
		// ls/resurrect can operate on the workspace as a unit, while the member
		// tombstone stays self-sufficient for single-member resurrect.
		if ($group !== null) {
			$tomb['group_id']    = $group['group_id'] ?? null;
			$tomb['group_title'] = $group['group_title'] ?? null;
			$tomb['group_pos']   = $group['group_pos'] ?? null;
		}
		return $tomb;
	}

	public function selfSurfaceId(): ?string {
		return getenv('CMUX_SURFACE_ID') ?: null;
	}

	public function selfSessionId(): ?string {
		$sid = $this->selfSurfaceId();
		if (!$sid) { return null; }
		foreach ($this->liveSessions() as $s) {
			if (($s['surface_id'] ?? null) === $sid || ($s['surface_ref'] ?? null) === $sid) {
				return $s['session_id'];
			}
		}
		return null;
	}

	public function resolveLiveBySessionId(string $sessionId): ?array {
		foreach ($this->liveSessions() as $s) {
			if ($s['session_id'] === $sessionId) { return $s; }
		}
		return null;
	}

	public function filterSelf(array $sessions, ?string $selfSurfaceId, ?string $selfSessionId): array {
		return array_values(array_filter($sessions, function ($s) use ($selfSurfaceId, $selfSessionId) {
			if ($selfSurfaceId && ($s['surface_ref'] ?? null) === $selfSurfaceId) { return false; }
			if ($selfSurfaceId && ($s['surface_id'] ?? null) === $selfSurfaceId) { return false; }
			if ($selfSessionId && ($s['session_id'] ?? null) === $selfSessionId) { return false; }
			return true;
		}));
	}

	public function isBusy(int $idleSeconds, int $idleFloor, string $lastScreen): bool {
		if ($idleSeconds < $idleFloor) { return true; }
		return (bool) preg_match(self::ACTIVE_TURN_RE, $lastScreen);
	}

	public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string {
		$cmd = 'cmux read-screen --surface ' . escapeshellarg($surfaceRef)
			 . ' --workspace ' . escapeshellarg($workspaceRef)
			 . ' --lines ' . (int) $lines . ' 2>/dev/null';
		return (string) shell_exec($cmd);
	}

	public function liveSessions(): array {
		// Deterministic session<->surface join via process ancestry (dotfiles-yt2):
		// tty numbers are recycled across live surfaces, so a tty join mis-pairs.
		$sessions = $this->cmux->loadClaudeSessionsByPid();
		$proc     = $this->cmux->parseProcTable($this->cmux->psProcTable());
		$debug    = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
		$joined   = $this->cmux->joinSessionsToSurfaces($sessions, $proc, $debug);

		// Tree supplies stable surface UUID + workspace/surface titles, keyed by ref.
		$treeIx = $this->treeIndex($this->cmux->tree());
		$now    = time();
		$out    = [];

		foreach ($joined as $j) {
			if (!$j['session_id']) { continue; }
			$ts   = $this->cmux->lastRealActivity($j['session_id'], $j['cwd']);
			$idle = $ts !== null ? ($now - $ts) : PHP_INT_MAX;
			$ref  = $j['surface_ref'];
			$out[] = [
				'session_id'      => $j['session_id'],
				'cwd'             => $j['cwd'],
				'model'           => $j['model'],
				'skip_perms'      => $j['skip_perms'],
				'pid'             => $j['pid'],
				'tty'             => $j['tty'],
				'surface_ref'     => $ref,
				'surface_id'      => $treeIx['surface'][$ref]['id'] ?? $ref,
				'workspace_ref'   => $j['workspace_ref'],
				'workspace_title' => $treeIx['workspace'][$j['workspace_ref']] ?? '',
				'tab_title'       => $treeIx['surface'][$ref]['title'] ?? $j['title'],
				'idle_seconds'    => $idle,
				'targetable'      => $j['targetable'],
				'reason'          => $j['reason'],
			];
		}

		// Second pass (dotfiles-c15): content-probe fallback for Claude sessions the
		// ancestry join left unbound (fresh / non-cmux-resumed). Bind each to a still-
		// unbound terminal surface by matching its on-screen statusline cwd, uniquely.
		$out = $this->bindUnresolvedByContentProbe($out, $debug, $treeIx);

		return $this->dedupBySessionId($out);
	}

	/**
	 * PURE. Index a cmux tree for ref-keyed lookups:
	 *   ['surface' => [surface_ref => ['id','title']], 'workspace' => [workspace_ref => title]].
	 */
	public function treeIndex(array $tree): array {
		$ix = ['surface' => [], 'workspace' => []];
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				$wref = $ws['ref'] ?? '';
				if ($wref) { $ix['workspace'][$wref] = $ws['title'] ?? ''; }
				foreach ($ws['panes'] ?? [] as $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$ref = $surf['ref'] ?? '';
						if (!$ref) { continue; }
						$ix['surface'][$ref] = [
							'id'    => $surf['id'] ?? $ref,
							'title' => $surf['title'] ?? '',
						];
					}
				}
			}
		}
		return $ix;
	}

	/**
	 * I/O wrapper for the content-probe fallback (dotfiles-c15). Finds Claude sessions
	 * the ancestry join left unbound (reason "no resume-script ancestor"), reads each
	 * still-unbound terminal surface's screen, and upgrades a row to targetable when
	 * contentProbeBind() finds it a unique cwd match.
	 */
	protected function bindUnresolvedByContentProbe(array $rows, array $debug, array $treeIx): array {
		$bound = [];
		$fresh = [];
		foreach ($rows as $i => $r) {
			if ($r['targetable']) { if ($r['surface_ref'] !== '') { $bound[$r['surface_ref']] = true; } continue; }
			if (strpos((string) $r['reason'], 'no resume-script') === 0) {
				$r['_i'] = $i;
				$r['tty'] = $this->cmux->getTtyForPid((int) $r['pid']) ?: ($r['tty'] ?? '');
				$fresh[] = $r;
			}
		}
		if (!$fresh) { return $rows; }

		// Candidate surfaces: terminal surfaces (debug-terminals lists only these) not
		// already claimed by a deterministic bind.
		$unbound = [];
		$screenByRef = [];
		foreach ($debug as $ref => $d) {
			if (isset($bound[$ref])) { continue; }
			$unbound[$ref] = ['tty' => $d['tty'] ?? '', 'workspace_ref' => $d['workspace_ref'] ?? ''];
			$screenByRef[$ref] = $this->readLastScreen($ref, $d['workspace_ref'] ?? '', 8);
		}

		$binds = $this->contentProbeBind($fresh, $unbound, $screenByRef);
		foreach ($fresh as $r) {
			$ref = $binds[$r['session_id']] ?? null;
			if (!$ref) { continue; }
			$wref = $unbound[$ref]['workspace_ref'] ?? '';
			$i = $r['_i'];
			$rows[$i]['surface_ref']     = $ref;
			$rows[$i]['surface_id']      = $treeIx['surface'][$ref]['id'] ?? $ref;
			$rows[$i]['workspace_ref']   = $wref;
			$rows[$i]['workspace_title'] = $treeIx['workspace'][$wref] ?? '';
			$rows[$i]['tab_title']       = $treeIx['surface'][$ref]['title'] ?? '';
			$rows[$i]['tty']             = $unbound[$ref]['tty'] ?? '';
			$rows[$i]['targetable']      = true;
			$rows[$i]['reason']          = 'bound via content-probe (fresh session)';
		}
		return $rows;
	}

	/**
	 * Keep the first row for each session_id, preserving order. A single Claude
	 * session can surface under multiple cmux panes/surfaces; liveSessions()
	 * builds one row per surface, so this collapses those back to one per session.
	 */
	public function dedupBySessionId(array $rows): array {
		$seen = [];
		$out  = [];
		foreach ($rows as $row) {
			$id = $row['session_id'] ?? null;
			if ($id !== null && isset($seen[$id])) {
				continue;
			}
			if ($id !== null) {
				$seen[$id] = true;
			}
			$out[] = $row;
		}
		return $out;
	}

	public function exportTranscript(array $sess, int $timeoutSecs = 30): bool {
		$id  = $sess['session_id'];
		$dir = $this->sessionDir($id);
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }

		$tmp = $dir . '/.transcript.' . ($sess['pid'] ?? getmypid()) . '.tmp';
		if (file_exists($tmp)) { @unlink($tmp); }

		// /export <path> writes rendered text straight to the file (no dialog when path is writable).
		// Inside a running Claude Code TUI a sent "\n" only inserts a newline in the prompt — it does
		// not submit. Send the command text, then press Return as a real key event to submit it.
		$this->cmux->sendToSurface($sess['surface_ref'], $sess['workspace_ref'], '/export ' . $tmp);
		$this->cmux->sendKeyToSurface($sess['surface_ref'], $sess['workspace_ref'], 'Return');

		$deadline = time() + $timeoutSecs;
		$lastSize = -1;
		while (time() < $deadline) {
			clearstatcache(true, $tmp);
			if (is_file($tmp)) {
				$size = filesize($tmp);
				if ($size > 0 && $size === $lastSize) {
					return @rename($tmp, $this->transcriptPath($id));
				}
				$lastSize = $size;
			}
			usleep(400000);
		}
		@unlink($tmp);
		return false;
	}

	/**
	 * PURE: reduce a raw user-message body to a short human summary, or '' if the
	 * message is noise (empty, a tool/skill wrapper, a local-command dump).
	 *
	 * A slash-command invocation — which Claude Code records as
	 * "<command-message>..</command-message><command-name>/foo</command-name>"
	 * (optionally with <command-args>) — collapses to "/foo [args]" rather than
	 * leaking the raw tags into the tombstone summary.
	 */
	public function summarizeUserText(string $raw): string {
		$text = trim($raw);
		if ($text === '') { return ''; }

		// Slash-command invocation → "/command-name [args]".
		if (preg_match('#<command-name>\s*/?([^<]+?)\s*</command-name>#i', $text, $m)) {
			$cmd = '/' . trim($m[1]);
			if (preg_match('#<command-args>\s*(.*?)\s*</command-args>#is', $text, $a) && trim($a[1]) !== '') {
				$cmd .= ' ' . trim($a[1]);
			}
			return trim(mb_substr(preg_replace('/\s+/', ' ', $cmd), 0, 100));
		}

		// Machine-generated noise that isn't a human prompt — skip to the next entry.
		$noisePrefixes = ['<command-message>', '<local-command-stdout>', 'Base directory for this skill:', 'Caveat:'];
		foreach ($noisePrefixes as $prefix) {
			if (stripos($text, $prefix) === 0) { return ''; }
		}

		// Strip any stray tags, collapse whitespace.
		$clean = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $text)));
		if ($clean === '') { return ''; }

		return mb_substr($clean, 0, 100);
	}

	public function deriveSummary(array $sess): string {
		$jsonl = $this->cmux->jsonlPathFor($sess['session_id'], $sess['cwd'] ?? '');
		if (is_file($jsonl)) {
			$fh = fopen($jsonl, 'r');
			while (($line = fgets($fh)) !== false) {
				$e = json_decode($line, true);
				if (($e['type'] ?? '') === 'user') {
					$content = $e['message']['content'] ?? '';
					if (is_array($content)) {
						$text = '';
						foreach ($content as $c) { if (($c['type'] ?? '') === 'text') { $text = $c['text']; break; } }
						$content = $text;
					}
					if (is_string($content)) {
						$summary = $this->summarizeUserText($content);
						if ($summary !== '') {
							fclose($fh);
							return $summary;
						}
					}
				}
			}
			fclose($fh);
		}
		$title = $sess['tab_title'] ?? '';
		// strip Claude Code's leading status glyph
		return trim(preg_replace('/^[^\x00-\x7F]+\s*/u', '', $title)) ?: '(no summary)';
	}

	/** PURE. The cwd token from a Claude REPL statusline ("📁 /foo"), or null if none. */
	public function extractStatuslineCwd(string $screen): ?string {
		return preg_match('/📁\s*(\S+)/u', $screen, $m) ? $m[1] : null;
	}

	/**
	 * PURE. GATE 1 predicate: does the on-screen Claude statusline's cwd correspond to
	 * $sessionCwd? The statusline abbreviates (often just "/basename"), so we accept a
	 * basename match or a path-suffix match. Returns false when no statusline is found
	 * (i.e. the surface is not showing a Claude REPL) — that must block the bury.
	 */
	public function statuslineMatchesSession(string $screen, string $sessionCwd): bool {
		$tok = $this->extractStatuslineCwd($screen);
		if ($tok === null || $sessionCwd === '') { return false; }
		$tok  = '/' . ltrim($tok, '/');
		$full = '/' . ltrim(rtrim($sessionCwd, '/'), '/');
		return basename($full) === basename($tok) || str_ends_with($full, $tok);
	}

	/**
	 * PURE. Content-probe fallback binding (dotfiles-c15). For Claude sessions the
	 * ancestry join could not bind (fresh / non-cmux-resumed), match each to a still-
	 * unbound terminal surface by reading its on-screen statusline cwd. A session binds
	 * only when EXACTLY ONE unclaimed surface matches its cwd (ties broken by OS tty);
	 * anything ambiguous stays unbound — never a guess.
	 *
	 * @param array $freshRows        rows to try to bind: [session_id, cwd, tty]
	 * @param array $unboundSurfaces  [surface_ref => ['tty'=>debug_tty,'workspace_ref'=>..]]
	 * @param array $screenByRef      [surface_ref => last-screen text]
	 * @return array [session_id => surface_ref] for unambiguous binds
	 */
	public function contentProbeBind(array $freshRows, array $unboundSurfaces, array $screenByRef): array {
		$binds = [];
		$claimed = [];
		foreach ($freshRows as $r) {
			$cands = [];
			foreach (array_keys($unboundSurfaces) as $ref) {
				if (isset($claimed[$ref])) { continue; }
				if ($this->statuslineMatchesSession($screenByRef[$ref] ?? '', (string) ($r['cwd'] ?? ''))) {
					$cands[] = $ref;
				}
			}
			if (count($cands) > 1 && !empty($r['tty'])) {
				$tied = array_values(array_filter($cands, fn($ref) => ($unboundSurfaces[$ref]['tty'] ?? '') === $r['tty']));
				if (count($tied) === 1) { $cands = $tied; }
			}
			if (count($cands) === 1) {
				$binds[$r['session_id']] = $cands[0];
				$claimed[$cands[0]] = true;
			}
		}
		return $binds;
	}

	/**
	 * PURE. GATE 2 predicate: does an exported transcript belong to the target? Assert
	 * the session's first meaningful prompt appears in the rendered transcript text
	 * (whitespace-insensitive). Empty needle → cannot assert → do not block.
	 */
	public function transcriptMatchesSession(string $transcriptText, string $firstPrompt): bool {
		$needle = trim(preg_replace('/\s+/', ' ', mb_substr($firstPrompt, 0, 60)));
		if ($needle === '') { return true; }
		$hay = preg_replace('/\s+/', ' ', $transcriptText);
		return mb_strpos($hay, $needle) !== false;
	}

	public function teardown(array $sess): bool {
		$killed = $this->killMember($sess);
		if ($killed) { $this->closeSurfaceOrWorkspace($sess); }
		return $killed;
	}

	/**
	 * GATE 3 + kill. Only kill a pid whose ~/.claude/sessions/<pid>.json sessionId
	 * equals the target. Never kill by surface/tty membership — recycled ttys mean a
	 * tty can host a different live session. Last-line defense that makes a wrong join
	 * non-destructive. Returns true iff the verified claude pid (and descendants) died.
	 */
	protected function killMember(array $sess): bool {
		$target = $sess['session_id'] ?? '';
		$pid    = (int) ($sess['pid'] ?? 0);
		if ($pid <= 0) {
			$this->cli->err('  Teardown aborted: no resolved pid for this session — leaving it ALIVE.');
			return false;
		}
		$pidSid = $this->cmux->sessionIdForPid($pid);
		if ($pidSid !== $target) {
			$this->cli->err("  Teardown aborted (gate 3): pid {$pid} maps to session " . substr((string) $pidSid, 0, 8) . ", not target " . substr($target, 0, 8) . " — leaving it ALIVE.");
			return false;
		}
		return $this->killPidTree($pid);
	}

	/** Close a single member's surface, or the workspace if it's the last surface. */
	protected function closeSurfaceOrWorkspace(array $sess): void {
		$wsRef = $sess['workspace_ref'] ?? '';
		$count = $wsRef ? $this->cmux->workspaceSurfaceCount($wsRef) : 0;
		$cmd = ($count <= 1)
			? 'cmux close-workspace --workspace ' . escapeshellarg($wsRef)
			: 'cmux close-surface --surface ' . escapeshellarg($sess['surface_ref']);
		$res = $this->cli->getCommandOutputAndExitCode($cmd);
		if (($res['exitCode'] ?? 1) !== 0) {
			$this->cli->msg('  (Process terminated, but the now-empty cmux tab lingered — close it manually.)', 'yellow');
		}
	}

	/** Close an entire workspace (and every remaining surface in it). */
	protected function closeWorkspace(string $wsRef): bool {
		if ($wsRef === '') { return false; }
		$res = $this->cli->getCommandOutputAndExitCode('cmux close-workspace --workspace ' . escapeshellarg($wsRef));
		return ($res['exitCode'] ?? 1) === 0;
	}

	/** Kill $pid and its descendants (SIGTERM, then SIGKILL survivors). True if $pid is dead after. */
	protected function killPidTree(int $pid): bool {
		if ($pid <= 0) { return false; }
		$proc = $this->cmux->parseProcTable($this->cmux->psProcTable());
		$pids = $this->cmux->descendantPids($proc, $pid); // $pid + subagents
		$this->signalPids($pids, defined('SIGTERM') ? SIGTERM : 15);
		$deadline = time() + 3;
		while (time() < $deadline && $this->cmux->pidIsAlive($pid)) { usleep(200000); }
		if ($this->cmux->pidIsAlive($pid)) {
			$proc = $this->cmux->parseProcTable($this->cmux->psProcTable());
			$this->signalPids($this->cmux->descendantPids($proc, $pid), 9);
		}
		return !$this->cmux->pidIsAlive($pid);
	}

	protected function signalPids(array $pids, int $signal): void {
		foreach ($pids as $pid) {
			if (function_exists('posix_kill')) { posix_kill((int) $pid, $signal); }
			else { $this->cli->runCommand('kill -' . $signal . ' ' . (int) $pid); }
		}
	}

	public function buryOne(array $sess, bool $force, bool $autoConfirm, ?array $group = null, bool $deferClose = false): bool {
		$id      = substr((string) $sess['session_id'], 0, 8);
		$idFloor = self::IDLE_FLOOR_DEFAULT;

		// Refuse sessions the join could not bind to a single surface with confidence.
		// --force does NOT override this: an unresolved surface_ref means we don't know
		// which tab we'd act on, and guessing risks clobbering a different live session.
		if (!($sess['targetable'] ?? false)) {
			$this->cli->err("  Refusing to bury {$id}: untargetable — " . ($sess['reason'] ?: 'ambiguous session↔surface mapping') . '. Resolve it first.');
			return false;
		}

		$screen = $this->readLastScreen($sess['surface_ref'], $sess['workspace_ref']);

		// GATE 1 (pre-export): the resolved surface must actually be showing THIS
		// session's idle Claude REPL. A statusline cwd mismatch means the join pointed
		// at the wrong tab; abort before typing /export into someone else's session.
		if (!$this->statuslineMatchesSession($screen, (string) $sess['cwd'])) {
			$onscreen = $this->extractStatuslineCwd($screen);
			$this->cli->err("  Refusing to bury {$id} (gate 1): resolved surface shows "
				. ($onscreen !== null ? "cwd '{$onscreen}'" : 'no Claude REPL statusline')
				. ", not session cwd '{$sess['cwd']}' — leaving it ALIVE.");
			return false;
		}

		if ($this->isBusy((int) $sess['idle_seconds'], $idFloor, $screen)) {
			if (!$force) {
				$this->cli->msg("  Skipping {$sess['tab_title']} — session looks busy (use --force to override).", 'yellow');
				return false;
			}
			$this->cli->msg('  Session looks busy but --force given; proceeding.', 'yellow');
		}

		$this->cli->msg("  Exporting transcript for {$id}…", 'cyan');
		if (!$this->exportTranscript($sess)) {
			$this->cli->err("  Export failed (no transcript written) — leaving session ALIVE.");
			return false;
		}

		// GATE 2 (post-export, pre-teardown): confirm the exported transcript actually
		// belongs to the target before anything destructive. The needle is the session's
		// derived summary — the SAME first meaningful turn deriveSummary() pulls from the
		// JSONL, tag-stripped so it matches how /export renders it (a slash-command turn
		// renders as "/foo", not the raw <command-*> tags). The transcript is already
		// safe on disk, so a mismatch aborts teardown (session left ALIVE).
		$summary  = $this->deriveSummary($sess);
		$exported = (string) @file_get_contents($this->transcriptPath($sess['session_id']));
		if (!$this->transcriptMatchesSession($exported, $summary)) {
			$this->cli->err("  Refusing to tear down {$id} (gate 2): exported transcript does not match this session's first turn — leaving it ALIVE (transcript kept for inspection).");
			return false;
		}

		$tomb = $this->buildTombstone($sess, [
			'workspace_title' => $sess['workspace_title'],
			'tab_title'       => $sess['tab_title'],
		], $summary, gmdate('Y-m-d\TH:i:s\Z'), $group);
		file_put_contents($this->metaPath($sess['session_id']), json_encode($tomb, JSON_PRETTY_PRINT));
		$this->upsertIndex($tomb);
		$this->cli->successMsg("  Buried: {$summary}");

		if (!$autoConfirm && !$this->cli->confirm("  Close the cmux tab and kill this session now?")) {
			$this->cli->msg('  Left the tab open; transcript is archived.', 'yellow');
			return true;
		}

		// In a grouped workspace bury we kill each member here but defer closing to a
		// single workspace-level close (which also sweeps the non-claude surfaces).
		if ($deferClose) {
			if ($this->killMember($sess)) {
				$this->cli->msg('  Process terminated — RAM freed.', 'green');
			} else {
				$this->cli->err('  Archived, but could not terminate the live session automatically — kill it manually (transcript is safe).');
			}
			return true;
		}

		if ($this->teardown($sess)) {
			$this->cli->msg('  Process terminated — RAM freed.', 'green');
		} else {
			$this->cli->err('  Archived, but could not terminate the live session automatically — kill it manually (transcript is safe).');
		}
		return true;
	}

	// =========================================================================
	// Workspace-level (grouped) bury  (dotfiles-c8a)
	// =========================================================================

	/**
	 * PURE. Classify a workspace's surfaces into a layout + member lists.
	 *
	 * Inputs (all injectable for testing):
	 *   $wsNode        cmux tree workspace node (panes[].surfaces[])
	 *   $liveByRef     [surface_ref => liveSessions row] for TARGETABLE claude rows
	 *   $isClaudeByRef [surface_ref => bool]  content-probe result (has a Claude REPL
	 *                  statusline). A shell has no statusline. Reliable for fresh+resumed.
	 *
	 * Returns:
	 *   'layout'      ordered list of surface entries with position + type + title +
	 *                 cwd/url + claude_session_id (for members) — the manifest body.
	 *   'members'     targetable claude liveSessions rows (to bury), with group_pos set.
	 *   'untargetable' claude surfaces detected but not bound to a targetable row
	 *                 (fresh/ambiguous) — presence forces abort unless --force.
	 */
	public function classifyWorkspaceLayout(array $wsNode, array $liveByRef, array $isClaudeByRef): array {
		$layout = [];
		$members = [];
		$untargetable = [];
		$pos = 0;

		foreach ($wsNode['panes'] ?? [] as $paneIdx => $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				$ref  = $surf['ref'] ?? '';
				$type = $surf['type'] ?? 'terminal';
				$row  = $liveByRef[$ref] ?? null;
				$isClaude = $isClaudeByRef[$ref] ?? false;

				$entry = [
					'group_pos'    => $pos,
					'pane_index'   => $pane['index'] ?? $paneIdx,
					'index_in_pane'=> $surf['index_in_pane'] ?? 0,
					'ref'          => $ref,
					'type'         => $type,
					'title'        => $surf['title'] ?? '',
					'url'          => $surf['url'] ?? null,
					'cwd'          => $row['cwd'] ?? null,
					'kind'         => 'shell',
					'claude_session_id' => null,
				];

				// A non-terminal, non-browser surface is a cmux-native agent session
				// (e.g. type "agentSession", the React Claude UI). It has no tty for the
				// join/statusline machinery, so it can never be a bound member — but it
				// IS Claude, so treat it as claude (untargetable) to force an abort rather
				// than silently closing it as if it were a shell.
				$claudeSurface = $isClaude || ($type !== 'terminal' && $type !== 'browser');

				if ($type === 'browser') {
					$entry['kind'] = 'browser';
				} elseif ($claudeSurface && $row && ($row['targetable'] ?? false)) {
					$entry['kind'] = 'claude';
					$entry['claude_session_id'] = $row['session_id'];
					$m = $row; $m['group_pos'] = $pos;
					$members[] = $m;
				} elseif ($claudeSurface) {
					// A Claude surface the join could not bind to a targetable session
					// (fresh/non-resumed, ambiguous, or a native agent session). Detected,
					// not buryable → forces abort (unless --force skips it, alive).
					$entry['kind'] = 'claude-untargetable';
					$untargetable[] = ['ref' => $ref, 'title' => $surf['title'] ?? ''];
				}

				$layout[] = $entry;
				$pos++;
			}
		}

		return ['layout' => $layout, 'members' => $members, 'untargetable' => $untargetable];
	}

	public function buryWorkspace(string $nameOrRef, bool $force, bool $autoConfirm): void {
		try {
			$wsInfo = $this->cmux->resolveWorkspaceNode($this->cmux->tree(), $nameOrRef);
		} catch (\RuntimeException $e) {
			$this->cli->exitErr($e->getMessage());
			return;
		}
		if (!$wsInfo) { $this->cli->exitErr("No workspace matches '{$nameOrRef}'."); return; }

		$wsRef   = $wsInfo['ref'];
		$wsTitle = $wsInfo['title'];

		// Index targetable claude rows by surface_ref, and content-probe every surface.
		$liveByRef = [];
		foreach ($this->liveSessions() as $r) {
			if (($r['targetable'] ?? false) && $r['surface_ref'] !== '') { $liveByRef[$r['surface_ref']] = $r; }
		}
		$isClaudeByRef = [];
		foreach ($wsInfo['node']['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				$ref = $surf['ref'] ?? '';
				if ($ref === '' || ($surf['type'] ?? '') === 'browser') { $isClaudeByRef[$ref] = false; continue; }
				$screen = $this->readLastScreen($ref, $wsRef, 8);
				$isClaudeByRef[$ref] = $this->extractStatuslineCwd($screen) !== null;
			}
		}

		$cls = $this->classifyWorkspaceLayout($wsInfo['node'], $liveByRef, $isClaudeByRef);

		// Self-guard: never bury a workspace containing the caller's own session.
		$selfSid = $this->selfSessionId();
		foreach ($cls['members'] as $m) {
			if ($selfSid && $m['session_id'] === $selfSid) {
				$this->cli->exitErr('Refusing to bury a workspace containing the caller\'s own session.');
				return;
			}
		}

		// Abort on any detected-but-unbindable Claude, unless --force skips them.
		if ($cls['untargetable']) {
			$this->cli->msg('Workspace "' . $wsTitle . '" has ' . count($cls['untargetable']) . ' Claude surface(s) not safely targetable:', 'yellow');
			foreach ($cls['untargetable'] as $u) {
				$this->cli->msg('  ' . $u['ref'] . '  ' . $u['title'], 'yellow');
			}
			if (!$force) {
				$this->cli->exitErr('Refusing to partially destroy a workspace. Resolve these (or re-run with --force to skip them and leave them alive).');
				return;
			}
			$this->cli->msg('  --force: skipping the above (left ALIVE); the workspace will not be fully closed.', 'yellow');
		}

		if (!$cls['members']) { $this->cli->exitErr('No targetable Claude sessions in that workspace to bury.'); return; }

		$this->cli->msg(sprintf('Workspace "%s" (%s): %d Claude session(s), %d other surface(s).',
			$wsTitle, $wsRef, count($cls['members']), count($cls['layout']) - count($cls['members'])), 'yellow');
		foreach ($cls['members'] as $m) {
			$this->cli->msg(sprintf('  %s  %-20.20s  %s', substr($m['session_id'], 0, 8), $m['tab_title'] ?? '', $m['cwd'] ?? ''));
		}
		if (!$autoConfirm && !$this->cli->confirm('Bury this workspace (' . count($cls['members']) . ' session(s)) as a group?')) {
			$this->cli->msg('Aborted.', 'yellow');
			return;
		}

		// Stamp a shared group + write the layout manifest BEFORE any destruction.
		$group = $this->cmux->uuidv4();
		$buriedAt = gmdate('Y-m-d\TH:i:s\Z');
		$dir = $this->workspaceGroupDir($group);
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		file_put_contents($this->manifestPath($group), json_encode([
			'group_id'    => $group,
			'group_title' => $wsTitle,
			'window_ref'  => $wsInfo['window_ref'],
			'buried_at'   => $buriedAt,
			'layout'      => $cls['layout'],
		], JSON_PRETTY_PRINT));

		// Bury each member (per-member gates), deferring the tab close.
		$buried = 0; $failed = 0;
		foreach ($cls['members'] as $m) {
			$sid = $m['session_id'];
			$fresh = $this->resolveLiveBySessionId($sid);
			if (!$fresh) { $this->cli->msg("  {$sid} is gone — skipping.", 'yellow'); $failed++; continue; }
			$fresh['group_pos'] = $m['group_pos'];
			$grp = ['group_id' => $group, 'group_title' => $wsTitle, 'group_pos' => $m['group_pos']];
			if ($this->buryOne($fresh, $force, true, $grp, true)) { $buried++; }
			else { $failed++; }
		}

		// Close the workspace only if everything was clean; otherwise close just the
		// buried members' surfaces + non-claude surfaces, leaving anything still alive.
		if ($failed === 0 && !$cls['untargetable']) {
			$this->closeWorkspace($wsRef);
		} else {
			foreach ($cls['layout'] as $e) {
				if ($e['kind'] === 'claude-untargetable') { continue; }
				if ($e['kind'] === 'claude') { continue; } // already killed; its surface closes when shell exits
				$this->cli->getCommandOutputAndExitCode('cmux close-surface --surface ' . escapeshellarg($e['ref']));
			}
			$this->cli->msg('  Workspace left open (some surfaces preserved).', 'yellow');
		}

		$this->cli->successMsg("Buried workspace \"{$wsTitle}\" — group {$group} ({$buried} session(s)).");
	}

	public function listTombstones(): void {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		if (!$tombs) { $this->cli->msg('Graveyard is empty.', 'yellow'); return; }
		usort($tombs, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));

		// Grouped view: workspace groups first (header + members), then loose tombstones.
		[$groups, $loose] = $this->groupTombstones($tombs);
		foreach ($groups as $gid => $members) {
			$title = $members[0]['group_title'] ?? '(workspace)';
			$this->cli->msg(sprintf('▸ workspace "%s"  group %s  %s  (%d session%s)',
				$title, substr($gid, 0, 8), substr($members[0]['buried_at'] ?? '', 0, 10),
				count($members), count($members) === 1 ? '' : 's'), 'green');
			$this->cli->msg('  resurrect: graveyard resurrect --workspace ' . substr($gid, 0, 8), 'cyan');
			foreach ($members as $t) {
				$this->cli->msg('    ' . $this->tombstoneLine($t));
			}
		}
		foreach ($loose as $t) {
			$this->cli->msg($this->tombstoneLine($t));
			$this->cli->msg("          cwd: " . ($t['cwd'] ?? ''), 'cyan');
		}
	}

	/** PURE: split tombstones into [group_id => members[], loose[]], preserving order. */
	public function groupTombstones(array $tombs): array {
		$groups = [];
		$loose  = [];
		foreach ($tombs as $t) {
			$gid = $t['group_id'] ?? null;
			if ($gid) { $groups[$gid][] = $t; }
			else { $loose[] = $t; }
		}
		foreach ($groups as &$members) {
			usort($members, fn($a, $b) => ($a['group_pos'] ?? 0) <=> ($b['group_pos'] ?? 0));
		}
		unset($members);
		return [$groups, $loose];
	}

	/** PURE: one-line tombstone summary. */
	public function tombstoneLine(array $t): string {
		return sprintf('%s  %-24.24s  %-10.10s  %s',
			substr($t['session_id'], 0, 8),
			$t['workspace_title'] ?: '(untitled)',
			substr($t['buried_at'] ?? '', 0, 10),
			$t['summary'] ?? '');
	}

	public function resolveTombstone(string $prefix): ?array {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		$matches = array_values(array_filter($tombs, fn($t) => str_starts_with($t['session_id'], $prefix)));
		if (count($matches) === 1) { return $matches[0]; }
		if (count($matches) > 1) {
			$this->cli->err("Ambiguous id '{$prefix}' — matches " . count($matches) . " tombstones:");
			foreach ($matches as $m) { $this->cli->msg('  ' . substr($m['session_id'], 0, 12) . '  ' . $m['summary']); }
		}
		return null;
	}

	public function showTombstone(string $prefix): void {
		$t = $this->resolveTombstone($prefix);
		if (!$t) { $this->cli->exitErr("No single tombstone matches '{$prefix}'."); }
		$path = $this->transcriptPath($t['session_id']);
		if (!is_file($path)) { $this->cli->exitErr("Transcript missing: {$path}"); }
		$editor = trim((string) shell_exec('command -v code 2>/dev/null'));
		if ($editor) { shell_exec('code -r ' . escapeshellarg($path)); }
		else { $ed = getenv('EDITOR') ?: 'vi'; $this->cli->runCommand($ed . ' ' . escapeshellarg($path)); }
		$this->cli->successMsg("Opened {$path}");
	}

	public function resurrect(string $prefix, bool $fromTranscript = false): void {
		$t = $this->resolveTombstone($prefix);
		if (!$t) { $this->cli->exitErr("No single tombstone matches '{$prefix}'."); }

		$jsonl         = $this->cmux->jsonlPathFor($t['session_id'], $t['cwd'] ?? '');
		$hasJsonl      = is_file($jsonl);
		$transcript    = $this->transcriptPath($t['session_id']);
		$hasTranscript = is_file($transcript);

		if (!$hasJsonl && !$hasTranscript) {
			$this->cli->exitErr("Neither live JSONL nor exported transcript available for {$t['session_id']} — cannot resurrect.");
		}

		$title = $t['workspace_title'] ?: 'resurrected';
		$cwd   = $t['cwd'] ?? '';
		$ws = $this->cmux->newWorkspace($title, $cwd ?: null);

		$mode = $this->launchSessionIntoSurface($t, $ws['firstSurfRef'], $ws['ref'], $fromTranscript);
		$note = $mode === 'resume' ? 'via --resume (restored in place)' : 'Claude is reading the transcript';
		$this->cli->successMsg("Resurrected '{$title}' in {$ws['ref']} — {$note}.");
	}

	/**
	 * Launch a buried session into an existing cmux surface: --resume when the live
	 * JSONL still exists (unless $fromTranscript), else restart Claude and point it at
	 * the exported transcript. Returns 'resume' or 'transcript'. Shared by single- and
	 * workspace-resurrect so both restore members identically.
	 */
	protected function launchSessionIntoSurface(array $t, string $surfRef, string $wsRef, bool $fromTranscript): string {
		$jsonl      = $this->cmux->jsonlPathFor($t['session_id'], $t['cwd'] ?? '');
		$transcript = $this->transcriptPath($t['session_id']);
		$useResume  = !$fromTranscript && is_file($jsonl);

		if ($useResume) {
			$launch = $this->cmux->buildResumeCommand($t['session_id'], !empty($t['skip_perms']), $t['model'] ?? null);
			$this->cmux->sendToSurface($surfRef, $wsRef, $launch . "\n");
			$this->cmux->sendKeyToSurface($surfRef, $wsRef, 'enter');
			return 'resume';
		}

		$launch = 'claude';
		if (!empty($t['skip_perms'])) { $launch .= ' --dangerously-skip-permissions'; }
		if (!empty($t['model']))      { $launch .= ' --model=' . $t['model']; }
		$this->cmux->sendToSurface($surfRef, $wsRef, $launch . "\n");
		sleep(3); // let the REPL come up
		$this->cmux->sendToSurface($surfRef, $wsRef,
			'Resuming a buried session. Read ' . $transcript . ' — that is a transcript of where we left off. Re-orient from it, then continue.');
		$this->cmux->sendKeyToSurface($surfRef, $wsRef, 'enter');
		return 'transcript';
	}

	/** Resolve a group id by exact or prefix match over stored workspace manifests. */
	public function resolveGroup(string $prefix): ?array {
		$root = $this->storeRoot() . '/workspaces';
		if (!is_dir($root)) { return null; }
		$matches = [];
		foreach (glob($root . '/*/manifest.json') ?: [] as $mf) {
			$gid = basename(dirname($mf));
			if ($gid === $prefix || str_starts_with($gid, $prefix)) {
				$m = json_decode((string) @file_get_contents($mf), true);
				if ($m) { $matches[] = $m; }
			}
		}
		if (count($matches) === 1) { return $matches[0]; }
		if (count($matches) > 1) {
			$this->cli->err("Ambiguous group '{$prefix}' — matches " . count($matches) . ' workspaces.');
		}
		return null;
	}

	public function resurrectWorkspace(string $prefix, bool $fromTranscript = false): void {
		$m = $this->resolveGroup($prefix);
		if (!$m) { $this->cli->exitErr("No single workspace group matches '{$prefix}'."); return; }

		$layout = $m['layout'] ?? [];
		if (!$layout) { $this->cli->exitErr('Manifest has no layout to restore.'); return; }

		// Member tombstones by session id (for resume metadata).
		$tombBySid = [];
		foreach ($this->readIndex()['tombstones'] ?? [] as $t) {
			if (!empty($t['group_id']) && $t['group_id'] === $m['group_id']) { $tombBySid[$t['session_id']] = $t; }
		}

		$firstCwd = $layout[0]['cwd'] ?? null;
		$ws = $this->cmux->newWorkspace($m['group_title'] ?: 'resurrected', $firstCwd);
		$wsRef = $ws['ref'];

		$restored = 0;
		foreach ($layout as $i => $e) {
			// First layout slot reuses the workspace's initial surface; the rest are new tabs.
			if ($i === 0) {
				$surfRef = $ws['firstSurfRef'];
			} else {
				$surfRef = $this->cmux->createSurface($wsRef, null, $e['type'] === 'browser' ? 'browser' : 'terminal', $e['url'] ?? null);
				if (!$surfRef) { $this->cli->msg("  Could not create surface for slot {$i} — skipping.", 'yellow'); continue; }
			}

			if ($e['kind'] === 'claude' && !empty($e['claude_session_id']) && isset($tombBySid[$e['claude_session_id']])) {
				$mode = $this->launchSessionIntoSurface($tombBySid[$e['claude_session_id']], $surfRef, $wsRef, $fromTranscript);
				$this->cli->msg('  ↺ ' . substr($e['claude_session_id'], 0, 8) . " ({$mode})", 'green');
				$restored++;
			} elseif ($e['kind'] === 'browser') {
				// url already applied at surface creation
			} elseif (!empty($e['cwd'])) {
				$this->cmux->sendToSurface($surfRef, $wsRef, 'cd ' . escapeshellarg($e['cwd']) . "\n");
			}
		}

		$this->cli->successMsg(sprintf('Resurrected workspace "%s" in %s — %d Claude session(s) restored.', $m['group_title'], $wsRef, $restored));
	}

	/**
	 * PURE: match $id against $rows by precedence tiers; first tier with >=1
	 * match wins (no fallthrough to weaker tiers).
	 *   1. exact surface_ref
	 *   2. exact surface_id (UUID)
	 *   3. session_id exact (if any) else session_id prefix matches
	 *   4. workspace_title/tab_title substring (case-insensitive)
	 * Returns [] if nothing matches in any tier.
	 */
	public function matchIdentifier(array $rows, string $id): array {
		$exact = array_values(array_filter($rows, fn($r) => ($r['surface_ref'] ?? null) === $id));
		if ($exact) { return $exact; }

		$exact = array_values(array_filter($rows, fn($r) => ($r['surface_id'] ?? null) === $id));
		if ($exact) { return $exact; }

		$sessionExact = array_values(array_filter($rows, fn($r) => ($r['session_id'] ?? null) === $id));
		if ($sessionExact) { return $sessionExact; }
		$prefix = array_values(array_filter($rows, fn($r) => str_starts_with((string) ($r['session_id'] ?? ''), $id)));
		if ($prefix) { return $prefix; }

		$needle = mb_strtolower($id);
		$nameMatches = array_values(array_filter($rows, function ($r) use ($needle) {
			$title = mb_strtolower((string) ($r['workspace_title'] ?? ''));
			$tab   = mb_strtolower((string) ($r['tab_title'] ?? ''));
			return ($needle !== '' && (str_contains($title, $needle) || str_contains($tab, $needle)));
		}));
		if ($nameMatches) { return $nameMatches; }

		return [];
	}

	/** Resolve $id against deduped liveSessions() via matchIdentifier(). */
	public function resolveLiveByIdentifier(string $id): array {
		return $this->matchIdentifier($this->liveSessions(), $id);
	}

	public function buryByRef(string $id, bool $force, bool $autoConfirm): void {
		$matches = $this->resolveLiveByIdentifier($id);

		if (!$matches) {
			$this->cli->exitErr("No live session matches '{$id}'.");
		}

		if (count($matches) > 1) {
			$this->cli->msg("'{$id}' is ambiguous — matches " . count($matches) . ' live session(s):', 'yellow');
			foreach ($matches as $m) {
				$this->cli->msg(sprintf(
					'  %s  idle=%ds  %-20.20s  %.40s  %s',
					substr($m['session_id'], 0, 8),
					$m['idle_seconds'] ?? 0,
					$m['workspace_title'] ?? '',
					$m['tab_title'] ?? '',
					$m['cwd'] ?? ''
				));
			}
			$this->cli->exitErr("'{$id}' is ambiguous — narrow it or pass a full session-id.");
		}

		$match = $matches[0];
		$selfSurfaceId = $this->selfSurfaceId();
		$selfSessionId = $this->selfSessionId();
		if ($selfSessionId && ($match['session_id'] ?? null) === $selfSessionId) {
			$this->cli->exitErr('Refusing to bury the caller\'s own session.');
		}
		if ($selfSurfaceId && (($match['surface_id'] ?? null) === $selfSurfaceId || ($match['surface_ref'] ?? null) === $selfSurfaceId)) {
			$this->cli->exitErr('Refusing to bury the caller\'s own session.');
		}

		$this->buryIds([$match['session_id']], $autoConfirm, $force);
	}

	public function candidates(): array {
		$rows = $this->filterSelf($this->liveSessions(), $this->selfSurfaceId(), $this->selfSessionId());
		$rows = array_values(array_filter($rows, fn($r) => $r['idle_seconds'] !== PHP_INT_MAX));
		usort($rows, function ($a, $b) {
			return $b['idle_seconds'] <=> $a['idle_seconds']
				?: strcmp($a['session_id'], $b['session_id']);
		});

		$out = [];
		foreach ($rows as $r) {
			$busy = $this->isBusy(
				(int) $r['idle_seconds'],
				self::IDLE_FLOOR_DEFAULT,
				$this->readLastScreen($r['surface_ref'], $r['workspace_ref'])
			);
			$out[] = [
				'session_id'      => $r['session_id'],
				'idle_seconds'    => $r['idle_seconds'],
				'cwd'             => $r['cwd'],
				'workspace_title' => $r['workspace_title'],
				'tab_title'       => $r['tab_title'],
				'busy'            => $busy,
				'surface_ref'     => $r['surface_ref'],
				'workspace_ref'   => $r['workspace_ref'],
				'pid'             => $r['pid'],
				'model'           => $r['model'],
				'skip_perms'      => $r['skip_perms'],
				'targetable'      => $r['targetable'] ?? true,
				'reason'          => $r['reason'] ?? '',
			];
		}
		return $out;
	}

	/**
	 * PURE: single tab-separated porcelain line for a candidate row. No trailing newline.
	 * Columns: session_id, idle_seconds, busy|idle, targetable|UNTARGETABLE, workspace_title, cwd, reason.
	 */
	public function formatCandidatePorcelain(array $row): string {
		return implode("\t", [
			$row['session_id'],
			(string) $row['idle_seconds'],
			$row['busy'] ? 'busy' : 'idle',
			($row['targetable'] ?? true) ? 'targetable' : 'UNTARGETABLE',
			$row['workspace_title'],
			$row['cwd'],
			$row['reason'] ?? '',
		]);
	}

	public function idleHuman(int $secs): string {
		if ($secs >= 86400) { return floor($secs / 86400) . 'd'; }
		if ($secs >= 3600)  { return floor($secs / 3600) . 'h'; }
		if ($secs >= 60)    { return floor($secs / 60) . 'm'; }
		return $secs . 's';
	}

	public function printCandidates(bool $porcelain): void {
		$rows = $this->candidates();
		if ($porcelain) {
			foreach ($rows as $r) { echo $this->formatCandidatePorcelain($r) . "\n"; }
			return;
		}
		if (!$rows) { $this->cli->msg('No buryable sessions.', 'yellow'); return; }
		foreach ($rows as $r) {
			$targetable = $r['targetable'] ?? true;
			$this->cli->msg(sprintf(
				'%s  idle=%-5.5s  %-5.5s  %-20.20s  %s',
				substr($r['session_id'], 0, 8),
				$this->idleHuman((int) $r['idle_seconds']),
				$r['busy'] ? 'busy' : 'idle',
				($r['workspace_title'] ?: '') . ($r['tab_title'] ? ' / ' . $r['tab_title'] : ''),
				$r['cwd']
			), $targetable ? '' : 'yellow');
			if (!$targetable) {
				$this->cli->msg('          ⚠ untargetable: ' . $r['reason'], 'yellow');
			}
		}
	}

	public function buryIds(array $sessionIds, bool $autoConfirm, bool $force = false): void {
		$ids = array_values(array_unique(array_filter($sessionIds, fn($s) => $s !== '')));
		if (!$ids) { $this->cli->msg('No session ids given.', 'yellow'); return; }

		if ($this->selfSurfaceId() === null) {
			$this->cli->msg('Warning: CMUX_SURFACE_ID is unset — self-protection is disabled; verify your targets.', 'yellow');
		}

		$selfSessionId = $this->selfSessionId();
		$resolved = [];
		$missing  = [];
		foreach ($ids as $sid) {
			$fresh = $this->resolveLiveBySessionId($sid);
			if (!$fresh) { $missing[] = $sid; continue; }
			$resolved[] = $fresh;
		}
		if ($missing) {
			foreach ($missing as $sid) {
				$this->cli->msg("  Session {$sid} is not currently live — skipping.", 'yellow');
			}
		}
		if ($selfSessionId && in_array($selfSessionId, $ids, true)) {
			$this->cli->exitErr('Refusing to bury the caller\'s own session.');
		}
		if (!$resolved) { $this->cli->msg('No live sessions to bury.', 'yellow'); return; }

		$this->cli->msg('Sessions to bury:', 'yellow');
		foreach ($resolved as $s) {
			$this->cli->msg(sprintf('  %s  idle=%ds  %-20.20s  %.40s',
				substr($s['session_id'], 0, 8), $s['idle_seconds'], $s['workspace_title'], $s['tab_title']));
		}
		if (!$autoConfirm && !$this->cli->confirm('Bury these ' . count($resolved) . ' session(s)?')) {
			$this->cli->msg('Aborted.', 'yellow'); return;
		}

		$n = 0;
		foreach (array_map(fn($s) => $s['session_id'], $resolved) as $sid) {
			$fresh = $this->resolveLiveBySessionId($sid);
			if (!$fresh) { $this->cli->msg("  Session {$sid} is gone — skipping.", 'yellow'); continue; }
			if ($this->buryOne($fresh, $force, true)) { $n++; }
		}
		$this->cli->successMsg("Buried {$n} of " . count($resolved) . ' session(s).');
	}

	public function buryIdle(int $thresholdSecs, bool $autoConfirm): void {
		$sessions = $this->filterSelf($this->liveSessions(), $this->selfSurfaceId(), $this->selfSessionId());

		$unknown = array_values(array_filter($sessions, fn($s) => $s['idle_seconds'] === PHP_INT_MAX));
		if ($unknown) {
			$this->cli->msg('  Skipping ' . count($unknown) . ' session(s) with unmeasurable idle time (no transcript found).', 'yellow');
		}
		$sessions = array_values(array_filter($sessions, fn($s) => $s['idle_seconds'] !== PHP_INT_MAX));

		$stale = array_values(array_filter($sessions, fn($s) => $s['idle_seconds'] >= $thresholdSecs));
		if (!$stale) { $this->cli->msg('No sessions idle past the threshold.', 'yellow'); return; }

		$this->cli->msg('Sessions to bury (idle >= ' . $thresholdSecs . 's):', 'yellow');
		foreach ($stale as $s) {
			$this->cli->msg(sprintf('  %s  idle=%ds  %-20.20s  %.40s',
				substr($s['session_id'], 0, 8), $s['idle_seconds'], $s['workspace_title'], $s['tab_title']));
		}
		if (!$autoConfirm && !$this->cli->confirm('Bury these ' . count($stale) . ' session(s)?')) {
			$this->cli->msg('Aborted.', 'yellow'); return;
		}
		$ids = array_map(fn($s) => $s['session_id'], $stale);
		$n = 0;
		foreach ($ids as $sid) {
			$fresh = $this->resolveLiveBySessionId($sid);
			if (!$fresh) { $this->cli->msg("  Session {$sid} is gone — skipping.", 'yellow'); continue; }
			if ($this->buryOne($fresh, false, true)) { $n++; }
		}
		$this->cli->successMsg("Buried {$n} of " . count($ids) . ' session(s).');
	}

	public function pickAndBury(bool $autoConfirm): void {
		$cands = $this->candidates();
		if (!$cands) { $this->cli->msg('No buryable sessions.', 'yellow'); return; }

		if (trim((string) shell_exec('command -v fzf 2>/dev/null')) !== '') {
			$ids = $this->pickWithFzf($cands);
		} else {
			$ids = $this->pickWithRepl($cands);
		}

		if (!$ids) { $this->cli->msg('Nothing selected.', 'yellow'); return; }
		$this->buryIds($ids, $autoConfirm);
	}

	public function pickWithFzf(array $cands): array {
		$selfBin = $_SERVER['argv'][0];
		$resolved = realpath($selfBin);
		if ($resolved !== false) { $selfBin = $resolved; }

		$lines = [];
		foreach ($cands as $r) {
			$label = sprintf(
				'%s  %s  %s%s  %s',
				$this->idleHuman((int) $r['idle_seconds']),
				$r['busy'] ? 'busy' : 'idle',
				$r['workspace_title'] ?: '',
				$r['tab_title'] ? ' / ' . $r['tab_title'] : '',
				$r['cwd']
			);
			$lines[] = $r['session_id'] . "\t" . $label;
		}

		$tmp = tempnam(sys_get_temp_dir(), 'gy-pick-');
		file_put_contents($tmp, implode("\n", $lines));

		$preview = escapeshellarg('php ' . $selfBin . ' _preview {1}');
		$cmd = 'fzf --multi --with-nth=2.. --delimiter="\t" --preview ' . $preview . ' --preview-window=right:60%:wrap';
		$out = shell_exec('cat ' . escapeshellarg($tmp) . ' | ' . $cmd . ' 2>/dev/null');
		@unlink($tmp);

		$ids = [];
		foreach (explode("\n", (string) $out) as $line) {
			$line = rtrim($line, "\r");
			if ($line === '') { continue; }
			$parts = explode("\t", $line, 2);
			$ids[] = $parts[0];
		}
		return $ids;
	}

	public function pickWithRepl(array $cands): array {
		foreach ($cands as $i => $r) {
			$this->cli->msg(sprintf(
				'%2d) %s  idle=%-5.5s  %-5.5s  %-20.20s  %s',
				$i + 1,
				substr($r['session_id'], 0, 8),
				$this->idleHuman((int) $r['idle_seconds']),
				$r['busy'] ? 'busy' : 'idle',
				($r['workspace_title'] ?: '') . ($r['tab_title'] ? ' / ' . $r['tab_title'] : ''),
				$r['cwd']
			));
		}

		while (true) {
			$line = (string) $this->cli->ask('Select (e.g. 1 3 5, 2-4, a=all, p<n>=preview, q=quit): ');
			$line = trim($line);
			if ($line === '' || strtolower($line) === 'q') { return []; }
			if (strtolower($line) === 'a') {
				return array_map(fn($r) => $r['session_id'], $cands);
			}
			if (preg_match('/^p(\d+)$/i', $line, $m)) {
				$idx = (int) $m[1] - 1;
				if (!isset($cands[$idx])) {
					$this->cli->msg('No such candidate.', 'yellow');
					continue;
				}
				$r = $cands[$idx];
				$this->cli->msg($this->readLastScreen($r['surface_ref'], $r['workspace_ref'], 40));
				continue;
			}
			$indices = $this->parseReplSelection($line, count($cands));
			if (!$indices) {
				$this->cli->msg('No valid selections.', 'yellow');
				continue;
			}
			$ids = [];
			foreach ($indices as $idx) {
				$ids[] = $cands[$idx]['session_id'];
			}
			return array_values(array_unique($ids));
		}
	}

	/** PURE: parse a REPL selection line ("1 3 5", "2-4") into 0-based indices within [0, count). Out-of-range tokens are warned and skipped. */
	public function parseReplSelection(string $line, int $count): array {
		$indices = [];
		foreach (preg_split('/\s+/', trim($line)) as $token) {
			if ($token === '') { continue; }
			if (preg_match('/^(\d+)-(\d+)$/', $token, $m)) {
				$start = (int) $m[1];
				$end   = (int) $m[2];
				if ($start > $end) { [$start, $end] = [$end, $start]; }
				for ($n = $start; $n <= $end; $n++) {
					$idx = $n - 1;
					if ($idx >= 0 && $idx < $count) { $indices[] = $idx; }
					else { $this->cli->msg("  Ignoring out-of-range selection: {$n}", 'yellow'); }
				}
			} elseif (preg_match('/^(\d+)$/', $token, $m)) {
				$n   = (int) $m[1];
				$idx = $n - 1;
				if ($idx >= 0 && $idx < $count) { $indices[] = $idx; }
				else { $this->cli->msg("  Ignoring out-of-range selection: {$n}", 'yellow'); }
			} else {
				$this->cli->msg("  Ignoring unrecognized token: {$token}", 'yellow');
			}
		}
		return array_values(array_unique($indices));
	}

	public function printPreview(string $sessionId): void {
		$s = $this->resolveLiveBySessionId($sessionId);
		if (!$s) { echo "(session no longer live)\n"; return; }
		echo sprintf(
			"cwd: %s\nmodel: %s\nidle: %s\n\n",
			$s['cwd'],
			$s['model'] ?: '(unknown)',
			$this->idleHuman((int) $s['idle_seconds'])
		);
		echo $this->readLastScreen($s['surface_ref'], $s['workspace_ref'], 40);
	}

	/**
	 * peek <id>: one-shot rendered preview of a LIVE session's recent genuine turns,
	 * read from its JSONL (not the terminal screen, not /export) so JT can inspect what
	 * a session was doing before approving a bury — without the interactive picker.
	 */
	public function peekSession(string $id, int $turns = 6): void {
		$matches = $this->resolveLiveByIdentifier($id);
		if (!$matches) {
			$this->cli->exitErr("No live session matches '{$id}'.");
		}
		if (count($matches) > 1) {
			$this->cli->msg("'{$id}' is ambiguous — matches " . count($matches) . ' live session(s):', 'yellow');
			foreach ($matches as $m) {
				$this->cli->msg(sprintf('  %s  %-20.20s  %s', substr($m['session_id'], 0, 8), $m['workspace_title'] ?? '', $m['cwd'] ?? ''));
			}
			$this->cli->exitErr("Narrow it or pass a full session-id.");
		}
		$s = $matches[0];

		$this->cli->msg(sprintf('%s  %s', substr($s['session_id'], 0, 8), $s['workspace_title'] ?: $s['tab_title']), 'cyan');
		$this->cli->msg(sprintf('cwd: %s   model: %s   idle: %s', $s['cwd'], $s['model'] ?: '(unknown)', $this->idleHuman((int) $s['idle_seconds'])), 'cyan');
		if (!($s['targetable'] ?? true)) {
			$this->cli->msg('⚠ untargetable: ' . $s['reason'], 'yellow');
		}
		$this->cli->msg('');

		$jsonl = $this->cmux->jsonlPathFor($s['session_id'], $s['cwd']);
		$entries = [];
		if (is_file($jsonl)) {
			$fh = fopen($jsonl, 'r');
			while (($line = fgets($fh)) !== false) {
				$e = json_decode($line, true);
				if ($e) { $entries[] = $e; }
			}
			fclose($fh);
		}
		$rendered = $this->renderTurns($entries, $turns);
		echo $rendered !== '' ? $rendered : "(no genuine conversation turns found)\n";
	}

	/**
	 * PURE-ish: render the last $limit genuine user/assistant turns from decoded JSONL
	 * entries as readable lines. Skips synthetic resume turns AND slash-command noise
	 * (both classified by Cmux::isSyntheticEntry) and tool-only turns with no text.
	 * Remaining text is tag-stripped, whitespace-collapsed, and truncated.
	 */
	public function renderTurns(array $entries, int $limit = 6, int $width = 160): string {
		$turns = [];
		foreach ($entries as $e) {
			$type = $e['type'] ?? '';
			if ($type !== 'user' && $type !== 'assistant') { continue; }
			if ($this->cmux->isSyntheticEntry($e)) { continue; }

			$content = $e['message']['content'] ?? '';
			$text = '';
			if (is_string($content)) {
				$text = $content;
			} elseif (is_array($content)) {
				foreach ($content as $c) {
					if (is_array($c) && ($c['type'] ?? '') === 'text') { $text .= ($text === '' ? '' : ' ') . ($c['text'] ?? ''); }
				}
			}

			// Genuine turns only reach here (command/synthetic already skipped). Clean
			// defensively: strip any stray tags, collapse whitespace.
			$text = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $text)));
			if ($text === '') { continue; } // tool-only / empty turn
			if (mb_strlen($text) > $width) { $text = mb_substr($text, 0, $width - 1) . '…'; }
			$turns[] = ($type === 'user' ? '❯ ' : '⏺ ') . $text;
		}

		$turns = array_slice($turns, -$limit);
		return $turns ? implode("\n", $turns) . "\n" : '';
	}
}
