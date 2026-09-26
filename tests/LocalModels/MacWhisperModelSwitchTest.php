<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\MacWhisperEngine;
use JT\LocalModels\MacWhisperPrefs;
use JT\Tests\TestCase;

/**
 * Switching MacWhisper's selected model to one the new store actually holds.
 *
 * The store flip alone is not enough: MacWhisper remembers a model id, not a
 * store, so falling back to the local store with large-v3 still selected fails
 * with "WhisperKit Model was not found at expected location". The per-location
 * model comes from the shared local-model config (WHISPER_MODEL_LOCAL /
 * WHISPER_MODEL_EXTERNAL), and it is written while the app is quit — a running
 * MacWhisper would overwrite the preference with its in-memory value.
 */
final class MacWhisperModelSwitchTest extends TestCase {

	private const SELECTED = '{"engine":{"whisperKit":{"model":{"id":"openai_whisper-large-v3-v20240930"}}},"language":{"specific":"en"}}';
	private const LIVE     = '{"engine":{"whisperKit":{"model":{"id":"openai_whisper-large-v3-v20240930"}}},"language":{"specific":"en"}}';

	private string $home = '';
	private string $volumes = '';

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		$this->bundle( $this->home . '/.macwhisper-local-models', 'openai_whisper-small' );
		$this->bundle( $this->volumes . '/AI-LAB/macwhisper/models', 'openai_whisper-large-v3-v20240930' );
		$this->bundle( $this->volumes . '/AI-LAB/macwhisper/models', 'openai_whisper-small' );
	}

	private function bundle( string $store, string $id ): void {
		mkdir( $store . '/whisperkit/models/argmaxinc/whisperkit-coreml/' . $id . '/AudioEncoder.mlmodelc', 0777, true );
	}

	private function config( string $body ): void {
		mkdir( $this->home . '/.config/auto-commit-ollama', 0777, true );
		file_put_contents( $this->home . '/.config/auto-commit-ollama/config', $body );
	}

	private function prefs(): FakeMacWhisperPrefs {
		return new FakeMacWhisperPrefs( [
			MacWhisperPrefs::SELECTED  => self::SELECTED,
			MacWhisperPrefs::DICTATION => self::SELECTED,
			MacWhisperPrefs::LIVE      => self::LIVE,
		] );
	}

	private function engine( FakeAppControl $apps, FakeMacWhisperPrefs $prefs ): MacWhisperEngine {
		return new MacWhisperEngine( $this->home, $this->volumes, $apps, $prefs );
	}

	private function modelId( FakeMacWhisperPrefs $prefs, string $key ): ?string {
		return json_decode( (string) $prefs->values[ $key ], true )['engine']['whisperKit']['model']['id'] ?? null;
	}

	public function testWritesTheLocalModelBetweenQuitAndReopen(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: true, cpu: 0.1 );
		$prefs = $this->prefs();
		$prefs->apps = $apps;

		$result = $this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertSame( 'applied', $result->status );
		$this->assertSame(
			[
				'quit:MacWhisper',
				'wait:MacWhisper',
				'set:' . MacWhisperPrefs::SELECTED,
				'set:' . MacWhisperPrefs::DICTATION,
				'reopen:MacWhisper',
			],
			$apps->calls
		);
		$this->assertSame( 'openai_whisper-small', $this->modelId( $prefs, MacWhisperPrefs::SELECTED ) );
		$this->assertSame( 'openai_whisper-small', $this->modelId( $prefs, MacWhisperPrefs::DICTATION ) );
		$this->assertSame(
			[ 'specific' => 'en' ],
			json_decode( $prefs->values[ MacWhisperPrefs::SELECTED ], true )['language'],
			'only the model changes; the rest of the runner config survives'
		);
		$this->assertStringContainsString( 'openai_whisper-small', implode( ' ', $result->warnings ) );
	}

	public function testUsesTheExternalKeyWhenFlippingToTheDrive(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\nWHISPER_MODEL_EXTERNAL=openai_whisper-large-v3-v20240930\n" );
		$apps  = new FakeAppControl( running: false );
		$prefs = new FakeMacWhisperPrefs( [
			MacWhisperPrefs::SELECTED  => str_replace( 'openai_whisper-large-v3-v20240930', 'openai_whisper-small', self::SELECTED ),
			MacWhisperPrefs::DICTATION => str_replace( 'openai_whisper-large-v3-v20240930', 'openai_whisper-small', self::SELECTED ),
		] );
		$engine = $this->engine( $apps, $prefs );
		$engine->apply( 'local' );

		$engine->apply( 'external' );

		$this->assertSame( 'openai_whisper-large-v3-v20240930', $this->modelId( $prefs, MacWhisperPrefs::SELECTED ) );
		$this->assertSame( 'openai_whisper-large-v3-v20240930', $this->modelId( $prefs, MacWhisperPrefs::DICTATION ) );
	}

	/** A quit app cannot overwrite the preference, so it is written straight away. */
	public function testWritesImmediatelyWhenTheAppIsNotRunning(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: false );
		$prefs = $this->prefs();

		$this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertSame( [], $apps->calls, 'nothing to quit or reopen' );
		$this->assertSame( 'openai_whisper-small', $this->modelId( $prefs, MacWhisperPrefs::SELECTED ) );
	}

	/** Live transcription keeps its own choice; only a missing model is reported. */
	public function testLeavesTheLiveRunnerAloneButWarnsWhenItsModelIsMissing(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$prefs = $this->prefs();

		$result = $this->engine( new FakeAppControl( running: false ), $prefs )->apply( 'local' );

		$this->assertSame( self::LIVE, $prefs->values[ MacWhisperPrefs::LIVE ] );
		$this->assertStringContainsString( 'live transcription', implode( ' ', $result->warnings ) );
	}

	public function testDoesNotWriteWhileTheAppIsBusy(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$apps  = new FakeAppControl( running: true, cpu: 42.0 );
		$prefs = $this->prefs();

		$result = $this->engine( $apps, $prefs )->apply( 'local' );

		$this->assertSame( [], $prefs->writes, 'a running app would overwrite the write anyway' );
		$this->assertStringContainsString( 'busy', implode( ' ', $result->warnings ) );
		$this->assertStringContainsString( 'openai_whisper-large-v3-v20240930', implode( ' ', $result->warnings ) );
	}

	public function testRefusesAConfiguredModelTheStoreDoesNotHold(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-large-v3-v20240930\n" );
		$prefs = $this->prefs();

		$result = $this->engine( new FakeAppControl( running: false ), $prefs )->apply( 'local' );

		$this->assertSame( [], $prefs->writes );
		$this->assertStringContainsString( 'WHISPER_MODEL_LOCAL', implode( ' ', $result->warnings ) );
	}

	/** Without a configured model, the flip still says why MacWhisper will complain. */
	public function testWarnsAboutAMissingSelectedModelWhenNothingIsConfigured(): void {
		$prefs = $this->prefs();

		$result = $this->engine( new FakeAppControl( running: false ), $prefs )->apply( 'local' );

		$this->assertSame( [], $prefs->writes );
		$warnings = implode( ' ', $result->warnings );
		$this->assertStringContainsString( 'openai_whisper-large-v3-v20240930', $warnings );
		$this->assertStringContainsString( 'WHISPER_MODEL_LOCAL', $warnings );
	}

	public function testANoopFlipWritesNothing(): void {
		$this->config( "WHISPER_MODEL_LOCAL=openai_whisper-small\n" );
		$prefs  = $this->prefs();
		$engine = $this->engine( new FakeAppControl( running: false ), $prefs );
		$engine->apply( 'local' );
		$prefs->writes = [];

		$engine->apply( 'local' );

		$this->assertSame( [], $prefs->writes );
	}
}

/**
 * In-memory MacWhisper preferences. No test may write the real domain.
 */
final class FakeMacWhisperPrefs extends MacWhisperPrefs {

	/** @var string[] */
	public array $writes = [];

	/** Shares the app fake's call log so ordering against quit/reopen is testable. */
	public ?FakeAppControl $apps = null;

	/** @param array<string, string> $values */
	public function __construct( public array $values = [] ) {
	}

	public function get( string $key ): ?string {
		return $this->values[ $key ] ?? null;
	}

	public function set( string $key, string $value ): bool {
		$this->writes[]         = $key;
		$this->values[ $key ]   = $value;
		if ( null !== $this->apps ) {
			$this->apps->calls[] = 'set:' . $key;
		}

		return true;
	}
}
