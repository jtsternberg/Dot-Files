<?php

namespace JT;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'md-atx',
	description: 'Rewrite setext markdown headings (underlined with = or -) as ATX headings (# / ##).',
)]
final class SetextToAtxCommand {

	public function __construct(
		private readonly Helpers $cli,
		private ?SetextToAtx $converter = null
	) {
	}

	#[Command(
		description: 'Report (or with --write, apply) setext-to-ATX heading conversions.',
		default: true,
	)]
	public function normalize(
		#[Argument( description: 'Markdown file, or directory to walk recursively.' )]
		string $path,
		#[Option( description: 'Write the conversions instead of only reporting them.' )]
		bool $write = false
	): int {
		$path = $this->cli->convertPathToAbsolute( $path );

		if ( ! file_exists( $path ) ) {
			$this->cli->err( "Path not found: {$path}\n" );

			return 1;
		}

		$converter = $this->converter ?: new SetextToAtx();
		$files     = $converter->markdownFiles( $path );

		if ( empty( $files ) ) {
			$this->cli->err( "No markdown files found under: {$path}\n" );

			return 1;
		}

		$headings = 0;
		$changed  = 0;

		foreach ( $files as $file ) {
			$result = $converter->convertFile( $file, $write );

			if ( $result['converted'] < 1 ) {
				continue;
			}

			$headings += $result['converted'];
			$changed++;

			$this->cli->output( sprintf(
				'%s  %d heading%s%s',
				$this->relative( $file, $path ),
				$result['converted'],
				1 === $result['converted'] ? '' : 's',
				$write && ! $result['written'] ? '  (WRITE FAILED)' : ''
			) );
		}

		if ( 0 === $changed ) {
			$this->cli->output( 'No setext headings found in ' . count( $files ) . ' file(s).' );

			return 0;
		}

		$this->cli->output( sprintf(
			"\n%d heading%s in %d of %d file%s%s",
			$headings,
			1 === $headings ? '' : 's',
			$changed,
			count( $files ),
			1 === count( $files ) ? '' : 's',
			$write ? ' rewritten.' : ' would change. Re-run with --write to apply.'
		) );

		return 0;
	}

	/**
	 * Path shown in output: relative to the scanned directory when it is one.
	 *
	 * @param string $file
	 * @param string $root
	 * @return string
	 */
	private function relative( string $file, string $root ): string {
		if ( is_dir( $root ) && 0 === strpos( $file, rtrim( $root, '/' ) . '/' ) ) {
			return substr( $file, strlen( rtrim( $root, '/' ) ) + 1 );
		}

		return $file;
	}
}
