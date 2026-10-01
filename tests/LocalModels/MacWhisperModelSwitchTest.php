<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\MacWhisperCli;
use JT\LocalModels\MacWhisperEngine;
use JT\LocalModels\MacWhisperPrefs;
use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Switching MacWhisper's selected model to one the new store actually holds.
 *
 * The store flip alone is not enough: MacWhisper remembers a model id, not a
 * store, so falling back to the local store with large-v3 (or Qwen3-ASR) still
 * selected fails in the app. The per-location model comes from the shared
 * local-model config (WHISPER_MODEL_LOCAL / WHISPER_MODEL_EXTERNAL) as an
 * `mw models list` id; a bare id means WhisperKit, the config's original form.
 *
 * Two selection paths, both observed against the live app:
 *   - running: after the restart, `mw models select`, which validates against
 *     the list the app cached at launch — hence after, never before.
 *   - quit: a direct prefs write, because `mw models select` launches a quit app.
 *     Only engine keys seen in real prefs are written.
 */
final class MacWhisperModelSwitchTest extends TestCase {

	private const LARGE = 'openai_whisper-large-v3-v20240930';
	private const QWEN  = 'qwen3-asr:qwen3-asr-1.7b';

	private string $home = '';
	private string $volumes = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		$local         = $this->home . '/.macwhisper-local-models';
		$external      = $this->volumes . '/AI-LAB/macwhisper/models';

