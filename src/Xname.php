<?php

namespace JT;

use JT\CLI\Helpers\Git;

/**
 * Rewrites a filename into a cross-OS-friendly form and renames the file to it.
 *
 * The dominant problem is spaces, so those are the headline transform, but the
 * same pass also strips the characters Windows forbids in a name
 * (<>:"/\|?*, control chars) and the trailing dots/spaces it silently drops.
 * The point is a name that survives a round trip through a Windows checkout, a
 * zip, or a URL without being mangled or refused.
 *
 * Two deliberate conservatisms:
 *
 *  - A leading dot is preserved, so `.gitignore` stays a dotfile rather than
 *    being trimmed down to `gitignore`.
 *  - Case is left alone unless asked (--lower). Lowercasing every file a user
 *    points at is a bigger, more surprising change than fixing spaces, and the
 *    case-insensitive-collision problem it addresses is rarer than the space
 *    problem it would cause by silently renaming `README.md`.
 *
 * This class owns the *policy* — what a clean name is, when a rename is safe,
 * and when a rename may be auto-committed. The git mechanics themselves live in
 * the shared Git helper; a rename uses `git mv` when the file is tracked (so
 * history follows it) and is only committable when the file is genuinely in
 * version control: tracked, present in HEAD, and clean.
 */
final class Xname {

	private Git $git;

	public function __construct( ?Git $git = null ) {
		$this->git = $git ?: new Git();
	}

	/**
	 * Turn a single filename (not a path) into its cross-OS-friendly form.
	 *
	 * @param string $name  Basename to clean.
	 * @param bool   $lower Also lowercase the result.
	 * @return string Cleaned name, or '' if nothing usable remains.
	 */
	public function sanitize( string $name, bool $lower = false ): string {
		// Hold a single leading dot aside so dotfiles stay dotfiles; the trim
		// at the end would otherwise eat it.
		$dot  = '';
		if ( '' !== $name && '.' === $name[0] ) {
			$dot  = '.';
			$name = substr( $name, 1 );
		}

		// Whitespace runs and Windows-illegal characters both become a dash.
		$name = preg_replace( '/\s+/u', '-', $name );
		$name = preg_replace( '/[<>:"\/\\\\|?*\x00-\x1F]+/u', '-', (string) $name );

		// Collapse dash runs, then drop any dash sitting next to a dot so
		// "file .txt" reads "file.txt", not "file-.txt".
		$name = preg_replace( '/-+/', '-', (string) $name );
		$name = preg_replace( '/-*\.-*/', '.', (string) $name );

		if ( $lower ) {
			$name = mb_strtolower( (string) $name );
		}

		// Windows forbids trailing dots and spaces; dashes at the edge are just
		// noise. The held leading dot is re-applied afterward.
		$name = trim( (string) $name, "-. \t" );

		return '' === $name ? '' : $dot . $name;
	}

	/**
	 * Classify each path: what it would become and whether it can be renamed.
	 *
	 * @param string[] $paths Absolute (or cwd-relative) file/dir paths.
	 * @param bool     $lower Pass through to sanitize().
	 * @return array<int, array{
	 *   path: string, dir: string, old: string, new: string, target: string,
	 *   status: string, tracked: bool, committed: bool, repoRoot: ?string
	 * }>
	 */
	public function plan( array $paths, bool $lower = false ): array {
		$plan = [];

		foreach ( $paths as $path ) {
			$dir = dirname( $path );
			$old = basename( $path );
			$new = $this->sanitize( $old, $lower );

			$record = [
				'path'      => $path,
				'dir'       => $dir,
				'old'       => $old,
				'new'       => $new,
				'target'    => $dir . '/' . $new,
				'status'    => 'ready',
				'tracked'   => false,
				'committed' => false,
				'repoRoot'  => null,
			];

			if ( ! file_exists( $path ) && ! is_link( $path ) ) {
				$record['status'] = 'missing';
			} elseif ( '' === $new ) {
				$record['status'] = 'empty';
			} elseif ( $new === $old ) {
				$record['status'] = 'unchanged';
			} elseif ( file_exists( $record['target'] ) || is_link( $record['target'] ) ) {
				$record['status'] = 'collision';
			}

			if ( 'ready' === $record['status'] && $this->git->isTracked( $path ) ) {
				$record['tracked']   = true;
				$record['repoRoot']  = $this->git->topLevel( $dir );
				// "Already in version control": committed and clean. A staged
				// new file, a dirty tracked file, or an ignored one is not.
				$record['committed'] = $this->git->isInHead( $path ) && $this->git->isClean( $path );
			}

			$plan[] = $record;
		}

		return $plan;
	}

	/**
	 * Perform one rename from a `ready` plan record.
	 *
	 * @param array{path: string, target: string, old: string, new: string, tracked: bool} $record
	 * @return bool Whether the file now lives at its new name.
	 */
	public function rename( array $record ): bool {
		if ( ! empty( $record['tracked'] ) ) {
			return $this->git->mv( $record['path'], $record['target'] );
		}

		return @rename( $record['path'], $record['target'] );
	}

	/**
	 * Commit already-staged renames under one repo with a standardized message.
	 *
	 * @param string   $repoRoot  Repo top level.
	 * @param string[] $pathspecs Old and new paths (relative to repoRoot).
	 * @param string[] $lines     "old -> new" lines for the commit body.
	 * @return string|null Short SHA of the new commit, or null on failure.
	 */
	public function commit( string $repoRoot, array $pathspecs, array $lines ): ?string {
		$message = "chore: normalize filenames for cross-OS compatibility\n\n"
			. implode( "\n", $lines ) . "\n";

		return $this->git->commitPaths( $repoRoot, $message, $pathspecs );
	}
}
