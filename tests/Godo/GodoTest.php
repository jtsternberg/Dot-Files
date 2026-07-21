<?php
namespace JT\Tests\Godo;

use JT\Tests\TestCase;
use JT\Godo;

/**
 * godo — the command-map layer on top of dirmap.
 *
 * These cover the store logic (CRUD on ~/.godo-cmdmap.json) and command
 * resolution, which is where the behavior lives. Path resolution (shelling to
 * dirmap) and command execution (passthru) are thin integration seams left to
 * the live-verify pass, not unit-tested here.
 *
 * Each test points GODO_CMDMAP at a throwaway file so the real ~/.godo-cmdmap.json
 * is never touched.
 */
final class GodoTest extends TestCase
{
	private string $store = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->store = sys_get_temp_dir() . '/godo-' . getmypid() . '-' . uniqid() . '.json';
		putenv('GODO_CMDMAP=' . $this->store);
	}

	protected function tearDown(): void
	{
		putenv('GODO_CMDMAP');
		@unlink($this->store);
	}

	private function makeGodo(): Godo
	{
		return new Godo($this->cli);
	}

	public function testCreatesStoreFileOnConstruct(): void
	{
		$this->makeGodo();
		$this->assertFileExists($this->store);
		$this->assertSame([], json_decode((string) file_get_contents($this->store), true));
	}

	public function testAppendCommandCreatesKeyAndArray(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('dotfiles', 'git prb');

		$this->assertSame(['git prb'], $godo->getStoredCommands('dotfiles'));
	}

	public function testAppendCommandChainsMultiple(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('wpe', 'git prb');
		$godo->appendCommand('wpe', 'composer install');

		$this->assertSame(['git prb', 'composer install'], $godo->getStoredCommands('wpe'));
	}

	public function testAppendCommandIsIdempotent(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('wpe', 'composer install');
		$godo->appendCommand('wpe', 'composer install');

		$this->assertSame(['composer install'], $godo->getStoredCommands('wpe'));
	}

	public function testAppendTrimsAndSkipsEmpty(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('k', '  git prb  ');
		$godo->appendCommand('k', '   ');

		$this->assertSame(['git prb'], $godo->getStoredCommands('k'));
	}

	public function testResolveCommandsUsesStoredWhenPresent(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('k', 'make build');

		// Stored commands win even when a default is offered.
		$this->assertSame(['make build'], $godo->resolveCommands('k', 'git prb'));
	}

	public function testResolveCommandsEmptyWhenNoStoredAndNoDefault(): void
	{
		// This is the condition bare `godo <key>` treats as an error.
		$this->assertSame([], $this->makeGodo()->resolveCommands('never-seen'));
	}

	public function testResolveCommandsUsesDefaultWhenEmpty(): void
	{
		$this->assertSame(['git prb'], $this->makeGodo()->resolveCommands('never-seen', 'git prb'));
	}

	public function testResolveCommandsIgnoresBlankDefault(): void
	{
		$this->assertSame([], $this->makeGodo()->resolveCommands('never-seen', '   '));
	}

	public function testGetStoredCommandsEmptyForUnknownKey(): void
	{
		$this->assertSame([], $this->makeGodo()->getStoredCommands('nope'));
	}

	public function testSetCommandReplacesArray(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('k', 'a');
		$godo->appendCommand('k', 'b');
		$godo->setCommand('k', 'only');

		$this->assertSame(['only'], $godo->getStoredCommands('k'));
	}

	public function testRemoveCommandByValue(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('k', 'a');
		$godo->appendCommand('k', 'b');
		$godo->removeCommand('k', 'a');

		$this->assertSame(['b'], $godo->getStoredCommands('k'));
	}

	public function testRemoveLastCommandDropsKey(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('k', 'a');
		$godo->removeCommand('k', 'a');

		$this->assertNotContains('k', $godo->keys());
	}

	public function testRemoveCommandWithNullClearsKey(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('k', 'a');
		$godo->appendCommand('k', 'b');
		$godo->removeCommand('k', null);

		$this->assertNotContains('k', $godo->keys());
	}

	public function testRemoveKeyEntirely(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('k', 'a');
		$godo->remove('k');

		$this->assertSame([], $godo->getStoredCommands('k'));
	}

	public function testKeysAreSortedOnSave(): void
	{
		$godo = $this->makeGodo();
		$godo->appendCommand('zebra', 'a');
		$godo->appendCommand('alpha', 'a');

		$onDisk = json_decode((string) file_get_contents($this->store), true);
		$this->assertSame(['alpha', 'zebra'], array_keys($onDisk));
	}

	public function testChangesPersistAcrossInstances(): void
	{
		$this->makeGodo()->appendCommand('k', 'git prb');

		$reloaded = $this->makeGodo();
		$this->assertSame(['git prb'], $reloaded->getStoredCommands('k'));
	}

	/**
	 * Write a stub `dirmap` bin that resolves only the given keys, so
	 * resolvePath() can be tested without the real dirmap/~.dirmap.json.
	 * The stub mimics `dirmap get <key>`: echo a path + exit 0 for a known
	 * key, exit 1 otherwise.
	 *
	 * @param array<string,string> $map key => path it should resolve to
	 */
	private function stubDirmap(array $map): string
	{
		$bin = sys_get_temp_dir() . '/dirmap-stub-' . getmypid() . '-' . uniqid();
		$cases = '';
		foreach ($map as $key => $path) {
			$cases .= sprintf("    %s) echo %s; exit 0;;\n", escapeshellarg($key), escapeshellarg($path));
		}
		$script = "#!/bin/sh\n# \$1=get \$2=key\ncase \"\$2\" in\n{$cases}  *) exit 1;;\nesac\n";
		file_put_contents($bin, $script);
		chmod($bin, 0755);
		putenv('GODO_DIRMAP_BIN=' . $bin);

		return $bin;
	}

	public function testResolvePathReturnsDirForKnownKey(): void
	{
		$bin = $this->stubDirmap(['dotfiles' => '/Users/JT/.dotfiles']);

		$this->assertSame('/Users/JT/.dotfiles', $this->makeGodo()->resolvePath('dotfiles'));

		putenv('GODO_DIRMAP_BIN');
		@unlink($bin);
	}

	public function testResolvePathReturnsEmptyForUnknownKey(): void
	{
		$bin = $this->stubDirmap(['dotfiles' => '/Users/JT/.dotfiles']);

		// This is the exact condition `godo addcmd` uses to reject a bad key.
		$this->assertSame('', $this->makeGodo()->resolvePath('wpegg'));

		putenv('GODO_DIRMAP_BIN');
		@unlink($bin);
	}

	public function testStoreLandsAtCmdmapInHomeWithoutOverride(): void
	{
		$home    = sys_get_temp_dir() . '/godo-home-' . getmypid() . '-' . uniqid();
		$oldHome = getenv('HOME');
		@mkdir($home, 0755, true);
		putenv('GODO_CMDMAP');
		putenv('HOME=' . $home);

		$godo = $this->makeGodo();
		$this->assertSame($home . '/.godo-cmdmap.json', $godo->source);

		putenv('HOME=' . $oldHome);
		@unlink($home . '/.godo-cmdmap.json');
		@rmdir($home);
	}
}
