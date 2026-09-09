<?php
namespace JT\Tests\Helpers;

use JT\Helpers\AgentArtifacts;
use JT\Tests\TestCase;

/**
 * Finding a claude session's transcript when its recorded cwd is wrong.
 *
 * Claude Code files a transcript under the project key of the cwd it FIRST ran in and
 * keeps writing there afterwards, while every multiplexer reports where the process is
 * now — herdr from the pane, cmux from ~/.claude/sessions/<pid>.json, which records the
 * resumed process's own cwd. Resume a session somewhere else and the two disagree
 * permanently, so composing the path from the reported cwd misses (dotfiles-hvf):
 * lastRealActivity() then returns null, idle_seconds becomes PHP_INT_MAX, and the
 * session reads as infinitely idle in `candidates`.
 *
 * The session id is globally unique and already in the filename, so it can be found
 * without the cwd at all. The composed path stays the fast path — one file_exists
 * against a directory scan of ~107 project dirs — and the scan is only paid on a miss.
 */
final class AgentArtifactsTranscriptResolutionTest extends TestCase {

	/** Real files under ~/.claude/projects, removed in tearDown. */
	private array $tmpPaths = [];

	protected function tearDown(): void {
		foreach ($this->tmpPaths as $p) {
			@unlink($p);
			@rmdir(dirname($p));
		}
		$this->tmpPaths = [];
		parent::tearDown();
	}

	private function art(): AgentArtifacts { return new AgentArtifacts($this->cli); }

	/** A transcript filed under $cwd's project key, holding one genuine turn at $ts. */
	private function writeTranscript(string $sid, string $cwd, string $ts): string {
		$path = $this->art()->jsonlPathFor($sid, $cwd);
		@mkdir(dirname($path), 0755, true);
		$this->tmpPaths[] = $path;
		file_put_contents($path, implode("\n", [
			json_encode(['type' => 'permission-mode', 'permissionMode' => 'bypassPermissions']),
			json_encode(['type' => 'assistant', 'message' => ['model' => 'claude-opus-5'], 'timestamp' => $ts]),
		]) . "\n");
		return $path;
	}

	/** A session id no other test or real session can collide with. */
	private function sid(string $tag): string {
		return sprintf('hvf-%s-%d-%s', $tag, getmypid(), bin2hex(random_bytes(4)));
	}

	private function launchCwd(string $tag): string {
		return sys_get_temp_dir() . '/gy-hvf-launch-' . $tag . '-' . getmypid();
	}

	# --- the resolver --------------------------------------------------------

	/**
	 * The composed path wins when it exists, and the scan is never consulted. Proven by
	 * planting a SECOND transcript for the same id elsewhere: if the glob ran first, or
	 * ran at all here, the wrong file could win.
	 */
	public function test_the_composed_path_is_the_fast_path_and_wins_outright(): void {
		$sid      = $this->sid('fast');
		$reported = $this->launchCwd('fast-reported');
		$elsewhere = $this->launchCwd('fast-elsewhere');

		$wanted = $this->writeTranscript($sid, $reported, '2026-07-01T10:00:00Z');
		$this->writeTranscript($sid, $elsewhere, '2026-07-02T10:00:00Z'); // newer, must lose

		$this->assertSame($wanted, $this->art()->resolveJsonlPath($sid, $reported));
	}

	/** THE hvf case: the reported cwd is wrong, and the transcript is still found. */
	public function test_a_wrong_cwd_falls_back_to_finding_the_transcript_by_session_id(): void {
		$sid    = $this->sid('wrongcwd');
		$launch = $this->launchCwd('wrongcwd');
		$wanted = $this->writeTranscript($sid, $launch, '2026-07-01T10:00:00Z');

		$this->assertSame($wanted, $this->art()->resolveJsonlPath($sid, '/Users/JT/somewhere/else'));
	}

	/** And with no cwd reported at all — there is nothing to compose from. */
	public function test_an_unknown_cwd_resolves_by_session_id_alone(): void {
		$sid    = $this->sid('nocwd');
		$wanted = $this->writeTranscript($sid, $this->launchCwd('nocwd'), '2026-07-01T10:00:00Z');

		$this->assertSame($wanted, $this->art()->resolveJsonlPath($sid, ''));
		$this->assertSame($wanted, $this->art()->resolveJsonlPath($sid, null));
	}

