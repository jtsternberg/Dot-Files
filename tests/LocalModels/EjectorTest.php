<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\Ejector;
use JT\LocalModels\EngineRegistry;
use JT\Tests\TestCase;

/**
 * Safe removal of the AI-LAB drive.
 *
 * The ordering is the whole point. Finder and diskutil fail with "busy" because
 * at eject time the model symlinks still resolve into /Volumes/AI-LAB, and the
 * watcher only flips to local AFTER the eject event fires — too late to help.
 * So: release the references first, then eject.
 *
 * When the volume is STILL busy, the holder is a loaded model, not the app: live,
 * Ollama.app -> ollama serve -> llama-server held an FD on a blob. Releasing that
 * is engine-specific work (`ollama stop`), so the Ejector asks each engine to
 * release its own holds rather than reaching for the app.
 *
 * diskutil, lsof and the engines' release commands are always stubbed here. A
 * test that shelled out for real would eject the developer's drive or unload
 * their running model.
 */
final class EjectorTest extends TestCase {

	private string $home = '';
	private string $volumes = '';
	private string $calls = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		$this->calls   = $this->graveyardRoot . '/diskutil-calls';
		mkdir( $this->home . '/.ollama-local-models', 0777, true );
		mkdir( $this->volumes . '/AI-LAB/ollama/models', 0777, true );

