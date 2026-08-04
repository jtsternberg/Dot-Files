<?php
namespace JT\Tests;

use JT\Paths;

/**
 * Pins the repo-root resolver. src/Paths.php always sits directly in src/, so
 * one dirname() is always correct — the point of the class is that no caller
 * hand-counts dirname() levels from its own (movable) location again.
 */
class PathsTest extends TestCase
{
	public function testRootIsTheRepositoryRoot(): void
	{
		$this->assertSame(dirname(__DIR__), Paths::root());
	}

	public function testRootHoldsRealRepoMarkers(): void
	{
		$this->assertFileExists(Paths::root() . '/composer.json');
		$this->assertDirectoryExists(Paths::root() . '/bin');
		$this->assertDirectoryExists(Paths::root() . '/src');
	}

	public function testPathJoinsRelativeSegments(): void
	{
		$this->assertSame(Paths::root() . '/.env', Paths::path('.env'));
		$this->assertSame(Paths::root() . '/bin/godo', Paths::path('/bin/godo'));
		$this->assertSame(Paths::root(), Paths::path());
	}
}
