<?php
namespace JT\Helpers;

/**
 * OS-level process, tty and lsof primitives.
 *
 * Deliberately knows nothing about any terminal multiplexer OR any agent: no
 * method here may mention cmux, claude or codex. Both transports need to ask
 * "is this pid alive", "what is its argv", "what does it hold open" — extracted
 * from Cmux so a second transport doesn't inherit a cmux dependency to reach `ps`.
 *
 * Anything that recognises an agent (isClaudeCommand, rollout paths, resume
 * scripts) lives in AgentArtifacts; anything that reads a cmux-specific env var
 * (parseSurfaceIdFromEnv) stays in Cmux.
 *
 * The PURE parse* methods take injected text so they are unit testable; the
 * shelling methods above them do the I/O.
 */
class Proc {

	protected $cli;

	public function __construct($cli) {
		$this->cli = $cli;
	}

	public function pidIsAlive(int $pid) {
		if (function_exists('posix_kill')) {
			return posix_kill($pid, 0);
		}
		exec("kill -0 {$pid} 2>/dev/null", $out, $code);
		return $code === 0;
	}

	public function getTtyForPid(int $pid) {
		$tty = trim((string) shell_exec("ps -p {$pid} -o tty= 2>/dev/null"));
		return ($tty && $tty !== '??') ? $tty : null;
	}

	/** Raw `ps -Ao pid,ppid,command` output. */
	public function psProcTable(): string {
		return (string) shell_exec('ps -Ao pid,ppid,command 2>/dev/null');
	}

	/**
	 * PURE. Parse `ps -Ao pid,ppid,command` into [ pid => ['ppid'=>int,'cmd'=>string] ].
	 */
	public function parseProcTable(string $raw): array {
		$proc = [];
		$lines = preg_split('/\n/', trim($raw)) ?: [];
		foreach ($lines as $i => $line) {
			if ($i === 0 && stripos($line, 'PID') !== false) { continue; } // header
			$p = preg_split('/\s+/', trim($line), 3);
			if (count($p) < 3 || !ctype_digit($p[0]) || !ctype_digit($p[1])) { continue; }
			$proc[(int) $p[0]] = ['ppid' => (int) $p[1], 'cmd' => $p[2]];
		}
		return $proc;
	}

	/** PURE. Children index: [ ppid => [pid,...] ]. */
	public function childIndex(array $proc): array {
		$kids = [];
		foreach ($proc as $pid => $info) { $kids[$info['ppid']][] = $pid; }
		return $kids;
	}

	/** PURE. All descendant pids of $root (inclusive) — used to kill an agent + its subagents. */
	public function descendantPids(array $proc, int $root): array {
		$kids = $this->childIndex($proc);
		$acc = [$root]; $stack = [$root]; $seen = [$root => true];
		while ($stack) {
			$cur = array_pop($stack);
			foreach ($kids[$cur] ?? [] as $c) {
				if (isset($seen[$c])) { continue; }
				$seen[$c] = true; $acc[] = $c; $stack[] = $c;
			}
		}
		return $acc;
	}

	public function pidCommand(int $pid): string {
		return trim((string) shell_exec('ps -p ' . $pid . ' -o command= 2>/dev/null'));
	}

	/**
	 * Raw `ps -wwEp <pid>`. NOTE: -E appends the environment to the command
	 * column on the same line, so this output carries every env var of the
	 * process — including CMUX_SOCKET_CAPABILITY, a live auth token. Feed it
	 * straight to a parser and never log or persist it.
	 */
	public function pidEnv(int $pid): string {
		return (string) shell_exec('ps -wwEp ' . (int) $pid . ' 2>/dev/null');
	}

	public function lsofForPid(int $pid): string {
		return (string) shell_exec('lsof -p ' . (int) $pid . ' 2>/dev/null');
	}

	/** PURE. The cwd an lsof dump reports for its process, or null. */
	public function parseLsofCwd(string $raw): ?string {
		// NAME is the last column and may contain spaces, so it's "rest of line".
		return preg_match('/^\S+\s+\d+\s+\S+\s+cwd\s+\S+\s+\S+\s+\S+\s+\S+\s+(.+)$/m', $raw, $m)
			? rtrim($m[1])
			: null;
	}

	public function getCwdForTty(string $tty) {
		$psOut = shell_exec("ps -t {$tty} -o pid=,stat= 2>/dev/null");
		$pid   = null;

		// Prefer foreground process (stat contains +)
		foreach (explode("\n", trim((string) $psOut)) as $line) {
			$parts = preg_split('/\s+/', trim($line));
			if (count($parts) >= 2 && strpos($parts[1], '+') !== false) {
				$pid = $parts[0];
				break;
			}
		}

		if (!$pid) {
			$lines = array_filter(explode("\n", trim((string) $psOut)));
			if ($lines) {
				$pid = preg_split('/\s+/', trim(reset($lines)))[0];
			}
		}

		if (!$pid) {
			return null;
		}

		$lsofOut = shell_exec("lsof -p {$pid} 2>/dev/null");
		foreach (explode("\n", (string) $lsofOut) as $line) {
			// Fields: COMMAND PID USER FD TYPE DEVICE SIZE/OFF NODE NAME
			$parts = preg_split('/\s+/', $line, 9);
			if (isset($parts[3]) && $parts[3] === 'cwd') {
				return $parts[8] ?? null;
			}
		}

		return null;
	}
}
