<?php
namespace JT\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use JT\CLI\Helpers;
use JT\Helpers\Cmux;
use JT\Graveyard;

/**
 * Base test case: hands every test a clean CLI helper, a Cmux, and a Graveyard.
 *
 * The CLI helper is the JT\CLI\Helpers singleton with args reset to [] (no
 * phpunit argv leakage) and output streams reset to the defaults. Streams matter
 * because the helper is a SINGLETON: a test that injects memory streams to
 * swallow prompts (GitPubTest) would otherwise leave every later test's msg()
 * output writing into a dead stream, silently voiding any ob_start()-based
 * output assertion. Reset here, so tests that inject do it after parent::setUp().
 *
 * Cmux + Graveyard are the same objects the bin/ scripts build. helpers.php is
 * loaded once in tests/bootstrap.php.
 */
abstract class TestCase extends BaseTestCase
{
	protected $cli;
	protected Cmux $cmux;
	protected Graveyard $gy;
	protected string $graveyardRoot;

	protected function setUp(): void
	{
		$this->cli  = Helpers::getInstance()->setArgs([]);
		$this->cli->resetStreams();
		$this->cli->forceSilent = false;
		$this->cmux = new Cmux($this->cli);

		// EVERY test gets a throwaway graveyard store, so no test can write into the
		// real ~/.claude-graveyard — or, far worse, tear down a real session that a
		// fixture happens to name. That is not hypothetical: a codex test carried a
		// live pid while codex bury was still refused unconditionally, and it buried
		// and killed a real session the moment bury started working. Defaulting the
		// root here fixes the whole class of that bug rather than one test file.
		// Tests that want their own root still just set GRAVEYARD_ROOT in their setUp.
		$this->graveyardRoot = sys_get_temp_dir() . '/gy-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($this->graveyardRoot, 0777, true);
		putenv('GRAVEYARD_ROOT=' . $this->graveyardRoot);

		// Point every cmux shell-out at a harmless stub so NO test reaches the real
		// binary (dotfiles-3qa). Before this, a test whose path hit Cmux::tree() shelled
		// out for real and, where cmux is absent (jtbot/CI), exit()ed mid-suite. The stub
		// returns an empty-but-valid tree, so liveSessions() is [] — deterministic across
		// machines regardless of what cmux is actually running. A test that needs real
		// cmux data still injects a Cmux subclass; one that wants tree() to fail overrides
		// CMUX_BIN itself. See CLAUDE.md's shelling-seam rule (GODO_DIRMAP_BIN).
		$stub = $this->graveyardRoot . '/cmux-stub';
		file_put_contents($stub, "#!/bin/sh\ncase \"\$1\" in\n  tree) echo '{\"windows\":[]}' ;;\n  *) : ;;\nesac\n");
		chmod($stub, 0755);
		putenv('CMUX_BIN=' . $stub);

		// Router-specific coverage constructs a Graveyard with NullCmux; see
		// Graveyard/GraveyardPageServerContractTest.php. $this->gy is not that shape.
		$this->gy = new Graveyard($this->cli, $this->cmux);
	}

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
		putenv('CMUX_BIN');
		if (isset($this->graveyardRoot) && is_dir($this->graveyardRoot)) {
			$this->rmrf($this->graveyardRoot);
		}
	}

	/** Recursively remove a directory tree created for a test. */
	private function rmrf(string $dir): void
	{
		// Refuse anything outside the temp dir — a bad root must never delete real data.
		$tmp = realpath(sys_get_temp_dir());
		$real = realpath($dir);
		if ($tmp === false || $real === false || !str_starts_with($real, $tmp)) { return; }

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $f) {
			$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		}
		@rmdir($real);
	}
}
