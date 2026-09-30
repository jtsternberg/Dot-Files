<?php
namespace JT\Tests\LocalModels;

use JT\LocalModels\ModelNotes;
use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The notes store behind `aimodels why` (and the `ollama-why` shim).
 *
 * The file predates MacWhisper support: every key in it is a bare Ollama tag and
 * locations use the old `sd` word. Migration must keep every field of every
 * entry, in both the notes and the graveyard.
 */
final class ModelNotesTest extends TestCase {

	private string $file = '';

	protected function setUp(): void {
		parent::setUp();
		$this->file = $this->graveyardRoot . '/.ollama-why.json';
	}

	private function legacy(): array {
		return [
			'models'    => [
				'qwen3.5:9b'      => [ 'when' => 'Default', 'speed' => '40 tok/s', 'tags' => [ 'chat' ], 'location' => 'both' ],
				'gemma4:26b'      => [ 'when' => 'Big', 'location' => 'sd', 'tested' => '2026-08 ok' ],
				'qwen2.5:1.5b'    => [ 'when' => 'Cron', 'location' => 'local' ],
			],
			'graveyard' => [
				'gemma4:31b'      => [ 'removed_at' => '2026-07-01', 'tested' => 'Too slow', 'location' => 'sd' ],
				'llama3.2:3b'     => [ 'removed_at' => '2026-06-01' ],
			],
		];
	}

	private function writeLegacy( array $data ): void {
		file_put_contents( $this->file, json_encode( $data ) );
	}

	public function testLegacyFileMigratesLosslesslyToEngineQualifiedKeys(): void {
		$legacy = $this->legacy();
		$this->writeLegacy( $legacy );

		$data = ( new ModelNotes( $this->file ) )->data();

		$this->assertSame( count( $legacy['models'] ), count( $data['models'] ) );
		$this->assertSame( count( $legacy['graveyard'] ), count( $data['graveyard'] ) );

		foreach ( [ 'models', 'graveyard' ] as $section ) {
			foreach ( $legacy[ $section ] as $key => $entry ) {
				$migrated = $data[ $section ][ 'ollama:' . $key ];
				if ( isset( $entry['location'] ) && 'sd' === $entry['location'] ) {
					$entry['location'] = 'external';
				}
				$this->assertSame( $entry, $migrated, "{$section}.{$key} changed in migration" );
			}
		}
	}

	public function testOldestFlatShapeIsTreatedAsModels(): void {
		$this->writeLegacy( [ 'qwen3.5:9b' => [ 'when' => 'Default' ] ] );

		$data = ( new ModelNotes( $this->file ) )->data();

		$this->assertSame( [ 'when' => 'Default' ], $data['models']['ollama:qwen3.5:9b'] );
		$this->assertSame( [], $data['graveyard'] );
	}

	public function testReadingNeverWritesTheFile(): void {
		$this->writeLegacy( $this->legacy() );
		$before = file_get_contents( $this->file );

		( new ModelNotes( $this->file ) )->data();

		$this->assertSame( $before, file_get_contents( $this->file ) );
		$this->assertFileDoesNotExist( $this->file . ModelNotes::BACKUP_SUFFIX );
	}

	public function testFirstWriteBacksUpTheLegacyFileOnce(): void {
		$this->writeLegacy( $this->legacy() );
		$original = file_get_contents( $this->file );

		( new ModelNotes( $this->file ) )->set( 'ollama:qwen3.5:9b', [ 'speed' => '41 tok/s' ] );
		( new ModelNotes( $this->file ) )->set( 'ollama:qwen3.5:9b', [ 'speed' => '42 tok/s' ] );

		$this->assertSame( $original, file_get_contents( $this->file . ModelNotes::BACKUP_SUFFIX ) );
		$saved = json_decode( file_get_contents( $this->file ), true );
		$this->assertSame( ModelNotes::VERSION, $saved['version'] );
		$this->assertSame( '42 tok/s', $saved['models']['ollama:qwen3.5:9b']['speed'] );
		$this->assertSame( 'Default', $saved['models']['ollama:qwen3.5:9b']['when'] );
	}

	/** The real file is a symlink into the dotfiles repo; a write must not replace the link. */
	public function testWritingThroughASymlinkKeepsTheLink(): void {
		$real = $this->graveyardRoot . '/real-notes.json';
		file_put_contents( $real, json_encode( $this->legacy() ) );
		symlink( $real, $this->file );

		( new ModelNotes( $this->file ) )->set( 'whisperkit:openai_whisper-small', [ 'when' => 'Offline default' ] );

		$this->assertTrue( is_link( $this->file ) );
		$this->assertArrayHasKey(
			'whisperkit:openai_whisper-small',
			json_decode( file_get_contents( $real ), true )['models']
		);
	}

	public function testMigratedFileIsNotMigratedAgain(): void {
		$notes = new ModelNotes( $this->file );
		$notes->set( 'ollama:qwen3.5:9b', [ 'when' => 'x' ] );

		$data = ( new ModelNotes( $this->file ) )->data();

		$this->assertSame( [ 'ollama:qwen3.5:9b' ], array_keys( $data['models'] ) );
		$this->assertFileDoesNotExist( $this->file . ModelNotes::BACKUP_SUFFIX, 'nothing legacy to back up' );
	}

