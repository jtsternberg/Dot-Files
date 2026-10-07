<?php
namespace JT\Tests\LocalModels;

use JT\AiModelsCommand;
use JT\CLI\Command\Dispatcher;
use JT\LocalModels\ModelNotes;
use JT\Tests\TestCase;

/**
 * `aimodels why`: notes for every local model, joined to the engines' inventory.
 *
 * Fixtures build both stores for both engines under a throwaway $home/$volumes,
 * and symlink the app-facing paths by hand — a real MacWhisper flip would try to
 * restart the app.
 */
final class AiModelsWhyTest extends TestCase {

	private string $home = '';
	private string $volumes = '';
	private bool $tty = false;

	protected function setUp(): void {
		parent::setUp();

		$this->home    = $this->graveyardRoot . '/home';
		$this->volumes = $this->graveyardRoot . '/Volumes';
		mkdir( $this->home . '/Library/Application Support/MacWhisper', 0777, true );
		mkdir( $this->volumes, 0777, true );
		// A phpunit run from a terminal has a tty stdin; `why` must not open fzf on it.
		$this->cli->forceInteractive = false;
	}

	protected function tearDown(): void {
		putenv( 'AIMODELS_OLLAMA_BIN' );
		parent::tearDown();
	}

	private function dispatch( array $argv ): array {
		$this->cli->setArgs( $argv );
		ob_start();
		$code = ( new Dispatcher(
			$this->cli,
			new AiModelsCommand( $this->cli, $this->home, $this->volumes, $this->tty )
		) )->run();

		return [ $code, (string) ob_get_clean() ];
	}

	private function json( array $argv ): array {
		[ $code, $out ] = $this->dispatch( $argv );
		$this->assertSame( 0, $code, $out );

		$decoded = json_decode( $out, true );
		$this->assertIsArray( $decoded, $out );

		return $decoded;
	}

	private function ollamaLocal(): string {
		return $this->home . '/.ollama-local-models';
	}

	private function ollamaExternal(): string {
		return $this->volumes . '/AI-LAB/ollama/models';
	}

	private function whisperLocal(): string {
		return $this->home . '/.macwhisper-local-models';
	}

	private function whisperExternal(): string {
		return $this->volumes . '/AI-LAB/macwhisper/models';
	}

	private function ollamaModel( string $store, string $model, string $tag ): void {
		$dir = $store . '/manifests/registry.ollama.ai/library/' . $model;
		@mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/' . $tag, '{"layers":[]}' );
	}

	private function whisperBundle( string $store, string $relative ): void {
		mkdir( $store . '/' . $relative . '/AudioEncoder.mlmodelc', 0777, true );
		file_put_contents( $store . '/' . $relative . '/AudioEncoder.mlmodelc/model.bin', 'weights' );
	}

	/**
	 * AI-LAB mounted, both engines pointed at it:
	 *   qwen3.5:9b        local + external
	 *   gemma4:26b        external only
	 *   openai_whisper-small  local + external
	 *   qwen3-asr 1.7b    external only
	 */
	private function seedMounted(): void {
		$this->ollamaModel( $this->ollamaLocal(), 'qwen3.5', '9b' );
		$this->ollamaModel( $this->ollamaExternal(), 'qwen3.5', '9b' );
		$this->ollamaModel( $this->ollamaExternal(), 'gemma4', '26b' );
		$small = 'whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-small';
		$this->whisperBundle( $this->whisperLocal(), $small );
		$this->whisperBundle( $this->whisperExternal(), $small );
		$this->whisperBundle( $this->whisperExternal(), 'whisperkitpro/models/argmaxinc/qwenasrkit-pro/qwen3-asr/text_decoder/1.7b' );

		symlink( $this->ollamaExternal(), $this->home . '/.ollama-models' );
		symlink( $this->whisperExternal(), $this->home . '/Library/Application Support/MacWhisper/models' );
	}

