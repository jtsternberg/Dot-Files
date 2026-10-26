<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Tests\TestCase;
use JT\Tests\Transport\FakeTransport;

/**
 * Resurrect puts back a title JT set by hand — a renamed cmux tab, a labelled herdr
 * pane — and ONLY that (dotfiles-76xl). Renaming a tab pins a static title over the
 * agent's own terminal title, and with it the ◑/✳/⏺ activity glyph, so restoring
 * Claude's auto title would freeze every resurrected tab.
 */
final class GraveyardPinnedTitleTest extends TestCase
{
	private const SID = 'aaaa1111-2222-3333-4444-555555555555';

	private function launcher(FakeTransport $transport): Graveyard
	{
		return new class ($this->cli, $transport) extends Graveyard {
			public function launch(array $t, string $surf, string $ws): string {
				return $this->launchSessionIntoSurface($t, $surf, $ws, false);
			}
		};
	}

	public function testTombstoneRecordsThePinnedTitle(): void
	{
		$tomb = $this->gy->buildTombstone(
			['session_id' => self::SID, 'cwd' => '/x', 'pinned_title' => 'agent overseer'],
			['workspace_title' => 'w', 'tab_title' => 'agent overseer'], 'sum', '2026-10-26T00:00:00Z'
		);

		$this->assertSame('agent overseer', $tomb['pinned_title']);
	}

	/** Additive schema: an unrenamed tab's tombstone keeps the shape it always had. */
	public function testTombstoneOmitsThePinnedTitleWhenNothingWasPinned(): void
	{
		foreach ([null, ''] as $none) {
			$tomb = $this->gy->buildTombstone(
				['session_id' => self::SID, 'cwd' => '/x', 'pinned_title' => $none],
				['workspace_title' => 'w', 'tab_title' => '✳ Claude Code'], 'sum', '2026-10-26T00:00:00Z'
			);
			$this->assertArrayNotHasKey('pinned_title', $tomb);
		}
	}

	/** A session bound by the /status probe is built from its surface row, not a live row. */
	public function testAProbedRowCarriesItsSurfacesPinnedTitle(): void
	{
		$row = $this->gy->synthesizeProbedRow('surface:4', 'workspace:2', ['session_id' => self::SID],
			[['session_id' => self::SID, 'cwd' => '/x']],
			['title' => 'agent overseer', 'pinned_title' => 'agent overseer']);

		$this->assertSame('agent overseer', $row['pinned_title']);
	}

	public function testResurrectRenamesTheSurfaceBeforeLaunchingIntoIt(): void
	{
		$transport = new FakeTransport('fake');
		$this->launcher($transport)->launch(['session_id' => self::SID, 'cwd' => '/x', 'pinned_title' => 'agent overseer'], 'surf-1', 'ws-1');

		$this->assertSame(['renameSurface', ['ws-1', 'surf-1', 'agent overseer']], $transport->calls[0]);
		$this->assertSame(['renameSurface', 'sendText', 'sendKey'], $transport->calledMethods());
	}

	public function testResurrectLeavesAnUnpinnedTitleToTheAgent(): void
	{
		$transport = new FakeTransport('fake');
		$this->launcher($transport)->launch(['session_id' => self::SID, 'cwd' => '/x', 'tab_title' => '✳ Claude Code'], 'surf-1', 'ws-1');

		$this->assertNotContains('renameSurface', $transport->calledMethods());
	}

	/** A lost name is cosmetic; the session itself must still come back. */
	public function testAFailedRenameStillLaunchesTheSession(): void
	{
		$transport = new FakeTransport('fake');
		$transport->renameOk = false;
		$this->launcher($transport)->launch(['session_id' => self::SID, 'cwd' => '/x', 'pinned_title' => 'agent overseer'], 'surf-1', 'ws-1');

		$this->assertSame(['renameSurface', 'sendText', 'sendKey'], $transport->calledMethods());
	}
}
