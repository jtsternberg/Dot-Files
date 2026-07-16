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

// lastRealActivity: skips synthetic cmux-bak resume pair (later ts) — genuine T1 wins
$tmpSid3 = 'test-sess-synthetic-' . getmypid();
$tmpJsonlPath3 = $cmux->jsonlPathFor($tmpSid3, $tmpCwd);
$genuineTs = '2026-06-05T12:00:00Z';
$resumeTs  = '2026-06-14T16:19:17.795Z';
file_put_contents(
	$tmpJsonlPath3,
	json_encode(['type' => 'user', 'timestamp' => $genuineTs, 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'do the real work']]]]) . "\n"
	. json_encode(['type' => 'user', 'timestamp' => $resumeTs, 'isMeta' => true, 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]]) . "\n"
	. json_encode(['type' => 'assistant', 'timestamp' => $resumeTs, 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'No response requested.']]]]) . "\n"
);
ok($cmux->lastRealActivity($tmpSid3, $tmpCwd) === strtotime($genuineTs), 'lastRealActivity skips synthetic resume pair, returns genuine ts (not later synthetic ts)');
ok($cmux->lastRealActivity($tmpSid3, $tmpCwd) !== strtotime($resumeTs), 'lastRealActivity does not return synthetic resume ts');

// isSyntheticEntry: direct classifier assertions
ok($cmux->isSyntheticEntry(['type' => 'user', 'isMeta' => true, 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]]) === true, 'isSyntheticEntry: true for synthetic user marker');
ok($cmux->isSyntheticEntry(['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'No response requested.']]]]) === true, 'isSyntheticEntry: true for synthetic assistant (model + text)');
ok($cmux->isSyntheticEntry(['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => []]]) === true, 'isSyntheticEntry: true for synthetic model alone');
ok($cmux->isSyntheticEntry(['type' => 'user', 'isMeta' => true, 'message' => ['content' => 'No response requested.']]) === true, 'isSyntheticEntry: true for string-content marker (isMeta)');
ok($cmux->isSyntheticEntry(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'do the real work']]]]) === false, 'isSyntheticEntry: false for genuine user entry');
// A genuine human turn that literally types a marker phrase (no isMeta) must NOT be synthetic.
ok($cmux->isSyntheticEntry(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]]) === false, 'isSyntheticEntry: false for genuine human turn with literal marker text (no isMeta)');
ok($cmux->isSyntheticEntry(['type' => 'assistant', 'message' => ['model' => 'claude-opus-4', 'content' => [['type' => 'text', 'text' => 'Here is the answer.']]]]) === false, 'isSyntheticEntry: false for genuine assistant entry');
// Slash-command turns (/export etc.) are non-activity — invocation, stdout, and caveat (some carry isMeta, some do not).
ok($cmux->isSyntheticEntry(['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => "<command-name>/export</command-name>\n            <command-message>export</command-message>"]]]]) === true, 'isSyntheticEntry: true for <command-name> invocation turn');
ok($cmux->isSyntheticEntry(['type' => 'user', 'message' => ['content' => '<local-command-stdout>Conversation exported to: /tmp/x.txt</local-command-stdout>']]) === true, 'isSyntheticEntry: true for <local-command-stdout> turn');
ok($cmux->isSyntheticEntry(['type' => 'user', 'isMeta' => true, 'message' => ['content' => [['type' => 'text', 'text' => '<local-command-caveat>Caveat: The messages below were generated by the user while running local commands.</local-command-caveat>']]]]) === true, 'isSyntheticEntry: true for <local-command-caveat> turn (isMeta)');
// A genuine human turn that merely mentions a command tag mid-sentence is NOT a command turn.
ok($cmux->isSyntheticEntry(['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'please run <command-name>/export</command-name> for me']]]]) === false, 'isSyntheticEntry: false when command tag is not at the start of the turn');

@unlink($tmpJsonlPath);
@unlink($tmpJsonlPath2);
@unlink($tmpJsonlPath3);
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
// Columns: session_id, idle, busy|idle, targetable|UNTARGETABLE, workspace_title, cwd, reason
$idleRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => false, 'targetable' => true, 'reason' => '', 'workspace_title' => 'proj', 'cwd' => '/x'];
ok($gy->formatCandidatePorcelain($idleRow) === "abc\t3600\tidle\ttargetable\tproj\t/x\t", 'formatCandidatePorcelain idle row');
$busyRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => true, 'targetable' => true, 'reason' => '', 'workspace_title' => 'proj', 'cwd' => '/x'];
ok($gy->formatCandidatePorcelain($busyRow) === "abc\t3600\tbusy\ttargetable\tproj\t/x\t", 'formatCandidatePorcelain busy row');
$untRow = ['session_id' => 'abc', 'idle_seconds' => 3600, 'busy' => false, 'targetable' => false, 'reason' => 'collision', 'workspace_title' => 'proj', 'cwd' => '/x'];
ok($gy->formatCandidatePorcelain($untRow) === "abc\t3600\tidle\tUNTARGETABLE\tproj\t/x\tcollision", 'formatCandidatePorcelain untargetable row');

// buryIds([]) is a clean no-op (guard) — should not throw
$threwBuryIds = false;
try { $gy->buryIds([], false); } catch (\Throwable $e) { $threwBuryIds = true; }
ok(!$threwBuryIds, 'buryIds([]) is a no-op, does not throw');

// buryIds([], false, true) — force param variant — also a clean no-op (guard)
$threwBuryIdsForce = false;
try { $gy->buryIds([], false, true); } catch (\Throwable $e) { $threwBuryIdsForce = true; }
ok(!$threwBuryIdsForce, 'buryIds([], false, true) is a no-op, does not throw');

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

// parseReplSelection: tokens, ranges, dedup, out-of-range ignored
ok($gy->parseReplSelection('1 3 5', 5) === [0, 2, 4], 'parseReplSelection: simple tokens');
ok($gy->parseReplSelection('2-4', 5) === [1, 2, 3], 'parseReplSelection: range');
ok($gy->parseReplSelection('1 1 2-3', 5) === [0, 1, 2], 'parseReplSelection: dedup + mixed tokens/range');
ok($gy->parseReplSelection('9 1', 5) === [0], 'parseReplSelection: out-of-range token ignored');

// ---------------------------------------------------------------------------
// Deterministic session<->surface join (dotfiles-yt2) + verify gates (dotfiles-4ur)
// ---------------------------------------------------------------------------

// parseProcTable
$psRaw = "  PID  PPID COMMAND\n"
	. "  100     1 /usr/bin/login -flp JT /bin/bash --noprofile --norc -c exec -l /bin/zsh '/tmp/cmux-surface-resume/claude-UUID1.zsh'\n"
	. "  200   100 -/bin/zsh /tmp/cmux-surface-resume/claude-UUID1.zsh\n"
	. "  300   200 /opt/homebrew/bin/claude --resume 11111111-1111-1111-1111-111111111111 --dangerously-skip-permissions\n"
	. "  400     1 /usr/bin/login -flp JT /bin/bash -c exec -l /bin/zsh '/tmp/cmux-agent-resume/claude-22222222-abc-UUID2.zsh'\n"
	. "  500   400 /opt/homebrew/bin/claude --resume 22222222-2222-2222-2222-222222222222\n";
$proc = $cmux->parseProcTable($psRaw);
ok(isset($proc[300]) && $proc[300]['ppid'] === 200, 'parseProcTable: pid/ppid parsed');
ok($cmux->isClaudeCommand($proc[300]['cmd']) === true, 'isClaudeCommand: true for claude binary');
ok($cmux->isClaudeCommand($proc[200]['cmd']) === false, 'isClaudeCommand: false for zsh running claude-*.zsh');
ok($cmux->descendantClaudePid($proc, 100) === 300, 'descendantClaudePid: finds claude under login/zsh');
ok($cmux->descendantPids($proc, 100) === [100, 200, 300] || $cmux->descendantPids($proc, 100) == [100,200,300], 'descendantPids: whole subtree');
ok($cmux->ancestorResumeScript($proc, 300) === 'claude-UUID1.zsh', 'ancestorResumeScript: walks up to resume script');
ok($cmux->claudeResumeArg($proc[300]['cmd']) === '11111111-1111-1111-1111-111111111111', 'claudeResumeArg: extracts --resume id');
ok($cmux->claudeResumeArg('/opt/homebrew/bin/claude') === null, 'claudeResumeArg: null when no --resume');

// parseDebugTerminals
$dtRaw = "[0] surface:59 \"lg\" mapped=1 tree=1 window=window:2 workspace=workspace:22 pane=pane:38 ctx=split\n"
	. "    runtime=1 focused=0\n"
	. "    tty=ttys052 cwd=/Users/JT/Code/asana-cli branch=main* ports=[]\n"
	. "    created=1s initialCommand=/bin/zsh '/tmp/cmux-surface-resume/claude-UUID1.zsh' portalHost=nil\n"
	. "[1] surface:70 \"other\" mapped=1 tree=1 window=window:1 workspace=workspace:9 pane=pane:5 ctx=split\n"
	. "    tty=ttys052 cwd=/Users/JT/Boss branch=nil ports=[]\n"
	. "    created=2s initialCommand=/bin/zsh '/tmp/cmux-agent-resume/claude-22222222-abc-UUID2.zsh' portalHost=nil\n";
$dbg = $cmux->parseDebugTerminals($dtRaw);
ok(isset($dbg['surface:59']) && $dbg['surface:59']['tty'] === 'ttys052', 'parseDebugTerminals: tty');
ok($dbg['surface:59']['cwd'] === '/Users/JT/Code/asana-cli', 'parseDebugTerminals: cwd');
ok($dbg['surface:59']['workspace_ref'] === 'workspace:22', 'parseDebugTerminals: workspace ref');
ok($dbg['surface:59']['script'] === 'claude-UUID1.zsh', 'parseDebugTerminals: resume script');
ok($dbg['surface:70']['script'] === 'claude-22222222-abc-UUID2.zsh', 'parseDebugTerminals: agent-resume script');

// joinSessionsToSurfaces — the core fix. Two surfaces share tty ttys052; join must
// bind each session to the RIGHT surface via ancestry, not tty.
$sessions = [
	300 => ['session_id' => '11111111-1111-1111-1111-111111111111', 'cwd' => '/Users/JT/Code/asana-cli', 'model' => 'opus', 'skip_perms' => true],
	500 => ['session_id' => '22222222-2222-2222-2222-222222222222', 'cwd' => '/Users/JT/Boss', 'model' => null, 'skip_perms' => false],
];
$joined = $cmux->joinSessionsToSurfaces($sessions, $proc, $dbg);
$bySid = [];
foreach ($joined as $r) { $bySid[$r['session_id']] = $r; }
ok($bySid['11111111-1111-1111-1111-111111111111']['surface_ref'] === 'surface:59', 'join: session bound to correct surface via ancestry (not tty)');
ok($bySid['11111111-1111-1111-1111-111111111111']['targetable'] === true, 'join: unambiguous session is targetable');
ok($bySid['11111111-1111-1111-1111-111111111111']['pid'] === 300, 'join: pid is the claude pid');
ok($bySid['22222222-2222-2222-2222-222222222222']['surface_ref'] === 'surface:70', 'join: second session -> its own surface');

// join: --resume mismatch => untargetable
$badProc = $proc;
$badProc[300]['cmd'] = '/opt/homebrew/bin/claude --resume 99999999-9999-9999-9999-999999999999';
$joinedBad = $cmux->joinSessionsToSurfaces($sessions, $badProc, $dbg);
$badRow = null; foreach ($joinedBad as $r) { if ($r['session_id'] === '11111111-1111-1111-1111-111111111111') { $badRow = $r; } }
ok($badRow['targetable'] === false && str_contains($badRow['reason'], '--resume'), 'join: --resume mismatch marks untargetable');

// join: shared resume script (two surfaces, same script) => ambiguous/untargetable
$dupDbg = $dbg;
$dupDbg['surface:70']['script'] = 'claude-UUID1.zsh';
$joinedDup = $cmux->joinSessionsToSurfaces($sessions, $proc, $dupDbg);
$dupRow = null; foreach ($joinedDup as $r) { if ($r['session_id'] === '11111111-1111-1111-1111-111111111111') { $dupRow = $r; } }
ok($dupRow['targetable'] === false && str_contains($dupRow['reason'], 'ambiguous'), 'join: script shared by >1 surface -> untargetable');

// treeIndex
$tree = ['windows' => [['workspaces' => [
	['ref' => 'workspace:22', 'title' => 'asana', 'panes' => [['surfaces' => [
		['ref' => 'surface:59', 'id' => 'UUID-59', 'title' => 'lg'],
	]]]],
]]]];
$ix = $gy->treeIndex($tree);
ok($ix['workspace']['workspace:22'] === 'asana', 'treeIndex: workspace title');
ok($ix['surface']['surface:59']['id'] === 'UUID-59', 'treeIndex: surface id');

// GATE 1: statusline cwd match
ok($gy->extractStatuslineCwd('foo 📁 /asana-cli | 🌿 main') === '/asana-cli', 'extractStatuslineCwd');
ok($gy->statuslineMatchesSession('… 📁 /asana-cli | 🌿 main', '/Users/JT/Code/asana-cli') === true, 'gate1: basename match passes');
ok($gy->statuslineMatchesSession('… 📁 /Boss', '/Users/JT/Code/asana-cli') === false, 'gate1: cwd mismatch blocks');
ok($gy->statuslineMatchesSession('a plain shell prompt $', '/Users/JT/Code/asana-cli') === false, 'gate1: no REPL statusline blocks');

// GATE 1 regression: SPACED paths (the Hankinsville/Southport UDO bug). extractStatuslineCwd
// must not stop at the first space, and matching must be robust to spaces / ~ / elision.
ok($gy->extractStatuslineCwd('[Opus] | 📁 /Southport UDO | 🌿 main') === '/Southport UDO', 'extractStatuslineCwd: keeps spaced path (not "/Southport")');
ok($gy->extractStatuslineCwd('📁 /Southport UDO') === '/Southport UDO', 'extractStatuslineCwd: spaced path, no trailing separator');
ok($gy->extractStatuslineCwd("📁 /a b c │ x") === '/a b c', 'extractStatuslineCwd: stops at box-drawing │ separator');
ok($gy->statuslineMatchesSession('📁 /Southport UDO | 🌿 m', '/Users/JT/Documents/Southport UDO') === true, 'gate1: spaced basename matches (Hankinsville repro)');
ok($gy->statuslineMatchesSession('📁 ~/Documents/Southport UDO', '/Users/JT/Documents/Southport UDO') === true, 'gate1: ~-abbreviated multi-component suffix matches');
ok($gy->statuslineMatchesSession('📁 …/Documents/Southport UDO | x', '/Users/JT/Documents/Southport UDO') === true, 'gate1: elided leading components (…) match by suffix');
ok($gy->statuslineMatchesSession('📁 /Southport UDO', '/Users/JT/Documents/Northport UDO') === false, 'gate1: different spaced dir still blocks');
ok($gy->statuslineMatchesSession('📁 /UDO', '/Users/JT/Documents/Southport UDO') === false, 'gate1: partial last-component (not a full component) does not match');

// -y / --force must NOT bypass a gate refusal — only the confirm prompt. A Graveyard
// whose surface always shows a MISMATCHED statusline must still be refused at gate 1
// even with force=true AND autoConfirm=true, and must write no tombstone.
$stub = new class($cli, $cmux) extends Graveyard {
	public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string {
		return '[Opus] | 📁 /totally-different-dir | 🌿 main';
	}
};
$fakeSess = ['session_id' => 'zztest-gate1', 'cwd' => '/Users/JT/x', 'surface_ref' => 'surface:1',
	'workspace_ref' => 'ws:1', 'targetable' => true, 'reason' => '', 'idle_seconds' => 999999,
	'tab_title' => 't', 'workspace_title' => 'w', 'pid' => 0, 'model' => null, 'skip_perms' => false];
ok($stub->buryOne($fakeSess, true, true) === false, '-y/force does NOT bypass gate 1 (mismatched statusline refused)');
ok(!is_file($stub->metaPath('zztest-gate1')), 'gate-1 refusal under -y/force writes no tombstone');

// pathTailComponents
ok($gy->pathTailComponents('/Users/JT/Documents/Southport UDO') === ['Users','JT','Documents','Southport UDO'], 'pathTailComponents: spaced component preserved');
ok($gy->pathTailComponents('~/Code/asana-cli') === ['Code','asana-cli'], 'pathTailComponents: drops leading ~');
ok($gy->pathTailComponents('…/a/b') === ['a','b'], 'pathTailComponents: drops elision marker');

// Whitespace-on-path sweep (same family as phase-1 encodeProjectKey): a spaced cwd must
// round-trip to a valid project key and jsonl path with no space-splitting.
ok($cmux->encodeProjectKey('/Users/JT/Documents/Southport UDO') === '-Users-JT-Documents-Southport-UDO', 'sweep: encodeProjectKey handles spaces');
ok(strpos($cmux->jsonlPathFor('sid', '/Users/JT/Documents/Southport UDO'), 'Southport-UDO/sid.jsonl') !== false, 'sweep: jsonlPathFor handles spaced cwd');

// GATE 2: transcript belongs to session
ok($gy->transcriptMatchesSession("… conversation …\n> Fix the login bug now\n…", 'Fix the login bug now') === true, 'gate2: matching first prompt passes');
ok($gy->transcriptMatchesSession("some other session entirely", 'Fix the login bug now') === false, 'gate2: mismatched transcript blocks');
ok($gy->transcriptMatchesSession("anything", '') === true, 'gate2: empty needle cannot assert (no block)');
// gate2: a slash-command session's needle is the tag-stripped summary ("/foo"), which is
// how /export renders it — must match (regression: raw <command-*> tags never appear rendered).
ok($gy->transcriptMatchesSession("❯ /monorepo-address-pr-review\n  ⎿ …", '/monorepo-address-pr-review') === true, 'gate2: slash-command summary needle matches rendered transcript');

// GATE 2 tail-anchored matching (compaction / bridging / caveat-opening fix, dotfiles-c8a).
// genuineTurns: skips synthetic/command/tool-only; keeps role+text.
$g2entries = [
	['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => '<local-command-caveat>Caveat: machine noise']]]], // caveat → skipped by isSyntheticEntry
	['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'the original first request about widgets']]]],
	['type' => 'user', 'isMeta' => true, 'message' => ['content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]], // synthetic
	['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Published the review with two findings fixed']]]],
	['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'review again, copilot made updates']]]],
];
$gt = $gy->genuineTurns($g2entries);
ok(count($gt) === 3, 'genuineTurns: drops caveat + synthetic + keeps 3 genuine');
ok($gt[0]['text'] === 'the original first request about widgets', 'genuineTurns: first genuine text');

// A compacted export contains only RECENT turns (not the first). Tail-anchored gate 2 passes.
$compactedExport = "…summary…\n❯ review again, copilot made updates\n⏺ Published the review with two findings fixed\n";
$needles = array_map(fn($t) => $t['text'], $gt);
ok($gy->transcriptBelongsToSession($compactedExport, $needles) === true, 'gate2: passes when a RECENT turn matches even though first turn is absent (compaction)');
ok($gy->transcriptBelongsToSession("an entirely unrelated session transcript", $needles) === false, 'gate2: refuses a genuine mis-target (no recent turn matches)');
ok($gy->transcriptBelongsToSession("anything", []) === true, 'gate2: no needles → cannot assert → does not block');
ok($gy->transcriptBelongsToSession("anything", ['   ', '']) === true, 'gate2: only-blank needles → does not block');

// ---------------------------------------------------------------------------
// peek: renderTurns (dotfiles-48w) — pure rendering of genuine JSONL turns
// ---------------------------------------------------------------------------
$entries = [
	['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => "<command-name>/superpowers:brainstorm</command-name>"]]]], // command → "/superpowers:brainstorm"
	['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => "Let's brainstorm the asana skill."]]]],
	['type' => 'user', 'isMeta' => true, 'message' => ['content' => [['type' => 'text', 'text' => 'Continue from where you left off.']]]], // synthetic → skipped
	['type' => 'assistant', 'message' => ['model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'No response requested.']]]], // synthetic → skipped
	['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'Add a sync subcommand']]]],
	['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Bash']]]], // tool-only → skipped (no text)
	['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Done — added sync.']]]],
];
$r = $gy->renderTurns($entries, 10);
ok(strpos($r, '/superpowers:brainstorm') === false, 'renderTurns: slash-command turn skipped as noise');
ok(strpos($r, '❯ Add a sync subcommand') !== false, 'renderTurns: genuine user turn shown');
ok(strpos($r, '⏺ Done — added sync.') !== false, 'renderTurns: assistant text shown');
ok(strpos($r, 'Continue from where you left off') === false, 'renderTurns: synthetic user resume skipped');
ok(strpos($r, 'No response requested') === false, 'renderTurns: synthetic assistant skipped');
ok(substr_count($r, "\n") === 3, 'renderTurns: 3 genuine turns (command + 2 synthetic + tool-only skipped)');
$r2 = $gy->renderTurns($entries, 2);
ok(substr_count($r2, "\n") === 2 && strpos($r2, 'Done — added sync.') !== false, 'renderTurns: honors last-N limit');
ok($gy->renderTurns([], 6) === '', 'renderTurns: empty entries → empty string');
$long = ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => str_repeat('x', 300)]]]];
ok(mb_substr(trim($gy->renderTurns([$long], 6)), -1) === '…', 'renderTurns: long turn truncated with ellipsis');

// ---------------------------------------------------------------------------
// Workspace-level (grouped) bury (dotfiles-c8a)
// ---------------------------------------------------------------------------
// uuidv4 shape
$uu = $cmux->uuidv4();
ok((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uu), 'uuidv4: valid v4 shape');
ok($cmux->uuidv4() !== $cmux->uuidv4(), 'uuidv4: unique');

// resolveWorkspaceNode: ref, title substring, ambiguity
$wtree = ['windows' => [['ref' => 'window:1', 'workspaces' => [
	['ref' => 'workspace:9', 'title' => 'asana-skill update', 'panes' => []],
	['ref' => 'workspace:12', 'title' => 'boss backend', 'panes' => []],
	['ref' => 'workspace:13', 'title' => 'boss frontend', 'panes' => []],
]]]];
ok($cmux->resolveWorkspaceNode($wtree, 'workspace:12')['title'] === 'boss backend', 'resolveWorkspaceNode: exact ref');
ok($cmux->resolveWorkspaceNode($wtree, 'asana')['ref'] === 'workspace:9', 'resolveWorkspaceNode: title substring');
ok($cmux->resolveWorkspaceNode($wtree, 'nope') === null, 'resolveWorkspaceNode: no match → null');
$threwWs = false; try { $cmux->resolveWorkspaceNode($wtree, 'boss'); } catch (\RuntimeException $e) { $threwWs = true; }
ok($threwWs, 'resolveWorkspaceNode: ambiguous title throws');

// classifyWorkspaceLayout: claude member (bound), untargetable claude (fresh), shell, browser
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
$c = $gy->classifyWorkspaceLayout($wsNode, $liveByRef, $isClaudeByRef);
ok(count($c['members']) === 1 && $c['members'][0]['session_id'] === 'sid-1', 'classify: one bound claude member');
ok($c['members'][0]['group_pos'] === 0, 'classify: member carries group_pos');
ok(count($c['untargetable']) === 1 && $c['untargetable'][0]['ref'] === 'surface:2', 'classify: fresh claude → untargetable (abort trigger)');
ok(count($c['layout']) === 4, 'classify: full layout captured');
$kinds = array_column($c['layout'], 'kind');
ok($kinds === ['claude', 'claude-untargetable', 'shell', 'browser'], 'classify: per-surface kinds correct');
ok($c['layout'][3]['url'] === 'https://x', 'classify: browser url recorded');

// classify: a cmux-native agentSession surface (no tty, not in isClaudeByRef) must be
// treated as Claude (untargetable → abort), NEVER as a shell that gets silently closed.
$wsAgent = ['panes' => [['index' => 0, 'surfaces' => [
	['ref' => 'surface:9', 'type' => 'agentSession', 'title' => 'Claude Code · React', 'index_in_pane' => 0],
]]]];
$ca = $gy->classifyWorkspaceLayout($wsAgent, [], []); // note: not in isClaudeByRef
ok(count($ca['untargetable']) === 1 && $ca['layout'][0]['kind'] === 'claude-untargetable', 'classify: agentSession → claude-untargetable (never shell)');

// untargetableReasonFor: specific reason per fact-set (dotfiles product-gap ask)
ok(str_contains($gy->untargetableReasonFor(['type' => 'agentSession']), 'cmux-native agent session'), 'reason: native agent session');
ok(str_contains($gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => true, 'has_claude' => false]), 'not running'), 'reason: resumed Claude exited (surface:34 case)');
ok(str_contains($gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => false]), 'no live shell'), 'reason: stale surface, no shell');
ok(str_contains($gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => true, 'has_claude' => true, 'has_session_file' => false]), 'no session file yet'), 'reason: running but no conversation');
ok(str_contains($gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => false]), 'no unique statusline-cwd match'), 'reason: fresh, no unique cwd match');
ok(str_contains($gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => false, 'cwd_conflict' => true]), 'multiple sessions'), 'reason: fresh, ambiguous cwd');
ok(str_contains($gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => true, 'has_claude' => true, 'has_session_file' => true, 'session_id' => '93de80a4-x', 'bound_elsewhere' => 'surface:33']), 'duplicate live view of session 93de80a4') && str_contains($gy->untargetableReasonFor(['type' => 'terminal', 'has_script' => true, 'has_shell' => true, 'has_claude' => true, 'has_session_file' => true, 'session_id' => '93de80a4-x', 'bound_elsewhere' => 'surface:33']), 'surface:33'), 'reason: duplicate view (surface:34 case)');

