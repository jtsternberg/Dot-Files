<?php
namespace JT\Tests\Godo;

use JT\CLI\Command\Dispatcher;
use JT\CLI\Command\Registry;
use JT\CLI\Command\ZshCompletion;
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

	public function testGodoCompletionFileMatchesGeneratedOutput(): void {
		$handler  = new GodoCommand( $this->cli, new Godo( $this->cli ) );
		$expected = ( new ZshCompletion() )->render( Registry::fromHandler( $handler ) );
		$actual   = file_get_contents(
			dirname( __DIR__, 2 ) . '/zsh-custom/plugins/godo-completions/godo-completions.plugin.zsh'
		);

		$this->assertSame( $expected, $actual );
	}
}
