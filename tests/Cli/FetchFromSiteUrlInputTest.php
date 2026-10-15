<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;
use JT\CLI\Commands\FetchFromSiteCommand;

/**
 * A pasted permalink is the identifier people actually have in hand, so fetch
 * accepts one and resolves it through the same slug lookup as a bare slug.
 */
class FetchFromSiteUrlInputTest extends TestCase
{
	private function command( string $input ): FetchFromSiteCommand
	{
		$cli = $this->cli->setArgs( [ 'jt-blog-fetch', $input ] );

		return new class( $cli ) extends FetchFromSiteCommand {
			public function identifier(): array {
				return [ 'id' => $this->postId, 'slug' => $this->postSlug ];
			}
		};
	}

	public function testPermalinkResolvesToItsLastPathSegment(): void
	{
		$this->assertSame(
			[ 'id' => null, 'slug' => 'my-great-post' ],
			$this->command( 'https://jtsternberg.com/2024/05/my-great-post/' )->identifier()
		);
	}

	public function testPermalinkWithoutTrailingSlashOrWithQuery(): void
	{
		$this->assertSame(
			[ 'id' => null, 'slug' => 'about' ],
			$this->command( 'https://coaching.jtsternberg.com/about?utm_source=x#top' )->identifier()
		);
	}

	public function testShortlinkQueryIdBecomesPostId(): void
	{
		$this->assertSame( [ 'id' => 123, 'slug' => null ], $this->command( 'https://jtsternberg.com/?p=123' )->identifier() );
		$this->assertSame( [ 'id' => 45, 'slug' => null ], $this->command( 'https://jtsternberg.com/?page_id=45' )->identifier() );
	}

	public function testUrlWithNoSlugIsRejected(): void
	{
		$this->expectExceptionMessage( 'Could not find a post slug' );
		$this->command( 'https://jtsternberg.com/' );
	}

	public function testBareSlugAndIdAreUnchanged(): void
	{
		$this->assertSame( [ 'id' => null, 'slug' => 'my-great-post' ], $this->command( 'my-great-post' )->identifier() );
		$this->assertSame( [ 'id' => 7, 'slug' => null ], $this->command( '7' )->identifier() );
	}
}
