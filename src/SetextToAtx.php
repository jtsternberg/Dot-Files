<?php

namespace JT;

/**
 * Rewrites setext headings (underlined with = or -) as ATX headings (# / ##).
 *
 * Written for a one-time vault normalization: jt-blog-fetch used to inherit
 * league/html-to-markdown's setext default, so every fetched post has
 * underlined H1/H2 while H3+ are already ATX. Setext also can't express H3+ at
 * all, and ATX-only renderers (the visual-review skill's, for one) read the
 * underlined form as plain paragraphs.
 *
 * The care here is entirely about what else is made of dashes. A line of dashes
 * is a setext underline only sometimes; it is also a YAML frontmatter fence, a
 * thematic break, a table delimiter row, or literal text inside fenced code.
 * Getting that wrong rewrites a post's frontmatter into a heading. So the rule
 * is deliberately narrow: convert only when the underline sits directly under a
 * single-line paragraph that starts its own block. Anything ambiguous is left
 * exactly as it was found.
 */
final class SetextToAtx {

	/**
	 * Normalize a markdown string.
	 *
	 * @param string $markdown
	 * @return array{markdown: string, converted: int}
	 */
	public function convertString( string $markdown ): array {
		$lines     = explode( "\n", $markdown );
		$out       = [];
		$converted = 0;
		$inFence   = false;
		$total     = count( $lines );

		// A frontmatter block only counts at the very top of the file.
		$frontmatterEnd = $this->frontmatterEnd( $lines );

		for ( $i = 0; $i < $total; $i++ ) {
			$line = $lines[ $i ];

			if ( $i <= $frontmatterEnd ) {
				$out[] = $line;
				continue;
			}

			if ( preg_match( '/^\s*(```|~~~)/', $line ) ) {
				$inFence = ! $inFence;
				$out[]   = $line;
				continue;
			}

			if ( $inFence ) {
				$out[] = $line;
				continue;
			}

			$underline = $this->underlineLevel( $lines[ $i + 1 ] ?? null );

			if ( null !== $underline && $this->isHeadingText( $line, $out ) ) {
				[ $prefix, $text ] = $this->splitBlockPrefix( rtrim( $line ) );

				foreach ( $prefix as $block ) {
					$out[] = $block;
					$out[] = '';
				}

				$out[] = str_repeat( '#', $underline ) . ' ' . $text;
				$i++; // Drop the underline.
				$converted++;
				continue;
			}

			$out[] = $line;
		}

		return [
			'markdown'  => implode( "\n", $out ),
			'converted' => $converted,
		];
	}

	/**
	 * Normalize one file, optionally writing the result back.
	 *
	 * @param string $path  Markdown file.
	 * @param bool   $write Write the result back when anything changed.
	 * @return array{path: string, converted: int, written: bool}
	 */
	public function convertFile( string $path, bool $write = false ): array {
		$markdown = (string) file_get_contents( $path );
		$result   = $this->convertString( $markdown );
		$written  = false;

		if ( $write && $result['converted'] > 0 && $result['markdown'] !== $markdown ) {
			$written = false !== file_put_contents( $path, $result['markdown'] );
		}

		return [
			'path'      => $path,
			'converted' => $result['converted'],
			'written'   => $written,
		];
	}

	/**
	 * Markdown files under a path, or the path itself when it is a file.
	 *
	 * @param string $path File or directory.
	 * @return string[] Sorted absolute-ish paths, VCS and dot directories skipped.
	 */
	public function markdownFiles( string $path ): array {
		if ( is_file( $path ) ) {
			return [ $path ];
		}

		if ( ! is_dir( $path ) ) {
			return [];
		}

		$files    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ): bool {
					// Skip dot directories (.git, .obsidian, .trash) entirely.
					return ! $file->isDir() || '.' !== substr( $file->getFilename(), 0, 1 );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'md' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Index of the closing frontmatter fence, or -1 when the file has none.
	 *
	 * @param string[] $lines
	 * @return int
	 */
	private function frontmatterEnd( array $lines ): int {
		if ( '---' !== ( $lines[0] ?? null ) ) {
			return -1;
		}

		$total = count( $lines );
		for ( $i = 1; $i < $total; $i++ ) {
			if ( '---' === rtrim( $lines[ $i ] ) ) {
				return $i;
			}
		}

		// Unterminated: treat the whole file as frontmatter rather than guess.
		return $total;
	}

	/**
	 * Heading level a line of = or - would set, or null if it is not an underline.
	 *
	 * @param string|null $line
	 * @return int|null 1 for =, 2 for -.
	 */
	private function underlineLevel( ?string $line ): ?int {
		if ( null === $line || ! preg_match( '/^(=+|-+)$/', rtrim( $line ), $m ) ) {
			return null;
		}

		// Require at least two marks: a lone "-" is a list bullet far more often
		// than a heading underline, and "=" alone never appears in this vault.
		if ( strlen( $m[1] ) < 2 ) {
			return null;
		}

		return '=' === $m[1][0] ? 1 : 2;
	}

	/**
	 * Peel block-level HTML off the front of a heading line.
	 *
	 * The old fetch path emitted block-level comments and figures inline, so
	 * pre-fix files hold lines like "<!--more-->A Better Analogy" underlined as
	 * one heading. A fetch today puts the block on its own line and the heading
	 * on the next, so match that. Inline markup wrapping the whole heading
	 * (a <span> around the text) is left inside the heading, because that is
	 * also what a fetch produces.
	 *
	 * @param string $line
	 * @return array{0: string[], 1: string} Blocks to emit above, remaining heading text.
	 */
	private function splitBlockPrefix( string $line ): array {
		$blocks  = [];
		$pattern = '#^\s*(<!--.*?-->|<(figure|div|iframe|table|blockquote|p|ul|ol|pre|video|audio)\b[^>]*>.*?</\2>)#is';

		while ( preg_match( $pattern, $line, $m ) ) {
			$remainder = substr( $line, strlen( $m[0] ) );

			// Only peel it off when real heading text follows on the same line.
			if ( '' === trim( $remainder ) ) {
				break;
			}

			$blocks[] = trim( $m[1] );
			$line     = ltrim( $remainder );
		}

		return [ $blocks, $line ];
	}

	/**
	 * Whether a line is a single-line paragraph that an underline can turn into
	 * a heading: it has content, it starts its own block, and it is not already
	 * some other construct (ATX heading, list item, quote, table row, code).
	 *
	 * @param string   $line    Candidate heading text.
	 * @param string[] $emitted Lines emitted so far; the last one is what precedes it.
	 * @return bool
	 */
	private function isHeadingText( string $line, array $emitted ): bool {
		if ( '' === trim( $line ) ) {
			return false;
		}

		// Indented four spaces or a tab is an indented code block.
		if ( preg_match( '/^(\t| {4,})/', $line ) ) {
			return false;
		}

		if ( preg_match( '/^\s*(#|>|\||[-*+=]\s|\d+[.)]\s)/', $line ) ) {
			return false;
		}

		// A break or underline is never heading text. Without this, two dividers
		// in a row ("---\n---") make the first one the "heading" and the second
		// its underline — found in a recipe note with a doubled divider.
		if ( preg_match( '/^(=+|-+|\*{3,}|_{3,})\s*$/', trim( $line ) ) ) {
			return false;
		}

		// Must open a block: start of file, or a blank line before it. This is
		// what keeps a multi-line paragraph sitting above a thematic break from
		// being read as a heading.
		$previous = end( $emitted );

		return false === $previous || '' === trim( (string) $previous );
	}
}
