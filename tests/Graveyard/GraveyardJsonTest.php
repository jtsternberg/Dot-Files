<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;

/**
 * dotfiles-bun — --json output for ls and candidates.
 *
 * Pure shape/format methods so the JSON is testable without cmux/TTY:
 *  - candidatesJson(rows)  => flat array with the fields the skill ranks on
 *  - lsJson(tombs)         => workspaces grouped + loose sessions
 */
final class GraveyardJsonTest extends TestCase
{
	public function testCandidatesJsonShape(): void
	{
		$rows = [[
			'session_id' => 'abc123', 'idle_seconds' => 90000, 'busy' => false,
			'targetable' => true, 'reason' => '', 'workspace_title' => 'tailscale',
			'tab_title' => 'net', 'cwd' => '/x', 'pid' => 42, 'model' => 'opus',
			'skip_perms' => true, 'surface_ref' => 'surface:1', 'workspace_ref' => 'workspace:1',
		]];
		$j = $this->gy->candidatesJson($rows);
		$this->assertCount(1, $j);
		$this->assertSame('abc123', $j[0]['session_id']);
		$this->assertSame(90000, $j[0]['idle_seconds']);
		$this->assertFalse($j[0]['busy']);
		$this->assertTrue($j[0]['targetable']);
		$this->assertSame('tailscale', $j[0]['workspace_title']);
		$this->assertSame('net', $j[0]['tab_title']);
		$this->assertSame('/x', $j[0]['cwd']);
		// A claude row with no explicit agent still reports itself as claude/buryable.
		$this->assertSame('claude', $j[0]['agent']);
		$this->assertTrue($j[0]['buryable']);
		// A row from before the transport union still reports itself as cmux-hosted,
		// so an agent reading this never sees an empty transport.
		$this->assertSame('cmux', $j[0]['transport']);
		// Stable key set so agents can rely on it. `agent` and `buryable` were added
		// when graveyard learned to DISCOVER codex sessions without being able to
		// bury them (dotfiles-nvf) — a consumer that offers a bury needs to know
		// which rows would be refused. `transport` was added when discovery became a
		// union over cmux + herdr, for the same reason the text view marks it: a
		// consumer needs to know where the session actually is. `verdict`, `p_done`
		// and `verdict_source` carry the bury-candidate ranking hint (dotfiles-0enr.1).
		// Additive; every pre-existing key kept.
		// The locator keys let a consumer (maestro your-cue's locate.sh) join a row to
		// the live multiplexer without a second lookup.
		$this->assertSame(
			['session_id', 'agent', 'transport', 'idle_seconds', 'busy', 'buryable', 'targetable', 'reason', 'workspace_title', 'tab_title', 'cwd', 'verdict', 'p_done', 'verdict_source',
				'surface_id', 'surface_ref', 'pane_ref', 'workspace_ref', 'window_ref', 'tab_ref'],
			array_keys($j[0])
		);
	}

	/**
	 * Contract for the locator fields: every row carries every key, missing values are
	 * null, and a herdr tab is emitted as tab_ref — never as window_ref, which would let
	 * a consumer treat a herdr tab as a cmux window. Driven through candidateRowFor()
	 * because that is what candidates() hands candidatesJson().
	 */
	public function testCandidatesJsonCarriesLocatorsPerTransport(): void
	{
		$base = [
			'idle_seconds' => 600, 'cwd' => '/x', 'workspace_title' => 'w', 'tab_title' => 't',
			'pid' => 1, 'model' => null, 'skip_perms' => false, 'agent' => 'claude',
		];
		$cmux = $base + [
			'session_id' => 'c1', 'transport' => 'cmux',
			'surface_ref' => 'surface:7', 'surface_id' => 'AAAA-1111', 'pane_ref' => 'pane:3',
			'workspace_ref' => 'workspace:2', 'window_ref' => 'window:1',
		];
		$herdr = $base + [
			'session_id' => 'h1', 'transport' => 'herdr',
			'surface_ref' => 'wF:p3', 'surface_id' => 'wF:p3', 'pane_ref' => 'wF:p3',
			'workspace_ref' => 'wF', 'window_ref' => 'wF:t1',
		];
		// A cmux row whose tree lookup missed: pane/window absent, not even null.
		$sparse = $base + [
			'session_id' => 'c2', 'transport' => 'cmux',
			'surface_ref' => 'surface:9', 'workspace_ref' => 'workspace:4',
		];
		$j = $this->gy->candidatesJson(array_map(
			fn($r) => $this->gy->candidateRowFor($r, false), [$cmux, $herdr, $sparse]
		));

		$pick = fn($row) => array_intersect_key($row, array_flip(
			['surface_id', 'surface_ref', 'pane_ref', 'workspace_ref', 'window_ref', 'tab_ref']
		));
		$this->assertSame([
			'surface_id' => 'AAAA-1111', 'surface_ref' => 'surface:7', 'pane_ref' => 'pane:3',
			'workspace_ref' => 'workspace:2', 'window_ref' => 'window:1', 'tab_ref' => null,
		], $pick($j[0]));
		$this->assertSame([
			'surface_id' => 'wF:p3', 'surface_ref' => 'wF:p3', 'pane_ref' => 'wF:p3',
			'workspace_ref' => 'wF', 'window_ref' => null, 'tab_ref' => 'wF:t1',
		], $pick($j[1]));
		$this->assertSame([
			'surface_id' => null, 'surface_ref' => 'surface:9', 'pane_ref' => null,
			'workspace_ref' => 'workspace:4', 'window_ref' => null, 'tab_ref' => null,
		], $pick($j[2]));
		// Same key set, same order, on every row.
		$this->assertSame(array_keys($j[0]), array_keys($j[1]));
		$this->assertSame(array_keys($j[0]), array_keys($j[2]));
	}

	public function testLsJsonGroupsWorkspacesAndLoose(): void
	{
		$tombs = [
			['session_id' => 'm1', 'group_id' => 'g1', 'group_pos' => 1, 'group_title' => 'ws', 'workspace_title' => 'ws', 'tab_title' => 't1', 'cwd' => '/a', 'summary' => 's1', 'buried_at' => '2026-07-14', 'last_active' => '2026-07-14'],
			['session_id' => 'm0', 'group_id' => 'g1', 'group_pos' => 0, 'group_title' => 'ws', 'workspace_title' => 'ws', 'tab_title' => 't0', 'cwd' => '/a', 'summary' => 's0', 'buried_at' => '2026-07-14', 'last_active' => '2026-07-14'],
			['session_id' => 'loose1', 'workspace_title' => 'solo', 'tab_title' => 'x', 'cwd' => '/b', 'summary' => 's', 'buried_at' => '2026-07-13', 'last_active' => '2026-07-13'],
		];
		$j = $this->gy->lsJson($tombs);
		$this->assertArrayHasKey('workspaces', $j);
		$this->assertArrayHasKey('sessions', $j);

		// one workspace, members ordered by group_pos
		$this->assertCount(1, $j['workspaces']);
		$this->assertSame('g1', $j['workspaces'][0]['group_id']);
		$this->assertSame('ws', $j['workspaces'][0]['title']);
		$this->assertSame(['m0', 'm1'], array_column($j['workspaces'][0]['sessions'], 'session_id'));

		// loose sessions flattened, member rows carry the searchRowJson fields
		$this->assertCount(1, $j['sessions']);
		$this->assertSame('loose1', $j['sessions'][0]['session_id']);
		$this->assertSame('solo', $j['sessions'][0]['workspace_title']);
	}
}
