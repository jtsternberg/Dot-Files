<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\EngineRegistry;
use JT\LocalModels\Watcher;
use JT\Tests\TestCase;

/**
 * The one LaunchAgent that drives every engine.
 *
 * launchctl is always a stub here: loading a real agent from a test would install
 * a live job on the developer's machine that flips real model stores.
 */
final class WatcherTest extends TestCase {

	private string $home = '';
	private string $volumes = '';
	private string $calls = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		$this->calls   = $this->graveyardRoot . '/launchctl-calls';
		mkdir( $this->home . '/Library/LaunchAgents', 0777, true );
		mkdir( $this->volumes, 0777, true );

		$stub = $this->graveyardRoot . '/launchctl-stub';
		file_put_contents( $stub, "#!/bin/sh\necho \"\$@\" >> '{$this->calls}'\nexit 0\n" );
		chmod( $stub, 0755 );
		putenv( 'AIMODELS_LAUNCHCTL_BIN=' . $stub );
	}

	protected function tearDown(): void {
		putenv( 'AIMODELS_LAUNCHCTL_BIN' );
		parent::tearDown();
	}

	private function watcher(): Watcher {
		return new Watcher( $this->home, $this->volumes );
	}

	private function launchctlCalls(): string {
		return is_file( $this->calls ) ? (string) file_get_contents( $this->calls ) : '';
	}

	public function testPlistDeclaresTheVolumesWatchAndTheApplyCommand(): void {
		$plist = $this->watcher()->render();

		$this->assertStringContainsString( '<string>com.jt.aimodels-watcher</string>', $plist );
		$this->assertStringContainsString( '<key>WatchPaths</key>', $plist );
		$this->assertStringContainsString( '<string>/Volumes</string>', $plist );
		$this->assertStringContainsString( 'bin/aimodels', $plist );
		$this->assertStringContainsString( '<string>watch</string>', $plist );
		$this->assertStringContainsString( '<string>apply</string>', $plist );
	}

	/**
	 * The old agent hard-coded /opt/homebrew/Cellar/php@8.3/8.3.14/bin/php, which a
	 * brew upgrade silently breaks. The replacement must use a version-stable php.
	 */
	public function testPlistUsesAVersionStablePhpBinary(): void {
		$this->assertStringNotContainsString( '/Cellar/', $this->watcher()->render() );
	}

	public function testInstallWritesAndLoadsThePlist(): void {
		$watcher = $this->watcher();

		$this->assertTrue( $watcher->install()->ok() );
		$this->assertFileExists( $watcher->plistPath() );
		$this->assertStringContainsString( 'load', $this->launchctlCalls() );
	}

	public function testInstallRetiresTheLegacyOllamodelsWatcher(): void {
		$legacy = $this->home . '/Library/LaunchAgents/com.jt.ollamodels-watcher.plist';
		file_put_contents( $legacy, '<plist/>' );

		$this->watcher()->install();

		$this->assertFileDoesNotExist( $legacy, 'the legacy agent must not survive cutover' );
		$this->assertStringContainsString( 'unload', $this->launchctlCalls() );
		$this->assertStringContainsString( 'com.jt.ollamodels-watcher', $this->launchctlCalls() );
	}

	public function testRemoveUnloadsAndDeletesThePlist(): void {
		$watcher = $this->watcher();
		$watcher->install();

		$this->assertTrue( $watcher->remove()->ok() );
		$this->assertFileDoesNotExist( $watcher->plistPath() );
		$this->assertStringContainsString( 'unload', $this->launchctlCalls() );
	}

	public function testApplyAllSkipsEnginesThatAreNotYetManageable(): void {
		// Ollama's local store exists; MacWhisper's does not, so it self-gates.
		mkdir( $this->home . '/.ollama-local-models', 0777, true );

		$results = $this->watcher()->applyAll();

		$this->assertSame( 'applied', $results['ollama']->status );
		$this->assertSame( 'skipped', $results['macwhisper']->status );
		$this->assertStringContainsString( 'local store missing', $results['macwhisper']->message );
	}

	public function testApplyAllFollowsTheDriveToTheExternalStore(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );
		mkdir( $this->volumes . '/AI-LAB/ollama/models', 0777, true );

		$results = $this->watcher()->applyAll();

		$this->assertSame( 'external', $results['ollama']->location );
		$this->assertSame(
			$this->volumes . '/AI-LAB/ollama/models',
			readlink( $this->home . '/.ollama-models' )
		);
	}

	public function testApplyAllFallsBackToLocalWhenTheDriveIsAbsent(): void {
		mkdir( $this->home . '/.ollama-local-models', 0777, true );

		$results = $this->watcher()->applyAll();

		$this->assertSame( 'local', $results['ollama']->location );
	}

	public function testStatusReportsInstalledStateWithoutMutating(): void {
		$watcher = $this->watcher();

		$before = $watcher->status();
		$this->assertFalse( $before['installed'] );
		$this->assertFileDoesNotExist( $watcher->plistPath() );

		$watcher->install();
		$this->assertTrue( $watcher->status()['installed'] );
	}

	public function testRegistryDrivenSoANewEngineNeedsNoWatcherChange(): void {
		$engines = ( new EngineRegistry( $this->home, $this->volumes ) )->engines();

		$this->assertCount( count( $engines ), $this->watcher()->applyAll() );
	}
}
