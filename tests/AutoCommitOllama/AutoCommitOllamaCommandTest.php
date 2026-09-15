<?php
namespace JT\Tests\AutoCommitOllama;

use JT\AutoCommitOllamaCommand;
use JT\CLI\Command\Dispatcher;
use JT\Helpers\Ollama;
use JT\Tests\TestCase;

/**
 * An Ollama whose config file and chat responses are canned.
 *
 * Model resolution and the storage-location guidance still run the real
 * resolveModel()/storagePath(), driven by the stubbed readlink target, so these
 * tests cover how the command wires them up rather than re-testing OllamaTest.
 */
final class StubOllama extends Ollama {

	/** @var array<int,array{0:string,1:string,2:string}> */
	public array $chats = [];

	/** @var array<int,array{content:?string,error:?string,errorType:?string}> */
	private array $responses;

	public function __construct(
		private array $stubConfig = [],
		?string $storagePath = null,
		array ...$responses
	) {
		parent::__construct( static fn(): string|false => $storagePath ?? false );

		$this->responses = empty( $responses )
			? [ [ 'content' => 'fix a thing', 'error' => null, 'errorType' => null ] ]
			: $responses;
	}

	public function config( ?string $configDir = null ): array {
		return $this->stubConfig;
	}

	public function chat(
		string $model,
		string $systemPrompt,
		string $userPrompt,
		string $url = self::DEFAULT_URL,
		int $timeoutSeconds = 300
	): array {
		$this->chats[] = [ $model, $systemPrompt, $userPrompt ];

		return $this->responses[ count( $this->chats ) - 1 ] ?? end( $this->responses );
	}
}

final class AutoCommitOllamaCommandTest extends TestCase {

	/** @var array<int,string> */
	private array $shellCalls = [];

	/** @var array<string,string> Needle => canned output, first match wins. */
	private array $shellMap = [];

	/** @var array<int,string> */
	private array $runs = [];

	private array $runResult = [ 'output' => '', 'error' => '', 'exitCode' => 0 ];

	/** @var array<int,array{0:string,1:string}> */
	private array $writes = [];

	/** @var array<int,string> */
	private array $spoken = [];

	protected function setUp(): void {
		parent::setUp();

		$this->shellMap = [
			'diff --cached --name-only'      => "src/foo.php",
			'pr-description-generator'       => "Changed files: src/foo.php",
			'git log --format='              => "abc1234 fix an earlier thing",
			'git log -1 --pretty=format:"%h"' => 'newhash',
		];
	}

	private function handler( ?Ollama $ollama = null ): AutoCommitOllamaCommand {
		return new AutoCommitOllamaCommand(
			$this->cli,
			$ollama ?: new StubOllama(),
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
			function ( string $path, string $contents ): void {
				$this->writes[] = [ $path, $contents ];
			},
			function ( string $text ): void {
				$this->spoken[] = $text;
			}
		);
	}

