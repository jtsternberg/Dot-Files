<?php
namespace JT\Tests\ClaudePing;

use JT\ClaudePing;
use JT\Tests\TestCase;

/**
 * describeCronExpression() wraps lorisleiva/cron-translator — not another LLM
 * call — so a schedule can always be checked against the raw cron expression
 * without trusting a model's own translation of it.
 */
final class ClaudePingDescribeCronTest extends TestCase
{
    private function makeClaudePing(): ClaudePing
    {
        return new ClaudePing($this->cli);
    }

    public function testWeekdaysAtExactTime(): void
    {
        $this->assertSame(
            'Every day 5 days a week at 5:30am',
            $this->makeClaudePing()->describeCronExpression('30 5 * * 1-5')
        );
    }

    public function testEveryDay(): void
    {
        $this->assertSame('Every day at 6:00am', $this->makeClaudePing()->describeCronExpression('0 6 * * *'));
    }

    public function testWeekends(): void
    {
        $this->assertSame(
            'Every Sunday and Saturday at 6:00am',
            $this->makeClaudePing()->describeCronExpression('0 6 * * 0,6')
        );
    }

    public function testArbitraryDayList(): void
    {
        $this->assertSame(
            'Every Monday, Wednesday and Friday at 9:15am',
            $this->makeClaudePing()->describeCronExpression('15 9 * * 1,3,5')
        );
    }

    public function testEveryNMinutes(): void
    {
        $this->assertSame('Every 15 minutes', $this->makeClaudePing()->describeCronExpression('*/15 * * * *'));
    }

    public function testEveryNHours(): void
    {
        $this->assertSame('Once every 2 hours', $this->makeClaudePing()->describeCronExpression('0 */2 * * *'));
    }

    public function testSpecificDayOfMonth(): void
    {
        $this->assertSame(
            'The 1st of every month at 9:00am',
            $this->makeClaudePing()->describeCronExpression('0 9 1 * *')
        );
    }

    public function testSpecificMonthAndDay(): void
    {
        $this->assertSame(
            'Every year on December the 25th at 9:00am',
            $this->makeClaudePing()->describeCronExpression('0 9 25 12 *')
        );
    }

    public function testMalformedExpressionFallsBackToItself(): void
    {
        $this->assertSame('not a cron expression', $this->makeClaudePing()->describeCronExpression('not a cron expression'));
    }
}
