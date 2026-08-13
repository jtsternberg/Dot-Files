<?php
namespace JT\Tests\Git;

use JT\Tests\TestCase;
use JT\GitHubActivityLogger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * githubuserlog — pure helpers extracted from bin/githubuserlog.
 *
 * These cover the genuinely pure logic: repo-filter pattern building, repo
 * filtering, `gh` output parsing, the two `gh` command-argument builders, and
 * the numeric-arg guard. The gh-shelling seam (execGitHub, mkdir, writeToFile)
 * is intentionally NOT unit-tested — same integration boundary left to
 * live-verify as gituserlog's git backticks and Godo's dirmap shell-out. The
 * helpers are static so tests need not construct the logger (whose constructor
 * checks for `gh` and creates directories).
 */
final class GitHubActivityLoggerTest extends TestCase
{
	/**
	 * A filter with regex metacharacters is used verbatim; a plain string is
	 * preg_quote'd (so `-` etc. can't misbehave as a range).
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function repoPatternProvider(): array
	{
		return [
			'plain string is quoted'   => [ 'project-', 'project\-' ],
			'anchor is regex'          => [ '^wp-', '^wp-' ],
			'alternation is regex'     => [ 'project-|app-|lib-', 'project-|app-|lib-' ],
			'uppercase plain is quoted'=> [ 'PROJECT-', 'PROJECT\-' ],
			'dot is regex'             => [ 'a.b', 'a.b' ],
		];
	}

	#[DataProvider( 'repoPatternProvider' )]
	public function testBuildRepoPattern( string $filter, string $expected ): void
	{
		$this->assertSame( $expected, GitHubActivityLogger::buildRepoPattern( $filter ) );
	}

	public function testFilterReposPlainSubstringCaseInsensitive(): void
	{
		$repos = [ 'project-a', 'other', 'Project-B', 'app-x' ];
		$this->assertSame(
			[ 'project-a', 'Project-B' ],
			array_values( GitHubActivityLogger::filterRepos( $repos, 'project-' ) )
		);
	}

	public function testFilterReposRegexAnchor(): void
	{
		$repos = [ 'wp-core', 'my-wp-plugin', 'WP-admin' ];
		$this->assertSame(
			[ 'wp-core', 'WP-admin' ],
			array_values( GitHubActivityLogger::filterRepos( $repos, '^wp-' ) )
		);
	}

	public function testFilterReposAlternation(): void
	{
		$repos = [ 'project-a', 'app-b', 'lib-c', 'zzz' ];
		$this->assertSame(
			[ 'project-a', 'app-b', 'lib-c' ],
			array_values( GitHubActivityLogger::filterRepos( $repos, 'project-|app-|lib-' ) )
		);
	}

	public function testParseRepoNamesDropsBlankLines(): void
	{
		$this->assertSame(
			[ 'alpha', 'beta', 'gamma' ],
			array_values( GitHubActivityLogger::parseRepoNames( "alpha\nbeta\n\ngamma\n" ) )
		);
	}

	public function testFilteredReposCommandPaginatesWithoutLimit(): void
	{
		$this->assertSame(
			[
				'gh api',
				"-X GET '/orgs/myorg/repos?per_page=500'",
				"-H 'Accept: application/vnd.github+json'",
				'--paginate',
			],
			GitHubActivityLogger::filteredReposCommand( 'myorg', false )
		);
	}

	public function testFilteredReposCommandWithLimit(): void
	{
		$this->assertSame(
			[ 'gh repo list myorg', '--json=name', '--limit 25' ],
			GitHubActivityLogger::filteredReposCommand( 'myorg', 25 )
		);
	}

	public function testSearchReposCommandWithoutLimit(): void
	{
		$this->assertSame(
			[ 'gh search repos', '--owner=myorg', "in:name 'thing'", '--json name' ],
			GitHubActivityLogger::searchReposCommand( 'myorg', 'thing', false )
		);
	}

	public function testSearchReposCommandWithLimit(): void
	{
		$this->assertSame(
			[ 'gh search repos', '--owner=myorg', "in:name 'thing'", '--limit=10', '--json name' ],
			GitHubActivityLogger::searchReposCommand( 'myorg', 'thing', 10 )
		);
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public static function positiveNumberProvider(): array
	{
		return [
			'positive int'   => [ '7', true ],
			'positive float' => [ '3.5', true ],
			'zero'           => [ '0', false ],
			'negative'       => [ '-1', false ],
			'non-numeric'    => [ 'abc', false ],
			'empty'          => [ '', false ],
		];
	}

	#[DataProvider( 'positiveNumberProvider' )]
	public function testIsPositiveNumber( $value, bool $expected ): void
	{
		$this->assertSame( $expected, GitHubActivityLogger::isPositiveNumber( $value ) );
	}
}