	/** @return array{0:int, 1:string} [exit code, captured output] */
	private function dispatch( array $args, ?Ollama $ollama = null ): array {
		$this->cli->setArgs( array_merge( [ 'auto-commit-ollama' ], $args ) );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $this->handler( $ollama ) ) )->run();
		$output = (string) ob_get_clean();

		return [ $code, $output ];
	}

	private function shellSaw( string $needle ): bool {
		foreach ( $this->shellCalls as $call ) {
			if ( str_contains( $call, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	public function testGeneratesACommitMessageFromStagedChangesAndCommitsIt(): void {
		[ $code, $output ] = $this->dispatch( [] );

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $this->runs );
		$this->assertStringContainsString( "git commit -m 'fix a thing'", $this->runs[0] );
		$this->assertStringContainsString( 'Commit newhash:', $output );
		$this->assertStringContainsString( 'fix a thing', $output );
	}

	public function testRefusesToRunWithoutStagedChanges(): void {
		$this->shellMap['diff --cached --name-only'] = '';
		$ollama = new StubOllama();

		[ $code, $output ] = $this->dispatch( [], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString(
			"Error: No staged changes found. Stage files with 'git add' first.",
			$output
		);
		$this->assertSame( [], $ollama->chats );
		$this->assertSame( [], $this->runs );
	}

	public function testPassesTheDeveloperContextArgumentIntoTheUserPrompt(): void {
		$ollama = new StubOllama();

		$this->dispatch( [ 'ship the parser rewrite' ], $ollama );

		$this->assertStringContainsString(
			'Context from the developer: ship the parser rewrite',
			$ollama->chats[0][2]
		);
	}

	public function testRecentCommitsSteerTheSystemPromptStyle(): void {
		$ollama = new StubOllama();

		$this->dispatch( [], $ollama );

		$this->assertStringContainsString( 'abc1234 fix an earlier thing', $ollama->chats[0][1] );
	}

	public function testResolvesTheModelFromTheConfigAndStorageLocation(): void {
		$ollama = new StubOllama(
			[ 'MODEL' => 'default-model', 'MODEL_SD' => 'sd-model', 'MODEL_LOCAL' => 'local-model' ],
			Ollama::SD_PATH
		);

		$this->dispatch( [], $ollama );

		$this->assertSame( 'sd-model', $ollama->chats[0][0] );
	}

	public function testFallsBackToTheSharedDefaultModelWithNoConfig(): void {
		$ollama = new StubOllama();

		$this->dispatch( [], $ollama );

		$this->assertSame( Ollama::DEFAULT_MODEL, $ollama->chats[0][0] );
	}

	public function testModelFlagOverridesTheResolvedModel(): void {
		$ollama = new StubOllama( [ 'MODEL' => 'default-model' ], Ollama::SD_PATH );

		$this->dispatch( [ '--model=explicit-model' ], $ollama );

		$this->assertSame( 'explicit-model', $ollama->chats[0][0] );
	}

	public function testReportsAConnectionFailureAndAsksWhetherOllamaIsRunning(): void {
		$ollama = new StubOllama(
			[],
			null,
			[ 'content' => null, 'error' => 'Connection refused', 'errorType' => 'transport' ]
		);

		[ $code, $output ] = $this->dispatch( [], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Error connecting to Ollama: Connection refused', $output );
		$this->assertStringContainsString( 'Is Ollama running?', $output );
		$this->assertStringNotContainsString( 'Requested model:', $output );
		$this->assertSame( [], $this->runs );
	}

	public function testPrintsModelNotFoundGuidanceWithAvailableModelsAndStorageLocation(): void {
		$this->shellMap['ollama list'] = "NAME        SIZE\nqwen3-coder 4 GB";
		$ollama = new StubOllama(
			[],
			Ollama::SD_PATH,
			[ 'content' => null, 'error' => "model 'bogus' not found", 'errorType' => 'api' ]
		);

		[ $code, $output ] = $this->dispatch( [ '--model=bogus' ], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( "Error from Ollama: model 'bogus' not found", $output );
		$this->assertStringContainsString( 'Requested model: bogus', $output );
		$this->assertStringContainsString( 'Available models (ollama list):', $output );
		$this->assertStringContainsString( 'qwen3-coder 4 GB', $output );
		$this->assertStringContainsString( 'Models storage: ' . Ollama::SD_PATH, $output );
		$this->assertStringContainsString( 'Switch with: ollamodels [local|sd]', $output );
		$this->assertStringContainsString( 'ollama pull bogus', $output );
		$this->assertStringContainsString( '~/.config/auto-commit-ollama/config', $output );
	}

	public function testSuggestsPullingAModelWhenNoneAreInstalled(): void {
		$ollama = new StubOllama(
			[],
			null,
			[ 'content' => null, 'error' => 'model not found', 'errorType' => 'api' ]
		);

		[ , $output ] = $this->dispatch( [], $ollama );

		$this->assertStringContainsString( 'No models found. Pull one with: ollama pull <model>', $output );
		$this->assertStringNotContainsString( 'Models storage:', $output );
	}

	public function testAnOllamaErrorThatIsNotAboutAModelSkipsTheGuidance(): void {
		$ollama = new StubOllama(
			[],
			null,
			[ 'content' => null, 'error' => 'context length exceeded', 'errorType' => 'api' ]
		);

		[ $code, $output ] = $this->dispatch( [], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Error from Ollama: context length exceeded', $output );
		$this->assertStringNotContainsString( 'Requested model:', $output );
	}

	public function testReportsAnEmptyOllamaResponseAsAFailureToGenerate(): void {
		$ollama = new StubOllama( [], null, [ 'content' => '', 'error' => null, 'errorType' => null ] );

		[ $code, $output ] = $this->dispatch( [], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Error: Failed to get a commit message from Ollama.', $output );
		$this->assertSame( [], $this->runs );
	}

	public function testReportsAFailedGitCommitWithItsStderr(): void {
		$this->runResult = [ 'output' => '', 'error' => 'nothing to commit', 'exitCode' => 1 ];

		[ $code, $output ] = $this->dispatch( [] );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Error: git commit failed.', $output );
		$this->assertStringContainsString( 'nothing to commit', $output );
	}

	public function testDebugFlagWritesThePromptToTheDebugFile(): void {
		[ $code, $output ] = $this->dispatch( [ '--debug' ] );

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( AutoCommitOllamaCommand::DEBUG_FILE, $this->writes[0][0] );
		$this->assertStringContainsString( '=== MODEL ===', $this->writes[0][1] );
		$this->assertStringContainsString( Ollama::DEFAULT_MODEL, $this->writes[0][1] );
		$this->assertStringContainsString( '=== SYSTEM PROMPT ===', $this->writes[0][1] );
		$this->assertStringContainsString( '=== USER PROMPT ===', $this->writes[0][1] );
		$this->assertStringContainsString(
			'Debug: prompt saved to ' . AutoCommitOllamaCommand::DEBUG_FILE,
			$output
		);
	}

	public function testNoDebugFlagWritesNothing(): void {
		$this->dispatch( [] );

		$this->assertSame( [], $this->writes );
	}

	public function testSayFlagSpeaksTheCommittedMessage(): void {
		$this->dispatch( [ '--say' ] );

		$this->assertSame( [ 'Committed: fix a thing' ], $this->spoken );
	}

	public function testNoprintSuppressesTheCommitOutputButStillCommits(): void {
		[ $code, $output ] = $this->dispatch( [ '--noprint' ] );

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $this->runs );
		$this->assertStringNotContainsString( 'Commit newhash:', $output );
		$this->assertSame( [], $this->spoken );
	}

	// --- --retry ------------------------------------------------------------

	/** @param array<string,string> $extra */
	private function retryShellMap( string $resolved, string $head, array $extra = [] ): void {
		$this->shellMap = array_merge( [
			'rev-parse --short'         => 'abc1234',
			"rev-parse --verify 'HEAD^"  => $resolved,
			'rev-parse --verify '        => $resolved,
			'rev-parse HEAD'            => $head,
			'git log -1 --pretty=format:"%B"' => 'bad subject',
			'git log --format='   => 'abc1234 fix an earlier thing',
			'git diff'            => '--- a/src/foo.php',
		], $extra );
	}

	private function retryOllama(): StubOllama {
		return new StubOllama(
			[],
			null,
			[ 'content' => 'subject is too vague', 'error' => null, 'errorType' => null ],
			[ 'content' => 'fix the parser off-by-one', 'error' => null, 'errorType' => null ]
		);
	}

	public function testRetryOnHeadAmendsTheCommit(): void {
		$this->retryShellMap( 'fullhash', 'fullhash' );
		$ollama = $this->retryOllama();

		[ $code, $output ] = $this->dispatch( [ '--retry' ], $ollama );

		$this->assertSame( 0, $code );
		$this->assertTrue( $this->shellSaw( "git rev-parse --verify 'HEAD^{commit}'" ) );
		$this->assertStringContainsString( 'Analyzing commit abc1234 for retry...', $output );
		$this->assertStringContainsString( 'Retry guidance:', $output );
		$this->assertStringContainsString( 'subject is too vague', $output );
		$this->assertStringContainsString( 'Amended abc1234:', $output );
		$this->assertStringContainsString( 'fix the parser off-by-one', $output );
		$this->assertCount( 1, $this->runs );
		$this->assertStringContainsString(
			"git commit --amend -m 'fix the parser off-by-one'",
			$this->runs[0]
		);
		$this->assertStringContainsString( 'bad subject', $ollama->chats[0][2] );
		$this->assertStringContainsString( 'Retry correction: subject is too vague', $ollama->chats[1][2] );
	}

	public function testRetryOnAnOlderCommitOnlySuggestsAMessage(): void {
		$this->retryShellMap( 'oldhash', 'differenthash' );

		[ $code, $output ] = $this->dispatch( [ '--retry=oldhash' ], $this->retryOllama() );

		$this->assertSame( 0, $code );
		$this->assertTrue( $this->shellSaw( "git rev-parse --verify 'oldhash^{commit}'" ) );
		$this->assertStringContainsString( 'New message for abc1234:', $output );
		$this->assertStringContainsString( 'fix the parser off-by-one', $output );
		$this->assertStringNotContainsString( 'Amended', $output );
		$this->assertSame( [], $this->runs );
	}

	public function testRetryWithAnUnresolvableCommitFailsBeforeCallingOllama(): void {
		$this->retryShellMap( '', 'fullhash' );
		$ollama = $this->retryOllama();

		[ $code, $output ] = $this->dispatch( [ '--retry=nope' ], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( "Error: Could not resolve commit 'nope'.", $output );
		$this->assertSame( [], $ollama->chats );
	}

	public function testRetryReasonSteersTheCriticPromptAndIsEchoedBack(): void {
		$this->retryShellMap( 'fullhash', 'fullhash' );
		$ollama = $this->retryOllama();

		[ , $output ] = $this->dispatch(
			[ '--retry', '--retryReason=says fix, actually a feature' ],
			$ollama
		);

		$this->assertStringContainsString( 'Retry reason given: says fix, actually a feature', $output );
		$this->assertStringContainsString(
			'The developer has noted the following issue: says fix, actually a feature',
			$ollama->chats[0][1]
		);
	}

	public function testRetryFoldsTheContextArgumentInFrontOfTheCorrection(): void {
		$this->retryShellMap( 'fullhash', 'fullhash' );
		$ollama = $this->retryOllama();

		$this->dispatch( [ 'it was a revert', '--retry' ], $ollama );

		$this->assertStringContainsString(
			'Context from the developer: it was a revert. Retry correction: subject is too vague',
			$ollama->chats[1][2]
		);
	}

	public function testRetryReportsAnEmptyCritiqueWithoutAmending(): void {
		$this->retryShellMap( 'fullhash', 'fullhash' );
		$ollama = new StubOllama( [], null, [ 'content' => '', 'error' => null, 'errorType' => null ] );

		[ $code, $output ] = $this->dispatch( [ '--retry' ], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Error: Failed to get retry analysis from Ollama.', $output );
		$this->assertSame( [], $this->runs );
	}

	public function testRetryReportsAFailedAmend(): void {
		$this->retryShellMap( 'fullhash', 'fullhash' );
		$this->runResult = [ 'output' => '', 'error' => '', 'exitCode' => 1 ];

		[ $code, $output ] = $this->dispatch( [ '--retry' ], $this->retryOllama() );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Error: git commit --amend failed.', $output );
	}

	// --- interface ----------------------------------------------------------

	public function testHelpAdvertisesEveryFlagAndTheOptionalRetryValue(): void {
		[ $code, $output ] = $this->dispatch( [ '--help' ] );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '[--retry[=<hash>]]', $output );
		$this->assertStringContainsString( '[--model=<model>]', $output );
		$this->assertStringContainsString( '[--retryReason=<reason>]', $output );
		$this->assertStringContainsString( '[--debug]', $output );
		$this->assertStringContainsString( '[--say]', $output );
		$this->assertStringContainsString( '[--noprint]', $output );
		$this->assertStringContainsString( '[<context>]', $output );
	}

	public function testCompletionGeneratesAZshFunctionWithAnOptionalRetryValue(): void {
		[ $code, $output ] = $this->dispatch( [ 'completion', 'zsh' ] );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '#compdef auto-commit-ollama', $output );
		$this->assertStringContainsString( 'compdef _auto_commit_ollama auto-commit-ollama', $output );
		$this->assertStringContainsString( '--retry=-[', $output );
		$this->assertStringContainsString( '--model=[', $output );
	}

	public function testCompletionPluginLazyLoadsGeneratedOutput(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$binDir   = sys_get_temp_dir() . '/auto-commit-ollama-completion-' . uniqid();
		$calls    = $binDir . '/calls';
		$stub     = $binDir . '/auto-commit-ollama';

		$this->assertStringContainsString( '_auto_commit_ollama_lazy()', $contents );
		$this->assertStringContainsString( 'command auto-commit-ollama completion zsh', $contents );
		// The generated interface must not be checked in alongside the loader.
		$this->assertStringNotContainsString( '--retry=-[', $contents );
		$this->assertStringNotContainsString( 'Suppress printing the result', $contents );

		mkdir( $binDir, 0777, true );
		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_auto_commit_ollama() { return 0; }\n"
			. "compdef _auto_commit_ollama auto-commit-ollama\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[auto-commit-ollama]} == _auto_commit_ollama_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_auto_commit_ollama_lazy || exit 12
[[ ${_comps[auto-commit-ollama]} == _auto_commit_ollama ]] || exit 13
[[ $+functions[_auto_commit_ollama] -eq 1 ]] || exit 14
ZSH;
		exec(
			'PATH=' . escapeshellarg( $binDir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc '
			. escapeshellarg( $script )
			. ' -- '
			. escapeshellarg( $plugin )
			. ' '
			. escapeshellarg( $calls ),
			$output,
			$code
		);

		$this->assertSame( 0, $code );
		$this->assertSame( "completion zsh\n", file_get_contents( $calls ) );

		@unlink( $calls );
		@unlink( $stub );
		@rmdir( $binDir );
	}

	public function testCompletionPluginFailsWithoutRecursingWhenGenerationFails(): void {
		$plugin = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$binDir = sys_get_temp_dir() . '/auto-commit-ollama-completion-failure-' . uniqid();
		$stub   = $binDir . '/auto-commit-ollama';

		mkdir( $binDir, 0777, true );
		file_put_contents( $stub, "#!/bin/sh\nexit 23\n" );
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
_auto_commit_ollama_lazy >/dev/null 2>&1
[[ $? -ne 0 ]] || exit 20
[[ ${_comps[auto-commit-ollama]} == _auto_commit_ollama_lazy ]] || exit 21
[[ $+functions[_auto_commit_ollama] -eq 0 ]] || exit 22
ZSH;
		exec(
			'PATH=' . escapeshellarg( $binDir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc '
			. escapeshellarg( $script )
			. ' -- '
			. escapeshellarg( $plugin ),
			$output,
			$code
		);

		$this->assertSame( 0, $code );

		@unlink( $stub );
		@rmdir( $binDir );
	}

	/**
	 * Plain `git rev-parse <bad-ref>` echoes the ref back on stdout and exits
	 * 128, so only --verify makes an unresolvable ref look unresolvable.
	 */
	public function testRetryResolvesTheRefWithRevParseVerify(): void {
		$this->retryShellMap( 'fullhash', 'fullhash' );

		$this->dispatch( [ '--retry=v1.2.3' ], $this->retryOllama() );

		$this->assertTrue( $this->shellSaw( "git rev-parse --verify 'v1.2.3^{commit}' 2>/dev/null" ) );
		$this->assertFalse( $this->shellSaw( "git rev-parse 'v1.2.3'" ) );
	}
}
