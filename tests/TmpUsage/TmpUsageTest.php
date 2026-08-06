<?php
namespace JT\Tests\TmpUsage;

use JT\TmpUsage;
use JT\Tests\TestCase;

/**
 * /tmp usage measurement.
 *
 * The trap this covers is the one the old shell watchdog fell into: it globbed
 * `/tmp/*`, which silently skips every dot-entry, so a multi-gig `.cache`-style
 * hog read as zero. So the fixtures here deliberately include a hidden entry,
 * and the totals must account for it.
 *
 * Sizes come from `du -sk`, which reports allocated blocks, not bytes — fixture
 * files are written big enough that the threshold assertions hold regardless of
 * block size or directory overhead.
 */
class TmpUsageTest extends TestCase
{
	private string $root = '';

	protected function setUp(): void
	{
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/tmpusage-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/big', 0777, true);

		// ~3MB inside a directory, ~2MB in a hidden file, and a token small file.
		file_put_contents($this->root . '/big/payload.bin', str_repeat('x', 3 * 1024 * 1024));
		file_put_contents($this->root . '/.hidden-big', str_repeat('y', 2 * 1024 * 1024));
		file_put_contents($this->root . '/small', str_repeat('z', 4 * 1024));
	}

	protected function tearDown(): void
	{
		$this->removeFixture($this->root);
		parent::tearDown();
	}

	/** Remove a fixture tree, refusing anything outside the system temp dir. */
	private function removeFixture(string $dir): void
	{
		$tmp  = realpath(sys_get_temp_dir());
		$real = realpath($dir);
		if ($tmp === false || $real === false || !str_starts_with($real, $tmp)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $f) {
			$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		}
		@rmdir($real);
	}

	public function testScanTotalIsTheSumOfItsItems(): void
	{
		$scan = (new TmpUsage($this->root))->scan();

		$this->assertNotEmpty($scan['items']);
		$this->assertSame(array_sum($scan['items']), $scan['totalKb']);
		// 3MB + 2MB + change, with room for block/dir overhead in either direction.
		$this->assertGreaterThanOrEqual(5 * 1024, $scan['totalKb']);
	}

	public function testScanIncludesHiddenEntriesAndSkipsDotDirs(): void
	{
		$items = (new TmpUsage($this->root))->scan()['items'];

		$this->assertSame(['big', '.hidden-big', 'small'], array_keys($items), 'items are keyed by basename, largest first');
		$this->assertArrayNotHasKey('.', $items);
		$this->assertArrayNotHasKey('..', $items);
		$this->assertGreaterThanOrEqual(3 * 1024, $items['big']);
		$this->assertGreaterThanOrEqual(2 * 1024, $items['.hidden-big']);
	}

	public function testTotalMbAndItemsMbReportWholeMegabytes(): void
	{
		$usage = new TmpUsage($this->root);

		$this->assertGreaterThanOrEqual(5, $usage->totalMb());
		$this->assertSame(3, $usage->itemsMb()['big']);
		$this->assertSame(2, $usage->itemsMb()['.hidden-big']);
	}

	public function testOffendersFiltersByThresholdAndSortsDescending(): void
	{
		$offenders = (new TmpUsage($this->root))->offenders(1);

		$this->assertSame(['big', '.hidden-big'], array_keys($offenders));
		$this->assertSame(3, $offenders['big']);
		$this->assertArrayNotHasKey('small', $offenders, 'sub-threshold entries are not offenders');
	}

	public function testOffendersIsEmptyWhenNothingReachesTheThreshold(): void
	{
		$this->assertSame([], (new TmpUsage($this->root))->offenders(500));
	}

	public function testMissingRootReadsAsEmptyWithoutErrors(): void
	{
		$usage = new TmpUsage($this->root . '/nope-not-here');

		$this->assertSame(['totalKb' => 0, 'items' => []], $usage->scan());
		$this->assertSame(0, $usage->totalMb());
		$this->assertSame([], $usage->itemsMb());
		$this->assertSame([], $usage->offenders(1));
	}

	public function testScanIsMemoizedSoOneRunSeesOneSnapshot(): void
	{
		$usage = new TmpUsage($this->root);
		$first = $usage->scan();

		// A single watchdog run must not re-walk /tmp per accessor: the readout,
		// the log line and the alert all have to agree on one snapshot.
		file_put_contents($this->root . '/late-arrival', str_repeat('q', 2 * 1024 * 1024));

		$this->assertSame($first, $usage->scan());
		$this->assertArrayNotHasKey('late-arrival', $usage->itemsMb());
		$this->assertArrayNotHasKey('late-arrival', $usage->offenders(1));
	}
}
