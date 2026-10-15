<?php
namespace JT\Tests\CmuxBak;

use JT\CmuxBak;
use JT\Tests\CmuxBak\Doubles\PromptHelpers;
use JT\Tests\CmuxBak\Doubles\RestoreCmux;
use JT\Tests\TestCase;

/**
 * A session that is live anywhere in cmux is never resumed again, and a workspace
 * is found by the sessions it hosts when its title has changed. Titles are not
 * identity: Claude Code retitles its tabs and workspaces get renamed, and one real
 * restore matched on title alone — recreated a renamed workspace, ran a live
 * session a second time against the same transcript, and offered to do the same
 * for nine retitled tabs.
 */
final class CmuxBakLiveSessionTest extends TestCase {

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

	public function testInteractiveRestoreNeverOffersToResumeASessionLiveOnARetitledSurface(): void {
		$this->prompts->forceInteractive = true;
		$this->prompts->answers          = [ 'n' ];

		$cmux = $this->cmux( [
			$this->liveWs( 'dotfiles', 'workspace:1', [ [ 'pane:1', [ [ 'surface:1', 'Retitled by Claude' ] ] ] ] ),
		] );
		$cmux->joinRows = [ $this->liveRow( 'sess-a', 'workspace:1', 'surface:1' ) ];

		$bak = $this->bak( $this->backup( [ $this->bakWs( 'dotfiles', [ [ 'Original title', 'sess-a' ] ] ) ] ), $cmux );

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $this->prompts->asked, 'A live session must not reach the open-or-skip prompt.' );
		$this->assertSame( [], $cmux->createdSurfaces );
		$this->assertSame( [], $cmux->sent );
		$this->assertStringContainsString( 'already running', $output );
		$this->assertStringNotContainsString( 'Not found', $output );
	}

	public function testALiveSessionIsNotListedAsSkippedWithAResumeCommand(): void {
		$this->prompts->setArgs( [ 'cmux-bak', 'restore', '--yes' ] );

		$cmux = $this->cmux( [
			$this->liveWs( 'dotfiles', 'workspace:1', [ [ 'pane:1', [ [ 'surface:1', 'Retitled by Claude' ] ] ] ] ),
		] );
		$cmux->joinRows = [ $this->liveRow( 'sess-a', 'workspace:9', 'surface:99' ) ];

		$bak = $this->bak( $this->backup( [ $this->bakWs( 'dotfiles', [ [ 'Original title', 'sess-a' ] ] ) ] ), $cmux );

		ob_start();
		$bak->restore();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'workspace:9 / surface:99', $output );
		$this->assertStringNotContainsString( 'Skipped', $output, 'Pasting a resume command for a live session would run it twice.' );
	}

	public function testRenamedWorkspaceIsFoundByItsLiveSessionAndNotRecreated(): void {
		$cmux = $this->cmux( [
			$this->liveWs( 'renamed', 'workspace:5', [
				[ 'pane:5a', [ [ 'surface:5a', 'Agent A' ] ] ],
				[ 'pane:5b', [ [ 'surface:5b', 'Agent B' ] ] ],
			] ),
		] );
		$cmux->joinRows = [ $this->liveRow( 'sess-a', 'workspace:5', 'surface:5a' ) ];

		$bak = $this->bak(
			$this->backup( [ $this->bakWs( 'original title', [ [ 'Agent A', 'sess-a' ], [ 'Agent B', 'sess-b' ] ] ) ] ),
			$cmux
		);

		ob_start();
		$code   = $bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $cmux->newWorkspaces, 'A renamed workspace must not be recreated.' );
		$this->assertSame( [], $cmux->layoutWorkspaces );
		$this->assertSame( [ 'surface:5b', 'workspace:5', "resume claude sess-b\n" ], end( $cmux->sent ) );
		$this->assertNotContains( 'sess-a', $this->resumedSessions( $cmux ) );
		$this->assertStringContainsString( "'renamed'", $output );
	}

	public function testRecreatedWorkspaceResumesOnlyTheSessionsThatAreNotLive(): void {
		// Live but unbound to any surface, so it can't identify a workspace — yet it
		// is still running, and resuming it would run it twice.
		$cmux = $this->cmux( [] );
		$cmux->joinRows = [ $this->liveRow( 'sess-a', '', '' ) ];

		$bak = $this->bak(
			$this->backup( [ $this->bakWs( 'gone', [ [ 'Agent A', 'sess-a' ], [ 'Agent B', 'sess-b' ] ] ) ] ),
			$cmux
		);

		ob_start();
		$bak->restore();
		$output = (string) ob_get_clean();

		$this->assertSame( [ [ 'gone', $this->existingDir() ] ], $cmux->newWorkspaces );
		$this->assertSame( [ 'sess-b' ], $this->resumedSessions( $cmux ) );
		$this->assertStringContainsString( 'already running', $output );
	}

	public function testAuditResumesIntoARenamedWorkspaceFoundByItsLiveSession(): void {
		$this->prompts->setArgs( [ 'cmux-bak', 'audit', '--yes' ] );

		$cmux = $this->cmux( [
			$this->liveWs( 'renamed', 'workspace:5', [
				[ 'pane:5a', [ [ 'surface:5a', 'Agent A' ] ] ],
				[ 'pane:5b', [ [ 'surface:5b', 'Agent B' ] ] ],
			] ),
		] );
		$cmux->joinRows = [ $this->liveRow( 'sess-a', 'workspace:5', 'surface:5a' ) ];

		$bak = $this->bak(
			$this->backup( [ $this->bakWs( 'original title', [ [ 'Agent A', 'sess-a' ], [ 'Agent B', 'sess-b' ] ] ) ] ),
			$cmux,
			true
		);

		ob_start();
		$bak->audit();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'is gone', $output );
		$this->assertSame( [ 'surface:5b', 'workspace:5', "resume claude sess-b\n" ], end( $cmux->sent ) );
	}

	// ── Fixtures ──────────────────────────────────────────────────────────────

	/** @return string[] Session ids restore sent a resume command for. */
	private function resumedSessions( RestoreCmux $cmux ): array {
		$ids = [];
		foreach ( $cmux->sent as [ , , $text ] ) {
			if ( preg_match( '/^resume \w+ (\S+)/', $text, $m ) ) {
				$ids[] = $m[1];
			}
		}

		return $ids;
	}

	private function bak( string $file, RestoreCmux $cmux, bool $fakeTranscripts = false ): CmuxBak {
		if ( $fakeTranscripts ) {
			$cmux->transcriptPath = __FILE__;
		}

		return new CmuxBak( $this->prompts, $file, false, false, $cmux );
	}

	private function cmux( array $workspaces ): RestoreCmux {
		$cmux = new RestoreCmux( $this->prompts );
		$cmux->treeData = [ 'windows' => [ [ 'workspaces' => $workspaces ] ] ];

		return $cmux;
	}

	/** @param array $panes [ [ pane_ref, [ [ surface_ref, title ], … ] ], … ] */
	private function liveWs( string $title, string $ref, array $panes ): array {
		return [
			'title' => $title,
			'ref'   => $ref,
			'panes' => array_map(
				fn( $p ) => [
					'ref'      => $p[0],
					'surfaces' => array_map(
						fn( $s ) => [ 'ref' => $s[0], 'title' => $s[1], 'type' => 'terminal' ],
						$p[1]
					),
				],
				$panes
			),
		];
	}

	private function liveRow( string $sid, string $wsRef, string $surfRef ): array {
		return [
			'session_id'    => $sid,
			'agent'         => 'claude',
			'surface_ref'   => $surfRef,
			'workspace_ref' => $wsRef,
			'cwd'           => $this->existingDir(),
			'model'         => null,
			'skip_perms'    => false,
			'opts'          => [],
			'reason'        => '',
		];
	}

	/** @param array $surfaces [ [ title, session_id ], … ] — one pane per surface. */
	private function bakWs( string $title, array $surfaces ): array {
		return [
			'title' => $title,
			'panes' => array_map(
				fn( $s ) => [
					'surfaces' => [ [
						'title'            => $s[0],
						'type'             => 'terminal',
						'cwd'              => $this->existingDir(),
						'agent'            => 'claude',
						'agent_session_id' => $s[1],
					] ],
				],
				$surfaces
			),
		];
	}

	private function backup( array $workspaces ): string {
		$file = sys_get_temp_dir() . '/cmux-bak-live-' . uniqid() . '.json';
		$this->temporaryFiles[] = $file;
		file_put_contents(
			$file,
			json_encode( [ 'version' => 2, 'timestamp' => '2026-10-15T00:00:00Z', 'workspaces' => $workspaces ] )
		);

		return $file;
	}

	private function existingDir(): string {
		return $this->graveyardRoot;
	}
}
