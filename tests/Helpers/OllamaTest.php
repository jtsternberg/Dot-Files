<?php
namespace JT\Tests\Helpers;

use JT\Helpers\Ollama;
use JT\Tests\TestCase;

final class OllamaTest extends TestCase {

	public function testResolveModelFallsBackToDefaultWhenNoSymlinkExists(): void {
		$ollama = new Ollama( static fn(): bool => false );

		$this->assertSame(
			'fallback',
			$ollama->resolveModel( [], 'fallback', '/home/jt' )
		);
	}

	public function testResolveModelUsesConfiguredModelWhenNoSymlinkExists(): void {
		$ollama = new Ollama( static fn(): bool => false );

		$this->assertSame(
			'configured',
			$ollama->resolveModel( [ 'MODEL' => 'configured' ], 'fallback', '/home/jt' )
		);
	}

	public function testResolveModelUsesSdKeyWhenSymlinkTargetsTheSdCard(): void {
		$ollama = new Ollama( static fn(): string => Ollama::SD_PATH );

		$this->assertSame(
			'sd-model',
			$ollama->resolveModel(
				[ 'MODEL' => 'default-model', 'MODEL_SD' => 'sd-model', 'MODEL_LOCAL' => 'local-model' ],
				'fallback',
				'/home/jt'
			)
		);
	}

	public function testResolveModelUsesLocalKeyWhenSymlinkTargetsAnythingElse(): void {
		$ollama = new Ollama( static fn(): string => '/some/other/path' );

		$this->assertSame(
			'local-model',
			$ollama->resolveModel(
				[ 'MODEL' => 'default-model', 'MODEL_SD' => 'sd-model', 'MODEL_LOCAL' => 'local-model' ],
				'fallback',
				'/home/jt'
			)
		);
	}

	public function testChatReturnsMessageContentOnSuccess(): void {
		$ollama = new Ollama(
			null,
			static fn( string $url, string $payload, int $timeout ): array => [
				json_encode( [ 'message' => [ 'content' => 'a summary' ] ] ),
				'',
			]
		);

		$this->assertSame(
			[ 'content' => 'a summary', 'error' => null, 'errorType' => null ],
			$ollama->chat( 'a-model', 'system', 'user' )
		);
	}

	public function testChatReturnsTheCurlErrorWhenTheRequestFails(): void {
		$ollama = new Ollama(
			null,
			static fn( string $url, string $payload, int $timeout ): array => [ null, 'Connection refused' ]
		);

		$this->assertSame(
			[ 'content' => null, 'error' => 'Connection refused', 'errorType' => 'transport' ],
			$ollama->chat( 'a-model', 'system', 'user' )
		);
	}

	public function testChatReturnsTheOllamaApiErrorWhenTheModelIsUnknown(): void {
		$ollama = new Ollama(
			null,
			static fn( string $url, string $payload, int $timeout ): array => [
				json_encode( [ 'error' => "model 'bogus' not found" ] ),
				'',
			]
		);

		$this->assertSame(
			[ 'content' => null, 'error' => "model 'bogus' not found", 'errorType' => 'api' ],
			$ollama->chat( 'bogus', 'system', 'user' )
		);
	}

	public function testStoragePathReturnsTheSymlinkTarget(): void {
		$ollama = new Ollama( static fn( string $path ): string => '/Volumes/AI-LAB/ollama/models' );

		$this->assertSame( '/Volumes/AI-LAB/ollama/models', $ollama->storagePath( '/home/jt' ) );
	}

	public function testStoragePathIsNullWhenNothingIsSymlinked(): void {
		$ollama = new Ollama( static fn(): bool => false );

		$this->assertNull( $ollama->storagePath( '/home/jt' ) );
	}

	public function testConfigParsesTheModelConfigFileFromAGivenDirectory(): void {
		$dir = sys_get_temp_dir() . '/ollama-config-' . uniqid();
		mkdir( $dir . '/auto-commit-ollama', 0777, true );
		file_put_contents(
			$dir . '/auto-commit-ollama/config',
			"MODEL=default-model\nMODEL_SD=sd-model\n"
		);

		$config = ( new Ollama() )->config( $dir );

		unlink( $dir . '/auto-commit-ollama/config' );
		rmdir( $dir . '/auto-commit-ollama' );
		rmdir( $dir );

		$this->assertSame(
			[ 'MODEL' => 'default-model', 'MODEL_SD' => 'sd-model' ],
			$config
		);
	}

	public function testConfigIsEmptyWhenTheConfigFileIsMissing(): void {
		$this->assertSame(
			[],
			( new Ollama() )->config( sys_get_temp_dir() . '/definitely-not-here-' . uniqid() )
		);
	}
}
