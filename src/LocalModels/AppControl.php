<?php

namespace JT\LocalModels;

/**
 * Asking model applications to quit and to come back.
 *
 * Always `osascript … to quit`, never a signal: Ollama and MacWhisper may be
 * mid-write to a model file, and a kill is how those get truncated. Reopening is
 * `open -a`, which puts the app back the way the user launched it.
 *
 * Subclass it to record calls in tests — no test may quit a real application.
 */
class AppControl {

	public function isRunning( string $app ): bool {
		if ( 'Darwin' !== PHP_OS_FAMILY ) {
			return false;
		}

		exec( 'pgrep -x ' . escapeshellarg( $app ) . ' 2>/dev/null', $out, $code );

		return 0 === $code && ! empty( $out );
	}

	public function quit( string $app ): bool {
		if ( 'Darwin' !== PHP_OS_FAMILY ) {
			return false;
		}

		exec(
			'osascript -e ' . escapeshellarg( 'tell application "' . $app . '" to quit' ) . ' 2>&1',
			$out,
			$code
		);

		return 0 === $code;
	}

	/**
	 * Wait for the app to actually go away. Quitting is asynchronous, and a
	 * subprocess holding a file descriptor (Ollama's llama-server) releases it
	 * only once the parent has torn it down — retrying the eject before then just
	 * reproduces the same "busy".
	 */
	public function waitForExit( string $app, int $tries = 10, int $sleepMicroseconds = 300000 ): bool {
		for ( $attempt = 0; $attempt < max( 1, $tries ); $attempt++ ) {
			if ( ! $this->isRunning( $app ) ) {
				return true;
			}

			usleep( $sleepMicroseconds );
		}

		return ! $this->isRunning( $app );
	}

	public function reopen( string $app ): bool {
		if ( 'Darwin' !== PHP_OS_FAMILY ) {
			return false;
		}

		exec( 'open -a ' . escapeshellarg( $app ) . ' 2>&1', $out, $code );

		return 0 === $code;
	}
}
