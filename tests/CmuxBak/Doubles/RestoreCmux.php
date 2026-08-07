<?php
namespace JT\Tests\CmuxBak\Doubles;

use JT\CLI\Helpers;
use JT\Helpers\Cmux;

/**
 * The cmux seam every cmux-bak restore/backup test drives: a Cmux that records what
 * would have been done to cmux and reads its state from injected fixtures, so no test
 * reaches the real binary (and no test can create or type into a real workspace).
 *
 * Shared by every cmux-bak test file rather than re-stubbed per file: the whole point
 * of the double is that all of them agree on what "restore called cmux" looks like.
 */
class RestoreCmux extends Cmux {

	public array $treeData = [ 'windows' => [] ];

	/**
	 * Live agent session rows the session↔surface join hands back — the liveness the
	 * real join derives from `ps`/cmux, injected instead of shelled out. Row shape is
	 * the join's: session_id, agent, surface_ref, workspace_ref, cwd, model,
	 * skip_perms, opts.
	 */
	public array $joinRows = [];

	public array $sent = [];
	public array $newWorkspaces = [];
	public array $newPanes = [];
	public array $createdSurfaces = [];

	/** Ref of the workspace newWorkspaceOrNull() pretends to create. */
	public string $createdWsRef = 'workspace:new';

	/** Workspace refs captureLayoutTree() was asked about, in order. */
	public array $layoutCaptures = [];

	/** [ workspace_ref => layout tree ] captureLayoutTree() hands back. */
	public array $layoutTrees = [];

	/** [ title, cwd, layout tree ] per newWorkspaceWithLayout() call. */
	public array $layoutWorkspaces = [];

	/**
	 * Tree node newWorkspaceWithLayout() returns. Null makes the replay fail, so a test
	 * can drive the fall back to the manual pane rebuild.
	 */
	public ?array $layoutWorkspaceNode = null;

	public function __construct( Helpers $cli ) {
		parent::__construct( $cli, false );
	}

	public function ping(): bool {
		return true;
	}

	public function tree(): array {
		return $this->treeData;
	}

	public function debugTerminals(): string {
		return '';
	}

	public function parseDebugTerminals( string $raw ): array {
		return [];
	}

	public function loadClaudeSessionsByPid(): array {
		return [];
	}

	public function psProcTable(): string {
		return '';
	}

	public function parseProcTable( string $raw ): array {
		return [];
	}

	public function joinSessionsToSurfaces( array $sessions, array $proc, array $debug, array $surfaceUuids = [] ): array {
		return $this->joinRows;
	}

	public function loadCodexSessionsByPid(): array {
		return [];
	}

	public function mapSurfaceUuids( array $tree ): array {
		return [];
	}

	public function joinCodexToSurfaces( array $codexSessions, array $surfaceUuids ): array {
		return [];
	}

	public function buildAgentResumeCommand(
		string $agent,
		string $sessionId,
		bool $skipPerms = false,
		?string $model = null,
		array $opts = []
	): string {
		return "resume {$agent} {$sessionId}";
	}

	public function sendToSurface( string $surfRef, string $wsRef, string $text ): void {
		$this->sent[] = [ $surfRef, $wsRef, $text ];
	}

	public function readScreen( string $surfRef, string $wsRef ): string {
		return '';
	}

	public function newWorkspaceOrNull( string $title, ?string $cwd, ?string $windowRef = null ): ?array {
		$this->newWorkspaces[] = [ $title, $cwd ];

		return [
			'ref'          => $this->createdWsRef,
			'id'           => 'ws-uuid-new',
			'firstPaneRef' => 'pane:new:0',
			'firstSurfRef' => 'surface:new:0:0',
		];
	}

	public function newPane( string $wsRef, string $direction = 'right' ): ?array {
		$this->newPanes[] = [ $wsRef, $direction ];
		$index            = count( $this->newPanes );

		return [
			'pane_ref'    => "pane:new:{$index}",
			'surface_ref' => "surface:new:{$index}:0",
		];
	}

	public function createSurface( string $wsRef, ?string $paneRef, string $type, ?string $url ): ?string {
		$this->createdSurfaces[] = [ $wsRef, $paneRef, $type, $url ];
		$index                   = count( $this->createdSurfaces );

		return "surface:extra:{$index}";
	}

	public function captureLayoutTree( string $wsRef, string $namePrefix = 'cmux-capture' ): ?array {
		$this->layoutCaptures[] = $wsRef;

		return $this->layoutTrees[ $wsRef ] ?? null;
	}

	public function newWorkspaceWithLayout( string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null ): ?array {
		$this->layoutWorkspaces[] = [ $title, $cwd, $layoutTree ];

		return $this->layoutWorkspaceNode;
	}
}
