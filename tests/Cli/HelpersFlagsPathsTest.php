<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;

/**
 * CLI\Helpers foundation: the flag predicates and path/file helpers that every
 * bin/ script leans on. These are the highest-leverage units in the repo — a
 * subtle regression here mis-reads --silent, --yes, or a path in every command
 * at once — yet were previously uncovered.
 *
 * The command-running helpers (runCommand et al.) are the process shell-out
 * seam and are left to live-verify, same boundary as elsewhere.
 */
final class HelpersFlagsPathsTest extends TestCase
{
	public function testHasArgChecksPositionalValues(): void
	{
		$this->cli->setArgs(['tool', 'build', 'target', '--flag']);
		$this->assertTrue($this->cli->hasArg('build'));
		$this->assertTrue($this->cli->hasArg('target'));
		$this->assertFalse($this->cli->hasArg('missing'));
		$this->assertFalse($this->cli->hasArg('flag'), '--flag is a flag, not a positional arg');
	}

	public function testGetArgFallback(): void
	{
		$this->cli->setArgs(['tool', 'only']);
		$this->assertSame('only', $this->cli->getArg(1));
		$this->assertNull($this->cli->getArg(9));
		$this->assertSame('fb', $this->cli->getArg(9, 'fb'));
	}

	public function testGetFlagStripsLeadingDashesAndFallsBack(): void
	{
		$this->cli->setArgs(['tool', '--host=example.com']);
		$this->assertSame('example.com', $this->cli->getFlag('host'));
		$this->assertSame('example.com', $this->cli->getFlag('--host'), 'leading -- is stripped');
		$this->assertNull($this->cli->getFlag('missing'));
		$this->assertSame(7, $this->cli->getFlag('missing', 7));
	}

	public function testHasFlagsAcceptsLongArrayAndShort(): void
	{
		$this->cli->setArgs(['tool', '--alpha', '-x']);

		// Any one long flag present -> true.
		$this->assertTrue($this->cli->hasFlags(['alpha', 'beta']));
		$this->assertTrue($this->cli->hasFlags('alpha'));
		// No long flag, but a matching short flag -> true.
		$this->assertTrue($this->cli->hasFlags(['beta'], 'x'));
		$this->assertTrue($this->cli->hasFlags('beta', ['x']));
		// Neither present -> false.
		$this->assertFalse($this->cli->hasFlags(['beta'], 'z'));
		$this->assertFalse($this->cli->hasFlags('beta'));
	}

	public function testIsSilentAcrossItsFlags(): void
	{
		$this->cli->setArgs(['tool', '--silent']);
		$this->assertTrue($this->cli->isSilent(), '--silent');

		$this->cli->setArgs(['tool', '--porcelain']);
		$this->assertTrue($this->cli->isSilent(), '--porcelain');

		$this->cli->setArgs(['tool', '-shh']);
		$this->assertTrue($this->cli->isSilent(), '-shh short flag');

		$this->cli->setArgs(['tool', 'plain']);
		$this->assertFalse($this->cli->isSilent());

		// forceSilent overrides even with no flag.
		$this->cli->forceSilent = true;
		$this->assertTrue($this->cli->isSilent(), 'forceSilent overrides');
		$this->cli->forceSilent = false;
	}

	public function testIsVerboseAutoconfirmAndIgnoreErrors(): void
	{
		$this->cli->setArgs(['tool', '--verbose']);
		$this->assertTrue($this->cli->isVerbose(), '--verbose');
		$this->cli->setArgs(['tool', '-v']);
		$this->assertTrue($this->cli->isVerbose(), '-v');
		$this->cli->setArgs(['tool']);
		$this->assertFalse($this->cli->isVerbose());

		$this->cli->setArgs(['tool', '--yes']);
		$this->assertTrue($this->cli->isAutoconfirm(), '--yes');
		$this->cli->setArgs(['tool', '-y']);
		$this->assertTrue($this->cli->isAutoconfirm(), '-y');
		$this->cli->setArgs(['tool']);
		$this->assertFalse($this->cli->isAutoconfirm());

		// shouldIgnoreErrors accepts the long --ignoreErrors and --ignore, plus the
		// single-dash short -ignore.
		$this->cli->setArgs(['tool', '--ignoreErrors']);
		$this->assertTrue($this->cli->shouldIgnoreErrors(), '--ignoreErrors (long)');
		$this->cli->setArgs(['tool', '--ignore']);
		$this->assertTrue($this->cli->shouldIgnoreErrors(), '--ignore (long)');
		$this->cli->setArgs(['tool', '-ignore']);
		$this->assertTrue($this->cli->shouldIgnoreErrors(), '-ignore (short)');
		$this->cli->setArgs(['tool']);
		$this->assertFalse($this->cli->shouldIgnoreErrors());
	}

	public function testConvertPathToAbsoluteJoinsRelativeToBase(): void
	{
		$this->assertSame(
			'/home/user/file.txt',
			$this->cli->convertPathToAbsolute('file.txt', '/home/user')
		);
	}

	public function testConvertPathToAbsoluteCollapsesParentSegments(): void
	{
		$this->assertSame(
			'/home/user/sibling',
			$this->cli->convertPathToAbsolute('../sibling', '/home/user/proj')
		);
	}

	public function testConvertPathToAbsoluteLeavesUrlSchemeUntouched(): void
	{
		$this->assertSame(
			'http://example.com/x',
			$this->cli->convertPathToAbsolute('http://example.com/x', '/whatever')
		);
	}

	public function testGetDirFilesFiltersByExtensionAndSortsByMtime(): void
	{
		$dir = $this->graveyardRoot . '/dirfiles';
		mkdir($dir, 0777, true);
		// Distinct mtimes: getDirFiles keys by mtime, so equal mtimes would collide.
		$base = 1_600_000_000;
		file_put_contents("$dir/old.txt", 'a');   touch("$dir/old.txt", $base);
		file_put_contents("$dir/mid.txt", 'b');   touch("$dir/mid.txt", $base + 100);
		file_put_contents("$dir/new.txt", 'c');   touch("$dir/new.txt", $base + 200);
		file_put_contents("$dir/skip.log", 'd');  touch("$dir/skip.log", $base + 300);

		$desc = $this->cli->getDirFiles($dir, 'txt'); // default modifiedDesc
		$this->assertSame(['new.txt', 'mid.txt', 'old.txt'], array_values($desc), 'newest first, .log excluded');

		$asc = $this->cli->getDirFiles($dir, 'txt', 'modifiedAsc');
		$this->assertSame(['old.txt', 'mid.txt', 'new.txt'], array_values($asc), 'oldest first');
	}

	public function testFilteredFileContentRowsRunsCallbackAndDropsFalse(): void
	{
		$file = $this->graveyardRoot . '/rows.txt';
		file_put_contents($file, "# comment\nkeep-one\n# another\nkeep-two\n");

		$result = $this->cli->filteredFileContentRows($file, function ($line) {
			$line = trim($line);
			// Drop blank and comment rows entirely.
			return ('' === $line || str_starts_with($line, '#')) ? false : $line;
		});

		$this->assertSame("keep-one\nkeep-two", $result);
	}

	public function testFilteredFileContentRowsMissingFileIsEmpty(): void
	{
		$this->assertSame(
			'',
			$this->cli->filteredFileContentRows($this->graveyardRoot . '/nope.txt', fn ($l) => $l)
		);
	}
}
