<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;

/**
 * dotfiles-55n.7 / .8 — `graveyard rename` and `graveyard delete` verbs.
 *
 *  rename <id> "<name>"            → stamps a custom `name` on the tombstone;
 *                                    titleizeSummary + resurrect matching prefer it
 *  rename --workspace <g> "<name>" → retitles every group member + the manifest
 *  delete <id>                     → removes the index entry + sessions/<id>/ + page-data/<id>.js
 *  delete --workspace <g>          → removes every member + workspaces/<g>/
 */
final class GraveyardRenameDeleteTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	protected function makeRoot(array $tombs): string
	{
		$root = sys_get_temp_dir() . '/gy-rd-' . getmypid() . '-' . uniqid();
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

	public function testSetSessionNameStampsCustomName(): void
	{
		$this->makeRoot([$this->tomb('sess1234-full', 'original summary')]);
		$gy = new Graveyard($this->cli, $this->cmux);

		$this->assertTrue($gy->setSessionName('sess1234-full', 'My Renamed Session'));
		$this->assertFalse($gy->setSessionName('nope-nope-nope', 'x')); // unknown id

		$tomb = $gy->readIndex()['tombstones'][0];
		$this->assertSame('My Renamed Session', $tomb['name']);
	}

	public function testTitleizePrefersCustomName(): void
	{
		$gy = new Graveyard($this->cli, $this->cmux);
		$t = $this->tomb('t', 'the summary');
		$this->assertSame('the summary', $gy->titleizeSummary($t)); // baseline
		$t['name'] = 'Chosen Name';
		$this->assertSame('Chosen Name', $gy->titleizeSummary($t)); // custom name wins
	}

	public function testResurrectMatchesByCustomName(): void
	{
		$this->makeRoot([$this->tomb('abcd1234-full', 'boring summary')]);
		$gy = new Graveyard($this->cli, $this->cmux);
		$gy->setSessionName('abcd1234-full', 'Zephyr Project');

		// resurrect resolves by the new name via the fuzzy resolver
		$match = $gy->resolveTombstoneFuzzy('zephyr')['match'];
		$this->assertNotNull($match);
		$this->assertSame('abcd1234-full', $match['session_id']);
	}

	public function testSetGroupNameRetitlesMembersAndManifest(): void
	{
		$gid = 'grp-uuid-abc';
		$root = $this->makeRoot([
			$this->tomb('m1111111-full', 'one', $gid, 'Old Name', 0),
			$this->tomb('m2222222-full', 'two', $gid, 'Old Name', 1),
			$this->tomb('loose000-full', 'unrelated'),
		]);
		@mkdir($root . '/workspaces/' . $gid, 0755, true);
		file_put_contents($root . '/workspaces/' . $gid . '/manifest.json', json_encode([
			'group_id' => $gid, 'group_title' => 'Old Name', 'layout' => [],
		]));

		$gy = new Graveyard($this->cli, $this->cmux);
		$this->assertSame(2, $gy->setGroupName($gid, 'Fresh Name')); // 2 members retitled

		$tombs = $gy->readIndex()['tombstones'];
		foreach ($tombs as $t) {
			if (($t['group_id'] ?? null) === $gid) { $this->assertSame('Fresh Name', $t['group_title']); }
			else { $this->assertArrayNotHasKey('group_title', $t); } // loose one untouched
		}
		$m = json_decode((string) file_get_contents($root . '/workspaces/' . $gid . '/manifest.json'), true);
		$this->assertSame('Fresh Name', $m['group_title']);
	}

	public function testPurgeSessionRemovesIndexAndArtifacts(): void
	{
		$root = $this->makeRoot([
			$this->tomb('doomed11-full', 'delete me'),
			$this->tomb('keeper22-full', 'keep me'),
		]);
		@mkdir($root . '/sessions/doomed11-full', 0755, true);
		file_put_contents($root . '/sessions/doomed11-full/transcript.txt', 'body');
		@mkdir($root . '/page-data', 0755, true);
		file_put_contents($root . '/page-data/doomed11-full.js', 'window.GYT={};');

		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->purgeSession('doomed11-full');

		$this->assertTrue($res['index']);
		$this->assertTrue($res['dir']);
		$this->assertTrue($res['js']);
		$this->assertDirectoryDoesNotExist($root . '/sessions/doomed11-full');
		$this->assertFileDoesNotExist($root . '/page-data/doomed11-full.js');

		$sids = array_column($gy->readIndex()['tombstones'], 'session_id');
		$this->assertSame(['keeper22-full'], $sids); // only the keeper remains

		// purging an unknown id is a harmless no-op
		$none = $gy->purgeSession('ghost000-full');
		$this->assertFalse($none['index']);
	}

	public function testPurgeGroupRemovesAllMembersAndManifest(): void
	{
		$gid = 'doomedgrp-uuid';
		$root = $this->makeRoot([
			$this->tomb('gm111111-full', 'member one', $gid, 'Doomed', 0),
			$this->tomb('gm222222-full', 'member two', $gid, 'Doomed', 1),
			$this->tomb('safe0000-full', 'unrelated'),
		]);
		foreach (['gm111111-full', 'gm222222-full'] as $sid) {
			@mkdir($root . '/sessions/' . $sid, 0755, true);
			file_put_contents($root . '/sessions/' . $sid . '/transcript.txt', 'x');
		}
		@mkdir($root . '/workspaces/' . $gid, 0755, true);
		file_put_contents($root . '/workspaces/' . $gid . '/manifest.json', '{}');

		$gy = new Graveyard($this->cli, $this->cmux);
		$res = $gy->purgeGroup($gid);

		$this->assertSame(2, $res['removed']);
		$this->assertDirectoryDoesNotExist($root . '/sessions/gm111111-full');
		$this->assertDirectoryDoesNotExist($root . '/sessions/gm222222-full');
		$this->assertDirectoryDoesNotExist($root . '/workspaces/' . $gid);

		$sids = array_column($gy->readIndex()['tombstones'], 'session_id');
		$this->assertSame(['safe0000-full'], $sids); // the unrelated session survives
	}
}
