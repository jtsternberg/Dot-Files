<?php
namespace JT\Tests\LocalLlmSummarizer;

use JT\Helpers\Ollama;
use JT\LocalLlmSummarizer;
use JT\Tests\TestCase;

final class LocalLlmSummarizerTest extends TestCase {

	private const TRANSCRIPT = <<<'MD'
# fix the flaky login test

- session `abc`
- cwd `/Users/jt/Code/app`
- 2026-09-17T18:46:56.339Z → 2026-09-17T19:03:45.336Z

**You:** The login test in   tests/LoginTest.php
fails every third run. Find out why.

**You:** <skill>
 <name>some-skill</name>
 lots of injected skill text that is not the user's words
</skill>

**Claude:** Looking at the test first.

**Claude:** The flake is a shared session cookie between tests.

**You:** ok fix it and commit

**Claude:** Fixed by resetting the cookie jar in setUp(); committed as 3ecbf89.
MD;

	private function summarizer( ?StubSummaryOllama $ollama = null ): LocalLlmSummarizer {
		return new LocalLlmSummarizer( $ollama ?? new StubSummaryOllama(), '/home/jt' );
	}

	public function testSplitTitlePullsTheTitleLineOffTheFront(): void {
		$this->assertSame(
			[ 'title' => 'Login test cookie flake', 'text' => 'It fixes a flake. It committed.' ],
			LocalLlmSummarizer::splitTitle( "TITLE: \"Login test cookie flake.\"\nIt fixes a flake. It committed." )
		);
	}

	public function testSplitTitleKeepsTheProseWhenTheModelDropsTheLabel(): void {
		$this->assertSame(
			[ 'title' => null, 'text' => 'Just prose.' ],
			LocalLlmSummarizer::splitTitle( "  Just prose.\n" )
		);
	}

	public function testSplitTitleCapsARunawayTitle(): void {
		$title = LocalLlmSummarizer::splitTitle( 'TITLE: ' . str_repeat( 'word ', 40 ) . "\nx" )['title'];

		$this->assertLessThanOrEqual( 80, mb_strlen( $title ) );
		$this->assertStringEndsWith( '…', $title );
	}

	public function testDigestKeepsTheUsersWordsSquashedAndDropsInjectedBlocks(): void {
		$digest = $this->summarizer()->digestTranscript( self::TRANSCRIPT );

		$this->assertStringContainsString( 'Original title: fix the flaky login test', $digest );
		$this->assertStringContainsString( 'Project: app', $digest );
		$this->assertStringContainsString( '- The login test in tests/LoginTest.php fails every third run. Find out why.', $digest );
		$this->assertStringContainsString( '- ok fix it and commit', $digest );
		$this->assertStringNotContainsString( 'injected skill text', $digest );
		$this->assertStringContainsString( 'Fixed by resetting the cookie jar in setUp(); committed as 3ecbf89.', $digest );
	}

	public function testDigestTrimsALongSessionToItsOpeningAndClosingInstructions(): void {
		$md = "# t\n\n";
		for ( $i = 1; $i <= 30; $i++ ) {
			$md .= "**You:** prompt number {$i}\n\n**Claude:** reply {$i}\n\n";
		}

		$digest = $this->summarizer()->digestTranscript( $md );

		$this->assertStringContainsString( 'prompt number 1', $digest );
		$this->assertStringContainsString( 'prompt number 30', $digest );
		$this->assertStringNotContainsString( 'prompt number 12', $digest );
		$this->assertStringContainsString( 'more instructions omitted', $digest );
		$this->assertStringNotContainsString( 'reply 5', $digest );
		$this->assertStringContainsString( 'reply 30', $digest );
	}

	public function testDigestListsToolCallsAsActionsAndKeepsThemOutOfTheReplies(): void {
		$md = "**You:** run the suite\n\n**Claude:** Running it now.\n  ↳ `Bash: composer test`, `Read: README.md`\n      OK (40 tests)\n      more output\nAll green.\n";

		$digest = $this->summarizer()->digestTranscript( $md );

		$this->assertStringContainsString( "- Bash: composer test\n- Read: README.md", $digest );
		$this->assertStringContainsString( 'Running it now. All green.', $digest );
		$this->assertStringNotContainsString( 'OK (40 tests)', $digest );
	}

	public function testDigestCutsAnOverlongPrompt(): void {
		$digest = $this->summarizer()->digestTranscript( "**You:** " . str_repeat( 'a', 2000 ) . "\n" );

		$this->assertStringNotContainsString( str_repeat( 'a', 300 ), $digest );
		$this->assertStringContainsString( '…', $digest );
	}

