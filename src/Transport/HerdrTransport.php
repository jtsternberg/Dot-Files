<?php
namespace JT\Transport;

use JT\Helpers\AgentArtifacts;
use JT\Helpers\Herdr;
use RuntimeException;

/**
 * herdr behind the SessionTransport seam.
 *
 * This class is short for one reason: herdr REPORTS the session<->surface binding.
 * `agent_session.value` is the agent's own session id, published by herdr's
 * `herdr:claude` / `herdr:codex` integration hook, and it arrives in the same
 * snapshot as the pane, tab, workspace, cwd and title. CmuxTransport has to
 * RECONSTRUCT the same fact from `ps` ancestry, `CMUX_SURFACE_ID`, a
 * debug-terminals dump and — when all of that comes up empty — an on-screen cwd
 * probe. So there is no join here, only a field mapping.
 *
 * Consequently the content-probe / statusline fallback is deliberately NOT ported.
 * It exists because cmux can leave a session unbound; herdr's hook cannot. A
 * screen scrape here would be a second, quietly different answer to a question
 * herdr already answers exactly.
 *
 * Everything the mapping cannot answer answers the honest empty value, and says
 * why at the field. Three are worth reading before changing them: `tty` is always
 * null (herdr owns the PTY and this repo never joins by tty — numbers are recycled
 * across live surfaces), `script` is always null (herdr launches agents directly,
 * with no resume-script bridge to name), and `captureLayoutTree()` returns null
 * (see its docblock — herdr's geometry is real but unreachable from its CLI).
 */
class HerdrTransport implements SessionTransport
{
	protected $cli;
	protected Herdr $herdr;
	protected AgentArtifacts $artifacts;

	public function __construct($cli, Herdr $herdr, ?AgentArtifacts $artifacts = null) {
		$this->cli       = $cli;
		$this->herdr     = $herdr;
		$this->artifacts = $artifacts ?: new AgentArtifacts($cli);
	}

	public function name(): string { return 'herdr'; }

	public function available(): bool { return $this->herdr->available(); }

	/**
	 * False: a herdr pane hosts a terminal and nothing else — no browser, no markdown
	 * viewer. A grouped restore of a cmux-buried workspace therefore DROPS those
	 * members, which is why Graveyard has to ask before restoring into herdr.
	 */
	public function supportsNonTerminalSurfaces(): bool { return false; }

	# =========================================================================
	# liveSessions() — the contract row, mapped straight off one snapshot.
	# =========================================================================

	public function liveSessions(): array {
		$snap = $this->snapshot();
		$now  = time();
		$out  = [];

		foreach ($this->boundAgents($snap) as $bound) {
			$out[] = $this->liveSessionRow($bound, $snap, $now);
		}

		// Same close as CmuxTransport: one row per session, first surface wins. A single
		// agent should only ever occupy one herdr pane, but the contract is per-session
		// and every caller reads it that way.
		return $this->artifacts->dedupBySessionId($out);
	}

	/**
	 * One snapshot agent, normalized to the SessionTransport row shape.
	 *
	 * Its own method for the same reason CmuxTransport's is: the key SET is the
	 * contract — buildTombstone() reads these by name, so a dropped key is a silent
	 * behavior change rather than an error. Pinned by
	 * tests/Transport/SessionTransportContractTest.php against the cmux row.
	 */
	protected function liveSessionRow(array $bound, array $snap, int $now): array {
		$paneId = $bound['pane_id'];
		$wsId   = $bound['workspace_id'];

		return [
			'transport'  => $this->name(),
			'session_id' => $bound['session_id'],
			'agent'      => $bound['agent'],
			'cwd'        => $bound['cwd'],
			'model'      => $bound['model'],
			'skip_perms' => $bound['skip_perms'],
			// Codex sandbox/approval/effort. MUST be carried: buildTombstone() stores
			// them as agent_opts and resurrect replays them, because `codex resume`
			// re-reads config rather than rehydrating turn_context — so dropping them
			// silently widens a restored session's sandbox.
			'opts'       => $bound['opts'],
			'pid'        => $bound['pid'],
			// Always null. herdr owns the PTY, and a tty is not a join key in this repo
			// anyway: tty numbers are recycled across live surfaces, so a tty join
			// mis-pairs sessions. Nothing below this seam may invent one.
			'tty'                => null,
			// herdr pane ids (`wF:p3`) are already stable for the pane's life — there is
			// no positional-ref/stable-uuid split as in cmux, so ref and id are the same
			// handle. Recording both keeps a tombstone readable by either lookup.
			'surface_ref'        => $paneId,
			'surface_id'         => $paneId,
			'home_workspace_id'  => $wsId,
			'home_pane_id'       => $paneId,
			'pane_ref'           => $paneId,
			// A herdr pane holds exactly one terminal, so a surface is always the first
			// (and only) member of its pane.
			'home_index_in_pane' => 0,
			'workspace_ref'      => $wsId,
			'window_ref'         => $bound['tab_id'],
			'workspace_title'    => $this->workspaceTitle($snap, $wsId),
			'tab_title'          => $bound['title'],
			'idle_seconds'       => $this->idleSeconds($bound, $now),
			'targetable'         => $bound['targetable'],
			'reason'             => $bound['reason'],
			// herdr starts agents itself, so a resurrected session never rides a resume
			// script — there is no bridge to be missing, and the content-probe fallback
			// this flag gates does not exist here.
			'no_bridge'          => false,
		];
	}