// groupTombstones + tombstoneLine
$ts = [
	['session_id' => 'aaaa1111', 'group_id' => 'g1', 'group_pos' => 1, 'group_title' => 'ws', 'buried_at' => '2026-07-14', 'workspace_title' => 'ws', 'summary' => 's2'],
	['session_id' => 'bbbb2222', 'group_id' => 'g1', 'group_pos' => 0, 'group_title' => 'ws', 'buried_at' => '2026-07-14', 'workspace_title' => 'ws', 'summary' => 's1'],
	['session_id' => 'cccc3333', 'buried_at' => '2026-07-13', 'workspace_title' => 'loose', 'summary' => 's3'],
];
[$groups, $loose] = $gy->groupTombstones($ts);
ok(count($groups['g1']) === 2 && count($loose) === 1, 'groupTombstones: splits grouped vs loose');
ok($groups['g1'][0]['session_id'] === 'bbbb2222', 'groupTombstones: members sorted by group_pos');
// (tombstoneLine removed — replaced by width-aware lsEntryLines below)

// ---------------------------------------------------------------------------
// contentProbeBind fallback (dotfiles-c15)
// ---------------------------------------------------------------------------
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
$b = $gy->contentProbeBind($fresh, $unbound, $screens);
ok(($b['f-1'] ?? null) === 'surface:1' && ($b['f-2'] ?? null) === 'surface:2', 'contentProbeBind: unique cwd matches bind');

