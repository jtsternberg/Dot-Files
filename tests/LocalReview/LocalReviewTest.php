<?php
namespace JT\Tests\LocalReview;

use JT\LocalReview;
use JT\Tests\TestCase;

final class LocalReviewTest extends TestCase {

	const DIFF = "diff --git a/x.py b/x.py\n-a = 1\n+a = 2\n";

	private function review( StubReviewOllama $ollama, string $diff = self::DIFF, ?string $primary = 'primary', ?string $advisor = 'advisor' ): array {
		return ( new LocalReview( $ollama, '/home/jt' ) )->review( $diff, $primary, $advisor );
	}

	private function callFor( StubReviewOllama $ollama, string $model ): array {
		foreach ( $ollama->calls as $call ) {
			if ( 'chat' === $call['call'] && $model === $call['model'] ) {
				return $call;
			}
		}
		$this->fail( "no chat call for {$model}" );
	}

	public function testThePrimaryRechecksInTheSameConversationUntilConfident(): void {
		$ollama = new StubReviewOllama( [], false, [
			'primary' => [ StubReviewOllama::reply( 'first list' ), StubReviewOllama::reply( "corrected list\n\nCONFIDENT" ) ],
		] );

		$result = $this->review( $ollama, self::DIFF, 'primary', 'none' );

		$this->assertSame( 2, $result['primary']['rounds'] );
		$this->assertTrue( $result['primary']['confident'] );
		$recheck = $ollama->calls[1]['messages'];
		$this->assertSame( [ 'user', 'assistant', 'user' ], array_column( $recheck, 'role' ) );
		$this->assertStringContainsString( self::DIFF, $recheck[0]['content'] );
		$this->assertSame( 'first list', $recheck[1]['content'] );
		$this->assertSame( LocalReview::RECHECK_PROMPT, $recheck[2]['content'] );
	}

	public function testTheReviewIsAFinalTurnThatOnlyWritesTheList(): void {
		$ollama = new StubReviewOllama( [], false, [
			'primary' => [
				StubReviewOllama::reply( 'first list' ),
				StubReviewOllama::reply( "line 10 looks fine, line 12 is a bug...\nCONFIDENT" ),
				StubReviewOllama::reply( "1. line 12: the bug\n" ),
			],
		] );

		$result = $this->review( $ollama, self::DIFF, 'primary', 'none' );

		$chats = array_values( array_filter( $ollama->calls, static fn( array $c ): bool => 'chat' === $c['call'] ) );
		$final = end( $chats )['messages'];
		$this->assertSame( LocalReview::FINAL_PROMPT, end( $final )['content'] );
		$this->assertSame( 'line 10 looks fine, line 12 is a bug...', $final[ count( $final ) - 2 ]['content'] );
		$this->assertSame( '1. line 12: the bug', $result['primary']['review'] );
		$this->assertSame( [ 'chat:primary', 'chat:primary', 'chat:primary', 'unload:primary' ], $ollama->sequence() );
	}

	public function testAFailedFinalTurnFallsBackToTheLastRecheck(): void {
		$ollama = new StubReviewOllama( [], false, [
			'primary' => [
				StubReviewOllama::reply( "the list\nCONFIDENT" ),
				[ 'content' => null, 'error' => 'timed out', 'errorType' => 'transport' ],
			],
		] );

		$result = $this->review( $ollama, self::DIFF, 'primary', 'none' );

		$this->assertSame( 'the list', $result['primary']['review'] );
		$this->assertSame( 'timed out', $result['primary']['error'] );
	}

	public function testThePrimaryStopsRecheckingAtTheCap(): void {
		$ollama = new StubReviewOllama( [], false, [ 'primary' => [ StubReviewOllama::reply( 'never sure' ) ] ] );

		$result = $this->review( $ollama, self::DIFF, 'primary', 'none' );

		$this->assertSame( 1 + LocalReview::MAX_RECHECKS, $result['primary']['rounds'] );
		$this->assertFalse( $result['primary']['confident'] );
		$this->assertCount( 1 + LocalReview::MAX_RECHECKS + 1, array_keys( $ollama->sequence(), 'chat:primary', true ) );
	}

	public function testTheAdvisorLoadsOnlyAfterThePrimaryIsUnloaded(): void {
		$ollama = new StubReviewOllama( [], false, [ 'primary' => [ StubReviewOllama::reply( "list\nCONFIDENT" ) ] ] );

		$this->review( $ollama );

		$this->assertSame( [ 'chat:primary', 'chat:primary', 'unload:primary', 'chat:advisor', 'unload:advisor' ], $ollama->sequence() );
	}

	public function testTheAdvisorGetsOneLongPassWithItsReasoningLeftOn(): void {
		$ollama = new StubReviewOllama( [], false, [
			'primary' => [ StubReviewOllama::reply( "list\nCONFIDENT" ) ],
			'advisor' => [ StubReviewOllama::reply( 'advisor findings' ) ],
		] );

		$result = $this->review( $ollama );

		$advisorCall = $this->callFor( $ollama, 'advisor' );
		$this->assertArrayNotHasKey( 'think', $advisorCall['request'] );
		$this->assertSame( LocalReview::ADVISOR_NUM_PREDICT, $advisorCall['request']['options']['num_predict'] );
		$this->assertSame( 'advisor findings', $result['advisor']['review'] );
	}

