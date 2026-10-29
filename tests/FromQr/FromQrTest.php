<?php
namespace JT\Tests\FromQr;

use JT\CLI\Command\Dispatcher;
use JT\FromQrCommand;
use JT\QrDecoder;
use JT\Totp;
use JT\Tests\TestCase;

final class FromQrTest extends TestCase {

	private string $dir;
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/from-qr-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
		file_put_contents( $this->dir . '/image with spaces.png', 'fixture' );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	private function decoder( string $output = "https://example.com/\n", int $code = 0, string $error = '' ): QrDecoder {
		$stub = $this->dir . '/zbarimg';
		file_put_contents( $stub, "#!/usr/bin/env php\n<?php\n"
			. 'file_put_contents(' . var_export( $this->dir . '/args', true ) . ', json_encode(array_slice($argv, 1)));' . "\n"
			. 'fwrite(STDOUT, ' . var_export( $output, true ) . ');' . "\n"
			. 'fwrite(STDERR, ' . var_export( $error, true ) . ');' . "\n"
			. 'exit(' . $code . ');' );
		chmod( $stub, 0755 );

		return new QrDecoder( $stub );
	}

	private function dispatch( array $args, QrDecoder $decoder ): array {
		$this->cli->setArgs( array_merge( [ 'from-qr' ], $args ) );
		ob_start();
		$code = ( new Dispatcher( $this->cli, new FromQrCommand( $this->cli, $decoder, new Totp( static fn() => 59 ) ) ) )->run();

		return [ $code, (string) ob_get_clean() ];
	}

	public function testDecodesOnlyQrAndPassesImageAsOneArgument(): void {
		$path = $this->dir . '/image with spaces.png';
		$result = $this->decoder()->decode( $path );
		$this->assertSame( 'https://example.com/', $result['text'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( [ '--quiet', '--raw', '-Sdisable', '-Sqrcode.enable', $path ], json_decode( file_get_contents( $this->dir . '/args' ), true ) );
	}

	public function testPreservesWhitespaceAndMultiplePayloads(): void {
		$payload = "  hello\nworld  \nsecond\n";
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png' ], $this->decoder( $payload . "\n" ) );
		$this->assertSame( 0, $code );
		$this->assertSame( $payload . "\n", $output );
	}

	public function testJsonContainsDecodedText(): void {
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--json' ], $this->decoder( "WIFI:T:WPA;S:test;P:sample;;\n" ) );
		$this->assertSame( 0, $code );
		$this->assertSame( [ 'text' => 'WIFI:T:WPA;S:test;P:sample;;' ], json_decode( $output, true ) );
	}

	public function testAuthenticatorQrAutomaticallyPrintsCurrentCode(): void {
		$uri = 'otpauth://totp/Example:test?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png' ], $this->decoder( $uri . "\n" ) );
		$this->assertSame( 0, $code );
		$this->assertSame( "287082\n", $output );
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--totp', '--json' ], $this->decoder( $uri . "\n" ) );
		$this->assertSame( 0, $code );
		$this->assertSame( [ 'code' => '287082' ], json_decode( $output, true ) );
	}