// ambiguous: two surfaces show the same cwd → no bind unless tty breaks the tie
$fresh2 = [['session_id' => 'f-3', 'cwd' => '/Users/JT/Code/asana-cli', 'tty' => 'ttysZZ']];
$unbound2 = ['surface:1' => ['tty' => 'ttysAA'], 'surface:2' => ['tty' => 'ttysBB']];
$screens2 = ['surface:1' => '📁 /asana-cli', 'surface:2' => '📁 /asana-cli'];
ok($gy->contentProbeBind($fresh2, $unbound2, $screens2) === [], 'contentProbeBind: ambiguous cwd (no tty match) → no bind');

// tty tiebreak: same cwd on two surfaces, session tty matches exactly one
$fresh3 = [['session_id' => 'f-4', 'cwd' => '/x', 'tty' => 'ttysBB']];
$unbound3 = ['surface:1' => ['tty' => 'ttysAA'], 'surface:2' => ['tty' => 'ttysBB']];
$screens3 = ['surface:1' => '📁 /x', 'surface:2' => '📁 /x'];
ok(($gy->contentProbeBind($fresh3, $unbound3, $screens3)['f-4'] ?? null) === 'surface:2', 'contentProbeBind: tty breaks a cwd tie');

// no surface matches → no bind
ok($gy->contentProbeBind([['session_id' => 'f-5', 'cwd' => '/nope', 'tty' => 't']], $unbound, $screens) === [], 'contentProbeBind: no cwd match → no bind');

