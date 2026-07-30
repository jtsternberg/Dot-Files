<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;

/**
 * Resurrect back into the ORIGINAL workspace when it still exists.
 *
 * Bury previously stored only workspace_title/tab_title — no ids — so resurrect
 * had nothing to aim at and always built a fresh workspace. Burying a tab out of
 * a workspace you are still sitting in and getting it back somewhere else is
 * needless work for the user to undo by hand.
 *
 * So a tombstone now records where it came from (workspace/pane uuids + the tab's
 * position in its pane) and resurrect restores in place when that workspace is
 * still around, falling back to a new workspace when it isn't — which is the case
 * the old behaviour was really written for.
 *
 * uuids, not refs: refs are positional labels cmux reassigns as things open and
 * close, so a ref stored at burial can point at a different workspace by the time
 * you resurrect (the same reason the session joins avoid tty).
 */
final class GraveyardResurrectInPlaceTest extends TestCase
{
	private function tree(): array
	{
		return ['windows' => [[
			'ref' => 'window:2', 'id' => 'EEEEEEEE-0000-4000-8000-000000000002',
			'workspaces' => [[
				'ref' => 'workspace:32', 'id' => 'F62E7243-D094-42CD-A9C5-F23CBFC52CD7', 'title' => 'dotfiles',
				'panes' => [
					['ref' => 'pane:59', 'id' => 'AAAAAAAA-0000-4000-8000-000000000059', 'index' => 0, 'surfaces' => [
						['ref' => 'surface:155', 'id' => 'CCCCCCCC-0000-4000-8000-000000000155', 'title' => 'agent', 'type' => 'terminal', 'index_in_pane' => 0],
					]],
					['ref' => 'pane:60', 'id' => 'BBBBBBBB-0000-4000-8000-000000000060', 'index' => 1, 'surfaces' => [
						['ref' => 'surface:157', 'id' => 'DDDDDDDD-0000-4000-8000-000000000157', 'title' => 'codex experiment', 'type' => 'terminal', 'index_in_pane' => 1],
					]],
				],
			]],
		]]];
	}

	// ── treeIndex now exposes the ids a tombstone needs ───────────────────────

	public function testTreeIndexExposesWorkspaceAndPaneIdsPerSurface(): void
	{
		$ix = $this->gy->treeIndex($this->tree());

		$this->assertSame('DDDDDDDD-0000-4000-8000-000000000157', $ix['surface']['surface:157']['id']);
		$this->assertSame('F62E7243-D094-42CD-A9C5-F23CBFC52CD7', $ix['surface']['surface:157']['workspace_id']);
		$this->assertSame('BBBBBBBB-0000-4000-8000-000000000060', $ix['surface']['surface:157']['pane_id']);
		$this->assertSame(1, $ix['surface']['surface:157']['index_in_pane']);
	}

	public function testTreeIndexKeepsTheWorkspaceTitleLookup(): void
	{
		// Pre-existing shape must survive: this map is read as [ref => title].
		$ix = $this->gy->treeIndex($this->tree());
		$this->assertSame('dotfiles', $ix['workspace']['workspace:32']);
	}

	// ── the tombstone records its home ────────────────────────────────────────

	public function testTombstoneRecordsWhereItWasBuriedFrom(): void
	{
		$tomb = $this->gy->buildTombstone([
			'session_id' => 'aaaa1111-2222-3333-4444-555555555555',
			'cwd' => '/Users/JT/.dotfiles',
			'home_workspace_id' => 'F62E7243-D094-42CD-A9C5-F23CBFC52CD7',
			'home_pane_id'      => 'BBBBBBBB-0000-4000-8000-000000000060',
			'window_ref'        => 'window:4',
			'home_index_in_pane'=> 1,
		], ['workspace_title' => 'dotfiles', 'tab_title' => 'codex experiment'], 'sum', '2026-07-29T00:00:00Z');

		$this->assertSame('F62E7243-D094-42CD-A9C5-F23CBFC52CD7', $tomb['home_workspace_id']);
		$this->assertSame('BBBBBBBB-0000-4000-8000-000000000060', $tomb['home_pane_id']);
		$this->assertSame('window:4', $tomb['window_ref']);
		$this->assertSame(1, $tomb['home_index_in_pane']);
	}

	public function testTombstoneOmitsHomeWhenItIsUnknown(): void
	{
		// Nothing to record (an older row, or a session with no bound surface) must not
		// write empty keys that resurrect would then try to aim at.
		$tomb = $this->gy->buildTombstone(
			['session_id' => 'aaaa1111-2222-3333-4444-555555555555', 'cwd' => '/x'],
			['workspace_title' => 'w', 'tab_title' => 't'], 'sum', '2026-07-29T00:00:00Z'
		);

		$this->assertArrayNotHasKey('home_workspace_id', $tomb);
		$this->assertArrayNotHasKey('home_pane_id', $tomb);
	}

	// ── resolveResurrectTarget ────────────────────────────────────────────────

	public function testResurrectTargetsTheOriginalWorkspaceWhenItStillExists(): void
	{
		$t = $this->gy->resolveResurrectTarget($this->tree(), [
			'home_workspace_id' => 'F62E7243-D094-42CD-A9C5-F23CBFC52CD7',
			'home_pane_id'      => 'BBBBBBBB-0000-4000-8000-000000000060',
			'home_index_in_pane'=> 1,
		]);

		$this->assertSame('in_place', $t['mode']);
		$this->assertSame('F62E7243-D094-42CD-A9C5-F23CBFC52CD7', $t['workspace_id']);
		$this->assertSame('BBBBBBBB-0000-4000-8000-000000000060', $t['pane_id']);
	}

	public function testResurrectFallsBackToANewWorkspaceWhenTheOriginalIsGone(): void
	{
		$t = $this->gy->resolveResurrectTarget($this->tree(), [
			'home_workspace_id' => '99999999-0000-4000-8000-999999999999',
			'home_pane_id'      => '88888888-0000-4000-8000-888888888888',
		]);

		$this->assertSame('new_workspace', $t['mode']);
	}

	public function testResurrectFallsBackForATombstoneWithNoHome(): void
	{
		// Every archive buried before this feature existed.
		$this->assertSame('new_workspace', $this->gy->resolveResurrectTarget($this->tree(), [])['mode']);
	}

	public function testResurrectStillTargetsTheWorkspaceWhenOnlyThePaneIsGone(): void
	{
		// A closed pane is not a reason to build a whole new workspace — put the tab
		// back in the workspace it belongs to and let cmux pick the pane.
		$t = $this->gy->resolveResurrectTarget($this->tree(), [
			'home_workspace_id' => 'F62E7243-D094-42CD-A9C5-F23CBFC52CD7',
			'home_pane_id'      => '77777777-0000-4000-8000-777777777777',
		]);

		$this->assertSame('in_place', $t['mode']);
		$this->assertSame('F62E7243-D094-42CD-A9C5-F23CBFC52CD7', $t['workspace_id']);
		$this->assertNull($t['pane_id']);
	}

	public function testResurrectIgnoresARefStoredWhereAUuidBelongs(): void
	{
		// Defensive: a positional ref must never be accepted as a home id, since it can
		// point at a different workspace by resurrect time.
		$t = $this->gy->resolveResurrectTarget($this->tree(), [
			'home_workspace_id' => 'workspace:32',
			'home_pane_id'      => 'pane:60',
		]);

		$this->assertSame('new_workspace', $t['mode']);
	}
}