	/**
	 * Idle from the SAME on-disk artifact cmux reads, never from herdr's own
	 * `agent_status`. That is the point: `graveyard candidates` must rank a herdr
	 * session against a cmux one, so both idle clocks have to be the last real
	 * conversation turn. PHP_INT_MAX when the transcript cannot be read, matching
	 * CmuxTransport.
	 */
	protected function idleSeconds(array $bound, int $now): int {
		if ($bound['agent'] === 'codex') {
			$rollout = $this->artifacts->codexRolloutPathFor($bound['session_id']);
			$ts      = $rollout !== null ? $this->artifacts->codexLastActivity($rollout) : null;
		} else {
			$ts = $this->artifacts->lastRealActivity($bound['session_id'], $bound['cwd']);
		}
		return $ts !== null ? ($now - $ts) : PHP_INT_MAX;
	}

	# =========================================================================
	# surfaces() — the workspace's shape, with whatever agent sits on each pane.
	# =========================================================================

	public function surfaces(?string $workspaceRef = null): array {
		$snap  = $this->snapshot();
		$bound = [];
		foreach ($this->boundAgents($snap) as $b) { $bound[$b['pane_id']] = $b; }

		$out = [];
		foreach ($this->panesByWorkspace($snap) as $wsId => $panes) {
			if ($workspaceRef !== null && $wsId !== $workspaceRef) { continue; }
			$title = $this->workspaceTitle($snap, $wsId);
			foreach (array_values($panes) as $paneIdx => $pane) {
				$paneId = (string) ($pane['pane_id'] ?? '');
				$b      = $bound[$paneId] ?? [];
				$out[]  = [
					// One terminal per pane, so a surface is always at position 0 of its
					// pane and is always the selected one — herdr has no tab stack inside
					// a pane for either value to vary over.
					'position'         => 0,
					'pane_index'       => $paneIdx,
					'pane_ref'         => $paneId,
					'pane_id'          => $paneId,
					'selected_in_pane' => true,
					'surface_ref'      => $paneId,
					'surface_id'       => $paneId,
					'workspace_ref'    => (string) $wsId,
					'workspace_id'     => (string) $wsId,
					'workspace_title'  => $title,
					'window_ref'       => $pane['tab_id'] ?? null,
					// herdr panes are terminals only — this is the F5 gap that
					// supportsNonTerminalSurfaces() reports.
					'type'             => 'terminal',
					'title'            => (string) ($pane['terminal_title_stripped'] ?? ''),
					'url'              => null,
					// See the row builder: never a tty.
					'tty'              => null,
					'cwd'              => $pane['cwd'] ?? null,
					// No resume-script bridge to name (see the class docblock).
					'script'           => null,
					'session_id'       => $b['session_id'] ?? null,
					'agent'            => $b['agent'] ?? null,
					'pid'              => $b['pid'] ?? null,
					'targetable'       => (bool) ($b['targetable'] ?? false),
					'reason'           => $b['reason'] ?? null,
				];
			}
		}
		return $out;
	}

