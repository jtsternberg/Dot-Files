<?php
namespace JT\Tests\CmuxBak;

use JT\CmuxBak;
use JT\Tests\CmuxBak\Doubles\PromptHelpers;
use JT\Tests\CmuxBak\Doubles\RestoreCmux;
use JT\Tests\TestCase;

/**
 * Split geometry: cmux-bak records each workspace's real split tree and restores
 * through it.
 *
 * `cmux tree` — everything the backup used to read — reports panes as a flat list
 * with no orientation, divider ratio or nesting, so a restored two-pane workspace
 * came back as a right-split at cmux's default ratio no matter how it had really
 * been arranged. `cmux layout get` is the only faithful source, so the backup now
 * carries that tree alongside the flat panes[] and restore replays it.
 */
final class CmuxBakLayoutGeometryTest extends TestCase {

	/** @var string[] */
	private array $temporaryFiles = [];

	private PromptHelpers $prompts;

	protected function setUp(): void {
		parent::setUp();
		$this->prompts = new PromptHelpers();
		$this->prompts->setArgs( [ 'cmux-bak', 'restore' ] );
	}

	protected function tearDown(): void {
		foreach ( $this->temporaryFiles as $file ) {
			@unlink( $file );
		}

		parent::tearDown();
	}

	// ── Backup: record the geometry ────────────────────────────────────────────

	public function testBackupRecordsEachWorkspacesSplitTreeAsSchemaVersionThree(): void {
		$file = $this->temporaryFile( 'backup-geometry' );
		$cmux = new RestoreCmux( $this->prompts );
		$cmux->treeData    = [ 'windows' => [ [ 'workspaces' => [ $this->liveWorkspace() ] ] ] ];
		$cmux->layoutTrees = [ 'workspace:1' => $this->capturedTree() ];
		$bak = new CmuxBak( $this->prompts, $file, false, false, $cmux );

		ob_start();
		$code = $bak->backup();
		ob_end_clean();

		$data = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( 0, $code );
		$this->assertSame( 3, $data['version'] );
		$this->assertSame( [ 'workspace:1' ], $cmux->layoutCaptures );

		$layout = $data['workspaces'][0]['layout_tree'];
		$this->assertSame( 'horizontal', $layout['direction'] );
		$this->assertSame( 0.35, $layout['split'] );
		$this->assertSame( 'vertical', $layout['children'][1]['direction'] );
		$this->assertSame( 0.7, $layout['children'][1]['split'] );

		// The flat panes[] data restore matches agent sessions against is untouched.
		$this->assertCount( 3, $data['workspaces'][0]['panes'] );
	}

	/**
	 * Stored geometry is geometry ONLY. cmux records the command each surface was
	 * launched with, and a replay would re-run it — resurrecting a workspace would
	 * launch the agent a second time on top of the resume cmux-bak sends itself. cwds
	 * go the same way: restore applies every recorded cwd through its own is_dir guard,
	 * so a directory deleted since the backup can never reach cmux.
	 */
	public function testBackupStoresGeometryWithoutCommandsOrCwdsToReplay(): void {
		$file = $this->temporaryFile( 'backup-sanitized' );
		$cmux = new RestoreCmux( $this->prompts );
		$cmux->treeData    = [ 'windows' => [ [ 'workspaces' => [ $this->liveWorkspace() ] ] ] ];
		$cmux->layoutTrees = [ 'workspace:1' => $this->capturedTree() ];
		$bak = new CmuxBak( $this->prompts, $file, false, false, $cmux );

		ob_start();
		$bak->backup();
		ob_end_clean();

		$data  = json_decode( (string) file_get_contents( $file ), true );
		$panes = $this->cmux->layoutTreePanes( $data['workspaces'][0]['layout_tree'] );

		$this->assertSame( [ 1, 2, 1 ], array_map( 'count', $panes ) );
		foreach ( $panes as $surfaces ) {
			foreach ( $surfaces as $surface ) {
				$this->assertArrayNotHasKey( 'command', $surface );
				$this->assertArrayNotHasKey( 'cwd', $surface );
				$this->assertArrayHasKey( 'type', $surface );
			}
		}
		// A browser tab's url IS geometry — without it the tab comes back blank.
		$this->assertSame( 'https://example.test/', $panes[1][1]['url'] );
	}

