<?php
namespace JT\Tests\Helpers;

use JT\Helpers\Herdr;
use JT\Tests\TestCase;

/**
 * Every test drives a stub script at HERDR_BIN, so nothing here reaches the real
 * herdr server (CLAUDE.md's shelling-seam rule; same hook as CMUX_BIN and
 * GODO_DIRMAP_BIN). The stubs also record their argv, which is how the
 * positional-vs-flag shapes below are pinned: `pane process-info` takes
 * `--pane <id>` while `pane read`/`send-text`/`close` take a positional, and
 * getting that backwards makes herdr answer `unknown option: <pane id>`.
 */
final class HerdrTest extends TestCase
{
	private string $fixture;

	/** Where a stub writes the argv it was called with. */
	private string $argvLog;

	protected function setUp(): void
	{
		parent::setUp();
		$this->fixture = dirname(__DIR__) . '/fixtures/herdr/snapshot-two-claude-one-codex.json';
		$this->argvLog = $this->graveyardRoot . '/herdr-argv.log';
		$this->stubHerdr('cat ' . escapeshellarg($this->fixture));
	}

	protected function tearDown(): void
	{
		putenv('HERDR_BIN');
		parent::tearDown();
	}

	/**
	 * Install a stub at HERDR_BIN. $body is shell run after the argv is logged;
	 * $exit is the stub's exit code and $stderr what it writes to fd 2, so a test
	 * can drive herdr's real failure shape (error JSON on stderr, exit 1).
	 */
	private function stubHerdr(string $body, int $exit = 0, string $stderr = ''): void
	{
		$bin = $this->graveyardRoot . '/herdr-stub-' . bin2hex(random_bytes(4));
		$script = "#!/bin/sh\n"
			. 'printf "%s\n" "$*" >> ' . escapeshellarg($this->argvLog) . "\n"
			. ($stderr !== '' ? 'printf "%s" ' . escapeshellarg($stderr) . " >&2\n" : '')
			. $body . "\n"
			. "exit {$exit}\n";
		file_put_contents($bin, $script);
		chmod($bin, 0755);
		putenv('HERDR_BIN=' . $bin);
	}

	/** The argv line of the stub's Nth (0-based) invocation. */
	private function loggedArgv(int $n = 0): string
	{
		$lines = array_values(array_filter(explode("\n", (string) @file_get_contents($this->argvLog)), 'strlen'));
		return $lines[$n] ?? '';
	}

	private function herdr(): Herdr
	{
		return new Herdr($this->cli);
	}

	public function testHerdrBinHonoursTheEnvOverride(): void
	{
		$this->assertSame(getenv('HERDR_BIN'), $this->herdr()->herdrBin());
	}

	public function testHerdrBinDefaultsToHerdrOnThePath(): void
	{
		putenv('HERDR_BIN');
		$this->assertSame('herdr', $this->herdr()->herdrBin());
	}

	public function testSnapshotUnwrapsTheResultEnvelope(): void
	{
		$snap = $this->herdr()->snapshot();

		$this->assertArrayHasKey('agents', $snap);
		$this->assertArrayNotHasKey('result', $snap);
		$this->assertCount(3, $snap['agents']);
		$this->assertSame('api snapshot', $this->loggedArgv());
	}

	public function testSnapshotCarriesTheFieldsTheTransportJoinsOn(): void
	{
		$agents = $this->herdr()->snapshot()['agents'];

		$this->assertSame('claude', $agents[0]['agent']);
		$this->assertSame('id', $agents[0]['agent_session']['kind']);
		$this->assertSame('herdr:claude', $agents[0]['agent_session']['source']);
		$this->assertSame('11111111-1111-4111-8111-111111111111', $agents[0]['agent_session']['value']);
		$this->assertSame('wA:p1', $agents[0]['pane_id']);
		$this->assertSame('wA', $agents[0]['workspace_id']);
		$this->assertSame('codex', $agents[2]['agent']);
	}

	public function testSnapshotThrowsRatherThanExitingWhenUnparseable(): void
	{
		$this->stubHerdr("echo 'not json'");

		$this->expectException(\RuntimeException::class);
		$this->herdr()->snapshot();
	}

