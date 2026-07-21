<?php
namespace JT\Tests\LinuxCatchup;

use JT\Tests\TestCase;
use JT\LinuxCatchup;

/**
 * linux-catchup — config loading and apt output parsing.
 *
 * Command execution (codex/claude/godo/apt via passthru) is orchestration in
 * the bin entry script and isn't unit-tested; this covers the pure logic.
 * LINUX_CATCHUP_CONFIG points at a throwaway file so the real config is safe.
 */
final class LinuxCatchupTest extends TestCase
{
	private string $config = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = sys_get_temp_dir() . '/lc-' . getmypid() . '-' . uniqid() . '.json';
		putenv('LINUX_CATCHUP_CONFIG=' . $this->config);
	}

	protected function tearDown(): void
	{
		putenv('LINUX_CATCHUP_CONFIG');
		@unlink($this->config);
	}

	private function make(): LinuxCatchup
	{
		return new LinuxCatchup($this->cli);
	}

	public function testWritesDefaultConfigOnFirstRun(): void
	{
		$lc = $this->make();
		$this->assertFileExists($this->config);
		$this->assertSame(
			['claudeplugins', 'notes', 'dotfiles', 'wpengine-jtsternberg'],
			$lc->repos()
		);
		$this->assertTrue($lc->wants('codex'));
		$this->assertTrue($lc->wants('claude'));
		$this->assertTrue($lc->wants('system'));
	}

	public function testPartialConfigMergesOverDefaults(): void
	{
		file_put_contents($this->config, json_encode(['codex' => false, 'repos' => ['dotfiles']]));

		$lc = $this->make();
		$this->assertFalse($lc->wants('codex'));
		$this->assertTrue($lc->wants('claude'));   // defaulted
		$this->assertSame(['dotfiles'], $lc->repos());
	}

	public function testInvalidJsonFallsBackToDefaults(): void
	{
		file_put_contents($this->config, 'not json {');

		$lc = $this->make();
		$this->assertSame(LinuxCatchup::DEFAULT_CONFIG['repos'], $lc->repos());
	}

	public function testWantsIsFalseForEmptyOrMissing(): void
	{
		file_put_contents($this->config, json_encode(['system' => false, 'repos' => []]));

		$lc = $this->make();
		$this->assertFalse($lc->wants('system'));
		$this->assertSame([], $lc->repos());
	}

	public function testParseOnlyNormalizesAndAliases(): void
	{
		[$known, $unknown] = $this->make()->parseOnly('system-update, repos');

		$this->assertSame(['system', 'repos'], $known);
		$this->assertSame([], $unknown);
	}

	public function testParseOnlyReportsUnknownSteps(): void
	{
		[$known, $unknown] = $this->make()->parseOnly('system,bogus');

		$this->assertSame(['system'], $known);
		$this->assertSame(['bogus'], $unknown);
	}

	public function testShouldRunWithoutOnlyFollowsConfig(): void
	{
		file_put_contents($this->config, json_encode(['codex' => false, 'system' => true, 'repos' => ['x']]));
		$lc = $this->make();

		$this->assertFalse($lc->shouldRun('codex', null));
		$this->assertTrue($lc->shouldRun('system', null));
		$this->assertTrue($lc->shouldRun('repos', null));
	}

	public function testOnlyRestrictsToNamedSteps(): void
	{
		$lc = $this->make();  // all steps enabled by default

		$this->assertTrue($lc->shouldRun('system', 'system'));
		$this->assertFalse($lc->shouldRun('codex', 'system'));
		$this->assertFalse($lc->shouldRun('repos', 'system'));
	}

	public function testOnlyOverridesConfigToggle(): void
	{
		// codex disabled in config, but explicitly requested via --only.
		file_put_contents($this->config, json_encode(['codex' => false]));
		$this->assertTrue($this->make()->shouldRun('codex', 'codex'));
	}

	public function testOnlyReposStillNeedsNonEmptyList(): void
	{
		file_put_contents($this->config, json_encode(['repos' => []]));
		$this->assertFalse($this->make()->shouldRun('repos', 'repos'));
	}

	public function testParseUpgradableCountsAndFlagsSecurity(): void
	{
		$raw = <<<TXT
		Listing... Done
		vim/jammy-security 2:8.2.3995 amd64 [upgradable from: 2:8.2.3000]
		curl/jammy-updates 7.81.0-1ubuntu1.15 amd64 [upgradable from: 7.81.0-1ubuntu1.14]
		openssl/jammy-security 3.0.2-0ubuntu1.15 amd64 [upgradable from: 3.0.2-0ubuntu1.10]
		TXT;

		$report = $this->make()->parseUpgradable($raw);

		$this->assertSame(3, $report['count']);
		$this->assertSame(['vim', 'curl', 'openssl'], $report['packages']);
		$this->assertSame(['vim', 'openssl'], $report['security']);
		$this->assertFalse($report['reboot_required']);
	}

	public function testParseUpgradableEmptyWhenUpToDate(): void
	{
		$report = $this->make()->parseUpgradable("Listing... Done\n");

		$this->assertSame(0, $report['count']);
		$this->assertSame([], $report['packages']);
		$this->assertSame([], $report['security']);
	}

	public function testParseUpgradablePassesRebootFlag(): void
	{
		$report = $this->make()->parseUpgradable('Listing... Done', true);
		$this->assertTrue($report['reboot_required']);
	}

	public function testParseUpgradableIgnoresJunkLines(): void
	{
		$raw = "\n\nWARNING: apt does not have a stable CLI interface.\nListing...\nnginx/jammy 1.18 amd64 [upgradable from: 1.17]\n";
		$report = $this->make()->parseUpgradable($raw);

		// The WARNING line has no `pkg/repo` shape, so it's skipped.
		$this->assertSame(['nginx'], $report['packages']);
	}
}
