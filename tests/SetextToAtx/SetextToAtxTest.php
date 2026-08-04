<?php
namespace JT\Tests\SetextToAtx;

use JT\SetextToAtx;
use JT\Tests\TestCase;

/**
 * Setext → ATX heading normalization.
 *
 * The dangerous input is not the heading, it's everything that also consists of
 * dashes: YAML frontmatter fences, thematic breaks, table delimiter rows, and
 * fenced code. A naive "line of dashes means the line above was a heading" pass
 * turns the closing `---` of frontmatter into `## slug: my-post`. Every one of
 * those cases has a test here.
 */
class SetextToAtxTest extends TestCase
{
	private SetextToAtx $converter;

	protected function setUp(): void
	{
		parent::setUp();
		$this->converter = new SetextToAtx();
	}

	public function testConvertsSetextHeadingsToAtx(): void
	{
		$result = $this->converter->convertString(
			"Title\n=====\n\nCredit where it's due\n---------------------\n\nBody.\n"
		);

		$this->assertSame(
			"# Title\n\n## Credit where it's due\n\nBody.\n",
			$result['markdown']
		);
		$this->assertSame(2, $result['converted']);
	}

	public function testLeavesFrontmatterFencesAlone(): void
	{
		$markdown = "---\nid: 1528\nslug: 2-years-live-cast-recording\n---\n\n# Title\n\nBody.\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testConvertsHeadingsThatFollowFrontmatter(): void
	{
		$result = $this->converter->convertString(
			"---\nid: 1\n---\n\nA Better Analogy\n----------------\n\nBody.\n"
		);

		$this->assertSame(
			"---\nid: 1\n---\n\n## A Better Analogy\n\nBody.\n",
			$result['markdown']
		);
		$this->assertSame(1, $result['converted']);
	}

	public function testLeavesThematicBreaksAlone(): void
	{
		$markdown = "Paragraph.\n\n---\n\nNext paragraph.\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testLeavesFencedCodeAlone(): void
	{
		$markdown = "Intro.\n\n```\nHeading\n-------\n```\n\nOutro.\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testLeavesTableDelimiterRowsAlone(): void
	{
		$markdown = "| Col | Col |\n| --- | --- |\n| a | b |\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testLeavesListsAndQuotesAndAtxAlone(): void
	{
		$markdown = "- item\n---\n\n> quote\n---\n\n## Already ATX\n---\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testLeavesMultiLineParagraphsAlone(): void
	{
		// CommonMark would read this as a setext heading spanning both lines,
		// but nothing in this vault generates that, and guessing would mangle
		// hand-written prose that happens to sit above a break.
		$markdown = "First line\nsecond line\n---\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testLeavesConsecutiveThematicBreaksAlone(): void
	{
		// Found in the wild (a recipe note with a doubled divider): without this,
		// the first --- is read as heading *text* and the second as its
		// underline, producing "## ---".
		$markdown = "Body.\n\n---\n---\n\n## Next\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testLeavesStarAndUnderscoreBreaksAlone(): void
	{
		$markdown = "Body.\n\n***\n---\n\n___\n===\n";

		$result = $this->converter->convertString($markdown);

		$this->assertSame($markdown, $result['markdown']);
		$this->assertSame(0, $result['converted']);
	}

	public function testSplitsABlockLevelPrefixOffTheHeadingText(): void
	{
		// Files fetched before the ATX fix have the artifact this mirrors: the
		// old converter glued a block-level comment or figure onto the heading
		// line. A fresh fetch now emits them on separate lines, so normalizing
		// has to land on the same shape or these files churn a second time.
		$result = $this->converter->convertString(
			"<!--more-->A Better Analogy\n---------------------------\n\nBody.\n"
		);

		$this->assertSame(
			"<!--more-->\n\n## A Better Analogy\n\nBody.\n",
			$result['markdown']
		);
		$this->assertSame(1, $result['converted']);
	}

	public function testSplitsAFigureOffTheHeadingText(): void
	{
		$result = $this->converter->convertString(
			"<figure class=\"x\"><img src=\"a.png\" /></figure>So what now?\n------------\n"
		);

		$this->assertSame(
			"<figure class=\"x\"><img src=\"a.png\" /></figure>\n\n## So what now?\n",
			$result['markdown']
		);
	}

	public function testKeepsInlineMarkupInsideTheHeading(): void
	{
		$result = $this->converter->convertString(
			"<span style=\"font-weight: 400;\">Show Notes:</span>\n---------------\n"
		);

		$this->assertSame(
			"## <span style=\"font-weight: 400;\">Show Notes:</span>\n",
			$result['markdown']
		);
	}

	public function testIsIdempotent(): void
	{
		$once  = $this->converter->convertString("Title\n=====\n\nBody.\n");
		$twice = $this->converter->convertString($once['markdown']);

		$this->assertSame($once['markdown'], $twice['markdown']);
		$this->assertSame(0, $twice['converted']);
	}

	public function testPreservesAbsentTrailingNewline(): void
	{
		$result = $this->converter->convertString("Title\n=====");

		$this->assertSame('# Title', $result['markdown']);
	}

	public function testConvertFileReportsWithoutWritingUnlessAsked(): void
	{
		$file = $this->graveyardRoot . '/post.md';
		$original = "Title\n=====\n";
		file_put_contents($file, $original);

		$dry = $this->converter->convertFile($file);

		$this->assertSame(1, $dry['converted']);
		$this->assertFalse($dry['written']);
		$this->assertSame($original, file_get_contents($file));

		$applied = $this->converter->convertFile($file, true);

		$this->assertSame(1, $applied['converted']);
		$this->assertTrue($applied['written']);
		$this->assertSame("# Title\n", file_get_contents($file));
	}

	public function testConvertFileDoesNotTouchAFileWithNothingToChange(): void
	{
		$file = $this->graveyardRoot . '/clean.md';
		file_put_contents($file, "# Title\n");
		$before = filemtime($file);

		$result = $this->converter->convertFile($file, true);

		$this->assertSame(0, $result['converted']);
		$this->assertFalse($result['written']);
		$this->assertSame($before, filemtime($file));
	}

	public function testMarkdownFilesWalksDirectoriesForMarkdownOnly(): void
	{
		$root = $this->graveyardRoot . '/vault';
		mkdir($root . '/nested/.git', 0777, true);
		file_put_contents($root . '/a.md', '');
		file_put_contents($root . '/skip.txt', '');
		file_put_contents($root . '/nested/b.md', '');
		file_put_contents($root . '/nested/.git/c.md', '');

		$files = $this->converter->markdownFiles($root);

		$this->assertSame([$root . '/a.md', $root . '/nested/b.md'], $files);
	}

	public function testMarkdownFilesAcceptsASingleFile(): void
	{
		$file = $this->graveyardRoot . '/one.md';
		file_put_contents($file, '');

		$this->assertSame([$file], $this->converter->markdownFiles($file));
	}
}
