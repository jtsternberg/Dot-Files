<?php

namespace JT\LocalModels;

/**
 * MacWhisper's runner-config preferences, read and written through `defaults`.
 *
 * Each runner key holds a JSON string naming an engine and model id. The app
 * keeps these in memory and writes them back, so a write only sticks while
 * MacWhisper is quit — callers own that ordering.
 *
 * Subclass to fake in tests; AIMODELS_DEFAULTS_BIN points every other test at a
 * stub so none reads or writes the real domain.
 */
class MacWhisperPrefs {

	public const DOMAIN = 'com.goodsnooze.MacWhisper';

	/** File transcription. */
	public const SELECTED = 'selectedRunnerConfig';

	public const DICTATION = 'dictationRunnerConfig';

	public const LIVE = 'liveTranscriptionRunnerConfig';

	public function get( string $key ): ?string {
		exec( $this->bin() . ' read ' . escapeshellarg( self::DOMAIN ) . ' ' . escapeshellarg( $key ) . ' 2>/dev/null', $out, $code );

		return 0 === $code ? implode( "\n", $out ) : null;
	}

	public function set( string $key, string $value ): bool {
		exec(
			$this->bin() . ' write ' . escapeshellarg( self::DOMAIN ) . ' ' . escapeshellarg( $key )
				. ' -string ' . escapeshellarg( $value ) . ' 2>&1',
			$out,
			$code
		);

		return 0 === $code;
	}

	private function bin(): string {
		return escapeshellarg( getenv( 'AIMODELS_DEFAULTS_BIN' ) ?: 'defaults' );
	}
}
