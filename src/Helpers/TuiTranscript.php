<?php
namespace JT\Helpers;

/**
 * PURE. Present a markdown transcript archive the way Claude Code's own /export presented
 * one: glyph-prefixed turns, hanging indents, hard wrap, no markdown syntax.
 *
 * Why this exists (dotfiles-0p4). Since dotfiles-36a the bury path archives markdown
 * SOURCE, which is right for the file — `graveyard show` gets a real preview in an editor,
 * and the corpus stays greppable. It is wrong for the page modal, which drops the text into
 * a `<pre>`: literal `**You:**` markers, and because nothing hard-wraps, `white-space:
 * pre-wrap` soft-wraps every paragraph flush to the container with no hanging indent. The
 * result is a wall of text where the old /export archives were genuinely scannable.
 *
 * The output is plain TEXT, deliberately. The modal keeps assigning `pre.textContent`, so
 * there is no innerHTML, no injection surface, and no change to its contentEditable
 * behavior — the reason a markdown-to-HTML pass was rejected.
 *
 * Nothing on disk changes. Legacy archives (the 61 written by /export before the seam)
 * are already in this shape and pass through untouched — detection is by CONTENT, not
 * extension, so the two markdown-in-a-.txt archives in the live store also render.
 */
class TuiTranscript {

	/**
	 * Speaker labels an archive may use, as a regex alternation.
	 *
	 * FOUR places have to agree on this — detection, turn-open, turn-end, and the header
	 * scan's stop condition — and the fourth is the dangerous one: renderHeader() consumes
	 * every line it walks and only stops at a marker it recognizes, so a transcript whose
	 * speaker is missing from this list is consumed WHOLE and renders as a bare banner.
	 * A codex archive that opens with an agent message (imported sessions do) has no user
	 * turn to stop the scan. One const, so adding a speaker cannot half-land.
	 */
	const SPEAKERS = 'You|Claude|Codex';

	/** Turn glyphs, matching what /export printed. */
	const USER_GLYPH = '❯';
	const ASST_GLYPH = '⏺';
	const RESULT_GLYPH = '⎿';

	/** Continuation indent under a turn glyph, and under a tool-result glyph. */
	const TURN_INDENT   = '  ';
	const RESULT_INDENT = '     ';

	/**
	 * Wrap column, measured off the real thing rather than guessed: across the legacy
	 * /export archives in the store, the 95th-percentile line length is 79-80 in every
	 * single one. It also happens to fit the page modal's `<pre>`, which is the point —
	 * wrap wider than the box and the browser soft-wraps the overflow with no hanging
	 * indent, orphaning words at the left margin.
	 */
	const DEFAULT_WIDTH = 80;

	/**
	 * Render markdown archive text as /export-style TUI text. Returns $text unchanged when
	 * it is not one of our markdown archives.
	 */
	public function fromMarkdown(string $text, int $width = self::DEFAULT_WIDTH): string {
		if (!$this->looksLikeMarkdownArchive($text)) { return $text; }

		$lines = explode("\n", str_replace("\r\n", "\n", $text));
		$out   = [];
		$this->renderHeader($lines, $out);

		$i = 0;
		$n = count($lines);
		while ($i < $n) {
			$line = $lines[$i];

			// A turn: "**You:** …" / "**Claude:** …" / "**Codex:** …" plus every line up to
			// the next turn.
			if (preg_match('/^\*\*(' . self::SPEAKERS . '):\*\*\s?(.*)$/u', $line, $m)) {
				$glyph = $m[1] === 'You' ? self::USER_GLYPH : self::ASST_GLYPH;
				$body  = [$m[2]];
				$i++;
				while ($i < $n && !$this->startsTurn($lines[$i])) { $body[] = $lines[$i]; $i++; }
				$this->renderTurn($glyph, $body, $width, $out);
				continue;
			}

			// Compaction boundary: "---" / "### ⟲ Context compacted" / summary paragraphs.
			if (preg_match('/^#{1,6}\s*(.*)$/u', $line, $m)) {
				$out[] = '';
				$out[] = trim($this->demark($m[1]));
				$i++;
				continue;
			}
			if (trim($line) === '---') { $i++; continue; }

			// Stray prose between turns (a compaction summary body).
			if (trim($line) !== '') {
				foreach ($this->wrap($this->demark($line), $width, '') as $w) { $out[] = $w; }
			} elseif (end($out) !== '') {
				$out[] = '';
			}
			$i++;
		}

		return rtrim(implode("\n", $out), "\n") . "\n";
	}

	/**
	 * Our archives always carry turn markers, and (unless the session had none) a
	 * "- session `<id>`" header line. Anything else is left alone.
	 */
	public function looksLikeMarkdownArchive(string $text): bool {
		return (bool) preg_match('/^\*\*(' . self::SPEAKERS . '):\*\*/mu', $text)
			|| (bool) preg_match('/^- session `/mu', $text);
	}

