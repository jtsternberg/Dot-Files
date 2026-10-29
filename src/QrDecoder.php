<?php
namespace JT;

/** Local QR decoding. The binary can be injected for isolated shell-boundary tests. */
final class QrDecoder {

	public function __construct( private readonly string $binary = 'zbarimg' ) {}

	/** @return array{text:?string, error:?string, error_type:?string} */
	public function decode( string $image ): array {
		if ( ! is_file( $image ) || ! is_readable( $image ) ) {
			return $this->failure( "No readable image: {$image}", 'input' );
		}

		// A file for stderr avoids blocking if a corrupt image produces many diagnostics.
		$stderr = tmpfile();
		if ( false === $stderr ) {
			return $this->failure( 'Unable to create decoder diagnostics stream.', 'decoder' );
		}
		$process = @proc_open(
			[ $this->binary, '--quiet', '--raw', '-Sdisable', '-Sqrcode.enable', $image ],
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => $stderr ],
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			fclose( $stderr );
			return $this->missingDependency();
		}
		fclose( $pipes[0] );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$code = proc_close( $process );
		rewind( $stderr );
		$error = trim( (string) stream_get_contents( $stderr ) );
		fclose( $stderr );

		if ( 127 === $code || 126 === $code ) {
			return $this->missingDependency();
		}
		if ( 4 === $code || ( 0 === $code && '' === $output ) ) {
			return $this->failure( 'No QR code found in the image.', 'empty' );
		}
		if ( 0 !== $code ) {
			return $this->failure( 'Could not decode image.' . ( '' !== $error ? " {$error}" : '' ), 'decoder' );
		}

		// ZBar appends one LF per symbol. Preserve whitespace inside the payload.
		$text = str_ends_with( $output, "\n" ) ? substr( $output, 0, -1 ) : $output;

		return [ 'text' => $text, 'error' => null, 'error_type' => null ];
	}

	private function missingDependency(): array {
		return $this->failure(
			'Cannot run zbarimg. Install ZBar: brew install zbar (macOS), or sudo apt install zbar-tools (Ubuntu).',
			'dependency'
		);
	}

	private function failure( string $error, string $type ): array {
		return [ 'text' => null, 'error' => $error, 'error_type' => $type ];
	}
}