	#[DataProvider( 'keys' )]
	public function testKeyResolution( string $input, string $expected ): void {
		$this->assertSame( $expected, ModelNotes::resolveKey( $input ) );
	}

	public static function keys(): array {
		return [
			'bare legacy name is ollama'  => [ 'qwen3.5:9b', 'ollama:qwen3.5:9b' ],
			'untagged bare name'          => [ 'llama3.1', 'ollama:llama3.1' ],
			'explicit ollama'             => [ 'ollama:qwen3.5:9b', 'ollama:qwen3.5:9b' ],
			'whisperkit mw id'            => [ 'whisperkit:openai_whisper-small', 'whisperkit:openai_whisper-small' ],
			'qwen3-asr mw id'             => [ 'qwen3-asr:qwen3-asr-1.7b', 'qwen3-asr:qwen3-asr-1.7b' ],
			'parakeet mw id'              => [ 'parakeet-pro:nvidia_parakeet-v3', 'parakeet-pro:nvidia_parakeet-v3' ],
			'whisper-cpp mw id'           => [ 'whisper-cpp:ggml-model-whisper-small.en', 'whisper-cpp:ggml-model-whisper-small.en' ],
			'macwhisper support bundle'   => [ 'macwhisper:speakerkit', 'macwhisper:speakerkit' ],
		];
	}

	public function testAnInventoryIdIsTakenAsIsEvenWithAnUnknownPrefix(): void {
		$this->assertSame( 'newkit:foo', ModelNotes::resolveKey( 'newkit:foo', [ 'newkit:foo' ] ) );
	}

	public function testEngineOfAKey(): void {
		$this->assertSame( 'ollama', ModelNotes::engineOf( 'ollama:qwen3.5:9b' ) );
		$this->assertSame( 'macwhisper', ModelNotes::engineOf( 'qwen3-asr:qwen3-asr-1.7b' ) );
	}

	#[DataProvider( 'locations' )]
	public function testLocationVocabulary( string $input, ?string $expected ): void {
		$this->assertSame( $expected, ModelNotes::normalizeLocation( $input ) );
	}

	public static function locations(): array {
		return [
			[ 'local', 'local' ],
			[ 'external', 'external' ],
			[ 'both', 'both' ],
			[ 'sd', 'external' ],
			[ 'SD', 'external' ],
			[ 'moon', null ],
		];
	}

	public function testArchiveMovesTheNoteToTheGraveyard(): void {
		$notes = new ModelNotes( $this->file );
		$notes->set( 'qwen3-asr:qwen3-asr-1.7b', [ 'when' => 'Accuracy', 'location' => 'external' ] );

		$notes->remove( 'qwen3-asr:qwen3-asr-1.7b', true, 'Too slow', '2026-09-30' );

		$data = ( new ModelNotes( $this->file ) )->data();
		$this->assertArrayNotHasKey( 'qwen3-asr:qwen3-asr-1.7b', $data['models'] );
		$this->assertSame(
			[ 'when' => 'Accuracy', 'location' => 'external', 'removed_at' => '2026-09-30', 'tested' => 'Too slow' ],
			$data['graveyard']['qwen3-asr:qwen3-asr-1.7b']
		);
	}

	public function testArchiveWithoutANoteLeavesATombstone(): void {
		$notes = new ModelNotes( $this->file );

		$this->assertFalse( $notes->remove( 'ollama:gone:1b', true, null, '2026-09-30' ) );

		$this->assertSame( [ 'removed_at' => '2026-09-30' ], $notes->data()['graveyard']['ollama:gone:1b'] );
	}

	public function testPlainRemoveDropsTheNote(): void {
		$notes = new ModelNotes( $this->file );
		$notes->set( 'ollama:a:1b', [ 'when' => 'x' ] );

		$this->assertTrue( $notes->remove( 'ollama:a:1b' ) );
		$this->assertSame( [], $notes->data()['models'] );
		$this->assertSame( [], $notes->data()['graveyard'] );
	}

	public function testForget(): void {
		$notes = new ModelNotes( $this->file );
		$notes->remove( 'ollama:gone:1b', true, null, '2026-09-30' );

		$this->assertTrue( $notes->forget( 'ollama:gone:1b' ) );
		$this->assertFalse( $notes->forget( 'ollama:gone:1b' ) );
		$this->assertSame( [], $notes->data()['graveyard'] );
	}

	/** `ollamodels reconcile` calls this after linking a local model into AI-LAB. */
	public function testPromoteLocalToBoth(): void {
		$this->writeLegacy( $this->legacy() );
		$notes = new ModelNotes( $this->file );

		$this->assertTrue( $notes->promoteLocalToBoth( 'ollama:qwen2.5:1.5b' ) );
		$this->assertFalse( $notes->promoteLocalToBoth( 'ollama:gemma4:26b' ), 'external is not local' );
		$this->assertFalse( $notes->promoteLocalToBoth( 'ollama:nope:1b' ) );

		$this->assertSame( 'both', ( new ModelNotes( $this->file ) )->data()['models']['ollama:qwen2.5:1.5b']['location'] );
	}

	public function testEmptyMapsEncodeAsObjects(): void {
		( new ModelNotes( $this->file ) )->set( 'ollama:a:1b', [ 'when' => 'x' ] );

		$this->assertStringContainsString( '"graveyard": {}', file_get_contents( $this->file ) );
	}
}
