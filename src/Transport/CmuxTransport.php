<?php
namespace JT\Transport;

use JT\Helpers\AgentArtifacts;
use JT\Helpers\Cmux;
use JT\Helpers\StatuslineProbeTrait;

/**
 * cmux behind the SessionTransport seam.
 *
 * Composes Helpers\Cmux rather than extending it: Cmux is still cmux-bak's
 * library and keeps its whole public surface, while this class owns only the
 * part graveyard needs — plus the session<->surface JOIN that used to live in
 * Graveyard. That join (ps ancestry, CMUX_SURFACE_ID, debug-terminals, the
 * content probe) reasons entirely in cmux shapes, which is exactly why it does
 * not belong above the seam.
 */
class CmuxTransport implements SessionTransport
{
	use StatuslineProbeTrait;

	protected $cli;
	protected Cmux $cmux;
	protected AgentArtifacts $artifacts;

	public function __construct($cli, Cmux $cmux, ?AgentArtifacts $artifacts = null) {
		$this->cli       = $cli;
		$this->cmux      = $cmux;
		$this->artifacts = $artifacts ?: new AgentArtifacts($cli);
	}

	public function name(): string { return 'cmux'; }

	public function available(): bool { return $this->cmux->ping(); }

	public function supportsNonTerminalSurfaces(): bool { return true; }

	/**
	 * The raw cmux client, for Graveyard code that still reasons in cmux shapes.
	 *
	 * @deprecated Removed in Task 3c. Bury classification and resurrect still walk
	 * the cmux tree and debug-terminals dump directly; once they move onto
	 * workspaceSurfaces() nothing above the seam needs a cmux client.
	 */
	public function cmux(): Cmux { return $this->cmux; }

	# =========================================================================
	# liveSessions() — the contract, and the cmux join that produces it.
	# Moved verbatim out of Graveyard; the only change is the added `transport`
	# key and the collaborators the calls now route through.
	# =========================================================================

	public function liveSessions(): array {
		// Deterministic session<->surface joins. Both agents bind on CMUX_SURFACE_ID
		// against the tree's per-surface id (dotfiles-zcm, dotfiles-dr9); Claude also
		// bridges through a resume script when it was resurrected behind one
		// (dotfiles-yt2). Never by tty — tty numbers are recycled across live
		// surfaces, so a tty join mis-pairs. BOTH joins need the surface-UUID map:
		// starve the Claude join of it and every cmux-launched session goes unbound.
		$sessions     = $this->cmux->loadClaudeSessionsByPid();
		$proc         = $this->cmux->parseProcTable($this->cmux->psProcTable());
		$debug        = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
		$tree         = $this->cmux->tree();
		$surfaceUuids = $this->cmux->mapSurfaceUuids($tree);
		$joined       = array_merge(
			$this->cmux->joinSessionsToSurfaces($sessions, $proc, $debug, $surfaceUuids),
			$this->cmux->joinCodexToSurfaces(
				$this->cmux->loadCodexSessionsByPid(),
				$surfaceUuids
			)
		);

		// Tree supplies stable surface UUID + workspace/surface titles, keyed by ref.
		$treeIx = $this->treeIndex($tree);
		$now    = time();
		$out    = [];

		foreach ($joined as $j) {
			if (!$j['session_id']) { continue; }
			$out[] = $this->liveSessionRow($j, $treeIx, $now);
		}

		// Second pass (dotfiles-c15): content-probe fallback for Claude sessions the
		// ancestry join left unbound (fresh / non-cmux-resumed). Bind each to a still-
		// unbound terminal surface by matching its on-screen statusline cwd, uniquely.
		$out = $this->bindUnresolvedByContentProbe($out, $debug, $treeIx);

		return $this->artifacts->dedupBySessionId($out);
	}

