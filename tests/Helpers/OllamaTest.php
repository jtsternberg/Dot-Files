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
			[ 'content' => 'a summary', 'error' => null ],
			$ollama->chat( 'a-model', 'system', 'user' )
		);
	}

	public function testChatReturnsTheCurlErrorWhenTheRequestFails(): void {
		$ollama = new Ollama(
			null,
			static fn( string $url, string $payload, int $timeout ): array => [ null, 'Connection refused' ]
		);

		$this->assertSame(
			[ 'content' => null, 'error' => 'Connection refused' ],
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
			[ 'content' => null, 'error' => "model 'bogus' not found" ],
			$ollama->chat( 'bogus', 'system', 'user' )
		);
	}
}
