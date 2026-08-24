<?php

namespace JT\LocalModels;

/**
 * Restarting a model app so it re-reads its store, and deciding when not to.
 *
 * MacWhisper caches its model list at launch and `mw` exposes no reload verb, so
 * a store flip only shows up after the app restarts. It quits cleanly via
 * AppleScript (unlike Ollama, whose menubar app refuses with -128), so a quit +
 * `open -a` is the whole mechanism.
 *
 * The busy signals exist because quitting mid-transcription kills the job. They
 * are VETOES and they fail safe: an unreadable signal counts as busy. A false
 * "busy" costs a stale model list and a warning; a false "idle" costs the user's
 * transcription.
 *
 * Subclass to record calls in tests — no test may quit a real application.
 */
class AppControl {

	/** Above this, assume the app is working. Idle MacWhisper measures 0.0-0.1%. */
	public const BUSY_CPU_PERCENT = 5.0;

	private const MEDIA_EXTENSIONS = [
		'm4a', 'mp3', 'wav', 'aiff', 'aif', 'caf', 'aac', 'flac', 'ogg', 'opus',
		'mp4', 'mov', 'm4v', 'mkv', 'webm', 'avi',
	];

	public function isRunning( string $app ): bool {
		return null !== $this->pid( $app );
	}

	public function pid( string $app ): ?int {
		if ( 'Darwin' !== PHP_OS_FAMILY ) {
			return null;
		}

		exec( 'pgrep -x ' . escapeshellarg( $app ) . ' 2>/dev/null', $out, $code );
		if ( 0 !== $code || empty( $out ) ) {
			return null;
		}

		return (int) trim( (string) $out[0] );
	}

	/**
	 * Recent CPU utilisation, or null when it cannot be read — which callers must
	 * treat as busy rather than idle.
	 */
	public function cpuPercent( string $app ): ?float {
		$pid = $this->pid( $app );
		if ( null === $pid ) {
			return null;
		}

		exec( 'ps -o %cpu= -p ' . escapeshellarg( (string) $pid ) . ' 2>/dev/null', $out, $code );
		$value = trim( implode( '', $out ) );
		if ( 0 !== $code || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Audio/video files the app currently holds open — a transcription in flight,
	 * or at least a document loaded that the user would not want interrupted.
	 *
	 * @return string[]
	 */
	public function openMediaFiles( string $app ): array {
		$pid = $this->pid( $app );
		if ( null === $pid ) {
			return [];
		}

		$lsof = getenv( 'AIMODELS_LSOF_BIN' ) ?: 'lsof';
		exec(
			escapeshellarg( $lsof ) . ' -p ' . escapeshellarg( (string) $pid ) . ' -Fn 2>/dev/null',
			$lines
		);

		$found = [];
		foreach ( $lines as $line ) {
			if ( 'n' !== substr( $line, 0, 1 ) ) {
				continue;
			}

			$path      = substr( $line, 1 );
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $extension, self::MEDIA_EXTENSIONS, true ) ) {
				$found[] = $path;
			}
		}

		return $found;
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
	 * Quitting is asynchronous; wait for the process to actually go away before
	 * relaunching, or the relaunch races the teardown.
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
