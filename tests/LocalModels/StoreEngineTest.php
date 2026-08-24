<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\Drive;
use JT\LocalModels\EngineRegistry;
use JT\LocalModels\Flip;
use JT\LocalModels\MacWhisperEngine;
use JT\LocalModels\OllamaEngine;
use JT\Tests\TestCase;

/**
 * The store engines and the primitives they flip with.
 *
 * Every engine here is constructed against a throwaway $home and $volumesRoot so
 * no test can touch the real ~/.ollama-models or the real MacWhisper models root.
 * That matters more than usual: a bad default would point a test at tens of GB of
 * real model data whose symlink these classes exist to replace.
 */
final class StoreEngineTest extends TestCase {

	private string $home = '';
	private string $volumes = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		mkdir( $this->home, 0777, true );
		mkdir( $this->volumes, 0777, true );
	}

	private function macwhisper(): MacWhisperEngine {
		return new MacWhisperEngine( $this->home, $this->volumes );
	}

	private function mountExternal(): string {
		$path = $this->volumes . '/AI-LAB/macwhisper/models';
		mkdir( $path, 0777, true );

		return $path;
	}

	private function makeLocalStore(): string {
		$path = $this->home . '/.macwhisper-local-models';
		mkdir( $path, 0777, true );

		return $path;
	}

	// --- Flip -----------------------------------------------------------------

	public function testFlipCreatesSymlinkWhenNoneExists(): void {
		$target = $this->makeLocalStore();
		$link   = $this->home . '/models';

		$this->assertTrue( Flip::swap( $link, $target ) );
		$this->assertTrue( is_link( $link ) );
		$this->assertSame( $target, readlink( $link ) );
	}

	public function testFlipReplacesAnExistingSymlinkWithoutRemovingItFirst(): void {
		$local    = $this->makeLocalStore();
		$external = $this->mountExternal();
		$link     = $this->home . '/models';

		Flip::swap( $link, $local );
		$this->assertTrue( Flip::swap( $link, $external ) );
		$this->assertSame( $external, readlink( $link ) );

		// No leftover temp links beside it: the swap renames its temp into place.
		$strays = glob( $this->home . '/models.aimodels-tmp.*' ) ?: [];
		$this->assertSame( [], $strays );
	}

	public function testFlipRefusesToReplaceARealDirectory(): void {
		$target = $this->makeLocalStore();
		$link   = $this->home . '/models';
		mkdir( $link, 0777, true );
		file_put_contents( $link . '/keep-me', 'real user data' );

		$this->assertFalse( Flip::swap( $link, $target ) );
		$this->assertFileExists( $link . '/keep-me' );
	}

	public function testCurrentTargetReadsThroughTheSymlinkOrReturnsNull(): void {
		$target = $this->makeLocalStore();
		$link   = $this->home . '/models';

		$this->assertNull( Flip::currentTarget( $link ) );
		Flip::swap( $link, $target );
		$this->assertSame( $target, Flip::currentTarget( $link ) );
	}

	// --- Drive ----------------------------------------------------------------

	public function testDriveDetectsAMountedVolumeByName(): void {
		$drive = new Drive( $this->volumes );

		$this->assertFalse( $drive->isMounted( 'AI-LAB' ) );
		mkdir( $this->volumes . '/AI-LAB', 0777, true );
		$this->assertTrue( $drive->isMounted( 'AI-LAB' ) );
	}

	// --- Paths ----------------------------------------------------------------

	public function testMacWhisperEngineUsesTheContractPaths(): void {
		$engine = $this->macwhisper();

		$this->assertSame( 'macwhisper', $engine->name() );
		$this->assertSame(
			$this->home . '/Library/Application Support/MacWhisper/models',
			$engine->symlinkPath()
		);
		$this->assertSame( $this->home . '/.macwhisper-local-models', $engine->storePath( 'local' ) );
		$this->assertSame( $this->volumes . '/AI-LAB/macwhisper/models', $engine->storePath( 'external' ) );
	}

	public function testOllamaEngineUsesTheContractPaths(): void {
		$engine = new OllamaEngine( $this->home, $this->volumes );

		$this->assertSame( 'ollama', $engine->name() );
		$this->assertSame( $this->home . '/.ollama-models', $engine->symlinkPath() );
		$this->assertSame( $this->home . '/.ollama-local-models', $engine->storePath( 'local' ) );
		$this->assertSame( $this->volumes . '/AI-LAB/ollama/models', $engine->storePath( 'external' ) );
	}

	// --- Preflight / self-gating ---------------------------------------------

	public function testEngineIsUnmanageableWhileTheSymlinkPathIsARealDirectory(): void {
		$this->makeLocalStore();
		$models = $this->home . '/Library/Application Support/MacWhisper/models';
		mkdir( $models, 0777, true );

		$pre = $this->macwhisper()->preflight();

		$this->assertFalse( $pre->manageable );
		$this->assertStringContainsString( 'real directory', $pre->reason );
	}

	public function testEngineIsUnmanageableUntilTheLocalStoreExists(): void {
		$pre = $this->macwhisper()->preflight();

		$this->assertFalse( $pre->manageable );
		$this->assertStringContainsString( 'local store', $pre->reason );
	}

	public function testEngineBecomesManageableOnceStoresExistAndPathIsFree(): void {
		$this->makeLocalStore();

		$this->assertTrue( $this->macwhisper()->preflight()->manageable );
	}

	// --- apply ---------------------------------------------------------------

	public function testApplyFlipsToTheRequestedStore(): void {
		$local  = $this->makeLocalStore();
		$engine = $this->macwhisper();

		$result = $engine->apply( 'local' );

		$this->assertSame( 'applied', $result->status );
		$this->assertSame( $local, readlink( $engine->symlinkPath() ) );
		$this->assertSame( 'local', $engine->currentLocation() );
	}

	public function testApplyIsANoopWhenAlreadyPointedAtTheTarget(): void {
		$this->makeLocalStore();
		$engine = $this->macwhisper();
		$engine->apply( 'local' );

		$this->assertSame( 'noop', $engine->apply( 'local' )->status );
	}

	public function testApplyFailsWithoutMutatingWhenTheTargetStoreIsMissing(): void {
		$this->makeLocalStore();
		$engine = $this->macwhisper();
		$engine->apply( 'local' );

		$result = $engine->apply( 'external' );

		$this->assertSame( 'failed', $result->status );
		$this->assertSame( 'local', $engine->currentLocation(), 'a failed apply must leave the symlink alone' );
	}

	public function testDryRunReportsWithoutTouchingTheSymlink(): void {
		$this->makeLocalStore();
		$engine = $this->macwhisper();

		$result = $engine->apply( 'local', true );

		$this->assertSame( 'would-apply', $result->status );
		$this->assertFalse( is_link( $engine->symlinkPath() ) );
	}

	public function testApplyRefusesWhenTheSymlinkPathIsARealDirectory(): void {
		$this->makeLocalStore();
		$models = $this->home . '/Library/Application Support/MacWhisper/models';
		mkdir( $models, 0777, true );
		file_put_contents( $models . '/keep-me', 'real user data' );

		$this->assertSame( 'failed', $this->macwhisper()->apply( 'local' )->status );
		$this->assertFileExists( $models . '/keep-me' );
	}

	public function testCurrentLocationIsNullWhenNothingIsLinkedYet(): void {
		$this->assertNull( $this->macwhisper()->currentLocation() );
	}

	public function testCurrentLocationResolvesTheExternalStore(): void {
		$this->makeLocalStore();
		$this->mountExternal();
		$engine = $this->macwhisper();
		$engine->apply( 'external' );

		$this->assertSame( 'external', $engine->currentLocation() );
	}

	// --- registry ------------------------------------------------------------

	public function testRegistryExposesBothEnginesByName(): void {
		$names = array_map(
			static fn( $engine ): string => $engine->name(),
			( new EngineRegistry( $this->home, $this->volumes ) )->engines()
		);

		$this->assertSame( [ 'ollama', 'macwhisper' ], $names );
	}
}
