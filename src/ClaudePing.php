<?php
namespace JT;

use JT\CLI\Helpers;
use Lorisleiva\CronTranslator\CronParsingException;
use Lorisleiva\CronTranslator\CronTranslator;

/**
 * ClaudePing — installs a crontab entry that makes a cheap headless `claude -p`
 * call at a given schedule, purely to land an API request under the account
 * at that wall-clock time (e.g. to start a usage-window clock early).
 *
 * The schedule is accepted as free text (or a raw cron expression) and turned
 * into a 5-field cron expression via a cheap headless Claude call of its own
 * (same minimal-cost flags as the ping itself) rather than a hand-rolled NL
 * parser. Installing is idempotent: exactly one managed line is kept in the
 * user's crontab, identified by MANAGED_TAG, and reinstalling replaces it
 * rather than piling up duplicates.
 *
 * crontab and claude are both shelled out to via injectable binaries
 * (CLAUDEPING_CRONTAB_BIN, CLAUDEPING_CLAUDE_BIN) so tests can stub both —
 * mirroring Godo::resolvePath()'s GODO_DIRMAP_BIN seam.
 */
class ClaudePing {

	/** Marks a crontab line as owned by this tool, so reinstall/remove can find it. */
	const MANAGED_TAG = '# claudeping-install managed';

	protected Helpers $cli;

	public function __construct( Helpers $cli ) {
		$this->cli = $cli;
	}

	/**
	 * Ask a cheap headless Claude call to turn free text (or an already-valid
	 * cron expression) into a 5-field cron expression. Returns null when the
	 * text wasn't a clear schedule, the call failed, or the reply wasn't a
	 * valid cron expression.
	 */
	public function parseSchedule( string $text ): ?string {
		$prompt = sprintf(
			'If clear, give me a cron expression (5 space-separated fields: '
			. 'minute hour day-of-month month day-of-week — nothing else, no '
			. 'explanation) for this schedule request, else reply with exactly '
			. '"no": %s',
			$text
		);

		$cmd = escapeshellcmd( $this->claudeBin() )
			. ' -p ' . escapeshellarg( $prompt )
			. ' --model haiku --safe-mode --tools "" --no-session-persistence'
			. ' --dangerously-skip-permissions --output-format json --max-budget-usd 0.02';

		$result = $this->cli->getCommandOutputAndExitCode( $cmd );
		if ( 0 !== $result['exitCode'] ) {
			return null;
		}

		$decoded = json_decode( $result['output'], true );
		$reply   = trim( (string) ( $decoded['result'] ?? '' ) );

		if ( '' === $reply || 0 === strcasecmp( $reply, 'no' ) ) {
			return null;
		}

		return $this->isValidCronExpression( $reply ) ? $reply : null;
	}

	/**
	 * A plain-English description of a cron expression, via lorisleiva/cron-translator
	 * — not another LLM pass, so a schedule can be checked without trusting a model
	 * to have translated it correctly. Falls back to the raw expression if the
	 * library can't parse it.
	 */
	public function describeCronExpression( string $expression ): string {
		try {
			return CronTranslator::translate( $expression );
		} catch ( CronParsingException $e ) {
			return $expression;
		}
	}

	/** The cron expression's 5 leading fields from a full managed crontab line. */
	public function cronFieldsFromLine( string $line ): string {
		$parts = preg_split( '/\s+/', trim( $line ) );

		return implode( ' ', array_slice( (array) $parts, 0, 5 ) );
	}

	/** Install (or replace) the managed cron entry at the given cron expression. */
	public function install( string $cronExpression ): void {
		$lines   = $this->unmanagedLines();
		$lines[] = $this->buildCronLine( $cronExpression );
		$this->writeCrontab( $lines );
	}

	/** Remove the managed cron entry, if any. Returns whether one existed. */
	public function remove(): bool {
		$before = $this->managedLine();
		$this->writeCrontab( $this->unmanagedLines() );

		return null !== $before;
	}

	/** The currently-installed managed crontab line, or null if none. */
	public function managedLine(): ?string {
		foreach ( $this->crontabLines() as $line ) {
			if ( false !== strpos( $line, self::MANAGED_TAG ) ) {
				return $line;
			}
		}

		return null;
	}

	/** The full ping command a cron line runs: a cheap headless `claude -p "hello"`. */
	public function pingCommand(): string {
		$home = getenv( 'HOME' ) ?: '';
		$log  = getenv( 'CLAUDEPING_LOG' ) ?: ( $home . '/morning-hello.log' );

		return sprintf(
			'cd %s && %s -p "hello" --model haiku --safe-mode --tools ""'
			. ' --no-session-persistence --dangerously-skip-permissions'
			. ' --max-budget-usd 0.01 >> %s 2>&1',
			escapeshellarg( $home ),
			escapeshellcmd( $this->claudeBin() ),
			escapeshellarg( $log )
		);
	}

	private function buildCronLine( string $cronExpression ): string {
		return trim( $cronExpression ) . ' ' . $this->pingCommand() . ' ' . self::MANAGED_TAG;
	}

	private function isValidCronExpression( string $expression ): bool {
		$fields = preg_split( '/\s+/', trim( $expression ) );
		if ( false === $fields || 5 !== count( $fields ) ) {
			return false;
		}

		foreach ( $fields as $field ) {
			if ( ! preg_match( '/^[0-9*\/,\-]+$/', $field ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return string[] */
	private function crontabLines(): array {
		$bin    = escapeshellcmd( $this->crontabBin() );
		$result = $this->cli->getCommandOutputAndExitCode( $bin . ' -l 2>/dev/null' );
		if ( '' === trim( $result['output'] ) ) {
			return [];
		}

		return array_values( array_filter(
			explode( "\n", $result['output'] ),
			static fn( $line ) => '' !== trim( $line )
		) );
	}

	/** @return string[] */
	private function unmanagedLines(): array {
		return array_values( array_filter(
			$this->crontabLines(),
			static fn( $line ) => false === strpos( $line, self::MANAGED_TAG )
		) );
	}

	/** @param string[] $lines */
	private function writeCrontab( array $lines ): void {
		$bin     = escapeshellcmd( $this->crontabBin() );
		$content = empty( $lines ) ? '' : implode( "\n", $lines ) . "\n";
		$tmp     = tempnam( sys_get_temp_dir(), 'claudeping-crontab-' );
		file_put_contents( $tmp, $content );
		$this->cli->getCommandOutputAndExitCode( $bin . ' ' . escapeshellarg( $tmp ) );
		@unlink( $tmp );
	}

	private function crontabBin(): string {
		return getenv( 'CLAUDEPING_CRONTAB_BIN' ) ?: 'crontab';
	}

	private function claudeBin(): string {
		$override = getenv( 'CLAUDEPING_CLAUDE_BIN' );
		if ( $override ) {
			return $override;
		}

		$found = trim( (string) shell_exec( 'command -v claude 2>/dev/null' ) );

		return '' !== $found ? $found : 'claude';
	}
}