		$this->whisperKit( $local, 'openai_whisper-small' );
		$this->whisperKit( $external, self::LARGE );
		$this->whisperKit( $external, 'openai_whisper-small' );
		mkdir( $external . '/whisperkitpro/models/argmaxinc/qwenasrkit-pro/qwen3-asr/text_decoder/1.7b/TextDecoderC0.mlmodelc', 0777, true );
		mkdir( $external . '/whisperkitpro/models/argmaxinc/parakeetkit-pro/nvidia_parakeet-v3/AudioEncoder.mlmodelc', 0777, true );
		touch( $external . '/ggml-model-whisper-base.en.bin' );
	}

	private function whisperKit( string $store, string $id ): void {
		mkdir( $store . '/whisperkit/models/argmaxinc/whisperkit-coreml/' . $id . '/AudioEncoder.mlmodelc', 0777, true );
	}

	private function config( string $body ): void {
		mkdir( $this->home . '/.config/ai-tooling', 0777, true );
		file_put_contents( $this->home . '/.config/ai-tooling/config', $body );
	}

	private static function runner( string $engineKey, string $id ): string {
		return json_encode( [ 'engine' => [ $engineKey => [ 'model' => [ 'id' => $id ] ] ], 'language' => [ 'specific' => 'en' ] ] );
	}

	/** Every mode on the given runner config, as after a selection in the app. */
	private function prefs( string $runner ): FakeMacWhisperPrefs {
		return new FakeMacWhisperPrefs( [
			MacWhisperPrefs::SELECTED  => $runner,
			MacWhisperPrefs::DICTATION => $runner,
			MacWhisperPrefs::LIVE      => $runner,
		] );
	}

	private function engine( FakeAppControl $apps, FakeMacWhisperPrefs $prefs, ?FakeMacWhisperCli $mw = null ): MacWhisperEngine {
		return new MacWhisperEngine( $this->home, $this->volumes, $apps, $prefs, $mw ?: new FakeMacWhisperCli( $prefs, $apps ) );
	}

	/** @return array{0: ?string, 1: ?string} engine key and model id */
	private function selection( FakeMacWhisperPrefs $prefs, string $key ): array {
		$engine = json_decode( (string) $prefs->values[ $key ], true )['engine'] ?? [];
		$name   = array_key_first( $engine );

		return [ $name, $engine[ $name ]['model']['id'] ?? null ];
	}

	private function assertAllModes( FakeMacWhisperPrefs $prefs, string $engineKey, string $id ): void {
		foreach ( [ MacWhisperPrefs::SELECTED, MacWhisperPrefs::DICTATION, MacWhisperPrefs::LIVE ] as $mode ) {
			$this->assertSame( [ $engineKey, $id ], $this->selection( $prefs, $mode ), $mode );
		}
	}

	public function testARunningAppIsSelectedThroughMwAfterItRelaunches(): void {
		$this->config( "WHISPER_MODEL_LOCAL=whisperkit:openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: true, cpu: 0.1 );
		$prefs = $this->prefs( self::runner( 'qwenASRKitPro', 'qwen3-asr-1.7b' ) );

		$result = $this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertSame( 'applied', $result->status );
		$this->assertSame(
			[ 'quit:MacWhisper', 'wait:MacWhisper', 'reopen:MacWhisper', 'select:whisperkit:openai_whisper-small' ],
			$apps->calls,
			'mw validates against the list the app caches at launch, so it runs after the reopen'
		);
		$this->assertSame( [], $prefs->writes, 'mw owns the prefs while the app runs' );
		$this->assertAllModes( $prefs, 'whisperKit', 'openai_whisper-small' );
		$warnings = implode( ' ', $result->warnings );
		$this->assertStringContainsString( 'whisperkit:openai_whisper-small', $warnings );
		$this->assertStringNotContainsString( 'is not in the local store', $warnings, 'live left Qwen too' );
	}

	/** The config's original form: a bare id is a WhisperKit model. */
	public function testABareConfiguredIdIsWhisperKit(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: true, cpu: 0.1 );
		$prefs = $this->prefs( self::runner( 'whisperKit', self::LARGE ) );

		$this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertContains( 'select:whisperkit:openai_whisper-small', $apps->calls );
	}

	public function testFlippingToTheDriveRestoresAQwenChoice(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\nWHISPER_MODEL_EXTERNAL=" . self::QWEN . "\n" );
		$apps   = new FakeAppControl( running: true, cpu: 0.1 );
		$prefs  = $this->prefs( self::runner( 'qwenASRKitPro', 'qwen3-asr-1.7b' ) );
		$engine = $this->engine( $apps, $prefs );
		$engine->apply( 'local' );
		$apps->calls = [];

		$result = $engine->apply( 'external' );

		$this->assertContains( 'select:' . self::QWEN, $apps->calls );
		$this->assertAllModes( $prefs, 'qwenASRKitPro', 'qwen3-asr-1.7b' );
		$this->assertStringNotContainsString( 'is not in the external store', implode( ' ', $result->warnings ) );
	}

	public function testAFailedMwSelectWarnsAboutEveryModeLeftOnAMissingModel(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: true, cpu: 0.1 );
		$prefs = $this->prefs( self::runner( 'qwenASRKitPro', 'qwen3-asr-1.7b' ) );
		$mw    = new FakeMacWhisperCli( $prefs, $apps, succeeds: false );

		$result = $this->engine( $apps, $prefs, $mw )->apply( 'local' );

		$warnings = implode( ' ', $result->warnings );
		$this->assertStringContainsString( 'could not select whisperkit:openai_whisper-small', $warnings );
		$this->assertStringContainsString(
			'file transcription, dictation and live transcription model ' . self::QWEN . ' is not in the local store',
			$warnings
		);
	}

	/** Observed: selecting a whisper-cpp model leaves live transcription where it was. */
	public function testWarnsWhenMwLeavesLiveOnAModelTheStoreLacks(): void {
		$this->config( "WHISPER_MODEL_EXTERNAL=whisper-cpp:ggml-model-whisper-base.en\n" );
		$apps  = new FakeAppControl( running: true, cpu: 0.1 );
		$prefs = $this->prefs( self::runner( 'parakeetKitPro', 'nvidia_parakeet-missing' ) );
		$engine = $this->engine( $apps, $prefs );
		$engine->apply( 'local' );

		$result = $engine->apply( 'external' );

		$this->assertSame( [ 'whisperCPP', 'ggml-model-whisper-base.en' ], $this->selection( $prefs, MacWhisperPrefs::SELECTED ) );
		$this->assertStringContainsString(
			'live transcription model parakeet-pro:nvidia_parakeet-missing is not in the external store',
			implode( ' ', $result->warnings )
		);
	}

	/** `mw models select` launches a quit app, so a quit app gets a direct write. */
	public function testAQuitAppIsWrittenDirectlyForEveryMode(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: false );
		$prefs = $this->prefs( self::runner( 'qwenASRKitPro', 'qwen3-asr-1.7b' ) );

		$result = $this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertSame( [], $apps->calls, 'nothing quit, reopened, or launched through mw' );
		$this->assertAllModes( $prefs, 'whisperKit', 'openai_whisper-small' );
		$this->assertSame(
			[ 'specific' => 'en' ],
			json_decode( $prefs->values[ MacWhisperPrefs::LIVE ], true )['language'],
			'only the engine changes; the rest of the runner config survives'
		);
		$this->assertStringNotContainsString( 'is not in the local store', implode( ' ', $result->warnings ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function observedEngines(): array {
		return [
			'qwen3-asr'    => [ self::QWEN, 'qwenASRKitPro', 'qwen3-asr-1.7b' ],
			'parakeet-pro' => [ 'parakeet-pro:nvidia_parakeet-v3', 'parakeetKitPro', 'nvidia_parakeet-v3' ],
			'whisperkit'   => [ 'whisperkit:' . self::LARGE, 'whisperKit', self::LARGE ],
		];
	}

	#[DataProvider('observedEngines')]
	public function testAQuitAppGetsEachObservedEngineShape( string $configured, string $engineKey, string $id ): void {
		$this->config( "WHISPER_MODEL_EXTERNAL=" . $configured . "\n" );
		$prefs  = $this->prefs( self::runner( 'whisperKit', 'openai_whisper-small' ) );
		$engine = $this->engine( new FakeAppControl( running: false ), $prefs );
		$engine->apply( 'local' );

		$engine->apply( 'external' );

		$this->assertAllModes( $prefs, $engineKey, $id );
	}

	/** whisper-cpp's runner config embeds the app's whole model record; never hand-build it. */
	public function testAQuitAppIsNotWrittenForAWhisperCppModel(): void {
		$this->config( "WHISPER_MODEL_EXTERNAL=whisper-cpp:ggml-model-whisper-base.en\n" );
		$prefs  = $this->prefs( self::runner( 'whisperKit', 'openai_whisper-small' ) );
		$engine = $this->engine( new FakeAppControl( running: false ), $prefs );
		$engine->apply( 'local' );

		$result = $engine->apply( 'external' );

		$this->assertSame( [], $prefs->writes );
		$this->assertStringContainsString(
			'select whisper-cpp:ggml-model-whisper-base.en in MacWhisper',
			implode( ' ', $result->warnings )
		);
	}

	public function testDoesNotSelectWhileTheAppIsBusy(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: true, cpu: 42.0 );
		$prefs = $this->prefs( self::runner( 'whisperKit', self::LARGE ) );

		$result = $this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertSame( [], $prefs->writes, 'a running app would overwrite the write anyway' );
		$this->assertNotContains( 'select:whisperkit:openai_whisper-small', $apps->calls );
		$warnings = implode( ' ', $result->warnings );
		$this->assertStringContainsString( 'busy', $warnings );
		$this->assertStringContainsString( 'whisperkit:' . self::LARGE, $warnings );
	}

	public function testRefusesAConfiguredModelTheStoreDoesNotHold(): void {
		$this->config( "WHISPER_MODEL_LOCAL=" . self::QWEN . "\n" );
		$apps  = new FakeAppControl( running: true, cpu: 0.1 );
		$prefs = $this->prefs( self::runner( 'whisperKit', 'openai_whisper-small' ) );

		$result = $this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertSame( [], $prefs->writes );
		$this->assertNotContains( 'select:' . self::QWEN, $apps->calls );
		$this->assertStringContainsString( 'WHISPER_MODEL_LOCAL=' . self::QWEN . ' is not in the local store', implode( ' ', $result->warnings ) );
	}

	/** Without a configured model, the flip still names every mode that will break. */
	public function testWarnsAboutEveryEnginesMissingModelWhenNothingIsConfigured(): void {
		$prefs = new FakeMacWhisperPrefs( [
			MacWhisperPrefs::SELECTED  => self::runner( 'whisperKit', self::LARGE ),
			MacWhisperPrefs::DICTATION => self::runner( 'whisperKit', 'openai_whisper-small' ),
			MacWhisperPrefs::LIVE      => self::runner( 'qwenASRKitPro', 'qwen3-asr-1.7b' ),
		] );

		$result = $this->engine( new FakeAppControl( running: false ), $prefs )->apply( 'local' );

		$this->assertSame( [], $prefs->writes );
		$warnings = implode( ' ', $result->warnings );
		$this->assertStringContainsString( 'file transcription model whisperkit:' . self::LARGE . ' is not in the local store', $warnings );
		$this->assertStringContainsString( 'live transcription model ' . self::QWEN . ' is not in the local store', $warnings );
		$this->assertStringNotContainsString( 'dictation', $warnings );
		$this->assertStringContainsString( 'WHISPER_MODEL_LOCAL', $warnings );
	}

	/** A mode on an engine key never seen in real prefs can't be checked — say so. */
	public function testAnUnrecognisedEngineIsReportedAsUnchecked(): void {
		$prefs = $this->prefs( self::runner( 'appleSpeech', 'whatever' ) );

		$result = $this->engine( new FakeAppControl( running: false ), $prefs )->apply( 'local' );

		$this->assertStringContainsString( 'engine appleSpeech', implode( ' ', $result->warnings ) );
	}

	public function testANoopFlipWritesNothing(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$prefs  = $this->prefs( self::runner( 'whisperKit', self::LARGE ) );
		$engine = $this->engine( new FakeAppControl( running: false ), $prefs );
		$engine->apply( 'local' );
		$prefs->writes = [];

		$engine->apply( 'local' );

		$this->assertSame( [], $prefs->writes );
	}

	/** The real client passes the id through and reports mw's exit status. */
	public function testTheCliRunsMwModelsSelect(): void {
		$log = $this->graveyardRoot . '/mw.log';
		putenv( 'AIMODELS_MW_BIN=' . self::sharedStub(
			'mw',
			"#!/bin/sh\necho \"\$@\" >> \"\$GRAVEYARD_ROOT/mw.log\"\n[ \"\$3\" = bad:id ] && exit 1\nexit 0\n"
		) );

		$mw = new MacWhisperCli( retries: 2, retrySleepMicroseconds: 0 );

		$this->assertTrue( $mw->select( self::QWEN ) );
		$this->assertFalse( $mw->select( 'bad:id' ) );
		$this->assertSame(
			"models select " . self::QWEN . "\nmodels select bad:id\nmodels select bad:id\n",
			file_get_contents( $log ),
			'a failure is retried: the first call after a relaunch can lose the race'
		);
	}
}