// ---------------------------------------------------------------------------
// Width-aware output formatting (dotfiles-rgk) — 60/80/120 cols via injected width
// ---------------------------------------------------------------------------
// ellipsize / ellipsizeLeft
ok($gy->ellipsizeText('hello world', 8) === 'hello w…', 'ellipsizeText: truncates with …');
ok($gy->ellipsizeText('short', 20) === 'short', 'ellipsizeText: no-op when it fits');
ok($gy->ellipsizeLeft('/a/b/c/deep', 6) === '…/deep', 'ellipsizeLeft: keeps the tail');

// shortenCwd: home→~, elide middle, always ≤ max
$home = '/Users/JT';
ok($gy->shortenCwd('/Users/JT/Sites/x', $home, 40) === '~/Sites/x', 'shortenCwd: home → ~');
$long = '/Users/JT/Sites/lindris-monorepo/local-frontend/lindris-frontend';
foreach ([20, 30, 40] as $mx) {
	$s = $gy->shortenCwd($long, $home, $mx);
	ok(mb_strlen($s) <= $mx, "shortenCwd: fits within $mx");
	ok(str_contains($s, 'lindris-frontend') || str_contains($s, '…'), "shortenCwd: keeps tail or elides at $mx");
}

// titleizeSummary: prefer clean human text; fall back off bare slash-command / noise
ok($gy->titleizeSummary(['summary' => '/hotline:ringing [CALL_ID: a0be3ca9] [MODE: quick_call]', 'tab_title' => '✳ Hotline call with lindris', 'workspace_title' => 'ws']) === 'Hotline call with lindris', 'titleize: bare slash-command falls back to session title');
ok($gy->titleizeSummary(['summary' => 'add a sync subcommand to the CLI', 'tab_title' => 'Terminal', 'workspace_title' => 'ws']) === 'add a sync subcommand to the CLI', 'titleize: prefers clean human summary');
ok($gy->titleizeSummary(['summary' => '', 'tab_title' => 'Terminal', 'workspace_title' => 'my-workspace']) === 'my-workspace', 'titleize: falls back to workspace title');
// caveat-family: summarizeUserText must skip a <local-command-caveat> turn (the fe4e5b02 bug),
// and titleize must not surface a leaked "Caveat: The messages below…" summary.
ok($gy->summarizeUserText('<local-command-caveat>Caveat: The messages below were generated by the user') === '', 'summarizeUserText: skips <local-command-caveat> turn');
ok($gy->summarizeUserText('<command-args>1234 careful</command-args>') === '', 'summarizeUserText: skips bare <command-args> turn');
ok($gy->titleizeSummary(['summary' => 'Caveat: The messages below were generated by the user while running local commands.', 'tab_title' => '✳ PR Review Checklist', 'workspace_title' => 'ws']) === 'PR Review Checklist', 'titleize: leaked caveat summary falls back to session title');

