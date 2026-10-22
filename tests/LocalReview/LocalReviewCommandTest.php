<?php
namespace JT\Tests\LocalReview;

use JT\CLI\Command\Dispatcher;
use JT\LocalReview;
use JT\LocalReviewCommand;
use JT\Tests\TestCase;

final class LocalReviewCommandTest extends TestCase {

	const DIFF = "diff --git a/x.py b/x.py\n-a = 1\n+a = 2\n";

	/** @var array<int,string[]> */
	private array $gitCalls = [];

	private string $diffFile;

	protected function setUp(): void {
		parent::setUp();
		$this->diffFile = sys_get_temp_dir() . '/local-review-' . uniqid() . '.diff';
		file_put_contents( $this->diffFile, "diff from a file\n" );
	}

	protected function tearDown(): void {
		@unlink( $this->diffFile );
	}

	/** @return array{0:int, 1:string} [exit code, captured stdout] */
	private function dispatch( array $args, StubReviewOllama $ollama, string $gitOut = self::DIFF, int $gitCode = 0, string $stdin = '' ): array {
		$this->cli->setArgs( array_merge( [ 'local-review' ], $args ) );
		$git = function ( array $gitArgs ) use ( $gitOut, $gitCode ): array {
			$this->gitCalls[] = $gitArgs;

			return [ $gitOut, $gitCode ];
		};
		$handler = new LocalReviewCommand( $this->cli, new LocalReview( $ollama, '/home/jt' ), $git, static fn(): string => $stdin );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		return [ $code, $output ];
	}

	private function confidentOllama(): StubReviewOllama {
		return new StubReviewOllama( [], false, [
			'p' => [ StubReviewOllama::reply( "primary list\nCONFIDENT" ) ],
			'a' => [ StubReviewOllama::reply( 'advisor list' ) ],
		] );
	}

	public function testReviewsTheWorkingTreeAgainstHeadByDefault(): void {
		$ollama = $this->confidentOllama();

		[ $code ] = $this->dispatch( [ '--primary=p', '--advisor=a' ], $ollama );

		$this->assertSame( 0, $code );
		$this->assertSame( [ [ 'diff', '--no-color', '--no-ext-diff', 'HEAD' ] ], $this->gitCalls );
		$this->assertStringContainsString( self::DIFF, $ollama->calls[0]['messages'][0]['content'] );
	}

	public function testBaseReviewsTheBranchSinceItForkedFromTheRef(): void {
		$this->dispatch( [ '--base=main', '--primary=p', '--advisor=a' ], $this->confidentOllama() );

		$this->assertSame( [ [ 'diff', '--no-color', '--no-ext-diff', 'main...HEAD' ] ], $this->gitCalls );
	}

	public function testADiffFileIsReviewedWithoutGit(): void {
		$ollama = $this->confidentOllama();

		$this->dispatch( [ $this->diffFile, '--primary=p', '--advisor=a' ], $ollama );

		$this->assertSame( [], $this->gitCalls );
		$this->assertStringContainsString( 'diff from a file', $ollama->calls[0]['messages'][0]['content'] );
	}

	public function testStdinIsReviewedWithoutGit(): void {
		$ollama = $this->confidentOllama();

		$this->dispatch( [ '--stdin', '--primary=p', '--advisor=a' ], $ollama, self::DIFF, 0, "diff from stdin\n" );

		$this->assertSame( [], $this->gitCalls );
		$this->assertStringContainsString( 'diff from stdin', $ollama->calls[0]['messages'][0]['content'] );
	}

	public function testPrintsBothReviewsUnderTheirOwnHeadings(): void {
		[ $code, $output ] = $this->dispatch( [ '--primary=p', '--advisor=a' ], $this->confidentOllama() );

		$this->assertSame( 0, $code );
		$this->assertMatchesRegularExpression( '/^## Primary review: p \(1 round, confident, \d+s\)\n\nprimary list\n/m', $output );
		$this->assertMatchesRegularExpression( '/^## Advisor review: a \(\d+s\)\n\nadvisor list\n/m', $output );
	}

	public function testAFailedReviewIsShownInItsSectionAndTheOtherStillCounts(): void {
		$ollama = new StubReviewOllama( [], false, [
			'p' => [ StubReviewOllama::reply( "primary list\nCONFIDENT" ) ],
			'a' => [ [ 'content' => null, 'error' => 'model not found', 'errorType' => 'api' ] ],
		] );

		[ $code, $output ] = $this->dispatch( [ '--primary=p', '--advisor=a' ], $ollama );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'Failed: model not found', $output );
	}

	public function testFailsWhenNoReviewSucceeded(): void {
		$ollama = new StubReviewOllama( [], false, [ 'p' => [ [ 'content' => null, 'error' => 'boom', 'errorType' => 'transport' ] ] ] );

		[ $code ] = $this->dispatch( [ '--primary=p', '--advisor=none' ], $ollama );

		$this->assertSame( 1, $code );
	}

	public function testAnEmptyDiffFailsWithoutCallingAModel(): void {
		$ollama = $this->confidentOllama();

		[ $code ] = $this->dispatch( [ '--primary=p', '--advisor=a' ], $ollama, '' );

		$this->assertSame( 1, $code );
		$this->assertSame( [], $ollama->calls );
	}

	public function testAGitFailureFailsWithoutCallingAModel(): void {
		$ollama = $this->confidentOllama();

		[ $code ] = $this->dispatch( [ '--base=nope', '--primary=p', '--advisor=a' ], $ollama, "fatal: bad revision\n", 128 );

		$this->assertSame( 1, $code );
		$this->assertSame( [], $ollama->calls );
	}

	public function testJsonCarriesBothReviews(): void {
		[ $code, $output ] = $this->dispatch( [ '--primary=p', '--advisor=a', '--json' ], $this->confidentOllama() );

		$data = json_decode( $output, true );
		$this->assertSame( 0, $code );
		$this->assertSame( 'primary list', $data['primary']['review'] );
		$this->assertSame( 1, $data['primary']['rounds'] );
		$this->assertTrue( $data['primary']['confident'] );
		$this->assertSame( 'advisor list', $data['advisor']['review'] );
		$this->assertArrayHasKey( 'duration_ms', $data['advisor'] );
	}
}