	/**
	 * One join row, normalized to the SessionTransport row shape.
	 *
	 * Its own method because the key set is the contract: every graveyard verb and
	 * buildTombstone() read these keys by name, so a dropped one is a silent behavior
	 * change (see `opts` below) rather than an error. Pinned by
	 * tests/Transport/SessionTransportContractTest.php.
	 */
	protected function liveSessionRow(array $j, array $treeIx, int $now): array {
		$agent = $j['agent'] ?? 'claude';
		if ($agent === 'codex') {
			$rollout = $this->artifacts->codexRolloutPathFor($j['session_id']);
			$ts      = $rollout !== null ? $this->artifacts->codexLastActivity($rollout) : null;
		} else {
			$ts = $this->artifacts->lastRealActivity($j['session_id'], $j['cwd']);
		}
		$idle = $ts !== null ? ($now - $ts) : PHP_INT_MAX;
		$ref  = $j['surface_ref'];

		return [
			'transport'       => $this->name(),
			'session_id'      => $j['session_id'],
			'agent'           => $agent,
			'cwd'             => $j['cwd'],
			'model'           => $j['model'],
			'skip_perms'      => $j['skip_perms'],
			// Agent-specific knobs (codex sandbox/approval/effort). MUST be carried:
			// buildTombstone() stores them as agent_opts and resurrect replays them,
			// and `codex resume` re-reads config rather than rehydrating turn_context —
			// so dropping them here silently widens a restored session's sandbox.
			'opts'            => $j['opts'] ?? [],
			'pid'             => $j['pid'],
			'tty'             => $j['tty'],
			'surface_ref'     => $ref,
			'surface_id'      => $treeIx['surface'][$ref]['id'] ?? $ref,
			// Where it currently lives, so bury can record a home to resurrect into.
			'home_workspace_id'  => $treeIx['surface'][$ref]['workspace_id'] ?? null,
			'home_pane_id'       => $treeIx['surface'][$ref]['pane_id'] ?? null,
			'pane_ref'           => $treeIx['surface'][$ref]['pane_ref'] ?? null,
			'home_index_in_pane' => $treeIx['surface'][$ref]['index_in_pane'] ?? null,
			'workspace_ref'   => $j['workspace_ref'],
			'window_ref'      => $treeIx['workspace_window'][$j['workspace_ref']] ?? null,
			'workspace_title' => $treeIx['workspace'][$j['workspace_ref']] ?? '',
			'tab_title'       => $treeIx['surface'][$ref]['title'] ?? $j['title'],
			'idle_seconds'    => $idle,
			'targetable'      => $j['targetable'],
			'reason'          => $j['reason'],
			'no_bridge'       => $j['no_bridge'] ?? false,
		];
	}

