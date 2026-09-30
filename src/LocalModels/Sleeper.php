<?php

namespace JT\LocalModels;

/**
 * Every wait in the model-store code (the drive settle window, app teardown,
 * reopen and `mw` retries) goes through here, so tests can swap in a fake clock.
 *
 * Static because the waits sit deep inside objects the CLI and registry build
 * themselves (Watcher -> Drive, EngineRegistry -> MacWhisperEngine), and
 * threading a sleeper through every constructor would change each public
 * signature for the sake of one seam.
 */
final class Sleeper {

	/** @var ?\Closure(int): void */
	private static ?\Closure $fake = null;

	public static function usleep( int $microseconds ): void {
		if ( null !== self::$fake ) {
			( self::$fake )( $microseconds );

			return;
		}

		if ( $microseconds > 0 ) {
			\usleep( $microseconds );
		}
	}

	/** @param ?callable(int): void $sleeper null restores real sleeping. */
	public static function fake( ?callable $sleeper ): void {
		self::$fake = null === $sleeper ? null : \Closure::fromCallable( $sleeper );
	}
}
