<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-gln — fuzzy resurrect by workspace/session name.
 *
 * Behavior contract: resurrect <fuzzy> resolves a buried tombstone by
 *   1. exact session-id (existing behavior, unchanged)
 *   2. unique session-id prefix (existing behavior)
 *   3. NEW: workspace/tab title substring (case-insensitive), when exactly one match
 *      — ambiguous title matches are rejected, NOT auto-picked.
 */
final class GraveyardFuzzyResurrectTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	protected function makeRoot(array $tombs): string
	{
		$root = sys_get_temp_dir() . '/gy-fuzzy-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);
		$gy = new Graveyard($this->cli, $this->cmux);
		foreach ($tombs as $t) { $gy->upsertIndex($t); }
		return $root;
	}

	protected function makeGy(): Graveyard
	{
		return new Graveyard($this->cli, $this->cmux);
	}

	public function testResolvesExactSessionIdUnchanged(): void
	{
		$this->makeRoot([
			['session_id' => 'abc12345-aaaa', 'workspace_title' => 'Tailscale', 'tab_title' => 'net'],
			['session_id' => 'def67890-bbbb', 'workspace_title' => 'Blog', 'tab_title' => 'post'],
		]);
		$this->assertSame('abc12345-aaaa', $this->makeGy()->findTombstone('abc12345-aaaa')['session_id']);
	}

	public function testResolvesUniquePrefixUnchanged(): void
	{
		$this->makeRoot([
			['session_id' => 'abc12345-aaaa', 'workspace_title' => 'Tailscale'],
			['session_id' => 'def67890-bbbb', 'workspace_title' => 'Blog'],
		]);
		$this->assertSame('def67890-bbbb', $this->makeGy()->findTombstone('def6')['session_id']);
	}

	public function testResolvesUniqueTitleSubstring(): void
	{
		$this->makeRoot([
			['session_id' => 'aaa11111-0000', 'workspace_title' => 'tailscale setup', 'tab_title' => 'net', 'buried_at' => '2026-07-17'],
			['session_id' => 'bbb22222-0000', 'workspace_title' => 'blog post', 'tab_title' => 'write', 'buried_at' => '2026-07-16'],
		]);
		// "tailscale" only matches the first tombstone's workspace_title.
		$hit = $this->makeGy()->findTombstone('Tailscale');
		$this->assertNotNull($hit);
		$this->assertSame('aaa11111-0000', $hit['session_id']);
	}

	public function testTitleMatchIsCaseInsensitiveAcrossFields(): void
	{
		$this->makeRoot([
			['session_id' => 'aaa11111-0000', 'workspace_title' => 'x', 'tab_title' => 'Review Property Spec', 'buried_at' => '2026-07-17'],
			['session_id' => 'bbb22222-0000', 'workspace_title' => 'y', 'tab_title' => 'other', 'buried_at' => '2026-07-16'],
		]);
		$this->assertSame('aaa11111-0000', $this->makeGy()->findTombstone('property spec')['session_id']);
	}

	public function testAmbiguousTitleReturnsNull(): void
	{
		$this->makeRoot([
			['session_id' => 'aaa11111-0000', 'workspace_title' => 'tailscale setup', 'buried_at' => '2026-07-17'],
			['session_id' => 'bbb22222-0000', 'workspace_title' => 'tailscale notes', 'buried_at' => '2026-07-16'],
		]);
		// "tailscale" matches two workspaces => ambiguous => reject (null), not auto-pick.
		$this->assertNull($this->makeGy()->findTombstone('tailscale'));
	}

	public function testAmbiguousResultExposesCandidates(): void
	{
		$this->makeRoot([
			['session_id' => 'aaa11111-0000', 'workspace_title' => 'tailscale setup', 'buried_at' => '2026-07-17'],
			['session_id' => 'bbb22222-0000', 'workspace_title' => 'tailscale notes', 'buried_at' => '2026-07-16'],
		]);
		$res = $this->makeGy()->resolveTombstoneFuzzy('tailscale');   // ambiguous
		$this->assertTrue($res['ambiguous']);
		$this->assertNull($res['match']);
		$this->assertSame(['aaa11111-0000', 'bbb22222-0000'], array_column($res['candidates'], 'session_id'));
	}

	public function testNoMatchReturnsNull(): void
	{
		$this->makeRoot([
			['session_id' => 'aaa11111-0000', 'workspace_title' => 'tailscale'],
		]);
		$res = $this->makeGy()->resolveTombstoneFuzzy('zzz-nope');
		$this->assertFalse($res['ambiguous']);
		$this->assertNull($res['match']);
		$this->assertSame([], $res['candidates']);
	}
}
