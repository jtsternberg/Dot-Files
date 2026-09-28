<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Tests\TestCase;

/**
 * The local-model description and the human NOTES.md are record fields like any
 * other, so every view shows them: page, ls, search, and both --json shapes. They
 * are annotated once, in tombstones(), which every view reads (AGENTS.md: share the
 * source, not just the renderer).
 */
final class GraveyardDescriptionViewsTest extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();
		$root = $this->graveyardRoot;
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0777, true);

		$this->gy = new class($this->cli, $this->transport) extends Graveyard {
			public function liveSessionIdsByAgent(): array { return []; }
		};
		$this->gy->upsertIndex(['session_id' => 'loose111-full', 'workspace_title' => 'solo', 'tab_title' => 'x', 'cwd' => '/b', 'summary' => 'first prompt', 'buried_at' => '2026-07-13',
			'description' => 'It rebuilt the cookie-jar reset.', 'description_model' => 'gemma-big']);
		$this->gy->upsertIndex(['session_id' => 'plain222-full', 'workspace_title' => 'solo', 'tab_title' => 'y', 'cwd' => '/b', 'summary' => 'nothing extra', 'buried_at' => '2026-07-12']);
		$this->gy->upsertIndex(['session_id' => 'm0-full', 'group_id' => 'grp-1', 'group_pos' => 0, 'group_title' => 'ws', 'workspace_title' => 'ws', 'tab_title' => 't0', 'cwd' => '/a', 'summary' => 's0', 'buried_at' => '2026-07-14']);
		$this->gy->upsertIndex(['session_id' => 'm1-full', 'group_id' => 'grp-1', 'group_pos' => 1, 'group_title' => 'ws', 'workspace_title' => 'ws', 'tab_title' => 't1', 'cwd' => '/a', 'summary' => 's1', 'buried_at' => '2026-07-14']);

		@mkdir(dirname($this->gy->noteSessionPath('loose111-full')), 0777, true);
		file_put_contents($this->gy->noteSessionPath('loose111-full'), "# Why\n\nBuilt the tailscale ACL fix.\n");
		@mkdir(dirname($this->gy->noteGroupPath('grp-1')), 0777, true);
		file_put_contents($this->gy->noteGroupPath('grp-1'), 'The kernel-zone investigation.');
	}

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	private function bySid(array $tombs): array
	{
		return array_column($tombs, null, 'session_id');
	}

	public function testTombstonesCarryTheNotesAlongsideTheDescription(): void
	{
		$t = $this->bySid($this->gy->tombstones());

		$this->assertSame("# Why\n\nBuilt the tailscale ACL fix.", $t['loose111-full']['note']);
		$this->assertSame('It rebuilt the cookie-jar reset.', $t['loose111-full']['description']);
		$this->assertSame('The kernel-zone investigation.', $t['m0-full']['plot_note']);
		$this->assertArrayNotHasKey('note', $t['plain222-full']);
		$this->assertArrayNotHasKey('plot_note', $t['plain222-full']);
	}

	public function testSearchMatchesTheDescription(): void
	{
		$this->assertSame(['loose111-full'], array_column($this->gy->searchTombstones('cookie-jar'), 'session_id'));
	}

	public function testSearchMatchesTheSessionNote(): void
	{
		$this->assertSame(['loose111-full'], array_column($this->gy->searchTombstones('tailscale acl'), 'session_id'));
	}

	public function testSearchMatchesThePlotNoteAsAGroupHit(): void
	{
		$hits = $this->gy->searchTombstones('kernel-zone');

		$this->assertSame(['m0-full', 'm1-full'], array_column($hits, 'session_id'));
		$this->assertSame(['group', 'group'], array_column($hits, 'match_scope'));
	}

	public function testJsonRowsCarryDescriptionAndNoteOnlyWhenPresent(): void
	{
		$t = $this->bySid($this->gy->tombstones());

		$row = $this->gy->searchRowJson($t['loose111-full']);
		$this->assertSame('It rebuilt the cookie-jar reset.', $row['description']);
		$this->assertSame('gemma-big', $row['description_model']);
		$this->assertSame("# Why\n\nBuilt the tailscale ACL fix.", $row['note']);

		$plain = $this->gy->searchRowJson($t['plain222-full']);
		$this->assertArrayNotHasKey('description', $plain);
		$this->assertArrayNotHasKey('note', $plain);
	}

	public function testLsAndSearchJsonCarryThePlotNoteOnTheWorkspace(): void
	{
		$tombs = $this->gy->tombstones();

		$this->assertSame('The kernel-zone investigation.', $this->gy->lsJson($tombs)['workspaces'][0]['note']);
		$search = $this->gy->searchJson($this->gy->searchTombstones('s1'), $tombs);
		$this->assertSame('The kernel-zone investigation.', $search['workspaces'][0]['note']);
	}

	public function testLsEntryShowsTheDescriptionAndMarksANote(): void
	{
		$t     = $this->bySid($this->gy->tombstones());
		$lines = $this->gy->lsEntryLines($t['loose111-full'], 120, '/home/x');

		$this->assertStringContainsString(Graveyard::NOTE_MARK, $lines['primary']);
		$this->assertSame('          It rebuilt the cookie-jar reset.', $lines['detail']);
		$this->assertNull($this->gy->lsEntryLines($t['plain222-full'], 120, '/home/x')['detail']);
		$this->assertStringNotContainsString(Graveyard::NOTE_MARK, $this->gy->lsEntryLines($t['plain222-full'], 120, '/home/x')['primary']);
	}

	public function testLsDetailIsCutToTheTerminalWidth(): void
	{
		$t = $this->bySid($this->gy->tombstones())['loose111-full'];
		$t['description'] = str_repeat('word ', 60);

		$this->assertLessThanOrEqual(60, mb_strlen($this->gy->lsEntryLines($t, 60, '/home/x')['detail']));
	}

	public function testThePageSearchesTheDescriptionAndNotes(): void
	{
		$html = $this->gy->renderStorePageHtml();

		$this->assertMatchesRegularExpression('/data-id="loose111-full"[^>]*data-search="[^"]*cookie-jar[^"]*tailscale ACL fix/s', $html);
		$this->assertMatchesRegularExpression('/data-gid="grp-1"[^>]*data-search="[^"]*kernel-zone/s', $html);
	}
}
