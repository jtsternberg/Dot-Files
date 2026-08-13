<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;
use JT\CLI\Helpers;

/**
 * CLI\Helpers argument parsing.
 *
 * setArgs() is "set the args", not "append" — a second call must start from a
 * clean slate so parsed flags never leak across invocations. This matters for
 * the reused singleton in tests and for any process that calls setArgs twice.
 */
class HelpersTest extends TestCase
{
	public function testSetArgsResetsFlagsFromPreviousCall(): void
	{
		$cli = Helpers::getInstance();

		$cli->setArgs(['tool', '--host=one', '-y']);
		$this->assertSame('one', $cli->getFlag('host'));
		$this->assertTrue($cli->hasShortFlag('y'));

		// A fresh call with none of those flags must drop them entirely.
		$cli->setArgs(['tool', 'positional']);
		$this->assertFalse($cli->hasFlag('host'), '--host must not leak');
		$this->assertFalse($cli->hasShortFlag('y'), '-y must not leak');
		$this->assertSame('positional', $cli->getArg(1));
	}

	/**
	 * setStreams() reads null as "leave this one alone", so injecting a stream was a
	 * one-way door on a SINGLETON: every later caller kept writing into the injected
	 * stream. resetStreams() is the way back to the default echo/STDERR behavior — the
	 * suite's base TestCase calls it so one test's swallowed output cannot silently void
	 * another test's output assertion.
	 */
	public function testResetStreamsRestoresDefaultOutput(): void
	{
		$cli  = Helpers::getInstance();
		$sink = fopen('php://memory', 'w+');
		$cli->setStreams($sink, $sink);

		ob_start();
		$cli->msg('into the sink');
		$this->assertSame('', ob_get_clean(), 'injected stream must bypass output buffering');

		$cli->resetStreams();

		ob_start();
		$cli->msg('back to stdout');
		$this->assertStringContainsString('back to stdout', (string) ob_get_clean());

		fclose($sink);
	}

	public function testSetArgsParsesLongShortAndPositional(): void
	{
		$cli = Helpers::getInstance()->setArgs(['tool', 'name', '--host=h', '--yes', '-v']);

		$this->assertSame('name', $cli->getArg(1));
		$this->assertSame('h', $cli->getFlag('host'));
		$this->assertTrue($cli->hasFlag('yes'));
		$this->assertSame('', $cli->getFlag('yes'), 'valueless long flag is empty string');
		$this->assertTrue($cli->hasShortFlag('v'));
	}

	/**
	 * isYes/isNo are a matched pair: both lowercase the answer, so both word
	 * forms must be listed lowercase. isNo listed 'No' (capital N) — unreachable
	 * after strtolower — so "no"/"No"/"NO" all read as NOT-no. That is not
	 * academic: gtag uses isNo() to detect a rejected tag description, and
	 * requestNoAnswer() is built straight on top of it.
	 */
	public function testIsYesMatchesShortAndLongFormsCaseInsensitively(): void
	{
		$cli = Helpers::getInstance();
		foreach (['y', 'Y', 'yes', 'Yes', 'YES'] as $answer) {
			$this->assertTrue($cli->isYes($answer), "isYes('$answer') should be true");
		}
		foreach (['n', 'no', 'maybe', ''] as $answer) {
			$this->assertFalse($cli->isYes($answer), "isYes('$answer') should be false");
		}
	}

	public function testIsNoMatchesShortAndLongFormsCaseInsensitively(): void
	{
		$cli = Helpers::getInstance();
		foreach (['n', 'N', 'no', 'No', 'NO'] as $answer) {
			$this->assertTrue($cli->isNo($answer), "isNo('$answer') should be true");
		}
		foreach (['y', 'yes', 'nope', ''] as $answer) {
			$this->assertFalse($cli->isNo($answer), "isNo('$answer') should be false");
		}
	}
}
