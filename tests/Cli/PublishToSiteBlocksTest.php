<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;
use JT\CLI\Commands\PublishToSiteCommand;

/**
 * Raw HTML posted over REST opens in the block editor as one Classic block, so
 * markdown must leave prepareContent() as block markup. --disableMarkdown is the
 * other half of the contract: the lossless round-trip flow in the blog-tools
 * skill pushes merged raw blocks through it and needs them byte-for-byte.
 */
class PublishToSiteBlocksTest extends TestCase
{
	private array $tempFiles = [];

	protected function tearDown(): void
	{
		array_map( 'unlink', array_filter( $this->tempFiles, 'file_exists' ) );
		parent::tearDown();
	}

	private function command( array $args, string $body ): PublishToSiteCommand
	{
		$contentFile = tempnam( sys_get_temp_dir(), 'publish_blocks_' );
		$this->tempFiles[] = $contentFile;
		file_put_contents( $contentFile, $body );
		$cli = $this->cli->setArgs( array_merge( [ 'jt-blog-publish', $contentFile ], $args ) );

		return new class( $cli, $contentFile, $body ) extends PublishToSiteCommand {
			public function __construct( $cli, string $contentFile, string $body ) {
				parent::__construct( $cli );
				$this->contentFile = $contentFile;
				$this->postContent = $body;
			}

			public function callPrepareContent(): string {
				return json_decode( $this->prepareContent() );
			}
		};
	}

	public function testMarkdownIsPublishedAsBlockMarkup(): void
	{
		if ( '' === trim( (string) shell_exec( 'command -v marked 2>/dev/null' ) ) ) {
			$this->markTestSkipped( "'marked' is not installed" );
		}

		$this->assertSame(
			"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Frontend App</h3>\n<!-- /wp:heading -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Shipped it</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			$this->command( [], "### Frontend App\n\n- Shipped it\n" )->callPrepareContent()
		);
	}

	public function testDisableMarkdownPushesBodyVerbatim(): void
	{
		$body = "<!-- wp:verse -->\n<pre class=\"wp-block-verse\">In the beginning</pre>\n<!-- /wp:verse -->\n<p>no block</p>";

		$this->assertSame(
			$body,
			$this->command( [ '--disableMarkdown', '--title=T' ], $body )->callPrepareContent()
		);
	}
}
