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
		// stable key set so agents can rely on it
		$this->assertSame(
			['session_id', 'idle_seconds', 'busy', 'targetable', 'reason', 'workspace_title', 'tab_title', 'cwd'],
			array_keys($j[0])
		);
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