	/**
	 * Panes grouped by workspace id, each group in the order herdr LAYS THE WORKSPACE
	 * OUT rather than the order `panes[]` happens to arrive in.
	 *
	 * The order is load-bearing, not cosmetic: bury numbers a group's members by it
	 * and resurrect replays that numbering, so a run that read the panes in a
	 * different order would restore the workspace transposed. `layouts[].panes[]` is
	 * herdr's own geometric order, so it wins; anything the layout omits is appended
	 * in snapshot order so a pane can never be dropped from the shape entirely.
	 *
	 * @return array<string, list<array>> workspace_id => panes
	 */
	protected function panesByWorkspace(array $snap): array {
		$byId = [];
		foreach ($snap['panes'] ?? [] as $pane) {
			$id = (string) ($pane['pane_id'] ?? '');
			if ($id !== '') { $byId[$id] = $pane; }
		}

		$out  = [];
		$seen = [];
		foreach ($snap['layouts'] ?? [] as $layout) {
			$wsId = (string) ($layout['workspace_id'] ?? '');
			if ($wsId === '') { continue; }
			foreach ($layout['panes'] ?? [] as $lp) {
				$id = (string) ($lp['pane_id'] ?? '');
				if ($id === '' || !isset($byId[$id])) { continue; }
				$out[$wsId][] = $byId[$id];
				$seen[$id]    = true;
			}
		}
		foreach ($byId as $id => $pane) {
			if (isset($seen[$id])) { continue; }
			$wsId = (string) ($pane['workspace_id'] ?? '');
			if ($wsId === '') { continue; }
			$out[$wsId][] = $pane;
		}
		return $out;
	}

	# =========================================================================
	# The one binding. Read by BOTH liveSessions() and surfaces(), so there is a
	# single answer to "what agent is on this pane" rather than two that can drift
	# (CLAUDE.md: several views of one dataset read one accessor).
	# =========================================================================

	/**
	 * Every snapshot agent graveyard can act on, annotated with the process facts.
	 *
	 * Two kinds of agent are dropped rather than reported:
	 *   - one whose `agent_session.kind` is not `id`, or whose value is empty. herdr
	 *     reports a pane it has detected an agent in before the integration hook has
	 *     published a session id; there is nothing to bury yet, and a row with an
	 *     empty session_id would be read as a targetable session by every caller.
	 *   - one whose kind is neither claude nor codex. graveyard archives those two
	 *     agents' transcripts and no others, so a gemini or cursor pane it cannot
	 *     resume must not be presented as buryable.
	 *
	 * @return list<array>
	 */
	protected function boundAgents(array $snap): array {
		$out = [];
		foreach ($snap['agents'] ?? [] as $agent) {
			$kind = (string) ($agent['agent'] ?? '');
			if ($kind !== 'claude' && $kind !== 'codex') { continue; }

			$session = $agent['agent_session'] ?? [];
			if ((string) ($session['kind'] ?? '') !== 'id') { continue; }
			$sid = (string) ($session['value'] ?? '');
			if ($sid === '') { continue; }

			$paneId = (string) ($agent['pane_id'] ?? '');
			$cwd    = (string) ($agent['cwd'] ?? '');
			$proc   = $this->agentProcess($paneId, $kind);

			// Everything bury needs to reach a session: who it is, where it runs, and
			// which pane to type into. Named individually so the abort report can tell
			// JT which one is missing rather than just "untargetable".
			$missing = [];
			if ($cwd === '')    { $missing[] = 'no cwd reported by herdr'; }
			if ($paneId === '') { $missing[] = 'no pane reported by herdr'; }

			$out[] = [
				'session_id'   => $sid,
				'agent'        => $kind,
				'cwd'          => $cwd,
				'pane_id'      => $paneId,
				'workspace_id' => (string) ($agent['workspace_id'] ?? ''),
				'tab_id'       => $agent['tab_id'] ?? null,
				'title'        => (string) ($agent['terminal_title_stripped'] ?? ''),
				'pid'          => $proc['pid'],
				'model'        => $this->resolveModel($kind, $sid, $cwd, $proc),
				'skip_perms'   => $this->resolveSkipPerms($kind, $sid, $cwd, $proc),
				'opts'         => $this->resolveOpts($kind, $sid, $proc),
				'targetable'   => $missing === [],
				'reason'       => $missing === [] ? null : implode('; ', $missing),
			];
		}
		return $out;
	}