	/** cmux without a usable layout API leaves the backup exactly as it was before. */
	public function testBackupOmitsTheLayoutKeyWhenCaptureFails(): void {
		$file = $this->temporaryFile( 'backup-no-geometry' );
		$cmux = new RestoreCmux( $this->prompts );
		$cmux->treeData = [ 'windows' => [ [ 'workspaces' => [ $this->liveWorkspace() ] ] ] ];
		$bak = new CmuxBak( $this->prompts, $file, false, false, $cmux );

		ob_start();
		$code = $bak->backup();
		ob_end_clean();

		$data = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( 0, $code );
		$this->assertArrayNotHasKey( 'layout_tree', $data['workspaces'][0] );
		$this->assertCount( 3, $data['workspaces'][0]['panes'] );
	}

	// ── The correlation gate ──────────────────────────────────────────────────

	/**
	 * The layout tree and the flat panes[] are two views of one workspace, joined
	 * positionally (cmux's depth-first leaf order matches `tree`'s pane order). That
	 * join is only trustworthy while both views describe the same shape — cmux silently
	 * drops surface types it cannot express in a layout — so restore checks pane count,
	 * each pane's tab count and each tab's type before trusting it.
	 */
	public function testGeometryIsOnlyTrustedWhenItMatchesTheRecordedPanes(): void {
		$bak      = new CmuxBak( $this->prompts, $this->temporaryFile( 'gate' ), false, false, new RestoreCmux( $this->prompts ) );
		$bakPanes = $this->bakPanes();

		$this->assertTrue( $this->fits( $bak, $this->capturedTree(), $bakPanes ) );
		$this->assertFalse( $this->fits( $bak, null, $bakPanes ), 'No recorded geometry at all.' );
		$this->assertFalse( $this->fits( $bak, [], $bakPanes ), 'An empty tree describes no panes.' );

		// One pane short (cmux dropped a pane it could not express).
		$onePane = [ 'pane' => [ 'surfaces' => [ [ 'type' => 'terminal' ] ] ] ];
		$this->assertFalse( $this->fits( $bak, $onePane, $bakPanes ) );

		// Right pane count, wrong tab count in the second one.
		$shortTabs = $this->capturedTree();
		array_pop( $shortTabs['children'][1]['children'][0]['pane']['surfaces'] );
		$this->assertFalse( $this->fits( $bak, $shortTabs, $bakPanes ) );

		// Right shape, but a tab is a different kind of surface than was recorded.
		$wrongType = $this->capturedTree();
		$wrongType['children'][1]['children'][0]['pane']['surfaces'][1]['type'] = 'terminal';
		$this->assertFalse( $this->fits( $bak, $wrongType, $bakPanes ) );
	}

	// ── Restore: replay the geometry ──────────────────────────────────────────

	public function testRecreatedWorkspaceReplaysTheRecordedGeometryInsteadOfRightSplits(): void {
		$cwd  = $this->graveyardRoot;
		$cmux = $this->cmuxForRestore();
		$cmux->layoutWorkspaceNode = $this->replayedWorkspaceNode();
		$bak = $this->bak( $this->backupWithGeometry( $this->capturedTree(), $cwd ), $cmux );

		ob_start();
		$code = $bak->restore();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $cmux->layoutWorkspaces );
		[ $title, $layoutCwd, $tree ] = $cmux->layoutWorkspaces[0];
		$this->assertSame( 'geometry', $title );
		$this->assertNull( $layoutCwd, 'Each surface gets its own recorded cwd, not one base for all of them.' );
		$this->assertSame( 0.35, $tree['split'] );

		// Geometry came back whole, so nothing is split or tabbed by hand.
		$this->assertSame( [], $cmux->newWorkspaces );
		$this->assertSame( [], $cmux->newPanes );
		$this->assertSame( [], $cmux->createdSurfaces );

