<?php
namespace JT\Tests\Graveyard;

use JT\Tests\TestCase;
use JT\Graveyard;
use JT\Helpers\Cmux;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ported from tests/graveyard/run.php — every assertion that targets a
 * JT\Graveyard method ($gy->...).
 */
final class GraveyardTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
	}

	public function testParseDuration(): void
	{
		$this->assertSame(172800, $this->gy->parseDuration('2d'));
		$this->assertSame(172800, $this->gy->parseDuration('48h'));
		$this->assertSame(5400, $this->gy->parseDuration('90m'));
		$this->assertSame(30, $this->gy->parseDuration('30'));
		$this->expectException(\InvalidArgumentException::class);
		$this->gy->parseDuration('nope');
	}

	public function testUpsertIndexDedupesBySessionId(): void
	{
		$root = sys_get_temp_dir() . '/gy-test-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		@mkdir($root, 0755, true);

		$gy2 = new Graveyard($this->cli, $this->cmux);
		$gy2->upsertIndex(['session_id' => 'x', 'summary' => 'first']);
		$gy2->upsertIndex(['session_id' => 'x', 'summary' => 'second']);
		$gy2->upsertIndex(['session_id' => 'y', 'summary' => 'other']);
		$idx = $gy2->readIndex();

		$this->assertCount(2, $idx['tombstones']);
		$xs = array_values(array_filter($idx['tombstones'], fn($t) => $t['session_id'] === 'x'));
		$this->assertSame('second', $xs[0]['summary']);
	}

	public function testStableGroupIdReusesPriorGroupElseMintsFresh(): void
	{
		$mint = fn() => 'FRESH';

		// Brand-new workspace: no member has a prior tombstone → mint fresh.
		$this->assertSame('FRESH', $this->gy->stableGroupId(['a', 'b'], [], $mint));

		// Common re-bury: all mapped members share one prior group → reuse it (stable).
		$this->assertSame('g1', $this->gy->stableGroupId(['a', 'b'], ['a' => 'g1', 'b' => 'g1'], $mint));

		// A new member joins a resurrected group: surviving members still resolve to g1.
		$this->assertSame('g1', $this->gy->stableGroupId(['a', 'b', 'c'], ['a' => 'g1', 'b' => 'g1'], $mint));

		// Merged from two prior plots → ambiguous → mint fresh.
		$this->assertSame('FRESH', $this->gy->stableGroupId(['a', 'b'], ['a' => 'g1', 'b' => 'g2'], $mint));
	}

	public function testReconcileTombstoneConfig(): void
	{
		// jsonl says yolo + a model; stored had the burial defaults → both corrected.
		[$t, $ch] = $this->gy->reconcileTombstoneConfig(
			['skip_perms' => false, 'model' => null],
			['permission_mode' => 'bypassPermissions', 'model' => 'claude-fable-5']
		);
		$this->assertTrue($t['skip_perms']);
		$this->assertSame('claude-fable-5', $t['model']);
		$this->assertSame([false, true], $ch['skip_perms']);
		$this->assertSame([null, 'claude-fable-5'], $ch['model']);

		// Null jsonl values must NOT downgrade good stored data.
		[$t2, $ch2] = $this->gy->reconcileTombstoneConfig(
			['skip_perms' => true, 'model' => 'opus'],
			['permission_mode' => null, 'model' => null]
		);
		$this->assertSame([], $ch2);
		$this->assertTrue($t2['skip_perms']);
		$this->assertSame('opus', $t2['model']);

		// No drift → no changes.
		[, $ch3] = $this->gy->reconcileTombstoneConfig(
			['skip_perms' => true, 'model' => 'opus'],
			['permission_mode' => 'bypassPermissions', 'model' => 'opus']
		);
		$this->assertSame([], $ch3);
	}

	public function testTranscriptUpToDate(): void
	{
		$root = sys_get_temp_dir() . '/gy-tud-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		$gy  = new Graveyard($this->cli, $this->cmux);
		$cwd = sys_get_temp_dir() . '/gy-tud-cwd-' . getmypid();
		$sid = 'sess-tud-' . getmypid() . '-' . uniqid();

		$jsonl = $this->cmux->jsonlPathFor($sid, $cwd);
		@mkdir(dirname($jsonl), 0755, true);
		$turn = strtotime('2026-07-01T10:00:00Z');
		$writeTurn = fn(int $ts) => file_put_contents($jsonl, json_encode([
			'type' => 'user', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $ts),
		]) . "\n");
		$writeTurn($turn);

		// No transcript on disk yet → must export.
		$this->assertFalse($gy->transcriptUpToDate($sid, $cwd));

		// Transcript written after the last genuine turn → already current, skip.
		$tp = $gy->transcriptPath($sid);
		@mkdir(dirname($tp), 0755, true);
		file_put_contents($tp, 'rendered');
		touch($tp, $turn + 100);
		$this->assertTrue($gy->transcriptUpToDate($sid, $cwd));

		// A newer genuine turn lands → stale, must re-export.
		$writeTurn($turn + 500);
		$this->assertFalse($gy->transcriptUpToDate($sid, $cwd));

		@unlink($jsonl);
		@unlink($tp);
		putenv('GRAVEYARD_ROOT');
	}

	public function testOrphanedManifestGroupsAndPrune(): void
	{
		$root = sys_get_temp_dir() . '/gy-orph-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		$gy = new Graveyard($this->cli, $this->cmux);

		// Two group manifests; only "live" is referenced by a tombstone.
		foreach (['live', 'orphan'] as $gid) {
			@mkdir("$root/workspaces/$gid", 0755, true);
			file_put_contents("$root/workspaces/$gid/manifest.json", json_encode(['group_id' => $gid, 'layout' => []]));
		}
		$gy->upsertIndex(['session_id' => 's1', 'group_id' => 'live']);

		$this->assertSame(['orphan'], $gy->orphanedManifestGroups());
		$this->assertSame(['orphan'], $gy->pruneOrphanedManifests());
		// Live manifest survives; orphan is gone; prune is idempotent.
		$this->assertFileExists("$root/workspaces/live/manifest.json");
		$this->assertFileDoesNotExist("$root/workspaces/orphan/manifest.json");
		$this->assertSame([], $gy->orphanedManifestGroups());

		putenv('GRAVEYARD_ROOT');
	}

	public function testIsBusy(): void
	{
		$this->assertTrue($this->gy->isBusy(5, 15, ''));
		$this->assertFalse($this->gy->isBusy(30, 15, ''));
		$this->assertTrue($this->gy->isBusy(30, 15, '... (esc to interrupt)'));
		$this->assertTrue($this->gy->isBusy(30, 15, '✳ Cogitating… (1.2k tokens)'));
		$this->assertFalse($this->gy->isBusy(30, 15, 'justin@mac ~/.dotfiles %'));
	}

	public function testFilterSelf(): void
	{
		$sessions = [
			['surface_ref' => 'surface:1', 'session_id' => 'self-sess'],
			['surface_ref' => 'surface:2', 'session_id' => 'other'],
		];
		$kept = $this->gy->filterSelf($sessions, 'surface:1', 'self-sess');
		$this->assertCount(1, $kept);
		$this->assertSame('other', $kept[0]['session_id']);

		$sess2 = [
			['surface_ref' => 'surface:9', 'surface_id' => 'uuid-9', 'session_id' => 'my-own-sess'],
			['surface_ref' => 'surface:8', 'surface_id' => 'uuid-8', 'session_id' => 'keep-me'],
		];
		$kept2 = $this->gy->filterSelf($sess2, null, 'my-own-sess');
		$this->assertCount(1, $kept2);
		$this->assertSame('keep-me', $kept2[0]['session_id']);
	}

	public function testDedupBySessionId(): void
	{
		$dupRows = [
			['session_id' => 'a', 'tab_title' => 'first'],
			['session_id' => 'a', 'tab_title' => 'dupe'],
			['session_id' => 'b', 'tab_title' => 'x'],
		];
		$deduped = $this->gy->dedupBySessionId($dupRows);
		$this->assertCount(2, $deduped);
		$this->assertSame('first', $deduped[0]['tab_title']);
	}

	public function testFormatCandidatePorcelain(): void
	{
		$idleRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => false, 'targetable' => true, 'reason' => '', 'workspace_title' => 'proj', 'cwd' => '/x'];
		$this->assertSame("abc\t3600\tidle\ttargetable\tproj\t/x\t", $this->gy->formatCandidatePorcelain($idleRow));
		$busyRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => true, 'targetable' => true, 'reason' => '', 'workspace_title' => 'proj', 'cwd' => '/x'];
		$this->assertSame("abc\t3600\tbusy\ttargetable\tproj\t/x\t", $this->gy->formatCandidatePorcelain($busyRow));
		$untRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => false, 'targetable' => false, 'reason' => 'collision', 'workspace_title' => 'proj', 'cwd' => '/x'];
		$this->assertSame("abc\t3600\tidle\tUNTARGETABLE\tproj\t/x\tcollision", $this->gy->formatCandidatePorcelain($untRow));
	}

	public function testBuryIdsEmptyIsNoOp(): void
	{
		$this->gy->buryIds([], false);
		$this->gy->buryIds([], false, true);
		$this->assertTrue(true); // reached here without throwing
	}

	public function testMatchIdentifier(): void
	{
		$mrows = [
			['surface_ref' => 'surface:5', 'surface_id' => 'UUID-5', 'session_id' => 'aaa111', 'workspace_title' => 'backend api', 'tab_title' => 'fix bug'],
			['surface_ref' => 'surface:6', 'surface_id' => 'UUID-6', 'session_id' => 'aaa222', 'workspace_title' => 'frontend', 'tab_title' => 'backend notes'],
			['surface_ref' => 'surface:7', 'surface_id' => 'UUID-7', 'session_id' => 'bbb333', 'workspace_title' => 'docs', 'tab_title' => 'x'],
		];

		$m1 = $this->gy->matchIdentifier($mrows, 'surface:6');
		$this->assertCount(1, $m1);
		$this->assertSame('aaa222', $m1[0]['session_id']);

		$m2 = $this->gy->matchIdentifier($mrows, 'UUID-7');
		$this->assertCount(1, $m2);
		$this->assertSame('bbb333', $m2[0]['session_id']);

		$this->assertCount(2, $this->gy->matchIdentifier($mrows, 'aaa'));

		$m4 = $this->gy->matchIdentifier($mrows, 'aaa111');
		$this->assertCount(1, $m4);
		$this->assertSame('aaa111', $m4[0]['session_id']);

		$this->assertCount(2, $this->gy->matchIdentifier($mrows, 'backend'));
		$this->assertSame([], $this->gy->matchIdentifier($mrows, 'nope'));
	}

	/**
	 * Same auto-title flaw as resolveWorkspaceNode: an exact normalized title/tab match
	 * must win over rows where the query is only a substring (e.g. the caller's own
	 * workspace auto-titled after the running command).
	 */
	public function testMatchIdentifierExactTitleBeatsSubstring(): void
	{
		$query = 'wpforms migration script issues';
		$mrows = [
			['surface_ref' => 'surface:5', 'session_id' => 'aaa111', 'workspace_title' => $query, 'tab_title' => 'x'],
			['surface_ref' => 'surface:6', 'session_id' => 'aaa222', 'workspace_title' => "graveyard bury --workspace '{$query}' -y", 'tab_title' => 'y'],
		];
		$m = $this->gy->matchIdentifier($mrows, $query);
		$this->assertCount(1, $m);
		$this->assertSame('aaa111', $m[0]['session_id']);

		// Glyph-prefixed exact title still wins, case-insensitively.
		$glyph = [
			['surface_ref' => 'surface:5', 'session_id' => 'aaa111', 'workspace_title' => "⠂ WPForms Migration", 'tab_title' => 'x'],
			['surface_ref' => 'surface:6', 'session_id' => 'aaa222', 'workspace_title' => 'other wpforms migration job', 'tab_title' => 'y'],
		];
		$mg = $this->gy->matchIdentifier($glyph, 'wpforms migration');
		$this->assertCount(1, $mg);
		$this->assertSame('aaa111', $mg[0]['session_id']);

		// No exact match: still falls back to substring (both rows match "backend").
		$sub = [
			['surface_ref' => 'surface:5', 'session_id' => 'aaa111', 'workspace_title' => 'backend api', 'tab_title' => 'x'],
			['surface_ref' => 'surface:6', 'session_id' => 'aaa222', 'workspace_title' => 'frontend', 'tab_title' => 'backend notes'],
		];
		$this->assertCount(2, $this->gy->matchIdentifier($sub, 'backend'));

		// Two exact matches remain genuinely ambiguous (both returned, caller rejects).
		$two = [
			['surface_ref' => 'surface:5', 'session_id' => 'aaa111', 'workspace_title' => 'deploy', 'tab_title' => 'x'],
			['surface_ref' => 'surface:6', 'session_id' => 'aaa222', 'workspace_title' => '⠂ deploy', 'tab_title' => 'y'],
		];
		$this->assertCount(2, $this->gy->matchIdentifier($two, 'deploy'));
	}

	public function testParseReplSelection(): void
	{
		$this->assertSame([0, 2, 4], $this->gy->parseReplSelection('1 3 5', 5));
		$this->assertSame([1, 2, 3], $this->gy->parseReplSelection('2-4', 5));
		$this->assertSame([0, 1, 2], $this->gy->parseReplSelection('1 1 2-3', 5));
		$this->assertSame([0], $this->gy->parseReplSelection('9 1', 5));
	}

	public function testTreeIndex(): void
	{
		$tree = ['windows' => [['workspaces' => [
			['ref' => 'workspace:22', 'title' => 'asana', 'panes' => [['surfaces' => [
				['ref' => 'surface:59', 'id' => 'UUID-59', 'title' => 'lg'],
			]]]],
		]]]];
		$ix = $this->gy->treeIndex($tree);
		$this->assertSame('asana', $ix['workspace']['workspace:22']);
		$this->assertSame('UUID-59', $ix['surface']['surface:59']['id']);
	}

	public function testGate1StatuslineCwdMatch(): void
	{
		$this->assertSame('/asana-cli', $this->gy->extractStatuslineCwd('foo 📁 /asana-cli | 🌿 main'));
		$this->assertTrue($this->gy->statuslineMatchesSession('… 📁 /asana-cli | 🌿 main', '/Users/JT/Code/asana-cli'));
		$this->assertFalse($this->gy->statuslineMatchesSession('… 📁 /Boss', '/Users/JT/Code/asana-cli'));
		$this->assertFalse($this->gy->statuslineMatchesSession('a plain shell prompt $', '/Users/JT/Code/asana-cli'));
	}

	public function testGate1SpacedPaths(): void
	{
		$this->assertSame('/Southport UDO', $this->gy->extractStatuslineCwd('[Opus] | 📁 /Southport UDO | 🌿 main'));
		$this->assertSame('/Southport UDO', $this->gy->extractStatuslineCwd('📁 /Southport UDO'));
		$this->assertSame('/a b c', $this->gy->extractStatuslineCwd("📁 /a b c │ x"));
		$this->assertTrue($this->gy->statuslineMatchesSession('📁 /Southport UDO | 🌿 m', '/Users/JT/Documents/Southport UDO'));
		$this->assertTrue($this->gy->statuslineMatchesSession('📁 ~/Documents/Southport UDO', '/Users/JT/Documents/Southport UDO'));
		$this->assertTrue($this->gy->statuslineMatchesSession('📁 …/Documents/Southport UDO | x', '/Users/JT/Documents/Southport UDO'));
		$this->assertFalse($this->gy->statuslineMatchesSession('📁 /Southport UDO', '/Users/JT/Documents/Northport UDO'));
		$this->assertFalse($this->gy->statuslineMatchesSession('📁 /UDO', '/Users/JT/Documents/Southport UDO'));
	}

	public function testForceDoesNotBypassGate1(): void
	{
		$stub = new class($this->cli, $this->cmux) extends Graveyard {
			public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string
			{
				return '[Opus] | 📁 /totally-different-dir | 🌿 main';
			}
		};
		$fakeSess = ['session_id' => 'zztest-gate1', 'cwd' => '/Users/JT/x', 'surface_ref' => 'surface:1',
			'workspace_ref' => 'ws:1', 'targetable' => true, 'reason' => '', 'idle_seconds' => 999999,
			'tab_title' => 't', 'workspace_title' => 'w', 'pid' => 0, 'model' => null, 'skip_perms' => false];
		$this->assertFalse($stub->buryOne($fakeSess, true, true));
		$this->assertFileDoesNotExist($stub->metaPath('zztest-gate1'));
	}

	public function testPathTailComponents(): void
	{
		$this->assertSame(['Users', 'JT', 'Documents', 'Southport UDO'], $this->gy->pathTailComponents('/Users/JT/Documents/Southport UDO'));
		$this->assertSame(['Code', 'asana-cli'], $this->gy->pathTailComponents('~/Code/asana-cli'));
		$this->assertSame(['a', 'b'], $this->gy->pathTailComponents('…/a/b'));
	}

	public function testGate2TranscriptMatchesSession(): void
	{
		$this->assertTrue($this->gy->transcriptMatchesSession("… conversation …\n> Fix the login bug now\n…", 'Fix the login bug now'));
		$this->assertFalse($this->gy->transcriptMatchesSession("some other session entirely", 'Fix the login bug now'));
		$this->assertTrue($this->gy->transcriptMatchesSession("anything", ''));
		$this->assertTrue($this->gy->transcriptMatchesSession("❯ /monorepo-address-pr-review\n  ⎿ …", '/monorepo-address-pr-review'));
	}

	public function testGate2IgnoresInlineMarkdown(): void
	{
		// Claude Code's /export strips inline markdown (**bold**, `code`, _em_) when
		// rendering a transcript, but genuineTurns() needles keep it. Gate 2 must
		// compare markdown-insensitively or it deterministically refuses to bury any
		// session whose recent turns lead with formatting (regression: 83599b0f).
		$needle   = '**8/8 ✅** all `combined` events fixed';
		$rendered = "…summary…\n⏺ 8/8 ✅ all combined events fixed\n";
		$this->assertTrue($this->gy->transcriptMatchesSession($rendered, $needle));

		// And through the needle-list gate-2 entry point.
		$this->assertTrue($this->gy->transcriptBelongsToSession($rendered, [$needle]));

		// A genuine mismatch must still block (stripping markers must not over-match).
		$this->assertFalse($this->gy->transcriptMatchesSession("an unrelated session entirely", $needle));
	}

	public function testGate2TailAnchoredMatching(): void
	{
		$g2entries = [
			['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => '<local-command-caveat>Caveat: machine noise']]]],
			['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'the original first request about widgets']]]],
			['type' => 'user', 'isMeta' => true, 'message' => ['content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]],
			['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Published the review with two findings fixed']]]],
			['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'review again, copilot made updates']]]],
		];
		$gt = $this->gy->genuineTurns($g2entries);
		$this->assertCount(3, $gt);
		$this->assertSame('the original first request about widgets', $gt[0]['text']);

		$compactedExport = "…summary…\n❯ review again, copilot made updates\n⏺ Published the review with two findings fixed\n";
		$needles = array_map(fn($t) => $t['text'], $gt);
		$this->assertTrue($this->gy->transcriptBelongsToSession($compactedExport, $needles));
		$this->assertFalse($this->gy->transcriptBelongsToSession("an entirely unrelated session transcript", $needles));
		$this->assertTrue($this->gy->transcriptBelongsToSession("anything", []));
		$this->assertTrue($this->gy->transcriptBelongsToSession("anything", ['   ', '']));
	}

	public function testRenderTurns(): void
	{
		$entries = [
			['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => "<command-name>/superpowers:brainstorm</command-name>"]]]],
			['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => "Let's brainstorm the asana skill."]]]],
			['type' => 'user', 'isMeta' => true, 'message' => ['content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]],
			['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'No response requested.']]]],
			['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'Add a sync subcommand']]]],
			['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Bash']]]],
			['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Done — added sync.']]]],
		];
		$r = $this->gy->renderTurns($entries, 10);
		$this->assertStringNotContainsString('/superpowers:brainstorm', $r);
		$this->assertStringContainsString('❯ Add a sync subcommand', $r);
		$this->assertStringContainsString('⏺ Done — added sync.', $r);
		$this->assertStringNotContainsString('Continue from where you left off', $r);
		$this->assertStringNotContainsString('No response requested', $r);
		$this->assertSame(3, substr_count($r, "\n"));

		$r2 = $this->gy->renderTurns($entries, 2);
		$this->assertSame(2, substr_count($r2, "\n"));
		$this->assertStringContainsString('Done — added sync.', $r2);

		$this->assertSame('', $this->gy->renderTurns([], 6));

		$long = ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => str_repeat('x', 300)]]]];
		$this->assertSame('…', mb_substr(trim($this->gy->renderTurns([$long], 6)), -1));
	}

	public function testClassifyWorkspaceLayout(): void
	{
		$wsNode = ['panes' => [
			['index' => 0, 'surfaces' => [
				['ref' => 'surface:1', 'type' => 'terminal', 'title' => 'claude a', 'index_in_pane' => 0],
				['ref' => 'surface:2', 'type' => 'terminal', 'title' => 'fresh claude', 'index_in_pane' => 1],
			]],
			['index' => 1, 'surfaces' => [
				['ref' => 'surface:3', 'type' => 'terminal', 'title' => 'a shell', 'index_in_pane' => 0],
				['ref' => 'surface:4', 'type' => 'browser', 'title' => 'docs', 'url' => 'https://x', 'index_in_pane' => 1],
			]],
		]];
		$liveByRef = ['surface:1' => ['session_id' => 'sid-1', 'cwd' => '/a', 'targetable' => true, 'tab_title' => 'claude a']];
		$isClaudeByRef = ['surface:1' => true, 'surface:2' => true, 'surface:3' => false, 'surface:4' => false];
		$c = $this->gy->classifyWorkspaceLayout($wsNode, $liveByRef, $isClaudeByRef);

		$this->assertCount(1, $c['members']);
		$this->assertSame('sid-1', $c['members'][0]['session_id']);
		$this->assertSame(0, $c['members'][0]['group_pos']);
		$this->assertCount(1, $c['untargetable']);
		$this->assertSame('surface:2', $c['untargetable'][0]['ref']);
		$this->assertCount(4, $c['layout']);
		$this->assertSame(['claude', 'claude-untargetable', 'shell', 'browser'], array_column($c['layout'], 'kind'));
		$this->assertSame('https://x', $c['layout'][3]['url']);

		// Each entry records whether it was the tab showing in its pane, so resurrect
		// can re-select it. surface:2 was the visible tab in pane 0; nothing else.
		$wsNode['panes'][0]['surfaces'][1]['selected_in_pane'] = true;
		$c2 = $this->gy->classifyWorkspaceLayout($wsNode, $liveByRef, $isClaudeByRef);
		$this->assertSame([false, true, false, false], array_column($c2['layout'], 'selected_in_pane'));

		$wsAgent = ['panes' => [['index' => 0, 'surfaces' => [
			['ref' => 'surface:9', 'type' => 'agentSession', 'title' => 'Claude Code · React', 'index_in_pane' => 0],
		]]]];
		$ca = $this->gy->classifyWorkspaceLayout($wsAgent, [], []);
		$this->assertCount(1, $ca['untargetable']);
		$this->assertSame('claude-untargetable', $ca['layout'][0]['kind']);
	}

	public function testPaneSelectionsPicksVisibleTabPerPane(): void
	{
		$layout = [
			['group_pos' => 0, 'pane_index' => 0, 'selected_in_pane' => false],
			['group_pos' => 1, 'pane_index' => 0, 'selected_in_pane' => true],  // showing in pane 0
			['group_pos' => 2, 'pane_index' => 1, 'selected_in_pane' => true],  // showing in pane 1
			['group_pos' => 3, 'pane_index' => 1, 'selected_in_pane' => false],
			['group_pos' => 4, 'pane_index' => 2, 'selected_in_pane' => false], // none showing -> omitted
		];
		// [pane_index => group_pos of the tab to re-select]; panes with no flagged tab omitted.
		$this->assertSame([0 => 1, 1 => 2], $this->gy->paneSelections($layout));
		$this->assertSame([], $this->gy->paneSelections([]));
	}

	public function testPlanLayoutRestore(): void
	{
		// Two panes, two tabs each (the collapse-bug repro shape).
		$layout = [
			['pane_index' => 0, 'kind' => 'claude', 'claude_session_id' => 'a'],
			['pane_index' => 0, 'kind' => 'claude', 'claude_session_id' => 'b'],
			['pane_index' => 1, 'kind' => 'claude', 'claude_session_id' => 'c'],
			['pane_index' => 1, 'kind' => 'shell'],
		];
		$steps = $this->gy->planLayoutRestore($layout);

		$this->assertSame(['first', 'tab', 'split', 'tab'], array_column($steps, 'op'));
		$this->assertSame([0, 0, 1, 1], array_column($steps, 'pane_index'));
		// Only the split step carries a direction; it defaults to a side-by-side column.
		$this->assertSame('right', $steps[2]['dir']);
		$this->assertArrayNotHasKey('dir', $steps[0]);
		// Entries are threaded through untouched.
		$this->assertSame('a', $steps[0]['entry']['claude_session_id']);
		$this->assertSame('c', $steps[2]['entry']['claude_session_id']);

		// Single pane, three tabs → one 'first' then plain tabs, no splits.
		$single = $this->gy->planLayoutRestore([
			['pane_index' => 0], ['pane_index' => 0], ['pane_index' => 0],
		]);
		$this->assertSame(['first', 'tab', 'tab'], array_column($single, 'op'));

		// Missing pane_index defaults to pane 0.
		$bare = $this->gy->planLayoutRestore([['kind' => 'claude'], ['kind' => 'shell']]);
		$this->assertSame(['first', 'tab'], array_column($bare, 'op'));

		$this->assertSame([], $this->gy->planLayoutRestore([]));
	}

	public function testLayoutTreeSurfaceCount(): void
	{
		// Bare pane (single-pane workspace).
		$this->assertSame(2, $this->gy->layoutTreeSurfaceCount(
			['pane' => ['surfaces' => [['type' => 'terminal'], ['type' => 'terminal']]]]
		));

		// Nested split: left pane (2 tabs) + right column split into two panes (1 + 3).
		$tree = ['direction' => 'horizontal', 'split' => 0.5, 'children' => [
			['pane' => ['surfaces' => [['type' => 'terminal'], ['type' => 'terminal']]]],
			['direction' => 'vertical', 'split' => 0.3, 'children' => [
				['pane' => ['surfaces' => [['type' => 'terminal']]]],
				['pane' => ['surfaces' => [['type' => 'terminal'], ['type' => 'terminal'], ['type' => 'terminal']]]],
			]],
		]];
		$this->assertSame(6, $this->gy->layoutTreeSurfaceCount($tree));
		$this->assertSame(0, $this->gy->layoutTreeSurfaceCount([]));
	}

	public function testSanitizeLayoutTree(): void
	{
		$tree = ['direction' => 'horizontal', 'split' => 0.5, 'children' => [
			['pane' => ['surfaces' => [
				['type' => 'terminal', 'cwd' => '/a', 'command' => 'claude --resume x', 'focus' => true],
			]]],
			['pane' => ['surfaces' => [['type' => 'terminal', 'command' => 'vim']]]],
		]];
		$clean = $this->gy->sanitizeLayoutTree($tree);

		// Commands stripped everywhere (would otherwise double-launch on replay)…
		$this->assertArrayNotHasKey('command', $clean['children'][0]['pane']['surfaces'][0]);
		$this->assertArrayNotHasKey('command', $clean['children'][1]['pane']['surfaces'][0]);
		// …but geometry, type, cwd, and focus survive.
		$this->assertSame('horizontal', $clean['direction']);
		$this->assertSame(0.5, $clean['split']);
		$this->assertSame('/a', $clean['children'][0]['pane']['surfaces'][0]['cwd']);
		$this->assertTrue($clean['children'][0]['pane']['surfaces'][0]['focus']);
		// Surface count is preserved (stays aligned with the manifest's layout[]).
		$this->assertSame(2, $this->gy->layoutTreeSurfaceCount($clean));
	}

	public function testUntargetableReasonFor(): void
	{
		$this->assertStringContainsString('cmux-native agent session', $this->gy->untargetableReasonFor(['type' => 'agentSession']));
		$this->assertStringContainsString('not running', $this->gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => true, 'has_claude' => false]));
		$this->assertStringContainsString('no live shell', $this->gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => false]));
		$this->assertStringContainsString('no session file yet', $this->gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => true, 'has_claude' => true, 'has_session_file' => false]));
		$this->assertStringContainsString('no unique statusline-cwd match', $this->gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => false]));
		$this->assertStringContainsString('multiple sessions', $this->gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => false, 'cwd_conflict' => true]));

		$dupFacts = ['type' => 'terminal', 'has_script' => true, 'has_shell' => true, 'has_claude' => true, 'has_session_file' => true, 'session_id' => '93de80a4-x', 'bound_elsewhere' => 'surface:33'];
		$this->assertStringContainsString('duplicate live view of session 93de80a4', $this->gy->untargetableReasonFor($dupFacts));
		$this->assertStringContainsString('surface:33', $this->gy->untargetableReasonFor($dupFacts));
	}

	public function testGroupTombstones(): void
	{
		$ts = [
			['session_id' => 'aaaa1111', 'group_id' => 'g1', 'group_pos' => 1, 'group_title' => 'ws', 'buried_at' => '2026-07-14', 'workspace_title' => 'ws', 'summary' => 's2'],
			['session_id' => 'bbbb2222', 'group_id' => 'g1', 'group_pos' => 0, 'group_title' => 'ws', 'buried_at' => '2026-07-14', 'workspace_title' => 'ws', 'summary' => 's1'],
			['session_id' => 'cccc3333', 'buried_at' => '2026-07-13', 'workspace_title' => 'loose', 'summary' => 's3'],
		];
		[$groups, $loose] = $this->gy->groupTombstones($ts);
		$this->assertCount(2, $groups['g1']);
		$this->assertCount(1, $loose);
		$this->assertSame('bbbb2222', $groups['g1'][0]['session_id']);
	}

	public function testContentProbeBind(): void
	{
		$fresh = [
			['session_id' => 'f-1', 'cwd' => '/Users/JT/Code/asana-cli', 'tty' => 'ttys010'],
			['session_id' => 'f-2', 'cwd' => '/Users/JT/Boss', 'tty' => 'ttys011'],
		];
		$unbound = [
			'surface:1' => ['tty' => 'ttys010'],
			'surface:2' => ['tty' => 'ttys011'],
			'surface:3' => ['tty' => 'ttys012'],
		];
		$screens = [
			'surface:1' => '… 📁 /asana-cli | 🌿 main',
			'surface:2' => '… 📁 /Boss | 🌿 main',
			'surface:3' => 'plain shell $',
		];
		$b = $this->gy->contentProbeBind($fresh, $unbound, $screens);
		$this->assertSame('surface:1', $b['f-1'] ?? null);
		$this->assertSame('surface:2', $b['f-2'] ?? null);

		$fresh2 = [['session_id' => 'f-3', 'cwd' => '/Users/JT/Code/asana-cli', 'tty' => 'ttysZZ']];
		$unbound2 = ['surface:1' => ['tty' => 'ttysAA'], 'surface:2' => ['tty' => 'ttysBB']];
		$screens2 = ['surface:1' => '📁 /asana-cli', 'surface:2' => '📁 /asana-cli'];
		$this->assertSame([], $this->gy->contentProbeBind($fresh2, $unbound2, $screens2));

		$fresh3 = [['session_id' => 'f-4', 'cwd' => '/x', 'tty' => 'ttysBB']];
		$unbound3 = ['surface:1' => ['tty' => 'ttysAA'], 'surface:2' => ['tty' => 'ttysBB']];
		$screens3 = ['surface:1' => '📁 /x', 'surface:2' => '📁 /x'];
		$this->assertSame('surface:2', $this->gy->contentProbeBind($fresh3, $unbound3, $screens3)['f-4'] ?? null);

		$this->assertSame([], $this->gy->contentProbeBind([['session_id' => 'f-5', 'cwd' => '/nope', 'tty' => 't']], $unbound, $screens));
	}

	public function testEllipsizeHelpers(): void
	{
		$this->assertSame('hello w…', $this->gy->ellipsizeText('hello world', 8));
		$this->assertSame('short', $this->gy->ellipsizeText('short', 20));
		$this->assertSame('…/deep', $this->gy->ellipsizeLeft('/a/b/c/deep', 6));
	}

	public function testShortenCwdHomeAbbrev(): void
	{
		$this->assertSame('~/Sites/x', $this->gy->shortenCwd('/Users/JT/Sites/x', '/Users/JT', 40));
	}

	#[DataProvider('shortenCwdWidthProvider')]
	public function testShortenCwdFitsWithinWidth(int $max): void
	{
		$home = '/Users/JT';
		$long = '/Users/JT/Sites/lindris-monorepo/local-frontend/lindris-frontend';
		$s = $this->gy->shortenCwd($long, $home, $max);
		$this->assertLessThanOrEqual($max, mb_strlen($s));
		$this->assertTrue(str_contains($s, 'lindris-frontend') || str_contains($s, '…'));
	}

	public static function shortenCwdWidthProvider(): array
	{
		return [[20], [30], [40]];
	}

	public function testTitleizeSummary(): void
	{
		$this->assertSame('Hotline call with lindris', $this->gy->titleizeSummary(['summary' => '/hotline:ringing [CALL_ID: a0be3ca9] [MODE: quick_call]', 'tab_title' => '✳ Hotline call with lindris', 'workspace_title' => 'ws']));
		$this->assertSame('add a sync subcommand to the CLI', $this->gy->titleizeSummary(['summary' => 'add a sync subcommand to the CLI', 'tab_title' => 'Terminal', 'workspace_title' => 'ws']));
		$this->assertSame('my-workspace', $this->gy->titleizeSummary(['summary' => '', 'tab_title' => 'Terminal', 'workspace_title' => 'my-workspace']));
		$this->assertSame('PR Review Checklist', $this->gy->titleizeSummary(['summary' => 'Caveat: The messages below were generated by the user while running local commands.', 'tab_title' => '✳ PR Review Checklist', 'workspace_title' => 'ws']));
	}

	public function testSummarizeUserText(): void
	{
		$this->assertSame('', $this->gy->summarizeUserText('<local-command-caveat>Caveat: The messages below were generated by the user'));
		$this->assertSame('', $this->gy->summarizeUserText('<command-args>1234 careful</command-args>'));
	}

	#[DataProvider('widthProvider')]
	public function testWidthAwareOutputNeverExceedsWidth(int $W): void
	{
		$home = '/Users/JT';
		$long = '/Users/JT/Sites/lindris-monorepo/local-frontend/lindris-frontend';
		$tomb = ['session_id' => 'abcd1234ef', 'buried_at' => '2026-07-15',
			'summary' => '/hotline:ringing [CALL_ID: a0be] [MODE: quick_call]', 'tab_title' => '✳ Review property spec for accessory dwelling unit rezoning',
			'workspace_title' => 'Hankinsville ADU feasibility', 'cwd' => '/Users/JT/Documents/Southport UDO'];

		foreach ([0, 4] as $indent) {
			$e = $this->gy->lsEntryLines($tomb, $W, $home, $indent);
			$this->assertLessThanOrEqual($W, mb_strlen($e['primary']));
			if ($e['secondary'] !== null) {
				$this->assertLessThanOrEqual($W, mb_strlen($e['secondary']));
			}
		}
		$this->assertLessThanOrEqual($W, mb_strlen($this->gy->groupHeaderLine('Hankinsville ADU feasibility', 3, '2026-07-15', $W)));

		$cand = ['session_id' => 'abcd1234ef', 'idle_seconds' => 5875200, 'busy' => false, 'targetable' => true,
			'tab_title' => '✳ Review property spec for accessory dwelling', 'workspace_title' => 'Hankinsville', 'cwd' => $long];
		$this->assertLessThanOrEqual($W, mb_strlen($this->gy->candidateLine($cand, $W, $home)));
	}

	public static function widthProvider(): array
	{
		return [[60], [80], [120]];
	}

	public function testLsEntryLinesStacksNarrowSingleLineWide(): void
	{
		$home = '/Users/JT';
		$tomb = ['session_id' => 'abcd1234ef', 'buried_at' => '2026-07-15',
			'summary' => '/hotline:ringing [CALL_ID: a0be] [MODE: quick_call]', 'tab_title' => '✳ Review property spec for accessory dwelling unit rezoning',
			'workspace_title' => 'Hankinsville ADU feasibility', 'cwd' => '/Users/JT/Documents/Southport UDO'];
		$this->assertNotNull($this->gy->lsEntryLines($tomb, 60, $home, 0)['secondary']);
		$this->assertNull($this->gy->lsEntryLines($tomb, 120, $home, 0)['secondary']);
	}

	/**
	 * Regression: exportTranscript typed "/export <path>" into the REPL without
	 * clearing whatever the user had already half-typed in the prompt box, so a
	 * leftover fragment got prepended ("for t" + "/export …" = "for t/export …")
	 * and the command never ran. sendExportCommand must clear the prompt FIRST.
	 */
	public function testSendExportCommandClearsPromptBeforeTypingExport(): void
	{
		$recorder = new class($this->cli) extends Cmux {
			public array $calls = [];
			public function sendToSurface(string $surfRef, string $wsRef, string $text): void
			{
				$this->calls[] = ['send', $text];
			}
			public function sendKeyToSurface(string $surfRef, string $wsRef, string $key): void
			{
				$this->calls[] = ['key', $key];
			}
		};

		$gy = new Graveyard($this->cli, $recorder);
		$sess = ['surface_ref' => 'surface:1', 'workspace_ref' => 'workspace:2'];
		$gy->sendExportCommand($sess, '/tmp/foo.tmp');

		// First action must be the prompt-clear (raw Ctrl-C byte), issued via the
		// text `send` path — send-key ctrl+c does not reach Claude Code's REPL.
		$this->assertSame(['send', Graveyard::CLEAR_PROMPT], $recorder->calls[0]);
		$this->assertSame("\x03", Graveyard::CLEAR_PROMPT);

		// Then the command text, then a real Return keypress to submit it.
		$this->assertSame(['send', '/export /tmp/foo.tmp'], $recorder->calls[1]);
		$this->assertSame(['key', 'Return'], $recorder->calls[2]);

		// The clear must precede the /export text (the whole point of the fix).
		$clearIdx  = array_search(['send', Graveyard::CLEAR_PROMPT], $recorder->calls, true);
		$exportIdx = array_search(['send', '/export /tmp/foo.tmp'], $recorder->calls, true);
		$this->assertLessThan($exportIdx, $clearIdx);
	}

	public function testReservedGroupPosFindsSlotBySessionIdElseNull(): void
	{
		$manifest = ['layout' => [
			['group_pos' => 0, 'claude_session_id' => 'aaaa1111-0000'],
			['group_pos' => 1, 'claude_session_id' => 'bbbb2222-0000'],
			['group_pos' => 2, 'claude_session_id' => 'cccc3333-0000'],
			['group_pos' => 3, 'kind' => 'terminal'], // non-claude surface, no session id
		]];

		$this->assertSame(2, $this->gy->reservedGroupPos($manifest, 'cccc3333-0000'));
		$this->assertSame(0, $this->gy->reservedGroupPos($manifest, 'aaaa1111-0000'));
		$this->assertNull($this->gy->reservedGroupPos($manifest, 'zzzz9999-0000'));
		$this->assertNull($this->gy->reservedGroupPos(['layout' => []], 'aaaa1111-0000'));
		$this->assertNull($this->gy->reservedGroupPos([], 'aaaa1111-0000'));
	}

	public function testFinishBuryCommandUsesEightCharPrefixes(): void
	{
		$sid = '96796864-fa54-4f22-855e-43dbcf37e71c';
		$gid = '8034842f-19af-4daf-a59b-3d2218bd54a1';
		$this->assertSame(
			'graveyard bury 96796864 --group 8034842f',
			$this->gy->finishBuryCommand($sid, $gid)
		);
	}

	/**
	 * buryIntoGroup resolves the group by prefix, reads its manifest, finds the live
	 * session's reserved slot, and calls buryOne() with that exact group_id + group_pos
	 * (full teardown). This is the recovery path that keeps a half-buried plot whole.
	 */
	public function testBuryIntoGroupBuriesReservedSessionAtItsSlot(): void
	{
		$root = sys_get_temp_dir() . '/gy-into-' . getmypid() . '-' . uniqid();
		putenv('GRAVEYARD_ROOT=' . $root);
		$gid  = 'abcd1234-0000-0000-0000-000000000000';
		$sid  = 'cccc3333-0000-0000-0000-000000000000';
		@mkdir("{$root}/workspaces/{$gid}", 0755, true);
		file_put_contents("{$root}/workspaces/{$gid}/manifest.json", json_encode([
			'group_id'    => $gid,
			'group_title' => 'demo plot',
			'layout'      => [
				['group_pos' => 0, 'claude_session_id' => 'aaaa1111-0000-0000-0000-000000000000'],
				['group_pos' => 1, 'claude_session_id' => 'bbbb2222-0000-0000-0000-000000000000'],
				['group_pos' => 2, 'claude_session_id' => $sid],
			],
		]));

		$gy = new class($this->cli, $this->cmux) extends Graveyard {
			public array $buriedWith = [];
			public function resolveLiveByIdentifier(string $ref): array
			{
				return [['session_id' => 'cccc3333-0000-0000-0000-000000000000', 'workspace_title' => 'w', 'tab_title' => 't']];
			}
			public function selfSessionId(): ?string { return null; }
			public function buryOne(array $sess, bool $force, bool $autoConfirm, ?array $group = null, bool $deferClose = false): bool
			{
				$this->buriedWith[] = ['sid' => $sess['session_id'], 'group' => $group, 'deferClose' => $deferClose];
				return true;
			}
		};

		$gy->buryIntoGroup('cccc3333', 'abcd1234', false, true);

		$this->assertCount(1, $gy->buriedWith);
		$this->assertSame($sid, $gy->buriedWith[0]['sid']);
		$this->assertSame($gid, $gy->buriedWith[0]['group']['group_id']);
		$this->assertSame(2, $gy->buriedWith[0]['group']['group_pos']);
		$this->assertSame('demo plot', $gy->buriedWith[0]['group']['group_title']);
		$this->assertFalse($gy->buriedWith[0]['deferClose']); // full teardown, not deferred

		// Wipe temp store.
		array_map('unlink', glob("{$root}/workspaces/{$gid}/*") ?: []);
		@rmdir("{$root}/workspaces/{$gid}");
		@rmdir("{$root}/workspaces");
		@rmdir($root);
		putenv('GRAVEYARD_ROOT');
	}
}
