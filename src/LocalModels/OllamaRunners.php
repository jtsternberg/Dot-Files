<?php

namespace JT\LocalModels;

/**
 * What Ollama currently holds loaded, read from `ollama ps`.
 *
 * The single reader of that output. Two unrelated callers depend on it — the
 * store ejector needs model names to unload before a drive can leave, and the
 * system watchdog needs sizes and the GPU split to tell a resident model apart
 * from a kernel memory leak — and they read through here rather than each
 * parsing `ollama ps` for itself, so a column-shape fix lands for both at once.
 *
 * Parsing is regex-anchored on purpose. `ollama ps` is space-padded columns
 * where `SIZE` is two tokens ("2.1 GB"), `PROCESSOR` is one or two ("100% GPU",
 * "58%/42% CPU/GPU"), `UNTIL` is a sentence, and `CONTEXT` only exists on newer
 * builds — so splitting on whitespace and counting columns misreads every one
 * of those.
 */
final class OllamaRunners {

	/** @var array<int, array{name:string,id:string,sizeGb:float,gpuPct:int}>|null Memoized. */
	private ?array $rows = null;

	/**
	 * Everything loaded right now, largest first in Ollama's own order.
	 *
	 * No Ollama installed, none running, or nothing loaded all read the same way:
	 * an empty list, no error. The watchdog calls this on a 4-minute timer where
	 * a missing binary is a normal state, not a fault.
	 *
	 * @return array<int, array{name:string,id:string,sizeGb:float,gpuPct:int}>
	 */
	public function resident(): array {
		if ( null !== $this->rows ) {
			return $this->rows;
		}

		$bin = getenv( 'AIMODELS_OLLAMA_BIN' ) ?: 'ollama';
		exec( escapeshellarg( $bin ) . ' ps 2>/dev/null', $lines, $code );

		$this->rows = 0 === $code ? self::parse( implode( "\n", $lines ) ) : [];

		return $this->rows;
	}

	/** @return string[] model:tag pairs, for callers that only need to name them. */
	public function names(): array {
		return array_column( $this->resident(), 'name' );
	}

	/**
	 * Total GB of resident weights actually wired onto the GPU.
	 *
	 * The GPU share only — a model Ollama split across CPU and GPU wires just its
	 * GPU half into the kernel's GPU zones, and counting the whole thing would
	 * over-explain those zones and mask a real leak.
	 */
	public function gpuResidentGb(): float {
		$total = 0.0;
		foreach ( $this->resident() as $row ) {
			$total += $row['sizeGb'] * $row['gpuPct'] / 100.0;
		}

		return round( $total, 2 );
	}

	/**
	 * @return array<int, array{name:string,id:string,sizeGb:float,gpuPct:int}>
	 */
	public static function parse( string $raw ): array {
		$rows = [];

		foreach ( preg_split( '/\R/', $raw ) ?: [] as $line ) {
			$matched = preg_match(
				'/^(\S+)\s+([0-9a-f]{6,})\s+([\d.]+)\s*([KMGT]?B)\s+'
					. '(\d+)%(?:\/(\d+)%)?\s+(CPU|GPU)(?:\/(CPU|GPU))?/i',
				trim( $line ),
				$m
			);

			if ( ! $matched ) {
				continue; // Header, blank line, or a shape we do not understand.
			}

			$rows[] = [
				'name'   => $m[1],
				'id'     => $m[2],
				'sizeGb' => round( self::toGb( (float) $m[3], $m[4] ), 2 ),
				'gpuPct' => self::gpuPct( $m ),
			];
		}

		return $rows;
	}

	/**
	 * The GPU percentage out of a matched PROCESSOR column.
	 *
	 * A split processor prints as "58%/42% CPU/GPU" — percentages and labels in
	 * the same order, CPU first — so the GPU share is whichever number sits
	 * opposite the GPU label, never simply the first one.
	 *
	 * @param array<int,string> $m
	 */
	private static function gpuPct( array $m ): int {
		$pairs = [ [ (int) $m[5], strtoupper( $m[7] ) ] ];
		if ( '' !== ( $m[6] ?? '' ) && '' !== ( $m[8] ?? '' ) ) {
			$pairs[] = [ (int) $m[6], strtoupper( $m[8] ) ];
		}

		foreach ( $pairs as [ $pct, $label ] ) {
			if ( 'GPU' === $label ) {
				return $pct;
			}
		}

		return 0;
	}

	private static function toGb( float $n, string $unit ): float {
		switch ( strtoupper( $unit ) ) {
			case 'TB': return $n * 1024.0;
			case 'GB': return $n;
			case 'MB': return $n / 1024.0;
			case 'KB': return $n / 1024.0 / 1024.0;
			default:   return $n / 1024.0 / 1024.0 / 1024.0;
		}
	}
}
