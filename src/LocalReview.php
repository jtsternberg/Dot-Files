<?php
namespace JT;

use JT\Helpers\Ollama;

/**
 * Review a diff with two local models in turn: a fast primary that re-checks
 * its own list until it says it is confident, then a slower reasoning advisor
 * in a single long pass. They catch different bugs, so a caller merges both.
 *
 * 2026-10-22, a diff with 5 planted bugs and 3 behavior-preserving decoys:
 * qwen3-coder alone 2.5/5 with 2 false findings (14s); with the re-check loop
 * 3.5/5 with none (31s); flint:40b 2.5/5 (203s) but the only one to catch a
 * dict mutated during iteration; the two lists together 4.5/5.
 *
 * Models resolve per store from the shared ~/.config/ai-tooling/config under
 * REVIEW_MODEL* and REVIEW_ADVISOR_MODEL*. An advisor of `none` (or none
 * configured) skips the second review, which is how a store without a
 * reasoning model degrades.
 */
class LocalReview {

	const PRIMARY_PREFIX = 'REVIEW_MODEL';
	const ADVISOR_PREFIX = 'REVIEW_ADVISOR_MODEL';

	const DEFAULT_PRIMARY = 'qwen3-coder:latest';

	/** In the benchmark the loop settled after one re-check; the cap bounds a model that never does. */
	const MAX_RECHECKS = 4;

	const CONFIDENT = 'CONFIDENT';

	/**
	 * flint:40b reasons on every reply even with `think:false`, which only hides
	 * the reasoning; at num_predict 400 it spent the whole budget there and
	 * returned an empty answer. A one-pass review used ~1.6K-4K tokens.
	 */
	const ADVISOR_NUM_PREDICT = 16384;

	const PRIMARY_NUM_PREDICT = 4096;

	const MIN_CTX = 8192;
	const MAX_CTX = 262144;

	/** The advisor's long reasoning at ~50 tok/s, plus a cold load from the external store. */
	const TIMEOUT_SECONDS = 1800;

	const REVIEW_PROMPT = 'Review this diff as a senior code reviewer. List every bug or behavior regression it '
		. 'introduces, each with the line and the concrete failure it causes. Skip style nits, and do not list '
		. 'changes that preserve behavior.';

	const RECHECK_PROMPT = 'Now double-check your review against the diff, line by line. Did you miss any bug, or '
		. 'list anything that is not actually a bug? If you are confident the list is complete and correct, end '
		. 'your reply with the single word ' . self::CONFIDENT . ' on its own line.';

	/**
	 * Its own turn: asked to re-check and write the list in one reply,
	 * qwen3-coder answered with its line-by-line deliberation and no list, even
	 * when told to check silently.
	 */
	const FINAL_PROMPT = 'Write only the final list of bugs from your review, in full: each bug with its line and '
		. 'the concrete failure it causes. No other text.';

	public function __construct( private ?Ollama $ollama = null, private ?string $home = null ) {}

	public function resolvePrimary( ?string $model = null ): string {
		return $this->resolve( $model, self::PRIMARY_PREFIX, self::DEFAULT_PRIMARY );
	}

	/** Null when no advisor should run. */
	public function resolveAdvisor( ?string $model = null ): ?string {
		$advisor = $this->resolve( $model, self::ADVISOR_PREFIX, '' );

		return '' === $advisor || 'none' === strtolower( $advisor ) ? null : $advisor;
	}

	/**
	 * @return array{
	 *   error:?string, errorType:?string,
	 *   primary:?array{model:string, review:?string, rounds:int, confident:bool, durationMs:int, error:?string, errorType:?string},
	 *   advisor:?array{model:string, review:?string, durationMs:int, error:?string, errorType:?string}
	 * }
	 */
	/**
	 * @param ?callable(string, string):void $progress Called with ('primary'|'advisor', model) as each review starts.
	 */
	public function review( string $diff, ?string $primary = null, ?string $advisor = null, ?callable $progress = null ): array {
		if ( '' === trim( $diff ) ) {
			return [ 'error' => 'nothing to review: the diff is empty', 'errorType' => 'input', 'primary' => null, 'advisor' => null ];
		}

		$primaryModel = $this->resolvePrimary( $primary );
		$advisorModel = $this->resolveAdvisor( $advisor );

		$progress && $progress( 'primary', $primaryModel );
		// Every re-check turn carries the earlier replies, so the conversation grows each round.
		$primaryResult = $this->runPrimary( $primaryModel, $diff, $this->numCtx( $diff, ( self::MAX_RECHECKS + 1 ) * self::PRIMARY_NUM_PREDICT / 2 ) );
		// Both together are ~45GB; loading the advisor beside the primary thrashes the machine.
		$this->ollama()->unload( $primaryModel );

		$advisorResult = null;
		if ( null !== $advisorModel ) {
			$progress && $progress( 'advisor', $advisorModel );
			// The reasoning lands in the same context window: one sized for the diff
			// alone overflowed mid-thought and the advisor never answered (493s, empty).
			$advisorResult = $this->runAdvisor( $advisorModel, $diff, $this->numCtx( $diff, self::ADVISOR_NUM_PREDICT ) );
			$this->ollama()->unload( $advisorModel );
		}

		return [ 'error' => null, 'errorType' => null, 'primary' => $primaryResult, 'advisor' => $advisorResult ];
	}

