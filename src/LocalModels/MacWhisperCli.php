<?php

namespace JT\LocalModels;

/**
 * MacWhisper's own `mw` CLI, for the one thing aimodels needs from it: selecting
 * a model by its `mw models list` id.
 *
 * Observed live, and why callers use it only on a RUNNING app:
 *   - it sets file, dictation and live transcription at once (live is left
 *     alone for a whisper-cpp model, which live transcription does not offer);
 *   - it validates the id against the list the app cached at launch, so it
 *     must follow a restart onto a new store, not precede it;
 *   - on a quit app it launches MacWhisper, and right after a quit it can fail
 *     with LaunchServices -600 — the reason a failure is retried.
 *
 * Subclass to fake in tests; AIMODELS_MW_BIN points every other test at a stub.
 */
class MacWhisperCli {

	public function __construct(
		private int $retries = 3,
		private int $retrySleepMicroseconds = 700000
	) {
	}

	public function select( string $mwId ): bool {
		$command = escapeshellarg( getenv( 'AIMODELS_MW_BIN' ) ?: 'mw' )
			. ' models select ' . escapeshellarg( $mwId ) . ' >/dev/null 2>&1';

		for ( $attempt = 0; $attempt < max( 1, $this->retries ); $attempt++ ) {
			exec( $command, $out, $code );
			if ( 0 === $code ) {
				return true;
			}

			if ( $attempt < $this->retries - 1 && $this->retrySleepMicroseconds > 0 ) {
				Sleeper::usleep( $this->retrySleepMicroseconds );
			}
		}

		return false;
	}
}
