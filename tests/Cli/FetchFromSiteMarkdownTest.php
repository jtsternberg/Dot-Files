<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;
use JT\CLI\Commands\FetchFromSiteCommand;

/**
 * Heading style for fetched post bodies.
 *
 * league/html-to-markdown defaults header_style to 'setext', so H1/H2 came back
 * underlined with = / - while H3+ came back as ATX — inconsistent within one
 * post, and unreadable to ATX-only markdown parsers (the visual-review skill
 * rendered every section as a paragraph and built an empty TOC). ATX for every
 * level is the contract now.
 */
class FetchFromSiteMarkdownTest extends TestCase
{
	private function command(): FetchFromSiteCommand
	{
		$cli = $this->cli->setArgs(['jt-blog-fetch', '123']);

		return new class( $cli ) extends FetchFromSiteCommand {
			public function callConvert( string $html ): string {
				return $this->convertHtmlToMarkdown( $html );
			}
		};
	}

	public function testHeadingsConvertToAtxAtEveryLevel(): void
	{
		$markdown = $this->command()->callConvert(
			'<h1>Title</h1><h2>Credit where it\'s due</h2><h3>Deeper</h3><p>Body.</p>'
		);

		$this->assertStringContainsString('# Title', $markdown);
		$this->assertStringContainsString("## Credit where it's due", $markdown);
		$this->assertStringContainsString('### Deeper', $markdown);
	}

	public function testNoSetextUnderlinesRemain(): void
	{
		$markdown = $this->command()->callConvert('<h1>Title</h1><h2>Credit where it\'s due</h2>');

		foreach (explode("\n", $markdown) as $line) {
			$this->assertDoesNotMatchRegularExpression(
				'/^[=-]{2,}\s*$/',
				$line,
				"Setext underline in output:\n$markdown"
			);
		}
	}

	public function testHeadingAfterAPreservedCommentStartsItsOwnLine(): void
	{
		// A figure is what turns preserve_comments on, so WP's <!--more--> only
		// reaches the markdown in posts that have one — and the library emits it
		// with no break, gluing the next heading to it ("<!--more-->## Title").
		// ATX only reads at the start of a line, so that heading would be text.
		$markdown = $this->command()->callConvert(
			'<p>Lede.</p><figure><img src="https://example.com/a.png"></figure>'
			. '<!--more--><h2>A Better Analogy</h2><p>Body.</p>'
		);

		$this->assertMatchesRegularExpression('/^## A Better Analogy$/m', $markdown);
		$this->assertStringContainsString('<!--more-->', $markdown);
		$this->assertStringNotContainsString('<!--more-->#', $markdown);
	}

	public function testLeavesAnInlineCommentOnItsOwnLine(): void
	{
		// Only a comment butting up against a heading gets broken apart; a
		// comment sitting inside a sentence stays inside that sentence.
		$markdown = $this->command()->callConvert(
			'<figure><img src="https://example.com/a.png"></figure>'
			. '<p>Text <!--note--> more text.</p>'
		);

		$this->assertMatchesRegularExpression('/Text .*note.* more text\./', $markdown);
	}

	public function testStillHardBreaksAndPreservesEmbeds(): void
	{
		$markdown = $this->command()->callConvert(
			"<p>One<br>Two</p><iframe src=\"https://example.com/embed\"></iframe>"
		);

		$this->assertStringContainsString("One\nTwo", $markdown);
		$this->assertStringContainsString('<iframe src="https://example.com/embed"></iframe>', $markdown);
	}
}