// lsEntryLines: NEVER exceeds width, no wrap, at 60/80/120 with a nasty spaced/long entry
$tomb = ['session_id' => 'abcd1234ef', 'buried_at' => '2026-07-15',
	'summary' => '/hotline:ringing [CALL_ID: a0be] [MODE: quick_call]', 'tab_title' => '✳ Review property spec for accessory dwelling unit rezoning',
	'workspace_title' => 'Hankinsville ADU feasibility', 'cwd' => '/Users/JT/Documents/Southport UDO'];
foreach ([60, 80, 120] as $W) {
	foreach ([0, 4] as $indent) {
		$e = $gy->lsEntryLines($tomb, $W, $home, $indent);
		ok(mb_strlen($e['primary']) <= $W, "lsEntryLines: primary ≤ $W (indent $indent)");
		ok($e['secondary'] === null || mb_strlen($e['secondary']) <= $W, "lsEntryLines: secondary ≤ $W (indent $indent)");
	}
	ok(mb_strlen($gy->groupHeaderLine('Hankinsville ADU feasibility', 3, '2026-07-15', $W)) <= $W, "groupHeaderLine ≤ $W");
	$cand = ['session_id' => 'abcd1234ef', 'idle_seconds' => 5875200, 'busy' => false, 'targetable' => true,
		'tab_title' => '✳ Review property spec for accessory dwelling', 'workspace_title' => 'Hankinsville', 'cwd' => $long];
	ok(mb_strlen($gy->candidateLine($cand, $W, $home)) <= $W, "candidateLine ≤ $W");
}
// stacked vs single-line: narrow stacks (secondary present), wide is single line
ok($gy->lsEntryLines($tomb, 60, $home, 0)['secondary'] !== null, 'lsEntryLines: narrow (60) stacks to 2 lines');
ok($gy->lsEntryLines($tomb, 120, $home, 0)['secondary'] === null, 'lsEntryLines: wide (120) is single line');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
