<?php
namespace JT;

/**
 * ClaudeChangelog — parses the Claude Code CLI's changelog markdown (the same
 * `## X.Y.Z` / `- ` format published at
 * github.com/anthropics/claude-code/blob/main/CHANGELOG.md and mirrored to
 * ~/.claude/cache/changelog.md) into a summary of what changed between two
 * versions.
 *
 * Pure parsing/formatting only; ClaudeUpdateCommand owns running `claude
 * update`, reading `claude --version`, and fetching the changelog text.
 */
class ClaudeChangelog {

	/**
	 * Parse a changelog into ordered sections (newest first, matching
	 * upstream's own order), each with its version and raw bullet lines.
	 *
	 * @return array<int, array{version:string, lines:string[]}>
	 */
	public function parseSections( string $changelog ): array {
		if ( ! preg_match_all(
			'/^## (\d+\.\d+\.\d+)[ \t]*\R(.*?)(?=^## \d|\z)/ms',
			$changelog,
			$matches,
			PREG_SET_ORDER
		) ) {
			return [];
		}

		$sections = [];
		foreach ( $matches as $match ) {
			$lines = [];
			foreach ( preg_split( '/\R/', trim( $match[2] ) ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line && '-' === $line[0] ) {
					$lines[] = trim( substr( $line, 1 ) );
				}
			}
			$sections[] = [ 'version' => $match[1], 'lines' => $lines ];
		}

		return $sections;
	}

	/**
	 * Sections strictly newer than $since. Stops at the first section at or
	 * below it rather than walking the whole file — safe because the
	 * changelog's own order is newest-first, same as parseSections() returns.
	 *
	 * @param array<int, array{version:string, lines:string[]}> $sections
	 * @return array<int, array{version:string, lines:string[]}>
	 */
	public function sectionsSince( array $sections, string $since ): array {
		$result = [];
		foreach ( $sections as $section ) {
			if ( version_compare( $section['version'], $since, '<=' ) ) {
				break;
			}
			$result[] = $section;
		}

		return $result;
	}

	/**
	 * Bucket a bullet by its leading verb. Matches the vocabulary the
	 * changelog itself is written in (Added.../Fixed.../Improved...); anything
	 * else — Changed, Removed, Windows:, etc. — is "other".
	 */
	public function categorize( string $line ): string {
		if ( 0 === stripos( $line, 'Added ' ) ) {
			return 'features';
		}

		if ( 0 === stripos( $line, 'Fixed ' ) ) {
			return 'bugfixes';
		}

		return 'other';
	}

	/**
	 * @param array<int, array{version:string, lines:string[]}> $sections
	 * @return array{features:string[], bugfixes:string[], other:string[], versionCount:int}
	 */
	public function summarize( array $sections ): array {
		$summary = [
			'features'     => [],
			'bugfixes'     => [],
			'other'        => [],
			'versionCount' => count( $sections ),
		];

		foreach ( $sections as $section ) {
			foreach ( $section['lines'] as $line ) {
				$summary[ $this->categorize( $line ) ][] = $line;
			}
		}

		return $summary;
	}
}
