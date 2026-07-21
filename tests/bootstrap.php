<?php
/**
 * PHPUnit bootstrap for the dotfiles CLI test suite.
 *
 * Composer's autoloader covers JT\ → src/ (including JT\CLI\Helpers,
 * JT\Helpers\Cmux) and JT\Tests\ → tests/. The tool libs are
 * hand-required until Task 4 of the src/ migration moves them into src/.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bin/graveyard_lib.php';   // JT\Graveyard (until Task 4)
require_once dirname(__DIR__) . '/bin/cmux-bak_lib.php';    // JT\CmuxBak (until Task 4)
require_once dirname(__DIR__) . '/bin/godo_lib.php';        // JT\Godo (until Task 4)
require_once dirname(__DIR__) . '/bin/linux-catchup_lib.php'; // JT\LinuxCatchup (until Task 4)
