<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;
use JT\CLI\Helpers;

class BootstrapTest extends TestCase
{
	public function testSrcBootstrapProvidesCliAndGetCli()
	{
		$cli = require dirname(__DIR__, 2) . '/src/bootstrap.php';
		$this->assertInstanceOf(Helpers::class, $cli);
		$this->assertTrue(function_exists('getCli'));
		$this->assertInstanceOf(Helpers::class, getCli([]));
	}
}
