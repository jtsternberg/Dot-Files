<?php
namespace JT\Tests\Helpers;

use JT\Helpers\AgentArtifacts;
use JT\Graveyard;
use JT\Tests\TestCase;
use JT\Transport\CmuxTransport;
use JT\Helpers\Cmux;

final class CodexHomeTest extends TestCase {
	private const SID = '01a1ad83-d628-70a3-8b57-51887188afd8';

	private function rollout(string $home, string $suffix = '', array $extra = []): string {
		$dir = $home . '/sessions/2026/11/05';
		if (!is_dir($dir)) { mkdir($dir, 0777, true); }
		$path = $dir . '/rollout-2026-11-05T16-01-00-' . self::SID . $suffix . '.jsonl';
		file_put_contents($path, json_encode(['type' => 'session_meta', 'payload' => $extra + ['id' => self::SID]]) . "\n");
		return $path;
	}

	public function testLiveRolloutSelectsItsHomeOverAnInheritedHome(): void {
		$art = new AgentArtifacts($this->cli);
		$path = $this->rollout($this->graveyardRoot . '/work home');
		$this->assertTrue($art->registerCodexRollout(self::SID, $path));
		$this->assertSame($path, $art->codexRolloutPathFor(self::SID));
		$this->assertFalse($art->registerCodexRollout('00000000-0000-7000-8000-000000000000', $path));
		$this->assertSame($this->graveyardRoot . '/work home', $art->codexHomeForRollout($path));
	}

	public function testCodexHomeIsUsedUnlessSessionsDirIsExplicitlyOverridden(): void {
		$oldHome = getenv('CODEX_HOME');
		$oldSessions = getenv('CODEX_SESSIONS_DIR');
		try {
			putenv('CODEX_HOME=' . $this->graveyardRoot . '/account');
			putenv('CODEX_SESSIONS_DIR');
			$art = new AgentArtifacts($this->cli);
			$this->assertSame($this->graveyardRoot . '/account/sessions', $art->codexSessionsDir());
			putenv('CODEX_SESSIONS_DIR=' . $this->graveyardRoot . '/override');
			$this->assertSame($this->graveyardRoot . '/override', $art->codexSessionsDir());
		} finally {
			putenv($oldHome === false ? 'CODEX_HOME' : 'CODEX_HOME=' . $oldHome);
			putenv($oldSessions === false ? 'CODEX_SESSIONS_DIR' : 'CODEX_SESSIONS_DIR=' . $oldSessions);
		}
	}

	public function testCmuxDiscoveryCarriesTheOpenRolloutThroughItsJoin(): void {
		$path = $this->rollout($this->graveyardRoot . '/work');
		$cmux = new class($this->cli) extends Cmux {
			public string $path;
			public function psProcTable(): string { return "PID PPID COMMAND\n42 1 codex\n"; }
			public function lsofForPid(int $pid): string { return "codex 42 user 5u REG 1,1 100 1 {$this->path}\n"; }
			public function pidEnv(int $pid): string { return ''; }
		};
		$cmux->path = $path;
		$sessions = $cmux->loadCodexSessionsByPid();
		$this->assertSame($path, $sessions[42]['rollout_path']);
		$this->assertSame($path, $cmux->joinCodexToSurfaces($sessions, [])[0]['rollout_path']);
	}

	public function testPaginatedHistoryUsesTheSegmentsOwnHome(): void {
		$home = $this->graveyardRoot . '/work';
		$base = $this->rollout($home);
		$prefix = file_get_contents($base);
		$seg = $this->rollout($home, '_01a1ad83-d628-70a3-8b57-51887188afd9', [
			'history_mode' => 'paginated',
			'history_base' => ['thread_id' => self::SID, 'end_byte_offset' => strlen($prefix)],
		]);
		$art = new AgentArtifacts($this->cli);
		$this->assertSame($prefix . file_get_contents($seg), $art->codexRolloutHistory($seg));
		unlink($base);
		$this->assertNull($art->codexRolloutHistory($seg));
	}

	public function testDiscoveryFeedsTheSameRolloutToActivityAndBurial(): void {
		$home = $this->graveyardRoot . '/work';
		$path = $this->rollout($home);
		file_put_contents($path, json_encode(['timestamp' => '2026-11-05T16:05:00Z', 'type' => 'event_msg', 'payload' => []]) . "\n", FILE_APPEND);
		$transport = new class($this->cli, $this->cmux) extends CmuxTransport {
			public array $join;
			public function liveSessions(): array {
				return [$this->liveSessionRow($this->join, $this->treeIndex([]), strtotime('2026-11-05T16:06:00Z'))];
			}
		};
		$transport->join = [
			'session_id' => self::SID, 'agent' => 'codex', 'cwd' => '/project', 'model' => null,
			'skip_perms' => false, 'pid' => null, 'tty' => null, 'surface_ref' => 'surface:1',
			'workspace_ref' => 'workspace:1', 'title' => '', 'targetable' => true, 'reason' => null,
			'rollout_path' => $path,
		];
		$gy = new Graveyard($this->cli, $transport);
		$row = $gy->liveSessions()[0];
		$this->assertSame(60, $row['idle_seconds']);
		$this->assertTrue($gy->archiveCodexRollout($row));
		$this->assertSame(file_get_contents($path), file_get_contents($gy->codexRolloutArchivePath(self::SID)));
		$this->assertSame($home, $gy->buildTombstone($row, [], '', '')['codex_home']);
	}

	public function testTombstonePreservesAndRestoresItsHomeWithoutTheBurialOverride(): void {
		$home = $this->graveyardRoot . '/work home';
		$path = $this->rollout($home);
		$art = new AgentArtifacts($this->cli);
		$art->registerCodexRollout(self::SID, $path);
		$gy = new Graveyard($this->cli, $this->transport, $art);
		$tomb = $gy->buildTombstone(['agent' => 'codex', 'session_id' => self::SID], [], '', '2026-11-06T00:00:00Z');
		$this->assertSame($home, $tomb['codex_home'] ?? null);
		$freshGy = new Graveyard($this->cli, $this->transport);
		$this->assertSame($path, $freshGy->tombstoneSessionFile($tomb));
		$storeOnly = new Graveyard($this->cli, $this->transport, new \JT\Helpers\NullAgentArtifacts($this->cli));
		$this->assertNull($storeOnly->tombstoneSessionFile($tomb));
		$this->assertStringStartsWith('CODEX_HOME=' . escapeshellarg($home) . ' codex resume', $freshGy->buildTombstoneLaunch($tomb, false));
		$this->assertStringStartsWith('CODEX_HOME=' . escapeshellarg($home) . ' codex ', $freshGy->buildTombstoneLaunch($tomb, true, 'pickup'));
		unlink($path);
		$this->assertNull($freshGy->tombstoneSessionFile($tomb));
	}

	public function testLegacyTombstoneUsesTheHomeOfItsResolvedNativeFile(): void {
		$home = $this->graveyardRoot . '/work';
		$this->rollout($home);
		$oldSessions = getenv('CODEX_SESSIONS_DIR');
		try {
			putenv('CODEX_SESSIONS_DIR=' . $home . '/sessions');
			$gy = new Graveyard($this->cli, $this->transport);
			$this->assertStringStartsWith('CODEX_HOME=' . escapeshellarg($home) . ' codex resume', $gy->buildTombstoneLaunch(['kind' => 'codex', 'session_id' => self::SID], false));
		} finally {
			putenv($oldSessions === false ? 'CODEX_SESSIONS_DIR' : 'CODEX_SESSIONS_DIR=' . $oldSessions);
		}
	}
}
