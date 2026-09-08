<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;
use JT\CLI\Helpers;

/**
 * A CLI helper whose exitErr throws instead of exit()ing, so the ensure-note
 * verbs' no-match / ambiguous branches are assertable (mirrors CmuxBak's
 * exit-intercept helper). Only the error path needs it; the create path runs
 * on the ordinary singleton CLI.
 */
final class NoteExitInterceptHelpers extends Helpers {
	public function __construct() { parent::__construct(); }
	public function exitErr( $args, $code = 1, $lineBreak = true ) {
		throw new \RuntimeException('exitErr: ' . $args);
	}
}

/**
 * graveyard `note` — human NOTES.md on a buried session/plot (design spec
 * 2026-09-08). Authoring (ensureSessionNote/ensureGroupNote), rendering
 * (renderNoteHtml/renderNoteJs), the data-has-note page markers, and the claim
 * that deletion carries NOTES.md out with the dir (no new cleanup code).
 */
final class GraveyardNoteTest extends TestCase
{
	protected function tomb(string $sid, string $summary, ?string $gid = null, ?string $gtitle = null, ?int $pos = null): array
	{
		$t = [
			'session_id' => $sid, 'workspace_title' => 'WS', 'tab_title' => 'Tab',
			'cwd' => '/home/x/proj', 'summary' => $summary, 'model' => 'opus',
			'buried_at' => '2026-09-01T10:00:00Z', 'last_active' => '2026-09-01T09:59:00Z',
		];
		if ($gid !== null) { $t['group_id'] = $gid; $t['group_title'] = $gtitle; $t['group_pos'] = $pos; }
		return $t;
	}

	/** Seed a workspace group manifest so resolveGroup() can find it. */
	protected function seedGroup(string $gid, string $title): void
	{
		@mkdir($this->graveyardRoot . '/workspaces/' . $gid, 0755, true);
		file_put_contents(
			$this->graveyardRoot . '/workspaces/' . $gid . '/manifest.json',
			json_encode(['group_id' => $gid, 'group_title' => $title, 'layout' => []])
		);
	}

	private function throwingGraveyard(): Graveyard
	{
		return new Graveyard(new NoteExitInterceptHelpers(), $this->transport);
	}

	// --- Test 1: ensureSessionNote -----------------------------------------

	public function testEnsureSessionNoteCreatesSeededFileAndReturnsPath(): void
	{
		$this->gy->upsertIndex($this->tomb('sess1234-full', 'fix the parser'));

		$path = $this->gy->ensureSessionNote('sess1234');
		$this->assertSame($this->gy->noteSessionPath('sess1234-full'), $path);
		$this->assertFileExists($path);
		$this->assertSame("# fix the parser\n\n", file_get_contents($path));
	}

	public function testEnsureSessionNoteLeavesAnExistingFileUntouched(): void
	{
		$this->gy->upsertIndex($this->tomb('sess1234-full', 'fix the parser'));
		$path = $this->gy->noteSessionPath('sess1234-full');
		@mkdir(dirname($path), 0755, true);
		file_put_contents($path, "already here\n");

		$this->assertSame($path, $this->gy->ensureSessionNote('sess1234'));
		$this->assertSame("already here\n", file_get_contents($path)); // not reseeded
	}

	public function testEnsureSessionNoteRejectsAmbiguousAndUnknownRefs(): void
	{
		$gy = $this->throwingGraveyard();
		$gy->upsertIndex($this->tomb('dupe0001-full', 'one'));
		$gy->upsertIndex($this->tomb('dupe0002-full', 'two'));

		try { $gy->ensureSessionNote('dupe'); $this->fail('expected ambiguous'); }
		catch (\RuntimeException $e) { $this->assertStringContainsString('ambiguous', $e->getMessage()); }

		try { $gy->ensureSessionNote('nope-nope'); $this->fail('expected no-match'); }
		catch (\RuntimeException $e) { $this->assertStringContainsString('No buried session matches', $e->getMessage()); }
	}

	// --- Test 2: ensureGroupNote -------------------------------------------

	public function testEnsureGroupNoteCreatesSeededFileAndReturnsPath(): void
	{
		$this->seedGroup('grp-uuid-abc', 'The Big Plot');

		$path = $this->gy->ensureGroupNote('grp-uuid');
		$this->assertSame($this->gy->noteGroupPath('grp-uuid-abc'), $path);
		$this->assertFileExists($path);
		$this->assertSame("# The Big Plot\n\n", file_get_contents($path));
	}

	public function testEnsureGroupNoteRejectsUnknownGroup(): void
	{
		$gy = $this->throwingGraveyard();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No single workspace group matches');
		$gy->ensureGroupNote('ghost');
	}

	// --- Test 3: renderNoteHtml (safe markdown → HTML) ---------------------

	public function testRenderNoteHtmlAutolinksBareUrls(): void
	{
		$html = $this->gy->renderNoteHtml('See https://example.com/thread for context.');
		$this->assertStringContainsString('<a href="https://example.com/thread"', $html);
	}

