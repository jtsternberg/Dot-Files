<?php
namespace JT\Tests\AutoCommitFm;

use JT\AutoCommitFmCommand;
use JT\CLI\Command\Dispatcher;
use JT\Helpers\FoundationModels;
use JT\Tests\TestCase;

/** A FoundationModels whose responses are canned; fitting is FoundationModelsTest's job. */
final class StubFoundationModels extends FoundationModels {

	/** @var array<int,array{0:string,1:string,2:string}> [instructions, prompt, model] */
	public array $responds = [];

	/** @var array<int,array{content:?string,error:?string}> */
	private array $responses;

	public function __construct( array ...$responses ) {
		parent::__construct( static fn(): array => [ 'stdout' => '', 'stderr' => '', 'exitCode' => 0 ] );

		$this->responses = empty( $responses )
			? [ [ 'content' => 'fix a thing', 'error' => null ] ]
			: $responses;
	}

	public function respond( string $instructions, string $prompt, string $model = self::DEFAULT_MODEL ): array {
		$this->responds[] = [ $instructions, $prompt, $model ];

		return $this->responses[ count( $this->responds ) - 1 ] ?? end( $this->responses );
	}
}

final class AutoCommitFmCommandTest extends TestCase {

	/** @var array<int,string> */
	private array $shellCalls = [];

	/** @var array<string,string> Needle => canned output, first match wins. */
	private array $shellMap = [];

	/** @var array<int,string> */
	private array $runs = [];

	private array $runResult = [ 'output' => '', 'error' => '', 'exitCode' => 0 ];

	private array $config = [];

	protected function setUp(): void {
		parent::setUp();

		$this->shellMap = [
			'diff --cached --name-only'       => 'src/foo.php',
			'pr-description-generator'        => 'Changed files: src/foo.php',
			'git log --format='               => 'abc1234 fix an earlier thing',
			'git log -1 --pretty=format:"%h"' => 'newhash',
		];
	}

	/** @return array{0:int, 1:string} [exit code, captured output] */
	private function dispatch( array $args, ?FoundationModels $fm = null ): array {
		$this->cli->setArgs( array_merge( [ 'auto-commit-fm' ], $args ) );

		$handler = new AutoCommitFmCommand(
			$this->cli,
			$fm ?: new StubFoundationModels(),
			fn(): array => $this->config,
			function ( string $command ): string {
				$this->shellCalls[] = $command;
				foreach ( $this->shellMap as $needle => $output ) {
					if ( str_contains( $command, $needle ) ) {
						return $output;
					}
				}

				return '';
			},
			function ( string $command ): array {
				$this->runs[] = $command;

				return $this->runResult;
			},
			static function (): void {},
			static function (): void {}
		);

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		return [ $code, $output ];
	}

	public function testGeneratesACommitMessageWithFmAndCommitsIt(): void {
		$fm = new StubFoundationModels();

		[ $code, $output ] = $this->dispatch( [ 'ship the parser' ], $fm );

		$this->assertSame( 0, $code );
		$this->assertSame( [ "git commit -m 'fix a thing'" ], $this->runs );
		$this->assertStringContainsString( 'Commit newhash:', $output );
		$this->assertStringContainsString( 'abc1234 fix an earlier thing', $fm->responds[0][0] );
		$this->assertStringContainsString( 'Changed files: src/foo.php', $fm->responds[0][1] );
		$this->assertStringContainsString( 'Context from the developer: ship the parser', $fm->responds[0][1] );
		$this->assertSame( FoundationModels::DEFAULT_MODEL, $fm->responds[0][2] );
	}

	public function testModelFlagIsPassedToFm(): void {
		$fm = new StubFoundationModels();

		$this->dispatch( [ '--model=other' ], $fm );

		$this->assertSame( 'other', $fm->responds[0][2] );
	}

