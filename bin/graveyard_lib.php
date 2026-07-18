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
	public function pageFilePath(): string { return $this->storeRoot() . '/page.html'; }
	public function pageDataDir(): string { return $this->storeRoot() . '/page-data'; }
	public function transcriptJsPath(string $id): string { return $this->pageDataDir() . "/{$id}.js"; }

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
		// Wrapper tags mirror Cmux::isSyntheticEntry (incl. <local-command-caveat>, the
		// one whose omission let a "Caveat: …" turn become a tombstone summary); the
		// plain-text prefixes catch the same content after tag-stripping.
		$noisePrefixes = [
			'<command-message>', '<command-args>', '<local-command-stdout>', '<local-command-caveat>',
			'Base directory for this skill:', 'Caveat: The messages below',
		];
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

	/**
	 * PURE. The cwd token from a Claude REPL statusline ("📁 /foo"), or null if none.
	 * The cwd MAY CONTAIN SPACES ("/Southport UDO"), so capture the whole field after
	 * the 📁 glyph up to the next status separator (| or │) or end of line — never
	 * stop at the first space (that was the phase-1-family bug: '/Southport UDO' →
	 * '/Southport', making every spaced-path session fail gate 1).
	 */
	public function extractStatuslineCwd(string $screen): ?string {
		if (!preg_match('/📁\s*([^|│\x{2502}\n]+)/u', $screen, $m)) { return null; }
		$tok = trim($m[1]);
		return $tok === '' ? null : $tok;
	}

	/**
	 * PURE. Split a path into its non-empty components, dropping a leading ~ and any
	 * elision markers (…), so an abbreviated statusline path can be compared by its
	 * trailing components. "~/Documents/Southport UDO" → [Documents, Southport UDO];
	 * "…/Southport UDO" → [Southport UDO]; "/a/b" → [a, b].
	 */
	public function pathTailComponents(string $path): array {
		$out = [];
		foreach (preg_split('#/+#', trim($path)) as $p) {
			$p = trim($p);
			if ($p === '' || $p === '~' || $p === '…' || $p === '...') { continue; }
			$out[] = $p;
		}
		return $out;
	}

	/**
	 * PURE. GATE 1 predicate: does the on-screen Claude statusline's cwd correspond to
	 * $sessionCwd? The statusline abbreviates (leading-component elision, ~-home, or
	 * just a trailing slice), so we match the statusline token's components as a
	 * TRAILING slice of the session cwd's components — robust to spaces, ~, and elision.
	 * Returns false when no statusline is found (surface is not a Claude REPL) — blocks.
	 */
	public function statuslineMatchesSession(string $screen, string $sessionCwd): bool {
		$tok = $this->extractStatuslineCwd($screen);
		if ($tok === null || $sessionCwd === '') { return false; }
		$tokComps  = $this->pathTailComponents($tok);
		$sessComps = $this->pathTailComponents($sessionCwd);
		$n = count($tokComps);
		if ($n === 0 || $n > count($sessComps)) { return false; }
		return array_slice($sessComps, -$n) === $tokComps;
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
	 *
	 * Needles come from genuineTurns(), which reads the raw JSONL and keeps inline
	 * markdown (**bold**, `code`, _em_). Claude Code's /export STRIPS those markers
	 * from the rendered transcript, so a literal substring test false-negatives on
	 * any turn that leads with formatting. Normalize markdown on both sides at compare
	 * time only — genuineTurns()/peek/tombstone output stay untouched.
	 */
	public function transcriptMatchesSession(string $transcriptText, string $firstPrompt): bool {
		$demark = fn(string $s): string => preg_replace('/[`*_]/', '', preg_replace('/\s+/', ' ', $s));
		$needle = trim($demark(mb_substr($firstPrompt, 0, 60)));
		if ($needle === '') { return true; }
		return mb_strpos($demark($transcriptText), $needle) !== false;
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
		// belongs to the target before anything destructive. Match on the session's
		// RECENT genuine turns (tag-stripped) rather than its first turn: /export renders
		// only the post-compaction / current-bridge conversation, and opening turns are
		// often machine caveats or skill preambles absent from the export — so the tail is
		// the reliable anchor. The transcript is already safe on disk, so a mismatch aborts
		// teardown (session left ALIVE).
		$exported = (string) @file_get_contents($this->transcriptPath($sess['session_id']));
		$needles  = $this->recentTurnNeedles((string) $sess['session_id'], (string) $sess['cwd']);
		if (!$this->transcriptBelongsToSession($exported, $needles)) {
			$this->cli->err("  Refusing to tear down {$id} (gate 2): exported transcript does not match this session's recent turns — leaving it ALIVE (transcript kept for inspection).");
			return false;
		}

		$summary = $this->deriveSummary($sess);
		$tomb = $this->buildTombstone($sess, [
			'workspace_title' => $sess['workspace_title'],
			'tab_title'       => $sess['tab_title'],
		], $summary, gmdate('Y-m-d\TH:i:s\Z'), $group);
		file_put_contents($this->metaPath($sess['session_id']), json_encode($tomb, JSON_PRETTY_PRINT));
		$this->upsertIndex($tomb);
		$this->cli->successMsg($this->ellipsizeText("  Buried: " . $this->cleanSummaryText($summary, getenv('HOME') ?: ''), $this->termWidth()));

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
					$untargetable[] = ['ref' => $ref, 'title' => $surf['title'] ?? '', 'type' => $type];
				}

				$layout[] = $entry;
				$pos++;
			}
		}

		return ['layout' => $layout, 'members' => $members, 'untargetable' => $untargetable];
	}

	/**
	 * PURE. Human reason WHY a detected Claude surface is untargetable, from injectable
	 * facts, so the abort report tells JT whether to fix, wait, or --force:
	 *   type              cmux surface type
	 *   has_script        surface launched via a cmux resume wrapper
	 *   has_shell         that wrapper's shell chain is alive
	 *   has_claude        a live claude process exists under it
	 *   has_session_file  that claude has a ~/.claude/sessions/<pid>.json
	 *   cwd_conflict      statusline cwd could not be uniquely matched (ambiguous)
	 */
	public function untargetableReasonFor(array $f): string {
		$type = $f['type'] ?? 'terminal';
		if ($type !== 'terminal' && $type !== 'browser') {
			return 'cmux-native agent session (not a Claude CLI terminal) — unsupported by bury';
		}
		if (empty($f['has_script'])) {
			return !empty($f['cwd_conflict'])
				? 'fresh/non-resumed Claude — statusline cwd matches multiple sessions (ambiguous); can\'t bind safely'
				: 'fresh/non-resumed Claude — no unique statusline-cwd match to bind it';
		}
		if (empty($f['has_shell']))   { return 'resumed surface with no live shell — stale; nothing to bury'; }
		if (empty($f['has_claude']))  { return 'resumed Claude not running (exited or never launched) — no live session to bury'; }
		if (empty($f['has_session_file'])) { return 'Claude running but no session file yet (no conversation) — wait, or start a turn'; }
		if (!empty($f['bound_elsewhere'])) {
			$sid = substr((string) ($f['session_id'] ?? ''), 0, 8);
			return "duplicate live view of session {$sid} (already bound to {$f['bound_elsewhere']}) — close this extra tab";
		}
		if (!empty($f['session_reason'])) { return (string) $f['session_reason']; }
		return 'live Claude session present but could not be bound to this surface (ambiguous)';
	}

	/** Gather untargetable-surface facts (I/O) and delegate to untargetableReasonFor(). */
	protected function diagnoseUntargetableSurface(string $ref, string $type, array $debug, array $proc): string {
		$script = $debug[$ref]['script'] ?? null;
		$roots  = [];
		if ($script !== null) {
			foreach ($proc as $pid => $info) {
				if (strpos($info['cmd'], $script) !== false) { $roots[] = $pid; }
			}
		}
		$claude = null;
		foreach ($roots as $r) {
			$c = $this->cmux->descendantClaudePid($proc, (int) $r);
			if ($c) { $claude = $c; break; }
		}
		$sid = $claude !== null ? $this->cmux->sessionIdForPid((int) $claude) : null;

		// If this surface's session is live but bound to a DIFFERENT surface, it's a
		// duplicate view (same session on two surfaces; the join deduped to the other).
		$boundElsewhere = null; $sessionReason = null;
		if ($sid !== null) {
			foreach ($this->liveSessions() as $lr) {
				if ($lr['session_id'] !== $sid) { continue; }
				if (($lr['targetable'] ?? false) && $lr['surface_ref'] !== '' && $lr['surface_ref'] !== $ref) {
					$boundElsewhere = $lr['surface_ref'];
				} else {
					$sessionReason = $lr['reason'] ?: null;
				}
				break;
			}
		}

		return $this->untargetableReasonFor([
			'type'             => $type,
			'has_script'       => $script !== null,
			'has_shell'        => (bool) $roots,
			'has_claude'       => $claude !== null,
			'has_session_file' => $sid !== null,
			'session_id'       => $sid,
			'bound_elsewhere'  => $boundElsewhere,
			'session_reason'   => $sessionReason,
		]);
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

		// Abort on any detected-but-unbindable Claude, unless --force skips them. Each
		// line carries the SPECIFIC reason so JT knows whether to fix, wait, or --force.
		if ($cls['untargetable']) {
			$w     = $this->termWidth();
			$proc  = $this->cmux->parseProcTable($this->cmux->psProcTable());
			$debug = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
			$this->cli->msg('Workspace "' . $wsTitle . '" has ' . count($cls['untargetable']) . ' Claude surface(s) not safely targetable:', 'yellow');
			foreach ($cls['untargetable'] as $u) {
				$reason = $this->diagnoseUntargetableSurface($u['ref'], $u['type'] ?? 'terminal', $debug, $proc);
				$this->cli->msg('  ' . $this->ellipsizeText($u['ref'] . '  ' . $this->stripGlyph((string) $u['title']), $w - 2), 'yellow');
				$this->cli->msg('      ↳ ' . $this->ellipsizeText($reason, $w - 8), 'yellow');
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

		// Nothing actually buried (every member failed a gate): this is a FAILURE, not a
		// bury. Do not claim success, do not leave a group/manifest artifact behind, do
		// not close anything. Remove the pre-written (now-empty) group dir and exit non-zero.
		if ($buried === 0) {
			$this->removeGroupArtifact($group);
			$this->cli->exitErr("Buried 0 of " . count($cls['members']) . " session(s) in \"{$wsTitle}\" — every member was refused by a gate; workspace left intact. No group created.");
			return;
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

	/** Remove a workspace group directory + manifest (used to clean up a failed bury). */
	protected function removeGroupArtifact(string $group): void {
		$dir = $this->workspaceGroupDir($group);
		@unlink($this->manifestPath($group));
		if (is_dir($dir)) { @rmdir($dir); }
	}

	# =========================================================================
	# Width-aware output formatting (dotfiles-rgk). Pure formatters take an
	# explicit $width so they're testable at 60/80/120 cols without a real tty.
	# =========================================================================

	/** Terminal width from COLUMNS or `tput cols`; clamped, fallback 80. */
	protected function termWidth(): int {
		$c = getenv('COLUMNS');
		if ($c !== false && ctype_digit(trim($c))) { return max(40, (int) trim($c)); }
		$t = trim((string) shell_exec('tput cols 2>/dev/null'));
		if ($t !== '' && ctype_digit($t)) { return max(40, (int) $t); }
		return 80;
	}

	/** PURE. Truncate to at most $max display chars, appending … when cut. */
	public function ellipsizeText(string $s, int $max): string {
		$s = trim($s);
		if ($max <= 0) { return ''; }
		if (mb_strlen($s) <= $max) { return $s; }
		if ($max === 1) { return '…'; }
		return rtrim(mb_substr($s, 0, $max - 1)) . '…';
	}

	/** PURE. Truncate from the LEFT with a leading … (keeps the tail). */
	public function ellipsizeLeft(string $s, int $max): string {
		if ($max <= 0) { return ''; }
		if (mb_strlen($s) <= $max) { return $s; }
		if ($max === 1) { return '…'; }
		return '…' . mb_substr($s, -($max - 1));
	}

	/** PURE. Strip Claude's leading status glyph (e.g. ✳/⠂) from a tab title. */
	public function stripGlyph(string $title): string {
		return trim(preg_replace('/^[^\x00-\x7F]+\s*/u', '', trim($title)));
	}

	/** PURE. Strip machine noise from a raw summary: <tags>, [MACHINE_KEY: ...], home→~. */
	public function cleanSummaryText(string $s, string $home = ''): string {
		$s = preg_replace('/<[^>]*>/', ' ', $s);               // <command-message> …
		$s = preg_replace('/\[[A-Z0-9_]+:[^\]]*\]/', ' ', $s); // [CALL_ID: …] [MODE: …]
		if ($home !== '') { $s = str_replace($home, '~', $s); }
		return trim(preg_replace('/\s+/', ' ', $s));
	}

	/**
	 * PURE. A title-like label for a tombstone: prefer a cleaned first-prompt summary,
	 * but fall back to the session's own title when the summary is empty or just a bare
	 * slash-command (e.g. "/hotline:ringing"), then the workspace title.
	 */
	public function titleizeSummary(array $t, string $home = ''): string {
		$sum = $this->cleanSummaryText((string) ($t['summary'] ?? ''), $home);
		// A summary that's actually machine noise (a leaked caveat / skill preamble from
		// an older tombstone) is not a title — treat it as empty so we fall back.
		if (stripos($sum, 'Caveat: The messages below') === 0 || stripos($sum, 'Base directory for this skill:') === 0) { $sum = ''; }
		$tab = $this->stripGlyph((string) ($t['tab_title'] ?? ''));
		$goodTab = ($tab !== '' && $tab !== 'Terminal');
		if (($sum === '' || $sum[0] === '/') && $goodTab) { return $tab; }
		if ($sum !== '') { return $sum; }
		if ($goodTab) { return $tab; }
		$ws = trim((string) ($t['workspace_title'] ?? ''));
		return $ws !== '' ? $ws : '(untitled)';
	}

	/** PURE. Home→~, then elide middle components with … so the result fits $max. */
	public function shortenCwd(string $cwd, string $home, int $max): string {
		$cwd = rtrim($cwd, '/');
		if ($cwd === '' || $max <= 0) { return ''; }
		if ($home !== '' && ($cwd === $home || strncmp($cwd, $home . '/', strlen($home) + 1) === 0)) {
			$cwd = '~' . substr($cwd, strlen($home));
		}
		if (mb_strlen($cwd) <= $max) { return $cwd; }

		$abs    = ($cwd[0] === '/');
		$comps  = array_values(array_filter(explode('/', $cwd), fn($p) => $p !== ''));
		$prefix = $abs ? '' : (string) array_shift($comps); // '' (leading /) or '~'/first comp
		$tail   = [];
		for ($i = count($comps) - 1; $i >= 0; $i--) {
			$try = $prefix . '/…/' . implode('/', array_merge([$comps[$i]], $tail));
			if (mb_strlen($try) <= $max) { array_unshift($tail, $comps[$i]); }
			else { break; }
		}
		if ($tail) { return $prefix . '/…/' . implode('/', $tail); }
		return $this->ellipsizeLeft($cwd, $max); // nothing fits with prefix → keep the tail
	}

	/**
	 * PURE. One tombstone entry as display lines that never exceed $width and never
	 * wrap. Returns ['primary'=>string, 'secondary'=>?string]. Wide terminals get a
	 * single line (id · title · cwd · date); below the threshold it stacks the title on
	 * line 1 and dim cwd·date on line 2 — one consistent shape for grouped & loose.
	 */
	public function lsEntryLines(array $t, int $width, string $home, int $indent = 0): array {
		$id    = substr((string) $t['session_id'], 0, 8);
		$date  = substr((string) ($t['buried_at'] ?? ''), 0, 10);
		$title = $this->titleizeSummary($t, $home);
		$cwd   = (string) ($t['cwd'] ?? '');
		$pad   = str_repeat(' ', $indent);
		$STACK_BELOW = 100;

		if ($width >= $STACK_BELOW) {
			$avail = $width - $indent - 8 - 6 - strlen($date); // id + three 2-space gaps + date
			if ($avail >= 24) {
				$cwdMax   = min(40, intdiv($avail, 2));
				$shortCwd = $this->shortenCwd($cwd, $home, $cwdMax);
				$titleTxt = $this->ellipsizeText($title, $avail - mb_strlen($shortCwd));
				return ['primary' => $pad . $id . '  ' . $titleTxt . '  ' . $shortCwd . '  ' . $date, 'secondary' => null];
			}
		}

		// Stacked: bright title line + dim, indented cwd·date line. (Do NOT run the
		// secondary through ellipsizeText — it trims the leading indent; the fields are
		// already sized to fit within $width here.)
		$titleTxt  = $this->ellipsizeText($title, $width - $indent - 8 - 2);
		$primary   = $pad . $id . '  ' . $titleTxt;
		$dPad      = str_repeat(' ', $indent + 2);
		$cwdMax    = $width - mb_strlen($dPad) - 3 - strlen($date);
		$shortCwd  = $this->shortenCwd($cwd, $home, max(0, $cwdMax));
		$secondary = $dPad . ($shortCwd !== '' ? $shortCwd . ' · ' : '') . $date;
		return ['primary' => $primary, 'secondary' => $secondary];
	}

	/** PURE. Group header line, truncated to $width. */
	public function groupHeaderLine(string $title, int $count, string $date, int $width): string {
		$meta = sprintf('  (%d session%s)  %s', $count, $count === 1 ? '' : 's', $date);
		$titleMax = $width - 2 - mb_strlen($meta); // "▸ "
		return '▸ ' . $this->ellipsizeText($title, max(4, $titleMax)) . $meta;
	}

	public function listTombstones(): void {
		$this->printTombstones(false);
	}

	public function listTombstonesJson(): void {
		$this->printTombstones(true);
	}

	protected function printTombstones(bool $json): void {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		if (!$tombs) {
			if ($json) { echo json_encode(['workspaces' => [], 'sessions' => []], JSON_PRETTY_PRINT) . "\n"; return; }
			$this->cli->msg('Graveyard is empty.', 'yellow'); return;
		}
		usort($tombs, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		if ($json) { echo json_encode($this->lsJson($tombs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; return; }

		$w    = $this->termWidth();
		$home = getenv('HOME') ?: '';
		[$groups, $loose] = $this->groupTombstones($tombs);

		foreach ($groups as $gid => $members) {
			$title = $members[0]['group_title'] ?? '(workspace)';
			$date  = substr($members[0]['buried_at'] ?? '', 0, 10);
			$this->cli->msg($this->groupHeaderLine($title, count($members), $date, $w), 'green');
			$this->cli->msg('  ↻ graveyard resurrect --workspace ' . substr($gid, 0, 8), 'blue');
			foreach ($members as $t) { $this->printLsEntry($t, $w, $home, 4); }
		}
		if ($groups && $loose) { $this->cli->msg(''); }
		foreach ($loose as $t) { $this->printLsEntry($t, $w, $home, 0); }
	}

	protected function printLsEntry(array $t, int $width, string $home, int $indent): void {
		$lines = $this->lsEntryLines($t, $width, $home, $indent);
		$this->cli->msg($lines['primary']);
		if ($lines['secondary'] !== null) { $this->cli->msg($lines['secondary'], 'cyan'); }
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


	/**
	 * Search buried tombstones by a case-insensitive term across the human-meaningful
	 * metadata (workspace_title/tab_title/cwd/summary). With $fullText, also grep the
	 * rendered transcript body for tombstones whose metadata didn't already match.
	 * Results are sorted newest-first (buried_at desc). Returns the matching tombstones.
	 */
	public function searchTombstones(string $term, bool $fullText = false): array {
		$needle = mb_strtolower(trim($term));
		$tombs  = $this->readIndex()['tombstones'] ?? [];
		$hits   = [];
		foreach ($tombs as $t) {
			$meta = mb_strtolower(implode(' ', [
				(string) ($t['workspace_title'] ?? ''),
				(string) ($t['tab_title'] ?? ''),
				(string) ($t['cwd'] ?? ''),
				(string) ($t['summary'] ?? ''),
			]));
			if ($needle !== '' && str_contains($meta, $needle)) { $hits[] = $t; continue; }

			if ($fullText) {
				$tp = $this->transcriptPath($t['session_id']);
				if ($needle !== '' && is_file($tp) && $this->fileContains($tp, $needle)) { $hits[] = $t; }
			}
		}
		usort($hits, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		return $hits;
	}

	/** Streamed case-insensitive substring scan — reads line-by-line instead of slurping the file. */
	protected function fileContains(string $path, string $needleLower): bool {
		$h = @fopen($path, 'r');
		if (!$h) { return false; }
		$found = false;
		while (($line = fgets($h)) !== false) {
			if (str_contains(mb_strtolower($line), $needleLower)) { $found = true; break; }
		}
		fclose($h);
		return $found;
	}

	/** PURE: a search hit reduced to the stable JSON-friendly field set. */
	public function searchRowJson(array $t): array {
		return [
			'session_id'      => $t['session_id'] ?? '',
			'workspace_title' => $t['workspace_title'] ?? '',
			'tab_title'       => $t['tab_title'] ?? '',
			'cwd'             => $t['cwd'] ?? '',
			'summary'         => $t['summary'] ?? '',
			'buried_at'       => $t['buried_at'] ?? '',
			'last_active'     => $t['last_active'] ?? null,
		];
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

	/**
	 * Resolve a buried tombstone by fuzzy reference: exact session-id, unique session-id
	 * prefix, OR a workspace/tab title substring (case-insensitive) — the same resolution
	 * order as the live-session matchIdentifier(). Returns a result shape (no side-channel
	 * state): ['match' => ?tombstone, 'candidates' => tombstones[] (the ambiguous set),
	 * 'ambiguous' => bool]. Ambiguity is reported and yields match=null, never auto-picked.
	 */
	public function resolveTombstoneFuzzy(string $ref): array {
		$none = ['match' => null, 'candidates' => [], 'ambiguous' => false];
		$tombs = $this->readIndex()['tombstones'] ?? [];
		if (!$tombs || $ref === '') { return $none; }

		// 1) exact session-id
		$exact = array_values(array_filter($tombs, fn($t) => ($t['session_id'] ?? null) === $ref));
		if (count($exact) === 1) { return ['match' => $exact[0], 'candidates' => [], 'ambiguous' => false]; }

		// 2) unique session-id prefix (explicit so prefix wins over title, stays backward-compatible)
		$prefix = array_values(array_filter($tombs, fn($t) => str_starts_with((string) ($t['session_id'] ?? ''), $ref)));
		if (count($prefix) === 1) { return ['match' => $prefix[0], 'candidates' => [], 'ambiguous' => false]; }
		if (count($prefix) > 1) {
			$this->reportAmbiguousTombstones($ref, $prefix);
			return ['match' => null, 'candidates' => $prefix, 'ambiguous' => true];
		}

		// 3) title substring via the shared matcher (workspace_title/tab_title)
		$byTitle = $this->matchIdentifier($tombs, $ref);
		if (count($byTitle) === 1) { return ['match' => $byTitle[0], 'candidates' => [], 'ambiguous' => false]; }
		if (count($byTitle) > 1) {
			$this->reportAmbiguousTombstones($ref, $byTitle);
			return ['match' => null, 'candidates' => $byTitle, 'ambiguous' => true];
		}

		return $none;
	}

	/** Convenience: the matched tombstone, or null when none/ambiguous. */
	public function findTombstone(string $ref): ?array {
		return $this->resolveTombstoneFuzzy($ref)['match'];
	}

	protected function reportAmbiguousTombstones(string $ref, array $matches): void {
		$this->cli->msg("'{$ref}' is ambiguous — matches " . count($matches) . ' buried session(s):', 'yellow');
		foreach ($matches as $m) {
			$this->cli->msg(sprintf(
				'  %s  %-24.24s  %.40s  %s',
				substr((string) $m['session_id'], 0, 8),
				$m['workspace_title'] ?? '',
				$m['tab_title'] ?? '',
				substr((string) ($m['buried_at'] ?? ''), 0, 10)
			));
		}
	}

	public function printSearch(string $term, bool $json, bool $fullText): void {
		$hits = $this->searchTombstones($term, $fullText);
		if ($json) {
			echo json_encode(array_map(fn($t) => $this->searchRowJson($t), $hits), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
			return;
		}
		if (!$hits) { $this->cli->msg("No buried sessions match '{$term}'.", 'yellow'); return; }
		$w    = $this->termWidth();
		$home = getenv('HOME') ?: '';
		foreach ($hits as $t) { $this->printLsEntry($t, $w, $home, 0); }
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

	# =========================================================================
	# graveyard page (dotfiles-pnl): self-contained HTML overview of the store.
	# =========================================================================

	/**
	 * Regenerate the HTML overview of every tombstone (newest-first), write it to
	 * pageFilePath(), and open it in the browser unless $openInBrowser is false or
	 * no platform opener exists. Transcripts ship as per-session page-data/<id>.js
	 * files (JSONP-style — fetch() is CORS-blocked on file://) so page.html stays
	 * lean and the modal loads them JIT. Stale page-data files are pruned.
	 * Returns the written path.
	 */
	public function page(bool $openInBrowser = true): string {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		usort($tombs, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		$tombs = $this->stampPlotPositions($tombs);

		$dir = $this->pageDataDir();
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		$keep = [];
		foreach ($tombs as $t) {
			$sid = (string) $t['session_id'];
			$tp  = $this->transcriptPath($sid);
			if (!is_file($tp)) { continue; }
			file_put_contents($this->transcriptJsPath($sid), $this->pageTranscriptJs($sid, (string) file_get_contents($tp)));
			$keep[$sid . '.js'] = true;
		}
		foreach (glob($dir . '/*.js') ?: [] as $f) {
			if (!isset($keep[basename($f)])) { @unlink($f); }
		}

		$path = $this->pageFilePath();
		file_put_contents($path, $this->pageHtml($tombs, gmdate('Y-m-d\TH:i:s\Z'), getenv('HOME') ?: ''));
		$this->cli->successMsg('Wrote ' . $path . ' (' . count($tombs) . ' session(s)).');

		if ($openInBrowser) {
			$opener = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
			if (trim((string) shell_exec('command -v ' . $opener . ' 2>/dev/null')) !== '') {
				shell_exec($opener . ' ' . escapeshellarg($path) . ' >/dev/null 2>&1 &');
			} else {
				$this->cli->msg("No '{$opener}' on PATH — open the file manually.", 'yellow');
			}
		}
		return $path;
	}

	/**
	 * PURE. A transcript as an injectable page-data JS file: assigns window.GYT[id].
	 * json_encode does the string escaping; JSON_HEX_TAG prevents a transcript
	 * containing "</script>" from breaking out of the script block, and
	 * INVALID_UTF8_SUBSTITUTE keeps mangled bytes from failing the encode.
	 */
	public function pageTranscriptJs(string $id, string $text): string {
		return 'window.GYT=window.GYT||{};GYT['
			. json_encode($id)
			. ']='
			. json_encode($text, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
			. ';';
	}

	/**
	 * PURE. A family plot's accent hue, derived deterministically from the group
	 * id: the same family always wears the same color across regenerations.
	 * Picks from a muted palette of well-separated hues (used via CSS
	 * --plot-hue for the fence, legend, and a faint bg tint).
	 */
	public function plotHue(string $groupId): int {
		$palette = [30, 60, 95, 160, 205, 255, 300, 345];
		return $palette[crc32($groupId) % count($palette)];
	}

	/**
	 * PURE. Order tombstones into render units for the page: loose stones and
	 * "family plots" (workspace groups), newest-first. A plot's sort key is its
	 * newest member's buried_at; its members keep their original tab order
	 * (group_pos, via groupTombstones). Ties keep input order.
	 */
	public function pageUnits(array $tombs): array {
		[$groups, $loose] = $this->groupTombstones($tombs);
		$units = [];
		$ord = 0;
		foreach ($loose as $t) {
			$units[] = ['type' => 'stone', 'sort' => (string) ($t['buried_at'] ?? ''), 'tomb' => $t, 'ord' => $ord++];
		}
		foreach ($groups as $gid => $members) {
			$max = '';
			foreach ($members as $m) {
				$b = (string) ($m['buried_at'] ?? '');
				if (strcmp($b, $max) > 0) { $max = $b; }
			}
			$title = trim((string) ($members[0]['group_title'] ?? ''));
			$units[] = [
				'type'    => 'plot',
				'sort'    => $max,
				'title'   => $title !== '' ? $title : '(family plot)',
				'hue'     => $this->plotHue((string) $gid),
				'members' => $members,
				'ord'     => $ord++,
			];
		}
		usort($units, fn($a, $b) => strcmp($b['sort'], $a['sort']) ?: ($a['ord'] <=> $b['ord']));
		return array_map(function ($u) { unset($u['ord']); return $u; }, $units);
	}

	/**
	 * PURE. Map a workspace manifest's claude members to their layout positions:
	 * claude_session_id => ['pane' => pane_index, 'tab' => index_in_pane] (0-based).
	 * Non-claude surfaces and unbound entries are skipped.
	 */
	public function manifestPositions(array $manifest): array {
		$out = [];
		foreach ($manifest['layout'] ?? [] as $e) {
			if (($e['kind'] ?? '') !== 'claude') { continue; }
			$sid = (string) ($e['claude_session_id'] ?? '');
			if ($sid === '') { continue; }
			$out[$sid] = [
				'pane' => (int) ($e['pane_index'] ?? 0),
				'tab'  => (int) ($e['index_in_pane'] ?? 0),
			];
		}
		return $out;
	}

	/**
	 * I/O. Stamp each grouped tombstone with its 'plot_pos' (pane/tab) read from the
	 * workspace group's manifest, when one is on disk. Ungrouped tombs and groups
	 * without a manifest are left untouched.
	 */
	protected function stampPlotPositions(array $tombs): array {
		$gids = [];
		foreach ($tombs as $t) {
			$g = (string) ($t['group_id'] ?? '');
			if ($g !== '') { $gids[$g] = true; }
		}
		$posByGid = [];
		foreach (array_keys($gids) as $gid) {
			$mp = $this->manifestPath($gid);
			if (!is_file($mp)) { continue; }
			$m = json_decode((string) @file_get_contents($mp), true);
			if (is_array($m)) { $posByGid[$gid] = $this->manifestPositions($m); }
		}
		foreach ($tombs as &$t) {
			$g   = (string) ($t['group_id'] ?? '');
			$sid = (string) ($t['session_id'] ?? '');
			if ($g !== '' && isset($posByGid[$g][$sid])) { $t['plot_pos'] = $posByGid[$g][$sid]; }
		}
		unset($t);
		return $tombs;
	}

	/**
	 * PURE. One headstone <button>: title + buried-date/short-id meta, with the
	 * modal's display strings riding in escaped data-* attributes (the modal fills
	 * via textContent — no HTML injection). A tombstone carrying a plot_pos (pane/
	 * tab from the workspace manifest, 0-based) shows a 1-based [P…,T…] suffix on
	 * both the stone meta and the modal dates line.
	 */
	protected function stoneHtml(array $t, int $i, string $home): string {
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$sid    = (string) ($t['session_id'] ?? '');
		$sid8   = substr($sid, 0, 8);
		$title  = $this->titleizeSummary($t, $home);
		$cwd    = $this->shortenCwd((string) ($t['cwd'] ?? ''), $home, 200);
		$ws     = trim((string) ($t['workspace_title'] ?? ''));
		$tab    = $this->stripGlyph((string) ($t['tab_title'] ?? ''));
		$buried = substr((string) ($t['buried_at'] ?? ''), 0, 10);
		$active = substr((string) ($t['last_active'] ?? ''), 0, 10);
		$model  = (string) ($t['model'] ?? '');

		$pos = '';
		if (isset($t['plot_pos']['pane'], $t['plot_pos']['tab'])) {
			$pos = ' [P' . ((int) $t['plot_pos']['pane'] + 1) . ',T' . ((int) $t['plot_pos']['tab'] + 1) . ']';
		}

		// The modal shows the transcript path (display: ~/-collapsed; copy: FULL path).
		$tpath      = $this->transcriptPath($sid);
		$tpathShort = $home !== '' ? str_replace($home, '~', $tpath) : $tpath;

		$where = implode(' · ', array_filter([$cwd, $ws . ($tab !== '' ? ' / ' . $tab : '')], fn($p) => trim($p) !== ''));
		$dates = 'buried ' . $buried
			. ($active !== '' ? ' · last active ' . $active : '')
			. ($model !== '' ? ' · ' . $model : '')
			. $pos;

		return '    <button type="button" class="stone" style="--i:' . min($i, 20) . '"'
			. ' @click="show($el)"'
			. ' x-show="stoneVisible($el)"'
			. ' data-id="' . $e($sid) . '"'
			. ' data-sid8="' . $e($sid8) . '"'
			. ' data-title="' . $e($title) . '"'
			. ' data-where="' . $e($where) . '"'
			. ' data-dates="' . $e($dates) . '"'
			. ' data-tpath="' . $e($tpath) . '"'
			. ' data-tpath-short="' . $e($tpathShort) . '">'
			. '<span class="stone-title">' . $e($title) . '</span>'
			. '<span class="stone-meta">' . $e($buried) . ' · ' . $e($sid8) . $e($pos) . '</span>'
			. '</button>';
	}

	/**
	 * I/O. The inlined Alpine.js source (vendored asset), so the generated page
	 * stays self-contained — no external <script src>, which file:// and a strict
	 * CSP both forbid. Resolves the asset relative to this lib whether it lives in
	 * bin/ (pre-migration) or src/ (post-migration). Empty string if not found —
	 * the page still renders, just non-interactive (and the Alpine test fails loud).
	 */
	protected function alpineJs(): string {
		foreach ([
			dirname(__DIR__) . '/src/assets/alpine.min.js', // lib in bin/
			__DIR__ . '/assets/alpine.min.js',              // lib moved into src/
			__DIR__ . '/../src/assets/alpine.min.js',
		] as $p) {
			if (is_file($p)) { return rtrim((string) file_get_contents($p)); }
		}
		return '';
	}

	/**
	 * PURE. Self-contained HTML overview: a full-width FIELD of compact headstones
	 * (auto-fill grid), with workspace groups fenced into "family plots" (a
	 * <fieldset> per group, legend = group title, members in tab order). Clicking
	 * a stone opens a <dialog> with the full card; the transcript loads JIT from
	 * page-data/<id>.js (see page()) and scrolls to the latest end. The modal's
	 * session id is click-to-copy (full id → clipboard, "copied ✓" flash).
	 * Display strings ride in escaped data-* attributes and the modal fills via
	 * textContent — no HTML injection from titles/cwds/transcripts. Units are
	 * rendered in pageUnits() order (newest-first). No external assets; one
	 * inline <script>.
	 */
	public function pageHtml(array $tombs, string $generatedAt, string $home = ''): string {
		$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$rows = [];
		$i = 0;
		foreach ($this->pageUnits($tombs) as $u) {
			if ($u['type'] === 'stone') {
				$rows[] = $this->stoneHtml($u['tomb'], $i++, $home);
				continue;
			}
			$stones = [];
			foreach ($u['members'] as $t) { $stones[] = $this->stoneHtml($t, $i++, $home); }
			// NOTE: the fieldset must NOT be display:grid (Chromium/WebKit render
			// grid fieldsets wrong) — the inner div carries the stone grid instead.
			$rows[] = '    <fieldset class="plot" style="--plot-hue:' . (int) $u['hue'] . '" data-title="' . $e($u['title']) . '" x-show="plotVisible($el)"><legend>' . $e($u['title']) . '</legend>'
				. '<div class="plot-stones">' . "\n" . implode("\n", $stones) . "\n" . '    </div></fieldset>';
		}

		$count   = count($tombs);
		$listing = $rows
			? implode("\n", $rows)
			: '    <p class="none empty">🪦<br>The graveyard is empty — no sessions lie here yet.</p>';

		return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Claude Graveyard</title>
<style>
:root {
	color-scheme: dark;
	--bg: #0b0d0b;
	--mist: #12160f;
	--stone: #1b1f1a;
	--stone-edge: #2e342c;
	--inscription: #e9e4d6;
	--weathered: #9aa294;
	--moss: #a8c196;
	--crypt: #070907;
	--serif: ui-serif, "New York", "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
	--mono: ui-monospace, "SF Mono", SFMono-Regular, Menlo, Consolas, monospace;
}
* { box-sizing: border-box; }
[x-cloak] { display: none !important; }
body {
	margin: 0; padding: 1.75rem 0 4rem;
	background:
		radial-gradient(ellipse 90% 45% at 50% -8%, rgba(168,193,150,.07), transparent 65%),
		linear-gradient(var(--mist), var(--bg) 45%) fixed,
		var(--bg);
	color: var(--inscription);
	font: 15px/1.6 var(--serif);
}
header.top { text-align: center; padding: 0 clamp(1rem, 3.5vw, 3rem); margin-bottom: 1.6rem; }
.crest { font-size: 2.1rem; line-height: 1.25; filter: grayscale(.4) brightness(.9); text-shadow: 0 6px 16px rgba(0,0,0,.65); }
h1 { margin: .3rem 0 .45rem; font: 500 clamp(1.05rem, 2.2vw, 1.5rem)/1.2 var(--serif); letter-spacing: .22em; text-transform: uppercase; white-space: nowrap; }
header.top .meta { margin: 0; }
.meta { color: var(--weathered); font: .78rem var(--mono); letter-spacing: .04em; }
.divider { display: flex; align-items: center; gap: 1rem; margin: 1.15rem 0 0; }
.divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, transparent, rgba(154,162,148,.4), transparent); }
.divider span { font-size: .95rem; line-height: 1; filter: grayscale(.35) brightness(.95); }
.toolbar { display: flex; justify-content: center; margin: 1.1rem 0 0; }
.search {
	width: min(30rem, 82vw); appearance: none; -webkit-appearance: none;
	background: rgba(233,228,214,.03); color: var(--inscription);
	border: 1px solid var(--stone-edge); border-radius: 999px;
	padding: .5rem 1rem; font: .82rem var(--mono); letter-spacing: .04em;
	transition: border-color .16s ease, box-shadow .16s ease;
}
.search::placeholder { color: #6b7263; }
.search:focus { outline: none; border-color: rgba(168,193,150,.55); box-shadow: 0 0 0 1px rgba(168,193,150,.2); }
.search::-webkit-search-cancel-button { filter: grayscale(1) brightness(1.4); cursor: pointer; }
.web { position: fixed; top: .3rem; z-index: 2; font-size: 1.55rem; opacity: .25; filter: grayscale(.6); pointer-events: none; }
.web-l { left: .45rem; }
.web-r { right: .45rem; transform: scaleX(-1); }
.fog {
	position: fixed; left: 0; right: 0; bottom: 0; height: 32vh; z-index: 1; pointer-events: none;
	background:
		radial-gradient(ellipse 65% 100% at 18% 105%, rgba(154,162,148,.09), transparent 70%),
		radial-gradient(ellipse 55% 90% at 78% 105%, rgba(154,162,148,.07), transparent 70%);
	animation: drift 26s ease-in-out infinite alternate;
}
@keyframes drift { from { transform: translateX(-2.5%); } to { transform: translateX(2.5%); } }
main#yard { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 1.1rem; padding: 0 clamp(1rem, 3.5vw, 3rem); }
main#yard > .stone { flex: 1 1 172px; max-width: 230px; }
.stone {
	appearance: none; -webkit-appearance: none; font: inherit; text-align: left; cursor: pointer;
	color: var(--inscription);
	background: linear-gradient(180deg, #23291f, var(--stone) 45%);
	border: 1px solid var(--stone-edge); border-bottom: 3px solid var(--stone-edge);
	border-radius: 46% 46% 6px 6px / 26px 26px 6px 6px;
	padding: 1.4rem .95rem .8rem; min-height: 8.25rem;
	display: flex; flex-direction: column; gap: .4rem;
	box-shadow: inset 0 1px 0 rgba(233,228,214,.05), 0 14px 22px -16px rgba(0,0,0,.85);
	transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease;
	animation: rise .45s ease-out both;
	animation-delay: calc(var(--i, 0) * 30ms);
}
@keyframes rise { from { opacity: 0; transform: translateY(10px); } }
.stone:hover, .stone:focus-visible {
	transform: translateY(-3px); border-color: rgba(168,193,150,.6); outline: none;
	box-shadow: inset 0 1px 0 rgba(233,228,214,.05), 0 18px 26px -16px rgba(0,0,0,.9), 0 0 0 1px rgba(168,193,150,.25);
}
.stone-title { font: 600 .86rem/1.35 var(--serif); display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
.stone-meta { margin-top: auto; font: .66rem var(--mono); color: var(--weathered); letter-spacing: .05em; }
.empty { flex: 1 1 100%; text-align: center; font-size: 1rem; line-height: 2.2; padding: 3rem 0; }
.none { color: #6b7263; font-style: italic; font-size: .88rem; }
fieldset.plot {
	flex: 0 1 auto; max-width: 100%; min-width: 0; margin: 0; padding: 0 .9rem .95rem;
	border: 1px dashed hsl(var(--plot-hue, 95) 34% 62% / .6); border-radius: 12px;
	background: hsl(var(--plot-hue, 95) 30% 58% / .075);
}
fieldset.plot legend {
	padding: 0 .55rem; font: .72rem var(--mono); letter-spacing: .18em;
	text-transform: uppercase; color: hsl(var(--plot-hue, 95) 42% 72%);
}
fieldset.plot legend::before { content: "🥀 "; filter: grayscale(.45); }
.plot-stones { display: flex; flex-wrap: wrap; gap: 1.1rem; padding-top: .35rem; }
.plot-stones .stone { flex: 0 0 172px; }
body:has(dialog#plot[open]) { overflow: hidden; }
dialog#plot { margin: auto; padding: 0; border: none; background: transparent; width: min(760px, 92vw); }
dialog#plot::backdrop { background: rgba(4,6,4,.72); backdrop-filter: blur(2px); }
dialog#plot .card { position: relative; max-height: 86vh; overflow: auto; overscroll-behavior: contain; }
.card {
	background: linear-gradient(180deg, #21261f, var(--stone) 32%);
	border: 1px solid var(--stone-edge); border-bottom: 3px solid var(--stone-edge);
	border-radius: 22px 22px 6px 6px;
	padding: 1.35rem 1.6rem 1.15rem;
	box-shadow: inset 0 1px 0 rgba(233,228,214,.05), 0 18px 30px -18px rgba(0,0,0,.85);
}
.card header { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; padding: 0 1.8rem .6rem 0; margin-bottom: .55rem; border-bottom: 1px solid rgba(233,228,214,.09); }
.card h2 { margin: 0; font-size: 1.12rem; font-weight: 600; line-height: 1.35; overflow-wrap: anywhere; }
.idcopy {
	background: none; border: 1px solid transparent; border-radius: 6px; cursor: copy;
	font: .78rem var(--mono); color: var(--weathered); letter-spacing: .08em;
	padding: .12rem .45rem; white-space: nowrap; flex-shrink: 0;
}
.idcopy:hover, .idcopy:focus-visible { color: var(--inscription); border-color: var(--stone-edge); outline: none; }
.idcopy.ok { color: var(--moss); }
.card .meta { margin: .18rem 0; overflow-wrap: anywhere; }
.crypt-cap { margin: .8rem 0 0; font: .68rem var(--mono); letter-spacing: .16em; text-transform: uppercase; color: var(--moss); }
#m-body pre {
	max-height: 50vh; overflow: auto; overscroll-behavior: contain; margin: .4rem 0 0;
	background: var(--crypt); border: 1px solid #20261f; border-radius: 8px;
	padding: .9rem 1rem; white-space: pre-wrap; overflow-wrap: anywhere;
	font: .8rem/1.55 var(--mono); color: #b9c0ae;
	animation: exhume .25s ease-out;
}
@keyframes exhume { from { opacity: 0; transform: translateY(-4px); } }
#m-close {
	position: absolute; top: .85rem; right: .95rem; width: 1.9rem; height: 1.9rem;
	border-radius: 50%; background: rgba(233,228,214,.04);
	border: 1px solid var(--stone-edge); color: var(--weathered);
	font-size: .8rem; line-height: 1; display: grid; place-items: center;
	cursor: pointer; padding: 0;
	transition: color .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}
#m-close:hover, #m-close:focus-visible {
	color: var(--moss); border-color: rgba(168,193,150,.6); outline: none;
	box-shadow: 0 0 0 1px rgba(168,193,150,.25), 0 0 12px rgba(168,193,150,.15);
	transform: rotate(90deg);
}
.tpath {
	display: block; margin: .55rem 0 0; padding: 0; background: none; border: none;
	font: .68rem var(--mono); color: var(--weathered); letter-spacing: .04em;
	cursor: copy; text-align: left; overflow-wrap: anywhere;
}
.tpath:hover, .tpath:focus-visible { color: var(--inscription); outline: none; }
.tpath.ok { color: var(--moss); }
footer { padding: 0 clamp(1rem, 3.5vw, 3rem); margin-top: 3rem; text-align: center; }
footer .divider { margin: 0 0 1.1rem; }
footer p { margin: .3rem 0; }
footer .epitaph { color: #6b7263; }
@media (prefers-reduced-motion: reduce) {
	.stone, #m-body pre, .fog { animation: none; }
}
@media (max-width: 560px) {
	h1 { letter-spacing: .12em; }
	header.top .meta { font-size: .68rem; }
}
</style>
</head>
<body x-data="graveyard()">
<span class="web web-l" aria-hidden="true">🕸</span>
<span class="web web-r" aria-hidden="true">🕸</span>
<header class="top">
  <div class="crest" aria-hidden="true">🪦</div>
  <h1>The Claude Graveyard</h1>
  <p class="meta">' . $count . ' session' . ($count === 1 ? '' : 's') . ' lie' . ($count === 1 ? 's' : '') . ' here · generated ' . $e($generatedAt) . '</p>
  <div class="divider" aria-hidden="true"><span>💀</span></div>
  <div class="toolbar">
    <input type="search" class="search" x-model="search" placeholder="search the graveyard…" aria-label="Search buried sessions" spellcheck="false" autocomplete="off">
  </div>
</header>
<main id="yard">
' . $listing . '
  <p class="none empty" x-cloak x-show="search.trim() && !hasMatches()">🔍<br>Nothing here matches “<span x-text="search.trim()"></span>”.</p>
</main>
<footer class="meta">
  <div class="divider" aria-hidden="true"><span>⚰️</span></div>
  <p>resurrect a session: graveyard resurrect &lt;id&gt;</p>
  <p class="epitaph">❦</p>
</footer>
<dialog id="plot" x-ref="dlg" @close="onClose()" @click.self="$refs.dlg.close()">
  <article class="card">
    <form method="dialog"><button id="m-close" aria-label="Close">✕</button></form>
    <header>
      <h2 id="m-title" x-text="item.title"></h2>
      <button type="button" id="m-id" class="idcopy" :class="{ ok: copiedId }" @click="copy(item.id, \'id\')" x-text="copiedId ? \'copied ✓\' : item.sid8" title="copy full session id"></button>
    </header>
    <p class="meta" id="m-where" x-text="item.where"></p>
    <p class="meta" id="m-dates" x-text="item.dates"></p>
    <div id="m-body" x-ref="body"></div>
    <button type="button" id="m-tpath" class="tpath" :class="{ ok: copiedPath }" @click="copy(item.tpath, \'path\')" x-text="copiedPath ? \'copied ✓\' : item.tpathShort" title="copy full transcript path"></button>
  </article>
</dialog>
<div class="fog" aria-hidden="true"></div>
<script>
document.addEventListener("alpine:init", function () {
	Alpine.data("graveyard", function () {
		return {
			item: {},
			copiedId: false,
			copiedPath: false,
			search: "",
			matchText: function (text) {
				return !this.search || (text || "").toLowerCase().indexOf(this.search.toLowerCase().trim()) !== -1;
			},
			stoneVisible: function (el) {
				var plot = el.closest("fieldset.plot");
				if (plot && this.matchText(plot.dataset.title)) { return true; } // plot title matches: keep all members
				return this.matchText(el.dataset.title);
			},
			plotVisible: function (el) {
				if (this.matchText(el.dataset.title)) { return true; }
				var stones = el.querySelectorAll(".stone");
				for (var i = 0; i < stones.length; i++) { if (this.matchText(stones[i].dataset.title)) { return true; } }
				return false;
			},
			hasMatches: function () {
				var q = this.search; // touch search so this getter re-runs on input
				var stones = document.querySelectorAll("#yard .stone");
				for (var i = 0; i < stones.length; i++) { if (this.stoneVisible(stones[i])) { return true; } }
				return false;
			},
			show: function (el) {
				var d = el.dataset;
				this.item = {
					id: d.id, sid8: d.sid8, title: d.title, where: d.where,
					dates: d.dates, tpath: d.tpath, tpathShort: d.tpathShort
				};
				this.copiedId = false;
				this.copiedPath = false;
				this.$refs.dlg.showModal();
				this.exhume(d.id);
			},
			onClose: function () { this.copiedId = false; this.copiedPath = false; },
			exhume: function (id) {
				var body = this.$refs.body;
				body.innerHTML = \'<p class="none">⛏ exhuming…</p>\';
				var render = function (text) {
					body.innerHTML = "";
					var cap = document.createElement("div");
					cap.className = "crypt-cap";
					cap.textContent = "⚰ exhumed transcript";
					body.appendChild(cap);
					var pre = document.createElement("pre");
					pre.textContent = text;
					body.appendChild(pre);
					pre.scrollTop = pre.scrollHeight;
				};
				if (window.GYT && Object.prototype.hasOwnProperty.call(window.GYT, id)) { render(window.GYT[id]); return; }
				var s = document.createElement("script");
				s.src = "page-data/" + encodeURIComponent(id) + ".js";
				s.onload = function () { render(window.GYT[id]); };
				s.onerror = function () { body.innerHTML = \'<p class="none">(no transcript archived)</p>\'; };
				document.head.appendChild(s);
			},
			copy: function (text, which) {
				if (!text) { return; }
				var self = this;
				var flash = function () {
					if (which === "id") { self.copiedId = true; setTimeout(function () { self.copiedId = false; }, 900); }
					else { self.copiedPath = true; setTimeout(function () { self.copiedPath = false; }, 900); }
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(flash, function () { self.legacyCopy(text); flash(); });
				} else { this.legacyCopy(text); flash(); }
			},
			legacyCopy: function (text) {
				var ta = document.createElement("textarea");
				ta.value = text;
				ta.setAttribute("readonly", "");
				ta.style.position = "fixed";
				ta.style.opacity = "0";
				document.body.appendChild(ta);
				ta.select();
				try { document.execCommand("copy"); } catch (err) {}
				document.body.removeChild(ta);
			}
		};
	});
});
</script>
<script>' . $this->alpineJs() . '</script>
</body>
</html>
';
	}

	public function resurrect(string $prefix, bool $fromTranscript = false): void {
		$res = $this->resolveTombstoneFuzzy($prefix);
		$t   = $res['match'];
		if (!$t) {
			if ($res['ambiguous']) {
				$this->cli->exitErr("'{$prefix}' is ambiguous — narrow it or pass a full session-id.");
			}
			$this->cli->exitErr("No buried session matches '{$prefix}'.");
		}

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

	/** PURE: a candidate row reduced to the stable JSON-friendly field set (idle_seconds stays numeric). */
	public function candidatesJson(array $rows): array {
		return array_map(fn($r) => [
			'session_id'      => $r['session_id'] ?? '',
			'idle_seconds'    => (int) ($r['idle_seconds'] ?? 0),
			'busy'            => (bool) ($r['busy'] ?? false),
			'targetable'      => (bool) ($r['targetable'] ?? true),
			'reason'          => $r['reason'] ?? '',
			'workspace_title' => $r['workspace_title'] ?? '',
			'tab_title'       => $r['tab_title'] ?? '',
			'cwd'             => $r['cwd'] ?? '',
		], $rows);
	}

	/** PURE: buried tombstones as {workspaces:[{group_id,title,sessions[]}], sessions:[loose...]}. */
	public function lsJson(array $tombs): array {
		[$groups, $loose] = $this->groupTombstones($tombs);
		$workspaces = [];
		foreach ($groups as $gid => $members) {
			$workspaces[] = [
				'group_id' => $gid,
				'title'    => $members[0]['group_title'] ?? '',
				'sessions' => array_map(fn($t) => $this->searchRowJson($t), $members),
			];
		}
		return [
			'workspaces' => $workspaces,
			'sessions'   => array_map(fn($t) => $this->searchRowJson($t), $loose),
		];
	}

	public function printCandidates(bool $porcelain, bool $json = false): void {
		$rows = $this->candidates();
		if ($json) { echo json_encode($this->candidatesJson($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; return; }
		if ($porcelain) {
			foreach ($rows as $r) { echo $this->formatCandidatePorcelain($r) . "\n"; }
			return;
		}
		if (!$rows) { $this->cli->msg('No buryable sessions.', 'yellow'); return; }
		$w    = $this->termWidth();
		$home = getenv('HOME') ?: '';
		foreach ($rows as $r) {
			$targetable = $r['targetable'] ?? true;
			$this->cli->msg($this->candidateLine($r, $w, $home), $targetable ? '' : 'yellow');
			if (!$targetable) {
				$this->cli->msg($this->ellipsizeText('          ⚠ ' . $r['reason'], $w), 'yellow');
			}
		}
	}

	/** PURE. One width-bounded candidate line: id · idle · state · title · cwd (· ⚠ if untargetable). */
	public function candidateLine(array $r, int $width, string $home): string {
		$id    = substr((string) $r['session_id'], 0, 8);
		$idle  = $this->idleHuman((int) $r['idle_seconds']);
		$state = ($r['busy'] ?? false) ? 'busy' : 'idle';
		$flag  = ($r['targetable'] ?? true) ? '' : ' ⚠';
		$title = $this->stripGlyph((string) ($r['tab_title'] ?? ''));
		if ($title === '' || $title === 'Terminal') { $title = (string) ($r['workspace_title'] ?? ''); }
		if ($title === '') { $title = '(untitled)'; }
		$left = sprintf('%s  %-4s %-4s', $id, $idle, $state);

		$avail = $width - mb_strlen($left) - 2 - mb_strlen($flag);
		if ($avail < 24) {
			return $left . '  ' . $this->ellipsizeText($title, max(0, $avail)) . $flag;
		}
		$cwdMax   = min(40, intdiv($avail, 2));
		$shortCwd = $this->shortenCwd((string) ($r['cwd'] ?? ''), $home, $cwdMax);
		$titleTxt = $this->ellipsizeText($title, $avail - 2 - mb_strlen($shortCwd));
		return $left . '  ' . $titleTxt . '  ' . $shortCwd . $flag;
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

		$w = $this->termWidth();
		$home = getenv('HOME') ?: '';
		$this->cli->msg($this->ellipsizeText(substr($s['session_id'], 0, 8) . '  ' . $this->stripGlyph((string) ($s['tab_title'] ?: $s['workspace_title'])), $w), 'cyan');
		$this->cli->msg($this->ellipsizeText(sprintf('%s   %s   idle %s', $this->shortenCwd((string) $s['cwd'], $home, max(20, $w - 30)), $s['model'] ?: '(unknown)', $this->idleHuman((int) $s['idle_seconds'])), $w), 'cyan');
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
	/**
	 * PURE. Genuine conversation turns from decoded JSONL entries: user/assistant turns
	 * that are not synthetic/command noise (Cmux::isSyntheticEntry) and carry text.
	 * Returns [['role'=>'user'|'assistant','text'=>string], ...] with text tag-stripped
	 * and whitespace-collapsed. Shared by renderTurns (peek) and gate 2.
	 */
	public function genuineTurns(array $entries): array {
		$out = [];
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
			$text = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $text)));
			if ($text === '') { continue; } // tool-only / empty turn
			$out[] = ['role' => $type, 'text' => $text];
		}
		return $out;
	}

	public function renderTurns(array $entries, int $limit = 6, int $width = 160): string {
		$turns = [];
		foreach ($this->genuineTurns($entries) as $t) {
			$text = mb_strlen($t['text']) > $width ? mb_substr($t['text'], 0, $width - 1) . '…' : $t['text'];
			$turns[] = ($t['role'] === 'user' ? '❯ ' : '⏺ ') . $text;
		}
		$turns = array_slice($turns, -$limit);
		return $turns ? implode("\n", $turns) . "\n" : '';
	}

	/**
	 * Needles for GATE 2 (dotfiles-c8a fix): the text of the LAST $count genuine turns
	 * from a session's JSONL. Matching on the tail (not the first turn) survives
	 * compaction, session bridging, and machine-caveat/skill-preamble opening turns —
	 * all of which mean the first turn may be absent from the rendered /export, while
	 * recent turns are always present.
	 */
	public function recentTurnNeedles(string $sessionId, string $cwd, int $count = 6): array {
		$jsonl = $this->cmux->jsonlPathFor($sessionId, $cwd);
		if (!is_file($jsonl)) { return []; }
		$entries = [];
		foreach (file($jsonl) as $line) { $e = json_decode($line, true); if ($e) { $entries[] = $e; } }
		$turns = $this->genuineTurns($entries);
		$tail  = array_slice($turns, -$count);
		return array_map(fn($t) => $t['text'], $tail);
	}

	/**
	 * PURE. GATE 2: does the exported transcript belong to the target? Passes if ANY of
	 * the session's recent genuine turns appears in the rendered transcript. Returns
	 * true when there are no usable needles (cannot assert → do not block).
	 */
	public function transcriptBelongsToSession(string $transcriptText, array $needles): bool {
		$hasNeedle = false;
		foreach ($needles as $n) {
			if (trim((string) $n) === '') { continue; }
			$hasNeedle = true;
			if ($this->transcriptMatchesSession($transcriptText, (string) $n)) { return true; }
		}
		return !$hasNeedle;
	}
}
