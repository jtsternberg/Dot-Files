<?php
namespace JT\Tests\Shell;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * launchd runs a plist's script with the interpreter the plist names, which on
 * macOS is Apple's /bin/bash 3.2, not the Homebrew bash 5 a shell session uses.
 * 3.2 rejects syntax that 5 accepts (an apostrophe inside a heredoc nested in
 * $(...)), so a script can pass every manual run and still fail every scheduled
 * one. Parse each plist-launched script with the exact interpreter launchd uses.
 */
class LaunchdScriptSyntaxTest extends TestCase {
	/** @return array<string, array{0:string,1:string}> */
	public static function plistScripts(): array {
		$bin   = dirname( __DIR__, 2 ) . '/bin';
		$cases = [];
		foreach ( glob( $bin . '/*.plist' ) as $plist ) {
			$xml = simplexml_load_file( $plist );
			if ( ! $xml ) {
				continue;
			}
			$nodes = $xml->xpath( '/plist/dict/key[.="ProgramArguments"]/following-sibling::array[1]/string' );
			$args  = array_map( 'strval', $nodes ?: [] );
			if ( count( $args ) < 2 || ! preg_match( '#/(ba|z)?sh$#', $args[0] ) ) {
				continue;
			}
			// The plist holds an absolute path from install time; resolve the
			// script in this checkout so the test runs from any clone.
			$cases[ basename( $plist ) ] = [ $args[0], $bin . '/' . basename( $args[1] ) ];
		}
		return $cases;
	}

	#[DataProvider( 'plistScripts' )]
	public function testScriptParsesUnderLaunchdInterpreter( string $interpreter, string $script ): void {
		if ( ! is_executable( $interpreter ) ) {
			$this->markTestSkipped( "$interpreter not present on this platform" );
		}
		$this->assertFileExists( $script );
		exec( escapeshellarg( $interpreter ) . ' -n ' . escapeshellarg( $script ) . ' 2>&1', $out, $code );
		$this->assertSame( 0, $code, "$interpreter -n failed:\n" . implode( "\n", $out ) );
	}
}