	public function testAppendsTheFmSignatureWithTheModelFilledIn(): void {
		$this->config = [
			'AUTO_COMMIT_FM_SIGNATURE'     => 'Co-Authored-By: bot (<model>)',
			'AUTO_COMMIT_OLLAMA_SIGNATURE' => 'Co-Authored-By: wrong one',
		];

		$this->dispatch( [] );

		$this->assertSame(
			'git commit -m ' . escapeshellarg( "fix a thing\n\nCo-Authored-By: bot (apple-fm/system)" ),
			$this->runs[0]
		);
	}

	public function testReportsAnFmFailureWithAnAvailabilityHintAndDoesNotCommit(): void {
		$fm = new StubFoundationModels( [ 'content' => null, 'error' => 'Model is not available' ] );

		[ $code, $output ] = $this->dispatch( [], $fm );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Error from Apple Foundation Models: Model is not available', $output );
		$this->assertStringContainsString( 'fm available', $output );
		$this->assertSame( [], $this->runs );
	}

	public function testReportsAnEmptyResponseAsAFailureToGenerate(): void {
		$fm = new StubFoundationModels( [ 'content' => '', 'error' => null ] );

		[ $code, $output ] = $this->dispatch( [], $fm );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString(
			'Error: Failed to get a commit message from Apple Foundation Models.',
			$output
		);
	}

	public function testRetryOnHeadCritiquesThenAmends(): void {
		$this->shellMap = [
			'rev-parse --verify' => 'fullhash',
			'rev-parse --short'  => 'short',
			'rev-parse HEAD'     => 'fullhash',
			'pretty=format:"%B"' => 'did stuff',
			'git diff fullhash'  => '+code',
		] + $this->shellMap;
		$fm = new StubFoundationModels(
			[ 'content' => 'too vague', 'error' => null ],
			[ 'content' => 'fix the parser', 'error' => null ]
		);

		[ $code ] = $this->dispatch( [ '--retry' ], $fm );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'commit message critic', $fm->responds[0][0] );
		$this->assertStringContainsString( 'Retry correction: too vague', $fm->responds[1][1] );
		$this->assertSame( [ "git commit --amend -m 'fix the parser'" ], $this->runs );
	}

	// --- interface ----------------------------------------------------------

	public function testHelpAdvertisesEveryFlag(): void {
		[ $code, $output ] = $this->dispatch( [ '--help' ] );

		$this->assertSame( 0, $code );
		foreach ( [ '[--retry[=<hash>]]', '[--model=<model>]', '[--retryReason=<reason>]', '[--debug]', '[--say]', '[--noprint]', '[<context>]' ] as $flag ) {
			$this->assertStringContainsString( $flag, $output );
		}
	}

	public function testCompletionGeneratesAZshFunction(): void {
		[ $code, $output ] = $this->dispatch( [ 'completion', 'zsh' ] );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '#compdef auto-commit-fm', $output );
		$this->assertStringContainsString( 'compdef _auto_commit_fm auto-commit-fm', $output );
	}

	public function testCompletionPluginLazyLoadsGeneratedOutput(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$binDir   = sys_get_temp_dir() . '/auto-commit-fm-completion-' . uniqid();
		$calls    = $binDir . '/calls';
		$stub     = $binDir . '/auto-commit-fm';

		$this->assertStringContainsString( 'command auto-commit-fm completion zsh', $contents );

		mkdir( $binDir, 0777, true );
		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_auto_commit_fm() { return 0; }\n"
			. "compdef _auto_commit_fm auto-commit-fm\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[auto-commit-fm]} == _auto_commit_fm_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_auto_commit_fm_lazy || exit 12
[[ ${_comps[auto-commit-fm]} == _auto_commit_fm ]] || exit 13
ZSH;
		exec(
			'PATH=' . escapeshellarg( $binDir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc ' . escapeshellarg( $script )
			. ' -- ' . escapeshellarg( $plugin ) . ' ' . escapeshellarg( $calls ),
			$output,
			$code
		);

		$this->assertSame( 0, $code );
		$this->assertSame( "completion zsh\n", file_get_contents( $calls ) );

		@unlink( $calls );
		@unlink( $stub );
		@rmdir( $binDir );
	}
}
