<?php

namespace JT\LocalModels;

/**
 * Removable-volume presence, with a settle window.
 *
 * The watcher fires on any /Volumes mutation, and launchd can hand us that event
 * before the volume's directories are readable. So "not mounted" is only true
 * after a short bounded wait — otherwise a mount event races into a spurious flip
 * back to the local store.
 */
final class Drive {

	public function __construct(
		private readonly string $volumesRoot = '/Volumes'
	) {
	}

	public function volumePath( string $name ): string {
		return $this->volumesRoot . '/' . $name;
	}

	public function isMounted( string $name ): bool {
		$path = $this->volumePath( $name );

		return is_dir( $path ) && is_readable( $path );
	}

	/**
	 * Wait briefly for a volume to become readable. Returns its final state.
	 *
	 * Only the mount edge needs this; an eject is immediate and the first check
	 * already answers it.
	 */
	public function settle( string $name, int $tries = 4, int $sleepMicroseconds = 300000 ): bool {
		for ( $attempt = 0; $attempt < max( 1, $tries ); $attempt++ ) {
			if ( $this->isMounted( $name ) ) {
				return true;
			}

			if ( $attempt < $tries - 1 ) {
				usleep( $sleepMicroseconds );
			}
		}

		return false;
	}
}