	/**
	 * PURE. Index a cmux tree for ref-keyed lookups:
	 *   ['surface' => [surface_ref => ['id','title']], 'workspace' => [workspace_ref => title]].
	 */
	public function treeIndex(array $tree): array {
		$ix = ['surface' => [], 'workspace' => [], 'workspace_window' => []];
		foreach ($tree['windows'] ?? [] as $window) {
			$windowRef = $window['ref'] ?? null;
			foreach ($window['workspaces'] ?? [] as $ws) {
				$wref = $ws['ref'] ?? '';
				if ($wref) { $ix['workspace'][$wref] = $ws['title'] ?? ''; }
				if ($wref && $windowRef) { $ix['workspace_window'][$wref] = $windowRef; }
				foreach ($ws['panes'] ?? [] as $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$ref = $surf['ref'] ?? '';
						if (!$ref) { continue; }
						$ix['surface'][$ref] = [
							'id'    => $surf['id'] ?? $ref,
							'title' => $surf['title'] ?? '',
							// Where this tab lives, by UUID, so a tombstone can be resurrected
							// back into it. Refs are positional and get reassigned, so they are
							// useless for something read back minutes or days later.
							'workspace_id'  => $ws['id'] ?? null,
							'pane_id'       => $pane['id'] ?? null,
							'pane_ref'      => $pane['ref'] ?? null,
							'index_in_pane' => $surf['index_in_pane'] ?? 0,
						];
					}
				}
			}
		}
		return $ix;
	}

	/**
	 * I/O wrapper for the content-probe fallback (dotfiles-c15). Finds Claude sessions
	 * for which the join had NO bridge at all (no_bridge — neither a resume-script
	 * ancestor nor a CMUX_SURFACE_ID), reads each still-unbound terminal surface's
	 * screen, and upgrades a row to targetable when contentProbeBind() finds it a
	 * unique cwd match.
	 *
	 * Now a genuine last resort: CMUX_SURFACE_ID binds cmux-launched sessions exactly
	 * (dotfiles-dr9), so this fires only for a session cmux never labelled — or one
	 * whose env we could not read. A row that names a closed surface is deliberately
	 * NOT a candidate: it is somewhere else, so a cwd guess would mis-bind it.
	 */
	protected function bindUnresolvedByContentProbe(array $rows, array $debug, array $treeIx): array {
		$bound = [];
		$fresh = [];
		foreach ($rows as $i => $r) {
			if ($r['targetable']) { if ($r['surface_ref'] !== '') { $bound[$r['surface_ref']] = true; } continue; }
			if (!empty($r['no_bridge'])) {
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
			$screenByRef[$ref] = $this->readScreen($ref, $d['workspace_ref'] ?? '', 8);
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

	# =========================================================================
	# Drive an existing surface.
	# =========================================================================

	public function sendText(string $surfaceRef, string $workspaceRef, string $text): void {
		$this->cmux->sendToSurface($surfaceRef, $workspaceRef, $text);
	}

	public function sendKey(string $surfaceRef, string $workspaceRef, string $key): void {
		$this->cmux->sendKeyToSurface($surfaceRef, $workspaceRef, $key);
	}

	/**
	 * cmux's read-screen. Shelled here rather than through Cmux::readScreen() because
	 * only this seam knows about --lines, and the bury gates depend on reading a bounded
	 * tail (a whole 200-line scrollback would match an active-turn marker from minutes
	 * ago). $lines = 0 omits the flag, which is Cmux::readScreen()'s behavior.
	 */
	public function readScreen(string $surfaceRef, string $workspaceRef, int $lines = 0): string {
		$cmd = escapeshellcmd($this->cmux->cmuxBin()) . ' read-screen --surface ' . escapeshellarg($surfaceRef)
			 . ' --workspace ' . escapeshellarg($workspaceRef);
		if ($lines > 0) { $cmd .= ' --lines ' . (int) $lines; }

		return (string) shell_exec($cmd . ' 2>/dev/null');
	}

	# =========================================================================
	# Describe / resolve.
	# =========================================================================

	public function describeWorkspace(string $handle, string $fallbackTitle = ''): string {
		return $this->cmux->describeWorkspace($handle, $fallbackTitle);
	}

	public function resolveWorkspace(string $nameOrRef): ?array {
		return $this->cmux->resolveWorkspaceNode($this->cmux->tree(), $nameOrRef);
	}

	public function workspaceSurfaceCount(string $workspaceRef): int {
		return $this->cmux->workspaceSurfaceCount($workspaceRef);
	}

	# =========================================================================
	# Create.
	# =========================================================================

	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): ?array {
		return $this->cmux->newWorkspaceOrNull($title, $cwd, $windowRef);
	}

	public function newSurface(string $workspaceRef, ?string $paneRef, string $type, ?string $command): ?string {
		return $this->cmux->createSurface($workspaceRef, $paneRef, $type, $command);
	}

	public function newSplit(string $workspaceRef, string $fromSurfaceRef, string $direction): ?string {
		return $this->cmux->newSplit($workspaceRef, $fromSurfaceRef, $direction);
	}

	public function selectSurface(string $workspaceRef, string $surfaceRef): bool {
		return $this->cmux->selectSurface($workspaceRef, $surfaceRef);
	}

	public function paneRefForSurface(string $workspaceRef, string $surfaceRef): ?string {
		return $this->cmux->paneRefForSurface($workspaceRef, $surfaceRef);
	}

	# =========================================================================
	# Teardown.
	# =========================================================================

	public function closeWorkspace(string $workspaceRef): array {
		return $this->cli->getCommandOutputAndExitCode(
			escapeshellcmd($this->cmux->cmuxBin()) . ' workspace close ' . escapeshellarg($workspaceRef)
		);
	}

	public function closeSurface(string $surfaceRef): array {
		return $this->cli->getCommandOutputAndExitCode(
			escapeshellcmd($this->cmux->cmuxBin()) . ' close-surface --surface ' . escapeshellarg($surfaceRef)
		);
	}

	# =========================================================================
	# Layout capture / replay.
	# =========================================================================

	public function captureLayoutTree(string $workspaceRef): ?array {
		return $this->cmux->captureLayoutTree($workspaceRef);
	}

	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array {
		return $this->cmux->newWorkspaceWithLayout($title, $cwd, $layoutTree, $windowRef);
	}

	public function sanitizeLayoutTree(array $node, array $dropSurfaceKeys = ['command']): array {
		return $this->cmux->sanitizeLayoutTree($node, $dropSurfaceKeys);
	}

	public function layoutTreeSurfaceCount(array $node): int {
		return $this->cmux->layoutTreeSurfaceCount($node);
	}
}
