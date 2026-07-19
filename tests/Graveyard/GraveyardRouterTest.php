<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-06t.1 — serve-only render path. The loopback server (via
 * bin/graveyard_router.php) renders the page FRESH from the store on every
 * request instead of serving a static index.html snapshot. These tests
 * exercise the render helpers the router calls (renderStorePageHtml /
 * renderTranscriptJs) directly — no socket, no `php -S` — including the
 * regression the whole refactor targets: a session buried after the server
 * started must appear on the next render.
 */
final class GraveyardRouterTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	protected function makeRoot(array $tombs): string
	{
		$root = sys_get_temp_dir() . '/gy-router-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);
		$gy = new Graveyard($this->cli, $this->cmux);
		foreach ($tombs as $t) { $gy->upsertIndex($t); }
		return $root;
	}

	protected function tomb(string $sid, string $summary, string $buried = '2026-07-15T10:00:00Z'): array
	{
		return [
			'session_id' => $sid, 'workspace_title' => 'WS', 'tab_title' => 'Tab',
			'cwd' => '/home/x/proj', 'summary' => $summary, 'model' => 'opus',
			'buried_at' => $buried, 'last_active' => '2026-07-14T09:59:00Z',
		];
	}

	public function testRenderStorePageHtmlReflectsCurrentStore(): void
	{
		$this->makeRoot([$this->tomb('first111-full', 'first session')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$html = $gy->renderStorePageHtml();
		$this->assertStringContainsString('first session', $html);
		$this->assertStringContainsString('x-data="graveyard()"', $html); // it's the real page
	}

	/**
	 * THE bug this refactor fixes: bury a new session while the "server" is up,
	 * then render again — the new session must appear (a static snapshot would
	 * not show it).
	 */
	public function testFreshRenderShowsSessionsBuriedAfterFirstRender(): void
	{
		$this->makeRoot([$this->tomb('old00000-full', 'old session')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$before = $gy->renderStorePageHtml();
		$this->assertStringContainsString('old session', $before);
		$this->assertStringNotContainsString('brand new session', $before);

		// A fresh bury lands in the store after the first render…
		$gy->upsertIndex($this->tomb('new99999-full', 'brand new session', '2026-07-16T10:00:00Z'));

		// …and the very next render reflects it.
		$after = $gy->renderStorePageHtml();
		$this->assertStringContainsString('brand new session', $after);
		$this->assertStringContainsString('old session', $after);
	}

	public function testRenderTranscriptJsReadsFreshFromDisk(): void
	{
		$root = $this->makeRoot([$this->tomb('trans111-full', 'has transcript')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$this->assertNull($gy->renderTranscriptJs('trans111-full')); // none archived yet
		$this->assertNull($gy->renderTranscriptJs('nope'));           // unknown id

		@mkdir($root . '/sessions/trans111-full', 0755, true);
		file_put_contents($root . '/sessions/trans111-full/transcript.txt', 'the exhumed body');

		$js = $gy->renderTranscriptJs('trans111-full');
		$this->assertNotNull($js);
		$this->assertStringContainsString('window.GYT', $js);
		$this->assertStringContainsString('the exhumed body', $js);
	}
}