/**
 * In-memory MacWhisper preferences. No test may write the real domain.
 */
final class FakeMacWhisperPrefs extends MacWhisperPrefs {

	/** @var string[] */
	public array $writes = [];

	/** @param array<string, string> $values */
	public function __construct( public array $values = [] ) {
	}

	public function get( string $key ): ?string {
		return $this->values[ $key ] ?? null;
	}

	public function set( string $key, string $value ): bool {
		$this->writes[]       = $key;
		$this->values[ $key ] = $value;

		return true;
	}
}

/**
 * Stands in for `mw models select`, reproducing what it was observed to do to
 * the prefs: every mode for WhisperKit/Qwen/Parakeet, file and dictation only
 * for whisper-cpp.
 */
final class FakeMacWhisperCli extends MacWhisperCli {

	private const ENGINE_KEYS = [
		'whisperkit'   => 'whisperKit',
		'qwen3-asr'    => 'qwenASRKitPro',
		'parakeet-pro' => 'parakeetKitPro',
		'whisper-cpp'  => 'whisperCPP',
	];

	public function __construct(
		private FakeMacWhisperPrefs $prefs,
		private FakeAppControl $apps,
		private bool $succeeds = true
	) {
	}

	public function select( string $mwId ): bool {
		$this->apps->calls[] = 'select:' . $mwId;
		if ( ! $this->succeeds ) {
			return false;
		}

		[ $prefix, $id ] = explode( ':', $mwId, 2 );
		$modes = [ MacWhisperPrefs::SELECTED, MacWhisperPrefs::DICTATION ];
		if ( 'whisper-cpp' !== $prefix ) {
			$modes[] = MacWhisperPrefs::LIVE;
		}

		foreach ( $modes as $mode ) {
			$config           = json_decode( (string) ( $this->prefs->values[ $mode ] ?? '' ), true ) ?: [];
			$config['engine'] = [ self::ENGINE_KEYS[ $prefix ] => [ 'model' => [ 'id' => $id ] ] ];
			// Written as the app would, not through set(): these are not aimodels writes.
			$this->prefs->values[ $mode ] = (string) json_encode( $config );
		}

		return true;
	}
}
