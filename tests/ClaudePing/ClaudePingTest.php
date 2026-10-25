<?php
namespace JT\Tests\ClaudePing;

use JT\ClaudePing;
use JT\Tests\TestCase;

/**
 * ClaudePing installs a crontab entry that makes a cheap headless `claude -p`
 * call at a given schedule. Both shell-out seams (crontab, claude) are stubbed
 * here — no test ever touches the real crontab or spends a real API call.
 */
final class ClaudePingTest extends TestCase
{
    private string $crontabState = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->crontabState = sys_get_temp_dir() . '/claudeping-crontab-' . getmypid() . '-' . uniqid();
        putenv('CLAUDEPING_CRONTAB_STATE=' . $this->crontabState);
        putenv('CLAUDEPING_CRONTAB_BIN=' . self::sharedStub('claudeping-crontab', <<<'SH'
#!/bin/sh
if [ "$1" = "-l" ]; then
  [ -f "$CLAUDEPING_CRONTAB_STATE" ] && cat "$CLAUDEPING_CRONTAB_STATE"
  exit 0
fi
cp "$1" "$CLAUDEPING_CRONTAB_STATE"
exit 0
SH
        ));
        putenv('CLAUDEPING_CLAUDE_BIN=' . self::sharedStub('claudeping-claude', <<<'SH'
#!/bin/sh
if [ "${CLAUDEPING_CLAUDE_STUB_EXIT:-0}" != "0" ]; then
  exit "$CLAUDEPING_CLAUDE_STUB_EXIT"
fi
printf '%s' "$CLAUDEPING_CLAUDE_STUB_REPLY"
SH
        ));
    }

    protected function tearDown(): void
    {
        putenv('CLAUDEPING_CRONTAB_STATE');
        putenv('CLAUDEPING_CRONTAB_BIN');
        putenv('CLAUDEPING_CLAUDE_BIN');
        putenv('CLAUDEPING_CLAUDE_STUB_REPLY');
        putenv('CLAUDEPING_CLAUDE_STUB_EXIT');
        putenv('CLAUDEPING_LOG');
        @unlink($this->crontabState);

        parent::tearDown();
    }

    private function makeClaudePing(): ClaudePing
    {
        return new ClaudePing($this->cli);
    }

    private function stubClaudeReply(string $result): void
    {
        putenv('CLAUDEPING_CLAUDE_STUB_REPLY=' . json_encode(['result' => $result]));
    }

    public function testParseScheduleReturnsNullWhenClaudeSaysNo(): void
    {
        $this->stubClaudeReply('no');

        $this->assertNull($this->makeClaudePing()->parseSchedule('do the thing sometimes'));
    }

    public function testParseScheduleReturnsCronExpressionWhenValid(): void
    {
        $this->stubClaudeReply('30 5 * * 2-5');

        $this->assertSame('30 5 * * 2-5', $this->makeClaudePing()->parseSchedule('weekdays at 5:30am'));
    }

    public function testParseScheduleReturnsNullForGarbageReply(): void
    {
        $this->stubClaudeReply('sure, how about every morning');

        $this->assertNull($this->makeClaudePing()->parseSchedule('mornings'));
    }

    public function testParseScheduleReturnsNullOnNonZeroExit(): void
    {
        putenv('CLAUDEPING_CLAUDE_STUB_EXIT=1');

        $this->assertNull($this->makeClaudePing()->parseSchedule('weekdays at 5:30am'));
    }

    public function testInstallWritesManagedLineWithCronExpression(): void
    {
        $this->makeClaudePing()->install('30 5 * * 2-5');

        $line = $this->makeClaudePing()->managedLine();
        $this->assertNotNull($line);
        $this->assertStringStartsWith('30 5 * * 2-5 ', $line);
        $this->assertStringContainsString('-p "hello"', $line);
        $this->assertStringContainsString(ClaudePing::MANAGED_TAG, $line);
    }

    public function testInstallIsIdempotentReplacingPreviousManagedLine(): void
    {
        $cp = $this->makeClaudePing();
        $cp->install('30 5 * * 2-5');
        $cp->install('0 6 * * 1-5');

        $lines = explode("\n", trim((string) file_get_contents($this->crontabState)));
        $managed = array_values(array_filter($lines, fn($l) => str_contains($l, ClaudePing::MANAGED_TAG)));

        $this->assertCount(1, $managed);
        $this->assertStringStartsWith('0 6 * * 1-5 ', $managed[0]);
    }

    public function testInstallPreservesUnrelatedExistingCrontabLines(): void
    {
        file_put_contents($this->crontabState, "17 3 * * * do-something-else.sh\n");

        $this->makeClaudePing()->install('30 5 * * 2-5');

        $contents = (string) file_get_contents($this->crontabState);
        $this->assertStringContainsString('do-something-else.sh', $contents);
        $this->assertStringContainsString(ClaudePing::MANAGED_TAG, $contents);
    }

    public function testRemoveStripsManagedLineAndReturnsTrue(): void
    {
        file_put_contents($this->crontabState, "17 3 * * * do-something-else.sh\n");
        $cp = $this->makeClaudePing();
        $cp->install('30 5 * * 2-5');

        $this->assertTrue($cp->remove());
        $this->assertNull($cp->managedLine());
        $this->assertStringContainsString('do-something-else.sh', (string) file_get_contents($this->crontabState));
    }

    public function testRemoveReturnsFalseWhenNothingInstalled(): void
    {
        $this->assertFalse($this->makeClaudePing()->remove());
    }

    public function testManagedLineReturnsNullWhenNoneInstalled(): void
    {
        $this->assertNull($this->makeClaudePing()->managedLine());
    }

    public function testPingCommandUsesHomeClaudeBinAndLogOverride(): void
    {
        putenv('CLAUDEPING_LOG=/tmp/claudeping-test.log');
        $home = getenv('HOME');

        $command = $this->makeClaudePing()->pingCommand();

        $this->assertStringContainsString("cd " . escapeshellarg($home), $command);
        $this->assertStringContainsString('>> ' . escapeshellarg('/tmp/claudeping-test.log'), $command);
        $this->assertStringContainsString('--model haiku', $command);
        $this->assertStringContainsString('--safe-mode', $command);
    }
}