		$this->stubDiskutil( 0, 'Volume AI-LAB on disk4s2 ejected' );
		$this->stubLsof( '' );
		$this->stubOllama( 0 );
	}

	protected function tearDown(): void {
		putenv( 'AIMODELS_DISKUTIL_BIN' );
		putenv( 'AIMODELS_LSOF_BIN' );
		putenv( 'AIMODELS_OLLAMA_BIN' );
		parent::tearDown();
	}

	private function stubDiskutil( int $exit, string $output ): void {
		$stub = $this->graveyardRoot . '/diskutil-stub';
		file_put_contents(
			$stub,
			"#!/bin/sh\necho \"\$@\" >> '{$this->calls}'\ncat <<'OUT'\n{$output}\nOUT\nexit {$exit}\n"
		);
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_DISKUTIL_BIN=' . $stub );
	}

	private function stubDiskutilFailingThenSucceeding(): void {
		$stub = $this->graveyardRoot . '/diskutil-stub';
		file_put_contents(
			$stub,
			"#!/bin/sh\necho \"\$@\" >> '{$this->calls}'\n"
			. "if [ \"\$(wc -l < '{$this->calls}')\" -le 1 ]; then\n"
			. "  echo 'Unmount failed - Resource busy'; exit 1\nfi\n"
			. "echo 'Volume AI-LAB ejected'; exit 0\n"
		);
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_DISKUTIL_BIN=' . $stub );
	}

	private function stubLsof( string $output ): void {
		$stub = $this->graveyardRoot . '/lsof-stub';
		file_put_contents( $stub, "#!/bin/sh\ncat <<'OUT'\n{$output}\nOUT\nexit 0\n" );
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_LSOF_BIN=' . $stub );
	}

	/**
	 * $stopExit = the exit code `ollama stop` returns, so a refusing runtime can
	 * be exercised as easily as a cooperative one.
	 */
	private function stubOllama( int $stopExit, string $psModel = 'qwen2.5-coder:1.5b' ): void {
		$stub = $this->graveyardRoot . '/ollama-stub';
		$log  = $this->graveyardRoot . '/ollama-calls';
		file_put_contents(
			$stub,
			"#!/bin/sh\necho \"\$@\" >> '{$log}'\n"
			. "case \"\$1\" in\n"
			. "  ps) printf 'NAME  ID  SIZE  PROCESSOR  UNTIL\\n{$psModel}  abc123  1.9 GB  100%% GPU  4 minutes from now\\n' ;;\n"
			. "  stop) exit {$stopExit} ;;\n"
			. "esac\nexit 0\n"
		);
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_OLLAMA_BIN=' . $stub );
	}

	private function ollamaCalls(): string {
		$log = $this->graveyardRoot . '/ollama-calls';

		return is_file( $log ) ? (string) file_get_contents( $log ) : '';
	}

	private function ejector(): Ejector {
		return new Ejector( new EngineRegistry( $this->home, $this->volumes ), $this->volumes );
	}

	private function diskutilCalls(): string {
		return is_file( $this->calls ) ? (string) file_get_contents( $this->calls ) : '';
	}

	// --- ordering -------------------------------------------------------------

	public function testFlipsEveryEngineToLocalBeforeEjecting(): void {
		$engine = ( new EngineRegistry( $this->home, $this->volumes ) )->engine( 'ollama' );
		$engine->apply( 'external' );
		$this->assertSame( 'external', $engine->currentLocation() );

		$report = $this->ejector()->eject();

		$this->assertTrue( $report['ejected'] );
		$this->assertSame( 'local', $engine->currentLocation() );
		$this->assertSame( 'local', $report['engines']['ollama']->location );
	}

	/**
	 * The genuine unreleasable case: an engine is pointed AT the drive and has no
	 * local store to fall back to. Ejecting then would yank the volume out from
	 * under a live symlink. (An unmigrated real models directory is NOT this case
	 * — it holds nothing on the drive, so it must not block the eject.)
	 */
	public function testDoesNotEjectWhenAnEngineCouldNotBeReleased(): void {
		$external = $this->volumes . '/AI-LAB/macwhisper/models';
		mkdir( $external, 0777, true );
		$models = $this->home . '/Library/Application Support/MacWhisper/models';
		mkdir( dirname( $models ), 0777, true );
		symlink( $external, $models );

		$report = $this->ejector()->eject();

		$this->assertFalse( $report['ejected'] );
		$this->assertStringContainsString( 'could not be released', $report['message'] );
		$this->assertSame( '', $this->diskutilCalls(), 'diskutil must not be reached' );
	}

	// --- not mounted ----------------------------------------------------------

	public function testNothingToDoWhenTheVolumeIsNotMounted(): void {
		$this->rmdirTree( $this->volumes . '/AI-LAB' );

		$report = $this->ejector()->eject();

		$this->assertTrue( $report['ejected'], 'already gone counts as success' );
		$this->assertTrue( $report['alreadyGone'] );
		$this->assertSame( '', $this->diskutilCalls() );
	}

	// --- releasing model holds ------------------------------------------------

	/**
	 * The live failure this replaced: the FD belonged to llama-server, a grandchild
	 * of Ollama.app. Unloading the model frees it and leaves the app running.
	 */
	public function testAsksEachEngineToReleaseItsHoldsWhenBusy(): void {
		$this->stubDiskutilFailingThenSucceeding();
		$this->stubLsof( 'llama-ser 38995 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/sha256-x' );

		$report = $this->ejector()->eject();

		$this->assertTrue( $report['ejected'] );
		$this->assertSame( [ 'qwen2.5-coder:1.5b' ], $report['released']['ollama'] );
		$this->assertStringContainsString( 'stop qwen2.5-coder:1.5b', $this->ollamaCalls() );
	}

	public function testCleanEjectReleasesNothing(): void {
		$report = $this->ejector()->eject();

		$this->assertTrue( $report['ejected'] );
		$this->assertSame( [], $report['released'] );
		$this->assertSame( '', $this->ollamaCalls(), 'a volume that ejects cleanly needs nothing unloaded' );
	}

	/**
	 * The class of bug that shipped green: the release verb FAILS at runtime.
	 * osascript-quitting Ollama returned -128 "User canceled" on the real machine
	 * while the stubbed double always succeeded. Nothing may report success it did
	 * not achieve, and a failed release must not be dressed up as a retry.
	 */
	public function testAReleaseThatFailsIsNotReportedAsReleased(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubOllama( 1 );   // `ollama stop` refuses
		$this->stubLsof( 'llama-ser 38995 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );

		$report = $this->ejector()->eject();

		$this->assertFalse( $report['ejected'] );
		$this->assertSame( [], $report['released'], 'a refused stop is not a release' );
		$this->assertNotEmpty( $report['holders'] );
		$this->assertStringContainsString( '--force', $report['message'] );
	}

	public function testRetryIsNotAttemptedWhenNothingCouldBeReleased(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubOllama( 1 );
		$this->stubLsof( 'llama-ser 38995 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );

		$this->ejector()->eject();

		$this->assertSame( 1, substr_count( $this->diskutilCalls(), 'eject' ), 'one attempt, no pointless retry' );
	}

	public function testNoReleaseFlagKeepsItToReportAndStop(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof( 'llama-ser 38995 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );

		$report = $this->ejector()->eject( [ 'no-release' => true ] );

		$this->assertSame( [], $report['released'] );
		$this->assertSame( '', $this->ollamaCalls() );
		$this->assertNotEmpty( $report['holders'] );
	}

	public function testDryRunReleasesNothing(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof( 'llama-ser 38995 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );

		$this->ejector()->eject( [ 'dry-run' => true ] );

		$this->assertSame( '', $this->ollamaCalls() );
		$this->assertSame( '', $this->diskutilCalls() );
	}

	/**
	 * MacWhisper holds nothing persistent — validated live, it was never the
	 * blocker — so it must not invent work to do here.
	 */
	public function testMacWhisperHasNoHoldsToRelease(): void {
		mkdir( $this->home . '/.macwhisper-local-models', 0777, true );
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof( 'llama-ser 38995 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );

		$report = $this->ejector()->eject();

		$this->assertArrayNotHasKey( 'macwhisper', $report['released'] );
	}

	// --- busy reporting -------------------------------------------------------

	public function testReportsTheProcessesHoldingTheVolume(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubOllama( 1 );
		$this->stubLsof(
			"MacWhispe 4210 JT  txt  REG  1,13  0  1 /Volumes/AI-LAB/macwhisper/models/x\n"
			. "ollama    5511 JT  txt  REG  1,13  0  2 /Volumes/AI-LAB/ollama/models/y"
		);

		$report = $this->ejector()->eject();

		$this->assertFalse( $report['ejected'] );
		$this->assertCount( 2, $report['holders'] );
		$this->assertSame( 'MacWhispe', $report['holders'][0]['command'] );
		$this->assertSame( '4210', $report['holders'][0]['pid'] );
	}

	public function testNeverSignalsOrQuitsAnything(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubOllama( 1 );
		$this->stubLsof( 'Finder 9001 JT txt REG 1,13 0 1 /Volumes/AI-LAB/something' );

		$report = $this->ejector()->eject();

		// An unknown holder is named, never acted on. There is no app-quit path at
		// all any more: osascript quit is unreliable (Ollama returns -128) and a
		// signal to an app mid-write is how model files get truncated.
		$this->assertFalse( $report['ejected'] );
		$this->assertArrayNotHasKey( 'quit', $report );
		$this->assertStringContainsString( 'Finder', $report['holders'][0]['command'] );
	}

	// --- force ----------------------------------------------------------------

	public function testForceIsOnlyUsedWhenAskedFor(): void {
		$this->ejector()->eject();
		$this->assertStringNotContainsString( 'force', $this->diskutilCalls() );

		$this->ejector()->eject( [ 'force' => true ] );
		$this->assertStringContainsString( 'force', $this->diskutilCalls() );
	}

	private function rmdirTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}
}