	public function testSnapshotThrowsWhenTheOutputIsEmpty(): void
	{
		$this->stubHerdr(':');

		$this->expectException(\RuntimeException::class);
		$this->herdr()->snapshot();
	}

	public function testSnapshotThrowsWithHerdrsOwnErrorMessage(): void
	{
		$err = '{"error":{"code":"server_unavailable","message":"herdr server is not running"},"id":"cli:api:snapshot"}';
		$this->stubHerdr(':', 1, $err);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('herdr server is not running');
		$this->herdr()->snapshot();
	}

	public function testAvailableIsTrueForARunningCompatibleServer(): void
	{
		$this->stubHerdr("printf '%s\\n' 'server:' '  status: running' '  compatible: yes'");
		$this->assertTrue($this->herdr()->available());
		$this->assertSame('status', $this->loggedArgv());
	}

	public function testAvailableIsFalseWhenTheServerIsIncompatible(): void
	{
		$this->stubHerdr("printf '%s\\n' 'server:' '  status: running' '  compatible: no'");
		$this->assertFalse($this->herdr()->available());
	}

	public function testAvailableIsFalseAndDoesNotThrowWhenHerdrIsAbsent(): void
	{
		putenv('HERDR_BIN=' . $this->graveyardRoot . '/no-such-herdr');
		$this->assertFalse($this->herdr()->available());
	}

	public function testPaneProcessInfoUsesThePaneFlagNotAPositional(): void
	{
		$info = [
			'foreground_process_group_id' => 40645,
			'foreground_processes' => [[
				'argv'    => ['claude', '--session-id', 'abc', '--model', 'opus', '--dangerously-skip-permissions'],
				'argv0'   => 'claude',
				'cmdline' => 'claude --session-id abc --model opus --dangerously-skip-permissions',
				'cwd'     => '/Users/tester/Sites/alpha',
				'name'    => 'claude.exe',
				'pid'     => 40645,
			]],
			'pane_id'   => 'wA:p1',
			'shell_pid' => 40434,
		];
		$this->stubHerdr('cat <<\'EOF\'' . "\n" . json_encode(['id' => 'cli:pane:process_info', 'result' => ['process_info' => $info, 'type' => 'pane_process_info']]) . "\nEOF");

		$out = $this->herdr()->paneProcessInfo('wA:p1');

		$this->assertSame(40434, $out['shell_pid']);
		// argv is a real array, not a string to re-split.
		$this->assertSame('--session-id', $out['foreground_processes'][0]['argv'][1]);
		$this->assertSame('pane process-info --pane wA:p1', $this->loggedArgv());
	}

	public function testPaneReadReturnsPlainTextAndTakesAPositionalPaneId(): void
	{
		$this->stubHerdr("printf '%s\\n' 'line one' 'line two'");

		$out = $this->herdr()->paneRead('wA:p1', 'visible', 40);

		$this->assertSame("line one\nline two", trim($out));
		$this->assertSame('pane read wA:p1 --source visible --lines 40', $this->loggedArgv());
	}

	public function testPaneReadOmitsLinesWhenNotGiven(): void
	{
		$this->stubHerdr(':');
		$this->herdr()->paneRead('wA:p1');
		$this->assertSame('pane read wA:p1 --source recent', $this->loggedArgv());
	}

	public function testPaneReadThrowsWhenHerdrFails(): void
	{
		$this->stubHerdr(':', 1, '{"error":{"code":"pane_not_found","message":"pane wZ:p9 not found"},"id":"cli:pane:read"}');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('pane wZ:p9 not found');
		$this->herdr()->paneRead('wZ:p9');
	}

	public function testPaneSendTextAndSendKeysTakePositionalArgs(): void
	{
		$this->stubHerdr(':');
		$h = $this->herdr();

		$h->paneSendText('wA:p1', 'echo hi');
		$h->paneSendKeys('wA:p1', 'enter');

		$this->assertSame('pane send-text wA:p1 echo hi', $this->loggedArgv(0));
		$this->assertSame('pane send-keys wA:p1 enter', $this->loggedArgv(1));
	}

	public function testPaneRunTakesPositionalPaneAndCommand(): void
	{
		$this->stubHerdr(':');
		$this->herdr()->paneRun('wA:p1', 'claude --resume abc');
		$this->assertSame('pane run wA:p1 claude --resume abc', $this->loggedArgv());
	}

