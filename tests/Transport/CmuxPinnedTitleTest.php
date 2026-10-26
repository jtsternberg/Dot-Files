<?php
namespace JT\Tests\Transport;

use JT\Helpers\Cmux;
use JT\Transport\CmuxTransport;
use JT\Tests\TestCase;

/**
 * A tab JT renamed by hand, as opposed to the title Claude sets on its own terminal
 * (dotfiles-76xl). `cmux tree` reports only the displayed title, so it cannot tell
 * the two apart; cmux's session-state file can, as a panel's `customTitle`. Keyed
 * by the panel `id`, which is the same surface UUID the tree reports.
 */
final class CmuxPinnedTitleTest extends TestCase
{
	private const RENAMED   = 'A191FFF3-AE0E-48FA-AF5C-C43E42FF9E15';
	private const UNRENAMED = '968B81E6-28F7-4214-9972-347B072B5146';

	protected function tearDown(): void
	{
		putenv('CMUX_SESSION_STATE');
		parent::tearDown();
	}

	/** The panel shapes cmux writes, trimmed to the keys that matter here. */
	private function state(): array
	{
		return ['windows' => [['tabManager' => ['workspaces' => [
			['customTitle' => 'a workspace name, not a tab', 'panels' => [
				['id' => self::RENAMED, 'title' => 'agent overseer', 'customTitle' => 'agent overseer', 'customTitleSource' => 'user'],
				['id' => self::UNRENAMED, 'title' => '✳ Herdr session fork'],
			]],
			['panels' => [
				['id' => 'B0000000-0000-4000-8000-000000000000', 'customTitle' => ''],
			]],
		]]]]];
	}

	private function treeWith(string $title): array
	{
		return ['windows' => [['ref' => 'window:1', 'workspaces' => [
			['ref' => 'workspace:2', 'id' => 'WS-UUID', 'title' => 'boss', 'panes' => [
				['ref' => 'pane:3', 'id' => 'PANE-UUID', 'index' => 0, 'surfaces' => [
					['ref' => 'surface:4', 'id' => self::RENAMED, 'type' => 'terminal', 'title' => $title],
					['ref' => 'surface:5', 'id' => self::UNRENAMED, 'type' => 'terminal', 'title' => '✳ Herdr session fork'],
				]],
			]],
		]]]];
	}

	public function test_parsePinnedTitles_keeps_only_panels_with_a_custom_title(): void
	{
		$this->assertSame([self::RENAMED => 'agent overseer'], $this->cmux->parsePinnedTitles($this->state()));
	}

	/** Lowercase ids still join: the tree reports them uppercase. */
	public function test_parsePinnedTitles_keys_by_uppercased_surface_id(): void
	{
		$state = ['windows' => [['tabManager' => ['workspaces' => [['panels' => [
			['id' => strtolower(self::RENAMED), 'customTitle' => 'x'],
		]]]]]]];
		$this->assertSame([self::RENAMED => 'x'], $this->cmux->parsePinnedTitles($state));
	}

	public function test_pinnedTitles_reads_the_session_state_file(): void
	{
		$path = $this->graveyardRoot . '/cmux-state.json';
		file_put_contents($path, json_encode($this->state()));
		putenv('CMUX_SESSION_STATE=' . $path);

		$this->assertSame([self::RENAMED => 'agent overseer'], $this->cmux->pinnedTitles());
	}

	/** No file, or one mid-write: nothing pinned, never an error. Bury must not fail on it. */
	public function test_pinnedTitles_is_empty_when_the_state_is_missing_or_unreadable(): void
	{
		putenv('CMUX_SESSION_STATE=' . $this->graveyardRoot . '/absent.json');
		$this->assertSame([], $this->cmux->pinnedTitles());

		$path = $this->graveyardRoot . '/torn.json';
		file_put_contents($path, '{"windows": [');
		putenv('CMUX_SESSION_STATE=' . $path);
		$this->assertSame([], $this->cmux->pinnedTitles());
	}

	public function test_surfaces_reports_the_pinned_title_and_null_for_an_unrenamed_tab(): void
	{
		$tree  = $this->treeWith('agent overseer');
		$state = $this->state();
		$cmux  = new class ($this->cli, $tree, $state) extends Cmux {
			public function __construct($cli, private array $t, private array $s) { parent::__construct($cli); }
			public function tree(): array { return $this->t; }
			public function pinnedTitles(): array { return $this->parsePinnedTitles($this->s); }
			public function debugTerminals(): string { return ''; }
			public function psProcTable(): string { return ''; }
			public function loadClaudeSessionsByPid(): array { return []; }
			public function loadCodexSessionsByPid(): array { return []; }
			public function codexSurfaceIdsByPid(): array { return []; }
		};

		$rows = (new CmuxTransport($this->cli, $cmux))->surfaces();
		$this->assertSame('agent overseer', $rows[0]['pinned_title']);
		$this->assertNull($rows[1]['pinned_title']);
	}

	public function test_a_live_session_row_carries_the_pinned_title(): void
	{
		$transport = new class ($this->cli, $this->cmux) extends CmuxTransport {
			public function rowFor(array $join, array $tree, array $pinned): array {
				return $this->liveSessionRow($join, $this->treeIndex($tree, $pinned), time());
			}
		};
		$join = [
			'session_id' => 'a', 'agent' => 'claude', 'cwd' => '/x', 'model' => null,
			'skip_perms' => false, 'pid' => null, 'tty' => null, 'surface_ref' => 'surface:4',
			'workspace_ref' => 'workspace:2', 'title' => '', 'targetable' => true, 'reason' => null,
		];
		$tree   = $this->treeWith('agent overseer');
		$pinned = $this->cmux->parsePinnedTitles($this->state());

		$this->assertSame('agent overseer', $transport->rowFor($join, $tree, $pinned)['pinned_title']);
		$this->assertNull($transport->rowFor(['surface_ref' => 'surface:5'] + $join, $tree, $pinned)['pinned_title']);
	}

	public function test_renameSurface_pins_the_title_with_rename_tab(): void
	{
		$log  = $this->graveyardRoot . '/cmux-argv.log';
		$stub = $this->graveyardRoot . '/cmux-rename-stub';
		file_put_contents($stub, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n");
		chmod($stub, 0755);
		putenv('CMUX_BIN=' . $stub);

		$this->assertTrue($this->transport->renameSurface('workspace:2', 'surface:4', "JT's tab"));
		$this->assertSame("rename-tab --workspace workspace:2 --surface surface:4 JT's tab\n", file_get_contents($log));
	}

	public function test_renameSurface_reports_a_failed_rename(): void
	{
		$stub = $this->graveyardRoot . '/cmux-fail-stub';
		file_put_contents($stub, "#!/bin/sh\nexit 1\n");
		chmod($stub, 0755);
		putenv('CMUX_BIN=' . $stub);

		$this->assertFalse($this->transport->renameSurface('workspace:2', 'surface:4', 'x'));
	}
}
