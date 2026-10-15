<?php

namespace JT\Tests\Cli\Publishing;

use JT\CLI\Publishing\GutenbergBlockConverter;
use JT\Tests\TestCase;

/**
 * The block editor re-runs each block's save() and compares it to the stored
 * markup; any mismatch shows "This block contains unexpected or invalid content".
 * These expectations are the exact markup core's save() produces, confirmed in
 * the Lindris team site's editor — so a "harmless" tweak to spacing inside a tag or to a
 * class list here breaks validation there.
 */
class GutenbergBlockConverterTest extends TestCase {

	private function convert(string $html): string {
		return (new GutenbergBlockConverter())->convert($html);
	}

	public function testParagraphKeepsInlineMarkup(): void {
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Hi <strong>bold</strong>, <code>x</code> and <a href=\"https://example.com\">link</a>.</p>\n<!-- /wp:paragraph -->",
			$this->convert('<p>Hi <strong>bold</strong>, <code>x</code> and <a href="https://example.com">link</a>.</p>')
		);
	}

	public function testH2HeadingOmitsDefaultLevel(): void {
		$this->assertSame(
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Section</h2>\n<!-- /wp:heading -->",
			$this->convert('<h2>Section</h2>')
		);
	}

	public function testOtherHeadingLevelsCarryLevelAttribute(): void {
		$this->assertSame(
			"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Frontend App</h3>\n<!-- /wp:heading -->",
			$this->convert('<h3>Frontend App</h3>')
		);
	}

	public function testUnorderedListWrapsEachItem(): void {
		$this->assertSame(
			"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item --><!-- wp:list-item -->\n<li>Two <a href=\"https://x.test\">#1</a></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			$this->convert("<ul>\n<li>One</li>\n<li>Two <a href=\"https://x.test\">#1</a></li>\n</ul>")
		);
	}

	public function testOrderedListCarriesOrderedAttribute(): void {
		$this->assertSame(
			"<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\"><!-- wp:list-item -->\n<li>First</li>\n<!-- /wp:list-item --></ol>\n<!-- /wp:list -->",
			$this->convert("<ol>\n<li>First</li>\n</ol>")
		);
	}

	public function testNestedListBecomesInnerListBlock(): void {
		$this->assertSame(
			"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Parent<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Child</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			$this->convert("<ul>\n<li>Parent<ul>\n<li>Child</li>\n</ul>\n</li>\n</ul>")
		);
	}

	/**
	 * marked renders "loose" lists (blank lines between items) as <li><p>…</p></li>;
	 * a list-item block's content is rich text, so the <p> wrapper must go.
	 */
	public function testLooseListItemDropsParagraphWrapper(): void {
		$this->assertSame(
			"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Loose</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			$this->convert("<ul>\n<li><p>Loose</p>\n</li>\n</ul>")
		);
	}

	public function testBlockquoteHoldsInnerParagraphBlocks(): void {
		$this->assertSame(
			"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->",
			$this->convert("<blockquote>\n<p>One</p>\n<p>Two</p>\n</blockquote>")
		);
	}

	public function testHorizontalRuleBecomesSeparator(): void {
		$this->assertSame(
			"<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",
			$this->convert('<hr>')
		);
	}

	/**
	 * Core's code block has no language attribute, so marked's language-* class
	 * is dropped; the trailing newline marked leaves inside <code> is trimmed.
	 */
	public function testCodeBlockKeepsEscapingAndDropsLanguageClass(): void {
		$this->assertSame(
			"<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>&lt;?php\necho \"a &lt;b&gt; &amp; c\";</code></pre>\n<!-- /wp:code -->",
			$this->convert("<pre><code class=\"language-php\">&lt;?php\necho &quot;a &lt;b&gt; &amp; c&quot;;\n</code></pre>")
		);
	}

	public function testTableGetsFigureWrapperAndAlignmentClasses(): void {
		$this->assertSame(
			"<!-- wp:table -->\n<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\"><thead><tr><th>Repo</th><th class=\"has-text-align-right\" data-align=\"right\">PRs</th></tr></thead><tbody><tr><td>frontend</td><td class=\"has-text-align-right\" data-align=\"right\">4</td></tr></tbody></table></figure>\n<!-- /wp:table -->",
			$this->convert("<table>\n<thead>\n<tr>\n<th>Repo</th>\n<th align=\"right\">PRs</th>\n</tr>\n</thead>\n<tbody><tr>\n<td>frontend</td>\n<td align=\"right\">4</td>\n</tr>\n</tbody></table>")
		);
	}

	/**
	 * Anything without a native mapping must still survive intact, so it lands in
	 * a Custom HTML block rather than being dropped.
	 */
	public function testUnknownElementFallsBackToHtmlBlock(): void {
		$this->assertSame(
			"<!-- wp:html -->\n<details><summary>More</summary>Body</details>\n<!-- /wp:html -->",
			$this->convert('<details><summary>More</summary>Body</details>')
		);
	}

	public function testBlocksAreSeparatedByBlankLine(): void {
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph -->",
			$this->convert("<p>A</p>\n<p>B</p>\n")
		);
	}

	public function testNonAsciiSurvivesUnencoded(): void {
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Shipped — 🎉 café</p>\n<!-- /wp:paragraph -->",
			$this->convert('<p>Shipped — 🎉 café</p>')
		);
	}

	public function testEmptyInputYieldsEmptyOutput(): void {
		$this->assertSame('', $this->convert("  \n"));
	}

	/**
	 * jt-blog-fetch keeps WP's <!--more--> in the markdown it writes, so a fetched
	 * post republished through marked carries it at top level. Core stores it as
	 * a More block; skipping comment nodes would silently drop the read-more cut.
	 */
	public function testMoreCommentBecomesMoreBlock(): void {
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Lede</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->\n\n<!-- wp:paragraph -->\n<p>Rest</p>\n<!-- /wp:paragraph -->",
			$this->convert("<p>Lede</p>\n<!--more-->\n<p>Rest</p>\n")
		);
	}

	public function testOtherTopLevelCommentSurvivesInHtmlBlock(): void {
		$this->assertSame(
			"<!-- wp:html -->\n<!-- note to self -->\n<!-- /wp:html -->",
			$this->convert('<!-- note to self -->')
		);
	}

	/**
	 * Agents publishing to the blog have hand-written block markup inside their
	 * markdown. Re-wrapping it would nest or drop those delimiters, so content
	 * that already carries blocks passes through untouched.
	 */
	public function testContentAlreadyInBlocksPassesThroughUnchanged(): void {
		$html = "<!-- wp:paragraph -->\n<p>Hand-written</p>\n<!-- /wp:paragraph -->\n<p>Loose</p>\n";
		$this->assertSame($html, $this->convert($html));
	}
}