	/**
	 * The agent's own process in its pane: `['pid' => ?int, 'argv' => list<string>]`.
	 *
	 * herdr hands over a real argv ARRAY, so the launch flags are read by exact token
	 * match — no `ps` text scraping, and no risk of a `--model` inside a quoted prompt
	 * being mistaken for the flag. A pane whose process-info herdr will not answer
	 * (it exited between the snapshot and this call, or herdr could not read its argv)
	 * degrades to a null pid and an empty argv, which is what the artifact fallbacks
	 * below are for.
	 */
	protected function agentProcess(string $paneId, string $agent): array {
		if ($paneId === '') { return ['pid' => null, 'argv' => []]; }

		try {
			$info = $this->herdr->paneProcessInfo($paneId);
		} catch (RuntimeException $e) {
			return ['pid' => null, 'argv' => []];
		}

		foreach ($info['foreground_processes'] ?? [] as $p) {
			if ((string) ($p['argv0'] ?? '') !== $agent) { continue; }
			$pid = isset($p['pid']) ? (int) $p['pid'] : null;
			return [
				'pid'  => $pid !== null && $pid > 0 ? $pid : null,
				'argv' => array_map('strval', array_values((array) ($p['argv'] ?? []))),
			];
		}
		return ['pid' => null, 'argv' => []];
	}

	/**
	 * The model a Claude session is actually on.
	 *
	 * Precedence is the SAME as cmux's, and deliberately not argv-first: the jsonl
	 * records the resolved model of the last assistant turn, so it survives a
	 * mid-session `/model`, which a launch flag never would. herdr's argv is only the
	 * next-best answer — better than AgentArtifacts' own `ps` fallback because it
	 * needs no shell-out and cannot mis-tokenize, but still just the launch flag.
	 * Codex records its model in the rollout's last turn_context for the same reason.
	 */
	protected function resolveModel(string $agent, string $sid, string $cwd, array $proc): ?string {
		$recorded = $agent === 'codex'
			? $this->codexContext($sid)['model']
			: $this->artifacts->readSessionJsonl($sid, $cwd)['model'];

		return $this->artifacts->resolveModel($recorded ?? $this->argvValue($proc['argv'], ['--model']), $proc['pid']);
	}

	/**
	 * Whether a Claude session is in bypass-permissions mode.
	 *
	 * jsonl first, again because it reflects the mode at the END of the conversation
	 * and so captures a mid-session shift+tab toggle. Only when the transcript says
	 * nothing at all does the launch flag get a vote. Codex has no analogue — it
	 * expresses the same idea through sandbox/approval, which ride in `opts`.
	 */
	protected function resolveSkipPerms(string $agent, string $sid, string $cwd, array $proc): bool {
		if ($agent === 'codex') { return false; }

		$mode = $this->artifacts->readSessionJsonl($sid, $cwd)['permission_mode'];
		if ($mode !== null) { return $this->artifacts->resolveSkipPerms($mode, null); }

		return in_array('--dangerously-skip-permissions', $proc['argv'], true)
			|| $this->artifacts->resolveSkipPerms(null, $proc['pid']);
	}

	/**
	 * Codex's sandbox / approval / reasoning-effort, as `agent_opts` on the tombstone.
	 *
	 * The rollout's last turn_context is the source of truth here for a sharper reason
	 * than model is: `codex resume` does NOT rehydrate these, and a session created
	 * read-only and resumed bare comes back with full access (dotfiles-f1n). A
	 * mid-session tightening therefore has to win over the launch flags, so argv only
	 * fills a value the rollout never recorded — the ~45% of sessions (every Codex
	 * Desktop one) that carry no turn_context at all. `effort` has no launch flag to
	 * fall back to.
	 */
	protected function resolveOpts(string $agent, string $sid, array $proc): array {
		if ($agent !== 'codex') { return []; }

		$ctx = $this->codexContext($sid);
		return [
			'sandbox'  => $ctx['sandbox']  ?? $this->argvValue($proc['argv'], ['--sandbox', '-s']),
			'approval' => $ctx['approval'] ?? $this->argvValue($proc['argv'], ['--ask-for-approval', '-a']),
			'effort'   => $ctx['effort'],
		];
	}

	/** The codex rollout context for a session id, or the all-null shape when it is gone. */
	protected function codexContext(string $sid): array {
		$rollout = $this->artifacts->codexRolloutPathFor($sid);
		return $rollout !== null
			? $this->artifacts->codexRolloutContext($rollout)
			: ['model' => null, 'sandbox' => null, 'approval' => null, 'effort' => null, 'has_turn_context' => false];
	}

