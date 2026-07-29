<?php
namespace JT\Tests\LinuxCatchup;

use JT\CLI\Command\Dispatcher;
use JT\Godo;
use JT\LinuxCatchup;
use JT\LinuxCatchupCommand;
use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LinuxCatchupCommandTest extends TestCase {

	private string $config = '';
	private string $godoStore = '';
	private string $dirmapBin = '';

	protected function setUp(): void {
		parent::setUp();

		$suffix = getmypid() . '-' . uniqid();
		$this->config = sys_get_temp_dir() . '/linux-catchup-command-' . $suffix . '.json';
		$this->godoStore = sys_get_temp_dir() . '/linux-catchup-godo-' . $suffix . '.json';
		$this->dirmapBin = sys_get_temp_dir() . '/linux-catchup-dirmap-' . $suffix;
		putenv( 'LINUX_CATCHUP_CONFIG=' . $this->config );
		putenv( 'GODO_CMDMAP=' . $this->godoStore );
		putenv( 'GODO_DIRMAP_BIN=' . $this->dirmapBin );
	}

	protected function tearDown(): void {
		putenv( 'LINUX_CATCHUP_CONFIG' );
		putenv( 'GODO_CMDMAP' );
		putenv( 'GODO_DIRMAP_BIN' );
		@unlink( $this->config );
		@unlink( $this->godoStore );
		@unlink( $this->dirmapBin );

		parent::tearDown();
	}

	public function testDispatcherBindsOnlyAndRunsTheRequestedToolStep(): void {
		$commands = [];
		$handler  = new LinuxCatchupCommand(
			$this->cli,
			new LinuxCatchup( $this->cli ),
			runner: static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
			commandExists: static fn( string $command ): bool => 'codex' === $command,
		);
		$this->cli->setArgs( [ 'linux-catchup', '--only=codex' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [ 'codex update' ], $commands );
	}

	#[DataProvider('yesOptionProvider')]
	public function testApplyAndYesBindThroughTheSystemUpdateAlias( string $yesOption ): void {
		$commands = [];
		$catchup  = new class( $this->cli ) extends LinuxCatchup {
			public function isLinux(): bool {
				return true;
			}
		};
		$handler = new LinuxCatchupCommand(
			$this->cli,
			$catchup,
			runner: static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
			shellOutput: static fn( string $command ): string =>
				'apt list --upgradable 2>/dev/null' === $command
					? "vim/jammy-security 2:8.2 amd64 [upgradable from: 2:8.1]\n"
					: '',
			fileExists: static fn( string $path ): bool => false,
		);
		$this->cli->setArgs( [
			'linux-catchup',
			'--only=system-update',
			'--apply',
			$yesOption,
		] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertSame(
			[
				'sudo apt-get update -qq',
				'sudo apt-get upgrade -y 2>&1',
			],
			$commands
		);
	}

	public static function yesOptionProvider(): array {
		return [
			'long option'  => [ '--yes' ],
			'short option' => [ '-y' ],
		];
	}

	public function testRepoOrchestrationUsesInjectedGodoAndRunner(): void {
		file_put_contents( $this->config, json_encode( [ 'repos' => [ 'dotfiles' ] ] ) );
		file_put_contents( $this->godoStore, json_encode( [ 'dotfiles' => [ 'git prb' ] ] ) );
		file_put_contents(
			$this->dirmapBin,
			"#!/bin/sh\n[ \"\$1\" = get ] && [ \"\$2\" = dotfiles ] && { echo /tmp; exit 0; }\nexit 1\n"
		);
		chmod( $this->dirmapBin, 0755 );

		$commands = [];
		$handler  = new LinuxCatchupCommand(
			$this->cli,
			new LinuxCatchup( $this->cli ),
			new Godo( $this->cli ),
			static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
		);
		$this->cli->setArgs( [ 'linux-catchup', '--only=repos' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertSame(
			[ escapeshellarg( dirname( __DIR__, 2 ) . '/bin/godo' ) . " 'dotfiles'" ],
			$commands
		);
	}

	#[DataProvider('invalidOnlyProvider')]
	public function testInvalidOnlyReturnsUsageFailureWithoutRunningCommands(
		string $only,
		string $expected
	): void {
		$commands = [];
		$handler  = new LinuxCatchupCommand(
			$this->cli,
			new LinuxCatchup( $this->cli ),
			runner: static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
		);
		$this->cli->setArgs( [ 'linux-catchup', '--only=' . $only ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $code );
		$this->assertSame( [], $commands );
		$this->assertStringContainsString( $expected, $output );
	}

	public static function invalidOnlyProvider(): array {
		return [
			'unknown' => [ 'bogus', 'Unknown --only step(s): bogus' ],
			'empty'   => [ '', '--only needs at least one step' ],
		];
	}

	public function testConfigIsAReflectedCommand(): void {
		$handler = new LinuxCatchupCommand( $this->cli );
		$this->cli->setArgs( [ 'linux-catchup', 'config' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertFileExists( $this->config );
		$this->assertStringContainsString( 'Config: ' . $this->config, $output );
		$this->assertStringContainsString( '"repos": []', $output );
	}

	#[DataProvider('reflectionOnlyProvider')]
	public function testHelpAndCompletionHaveNoOperationalSideEffects(
		array $arguments,
		string $expected
	): void {
		$commands = [];
		$handler  = new LinuxCatchupCommand(
			$this->cli,
			runner: static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
		);
		$this->cli->setArgs( array_merge( [ 'linux-catchup' ], $arguments ) );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertFileDoesNotExist( $this->config );
		$this->assertFileDoesNotExist( $this->godoStore );
		$this->assertSame( [], $commands );
		$this->assertStringContainsString( $expected, $output );
	}

	public static function reflectionOnlyProvider(): array {
		return [
			'help' => [
				[ '--help' ],
				'usage: linux-catchup [--apply] [--yes|-y] [--only=<steps>]',
			],
			'completion' => [
				[ 'completion', 'zsh' ],
				'compdef _linux_catchup linux-catchup',
			],
		];
	}

	public function testCompletionPluginLazyLoadsGeneratedOutput(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$binDir   = sys_get_temp_dir() . '/linux-catchup-completion-' . uniqid();
		$calls    = $binDir . '/calls';
		$stub     = $binDir . '/linux-catchup';

		$this->assertStringContainsString( '_linux_catchup_lazy()', $contents );
		$this->assertStringContainsString( 'command linux-catchup completion zsh', $contents );
		$this->assertStringNotContainsString( "'config:Print the resolved config", $contents );
		$this->assertStringNotContainsString( "'--apply[Apply system", $contents );

		mkdir( $binDir, 0777, true );
		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_linux_catchup() { return 0; }\n"
			. "compdef _linux_catchup linux-catchup\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[linux-catchup]} == _linux_catchup_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_linux_catchup_lazy || exit 12
[[ ${_comps[linux-catchup]} == _linux_catchup ]] || exit 13
[[ $+functions[_linux_catchup] -eq 1 ]] || exit 14
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
		$binDir = sys_get_temp_dir() . '/linux-catchup-completion-failure-' . uniqid();
		$stub   = $binDir . '/linux-catchup';

		mkdir( $binDir, 0777, true );
		file_put_contents( $stub, "#!/bin/sh\nexit 23\n" );
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
_linux_catchup_lazy >/dev/null 2>&1
[[ $? -ne 0 ]] || exit 20
[[ ${_comps[linux-catchup]} == _linux_catchup_lazy ]] || exit 21
[[ $+functions[_linux_catchup] -eq 0 ]] || exit 22
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
