<?php
namespace JT\Helpers;

/**
 * FoundationModels — Apple's on-device model, via the `fm` CLI (macOS 27+ on
 * Apple silicon).
 *
 * The model's context window is small enough that an ordinary diff overflows
 * it, and `fm` answers an overflow with a hard error rather than truncating, so
 * respond() trims the prompt to fit before sending it.
 */
class FoundationModels {

	/** Instructions + prompt + response; measured, `fm` refuses a prompt past ~4.1k. */
	const CONTEXT_TOKENS = 4096;

	/** Left free for the response itself. */
	const RESPONSE_RESERVE_TOKENS = 600;

	const DEFAULT_MODEL = 'system';

	const TRUNCATION_NOTE = "\n[truncated to fit the model's context window]";

	/** @var callable(string[], string):array{stdout:string, stderr:string, exitCode:int} */
	private $run;

	public function __construct( ?callable $run = null ) {
		$this->run = $run ?: [ $this, 'procRun' ];
	}

	/**
	 * The prompt goes on stdin, not argv: a diff can be long, and argv would also
	 * expose it in `ps`.
	 *
	 * @return array{content:?string, error:?string}
	 */
	public function respond( string $instructions, string $prompt, string $model = self::DEFAULT_MODEL ): array {
		$prompt = $this->fit( $instructions, $prompt );
		$result = ( $this->run )(
			[ 'fm', 'respond', '--no-stream', '-m', $model, '-i', $instructions ],
			$prompt
		);

		if ( 0 !== $result['exitCode'] ) {
			$error = trim( $result['stderr'] ) ?: trim( $result['stdout'] ) ?: "fm exited {$result['exitCode']}";

			return [ 'content' => null, 'error' => $error ];
		}

		return [ 'content' => trim( $result['stdout'] ), 'error' => null ];
	}

	/**
	 * Cut $prompt at a line boundary until instructions + prompt fit $budget
	 * tokens. Each count is a ~0.5s `fm` call, so the cut is sized from the
	 * measured overshoot rather than walked a line at a time.
	 *
	 * When tokens cannot be counted the prompt goes out whole: `fm` then reports
	 * the overflow itself, which beats guessing a size.
	 */
	public function fit( string $instructions, string $prompt, int $budget = self::CONTEXT_TOKENS - self::RESPONSE_RESERVE_TOKENS ): string {
		$fitted = $prompt;

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$tokens = $this->countTokens( $instructions, $fitted );
			if ( null === $tokens || $tokens <= $budget ) {
				return $fitted;
			}

			$body   = $fitted === $prompt ? $prompt : substr( $fitted, 0, -strlen( self::TRUNCATION_NOTE ) );
			$keep   = (int) floor( strlen( $body ) * ( $budget / $tokens ) * 0.9 ) - strlen( self::TRUNCATION_NOTE );
			$cut    = substr( $body, 0, max( 0, $keep ) );
			$eol    = strrpos( $cut, "\n" );
			$fitted = ( false === $eol ? $cut : substr( $cut, 0, $eol + 1 ) ) . self::TRUNCATION_NOTE;
		}

		return $fitted;
	}

	public function countTokens( string $instructions, string $prompt ): ?int {
		$result = ( $this->run )( [ 'fm', 'count-tokens', '-q', '-i', $instructions ], $prompt );
		$count  = trim( $result['stdout'] );

		return 0 === $result['exitCode'] && ctype_digit( $count ) ? (int) $count : null;
	}

	/** @return array{stdout:string, stderr:string, exitCode:int} */
	private function procRun( array $argv, string $stdin ): array {
		$proc = proc_open(
			implode( ' ', array_map( 'escapeshellarg', $argv ) ),
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		if ( ! is_resource( $proc ) ) {
			return [ 'stdout' => '', 'stderr' => 'could not start fm', 'exitCode' => 127 ];
		}

		fwrite( $pipes[0], $stdin );
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return [ 'stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close( $proc ) ];
	}
}
