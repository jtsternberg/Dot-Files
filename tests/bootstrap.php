<?php
/**
 * PHPUnit bootstrap for the dotfiles CLI test suite.
 *
 * composer's autoloader covers the PSR-4 JT\CLI\* classes and the getCli()
 * helper (misc/bootstrap.php, loaded via the "files" autoload).
 *
 * The lib classes below live in deliberately lowercase/snake-cased files
 * (cmux.php, graveyard_lib.php) so they read as hand-loaded companion libs to
 * their bin/ entry scripts. PSR-4's ClassName.php rule cannot map them, so we
 * require them explicitly here — exactly as the entry scripts do at runtime.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// helpers.php is loaded via getCli()'s `require` at runtime; here we load it once
// (require_once) so JT\CLI\Helpers exists without the per-call redeclare that a
// repeated require would cause across many test cases.
$argv = $argv ?? [];                                        // helpers.php ends with setArgs($argv)
require_once dirname(__DIR__) . '/misc/helpers.php';        // JT\CLI\Helpers (+ Exception)
require_once dirname(__DIR__) . '/misc/helpers/cmux.php';   // JT\Helpers\Cmux
require_once dirname(__DIR__) . '/bin/graveyard_lib.php';   // JT\Graveyard
require_once dirname(__DIR__) . '/bin/cmux-bak_lib.php';    // JT\CmuxBak
