<?php
namespace JT\Tests\Graveyard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GraveyardTempCleanupTest extends TestCase
{
	public static function leakingTestProvider(): array
	{
		return [
			'codex discovery' => [
				'GraveyardCodexTest.php',
				'testCodexLastActivityNullForMissingRollout',
				'gy-codex-*',
			],
			'codex bury' => [
				'GraveyardCodexBuryTest.php',
				'testArchiveCodexRolloutFailsWhenTheRolloutIsGone',
				'gy-codexbury-*',
			],
			'view parity' => [
				'GraveyardLaunchSafetyTest.php',
				'testLsAndSearchReadTheSameAnnotatedSource',
				'gy-parity-*',
			],
		];
	}

	#[DataProvider('leakingTestProvider')]
	public function testGraveyardTestsRemoveTheirTemporaryRoots(
		string $testFile,
		string $filter,
		string $tempPattern
	): void {
		$before = $this->matchingRoots($tempPattern);
		$root = dirname(__DIR__, 2);
		$command = implode(' ', array_map('escapeshellarg', [
			PHP_BINARY,
			$root . '/vendor/bin/phpunit',
			'--configuration',
			$root . '/phpunit.xml.dist',
			'--filter',
			$filter,
			__DIR__ . '/' . $testFile,
		]));

		exec($command, $output, $exitCode);
		$after = $this->matchingRoots($tempPattern);
		$leaked = array_values(array_diff($after, $before));

		foreach ($leaked as $path) {
			$this->removeTree($path);
		}

		$this->assertSame(0, $exitCode, implode("\n", $output));
		$this->assertSame([], $leaked, "$testFile left a $tempPattern root behind");
	}

	private function matchingRoots(string $pattern): array
	{
		$roots = glob(sys_get_temp_dir() . '/' . $pattern, GLOB_ONLYDIR) ?: [];
		sort($roots);
		return $roots;
	}

	private function removeTree(string $dir): void
	{
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $file) {
			$file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
		}
		@rmdir($dir);
	}
}
