<?php

namespace JT;

/**
 * Finds rotted symlinks in bin/.
 *
 * Several bin/ entries are hand-made symlinks into other repos (Claude Code
 * plugins, for one). When those repos restructure, the link dangles and the
 * command silently disappears from PATH with no error anywhere — which is how
 * wplocal, localwpshell and silentlocalwpshell sat broken. BinLinksTest turns
 * that into a suite failure.
 *
 * "Rotted" is deliberately narrower than "dangling": the target's tree has to
 * still exist on this machine. A link into a checkout that simply isn't
 * installed here (a macOS-only plugin dir, on Linux) is a fact about the
 * machine, not a bug in the repo, and stays quiet.
 */
final class BinLinks {

	/**
	 * Rotted symlinks in a bin directory.
	 *
	 * @param string|null $binDir Directory to scan. Defaults to the repo's bin/.
	 * @return array<int, array{link: string, target: string}> Link basename + raw target, sorted by name.
	 */
	public static function rot( ?string $binDir = null ): array {
		$binDir = $binDir ?: Paths::path( 'bin' );

		$entries = @scandir( $binDir );
		if ( false === $entries ) {
			return [];
		}

		$rot = [];
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$link = $binDir . '/' . $name;
			if ( ! is_link( $link ) ) {
				continue;
			}

			$target = readlink( $link );
			if ( false === $target || self::resolves( $binDir, $target ) ) {
				continue;
			}

			$rot[] = [ 'link' => $name, 'target' => $target ];
		}

		return $rot;
	}

	/**
	 * Whether a link target is fine to leave alone: it exists, or its
	 * surrounding tree is absent from this machine entirely.
	 *
	 * The tree test looks two levels up from the target. That is the level that
	 * distinguishes "the file moved within a checkout I have" (grandparent
	 * present — rot) from "I don't have this checkout" (grandparent missing).
	 *
	 * @param string $binDir Directory holding the link, for resolving relative targets.
	 * @param string $target Raw readlink() value.
	 * @return bool
	 */
	private static function resolves( string $binDir, string $target ): bool {
		$path = self::absolute( $binDir, $target );

		if ( file_exists( $path ) ) {
			return true;
		}

		return ! is_dir( dirname( $path, 2 ) );
	}

	/**
	 * Resolve a link target against the directory holding the link.
	 *
	 * @param string $binDir Directory holding the link.
	 * @param string $target Raw readlink() value, absolute or relative.
	 * @return string
	 */
	private static function absolute( string $binDir, string $target ): string {
		return '/' === substr( $target, 0, 1 ) ? $target : $binDir . '/' . $target;
	}
}
