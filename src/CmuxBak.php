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

		$tree = $this->cmux->tree();

		// Bind each live Claude to its surface via the deterministic, tty-free join
		// (process ancestry → resume script → surface_ref). Keying by tty instead —
		// as this used to — mis-pairs sessions, because cmux recycles tty numbers
		// across surfaces, so one session id gets stamped onto every surface sharing
		// that tty. See dotfiles-e5g and Cmux::joinSessionsToSurfaces().
		$debug = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
		$rows  = $this->cmux->joinSessionsToSurfaces(
			$this->cmux->loadClaudeSessionsByPid(),
			$this->cmux->parseProcTable($this->cmux->psProcTable()),
			$debug
		);

		// cwd for plain terminals (no live Claude), keyed by surface_ref.
		$cwdBySurf = [];
		foreach ($debug as $ref => $d) {
			if (!empty($d['cwd'])) {
				$cwdBySurf[$ref] = $d['cwd'];
			}
		}

		if ($this->verbose) {
			$bound = array_filter($rows, fn($r) => $r['surface_ref'] !== '' && !empty($r['session_id']));
			$this->cli->msg('  Found ' . count($bound) . ' active Claude sessions', 'cyan');
			foreach ($bound as $r) {
				$short  = substr((string) $r['session_id'], 0, 8);
				$flags  = !empty($r['skip_perms']) ? ' --dangerously-skip-permissions' : '';
				$flags .= !empty($r['model']) ? " --model={$r['model']}" : '';
				$this->cli->msg("    → {$r['surface_ref']} {$short}… cwd={$r['cwd']}{$flags}", 'green');
			}
		}

		$workspacesData = $this->buildWorkspacesData($tree['windows'] ?? [], $rows, $cwdBySurf);

		$backup = [
			'version'    => 1,
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
		$sessCount = array_sum(array_map(function($ws) {
			return array_sum(array_map(function($p) {
				return count(array_filter($p['surfaces'], fn($s) => !empty($s['claude_session_id'])));
			}, $ws['panes']));
		}, $workspacesData));

		$this->cli->successMsg(
			"Saved {$wsCount} workspaces, {$surfCount} surfaces, {$sessCount} Claude sessions → {$this->bakFile}"
		);
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
		$liveBySurf = $this->liveSessionsBySurfaceRef();

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
						$sessionId  = $bakSurf['claude_session_id'] ?? null;
						$bakCwd     = $bakSurf['cwd'] ?? '';
						$surfType   = $bakSurf['type'] ?? 'terminal';
						$skipPerms  = $bakSurf['claude_skip_permissions'] ?? false;
						$model      = $bakSurf['claude_model'] ?? null;

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

							$choice = $this->askSurfaceNotFound($surfTitle, $bakCwd, $sessionId);
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

						$status = $this->surfaceClaudeStatus($liveBySurf, $surfRef, $sessionId);
						if ($status === 'same') {
							$this->cli->msg('    ✓ Same Claude session already running', 'green');
						} elseif ($status === 'other') {
							$short = substr($liveBySurf[$surfRef]['session_id'], 0, 8);
							$this->cli->msg("    ✓ Different Claude session running ({$short}…), leaving it", 'cyan');
						} else {
							$short = substr($sessionId, 0, 8);
							$this->cli->msg("    ✗ Claude not running — resuming {$short}…", 'yellow');
							if ($bakCwd) {
								$this->cli->msg("    → cd {$bakCwd}");
								$this->cmux->sendToSurface($surfRef, $currentWsRef, "cd {$bakCwd}\n");
								if (!$this->dryRun) {
									usleep(300000);
								}
							}
							$resumeCmd = $this->cmux->buildResumeCommand($sessionId, $skipPerms, $model);
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
					$sessions = array_filter($surfs, fn($s) => !empty($s['claude_session_id']));
					$sc = count($surfs);
					$ss = count($sessions);
					$this->cli->msg("    Would create with {$sc} surface(s), {$ss} Claude session(s)");
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
						$sessionId = $bakSurf['claude_session_id'] ?? null;
						$surfType  = $bakSurf['type'] ?? 'terminal';
						$surfUrl   = $bakSurf['url'] ?? null;

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
							$short      = substr($sessionId, 0, 8);
							$resumeCmd  = $this->cmux->buildResumeCommand($sessionId, $skipPerms, $model);
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
	 * Read-only diff: which backed-up Claude sessions are still running?
	 *
	 * Liveness is matched by session id (not tty/title, both of which drift):
	 * the deterministic session↔surface join reports every live Claude and
	 * where it currently lives. A backed-up session absent from that set did
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

		// Live session_id → current location, via the deterministic join.
		$liveById = [];
		$rows = $this->cmux->joinSessionsToSurfaces(
			$this->cmux->loadClaudeSessionsByPid(),
			$this->cmux->parseProcTable($this->cmux->psProcTable()),
			$this->cmux->parseDebugTerminals($this->cmux->debugTerminals())
		);
		foreach ($rows as $r) {
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
					$sid = $bakSurf['claude_session_id'] ?? null;
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
						$this->cli->msg("  ✓ {$label} — running [{$where}]", 'green');
					} else {
						$short   = substr($sid, 0, 8);
						$bakCwd  = $bakSurf['cwd'] ?? '';
						$hasTx   = $bakCwd && file_exists($this->cmux->jsonlPathFor($sid, $bakCwd));
						$note    = $hasTx ? 'resumable' : 'transcript NOT found';
						$this->cli->msg("  ✗ {$label} — NOT running ({$short}…, {$note})", 'yellow');
						$missing[] = [
							'ws_title'   => $wsTitle,
							'surf'       => $bakSurf,
							'session_id' => $sid,
							'resumable'  => $hasTx,
						];
					}
				}
			}
		}

		$this->cli->lineBreak();
		$this->cli->msg(
			"Backed-up Claude sessions: {$total} — running: {$running}, missing: " . count($missing),
			'cyan'
		);

		if (!$missing) {
			$this->cli->successMsg('All backed-up Claude sessions are live. Nothing to restore.');
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
	 * already hosts a live Claude.
	 */
	protected function resumeMissing(array $tree, array $liveById, array $m) {
		$surf  = $m['surf'];
		$sid   = $m['session_id'];
		$label = substr($surf['title'] ?? '', 0, 45);
		$this->cli->msg("Resuming '{$label}'");

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

		// Don't clobber a live Claude already running on this surface.
		foreach ($liveById as $liveSid => $row) {
			if (($row['surface_ref'] ?? '') === $surfRef) {
				$short = substr($liveSid, 0, 8);
				$this->cli->msg("    ✓ A Claude session ({$short}…) is already running here — leaving it.", 'cyan');
				return;
			}
		}

		$bakCwd    = $surf['cwd'] ?? '';
		$skipPerms = $surf['claude_skip_permissions'] ?? false;
		$model     = $surf['claude_model'] ?? null;

		if ($bakCwd) {
			$this->cli->msg("    → cd {$bakCwd}");
			$this->cmux->sendToSurface($surfRef, $wsRef, "cd {$bakCwd}\n");
			if (!$this->dryRun) {
				usleep(300000);
			}
		}
		$resumeCmd = $this->cmux->buildResumeCommand($sid, $skipPerms, $model);
		$this->cli->msg("    → {$resumeCmd}");
		$this->cmux->sendToSurface($surfRef, $wsRef, "{$resumeCmd}\n");
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Live Claude sessions indexed by the surface they currently occupy:
	 * [ surface_ref => join row ]. Built from the deterministic, tty-free join so
	 * a surface's liveness is judged by the surface it launched — not by a tty it
	 * happens to share with another surface (dotfiles-e5g).
	 */
	protected function liveSessionsBySurfaceRef(): array {
		$rows = $this->cmux->joinSessionsToSurfaces(
			$this->cmux->loadClaudeSessionsByPid(),
			$this->cmux->parseProcTable($this->cmux->psProcTable()),
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
	 *   'other'  — a different Claude is live here (leave it alone)
	 *   'resume' — no live Claude on this surface (safe to resume)
	 */
	protected function surfaceClaudeStatus(array $liveBySurf, string $surfRef, ?string $wantSid): string {
		$live = $liveBySurf[$surfRef] ?? null;
		if (!$live) {
			return 'resume';
		}
		return ($live['session_id'] ?? null) === $wantSid ? 'same' : 'other';
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
						$sessionId = null;
						$cwd       = null;
						$skipPerms = false;
						$model     = null;

						if ($type === 'terminal') {
							$row = $bySurf[$surfRef] ?? null;
							if ($row) {
								$sessionId = $row['session_id'];
								$cwd       = $row['cwd'];
								$skipPerms = (bool) ($row['skip_perms'] ?? false);
								$model     = $row['model'] ?? null;
							} else {
								$cwd = $cwdBySurf[$surfRef] ?? null;
							}
						}

						$paneData['surfaces'][] = [
							'ref'                        => $surfRef,
							'title'                      => $surf['title'] ?? '',
							'type'                       => $type,
							'tty'                        => $surf['tty'] ?? '',
							'url'                        => $surf['url'] ?? null,
							'cwd'                        => $cwd,
							'claude_session_id'          => $sessionId,
							'claude_skip_permissions'    => $skipPerms,
							'claude_model'               => $model,
							'index_in_pane'              => $surf['index_in_pane'] ?? 0,
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
	protected function askSurfaceNotFound(string $surfTitle, string $cwd, string $sessionId) {
		$short = substr($sessionId, 0, 8);
		$label = substr($surfTitle, 0, 50);
		$this->cli->msg("    Session: {$short}… | cwd: {$cwd}", 'cyan');
		$this->cli->msg('    What would you like to do?', 'cyan');
		$this->cli->msg('      [n] Open a new surface in this workspace and resume the session');
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
