<?php
namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'from-qr',
	description: 'Print QR code payloads from an image using local ZBar (zbarimg).',
)]
final class FromQrCommand {

	public function __construct(
		private readonly Helpers $cli,
		private ?QrDecoder $decoder = null,
	) {}

	#[Command(
		description: 'Decode QR codes; multiple values are separated by newlines. Exits 1 on failure or no QR. Requires brew install zbar (macOS) or sudo apt install zbar-tools (Ubuntu).',
		default: true,
	)]
	public function run(
		#[Argument( description: 'QR image file (e.g. PNG or JPEG).', completion: 'files' )]
		string $image,
		#[Option( description: 'Print JSON (text on success; error and error_type on failure).' )]
		bool $json = false
	): int {
		$this->decoder ??= new QrDecoder();
		$result = $this->decoder->decode( (string) $this->cli->convertPathToAbsolute( $image ) );
		$failed = null !== $result['error'];

		if ( $json ) {
			$this->cli->output( json_encode(
				$failed ? [ 'error' => $result['error'], 'error_type' => $result['error_type'] ] : [ 'text' => $result['text'] ],
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