	private function runPrimary( string $model, string $diff, int $numCtx ): array {
		$started   = microtime( true );
		$messages  = [ [ 'role' => 'user', 'content' => self::REVIEW_PROMPT . "\n\n" . $diff ] ];
		$request   = [ 'options' => [ 'temperature' => 0, 'num_ctx' => $numCtx, 'num_predict' => self::PRIMARY_NUM_PREDICT ] ];
		$review    = null;
		$rounds    = 0;
		$confident = false;

		while ( ! $confident && $rounds <= self::MAX_RECHECKS ) {
			if ( $rounds > 0 ) {
				$messages[] = [ 'role' => 'user', 'content' => self::RECHECK_PROMPT ];
			}
			$reply = $this->ollama()->chatMessages( $model, $messages, Ollama::DEFAULT_URL, self::TIMEOUT_SECONDS, $request );
			$rounds++;

			if ( null !== $reply['error'] ) {
				// A failed re-check still leaves the last good list worth reporting.
				return $this->result( $model, $review, $started, (string) $reply['error'], (string) $reply['errorType'] )
					+ [ 'rounds' => $rounds, 'confident' => false ];
			}

			[ $review, $confident ] = $this->stripConfident( trim( (string) $reply['content'] ) );
			$messages[] = [ 'role' => 'assistant', 'content' => $review ];
		}

		$messages[] = [ 'role' => 'user', 'content' => self::FINAL_PROMPT ];
		$reply      = $this->ollama()->chatMessages( $model, $messages, Ollama::DEFAULT_URL, self::TIMEOUT_SECONDS, $request );
		if ( null !== $reply['error'] ) {
			return $this->result( $model, $review, $started, (string) $reply['error'], (string) $reply['errorType'] )
				+ [ 'rounds' => $rounds, 'confident' => $confident ];
		}
		[ $final ] = $this->stripConfident( trim( (string) $reply['content'] ) );

		return $this->result( $model, '' === $final ? $review : $final, $started ) + [ 'rounds' => $rounds, 'confident' => $confident ];
	}

	private function runAdvisor( string $model, string $diff, int $numCtx ): array {
		$started = microtime( true );
		$reply   = $this->ollama()->chatMessages(
			$model,
			[ [ 'role' => 'user', 'content' => self::REVIEW_PROMPT . "\n\n" . $diff ] ],
			Ollama::DEFAULT_URL,
			self::TIMEOUT_SECONDS,
			// No `think` and no temperature: a reasoning advisor is the point, and
			// its Modelfile carries the sampling its authors evaluated with.
			[ 'options' => [ 'num_ctx' => $numCtx, 'num_predict' => self::ADVISOR_NUM_PREDICT ] ]
		);

		if ( null !== $reply['error'] ) {
			return $this->result( $model, null, $started, (string) $reply['error'], (string) $reply['errorType'] );
		}

		$review = trim( (string) $reply['content'] );
		if ( '' === $review ) {
			return $this->result( $model, null, $started, 'the advisor returned no review; it likely spent its whole num_predict budget reasoning', 'empty' );
		}

		return $this->result( $model, $review, $started );
	}

	/** @return array{0:string, 1:bool} [review without the marker, whether it was present] */
	private function stripConfident( string $content ): array {
		$marker = '/\n?[*_`\s]*' . self::CONFIDENT . '[*_`.!\s]*$/';
		if ( preg_match( $marker, $content ) ) {
			return [ trim( (string) preg_replace( $marker, '', $content ) ), true ];
		}

		return [ $content, false ];
	}

	private function result( string $model, ?string $review, float $started, ?string $error = null, ?string $errorType = null ): array {
		return [
			'model'      => $model,
			'review'     => $review,
			'durationMs' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'error'      => $error,
			'errorType'  => $errorType,
		];
	}

	/** ~4 bytes per token for the diff and prompt, plus the reply budget, 30% headroom, in 2048-token steps. */
	private function numCtx( string $diff, int $replyTokens ): int {
		$tokens = (int) ( ( strlen( $diff ) / 4 + 1000 + $replyTokens ) * 1.3 );
		$ctx    = (int) ( ceil( $tokens / 2048 ) * 2048 );

		return max( self::MIN_CTX, min( self::MAX_CTX, $ctx ) );
	}

	private function resolve( ?string $model, string $prefix, string $default ): string {
		if ( null !== $model && '' !== trim( $model ) ) {
			return trim( $model );
		}

		return $this->ollama()->resolveModel( $this->ollama()->config(), $default, $this->home(), $prefix );
	}

	private function home(): string {
		return $this->home ?? ( getenv( 'HOME' ) ?: '' );
	}

	private function ollama(): Ollama {
		return $this->ollama ??= new Ollama();
	}
}
