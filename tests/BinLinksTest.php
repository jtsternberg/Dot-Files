<?php
namespace JT\Tests;

use JT\BinLinks;
use JT\Paths;

/**
 * Guard against the failure shape that broke wplocal/localwpshell/
 * silentlocalwpshell: hand-made symlinks in bin/ pointing into another repo
 * that later restructured, leaving the commands silently gone from PATH.
 *
 * "Rotted" means the target's tree is installed on this machine but the target
 * itself is missing — the moved-file case. A link whose whole tree is absent
 * (a Mac-only plugin checkout on Linux) is not rot and must not fail the suite.
 */
class BinLinksTest extends TestCase
{
	public function testBinDirHasNoRottedSymlinks(): void
	{
		$rot = BinLinks::rot();

		$this->assertSame([], $rot, "Rotted symlinks in bin/:\n" . implode(
			"\n",
			array_map( fn( $e ) => "  {$e['link']} -> {$e['target']}", $rot )
		));
	}

	public function testDetectsALinkWhoseTargetMovedWithinAnExistingTree(): void
	{
		$root = $this->fixture();
		mkdir("$root/plugin/skills/scripts", 0777, true);
		touch("$root/plugin/skills/scripts/tool");
		mkdir("$root/bin", 0777, true);
		symlink("$root/plugin/scripts/tool", "$root/bin/tool");

		$rot = BinLinks::rot("$root/bin");

		$this->assertCount(1, $rot);
		$this->assertSame('tool', $rot[0]['link']);
		$this->assertSame("$root/plugin/scripts/tool", $rot[0]['target']);
	}

	public function testIgnoresALinkWhoseEntireTreeIsAbsent(): void
	{
		$root = $this->fixture();
		mkdir("$root/bin", 0777, true);
		symlink("$root/not-installed/anywhere/near/here/tool", "$root/bin/tool");

		$this->assertSame([], BinLinks::rot("$root/bin"));
	}

	public function testIgnoresResolvableLinksAndPlainFiles(): void
	{
		$root = $this->fixture();
		mkdir("$root/elsewhere", 0777, true);
		touch("$root/elsewhere/tool");
		mkdir("$root/bin", 0777, true);
		symlink("$root/elsewhere/tool", "$root/bin/absolute");
		symlink('../elsewhere/tool', "$root/bin/relative");
		touch("$root/bin/regular-script");

		$this->assertSame([], BinLinks::rot("$root/bin"));
	}

	public function testFlagsARelativeLinkThatNoLongerResolves(): void
	{
		$root = $this->fixture();
		mkdir("$root/elsewhere/deeper", 0777, true);
		mkdir("$root/bin", 0777, true);
		symlink('../elsewhere/deeper/gone/tool', "$root/bin/tool");

		$rot = BinLinks::rot("$root/bin");

		$this->assertCount(1, $rot);
		$this->assertSame('../elsewhere/deeper/gone/tool', $rot[0]['target']);
	}

	public function testDefaultsToTheRepoBinDir(): void
	{
		$this->assertDirectoryExists(Paths::path('bin'));
		$this->assertSame(BinLinks::rot(Paths::path('bin')), BinLinks::rot());
	}

	private function fixture(): string
	{
		$dir = $this->graveyardRoot . '/binlinks-' . bin2hex(random_bytes(4));
		mkdir($dir, 0777, true);

		return $dir;
	}
}
