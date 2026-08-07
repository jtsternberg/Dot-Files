<?php
namespace JT\Tests\CmuxBak;

use JT\CmuxBak;
use JT\Tests\CmuxBak\Doubles\PromptHelpers;
use JT\Tests\CmuxBak\Doubles\RestoreCmux;
use JT\Tests\TestCase;

/**
 * Restore's *shape* concerns, all three born from one real post-restart restore:
 * husk workspaces recreated as empty shells, a `cd` into a directory that no
 * longer existed, and a two-pane workspace flattened into one pane of tabs.
 */
final class CmuxBakRestoreShapeTest extends TestCase {

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

	// ── 1. Husk workspaces ────────────────────────────────────────────────────

	public function testMissingWorkspaceWithoutAgentSessionsPromptsAndSkipsByDefault(): void {
		$cmux = $this->cmuxWithLiveWorkspaces( [] );
		$bak  = $this->bak( $this->huskBackup(), $cmux );

		$this->prompts->answers = [ '' ];

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $cmux->newWorkspaces, 'A husk workspace must not be created without an answer.' );
		$this->assertStringContainsString( 'no agent sessions', $output );
		$this->assertStringContainsString( 'Skipped', $output );
		$this->assertNotEmpty( $this->prompts->asked );
	}

	public function testMissingWorkspaceWithoutAgentSessionsIsCreatedWhenConfirmed(): void {
		$cmux = $this->cmuxWithLiveWorkspaces( [] );
		$bak  = $this->bak( $this->huskBackup(), $cmux );

		$this->prompts->answers = [ 'c' ];

		ob_start();
		$code = $bak->restore();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [ [ 'husk-workspace', null ] ], $cmux->newWorkspaces );
	}

	public function testMissingWorkspaceWithAnAgentSessionIsCreatedWithoutPrompting(): void {
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithLiveWorkspaces( [] );
		$bak  = $this->bak(
			$this->backup( [
				[
					'title' => 'real-workspace',
					'panes' => [
						[
							'surfaces' => [
								[
									'title'            => 'Agent',
									'type'             => 'terminal',
									'cwd'              => $cwd,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-1',
								],
							],
						],
					],
				],
			] ),
			$cmux
		);

		ob_start();
		$code = $bak->restore();
		ob_end_clean();

		$this->assertSame( [], $this->prompts->asked, 'A workspace with an agent session must not prompt.' );
		$this->assertSame( [ [ 'real-workspace', $cwd ] ], $cmux->newWorkspaces );
		$this->assertSame( 0, $code );
	}

	public function testAutoconfirmCreatesHuskWorkspacesWithoutPrompting(): void {
		$this->prompts->setArgs( [ 'cmux-bak', 'restore', '--yes' ] );
		$cmux = $this->cmuxWithLiveWorkspaces( [] );
		$bak  = $this->bak( $this->huskBackup(), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $this->prompts->asked, '--yes pre-answers the husk prompt.' );
		$this->assertSame( [ [ 'husk-workspace', null ] ], $cmux->newWorkspaces );
		$this->assertStringContainsString( '--yes', $output );
	}

	public function testSilentModeSkipsHuskWorkspacesWithoutPrompting(): void {
		$this->prompts->forceSilent = true;
		$cmux = $this->cmuxWithLiveWorkspaces( [] );
		$bak  = $this->bak( $this->huskBackup(), $cmux );

		ob_start();
		$bak->restore();
		ob_end_clean();

		$this->assertSame( [], $this->prompts->asked );
		$this->assertSame( [], $cmux->newWorkspaces );
	}

	// ── 2. Stale cwd ──────────────────────────────────────────────────────────

	public function testStaleCwdIsWarnedAboutAndNotCdIntoWhileTheResumeStillHappens(): void {
		$gone = sys_get_temp_dir() . '/cmux-bak-gone-' . uniqid();
		$cmux = $this->cmuxWithLiveWorkspaces( [
			[
				'title' => 'dotfiles',
				'ref'   => 'workspace:1',
				'panes' => [
					[
						'ref'      => 'pane:1',
						'surfaces' => [
							[
								'title' => 'Agent',
								'ref'   => 'surface:1',
								'type'  => 'terminal',
							],
						],
					],
				],
			],
		] );
		$bak = $this->bak(
			$this->backup( [
				[
					'title' => 'dotfiles',
					'panes' => [
						[
							'surfaces' => [
								[
									'title'            => 'Agent',
									'type'             => 'terminal',
									'cwd'              => $gone,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-1',
								],
							],
						],
					],
				],
			] ),
			$cmux
		);

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( $gone, $output );
		$this->assertStringContainsString( 'no longer exists', $output );
		$this->assertSame(
			[ [ 'surface:1', 'workspace:1', "resume claude sess-1\n" ] ],
			$cmux->sent,
			'The stale cd must be dropped while the resume still runs.'
		);
	}

	public function testExistingCwdIsStillCdIntoBeforeTheResume(): void {
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithLiveWorkspaces( [
			[
				'title' => 'dotfiles',
				'ref'   => 'workspace:1',
				'panes' => [
					[
						'ref'      => 'pane:1',
						'surfaces' => [
							[
								'title' => 'Agent',
								'ref'   => 'surface:1',
								'type'  => 'terminal',
							],
						],
					],
				],
			],
		] );
		$bak = $this->bak(
			$this->backup( [
				[
					'title' => 'dotfiles',
					'panes' => [
						[
							'surfaces' => [
								[
									'title'            => 'Agent',
									'type'             => 'terminal',
									'cwd'              => $cwd,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-1',
								],
							],
						],
					],
				],
			] ),
			$cmux
		);

		ob_start();
		$bak->restore();
		ob_end_clean();

		$this->assertSame(
			[
				[ 'surface:1', 'workspace:1', "cd {$cwd}\n" ],
				[ 'surface:1', 'workspace:1', "resume claude sess-1\n" ],
			],
			$cmux->sent
		);
	}

	public function testWorkspaceCreationIgnoresAStaleFirstCwd(): void {
		$gone = sys_get_temp_dir() . '/cmux-bak-gone-' . uniqid();
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithLiveWorkspaces( [] );
		$bak  = $this->bak(
			$this->backup( [
				[
					'title' => 'fresh',
					'panes' => [
						[
							'surfaces' => [
								[
									'title'            => 'Stale',
									'type'             => 'terminal',
									'cwd'              => $gone,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-1',
								],
								[
									'title'            => 'Good',
									'type'             => 'terminal',
									'cwd'              => $cwd,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-2',
								],
							],
						],
					],
				],
			] ),
			$cmux
		);

		ob_start();
		$bak->restore();
		ob_end_clean();

		$this->assertSame( [ [ 'fresh', $cwd ] ], $cmux->newWorkspaces );
	}

	// ── 3. Pane structure ─────────────────────────────────────────────────────

	public function testRecreatedWorkspaceRebuildsTheRecordedPaneCountAndPlacement(): void {
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithLiveWorkspaces( [] );
		$bak  = $this->bak(
			$this->backup( [
				[
					'title' => 'two-panes',
					'panes' => [
						[
							'index'    => 0,
							'surfaces' => [
								[
									'title'            => 'Left',
									'type'             => 'terminal',
									'cwd'              => $cwd,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-left',
								],
							],
						],
						[
							'index'    => 1,
							'surfaces' => [
								[
									'title'            => 'Right one',
									'type'             => 'terminal',
									'cwd'              => $cwd,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-right-1',
								],
								[
									'title'            => 'Right two',
									'type'             => 'terminal',
									'cwd'              => $cwd,
									'agent'            => 'claude',
									'agent_session_id' => 'sess-right-2',
								],
							],
						],
					],
				],
			] ),
			$cmux
		);

		ob_start();
		$bak->restore();
		ob_end_clean();

		$this->assertSame(
			[ [ 'workspace:new', 'right' ] ],
			$cmux->newPanes,
			'A second recorded pane must be created as a right-split.'
		);

		// The extra tab belongs to the SECOND pane, not the first.
		$this->assertSame(
			[ [ 'workspace:new', 'pane:new:1', 'terminal', null ] ],
			$cmux->createdSurfaces
		);

		$resumed = array_values( array_filter(
			$cmux->sent,
			fn( array $s ) => str_starts_with( $s[2], 'resume ' )
		) );
		$this->assertSame(
			[
				[ 'surface:new:0:0', 'workspace:new', "resume claude sess-left\n" ],
				[ 'surface:new:1:0', 'workspace:new', "resume claude sess-right-1\n" ],
				[ 'surface:extra:1', 'workspace:new', "resume claude sess-right-2\n" ],
			],
			$resumed
		);
	}

	// ── 4. The surface-not-found decision ─────────────────────────────────────

	public function testAutoconfirmOpensANewSurfaceAndResumesTheSessionWithoutPrompting(): void {
		$this->prompts->setArgs( [ 'cmux-bak', 'restore', '--yes' ] );
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithRetitledSurface();
		$bak  = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $this->prompts->asked, '--yes pre-answers the surface prompt.' );
		$this->assertSame(
			[ [ 'workspace:1', 'pane:1', 'terminal', null ] ],
			$cmux->createdSurfaces,
			'--yes must open a new surface in the workspace.'
		);
		$this->assertSame(
			[
				[ 'surface:extra:1', 'workspace:1', "cd {$cwd}\n" ],
				[ 'surface:extra:1', 'workspace:1', "resume claude sess-1\n" ],
			],
			$cmux->sent
		);
		$this->assertStringContainsString( '--yes', $output );
	}

	public function testAutoconfirmSkipsASessionAlreadyRunningInAnotherWorkspace(): void {
		$this->prompts->setArgs( [ 'cmux-bak', 'restore', '--yes' ] );
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithRetitledSurface();
		$cmux->joinRows = [
			[
				'session_id'    => 'sess-1',
				'agent'         => 'claude',
				'surface_ref'   => 'surface:99',
				'workspace_ref' => 'workspace:9',
				'cwd'           => $cwd,
				'model'         => null,
				'skip_perms'    => false,
				'opts'          => [],
				'reason'        => '',
			],
		];
		$bak = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $cmux->createdSurfaces, 'A session live elsewhere must not be resumed a second time.' );
		$this->assertSame( [], $cmux->sent );
		$this->assertStringContainsString( 'workspace:9', $output );
		$this->assertStringContainsString( 'surface:99', $output );
	}

	public function testSilentModeSkipsTheSurfacePromptWithoutAsking(): void {
		$this->prompts->forceSilent = true;
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithRetitledSurface();
		$bak  = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$code = $bak->restore();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $this->prompts->asked );
		$this->assertSame( [], $cmux->createdSurfaces );
		$this->assertSame( [], $cmux->sent );
	}

	public function testNonTtyStdinSkipsTheSurfacePromptAndWarnsOnStderr(): void {
		$this->prompts->forceInteractive = false;
		$stderr = fopen( 'php://memory', 'w+' );
		$this->prompts->setStreams( null, $stderr );

		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithRetitledSurface();
		$bak  = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$code = $bak->restore();
		ob_end_clean();

		rewind( $stderr );
		$warned = (string) stream_get_contents( $stderr );
		fclose( $stderr );

		$this->assertSame( 0, $code );
		$this->assertSame( [], $this->prompts->asked, 'A non-interactive stdin must never reach the prompt.' );
		$this->assertSame( [], $cmux->createdSurfaces );
		$this->assertSame( [], $cmux->sent );
		$this->assertStringContainsString( 'not a tty', $warned );
		$this->assertStringContainsString( 'sess-1', $warned );
	}

	public function testInteractiveStdinStillPromptsAndCanOpenTheNewSurface(): void {
		$this->prompts->forceInteractive = true;
		$this->prompts->answers          = [ 'n' ];

		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithRetitledSurface();
		$bak  = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$bak->restore();
		ob_end_clean();

		$this->assertNotEmpty( $this->prompts->asked, 'An interactive run still gets the [n/s] prompt.' );
		$this->assertSame( [ [ 'workspace:1', 'pane:1', 'terminal', null ] ], $cmux->createdSurfaces );
		$this->assertSame(
			[ 'surface:extra:1', 'workspace:1', "resume claude sess-1\n" ],
			end( $cmux->sent )
		);
	}

	public function testSkippedSessionsAreSummarizedWithAPasteableResumeCommand(): void {
		$this->prompts->forceInteractive = true;
		$this->prompts->answers          = [ 's' ];

		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithRetitledSurface();
		$bak  = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( [], $cmux->sent );
		$this->assertStringContainsString( 'Skipped 1 agent session', $output );
		$this->assertStringContainsString( "cd {$cwd} && resume claude sess-1", $output );
	}

	public function testSkippedSessionSummaryIsShownForAutoconfirmedSkipsToo(): void {
		$this->prompts->setArgs( [ 'cmux-bak', 'restore', '--yes' ] );
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithRetitledSurface();
		$cmux->joinRows = [
			[
				'session_id'    => 'sess-1',
				'agent'         => 'claude',
				'surface_ref'   => 'surface:99',
				'workspace_ref' => 'workspace:9',
				'cwd'           => $cwd,
				'model'         => null,
				'skip_perms'    => false,
				'opts'          => [],
				'reason'        => '',
			],
		];
		$bak = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$bak->restore();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Skipped 1 agent session', $output );
		$this->assertStringContainsString( "cd {$cwd} && resume claude sess-1", $output );
	}

	public function testNoSkipsMeansNoSummary(): void {
		$cwd  = $this->existingDir();
		$cmux = $this->cmuxWithLiveWorkspaces( [
			[
				'title' => 'dotfiles',
				'ref'   => 'workspace:1',
				'panes' => [
					[
						'ref'      => 'pane:1',
						'surfaces' => [
							[
								'title' => 'Agent',
								'ref'   => 'surface:1',
								'type'  => 'terminal',
							],
						],
					],
				],
			],
		] );
		$bak = $this->bak( $this->backupWithMissingSurface( $cwd ), $cmux );

		ob_start();
		$bak->restore();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Skipped', $output );
	}

	// ── Fixtures ──────────────────────────────────────────────────────────────

	/** A live workspace whose only surface no longer carries the backed-up title. */
	private function cmuxWithRetitledSurface(): RestoreCmux {
		return $this->cmuxWithLiveWorkspaces( [
			[
				'title' => 'dotfiles',
				'ref'   => 'workspace:1',
				'panes' => [
					[
						'ref'      => 'pane:1',
						'surfaces' => [
							[
								'title' => 'Some other title',
								'ref'   => 'surface:1',
								'type'  => 'terminal',
							],
						],
					],
				],
			],
		] );
	}

	/** A backup of workspace 'dotfiles' holding one surface titled 'Agent'. */
	private function backupWithMissingSurface( string $cwd ): string {
		return $this->backup( [
			[
				'title' => 'dotfiles',
				'panes' => [
					[
						'surfaces' => [
							[
								'title'            => 'Agent',
								'type'             => 'terminal',
								'cwd'              => $cwd,
								'agent'            => 'claude',
								'agent_session_id' => 'sess-1',
							],
						],
					],
				],
			],
		] );
	}

	private function bak( string $file, RestoreCmux $cmux ): CmuxBak {
		return new CmuxBak( $this->prompts, $file, false, false, $cmux );
	}

	private function cmuxWithLiveWorkspaces( array $workspaces ): RestoreCmux {
		$cmux = new RestoreCmux( $this->prompts );
		$cmux->treeData = [ 'windows' => [ [ 'workspaces' => $workspaces ] ] ];

		return $cmux;
	}

	/** A backup file whose only workspace is an agent-less husk. */
	private function huskBackup(): string {
		return $this->backup( [
			[
				'title' => 'husk-workspace',
				'panes' => [
					[
						'surfaces' => [
							[
								'title' => 'JT@JT-MBP14:~/Sites/lindris-monorepo',
								'type'  => 'terminal',
								'cwd'   => null,
								'agent' => null,
							],
						],
					],
				],
			],
		] );
	}

	private function backup( array $workspaces ): string {
		$file = sys_get_temp_dir() . '/cmux-bak-shape-' . uniqid() . '.json';
		$this->temporaryFiles[] = $file;
		file_put_contents(
			$file,
			json_encode(
				[
					'version'    => 2,
					'timestamp'  => '2026-08-06T12:00:00Z',
					'workspaces' => $workspaces,
				],
				JSON_PRETTY_PRINT
			)
		);

		return $file;
	}

	/** A directory that really exists, so is_dir() checks see the real thing. */
	private function existingDir(): string {
		return $this->graveyardRoot;
	}
}
