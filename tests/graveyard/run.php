#!/usr/bin/env php
<?php
namespace JT;
# Plain-PHP assert harness for graveyard pure logic. Run: php tests/graveyard/run.php
$cli = require_once dirname(__DIR__, 2) . '/misc/helpers.php';
require_once dirname(__DIR__, 2) . '/misc/helpers/cmux.php';

$pass = 0; $fail = 0;
function ok($cond, $label) {
	global $pass, $fail;
	if ($cond) { $pass++; echo "ok - $label\n"; }
	else { $fail++; echo "NOT OK - $label\n"; }
}

$cmux = new Helpers\Cmux($cli);

// encodeProjectKey
ok($cmux->encodeProjectKey('/Users/JT/.dotfiles') === '-Users-JT--dotfiles', 'encodeProjectKey dotfiles');
ok($cmux->encodeProjectKey('/Users/JT/Code/claude-plugins') === '-Users-JT-Code-claude-plugins', 'encodeProjectKey plugins');
ok($cmux->encodeProjectKey('/Users/JT/Documents/Southport UDO') === '-Users-JT-Documents-Southport-UDO', 'encodeProjectKey spaces');
ok($cmux->encodeProjectKey('/Users/JT/Local Sites/gatehouse/app/public/wp-content') === '-Users-JT-Local-Sites-gatehouse-app-public-wp-content', 'encodeProjectKey spaced path');

// buildResumeCommand
ok($cmux->buildResumeCommand('abc', false, null) === 'claude --resume abc', 'resume plain');
ok($cmux->buildResumeCommand('abc', true, 'opus') === 'claude --dangerously-skip-permissions --resume abc --model=opus', 'resume flags');

ok(method_exists($cmux, 'newWorkspace'), 'Cmux::newWorkspace exists');

require_once dirname(__DIR__, 2) . '/bin/graveyard_lib.php';
$gy = new Graveyard($cli, $cmux);

// parseDuration
ok($gy->parseDuration('2d') === 172800, 'dur 2d');
ok($gy->parseDuration('48h') === 172800, 'dur 48h');
ok($gy->parseDuration('90m') === 5400, 'dur 90m');
ok($gy->parseDuration('30') === 30, 'dur bare seconds');
$threw = false; try { $gy->parseDuration('nope'); } catch (\InvalidArgumentException $e) { $threw = true; }
ok($threw, 'dur invalid throws');

// upsertIndex dedupes by session_id (use a temp store root via env override)
putenv('GRAVEYARD_ROOT=' . sys_get_temp_dir() . '/gy-test-' . getmypid());
@mkdir(getenv('GRAVEYARD_ROOT'), 0755, true);
$gy2 = new Graveyard($cli, $cmux);
$gy2->upsertIndex(['session_id' => 'x', 'summary' => 'first']);
$gy2->upsertIndex(['session_id' => 'x', 'summary' => 'second']);
$gy2->upsertIndex(['session_id' => 'y', 'summary' => 'other']);
$idx = $gy2->readIndex();
ok(count($idx['tombstones']) === 2, 'upsert dedupes to 2');
$xs = array_values(array_filter($idx['tombstones'], fn($t) => $t['session_id'] === 'x'));
ok($xs[0]['summary'] === 'second', 'upsert newest wins');

// isBusy: idle floor
ok($gy->isBusy(5, 15, '')  === true,  'busy when idle<floor');
ok($gy->isBusy(30, 15, '') === false, 'idle enough, quiet screen');
// isBusy: active-turn markers on screen
ok($gy->isBusy(30, 15, '... (esc to interrupt)') === true,  'busy: esc-to-interrupt');
ok($gy->isBusy(30, 15, '✳ Cogitating… (1.2k tokens)') === true, 'busy: token counter');
ok($gy->isBusy(30, 15, 'justin@mac ~/.dotfiles %') === false, 'quiet prompt not busy');

// filterSelf
$sessions = [
	['surface_ref' => 'surface:1', 'session_id' => 'self-sess'],
	['surface_ref' => 'surface:2', 'session_id' => 'other'],
];
$kept = $gy->filterSelf($sessions, 'surface:1', 'self-sess');
ok(count($kept) === 1 && $kept[0]['session_id'] === 'other', 'filterSelf drops self by surface+session');

$sess2 = [
	['surface_ref' => 'surface:9', 'surface_id' => 'uuid-9', 'session_id' => 'my-own-sess'],
	['surface_ref' => 'surface:8', 'surface_id' => 'uuid-8', 'session_id' => 'keep-me'],
];
$kept2 = $gy->filterSelf($sess2, null, 'my-own-sess');
ok(count($kept2) === 1 && $kept2[0]['session_id'] === 'keep-me', 'filterSelf drops self by session_id alone');

