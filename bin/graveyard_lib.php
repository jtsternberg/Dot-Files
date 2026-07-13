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

	public function buildTombstone(array $session, array $surface, string $summary, string $buriedAt): array {
		$sessionId = $session['session_id'];
		$cwd       = $session['cwd'] ?? '';
		$lastActive = null;
		$jsonl = $this->cmux->jsonlPathFor($sessionId, $cwd);
		if (is_file($jsonl)) {
			$lastActive = gmdate('Y-m-d\TH:i:s\Z', filemtime($jsonl));
		}
		return [
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
		$byTty = $this->cmux->loadClaudeSessions();
		$tree  = $this->cmux->tree();
		$now   = time();
		$out   = [];

		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				foreach ($ws['panes'] ?? [] as $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$tty = $surf['tty'] ?? '';
						if (!$tty || empty($byTty[$tty])) { continue; }
						$sess = $byTty[$tty];
						$ts   = $this->cmux->lastRealActivity($sess['session_id'], $sess['cwd'] ?? '');
						$idle = $ts !== null ? ($now - $ts) : PHP_INT_MAX;
						$out[] = [
							'session_id'      => $sess['session_id'],
							'cwd'             => $sess['cwd'] ?? '',
							'model'           => $sess['model'] ?? null,
							'skip_perms'      => (bool) ($sess['skip_perms'] ?? false),
							'pid'             => $sess['pid'] ?? null,
							'tty'             => $tty,
							'surface_ref'     => $surf['ref'] ?? '',
							'surface_id'      => $surf['id'] ?? ($surf['ref'] ?? ''),
							'workspace_ref'   => $ws['ref'] ?? '',
							'workspace_title' => $ws['title'] ?? '',
							'tab_title'       => $surf['title'] ?? '',
							'idle_seconds'    => $idle,
						];
					}
				}
			}
		}
		return $this->dedupBySessionId($out);
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

	public function teardown(array $sess): bool {
		$wsRef = $sess['workspace_ref'] ?? '';
		$count = $wsRef ? $this->cmux->workspaceSurfaceCount($wsRef) : 0;

		if ($count <= 1) {
			$cmd = 'cmux close-workspace --workspace ' . escapeshellarg($wsRef);
		} else {
			$cmd = 'cmux close-surface --surface ' . escapeshellarg($sess['surface_ref']);
		}
		$res = $this->cli->getCommandOutputAndExitCode($cmd);

		$pid = $sess['pid'] ?? null;
		if ($pid && $this->cmux->pidIsAlive((int) $pid)) {
			if (function_exists('posix_kill')) {
				posix_kill((int) $pid, SIGTERM);
			} else {
				$this->cli->runCommand('kill ' . (int) $pid);
			}
		}

		if ($pid > 0) {
			$deadline = time() + 3;
			while (time() < $deadline && $this->cmux->pidIsAlive((int) $pid)) { usleep(200000); }
			if ($this->cmux->pidIsAlive((int) $pid)) {
				if (function_exists('posix_kill')) { posix_kill((int) $pid, 9); }
				else { $this->cli->runCommand('kill -9 ' . (int) $pid); }
			}
		}

		return ($res['exitCode'] ?? 1) === 0;
	}

	public function buryOne(array $sess, bool $force, bool $autoConfirm): bool {
		$idFloor = self::IDLE_FLOOR_DEFAULT;
		$screen  = $this->readLastScreen($sess['surface_ref'], $sess['workspace_ref']);
		if ($this->isBusy((int) $sess['idle_seconds'], $idFloor, $screen)) {
			if (!$force) {
				$this->cli->msg("  Skipping {$sess['tab_title']} — session looks busy (use --force to override).", 'yellow');
				return false;
			}
			$this->cli->msg('  Session looks busy but --force given; proceeding.', 'yellow');
		}

		$this->cli->msg("  Exporting transcript for " . substr($sess['session_id'], 0, 8) . '…', 'cyan');
		if (!$this->exportTranscript($sess)) {
			$this->cli->err("  Export failed (no transcript written) — leaving session ALIVE.");
			return false;
		}

		$summary = $this->deriveSummary($sess);
		$tomb = $this->buildTombstone($sess, [
			'workspace_title' => $sess['workspace_title'],
			'tab_title'       => $sess['tab_title'],
		], $summary, gmdate('Y-m-d\TH:i:s\Z'));
		file_put_contents($this->metaPath($sess['session_id']), json_encode($tomb, JSON_PRETTY_PRINT));
		$this->upsertIndex($tomb);
		$this->cli->successMsg("  Buried: {$summary}");

		if (!$autoConfirm && !$this->cli->confirm("  Close the cmux tab and kill this session now?")) {
			$this->cli->msg('  Left the tab open; transcript is archived.', 'yellow');
			return true;
		}
		if ($this->teardown($sess)) {
			$this->cli->msg('  Tab closed; process terminated — RAM freed.', 'green');
		} else {
			$this->cli->err('  Archived, but could not close the cmux tab automatically — close it manually (transcript is safe).');
		}
		return true;
	}

	public function listTombstones(): void {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		if (!$tombs) { $this->cli->msg('Graveyard is empty.', 'yellow'); return; }
		usort($tombs, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		foreach ($tombs as $t) {
			$id = substr($t['session_id'], 0, 8);
			$this->cli->msg(sprintf(
				"%s  %-24.24s  %-10.10s  %s",
				$id, $t['workspace_title'] ?: '(untitled)', substr($t['buried_at'] ?? '', 0, 10), $t['summary'] ?? ''
			));
			$this->cli->msg("          cwd: " . ($t['cwd'] ?? ''), 'cyan');
		}
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
		$surfRef = $ws['firstSurfRef'];
		$wsRef   = $ws['ref'];

		$buildLaunch = function (string $base) use ($t): string {
			$launch = $base;
			if (!empty($t['skip_perms'])) { $launch .= ' --dangerously-skip-permissions'; }
			if (!empty($t['model']))      { $launch .= ' --model=' . $t['model']; }
			return $launch;
		};

		$useResume = !$fromTranscript && $hasJsonl;

		if ($useResume) {
			// The live JSONL still exists — restore the session as-was via --resume.
			$launch = $this->cmux->buildResumeCommand($t['session_id'], !empty($t['skip_perms']), $t['model'] ?? null);
			$this->cmux->sendToSurface($surfRef, $wsRef, $launch . "\n");
			$this->cmux->sendKeyToSurface($surfRef, $wsRef, 'enter');
			$this->cli->successMsg("Resurrected '{$title}' in {$wsRef} via --resume (restored in place).");
			return;
		}

		// Fall back to the read-the-transcript restart — either the JSONL is
		// gone (GC'd), or --from-transcript forced this path.
		if (!$hasTranscript) {
			$this->cli->exitErr("Neither live JSONL nor exported transcript available for {$t['session_id']} — cannot resurrect.");
		}

		$launch = $buildLaunch('claude');
		$this->cmux->sendToSurface($surfRef, $wsRef, $launch . "\n");
		sleep(3); // let the REPL come up

		$preamble = 'Resuming a buried session. Read ' . $transcript
			. ' — that is a transcript of where we left off. Re-orient from it, then continue.';
		$this->cmux->sendToSurface($surfRef, $wsRef, $preamble);
		$this->cmux->sendKeyToSurface($surfRef, $wsRef, 'enter');

		$note = ($fromTranscript && $hasJsonl) ? ' (forced transcript mode)' : '';
		$this->cli->successMsg("Resurrected '{$title}' in {$wsRef} — Claude is reading the transcript.{$note}");
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
			];
		}
		return $out;
	}

	/** PURE: single tab-separated porcelain line for a candidate row. No trailing newline. */
	public function formatCandidatePorcelain(array $row): string {
		return implode("\t", [
			$row['session_id'],
			(string) $row['idle_seconds'],
			$row['busy'] ? 'busy' : 'idle',
			$row['workspace_title'],
			$row['cwd'],
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
			$this->cli->msg(sprintf(
				'%s  idle=%-5.5s  %-5.5s  %-20.20s  %s',
				substr($r['session_id'], 0, 8),
				$this->idleHuman((int) $r['idle_seconds']),
				$r['busy'] ? 'busy' : 'idle',
				($r['workspace_title'] ?: '') . ($r['tab_title'] ? ' / ' . $r['tab_title'] : ''),
				$r['cwd']
			));
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
}
