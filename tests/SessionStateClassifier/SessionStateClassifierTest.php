<?php
namespace JT\Tests\SessionStateClassifier;

use JT\Helpers\Ollama;
use JT\SessionStateClassifier;
use JT\Tests\TestCase;

/**
 * The done/waiting/working read of a session's last assistant message.
 *
 * The recipe (prompt, request fields, first-token logprob normalization) is a port
 * of the 2026-09-30 bench, so these tests pin it rather than describe it: a change
 * to any of it invalidates the measured accuracy. Every model call goes through an
 * injected post stub — no test reaches Ollama.
 */
final class SessionStateClassifierTest extends TestCase {

	private ?array $sent = null;
	private int $posts = 0;
	private string $xdg;

	/** An empty config root, so no test resolves the model from the real machine's config. */
	protected function setUp(): void {
		parent::setUp();
		$this->xdg = $this->graveyardRoot . '/xdg';
		mkdir( $this->xdg . '/ai-tooling', 0777, true );
		putenv( 'XDG_CONFIG_HOME=' . $this->xdg );
	}

	protected function tearDown(): void {
		putenv( 'XDG_CONFIG_HOME' );
		parent::tearDown();
	}

	private function classifier( $response, string $error = '' ): SessionStateClassifier {
		$post = function ( string $url, string $payload, int $timeout ) use ( $response, $error ): array {
			$this->posts++;
			$this->sent = json_decode( $payload, true );
			return [ $error === '' ? json_encode( $response ) : null, $error ];
		};

		return new SessionStateClassifier( new Ollama( static fn(): bool => false, $post ), '/home/nobody' );
	}

	private static function first( array $top, string $token = 'done' ): array {
		return [ 'message' => [ 'content' => $token ], 'logprobs' => [
			[ 'token' => $token, 'logprob' => -0.1, 'top_logprobs' => $top ],
		] ];
	}

	private static function alt( string $token, float $p ): array {
		return [ 'token' => $token, 'logprob' => log( $p ) ];
	}

	public function test_it_sends_the_bench_recipe_verbatim(): void {
		$this->classifier( self::first( [ self::alt( 'done', 0.9 ) ] ) )->classify( 'All finished.' );

		$this->assertSame( SessionStateClassifier::DEFAULT_MODEL, $this->sent['model'] );
		$this->assertFalse( $this->sent['stream'] );
		$this->assertFalse( $this->sent['think'] );
		$this->assertSame( '10m', $this->sent['keep_alive'] );
		$this->assertTrue( $this->sent['logprobs'] );
		$this->assertSame( 10, $this->sent['top_logprobs'] );
		$this->assertSame( [ 'temperature' => 0, 'num_predict' => 3 ], $this->sent['options'] );
		$this->assertSame( [
			[ 'role' => 'system', 'content' => "Classify the last message an AI coding agent sent its user. Reply with exactly one word: done, waiting, or working.\ndone: the requested work is finished; nothing waits on a reply. Soft optional offers ('say the word if you want X') and tasks left for the user to do elsewhere ('Next for you:' steps, commands to run, PRs to review) still count as done.\nwaiting: the agent is blocked until the user replies in this conversation: a direct question, a choice it is waiting on, or a request to report back ('tell me when it is done').\nworking: the agent is still working: it narrates its next step, or waits on another agent or background job." ],
			[ 'role' => 'user', 'content' => 'All finished.' ],
		], $this->sent['messages'] );
	}

	public function test_the_user_message_is_the_last_1500_characters(): void {
		$text = str_repeat( 'é', 100 ) . str_repeat( 'x', 1500 );
		$this->classifier( self::first( [ self::alt( 'done', 0.9 ) ] ) )->classify( $text );

		$this->assertSame( str_repeat( 'x', 1500 ), $this->sent['messages'][1]['content'] );
	}

