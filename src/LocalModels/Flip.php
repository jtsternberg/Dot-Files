<?php

namespace JT\LocalModels;

/**
 * Atomic store-symlink swap.
 *
 * Every engine points one app-facing path (~/.ollama-models, MacWhisper's models
 * root) at one of two real stores. The swap is `symlink` to a temp name followed
 * by `rename` over the live path, because rename(2) replaces a symlink
 * atomically: there is never an instant where the app sees no store at all.
 * (bin/ollamodels historically unlinked first, which leaves exactly that window.)
 *
 * A path that is a real file or directory is NEVER touched. That is the models
 * root before migration — tens of GB of real user data — and refusing is the only
 * safe answer.
 */
final class Flip {

	/**
	 * Point $link at $target. Returns false without mutating anything if the
	 * target is not a real directory, or the link path holds real data.
	 */
	public static function swap( string $link, string $target ): bool {
		if ( ! is_dir( $target ) ) {
			return false;
		}

		if ( ! is_link( $link ) && file_exists( $link ) ) {
			return false;
		}

		// The app-support directory holding the link can be absent on a fresh
		// machine; the link's own parent is ours to create, its contents are not.
		$parent = dirname( $link );
		if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			return false;
		}

		$tmp = $link . '.aimodels-tmp.' . getmypid();
		if ( is_link( $tmp ) || file_exists( $tmp ) ) {
			@unlink( $tmp );
		}

		if ( ! @symlink( $target, $tmp ) ) {
			return false;
		}

		if ( ! @rename( $tmp, $link ) ) {
			@unlink( $tmp );

			return false;
		}

		return self::currentTarget( $link ) === $target;
	}

	/**
	 * What the link points at, or null when it is absent or not a symlink.
	 */
	public static function currentTarget( string $link ): ?string {
		if ( ! is_link( $link ) ) {
			return null;
		}

		$target = readlink( $link );

		return false === $target ? null : $target;
	}
}
