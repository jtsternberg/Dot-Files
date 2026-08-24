<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\Watcher;
use JT\Tests\TestCase;

/**
 * Debouncing the watcher's firings.
 *
 * launchd's WatchPaths on /Volumes can get stuck re-triggering: observed live
 * firing every ~10s (its throttle floor) for minutes with no mount activity and a
 * completely stable /Volumes listing. Unloading and reloading the agent cleared
 * it, which places the cause in a pending WatchPaths event rather than real
 * churn — a fast-exiting job leaving the event armed.
 *
 * The job cannot stop launchd firing, so instead each firing gets cheap: compare
 * a fingerprint of what we actually care about and exit before doing any engine
 * work when nothing moved.
 *
 * The fingerprint deliberately includes each engine's CURRENT symlink target as
 * well as the drive's presence, so a store that drifted (a manual flip, a failed
 * run) still gets corrected rather than debounced away.
 */
final class WatcherDebounceTest extends TestCase {

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

	public function testFirstFiringDoesTheWork(): void {
		$results = $this->watcher()->applyIfChanged();

		$this->assertArrayHasKey( 'ollama', $results );
		$this->assertSame( 'applied', $results['ollama']->status );
	}

	public function testASecondIdenticalFiringSkipsAllEngineWork(): void {
		$this->watcher()->applyIfChanged();

		$results = $this->watcher()->applyIfChanged();

		$this->assertSame( [], $results, 'nothing to report when nothing moved' );
	}

	public function testTheDriveAppearingIsNotDebounced(): void {
		$this->watcher()->applyIfChanged();
		mkdir( $this->volumes . '/AI-LAB/ollama/models', 0777, true );

		$results = $this->watcher()->applyIfChanged();

		$this->assertSame( 'applied', $results['ollama']->status );
		$this->assertSame( 'external', $results['ollama']->location );
	}

	/**
	 * Safety: the fingerprint covers symlink state, so a store someone flipped by
	 * hand is put back rather than silently left wrong.
	 */
	public function testAStoreThatDriftedIsNotDebounced(): void {
		mkdir( $this->volumes . '/AI-LAB/ollama/models', 0777, true );
		$this->watcher()->applyIfChanged();
		// Someone flips it to local while the drive is still mounted.
		@unlink( $this->home . '/.ollama-models' );
		symlink( $this->home . '/.ollama-local-models', $this->home . '/.ollama-models' );

		$results = $this->watcher()->applyIfChanged();

		$this->assertSame( 'applied', $results['ollama']->status );
		$this->assertSame( 'external', $results['ollama']->location );
	}

	// --- logging --------------------------------------------------------------

	public function testDebouncedFiringsAreNotLoggedEveryTime(): void {
		$this->watcher()->applyIfChanged( 1000 );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->watcher()->applyIfChanged( 1000 + $i );
		}

		$this->assertSame(
			0,
			substr_count( $this->log(), 'unchanged-debounced' ),
			'a log line per firing would balloon the very file it is meant to keep readable'
		);
	}

	public function testDebouncedFiringsAreSummarisedOncePerWindow(): void {
		$this->watcher()->applyIfChanged( 1000 );
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->watcher()->applyIfChanged( 1000 + ( $i * 10 ) );
		}
		// Past the window, the accumulated count is reported once.
		$this->watcher()->applyIfChanged( 1000 + 1000 );

		$log = $this->log();
		$this->assertSame( 1, substr_count( $log, 'unchanged-debounced' ) );
		$this->assertStringContainsString( 'suppressed=31', $log );
	}

	/**
	 * A firing rate that high is the stuck-WatchPaths signature, so the log should
	 * name the remedy rather than leaving the next reader to rediscover it.
	 */
	public function testARunawayFiringRateCarriesTheRemedy(): void {
		$this->watcher()->applyIfChanged( 1000 );
		for ( $i = 1; $i <= 40; $i++ ) {
			$this->watcher()->applyIfChanged( 1000 + ( $i * 10 ) );
		}
		$this->watcher()->applyIfChanged( 1000 + 1000 );

		$this->assertStringContainsString( 'watch reload', $this->log() );
	}

	// --- reload ---------------------------------------------------------------

	public function testReloadReinstatesTheAgentAndIsRecorded(): void {
		$stub = $this->graveyardRoot . '/launchctl-noop';
		file_put_contents( $stub, "#!/bin/sh\nexit 0\n" );
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_LAUNCHCTL_BIN=' . $stub );

		$watcher = $this->watcher();
		mkdir( dirname( $watcher->plistPath() ), 0777, true );
		file_put_contents( $watcher->plistPath(), $watcher->render() );

		$result = $watcher->reload();

		$this->assertTrue( $result->ok() );
		$this->assertStringContainsString( 'event=reload', $this->log() );

		putenv( 'AIMODELS_LAUNCHCTL_BIN' );
	}

	public function testReloadRefusesWhenTheAgentIsNotInstalled(): void {
		$this->assertFalse( $this->watcher()->reload()->ok() );
	}
}