// lastRealActivity: streams JSONL, returns last user/assistant timestamp (ignores later system entries)
$tmpCwd = '/tmp/gy-test-cwd-' . getmypid();
$tmpSid = 'test-sess-' . getmypid();
$tmpJsonlPath = $cmux->jsonlPathFor($tmpSid, $tmpCwd);
@mkdir(dirname($tmpJsonlPath), 0755, true);
$t1 = '2026-07-01T10:00:00Z';
$t2 = '2026-07-01T10:05:00Z';
$t3 = '2026-07-01T10:09:00Z';
file_put_contents(
	$tmpJsonlPath,
	json_encode(['type' => 'user', 'timestamp' => $t1]) . "\n"
	. json_encode(['type' => 'assistant', 'timestamp' => $t2]) . "\n"
	. json_encode(['type' => 'system', 'timestamp' => $t3]) . "\n"
);
ok($cmux->lastRealActivity($tmpSid, $tmpCwd) === strtotime($t2), 'lastRealActivity returns last user/assistant ts, ignores trailing system entry');
ok($cmux->lastRealActivity('nope', '/no/such') === null, 'lastRealActivity null for missing file');

// lastRealActivity: ignores unparseable timestamps, keeps prior valid one
$tmpSid2 = 'test-sess-bad-ts-' . getmypid();
$tmpJsonlPath2 = $cmux->jsonlPathFor($tmpSid2, $tmpCwd);
$goodTs = '2026-02-01T09:00:00Z';
file_put_contents(
	$tmpJsonlPath2,
	json_encode(['type' => 'user', 'timestamp' => $goodTs]) . "\n"
	. json_encode(['type' => 'assistant', 'timestamp' => 'not-a-date']) . "\n"
);
ok($cmux->lastRealActivity($tmpSid2, $tmpCwd) === strtotime($goodTs), 'lastRealActivity ignores unparseable timestamp, keeps good one');
@unlink($tmpJsonlPath);
@unlink($tmpJsonlPath2);
@rmdir(dirname($tmpJsonlPath));

// dedupBySessionId: keeps first row per session_id, preserves order
$dupRows = [
	['session_id' => 'a', 'tab_title' => 'first'],
	['session_id' => 'a', 'tab_title' => 'dupe'],
	['session_id' => 'b', 'tab_title' => 'x'],
];
$deduped = $gy->dedupBySessionId($dupRows);
ok(count($deduped) === 2, 'dedupBySessionId returns 2 rows');
ok($deduped[0]['tab_title'] === 'first', 'dedupBySessionId keeps first row for duplicate session_id');

// formatCandidatePorcelain: pure tab-separated formatter
$idleRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => false, 'workspace_title' => 'proj', 'cwd' => '/x'];
ok($gy->formatCandidatePorcelain($idleRow) === "abc\t3600\tidle\tproj\t/x", 'formatCandidatePorcelain idle row');
$busyRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => true, 'workspace_title' => 'proj', 'cwd' => '/x'];
ok($gy->formatCandidatePorcelain($busyRow) === "abc\t3600\tbusy\tproj\t/x", 'formatCandidatePorcelain busy row');

// buryIds([]) is a clean no-op (guard) — should not throw
$threwBuryIds = false;
try { $gy->buryIds([], false); } catch (\Throwable $e) { $threwBuryIds = true; }
ok(!$threwBuryIds, 'buryIds([]) is a no-op, does not throw');

// matchIdentifier: precedence tiers, first tier winning stops fallthrough
$mrows = [
	['surface_ref' => 'surface:5', 'surface_id' => 'UUID-5', 'session_id' => 'aaa111', 'workspace_title' => 'backend api', 'tab_title' => 'fix bug'],
	['surface_ref' => 'surface:6', 'surface_id' => 'UUID-6', 'session_id' => 'aaa222', 'workspace_title' => 'frontend', 'tab_title' => 'backend notes'],
	['surface_ref' => 'surface:7', 'surface_id' => 'UUID-7', 'session_id' => 'bbb333', 'workspace_title' => 'docs', 'tab_title' => 'x'],
];

$m1 = $gy->matchIdentifier($mrows, 'surface:6');
ok(count($m1) === 1 && $m1[0]['session_id'] === 'aaa222', 'matchIdentifier: surface_ref tier wins');

$m2 = $gy->matchIdentifier($mrows, 'UUID-7');
ok(count($m2) === 1 && $m2[0]['session_id'] === 'bbb333', 'matchIdentifier: surface_id tier');

$m3 = $gy->matchIdentifier($mrows, 'aaa');
ok(count($m3) === 2, 'matchIdentifier: session_id prefix tier returns both, no fallthrough to names');

$m4 = $gy->matchIdentifier($mrows, 'aaa111');
ok(count($m4) === 1 && $m4[0]['session_id'] === 'aaa111', 'matchIdentifier: exact session_id beats prefix-of-others');

$m5 = $gy->matchIdentifier($mrows, 'backend');
ok(count($m5) === 2, 'matchIdentifier: name substring tier (weakest), reached only when no ref/id/session matched');

$m6 = $gy->matchIdentifier($mrows, 'nope');
ok($m6 === [], 'matchIdentifier: no match returns []');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