	public function testPaneSplitReturnsTheNewPaneId(): void
	{
		$this->stubHerdr('cat <<\'EOF\'' . "\n" . json_encode([
			'id'     => 'cli:pane:split',
			'result' => ['pane' => ['pane_id' => 'wA:p2', 'workspace_id' => 'wA', 'tab_id' => 'wA:t1'], 'type' => 'pane_info'],
		]) . "\nEOF");

		$this->assertSame('wA:p2', $this->herdr()->paneSplit('wA:p1', 'right'));
		$this->assertSame('pane split --pane wA:p1 --direction right', $this->loggedArgv());
	}

	public function testPaneSplitReturnsNullWhenHerdrFails(): void
	{
		$this->stubHerdr(':', 1, '{"error":{"code":"bad_request","message":"nope"}}');
		$this->assertNull($this->herdr()->paneSplit('wA:p1', 'right'));
	}

	public function testPaneCloseTakesAPositionalPaneId(): void
	{
		$this->stubHerdr('echo \'{"id":"cli:pane:close","result":{"type":"ok"}}\'');
		$this->herdr()->paneClose('wA:p2');
		$this->assertSame('pane close wA:p2', $this->loggedArgv());
	}

	public function testWorkspaceCreatePassesLabelCwdAndEnvAndReturnsTheRootPane(): void
	{
		$this->stubHerdr('cat <<\'EOF\'' . "\n" . json_encode([
			'id'     => 'cli:workspace:create',
			'result' => [
				'root_pane' => ['pane_id' => 'wJ:p1', 'tab_id' => 'wJ:t1', 'workspace_id' => 'wJ'],
				'tab'       => ['tab_id' => 'wJ:t1', 'workspace_id' => 'wJ'],
				'type'      => 'workspace_created',
				'workspace' => ['workspace_id' => 'wJ', 'label' => 'gy-restore'],
			],
		]) . "\nEOF");

		$out = $this->herdr()->workspaceCreate('gy-restore', '/Users/tester/Sites/alpha', ['FOO' => 'bar']);

		$this->assertSame('wJ', $out['workspace']['workspace_id']);
		$this->assertSame('wJ:p1', $out['root_pane']['pane_id']);
		$this->assertSame(
			'workspace create --label gy-restore --cwd /Users/tester/Sites/alpha --env FOO=bar --no-focus',
			$this->loggedArgv()
		);
	}

	public function testWorkspaceCreateReturnsNullWhenHerdrFails(): void
	{
		$this->stubHerdr(':', 1, '{"error":{"code":"bad_request","message":"nope"}}');
		$this->assertNull($this->herdr()->workspaceCreate('gy-restore', null));
	}

	public function testWorkspaceCloseTakesAPositionalIdAndReturnsTheResult(): void
	{
		$this->stubHerdr('echo \'{"id":"cli:workspace:close","result":{"type":"ok"}}\'');

		$this->assertSame(['type' => 'ok'], $this->herdr()->workspaceClose('wJ'));
		$this->assertSame('workspace close wJ', $this->loggedArgv());
	}

	public function testWorkspaceFocusReportsSuccess(): void
	{
		$this->stubHerdr('echo \'{"id":"cli:workspace:focus","result":{"type":"workspace_info","workspace":{"workspace_id":"wJ"}}}\'');

		$this->assertTrue($this->herdr()->workspaceFocus('wJ'));
		$this->assertSame('workspace focus wJ', $this->loggedArgv());
	}

	public function testWorkspaceFocusReportsFailureWithoutThrowing(): void
	{
		$this->stubHerdr(':', 1, '{"error":{"code":"workspace_not_found","message":"nope"}}');
		$this->assertFalse($this->herdr()->workspaceFocus('wZ'));
	}

	/**
	 * The whole point of the seam: an argument that would otherwise be read as a
	 * flag or split on whitespace has to arrive as one argv entry.
	 */
	public function testArgumentsAreShellEscaped(): void
	{
		$this->stubHerdr(':');
		$this->herdr()->paneSendText('wA:p1', '--not-a-flag; rm -rf /');
		$this->assertSame("pane send-text wA:p1 --not-a-flag; rm -rf /", $this->loggedArgv());
	}
}
