<?php
namespace JT\Tests\Helpers;

use JT\Helpers\FoundationModels;
use JT\Tests\TestCase;

final class FoundationModelsTest extends TestCase {

	/** @var array<int,array{argv:string[], stdin:string}> */
	private array $calls = [];

	/**
	 * A fake `fm`: count-tokens reports one token per character of
	 * instructions + prompt, respond answers with $reply.
	 */
	private function fm( string $reply = 'fix a thing', int $exitCode = 0, string $stderr = '' ): FoundationModels {
		return new FoundationModels( function ( array $argv, string $stdin ) use ( $reply, $exitCode, $stderr ): array {
			$this->calls[] = [ 'argv' => $argv, 'stdin' => $stdin ];

			if ( 'count-tokens' === $argv[1] ) {
				$instructions = $argv[ array_search( '-i', $argv, true ) + 1 ];

				return [ 'stdout' => (string) ( strlen( $instructions ) + strlen( $stdin ) ), 'stderr' => '', 'exitCode' => 0 ];
			}

			return [ 'stdout' => $reply . "\n", 'stderr' => $stderr, 'exitCode' => $exitCode ];
		} );
	}

	/** @return array{argv:string[], stdin:string} */
	private function respondCall(): array {
		foreach ( $this->calls as $call ) {
			if ( 'respond' === $call['argv'][1] ) {
				return $call;
			}
		}
		$this->fail( 'fm respond was never called' );
	}

	public function testRespondSendsInstructionsAsAFlagAndThePromptOnStdinWithoutStreaming(): void {
		$result = $this->fm()->respond( 'be terse', 'the diff' );

		$this->assertSame( [ 'content' => 'fix a thing', 'error' => null ], $result );
		$call = $this->respondCall();
		$this->assertSame(
			[ 'fm', 'respond', '--no-stream', '-m', 'system', '-i', 'be terse' ],
			$call['argv']
		);
		$this->assertSame( 'the diff', $call['stdin'] );
	}

	public function testRespondPassesTheRequestedModel(): void {
		$this->fm()->respond( 'be terse', 'the diff', 'other' );

		$this->assertSame( 'other', $this->respondCall()['argv'][4] );
	}

	public function testRespondReportsStderrWhenFmFails(): void {
		$result = $this->fm( '', 1, "Error: The session's transcript exceeded the model's context size.\n" )
			->respond( 'be terse', 'the diff' );

		$this->assertNull( $result['content'] );
		$this->assertSame( "Error: The session's transcript exceeded the model's context size.", $result['error'] );
	}

	public function testRespondReportsAMissingBinary(): void {
		$fm = new FoundationModels( static fn(): array => [ 'stdout' => '', 'stderr' => 'sh: fm: command not found', 'exitCode' => 127 ] );

		$this->assertSame( 'sh: fm: command not found', $fm->respond( 'i', 'p' )['error'] );
	}

	public function testFitLeavesAPromptThatFitsUntouched(): void {
		$this->assertSame( 'short', $this->fm()->fit( 'i', 'short', 100 ) );
	}

	public function testFitTruncatesAtALineBoundaryUntilItFitsAndSaysSo(): void {
		$prompt = implode( "\n", array_fill( 0, 50, str_repeat( 'x', 19 ) ) );

		$fitted = $this->fm()->fit( 'inst', $prompt, 300 );

		$this->assertLessThanOrEqual( 300, strlen( 'inst' ) + strlen( $fitted ) );
		$this->assertStringEndsWith( FoundationModels::TRUNCATION_NOTE, $fitted );
		$body = substr( $fitted, 0, -strlen( FoundationModels::TRUNCATION_NOTE ) );
		foreach ( explode( "\n", rtrim( $body, "\n" ) ) as $line ) {
			$this->assertSame( str_repeat( 'x', 19 ), $line );
		}
	}

	public function testRespondFitsThePromptIntoTheContextWindowFirst(): void {
		$prompt = str_repeat( "line of diff\n", 1000 );

		$this->fm()->respond( 'be terse', $prompt );

		$stdin = $this->respondCall()['stdin'];
		$this->assertLessThan( strlen( $prompt ), strlen( $stdin ) );
		$this->assertLessThanOrEqual(
			FoundationModels::CONTEXT_TOKENS - FoundationModels::RESPONSE_RESERVE_TOKENS,
			strlen( 'be terse' ) + strlen( $stdin )
		);
	}

	public function testFitLeavesThePromptAloneWhenTokensCannotBeCounted(): void {
		$fm = new FoundationModels( static fn(): array => [ 'stdout' => '', 'stderr' => 'boom', 'exitCode' => 1 ] );

		$this->assertSame( 'whole prompt', $fm->fit( 'i', 'whole prompt', 1 ) );
	}
}
