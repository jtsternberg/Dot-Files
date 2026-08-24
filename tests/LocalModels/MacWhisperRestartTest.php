<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\AppControl;
use JT\LocalModels\MacWhisperEngine;
use JT\Tests\TestCase;

/**
 * Restarting MacWhisper after a real store switch.
 *
 * MacWhisper caches its model list at launch and `mw` has no reload verb, so a
 * flipped store shows the OLD model set until the app restarts. Restarting is
 * therefore part of the flip, not a nicety.
 *
 * The danger is restarting mid-transcription, which kills the job. Every busy
 * signal here is a VETO that fails safe: anything unclear counts as busy, and the
 * cost of a false "busy" is only a stale list plus a warning.
 */
final class MacWhisperRestartTest extends TestCase {

	private string $home = '';
	private string $volumes = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		mkdir( $this->home . '/.macwhisper-local-models', 0777, true );
		mkdir( $this->volumes . '/AI-LAB/macwhisper/models', 0777, true );
	}

	protected function tearDown(): void {
		putenv( 'AIMODELS_NO_RESTART' );
		parent::tearDown();
	}

	private function engine( FakeAppControl $apps ): MacWhisperEngine {
		return new MacWhisperEngine( $this->home, $this->volumes, $apps );
	}

	public function testRestartsAfterAnActualSwitchWhenIdle(): void {
		$apps   = new FakeAppControl( running: true, cpu: 0.1 );
		$engine = $this->engine( $apps );

		$result = $engine->apply( 'local' );

		$this->assertSame( 'applied', $result->status );
		$this->assertSame(
			[ 'quit:MacWhisper', 'wait:MacWhisper', 'reopen:MacWhisper' ],
			$apps->calls
		);
		$this->assertStringContainsString(
			'restarted MacWhisper',
			implode( ' ', $result->warnings )
		);
	}

	/**
	 * The watcher fires on EVERY /Volumes change — a single mount produced three
	 * firings live, all of them "already external". Restarting on each would be a
	 * quit/reopen storm, so only a flip that actually moved the symlink restarts.
	 */
	public function testDoesNotRestartOnAnIdempotentReapply(): void {
		$apps   = new FakeAppControl( running: true, cpu: 0.1 );
		$engine = $this->engine( $apps );
		$engine->apply( 'local' );
		$apps->calls = [];

		$result = $engine->apply( 'local' );

		$this->assertSame( 'noop', $result->status );
		$this->assertSame( [], $apps->calls, 'a noop must never touch the app' );
	}

	public function testDoesNotRestartOnADryRun(): void {
		$apps = new FakeAppControl( running: true, cpu: 0.1 );

		$this->engine( $apps )->apply( 'local', true );

		$this->assertSame( [], $apps->calls );
	}

	public function testDoesNotRestartWhatIsNotRunning(): void {
		$apps = new FakeAppControl( running: false, cpu: 0.0 );

		$result = $this->engine( $apps )->apply( 'local' );

		$this->assertSame( [], $apps->calls );
		// The local-store download advisory still applies; only restart chatter
		// should be absent for an app that is not open.
		$this->assertSame(
			[],
			array_values( array_filter(
				$result->warnings,
				static fn( string $w ): bool => str_contains( $w, 'MacWhisper' ) && str_contains( $w, 'relaunch' )
			) )
		);
	}

	// --- busy vetoes ----------------------------------------------------------

	public function testSkipsTheRestartWhenCpuSuggestsWork(): void {
		$apps = new FakeAppControl( running: true, cpu: 42.0 );

		$result = $this->engine( $apps )->apply( 'local' );

		$this->assertSame( [], $apps->calls, 'never quit a possibly-transcribing app' );
		$this->assertStringContainsString( 'busy', implode( ' ', $result->warnings ) );
		$this->assertStringContainsString( 'relaunch', implode( ' ', $result->warnings ) );
	}

	public function testSkipsTheRestartWhileItHoldsAnAudioFileOpen(): void {
		$apps = new FakeAppControl( running: true, cpu: 0.1, mediaFiles: [ '/Users/JT/recording.m4a' ] );

		$result = $this->engine( $apps )->apply( 'local' );

		$this->assertSame( [], $apps->calls );
		$this->assertStringContainsString( 'busy', implode( ' ', $result->warnings ) );
	}

	/**
	 * If a signal cannot be read, assume busy. Killing a transcription is far worse
	 * than leaving a stale model list.
	 */
	public function testTreatsAnUnreadableCpuSampleAsBusy(): void {
		$apps = new FakeAppControl( running: true, cpu: null );

		$result = $this->engine( $apps )->apply( 'local' );

		$this->assertSame( [], $apps->calls );
		$this->assertStringContainsString( 'busy', implode( ' ', $result->warnings ) );
	}

	public function testRestartCanBeDisabledOutright(): void {
		putenv( 'AIMODELS_NO_RESTART=1' );
		$apps = new FakeAppControl( running: true, cpu: 0.1 );

		$result = $this->engine( $apps )->apply( 'local' );

		$this->assertSame( [], $apps->calls );
		$this->assertStringContainsString( 'relaunch', implode( ' ', $result->warnings ) );
	}

	/**
	 * `open -a` can fail while the app is still tearing down from the quit — seen
	 * live on two flips in quick succession, which left MacWhisper closed. The
	 * reopen must be retried rather than surrendered to.
	 */
	public function testRetriesAReopenThatLosesTheRaceWithTeardown(): void {
		$apps = new FakeAppControl( running: true, cpu: 0.1, reopenFailures: 1 );

		$result = $this->engine( $apps )->apply( 'local' );

		$this->assertSame(
			[ 'quit:MacWhisper', 'wait:MacWhisper', 'reopen:MacWhisper', 'reopen:MacWhisper' ],
			$apps->calls
		);
		$this->assertStringContainsString( 'restarted MacWhisper', implode( ' ', $result->warnings ) );
	}

	public function testAFailedQuitIsNotReportedAsARestart(): void {
		$apps = new FakeAppControl( running: true, cpu: 0.1, quitSucceeds: false );

		$result = $this->engine( $apps )->apply( 'local' );

		$this->assertSame( [ 'quit:MacWhisper' ], $apps->calls, 'no reopen for an app that never quit' );
		$this->assertStringNotContainsString( 'restarted MacWhisper', implode( ' ', $result->warnings ) );
		$this->assertStringContainsString( 'relaunch', implode( ' ', $result->warnings ) );
	}
}

