<?php
namespace JT;

use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;

#[Program(
	name: 'claude-update',
	description: 'Run `claude update`, then summarize what changed since your previous version.',
)]
final class ClaudeUpdateCommand {

	/** Canonical, always-current source — the same file `claude update` itself mirrors locally. */
	const CHANGELOG_URL = 'https://raw.githubusercontent.com/anthropics/claude-code/refs/heads/main/CHANGELOG.md';

	/** Offline fallback: Claude Code's own local mirror of the file above. */
	const LOCAL_CACHE_PATH = '.claude/cache/changelog.md';

	/** @var callable(string):int */
	private $runner;

	/** @var callable():?string */
	private $installedVersion;

	/** @var callable():?string */
	private $changelogText;

	/** @var callable():bool */
	private $commandExists;

	public function __construct(
		private readonly Helpers $cli,
		private ?ClaudeChangelog $changelog = null,
		?callable $runner = null,
		?callable $installedVersion = null,
		?callable $changelogText = null,
		?callable $commandExists = null,
	) {
		$this->runner = $runner ?: static function ( string $command ): int {
			passthru( $command, $code );

			return $code;
		};
		$this->installedVersion = $installedVersion ?: static function (): ?string {
			return self::extractVersion( (string) shell_exec( 'claude --version 2>/dev/null' ) );
		};
		$this->changelogText = $changelogText ?: function (): ?string {
			return $this->fetchChangelogText();
		};
		$this->commandExists = $commandExists ?: static function (): bool {
			return '' !== trim( (string) shell_exec( 'command -v claude 2>/dev/null' ) );
		};
	}

	#[Command(
		description: 'Run `claude update` and list what changed since your previous version.',
		default: true,
	)]
	public function run(
		#[Option( description: 'List every fix and other change too, not just new features.' )]
		bool $full = false
	): int {
		if ( ! ( $this->commandExists )() ) {
			$this->cli->err( 'claude not found on PATH.' );

			return 1;
		}

		$before = ( $this->installedVersion )();
		$code   = ( $this->runner )( 'claude update' );
		if ( 0 !== $code ) {
			return $code;
		}

		$after = ( $this->installedVersion )();
		if ( null === $after ) {
			$this->cli->err( "\nUpdated, but couldn't read the installed version to summarize changes." );

			return 0;
		}

		if ( $before === $after ) {
			$this->cli->msg(
				sprintf( "\nAlready on the latest (%s) — nothing to summarize.", $after ),
				'green'
			);

			return 0;
		}

		$changelog = ( $this->changelogText )();
		if ( null === $changelog ) {
			$this->cli->msg( sprintf(
				"\nUpdated %s → %s, but couldn't fetch a changelog to summarize from.\n"
				. 'See https://code.claude.com/docs/en/changelog for details.',
				$before ?? '?',
				$after
			), 'yellow' );

			return 0;
		}

		$sections = $this->changelog()->parseSections( $changelog );
		if ( null !== $before ) {
			$sections = $this->changelog()->sectionsSince( $sections, $before );
		}

		$this->printSummary( $before, $after, $this->changelog()->summarize( $sections ), $full );

		return 0;
	}

	/**
	 * Fetch the canonical changelog from GitHub; fall back to Claude Code's own
	 * local mirror when offline or the fetch otherwise fails.
	 */
	private function fetchChangelogText(): ?string {
		$remote = shell_exec(
			'curl -fsSL --max-time 5 ' . escapeshellarg( self::CHANGELOG_URL ) . ' 2>/dev/null'
		);
		if ( is_string( $remote ) && '' !== trim( $remote ) ) {
			return $remote;
		}

		$local = ( getenv( 'HOME' ) ?: '' ) . '/' . self::LOCAL_CACHE_PATH;

		return is_readable( $local ) ? (string) file_get_contents( $local ) : null;
	}

	/**
	 * @param array{features:string[], bugfixes:string[], other:string[], versionCount:int} $summary
	 */
	private function printSummary( ?string $before, string $after, array $summary, bool $full ): void {
		$green = $this->cli->color( 'green' );
		$reset = $this->cli->color( 'none' );

		$this->cli->msg( sprintf(
			"\n%sUpdated %s → %s (%d version%s)%s",
			$green,
			$before ?? '?',
			$after,
			$summary['versionCount'],
			1 === $summary['versionCount'] ? '' : 's',
			$reset
		) );

		$this->printBucket( '✨ Features', $summary['features'], true );
		$this->printBucket( '🐛 Bugfixes', $summary['bugfixes'], $full );
		$this->printBucket( '🔧 Other changes', $summary['other'], $full );

		if ( ! $full && ( ! empty( $summary['bugfixes'] ) || ! empty( $summary['other'] ) ) ) {
			$this->cli->msg( "\n(--full to also list bugfixes and other changes)", 'dark_gray' );
		}
	}

	/** @param string[] $lines */
	private function printBucket( string $label, array $lines, bool $listed ): void {
		if ( empty( $lines ) ) {
			return;
		}

		$this->cli->msg( sprintf( "\n%s (%d)%s", $label, count( $lines ), $listed ? ':' : '' ) );
		if ( ! $listed ) {
			return;
		}

		foreach ( $lines as $line ) {
			$this->cli->msg( '  - ' . $line );
		}
	}

	private static function extractVersion( string $output ): ?string {
		return preg_match( '/(\d+\.\d+\.\d+)/', $output, $m ) ? $m[1] : null;
	}

	private function changelog(): ClaudeChangelog {
		if ( null === $this->changelog ) {
			$this->changelog = new ClaudeChangelog();
		}

		return $this->changelog;
	}
}
