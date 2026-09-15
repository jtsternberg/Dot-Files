<?php
namespace JT;

use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Helpers;
use JT\Helpers\Ollama;

#[Program(
	name: 'claude-update',
	description: 'Run `claude update`, then summarize what changed since your previous version.',
)]
final class ClaudeUpdateCommand {

	/** Canonical, always-current source — the same file `claude update` itself mirrors locally. */
	const CHANGELOG_URL = 'https://raw.githubusercontent.com/anthropics/claude-code/refs/heads/main/CHANGELOG.md';

	/** Offline fallback: Claude Code's own local mirror of the file above. */
	const LOCAL_CACHE_PATH = '.claude/cache/changelog.md';

	/** Above this many total changes, hand the list to the local model instead of dumping every bullet. */
	const SUMMARIZE_OVER_DEFAULT = 40;

	const SUMMARY_SYSTEM_PROMPT = 'You are a terse release-notes summarizer. Given a Claude Code '
		. "changelog covering one or more versions, write a short summary a developer can skim in a "
		. "few seconds. Group related changes, lead with what's most user-facing or impactful, and "
		. 'skip minor internal fixes. Output ONLY the summary: plain text, bullet points (-), no '
		. 'markdown headers, no preamble, no closing remarks.';

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
		private ?Ollama $ollama = null,
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
		bool $full = false,
		#[Option(
			description: 'Ollama model to summarize a large changelog with (default: auto-detected, same as auto-commit-ollama).',
		)]
		?string $model = null,
		#[Option(
			name: 'summarize-over',
			description: 'Ask the local model to summarize instead of listing bullets when there are more than this many changes.',
			valueName: 'n',
		)]
		int $summarizeOver = self::SUMMARIZE_OVER_DEFAULT
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

		$summary = $this->changelog()->summarize( $sections );
		$this->printHeader( $before, $after, $summary['versionCount'] );

		$ai = ! $full && $this->totalChanges( $summary ) > $summarizeOver
			? $this->summarizeViaOllama( $before, $after, $summary, $model )
			: null;

		if ( null !== $ai ) {
			$this->cli->msg( "\n" . $ai );

			return 0;
		}

		$this->printBuckets( $summary, $full );

		return 0;
	}

	/** @param array{features:string[], bugfixes:string[], other:string[], versionCount:int} $summary */
	private function totalChanges( array $summary ): int {
		return count( $summary['features'] ) + count( $summary['bugfixes'] ) + count( $summary['other'] );
	}

	/**
	 * @param array{features:string[], bugfixes:string[], other:string[], versionCount:int} $summary
	 */
	private function summarizeViaOllama(
		?string $before,
		string $after,
		array $summary,
		?string $modelOverride
	): ?string {
		$model = $modelOverride ?: $this->ollama()->resolveModel(
			$this->ollama()->config(),
			Ollama::DEFAULT_MODEL,
			getenv( 'HOME' ) ?: ''
		);

		$userPrompt = sprintf(
			"Changes from %s to %s (%d versions):\n\nFeatures:\n%s\n\nBugfixes:\n%s\n\nOther changes:\n%s",
			$before ?? '?',
			$after,
			$summary['versionCount'],
			$this->bulletList( $summary['features'] ),
			$this->bulletList( $summary['bugfixes'] ),
			$this->bulletList( $summary['other'] )
		);

		$result = $this->ollama()->chat( $model, self::SUMMARY_SYSTEM_PROMPT, $userPrompt );

		if ( null === $result['content'] ) {
			$this->cli->msg( sprintf(
				"\n(couldn't summarize via local model %s: %s — showing counts instead)",
				$model,
				$result['error'] ?? 'unknown error'
			), 'yellow' );

			return null;
		}

		return trim( $result['content'] );
	}

	/** @param string[] $lines */
	private function bulletList( array $lines ): string {
		return empty( $lines ) ? '(none)' : implode( "\n", array_map(
			static fn( string $line ): string => '- ' . $line,
			$lines
		) );
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

	private function printHeader( ?string $before, string $after, int $versionCount ): void {
		$green = $this->cli->color( 'green' );
		$reset = $this->cli->color( 'none' );

		$this->cli->msg( sprintf(
			"\n%sUpdated %s → %s (%d version%s)%s",
			$green,
			$before ?? '?',
			$after,
			$versionCount,
			1 === $versionCount ? '' : 's',
			$reset
		) );
	}

	/**
	 * @param array{features:string[], bugfixes:string[], other:string[], versionCount:int} $summary
	 */
	private function printBuckets( array $summary, bool $full ): void {
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

	private function ollama(): Ollama {
		if ( null === $this->ollama ) {
			$this->ollama = new Ollama();
		}

		return $this->ollama;
	}
}
