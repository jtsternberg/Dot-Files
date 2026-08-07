<?php
namespace JT\Tests\CmuxBak;

use JT\CLI\Command\Dispatcher;
use JT\CmuxBakCommand;
use JT\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CmuxBakCommandTest extends TestCase {

	public function testBinIsAThinAttributedDispatcher(): void {
		$bin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/bin/cmux-bak' );

		$this->assertStringContainsString( 'use JT\\CLI\\Command\\Dispatcher;', $bin );
		$this->assertStringContainsString( 'new CmuxBakCommand( $cli )', $bin );
		$this->assertStringNotContainsString( 'getHelp()', $bin );
		$this->assertStringNotContainsString( 'new CmuxBak($cli)', $bin );
		$this->assertStringNotContainsString( 'hasFlag(', $bin );
	}

	/**
	 * @return array{0:CmuxBakCommand,1:object}
	 */
	private function handlerWithRecorder(): array {
		$state = new class() {

			/** @var array<int,array{file:string,dry_run:bool,verbose:bool}> */
			public array $configurations = [];

			/** @var string[] */
			public array $actions = [];
		};
		$handler = new CmuxBakCommand(
			$this->cli,
			static function (
				string $file,
				bool $dryRun,
				bool $verbose
			) use ( $state ): object {
				$state->configurations[] = [
					'file'     => $file,
					'dry_run'  => $dryRun,
					'verbose'  => $verbose,
				];

				return new class( $state ) {

					public function __construct(
						private readonly object $state
					) {
					}

					public function backup(): int {
						return $this->record( 'backup' );
					}

					public function restore(): int {
						return $this->record( 'restore' );
					}

					public function audit(): int {
						return $this->record( 'audit' );
					}

					private function record( string $action ): int {
						$this->state->actions[] = $action;

						return 0;
					}
				};
			}
		);

		return [ $handler, $state ];
	}

	public function testDefaultBackupBindsEveryPublicOption(): void {
		[ $handler, $state ] = $this->handlerWithRecorder();
		$this->cli->setArgs( [
			'cmux-bak',
			'--file=/tmp/cmux-bak-test.json',
			'--dry-run',
			'--verbose',
		] );

		$code = ( new Dispatcher( $this->cli, $handler ) )->run();

		$this->assertSame( 0, $code );
		$this->assertSame( [ 'backup' ], $state->actions );
		$this->assertSame(
			[
				[
					'file'     => '/tmp/cmux-bak-test.json',
					'dry_run'  => true,
					'verbose'  => true,
				],
			],
			$state->configurations
		);
	}

	public function testRemovedRestoreOptionFailsBeforeCreatingTheService(): void {
		[ $handler, $state ] = $this->handlerWithRecorder();
		$this->cli->setArgs( [ 'cmux-bak', '--restore' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $code );
		$this->assertSame( [], $state->configurations );
		$this->assertSame( [], $state->actions );
		$this->assertStringContainsString( 'Unknown option: --restore', $output );
	}

	/**
	 * The prompting verbs must accept the auto-confirm flag AND let it reach the
	 * prompt layer, which reads it back off the parsed arguments through
	 * Helpers::isAutoconfirm() (restore's husk prompt) and Helpers::confirm()
	 * (audit's resume prompt).
	 */
	#[DataProvider('autoconfirmProvider')]
	public function testPromptingCommandsAcceptAutoconfirm(
		string $command,
		string $flag
	): void {
		$cli  = $this->cli;
		$seen = [];
		$handler = new CmuxBakCommand(
			$cli,
			static function () use ( $cli, &$seen ): object {
				$seen[] = $cli->isAutoconfirm();

				return new class() {

					public function restore(): int {
						return 0;
					}

					public function audit(): int {
						return 0;
					}
				};
			}
		);
		$cli->setArgs( [ 'cmux-bak', $command, $flag ] );

		ob_start();
		$code   = ( new Dispatcher( $cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code, "cmux-bak {$command} {$flag} must dispatch. Output: {$output}" );
		$this->assertSame(
			[ true ],
			$seen,
			"cmux-bak {$command} {$flag} must reach the prompt layer as auto-confirm."
		);
	}

	public static function autoconfirmProvider(): array {
		return [
			'restore --yes' => [ 'restore', '--yes' ],
			'restore -y'    => [ 'restore', '-y' ],
			'audit --yes'   => [ 'audit', '--yes' ],
			'audit -y'      => [ 'audit', '-y' ],
		];
	}

	/** Backup prompts for nothing, so it must not advertise or accept auto-confirm. */
	public function testBackupRejectsAutoconfirm(): void {
		[ $handler, $state ] = $this->handlerWithRecorder();
		$this->cli->setArgs( [ 'cmux-bak', '--yes' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $code );
		$this->assertSame( [], $state->actions );
		$this->assertStringContainsString( 'Unknown option: --yes', $output );
	}

	public function testOperationalExceptionReturnsFailure(): void {
		$handler = new CmuxBakCommand(
			$this->cli,
			static function (): object {
				throw new \RuntimeException( 'operational failure' );
			}
		);
		$this->cli->setArgs( [ 'cmux-bak' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'operational failure', $output );
	}

	public function testProgrammingErrorIsNotSwallowed(): void {
		$handler = new CmuxBakCommand(
			$this->cli,
			static function (): object {
				throw new \Error( 'programming error' );
			}
		);
		$this->cli->setArgs( [ 'cmux-bak' ] );

		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'programming error' );

		( new Dispatcher( $this->cli, $handler ) )->run();
	}

	#[DataProvider('publicCommandProvider')]
	public function testReflectedCommandsDispatchWithTheirOptions(
		string $command,
		string $expectedAction
	): void {
		[ $handler, $state ] = $this->handlerWithRecorder();
		$this->cli->setArgs( [
			'cmux-bak',
			$command,
			'--file=/tmp/cmux-bak-' . $command . '.json',
			'--dry-run',
			'--verbose',
		] );

		$code = ( new Dispatcher( $this->cli, $handler ) )->run();

		$this->assertSame( 0, $code );
		$this->assertSame( [ $expectedAction ], $state->actions );
		$this->assertSame(
			[
				[
					'file'     => '/tmp/cmux-bak-' . $command . '.json',
					'dry_run'  => true,
					'verbose'  => true,
				],
			],
			$state->configurations
		);
	}

	public static function publicCommandProvider(): array {
		return [
			'restore' => [ 'restore', 'restore' ],
			'audit'   => [ 'audit', 'audit' ],
		];
	}

	#[DataProvider('reflectionOnlyProvider')]
	public function testHelpAndCompletionDoNotCreateOperationalDependencies(
		array $arguments,
		string $expected
	): void {
		$factoryCalls = 0;
		$handler      = new CmuxBakCommand(
			$this->cli,
			static function () use ( &$factoryCalls ): object {
				$factoryCalls++;

				throw new \RuntimeException( 'The operational factory must stay lazy.' );
			}
		);
		$this->cli->setArgs( array_merge( [ 'cmux-bak' ], $arguments ) );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( 0, $factoryCalls );
		$this->assertStringContainsString( $expected, $output );
		$this->assertStringNotContainsString( '--restore', $output );
	}

	public static function reflectionOnlyProvider(): array {
		return [
			'help' => [
				[ '--help' ],
				'usage: cmux-bak [--file=<path>] [--dry-run] [--verbose]',
			],
			'completion' => [
				[ 'completion', 'zsh' ],
				'compdef _cmux_bak cmux-bak',
			],
		];
	}

	public function testCompletionPluginLazyLoadsGeneratedOutputExactlyOnce(): void {
		$plugin   = dirname( __DIR__, 2 )
			. '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$contents = (string) file_get_contents( $plugin );
		$binDir   = sys_get_temp_dir() . '/cmux-bak-completion-' . uniqid();
		$calls    = $binDir . '/calls';
		$stub     = $binDir . '/cmux-bak';

		$this->assertStringContainsString( '_cmux_bak_lazy()', $contents );
		$this->assertStringContainsString( 'command cmux-bak completion zsh', $contents );
		$this->assertStringNotContainsString( "'1:command:(audit)'", $contents );
		$this->assertStringNotContainsString( "'--file=[use a specific backup file]", $contents );

		mkdir( $binDir, 0777, true );
		file_put_contents(
			$stub,
			"#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $calls ) . "\n"
			. "cat <<'ZSH'\n"
			. "_cmux_bak() { return 0; }\n"
			. "compdef _cmux_bak cmux-bak\n"
			. "ZSH\n"
		);
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
[[ ${_comps[cmux-bak]} == _cmux_bak_lazy ]] || exit 10
[[ ! -e "$2" ]] || exit 11
_cmux_bak_lazy || exit 12
[[ ${_comps[cmux-bak]} == _cmux_bak ]] || exit 13
[[ $+functions[_cmux_bak] -eq 1 ]] || exit 14
_cmux_bak || exit 15
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
		$binDir = sys_get_temp_dir() . '/cmux-bak-completion-failure-' . uniqid();
		$stub   = $binDir . '/cmux-bak';

		mkdir( $binDir, 0777, true );
		file_put_contents( $stub, "#!/bin/sh\nexit 23\n" );
		chmod( $stub, 0755 );

		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
source "$1"
_cmux_bak_lazy >/dev/null 2>&1
[[ $? -ne 0 ]] || exit 20
[[ ${_comps[cmux-bak]} == _cmux_bak_lazy ]] || exit 21
[[ $+functions[_cmux_bak] -eq 0 ]] || exit 22
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
