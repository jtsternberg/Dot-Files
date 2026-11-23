<?php
namespace JT\Tests\Shell;

use PHPUnit\Framework\TestCase;

/**
 * Drives `_claude_home_share` from .misc-functions in a clean zsh against temp
 * dirs, so the real ~/.claude and ~/.claude-alt are never touched.
 */
class ClaudeHomeShareTest extends TestCase {
	private string $root;
	private string $src;
	private string $dst;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/claude-home-share-' . uniqid();
		$this->src  = $this->root . '/src';
		$this->dst  = $this->root . '/dst';
		mkdir( $this->src . '/projects', 0777, true );
		mkdir( $this->src . '/runway', 0777, true );
		file_put_contents( $this->src . '/settings.json', "{}\n" );
		file_put_contents( $this->src . '/CLAUDE.md', "claude\n" );
		file_put_contents( $this->src . '/.claude.json', "{}\n" );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
	}

	/** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
	private function share(): array {
		$file = dirname( __DIR__, 2 ) . '/.misc-functions';
		$cmd  = sprintf(
			'zsh -fc %s',
			escapeshellarg( sprintf(
				'source %s; _claude_home_share %s %s',
				escapeshellarg( $file ),
				escapeshellarg( $this->src ),
				escapeshellarg( $this->dst )
			) )
		);
		$proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$out  = stream_get_contents( $pipes[1] );
		$err  = stream_get_contents( $pipes[2] );
		return [ proc_close( $proc ), $out, $err ];
	}

	private function listing(): array {
		$names = array_values( array_diff( scandir( $this->dst ), [ '.', '..' ] ) );
		$out   = [];
		foreach ( $names as $n ) {
			$p         = "$this->dst/$n";
			$out[ $n ] = is_link( $p ) ? 'link:' . readlink( $p ) : 'real';
		}
		return $out;
	}

	public function testFreshSetupLinksSharedNamesAndKeepsAccountStateOut(): void {
		[ $code, $out, $err ] = $this->share();

		$this->assertSame( 0, $code );
		$this->assertSame( '', $out . $err );
		$this->assertSame( '0700', substr( sprintf( '%o', fileperms( $this->dst ) ), -4 ) );
		// .claude.json (login/MCP state) and runway (per-account quota) stay out.
		$this->assertSame( [
			'CLAUDE.md'     => "link:$this->src/CLAUDE.md",
			'projects'      => "link:$this->src/projects",
			'settings.json' => "link:$this->src/settings.json",
		], $this->listing() );
	}

	public function testRerunIsSilentAndChangesNothing(): void {
		$this->share();
		$before = $this->listing();

		[ $code, $out, $err ] = $this->share();

		$this->assertSame( 0, $code );
		$this->assertSame( '', $out . $err );
		$this->assertSame( $before, $this->listing() );
	}

	public function testRealSettingsWarnsAndIsLeftAlone(): void {
		mkdir( $this->dst, 0700 );
		file_put_contents( $this->dst . '/settings.json', "{\"x\":1}\n" );

		[ $code, , $err ] = $this->share();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'settings.json is a real file', $err );
		$this->assertSame( "{\"x\":1}\n", file_get_contents( $this->dst . '/settings.json' ) );
		$this->assertSame( "link:$this->src/projects", $this->listing()['projects'] );
	}
}
