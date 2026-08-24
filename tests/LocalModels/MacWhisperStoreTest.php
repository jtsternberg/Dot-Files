<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\ApplyResult;
use JT\LocalModels\MacWhisperEngine;
use JT\Tests\TestCase;

/**
 * MacWhisper's reconcile and residency, against fixtures shaped like the real
 * stores.
 *
 * The real layout, confirmed on disk, is why these rules are what they are:
 *
 *   whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-small   (weights)
 *   whisperkit/models/openai/whisper-small                              (tokenizer stub)
 *   whisperkitpro/models/argmaxinc/parakeetkit-pro/nvidia_parakeet-v3   (weights, NO config.json)
 *   argmaxinc/whisperkit-coreml/config.json                             (registry catalog)
 *   ggml-model-whisper-small.en.bin                                     (whisper-cpp)
 *
 * A model is identified by containing *.mlmodelc, not by containing config.json:
 * the parakeet bundle has no config.json, and the registry catalogs have nothing
 * but one. Depth varies between frameworks, so the rule cannot key off depth.
 */
final class MacWhisperStoreTest extends TestCase {

	private string $home = '';
	private string $volumes = '';
	private string $local = '';
	private string $external = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home     = $this->graveyardRoot . '/home';
		$this->volumes  = $this->graveyardRoot . '/Volumes';
		$this->local    = $this->home . '/.macwhisper-local-models';
		$this->external = $this->volumes . '/AI-LAB/macwhisper/models';
		mkdir( $this->local, 0777, true );
		mkdir( $this->external, 0777, true );
	}

	private function engine(): MacWhisperEngine {
		return new MacWhisperEngine( $this->home, $this->volumes );
	}

	/** A weights bundle: at least one *.mlmodelc directory. */
	private function weights( string $store, string $relative ): void {
		mkdir( $store . '/' . $relative . '/AudioEncoder.mlmodelc', 0777, true );
		file_put_contents( $store . '/' . $relative . '/AudioEncoder.mlmodelc/model.bin', 'weights' );
	}

	/** A tokenizer stub: json only, no weights. */
	private function stub( string $store, string $relative ): void {
		mkdir( $store . '/' . $relative, 0777, true );
		file_put_contents( $store . '/' . $relative . '/config.json', '{}' );
		file_put_contents( $store . '/' . $relative . '/tokenizer.json', '{}' );
	}

	private function seedLocal(): void {
		$this->weights( $this->local, 'whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-small' );
		$this->stub( $this->local, 'whisperkit/models/openai/whisper-small' );
		mkdir( $this->local . '/argmaxinc/whisperkit-coreml', 0777, true );
		file_put_contents( $this->local . '/argmaxinc/whisperkit-coreml/config.json', '{}' );
		mkdir( $this->local . '/speakerkit/speaker_embedder', 0777, true );
		file_put_contents( $this->local . '/speakerkit/speaker_embedder/model', 'x' );
		file_put_contents( $this->local . '/ggml-model-whisper-small.en.bin', 'bin' );
	}

	private function seedExternalAsSuperset(): void {
		$this->weights( $this->external, 'whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-small' );
		$this->weights( $this->external, 'whisperkitpro/models/argmaxinc/parakeetkit-pro/nvidia_parakeet-v3' );
		$this->stub( $this->external, 'whisperkit/models/openai/whisper-small' );
		// The real external store carries the registry catalogs too; a superset
		// fixture that omits them makes reconcile look busy when it is idle.
		mkdir( $this->external . '/argmaxinc/whisperkit-coreml', 0777, true );
		file_put_contents( $this->external . '/argmaxinc/whisperkit-coreml/config.json', '{}' );
		mkdir( $this->external . '/speakerkit/speaker_embedder', 0777, true );
		file_put_contents( $this->external . '/speakerkit/speaker_embedder/model', 'x' );
		file_put_contents( $this->external . '/ggml-model-whisper-small.en.bin', 'bin' );
	}

	// --- residency ------------------------------------------------------------

	public function testResidencyFindsWeightsBundlesByMlmodelcNotConfigJson(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();

		$names = array_column( $this->engine()->residency(), 'name' );

		$this->assertContains( 'openai_whisper-small', $names );
		$this->assertContains( 'nvidia_parakeet-v3', $names, 'the parakeet bundle has no config.json' );
	}

	public function testResidencyExcludesTokenizerStubsAndRegistryCatalogs(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();

		$names = array_column( $this->engine()->residency(), 'name' );

		$this->assertNotContains( 'whisper-small', $names, 'json-only stub is not a model' );
		$this->assertNotContains( 'whisperkit-coreml', $names, 'registry catalog is not a model' );
	}

	public function testResidencyReportsWhisperCppBinsAsModels(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();

		$row = $this->rowFor( 'ggml-model-whisper-small.en' );

		$this->assertSame( 'whisper-cpp', $row['framework'] );
		$this->assertTrue( $row['local'] );
		$this->assertTrue( $row['external'] );
	}

	public function testResidencyMarksExternalOnlyModels(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();

		$row = $this->rowFor( 'nvidia_parakeet-v3' );

		$this->assertFalse( $row['local'] );
		$this->assertTrue( $row['external'] );
	}

	public function testResidencyMarksAvailabilityFromTheActiveStore(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		$engine = $this->engine();
		$engine->apply( 'local' );

		$rows = [];
		foreach ( $engine->residency() as $row ) {
			$rows[ $row['name'] ] = $row;
		}

		$this->assertTrue( $rows['openai_whisper-small']['available'] );
		$this->assertFalse(
			$rows['nvidia_parakeet-v3']['available'],
			'an external-only model is not available while local is active'
		);
	}

	public function testResidencyReportsDiarizationSupportPresence(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();

		$support = array_values( array_filter(
			$this->engine()->residency(),
			static fn( array $row ): bool => 'support' === $row['kind']
		) );

		$this->assertNotEmpty( $support );
		$this->assertSame( 'speakerkit', $support[0]['name'] );
		$this->assertTrue( $support[0]['local'], 'diarization must be offline-safe' );
	}

	/**
	 * Both stores carry .cache/huggingface/download/<model>/ scaffolding that
	 * mirrors the bundle shape, *.mlmodelc entries and all. Counting it as the
	 * model is not a cosmetic bug: the local store holds a download stub for
	 * large-v3 while holding none of its weights, so treating the stub as the
	 * model reports an external-only model as offline-safe.
	 */
	public function testResidencyIgnoresHuggingfaceDownloadCacheStubs(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		$this->weights(
			$this->local,
			'whisperkit/models/argmaxinc/whisperkit-coreml/.cache/huggingface/download/nvidia_parakeet-v3'
		);

		$row = $this->rowFor( 'nvidia_parakeet-v3' );

		$this->assertFalse( $row['local'], 'a download cache stub is not the model' );
	}

	public function testResidencyDoesNotReportSpeakerkitInternalsAsModels(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		$this->weights( $this->local, 'speakerkit/speaker_embedder/pyannote-v3-pro/W8A16' );
		$this->weights( $this->external, 'speakerkit/speaker_embedder/pyannote-v3-pro/W8A16' );

		$names = array_column( $this->engine()->residency(), 'name' );

		$this->assertNotContains( 'W8A16', $names );
		$this->assertContains( 'speakerkit', $names, 'still reported, as one support row' );
	}

	public function testResidencySizeComesFromTheRealBundleNotASameNamedStub(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		file_put_contents(
			$this->external . '/whisperkitpro/models/argmaxinc/parakeetkit-pro/nvidia_parakeet-v3/AudioEncoder.mlmodelc/model.bin',
			str_repeat( 'w', 3 * 1048576 )
		);
		// An empty same-named stub must not win.
		mkdir(
			$this->external . '/whisperkitpro/models/argmaxinc/parakeetkit-pro/.cache/huggingface/download/nvidia_parakeet-v3/AudioEncoder.mlmodelc',
			0777,
			true
		);

		$this->assertSame( 3, $this->rowFor( 'nvidia_parakeet-v3' )['sizeMb'] );
	}

	private function rowFor( string $name ): array {
		foreach ( $this->engine()->residency() as $row ) {
			if ( $row['name'] === $name ) {
				return $row;
			}
		}

		$this->fail( "no residency row for {$name}" );
	}

	// --- reconcile ------------------------------------------------------------

	public function testReconcileCopiesEntriesStrandedInTheLocalStore(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		// A model downloaded while the local store was active.
		$this->weights( $this->local, 'whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-fresh' );

		$result = $this->engine()->reconcile();

		$this->assertSame( ApplyResult::APPLIED, $result->status );
		$this->assertFileExists(
			$this->external . '/whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-fresh/AudioEncoder.mlmodelc/model.bin'
		);
	}

	public function testReconcileNeverOverwritesWhatTheExternalStoreAlreadyHas(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		$existing = $this->external . '/ggml-model-whisper-small.en.bin';
		file_put_contents( $existing, 'AUTHORITATIVE' );
		file_put_contents( $this->local . '/ggml-model-whisper-small.en.bin', 'divergent' );

		$this->engine()->reconcile();

		$this->assertSame( 'AUTHORITATIVE', file_get_contents( $existing ) );
	}

	public function testReconcileIsANoopWhenTheExternalSupersetIsComplete(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();

		$this->assertSame( ApplyResult::NOOP, $this->engine()->reconcile()->status );
	}

	public function testReconcileDryRunReportsWithoutCopying(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		$this->weights( $this->local, 'whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-fresh' );

		$result = $this->engine()->reconcile( [ 'dry-run' => true ] );

		$this->assertSame( ApplyResult::WOULD_APPLY, $result->status );
		$this->assertNotEmpty( $result->details );
		$this->assertFileDoesNotExist(
			$this->external . '/whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-fresh'
		);
	}

	public function testReconcileFailsWhenTheExternalStoreIsAbsent(): void {
		$this->seedLocal();
		$this->rmdirTree( $this->external );

		$result = $this->engine()->reconcile();

		$this->assertSame( ApplyResult::FAILED, $result->status );
	}

	public function testReconcileIgnoresFinderMetadata(): void {
		$this->seedLocal();
		$this->seedExternalAsSuperset();
		file_put_contents( $this->local . '/.DS_Store', 'junk' );

		$this->engine()->reconcile();

		$this->assertFileDoesNotExist( $this->external . '/.DS_Store' );
	}

	/**
	 * ditto preserves macOS metadata; Linux has no ditto, and this repo runs on both.
	 */
	public function testCopyCommandIsPlatformAppropriate(): void {
		$engine = $this->engine();

		$this->assertStringContainsString( 'ditto', $engine->copyCommand( '/a', '/b', 'Darwin' ) );
		$this->assertStringContainsString( 'cp -a', $engine->copyCommand( '/a', '/b', 'Linux' ) );
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
