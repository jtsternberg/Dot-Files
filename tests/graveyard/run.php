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

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
