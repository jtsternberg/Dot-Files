<?php
namespace JT\Tests\Helpers;

use JT\Helpers\AgentArtifacts;
use JT\Tests\TestCase;

/**
 * Codex 0.157+ resumes a session into a NEW segment file named
 * `rollout-<ts>-<sessionId>_<segmentId>.jsonl` rather than appending. The segment's
 * session_meta says `history_mode: paginated` and names how much of the previous
 * segment is real history (`history_base.end_byte_offset`) — the base can run longer
 * than that offset, because turns rewound before the resume stay in the file.
 *
 * Measured on a live session: the base held ordinals 0-389, the segment started at
 * ordinal 367 with end_byte_offset 2111133, and the TUI held ONLY the segment open.
 * So the segment alone is not the conversation, and the base whole is not either.
 */
final class CodexPaginatedRolloutTest extends TestCase
{
	private const SID = '01a0e096-f72f-7fc0-9240-9e11f19bc002';
	private const SEG = '01a0e0c5-108f-7111-9623-35d903f54ceb';
	private const SEG2 = '01a0e0d1-0000-7000-8000-000000000001';

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$root      = $this->graveyardRoot . '/codex-sessions';
		$this->dir = $root . '/2026/09/26';
		mkdir($this->dir, 0777, true);
		putenv('CODEX_SESSIONS_DIR=' . $root);
	}

	protected function tearDown(): void
	{
		putenv('CODEX_SESSIONS_DIR');
		parent::tearDown();
	}

	private function art(): AgentArtifacts
	{
		return new AgentArtifacts($this->cli);
	}

	private function rec(string $type, array $payload): string
	{
		return json_encode(['type' => $type, 'payload' => $payload]) . "\n";
	}

	private function meta(array $extra = []): string
	{
		return $this->rec('session_meta', array_merge(['id' => self::SID, 'session_id' => self::SID, 'thread_source' => 'user'], $extra));
	}

	/** Base segment: kept history, then rewound turns that are NOT history. Returns [path, keptBytes]. */
	private function base(): array
	{
		$kept  = $this->meta() . $this->rec('response_item', ['n' => 'kept-1']) . $this->rec('response_item', ['n' => 'kept-2']);
		$path  = "{$this->dir}/rollout-2026-09-26T21-59-45-" . self::SID . '.jsonl';
		file_put_contents($path, $kept . $this->rec('response_item', ['n' => 'rewound']));
		return [$path, $kept];
	}

	private function segment(string $seg, string $ts, int $offset, string $body): string
	{
		$path = "{$this->dir}/rollout-2026-09-26T{$ts}-" . self::SID . "_{$seg}.jsonl";
		file_put_contents($path, $this->meta([
			'history_mode' => 'paginated',
			'history_base' => ['thread_id' => self::SID, 'end_ordinal_exclusive' => 3, 'end_byte_offset' => $offset],
		]) . $body);
		return $path;
	}

	public function testRolloutUuidFromPathTakesTheSessionIdOffASegmentName(): void
	{
		$this->assertSame(self::SID, $this->art()->rolloutUuidFromPath(
			"/x/rollout-2026-09-26T22-50-06-" . self::SID . '_' . self::SEG . '.jsonl'
		));
	}

	public function testLsofSeesASegmentAsItsSession(): void
	{
		$seg = "/Users/JT/.codex/sessions/2026/09/26/rollout-2026-09-26T22-50-06-" . self::SID . '_' . self::SEG . '.jsonl';
		$raw = "codex   6346   JT   43u   REG   1,16   326208 292657212 {$seg}\n";

		$this->assertSame([$seg], $this->art()->parseLsofRolloutPaths($raw));
		$this->assertSame(self::SID, $this->art()->ownSessionIdFromLsof($raw));
	}

	public function testRolloutPathForIsTheNewestSegment(): void
	{
		[$base, $kept] = $this->base();
		$seg = $this->segment(self::SEG, '22-50-06', strlen($kept), $this->rec('response_item', ['n' => 'seg']));

		$this->assertSame($seg, $this->art()->codexRolloutPathFor(self::SID));
	}

	public function testHistoryJoinsTheKeptBasePrefixToTheSegment(): void
	{
		[, $kept] = $this->base();
		$body = $this->rec('response_item', ['n' => 'seg']);
		$seg  = $this->segment(self::SEG, '22-50-06', strlen($kept), $body);

		$segText = file_get_contents($seg);
		$this->assertSame($kept . $segText, $this->art()->codexRolloutHistory($seg));
	}

	public function testHistoryFollowsAChainOfSegments(): void
	{
		[, $kept] = $this->base();
		$seg1     = $this->segment(self::SEG, '22-50-06', strlen($kept), $this->rec('response_item', ['n' => 'seg1']));
		$seg1Text = file_get_contents($seg1);
		$seg2     = $this->segment(self::SEG2, '23-10-00', strlen($seg1Text), $this->rec('response_item', ['n' => 'seg2']));

		$this->assertSame($kept . $seg1Text . file_get_contents($seg2), $this->art()->codexRolloutHistory($seg2));
	}

	public function testHistoryOfAnUnpaginatedRolloutIsTheFileItself(): void
	{
		[$base] = $this->base();
		$this->assertSame(file_get_contents($base), $this->art()->codexRolloutHistory($base));
	}

	public function testHistoryIsNullWhenTheBaseIsMissingOrTooShort(): void
	{
		// An archive missing its first part must fail, not pass as complete: bury tears
		// the live session down once the archive exists.
		$seg = $this->segment(self::SEG, '22-50-06', 10, $this->rec('response_item', ['n' => 'seg']));
		$this->assertNull($this->art()->codexRolloutHistory($seg));

		[, $kept] = $this->base();
		$seg = $this->segment(self::SEG, '22-50-06', strlen($kept) + 999999, $this->rec('response_item', ['n' => 'seg']));
		$this->assertNull($this->art()->codexRolloutHistory($seg));
	}
}