	/**
	 * PURE. The value of the first of $flags present in an argv array, in either
	 * `--flag value` or `--flag=value` form. Null when none is present.
	 */
	protected function argvValue(array $argv, array $flags): ?string {
		$count = count($argv);
		for ($i = 0; $i < $count; $i++) {
			foreach ($flags as $flag) {
				if ($argv[$i] === $flag) {
					$next = $argv[$i + 1] ?? null;
					// A following token that is itself a flag means this one took no value.
					return ($next !== null && !str_starts_with($next, '-')) ? $next : null;
				}
				if (str_starts_with($argv[$i], $flag . '=')) {
					$value = substr($argv[$i], strlen($flag) + 1);
					return $value !== '' ? $value : null;
				}
			}
		}
		return null;
	}

	# =========================================================================
	# Describe / resolve.
	# =========================================================================

	/** A workspace's herdr label, falling back to its id (which is what the UI shows). */
	protected function workspaceTitle(array $snap, string $wsId): string {
		foreach ($snap['workspaces'] ?? [] as $ws) {
			if ((string) ($ws['workspace_id'] ?? '') === $wsId) {
				$label = (string) ($ws['label'] ?? '');
				return $label !== '' ? $label : $wsId;
			}
		}
		return $wsId;
	}

	/**
	 * Where a workspace actually IS, phrased so JT can find it on screen — the same
	 * job CmuxTransport's does, with herdr's sidebar slot in place of cmux's. No
	 * window clause: herdr's workspaces are a single flat list.
	 */
	public function describeWorkspace(string $handle, string $fallbackTitle = ''): string {
		$spaces = array_values($this->snapshot()['workspaces'] ?? []);
		foreach ($spaces as $i => $ws) {
			if ((string) ($ws['workspace_id'] ?? '') !== $handle) { continue; }
			return sprintf('"%s" (workspace %d of %d, %s)',
				(string) ($ws['label'] ?? $handle), $i + 1, count($spaces), $handle);
		}
		return $fallbackTitle !== '' ? sprintf('"%s" (%s)', $fallbackTitle, $handle) : $handle;
	}

	/**
	 * Resolve a workspace id or label to its handle + title.
	 *
	 * Same three-tier match as cmux, including the exact-title tiebreak that exists
	 * because a workspace auto-labelled after its running command CONTAINS the query
	 * as a substring and made the command ambiguous against itself (dotfiles-w7k).
	 * `window_ref` is the workspace's active tab, herdr's counterpart of a cmux
	 * window ref.
	 */
	public function resolveWorkspace(string $nameOrRef): ?array {
		$needle  = $this->artifacts->normalizeTitle($nameOrRef);
		$exact   = [];
		$matches = [];

		foreach ($this->snapshot()['workspaces'] ?? [] as $ws) {
			$id    = (string) ($ws['workspace_id'] ?? '');
			$label = (string) ($ws['label'] ?? '');
			$hit   = ['ref' => $id, 'title' => $label, 'node' => $ws, 'window_ref' => $ws['active_tab_id'] ?? ''];

			if ($id !== '' && $id === $nameOrRef) { return $hit; }
			if ($needle !== '' && $this->artifacts->normalizeTitle($label) === $needle) { $exact[] = $hit; }
			if ($nameOrRef !== '' && stripos($label, $nameOrRef) !== false) { $matches[] = $hit; }
		}

		foreach ([$exact, $matches] as $set) {
			if (count($set) === 1) { return $set[0]; }
			if (count($set) > 1) {
				$titles = implode(', ', array_map(fn($m) => "{$m['ref']} \"{$m['title']}\"", $set));
				throw new RuntimeException("Ambiguous workspace '{$nameOrRef}' — matches: {$titles}");
			}
		}
		return null;
	}

	/** Panes in a workspace — a herdr pane holds exactly one surface, so this is both. */
	public function workspaceSurfaceCount(string $workspaceRef): int {
		return count($this->panesByWorkspace($this->snapshot())[$workspaceRef] ?? []);
	}

