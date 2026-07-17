<?php
namespace JT\Tests\CmuxBak;

use JT\Tests\TestCase;
use JT\CmuxBak;

/**
 * First tests for `cmux-bak` — the pure/no-I-O helpers only:
 *  - normalizeTitle() strips Claude Code's leading status glyph (✳ / braille spinner)
 *    plus following whitespace, for backup↔restore title matching
 *  - firstCwdFromBakWs() finds the first non-empty cwd across panes[].surfaces[]
 *  - allSurfacesFromBakWs() flattens panes[].surfaces[] in pane order
 *
 * The helpers are protected, so tests reach them through a small reflection
 * helper (no existing reflection pattern in the suite — established here).
 */
final class CmuxBakTest extends TestCase
{
	protected CmuxBak $bak;

	protected function setUp(): void
	{
		parent::setUp();
		$this->bak = new CmuxBak($this->cli);
	}

	/** Invoke a protected CmuxBak method. */
	protected function invokeProtected(string $method, array $args = [])
	{
		$ref = new \ReflectionMethod(CmuxBak::class, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($this->bak, $args);
	}

	public function testInstantiates(): void
	{
		$this->assertInstanceOf(CmuxBak::class, new CmuxBak($this->cli));
	}

	public function testNormalizeTitleStripsLeadingStatusGlyph(): void
	{
		$this->assertSame('my tab', $this->invokeProtected('normalizeTitle', ['✳ my tab']));
		$this->assertSame('working', $this->invokeProtected('normalizeTitle', ['⠂ working']));
	}

	public function testNormalizeTitleLeavesPlainTitlesAlone(): void
	{
		$this->assertSame('plain title', $this->invokeProtected('normalizeTitle', ['plain title']));
	}

	public function testNormalizeTitleLeavesNonAsciiNotAtStartAlone(): void
	{
		$this->assertSame('my ✳ tab', $this->invokeProtected('normalizeTitle', ['my ✳ tab']));
	}

	public function testNormalizeTitleGlyphOnlyBecomesEmpty(): void
	{
		$this->assertSame('', $this->invokeProtected('normalizeTitle', ['✳']));
	}

	public function testFirstCwdReturnsNullForEmptyWorkspace(): void
	{
		$this->assertNull($this->invokeProtected('firstCwdFromBakWs', [[]]));
	}

	public function testFirstCwdReturnsNullWhenAllCwdsEmpty(): void
	{
		$ws = ['panes' => [
			['surfaces' => [['cwd' => null], ['cwd' => '']]],
			['surfaces' => [['ref' => 's:3']]],
		]];
		$this->assertNull($this->invokeProtected('firstCwdFromBakWs', [$ws]));
	}

	public function testFirstCwdReturnsFirstNonEmptyIncludingLaterPane(): void
	{
		$ws = ['panes' => [
			['surfaces' => [['cwd' => ''], ['cwd' => null]]],
			['surfaces' => [['cwd' => '/first'], ['cwd' => '/second']]],
		]];
		$this->assertSame('/first', $this->invokeProtected('firstCwdFromBakWs', [$ws]));
	}

	public function testAllSurfacesEmptyWorkspace(): void
	{
		$this->assertSame([], $this->invokeProtected('allSurfacesFromBakWs', [[]]));
		$this->assertSame([], $this->invokeProtected('allSurfacesFromBakWs', [['panes' => []]]));
	}

	public function testAllSurfacesFlattensInPaneOrder(): void
	{
		$ws = ['panes' => [
			['surfaces' => [['ref' => 's:1'], ['ref' => 's:2']]],
			['surfaces' => [['ref' => 's:3']]],
		]];
		$surfs = $this->invokeProtected('allSurfacesFromBakWs', [$ws]);
		$this->assertSame(['s:1', 's:2', 's:3'], array_column($surfs, 'ref'));
	}
}
