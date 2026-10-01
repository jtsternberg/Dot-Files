<?php
namespace JT;

use JT\Helpers\Ollama;

/**
 * Reads an agent session's last assistant message as done / waiting / working, with
 * p(done), off a local decision model.
 *
 * The prompt, request fields, and first-token logprob normalization are a port of the
 * 2026-09-30 bench (dotfiles-0enr.1: 92% holdout on qwen3.5:9b). Change any of them
 * and that measurement no longer describes what this does.
 */
final class SessionStateClassifier {

	/** resolveModel()'s fallback when the config names no DECISION_MODEL* key. */
	const DEFAULT_MODEL = 'qwen3.5:9b';

	const CONFIG_PREFIX = 'DECISION_MODEL';

	const SYSTEM_PROMPT = "Classify the last message an AI coding agent sent its user. Reply with exactly one word: done, waiting, or working.\n"
		. "done: the requested work is finished; nothing waits on a reply. Soft optional offers ('say the word if you want X') and tasks left for the user to do elsewhere ('Next for you:' steps, commands to run, PRs to review) still count as done.\n"
		. "waiting: the agent is blocked until the user replies in this conversation: a direct question, a choice it is waiting on, or a request to report back ('tell me when it is done').\n"
		. 'working: the agent is still working: it narrates its next step, or waits on another agent or background job.';

	const LABELS = [ 'done', 'waiting', 'working' ];

	const MAX_CHARS = 1500;

	/** Long enough for a cold model load (~30 s on the M2 Max); a down server fails instantly. */
	const TIMEOUT_SECONDS = 60;

	/** A hotline callee that ended its call. Line-anchored, so prose mentioning one doesn't count. */
	const STATUS_DONE_RE = '/^STATUS: (?:WORK_COMPLETE|DONE)\b/';

	/** Only a STATUS line this near the end is the message's own; one higher up is a quoted callee reply. */
	const STATUS_TAIL_LINES = 3;

	private ?Ollama $ollama;

	private ?string $model = null;

	public function __construct( ?Ollama $ollama = null, private ?string $home = null ) {
		$this->ollama = $ollama;
	}

	public function resolveModel(): string {
		return $this->model ??= $this->ollama()->resolveModel(
			$this->ollama()->config(),
			self::DEFAULT_MODEL,
			$this->home ?? ( getenv( 'HOME' ) ?: '' ),
			self::CONFIG_PREFIX
		);
	}

	/**
	 * PURE. A finished hotline call needs no model: its own STATUS line says so.
	 *
	 * @return ?array{verdict:string, p_done:float, verdict_source:string}
	 */
	public function statusLineVerdict( string $text ): ?array {
		$lines = array_values( array_filter( preg_split( '/\R/', $text ), fn( $l ) => trim( $l ) !== '' ) );
		foreach ( array_slice( $lines, -self::STATUS_TAIL_LINES ) as $line ) {
			if ( preg_match( self::STATUS_DONE_RE, $line ) ) {
				return [ 'verdict' => 'done', 'p_done' => 1.0, 'verdict_source' => 'status-line' ];
			}
		}
		return null;
	}

	/** PURE. Cache key for a message: an unchanged message keeps its verdict. */
	public function fingerprint( string $text ): string {
		return sha1( $text );
	}

	/**
	 * @return array{verdict:?string, p_done:?float, error:?string, errorType:?string}
	 */
	public function classify( string $text ): array {
		$r = $this->ollama()->chat(
			$this->resolveModel(),
			self::SYSTEM_PROMPT,
			mb_substr( $text, -self::MAX_CHARS ),
			Ollama::DEFAULT_URL,
			self::TIMEOUT_SECONDS,
			[
				'think'        => false,
				'keep_alive'   => '10m',
				'logprobs'     => true,
				'top_logprobs' => 10,
				'options'      => [ 'temperature' => 0, 'num_predict' => 3 ],
			]
		);

		if ( null !== $r['error'] ) {
			return [ 'verdict' => null, 'p_done' => null, 'error' => $r['error'], 'errorType' => $r['errorType'] ];
		}

		$probs = $this->labelProbabilities( $r['logprobs'] ?? [] );
		if ( null === $probs ) {
			return [
				'verdict'   => null,
				'p_done'    => null,
				'error'     => 'no done/waiting/working token in the reply: ' . trim( (string) $r['content'] ),
				'errorType' => 'api',
			];
		}

		arsort( $probs );

		return [ 'verdict' => (string) array_key_first( $probs ), 'p_done' => $probs['done'], 'error' => null, 'errorType' => null ];
	}

	/**
	 * PURE. The three labels' probabilities from the first non-blank token's
	 * alternatives, normalized over the three. Null when none of them appears.
	 *
	 * @return ?array<string,float>
	 */
	public function labelProbabilities( array $logprobs ): ?array {
		$first = null;
		foreach ( $logprobs as $entry ) {
			if ( '' !== trim( (string) ( $entry['token'] ?? '' ) ) ) { $first = $entry; break; }
		}
		if ( null === $first ) { return null; }

		$p = array_fill_keys( self::LABELS, 0.0 );
		foreach ( $first['top_logprobs'] ?? [] as $alt ) {
			$word = strtolower( trim( (string) ( $alt['token'] ?? '' ) ) );
			if ( isset( $p[ $word ] ) ) { $p[ $word ] += exp( (float) $alt['logprob'] ); }
		}

		$z = array_sum( $p );
		if ( $z <= 0 ) { return null; }

		return array_map( static fn( float $v ): float => $v / $z, $p );
	}

	private function ollama(): Ollama {
		return $this->ollama ??= new Ollama();
	}
}
