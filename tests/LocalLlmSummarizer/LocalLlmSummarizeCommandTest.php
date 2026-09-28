<?php
namespace JT\Tests\LocalLlmSummarizer;

use JT\CLI\Command\Dispatcher;
use JT\LocalLlmSummarizeCommand;
use JT\LocalLlmSummarizer;
use JT\Tests\TestCase;

final class LocalLlmSummarizeCommandTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/llm-summarize-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		@rmdir( $this->dir );
	}

	private function file( string $name, string $contents ): string {
		file_put_contents( $this->dir . '/' . $name, $contents );

		return $this->dir . '/' . $name;
	}

	/** @return array{0:int, 1:string} [exit code, captured output] */
	private function dispatch( array $args, ?StubSummaryOllama $ollama = null, string $stdin = '' ): array {
		$this->cli->setArgs( array_merge( [ 'local-llm-summarize' ], $args ) );
		$handler = new LocalLlmSummarizeCommand(
			$this->cli,
			new LocalLlmSummarizer( $ollama ?? new StubSummaryOllama(), '/home/jt' ),
			static fn(): string => $stdin
		);

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		return [ $code, $output ];
	}

	public function testSummarizesAFileAsAParagraph(): void {
		$ollama = new StubSummaryOllama( [], false, [ 'content' => 'The file lists chores.', 'error' => null, 'errorType' => null ] );

		[ $code, $output ] = $this->dispatch( [ $this->file( 'notes.txt', 'wash dishes' ) ], $ollama );

		$this->assertSame( 0, $code );
		$this->assertSame( "The file lists chores.\n", $output );
		$this->assertStringContainsString( 'wash dishes', $ollama->chats[0]['user'] );
	}

	public function testReadsStdinWhenNoFileIsGiven(): void {
		$ollama = new StubSummaryOllama( [], false, [ 'content' => 'Piped.', 'error' => null, 'errorType' => null ] );

		[ $code ] = $this->dispatch( [ '--model=m' ], $ollama, 'from a pipe' );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'from a pipe', $ollama->chats[0]['user'] );
	}

	public function testSessionModePrintsTheTitleThenTheSummary(): void {
		$ollama = new StubSummaryOllama( [], false, [
			'content'   => "TITLE: Login cookie flake\nIt fixed a flake. It committed.",
			'error'     => null,
			'errorType' => null,
		] );

		[ $code, $output ] = $this->dispatch(
			[ $this->file( 'transcript.md', "**You:** fix the login test\n\n**Claude:** Done.\n" ), '--session' ],
			$ollama
		);

		$this->assertSame( 0, $code );
		$this->assertSame( "Login cookie flake\n\nIt fixed a flake. It committed.\n", $output );
		$this->assertStringContainsString( 'TITLE:', $ollama->chats[0]['user'] );
	}

	public function testJsonOutputCarriesTheModelAndTiming(): void {
		$ollama = new StubSummaryOllama( [ 'SUMMARY_MODEL' => 'gemma-big' ], false, [
			'content'   => "TITLE: Login cookie flake\nIt fixed a flake.",
			'error'     => null,
			'errorType' => null,
		] );

		[ $code, $output ] = $this->dispatch(
			[ $this->file( 't.md', "**You:** fix it\n" ), '--session', '--json' ],
			$ollama
		);
		$json = json_decode( $output, true );

		$this->assertSame( 0, $code );
		$this->assertSame( 'Login cookie flake', $json['title'] );
		$this->assertSame( 'It fixed a flake.', $json['summary'] );
		$this->assertSame( 'gemma-big', $json['model'] );
		$this->assertIsInt( $json['duration_ms'] );
	}

	public function testModelOptionOverridesTheConfig(): void {
		$ollama = new StubSummaryOllama( [ 'SUMMARY_MODEL' => 'configured' ] );

		$this->dispatch( [ $this->file( 'a.txt', 'x' ), '--model=forced' ], $ollama );

		$this->assertSame( 'forced', $ollama->chats[0]['model'] );
	}

	public function testAMissingFileFails(): void {
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/nope.txt' ] );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'No such file', $output );
	}

	public function testAnUnreachableOllamaSaysSo(): void {
		$ollama = new StubSummaryOllama( [], false, [ 'content' => null, 'error' => 'Connection refused', 'errorType' => 'transport' ] );

		[ $code, $output ] = $this->dispatch( [ $this->file( 'a.txt', 'x' ) ], $ollama );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Connection refused', $output );
		$this->assertStringContainsString( 'Is Ollama running?', $output );
	}

	public function testJsonFailureIsStillJson(): void {
		$ollama = new StubSummaryOllama( [], false, [ 'content' => null, 'error' => "model 'x' not found", 'errorType' => 'api' ] );

		[ $code, $output ] = $this->dispatch( [ $this->file( 'a.txt', 'x' ), '--json' ], $ollama );
		$json = json_decode( $output, true );

		$this->assertSame( 1, $code );
		$this->assertSame( "model 'x' not found", $json['error'] );
		$this->assertSame( 'api', $json['error_type'] );
	}

	// --- interface ----------------------------------------------------------

	public function testHelpAdvertisesEveryFlag(): void {
		[ $code, $output ] = $this->dispatch( [ '--help' ] );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '[<file>]', $output );
		$this->assertStringContainsString( '[--session]', $output );
		$this->assertStringContainsString( '[--model=<model>]', $output );
		$this->assertStringContainsString( '[--json]', $output );
	}

	public function testCompletionCompletesFilesAndFlags(): void {
		[ $code, $output ] = $this->dispatch( [ 'completion', 'zsh' ] );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'compdef _local_llm_summarize local-llm-summarize', $output );
		$this->assertStringContainsString( '_files', $output );
		$this->assertStringContainsString( '--session', $output );
	}

	public function testCompletionPluginLazyLoadsGeneratedOutput(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$calls    = $this->dir . '/calls';
		$stub     = $this->dir . '/local-llm-summarize';

		$this->assertStringContainsString( 'command local-llm-summarize completion zsh', $contents );
		$this->assertStringNotContainsString( 'TITLE plus two sentences', $contents );

		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_local_llm_summarize() { return 0; }\n"
			. "compdef _local_llm_summarize local-llm-summarize\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[local-llm-summarize]} == _local_llm_summarize_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_local_llm_summarize_lazy || exit 12
[[ ${_comps[local-llm-summarize]} == _local_llm_summarize ]] || exit 13
ZSH;
		exec(
			'PATH=' . escapeshellarg( $this->dir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc ' . escapeshellarg( $script )
			. ' -- ' . escapeshellarg( $plugin ) . ' ' . escapeshellarg( $calls ),
			$out,
			$code
		);

		$this->assertSame( 0, $code );
		$this->assertSame( "completion zsh\n", file_get_contents( $calls ) );
	}
}