	public function testRawKeepsOriginalAuthenticatorPayload(): void {
		$uri = 'otpauth://totp/Example:test?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--raw', '--json' ], $this->decoder( $uri . "\n" ) );
		$this->assertSame( 0, $code );
		$this->assertSame( [ 'text' => $uri ], json_decode( $output, true ) );
	}

	public function testForcedTotpRejectsOrdinaryQr(): void {
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--totp', '--json' ], $this->decoder() );
		$this->assertSame( 1, $code );
		$this->assertSame( 'totp', json_decode( $output, true )['error_type'] );
	}

	public function testMalformedAuthenticatorIsNotPrinted(): void {
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--json' ], $this->decoder( "otpauth://totp/test?secret=INVALID!\n" ) );
		$this->assertSame( 1, $code );
		$this->assertSame( 'totp', json_decode( $output, true )['error_type'] );
		$this->assertStringNotContainsString( 'INVALID!', $output );
	}

	public function testMixedQrResultsRequireRawMode(): void {
		$payload = "https://example.com/\notpauth://totp/test?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ\n";
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--json' ], $this->decoder( $payload ) );
		$this->assertSame( 1, $code );
		$this->assertSame( 'totp', json_decode( $output, true )['error_type'] );
		$this->assertStringNotContainsString( 'secret=', $output );
	}

	public function testRawAndTotpCannotBeCombined(): void {
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--totp', '--raw', '--json' ], $this->decoder() );
		$this->assertSame( 1, $code );
		$this->assertSame( 'usage', json_decode( $output, true )['error_type'] );
		$this->assertFileDoesNotExist( $this->dir . '/args' );
	}

	public function testMissingImageDoesNotRunDecoder(): void {
		$result = $this->decoder()->decode( $this->dir . '/missing.png' );
		$this->assertSame( 'input', $result['error_type'] );
		$this->assertFileDoesNotExist( $this->dir . '/args' );
	}

	public function testNoQrIsAnErrorEvenWhenOtherBarcodesExist(): void {
		[ $code, $output ] = $this->dispatch( [ $this->dir . '/image with spaces.png', '--json' ], $this->decoder( '', 4 ) );
		$this->assertSame( 1, $code );
		$this->assertSame( 'empty', json_decode( $output, true )['error_type'] );
	}

	public function testDecoderFailureDoesNotReturnPartialOutput(): void {
		$result = $this->decoder( "partial\n", 1, 'bad image' )->decode( $this->dir . '/image with spaces.png' );
		$this->assertNull( $result['text'] );
		$this->assertSame( 'decoder', $result['error_type'] );
		$this->assertStringContainsString( 'bad image', $result['error'] );
	}

	public function testMissingDependencyExplainsInstallation(): void {
		$result = ( new QrDecoder( $this->dir . '/not-installed' ) )->decode( $this->dir . '/image with spaces.png' );
		$this->assertSame( 'dependency', $result['error_type'] );
		$this->assertStringContainsString( 'brew install zbar', $result['error'] );
		$this->assertStringContainsString( 'apt install zbar-tools', $result['error'] );
	}

	public function testHelpAndCompletionDoNotInvokeDecoder(): void {
		$decoder = $this->decoder();
		[ $code, $help ] = $this->dispatch( [ '--help' ], $decoder );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'from-qr', $help );
		$this->assertStringContainsString( '--json', $help );
		$this->assertStringContainsString( '--totp', $help );
		$this->assertStringContainsString( '--raw', $help );
		[ $code, $completion ] = $this->dispatch( [ 'completion', 'zsh' ], $decoder );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'compdef _from_qr from-qr', $completion );
		$this->assertStringContainsString( '_files', $completion );
		$this->assertStringContainsString( '--totp', $completion );
		$this->assertStringContainsString( '--raw', $completion );
		$this->assertFileDoesNotExist( $this->dir . '/args' );
	}

	public function testCompletionLoadsOnceAndCanRetryAfterGenerationFailure(): void {
		$plugin = dirname( __DIR__, 2 ) . '/zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh';
		$this->assertStringNotContainsString( 'QR image file', file_get_contents( $plugin ) );
		$stub = $this->dir . '/from-qr';
		file_put_contents( $stub, "#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg( $this->dir . '/calls' ) . "\n"
			. '[ -e ' . escapeshellarg( $this->dir . '/ready' ) . ' ] || exit 1' . "\n"
			. "cat <<'ZSH'\n_from_qr() { return 0; }\ncompdef _from_qr from-qr\nZSH\n" );
		chmod( $stub, 0755 );
		$script = <<<'ZSH'
autoload -Uz compinit
compinit -C
_message() { :; }
source "$1"
[[ ${_comps[from-qr]} == _from_qr_lazy ]] || exit 10
[[ ! -e "$2/calls" ]] || exit 11
_from_qr_lazy && exit 12
[[ ${_comps[from-qr]} == _from_qr_lazy ]] || exit 13
touch "$2/ready"
_from_qr_lazy || exit 14
[[ ${_comps[from-qr]} == _from_qr ]] || exit 15
${_comps[from-qr]} || exit 16
ZSH;
		exec( 'PATH=' . escapeshellarg( $this->dir . ':' . getenv( 'PATH' ) )
			. ' zsh -fc ' . escapeshellarg( $script ) . ' -- ' . escapeshellarg( $plugin )
			. ' ' . escapeshellarg( $this->dir ), $out, $code );
		$this->assertSame( 0, $code );
		$this->assertSame( "completion zsh\ncompletion zsh\n", file_get_contents( $this->dir . '/calls' ) );
	}
}
