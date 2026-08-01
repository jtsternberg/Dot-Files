<?php
namespace JT\Tests\Git;

use JT\Tests\TestCase;
use JT\GitUserLogger;

/**
 * gituserlog — pure helpers extracted from bin/gituserlog.
 *
 * These cover the genuinely pure logic (URL → repo name, git-output parsing,
 * author de-duplication, days validation). The git-shelling seam (clone/fetch,
 * `git log` backticks) is intentionally NOT unit-tested — it is the same kind of
 * integration boundary left to live-verify as Godo's dirmap shell-out.
 */
final class GitUserLoggerTest extends TestCase
{
	private function makeLogger(): GitUserLogger
	{
		return new GitUserLogger($this->cli);
	}

	// --- repoNameFromUrl -----------------------------------------------------

	public function testRepoNameFromUrlSshUrl(): void
	{
		$this->assertSame(
			'Dot-Files',
			$this->makeLogger()->repoNameFromUrl('git@github.com:jtsternberg/Dot-Files.git')
		);
	}

	public function testRepoNameFromUrlWikiUrl(): void
	{
		$this->assertSame(
			'repo.wiki',
			$this->makeLogger()->repoNameFromUrl('git@github.com:org/repo.wiki.git')
		);
	}

	/**
	 * Documents the EXISTING greedy-regex behavior. `~\/(.+)\.git~` matches from
	 * the FIRST slash, so an https URL yields the whole path, not the bare repo
	 * name. Real .ghrepos configs use SSH (git@...:org/repo.git) URLs, which have
	 * a single slash and so resolve correctly. Preserved verbatim during the
	 * extraction — see report notes.
	 */
	public function testRepoNameFromUrlHttpsGreedyQuirkIsPreserved(): void
	{
		$this->assertSame(
			'/github.com/foo/bar',
			$this->makeLogger()->repoNameFromUrl('https://github.com/foo/bar.git')
		);
	}

	public function testRepoNameFromUrlNoMatchReturnsEmpty(): void
	{
		$this->assertSame('', $this->makeLogger()->repoNameFromUrl('not-a-repo-url'));
	}

	// --- parseAuthors --------------------------------------------------------

	public function testParseAuthorsMultiLine(): void
	{
		$this->assertSame(
			['alice', 'bob', 'carol'],
			$this->makeLogger()->parseAuthors("alice\nbob\ncarol")
		);
	}

	public function testParseAuthorsDropsTrailingNewlineEmpty(): void
	{
		$this->assertSame(
			['alice', 'bob'],
			$this->makeLogger()->parseAuthors("alice\nbob\n")
		);
	}

	public function testParseAuthorsTrimsWhitespace(): void
	{
		$this->assertSame(
			['alice', 'bob'],
			$this->makeLogger()->parseAuthors("  alice \n bob \n")
		);
	}

	public function testParseAuthorsEmptyReturnsEmptyArray(): void
	{
		$this->assertSame([], $this->makeLogger()->parseAuthors(''));
	}

	// --- dedupeAuthors -------------------------------------------------------

	public function testDedupeAuthorsMergesAndUniques(): void
	{
		$this->assertSame(
			['a', 'b', 'c'],
			$this->makeLogger()->dedupeAuthors([['a', 'b'], ['b', 'c']])
		);
	}

	public function testDedupeAuthorsFiltersEmpties(): void
	{
		$this->assertSame(
			['a', 'b'],
			$this->makeLogger()->dedupeAuthors([['a', ''], ['', 'b']])
		);
	}

	public function testDedupeAuthorsEmptyInputReturnsEmptyArray(): void
	{
		$this->assertSame([], $this->makeLogger()->dedupeAuthors([]));
	}

	// --- isValidDays ---------------------------------------------------------

	public function testIsValidDaysAcceptsDefaultSevenInt(): void
	{
		$this->assertTrue(GitUserLogger::isValidDays(7));
	}

	public function testIsValidDaysAcceptsNumericString(): void
	{
		$this->assertTrue(GitUserLogger::isValidDays('94'));
	}

	public function testIsValidDaysRejectsNonNumeric(): void
	{
		$this->assertFalse(GitUserLogger::isValidDays('abc'));
	}

	public function testIsValidDaysRejectsZero(): void
	{
		$this->assertFalse(GitUserLogger::isValidDays(0));
	}

	public function testIsValidDaysRejectsNegative(): void
	{
		$this->assertFalse(GitUserLogger::isValidDays(-3));
	}
}