	/** AI-LAB ejected: only the local stores exist, both engines point at them. */
	private function seedEjected(): void {
		$this->ollamaModel( $this->ollamaLocal(), 'qwen3.5', '9b' );
		$this->whisperBundle( $this->whisperLocal(), 'whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-small' );

		symlink( $this->ollamaLocal(), $this->home . '/.ollama-models' );
		symlink( $this->whisperLocal(), $this->home . '/Library/Application Support/MacWhisper/models' );
	}

	private function rows( array $decoded ): array {
		return array_column( $decoded['models'], null, 'id' );
	}

	private function notesFile(): string {
		return ModelNotes::defaultPath( $this->home );
	}

	/**
	 * `status` and `why` are two views of one inventory. Every model status
	 * reports must appear in why with the same residency, or the two drift the
	 * way ollama-why's hardcoded store paths once drifted from aimodels.
	 */
	public function testWhyReadsTheSameInventoryAsStatus(): void {
		$this->seedMounted();

		$status = $this->json( [ 'aimodels', 'status', '--json' ] );
		$why    = $this->rows( $this->json( [ 'aimodels', 'why', '--json' ] ) );

		$seen = 0;
		foreach ( $status['engines'] as $engine ) {
			foreach ( $engine['models'] as $model ) {
				$this->assertArrayHasKey( $model['id'], $why );
				foreach ( [ 'engine', 'local', 'external', 'available', 'sizeMb' ] as $field ) {
					$this->assertSame( $model[ $field ], $why[ $model['id'] ][ $field ], "{$model['id']}.{$field}" );
				}
				$seen++;
			}
		}
		$this->assertSame( 4, $seen );
		$this->assertCount( $seen, $why, 'why lists nothing status does not, absent notes' );
	}

	public function testListIsTheDefaultAndDerivesLocationFromTheInventory(): void {
		$this->seedMounted();

		$rows = $this->rows( $this->json( [ 'aimodels', 'why', '--json' ] ) );

		$this->assertSame( 'both', $rows['ollama:qwen3.5:9b']['location'] );
		$this->assertSame( 'external', $rows['ollama:gemma4:26b']['location'] );
		$this->assertSame( 'both', $rows['whisperkit:openai_whisper-small']['location'] );
		$this->assertSame( 'external', $rows['qwen3-asr:qwen3-asr-1.7b']['location'] );
		$this->assertSame( 'available', $rows['qwen3-asr:qwen3-asr-1.7b']['state'] );
		$this->assertNull( $rows['qwen3-asr:qwen3-asr-1.7b']['note'] );
	}

	/** A stored location is only last-known; the inventory overrides it. */
	public function testInventoryBeatsAStaleStoredLocation(): void {
		$this->seedMounted();
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:gemma4:26b', [ 'when' => 'Big', 'location' => 'local' ] );

		$rows = $this->rows( $this->json( [ 'aimodels', 'why', '--json' ] ) );

		$this->assertSame( 'external', $rows['ollama:gemma4:26b']['location'] );
		$this->assertSame( 'Big', $rows['ollama:gemma4:26b']['note']['when'] );
	}