	public function testSummarizeSessionUsesTheSummaryModelKeysNotTheCommitModel(): void {
		$ollama = new StubSummaryOllama(
			[ 'MODEL' => 'qwen3-coder', 'MODEL_SD' => 'qwen3-coder', 'SUMMARY_MODEL_SD' => 'gemma-big' ],
			Ollama::SD_PATH
		);

		$result = $this->summarizer( $ollama )->summarizeSession( self::TRANSCRIPT );

		$this->assertSame( 'gemma-big', $ollama->chats[0]['model'] );
		$this->assertSame( 'gemma-big', $result['model'] );
	}

	public function testSummarizeSessionDefaultsToAModelInTheLocalStore(): void {
		$ollama = new StubSummaryOllama( [ 'MODEL' => 'qwen3-coder' ], '/Users/jt/.ollama/models' );

		$this->summarizer( $ollama )->summarizeSession( self::TRANSCRIPT );

		$this->assertSame( LocalLlmSummarizer::DEFAULT_MODEL, $ollama->chats[0]['model'] );
	}

	public function testAnExplicitModelWins(): void {
		$ollama = new StubSummaryOllama( [ 'SUMMARY_MODEL' => 'configured' ] );

		$this->summarizer( $ollama )->summarizeSession( self::TRANSCRIPT, 'forced' );

		$this->assertSame( 'forced', $ollama->chats[0]['model'] );
	}

	public function testSummarizeSessionDisablesThinkingAndSendsTheDigest(): void {
		$ollama = new StubSummaryOllama();

		$this->summarizer( $ollama )->summarizeSession( self::TRANSCRIPT );
		$chat = $ollama->chats[0];

		$this->assertFalse( $chat['request']['think'] );
		$this->assertSame( LocalLlmSummarizer::TIMEOUT_SECONDS, $chat['timeout'] );
		$this->assertStringContainsString( 'TITLE:', $chat['user'] );
		$this->assertStringContainsString( 'ok fix it and commit', $chat['user'] );
	}

	public function testSummarizeSessionReturnsTheParsedTitleAndText(): void {
		$ollama = new StubSummaryOllama( [], false, [
			'content'   => "TITLE: Login test cookie flake\nIt fixes a flaky login test. It reset the cookie jar and committed.",
			'error'     => null,
			'errorType' => null,
		] );

		$result = $this->summarizer( $ollama )->summarizeSession( self::TRANSCRIPT );

		$this->assertNull( $result['error'] );
		$this->assertSame( 'Login test cookie flake', $result['title'] );
		$this->assertSame( 'It fixes a flaky login test. It reset the cookie jar and committed.', $result['text'] );
	}

	public function testSummarizeSessionPassesOllamaFailuresThrough(): void {
		$ollama = new StubSummaryOllama( [], false, [ 'content' => null, 'error' => 'Connection refused', 'errorType' => 'transport' ] );

		$result = $this->summarizer( $ollama )->summarizeSession( self::TRANSCRIPT );

		$this->assertSame( 'Connection refused', $result['error'] );
		$this->assertSame( 'transport', $result['errorType'] );
		$this->assertNull( $result['text'] );
	}

	public function testAnEmptyReplyIsAnError(): void {
		$ollama = new StubSummaryOllama( [], false, [ 'content' => "  \n", 'error' => null, 'errorType' => null ] );

		$result = $this->summarizer( $ollama )->summarizeSession( self::TRANSCRIPT );

		$this->assertSame( 'empty', $result['errorType'] );
	}

	public function testATranscriptWithNothingToSummarizeNeverCallsTheModel(): void {
		$ollama = new StubSummaryOllama();

		$result = $this->summarizer( $ollama )->summarizeSession( "# \n\n" );

		$this->assertSame( [], $ollama->chats );
		$this->assertSame( 'input', $result['errorType'] );
	}

	public function testSummarizeTextSizesTheContextToTheInput(): void {
		$ollama = new StubSummaryOllama( [], false, [ 'content' => 'The file is a list.', 'error' => null, 'errorType' => null ] );

		$small = $this->summarizer( $ollama )->summarizeText( 'short text' );
		$this->summarizer( $ollama )->summarizeText( str_repeat( 'word ', 40000 ) );

		$this->assertSame( 'The file is a list.', $small['text'] );
		$this->assertNull( $small['title'] );
		$this->assertSame( 8192, $ollama->chats[0]['request']['options']['num_ctx'] );
		$this->assertGreaterThan( 50000, $ollama->chats[1]['request']['options']['num_ctx'] );
		$this->assertNotSame( '', $ollama->chats[0]['system'] );
	}
}
