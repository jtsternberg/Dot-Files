<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\AppControl;
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
 * diskutil and lsof are always stubbed here. A test that shelled out for real
 * would eject the developer's drive.
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
	}

	protected function tearDown(): void {
		putenv( 'AIMODELS_DISKUTIL_BIN' );
		putenv( 'AIMODELS_LSOF_BIN' );
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

	private function stubLsof( string $output ): void {
		$stub = $this->graveyardRoot . '/lsof-stub';
		file_put_contents( $stub, "#!/bin/sh\ncat <<'OUT'\n{$output}\nOUT\nexit 0\n" );
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_LSOF_BIN=' . $stub );
	}

	private function ejector( ?AppControl $apps = null ): Ejector {
		return new Ejector(
			new EngineRegistry( $this->home, $this->volumes ),
			$this->volumes,
			'AI-LAB',
			$apps ?: new RecordingAppControl( [] )
		);
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

	// --- busy reporting -------------------------------------------------------

	public function testReportsTheProcessesHoldingTheVolume(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof(
			"MacWhispe 4210 JT  txt  REG  1,13  0  1 /Volumes/AI-LAB/macwhisper/models/x\n"
			. "ollama    5511 JT  txt  REG  1,13  0  2 /Volumes/AI-LAB/ollama/models/y"
		);

		$report = $this->ejector()->eject();

		$this->assertFalse( $report['ejected'] );
		$this->assertCount( 2, $report['holders'] );
		$this->assertSame( 'MacWhispe', $report['holders'][0]['command'] );
		$this->assertSame( '4210', $report['holders'][0]['pid'] );
		$this->assertStringContainsString( '--force', $report['message'] );
	}

	public function testNeverTouchesAProcessItDoesNotRecognise(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof( "Finder 9001 JT txt REG 1,13 0 1 /Volumes/AI-LAB/something" );

		$report = $this->ejector()->eject();

		$this->assertSame( [], $report['quit'], 'an unknown holder is reported, never quit' );
		$this->assertFalse( $report['ejected'] );
		$this->assertStringContainsString( '--force', $report['message'] );
	}

	// --- quit the blocking app, then put it back -------------------------------

	/**
	 * The live failure: `llama-server` — an Ollama.app subprocess, not "Ollama" —
	 * held an open FD to a blob on the drive. lsof reports the subprocess name,
	 * and truncates COMMAND to 9 characters, so matching a holder to an app has
	 * to cope with both.
	 */
	public function testQuitsTheOwningAppWhenASubprocessBlocksTheEject(): void {
		$this->stubDiskutilFailingThenSucceeding();
		$this->stubLsof( 'llama-ser 7788 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/sha256-x' );
		$apps = new RecordingAppControl( [ 'Ollama' ] );

		$report = $this->ejector( $apps )->eject();

		$this->assertTrue( $report['ejected'] );
		$this->assertSame( [ 'Ollama' ], $report['quit'] );
		$this->assertSame( [ 'Ollama' ], $report['reopened'] );
		$this->assertSame( [ 'quit:Ollama', 'wait:Ollama', 'reopen:Ollama' ], $apps->calls );
	}

	public function testMapsATruncatedLsofCommandToItsApp(): void {
		$this->stubDiskutilFailingThenSucceeding();
		$this->stubLsof( 'MacWhispe 4210 JT txt REG 1,13 0 1 /Volumes/AI-LAB/macwhisper/models/x' );
		$apps = new RecordingAppControl( [ 'MacWhisper' ] );

		$report = $this->ejector( $apps )->eject();

		$this->assertSame( [ 'MacWhisper' ], $report['quit'] );
		$this->assertTrue( $report['ejected'] );
	}

	/**
	 * fail => restore. If the retry still fails, whatever we closed must come
	 * back; leaving Ollama shut because an eject didn't work is not acceptable.
	 */
	public function testReopensWhatItQuitEvenWhenTheEjectStillFails(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof( 'llama-ser 7788 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );
		$apps = new RecordingAppControl( [ 'Ollama' ] );

		$report = $this->ejector( $apps )->eject();

		$this->assertFalse( $report['ejected'] );
		$this->assertSame( [ 'Ollama' ], $report['quit'] );
		$this->assertSame( [ 'Ollama' ], $report['reopened'], 'a failed eject must not leave an app closed' );
	}

	public function testCleanEjectNeverQuitsAnything(): void {
		$apps = new RecordingAppControl( [ 'Ollama', 'MacWhisper' ] );

		$report = $this->ejector( $apps )->eject();

		$this->assertTrue( $report['ejected'] );
		$this->assertSame( [], $report['quit'] );
		$this->assertSame( [], $apps->calls, 'a volume that ejects cleanly needs no app touched' );
	}

	public function testNoQuitRestoresTheReportAndStopBehaviour(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof( 'llama-ser 7788 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );
		$apps = new RecordingAppControl( [ 'Ollama' ] );

		$report = $this->ejector( $apps )->eject( [ 'no-quit' => true ] );

		$this->assertSame( [], $report['quit'] );
		$this->assertSame( [], $apps->calls );
		$this->assertNotEmpty( $report['holders'] );
	}

	public function testNoRestartLeavesTheAppClosed(): void {
		$this->stubDiskutilFailingThenSucceeding();
		$this->stubLsof( 'llama-ser 7788 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );
		$apps = new RecordingAppControl( [ 'Ollama' ] );

		$report = $this->ejector( $apps )->eject( [ 'no-restart' => true ] );

		$this->assertSame( [ 'Ollama' ], $report['quit'] );
		$this->assertSame( [], $report['reopened'] );
	}

	public function testDryRunQuitsNothing(): void {
		$this->stubDiskutil( 1, 'Unmount failed - Resource busy' );
		$this->stubLsof( 'llama-ser 7788 JT txt REG 1,13 0 1 /Volumes/AI-LAB/ollama/models/blobs/x' );
		$apps = new RecordingAppControl( [ 'Ollama' ] );

		$this->ejector( $apps )->eject( [ 'dry-run' => true ] );

		$this->assertSame( [], $apps->calls );
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

	// --- force ----------------------------------------------------------------

	public function testForceIsOnlyUsedWhenAskedFor(): void {
		$this->ejector()->eject();
		$this->assertStringNotContainsString( 'force', $this->diskutilCalls() );

		$this->ejector()->eject( [ 'force' => true ] );
		$this->assertStringContainsString( 'force', $this->diskutilCalls() );
	}

	// --- dry run --------------------------------------------------------------

	public function testDryRunNeitherFlipsNorEjects(): void {
		$engine = ( new EngineRegistry( $this->home, $this->volumes ) )->engine( 'ollama' );
		$engine->apply( 'external' );

		$report = $this->ejector()->eject( [ 'dry-run' => true ] );

		$this->assertFalse( $report['ejected'] );
		$this->assertSame( 'external', $engine->currentLocation() );
		$this->assertSame( '', $this->diskutilCalls() );
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

/**
 * Records what would have been done to real applications. No test may quit or
 * reopen anything on the developer's machine.
 */
final class RecordingAppControl extends AppControl {

	/** @var string[] */
	public array $calls = [];

	/** @param string[] $running */
	public function __construct( private array $running = [] ) {
	}

	public function isRunning( string $app ): bool {
		return in_array( $app, $this->running, true );
	}

	public function quit( string $app ): bool {
		$this->calls[]  = 'quit:' . $app;
		$this->running  = array_values( array_diff( $this->running, [ $app ] ) );

		return true;
	}

	public function waitForExit( string $app, int $tries = 10, int $sleepMicroseconds = 300000 ): bool {
		$this->calls[] = 'wait:' . $app;

		return true;
	}

	public function reopen( string $app ): bool {
		$this->calls[] = 'reopen:' . $app;

		return true;
	}
}
