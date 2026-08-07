<?php
namespace JT;

use JT\Helpers\Cmux;

class CmuxBak {

	const BAK_DEFAULT = '~/.config/cmux/bak.json';

	protected $cli;
	protected $cmux;
	protected $bakFile;
	protected $dryRun;
	protected $verbose;

	public function __construct(
		$cli,
		string $bakFile = self::BAK_DEFAULT,
		bool $dryRun = false,
		bool $verbose = false,
		?Cmux $cmux = null
	) {
		$this->cli     = $cli;
		$this->dryRun  = $dryRun;
		$this->verbose = $verbose;
		$this->cmux    = $cmux;
		$this->bakFile = $this->cli->convertPathToAbsolute($bakFile);
	}

	protected function prepare(): bool {
		if (!$this->cmux()->ping()) {
			$this->cli->err('cmux is not reachable. Is cmux running?');

			return false;
		}

		return true;
	}

	protected function cmux(): Cmux {
		if (!$this->cmux instanceof Cmux) {
			$this->cmux = new Cmux($this->cli, $this->dryRun);
		}

		return $this->cmux;
	}

	// ── Backup ────────────────────────────────────────────────────────────────

	public function backup(): int {
		if (!$this->prepare()) {
			return 1;
		}

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

		$layoutByWsRef  = $this->captureLayoutTrees($tree['windows'] ?? []);
		$workspacesData = $this->buildWorkspacesData($tree['windows'] ?? [], $rows, $cwdBySurf, $layoutByWsRef);

		$backup = [
			'version'    => 3,
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
		$geoCount = count($layoutByWsRef);

		$this->cli->successMsg(
			"Saved {$wsCount} workspaces ({$geoCount} with split geometry), {$surfCount} surfaces, "
			. "{$sessCount} agent sessions{$breakdown} → {$this->bakFile}"
		);

		return 0;
	}

	/**
	 * Each live workspace's real split geometry, keyed by workspace ref, ready to store.
	 *
	 * `cmux tree` — the rest of this backup — reports panes as a flat list with no
	 * orientation, divider ratio or nesting, so a workspace split top/bottom and one
	 * split left/right are byte-identical in it and restore could only guess. `layout
	 * get` is the only faithful source, reached through a throwaway named layout per
	 * workspace (save → get → delete).
	 *
	 * What is stored is GEOMETRY ONLY — `command` and `cwd` are dropped. cmux records
	 * the command each surface was launched with, and replaying that would re-run it, so
	 * a restored workspace would launch an agent itself on top of the resume restore
	 * sends. cwds go with it because restore applies every recorded cwd through its own
	 * is_dir guard; a directory deleted since the backup must never reach cmux.
	 *
	 * A workspace cmux can't capture is simply absent from the map: restore then rebuilds
	 * it from panes[] as before.
	 */
	protected function captureLayoutTrees(array $windows): array {
		$byWsRef = [];
		foreach ($windows as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				$ref = $ws['ref'] ?? '';
				if ($ref === '') {
					continue;
				}

				$tree = $this->cmux->captureLayoutTree($ref, 'cmux-bak-capture');
				if (is_array($tree) && $tree) {
					$byWsRef[$ref] = $this->cmux->sanitizeLayoutTree($tree, ['command', 'cwd']);
				} elseif ($this->verbose) {
					$this->cli->msg("    ? {$ref} (" . ($ws['title'] ?? '') . ') — cmux reported no layout; its splits will be approximated on restore', 'yellow');
				}
			}
		}

		return $byWsRef;
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

	public function restore(): int {
		if (!$this->prepare()) {
			return 1;
		}

		if (!file_exists($this->bakFile)) {
			$this->cli->err("Backup file not found: {$this->bakFile}\nRun cmux-bak first.");

			return 1;
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
							$this->cdToRecordedCwd($surfRef, $currentWsRef, $bakCwd);
							$resumeCmd = $this->cmux->buildAgentResumeCommand($agent, $sessionId, $skipPerms, $model, $opts);
							$this->cli->msg("    → {$resumeCmd}");
							$this->cmux->sendToSurface($surfRef, $currentWsRef, "{$resumeCmd}\n");
							$this->warnIfCodexTrustPrompt($agent, $surfRef, $currentWsRef);
						}
					}
				}

			} else {
				$bakPanes    = array_values($bakWs['panes'] ?? []);
				$firstCwd    = $this->firstCwdFromBakWs($bakWs);
				$layoutTree  = is_array($bakWs['layout_tree'] ?? null) ? $bakWs['layout_tree'] : null;
				$useGeometry = $this->layoutTreeFitsBakPanes($layoutTree, $bakPanes);

				// A workspace whose every surface carries agent=null is a husk: nothing to
				// resume, so recreating it produces an empty shell. One backup held four
				// husks with the same title and restore silently made four useless
				// workspaces. Ask; the default answer is to leave it out.
				if (!$this->bakWsHasAgentSession($bakWs) && !$this->askCreateHuskWorkspace($bakPanes)) {
					$this->cli->lineBreak();
					continue;
				}

				$this->cli->msg('  ✗ Not found — creating workspace', 'yellow');

				if ($this->dryRun) {
					$surfs   = $this->allSurfacesFromBakWs($bakWs);
					$byAgent = $this->countSessionsByAgent([$bakWs]);
					$sc      = count($surfs);
					$ss      = array_sum($byAgent);
					$pc      = max(1, count($bakPanes));
					$detail  = $byAgent
						? ': ' . implode(', ', array_map(fn($a, $n) => "{$n} {$a}", array_keys($byAgent), $byAgent))
						: '';
					$geo     = $useGeometry ? ', from its recorded split geometry' : '';
					$this->cli->msg("    Would create with {$pc} pane(s), {$sc} surface(s), {$ss} agent session(s){$detail}{$geo}");
					continue;
				}

				// Preferred path: replay cmux's own captured geometry, so the splits come
				// back with the orientation, divider ratios and nesting they had. Anything
				// short of that — no recorded layout, one that no longer describes the
				// recorded panes, or a replay cmux refuses — falls back to rebuilding the
				// pane COUNT as right-splits, which is all `cmux tree` data can support.
				$built = $useGeometry
					? $this->createWorkspaceFromGeometry($wsTitle, $layoutTree, $bakPanes)
					: null;

				if (!$useGeometry && $layoutTree !== null) {
					$this->cli->msg('    ⚠ The recorded split geometry no longer describes the recorded panes — rebuilding as right-splits.', 'yellow');
				}

				$built = $built ?: $this->createWorkspaceWithPaneSplits($wsTitle, $firstCwd, $bakPanes);
				if (!$built) {
					continue;
				}

				$newWsRef   = $built['ws_ref'];
				$paneRefs   = $built['pane_refs'];
				$surfRefs   = $built['surf_refs'];
				$createdCwd = $built['cwd'];

				foreach ($bakPanes as $paneIdx => $bakPane) {
					$paneRef = $paneRefs[$paneIdx] ?? $paneRefs[0] ?? null;

					foreach (array_values($bakPane['surfaces'] ?? []) as $surfIdx => $bakSurf) {
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

						// Surfaces the created workspace already owns are reused in place: a
						// geometry replay rebuilds the whole tab stack at once, and a
						// split-rebuilt workspace at least owns one surface per pane.
						// Anything still missing is created INSIDE its own pane, never
						// dumped into pane 0.
						$targetRef   = $surfRefs[$paneIdx][$surfIdx]
							?? $this->cmux->createSurface($newWsRef, $paneRef, $surfType, $surfUrl);
						$targetWsRef = $newWsRef;

						if (!$targetRef) {
							continue;
						}

						// Only cd if it wasn't handled via --cwd on workspace creation
						if (!($paneIdx === 0 && $surfIdx === 0 && $createdCwd === $bakCwd)) {
							$this->cdToRecordedCwd($targetRef, $targetWsRef, $bakCwd);
						}

						if ($sessionId) {
							$resumeCmd = $this->cmux->buildAgentResumeCommand($agent, $sessionId, $skipPerms, $model, $opts);
							$this->cli->msg("    → {$resumeCmd}");
							$this->cmux->sendToSurface($targetRef, $targetWsRef, "{$resumeCmd}\n");
							$this->warnIfCodexTrustPrompt($agent, $targetRef, $targetWsRef);
						}
					}
				}
			}

			$this->cli->lineBreak();
		}

		$this->cli->successMsg('Restore complete.');

		return 0;
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
	 * a vanished workspace/surface is deferred to `restore` to recreate.
	 */
	public function audit(): int {
		if (!$this->prepare()) {
			return 1;
		}

		if (!file_exists($this->bakFile)) {
			$this->cli->err("Backup file not found: {$this->bakFile}\nRun cmux-bak first.");

			return 1;
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
			return 0;
		}

		if ($this->dryRun) {
			$this->cli->msg('(dry run — re-run without --dry-run to resume the missing session(s))', 'cyan');
			return 0;
		}

		$this->cli->lineBreak();
		if (!$this->cli->confirm('Resume the ' . count($missing) . ' missing session(s)?')) {
			$this->cli->msg('Left as-is.', 'cyan');
			return 0;
		}

		$tree = $this->cmux->tree();
		foreach ($missing as $m) {
			$this->resumeMissing($tree, $liveById, $m);
		}

		$this->cli->successMsg('Audit resume complete.');

		return 0;
	}

	/**
	 * Resume one missing session into its existing surface, matched by workspace
	 * title + (normalized) surface title. Skips — pointing at `restore` — when
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
			$this->cli->msg("    ✗ Workspace '{$m['ws_title']}' is gone — run `cmux-bak restore` to recreate it.", 'yellow');
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
			$this->cli->msg("    ✗ Surface not found in '{$m['ws_title']}' — run `cmux-bak restore` to recreate it.", 'yellow');
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

		$this->cdToRecordedCwd($surfRef, $wsRef, $bakCwd);
		$resumeCmd = $this->cmux->buildAgentResumeCommand($agent, $sid, $skipPerms, $model, $opts);
		$this->cli->msg("    → {$resumeCmd}");
		$this->cmux->sendToSurface($surfRef, $wsRef, "{$resumeCmd}\n");
		$this->warnIfCodexTrustPrompt($agent, $surfRef, $wsRef);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** Codex pauses at this prompt in an untrusted cwd; never answer it on the user's behalf. */
	protected function warnIfCodexTrustPrompt(string $agent, string $surfRef, string $wsRef): void {
		if ($agent !== 'codex' || $this->dryRun) { return; }
		usleep(500000);
		$screen = $this->cmux->readScreen($surfRef, $wsRef);
		if (stripos($screen, 'Do you trust the contents of this directory?') === false) { return; }
		$this->cli->err('    Codex is awaiting trust confirmation in this surface; resume is not complete.');
	}

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
	 * Each workspace also carries `layout_tree` — its real split geometry, which the flat
	 * panes[] cannot express — whenever captureLayoutTrees() got one for it.
	 *
	 * @param array $windows        tree['windows']
	 * @param array $joinRows       Cmux::joinSessionsToSurfaces() output
	 * @param array $cwdBySurf      surface_ref => cwd, for terminals with no live Claude
	 * @param array $layoutByWsRef  workspace_ref => sanitized cmux layout tree
	 * @return array workspaces[]
	 */
	protected function buildWorkspacesData(array $windows, array $joinRows, array $cwdBySurf, array $layoutByWsRef = []): array {
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

				$layoutTree = $layoutByWsRef[$ws['ref'] ?? ''] ?? null;
				if (is_array($layoutTree)) {
					$wsData['layout_tree'] = $layoutTree;
				}

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

	/**
	 * Send `cd <cwd>` into a surface — unless the recorded directory is gone.
	 *
	 * A backup is a snapshot of directories that can be renamed, moved or deleted
	 * before it is ever restored, and cd'ing into one of those blind just leaves a
	 * shell error on screen (a restore did exactly that with a since-deleted
	 * ~/Downloads path). An agent resume follows either way: the shell's spawn
	 * directory is a worse cwd than the recorded one, but far better than no resume.
	 *
	 * Returns whether the cd was sent.
	 */
	protected function cdToRecordedCwd(string $surfRef, string $wsRef, ?string $cwd): bool {
		if (empty($cwd)) {
			return false;
		}

		if (!is_dir($cwd)) {
			$this->cli->msg("    ⚠ Recorded cwd no longer exists: {$cwd} — skipping the cd.", 'yellow');

			return false;
		}

		$this->cli->msg("    → cd {$cwd}");
		$this->cmux->sendToSurface($surfRef, $wsRef, "cd {$cwd}\n");
		if (!$this->dryRun) {
			usleep(300000);
		}

		return true;
	}

	/**
	 * PURE. Whether a recorded layout tree can be trusted to carry this workspace's
	 * recorded surfaces.
	 *
	 * The layout tree and the flat panes[] are two views of one workspace, joined
	 * positionally: cmux's depth-first leaf order matches the pane order `cmux tree`
	 * reports, so pane N of one is pane N of the other. That join only holds while both
	 * views describe the same shape, and they can disagree — cmux drops surface types it
	 * cannot express in a layout (agent-session, markdown, …), and a hand-edited or
	 * part-written bak.json can say anything. So the pane count, every pane's tab count
	 * and every tab's type have to line up before a session is sent anywhere; on any
	 * disagreement the caller rebuilds from panes[] instead of resuming a session into
	 * the wrong tab.
	 */
	protected function layoutTreeFitsBakPanes(?array $layoutTree, array $bakPanes): bool {
		if (!$layoutTree || !$bakPanes) {
			return false;
		}

		$layoutPanes = $this->cmux->layoutTreePanes($layoutTree);
		if (count($layoutPanes) !== count($bakPanes)) {
			return false;
		}

		foreach (array_values($bakPanes) as $i => $bakPane) {
			$bakSurfaces = array_values($bakPane['surfaces'] ?? []);
			if (count($layoutPanes[$i]) !== count($bakSurfaces)) {
				return false;
			}

			foreach ($bakSurfaces as $j => $bakSurf) {
				$want = $bakSurf['type'] ?? 'terminal';
				$have = $layoutPanes[$i][$j]['type'] ?? 'terminal';
				if ($want !== $have) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Create a workspace by replaying its recorded split geometry, returning the handles
	 * the surface-placement loop needs — or null to fall back to a pane-split rebuild.
	 *
	 * No `--cwd`: the stored tree is geometry only, and every recorded cwd is applied
	 * per-surface afterwards through the is_dir guard, so one base directory for the
	 * whole workspace would only fight that.
	 *
	 * A replay can come back with fewer surfaces than it was asked for. The tabs that DID
	 * come back keep their positional owner, the rest are created in their own pane by
	 * the placement loop — better than abandoning a workspace cmux has already opened
	 * (falling back here would leave that one behind and build a second).
	 */
	protected function createWorkspaceFromGeometry(string $wsTitle, array $layoutTree, array $bakPanes): ?array {
		if ($this->verbose) {
			$this->cli->msg('    Creating from recorded split geometry (' . count($bakPanes) . ' pane(s))');
		}

		$node = $this->cmux->newWorkspaceWithLayout($wsTitle, null, $layoutTree);
		if (!$node || empty($node['ref'])) {
			$this->cli->msg('    ⚠ cmux could not replay the recorded split geometry — rebuilding as right-splits.', 'yellow');

			return null;
		}

		$paneRefs = [];
		$surfRefs = [];
		foreach (array_values($node['panes'] ?? []) as $paneIdx => $pane) {
			$paneRefs[$paneIdx] = $pane['ref'] ?? null;
			foreach (array_values($pane['surfaces'] ?? []) as $surfIdx => $surf) {
				if (!empty($surf['ref'])) {
					$surfRefs[$paneIdx][$surfIdx] = $surf['ref'];
				}
			}
		}

		$this->cli->msg("    Created as {$node['ref']} with its recorded splits", 'green');

		$wanted = array_sum(array_map(fn($p) => count($p['surfaces'] ?? []), $bakPanes));
		$got    = array_sum(array_map('count', $surfRefs));
		if ($got < $wanted) {
			$this->cli->msg("    ⚠ The replayed workspace came back with fewer surfaces than recorded ({$got} of {$wanted}) — the rest are opened in their own pane.", 'yellow');
		}

		return [
			'ws_ref'    => $node['ref'],
			'pane_refs' => $paneRefs,
			'surf_refs' => $surfRefs,
			'cwd'       => null,
		];
	}

	/**
	 * Create a workspace and rebuild its recorded pane COUNT as right-splits, returning
	 * the same handles createWorkspaceFromGeometry() does (or null if cmux won't create
	 * it). The fallback for a backup with no usable geometry: cmux gives a new workspace
	 * exactly one pane, so panes 1..n are split off, and since `cmux tree` reports panes
	 * flat — no orientation, no divider ratio — every one of them is a right-split at
	 * cmux's default ratio.
	 */
	protected function createWorkspaceWithPaneSplits(string $wsTitle, ?string $firstCwd, array $bakPanes): ?array {
		if ($this->verbose) {
			$this->cli->msg('    ' . ($firstCwd
				? "Creating with cwd {$firstCwd}"
				: 'Creating with no cwd (nothing recorded, or none of it still exists)'));
		}

		$newWs = $this->cmux->newWorkspaceOrNull($wsTitle, $firstCwd ?: null);
		if (!$newWs) {
			$this->cli->err("    Failed to create workspace '{$wsTitle}'");

			return null;
		}

		$newWsRef = $newWs['ref'];
		$this->cli->msg("    Created as {$newWsRef}", 'green');

		$paneRefs = [0 => $newWs['firstPaneRef'] ?? null];
		$surfRefs = [];
		if (!empty($newWs['firstSurfRef'])) {
			$surfRefs[0][0] = $newWs['firstSurfRef'];
		}

		for ($i = 1, $n = count($bakPanes); $i < $n; $i++) {
			$made = $this->cmux->newPane($newWsRef, 'right');
			if (!$made || empty($made['pane_ref'])) {
				// Pane 1 takes the orphaned surfaces as TABS. Aiming them at pane 1's own
				// surface instead would resume two sessions into one terminal.
				$this->cli->msg('    ⚠ Could not create pane ' . ($i + 1) . ' — its surfaces become tabs in pane 1.', 'yellow');
				$paneRefs[$i] = $paneRefs[0];
				continue;
			}

			$this->cli->msg('    → Pane ' . ($i + 1) . " split right as {$made['pane_ref']}", 'green');
			$paneRefs[$i] = $made['pane_ref'];
			if (!empty($made['surface_ref'])) {
				$surfRefs[$i][0] = $made['surface_ref'];
			}
		}

		return [
			'ws_ref'    => $newWsRef,
			'pane_refs' => $paneRefs,
			'surf_refs' => $surfRefs,
			'cwd'       => $firstCwd,
		];
	}

	/** PURE. Whether any surface in a backed-up workspace carries an agent session. */
	protected function bakWsHasAgentSession(array $bakWs): bool {
		foreach ($bakWs['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				if ($this->normalizeBakSurface($surf)['session_id']) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Ask whether to recreate a missing workspace that holds no agent session at all.
	 * Returns true to create it.
	 *
	 * Enter (or anything unrecognized) means skip: an agent-less workspace carries no
	 * state, so the answer that leaves no litter behind is the default. Silent runs
	 * can neither show the prompt nor read an answer, so they take that default;
	 * --yes means the caller has pre-answered every prompt affirmatively.
	 */
	protected function askCreateHuskWorkspace(array $bakPanes): bool {
		$surfaces = 0;
		foreach ($bakPanes as $pane) {
			$surfaces += count($pane['surfaces'] ?? []);
		}

		$this->cli->msg("  ✗ Not found — and it has no agent sessions to restore ({$surfaces} plain surface(s))", 'yellow');

		if ($this->dryRun) {
			$this->cli->msg('    (dry run — would prompt to recreate the empty workspace or skip)', 'cyan');

			return false;
		}

		if ($this->cli->isAutoconfirm()) {
			$this->cli->msg('    Recreating it anyway (--yes).', 'cyan');

			return true;
		}

		if ($this->cli->isSilent()) {
			return false;
		}

		$this->cli->msg('    Recreating it gives you an empty workspace. What would you like to do?', 'cyan');
		$this->cli->msg('      [c] Create the empty workspace anyway');
		$this->cli->msg('      [s] Skip (default)');

		while (true) {
			$answer = strtolower(trim((string) $this->cli->ask('    Choice [c/s]: ')));
			if ($answer === 'c' || $answer === 'create') {
				return true;
			}
			if ($answer === 's' || $answer === 'skip' || $answer === '') {
				$this->cli->msg('    Skipped.', 'cyan');

				return false;
			}
			$this->cli->msg('    Please enter c or s.', 'yellow');
		}
	}

	/**
	 * The first recorded cwd in a workspace that STILL EXISTS, for `workspace create
	 * --cwd`. A since-deleted directory there would make cmux either fail the create
	 * or open the workspace somewhere unexpected, so stale entries are passed over.
	 */
	protected function firstCwdFromBakWs(array $bakWs) {
		foreach ($bakWs['panes'] ?? [] as $pane) {
			foreach ($pane['surfaces'] ?? [] as $surf) {
				if (!empty($surf['cwd']) && is_dir($surf['cwd'])) {
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
