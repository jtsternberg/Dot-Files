<?php

namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'xname',
	description: 'Rename files to cross-OS-friendly names (spaces to dashes, illegal characters stripped).',
)]
final class XnameCommand {

	public function __construct(
		private readonly Helpers $cli,
		private ?Xname $xname = null
	) {
	}

	#[Command(
		description: 'Rename the given file(s)/dir(s). Applies by default; use --dry-run to preview.',
		default: true,
	)]
	public function rename(
		#[Option( name: 'dry-run', aliases: [ 'n' ], description: 'Preview the renames without touching anything.' )]
		bool $dryRun = false,
		#[Option( aliases: [ 'l' ], description: 'Also lowercase the name.' )]
		bool $lower = false,
		#[Option( name: 'no-commit', description: 'Do not auto-commit renames of already-committed files.' )]
		bool $noCommit = false,
		#[Argument( description: 'One or more files or directories to rename.', completion: 'files' )]
		string ...$paths
	): int {
		if ( empty( $paths ) ) {
			$this->cli->err( 'Nothing to do. Pass one or more files or directories.' );

			return 1;
		}

		$xname = $this->xname ?: new Xname();
		$abs   = array_map( fn( string $p ): string => $this->cli->convertPathToAbsolute( $p ), $paths );
		$plan  = $xname->plan( $abs, $lower );

		$problems = 0;
		$renamed  = 0;
		$committable = []; // repoRoot => [ 'specs' => [], 'lines' => [] ]

		foreach ( $plan as $record ) {
			switch ( $record['status'] ) {
				case 'missing':
					$this->cli->err( 'not found: ' . $record['path'] );
					$problems++;
					break;

				case 'empty':
					$this->cli->err( 'skip (name would be empty): ' . $record['old'] );
					$problems++;
					break;

				case 'unchanged':
					$this->cli->msg( 'ok  ' . $record['old'] . ' (already clean)', 'cyan' );
					break;

				case 'collision':
					$this->cli->err( 'skip (target exists): ' . $record['old'] . ' -> ' . $record['new'] );
					$problems++;
					break;

				case 'ready':
					$arrow = $record['old'] . '  ->  ' . $record['new']
						. ( $record['tracked'] ? '  [git mv]' : '' );

					if ( $dryRun ) {
						$this->cli->output( 'would rename  ' . $arrow );
						break;
					}

					if ( ! $xname->rename( $record ) ) {
						$this->cli->err( 'FAILED  ' . $arrow );
						$problems++;
						break;
					}

					$this->cli->output( 'renamed  ' . $arrow );
					$renamed++;

					// Auto-commit only files that were genuinely in version
					// control (committed and clean); a git-mv'd staged or dirty
					// file is renamed but left for the user to commit.
					if ( $record['committed'] && null !== $record['repoRoot'] ) {
						$root = $record['repoRoot'];
						$committable[ $root ] ??= [ 'specs' => [], 'lines' => [] ];
						$committable[ $root ]['specs'][] = $this->relativeTo( $root, $record['dir'] . '/' . $record['old'] );
						$committable[ $root ]['specs'][] = $this->relativeTo( $root, $record['target'] );
						$committable[ $root ]['lines'][] = $record['old'] . ' -> ' . $record['new'];
					}
					break;
			}
		}

		if ( ! $dryRun && ! $noCommit && ! empty( $committable ) ) {
			foreach ( $committable as $root => $group ) {
				$sha = $xname->commit( $root, $group['specs'], $group['lines'] );
				if ( null !== $sha ) {
					$this->cli->msg(
						'committed ' . $sha . '  (' . count( $group['lines'] ) . ' rename(s) in ' . $root . ')',
						'green'
					);
				} else {
					$this->cli->err( 'commit FAILED in ' . $root );
					$problems++;
				}
			}
		}

		if ( $dryRun && $renamed === 0 ) {
			$this->cli->msg( 'Dry run — re-run without --dry-run to apply.', 'yellow' );
		}

		return $problems > 0 ? 1 : 0;
	}

	/**
	 * Path relative to a repo root, for use as a git pathspec.
	 */
	private function relativeTo( string $root, string $path ): string {
		$prefix = rtrim( $root, '/' ) . '/';
		if ( 0 === strpos( $path, $prefix ) ) {
			return substr( $path, strlen( $prefix ) );
		}

		return $path;
	}
}
