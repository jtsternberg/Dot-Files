<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;
use JT\CLI\Commands\SiteCommand;
use JT\Paths;

/**
 * Regression coverage for SiteCommand::loadEnv().
 *
 * The class moved from misc/commands/ to src/CLI/Commands/ in f37c6c9 as a pure
 * rename, so its hand-counted dirname(dirname(__DIR__)) silently started
 * resolving to src/ instead of the repo root and every command extending it
 * (jt-blog-*, hisi-*) died with ".env file not found at .../src/.env".
 */
class SiteCommandEnvTest extends TestCase
{
	private function stub(?string $root = null): SiteCommand
	{
		return new class( $this->cli, $root ) extends SiteCommand {
			private ?string $rootOverride;

			public function __construct( $cli, ?string $root ) {
				parent::__construct( $cli );
				$this->rootOverride = $root;
			}

			protected function repoRoot(): string {
				return $this->rootOverride ?? parent::repoRoot();
			}

			protected function getRequiredEnvVars(): array {
				return [];
			}

			public function callEnvFilePath(): string {
				return $this->envFilePath();
			}

			public function callLoadEnv(): void {
				$this->loadEnv();
			}

			public function run(): void {}
		};
	}

	public function testEnvFileResolvesToRepoRootNotSrc(): void
	{
		$path = $this->stub()->callEnvFilePath();

		$this->assertSame(Paths::root() . '/.env', $path);
		$this->assertNotSame(Paths::root() . '/src/.env', $path);
	}

	public function testLoadEnvErrorNamesTheResolvedPath(): void
	{
		$root = $this->graveyardRoot . '/no-env';
		mkdir($root, 0777, true);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage($root . '/.env');

		$this->stub($root)->callLoadEnv();
	}

	public function testLoadEnvReadsTheEnvFileFromTheResolvedRoot(): void
	{
		$root = $this->graveyardRoot . '/with-env';
		mkdir($root, 0777, true);
		file_put_contents($root . '/.env', "JTS_SITE_ENV_PROBE=loaded\n");

		unset($_ENV['JTS_SITE_ENV_PROBE'], $_SERVER['JTS_SITE_ENV_PROBE']);

		$this->stub($root)->callLoadEnv();

		$this->assertSame('loaded', $_ENV['JTS_SITE_ENV_PROBE'] ?? null);

		unset($_ENV['JTS_SITE_ENV_PROBE'], $_SERVER['JTS_SITE_ENV_PROBE']);
	}
}
