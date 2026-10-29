<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;
use InvalidArgumentException;

#[Program(
	name: 'from-qr',
	description: 'Read QR images locally; authenticator setup QRs automatically print the current 2FA code.',
)]
final class FromQrCommand {

	public function __construct(
		private readonly Helpers $cli,
		private ?QrDecoder $decoder = null,
		private ?Totp $totp = null,
	) {}

	#[Command(
		description: 'Decode QR codes; multiple values are separated by newlines. Exits 1 on failure or no QR. Requires brew install zbar (macOS) or sudo apt install zbar-tools (Ubuntu).',
		default: true,
	)]
	public function run(
		#[Argument( description: 'QR image file (e.g. PNG or JPEG).', completion: 'files' )]
		string $image,
		#[Option( description: 'Print JSON (text or code on success; error and error_type on failure).' )]
		bool $json = false,
		#[Option( description: 'Require a TOTP authenticator setup QR and print its current 2FA code.' )]
		bool $totp = false,
		#[Option( description: 'Print the original QR payload, including otpauth URIs, without conversion.' )]
		bool $raw = false,
		#[Option( description: 'Copy the result to the system clipboard.' )]
		bool $copy = false
	): int {
		$field = 'text';
		if ( $totp && $raw ) {
			$result = [ 'text' => null, 'error' => '--totp and --raw cannot be combined.', 'error_type' => 'usage' ];
		} else {
			$this->decoder ??= new QrDecoder();
			$result = $this->decoder->decode( (string) $this->cli->convertPathToAbsolute( $image ) );
			if ( null === $result['error'] && ! $raw
				&& ( $totp || preg_match( '/(?:^|\R)\s*otpauth:/i', $result['text'] ) )
			) {
				try {
					$this->totp ??= new Totp();
					$result['text'] = $this->totp->code( $result['text'] );
					$field = 'code';
				} catch ( InvalidArgumentException $error ) {
					$result = [ 'text' => null, 'error' => $error->getMessage(), 'error_type' => 'totp' ];
				}
			}
		}
		$failed = null !== $result['error'];

		if ( ! $failed && $copy ) {
			$clipboard = PHP_OS_FAMILY === 'Darwin' ? 'pbcopy' : ( getenv( 'WAYLAND_DISPLAY' ) ? 'wl-copy' : 'xclip' );
			$process = @proc_open( [ $clipboard, ...( 'xclip' === $clipboard ? [ '-selection', 'clipboard' ] : [] ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) {
				$result = [ 'text' => null, 'error' => "Clipboard tool unavailable: {$clipboard}", 'error_type' => 'clipboard' ];
				$failed = true;
			} else {
				fwrite( $pipes[0], $result['text'] );
				fclose( $pipes[0] );
				fclose( $pipes[1] );
				$error = stream_get_contents( $pipes[2] );
				fclose( $pipes[2] );
				if ( 0 !== proc_close( $process ) ) {
					$result = [ 'text' => null, 'error' => 'Could not copy to clipboard.' . ( $error ? ' ' . trim( $error ) : '' ), 'error_type' => 'clipboard' ];
					$failed = true;
				}
			}
		}

		if ( $json ) {
			$this->cli->output( json_encode(
				$failed ? [ 'error' => $result['error'], 'error_type' => $result['error_type'] ] : [ $field => $result['text'] ],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			) );
		} elseif ( $failed ) {
			$this->cli->errStderr( 'Error: ' . $result['error'] );
		} else {
			// Always append the record delimiter, even when a payload ends with LF.
			$this->cli->output( $result['text'] . "\n", false );
		}

		return $failed ? 1 : 0;
	}
}
