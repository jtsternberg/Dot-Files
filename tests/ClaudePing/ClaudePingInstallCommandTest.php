<?php
namespace JT\Tests\ClaudePing;

use JT\ClaudePing;
use JT\ClaudePingInstallCommand;
use JT\CLI\Command\Dispatcher;
use JT\Tests\TestCase;

final class ClaudePingInstallCommandTest extends TestCase {

	private string $crontabState = '';

	protected function setUp(): void {
		parent::setUp();

		$this->crontabState = sys_get_temp_dir() . '/claudeping-command-crontab-' . getmypid() . '-' . uniqid();
		putenv( 'CLAUDEPING_CRONTAB_STATE=' . $this->crontabState );
		putenv( 'CLAUDEPING_CRONTAB_BIN=' . self::sharedStub( 'claudeping-crontab', <<<'SH'
#!/bin/sh
if [ "$1" = "-l" ]; then
  [ -f "$CLAUDEPING_CRONTAB_STATE" ] && cat "$CLAUDEPING_CRONTAB_STATE"
  exit 0
fi
cp "$1" "$CLAUDEPING_CRONTAB_STATE"
exit 0
SH
		) );
		putenv( 'CLAUDEPING_CLAUDE_BIN=' . self::sharedStub( 'claudeping-claude', <<<'SH'
#!/bin/sh
if [ "${CLAUDEPING_CLAUDE_STUB_EXIT:-0}" != "0" ]; then
  exit "$CLAUDEPING_CLAUDE_STUB_EXIT"
fi
printf '%s' "$CLAUDEPING_CLAUDE_STUB_REPLY"
SH
		) );
	}

	protected function tearDown(): void {
		putenv( 'CLAUDEPING_CRONTAB_STATE' );
		putenv( 'CLAUDEPING_CRONTAB_BIN' );
		putenv( 'CLAUDEPING_CLAUDE_BIN' );
		putenv( 'CLAUDEPING_CLAUDE_STUB_REPLY' );
		putenv( 'CLAUDEPING_CLAUDE_STUB_EXIT' );
		@unlink( $this->crontabState );

		parent::tearDown();
	}

	private function stubClaudeReply( string $result ): void {
		putenv( 'CLAUDEPING_CLAUDE_STUB_REPLY=' . json_encode( [ 'result' => $result ] ) );
	}

	public function testInstallDispatchesDefaultCommandAndWritesCrontab(): void {
		$this->stubClaudeReply( '30 5 * * 2-5' );
		$handler = new ClaudePingInstallCommand( $this->cli );
		$this->cli->setArgs( [ 'claudeping-install', 'weekdays at 5:30am' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString(
			'30 5 * * 2-5',
			(string) file_get_contents( $this->crontabState )
		);
		$this->assertStringContainsString( '5:30am', $output );
	}

	public function testInstallFailsWhenScheduleIsUnclear(): void {
		$this->stubClaudeReply( 'no' );
		$handler = new ClaudePingInstallCommand( $this->cli );
		$this->cli->setArgs( [ 'claudeping-install', 'sometime maybe' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 1, $code );
		$this->assertFileDoesNotExist( $this->crontabState );
	}

	public function testRemoveDispatchesAndClearsCrontab(): void {
		$this->stubClaudeReply( '30 5 * * 2-5' );
		( new ClaudePing( $this->cli ) )->install( '30 5 * * 2-5' );

		$handler = new ClaudePingInstallCommand( $this->cli );
		$this->cli->setArgs( [ 'claudeping-install', 'remove' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertStringNotContainsString(
			ClaudePing::MANAGED_TAG,
			(string) file_get_contents( $this->crontabState )
		);
	}

	public function testListDispatchesAndPrintsManagedLine(): void {
		( new ClaudePing( $this->cli ) )->install( '30 5 * * 2-5' );

		$handler = new ClaudePingInstallCommand( $this->cli );
		$this->cli->setArgs( [ 'claudeping-install', 'list' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringStartsWith( '30 5 * * 2-5 ', $output );
		$this->assertStringContainsString( '5:30am', $output );
	}

	public function testHelpDoesNotConstructTheDomainService(): void {
		$handler = new ClaudePingInstallCommand( $this->cli );
		$this->cli->setArgs( [ 'claudeping-install', '--help' ] );

		ob_start();
		$code = ( new Dispatcher( $this->cli, $handler ) )->run();
		ob_end_clean();

		$this->assertSame( 0, $code );
		$this->assertFileDoesNotExist( $this->crontabState );
	}
}
