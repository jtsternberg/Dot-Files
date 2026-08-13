<?php
namespace JT\Tests\Git;

use JT\Tests\TestCase;
use JT\CLI\Helpers\Git;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * git-nexttag / gtag — pure tag computation extracted from JT\CLI\Helpers\Git.
 *
 * getNextTag() shells out to `git tag` (via currentTag()) and then computes the
 * next version. That computation is the pure, testable part: it is split into
 * Git::incrementTag($lasttag, $type). The shell-out (currentTag()) is the same
 * kind of integration seam left to live-verify as Godo's dirmap shell-out.
 * validTag() is already pure.
 */
final class GitTagTest extends TestCase
{
	/**
	 * Faithful expectations for the existing increment behavior, including the
	 * deliberate quirks: PHP string-increment of the "vN" major part, quote
	 * stripping, empty-type defaulting to patch, and an empty last tag always
	 * yielding "1.0.0" regardless of requested type.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function incrementProvider(): array
	{
		return [
			'patch bump'                 => [ 'v4.17.25', 'patch', 'v4.17.26' ],
			'minor bump zeroes patch'    => [ 'v4.17.25', 'minor', 'v4.18.0' ],
			'major bump string-incr v'   => [ 'v4.17.25', 'major', 'v5.0.0' ],
			'subpatch bump'              => [ 'v4.17.25.1', 'subpatch', 'v4.17.25.2' ],
			'default type is patch'      => [ 'v4.17.25', '', 'v4.17.26' ],
			'quotes stripped from type'  => [ 'v4.17.25', '"minor"', 'v4.18.0' ],
			'single quotes stripped'     => [ 'v4.17.25', "'patch'", 'v4.17.26' ],
			'whitespace trimmed'         => [ 'v4.17.25', '  patch  ', 'v4.17.26' ],
			'empty tag -> 1.0.0 (patch)' => [ '', 'patch', '1.0.0' ],
			'empty tag -> 1.0.0 (major)' => [ '', 'major', '1.0.0' ],
			'empty tag -> 1.0.0 (minor)' => [ '', 'minor', '1.0.0' ],
		];
	}

	#[DataProvider( 'incrementProvider' )]
	public function testIncrementTag( string $lasttag, string $type, string $expected ): void
	{
		$this->assertSame( $expected, Git::incrementTag( $lasttag, $type ) );
	}

	public function testIncrementTagDefaultsToPatch(): void
	{
		$this->assertSame( 'v4.17.26', Git::incrementTag( 'v4.17.25' ) );
	}

	public function testUnknownTypeThrowsWithData(): void
	{
		try {
			Git::incrementTag( 'v4.17.25', 'bogus' );
			$this->fail( 'Expected an exception for an unrecognized type.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 1, $e->getCode() );
			$this->assertSame( 'bogus', $e->data );
			$this->assertStringContainsString( 'not recognized', $e->getMessage() );
		}
	}

	public function testMissingSectionThrows(): void
	{
		$this->expectException( \Exception::class );
		$this->expectExceptionCode( 2 );
		Git::incrementTag( 'v4.17', 'subpatch' );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function validTagProvider(): array
	{
		return [
			'canonical'            => [ 'v4.17.25', true ],
			'no v prefix'          => [ '4.17.25', false ],
			'too few dots'         => [ 'v4.17', false ],
			'too many dots'        => [ 'v4.17.25.1', false ],
			'trailing dot'         => [ 'v4.17.', false ],
			'adjacent dots'        => [ 'v4..17', false ],
		];
	}

	#[DataProvider( 'validTagProvider' )]
	public function testValidTag( string $tag, bool $expected ): void
	{
		$this->assertSame( $expected, $this->cli->git->validTag( $tag ) );
	}
}
