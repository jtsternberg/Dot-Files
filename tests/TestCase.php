<?php
namespace JT\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use JT\CLI\Helpers;
use JT\Helpers\Cmux;
use JT\Transport\CmuxTransport;
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
 * Cmux, its CmuxTransport, and Graveyard are the same objects bin/graveyard builds.
 * Graveyard takes the TRANSPORT, not the Cmux — pass $this->transport (or wrap your own
 * Cmux double in a CmuxTransport). helpers.php is loaded once in tests/bootstrap.php.
 */
abstract class TestCase extends BaseTestCase
{
	protected $cli;
	protected Cmux $cmux;
	protected CmuxTransport $transport;
	protected Graveyard $gy;
	protected string $graveyardRoot;

	protected function setUp(): void
	{
		$this->cli  = Helpers::getInstance()->setArgs([]);
		$this->cli->resetStreams();
		$this->cli->forceSilent      = false;
		$this->cli->forceInteractive = null;
		$this->cmux = new Cmux($this->cli);
		$this->transport = new CmuxTransport($this->cli, $this->cmux);

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
		putenv('CMUX_BIN=' . self::sharedStub('cmux', "#!/bin/sh\ncase \"\$1\" in\n  tree) echo '{\"windows\":[]}' ;;\n  *) : ;;\nesac\n"));

		// Liveness never reads the real machine either. Cmux::loadClaudeSessionsByPid()
		// walks ~/.claude/sessions (and ps's every live pid), and loadCodexSessionsByPid()
		// runs ps then lsof per live codex — ~0.6s for every test whose path reaches
		// tombstones(), and a result that depended on what agents happened to be running.
		mkdir($this->graveyardRoot . '/no-live-claude-sessions');
		putenv('CLAUDE_SESSIONS_DIR=' . $this->graveyardRoot . '/no-live-claude-sessions');
		putenv('PROC_PS_BIN=' . self::sharedStub('ps', "#!/bin/sh\necho '  PID  PPID COMMAND'\n"));

		// Same reasoning for launchctl (dotfiles LocalModels): OllamaEngine's
		// post-flip hook runs `launchctl setenv OLLAMA_MODELS <path>`. Any test
		// that flips a store with a temp $home and no stub sets the REAL launchd
		// variable to a temp directory that is deleted at tearDown — so the next
		// Ollama.app launch looks for its models in a path that no longer exists.
		// That is not hypothetical: it happened, and it took a manual
		// `launchctl setenv` to repair. Stub it for EVERY test, like CMUX_BIN.
		putenv('AIMODELS_LAUNCHCTL_BIN=' . self::sharedStub('launchctl', "#!/bin/sh\nexit 0\n"));

		// And for `defaults`: a MacWhisper flip rewrites the app's selected model.
		// Exit 1 reads as "key not set", so an un-faked engine never touches it.
		putenv('AIMODELS_DEFAULTS_BIN=' . self::sharedStub('defaults', "#!/bin/sh\nexit 1\n"));

		// Router-specific coverage constructs a Graveyard with NullTransport; see
		// Graveyard/GraveyardPageServerContractTest.php. $this->gy is not that shape.
		$this->gy = new Graveyard($this->cli, $this->transport);
	}

	protected function tearDown(): void
	{
		putenv('GRAVEYARD_ROOT');
		putenv('CMUX_BIN');
		putenv('CLAUDE_SESSIONS_DIR');
		putenv('PROC_PS_BIN');
		putenv('AIMODELS_LAUNCHCTL_BIN');
		putenv('AIMODELS_DEFAULTS_BIN');
		if (isset($this->graveyardRoot) && is_dir($this->graveyardRoot)) {
			$this->rmrf($this->graveyardRoot);
		}
	}

	/**
	 * A read-only stub executable, written once and reused by every test that asks
	 * for the same body. Not per-test: macOS assesses each NEW executable on its first
	 * exec (~0.2s), so fresh stubs in every test's root cost ~0.4s a test across the
	 * suite. Content-addressed, so a changed body is a new file, never a stale one.
	 */
	protected static function sharedStub(string $name, string $body): string
	{
		$dir = sys_get_temp_dir() . '/jt-test-stubs';
		$path = $dir . '/' . $name . '-' . substr(sha1($body), 0, 12);
		if (is_file($path)) { return $path; }
		@mkdir($dir, 0777, true);
		$tmp = $path . '.' . getmypid() . '.tmp';
		file_put_contents($tmp, $body);
		chmod($tmp, 0755);
		rename($tmp, $path); // atomic: a parallel run never execs a half-written stub
		return $path;
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
