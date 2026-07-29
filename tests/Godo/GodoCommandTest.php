<?php
namespace JT\Tests\Godo;

use JT\CLI\Command\Dispatcher;
use JT\Godo;
use JT\GodoCommand;
use JT\Tests\TestCase;

final class GodoCommandTest extends TestCase {

	private string $store = '';
	private string $dirmapBin = '';

	protected function setUp(): void {
		parent::setUp();

		$this->store = sys_get_temp_dir() . '/godo-command-' . getmypid() . '-' . uniqid() . '.json';
		putenv( 'GODO_CMDMAP=' . $this->store );

		$this->dirmapBin = sys_get_temp_dir() . '/godo-command-dirmap-' . getmypid() . '-' . uniqid();
		file_put_contents(
			$this->dirmapBin,
			"#!/bin/sh\n[ \"\$1\" = get ] && [ \"\$2\" = dotfiles ] && { echo /tmp; exit 0; }\nexit 1\n"
		);
		chmod( $this->dirmapBin, 0755 );
		putenv( 'GODO_DIRMAP_BIN=' . $this->dirmapBin );
	}

	protected function tearDown(): void {
		putenv( 'GODO_CMDMAP' );
		putenv( 'GODO_DIRMAP_BIN' );
		@unlink( $this->store );
		@unlink( $this->dirmapBin );

		parent::tearDown();
	}

	public function testDispatcherCallsAnnotatedGodoCommandMethods(): void {
		$godo    = new Godo( $this->cli );
		$handler = new GodoCommand( $this->cli, $godo );
		$this->cli->setArgs( [ 'godo', 'addcmd', 'dotfiles', 'git status' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [ 'git status' ], $godo->getStoredCommands( 'dotfiles' ) );
	}

	public function testHelpDoesNotConstructOrWriteTheDomainStore(): void {
		$handler = new GodoCommand( $this->cli );
		$this->cli->setArgs( [ 'godo', '--help' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertFileDoesNotExist( $this->store );
	}

	public function testAddCommandRejectsABlankCommand(): void {
		$godo    = new Godo( $this->cli );
		$handler = new GodoCommand( $this->cli, $godo );
		$this->cli->setArgs( [ 'godo', 'addcmd', 'dotfiles', '   ' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 1, $code );
		$this->assertSame( [], $godo->getStoredCommands( 'dotfiles' ) );
	}

	public function testGodoCompletionPluginLazyLoadsGeneratedOutput(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/godo-completions/godo-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$binDir   = sys_get_temp_dir() . '/godo-completion-bin-' . uniqid();
		$calls    = $binDir . '/calls';
		$stub     = $binDir . '/godo';

		$this->assertStringContainsString( '_godo_lazy()', $contents );
		$this->assertStringContainsString( 'command godo completion zsh', $contents );
		$this->assertStringNotContainsString( '# BEGIN GENERATED COMPLETION', $contents );
		$this->assertStringNotContainsString( 'commands=(', $contents );
		$this->assertStringNotContainsString( "'get:", $contents );
		$this->assertStringNotContainsString( 'Print the stored commands for a key.', $contents );

		mkdir( $binDir, 0777, true );
		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_godo() { return 0; }\n"
			. "compdef _godo godo\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[godo]} == _godo_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_godo_lazy || exit 12
[[ ${_comps[godo]} == _godo ]] || exit 13
[[ $+functions[_godo] -eq 1 ]] || exit 14
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

	public function testGodoCompletionPluginFailsWithoutRecursingWhenGenerationFails(): void {
		$plugin = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/godo-completions/godo-completions.plugin.zsh';
		$binDir = sys_get_temp_dir() . '/godo-completion-failure-' . uniqid();
		$stub   = $binDir . '/godo';

		mkdir( $binDir, 0777, true );
		file_put_contents( $stub, "#!/bin/sh\nexit 23\n" );
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
_godo_lazy >/dev/null 2>&1
[[ $? -ne 0 ]] || exit 20
[[ ${_comps[godo]} == _godo_lazy ]] || exit 21
[[ $+functions[_godo] -eq 0 ]] || exit 22
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