	/**
	 * Does this tab still exist? A stored handle must read as gone rather than be
	 * handed to a create call, because herdr reassigns `wF:t1`-shaped ids after a
	 * server restart, so a ref recorded before one names whatever occupies that slot.
	 */
	public function windowExists(string $windowRef): bool {
		if ($windowRef === '') { return false; }

		$snap = $this->snapshot();
		foreach ($snap['tabs'] ?? [] as $tab) {
			if ((string) ($tab['tab_id'] ?? '') === $windowRef) { return true; }
		}
		// Fall back to the panes: `tabs[]` is a convenience list, and a pane naming the
		// tab is proof enough that it is there.
		foreach ($snap['panes'] ?? [] as $pane) {
			if ((string) ($pane['tab_id'] ?? '') === $windowRef) { return true; }
		}
		return false;
	}

	# =========================================================================
	# Drive an existing surface.
	#
	# sendText/sendKey let herdr's RuntimeException through on purpose: bury types
	# `/export` into a live REPL, and a send that silently did nothing would leave
	# bury waiting on a transcript that is never written. bin/graveyard's entry seam
	# turns it into an exitErr (dotfiles-3qa).
	# =========================================================================

	public function sendText(string $surfaceRef, string $workspaceRef, string $text): void {
		$this->herdr->paneSendText($surfaceRef, $text);
	}

	/**
	 * Send one key press, translating graveyard's cmux-flavoured key names.
	 *
	 * The two CLIs do NOT share a key vocabulary: cmux takes `Return`/`Escape`, herdr
	 * takes `enter`/`esc` (`herdr --default-config`: "special keys like
	 * enter/tab/esc/left/right/up/down"; `herdr pane send-keys --help` adds that `esc`
	 * is canonical and `escape` also accepted). herdr validates every key before
	 * writing any bytes, so an untranslated `Return` is a hard failure — which is the
	 * good case. The bad case is the reverse: a name that parses as a literal
	 * character would type it into the REPL and bury would hang waiting for a
	 * submission that never happened. Hence an explicit map, and pass-through
	 * lowercased for everything herdr already understands (`ctrl+c`, `tab`, arrows).
	 */
	public function sendKey(string $surfaceRef, string $workspaceRef, string $key): void {
		$this->herdr->paneSendKeys($surfaceRef, $this->herdrKeyName($key));
	}

	/** PURE. cmux/graveyard key name => herdr key name. */
	public function herdrKeyName(string $key): string {
		// A bare newline is a submission, not whitespace — matched before trim(), which
		// would otherwise reduce it to '' and send herdr an empty key.
		if (trim($key) === '' && $key !== '') { return 'enter'; }

		$map = ['return' => 'enter', 'enter' => 'enter', 'escape' => 'esc', 'esc' => 'esc'];
		$lower = strtolower(trim($key));

		return $map[$lower] ?? $lower;
	}

	/**
	 * A pane's recent output. `$lines = 0` means "no limit", matching
	 * Cmux::readScreen(); bury passes a bounded tail because a whole scrollback would
	 * match an active-turn marker from minutes ago.
	 *
	 * Returns '' rather than throwing, unlike the send verbs: bury POLLS this in a
	 * loop while waiting for a modal or a prompt, and a transient read failure must
	 * cost one iteration, not the whole bury.
	 */
	/**
	 * A BOUNDED read must ask herdr for `visible`, not `recent`.
	 *
	 * `recent` is history-oriented: `pane read --source recent --lines 6` returns
	 * ZERO BYTES on a pane whose scrollback is shorter than the viewport, and only
	 * starts answering somewhere above 12 lines. Every caller of a bounded read wants
	 * "the last N lines on screen" — which is what `Cmux::readScreen --lines N` gives
	 * and what `visible` gives — and the callers are the bury gates:
	 *
	 *   - GATE 1 scrapes the Claude REPL statusline for a cwd. An empty screen reads
	 *     as "no statusline", so every herdr bury was refused (dotfiles-6xo).
	 *   - isBusy() looks for an active-turn marker in the SAME string. An empty screen
	 *     has no marker, so on any path that bypasses gate 1 the busy check would fail
	 *     OPEN and tear down a session mid-turn.
	 *
	 * Unit tests cannot catch this: a stubbed HERDR_BIN returns its canned fixture
	 * whatever --source and --lines say. It took a real bury against a real herdr agent.
	 */
	public function readScreen(string $surfaceRef, string $workspaceRef, int $lines = 0): string {
		try {
			return $lines > 0
				? $this->herdr->paneRead($surfaceRef, 'visible', $lines)
				: $this->herdr->paneRead($surfaceRef, 'recent', null);
		} catch (RuntimeException $e) {
			return '';
		}
	}