	/**
	 * Structural markers only count at COLUMN 0. Indentation is what separates a compaction
	 * rule from `echo "---"` output, and a real heading from `cat notes.md` output — an
	 * indented "---" ending the turn cut it in half and pushed the remainder through the
	 * stray-prose path, unindented and with literal ↳ markers.
	 */
	private function startsTurn(string $line): bool {
		return (bool) preg_match('/^\*\*(' . self::SPEAKERS . '):\*\*/u', $line)
			|| $line === '---'
			|| (bool) preg_match('/^#{1,6}\s/u', $line);
	}

	/**
	 * The "# title" + "- session/cwd/branch/dates" preamble becomes a compact banner. The
	 * session id is dropped (the modal already shows it) and the ISO timestamps collapse to
	 * dates + times, which is what a reader actually wants at a glance.
	 */
	private function renderHeader(array &$lines, array &$out): void {
		$consumed = 0;
		$title = $where = $when = '';
		foreach ($lines as $idx => $line) {
			if (preg_match('/^\*\*(' . self::SPEAKERS . '):\*\*/u', $line)) { break; }
			$consumed = $idx + 1;
			if (preg_match('/^#\s+(.*)$/u', $line, $m)) { $title = trim($this->demark($m[1])); continue; }
			if (preg_match('/^- session\s/u', $line)) { continue; }
			if (preg_match('/^- cwd\s+(.*)$/u', $line, $m)) { $where = trim($this->demark($m[1])); continue; }
			if (preg_match('/^- (\S+)\s*→\s*(\S+)$/u', $line, $m)) {
				$when = $this->humanStamp($m[1]) . ' → ' . $this->humanStamp($m[2]);
				continue;
			}
		}
		if ($title === '' && $where === '' && $when === '') { return; }

		foreach ([$title, $where, $when] as $part) {
			if (trim((string) $part) !== '') { $out[] = trim((string) $part); }
		}
		$out[] = '';
		$lines = array_slice($lines, $consumed);
	}

