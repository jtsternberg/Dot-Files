<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;

/**
 * dotfiles-bury-group-target — `bury --workspace <group-id>` symmetry.
 *
 * resurrect accepts a buried group-id prefix; bury --workspace must too. When the
 * arg resolves to a buried group, bury retargets to the LIVE cmux workspace that
 * currently hosts that group's (resurrected) member sessions. liveWorkspaceForGroup
 * is the pure core of that retarget: given the resolved manifest + the current
 * live-session rows, it returns the hosting workspace_ref (or null).
 */
final class GraveyardBuryGroupTargetTest extends TestCase
{
	/** @param array<int,array{claude_session_id?:string}> $members */
	private function manifest(array $memberSids): array
	{
		return [
			'group_id'    => '8034842f-19af-4daf-a59b-3d2218bd54a1',
			'group_title' => 'cli pr 27 - conceptual guides',
			'layout'      => array_map(fn($sid) => ['claude_session_id' => $sid], $memberSids),
		];
	}

	private function live(array $pairs): array
	{
		// pairs: [session_id => workspace_ref]
		$rows = [];
		foreach ($pairs as $sid => $wsRef) {
			$rows[] = ['session_id' => $sid, 'workspace_ref' => $wsRef];
		}
		return $rows;
	}

	public function testMapsGroupMembersToHostingWorkspace(): void
	{
		$m = $this->manifest(['aaa-1', 'bbb-2', 'ccc-3']);
		$live = $this->live([
			'aaa-1'   => 'workspace:71',
			'bbb-2'   => 'workspace:71',
			'ccc-3'   => 'workspace:71',
			'other-9' => 'workspace:42', // unrelated session in another workspace
		]);
		$this->assertSame('workspace:71', $this->gy->liveWorkspaceForGroup($m, $live));
	}

	public function testReturnsNullWhenNoMembersAreLive(): void
	{
		$m = $this->manifest(['aaa-1', 'bbb-2']);
		$live = $this->live(['other-9' => 'workspace:42']);
		$this->assertNull($this->gy->liveWorkspaceForGroup($m, $live));
	}

	public function testReturnsNullForEmptyManifest(): void
	{
		$this->assertNull($this->gy->liveWorkspaceForGroup(['layout' => []], []));
	}

	public function testPartialLiveMembersStillResolveHostingWorkspace(): void
	{
		// Only one of three members came back live — still enough to target its workspace.
		$m = $this->manifest(['aaa-1', 'bbb-2', 'ccc-3']);
		$live = $this->live(['bbb-2' => 'workspace:60']);
		$this->assertSame('workspace:60', $this->gy->liveWorkspaceForGroup($m, $live));
	}

	public function testSplitMembersPickMajorityWorkspace(): void
	{
		// A stray duplicate view landed a member in a different workspace; the
		// workspace hosting the most members wins so the target is unambiguous.
		$m = $this->manifest(['aaa-1', 'bbb-2', 'ccc-3']);
		$live = $this->live([
			'aaa-1' => 'workspace:71',
			'bbb-2' => 'workspace:71',
			'ccc-3' => 'workspace:99',
		]);
		$this->assertSame('workspace:71', $this->gy->liveWorkspaceForGroup($m, $live));
	}

	public function testTieBrokenDeterministicallyByRef(): void
	{
		// Equal counts across two workspaces → lowest ref string wins (stable, not
		// dependent on hash/iteration order).
		$m = $this->manifest(['aaa-1', 'bbb-2']);
		$live = $this->live([
			'aaa-1' => 'workspace:88',
			'bbb-2' => 'workspace:20',
		]);
		$this->assertSame('workspace:20', $this->gy->liveWorkspaceForGroup($m, $live));
	}

	// --- groupBuryTarget: session-id precise path, then group_title fallback ---

	public function testTargetPrefersLiveWorkspaceRefWhenSessionIdsMatch(): void
	{
		$m = $this->manifest(['aaa-1', 'bbb-2']);
		$live = $this->live(['aaa-1' => 'workspace:71', 'bbb-2' => 'workspace:71']);
		$this->assertSame('workspace:71', $this->gy->groupBuryTarget($m, $live));
	}

	public function testTargetFallsBackToGroupTitleWhenNoIdsMatch(): void
	{
		// Transcript-mode resurrect relaunches Claude on the exported transcript, minting
		// NEW session ids — so the manifest's recorded ids are not live. The workspace is
		// still there under the group_title resurrect stamped on it; target that.
		$m = $this->manifest(['aaa-1', 'bbb-2']); // group_title = "cli pr 27 - conceptual guides"
		$live = $this->live(['fresh-x' => 'workspace:71', 'fresh-y' => 'workspace:71']);
		$this->assertSame('cli pr 27 - conceptual guides', $this->gy->groupBuryTarget($m, $live));
	}

	public function testTargetIsNullWhenNoIdsMatchAndNoTitle(): void
	{
		$m = ['group_id' => 'deadbeef', 'group_title' => '', 'layout' => [['claude_session_id' => 'aaa-1']]];
		$this->assertNull($this->gy->groupBuryTarget($m, $this->live(['other' => 'workspace:1'])));
	}
}
