<?php

namespace JT;

/**
 * Measures what is sitting in /tmp, per top-level entry.
 *
 * Measurement only — nothing here deletes, truncates, or moves a byte. What is
 * safe to remove from /tmp is a judgement call (a live IPC socket and a parked
 * backup dir look identical to `du`), so remediation belongs to the triage skill
 * and a human, and this class exists purely to notice and report.
 *
 * Two details the shell script this replaces got wrong:
 *
 * 1. On macOS /tmp is a symlink to /private/tmp, so the default root is the
 *    resolved one — otherwise per-entry paths straddle the link and `du` on the
 *    glob only worked by accident.
 * 2. It globbed `/tmp/*`, which the shell expands without dot-entries, so a
 *    hidden multi-gig hog counted as nothing. Entries come from scandir here,
 *    hidden ones included.
 *
 * One scan per instance: the readout, the log line, and the alert must all
 * describe the same snapshot, and re-walking a /tmp with thousands of entries
 * three times is the slowest thing the watchdog could do on a 4-minute timer.
 */
final class TmpUsage {

	/** macOS's real /tmp. Overridden in tests and on Linux callers. */
	const DEFAULT_ROOT = '/private/tmp';

	/** How many paths to hand a single `du` invocation. */
	const DU_BATCH = 200;

	private string $root;

	/** @var array{totalKb:int,items:array<string,int>}|null Memoized scan. */
	private ?array $scanned = null;

	public function __construct( string $root = self::DEFAULT_ROOT ) {
		$this->root = $root;
	}

	/** The directory being measured. */
	public function root(): string {
		return $this->root;
	}

	/**
	 * Size of every top-level entry, in KB, largest first.
	 *
	 * Files and directories both count, hidden entries included. An unreadable or
	 * missing root is not an error: it reads as empty, quietly.
	 *
	 * @return array{totalKb:int,items:array<string,int>} items keyed by basename.
	 */
	public function scan(): array {
		if ( null !== $this->scanned ) {
			return $this->scanned;
		}

		$this->scanned = [ 'totalKb' => 0, 'items' => [] ];

		if ( ! is_dir( $this->root ) ) {
			return $this->scanned;
		}

		$entries = @scandir( $this->root );
		if ( false === $entries ) {
			return $this->scanned;
		}

		$paths = [];
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$paths[ $entry ] = $this->root . '/' . $entry;
		}

		$items = [];
		foreach ( array_chunk( $paths, self::DU_BATCH ) as $batch ) {
			$args = implode( ' ', array_map( 'escapeshellarg', $batch ) );
			// -s per argument, -k in KB. stderr is dropped: entries vanish mid-scan
			// (that is what /tmp is for) and permission-denied subtrees are normal.
			$raw = (string) @shell_exec( 'du -sk ' . $args . ' 2>/dev/null' );

			foreach ( preg_split( '/\R/', $raw ) as $line ) {
				if ( '' === trim( $line ) || ! str_contains( $line, "\t" ) ) {
					continue;
				}
				[ $kb, $path ] = explode( "\t", $line, 2 );
				if ( ! ctype_digit( trim( $kb ) ) ) {
					continue;
				}
				$items[ basename( $path ) ] = (int) trim( $kb );
			}
		}

		arsort( $items );

		$this->scanned = [ 'totalKb' => array_sum( $items ), 'items' => $items ];

		return $this->scanned;
	}

	/** Whole megabytes across the whole root. */
	public function totalMb(): int {
		return intdiv( $this->scan()['totalKb'], 1024 );
	}

	/**
	 * Every top-level entry in whole megabytes, largest first.
	 *
	 * @return array<string,int> basename => MB
	 */
	public function itemsMb(): array {
		return array_map( fn( $kb ) => intdiv( $kb, 1024 ), $this->scan()['items'] );
	}

	/**
	 * Entries at or above a size, largest first.
	 *
	 * @param int $itemMb Threshold in megabytes.
	 * @return array<string,int> basename => MB
	 */
	public function offenders( int $itemMb ): array {
		return array_filter( $this->itemsMb(), fn( $mb ) => $mb >= $itemMb );
	}
}
