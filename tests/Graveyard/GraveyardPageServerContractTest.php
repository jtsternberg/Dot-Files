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

	/**
	 * A uuid, unlike the two ids above: codexRolloutPathFor() only globs for a
	 * uuid-shaped id, so a live-rollout test cannot use a readable fixture name.
	 */
	private const LIVE_CODEX_ID = '99999999-9999-4999-8999-999999999999';

	private ?string $realHome = null;

	protected function tearDown(): void
	{
		putenv('CODEX_SESSIONS_DIR');
		if ($this->realHome !== null) { putenv('HOME=' . $this->realHome); }
		parent::tearDown();
	}

	private function routerGraveyard(): Graveyard
	{
		// EXACTLY what bin/graveyard_router.php builds: no transport, and no live
		// artifact reads either. The second half is not decoration — see
		// Helpers\NullAgentArtifacts, and the live-rollout test below.
		return new Graveyard(
			$this->cli,
			new \JT\Transport\NullTransport($this->cli),
			new \JT\Helpers\NullAgentArtifacts($this->cli)
		);
	}

	/**
	 * Write a LIVE codex rollout for a session, the way a running session would.
	 *
	 * CODEX_SESSIONS_DIR keeps it inside the test's temp root, so this can never
	 * find — or be confused with — a real rollout on this machine.
	 */
	private function seedLiveRollout(string $sessionId): string
	{
		$dir = $this->graveyardRoot . '/codex-sessions/2026/07/30';
		mkdir($dir, 0777, true);
		putenv('CODEX_SESSIONS_DIR=' . $this->graveyardRoot . '/codex-sessions');
		$path = $dir . "/rollout-2026-07-30T00-00-00-{$sessionId}.jsonl";
		file_put_contents($path, json_encode([
			'timestamp' => '2026-07-30T00:00:03.000Z', 'type' => 'response_item',
			'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'LIVE body, still running']]],
		]) . "\n");
		return $path;
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
		$this->seedArchivedRollout($gy, self::CODEX_ID);
	}

	private function seedArchivedRollout(Graveyard $gy, string $sessionId): void
	{
		$gy->upsertIndex($this->tomb($sessionId, '/page-contract', 'codex'));

		$rollout = [
			['timestamp' => '2026-07-30T00:00:00.000Z', 'type' => 'session_meta', 'payload' => ['session_id' => $sessionId, 'cwd' => '/tmp/dotfiles']],
			['timestamp' => '2026-07-30T00:00:01.000Z', 'type' => 'response_item', 'payload' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => '/page-contract']]]],
			['timestamp' => '2026-07-30T00:00:02.000Z', 'type' => 'response_item', 'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Codex archive body']]]],
		];
		$path = $gy->codexRolloutArchivePath($sessionId);
		mkdir(dirname($path), 0777, true);
		file_put_contents($path, implode("\n", array_map(json_encode(...), $rollout)) . "\n");
	}

	/**
	 * The page server must not read a session that is still running (dotfiles-dnc).
	 *
	 * Live-first is right for the CLI — bury derives a summary while the session is
	 * alive, and the live rollout has the newest turns — and wrong for a request
	 * handler, which renders the archive. Asserted against the CLI's own answer in the
	 * same test so the two cannot be conflated: same store, same session, and the two
	 * configurations must resolve to different files.
	 */
	public function testThePageServerCannotReachALiveRollout(): void
	{
		$gy = $this->routerGraveyard();
		$this->seedStore($gy);
		$this->seedArchivedRollout($gy, self::LIVE_CODEX_ID);
		$live = $this->seedLiveRollout(self::LIVE_CODEX_ID);

		$this->assertSame($live, $this->gy->codexRolloutReadPath(self::LIVE_CODEX_ID), 'the CLI reads live-first');
		$this->assertSame(
			$gy->codexRolloutArchivePath(self::LIVE_CODEX_ID),
			$gy->codexRolloutReadPath(self::LIVE_CODEX_ID),
			'the page server reads the archive even while the live rollout exists'
		);

		// …and the rendered payload is the archived text, not the live one.
		$js = $gy->renderTranscriptJs(self::LIVE_CODEX_ID);
		$this->assertStringContainsString('Codex archive body', $js);
		$this->assertStringNotContainsString('LIVE body, still running', $js);
	}

	/**
	 * All four live reads, each asserted against what the REAL reader answers for the
	 * same session — otherwise "returns null" proves nothing, because a real reader
	 * returns null for a session that simply isn't there.
	 */
	public function testTheNullArtifactReaderAnswersEveryLiveReadEmpty(): void
	{
		$null = new \JT\Helpers\NullAgentArtifacts($this->cli);
		$real = new \JT\Helpers\AgentArtifacts($this->cli);
		$this->seedLiveRollout(self::LIVE_CODEX_ID);
		$this->seedLiveClaudeTranscript(self::CLAUDE_ID, '/tmp/dotfiles');

		$this->assertNotNull($real->codexRolloutPathFor(self::LIVE_CODEX_ID));
		$this->assertNull($null->codexRolloutPathFor(self::LIVE_CODEX_ID));

		$this->assertNotSame('', $real->jsonlPathFor(self::CLAUDE_ID, '/tmp/dotfiles'));
		$this->assertSame('', $null->jsonlPathFor(self::CLAUDE_ID, '/tmp/dotfiles'));

		$this->assertSame('opus', $real->readSessionJsonl(self::CLAUDE_ID, '/tmp/dotfiles')['model']);
		$this->assertSame(['permission_mode' => null, 'model' => null], $null->readSessionJsonl(self::CLAUDE_ID, '/tmp/dotfiles'));

		$this->assertNotNull($real->lastRealActivity(self::CLAUDE_ID, '/tmp/dotfiles'));
		$this->assertNull($null->lastRealActivity(self::CLAUDE_ID, '/tmp/dotfiles'));
	}

	/**
	 * A live Claude JSONL, where jsonlPathFor() looks for one.
	 *
	 * That path is built from `~/.claude` with no env override, so HOME is repointed
	 * at the test's temp root for the duration — nothing may write into, or be
	 * answered by, JT's real ~/.claude.
	 */
	private function seedLiveClaudeTranscript(string $sessionId, string $cwd): void
	{
		$this->realHome = (string) getenv('HOME');
		putenv('HOME=' . $this->graveyardRoot . '/home');
		$dir = $this->graveyardRoot . '/home/.claude/projects/' . (new \JT\Helpers\AgentArtifacts($this->cli))->encodeProjectKey($cwd);
		mkdir($dir, 0777, true);
		file_put_contents($dir . '/' . $sessionId . '.jsonl', json_encode([
			'type' => 'assistant', 'timestamp' => '2026-07-30T00:00:05.000Z',
			'message' => ['model' => 'opus', 'content' => [['type' => 'text', 'text' => 'still running']]],
		]) . "\n");
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
