<?php
namespace JT\Tests\Helpers;

use JT\Tests\TestCase;
use JT\Helpers\TuiTranscript;

/**
 * dotfiles-0p4: present a markdown archive the way Claude Code's own /export presented a
 * transcript — glyph-prefixed turns, hanging indents, hard wrap, no markdown markers.
 *
 * PRESENTATION ONLY. The archive on disk stays markdown (that is what makes `graveyard
 * show` give a real preview and keeps the corpus greppable); this is what the page modal
 * displays. Output is plain TEXT, so the modal keeps assigning pre.textContent — no
 * innerHTML, no injection surface, no change to the contentEditable behavior.
 *
 * The 61 pre-seam archives are already TUI text and must pass through untouched.
 */
final class TuiTranscriptTest extends TestCase
{
	private function render(string $md, int $width = 80): string
	{
		return (new TuiTranscript())->fromMarkdown($md, $width);
	}

	// =====================================================================
	// Format detection — legacy archives must not be touched
	// =====================================================================

	public function testLegacyTuiArchivePassesThroughUnchanged(): void
	{
		// Exactly what Claude Code's /export wrote: already glyphed, already wrapped.
		$tui = "❯ yes\n\n⏺ Removed, and the working tree is clean.\n\n✳ Baked for 15s\n";

		$this->assertSame($tui, $this->render($tui));
	}

	public function testUnrecognizedTextPassesThroughUnchanged(): void
	{
		$this->assertSame("just some notes\n", $this->render("just some notes\n"));
	}

	public function testDetectsAMarkdownArchiveByItsTurnMarkers(): void
	{
		// The two markdown-in-a-.txt archives in the live store are found this way —
		// content, not extension, decides.
		$out = $this->render("**You:** hi\n");

		$this->assertStringContainsString('❯ hi', $out);
	}

	// =====================================================================
	// Turn glyphs + hanging indent
	// =====================================================================

	public function testTurnMarkersBecomeGlyphs(): void
	{
		$out = $this->render("**You:** do the thing\n\n**Claude:** done\n");

		$this->assertStringContainsString("❯ do the thing", $out);
		$this->assertStringContainsString("⏺ done", $out);
		$this->assertStringNotContainsString('**', $out);
	}

	public function testLongProseHardWrapsWithATwoSpaceHangingIndent(): void
	{
		$body = 'alpha bravo charlie delta echo foxtrot golf hotel india juliett kilo lima mike';
		$out  = $this->render("**Claude:** {$body}\n", 40);
		$lines = explode("\n", trim($out));

		$this->assertStringStartsWith('⏺ ', $lines[0]);
		$this->assertGreaterThan(1, count($lines), 'the body must wrap at the given width');
		foreach (array_slice($lines, 1) as $ln) {
			if (trim($ln) === '') { continue; }
			$this->assertStringStartsWith('  ', $ln, 'continuation lines hang under the glyph');
			$this->assertLessThanOrEqual(40, mb_strlen($ln));
		}
		// No word was lost or split by the wrap.
		$this->assertSame($body, trim(preg_replace('/\s+/', ' ', str_replace('⏺', '', $out))));
	}

	public function testWrapCountsCharactersNotBytes(): void
	{
		// A line of multibyte em-dashes must not wrap three times too early.
		$out = $this->render("**Claude:** " . trim(str_repeat('— ', 20)) . "\n", 50);
		foreach (explode("\n", trim($out)) as $ln) {
			$this->assertLessThanOrEqual(50, mb_strlen($ln));
			$this->assertGreaterThan(25, mb_strlen($ln), 'wrapped on bytes instead of characters');
		}
	}

	public function testBlankLineSeparatesTurns(): void
	{
		$out = $this->render("**You:** one\n\n**Claude:** two\n");

		$this->assertMatchesRegularExpression('/❯ one\n\n⏺ two/', $out);
	}

	// =====================================================================
	// Inline markdown
	// =====================================================================

	public function testInlineMarkdownIsStripped(): void
	{
		$out = $this->render("**Claude:** the **bold** and `code` and _em_ bits\n");

		$this->assertStringContainsString('the bold and code and em bits', $out);
	}

	public function testBulletListsKeepTheirShape(): void
	{
		$out = $this->render("**Claude:** wrapped up:\n- **PR #2467** merged\n- deploy live\n");

		$this->assertStringContainsString('- PR #2467 merged', $out);
		$this->assertStringContainsString('- deploy live', $out);
	}

	public function testElisionMarkerLosesItsMarkdown(): void
	{
		$out = $this->render("**Claude:** big output\n  ↳ `Bash: ls`\n      first line\n      … _(1234 chars elided)_\n");

		$this->assertStringContainsString('… (1234 chars elided)', $out);
		$this->assertStringNotContainsString('_(1234', $out);
	}

	// =====================================================================
	// Tool calls and their output
	// =====================================================================

