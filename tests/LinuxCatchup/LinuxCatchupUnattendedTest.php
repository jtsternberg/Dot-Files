<?php
namespace JT\Tests\LinuxCatchup;

use JT\CLI\Command\Dispatcher;
use JT\Godo;
use JT\LinuxCatchup;
use JT\LinuxCatchupCommand;
use JT\Tests\TestCase;

/**
 * linux-catchup --unattended: the timer/cron mode — no sudo, no prompts,
 * stdin closed, overlapping runs locked out.
 */
final class LinuxCatchupUnattendedTest extends TestCase {

	private string $root = '';
	private string $config = '';
	private string $dirmapBin = '';

	protected function setUp(): void {
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/lc-unattended-' . getmypid() . '-' . uniqid();
		mkdir( $this->root, 0777, true );
		$this->config    = $this->root . '/linux-catchup.json';
		$this->dirmapBin = $this->root . '/dirmap';
		putenv( 'LINUX_CATCHUP_CONFIG=' . $this->config );
		putenv( 'GODO_CMDMAP=' . $this->root . '/godo.json' );
		putenv( 'GODO_DIRMAP_BIN=' . $this->dirmapBin );
	}

	protected function tearDown(): void {
		putenv( 'LINUX_CATCHUP_CONFIG' );
		putenv( 'GODO_CMDMAP' );
		putenv( 'GODO_DIRMAP_BIN' );
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );

		parent::tearDown();
	}

	public function testReposWithStoredCommandsRunGodoWithStdinClosed(): void {
		file_put_contents( $this->root . '/godo.json', json_encode( [ 'notes' => [ 'git pull --ff-only' ] ] ) );

		$commands = $this->runReposUnattended( [ 'notes' ] );

		$this->assertSame( [ $this->godo() . " 'notes' </dev/null" ], $commands );
	}

	public function testReposWithoutCommandsRunTheDefaultWithoutPromptingOrSeedingTheMap(): void {
		$commands = $this->runReposUnattended( [ 'notes' ], $output );

		$this->assertSame(
			[ $this->godo() . " 'notes' --default=" . escapeshellarg( LinuxCatchup::DEFAULT_REPO_COMMAND ) . ' </dev/null' ],
			$commands
		);
		$this->assertStringNotContainsString( 'Add ', $output );
		$this->assertStringNotContainsString(
			LinuxCatchup::DEFAULT_REPO_COMMAND,
			(string) @file_get_contents( $this->root . '/godo.json' )
		);
	}

	public function testUnattendedSystemStepNeverRunsSudoEvenWithApply(): void {
		$commands = [];
		$handler  = $this->handler( $commands, linux: true, shellOutput: static fn( string $command ): string =>
			'apt list --upgradable 2>/dev/null' === $command
				? "vim/jammy-security 2:8.2 amd64 [upgradable from: 2:8.1]\n"
				: '' );
		$this->cli->setArgs( [ 'linux-catchup', '--unattended', '--only=system', '--apply', '--yes' ] );

		$output = $this->dispatch( $handler, $code );

		$this->assertSame( 0, $code );
		$this->assertSame( [], $commands );
		$this->assertStringContainsString( 'needs sudo', $output );
		$this->assertStringContainsString( 'vim', $output );
	}

	public function testUnattendedOllamaBehindReportsButNeverRunsTheInstaller(): void {
		$commands = [];
		$handler  = $this->handler(
			$commands,
			linux: true,
			shellOutput: static fn( string $command ): string => match ( true ) {
				str_starts_with( $command, 'ollama --version' ) => "ollama version is 0.1.0\n",
				str_starts_with( $command, 'curl' )             => '{"tag_name": "v0.9.0"}',
				default                                         => '',
			},
			commandExists: static fn( string $command ): bool => 'ollama' === $command,
		);
		$this->cli->setArgs( [ 'linux-catchup', '--unattended', '--only=ollama' ] );

		$output = $this->dispatch( $handler, $code );

		$this->assertSame( 0, $code );
		$this->assertSame( [], $commands );
		$this->assertStringContainsString( 'needs sudo', $output );
	}

	public function testUnattendedToolUpdatesRunWithStdinClosed(): void {
		$commands = [];
		$handler  = $this->handler( $commands, commandExists: static fn( string $command ): bool => 'claude' === $command );
		$this->cli->setArgs( [ 'linux-catchup', '--unattended', '--only=claude' ] );

		$this->dispatch( $handler, $code );

		$this->assertSame( 0, $code );
		$this->assertSame( [ 'claude update </dev/null' ], $commands );
	}

	public function testOverlappingRunSkipsWithoutRunningAnything(): void {
		$commands = [];
		$handler  = $this->handler( $commands, commandExists: static fn(): bool => true );
		$this->cli->setArgs( [ 'linux-catchup', '--unattended', '--only=claude' ] );

		$held = fopen( $this->config . '.lock', 'c' );
		$this->assertTrue( flock( $held, LOCK_EX | LOCK_NB ) );
		$output = $this->dispatch( $handler, $code );
		flock( $held, LOCK_UN );
		fclose( $held );

		$this->assertSame( 0, $code );
		$this->assertSame( [], $commands );
		$this->assertStringContainsString( 'already running', $output );
	}

	public function testAttendedOverlapFailsSoAHumanNotices(): void {
		$commands = [];
		$handler  = $this->handler( $commands, commandExists: static fn(): bool => true );
		$this->cli->setArgs( [ 'linux-catchup', '--only=claude' ] );

		$held = fopen( $this->config . '.lock', 'c' );
		flock( $held, LOCK_EX | LOCK_NB );
		$this->dispatch( $handler, $code );
		fclose( $held );

		$this->assertSame( 1, $code );
		$this->assertSame( [], $commands );
	}

	private function handler(
		array &$commands,
		bool $linux = false,
		?callable $shellOutput = null,
		?callable $commandExists = null,
	): LinuxCatchupCommand {
		$catchup = $linux
			? new class( $this->cli ) extends LinuxCatchup {
				public function isLinux(): bool {
					return true;
				}
			}
			: new LinuxCatchup( $this->cli );

		return new LinuxCatchupCommand(
			$this->cli,
			$catchup,
			runner: static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
			shellOutput: $shellOutput ?? static fn(): string => '',
			fileExists: static fn(): bool => false,
			commandExists: $commandExists ?? static fn(): bool => false,
		);
	}

	private function dispatch( LinuxCatchupCommand $handler, ?int &$code ): string {
		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();

		return (string) ob_get_clean();
	}

	/**
	 * Run the repos step unattended with every key resolving to the temp root.
	 *
	 * @param string[] $keys
	 * @return string[] commands the runner received
	 */
	private function runReposUnattended( array $keys, ?string &$output = null ): array {
		file_put_contents( $this->config, json_encode( [ 'repos' => $keys ] ) );
		file_put_contents( $this->dirmapBin, "#!/bin/sh\necho " . escapeshellarg( $this->root ) . "\n" );
		chmod( $this->dirmapBin, 0755 );

		$commands = [];
		$handler  = new LinuxCatchupCommand(
			$this->cli,
			new LinuxCatchup( $this->cli ),
			new Godo( $this->cli ),
			runner: static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
		);
		$this->cli->setArgs( [ 'linux-catchup', '--unattended', '--only=repos' ] );
		$output = $this->dispatch( $handler, $code );
		$this->assertSame( 0, $code );

		return $commands;
	}

	private function godo(): string {
		return escapeshellarg( dirname( __DIR__, 2 ) . '/bin/godo' );
	}
}
