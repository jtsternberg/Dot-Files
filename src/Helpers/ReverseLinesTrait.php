<?php
namespace JT\Helpers;

/**
 * The one implementation of "read this file's lines from the end".
 *
 * Shared by JT\Helpers\Cmux and JT\Helpers\CodexRollout rather than living in either, for
 * the same reason TitleGlyphTrait is a trait: CodexRollout is deliberately dependency-free
 * (it takes no $cli and does no shelling, so a store-only reader can use it), so it cannot
 * reach a Cmux instance to borrow this — and a second copy of a chunked backward reader is
 * exactly the kind of subtle duplicate where only one copy gets the boundary case fixed.
 */
trait ReverseLinesTrait {

	/**
	 * Invoke $fn($line) on each line of $path from LAST to FIRST, reading fixed
	 * chunks backward from EOF so the whole file is never held in memory. A line
	 * straddling a chunk boundary is carried into the next (earlier) chunk before
	 * it's emitted. $fn returning false stops the scan early. Blank lines are
	 * skipped. Lets tail-only scans avoid touching the head of a large transcript.
	 */
	public function eachLineReverse(string $path, callable $fn): void {
		$h = @fopen($path, 'rb');
		if (!$h) { return; }
		$chunkSize = 65536;
		$stat = fstat($h);
		$pos  = $stat ? (int) $stat['size'] : 0;
		$carry = ''; // fragment that belongs AFTER the current chunk (start of a later line)
		while ($pos > 0) {
			$read = (int) min($chunkSize, $pos);
			$pos -= $read;
			fseek($h, $pos);
			$buf   = (string) fread($h, $read) . $carry;
			$lines = explode("\n", $buf);
			// Unless we've reached the file start, the first fragment continues into
			// the earlier chunk — hold it back rather than emit a partial line.
			$carry = $pos > 0 ? array_shift($lines) : '';
			for ($i = count($lines) - 1; $i >= 0; $i--) {
				if ($lines[$i] === '') { continue; }
				if ($fn($lines[$i]) === false) { fclose($h); return; }
			}
		}
		fclose($h);
	}
}