	public function test_it_normalizes_the_first_non_blank_tokens_label_alternatives(): void {
		$response = [ 'message' => [ 'content' => ' working' ], 'logprobs' => [
			[ 'token' => ' ', 'logprob' => -0.01, 'top_logprobs' => [ self::alt( 'done', 0.99 ) ] ],
			[ 'token' => 'working', 'logprob' => -0.2, 'top_logprobs' => [
				self::alt( 'working', 0.5 ),
				self::alt( ' Working', 0.1 ),   // stripped + lowercased, so it sums into working
				self::alt( 'done', 0.3 ),
				self::alt( 'wait', 0.05 ),      // not exactly a label: ignored
				self::alt( 'waiting', 0.1 ),
			] ],
		] ];

		$r = $this->classifier( $response )->classify( 'Running the suite next.' );

		$this->assertSame( 'working', $r['verdict'] );
		$this->assertEqualsWithDelta( 0.3 / 1.0, $r['p_done'], 1e-9 );
		$this->assertNull( $r['error'] );
	}

	public function test_an_unreachable_ollama_yields_no_verdict_and_says_why(): void {
		$r = $this->classifier( null, 'Connection refused' )->classify( 'x' );

		$this->assertNull( $r['verdict'] );
		$this->assertNull( $r['p_done'] );
		$this->assertSame( 'transport', $r['errorType'] );
		$this->assertSame( 'Connection refused', $r['error'] );
	}

	public function test_a_response_with_no_label_alternatives_yields_no_verdict(): void {
		$r = $this->classifier( self::first( [ self::alt( 'maybe', 0.9 ) ], 'maybe' ) )->classify( 'x' );

		$this->assertNull( $r['verdict'] );
		$this->assertNull( $r['p_done'] );
		$this->assertSame( 'api', $r['errorType'] );
	}

	public function test_a_hotline_completion_status_line_is_done_without_a_model_call(): void {
		$c = $this->classifier( self::first( [ self::alt( 'waiting', 0.9 ) ] ) );

		foreach ( [ "Report.\n\nSTATUS: WORK_COMPLETE call_id=abc", "Answer.\nSTATUS: DONE", "Report.\nSTATUS: WORK_COMPLETE call_id=abc\n\nHOTLINE_NOTE: one.\nTwo." ] as $text ) {
			$this->assertSame(
				[ 'verdict' => 'done', 'p_done' => 1.0, 'verdict_source' => 'status-line' ],
				$c->statusLineVerdict( $text )
			);
		}
		$this->assertSame( 0, $this->posts );
	}

	public function test_other_status_lines_and_inline_mentions_are_not_completions(): void {
		$c = $this->classifier( [] );

		$this->assertNull( $c->statusLineVerdict( "Step 1 done.\nSTATUS: AWAITING_REVIEW call_id=abc" ) );
		$this->assertNull( $c->statusLineVerdict( 'End with a STATUS: DONE line when finished?' ) );
		$this->assertNull(
			$c->statusLineVerdict( "The callee replied:\n\nSTATUS: WORK_COMPLETE call_id=x\n\nNow I'll wire it in.\nRunning tests.\nThen the docs." ),
			'a quoted STATUS line above the last 3 non-blank lines'
		);
	}

	public function test_the_model_comes_from_the_decision_model_config_keys(): void {
		file_put_contents( $this->xdg . '/ai-tooling/config', "MODEL=commit-model\nDECISION_MODEL=decider:1b\n" );

		$this->classifier( self::first( [ self::alt( 'done', 0.9 ) ] ) )->classify( 'x' );

		$this->assertSame( 'decider:1b', $this->sent['model'] );
	}

	public function test_the_fingerprint_changes_with_the_message(): void {
		$c = $this->classifier( [] );

		$this->assertSame( $c->fingerprint( 'a' ), $c->fingerprint( 'a' ) );
		$this->assertNotSame( $c->fingerprint( 'a' ), $c->fingerprint( 'b' ) );
	}
}
