<?php
namespace JT\Tests\Graveyard;

use JT\Graveyard;
use JT\Tests\TestCase;
use JT\Tests\Transport\FakeTransport;

/**
 * GATE 1 must read deep enough to see the input box's bottom rule.
 *
 * statuslineSessionIds() only trusts ids below that rule (an id typed into the input
 * box proves nothing), and the statusline under it is now several lines tall — model
 * line, context bars, the session id, the permissions footer — plus whatever Claude
 * Code appends ("✔ Update installed", "/rc"). A 6-line tail held only the statusline,
 * so the rule fell off the top, the id read as absent, and gate 1 refused the very
 * session it was looking at.
 */
final class GraveyardGate1WindowTest extends TestCase
{
	private const SID = '98f91798-421b-442a-ae0f-60d69cf05f5a';

	private ?string $oldHome = null;

	protected function tearDown(): void
	{
		if ($this->oldHome !== null) { putenv('HOME=' . $this->oldHome); }
		putenv('GRAVEYARD_EXPORT_BIN');
		parent::tearDown();
	}

	public function testGate1SeesTheSessionIdUnderATallStatusline(): void
	{
		$rule = str_repeat('─', 60);
		$screen = implode("\n", [
			'✻ Worked for 15s · done 4:08 PM',
			'',
			str_repeat('─', 50) . ' ci-nightly ─',
			'❯ ',
			$rule,
			'   Opus 5.5  lindris-next-app  app   main ●   $167.53',
			'  ███▄░░░░░░░░░░░ 24% of 1000k  5h: ██▄│░░░░░░░░░░░ 17%',
			'  ' . self::SID,
			'  ⏵⏵ bypass permissions on (shift+tab to cycle) · ← 1 agent',
			'                                    ✔ Update installed · Restart to update',
			'                                                                       /rc',
			'',
		]);
		[$gy] = $this->buryFixture($screen);

		ob_start();
		$gy->buryOne($this->row(), false, true);
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString('gate 1', $out, 'the id on the statusline must satisfy gate 1');
		$this->assertStringContainsString('gate 2', $out, 'so the bury reaches the post-export gate');
	}

	private function buryFixture(string $screen): array
	{
		// A JSONL whose needle the stub export lacks, so GATE 2 stops the bury before
		// any teardown — reaching it is the receipt that gate 1 passed.
		$this->oldHome = getenv('HOME') ?: '';
		$home = $this->graveyardRoot . '/home';
		@mkdir($home, 0755, true);
		putenv('HOME=' . $home);

		$jsonl = $this->cmux->jsonlPathFor(self::SID, '/Users/JT/Sites/lindris-next-monorepo');
		@mkdir(dirname($jsonl), 0755, true);
		file_put_contents($jsonl, json_encode([
			'type' => 'user', 'uuid' => 'u1', 'sessionId' => self::SID, 'cwd' => '/Users/JT/Sites/lindris-next-monorepo',
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'A NEEDLE THE EXPORT WILL NOT CONTAIN']]],
		]) . "\n");

		$bin = $this->graveyardRoot . '/export-stub';
		file_put_contents($bin, "#!/bin/sh\necho '# unrelated export body'\n");
		chmod($bin, 0755);
		putenv('GRAVEYARD_EXPORT_BIN=' . $bin);

		$fake = new FakeTransport(name: 'cmux', screen: $screen);
		$fake->tailLines = true;

		return [new Graveyard($this->cli, $fake), $fake];
	}

	private function row(): array
	{
		return FakeTransport::row('cmux', self::SID, [
			'cwd' => '/Users/JT/Sites/lindris-next-monorepo', 'idle_seconds' => 600,
			'tab_title' => 'ci-nightly', 'workspace_title' => 'conductor',
		]);
	}
}
