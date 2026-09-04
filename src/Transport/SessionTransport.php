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

	// --- drive an existing surface (bury: /export, /status, screen probes) ---

	public function sendText(string $surfaceRef, string $workspaceRef, string $text): void;
	public function sendKey(string $surfaceRef, string $workspaceRef, string $key): void;

	/** Visible buffer of a surface. $lines = 0 means "whatever the transport shows by default". */
	public function readScreen(string $surfaceRef, string $workspaceRef, int $lines = 0): string;

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