	public function testMacWhisperNotesCanBeSetListedAndGraveyarded(): void {
		$this->seedMounted();

		[ $code ] = $this->dispatch( [
			'aimodels', 'why', 'set', 'qwen3-asr:qwen3-asr-1.7b', 'Accuracy pick; drive-bound',
			'--speed=unmeasured', '--tags=asr,multilingual',
		] );
		$this->assertSame( 0, $code );

		$row = $this->rows( $this->json( [ 'aimodels', 'why', '--json' ] ) )['qwen3-asr:qwen3-asr-1.7b'];
		$this->assertSame( 'Accuracy pick; drive-bound', $row['note']['when'] );
		$this->assertSame( [ 'asr', 'multilingual' ], $row['note']['tags'] );
		$this->assertSame( 'external', $row['note']['location'], 'recorded from the inventory on set' );

		[ , $text ] = $this->dispatch( [ 'aimodels', 'why' ] );
		$this->assertStringContainsString( 'qwen3-asr:qwen3-asr-1.7b', $text );
		$this->assertStringContainsString( 'Accuracy pick; drive-bound', $text );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'rm', 'qwen3-asr:qwen3-asr-1.7b', '--delete-model', '--tested=Too slow' ] );
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( 'MacWhisper', $out, 'says the bundle itself was left alone' );

		$history = $this->json( [ 'aimodels', 'why', 'history', '--json' ] );
		$this->assertSame( 'Too slow', $history['graveyard'][0]['tested'] );
		$this->assertSame( 'qwen3-asr:qwen3-asr-1.7b', $history['graveyard'][0]['id'] );
		$this->assertDirectoryExists(
			$this->whisperExternal() . '/whisperkitpro/models/argmaxinc/qwenasrkit-pro/qwen3-asr/text_decoder/1.7b'
		);
	}

	public function testBareLegacyNamesResolveAsOllama(): void {
		$this->seedMounted();

		$this->dispatch( [ 'aimodels', 'why', 'set', 'qwen3.5:9b', 'Default chat' ] );

		$this->assertSame( 'Default chat', ( new ModelNotes( $this->notesFile() ) )->note( 'ollama:qwen3.5:9b' )['when'] );
	}

	public function testOldSdLocationIsAcceptedAsExternal(): void {
		$this->seedMounted();

		$this->dispatch( [ 'aimodels', 'why', 'set', 'gemma4:26b', '--location=sd' ] );
		$this->dispatch( [ 'aimodels', 'why', 'locate', 'qwen3.5:9b', 'sd' ] );
		$this->dispatch( [ 'aimodels', 'why', 'set', 'ollama:x:1b', '--loc=both' ] );

		$notes = new ModelNotes( $this->notesFile() );
		$this->assertSame( 'external', $notes->note( 'ollama:gemma4:26b' )['location'] );
		$this->assertSame( 'external', $notes->note( 'ollama:qwen3.5:9b' )['location'] );
		$this->assertSame( 'both', $notes->note( 'ollama:x:1b' )['location'] );
	}

	public function testInvalidLocationIsAUsageFailure(): void {
		$this->seedMounted();

		[ $code ] = $this->dispatch( [ 'aimodels', 'why', 'locate', 'qwen3.5:9b', 'moon' ] );
		[ $code2 ] = $this->dispatch( [ 'aimodels', 'why', 'set', 'qwen3.5:9b', '--location=moon' ] );

		$this->assertSame( 1, $code );
		$this->assertSame( 1, $code2 );
		$this->assertFileDoesNotExist( $this->notesFile() );
	}

	public function testDriveBoundNotesShowOfflineWhileEjected(): void {
		$this->seedEjected();
		$notes = new ModelNotes( $this->notesFile() );
		$notes->set( 'ollama:gemma4:26b', [ 'when' => 'Big', 'location' => 'external' ] );
		$notes->set( 'qwen3-asr:qwen3-asr-1.7b', [ 'when' => 'Accuracy', 'location' => 'external' ] );
		$notes->set( 'ollama:gone:1b', [ 'when' => 'Nothing', 'location' => 'local' ] );
		$notes->set( 'ollama:qwen3.5:9b', [ 'when' => 'Default', 'location' => 'both' ] );

		$rows = $this->rows( $this->json( [ 'aimodels', 'why', '--json' ] ) );

		$this->assertSame( 'offline', $rows['ollama:gemma4:26b']['state'] );
		$this->assertSame( 'offline', $rows['qwen3-asr:qwen3-asr-1.7b']['state'] );
		$this->assertSame( 'orphaned', $rows['ollama:gone:1b']['state'] );
		$this->assertSame( 'available', $rows['ollama:qwen3.5:9b']['state'] );
		// The local store cannot see the drive, so "both" comes from the note.
		$this->assertSame( 'both', $rows['ollama:qwen3.5:9b']['location'] );

		[ , $text ] = $this->dispatch( [ 'aimodels', 'why' ] );
		$this->assertStringContainsString( 'Offline', $text );
		$this->assertStringContainsString( 'Orphaned', $text );
	}

	public function testAModelOutsideTheActiveStoreIsStrandedNotOrphaned(): void {
		$this->seedMounted();
		$this->ollamaModel( $this->ollamaLocal(), 'stuck', '1b' );

		$rows = $this->rows( $this->json( [ 'aimodels', 'why', '--json' ] ) );

		$this->assertSame( 'stranded', $rows['ollama:stuck:1b']['state'] );
		$this->assertSame( 'local', $rows['ollama:stuck:1b']['location'] );
	}

	public function testEngineFilter(): void {
		$this->seedMounted();

		$ollama = $this->rows( $this->json( [ 'aimodels', 'why', '--json', '--engine=ollama' ] ) );
		$whisper = $this->rows( $this->json( [ 'aimodels', 'why', '--json', '--engine=whisper' ] ) );

		$this->assertSame( [ 'ollama' ], array_values( array_unique( array_column( $ollama, 'engine' ) ) ) );
		$this->assertSame( [ 'macwhisper' ], array_values( array_unique( array_column( $whisper, 'engine' ) ) ) );
	}

	public function testDeleteModelRunsOllamaRmForOllamaModels(): void {
		$this->seedMounted();
		$log  = $this->graveyardRoot . '/ollama-calls';
		putenv( 'AIMODELS_OLLAMA_BIN=' . self::sharedStub( 'ollama', "#!/bin/sh\necho \"\$@\" >> \"\$GRAVEYARD_ROOT/ollama-calls\"\n" ) );
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:gemma4:26b', [ 'when' => 'Big' ] );

		[ $code ] = $this->dispatch( [ 'aimodels', 'why', 'rm', 'gemma4:26b', '--delete-model' ] );

		$this->assertSame( 0, $code );
		$this->assertSame( "rm gemma4:26b\n", file_get_contents( $log ) );
		$this->assertArrayHasKey( 'ollama:gemma4:26b', ( new ModelNotes( $this->notesFile() ) )->data()['graveyard'] );
	}

	/**
	 * `tested` is a dated history, one result per run. Replacing it by default
	 * once wiped a model's whole history, so --tested appends with ` | `.
	 */
	public function testSetTestedAppendsToTheExistingHistory(): void {
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [ 'tested' => '2026-09-30: 92% holdout' ] );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'set', 'qwen3.5:9b', '--tested=2026-10-06: cold 9.5s' ] );

		$this->assertSame( 0, $code, $out );
		$this->assertSame(
			'2026-09-30: 92% holdout | 2026-10-06: cold 9.5s',
			( new ModelNotes( $this->notesFile() ) )->note( 'ollama:qwen3.5:9b' )['tested']
		);
	}

	public function testSetTestedOnAnEmptyFieldJustSetsIt(): void {
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [ 'when' => 'Default' ] );

		$this->dispatch( [ 'aimodels', 'why', 'set', 'qwen3.5:9b', '--tested=first run' ] );

		$this->assertSame( 'first run', ( new ModelNotes( $this->notesFile() ) )->note( 'ollama:qwen3.5:9b' )['tested'] );
	}

	/** Re-running the same command must not stack a duplicate entry. */
	public function testSetTestedDoesNotAppendAnEntryAlreadyRecorded(): void {
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [ 'tested' => 'a | b' ] );

		$this->dispatch( [ 'aimodels', 'why', 'set', 'qwen3.5:9b', '--tested=b' ] );

		$this->assertSame( 'a | b', ( new ModelNotes( $this->notesFile() ) )->note( 'ollama:qwen3.5:9b' )['tested'] );
	}

	public function testReplaceTestedOverwritesTheHistory(): void {
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [ 'tested' => 'old' ] );

		$this->dispatch( [ 'aimodels', 'why', 'set', 'qwen3.5:9b', '--tested=new', '--replace-tested' ] );

		$this->assertSame( 'new', ( new ModelNotes( $this->notesFile() ) )->note( 'ollama:qwen3.5:9b' )['tested'] );
	}

	public function testRmTestedAppendsTheRemovalReasonToTheHistory(): void {
		$this->seedMounted();
		putenv( 'AIMODELS_OLLAMA_BIN=' . self::sharedStub( 'ollama', "#!/bin/sh\nexit 0\n" ) );
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:gemma4:26b', [ 'tested' => '2026-10-01: 80%' ] );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'rm', 'gemma4:26b', '--delete-model', '--tested=removed: too slow' ] );

		$this->assertSame( 0, $code, $out );
		$this->assertSame(
			'2026-10-01: 80% | removed: too slow',
			( new ModelNotes( $this->notesFile() ) )->buried( 'ollama:gemma4:26b' )['tested']
		);
	}

	public function testRmReplaceTestedOverwritesTheHistory(): void {
		$this->seedMounted();
		putenv( 'AIMODELS_OLLAMA_BIN=' . self::sharedStub( 'ollama', "#!/bin/sh\nexit 0\n" ) );
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:gemma4:26b', [ 'tested' => 'old' ] );

		$this->dispatch( [ 'aimodels', 'why', 'rm', 'gemma4:26b', '--delete-model', '--tested=only this', '--replace-tested' ] );

		$this->assertSame( 'only this', ( new ModelNotes( $this->notesFile() ) )->buried( 'ollama:gemma4:26b' )['tested'] );
	}

	public function testHelpSaysTestedAppends(): void {
		[ , $out ] = $this->dispatch( [ 'aimodels', 'why', '--help' ] );

		$this->assertStringContainsString( '--replace-tested', $out );
		$this->assertMatchesRegularExpression( '/--tested=<tested>\]?\s+.*[Aa]ppend/', $out );
	}

	public function testRmWithoutANoteOrDeleteIsAFailure(): void {
		[ $code ] = $this->dispatch( [ 'aimodels', 'why', 'rm', 'nothing:1b' ] );

		$this->assertSame( 1, $code );
	}

	public function testForgetAndHistory(): void {
		$notes = new ModelNotes( $this->notesFile() );
		$notes->remove( 'ollama:gone:1b', true, 'Bad', '2026-09-01' );

		[ , $history ] = $this->dispatch( [ 'aimodels', 'why', 'history' ] );
		$this->assertStringContainsString( 'gone:1b', $history );
		$this->assertStringContainsString( 'Bad', $history );

		[ $code ] = $this->dispatch( [ 'aimodels', 'why', 'forget', 'gone:1b' ] );
		[ $again ] = $this->dispatch( [ 'aimodels', 'why', 'forget', 'gone:1b' ] );

		$this->assertSame( 0, $code );
		$this->assertSame( 1, $again );
	}

	public function testGraveyardAppearsInTheListViewInBothForms(): void {
		( new ModelNotes( $this->notesFile() ) )->remove( 'ollama:gone:1b', true, 'Bad', '2026-09-01' );

		$json = $this->json( [ 'aimodels', 'why', '--json' ] );
		[ , $text ] = $this->dispatch( [ 'aimodels', 'why' ] );

		$this->assertSame( 'ollama:gone:1b', $json['graveyard'][0]['id'] );
		$this->assertStringContainsString( 'gone:1b', $text );
	}

	public function testPathAndNotes(): void {
		file_put_contents( $this->notesFile(), json_encode( [ 'models' => [ 'a:1b' => [ 'when' => 'x' ] ] ] ) );

		[ , $path ] = $this->dispatch( [ 'aimodels', 'why', 'path' ] );
		$notes = $this->json( [ 'aimodels', 'why', 'notes' ] );

		$this->assertSame( $this->notesFile(), trim( $path ) );
		$this->assertSame( 'x', $notes['models']['ollama:a:1b']['when'], 'notes prints the migrated shape' );
	}

	public function testLegacyFileIsBackedUpAndMigratedOnFirstWrite(): void {
		$this->seedMounted();
		$legacy = [
			'models'    => [ 'qwen3.5:9b' => [ 'when' => 'Default', 'location' => 'both' ], 'gemma4:26b' => [ 'when' => 'Big', 'location' => 'sd' ] ],
			'graveyard' => [ 'old:1b' => [ 'removed_at' => '2026-01-01', 'tested' => 'Bad' ] ],
		];
		file_put_contents( $this->notesFile(), json_encode( $legacy ) );

		$this->dispatch( [ 'aimodels', 'why', 'set', 'whisperkit:openai_whisper-small', 'Offline default' ] );

		$this->assertFileExists( $this->notesFile() . ModelNotes::BACKUP_SUFFIX );
		$saved = json_decode( file_get_contents( $this->notesFile() ), true );
		$this->assertCount( 3, $saved['models'] );
		$this->assertCount( 1, $saved['graveyard'] );
		$this->assertSame( 'external', $saved['models']['ollama:gemma4:26b']['location'] );
	}

	public function testKeysListsInventoryAndNotedIdsForCompletion(): void {
		$this->seedMounted();
		( new ModelNotes( $this->notesFile() ) )->remove( 'ollama:gone:1b', true, null, '2026-09-01' );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'keys' ] );
		$keys = explode( "\n", trim( $out ) );

		$this->assertSame( 0, $code );
		$this->assertContains( 'qwen3-asr:qwen3-asr-1.7b', $keys );
		$this->assertContains( 'qwen3.5:9b', $keys, 'Ollama keys complete bare, the way ollama-why took them' );
		$this->assertContains( 'gone:1b', $keys );
	}

	public function testUnknownWhyActionIsAUsageFailure(): void {
		[ $code ] = $this->dispatch( [ 'aimodels', 'why', 'sideways' ] );

		$this->assertSame( 1, $code );
	}

	public function testCompletionOffersWhyAndItsModelKeys(): void {
		[ , $out ] = $this->dispatch( [ 'aimodels', 'completion', 'zsh' ] );

		$this->assertStringContainsString( "'why:", $out );
		$this->assertStringContainsString( 'aimodels why keys', $out );
	}

	/**
	 * `ollama-why` is a shim: every old invocation becomes `aimodels why …`,
	 * scoped to Ollama unless it names another engine.
	 */
	public function testOllamaWhyShimForwardsToWhyScopedToOllama(): void {
		$this->assertSame(
			[ 'aimodels', 'why', 'set', 'qwen3.5:9b', 'Default', '--speed=40', '--engine=ollama' ],
			AiModelsCommand::ollamaWhyArgv( [ 'ollama-why', 'set', 'qwen3.5:9b', 'Default', '--speed=40' ] )
		);
		$this->assertSame( [ 'aimodels', 'why', '--engine=ollama' ], AiModelsCommand::ollamaWhyArgv( [ 'ollama-why' ] ) );
		$this->assertSame( [ 'aimodels', 'why', '-h', '--engine=ollama' ], AiModelsCommand::ollamaWhyArgv( [ 'ollama-why', 'help' ] ) );
		$this->assertSame(
			[ 'aimodels', 'why', '--engine=macwhisper' ],
			AiModelsCommand::ollamaWhyArgv( [ 'ollama-why', '--engine=macwhisper' ] )
		);
	}

	public function testOllamaWhyShimListsOnlyOllama(): void {
		$this->seedMounted();

		[ $code, $out ] = $this->dispatch( AiModelsCommand::ollamaWhyArgv( [ 'ollama-why' ] ) );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'qwen3.5:9b', $out );
		$this->assertStringNotContainsString( 'qwen3-asr', $out );
	}

	private static function plain( string $text ): string {
		return (string) preg_replace( '/\e\[[0-9;]*m/', '', $text );
	}

	/** `show` is the one-model detail view: the browse preview and the full dump both render it. */
	public function testShowPrintsOneNoteWithTestedOneEntryPerLineNewestFirst(): void {
		$this->seedMounted();
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [
			'when'   => 'General summarizer',
			'speed'  => '~21 tok/s',
			'tags'   => [ 'summarize', 'jev' ],
			'tested' => '2026-07-24: first run | 2026-10-06 second run | undated note',
		] );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'show', 'qwen3.5:9b' ] );
		$lines = explode( "\n", self::plain( $out ) );

		$this->assertSame( 0, $code, $out );
		$this->assertSame( 'qwen3.5:9b  [both]  ollama', $lines[0] );
		$this->assertContains( '  when    General summarizer', $lines );
		$this->assertContains( '  speed   ~21 tok/s', $lines );
		$this->assertContains( '  tags    summarize, jev', $lines );
		$tested = array_search( '  tested', $lines, true );
		$this->assertNotFalse( $tested, $out );
		$this->assertSame( '                undated note', $lines[ $tested + 1 ] );
		$this->assertSame( '    2026-10-06  second run', $lines[ $tested + 2 ] );
		$this->assertSame( '    2026-07-24  first run', $lines[ $tested + 3 ] );
	}

	/** Continuation lines keep their column instead of wrapping to column 0. */
	public function testShowWrapsToThePreviewWidthWithAHangingIndent(): void {
		$this->seedMounted();
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [
			'when' => 'one two three four five six seven eight nine ten',
		] );
		putenv( 'FZF_PREVIEW_COLUMNS=30' );

		[ , $out ] = $this->dispatch( [ 'aimodels', 'why', 'show', 'qwen3.5:9b' ] );
		putenv( 'FZF_PREVIEW_COLUMNS' );
		$lines = explode( "\n", self::plain( $out ) );

		$this->assertSame( '  when    one two three four', $lines[1] );
		$this->assertSame( '          five six seven eight', $lines[2] );
		$this->assertSame( '          nine ten', $lines[3] );
	}

	public function testShowFindsGraveyardEntries(): void {
		( new ModelNotes( $this->notesFile() ) )->remove( 'ollama:gone:1b', true, 'Bad', '2026-09-01' );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'show', 'gone:1b' ] );

		$this->assertSame( 0, $code, $out );
		$this->assertStringStartsWith( 'gone:1b  [removed 2026-09-01]  ollama', self::plain( $out ) );
		$this->assertStringContainsString( 'Bad', $out );
	}

	/** Re-installed after removal: plain show is the live note, --removed the graveyard one. */
	public function testShowRemovedPicksTheGraveyardEntryOfAReinstalledModel(): void {
		$this->seedMounted();
		$notes = new ModelNotes( $this->notesFile() );
		$notes->set( 'ollama:gemma4:26b', [ 'when' => 'Old take' ] );
		$notes->remove( 'ollama:gemma4:26b', true, 'Dropped', '2026-09-01' );
		$notes->set( 'ollama:gemma4:26b', [ 'when' => 'New take' ] );

		[ , $live ] = $this->dispatch( [ 'aimodels', 'why', 'show', 'gemma4:26b' ] );
		[ , $gone ] = $this->dispatch( [ 'aimodels', 'why', 'show', 'gemma4:26b', '', '--removed' ] );

		$this->assertStringContainsString( 'New take', $live );
		$this->assertStringContainsString( 'Old take', $gone );
		$this->assertStringContainsString( '[removed 2026-09-01]', self::plain( $gone ) );
	}

	public function testShowUnknownModelIsAFailure(): void {
		[ $code ] = $this->dispatch( [ 'aimodels', 'why', 'show', 'nope:1b' ] );

		$this->assertSame( 1, $code );
	}

	/**
	 * Not a terminal (a pipe, an agent's Bash tool): the full dump, every field,
	 * so nothing is lost when --json was forgotten.
	 */
	public function testListOffATerminalIsTheFullDumpOfEveryNote(): void {
		$this->seedMounted();
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [ 'when' => 'General', 'tested' => 'a | b' ] );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why' ] );
		$plain = self::plain( $out );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( "qwen3.5:9b  [both]  ollama\n  when    General\n", $plain );
		$this->assertStringContainsString( "    b\n", $plain );
		$this->assertStringContainsString( 'no note — run: aimodels why set gemma4:26b', $plain );
	}

	/**
	 * browse hands fzf one row per model (installed and graveyard), with the
	 * full key as hidden field 1 so the preview can `show` it, then prints the
	 * picked model's note.
	 */
	public function testBrowseFeedsFzfAndShowsThePick(): void {
		$this->seedMounted();
		( new ModelNotes( $this->notesFile() ) )->set( 'ollama:qwen3.5:9b', [ 'when' => 'General', 'tags' => [ 'jev' ] ] );
		( new ModelNotes( $this->notesFile() ) )->remove( 'ollama:gone:1b', true, 'Bad', '2026-09-01' );
		putenv( 'AIMODELS_FZF_BIN=' . self::sharedStub( 'fzf', implode( "\n", [
			'#!/bin/sh',
			'printf "%s\n" "$@" > "$GRAVEYARD_ROOT/fzf-args"',
			'cat > "$GRAVEYARD_ROOT/fzf-stdin"',
			'grep "^ollama:qwen3.5:9b	" "$GRAVEYARD_ROOT/fzf-stdin"',
			'',
		] ) ) );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'browse' ] );
		putenv( 'AIMODELS_FZF_BIN' );

		$this->assertSame( 0, $code, $out );
		$rows = self::plain( (string) file_get_contents( $this->graveyardRoot . '/fzf-stdin' ) );
		$this->assertMatchesRegularExpression( '/^ollama:qwen3\.5:9b\t\tqwen3\.5:9b +both +ollama +jev$/m', $rows );
		$this->assertMatchesRegularExpression( '/^ollama:gone:1b\t--removed\tgone:1b +removed +ollama/m', $rows );
		$args = (string) file_get_contents( $this->graveyardRoot . '/fzf-args' );
		$this->assertMatchesRegularExpression( '/why show \{1\} \{2\}/', $args );
		$this->assertStringContainsString( '--with-nth=3..', $args );
		$this->assertStringStartsWith( 'qwen3.5:9b  [both]  ollama', self::plain( $out ) );
	}

	public function testBrowseEscapeIsNotAFailure(): void {
		$this->seedMounted();
		putenv( 'AIMODELS_FZF_BIN=' . self::sharedStub( 'fzf-esc', "#!/bin/sh\ncat > /dev/null\nexit 130\n" ) );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'browse' ] );
		putenv( 'AIMODELS_FZF_BIN' );

		$this->assertSame( 0, $code );
		$this->assertSame( '', $out );
	}

	public function testBrowseWithoutFzfSaysHowToGetIt(): void {
		putenv( 'AIMODELS_FZF_BIN=' . $this->graveyardRoot . '/no-such-fzf' );

		[ $code, $out ] = $this->dispatch( [ 'aimodels', 'why', 'browse' ] );
		putenv( 'AIMODELS_FZF_BIN' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'brew install fzf', $out );
	}

	/** A human at a terminal gets the browser by default; --full and --json still print. */
	public function testListBrowsesOnlyForAHumanAtATerminal(): void {
		$this->seedMounted();
		putenv( 'AIMODELS_FZF_BIN=' . self::sharedStub( 'fzf-mark', "#!/bin/sh\ncat > /dev/null\necho browsed > \"\$GRAVEYARD_ROOT/fzf-ran\"\nexit 130\n" ) );
		$this->cli->forceInteractive = true;
		$this->tty                   = true;
		$ran                         = $this->graveyardRoot . '/fzf-ran';

		[ , $out ] = $this->dispatch( [ 'aimodels', 'why', '--full' ] );
		$this->assertFileDoesNotExist( $ran );
		$this->assertStringContainsString( 'qwen3.5:9b', $out );

		$this->json( [ 'aimodels', 'why', '--json' ] );
		$this->assertFileDoesNotExist( $ran );

		$this->dispatch( [ 'aimodels', 'why' ] );
		putenv( 'AIMODELS_FZF_BIN' );
		$this->assertFileExists( $ran );
	}
}
