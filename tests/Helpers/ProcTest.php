<?php
namespace JT\Tests\Helpers;

use JT\Helpers\Proc;
use JT\Tests\TestCase;

final class ProcTest extends TestCase {

	private function proc(): Proc {
		return new Proc($this->cli);
	}

	public function test_parseProcTable_indexes_by_pid_with_ppid_and_cmd(): void {
		$raw = "  PID  PPID COMMAND\n"
			 . "  100     1 /bin/zsh -l\n"
			 . "  200   100 claude --session-id abc --model opus\n";

		$out = $this->proc()->parseProcTable($raw);

		$this->assertSame([100, 200], array_keys($out));
		$this->assertSame(100, $out[200]['ppid']);
		$this->assertSame('claude --session-id abc --model opus', $out[200]['cmd']);
	}

	public function test_parseProcTable_skips_rows_that_are_not_pid_ppid_command(): void {
		$raw = "PID PPID COMMAND\nnot a row\n  1\n  300 400 ok\n";

		$this->assertSame([300], array_keys($this->proc()->parseProcTable($raw)));
	}

	public function test_childIndex_groups_pids_under_their_parent(): void {
		$proc = [
			100 => ['ppid' => 1,   'cmd' => 'zsh'],
			200 => ['ppid' => 100, 'cmd' => 'claude'],
			300 => ['ppid' => 100, 'cmd' => 'codex'],
		];

		$this->assertSame([200, 300], $this->proc()->childIndex($proc)[100]);
	}

	public function test_descendantPids_walks_the_whole_tree_including_root(): void {
		$proc = [
			100 => ['ppid' => 1,   'cmd' => 'zsh'],
			200 => ['ppid' => 100, 'cmd' => 'claude'],
			300 => ['ppid' => 200, 'cmd' => 'node mcp'],
			400 => ['ppid' => 1,   'cmd' => 'unrelated'],
		];

		$out = $this->proc()->descendantPids($proc, 100);
		sort($out);

		$this->assertSame([100, 200, 300], $out);
	}

	public function test_descendantPids_survives_a_parent_cycle(): void {
		// A pid table read mid-reparent can describe a cycle; the walk must terminate.
		$proc = [
			100 => ['ppid' => 200, 'cmd' => 'a'],
			200 => ['ppid' => 100, 'cmd' => 'b'],
		];

		$out = $this->proc()->descendantPids($proc, 100);
		sort($out);

		$this->assertSame([100, 200], $out);
	}

	public function test_parseLsofCwd_reads_the_cwd_row_including_spaces_in_the_path(): void {
		$raw = "COMMAND   PID USER   FD   TYPE DEVICE SIZE/OFF NODE NAME\n"
			 . "claude  40645   JT  txt    REG   1,17   123456   99 /usr/local/bin/claude\n"
			 . "claude  40645   JT  cwd    DIR   1,17      640  22 /Users/JT/Sites/my project\n";

		$this->assertSame('/Users/JT/Sites/my project', $this->proc()->parseLsofCwd($raw));
	}

	public function test_parseLsofCwd_returns_null_when_no_cwd_row_is_present(): void {
		$raw = "COMMAND   PID USER   FD   TYPE DEVICE SIZE/OFF NODE NAME\n"
			 . "claude  40645   JT  txt    REG   1,17   123456   99 /usr/local/bin/claude\n";

		$this->assertNull($this->proc()->parseLsofCwd($raw));
	}

	public function test_pidIsAlive_is_true_for_this_process_and_false_for_a_reaped_one(): void {
		$this->assertTrue((bool) $this->proc()->pidIsAlive(getmypid()));
		// pid 1 always exists; a pid above the kernel max never does.
		$this->assertFalse((bool) $this->proc()->pidIsAlive(4194304));
	}

	public function test_pidCommand_returns_this_processes_own_argv(): void {
		$this->assertStringContainsString('php', $this->proc()->pidCommand(getmypid()));
	}
}