	public function testToolLabelBecomesAnExportStyleCallLine(): void
	{
		$out = $this->render("**Claude:** looking\n  ↳ `Bash: git status`\n");

		$this->assertStringContainsString('⏺ Bash(git status)', $out);
		$this->assertStringNotContainsString('↳', $out);
	}

	public function testSeveralToolsOnOneLabelLineSplitIntoSeparateCalls(): void
	{
		// The pre-archive-fidelity renderer joined labels with ", " on a single ↳ line.
		$out = $this->render("**Claude:** two things\n  ↳ `Bash: one`, `Read: /tmp/x`\n");

		$this->assertStringContainsString('⏺ Bash(one)', $out);
		$this->assertStringContainsString('⏺ Read(/tmp/x)', $out);
	}

	public function testToolOutputBecomesAResultBlock(): void
	{
		$md = "**Claude:** checking\n  ↳ `Bash: ls -la`\n      total 8\n      drwxr-xr-x  2 JT  staff\n";
		$out = $this->render($md);

		$this->assertStringContainsString('⎿', $out);
		$this->assertMatchesRegularExpression('/⎿ {2}total 8/', $out);
		// Continuation output lines align under the first, not under the glyph.
		$this->assertMatchesRegularExpression('/\n {5}drwxr-xr-x {2}2 JT {2}staff/', $out);
	}

	public function testToolOutputIsNeverReflowed(): void
	{
		// Command output is structured (tables, paths, diffs). Lines that already fit must
		// survive byte-identical — joining or re-flowing them destroys the alignment that
		// makes them readable.
		$md = "**Claude:** x\n  ↳ `Bash: ls -la`\n      -rw-r--r--  1 JT  staff   19215 file.yaml\n"
			. "      drwxr-xr-x  2 JT  staff     64 dir\n";
		$out = $this->render($md, 88);

		$this->assertStringContainsString('-rw-r--r--  1 JT  staff   19215 file.yaml', $out);
		$this->assertStringContainsString('drwxr-xr-x  2 JT  staff     64 dir', $out);
	}

	public function testOverlongOutputLinesSplitUnderTheResultIndent(): void
	{
		// Without this an 800-char log line soft-wraps flush to the modal's edge with no
		// hanging indent — the wall-of-text this renderer exists to fix. /export split
		// them at terminal width; so do we, keeping every character.
		$long = trim(str_repeat('abcdefgh ', 40));
		$out  = $this->render("**Claude:** x\n  ↳ `Bash: cat log`\n      {$long}\n", 60);
		$lines = array_values(array_filter(explode("\n", $out), fn($l) => str_contains($l, 'abcdefgh')));

		$this->assertGreaterThan(1, count($lines), 'the overlong line must be split');
		foreach ($lines as $ln) {
			$this->assertLessThanOrEqual(60, mb_strlen($ln));
			$this->assertMatchesRegularExpression('/^ {2}⎿ {2}|^ {5}/', $ln, 'splits keep the result indent');
		}
		// Nothing lost.
		$this->assertSame($long, trim(preg_replace('/\s+/', ' ', str_replace(['⎿'], '', implode(' ', $lines)))));
	}

	/**
	 * Regression: a bare "---" is the compaction rule at column 0, but `echo "---"` output
	 * produces an INDENTED "---" inside a tool result. Treating that as a turn boundary cut
	 * the turn in half and dumped the rest through the stray-prose path — unindented, with
	 * literal "↳" markers.
	 */
	public function testADashRuleInsideToolOutputDoesNotEndTheTurn(): void
	{
		$md = "**Claude:** looking\n  ↳ `Bash: echo \"---\"; ls`\n      first\n      ---\n      ---libs---\n"
			. "      after-the-rule\n  ↳ `Bash: second call`\n      more output\n";
		$out = $this->render($md);

		$this->assertStringContainsString('⏺ Bash(second call)', $out, 'the second tool call was orphaned');
		$this->assertStringNotContainsString('↳', $out);
		foreach (['---libs---', 'after-the-rule', 'more output'] as $body) {
			// Indented, and still inside a result block. (Not `^ {2,}\S.*` — for a line of
			// dashes \S eats the first one and the needle can no longer match.)
			$this->assertMatchesRegularExpression("/^ {2,}.*" . preg_quote($body, '/') . '/m', $out,
				"'{$body}' lost its result indentation");
		}
	}

	/** Regression: an indented "### x" in tool output is output, not a heading. */
	public function testAnIndentedHeadingInsideToolOutputStaysOutput(): void
	{
		$out = $this->render("**Claude:** x\n  ↳ `Bash: cat notes.md`\n      ### a markdown heading\n");

		$this->assertMatchesRegularExpression('/^ {2,}.*### a markdown heading/m', $out);
	}