		// Each backed-up surface resumes in the tab the layout rebuilt for it.
		$this->assertSame(
			[
				[ 'surface:2', 'workspace:2', "resume claude sess-left\n" ],
				[ 'surface:3', 'workspace:2', "resume claude sess-right-1\n" ],
				[ 'surface:5', 'workspace:2', "resume claude sess-bottom\n" ],
			],
			$this->resumes( $cmux )
		);
	}

	public function testGeometryThatNoLongerMatchesThePanesFallsBackToRightSplits(): void {
		$cwd  = $this->graveyardRoot;
		$cmux = $this->cmuxForRestore();
		$bak  = $this->bak(
			$this->backupWithGeometry( [ 'pane' => [ 'surfaces' => [ [ 'type' => 'terminal' ] ] ] ], $cwd ),
			$cmux
		);

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $cmux->layoutWorkspaces, 'A layout that cannot be trusted must not be replayed.' );
		$this->assertSame( [ [ 'geometry', $cwd ] ], $cmux->newWorkspaces );
		$this->assertSame( [ [ 'workspace:new', 'right' ], [ 'workspace:new', 'right' ] ], $cmux->newPanes );
		$this->assertStringContainsString( 'recorded split geometry', $output );
	}

	public function testFallsBackToRightSplitsWhenCmuxCannotReplayTheLayout(): void {
		$cwd  = $this->graveyardRoot;
		$cmux = $this->cmuxForRestore();
		$cmux->layoutWorkspaceNode = null;
		$bak = $this->bak( $this->backupWithGeometry( $this->capturedTree(), $cwd ), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $cmux->layoutWorkspaces );
		$this->assertSame( [ [ 'geometry', $cwd ] ], $cmux->newWorkspaces, 'The workspace still gets restored.' );
		$this->assertSame( [ [ 'workspace:new', 'right' ], [ 'workspace:new', 'right' ] ], $cmux->newPanes );
		$this->assertStringContainsString( 'could not replay', $output );
		$this->assertCount( 3, $this->resumes( $cmux ) );
	}

	/**
	 * cmux can build fewer tabs than the layout asked for. The surfaces that DID come
	 * back still take their sessions; the rest are opened as tabs in the right pane
	 * rather than being dropped or dumped into pane 1.
	 */
	public function testMissingReplayedTabsAreOpenedInTheirOwnPane(): void {
		$cwd  = $this->graveyardRoot;
		$cmux = $this->cmuxForRestore();
		$node = $this->replayedWorkspaceNode();
		array_pop( $node['panes'][1]['surfaces'] );
		$cmux->layoutWorkspaceNode = $node;
		$bak = $this->bak( $this->backupWithGeometry( $this->capturedTree(), $cwd ), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [ [ 'workspace:2', 'pane:3', 'browser', 'https://example.test/' ] ], $cmux->createdSurfaces );
		$this->assertSame( [], $cmux->newWorkspaces, 'Never a second workspace for the same backup entry.' );
		$this->assertStringContainsString( 'fewer surfaces', $output );
	}

	public function testAVersionTwoBackupNeverAttemptsALayoutReplay(): void {
		$cwd  = $this->graveyardRoot;
		$cmux = $this->cmuxForRestore();
		$bak  = $this->bak( $this->backupWithGeometry( null, $cwd ), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $cmux->layoutWorkspaces );
		$this->assertSame( [ [ 'geometry', $cwd ] ], $cmux->newWorkspaces );
		$this->assertSame( [ [ 'workspace:new', 'right' ], [ 'workspace:new', 'right' ] ], $cmux->newPanes );
		$this->assertStringNotContainsString( 'recorded split geometry', $output );
	}

	// ── Geometry does not excuse the other restore guards ─────────────────────

	public function testHuskWorkspaceIsStillOfferedBeforeAnyGeometryIsReplayed(): void {
		$cmux = $this->cmuxForRestore();
		$cmux->layoutWorkspaceNode = $this->replayedWorkspaceNode();
		$bak = $this->bak( $this->huskBackupWithGeometry(), $cmux );

		$this->prompts->answers = [ '' ];

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $cmux->layoutWorkspaces );
		$this->assertSame( [], $cmux->newWorkspaces );
		$this->assertStringContainsString( 'no agent sessions', $output );
		$this->assertNotEmpty( $this->prompts->asked );
	}

	public function testStaleCwdIsStillGuardedOnTheGeometryPath(): void {
		$gone = $this->graveyardRoot . '/deleted-since-the-backup';
		$cmux = $this->cmuxForRestore();
		$cmux->layoutWorkspaceNode = $this->replayedWorkspaceNode();
		$bak = $this->bak( $this->backupWithGeometry( $this->capturedTree(), $gone ), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'no longer exists', $output );
		$this->assertSame( [], array_filter( $cmux->sent, fn( array $s ) => str_starts_with( $s[2], 'cd ' ) ) );
		$this->assertCount( 3, $this->resumes( $cmux ), 'The resumes still run from wherever the shell spawned.' );
	}

	public function testDryRunReportsTheRecordedGeometryAndChangesNothing(): void {
		$cwd  = $this->graveyardRoot;
		$cmux = $this->cmuxForRestore();
		$bak  = new CmuxBak( $this->prompts, $this->backupWithGeometry( $this->capturedTree(), $cwd ), true, false, $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'recorded split geometry', $output );
		$this->assertSame( [], $cmux->layoutWorkspaces );
		$this->assertSame( [], $cmux->newWorkspaces );
		$this->assertSame( [], $cmux->sent );
	}

	// ── Fixtures ──────────────────────────────────────────────────────────────

	/** Invoke CmuxBak's protected geometry gate. */
	private function fits( CmuxBak $bak, ?array $tree, array $bakPanes ): bool {
		$method = new \ReflectionMethod( CmuxBak::class, 'layoutTreeFitsBakPanes' );
		$method->setAccessible( true );

		return (bool) $method->invoke( $bak, $tree, $bakPanes );
	}

	/** @return array<int, array{0:string,1:string,2:string}> the resume keystrokes only */
	private function resumes( RestoreCmux $cmux ): array {
		return array_values( array_filter( $cmux->sent, fn( array $s ) => str_starts_with( $s[2], 'resume ' ) ) );
	}

	private function bak( string $file, RestoreCmux $cmux ): CmuxBak {
		return new CmuxBak( $this->prompts, $file, false, false, $cmux );
	}

	/** A cmux with no live workspaces, so every backed-up workspace has to be recreated. */
	private function cmuxForRestore(): RestoreCmux {
		$cmux = new RestoreCmux( $this->prompts );
		$cmux->treeData = [ 'windows' => [ [ 'workspaces' => [] ] ] ];

		return $cmux;
	}

	/**
	 * What `cmux layout get` reports for the workspace below: a left column, a right
	 * column split 70/30 top to bottom, and two tabs in the top-right pane. Carries the
	 * commands and cwds cmux records, which the backup is expected to drop.
	 */
	private function capturedTree(): array {
		return [
			'direction' => 'horizontal',
			'split'     => 0.35,
			'children'  => [
				[ 'pane' => [ 'surfaces' => [
					[ 'type' => 'terminal', 'cwd' => '/left', 'command' => 'claude --resume sess-left' ],
				] ] ],
				[
					'direction' => 'vertical',
					'split'     => 0.7,
					'children'  => [
						[ 'pane' => [ 'surfaces' => [
							[ 'type' => 'terminal', 'cwd' => '/right', 'command' => 'claude --resume sess-right-1' ],
							[ 'type' => 'browser', 'cwd' => '/right', 'url' => 'https://example.test/' ],
						] ] ],
						[ 'pane' => [ 'surfaces' => [
							[ 'type' => 'terminal', 'cwd' => '/bottom' ],
						] ] ],
					],
				],
			],
		];
	}

	/** The live `cmux tree` workspace the captured tree above describes. */
	private function liveWorkspace(): array {
		return [
			'title' => 'geometry',
			'ref'   => 'workspace:1',
			'panes' => [
				[
					'ref'      => 'pane:1',
					'index'    => 0,
					'surfaces' => [
						[ 'ref' => 'surface:1', 'title' => 'Left', 'type' => 'terminal', 'index_in_pane' => 0 ],
					],
				],
				[
					'ref'      => 'pane:2',
					'index'    => 1,
					'surfaces' => [
						[ 'ref' => 'surface:2', 'title' => 'Right', 'type' => 'terminal', 'index_in_pane' => 0 ],
						[ 'ref' => 'surface:3', 'title' => 'Docs', 'type' => 'browser', 'url' => 'https://example.test/', 'index_in_pane' => 1 ],
					],
				],
				[
					'ref'      => 'pane:3',
					'index'    => 2,
					'surfaces' => [
						[ 'ref' => 'surface:4', 'title' => 'Bottom', 'type' => 'terminal', 'index_in_pane' => 0 ],
					],
				],
			],
		];
	}

	/**
	 * The workspace node cmux returns after replaying capturedTree(): three panes in
	 * depth-first order, the second holding two tabs.
	 */
	private function replayedWorkspaceNode(): array {
		return [
			'ref'   => 'workspace:2',
			'id'    => 'ws-uuid-replayed',
			'title' => 'geometry',
			'panes' => [
				[ 'ref' => 'pane:2', 'surfaces' => [ [ 'ref' => 'surface:2' ] ] ],
				[ 'ref' => 'pane:3', 'surfaces' => [ [ 'ref' => 'surface:3' ], [ 'ref' => 'surface:4' ] ] ],
				[ 'ref' => 'pane:4', 'surfaces' => [ [ 'ref' => 'surface:5' ] ] ],
			],
		];
	}

	/** The backed-up panes[] matching capturedTree(): 1 tab, then 2 tabs, then 1. */
	private function bakPanes( ?string $cwd = null ): array {
		return [
			[
				'index'    => 0,
				'surfaces' => [ $this->bakSurface( 'Left', 'sess-left', $cwd ) ],
			],
			[
				'index'    => 1,
				'surfaces' => [
					$this->bakSurface( 'Right one', 'sess-right-1', $cwd ),
					[
						'title' => 'Docs',
						'type'  => 'browser',
						'url'   => 'https://example.test/',
						'cwd'   => $cwd,
					],
				],
			],
			[
				'index'    => 2,
				'surfaces' => [ $this->bakSurface( 'Bottom', 'sess-bottom', $cwd ) ],
			],
		];
	}

	private function bakSurface( string $title, string $sessionId, ?string $cwd ): array {
		return [
			'title'            => $title,
			'type'             => 'terminal',
			'cwd'              => $cwd,
			'agent'            => 'claude',
			'agent_session_id' => $sessionId,
		];
	}

	private function backupWithGeometry( ?array $layoutTree, ?string $cwd ): string {
		$workspace = [
			'title' => 'geometry',
			'panes' => $this->bakPanes( $cwd ),
		];
		if ( $layoutTree !== null ) {
			$workspace['layout_tree'] = $layoutTree;
		}

		return $this->writeBackup( [ $workspace ], $layoutTree === null ? 2 : 3 );
	}

	/** A geometry-carrying workspace with no agent session anywhere in it. */
	private function huskBackupWithGeometry(): string {
		return $this->writeBackup( [
			[
				'title'       => 'husk-workspace',
				'layout_tree' => [ 'pane' => [ 'surfaces' => [ [ 'type' => 'terminal' ] ] ] ],
				'panes'       => [
					[ 'surfaces' => [ [ 'title' => 'shell', 'type' => 'terminal', 'cwd' => null, 'agent' => null ] ] ],
				],
			],
		], 3 );
	}

	private function writeBackup( array $workspaces, int $version ): string {
		$file = $this->temporaryFile( 'geometry-' . $version );
		file_put_contents(
			$file,
			json_encode(
				[
					'version'    => $version,
					'timestamp'  => '2026-08-07T12:00:00Z',
					'workspaces' => $workspaces,
				],
				JSON_PRETTY_PRINT
			)
		);

		return $file;
	}

	private function temporaryFile( string $label ): string {
		$file = sys_get_temp_dir() . '/cmux-bak-' . $label . '-' . uniqid() . '.json';
		$this->temporaryFiles[] = $file;

		return $file;
	}
}
