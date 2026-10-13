<?php
namespace JT\Tests\ToText;

use JT\CLI\Command\Dispatcher;
use JT\ImageToText;
use JT\Tests\TestCase;
use JT\ToTextCommand;

final class ToTextCommandTest extends TestCase {

	private string $image;

	protected function setUp(): void {
		parent::setUp();
		$this->image = sys_get_temp_dir() . '/to-text-' . uniqid() . '.png';
		file_put_contents( $this->image, 'png-bytes' );
	}

	protected function tearDown(): void {
		@unlink( $this->image );
	}

	/** @return array{0:int, 1:string} [exit code, captured output] */
	private function dispatch( array $args, StubVisionOllama $ollama ): array {
		$this->cli->setArgs( array_merge( [ 'to-text' ], $args ) );
		$handler = new ToTextCommand( $this->cli, new ImageToText( $ollama, '/home/jt' ) );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		return [ $code, $output ];
	}

	public function testPrintsTheTextInTheImage(): void {
		$ollama = new StubVisionOllama( [], false, [ 'content' => "Line one\nLine two\n", 'error' => null, 'errorType' => null ] );

		[ $code, $output ] = $this->dispatch( [ $this->image ], $ollama );

		$this->assertSame( 0, $code );
		$this->assertSame( "Line one\nLine two\n", $output );
	}

	public function testModelFlagOverridesConfig(): void {
		$ollama = new StubVisionOllama( [ 'OCR_MODEL' => 'configured' ] );

		$this->dispatch( [ $this->image, '--model=explicit' ], $ollama );

		$this->assertSame( 'explicit', $ollama->chats[0]['model'] );
	}

	public function testJsonOutputCarriesTextAndModel(): void {
		$ollama = new StubVisionOllama();

		[ $code, $output ] = $this->dispatch( [ $this->image, '--json', '--model=m' ], $ollama );

		$data = json_decode( $output, true );
		$this->assertSame( 0, $code );
		$this->assertSame( 'Some text', $data['text'] );
		$this->assertSame( 'm', $data['model'] );
		$this->assertArrayHasKey( 'duration_ms', $data );
	}

	public function testNoTextFoundExitsNonzeroWithNothingOnStdout(): void {
		$ollama = new StubVisionOllama( [], false, [ 'content' => '', 'error' => null, 'errorType' => null ] );

		[ $code, $output ] = $this->dispatch( [ $this->image, '--model=m' ], $ollama );

		$this->assertSame( 1, $code );
		$this->assertSame( '', $output );
	}

	public function testMissingImageExitsNonzero(): void {
		[ $code ] = $this->dispatch( [ '/nope/missing.png' ], new StubVisionOllama() );

		$this->assertSame( 1, $code );
	}

	public function testCompletionCompletesFilesAndFlags(): void {
		[ $code, $output ] = $this->dispatch( [ 'completion', 'zsh' ], new StubVisionOllama() );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'compdef _to_text to-text', $output );
		$this->assertStringContainsString( '_files', $output );
		$this->assertStringContainsString( '--model', $output );
	}

	public function testCompletionPluginLazyLoadsGeneratedOutput(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$dir      = sys_get_temp_dir() . '/to-text-zsh-' . uniqid();
		$calls    = $dir . '/calls';
		$stub     = $dir . '/to-text';
		mkdir( $dir );

		$this->assertStringContainsString( 'command to-text completion zsh', $contents );
		$this->assertStringNotContainsString( 'Ollama vision model to use', $contents );

		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_to_text() { return 0; }\n"
			. "compdef _to_text to-text\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[to-text]} == _to_text_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_to_text_lazy || exit 12
[[ ${_comps[to-text]} == _to_text ]] || exit 13
ZSH;
		exec(
			'PATH=' . escapeshellarg( $dir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc ' . escapeshellarg( $script )
			. ' -- ' . escapeshellarg( $plugin ) . ' ' . escapeshellarg( $calls ),
			$out,
			$code
		);
		$called = (string) @file_get_contents( $calls );
		array_map( 'unlink', glob( $dir . '/*' ) ?: [] );
		rmdir( $dir );

		$this->assertSame( 0, $code );
		$this->assertSame( "completion zsh\n", $called );
	}
}
