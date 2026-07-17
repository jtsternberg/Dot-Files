<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-lj6 — `graveyard search <term>` verb.
 *
 * Behavior contract (drives the skill's prose search):
 *  - metadata match across workspace_title/tab_title/cwd/summary (case-insensitive)
 *  - results sorted newest-first (buried_at desc)
 *  - --full-text also greps rendered transcripts on disk
 *  - --json emits structured rows
 *  - no hits => empty result (CLI prints a message, exit 0)
 */
final class GraveyardSearchTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	/** Build an isolated graveyard root with a couple of tombstones. */
	protected function makeRoot(array $tombs): string
	{
		$root = sys_get_temp_dir() . '/gy-search-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);
		$gy = new Graveyard($this->cli, $this->cmux);
		foreach ($tombs as $t) { $gy->upsertIndex($t); }
		return $root;
	}

	public function testSearchMatchesMetadataCaseInsensitively(): void
	{
		$this->makeRoot([
			['session_id' => 'aaa111', 'workspace_title' => 'Tailscale Setup', 'tab_title' => 'net', 'cwd' => '/x', 'summary' => 'configure the mesh', 'buried_at' => '2026-07-10'],
			['session_id' => 'bbb222', 'workspace_title' => 'Blog', 'tab_title' => 'post', 'cwd' => '/y', 'summary' => 'write about routers', 'buried_at' => '2026-07-11'],
		]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$hits = $gy->searchTombstones('tailscale');
		$this->assertCount(1, $hits);
		$this->assertSame('aaa111', $hits[0]['session_id']);

		// matches across any of the metadata fields, case-insensitively
		$this->assertCount(1, $gy->searchTombstones('ROUTERS'));
		$this->assertSame('bbb222', $gy->searchTombstones('ROUTERS')[0]['session_id']);
	}

	public function testSearchSortedNewestFirst(): void
	{
		$this->makeRoot([
			['session_id' => 'old111', 'workspace_title' => 'ollama older', 'buried_at' => '2026-07-01'],
			['session_id' => 'new222', 'workspace_title' => 'ollama newer', 'buried_at' => '2026-07-09'],
		]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$hits = $gy->searchTombstones('ollama');
		$this->assertSame(['new222', 'old111'], array_column($hits, 'session_id'));
	}

	public function testSearchFullTextGrepTranscripts(): void
	{
		$root = $this->makeRoot([
			['session_id' => 'meta111', 'workspace_title' => 'unrelated', 'summary' => 'nothing here', 'buried_at' => '2026-07-10'],
		]);
		// The term appears ONLY in the transcript body, not the metadata.
		$sdir = $root . '/sessions/meta111';
		@mkdir($sdir, 0755, true);
		file_put_contents($sdir . '/transcript.txt', "…conversation about ollama local models…");

		$gy = new Graveyard($this->cli, $this->cmux);
		$this->assertCount(0, $gy->searchTombstones('ollama'));            // metadata only: miss
		$this->assertCount(1, $gy->searchTombstones('ollama', true));      // full-text: hit
		$this->assertSame('meta111', $gy->searchTombstones('ollama', true)[0]['session_id']);
	}

	public function testSearchNoHitsReturnsEmpty(): void
	{
		$this->makeRoot([
			['session_id' => 'x1', 'workspace_title' => 'tailscale', 'buried_at' => '2026-07-10'],
		]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$this->assertSame([], $gy->searchTombstones('nonexistent-thing'));
	}

	public function testSearchJsonShape(): void
	{
		$this->makeRoot([
			['session_id' => 'aaa111', 'workspace_title' => 'Tailscale Setup', 'tab_title' => 'net', 'cwd' => '/x', 'summary' => 's', 'buried_at' => '2026-07-10'],
		]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$rows = $gy->searchTombstones('tailscale');
		$this->assertSame(
			['session_id', 'workspace_title', 'tab_title', 'cwd', 'summary', 'buried_at', 'last_active'],
			array_keys($gy->searchRowJson($rows[0]))
		);
	}
}