	public function test_a_session_with_no_transcript_anywhere_resolves_to_null(): void {
		$this->assertNull($this->art()->resolveJsonlPath($this->sid('absent'), '/no/such/cwd'));
		$this->assertNull($this->art()->resolveJsonlPath('', '/no/such/cwd'));
	}

	/**
	 * Two project dirs holding the same id: the most recently written one is the live
	 * conversation, and idle time must be measured against that rather than a stale copy.
	 */
	public function test_the_most_recently_written_transcript_wins_a_tie(): void {
		$sid = $this->sid('tie');
		$old = $this->writeTranscript($sid, $this->launchCwd('tie-old'), '2026-07-01T10:00:00Z');
		$new = $this->writeTranscript($sid, $this->launchCwd('tie-new'), '2026-07-02T10:00:00Z');
		touch($old, time() - 3600);
		touch($new, time());

		$this->assertSame($new, $this->art()->resolveJsonlPath($sid, '/no/such/cwd'));
	}

	/**
	 * A session id is interpolated into a glob pattern, so one carrying pattern
	 * metacharacters could match a DIFFERENT session's transcript — and idle time, model
	 * and permission mode would then be read off somebody else's conversation. Refused.
	 */
	public function test_a_session_id_carrying_glob_metacharacters_is_refused(): void {
		$sid = $this->sid('meta');
		$this->writeTranscript($sid, $this->launchCwd('meta'), '2026-07-01T10:00:00Z');

		$this->assertNull($this->art()->resolveJsonlPath('hvf-meta-*', '/no/such/cwd'));
		$this->assertNull($this->art()->resolveJsonlPath('../../etc/passwd', '/no/such/cwd'));
	}

	/** Memoised: the scan is paid once per (session, cwd), not once per reader. */
	public function test_the_resolution_is_memoised(): void {
		$sid    = $this->sid('memo');
		$wanted = $this->writeTranscript($sid, $this->launchCwd('memo'), '2026-07-01T10:00:00Z');
		$art    = $this->art();

		$this->assertSame($wanted, $art->resolveJsonlPath($sid, '/no/such/cwd'));
		unlink($wanted);
		$this->assertSame($wanted, $art->resolveJsonlPath($sid, '/no/such/cwd'), 'answered from the memo');
		$this->assertNull($this->art()->resolveJsonlPath($sid, '/no/such/cwd'), 'a fresh reader rescans');
	}

	# --- the readers that benefit -------------------------------------------

	/**
	 * The hvf symptom itself: a measurable idle time instead of PHP_INT_MAX. Both
	 * transports feed this method their reported cwd, so both are fixed by it.
	 */
	public function test_lastRealActivity_measures_a_session_whose_reported_cwd_is_wrong(): void {
		$sid = $this->sid('idle');
		$ts  = '2026-07-01T10:05:00Z';
		$this->writeTranscript($sid, $this->launchCwd('idle'), $ts);

		$this->assertSame(strtotime($ts), $this->art()->lastRealActivity($sid, '/Users/JT/somewhere/else'));
	}

	/** So a resurrected session gets its real model and permission mode back. */
	public function test_readSessionJsonl_reads_a_session_whose_reported_cwd_is_wrong(): void {
		$sid = $this->sid('meta-read');
		$this->writeTranscript($sid, $this->launchCwd('meta-read'), '2026-07-01T10:05:00Z');

		$meta = $this->art()->readSessionJsonl($sid, '/Users/JT/somewhere/else');
		$this->assertSame('claude-opus-5', $meta['model']);
		$this->assertSame('bypassPermissions', $meta['permission_mode']);

		// And with no cwd at all, which used to short-circuit to nulls.
		$this->assertSame('claude-opus-5', $this->art()->readSessionJsonl($sid, null)['model']);
	}

	/** "Is this still resumable?" is decided on this, for both agents. */
	public function test_transcriptPathFor_finds_a_claude_transcript_despite_a_wrong_cwd(): void {
		$sid    = $this->sid('resumable');
		$wanted = $this->writeTranscript($sid, $this->launchCwd('resumable'), '2026-07-01T10:05:00Z');

		$this->assertSame($wanted, $this->art()->transcriptPathFor('claude', $sid, '/Users/JT/somewhere/else'));
		$this->assertNull($this->art()->transcriptPathFor('claude', $this->sid('gone'), ''));
		// codex is unaffected: it was always globbed by id, never composed from a cwd.
		$this->assertNull($this->art()->transcriptPathFor('codex', $this->sid('gone'), '/any/cwd'));
	}
}