	/**
	 * Regression: a long tool-call line wraps to a 6-space continuation indent, which
	 * "starts with" the 5-space result indent. Sniffing indentation to decide whether the
	 * result block had already opened swallowed the ⎿ glyph on the first output line.
	 */
	public function testResultGlyphAppearsEvenAfterAWrappedCallLine(): void
	{
		$long = 'ls bin/ | grep -i grave; echo "one"; ls src/ | grep -i grave; echo "two"; grep -rl needle bin src';
		$out  = $this->render("**Claude:** x\n  ↳ `Bash: {$long}`\n      first output line\n", 88);

		$this->assertMatchesRegularExpression('/⎿ {2}first output line/', $out);
	}

	// =====================================================================
	// Fenced code blocks (pasted terminal output inside a prompt)
	// =====================================================================

	public function testFencedCodeSurvivesVerbatim(): void
	{
		$md = "**You:** ```\ngraveyard bury --workspace 8034842f\n  a45ec4c8  ⠂ Fix transcript e\n```\n\nwhy no title?\n";
		$out = $this->render($md, 40);

		$this->assertStringNotContainsString('```', $out);
		// Alignment inside the block is preserved — no wrapping, no markdown stripping.
		$this->assertStringContainsString('graveyard bury --workspace 8034842f', $out);
		$this->assertStringContainsString('a45ec4c8  ⠂ Fix transcript e', $out);
		$this->assertStringContainsString('why no title?', $out);
	}

	public function testBackticksInsideAFenceAreNotTreatedAsInlineCode(): void
	{
		$md = "**You:** ```\nrun `echo hi` now\n```\n";

		$this->assertStringContainsString('run `echo hi` now', $this->render($md));
	}

	// =====================================================================
	// Header
	// =====================================================================

	public function testHeaderBecomesABannerWithoutMarkdownSyntax(): void
	{
		$md = "# my session title\n\n- session `abc-123`\n- cwd `/Users/JT/.dotfiles` · branch `master`\n"
			. "- 2026-07-23T01:01:37.891Z → 2026-07-23T01:19:54.815Z\n\n**You:** hi\n";
		$out = $this->render($md);

		$this->assertStringNotContainsString('# my session title', $out);
		$this->assertStringContainsString('my session title', $out);
		$this->assertStringContainsString('/Users/JT/.dotfiles', $out);
		$this->assertStringContainsString('master', $out);
		// Dates collapse to something readable rather than two ISO timestamps.
		$this->assertStringContainsString('2026-07-23', $out);
		$this->assertStringNotContainsString('01:01:37.891Z', $out);
		// The banner sits above the first turn.
		$this->assertLessThan(mb_strpos($out, '❯ hi'), mb_strpos($out, 'my session title'));
	}

	public function testCompactionMarkerReads(): void
	{
		$md = "**You:** a\n\n---\n\n### ⟲ Context compacted\n\nsummary text\n\n**You:** b\n";
		$out = $this->render($md);

		$this->assertStringContainsString('⟲ Context compacted', $out);
		$this->assertStringNotContainsString('###', $out);
		$this->assertStringContainsString('summary text', $out);
	}

	// =====================================================================
	// End to end on a real archive
	// =====================================================================

	public function testRendersARealArchiveWithoutLeavingMarkdownBehind(): void
	{
		$md = <<<'MD'
			# 29414bc2

			- session `29414bc2-0e8e-40c7-b49f-cbcbac830e6e`
			- cwd `/Users/JT/.dotfiles` · branch `master`
			- 2026-07-23T01:01:37.891Z → 2026-07-23T01:19:54.815Z

			**You:** why doesn't it show me the workspace _title_ when I do this? I assumed it would.

			**Claude:** I'm on Opus here, so I'll dig in. Let me find where graveyard renders that confirmation.
			  ↳ `Bash: grep -rl "Bury this workspace" bin src`
			      bin/graveyard_lib.php
			  ↳ `Read: /Users/JT/.dotfiles/bin/graveyard_lib.php`
			      1286  $this->cli->msg(sprintf('Workspace "%s"'));

			**Claude:** Found it — the confirm line drops the title. **Fixed** in `1291`.
			MD;
		$md = preg_replace('/^\t+/m', '', $md);

		$out = $this->render($md, 88);

		foreach (['**', '↳', '```', '# 29414bc2'] as $leftover) {
			$this->assertStringNotContainsString($leftover, $out, "markdown leftover: {$leftover}");
		}
		$this->assertStringContainsString('❯ ', $out);
		$this->assertStringContainsString('⏺ ', $out);
		$this->assertStringContainsString('⏺ Bash(grep -rl "Bury this workspace" bin src)', $out);
		$this->assertMatchesRegularExpression('/⎿ {2}bin\/graveyard_lib\.php/', $out);
		$this->assertStringContainsString('workspace title when I do this', $out);
		foreach (explode("\n", $out) as $ln) {
			// Only tool output is allowed to run long (it is never re-wrapped).
			if (preg_match('/^\s{2,}/', $ln) && !preg_match('/^ {2}[⎿]|^ {5}/', $ln)) {
				$this->assertLessThanOrEqual(88, mb_strlen($ln), "over width: {$ln}");
			}
		}
	}
}
