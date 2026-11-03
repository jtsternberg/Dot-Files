<?php
namespace JT\Tests\Shell;

use PHPUnit\Framework\TestCase;

/**
 * Drives `_codex_home_share` from .misc-functions in a clean zsh against temp
 * dirs, so the real ~/.codex and ~/.codex-work are never touched.
 */
class CodexHomeShareTest extends TestCase {
	private string $root;
	private string $src;
	private string $dst;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/codex-home-share-' . uniqid();
		$this->src  = $this->root . '/src';
		$this->dst  = $this->root . '/dst';
		mkdir( $this->src . '/plugins', 0777, true );
		file_put_contents( $this->src . '/config.toml', "model = 'x'\n" );
		file_put_contents( $this->src . '/AGENTS.md', "agents\n" );
		file_put_contents( $this->src . '/auth.json', "{}\n" );
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
				'source %s; _codex_home_share %s %s',
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

	public function testFreshSetupCreatesPrivateHomeAndLinksOnlyExistingSharedNames(): void {
		[ $code, $out, $err ] = $this->share();

		$this->assertSame( 0, $code );
		$this->assertSame( '', $out . $err );
		$this->assertSame( '0700', substr( sprintf( '%o', fileperms( $this->dst ) ), -4 ) );
		$this->assertSame( [
			'AGENTS.md'   => "link:$this->src/AGENTS.md",
			'config.toml' => "link:$this->src/config.toml",
			'plugins'     => "link:$this->src/plugins",
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

	public function testRealConfigWarnsAndIsLeftAlone(): void {
		mkdir( $this->dst, 0700 );
		file_put_contents( $this->dst . '/config.toml', "model = 'work'\n" );

		[ $code, , $err ] = $this->share();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'config.toml is a real file', $err );
		$this->assertSame( 1, substr_count( trim( $err ), "\n" ) + 1 );
		$this->assertSame( "model = 'work'\n", file_get_contents( $this->dst . '/config.toml' ) );
		$this->assertSame( 'real', $this->listing()['config.toml'] );
		$this->assertSame( "link:$this->src/plugins", $this->listing()['plugins'] );
	}
}
