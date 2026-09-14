<?php
namespace JT\Tests\ClaudeUpdate;

use JT\CLI\Command\Dispatcher;
use JT\ClaudeUpdateCommand;
use JT\Tests\TestCase;

final class ClaudeUpdateCommandTest extends TestCase {

	private const CHANGELOG = <<<'MD'
		# Changelog

		## 2.1.270

		- Added a shiny new feature
		- Fixed a nagging bug
		- Improved something minor

		## 2.1.269

		- Added an older feature
		- Fixed an older bug

		## 2.1.268

		- Fixed something before the version we were on
		MD;

	private function handler( array $versions, ?callable $runner = null ): ClaudeUpdateCommand {
		$calls = 0;

		return new ClaudeUpdateCommand(
			$this->cli,
			null,
			$runner ?: static fn( string $command ): int => 0,
			static function () use ( $versions, &$calls ): ?string {
				$version = $versions[ $calls ] ?? end( $versions );
				++$calls;

				return $version;
			},
			static fn(): ?string => self::CHANGELOG,
			static fn(): bool => true,
		);
	}

	public function testSummarizesFeaturesAndCollapsesFixesAndOtherToCountsByDefault(): void {
		$handler = $this->handler( [ '2.1.268', '2.1.270' ] );
		$this->cli->setArgs( [ 'claude-update' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'Updated 2.1.268 → 2.1.270 (2 versions)', $output );
		$this->assertStringContainsString( 'Features (2)', $output );
		$this->assertStringContainsString( 'Added a shiny new feature', $output );
		$this->assertStringContainsString( 'Added an older feature', $output );
		$this->assertStringContainsString( 'Bugfixes (2)', $output );
		$this->assertStringNotContainsString( 'Fixed a nagging bug', $output );
		$this->assertStringContainsString( 'Other changes (1)', $output );
		$this->assertStringNotContainsString( 'Improved something minor', $output );
		$this->assertStringContainsString( '--full', $output );
	}

	public function testFullOptionListsEveryBugfixAndOtherChangeToo(): void {
		$handler = $this->handler( [ '2.1.268', '2.1.270' ] );
		$this->cli->setArgs( [ 'claude-update', '--full' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'Fixed a nagging bug', $output );
		$this->assertStringContainsString( 'Improved something minor', $output );
	}

	public function testNoOpUpdateReportsNothingToSummarizeWithoutTouchingTheChangelog(): void {
		$changelogCalls = 0;
		$handler        = new ClaudeUpdateCommand(
			$this->cli,
			null,
			static fn( string $command ): int => 0,
			static fn(): ?string => '2.1.270',
			static function () use ( &$changelogCalls ): ?string {
				++$changelogCalls;

				return self::CHANGELOG;
			},
			static fn(): bool => true,
		);
		$this->cli->setArgs( [ 'claude-update' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'Already on the latest (2.1.270)', $output );
		$this->assertSame( 0, $changelogCalls );
	}

	public function testMissingClaudeOnPathFailsWithoutRunningUpdate(): void {
		$commands = [];
		$handler  = new ClaudeUpdateCommand(
			$this->cli,
			null,
			static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
			null,
			null,
			static fn(): bool => false,
		);
		$this->cli->setArgs( [ 'claude-update' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 1, $code );
		$this->assertSame( [], $commands );
	}

	public function testNonZeroUpdateExitCodeIsReturnedWithoutSummarizing(): void {
		$handler = $this->handler(
			[ '2.1.268' ],
			static fn( string $command ): int => 3
		);
		$this->cli->setArgs( [ 'claude-update' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 3, $code );
	}

	public function testUnreadableVersionAfterUpdateIsReportedAsAnError(): void {
		$handler = $this->handler( [ '2.1.268', null ] );
		$this->cli->setArgs( [ 'claude-update' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( "couldn't read the installed version", $output );
	}

	public function testMissingChangelogFallsBackToAPlainNotice(): void {
		$handler = new ClaudeUpdateCommand(
			$this->cli,
			null,
			static fn( string $command ): int => 0,
			( function () {
				$calls = 0;

				return function () use ( &$calls ): ?string {
					$version = 0 === $calls ? '2.1.268' : '2.1.270';
					++$calls;

					return $version;
				};
			} )(),
			static fn(): ?string => null,
			static fn(): bool => true,
		);
		$this->cli->setArgs( [ 'claude-update' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( "couldn't fetch a changelog", $output );
		$this->assertStringContainsString( 'code.claude.com/docs/en/changelog', $output );
	}

	public function testHelpHasNoOperationalSideEffects(): void {
		$commands = [];
		$handler  = new ClaudeUpdateCommand(
			$this->cli,
			null,
			static function ( string $command ) use ( &$commands ): int {
				$commands[] = $command;

				return 0;
			},
		);
		$this->cli->setArgs( [ 'claude-update', '--help' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertSame( [], $commands );
		$this->assertStringContainsString( 'usage: claude-update', $output );
	}
}
