<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\Watcher;
use JT\Tests\TestCase;

/**
 * The watcher's audit trail.
 *
 * A background job that re-points symlinks on every /Volumes event with no log
 * is an invisible-failure trap (see beads dotfiles-8mr for the same shape). It
 * also cost a live debugging session: diagnosing why both stores were back on
 * external after a failed eject took a flip-persistence experiment, when one log
 * line would have said "eject bumped /Volumes, drive still mounted, re-applied
 * external".
 */
final class WatcherLogTest extends TestCase {

	private string $home = '';
	private string $volumes = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		mkdir( $this->home . '/.ollama-local-models', 0777, true );
		mkdir( $this->volumes, 0777, true );
	}

	private function watcher(): Watcher {
		return new Watcher( $this->home, $this->volumes );
	}

	private function log(): string {
		$path = $this->watcher()->logPath();

		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}

	public function testLogLivesUnderTheUsersLogDirectory(): void {
		$this->assertSame(
			$this->home . '/Library/Logs/aimodels-watcher.log',
			$this->watcher()->logPath()
		);
	}

	public function testEveryApplyRecordsMountStateAndPerEngineOutcome(): void {
		$this->watcher()->applyAll();
		$log = $this->log();

		$this->assertStringContainsString( 'mounted=no', $log );
		$this->assertStringContainsString( 'engine=ollama', $log );
		$this->assertStringContainsString( 'status=applied', $log );
		$this->assertStringContainsString( 'location=local', $log );
	}

	public function testSkippedEnginesAreLoggedWithTheirReason(): void {
		$this->watcher()->applyAll();
		$log = $this->log();

		$this->assertStringContainsString( 'engine=macwhisper', $log );
		$this->assertStringContainsString( 'status=skipped', $log );
		$this->assertStringContainsString( 'local store missing', $log );
	}

	/**
	 * The failed-eject case that needed diagnosing live: the drive is still
	 * mounted, so the watcher correctly restores external. The log must make that
	 * legible instead of looking like the flip "didn't stick".
	 */
	public function testMountedRunsRecordTheExternalDecision(): void {
		mkdir( $this->volumes . '/AI-LAB/ollama/models', 0777, true );

		$this->watcher()->applyAll();

		$this->assertStringContainsString( 'mounted=yes', $this->log() );
		$this->assertStringContainsString( 'location=external', $this->log() );
	}

	public function testEachRunIsTimestampedAndAppended(): void {
		$this->watcher()->applyAll();
		$first = substr_count( $this->log(), "\n" );

		$this->watcher()->applyAll();

		$this->assertGreaterThan( $first, substr_count( $this->log(), "\n" ), 'runs append, never overwrite' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/m', $this->log() );
	}

	public function testInstallAndRemoveAreRecorded(): void {
		$stub = $this->graveyardRoot . '/launchctl-noop';
		file_put_contents( $stub, "#!/bin/sh\nexit 0\n" );
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_LAUNCHCTL_BIN=' . $stub );

		$watcher = $this->watcher();
		$watcher->install();
		$watcher->remove();

		$this->assertStringContainsString( 'event=install', $this->log() );
		$this->assertStringContainsString( 'event=remove', $this->log() );
	}

	/**
	 * It runs on every /Volumes change forever, so the log must not grow forever.
	 */
	public function testLogIsTrimmedWhenItGrowsTooLarge(): void {
		$path = $this->watcher()->logPath();
		mkdir( dirname( $path ), 0777, true );
		file_put_contents( $path, str_repeat( "old noise line\n", 40000 ) );
		$this->assertGreaterThan( 256 * 1024, filesize( $path ) );

		$this->watcher()->applyAll();

		clearstatcache( true, $path );
		$this->assertLessThan( 256 * 1024, filesize( $path ) );
		$this->assertStringContainsString( 'engine=ollama', $this->log(), 'the new line survives the trim' );
	}

	public function testStatusExposesTheRecentLogLines(): void {
		$this->watcher()->applyAll();

		$recent = $this->watcher()->status()['recent'];

		$this->assertNotEmpty( $recent );
		$this->assertStringContainsString( 'engine=ollama', implode( "\n", $recent ) );
	}
}
