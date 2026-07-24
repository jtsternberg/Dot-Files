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

	public function testSetArgsParsesLongShortAndPositional(): void
	{
		$cli = Helpers::getInstance()->setArgs(['tool', 'name', '--host=h', '--yes', '-v']);

		$this->assertSame('name', $cli->getArg(1));
		$this->assertSame('h', $cli->getFlag('host'));
		$this->assertTrue($cli->hasFlag('yes'));
		$this->assertSame('', $cli->getFlag('yes'), 'valueless long flag is empty string');
		$this->assertTrue($cli->hasShortFlag('v'));
	}
}