	/** "2026-07-23T01:01:37.891Z" → "2026-07-23 01:01". Anything unparseable passes through. */
	private function humanStamp(string $stamp): string {
		if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $stamp, $m)) { return $m[1] . ' ' . $m[2]; }
		return $stamp;
	}

	/**
	 * One turn: the prose wraps under its glyph; a "↳ `Tool: args`" label becomes an
	 * /export-style "⏺ Tool(args)" call line; the indented output beneath it becomes a
	 * "⎿" result block. Fenced code is emitted verbatim — it is pasted terminal output,
	 * and wrapping or de-marking it destroys the alignment that makes it readable.
	 */
	private function renderTurn(string $glyph, array $body, int $width, array &$out): void {
		if ($out && end($out) !== '') { $out[] = ''; }

		$first    = true;
		$fenced   = false;
		$inResult = false; // explicit state: indent-sniffing cannot tell a wrapped call line
		$pending  = [];    // (6 spaces) from a result continuation (5) — it "starts with" it

		$flush = function () use (&$pending, &$first, $glyph, $width, &$out): void {
			if (!$pending) { return; }
			$text = trim(implode(' ', $pending));
			$pending = [];
			if ($text === '') { return; }
			$prefix = $first ? $glyph . ' ' : self::TURN_INDENT;
			foreach ($this->wrap($this->demark($text), $width, self::TURN_INDENT, $prefix) as $ln) { $out[] = $ln; }
			$first = false;
		};

		foreach ($body as $line) {
			// Fenced block: dump it as-is, indented under the turn.
			if (preg_match('/^\s*```/u', $line)) {
				$flush();
				$fenced = !$fenced;
				continue;
			}
			if ($fenced) {
				if ($first) { $out[] = rtrim($glyph); $first = false; }
				$out[] = self::TURN_INDENT . $line;
				continue;
			}

			// Tool label line → one call line per tool.
			if (preg_match('/^\s*↳\s*(.*)$/u', $line, $m)) {
				$flush();
				foreach ($this->toolCalls($m[1]) as $call) {
					foreach ($this->wrap($call, $width, '      ', self::ASST_GLYPH . ' ') as $ln) { $out[] = $ln; }
				}
				$first    = false;
				$inResult = false; // the next indented line opens this call's result block
				continue;
			}

			// Tool output: indented 4+ spaces under a label. Never RE-FLOWED — command output
			// is structured (tables, diffs, listings) and joining lines destroys the
			// alignment. Overlong single lines are still SPLIT, because the alternative is
			// the modal soft-wrapping them flush to its edge with no hanging indent.
			if (preg_match('/^ {4,}(\S.*)$/u', $line, $m)) {
				$flush();
				$prefix = $inResult
					? self::RESULT_INDENT
					: self::TURN_INDENT . self::RESULT_GLYPH . '  ';
				foreach ($this->splitLong($this->demarkElision($m[1]), $width, self::RESULT_INDENT, $prefix) as $ln) {
					$out[] = $ln;
				}
				$first    = false;
				$inResult = true;
				continue;
			}
			$inResult = false;

			// A list item or a blank line ends the current paragraph.
			if (preg_match('/^\s*[-*+]\s/u', $line)) {
				$flush();
				$item = preg_replace('/^\s*[-*+]\s+/u', '- ', $line);
				foreach ($this->wrap($this->demark($item), $width, self::TURN_INDENT . '  ', $first ? $glyph . ' ' : self::TURN_INDENT) as $ln) {
					$out[] = $ln;
				}
				$first = false;
				continue;
			}
			if (trim($line) === '') {
				$flush();
				if (end($out) !== '') { $out[] = ''; }
				continue;
			}

			$pending[] = trim($line);
		}
		$flush();
	}

	/**
	 * "`Bash: git status`, `Read: /tmp/x`" → ["Bash(git status)", "Read(/tmp/x)"]. Handles
	 * both the current one-label-per-line form and the older comma-joined form.
	 */
	private function toolCalls(string $labels): array {
		$calls = [];
		if (preg_match_all('/`([^`]+)`/u', $labels, $m)) {
			foreach ($m[1] as $label) {
				$label = trim($label);
				if ($label === '') { continue; }
				$calls[] = preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/u', $label, $p) && $p[2] !== ''
					? $p[1] . '(' . $p[2] . ')'
					: $label;
			}
		}
		// "+3 more" style tails carry no backticks.
		if (preg_match('/\+(\d+)\s+more/u', $labels, $m)) { $calls[] = '…and ' . $m[1] . ' more tool call(s)'; }
		return $calls;
	}

	/** Strip inline markdown emphasis/code markers. Mirrors GATE 2's compare-time demark. */
	private function demark(string $s): string {
		return $this->demarkElision(preg_replace('/(\*\*|__|`|(?<![A-Za-z0-9])_|_(?![A-Za-z0-9]))/u', '', $s));
	}

	/** "… _(1234 chars elided)_" keeps its meaning but loses the markdown. */
	private function demarkElision(string $s): string {
		return preg_replace('/_\((\d+ chars elided)\)_/u', '($1)', $s);
	}

	/**
	 * Split an already-formatted line that is too long, WITHOUT re-flowing it.
	 *
	 * Unlike wrap(), runs of spaces are preserved verbatim — this is command output, where
	 * consecutive spaces are column alignment, not slack. A line that fits is returned
	 * untouched; a longer one breaks at the last space before the limit (hard-cut if there
	 * is none, e.g. a 900-char URL or base64 blob).
	 *
	 * @return list<string>
	 */
	private function splitLong(string $text, int $width, string $indent, string $prefix): array {
		$lines = [];
		$open  = $prefix;
		while (true) {
			$room = max(8, $width - mb_strlen($open));
			if (mb_strlen($text) <= $room) {
				$lines[] = $open . $text;
				return $lines;
			}
			$cut   = $room;
			$space = mb_strrpos(mb_substr($text, 0, $room + 1), ' ');
			if ($space !== false && $space >= (int) ($room / 4)) { $cut = $space; }
			$lines[] = $open . mb_substr($text, 0, $cut);
			$text    = ltrim(mb_substr($text, $cut), ' ');
			$open    = $indent;
			if ($text === '') { return $lines; }
		}
	}

	/**
	 * Character-aware word wrap. $prefix opens the first line (the glyph), $indent opens
	 * every continuation line; both count against $width. A word longer than the available
	 * room is left whole — the modal soft-wraps it rather than us breaking a path or URL.
	 *
	 * @return list<string>
	 */
	private function wrap(string $text, int $width, string $indent, ?string $prefix = null): array {
		$prefix = $prefix ?? $indent;
		$text   = trim(preg_replace('/[ \t]+/u', ' ', $text));
		if ($text === '') { return [rtrim($prefix)]; }

		$lines = [];
		$cur   = $prefix;
		$room  = max(8, $width - mb_strlen($prefix));
		$used  = 0;
		foreach (explode(' ', $text) as $word) {
			$wl = mb_strlen($word);
			if ($used > 0 && $used + 1 + $wl > $room) {
				$lines[] = $cur;
				$cur     = $indent;
				$room    = max(8, $width - mb_strlen($indent));
				$used    = 0;
			}
			$cur  .= ($used > 0 ? ' ' : '') . $word;
			$used += ($used > 0 ? 1 : 0) + $wl;
		}
		$lines[] = $cur;
		return $lines;
	}
}
