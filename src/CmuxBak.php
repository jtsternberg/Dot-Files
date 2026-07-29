<?php
namespace JT;

class CmuxBak {

	const BAK_DEFAULT = '~/.config/cmux/bak.json';

	protected $cli;
	protected $cmux;
	protected $bakFile;
	protected $dryRun;
	protected $verbose;

	public function __construct($cli) {
		$this->cli     = $cli;
		$this->dryRun  = $cli->hasFlag('dry-run');
		$this->verbose = $cli->isVerbose();
		$this->cmux    = new \JT\Helpers\Cmux($cli, $this->dryRun);

		$file          = $cli->getFlag('file', self::BAK_DEFAULT);
		$this->bakFile = $this->cli->convertPathToAbsolute($file);
	}

	public function run() {
		$result = shell_exec('cmux ping 2>/dev/null');
		if (trim((string) $result) !== 'PONG') {
			$this->cli->exitErr('cmux is not reachable. Is cmux running?');
		}

		if ($this->cli->hasArg('audit')) {
			$this->audit();
		} elseif ($this->cli->hasFlag('restore')) {
			$this->restore();
		} else {
			$this->backup();
		}
	}

	// ── Backup ────────────────────────────────────────────────────────────────

	protected function backup() {
		$this->cli->msg('Scanning cmux state...', 'yellow');

		$tree  = $this->cmux->tree();
		$debug = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
		$rows  = $this->agentRows($tree, $debug);

		// cwd for plain terminals (no live agent), keyed by surface_ref.
		$cwdBySurf = [];
		foreach ($debug as $ref => $d) {
			if (!empty($d['cwd'])) {
				$cwdBySurf[$ref] = $d['cwd'];
			}
		}

		if ($this->verbose) {
			$this->reportBoundRows($rows);
		}

		$workspacesData = $this->buildWorkspacesData($tree['windows'] ?? [], $rows, $cwdBySurf);

		$backup = [
			'version'    => 2,
			'timestamp'  => gmdate('Y-m-d\TH:i:s\Z'),
			'workspaces' => $workspacesData,
		];

		$dir = dirname($this->bakFile);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($this->bakFile, json_encode($backup, JSON_PRETTY_PRINT));

		$wsCount   = count($workspacesData);
		$surfCount = array_sum(array_map(function($ws) {
			return array_sum(array_map(fn($p) => count($p['surfaces']), $ws['panes']));
		}, $workspacesData));

		$byAgent = $this->countSessionsByAgent($workspacesData);
		$sessCount = array_sum($byAgent);
		$breakdown = $byAgent
			? ' (' . implode(', ', array_map(fn($a, $n) => "{$n} {$a}", array_keys($byAgent), $byAgent)) . ')'
			: '';

		$this->cli->successMsg(
			"Saved {$wsCount} workspaces, {$surfCount} surfaces, {$sessCount} agent sessions{$breakdown} → {$this->bakFile}"
		);
	}

	/**
	 * Every live agent session bound to the surface it occupies, both agents in one
	 * list sharing one row shape.
	 *
	 * Claude binds via the deterministic, tty-free ancestry join (process ancestry →
	 * resume script → surface_ref). Keying by tty instead — as this used to —
	 * mis-pairs sessions, because cmux recycles tty numbers across surfaces, so one
	 * session id gets stamped onto every surface sharing that tty (dotfiles-e5g).
	 *
	 * Codex has no resume-script ancestor to walk to, so it binds via the surface
	 * UUID cmux puts in every surface process's environment (CMUX_SURFACE_ID)
	 * against the tree's per-surface `id`. Also exact, also tty-free.
	 */
	protected function agentRows(array $tree, array $debug): array {
		$claude = $this->cmux->joinSessionsToSurfaces(
			$this->cmux->loadClaudeSessionsByPid(),
			$this->cmux->parseProcTable($this->cmux->psProcTable()),
			$debug
		);
		$codex = $this->cmux->joinCodexToSurfaces(
			$this->cmux->loadCodexSessionsByPid(),
			$this->cmux->mapSurfaceUuids($tree)
		);

		return array_merge($claude, $codex);
	}

