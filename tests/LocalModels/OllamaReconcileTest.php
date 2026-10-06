<?php
namespace JT\Tests\LocalModels;

use JT\AiModelsCommand;
use JT\LocalModels\ApplyResult;
use JT\LocalModels\ModelNotes;
use JT\LocalModels\OllamaEngine;
use JT\Tests\TestCase;

/**
 * Ollama reconcile: mirror local-primary models into the AI-LAB tree so the
 * external store sees every model — the manifest as a copy, the blobs as
 * symlinks.
 *
 * Direction is strictly local -> external; the reverse would dangle the moment
 * the drive ejects. Anything the external store already holds as a real file —
 * its own copy of a model, a layer shared with another model — is left alone.
 */
final class OllamaReconcileTest extends TestCase {

	private string $home = '';
	private string $volumes = '';
	private string $local = '';
	private string $external = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home     = $this->graveyardRoot . '/home';
		$this->volumes  = $this->graveyardRoot . '/Volumes';
		$this->local    = $this->home . '/.ollama-local-models';
		$this->external = $this->volumes . '/AI-LAB/ollama/models';
		mkdir( $this->local . '/blobs', 0777, true );
		mkdir( $this->external . '/blobs', 0777, true );
	}

	private function engine(): OllamaEngine {
		return new OllamaEngine( $this->home, $this->volumes );
	}

	/**
	 * A model whose manifest names a config blob and one layer blob per entry
	 * in $layers, written into $store.
	 *
	 * @param string[] $layers
	 */
	private function seedModel( string $store, string $model, string $tag, array $layers ): string {
		$dir = $store . '/manifests/registry.ollama.ai/library/' . $model;
		@mkdir( $dir, 0777, true );
		@mkdir( $store . '/blobs', 0777, true );

		$manifest = [ 'config' => [ 'digest' => 'sha256:cfg' . $model ], 'layers' => [] ];
		file_put_contents( $store . '/blobs/sha256-cfg' . $model, 'config' );
		foreach ( $layers as $layer ) {
			$manifest['layers'][] = [ 'digest' => 'sha256:' . $layer ];
			file_put_contents( $store . '/blobs/sha256-' . $layer, 'weights ' . $layer );
		}
		file_put_contents( $dir . '/' . $tag, (string) json_encode( $manifest ) );

		return $dir . '/' . $tag;
	}

	private function manifestIn( string $store, string $model, string $tag ): string {
		return $store . '/manifests/registry.ollama.ai/library/' . $model . '/' . $tag;
	}

	private function assertManifestCopied( string $localManifest, string $externalManifest ): void {
		$this->assertFalse( is_link( $externalManifest ), 'manifest must be a real file, not a symlink' );
		$this->assertFileExists( $externalManifest );
		$this->assertSame( file_get_contents( $localManifest ), file_get_contents( $externalManifest ) );
	}

	public function testMirrorsALocalOnlyModelIntoTheExternalStore(): void {
		$manifest = $this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa', 'bbb' ] );

		$result = $this->engine()->reconcile();

		$this->assertSame( ApplyResult::APPLIED, $result->status );
		$this->assertManifestCopied( $manifest, $this->manifestIn( $this->external, 'qwen3.5', '9b' ) );
		$this->assertSame( $this->local . '/blobs/sha256-aaa', readlink( $this->external . '/blobs/sha256-aaa' ) );
		$this->assertSame( $this->local . '/blobs/sha256-bbb', readlink( $this->external . '/blobs/sha256-bbb' ) );
		$this->assertSame( $this->local . '/blobs/sha256-cfgqwen3.5', readlink( $this->external . '/blobs/sha256-cfgqwen3.5' ) );
		$this->assertStringContainsString( 'qwen3.5:9b', implode( "\n", $result->details ) );
	}

	/**
	 * Ollama 0.40 refuses a manifest that is a symlink unless it points at a
	 * sha256 blob ("manifest symlink target ... is not a sha256 blob") and drops
	 * the model from `ollama list`. Blob symlinks still load, so only the
	 * manifest — a few hundred bytes of JSON — has to be a copy.
	 */
	public function testReplacesAManifestSymlinkAnEarlierReconcileLeftWithACopy(): void {
		$manifest = $this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$target   = $this->manifestIn( $this->external, 'qwen3.5', '9b' );
		mkdir( dirname( $target ), 0777, true );
		symlink( $manifest, $target );
		symlink( $this->local . '/blobs/sha256-aaa', $this->external . '/blobs/sha256-aaa' );
		symlink( $this->local . '/blobs/sha256-cfgqwen3.5', $this->external . '/blobs/sha256-cfgqwen3.5' );

		$result = $this->engine()->reconcile();

		$this->assertSame( ApplyResult::APPLIED, $result->status );
		$this->assertManifestCopied( $manifest, $target );
		$this->assertSame( $this->local . '/blobs/sha256-aaa', readlink( $this->external . '/blobs/sha256-aaa' ) );
		$this->assertStringContainsString( 'qwen3.5:9b', implode( "\n", $result->details ) );
		$this->assertSame( ApplyResult::NOOP, $this->engine()->reconcile()->status );
	}

	/** Only a link to the matching local manifest is ours to convert. */
	public function testLeavesAManifestSymlinkThatPointsElsewhereAlone(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$target = $this->manifestIn( $this->external, 'qwen3.5', '9b' );
		mkdir( dirname( $target ), 0777, true );
		file_put_contents( $this->external . '/blobs/sha256-elsewhere', '{}' );
		symlink( $this->external . '/blobs/sha256-elsewhere', $target );

		$this->engine()->reconcile();

		$this->assertSame( $this->external . '/blobs/sha256-elsewhere', readlink( $target ) );
	}

	public function testDryRunReportsAManifestConversionWithoutMakingIt(): void {
		$manifest = $this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$target   = $this->manifestIn( $this->external, 'qwen3.5', '9b' );
		mkdir( dirname( $target ), 0777, true );
		symlink( $manifest, $target );

		$result = $this->engine()->reconcile( [ 'dry-run' => true ] );

		$this->assertSame( ApplyResult::WOULD_APPLY, $result->status );
		$this->assertStringContainsString( 'would copy', implode( "\n", $result->details ) );
		$this->assertTrue( is_link( $target ) );
	}

	/**
	 * Ollama 0.40 writes a manifest list's per-runner child under the legacy
	 * tree as `library/<runner>/<sha256 hex>`. It is not a model — the parent
	 * reaches it through the blob of the same digest — and mirroring it makes it
	 * show up in `ollama list` as one. Status must not count it either.
	 */
	public function testSkipsARunnerChildManifestNamedByDigest(): void {
		$digest = str_repeat( 'c9', 32 );
		$this->seedModel( $this->local, 'llamacpp', $digest, [ 'ccc' ] );
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );

		$result = $this->engine()->reconcile();

		$this->assertFileDoesNotExist( $this->manifestIn( $this->external, 'llamacpp', $digest ) );
		$this->assertStringNotContainsString( 'llamacpp', implode( "\n", $result->details ) );
		$names = array_column( $this->engine()->residency(), 'name' );
		$this->assertContains( 'qwen3.5:9b', $names );
		$this->assertNotContains( 'llamacpp:' . $digest, $names );
	}

	/** A layer another external model already owns is a real file: never replaced. */
	public function testLeavesABlobTheExternalStoreAlreadyHoldsAlone(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'shared', 'own' ] );
		file_put_contents( $this->external . '/blobs/sha256-shared', 'real external copy' );

		$this->engine()->reconcile();

		$this->assertFalse( is_link( $this->external . '/blobs/sha256-shared' ) );
		$this->assertSame( 'real external copy', file_get_contents( $this->external . '/blobs/sha256-shared' ) );
		$this->assertTrue( is_link( $this->external . '/blobs/sha256-own' ) );
	}

	public function testNeverTouchesAModelTheExternalStoreHasItsOwnCopyOf(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$this->seedModel( $this->external, 'qwen3.5', '9b', [ 'zzz' ] );

		$result = $this->engine()->reconcile();

		$this->assertSame( ApplyResult::NOOP, $result->status );
		$this->assertFalse( is_link( $this->manifestIn( $this->external, 'qwen3.5', '9b' ) ) );
		$this->assertFileDoesNotExist( $this->external . '/blobs/sha256-aaa' );
	}

	public function testASecondRunIsANoop(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$this->engine()->reconcile();

		$this->assertSame( ApplyResult::NOOP, $this->engine()->reconcile()->status );
	}

	public function testDryRunReportsTheLinksWithoutCreatingThem(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );

		$result = $this->engine()->reconcile( [ 'dry-run' => true ] );

		$this->assertSame( ApplyResult::WOULD_APPLY, $result->status );
		$details = implode( "\n", $result->details );
		$this->assertStringContainsString( 'would copy', $details );
		$this->assertStringContainsString( 'would link', $details );
		$this->assertFileDoesNotExist( $this->manifestIn( $this->external, 'qwen3.5', '9b' ) );
		$this->assertFileDoesNotExist( $this->external . '/blobs/sha256-aaa' );
	}

	public function testFailsWhenTheExternalStoreIsNotMounted(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		rmdir( $this->external . '/blobs' );
		rmdir( $this->external );

		$result = $this->engine()->reconcile();

		$this->assertSame( ApplyResult::FAILED, $result->status );
		$this->assertStringContainsString( 'mount AI-LAB', $result->message );
	}

	public function testAnUnreadableManifestOrMissingBlobIsWarnedAboutNotFatal(): void {
		$dir = $this->local . '/manifests/registry.ollama.ai/library/broken';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/latest', 'not json' );
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		unlink( $this->local . '/blobs/sha256-aaa' );

		$result = $this->engine()->reconcile();

		$this->assertTrue( $result->ok() );
		$warnings = implode( "\n", $result->warnings );
		$this->assertStringContainsString( 'broken:latest', $warnings );
		$this->assertStringContainsString( 'sha256-aaa', $warnings );
	}

	/**
	 * Once a model is linked into AI-LAB it lives in both stores, so a note that
	 * says "local" is now wrong. Written through ModelNotes, never raw JSON, so
	 * the engine-qualified keys and legacy migration stay in one place.
	 */
	public function testPromotesALocalNoteToBothThroughModelNotes(): void {
		$notes = new ModelNotes( ModelNotes::defaultPath( $this->home ) );
		$notes->set( 'ollama:qwen3.5:9b', [ 'when' => 'Default', 'location' => 'local' ] );
		$notes->set( 'ollama:gemma4:e4b', [ 'when' => 'Tiny', 'location' => 'external' ] );
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$this->seedModel( $this->local, 'gemma4', 'e4b', [ 'ggg' ] );

		$result = $this->engine()->reconcile();

		$reread = new ModelNotes( ModelNotes::defaultPath( $this->home ) );
		$this->assertSame( 'both', $reread->note( 'ollama:qwen3.5:9b' )['location'] );
		$this->assertSame( 'Default', $reread->note( 'ollama:qwen3.5:9b' )['when'] );
		$this->assertSame( 'external', $reread->note( 'ollama:gemma4:e4b' )['location'] );
		$this->assertStringContainsString( 'local -> both', implode( "\n", $result->details ) );
	}

	public function testDryRunPromotesNothing(): void {
		$notes = new ModelNotes( ModelNotes::defaultPath( $this->home ) );
		$notes->set( 'ollama:qwen3.5:9b', [ 'location' => 'local' ] );
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );

		$this->engine()->reconcile( [ 'dry-run' => true ] );

		$this->assertSame( 'local', ( new ModelNotes( ModelNotes::defaultPath( $this->home ) ) )->note( 'ollama:qwen3.5:9b' )['location'] );
	}

	// --- auto-reconcile on the flip to AI-LAB ------------------------------------

	/**
	 * A model pulled while the drive was ejected is local-only. The flip back to
	 * AI-LAB — the watcher's mount edge, or a manual `aimodels ollama sd` — is
	 * when the external store should start seeing it.
	 */
	public function testTheFlipToExternalReconcilesLocalOnlyModels(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$this->engine()->apply( 'local' );

		$result = $this->engine()->apply( 'external' );

		$this->assertSame( ApplyResult::APPLIED, $result->status );
		$this->assertManifestCopied( $this->manifestIn( $this->local, 'qwen3.5', '9b' ), $this->manifestIn( $this->external, 'qwen3.5', '9b' ) );
		$this->assertStringContainsString( 'reconciled', implode( "\n", $result->warnings ) );
	}

	public function testTheWatcherMountEdgeReconciles(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$this->engine()->apply( 'local' );

		( new \JT\LocalModels\Watcher( $this->home, $this->volumes ) )->applyAll();

		$this->assertManifestCopied( $this->manifestIn( $this->local, 'qwen3.5', '9b' ), $this->manifestIn( $this->external, 'qwen3.5', '9b' ) );
	}

	public function testANoopOrDryRunFlipDoesNotReconcile(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$this->engine()->apply( 'local' );
		$this->engine()->apply( 'external', true );
		$this->assertFileDoesNotExist( $this->manifestIn( $this->external, 'qwen3.5', '9b' ) );

		// Already external: the flip is a noop, so nothing new to reconcile against.
		symlink( $this->external, $this->home . '/.ollama-models.tmp' );
		rename( $this->home . '/.ollama-models.tmp', $this->home . '/.ollama-models' );
		$this->engine()->apply( 'external' );
		$this->assertFileDoesNotExist( $this->manifestIn( $this->external, 'qwen3.5', '9b' ) );
	}

	public function testAFlipWithNothingToReconcileSaysNothingAboutIt(): void {
		$this->engine()->apply( 'local' );

		$result = $this->engine()->apply( 'external' );

		$this->assertStringNotContainsString( 'reconcil', implode( "\n", $result->warnings ) );
	}

	// --- bin/ollamodels shim ------------------------------------------------------

	/**
	 * Every documented ollamodels invocation, as the aimodels argv it now runs.
	 */
	public function testOllamodelsArgvMapsEveryOldInvocation(): void {
		$cases = [
			[ [ 'ollamodels' ], [ 'aimodels', 'ollama', 'auto' ] ],
			[ [ 'ollamodels', 'auto' ], [ 'aimodels', 'ollama', 'auto' ] ],
			[ [ 'ollamodels', 'sd' ], [ 'aimodels', 'ollama', 'sd' ] ],
			[ [ 'ollamodels', 'SD' ], [ 'aimodels', 'ollama', 'sd' ] ],
			[ [ 'ollamodels', 'local' ], [ 'aimodels', 'ollama', 'local' ] ],
			[ [ 'ollamodels', 'reconcile', '--dry-run' ], [ 'aimodels', 'ollama', 'reconcile', '--dry-run' ] ],
			[ [ 'ollamodels', 'reconcile', '-y' ], [ 'aimodels', 'ollama', 'reconcile' ] ],
			[ [ 'ollamodels', 'reconcile', '--yes' ], [ 'aimodels', 'ollama', 'reconcile' ] ],
			[ [ 'ollamodels', '-y', 'reconcile' ], [ 'aimodels', 'ollama', 'reconcile' ] ],
			[ [ 'ollamodels', 'help' ], [ 'aimodels', 'help', 'ollama' ] ],
			[ [ 'ollamodels', '-h' ], [ 'aimodels', 'help', 'ollama' ] ],
			[ [ 'ollamodels', '--help' ], [ 'aimodels', 'help', 'ollama' ] ],
		];

		foreach ( $cases as [ $argv, $expected ] ) {
			$this->assertSame( $expected, AiModelsCommand::ollamodelsArgv( $argv ), implode( ' ', $argv ) );
		}
	}

	/** The watcher is shared by every engine now; ollamodels must not manage it. */
	public function testOllamodelsWatcherVerbsAreRefused(): void {
		$this->assertNull( AiModelsCommand::ollamodelsArgv( [ 'ollamodels', 'installwatcher' ] ) );
		$this->assertNull( AiModelsCommand::ollamodelsArgv( [ 'ollamodels', 'removewatcher' ] ) );
	}

	public function testAimodelsOllamaReconcileRunsTheEngineReconcile(): void {
		$this->seedModel( $this->local, 'qwen3.5', '9b', [ 'aaa' ] );
		$this->cli->setArgs( [ 'aimodels', 'ollama', 'reconcile' ] );

		ob_start();
		$code = ( new \JT\CLI\Command\Dispatcher(
			$this->cli,
			new AiModelsCommand( $this->cli, $this->home, $this->volumes )
		) )->run();
		$out = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertManifestCopied( $this->manifestIn( $this->local, 'qwen3.5', '9b' ), $this->manifestIn( $this->external, 'qwen3.5', '9b' ) );
		$this->assertStringContainsString( 'qwen3.5:9b', $out );
	}
}
