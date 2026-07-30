<?php
namespace JT\Tests\Helpers;

use JT\Helpers\NullCmux;
use JT\Tests\TestCase;

final class NullCmuxTest extends TestCase
{
	public function testReadOnlyLookupsDescribeNoLiveCmuxState(): void
	{
		$cmux = new NullCmux($this->cli);

		$this->assertSame([], $cmux->tree());
		$this->assertSame([], $cmux->loadClaudeSessionsByPid());
		$this->assertSame([], $cmux->loadCodexSessionsByPid());
		$this->assertSame('', $cmux->jsonlPathFor('session', '/tmp/project'));
		$this->assertNull($cmux->codexRolloutPathFor('session'));
	}

	public function testMutatingCmuxCallsAreLoudBugs(): void
	{
		$cmux = new NullCmux($this->cli);

		$this->expectException(\LogicException::class);
		$cmux->sendToSurface('surface:1', 'workspace:1', 'anything');
	}
}
