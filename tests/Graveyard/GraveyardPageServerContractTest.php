<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Tests\TestCase;

/**
 * The page router is the one caller that deliberately has no cmux. Exercise its
 * four endpoint-facing methods in that exact shape, rather than through $this->gy.
 */
final class GraveyardPageServerContractTest extends TestCase
{
	private const CLAUDE_ID = 'page-claude-0001';
	private const CODEX_ID = 'page-codex-0002';

	private function routerGraveyard(): Graveyard
	{
		return new Graveyard($this->cli, null);
	}

	private function tomb(string $id, string $summary, string $kind = 'claude'): array
	{
		return [
			'session_id' => $id, 'kind' => $kind, 'workspace_title' => 'dotfiles',
			'tab_title' => 'Terminal', 'cwd' => '/tmp/dotfiles', 'summary' => $summary,
			'model' => 'gpt-5.6-terra', 'buried_at' => '2026-07-30T00:00:00Z',
			'last_active' => '2026-07-29T23:59:00Z',
		];
	}

	private function seedStore(Graveyard $gy): void
	{
		$gy->upsertIndex($this->tomb(self::CLAUDE_ID, 'ordinary archived transcript'));
		$gy->upsertIndex($this->tomb(self::CODEX_ID, '/page-contract', 'codex'));

		$rollout = [
			['timestamp' => '2026-07-30T00:00:00.000Z', 'type' => 'session_meta', 'payload' => ['session_id' => self::CODEX_ID, 'cwd' => '/tmp/dotfiles']],
			['timestamp' => '2026-07-30T00:00:01.000Z', 'type' => 'response_item', 'payload' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => '/page-contract']]]],
			['timestamp' => '2026-07-30T00:00:02.000Z', 'type' => 'response_item', 'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Codex archive body']]]],
		];
		$path = $gy->codexRolloutArchivePath(self::CODEX_ID);
		mkdir(dirname($path), 0777, true);
		file_put_contents($path, implode("\n", array_map(json_encode(...), $rollout)) . "\n");
	}

	public function testRootRenderUsesTheRouterCmuxFreeConfiguration(): void
	{
		$gy = $this->routerGraveyard();
		$this->seedStore($gy);

		$html = $gy->renderStorePageHtml();
		$this->assertStringContainsString('ordinary archived transcript', $html);
		$this->assertStringContainsString('/page-contract', $html);
	}

	public function testPageDataRendersArchivedCodexRolloutsWithoutCmux(): void
	{
		$gy = $this->routerGraveyard();
		$this->seedStore($gy);

		$js = $gy->renderTranscriptJs(self::CODEX_ID);
		$this->assertNotNull($js);
		$this->assertStringContainsString('Codex archive body', $js);
		$this->assertNull($gy->renderTranscriptJs('missing-session'));
	}

	public function testRenameApiUsesTheRouterCmuxFreeConfiguration(): void
	{
		$gy = $this->routerGraveyard();
		$this->seedStore($gy);

		$res = $gy->handleApi('POST', '/api/rename', ['scope' => 'session', 'id' => self::CLAUDE_ID, 'name' => 'Renamed from page']);
		$this->assertSame(200, $res['status']);
		$this->assertSame('Renamed from page', $gy->sessionMeta(self::CLAUDE_ID)['name']);
	}

	public function testDeleteApiUsesTheRouterCmuxFreeConfiguration(): void
	{
		$gy = $this->routerGraveyard();
		$this->seedStore($gy);

		$res = $gy->handleApi('POST', '/api/delete', ['scope' => 'session', 'id' => self::CLAUDE_ID]);
		$this->assertSame(200, $res['status']);
		$this->assertNull($gy->sessionMeta(self::CLAUDE_ID));
	}
}