	public function testRenderNoteHtmlStripsRawHtml(): void
	{
		$html = $this->gy->renderNoteHtml("hello\n\n<script>alert(1)</script>\n\n**bold**");
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringNotContainsString('alert(1)', $html); // stripped, not escaped-through
		$this->assertStringContainsString('<strong>bold</strong>', $html); // real markdown still renders
	}

	// --- Test 4: renderNoteJs (page-data payload) --------------------------

	public function testRenderNoteJsIsNullWhenNoNoteExists(): void
	{
		$this->gy->upsertIndex($this->tomb('nonote00-full', 'no note here'));
		$this->assertNull($this->gy->renderNoteJs('nonote00-full'));
		$this->assertNull($this->gy->renderNoteJs(''));
	}

	public function testRenderNoteJsEmitsPayloadForSessionAndGroup(): void
	{
		// session note
		$sp = $this->gy->noteSessionPath('withnote-full');
		@mkdir(dirname($sp), 0755, true);
		file_put_contents($sp, "# Title\n\nbody text\n");
		$js = $this->gy->renderNoteJs('withnote-full');
		$this->assertNotNull($js);
		$this->assertStringContainsString('window.GYN', $js);
		$this->assertStringContainsString('withnote-full', $js);
		$this->assertStringContainsString('body text', $js);

		// group note resolves through the same key channel
		$gp = $this->gy->noteGroupPath('grpnote00-uuid');
		@mkdir(dirname($gp), 0755, true);
		file_put_contents($gp, "# Plot\n\nplot note\n");
		$this->assertStringContainsString('plot note', (string) $this->gy->renderNoteJs('grpnote00-uuid'));
	}

	public function testRenderNoteJsDoesNotBreakOutOfScriptBlock(): void
	{
		$sp = $this->gy->noteSessionPath('breakout-full');
		@mkdir(dirname($sp), 0755, true);
		file_put_contents($sp, "text with </script> in it\n");
		$js = (string) $this->gy->renderNoteJs('breakout-full');
		$this->assertStringNotContainsString('</script>', $js); // JSON_HEX_TAG escapes it
	}

	// --- Test 5: data-has-note markers on the page -------------------------

	public function testStoneCarriesHasNoteOnlyWhenNoteExists(): void
	{
		$withNote = $this->tomb('hasnote0-full', 'annotated');
		$without  = $this->tomb('plain000-full', 'bare');
		$this->gy->upsertIndex($withNote);
		$this->gy->upsertIndex($without);

		$sp = $this->gy->noteSessionPath('hasnote0-full');
		@mkdir(dirname($sp), 0755, true);
		file_put_contents($sp, "# annotated\n\nx\n");

		$html = $this->gy->pageHtml([$withNote, $without], '2026-09-08');
		// the annotated stone carries the flag; the bare one does not
		$this->assertMatchesRegularExpression(
			'/data-id="hasnote0-full"[^>]*data-has-note="1"/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/data-id="plain000-full"[^>]*data-has-note="1"/',
			$html
		);
	}

	public function testPlotCarriesHasNoteWhenGroupNoteExists(): void
	{
		$gid = 'plotnote-uuid';
		$m1 = $this->tomb('pm111111-full', 'one', $gid, 'Noted Plot', 0);
		$m2 = $this->tomb('pm222222-full', 'two', $gid, 'Noted Plot', 1);
		$this->gy->upsertIndex($m1);
		$this->gy->upsertIndex($m2);

		$gp = $this->gy->noteGroupPath($gid);
		@mkdir(dirname($gp), 0755, true);
		file_put_contents($gp, "# Noted Plot\n\ncontext\n");

		$html = $this->gy->pageHtml([$m1, $m2], '2026-09-08');
		$this->assertMatchesRegularExpression(
			'/data-gid="' . preg_quote($gid, '/') . '"[^>]*data-has-note="1"/',
			$html
		);
	}

	// --- Test 7: deletion removes NOTES.md with the dir --------------------

	public function testPurgeSessionRemovesTheNote(): void
	{
		$this->gy->upsertIndex($this->tomb('doomed00-full', 'delete me'));
		$path = $this->gy->ensureSessionNote('doomed00');
		$this->assertFileExists($path);

		$this->gy->purgeSession('doomed00-full');
		$this->assertFileDoesNotExist($path); // carried out with sessions/<id>/ — no new cleanup code
	}

	public function testDeleteGroupRemovesTheNote(): void
	{
		$gid = 'doomedgrp-uuid';
		$this->gy->upsertIndex($this->tomb('gm111111-full', 'one', $gid, 'Doomed', 0));
		$this->seedGroup($gid, 'Doomed');
		$path = $this->gy->ensureGroupNote($gid);
		$this->assertFileExists($path);

		$this->gy->purgeGroup($gid);
		$this->assertFileDoesNotExist($path); // carried out with workspaces/<gid>/
	}
}
