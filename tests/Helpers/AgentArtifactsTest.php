<?php
namespace JT\Tests\Helpers;

use JT\Helpers\AgentArtifacts;
use JT\Helpers\Cmux;
use JT\Helpers\Proc;
use JT\Tests\TestCase;

/**
 * AgentArtifacts is the multiplexer-free half of the old Cmux class: what Claude
 * Code and Codex leave on disk, plus what their argv says. These are the facts a
 * graveyard tombstone is built from, which is why an archive buried out of one
 * transport can be resurrected into another.
 */
final class AgentArtifactsTest extends TestCase {

	private function art(?Proc $proc = null): AgentArtifacts {
		return new AgentArtifacts($this->cli, $proc);
	}

	# --- on-disk paths -------------------------------------------------------

	public function test_encodeProjectKey_slugs_a_cwd_into_claudes_project_key(): void {
		$this->assertSame('-Users-JT--dotfiles', $this->art()->encodeProjectKey('/Users/JT/.dotfiles'));
	}

	public function test_jsonlPathFor_composes_the_projects_transcript_path(): void {
		$this->assertSame(
			'/Users/JT/.claude/projects/-Users-JT--dotfiles/sid-1.jsonl',
			$this->art()->jsonlPathFor('sid-1', '/Users/JT/.dotfiles')
		);
	}

	# --- argv recognition ----------------------------------------------------

	public function test_isClaudeCommand_accepts_the_binary_and_rejects_a_resume_wrapper(): void {
		$art = $this->art();
		$this->assertTrue($art->isClaudeCommand('/usr/local/bin/claude --resume x'));
		$this->assertTrue($art->isClaudeCommand('claude'));
		// A cmux resume script mentions claude in its NAME but is a zsh process.
		$this->assertFalse($art->isClaudeCommand('zsh claude-abc.zsh'));
	}

	public function test_codexSubcommand_and_nonTui_classification(): void {
		$art = $this->art();
		$this->assertSame('exec', $art->codexSubcommand('codex exec --json'));
		$this->assertTrue($art->isCodexNonTuiCommand('codex exec --json'));
		// Bare `codex` is the interactive TUI — the only kind graveyard can bury.
		$this->assertFalse($art->isCodexNonTuiCommand('codex'));
	}

	public function test_cmdModelArg_and_cmdHasSkipPerms_read_launch_flags(): void {
		$art = $this->art();
		$this->assertSame('opus', $art->cmdModelArg('claude --model opus --resume x'));
		$this->assertNull($art->cmdModelArg('claude --resume x'));
		$this->assertTrue($art->cmdHasSkipPerms('claude --dangerously-skip-permissions'));
		$this->assertFalse($art->cmdHasSkipPerms('claude --resume x'));
	}

	# --- resume commands -----------------------------------------------------

	public function test_buildAgentResumeCommand_claude_carries_model_and_skip_perms(): void {
		$cmd = $this->art()->buildAgentResumeCommand('claude', 'abc-123', true, 'opus');

		$this->assertSame('claude --dangerously-skip-permissions --resume abc-123 --model=opus', $cmd);
	}

	public function test_buildAgentResumeCommand_codex_replays_sandbox_and_approval(): void {
		$cmd = $this->art()->buildAgentResumeCommand('codex', 'cdx-1', false, 'gpt-5', [
			'sandbox'  => 'workspace-write',
			'approval' => 'on-request',
		]);

		$this->assertSame(
			'codex resume --model=gpt-5 --sandbox=workspace-write --ask-for-approval=on-request cdx-1',
			$cmd
		);
	}

	public function test_buildAgentResumeCommand_codex_ignores_claudes_skip_perms(): void {
		$cmd = $this->art()->buildAgentResumeCommand('codex', 'cdx-1', true, null);

		$this->assertStringNotContainsString('--dangerously-skip-permissions', $cmd);
	}

	# --- Proc is the injected seam -------------------------------------------

	public function test_argv_resolution_reads_the_live_process_through_the_injected_proc(): void {
		// The seam every test that fakes a process must now target: AgentArtifacts
		// asks Proc, not Cmux. Doubling Cmux::pidCommand reaches only a forwarder.
		$proc = new class($this->cli) extends Proc {
			public function pidCommand(int $pid): string {
				return 'claude --model sonnet --dangerously-skip-permissions';
			}
		};

		$art = $this->art($proc);

		// jsonl said nothing, so both fall back to the live argv.
		$this->assertSame('sonnet', $art->resolveModel(null, 4242));
		$this->assertTrue($art->resolveSkipPerms(null, 4242));
	}

	public function test_jsonl_permission_mode_wins_over_the_launch_flag(): void {
		// The jsonl reflects the mode at the END of the conversation, so it captures
		// a mid-session shift+tab toggle that a launch flag never would.
		$proc = new class($this->cli) extends Proc {
			public function pidCommand(int $pid): string { return 'claude --dangerously-skip-permissions'; }
		};

		$this->assertFalse($this->art($proc)->resolveSkipPerms('default', 4242));
	}

	# --- Cmux still answers for cmux-bak -------------------------------------

	public function test_cmux_forwards_moved_methods_so_cmux_bak_is_unaffected(): void {
		$this->assertSame(
			$this->art()->buildAgentResumeCommand('claude', 'abc-123', true, 'opus'),
			$this->cmux->buildAgentResumeCommand('claude', 'abc-123', true, 'opus')
		);
		$this->assertSame(AgentArtifacts::SESSIONS_DIR, Cmux::SESSIONS_DIR);
		$this->assertSame(AgentArtifacts::CODEX_NON_TUI_SUBCOMMANDS, Cmux::CODEX_NON_TUI_SUBCOMMANDS);
	}
}
