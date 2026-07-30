<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-lj6 — `graveyard search <term>` verb.
 *
 * Behavior contract (drives the skill's prose search):
 *  - metadata match across group_title/workspace_title/tab_title/cwd/summary (case-insensitive)
 *  - results sorted newest-first (buried_at desc)
 *  - --full-text also greps rendered transcripts on disk
 *  - --json emits structured rows
 *  - no hits => empty result (CLI prints a message, exit 0)
 *  - a hit inside a buried workspace surfaces the WHOLE plot (same shape as ls),
 *    with the members that actually matched flagged
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

	public function testSearchFullTextMatchesAcrossTranscriptLineBreaks(): void
	{
		$root = $this->makeRoot([
			['session_id' => 'split111', 'workspace_title' => 'unrelated', 'buried_at' => '2026-07-10'],
		]);
		@mkdir($root . '/sessions/split111', 0755, true);
		file_put_contents($root . '/sessions/split111/transcript.txt', "the hidden\nneedle is here");

		$this->assertCount(1, (new Graveyard($this->cli, $this->cmux))->searchTombstones("hidden\nneedle", true));
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
		// `live` was added because resurrect deliberately keeps a tombstone, so a row
		// can describe a session that is running right now — ls/search previously
		// implied every row was still down. Additive; `live_agent` only appears when
		// the session is actually live.
		$this->assertSame(
			// `agent` was added with codex rendering: structured output is a view, and a
			// codex row was otherwise indistinguishable from a Claude one here.
			['session_id', 'workspace_title', 'tab_title', 'cwd', 'summary', 'buried_at', 'last_active', 'agent', 'live'],
			array_keys($gy->searchRowJson($rows[0]))
		);
		$this->assertFalse($gy->searchRowJson($rows[0])['live']);
	}

	/**
	 * A workspace's own title is searchable even when no member's per-session
	 * metadata carries the term (group_title is set at the workspace bury and can
	 * differ from every member's workspace_title/tab_title).
	 */
	public function testSearchMatchesGroupTitle(): void
	{
		$this->makeRoot([
			['session_id' => 'g1', 'group_id' => 'grp-1', 'group_pos' => 0, 'group_title' => 'graveyard original spec', 'workspace_title' => 'graveyard', 'tab_title' => 'gy', 'summary' => 'nothing', 'buried_at' => '2026-07-10'],
		]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$hits = $gy->searchTombstones('original');
		$this->assertSame(['g1'], array_column($hits, 'session_id'));
	}

	/** A hit on one member expands to the full plot; siblings ride along, unflagged. */
	public function testExpandSearchHitsReturnsWholePlot(): void
	{
		$this->makeRoot([
			['session_id' => 'm1', 'group_id' => 'grp-1', 'group_pos' => 1, 'group_title' => 'audit baremetal PR', 'workspace_title' => 'audit baremetal PR', 'tab_title' => 'audit', 'summary' => 'the baremetal audit', 'buried_at' => '2026-07-28'],
			['session_id' => 'm0', 'group_id' => 'grp-1', 'group_pos' => 0, 'group_title' => 'audit baremetal PR', 'workspace_title' => 'audit baremetal PR', 'tab_title' => 'sibling', 'summary' => 'unrelated notes', 'buried_at' => '2026-07-28'],
			['session_id' => 'loose1', 'workspace_title' => 'baremetal notes', 'summary' => 'x', 'buried_at' => '2026-07-20'],
			['session_id' => 'nomatch', 'workspace_title' => 'something else', 'summary' => 'y', 'buried_at' => '2026-07-27'],
		]);
		$gy  = new Graveyard($this->cli, $this->cmux);
		$all = $gy->readIndex()['tombstones'];
		$out = $gy->expandSearchHits($gy->searchTombstones('baremetal'), $all);

		$this->assertCount(1, $out['workspaces']);
		$ws = $out['workspaces'][0];
		$this->assertSame('grp-1', $ws['group_id']);
		$this->assertSame('audit baremetal PR', $ws['title']);
		// every member, ordered by group_pos — not just the one that matched
		$this->assertSame(['m0', 'm1'], array_column($ws['sessions'], 'session_id'));
		$this->assertSame(['m1' => true], $ws['matched']);
		// loose hits stay loose; non-hits stay out entirely
		$this->assertSame(['loose1'], array_column($out['sessions'], 'session_id'));
	}

	/** Plots sort newest-first among themselves, by their newest member. */
	public function testExpandSearchHitsSortsPlotsNewestFirst(): void
	{
		$this->makeRoot([
			['session_id' => 'a0', 'group_id' => 'old', 'group_pos' => 0, 'group_title' => 'ollama old plot', 'buried_at' => '2026-07-01'],
			['session_id' => 'b0', 'group_id' => 'new', 'group_pos' => 0, 'group_title' => 'ollama new plot', 'buried_at' => '2026-07-09'],
		]);
		$gy  = new Graveyard($this->cli, $this->cmux);
		$all = $gy->readIndex()['tombstones'];
		$out = $gy->expandSearchHits($gy->searchTombstones('ollama'), $all);
		$this->assertSame(['new', 'old'], array_column($out['workspaces'], 'group_id'));
	}

	/** search --json mirrors ls --json ({workspaces,sessions}) plus a per-row matched flag. */
	public function testSearchJsonIsGroupAware(): void
	{
		$this->makeRoot([
			['session_id' => 'm1', 'group_id' => 'grp-1', 'group_pos' => 1, 'group_title' => 'audit baremetal PR', 'workspace_title' => 'audit baremetal PR', 'summary' => 'the baremetal audit', 'buried_at' => '2026-07-28'],
			['session_id' => 'm0', 'group_id' => 'grp-1', 'group_pos' => 0, 'group_title' => 'audit baremetal PR', 'workspace_title' => 'audit baremetal PR', 'summary' => 'unrelated', 'buried_at' => '2026-07-28'],
		]);
		$gy   = new Graveyard($this->cli, $this->cmux);
		$json = $gy->searchJson($gy->searchTombstones('baremetal'), $gy->readIndex()['tombstones']);

		$this->assertSame(['workspaces', 'sessions'], array_keys($json));
		$sessions = $json['workspaces'][0]['sessions'];
		$this->assertSame(['group_id', 'title', 'sessions'], array_keys($json['workspaces'][0]));
		$this->assertSame([false, true], array_column($sessions, 'matched'));
		$this->assertArrayHasKey('summary', $sessions[0]);
	}

	/** The match marker only shifts the title when one is asked for — ls stays byte-identical. */
	public function testLsEntryLinesMarkerIsOptOut(): void
	{
		$gy = new Graveyard($this->cli, $this->cmux);
		$t  = ['session_id' => 'abcdef12', 'summary' => 'a title', 'cwd' => '/x', 'buried_at' => '2026-07-28'];
		$plain    = $gy->lsEntryLines($t, 120, '/home/x', 4);
		$flagged  = $gy->lsEntryLines($t, 120, '/home/x', 4, '✱');
		$unmarked = $gy->lsEntryLines($t, 120, '/home/x', 4, ' ');

		$this->assertStringContainsString('abcdef12  a title', $plain['primary']);
		$this->assertStringContainsString('abcdef12  ✱ a title', $flagged['primary']);
		// matched and unmatched rows in the same plot line up
		$this->assertSame(
			mb_strpos($flagged['primary'], 'a title'),
			mb_strpos($unmarked['primary'], 'a title')
		);
	}
}
