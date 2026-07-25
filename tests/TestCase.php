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

	protected function setUp(): void
	{
		$this->cli  = Helpers::getInstance()->setArgs([]);
		$this->cli->resetStreams();
		$this->cli->forceSilent = false;
		$this->cmux = new Cmux($this->cli);
		$this->gy   = new Graveyard($this->cli, $this->cmux);
	}
}
