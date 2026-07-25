<?php
/**
 * PHPUnit bootstrap for the dotfiles CLI test suite.
 *
 * Composer's autoloader covers everything: JT\ → src/, JT\Tests\ → tests/.
 * Nothing is hand-required — the last two tool libs (JT\Graveyard, JT\CmuxBak)
 * moved into src/ in Task 4 of the src/ PSR-4 migration (dotfiles-206).
 */

require dirname(__DIR__) . '/vendor/autoload.php';
