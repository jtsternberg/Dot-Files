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

// buildResumeCommand
ok($cmux->buildResumeCommand('abc', false, null) === 'claude --resume abc', 'resume plain');
ok($cmux->buildResumeCommand('abc', true, 'opus') === 'claude --dangerously-skip-permissions --resume abc --model=opus', 'resume flags');

ok(method_exists($cmux, 'newWorkspace'), 'Cmux::newWorkspace exists');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