	public function testEachModelsContextHoldsTheDiffPlusItsWholeOutputBudget(): void {
		$ollama = new StubReviewOllama( [], false, [ 'primary' => [ StubReviewOllama::reply( "list\nCONFIDENT" ) ] ] );

		$this->review( $ollama );

		$primary = $ollama->calls[0]['request']['options'];
		$advisor = $this->callFor( $ollama, 'advisor' )['request']['options'];
		$this->assertGreaterThan( LocalReview::PRIMARY_NUM_PREDICT * ( 1 + LocalReview::MAX_RECHECKS ) / 2, $primary['num_ctx'] );
		$this->assertGreaterThan( LocalReview::ADVISOR_NUM_PREDICT, $advisor['num_ctx'] );
	}

	public function testAnEmptyAdvisorReplyIsAnErrorThatNamesTheOutputBudget(): void {
		$ollama = new StubReviewOllama( [], false, [
			'primary' => [ StubReviewOllama::reply( "list\nCONFIDENT" ) ],
			'advisor' => [ StubReviewOllama::reply( "  \n" ) ],
		] );

		$result = $this->review( $ollama );

		$this->assertSame( 'empty', $result['advisor']['errorType'] );
		$this->assertStringContainsString( 'num_predict', $result['advisor']['error'] );
	}

	public function testReportsEachModelAsItStarts(): void {
		$ollama = new StubReviewOllama( [], false, [ 'primary' => [ StubReviewOllama::reply( "list\nCONFIDENT" ) ] ] );
		$seen   = [];

		( new LocalReview( $ollama, '/home/jt' ) )->review( self::DIFF, 'primary', 'advisor', static function ( string $role, string $model ) use ( &$seen ): void {
			$seen[] = "$role:$model";
		} );

		$this->assertSame( [ 'primary:primary', 'advisor:advisor' ], $seen );
	}

	public function testAnAdvisorOfNoneSkipsTheSecondReview(): void {
		$ollama = new StubReviewOllama( [], false, [ 'primary' => [ StubReviewOllama::reply( "list\nCONFIDENT" ) ] ] );

		$result = $this->review( $ollama, self::DIFF, 'primary', 'none' );

		$this->assertNull( $result['advisor'] );
		$this->assertSame( [ 'chat:primary', 'chat:primary', 'unload:primary' ], $ollama->sequence() );
	}

	public function testAFailedPrimaryStillLeavesTheAdvisorToRun(): void {
		$ollama = new StubReviewOllama( [], false, [
			'primary' => [ [ 'content' => null, 'error' => 'model not found', 'errorType' => 'api' ] ],
		] );

		$result = $this->review( $ollama );

		$this->assertSame( 'api', $result['primary']['errorType'] );
		$this->assertSame( 1, $result['primary']['rounds'] );
		$this->assertSame( 'No bugs found.', $result['advisor']['review'] );
	}

	public function testResolvesBothModelsFromTheirOwnConfigKeysPerStore(): void {
		$config = [
			'MODEL'                      => 'commit-model',
			'REVIEW_MODEL_SD'            => 'big-primary',
			'REVIEW_MODEL_LOCAL'         => 'small-primary',
			'REVIEW_ADVISOR_MODEL_SD'    => 'big-advisor',
			'REVIEW_ADVISOR_MODEL_LOCAL' => 'none',
		];

		$sd    = new LocalReview( new StubReviewOllama( $config, '/Volumes/AI-LAB/ollama/models' ), '/home/jt' );
		$local = new LocalReview( new StubReviewOllama( $config, '/home/jt/.ollama-local' ), '/home/jt' );

		$this->assertSame( 'big-primary', $sd->resolvePrimary() );
		$this->assertSame( 'big-advisor', $sd->resolveAdvisor() );
		$this->assertSame( 'small-primary', $local->resolvePrimary() );
		$this->assertNull( $local->resolveAdvisor() );
	}

	public function testWithNoConfigThereIsNoAdvisor(): void {
		$review = new LocalReview( new StubReviewOllama(), '/home/jt' );

		$this->assertSame( LocalReview::DEFAULT_PRIMARY, $review->resolvePrimary() );
		$this->assertNull( $review->resolveAdvisor() );
	}

	public function testAnEmptyDiffFailsWithoutCallingAnyModel(): void {
		$ollama = new StubReviewOllama();

		$result = $this->review( $ollama, " \n" );

		$this->assertSame( 'input', $result['errorType'] );
		$this->assertSame( [], $ollama->calls );
	}

	public function testTheContextGrowsWithTheDiffSoNothingTruncates(): void {
		$small = new StubReviewOllama( [], false, [ 'primary' => [ StubReviewOllama::reply( "x\nCONFIDENT" ) ] ] );
		$large = new StubReviewOllama( [], false, [ 'primary' => [ StubReviewOllama::reply( "x\nCONFIDENT" ) ] ] );

		$this->review( $small, self::DIFF, 'primary', 'none' );
		$this->review( $large, self::DIFF . str_repeat( "+line of code here\n", 20000 ), 'primary', 'none' );

		$smallCtx = $small->calls[0]['request']['options']['num_ctx'];
		$largeCtx = $large->calls[0]['request']['options']['num_ctx'];
		$this->assertLessThan( $largeCtx, $smallCtx );
		$this->assertGreaterThan( 380000 / 4, $largeCtx );
		$this->assertSame( 0, $largeCtx % 2048 );
	}
}
