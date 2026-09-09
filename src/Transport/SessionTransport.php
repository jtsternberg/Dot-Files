<?php
namespace JT\Transport;

/**
 * One live-session transport (a terminal multiplexer graveyard can drive).
 *
 * liveSessions() is the contract: its row shape is what every graveyard verb
 * consumes, and it is already transport-neutral. `surface_ref`/`workspace_ref`
 * are opaque handles — cmux positional refs, herdr `wF:p3` pane ids — and no
 * caller may parse them. Each row carries `transport` so a caller holding a row
 * knows which implementation to send it back to.
 *
 * Nothing cmux-shaped belongs here. A method that takes or returns a cmux `tree`
 * node, a debug-terminals dump, or a surface-UUID map would force every other
 * transport to fake cmux's data model, which is the leak this seam exists to
 * prevent. Those live inside CmuxTransport.
 */
interface SessionTransport
{
	/** Stable short name: 'cmux' | 'herdr' | 'null'. Stamped into every row. */
	public function name(): string;

	/** Is this transport running and reachable right now? Must not throw. */
	public function available(): bool;

	/**
	 * The surface/pane handle of the process CALLING graveyard, if this transport is
	 * the one hosting it. cmux answers from CMUX_SURFACE_ID, herdr from HERDR_PANE_ID.
	 * null means "not my caller" — which is how the registry identifies the host.
	 *
	 * Read from the environment, never from the multiplexer, so it stays answerable
	 * when the transport is unreachable: an agent whose server has died is still the
	 * caller, and self-protection must not lapse with it.
	 */
	public function selfSurfaceRef(): ?string;

	/**
	 * Live agent sessions this transport hosts.
	 *
	 * @return list<array{
	 *   transport:string, session_id:string, agent:string, cwd:string,
	 *   model:?string, skip_perms:bool, opts:array, pid:?int, tty:?string,
	 *   surface_ref:string, surface_id:string, workspace_ref:string,
	 *   window_ref:?string, pane_ref:?string, home_workspace_id:?string,
	 *   home_pane_id:?string, home_index_in_pane:?int, workspace_title:string,
	 *   tab_title:string, idle_seconds:int, targetable:bool, reason:?string,
	 *   no_bridge:bool
	 * }>
	 */
	public function liveSessions(): array;

	/**
	 * Every surface the transport hosts, annotated with whatever agent session is
	 * bound to it. Pass a workspace ref to scope it; null spans all workspaces
	 * (liveCodexSurfaceRefs needs the unscoped form).
	 *
	 * The raw material for bury classification: `position` is the surface's order
	 * within its pane, and `type` is where transports differ — cmux emits
	 * 'terminal'|'browser'|'markdown'|…, herdr only ever 'terminal'. Ordered as the
	 * transport lays the workspace out, because bury numbers a group's members by
	 * that order and resurrect replays it.
	 *
	 * The binding is the transport's DETERMINISTIC one. liveSessions() is the
	 * authority on membership — it adds artifact enrichment and a screen-scraping
	 * second pass on top — so bury reads liveSessions() for who to bury and these
	 * rows for how the workspace is shaped. A row with `agent` set and `session_id`
	 * null is a live agent the transport can see but has no session for yet (a
	 * zero-turn codex): still an agent surface, and closing it as a shell is
	 * data loss (dotfiles-5p5).
	 *
	 * `script` is the unique per-surface launch/resume script a transport bridges
	 * sessions through, or null when it has no such bridge (herdr launches agents
	 * directly). `cwd` is the surface's own recorded cwd — not the foreground
	 * process's, which bury re-probes through `tty` while the workspace is alive.
	 *
	 * `workspace_id`/`pane_id` are the transport's STABLE ids for a surface's home,
	 * as opposed to `workspace_ref`/`pane_ref`, which are positional handles the
	 * transport reassigns as things open and close. Resurrect matches a tombstone's
	 * recorded home against the ids, never the refs.
	 *
	 * @return list<array{position:int, pane_index:int, pane_ref:?string,
	 *   pane_id:?string, selected_in_pane:bool, surface_ref:string,
	 *   surface_id:string, workspace_ref:string, workspace_id:?string,
	 *   workspace_title:string,
	 *   window_ref:?string, type:string, title:string, url:?string, tty:?string,
	 *   cwd:?string, script:?string, session_id:?string, agent:?string, pid:?int,
	 *   targetable:bool, reason:?string}>
	 */
	public function surfaces(?string $workspaceRef = null): array;

	/**
	 * Does this window/tab handle still exist? cmux: a window ref. herdr: a tab id.
	 *
	 * Opaque either way — the caller only ever holds a handle it was handed earlier,
	 * and asks this because such handles go stale when the transport restarts.
	 */
	public function windowExists(string $windowRef): bool;

	// --- drive an existing surface (bury: /export, /status, screen probes) ---

	public function sendText(string $surfaceRef, string $workspaceRef, string $text): void;
	public function sendKey(string $surfaceRef, string $workspaceRef, string $key): void;

	/**
	 * Visible buffer of a surface. $lines = 0 means "whatever the transport shows by
	 * default".
	 *
	 * '' and null are DIFFERENT answers and every implementation must keep them apart:
	 * '' is "I read the surface and it is blank", null is "the read failed, I know
	 * nothing". Callers want opposite things from a failure — a bury poller waiting for
	 * a modal must lose one iteration (`?? ''`), while the busy check must refuse for
	 * want of evidence — so the seam reports which happened and each caller decides.
	 */
	public function readScreen(string $surfaceRef, string $workspaceRef, int $lines = 0): ?string;

	// --- describe / resolve, for confirmation prompts and fuzzy targeting ---

	public function describeWorkspace(string $handle, string $fallbackTitle = ''): string;

	/**
	 * Resolve a workspace name, ref or id to its handle + title. May throw
	 * \RuntimeException when the handle is ambiguous; callers report that verbatim.
	 */
	public function resolveWorkspace(string $nameOrRef): ?array;

	public function workspaceSurfaceCount(string $workspaceRef): int;

	// --- create, for resurrect ---

	/** Null (never an exit) when the create fails, so a mid-loop caller can carry on. */
	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): ?array;
	public function newSurface(string $workspaceRef, ?string $paneRef, string $type, ?string $command): ?string;
	public function newSplit(string $workspaceRef, string $fromSurfaceRef, string $direction): ?string;
	public function selectSurface(string $workspaceRef, string $surfaceRef): bool;
	public function paneRefForSurface(string $workspaceRef, string $surfaceRef): ?string;

	// --- teardown. Both return the ['output','error','exitCode'] command result. ---

	public function closeWorkspace(string $workspaceRef): array;
	public function closeSurface(string $surfaceRef): array;

	/**
	 * Can this transport host non-terminal surfaces (browser, markdown viewers)?
	 * cmux: true. herdr: false — panes are terminals only, so a grouped restore
	 * under herdr drops them and must confirm first.
	 */
	public function supportsNonTerminalSurfaces(): bool;

	// --- layout capture/replay for a grouped bury; null when unsupported ---

	public function captureLayoutTree(string $workspaceRef): ?array;
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array;
	public function sanitizeLayoutTree(array $node, array $dropSurfaceKeys = ['command']): array;
	public function layoutTreeSurfaceCount(array $node): int;
}