	# =========================================================================
	# Create.
	# =========================================================================

	/**
	 * `$windowRef` is accepted and ignored: herdr's `workspace create` takes only
	 * label/cwd/env/focus (verified against `herdr api schema --json`'s
	 * WorkspaceCreateParams), so a workspace cannot be aimed at a particular tab.
	 * Restoring into the buried workspace's original tab is therefore a cmux-only
	 * capability; under herdr the restore lands in a new workspace of its own.
	 */
	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): ?array {
		$created = $this->herdr->workspaceCreate($title, $cwd);
		if (!$created) { return null; }

		$wsId   = (string) ($created['workspace']['workspace_id'] ?? '');
		$paneId = (string) ($created['root_pane']['pane_id'] ?? '');
		if ($wsId === '' || $paneId === '') { return null; }

		// Same four keys CmuxTransport returns, because Graveyard reads them by name.
		// A herdr pane IS its surface, so the last two carry the same handle.
		return ['ref' => $wsId, 'id' => $wsId, 'firstPaneRef' => $paneId, 'firstSurfRef' => $paneId];
	}

	/**
	 * herdr has no surface-inside-a-pane, so a new terminal is necessarily a new PANE
	 * (a split). That is a real difference in kind, not just in name: where cmux would
	 * stack a restored tab behind an existing one, herdr adds a column. A restore of a
	 * multi-tab cmux pane therefore comes back as several herdr panes.
	 *
	 * Any `$type` other than terminal is refused rather than approximated — a browser
	 * surface silently restored as a shell is a member the user thinks came back and
	 * did not.
	 */
	public function newSurface(string $workspaceRef, ?string $paneRef, string $type, ?string $command): ?string {
		if ($type !== 'terminal') { return null; }

		$target = $paneRef !== null && $paneRef !== '' ? $paneRef : $this->firstPaneRef($workspaceRef);
		if ($target === null) { return null; }

		$new = $this->herdr->paneSplit($target, 'right');
		if ($new === null) { return null; }
		if ($command !== null && $command !== '') { $this->herdr->paneRun($new, $command); }

		return $new;
	}

	public function newSplit(string $workspaceRef, string $fromSurfaceRef, string $direction): ?string {
		return $this->herdr->paneSplit($fromSurfaceRef, $this->herdrDirection($direction));
	}

	/**
	 * PURE. herdr accepts only `right` and `down`. cmux's `left`/`up` are the same
	 * divider from the other side, so they fold onto their surviving axis rather than
	 * being refused — a restore that lost a pane would be worse than one whose split
	 * grew on the other side.
	 */
	public function herdrDirection(string $direction): string {
		return in_array(strtolower(trim($direction)), ['down', 'up', 'below', 'vertical'], true) ? 'down' : 'right';
	}

	/**
	 * A no-op that reports honestly rather than shelling out. A herdr pane holds one
	 * terminal, so a surface is ALWAYS the selected one in its pane and there is
	 * nothing to bring forward; true means the invariant this asks for already holds.
	 *
	 * Deliberately not implemented as a focus: herdr's CLI has no focus-this-pane-id
	 * verb at all (`herdr pane focus` moves focus DIRECTIONALLY; the API's `pane.focus`
	 * has no CLI surface), and focusing the workspace instead would yank JT's focus
	 * mid-restore — which every create call here passes `--no-focus` to avoid.
	 */
	public function selectSurface(string $workspaceRef, string $surfaceRef): bool {
		return $this->paneRefForSurface($workspaceRef, $surfaceRef) !== null;
	}

	/** A herdr pane IS its surface, so this confirms the pane is in the workspace. */
	public function paneRefForSurface(string $workspaceRef, string $surfaceRef): ?string {
		foreach ($this->panesByWorkspace($this->snapshot())[$workspaceRef] ?? [] as $pane) {
			if ((string) ($pane['pane_id'] ?? '') === $surfaceRef) { return $surfaceRef; }
		}
		return null;
	}

	/** The workspace's first pane in layout order, for a split with no explicit target. */
	protected function firstPaneRef(string $workspaceRef): ?string {
		$panes = $this->panesByWorkspace($this->snapshot())[$workspaceRef] ?? [];
		$id    = (string) ($panes[0]['pane_id'] ?? '');
		return $id !== '' ? $id : null;
	}

	# =========================================================================
	# Teardown. Both answer the ['output','error','exitCode'] command result the
	# interface documents, so a failed close reads as a failure rather than as a
	# thrown exception a mid-loop caller would die on.
	# =========================================================================

	public function closeWorkspace(string $workspaceRef): array {
		return $this->asCommandResult(fn() => $this->herdr->workspaceClose($workspaceRef));
	}

	public function closeSurface(string $surfaceRef): array {
		return $this->asCommandResult(fn() => $this->herdr->paneClose($surfaceRef));
	}

	/** Run a Herdr teardown verb, reporting herdr's own message on failure. */
	protected function asCommandResult(callable $call): array {
		try {
			$call();
		} catch (RuntimeException $e) {
			return ['output' => '', 'error' => $e->getMessage(), 'exitCode' => 1];
		}
		return ['output' => '', 'error' => '', 'exitCode' => 0];
	}

	# =========================================================================
	# Layout capture / replay — unsupported, and null is the honest answer.
	# =========================================================================

	/**
	 * Null: herdr's split geometry is real but out of this client's reach.
	 *
	 * Three separate findings, any one of which is enough, all verified against herdr
	 * 0.8.2 / protocol 20:
	 *
	 *   1. There is nowhere to replay a captured tree INTO. `workspace create` takes
	 *      only label/cwd/env/focus — no `--layout`, unlike cmux's. A captured tree
	 *      would sit in the manifest, pass Graveyard's surface-count gate, and then
	 *      fail at newWorkspaceWithLayout(); returning null skips straight to the
	 *      manual rebuild instead of announcing a replay failure first.
	 *   2. The recursive shape exists over the SOCKET API (`layout.export` /
	 *      `layout.apply`, a proper `{type:split,direction,ratio,first,second}` node)
	 *      but herdr's CLI exposes no `layout` command at all — `herdr pane layout`
	 *      only reports the flat view. Helpers\Herdr is a CLI client, so reaching it
	 *      would mean speaking JSON-RPC over herdr.sock, a different mechanism.
	 *   3. What the snapshot DOES give is flat: `layouts[].splits[]` is a list of
	 *      `{id,direction,ratio,rect}` with no parent/child link, so any nesting built
	 *      from it would be inferred from rectangle arithmetic. A guessed tree that
	 *      Graveyard then trusts is worse than no tree.
	 *
	 * Consequence for resurrect: it falls back to planLayoutRestore()'s manual
	 * rebuild, which herdr supports exactly — panes and their order come back via
	 * `pane split`; divider ratios and nested orientation do not.
	 */
	public function captureLayoutTree(string $workspaceRef): ?array {
		return null;
	}

	/** Null for the same reason captureLayoutTree() is: herdr cannot create from a layout. */
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array {
		return null;
	}

	/**
	 * PURE. Identity — there is no herdr layout tree to sanitize. Kept honest rather
	 * than throwing: Graveyard sanitizes any tree a manifest carries before storing
	 * it, including one captured under cmux and re-read while herdr is the transport.
	 */
	public function sanitizeLayoutTree(array $node, array $dropSurfaceKeys = ['command']): array {
		return $node;
	}

	/**
	 * PURE. 0 — nothing herdr captured, so nothing to count. Graveyard gates its
	 * geometry-restore path on this matching the manifest's surface count, and 0 never
	 * matches a workspace with members, so the gate closes and the manual rebuild runs.
	 */
	public function layoutTreeSurfaceCount(array $node): int {
		return 0;
	}

	# =========================================================================
	# Snapshot access.
	# =========================================================================

	/**
	 * The one snapshot every read here is answered from.
	 *
	 * Not memoised, deliberately. bury reads liveSessions(), then drives a REPL for
	 * several seconds, then re-reads the workspace's shape to decide what to close —
	 * and a cached snapshot would answer that second question with the world as it was
	 * before the /export, which is how a pane that has since exited gets closed as if
	 * it were still an agent's.
	 *
	 * An unreachable herdr propagates its RuntimeException rather than degrading to an
	 * empty world, exactly as Cmux::tree() does. "herdr is not running" must not be
	 * reported to JT as "there is nothing live to bury"; bin/graveyard's entry seam
	 * turns the throw into an exitErr (dotfiles-3qa).
	 */
	protected function snapshot(): array {
		return $this->herdr->snapshot();
	}
}
