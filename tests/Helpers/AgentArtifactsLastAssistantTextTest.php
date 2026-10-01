<?php
namespace JT\Tests\Helpers;

use JT\Helpers\AgentArtifacts;
use JT\Tests\TestCase;

/**
 * The text a bury-candidate verdict reads: the session's last assistant TEXT message,
 * raw (newlines kept — the bench classified it that way), skipping tool-only turns and
 * synthetic resume/slash-command noise. Fixtures are synthetic.
 */
final class AgentArtifactsLastAssistantTextTest extends TestCase {

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

	private function cwd(): string { return sys_get_temp_dir() . '/gy-lat-' . getmypid(); }

	private function write(array $entries): string {
		$sid  = sprintf('lat-%d-%s', getmypid(), bin2hex(random_bytes(4)));
		$path = $this->art()->jsonlPathFor($sid, $this->cwd());
		@mkdir(dirname($path), 0755, true);
		$this->tmpPaths[] = $path;
		file_put_contents($path, implode("\n", array_map('json_encode', $entries)) . "\n");
		return $sid;
	}

	private static function assistant(array $content, string $model = 'claude-opus-5'): array {
		return ['type' => 'assistant', 'message' => ['model' => $model, 'content' => $content]];
	}

	public function test_it_returns_the_last_assistant_text_with_newlines_intact(): void {
		$sid = $this->write([
			self::assistant([['type' => 'text', 'text' => 'Earlier reply.']]),
			['type' => 'user', 'message' => ['content' => 'thanks']],
			self::assistant([['type' => 'text', 'text' => "Shipped.\n\nNext for you: push."]]),
		]);

		$this->assertSame("Shipped.\n\nNext for you: push.", $this->art()->lastAssistantText($sid, $this->cwd()));
	}

	public function test_tool_only_and_synthetic_turns_are_skipped(): void {
		$sid = $this->write([
			self::assistant([['type' => 'text', 'text' => 'Running the suite now.']]),
			self::assistant([['type' => 'tool_use', 'name' => 'Bash', 'input' => []]]),
			['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'ok']]]],
			self::assistant([['type' => 'text', 'text' => 'No response requested.']], '<synthetic>'),
		]);

		$this->assertSame('Running the suite now.', $this->art()->lastAssistantText($sid, $this->cwd()));
	}

	public function test_an_unanswered_user_prompt_last_means_no_text(): void {
		// The turn died (usage limit, API error, Esc) before the agent answered: its
		// older reply describes a conversation that has since moved on.
		foreach ([
			'plain string'  => 'that tab completion is wrong, right?',
			'text block'    => [['type' => 'text', 'text' => 'that tab completion is wrong, right?']],
		] as $label => $content) {
			$sid = $this->write([
				self::assistant([['type' => 'text', 'text' => 'Shipped.']]),
				['type' => 'user', 'message' => ['content' => $content]],
				['type' => 'attachment'],
			]);
			$this->assertNull($this->art()->lastAssistantText($sid, $this->cwd()), $label);
		}
	}

	public function test_meta_and_slash_command_user_turns_last_still_read_the_reply(): void {
		$sid = $this->write([
			self::assistant([['type' => 'text', 'text' => 'Shipped.']]),
			['type' => 'user', 'isMeta' => true, 'message' => ['content' => 'Caveat: local command output follows.']],
			['type' => 'user', 'message' => ['content' => '<command-name>/model</command-name>']],
		]);

		$this->assertSame('Shipped.', $this->art()->lastAssistantText($sid, $this->cwd()));
	}

	public function test_a_session_with_no_transcript_or_no_text_has_none(): void {
		$this->assertNull($this->art()->lastAssistantText('lat-missing-' . getmypid(), $this->cwd()));

		$sid = $this->write([self::assistant([['type' => 'tool_use', 'name' => 'Bash', 'input' => []]])]);
		$this->assertNull($this->art()->lastAssistantText($sid, $this->cwd()));
	}
}
