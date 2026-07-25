<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-36a: the archive extension follows the RENDERER, not the version.
 *
 *   exportTranscriptViaBin()  → transcript.md   (export-session.mjs emits markdown SOURCE)
 *   exportTranscriptViaRepl() → transcript.txt  (Claude Code's /export emits rendered TUI text)
 *
 * transcriptPath() is the resolver every reader already calls, so it prefers .md and falls
 * back to .txt — the store is permanently mixed (61 TUI archives predate the seam) and both
 * must keep working. Writers use the explicit md/txt paths instead.
 *
 * The subtle one is testReplExportSupersedesAStaleMarkdownArchive(): re-burying with
 * GRAVEYARD_EXPORT_BIN=off writes .txt, and if the previous .md survived, every reader
 * (GATE 2 included) would prefer the STALE file over the fresh one.
 */
final class GraveyardTranscriptExtensionTest extends TestCase
{
	/** @var list<string> */
	private array $cleanup = [];

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_EXPORT_BIN');
		putenv('GRAVEYARD_ROOT');
		foreach ($this->cleanup as $path) {
			is_dir($path) ? $this->rmrf($path) : @unlink($path);
		}
		$this->cleanup = [];
	}

	private function rmrf(string $dir): void
	{
		foreach (glob($dir . '/*') ?: [] as $p) {
			is_dir($p) ? $this->rmrf($p) : @unlink($p);
		}
		@rmdir($dir);
	}

	private function tmpName(string $slug): string
	{
		return sys_get_temp_dir() . '/gy-' . $slug . '-' . getmypid() . '-' . uniqid();
	}

	private function makeGraveyard(): Graveyard
	{
		$root = $this->tmpName('ext-root');
		putenv('GRAVEYARD_ROOT=' . $root);
		$this->cleanup[] = $root;

		return new Graveyard($this->cli, $this->cmux);
	}

	/** Stub export-session.mjs emitting $stdout. */
	private function stubExportBin(string $stdout): string
	{
		$bin = $this->tmpName('ext-stub');
		file_put_contents($bin, "#!/bin/sh\ncat <<'GYEOF'\n{$stdout}\nGYEOF\nexit 0\n");
		chmod($bin, 0755);
		putenv('GRAVEYARD_EXPORT_BIN=' . $bin);
		$this->cleanup[] = $bin;

		return $bin;
	}

	private function sess(string $sid): array
	{
		return [
			'session_id'    => $sid,
			'cwd'           => '/Users/JT/.dotfiles',
			'pid'           => getmypid(),
			'surface_ref'   => 'surface:1',
			'workspace_ref' => 'workspace:1',
		];
	}

	private function writeArchive(Graveyard $gy, string $sid, string $ext, string $body): string
	{
		$dir = $gy->sessionDir($sid);
		@mkdir($dir, 0755, true);
		$path = $dir . '/transcript.' . $ext;
		file_put_contents($path, $body);

		return $path;
	}

	// =====================================================================
	// transcriptPath() — the resolver every reader goes through
	// =====================================================================

	public function testTranscriptPathFallsBackToTxtForLegacyArchives(): void
	{
		// 61 of these predate the seam. They are TUI-rendered text and .txt is correct
		// for them, so they must resolve untouched and unrenamed.
		$gy  = $this->makeGraveyard();
		$sid = 'legacy-tui';
		$txt = $this->writeArchive($gy, $sid, 'txt', "❯ hello\n⏺ hi\n");

		$this->assertSame($txt, $gy->transcriptPath($sid));
	}

	public function testTranscriptPathPrefersMarkdownWhenBothExist(): void
	{
		$gy  = $this->makeGraveyard();
		$sid = 'both-present';
		$this->writeArchive($gy, $sid, 'txt', "❯ old tui\n");
		$md = $this->writeArchive($gy, $sid, 'md', "**You:** newer markdown\n");

		$this->assertSame($md, $gy->transcriptPath($sid));
	}

	public function testTranscriptPathDefaultsToTxtWhenNothingIsArchived(): void
	{
		// Readers guard with is_file(); returning the legacy name for a missing archive
		// keeps "Transcript missing: …/transcript.txt" honest for pre-seam sessions.
		$gy = $this->makeGraveyard();

		$this->assertSame($gy->sessionDir('nothing') . '/transcript.txt', $gy->transcriptPath('nothing'));
	}

	public function testExplicitRendererPathsAreDistinct(): void
	{
		$gy = $this->makeGraveyard();

		$this->assertStringEndsWith('/transcript.md', $gy->transcriptMdPath('x'));
		$this->assertStringEndsWith('/transcript.txt', $gy->transcriptTxtPath('x'));
	}

	// =====================================================================
	// Writers name the file after the renderer
	// =====================================================================

	public function testBinExportWritesMarkdown(): void
	{
		$bin = $this->stubExportBin('**You:** hi');
		$gy  = $this->makeGraveyard();
		$sid = 'bin-writes-md';

		$this->assertTrue($gy->exportTranscriptViaBin($this->sess($sid), $bin));
		$this->assertFileExists($gy->transcriptMdPath($sid));
		$this->assertFileDoesNotExist($gy->transcriptTxtPath($sid));
		$this->assertSame($gy->transcriptMdPath($sid), $gy->transcriptPath($sid));
	}

	public function testReplExportWritesTxt(): void
	{
		$gy  = $this->replGraveyard();
		$sid = 'repl-writes-txt';

		$this->assertTrue($gy->exportTranscriptViaRepl($this->sess($sid), 5));
		$this->assertFileExists($gy->transcriptTxtPath($sid));
		$this->assertFileDoesNotExist($gy->transcriptMdPath($sid));
		$this->assertSame($gy->transcriptTxtPath($sid), $gy->transcriptPath($sid));
	}

	// =====================================================================
	// One archive per session: a fresh export supersedes the other renderer's
	// =====================================================================

	public function testBinExportSupersedesAStaleTxtArchive(): void
	{
		$bin = $this->stubExportBin('**You:** fresh markdown');
		$gy  = $this->makeGraveyard();
		$sid = 'md-supersedes-txt';
		$this->writeArchive($gy, $sid, 'txt', "❯ stale tui export\n");

		$this->assertTrue($gy->exportTranscriptViaBin($this->sess($sid), $bin));
		$this->assertFileDoesNotExist($gy->transcriptTxtPath($sid), 'the superseded archive must not linger');
		$this->assertStringContainsString('fresh markdown', (string) file_get_contents($gy->transcriptPath($sid)));
	}

	public function testReplExportSupersedesAStaleMarkdownArchive(): void
	{
		// Re-bury with GRAVEYARD_EXPORT_BIN=off after an earlier .md export. If the stale
		// .md survived, transcriptPath() would prefer it and GATE 2 would check the WRONG
		// file — the cosmetic rename turning into a correctness bug.
		$gy  = $this->replGraveyard();
		$sid = 'txt-supersedes-md';
		$this->writeArchive($gy, $sid, 'md', "**You:** stale markdown export\n");

		$this->assertTrue($gy->exportTranscriptViaRepl($this->sess($sid), 5));
		$this->assertFileDoesNotExist($gy->transcriptMdPath($sid), 'the superseded archive must not linger');
		$this->assertSame($gy->transcriptTxtPath($sid), $gy->transcriptPath($sid));
		$this->assertStringContainsString('typed into the repl', (string) file_get_contents($gy->transcriptPath($sid)));
	}

	// =====================================================================
	// The readers named in dotfiles-36a as the ones that will bite
	// =====================================================================

	/**
	 * GATE 2 reads transcriptPath(). A bin export writes .md, so if the resolver did not
	 * follow, bury would write its export and then fail to find it — refusing teardown and
	 * leaving sessions alive. Same shape as the real bury path, minus the teardown.
	 */
	public function testGate2FindsTheMarkdownArchiveABinExportJustWrote(): void
	{
		$needle = 'Now prove GATE 2 still opens after the rename';
		$bin = $this->stubExportBin("# session\n\n**Claude:** {$needle} — and it does.");
		$gy  = $this->makeGraveyard();
		$sid = 'gate2-md';

		$this->assertTrue($gy->exportTranscriptViaBin($this->sess($sid), $bin));

		$exported = (string) @file_get_contents($gy->transcriptPath($sid));
		$this->assertNotSame('', $exported, 'GATE 2 read an empty string — the resolver missed the .md');
		$this->assertTrue($gy->transcriptBelongsToSession($exported, [$needle]));
	}

	/** `graveyard show` and the page modal must open/display the .md, not report it missing. */
	public function testShowAndPageReadersResolveMarkdown(): void
	{
		$gy  = $this->makeGraveyard();
		$sid = 'readers-md';
		$this->writeArchive($gy, $sid, 'md', "**You:** find me\n");

		// showTombstone() guards on is_file(transcriptPath()); the page modal prints it.
		$this->assertFileExists($gy->transcriptPath($sid));
		// renderTranscriptJs() is the page-data reader behind the transcript modal.
		$js = $gy->renderTranscriptJs($sid);
		$this->assertNotNull($js, 'the page modal would show "(no transcript lies here)"');
		$this->assertStringContainsString('find me', $js);
	}

	/** `search --full-text` greps the archive; a .md-only session must still be searchable. */
	public function testFullTextSearchFindsMarkdownArchives(): void
	{
		$gy  = $this->makeGraveyard();
		$sid = 'searchable-md';
		$this->writeArchive($gy, $sid, 'md', "**Claude:** the tarpaulin hypothesis\n");
		$gy->upsertIndex(['session_id' => $sid, 'buried_at' => '2026-07-25T00:00:00Z', 'summary' => 'unrelated']);

		$hits = $gy->searchTombstones('tarpaulin hypothesis', true);

		$this->assertCount(1, $hits);
		$this->assertSame($sid, $hits[0]['session_id']);
	}

	/** purgeSession() rmrf's the session dir, so it takes the .md with it. */
	public function testPurgeRemovesMarkdownArchives(): void
	{
		$gy  = $this->makeGraveyard();
		$sid = 'purge-md';
		$md  = $this->writeArchive($gy, $sid, 'md', "**You:** delete me\n");
		$gy->upsertIndex(['session_id' => $sid]);

		$removed = $gy->purgeSession($sid);

		$this->assertTrue($removed['dir']);
		$this->assertFileDoesNotExist($md);
	}

	/** transcriptUpToDate() compares the archive's mtime — it must stat the .md. */
	public function testTranscriptUpToDateSeesTheMarkdownArchive(): void
	{
		$gy  = $this->makeGraveyard();
		$sid = 'uptodate-md-' . uniqid();
		$cwd = sys_get_temp_dir() . '/gy-ext-cwd-' . getmypid();

		$jsonl = $this->cmux->jsonlPathFor($sid, $cwd);
		@mkdir(dirname($jsonl), 0755, true);
		$this->cleanup[] = dirname($jsonl);
		$turn = strtotime('2026-07-01T10:00:00Z');
		file_put_contents($jsonl, json_encode([
			'type' => 'user', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $turn),
		]) . "\n");

		$this->assertFalse($gy->transcriptUpToDate($sid, $cwd), 'nothing archived yet');

		$md = $this->writeArchive($gy, $sid, 'md', "**You:** archived\n");
		touch($md, $turn + 100);
		$this->assertTrue($gy->transcriptUpToDate($sid, $cwd), 'a .md archive newer than the last turn is current');
	}

	/**
	 * A Graveyard whose REPL path is stubbed (no cmux, no live session): sendExportCommand()
	 * writes the temp file /export would have produced.
	 */
	private function replGraveyard(): Graveyard
	{
		$root = $this->tmpName('ext-root');
		putenv('GRAVEYARD_ROOT=' . $root);
		$this->cleanup[] = $root;

		return new class ($this->cli, $this->cmux) extends Graveyard {
			public function sendExportCommand(array $sess, string $tmp): void {
				file_put_contents($tmp, "typed into the repl\n");
			}
		};
	}
}
