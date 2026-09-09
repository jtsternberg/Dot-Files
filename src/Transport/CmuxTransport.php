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

	public function selfSurfaceRef(): ?string { return getenv('CMUX_SURFACE_ID') ?: null; }

	public function supportsNonTerminalSurfaces(): bool { return true; }

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

	# =========================================================================
	# surfaces() — the workspace's shape, with whatever agent sits on each surface.
	# Built from tree + debug-terminals + the deterministic joins, so bury
	# classification never has to see any of those three shapes.
	# =========================================================================

	public function surfaces(?string $workspaceRef = null): array {
		$tree  = $this->cmux->tree();
		$debug = $this->cmux->parseDebugTerminals($this->cmux->debugTerminals());
		$uuids = $this->cmux->mapSurfaceUuids($tree);
		$bound = $this->surfaceBindings($debug, $uuids);

		$out = [];
		foreach ($tree['windows'] ?? [] as $window) {
			$windowRef = $window['ref'] ?? null;
			foreach ($window['workspaces'] ?? [] as $ws) {
				$wref = (string) ($ws['ref'] ?? '');
				if ($workspaceRef !== null && $wref !== $workspaceRef) { continue; }
				foreach ($ws['panes'] ?? [] as $paneIdx => $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$ref = (string) ($surf['ref'] ?? '');
						$b   = $bound[$ref] ?? [];
						$out[] = [
							'position'         => (int) ($surf['index_in_pane'] ?? 0),
							'pane_index'       => (int) ($pane['index'] ?? $paneIdx),
							'pane_ref'         => $pane['ref'] ?? null,
							'pane_id'          => $pane['id'] ?? null,
							'selected_in_pane' => (bool) ($surf['selected_in_pane'] ?? false),
							'surface_ref'      => $ref,
							'surface_id'       => (string) ($surf['id'] ?? $ref),
							'workspace_ref'    => $wref,
							'workspace_id'     => $ws['id'] ?? null,
							'workspace_title'  => (string) ($ws['title'] ?? ''),
							'window_ref'       => $windowRef,
							'type'             => (string) ($surf['type'] ?? 'terminal'),
							'title'            => (string) ($surf['title'] ?? ''),
							'url'              => $surf['url'] ?? null,
							// debug-terminals is the tty bury's cwd probe has always used;
							// the tree's own tty is the fallback for a surface it omits.
							'tty'              => ($debug[$ref]['tty'] ?? null) ?: ($surf['tty'] ?? null),
							'cwd'              => $debug[$ref]['cwd'] ?? null,
							'script'           => $debug[$ref]['script'] ?? null,
							'session_id'       => $b['session_id'] ?? null,
							'agent'            => $b['agent'] ?? null,
							'pid'              => $b['pid'] ?? null,
							'targetable'       => (bool) ($b['targetable'] ?? false),
							'reason'           => $b['reason'] ?? null,
						];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * [ surface_ref => ['session_id','agent','pid','targetable','reason'] ] for every
	 * surface an agent is deterministically bound to.
	 *
	 * Reads the SAME two joins liveSessions() does, so there is one answer to "what is
	 * on this surface" rather than a second, quietly different one. It deliberately
	 * stops short of liveSessions()' content-probe second pass: that reads a screen per
	 * unbound surface, and a caller asking for the workspace's shape is not asking for
	 * a screen scrape of all of it.
	 *
	 * Codex wins any contest for a surface, and a codex TUI with no rollout yet still
	 * claims one with a null session_id. Both because a codex bind is OS-exact
	 * (CMUX_SURFACE_ID out of the process's own environment) while Claude's may be a
	 * name match — and because a codex surface misread as anything else gets closed
	 * unarchived (dotfiles-5p5, data loss).
	 */
	protected function surfaceBindings(array $debug, array $surfaceUuids): array {
		$proc = $this->cmux->parseProcTable($this->cmux->psProcTable());
		$rows = array_merge(
			$this->cmux->joinSessionsToSurfaces($this->cmux->loadClaudeSessionsByPid(), $proc, $debug, $surfaceUuids),
			$this->cmux->joinCodexToSurfaces($this->cmux->loadCodexSessionsByPid(), $surfaceUuids)
		);

		$out = [];
		foreach ($rows as $r) {
			$ref = (string) ($r['surface_ref'] ?? '');
			if ($ref === '' || empty($r['session_id'])) { continue; }
			$out[$ref] = [
				'session_id' => $r['session_id'],
				'agent'      => $r['agent'] ?? 'claude',
				'pid'        => $r['pid'] ?? null,
				'targetable' => (bool) ($r['targetable'] ?? false),
				'reason'     => ($r['reason'] ?? '') !== '' ? $r['reason'] : null,
			];
		}

		foreach ($this->cmux->codexSurfaceIdsByPid() as $pid => $surfaceId) {
			$ref = (string) ($surfaceUuids[$surfaceId]['surface_ref'] ?? '');
			if ($ref === '' || ($out[$ref]['agent'] ?? null) === 'codex') { continue; }
			$out[$ref] = [
				'session_id' => null,
				'agent'      => 'codex',
				'pid'        => (int) $pid,
				'targetable' => false,
				'reason'     => 'live codex with no rollout yet (zero-turn session)',
			];
		}

		return $out;
	}

	public function windowExists(string $windowRef): bool {
		return $this->cmux->windowRefExists($this->cmux->tree(), $windowRef);
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
			// A poller-shaped bulk read: an unreadable surface simply matches no probe.
			$screenByRef[$ref] = $this->readScreen($ref, $d['workspace_ref'] ?? '', 8) ?? '';
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
	 *
	 * No-output and failure are the SAME shell_exec answer here (null on error and on a
	 * command that printed nothing, '' on an empty pipe), so this seam cannot tell them
	 * apart and reports the safe one: null, "no evidence". A poller coalesces that to ''
	 * and loses an iteration; the busy check refuses. Reading a blank screen as unknown
	 * costs nothing real — a surface only reaches the busy check once its gate 1 has
	 * proved a live agent is hosted there, and a live agent's TUI is never blank.
	 */
	public function readScreen(string $surfaceRef, string $workspaceRef, int $lines = 0): ?string {
		$cmd = escapeshellcmd($this->cmux->cmuxBin()) . ' read-screen --surface ' . escapeshellarg($surfaceRef)
			 . ' --workspace ' . escapeshellarg($workspaceRef);
		if ($lines > 0) { $cmd .= ' --lines ' . (int) $lines; }

		$out = shell_exec($cmd . ' 2>/dev/null');

		return ($out === null || $out === false || $out === '') ? null : $out;
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
