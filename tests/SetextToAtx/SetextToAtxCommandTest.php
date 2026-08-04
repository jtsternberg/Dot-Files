<?php
namespace JT\Tests\SetextToAtx;

use JT\CLI\Command\Dispatcher;
use JT\SetextToAtxCommand;
use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SetextToAtxCommandTest extends TestCase {

	private string $vault = '';

	protected function setUp(): void {
		parent::setUp();

		$this->vault = $this->graveyardRoot . '/vault';
		mkdir( $this->vault . '/nested', 0777, true );
		file_put_contents( $this->vault . '/post.md', "Title\n=====\n\nBody.\n" );
		file_put_contents( $this->vault . '/nested/other.md', "Deeper\n------\n" );
		file_put_contents( $this->vault . '/clean.md', "# Already\n" );
	}

	/** @return array{code: int, output: string} */
	private function dispatch( array $arguments ): array {
		$this->cli->setArgs( array_merge( [ 'md-atx' ], $arguments ) );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, new SetextToAtxCommand( $this->cli ) ) )->run();
		$output = (string) ob_get_clean();

		return [ 'code' => $code, 'output' => $output ];
	}

	public function testDryRunReportsWithoutTouchingFiles(): void {
		$result = $this->dispatch( [ $this->vault ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( 'post.md', $result['output'] );
		$this->assertStringContainsString( 'nested/other.md', $result['output'] );
		$this->assertStringNotContainsString( 'clean.md', $result['output'] );
		$this->assertStringContainsString( '--write', $result['output'] );
		$this->assertSame( "Title\n=====\n\nBody.\n", file_get_contents( $this->vault . '/post.md' ) );
	}

	public function testWriteNormalizesEveryMarkdownFileUnderThePath(): void {
		$result = $this->dispatch( [ $this->vault, '--write' ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertSame( "# Title\n\nBody.\n", file_get_contents( $this->vault . '/post.md' ) );
		$this->assertSame( "## Deeper\n", file_get_contents( $this->vault . '/nested/other.md' ) );
		$this->assertSame( "# Already\n", file_get_contents( $this->vault . '/clean.md' ) );
	}

	public function testReportsNothingToDoOnAnAlreadyNormalizedTree(): void {
		$this->dispatch( [ $this->vault, '--write' ] );

		$result = $this->dispatch( [ $this->vault ] );

		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( 'No setext headings', $result['output'] );
	}

	public function testFailsOnAMissingPath(): void {
		$result = $this->dispatch( [ $this->vault . '/nope' ] );

		$this->assertSame( 1, $result['code'] );
	}

	#[DataProvider( 'reflectionOnlyProvider' )]
	public function testReflectionOnlyPathsTouchNothing( array $arguments, string $expected ): void {
		$result = $this->dispatch( $arguments );

		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( $expected, $result['output'] );
		$this->assertSame( "Title\n=====\n\nBody.\n", file_get_contents( $this->vault . '/post.md' ) );
	}

	public static function reflectionOnlyProvider(): array {
		return [
			'help'       => [ [ '--help' ], 'usage: md-atx' ],
			'completion' => [ [ 'completion', 'zsh' ], 'compdef _md_atx md-atx' ],
		];
	}

	public function testCompletionPluginLazyLoadsGeneratedOutputExactlyOnce(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$binDir   = sys_get_temp_dir() . '/md-atx-completion-' . uniqid();
		$calls    = $binDir . '/calls';
		$stub     = $binDir . '/md-atx';

		$this->assertStringContainsString( '_md_atx_lazy()', $contents );
		$this->assertStringContainsString( 'command md-atx completion zsh', $contents );
		$this->assertStringNotContainsString( '--write[', $contents );

		mkdir( $binDir, 0777, true );
		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_md_atx() { return 0; }\n"
			. "compdef _md_atx md-atx\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[md-atx]} == _md_atx_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_md_atx_lazy || exit 12
[[ ${_comps[md-atx]} == _md_atx ]] || exit 13
[[ $+functions[_md_atx] -eq 1 ]] || exit 14
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
		$binDir = sys_get_temp_dir() . '/md-atx-completion-failure-' . uniqid();
		$stub   = $binDir . '/md-atx';

		mkdir( $binDir, 0777, true );
		file_put_contents( $stub, "#!/bin/sh\nexit 23\n" );
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
_md_atx_lazy >/dev/null 2>&1
[[ $? -ne 0 ]] || exit 20
[[ ${_comps[md-atx]} == _md_atx_lazy ]] || exit 21
[[ $+functions[_md_atx] -eq 0 ]] || exit 22
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
}