/**
 * Records what would have happened to the real app. No test may quit MacWhisper.
 */
final class FakeAppControl extends AppControl {

	/** @var string[] */
	public array $calls = [];

	/** @param string[] $mediaFiles */
	public function __construct(
		private bool $running = false,
		private ?float $cpu = 0.0,
		private array $mediaFiles = [],
		private bool $quitSucceeds = true,
		private int $reopenFailures = 0
	) {
	}

	public function isRunning( string $app ): bool {
		return $this->running;
	}

	public function cpuPercent( string $app ): ?float {
		return $this->cpu;
	}

	public function openMediaFiles( string $app ): array {
		return $this->mediaFiles;
	}

	public function quit( string $app ): bool {
		$this->calls[] = 'quit:' . $app;
		if ( ! $this->quitSucceeds ) {
			return false;
		}
		$this->running = false;

		return true;
	}

	public function waitForExit( string $app, int $tries = 10, int $sleepMicroseconds = 300000 ): bool {
		$this->calls[] = 'wait:' . $app;

		return true;
	}

	public function reopen( string $app ): bool {
		$this->calls[] = 'reopen:' . $app;
		if ( $this->reopenFailures > 0 ) {
			$this->reopenFailures--;

			return false;
		}
		$this->running = true;

		return true;
	}
}