	/** --verbose: what bound where, and why anything live didn't. */
	protected function reportBoundRows(array $rows) {
		$bound   = array_filter($rows, fn($r) => $r['surface_ref'] !== '' && !empty($r['session_id']));
		$unbound = array_filter($rows, fn($r) => $r['surface_ref'] === '' && !empty($r['session_id']));

		$this->cli->msg('  Found ' . count($bound) . ' active agent sessions', 'cyan');
		foreach ($bound as $r) {
			$short  = substr((string) $r['session_id'], 0, 8);
			$agent  = $r['agent'] ?? 'claude';
			$flags  = !empty($r['skip_perms']) ? ' --dangerously-skip-permissions' : '';
			$flags .= !empty($r['model']) ? " --model={$r['model']}" : '';
			$this->cli->msg("    → {$r['surface_ref']} [{$agent}] {$short}… cwd={$r['cwd']}{$flags}", 'green');
		}
		foreach ($unbound as $r) {
			$short = substr((string) $r['session_id'], 0, 8);
			$agent = $r['agent'] ?? 'claude';
			$this->cli->msg("    ? [{$agent}] {$short}… unbound — {$r['reason']}", 'yellow');
		}
	}

	// ── Restore ───────────────────────────────────────────────────────────────

	protected function restore() {
		if (!file_exists($this->bakFile)) {
			$this->cli->exitErr("Backup file not found: {$this->bakFile}\nRun cmux-bak first.");
		}

		$backup = json_decode(file_get_contents($this->bakFile), true);
		$this->cli->msg('Backup from: ' . ($backup['timestamp'] ?? 'unknown'), 'yellow');
		if ($this->dryRun) {
			$this->cli->msg('(dry run — no changes will be made)', 'cyan');
		}
		$this->cli->lineBreak();

		$tree      = $this->cmux->tree();
		$liveBySurf = $this->agentRowsBySurfaceRef($tree);

		// Map workspace title → workspace data (current)
		$currentWsByTitle = [];
		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				$currentWsByTitle[$ws['title'] ?? ''] = $ws;
			}
		}

		foreach ($backup['workspaces'] ?? [] as $bakWs) {
			$wsTitle = $bakWs['title'] ?? '';
			$this->cli->msg("Workspace: '{$wsTitle}'");

			if (isset($currentWsByTitle[$wsTitle])) {
				$currentWs    = $currentWsByTitle[$wsTitle];
				$currentWsRef = $currentWs['ref'] ?? '';
				$this->cli->msg("  ✓ Exists as {$currentWsRef}", 'green');

				// Map surface title → surface data within this workspace.
				// Index by both raw title and normalized title (spinner prefix stripped)
				// so matches survive Claude Code changing ✳ ↔ ⠂⠒⠐… between backup and restore.
				$currentSurfByTitle = [];
				foreach ($currentWs['panes'] ?? [] as $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$data = [
							'ref'      => $surf['ref'] ?? '',
							'pane_ref' => $pane['ref'] ?? '',
							'tty'      => $surf['tty'] ?? '',
							'type'     => $surf['type'] ?? 'terminal',
						];
						$raw        = $surf['title'] ?? '';
						$normalized = $this->normalizeTitle($raw);
						$currentSurfByTitle[$raw]        = $data;
						$currentSurfByTitle[$normalized] = $data;
					}
				}

				foreach ($bakWs['panes'] ?? [] as $bakPane) {
					foreach ($bakPane['surfaces'] ?? [] as $bakSurf) {
						$surfTitle  = $bakSurf['title'] ?? '';
						$bakCwd     = $bakSurf['cwd'] ?? '';
						$surfType   = $bakSurf['type'] ?? 'terminal';
						$norm       = $this->normalizeBakSurface($bakSurf);
						$agent      = $norm['agent'];
						$sessionId  = $norm['session_id'];
						$skipPerms  = $norm['skip_perms'];
						$model      = $norm['model'];
						$opts       = $norm['opts'];

						if ($surfType !== 'terminal' || !$sessionId) {
							continue;
						}

						$label = substr($surfTitle, 0, 45);
						$this->cli->msg("  Surface '{$label}'");

						$currentSurf = $currentSurfByTitle[$surfTitle]
							?? $currentSurfByTitle[$this->normalizeTitle($surfTitle)]
							?? null;

						if (!$currentSurf) {
							$this->cli->msg('    ✗ Not found in current workspace', 'yellow');

							if ($this->dryRun) {
								$this->cli->msg('    (dry run — would prompt to open new surface or skip)', 'cyan');
								continue;
							}

							$choice = $this->askSurfaceNotFound($surfTitle, $bakCwd, $sessionId, $agent);
							if ($choice === 'skip') {
								$this->cli->msg('    Skipped.', 'cyan');
								continue;
							}

							// Open a new surface in the workspace and target it
							$surfRef = $this->openNewSurfaceInWorkspace($currentWsRef, $currentWs);
							if (!$surfRef) {
								$this->cli->err('    Failed to create new surface, skipping.');
								continue;
							}
							$this->cli->msg("    → Opened new surface {$surfRef}", 'green');
							$currentSurf = ['ref' => $surfRef, 'tty' => ''];
						}

						$surfRef = $currentSurf['ref'];

						$status = $this->surfaceAgentStatus($liveBySurf, $surfRef, $sessionId);
						if ($status === 'same') {
							$this->cli->msg("    ✓ Same {$agent} session already running", 'green');
						} elseif ($status === 'other') {
							$live      = $liveBySurf[$surfRef];
							$short     = substr((string) $live['session_id'], 0, 8);
							$liveAgent = $live['agent'] ?? 'claude';
							$this->cli->msg("    ✓ Different {$liveAgent} session running ({$short}…), leaving it", 'cyan');
						} else {
							$short = substr($sessionId, 0, 8);
							$this->cli->msg("    ✗ {$agent} not running — resuming {$short}…", 'yellow');
							if ($bakCwd) {
								$this->cli->msg("    → cd {$bakCwd}");
								$this->cmux->sendToSurface($surfRef, $currentWsRef, "cd {$bakCwd}\n");
								if (!$this->dryRun) {
									usleep(300000);
								}
							}
							$resumeCmd = $this->cmux->buildAgentResumeCommand($agent, $sessionId, $skipPerms, $model, $opts);
							$this->cli->msg("    → {$resumeCmd}");
							$this->cmux->sendToSurface($surfRef, $currentWsRef, "{$resumeCmd}\n");
						}
					}
				}

			} else {
				$this->cli->msg('  ✗ Not found — creating workspace', 'yellow');

				$firstCwd = $this->firstCwdFromBakWs($bakWs);

				$cmd = 'cmux new-workspace --name ' . escapeshellarg($wsTitle);
				if ($firstCwd) {
					$cmd .= ' --cwd ' . escapeshellarg($firstCwd);
				}

				if ($this->verbose) {
					$this->cli->msg("  \$ {$cmd}");
				}

				if ($this->dryRun) {
					$surfs   = $this->allSurfacesFromBakWs($bakWs);
					$byAgent = $this->countSessionsByAgent([$bakWs]);
					$sc      = count($surfs);
					$ss      = array_sum($byAgent);
					$detail  = $byAgent
						? ': ' . implode(', ', array_map(fn($a, $n) => "{$n} {$a}", array_keys($byAgent), $byAgent))
						: '';
					$this->cli->msg("    Would create with {$sc} surface(s), {$ss} agent session(s){$detail}");
					continue;
				}

				$result = $this->cli->getCommandOutputAndExitCode($cmd);
				if ($result['exitCode'] !== 0) {
					$this->cli->err("    Failed to create workspace: " . $result['error']);
					continue;
				}

				usleep(500000);

				// Re-fetch tree to find the new workspace
				$newTree = $this->cmux->tree();
				$newWs   = $this->cmux->findWorkspaceByTitle($newTree, $wsTitle);

				if (!$newWs) {
					$this->cli->err("    Could not find newly created workspace '{$wsTitle}'");
					continue;
				}

				$newWsRef      = $newWs['ref'];
				$firstPaneRef  = $newWs['panes'][0]['ref'] ?? null;
				$firstSurfRef  = $newWs['panes'][0]['surfaces'][0]['ref'] ?? null;
				$this->cli->msg("    Created as {$newWsRef}", 'green');

				foreach ($bakWs['panes'] ?? [] as $paneIdx => $bakPane) {
					foreach ($bakPane['surfaces'] ?? [] as $surfIdx => $bakSurf) {
						$bakCwd    = $bakSurf['cwd'] ?? '';
						$surfType  = $bakSurf['type'] ?? 'terminal';
						$surfUrl   = $bakSurf['url'] ?? null;
						// Read per-surface — these used to be read only in the
						// existing-workspace branch above, so a RECREATED workspace
						// resumed every session with whatever $skipPerms/$model had
						// leaked in from that other loop (undefined on the first one),
						// silently dropping --dangerously-skip-permissions/--model.
						$norm      = $this->normalizeBakSurface($bakSurf);
						$agent     = $norm['agent'];
						$sessionId = $norm['session_id'];
						$skipPerms = $norm['skip_perms'];
						$model     = $norm['model'];
						$opts      = $norm['opts'];

						if ($paneIdx === 0 && $surfIdx === 0) {
							$targetRef   = $firstSurfRef;
							$targetWsRef = $newWsRef;
						} else {
							$targetRef = $this->cmux->createSurface($newWsRef, $firstPaneRef, $surfType, $surfUrl);
							if (!$targetRef) {
								continue;
							}
							$targetWsRef = $newWsRef;
						}

						if (!$targetRef) {
							continue;
						}

						// Only cd if it wasn't handled via --cwd on workspace creation
						if ($bakCwd && !($paneIdx === 0 && $surfIdx === 0 && $firstCwd)) {
							$this->cmux->sendToSurface($targetRef, $targetWsRef, "cd {$bakCwd}\n");
							usleep(300000);
						}

						if ($sessionId) {
							$resumeCmd = $this->cmux->buildAgentResumeCommand($agent, $sessionId, $skipPerms, $model, $opts);
							$this->cli->msg("    → {$resumeCmd}");
							$this->cmux->sendToSurface($targetRef, $targetWsRef, "{$resumeCmd}\n");
						}
					}
				}
			}

			$this->cli->lineBreak();
		}

		$this->cli->successMsg('Restore complete.');
	}

	// ── Audit ───────────────────────────────────────────────────────────────

	/**
	 * Read-only diff: which backed-up agent sessions are still running?
	 *
	 * Liveness is matched by session id (not tty/title, both of which drift):
	 * the deterministic session↔surface joins report every live Claude and codex
	 * and where each currently lives. A backed-up session absent from that set did
	 * not re-open. For the missing ones we offer to resume into their existing
	 * surface (dead-session-in-live-surface — the common post-restart case);
	 * a vanished workspace/surface is deferred to `--restore` to recreate.
	 */
	protected function audit() {
		if (!file_exists($this->bakFile)) {
			$this->cli->exitErr("Backup file not found: {$this->bakFile}\nRun cmux-bak first.");
		}

		$backup = json_decode(file_get_contents($this->bakFile), true);
		$this->cli->msg('Auditing against backup from: ' . ($backup['timestamp'] ?? 'unknown'), 'yellow');
		if ($this->dryRun) {
			$this->cli->msg('(dry run — no changes will be made)', 'cyan');
		}
		$this->cli->lineBreak();

		// Live session_id → current location, via the deterministic joins.
		$liveById = [];
		foreach ($this->agentRows($this->cmux->tree(), $this->cmux->parseDebugTerminals($this->cmux->debugTerminals())) as $r) {
			if (!empty($r['session_id'])) {
				$liveById[$r['session_id']] = $r;
			}
		}

		$missing = [];
		$running = 0;
		$total   = 0;

		foreach ($backup['workspaces'] ?? [] as $bakWs) {
			$wsTitle = $bakWs['title'] ?? '';
			foreach ($bakWs['panes'] ?? [] as $bakPane) {
				foreach ($bakPane['surfaces'] ?? [] as $bakSurf) {
					$norm  = $this->normalizeBakSurface($bakSurf);
					$sid   = $norm['session_id'];
					$agent = $norm['agent'];
					if (($bakSurf['type'] ?? 'terminal') !== 'terminal' || !$sid) {
						continue;
					}

					$total++;
					$label = substr($bakSurf['title'] ?? '', 0, 45);

					if (isset($liveById[$sid])) {
						$running++;
						$loc   = $liveById[$sid];
						$where = $loc['surface_ref']
							? "{$loc['workspace_ref']} / {$loc['surface_ref']}"
							: '(live, surface unbound)';
						$this->cli->msg("  ✓ [{$agent}] {$label} — running [{$where}]", 'green');
					} else {
						$short   = substr($sid, 0, 8);
						$bakCwd  = $bakSurf['cwd'] ?? '';
						$txPath  = $this->cmux->transcriptPathFor($agent, $sid, $bakCwd);
						$hasTx   = $txPath !== null && file_exists($txPath);
						$note    = $hasTx ? 'resumable' : 'transcript NOT found';
						$this->cli->msg("  ✗ [{$agent}] {$label} — NOT running ({$short}…, {$note})", 'yellow');
						$missing[] = [
							'ws_title'   => $wsTitle,
							'surf'       => $bakSurf,
							'session_id' => $sid,
							'agent'      => $agent,
							'resumable'  => $hasTx,
						];
					}
				}
			}
		}

		$this->cli->lineBreak();
		$this->cli->msg(
			"Backed-up agent sessions: {$total} — running: {$running}, missing: " . count($missing),
			'cyan'
		);

		if (!$missing) {
			$this->cli->successMsg('All backed-up agent sessions are live. Nothing to restore.');
			return;
		}

		if ($this->dryRun) {
			$this->cli->msg('(dry run — re-run without --dry-run to resume the missing session(s))', 'cyan');
			return;
		}

		$this->cli->lineBreak();
		if (!$this->cli->confirm('Resume the ' . count($missing) . ' missing session(s)?')) {
			$this->cli->msg('Left as-is.', 'cyan');
			return;
		}

		$tree = $this->cmux->tree();
		foreach ($missing as $m) {
			$this->resumeMissing($tree, $liveById, $m);
		}

		$this->cli->successMsg('Audit resume complete.');
	}

	/**
	 * Resume one missing session into its existing surface, matched by workspace
	 * title + (normalized) surface title. Skips — pointing at `--restore` — when
	 * the workspace or surface is gone, and refuses to clobber a surface that
	 * already hosts a live agent session.
	 */
	protected function resumeMissing(array $tree, array $liveById, array $m) {
		$surf  = $m['surf'];
		$sid   = $m['session_id'];
		$agent = $m['agent'] ?? 'claude';
		$label = substr($surf['title'] ?? '', 0, 45);
		$this->cli->msg("Resuming [{$agent}] '{$label}'");

		if (!$m['resumable']) {
			$this->cli->err('    Transcript not found — cannot resume. Skipping.');
			return;
		}

		$currentWs = $this->cmux->findWorkspaceByTitle($tree, $m['ws_title']);
		if (!$currentWs) {
			$this->cli->msg("    ✗ Workspace '{$m['ws_title']}' is gone — run `cmux-bak --restore` to recreate it.", 'yellow');
			return;
		}
		$wsRef = $currentWs['ref'] ?? '';

		$target   = null;
		$wantRaw  = $surf['title'] ?? '';
		$wantNorm = $this->normalizeTitle($wantRaw);
		foreach ($currentWs['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $s) {
				$raw = $s['title'] ?? '';
				if ($raw === $wantRaw || $this->normalizeTitle($raw) === $wantNorm) {
					$target = $s;
					break 2;
				}
			}
		}
		if (!$target) {
			$this->cli->msg("    ✗ Surface not found in '{$m['ws_title']}' — run `cmux-bak --restore` to recreate it.", 'yellow');
			return;
		}
		$surfRef = $target['ref'] ?? '';

		// Don't clobber a live agent session already running on this surface.
		foreach ($liveById as $liveSid => $row) {
			if (($row['surface_ref'] ?? '') === $surfRef) {
				$short     = substr((string) $liveSid, 0, 8);
				$liveAgent = $row['agent'] ?? 'claude';
				$this->cli->msg("    ✓ A {$liveAgent} session ({$short}…) is already running here — leaving it.", 'cyan');
				return;
			}
		}

		$norm      = $this->normalizeBakSurface($surf);
		$bakCwd    = $surf['cwd'] ?? '';
		$skipPerms = $norm['skip_perms'];
		$model     = $norm['model'];
		$opts      = $norm['opts'];

		if ($bakCwd) {
			$this->cli->msg("    → cd {$bakCwd}");
			$this->cmux->sendToSurface($surfRef, $wsRef, "cd {$bakCwd}\n");
			if (!$this->dryRun) {
				usleep(300000);
			}
		}
		$resumeCmd = $this->cmux->buildAgentResumeCommand($agent, $sid, $skipPerms, $model, $opts);
		$this->cli->msg("    → {$resumeCmd}");
		$this->cmux->sendToSurface($surfRef, $wsRef, "{$resumeCmd}\n");
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Live agent sessions (both agents) indexed by the surface they currently
	 * occupy: [ surface_ref => join row ]. Built from the deterministic, tty-free
	 * joins so a surface's liveness is judged by the surface a session actually
	 * launched in — not by a tty it happens to share with another surface
	 * (dotfiles-e5g).
	 */
	protected function agentRowsBySurfaceRef(?array $tree = null): array {
		$rows = $this->agentRows(
			$tree ?? $this->cmux->tree(),
			$this->cmux->parseDebugTerminals($this->cmux->debugTerminals())
		);
		$bySurf = [];
		foreach ($rows as $r) {
			$ref = $r['surface_ref'] ?? '';
			if ($ref !== '') {
				$bySurf[$ref] = $r;
			}
		}
		return $bySurf;
	}

	/**
	 * PURE. Decide what to do with a surface we want to resume $wantSid into,
	 * given the live-by-surface_ref map:
	 *   'same'   — our session is already live on this surface (nothing to do)
	 *   'other'  — some other agent session is live here (leave it alone)
	 *   'resume' — nothing live on this surface (safe to resume)
	 *
	 * Session ids are compared without regard to agent: they're uuids from
	 * different generators, so a cross-agent id collision isn't a thing, and a
	 * codex sitting where a Claude was backed up from is still 'other'.
	 */
	protected function surfaceAgentStatus(array $liveBySurf, string $surfRef, ?string $wantSid): string {
		$live = $liveBySurf[$surfRef] ?? null;
		if (!$live) {
			return 'resume';
		}
		return ($live['session_id'] ?? null) === $wantSid ? 'same' : 'other';
	}

	/**
	 * PURE. Read one backed-up surface's agent fields, tolerating v1 files.
	 * Returns [agent, session_id, model, skip_perms].
	 *
	 * v2 stores a single generic agent per surface; v1 stored claude_* keys with no
	 * agent at all. bak.json is a cache every run rewrites wholesale, so this shim
	 * only has to cover a file already on disk at upgrade time — it goes away next
	 * release. A surface carrying a session id but no agent is read as claude, so a
	 * partial/hand-edited file can never turn into a stray `codex resume`.
	 */
	protected function normalizeBakSurface(array $bakSurf): array {
		$sessionId = $bakSurf['agent_session_id'] ?? $bakSurf['claude_session_id'] ?? null;

		return [
			'agent'      => $sessionId ? ($bakSurf['agent'] ?? 'claude') : null,
			'session_id' => $sessionId,
			'model'      => $bakSurf['agent_model'] ?? $bakSurf['claude_model'] ?? null,
			'skip_perms' => (bool) ($bakSurf['agent_skip_permissions'] ?? $bakSurf['claude_skip_permissions'] ?? false),
			'opts'       => is_array($bakSurf['agent_opts'] ?? null) ? $bakSurf['agent_opts'] : [],
		];
	}

	/** PURE. [ agent => session count ] across a backup's workspaces, agents with none omitted. */
	protected function countSessionsByAgent(array $workspaces): array {
		$counts = [];
		foreach ($workspaces as $ws) {
			foreach ($ws['panes'] ?? [] as $pane) {
				foreach ($pane['surfaces'] ?? [] as $surf) {
					$norm = $this->normalizeBakSurface($surf);
					if ($norm['session_id']) {
						$agent = $norm['agent'];
						$counts[$agent] = ($counts[$agent] ?? 0) + 1;
					}
				}
			}
		}
		ksort($counts);
		return $counts;
	}

	/**
	 * PURE. Build the backup `workspaces` structure from a cmux tree and the
	 * deterministic session↔surface join. Sessions bind to surfaces by
	 * surface_ref, never by tty: cmux recycles tty numbers across surfaces, so a
	 * tty key stamps one session id onto every surface sharing that tty (the
	 * duplicate-session-id bug, dotfiles-e5g). surface_ref pairs each session with
	 * exactly the surface it launched. A terminal with no join row (a plain shell,
	 * no live Claude) falls back to the debug-terminals cwd map, also by ref.
	 *
	 * @param array $windows    tree['windows']
	 * @param array $joinRows   Cmux::joinSessionsToSurfaces() output
	 * @param array $cwdBySurf  surface_ref => cwd, for terminals with no live Claude
	 * @return array workspaces[]
	 */
	protected function buildWorkspacesData(array $windows, array $joinRows, array $cwdBySurf): array {
		$bySurf = [];
		foreach ($joinRows as $r) {
			$ref = $r['surface_ref'] ?? '';
			if ($ref !== '') {
				$bySurf[$ref] = $r;
			}
		}

		$workspacesData = [];
		foreach ($windows as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				$wsData = [
					'title'       => $ws['title'] ?? '',
					'ref'         => $ws['ref'] ?? '',
					'description' => $ws['description'] ?? null,
					'panes'       => [],
				];

				foreach ($ws['panes'] ?? [] as $pane) {
					$paneData = [
						'ref'      => $pane['ref'] ?? '',
						'index'    => $pane['index'] ?? 0,
						'surfaces' => [],
					];

					foreach ($pane['surfaces'] ?? [] as $surf) {
						$surfRef   = $surf['ref'] ?? '';
						$type      = $surf['type'] ?? 'terminal';
						$agent     = null;
						$sessionId = null;
						$cwd       = null;
						$skipPerms = false;
						$model     = null;
						$opts      = [];

						if ($type === 'terminal') {
							$row = $bySurf[$surfRef] ?? null;
							if ($row) {
								$agent     = $row['agent'] ?? 'claude';
								$sessionId = $row['session_id'];
								$cwd       = $row['cwd'];
								$skipPerms = (bool) ($row['skip_perms'] ?? false);
								$model     = $row['model'] ?? null;
								$opts      = $row['opts'] ?? [];
							} else {
								$cwd = $cwdBySurf[$surfRef] ?? null;
							}
						}

						$paneData['surfaces'][] = [
							'ref'                    => $surfRef,
							'title'                  => $surf['title'] ?? '',
							'type'                   => $type,
							'tty'                    => $surf['tty'] ?? '',
							'url'                    => $surf['url'] ?? null,
							'cwd'                    => $cwd,
							'agent'                  => $agent,
							'agent_session_id'       => $sessionId,
							'agent_skip_permissions' => $skipPerms,
							'agent_model'            => $model,
							// Agent-specific knobs with no cross-agent meaning — for
							// codex, the sandbox/approval/effort that `codex resume`
							// will NOT rehydrate on its own.
							'agent_opts'             => $opts,
							'index_in_pane'          => $surf['index_in_pane'] ?? 0,
						];
					}

					$wsData['panes'][] = $paneData;
				}

				$workspacesData[] = $wsData;
			}
		}

		return $workspacesData;
	}

	/**
	 * Strip the leading status indicator that Claude Code prepends to terminal titles.
	 * Claude uses spinner chars (⠂⠒⠐…) while active and ✳ when idle — these change
	 * between backup time and restore time, so we normalize them away for matching.
	 */
	protected function normalizeTitle(string $title): string {
		// Strip any leading non-ASCII characters (the icon) plus following whitespace
		return preg_replace('/^[^\x00-\x7F]+\s*/u', '', $title);
	}

	protected function firstCwdFromBakWs(array $bakWs) {
		foreach ($bakWs['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				if (!empty($surf['cwd'])) {
					return $surf['cwd'];
				}
			}
		}
		return null;
	}

	protected function allSurfacesFromBakWs(array $bakWs) {
		$surfs = [];
		foreach ($bakWs['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				$surfs[] = $surf;
			}
		}
		return $surfs;
	}

	/**
	 * Prompt the user when a backed-up surface can't be matched by title.
	 * Returns 'new' or 'skip'.
	 */
	protected function askSurfaceNotFound(string $surfTitle, string $cwd, string $sessionId, string $agent = 'claude') {
		$short = substr($sessionId, 0, 8);
		$label = substr($surfTitle, 0, 50);
		$this->cli->msg("    '{$label}' | {$agent} {$short}… | cwd: {$cwd}", 'cyan');
		$this->cli->msg('    What would you like to do?', 'cyan');
		$this->cli->msg("      [n] Open a new surface in this workspace and resume the {$agent} session");
		$this->cli->msg('      [s] Skip');

		while (true) {
			$answer = strtolower(trim($this->cli->ask('    Choice [n/s]: ')));
			if ($answer === 'n' || $answer === 'new') {
				return 'new';
			}
			if ($answer === 's' || $answer === 'skip' || $answer === '') {
				return 'skip';
			}
			$this->cli->msg('    Please enter n or s.', 'yellow');
		}
	}

	/**
	 * Create a new terminal surface in an existing workspace and return its ref.
	 * Adds to the first pane if one exists, otherwise creates a new pane.
	 */
	protected function openNewSurfaceInWorkspace(string $wsRef, array $currentWs) {
		$firstPaneRef = $currentWs['panes'][0]['ref'] ?? null;

		$cmd = 'cmux new-surface --type terminal --workspace ' . escapeshellarg($wsRef);
		if ($firstPaneRef) {
			$cmd .= ' --pane ' . escapeshellarg($firstPaneRef);
		}

		shell_exec($cmd . ' 2>/dev/null');
		usleep(500000);

		// Find the newly created surface (last in the workspace)
		$newTree = $this->cmux->tree();
		$newWs   = $this->cmux->findWorkspaceByRef($newTree, $wsRef);
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
}
