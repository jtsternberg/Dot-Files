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
						$jsonl = $this->cmux->jsonlPathFor($sess['session_id'], $sess['cwd'] ?? '');
						$idle = is_file($jsonl) ? ($now - filemtime($jsonl)) : PHP_INT_MAX;
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
		return $out;
	}

	public function exportTranscript(array $sess, int $timeoutSecs = 30): bool {
		$id  = $sess['session_id'];
		$dir = $this->sessionDir($id);
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }

		$tmp = $dir . '/.transcript.' . ($sess['pid'] ?? getmypid()) . '.tmp';
		if (file_exists($tmp)) { @unlink($tmp); }

		// /export <path> writes rendered text straight to the file (no dialog when path is writable).
		$this->cmux->sendToSurface($sess['surface_ref'], $sess['workspace_ref'], '/export ' . $tmp . "\n");

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

	public function deriveSummary(array $sess): string {
		$jsonl = $this->cmux->jsonlPathFor($sess['session_id'], $sess['cwd'] ?? '');
		if (is_file($jsonl)) {
			$fh = fopen($jsonl, 'r');
			while (($line = fgets($fh)) !== false) {
				$e = json_decode($line, true);
				if (($e['type'] ?? '') === 'user') {
					$content = $e['message']['content'] ?? '';
					if (is_array($content)) {
						foreach ($content as $c) { if (($c['type'] ?? '') === 'text') { $content = $c['text']; break; } }
					}
					if (is_string($content) && trim($content) !== '') {
						fclose($fh);
						return trim(mb_substr(preg_replace('/\s+/', ' ', $content), 0, 100));
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

	public function resurrect(string $prefix): void {
		$t = $this->resolveTombstone($prefix);
		if (!$t) { $this->cli->exitErr("No single tombstone matches '{$prefix}'."); }
		$path = $this->transcriptPath($t['session_id']);
		if (!is_file($path)) { $this->cli->exitErr("Transcript missing: {$path}"); }

		$title = $t['workspace_title'] ?: 'resurrected';
		$cwd   = $t['cwd'] ?? '';
		$ws = $this->cmux->newWorkspace($title, $cwd ?: null);
		$surfRef = $ws['firstSurfRef'];
		$wsRef   = $ws['ref'];

		// Launch a FRESH claude (no --resume), then tell it to read the transcript.
		$launch = 'claude';
		if (!empty($t['skip_perms'])) { $launch .= ' --dangerously-skip-permissions'; }
		if (!empty($t['model']))      { $launch .= ' --model=' . $t['model']; }
		$this->cmux->sendToSurface($surfRef, $wsRef, $launch . "\n");
		sleep(3); // let the REPL come up

		$preamble = 'Resuming a buried session. Read ' . $path
			. ' — that is a transcript of where we left off. Re-orient from it, then continue.';
		$this->cmux->sendToSurface($surfRef, $wsRef, $preamble);
		$this->cmux->sendKeyToSurface($surfRef, $wsRef, 'enter');
		$this->cli->successMsg("Resurrected '{$title}' in {$wsRef} — Claude is reading the transcript.");
	}

	public function buryByRef(string $ref, bool $force, bool $autoConfirm): void {
		$self = $this->selfSurfaceId();
		$selfSessionId = $this->selfSessionId();
		foreach ($this->liveSessions() as $s) {
			if ($s['surface_ref'] === $ref || ($s['surface_id'] ?? '') === $ref) {
				if ($self && ($s['surface_ref'] === $self || ($s['surface_id'] ?? '') === $self)) {
					$this->cli->exitErr('Refusing to bury the caller\'s own session.');
				}
				if ($selfSessionId && ($s['session_id'] ?? null) === $selfSessionId) {
					$this->cli->exitErr('Refusing to bury the caller\'s own session.');
				}
				$this->buryOne($s, $force, $autoConfirm);
				return;
			}
		}
		$this->cli->exitErr("No live claude session found at surface '{$ref}'.");
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
}
