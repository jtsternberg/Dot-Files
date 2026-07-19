<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-vn5.1 / dotfiles-06t — the loopback server's JSON API
 * (POST /api/rename, /api/delete), exercised via Graveyard::handleApi()
 * directly (no socket, no `php -S`). Reuses the same rename/delete/purge core
 * as the CLI verbs. Serve-only: the page is re-rendered fresh per request by
 * the router, so handleApi() just mutates the store and returns JSON — it no
 * longer regenerates any file.
 */
final class GraveyardServeApiTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	protected function makeRoot(array $tombs): string
	{
		$root = sys_get_temp_dir() . '/gy-serve-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);
		$gy = new Graveyard($this->cli, $this->cmux);
		foreach ($tombs as $t) { $gy->upsertIndex($t); }
		return $root;
	}

	protected function tomb(string $sid, string $summary, ?string $gid = null, ?string $gtitle = null, ?int $pos = null): array
	{
		$t = [
			'session_id' => $sid, 'workspace_title' => 'WS', 'tab_title' => 'Tab',
			'cwd' => '/home/x/proj', 'summary' => $summary, 'model' => 'opus',
			'buried_at' => '2026-07-15T10:00:00Z', 'last_active' => '2026-07-14T09:59:00Z',
		];
		if ($gid !== null) { $t['group_id'] = $gid; $t['group_title'] = $gtitle; $t['group_pos'] = $pos; }
		return $t;
	}

	public function testRenameSessionViaApi(): void
	{
		$this->makeRoot([$this->tomb('sess1234-full', 'original summary')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$res = $gy->handleApi('POST', '/api/rename', ['scope' => 'session', 'id' => 'sess1234-full', 'name' => 'New Name']);
		$this->assertSame(200, $res['status']);
		$this->assertTrue($res['body']['ok']);

		$tomb = $gy->readIndex()['tombstones'][0];
		$this->assertSame('New Name', $tomb['name']);
	}

	public function testRenameUnknownSessionReturns404(): void
	{
		$this->makeRoot([$this->tomb('sess1234-full', 'original summary')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$res = $gy->handleApi('POST', '/api/rename', ['scope' => 'session', 'id' => 'ghost', 'name' => 'x']);
		$this->assertSame(404, $res['status']);
		$this->assertFalse($res['body']['ok']);
	}

	public function testRenameEmptyNameRejected(): void
	{
		$this->makeRoot([$this->tomb('sess1234-full', 'original summary')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$res = $gy->handleApi('POST', '/api/rename', ['scope' => 'session', 'id' => 'sess1234-full', 'name' => '   ']);
		$this->assertSame(400, $res['status']);
		$this->assertFalse($res['body']['ok']);
	}

	public function testDeleteSessionViaApi(): void
	{
		$root = $this->makeRoot([
			$this->tomb('doomed11-full', 'delete me'),
			$this->tomb('keeper22-full', 'keep me'),
		]);
		@mkdir($root . '/sessions/doomed11-full', 0755, true);
		file_put_contents($root . '/sessions/doomed11-full/transcript.txt', 'body');

		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->handleApi('POST', '/api/delete', ['scope' => 'session', 'id' => 'doomed11-full']);
		$this->assertSame(200, $res['status']);
		$this->assertTrue($res['body']['ok']);

		$sids = array_column($gy->readIndex()['tombstones'], 'session_id');
		$this->assertSame(['keeper22-full'], $sids);
		$this->assertDirectoryDoesNotExist($root . '/sessions/doomed11-full');
	}

	public function testRenameGroupViaApi(): void
	{
		$gid = 'grp-uuid-abc';
		$root = $this->makeRoot([
			$this->tomb('m1111111-full', 'one', $gid, 'Old Name', 0),
			$this->tomb('m2222222-full', 'two', $gid, 'Old Name', 1),
		]);
		@mkdir($root . '/workspaces/' . $gid, 0755, true);
		file_put_contents($root . '/workspaces/' . $gid . '/manifest.json', json_encode([
			'group_id' => $gid, 'group_title' => 'Old Name', 'layout' => [],
		]));

		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->handleApi('POST', '/api/rename', ['scope' => 'group', 'id' => $gid, 'name' => 'Fresh Name']);
		$this->assertSame(200, $res['status']);
		$this->assertTrue($res['body']['ok']);

		foreach ($gy->readIndex()['tombstones'] as $t) {
			$this->assertSame('Fresh Name', $t['group_title']);
		}
	}

	public function testDeleteGroupViaApi(): void
	{
		$gid = 'doomedgrp-uuid';
		$root = $this->makeRoot([
			$this->tomb('gm111111-full', 'member one', $gid, 'Doomed', 0),
			$this->tomb('gm222222-full', 'member two', $gid, 'Doomed', 1),
			$this->tomb('safe0000-full', 'unrelated'),
		]);
		@mkdir($root . '/workspaces/' . $gid, 0755, true);
		file_put_contents($root . '/workspaces/' . $gid . '/manifest.json', json_encode([
			'group_id' => $gid, 'group_title' => 'Doomed', 'layout' => [],
		]));

		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->handleApi('POST', '/api/delete', ['scope' => 'group', 'id' => $gid]);
		$this->assertSame(200, $res['status']);
		$this->assertTrue($res['body']['ok']);

		$sids = array_column($gy->readIndex()['tombstones'], 'session_id');
		$this->assertSame(['safe0000-full'], $sids);
		$this->assertDirectoryDoesNotExist($root . '/workspaces/' . $gid);
	}

	public function testInvalidScopeRejected(): void
	{
		$this->makeRoot([]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->handleApi('POST', '/api/delete', ['scope' => 'bogus', 'id' => 'x']);
		$this->assertSame(400, $res['status']);
		$this->assertFalse($res['body']['ok']);
	}

	public function testGetMethodNotAllowed(): void
	{
		$this->makeRoot([]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->handleApi('GET', '/api/rename', []);
		$this->assertSame(405, $res['status']);
	}

	public function testUnknownPathReturns404(): void
	{
		$this->makeRoot([]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->handleApi('POST', '/api/nope', []);
		$this->assertSame(404, $res['status']);
	}

	/**
	 * Regression: handleApi() must never print to stdout — under `php -S` this
	 * is a live HTTP request, and any leaked text lands ahead of the JSON body
	 * the client's response.json() parses, silently breaking the live UI.
	 */
	public function testMutationsDoNotLeakOutputIntoTheResponse(): void
	{
		$this->makeRoot([$this->tomb('sess1234-full', 'original summary')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		ob_start();
		$gy->handleApi('POST', '/api/rename', ['scope' => 'session', 'id' => 'sess1234-full', 'name' => 'New Name']);
		$leaked = ob_get_clean();

		$this->assertSame('', $leaked, 'handleApi() must not print anything (it would corrupt the HTTP JSON response)');
	}
}
