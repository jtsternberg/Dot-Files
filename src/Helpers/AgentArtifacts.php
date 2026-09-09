<?php
namespace JT\Helpers;

# =============================================================================
# AgentArtifacts — what Claude Code and Codex leave on disk, and what their argv
# says. Session JSONL paths, codex rollouts, transcript resolution, resume-command
# construction, and the argv sniffing that recovers a session's model and
# permission mode.
#
# Knows nothing about any terminal multiplexer. This is the half of the old Cmux
# class that made a graveyard tombstone portable in the first place: a session
# buried out of cmux resurrects into herdr because none of the archive's facts
# came from cmux. Extracted from Cmux so both transports read them the same way.
# =============================================================================

class AgentArtifacts {

	use TitleGlyphTrait;
	use ReverseLinesTrait;

	const SESSIONS_DIR = '~/.claude/sessions';

	/** Codex rollout transcripts live under <root>/YYYY/MM/DD/rollout-<ts>-<uuid>.jsonl. */
	const CODEX_SESSIONS_DIR = '~/.codex/sessions';

	/**
	 * codex subcommands that never own an interactive surface: the VS Code
	 * extension's `app-server`, headless `exec`, and the various one-shot
	 * utilities. Only an interactive TUI is a session that can be backed up
	 * and resumed.
	 */
	const CODEX_NON_TUI_SUBCOMMANDS = [
		'app-server', 'exec', 'mcp', 'mcp-server', 'login', 'logout',
		'completion', 'apply', 'sandbox', 'debug', 'generate-ts',
	];

	protected $cli;
	protected Proc $proc;

	/** Memoised resolveJsonlPath() answers, keyed "<session id>\0<reported cwd>". */
	private array $jsonlPathCache = [];

	public function __construct($cli, ?Proc $proc = null) {
		$this->cli  = $cli;
		$this->proc = $proc ?: new Proc($cli);
	}

	/**
	 * The Proc these artifact reads go through. Exposed so a holder that also needs
	 * raw process primitives shares this instance rather than newing a second one —
	 * a test that injects a stubbed Proc here then has it honoured everywhere,
	 * instead of half the pid lookups quietly reaching the real `ps`.
	 */
	public function proc(): Proc { return $this->proc; }

	public function encodeProjectKey(string $cwd): string {
		return preg_replace('/[^a-zA-Z0-9]/', '-', $cwd);
	}

	/** Absolute path of the Claude per-pid session root (CLAUDE_SESSIONS_DIR overrides, for tests). */
	public function claudeSessionsDir(): string {
		$override = getenv('CLAUDE_SESSIONS_DIR');
		return $this->cli->convertPathToAbsolute($override !== false && $override !== '' ? $override : self::SESSIONS_DIR);
	}

	public function jsonlPathFor(string $sessionId, string $cwd): string {
		$claudeDir = $this->cli->convertPathToAbsolute('~/.claude');
		return "{$claudeDir}/projects/{$this->encodeProjectKey($cwd)}/{$sessionId}.jsonl";
	}

	/**
	 * Where a claude session's transcript ACTUALLY is, given a cwd that may be wrong.
	 *
	 * Claude Code files a transcript under the project key of the cwd it first ran in and
	 * keeps writing there, while a multiplexer reports where the process is NOW — herdr
	 * from the pane, cmux from ~/.claude/sessions/<pid>.json, which records the resumed
	 * process's own cwd. Resume a session in another directory and the two disagree for
	 * good, so composing the path from the reported cwd misses; lastRealActivity() then
	 * returns null and the session reads as infinitely idle (dotfiles-hvf).
	 *
	 * The session id is globally unique and already in the filename, so the transcript is
	 * findable without the cwd at all. Composition stays FIRST — one file_exists, against
	 * a scan of every project directory — so the scan is only paid on a miss, and a
	 * session whose cwd is right resolves exactly as it always did.
	 *
	 * Memoised per (session, cwd): a single `candidates` render asks several readers about
	 * the same row, and each miss would otherwise rescan.
	 */
	public function resolveJsonlPath(string $sessionId, ?string $cwd): ?string {
		if ($sessionId === '') { return null; }

		$key = $sessionId . "\0" . (string) $cwd;
		if (array_key_exists($key, $this->jsonlPathCache)) { return $this->jsonlPathCache[$key]; }

		$composed = (string) $cwd !== '' ? $this->jsonlPathFor($sessionId, (string) $cwd) : '';
		if ($composed !== '' && is_file($composed)) { return $this->jsonlPathCache[$key] = $composed; }

		return $this->jsonlPathCache[$key] = $this->findJsonlBySessionId($sessionId);
	}

	/**
	 * The transcript for a session id, wherever it was filed. Null when there is none.
	 *
	 * The id is interpolated into a glob pattern, so one carrying pattern metacharacters
	 * could match a DIFFERENT session's file — and idle time, model and permission mode
	 * would then be read off somebody else's conversation. Such an id is refused rather
	 * than escaped: no real session id contains one.
	 *
	 * More than one project directory can hold the same id (a session resumed elsewhere
	 * before this resolver existed, then written to again). The most recently written file
	 * is the live conversation, so idle time is measured against that and not a stale copy.
	 */
	protected function findJsonlBySessionId(string $sessionId): ?string {
		if ($sessionId === '' || strpbrk($sessionId, "*?[]\\/\0") !== false) { return null; }

		$projects = $this->cli->convertPathToAbsolute('~/.claude') . '/projects';
		$hits     = glob("{$projects}/*/{$sessionId}.jsonl") ?: [];
		if (!$hits) { return null; }
		if (count($hits) > 1) {
			usort($hits, fn($a, $b) => (int) @filemtime($b) <=> (int) @filemtime($a));
		}
		return $hits[0];
	}

	/**
	 * Load all active Claude sessions from ~/.claude/sessions/<pid>.json.
	 * Returns array keyed by tty: [ tty => [session_id, cwd, status, pid, skip_perms, model] ]
	 */
	public function loadClaudeSessions(): array {
		$sessionsDir = $this->cli->convertPathToAbsolute(self::SESSIONS_DIR);
		$sessions    = [];

		if (!is_dir($sessionsDir)) {
			return $sessions;
		}

		foreach (glob($sessionsDir . '/*.json') ?: [] as $file) {
			$pid = pathinfo($file, PATHINFO_FILENAME);
			if (!ctype_digit($pid)) {
				continue;
			}
			if (!$this->proc->pidIsAlive((int) $pid)) {
				continue;
			}
			$raw = @file_get_contents($file);
			if ($raw === false) {
				continue;
			}
			$data = json_decode($raw, true);
			if (!$data) {
				continue;
			}
			$tty = $this->proc->getTtyForPid((int) $pid);
			if ($tty) {
				$sessionId = $data['sessionId'] ?? null;
				$cwd       = $data['cwd'] ?? null;
				$jsonl     = $this->readSessionJsonl($sessionId, $cwd);

				$sessions[$tty] = [
					'session_id'   => $sessionId,
					'cwd'          => $cwd,
					'status'       => $data['status'] ?? null,
					'pid'          => (int) $pid,
					'skip_perms'   => $this->resolveSkipPerms($jsonl['permission_mode'], (int) $pid),
					'model'        => $this->resolveModel($jsonl['model'], (int) $pid),
				];
			}
		}

		return $sessions;
	}

	/** Absolute path of the codex sessions root (CODEX_SESSIONS_DIR overrides, for tests). */
	public function codexSessionsDir(): string {
		$override = getenv('CODEX_SESSIONS_DIR');
		return $this->cli->convertPathToAbsolute($override !== false && $override !== '' ? $override : self::CODEX_SESSIONS_DIR);
	}

	/**
	 * Path of the rollout for a codex session id, or null if it's gone. Globbed
	 * rather than reconstructed: the YYYY/MM/DD directories aren't derivable from
	 * a bare uuid, and this is what audit's "resumable" check rests on.
	 */
	public function codexRolloutPathFor(string $sessionId): ?string {
		if (!preg_match('/^[0-9a-fA-F-]{36}$/', $sessionId)) { return null; }
		$hits = glob($this->codexSessionsDir() . "/*/*/*/rollout-*-{$sessionId}.jsonl") ?: [];
		return $hits ? $hits[0] : null;
	}

	/**
	 * The model / sandbox / approval / reasoning-effort a codex session was last
	 * running under, from the LAST turn_context record in its rollout — the state
	 * at the END of the conversation, so a mid-session change wins. Same principle
	 * as resolveModel()/resolveSkipPerms() treating Claude's jsonl as truth.
	 *
	 * This exists because `codex resume` does NOT rehydrate them. Measured in the
	 * real interactive TUI, not just under `codex exec`: a session created with
	 * `-s read-only` and resumed bare reports `Permissions: Full Access` in
	 * /status; resumed with these values replayed it reports
	 * `Permissions: Read Only (never)`. Restoring bare silently widens the sandbox.
	 * (Test on a FRESH session — one that was previously resumed WITH explicit
	 * flags reports Read Only on a later bare resume, which looks like rehydration
	 * and isn't.)
	 *
	 * Scanned backward — rollouts run to megabytes, so the head is never read.
	 *
	 * `has_turn_context` is the honest half of the answer, and callers need it: a
	 * null sandbox cannot distinguish "recorded as unset" from "never recorded",
	 * and ~45% of real rollouts are the second — every Codex Desktop
	 * (source=vscode) session, permanently. That is not a legacy-version quirk
	 * waiting to age out, so a false here means the session's real sandbox/approval
	 * are UNKNOWABLE and a resume will silently take config.toml's (dotfiles-f1n).
	 */
	public function codexRolloutContext(string $rolloutPath): array {
		$ctx = ['model' => null, 'sandbox' => null, 'approval' => null, 'effort' => null, 'has_turn_context' => false];

		$this->eachLineReverse($rolloutPath, function (string $line) use (&$ctx) {
			$rec = json_decode(trim($line), true);
			if (!is_array($rec) || ($rec['type'] ?? '') !== 'turn_context') {
				return true;
			}
			$pl  = $rec['payload'] ?? [];
			$ctx = [
				'model'    => $pl['model'] ?? ($pl['settings']['model'] ?? null),
				'sandbox'  => $pl['sandbox_policy']['type'] ?? null,
				'approval' => $pl['approval_policy'] ?? null,
				'effort'   => $pl['reasoning_effort'] ?? ($pl['settings']['reasoning_effort'] ?? null),
				'has_turn_context' => true,
			];
			return false; // last one wins; stop at the first hit scanning backward
		});

		return $ctx;
	}

	/**
	 * The cwd recorded in a rollout's `session_meta` header — where the
	 * conversation actually ran, which is what restore should cd into even if the
	 * live process has since been cd'd elsewhere.
	 */
	public function codexSessionCwd(string $rolloutPath): ?string {
		$h = @fopen($rolloutPath, 'rb');
		if (!$h) { return null; }
		$cwd = null;
		// The header is the first record, but scan a few lines in case of a
		// leading blank or a writer that emits a preamble.
		for ($i = 0; $i < 5 && ($line = fgets($h)) !== false; $i++) {
			$rec = json_decode(trim($line), true);
			if (is_array($rec) && ($rec['type'] ?? '') === 'session_meta') {
				$cwd = $rec['payload']['cwd'] ?? null;
				break;
			}
		}
		fclose($h);
		return $cwd !== null && $cwd !== '' ? $cwd : null;
	}

	/** PURE. The session uuid embedded in a rollout filename, or null. */
	public function rolloutUuidFromPath(string $path): ?string {
		return preg_match('/rollout-\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}-([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\.jsonl$/', $path, $m)
			? $m[1]
			: null;
	}

	/**
	 * Read the last permission-mode and model from a session's JSONL transcript.
	 * These reflect the state at the END of the conversation (either can change
	 * mid-run), so we scan backward and take the first of each we encounter,
	 * stopping as soon as both are known instead of decoding the whole file.
	 */
	public function readSessionJsonl(?string $sessionId, ?string $cwd): array {
		$result = ['permission_mode' => null, 'model' => null];

		if (!$sessionId) {
			return $result;
		}

		// A missing cwd is no longer a dead end: the transcript is findable by id alone.
		$jsonlPath = $this->resolveJsonlPath($sessionId, $cwd);

		if ($jsonlPath === null) {
			return $result;
		}

		$this->eachLineReverse($jsonlPath, function (string $line) use (&$result) {
			$entry = json_decode($line, true);
			if (!$entry || !isset($entry['type'])) {
				return true;
			}
			if ($result['permission_mode'] === null
				&& $entry['type'] === 'permission-mode' && isset($entry['permissionMode'])
			) {
				$result['permission_mode'] = $entry['permissionMode'];
			}
			if ($result['model'] === null
				&& $entry['type'] === 'assistant'
				&& isset($entry['message']['model'])
				&& $entry['message']['model'] !== '<synthetic>'
			) {
				$result['model'] = $entry['message']['model'];
			}
			// Keep scanning backward until BOTH are known.
			return $result['permission_mode'] === null || $result['model'] === null;
		});

		return $result;
	}

	/**
	 * Whether a JSONL entry is SYNTHETIC / non-activity (not a real user/assistant
	 * conversation turn). Such entries must not count toward last-activity/idle.
	 * Three classes are detected, each per-entry (never by timestamp pairing):
	 *
	 *   1. cmux-bak mass-restore resume turns — a user "Continue from where you left
	 *      off." (isMeta) turn and an assistant turn whose model is '<synthetic>'
	 *      / text "No response requested.".
	 *   2. Slash-command turns — /export, /resume, etc. write command invocation,
	 *      stdout, and caveat turns (text begins with <command-name>, <command-args>,
	 *      <command-message>, <local-command-stdout>, <local-command-caveat>). These
	 *      freshen the JSONL without representing real work.
	 *
	 * @param array $entry Decoded JSONL entry.
	 * @return bool True if the entry is synthetic / non-activity.
	 */
	public function isSyntheticEntry(array $entry): bool {
		// Assistant marker: synthetic model.
		if (
			isset($entry['type'], $entry['message']['model'])
			&& $entry['type'] === 'assistant'
			&& $entry['message']['model'] === '<synthetic>'
		) {
			return true;
		}

		// Concatenate text content (array-of-parts or plain string).
		$content = $entry['message']['content'] ?? null;
		$text = '';
		if (is_string($content)) {
			$text = $content;
		} elseif (is_array($content)) {
			foreach ($content as $part) {
				if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
					$text .= $part['text'];
				}
			}
		}
		$text = trim($text);

		// Slash-command turns (invocation / stdout / caveat). Not gated on isMeta —
		// the invocation and stdout turns carry no isMeta flag.
		$commandPrefixes = ['<command-name>', '<command-args>', '<command-message>', '<local-command-stdout>', '<local-command-caveat>'];
		foreach ($commandPrefixes as $prefix) {
			if (strncmp($text, $prefix, strlen($prefix)) === 0) {
				return true;
			}
		}

		// Text-content resume marker. Gated on isMeta so a genuine human turn that
		// happens to type the same words isn't misclassified (the real synthetic user
		// resume turn always carries isMeta:true; the synthetic assistant turn is
		// already caught by the '<synthetic>' model check above).
		if (empty($entry['isMeta'])) {
			return false;
		}

		$markers = ['Continue from where you left off.', 'No response requested.'];
		return in_array($text, $markers, true);
	}

	/**
	 * Unix timestamp of the last JSONL entry whose type is 'user' or 'assistant'
	 * and that has a 'timestamp'. Null if the file is missing or has no such entry.
	 * JSONL mtime is unreliable (freshened by cron/housekeeping); this reflects the
	 * actual last real conversation turn. Synthetic resume turns (see
	 * isSyntheticEntry) are skipped so restored sessions don't look freshly active.
	 */
	public function lastRealActivity(string $sessionId, string $cwd): ?int {
		$jsonlPath = $this->resolveJsonlPath($sessionId, $cwd);

		if ($jsonlPath === null) {
			return null;
		}

		// The latest genuine turn is the last real user/assistant entry — scan backward
		// and take the first parseable one, stopping there instead of reading the head.
		$lastTs = null;
		$this->eachLineReverse($jsonlPath, function (string $line) use (&$lastTs) {
			$entry = json_decode($line, true);
			if (!$entry || !isset($entry['type'], $entry['timestamp'])) {
				return true;
			}
			if (($entry['type'] === 'user' || $entry['type'] === 'assistant')
				&& !$this->isSyntheticEntry($entry)
			) {
				$parsed = strtotime($entry['timestamp']);
				if ($parsed !== false) { $lastTs = $parsed; return false; }
			}
			return true;
		});

		return $lastTs;
	}

	/**
	 * Idle clock for a codex session: the timestamp of the last complete record in
	 * its rollout. Scanned backward, because rollouts run to megabytes.
	 *
	 * An unparseable tail line is skipped rather than treated as "no activity" — a
	 * rollout being appended to can end mid-write, and reporting no activity would
	 * read as infinitely idle, i.e. make a live session look buryable.
	 */
	public function codexLastActivity(string $rolloutPath): ?int {
		$ts = null;
		$this->eachLineReverse($rolloutPath, function (string $line) use (&$ts) {
			$rec = json_decode(trim($line), true);
			if (!is_array($rec) || empty($rec['timestamp'])) {
				return true; // partial/blank tail line — keep walking back
			}
			$parsed = strtotime((string) $rec['timestamp']);
			if ($parsed === false) { return true; }
			$ts = $parsed;
			return false;
		});
		return $ts;
	}

	/**
	 * Keep the first row for each session_id, preserving order. A single Claude
	 * session can surface under multiple multiplexer panes/surfaces; a transport's
	 * liveSessions() builds one row per surface, so this collapses those back to one
	 * per session.
	 */
	public function dedupBySessionId(array $rows): array {
		$seen = [];
		$out  = [];
		foreach ($rows as $row) {
			$id = $row['session_id'] ?? null;
			if ($id !== null && isset($seen[$id])) {
				continue;
			}
			if ($id !== null) {
				$seen[$id] = true;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Transcript path for a session, dispatched on agent — what "is this still
	 * resumable?" is decided on. Both agents are now resolved by id (claude preferring
	 * the cwd-composed path when it hits), so both return null rather than a path that
	 * does not exist, and a session whose reported cwd is wrong still reads as resumable.
	 */
	public function transcriptPathFor(string $agent, string $sessionId, string $cwd): ?string {
		if ($agent === 'codex') {
			return $this->codexRolloutPathFor($sessionId);
		}
		return $this->resolveJsonlPath($sessionId, $cwd);
	}

	/** PURE. Path of the FIRST rollout jsonl an lsof dump shows open, or null. */
	public function parseLsofRolloutPath(string $raw): ?string {
		return $this->parseLsofRolloutPaths($raw)[0] ?? null;
	}

	/**
	 * PURE. EVERY rollout jsonl an lsof dump shows open, in lsof's own order.
	 *
	 * A codex TUI holds more than one: its own, plus one per subagent thread it has spawned
	 * (they stay open for the life of the process). So "the rollout this pid has open" is a
	 * set, and lsof's order is fd order — which is why picking the first silently returned a
	 * SUBAGENT as the session. See ownRolloutPathFromLsof().
	 */
	public function parseLsofRolloutPaths(string $raw): array {
		return preg_match_all('#(\S*/rollout-\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}-[0-9a-fA-F-]{36}\.jsonl)#', $raw, $m)
			? array_values(array_unique($m[1]))
			: [];
	}

	/**
	 * PURE. The codex session id (uuid) from an lsof dump, or null. The uuid is
	 * positional — it trails a `rollout-<ISO-ish timestamp>-` prefix — so the
	 * shape is matched strictly rather than grabbing any uuid-looking run.
	 *
	 * FIRST match only, and therefore NOT "which session is this pid running" when the
	 * process has subagent threads open — use ownSessionIdFromLsof()/codexSessionIdForPid()
	 * for that. This stays as the pure parser it says it is.
	 */
	public function parseLsofRollout(string $raw): ?string {
		$path = $this->parseLsofRolloutPath($raw);
		return $path !== null ? $this->rolloutUuidFromPath($path) : null;
	}

	/**
	 * The rollout of the SESSION a pid is running, out of every rollout it holds open.
	 *
	 * Subagent threads are excluded by reading each candidate's own session_meta
	 * (CodexRollout::selfMeta — the LAST one in the file, since a spawned thread's rollout
	 * opens with a copy of its parent's records). What remains is the conversation a human is
	 * sitting in front of: the one to bury, to resume, and to prove a pid against.
	 *
	 * Measured: pid 53691 held rollout-…-019faf33 (thread_source=user) and
	 * rollout-…-019faf55 (thread_source=subagent, "Wegener") open, with the subagent listed
	 * FIRST — so graveyard offered a subagent thread as a buriable session, and its bury
	 * then failed the archive gate because the file's ids belong to two different threads.
	 *
	 * Ties (more than one real thread open, e.g. after a fork) go to the most recently
	 * written, which is the one still being appended to. Fails SOFT: if nothing can be read
	 * — an unreadable file, or a codex old enough not to emit thread_source — the first path
	 * is returned, so discovery never loses a session it used to find.
	 */
	public function ownRolloutPathFromLsof(string $raw): ?string {
		$paths = $this->parseLsofRolloutPaths($raw);
		if (count($paths) < 2) { return $paths[0] ?? null; }

		$reader = new CodexRollout();
		$own    = array_values(array_filter($paths, fn($p) => !$reader->selfMeta($p)['is_subagent']));
		if (!$own) { return $paths[0]; }

		usort($own, function ($a, $b) {
			clearstatcache(true, $a);
			clearstatcache(true, $b);
			return (int) @filemtime($b) <=> (int) @filemtime($a);
		});
		return $own[0];
	}

	/** The session id of the rollout ownRolloutPathFromLsof() picks, or null. */
	public function ownSessionIdFromLsof(string $raw): ?string {
		$path = $this->ownRolloutPathFromLsof($raw);
		return $path !== null ? $this->rolloutUuidFromPath($path) : null;
	}

	/** PURE. Is this command the claude binary (not a claude-*.zsh wrapper arg)? */
	public function isClaudeCommand(string $cmd): bool {
		$first = preg_split('/\s+/', trim($cmd))[0] ?? '';
		return $first === 'claude' || substr($first, -7) === '/claude';
	}

	/** PURE. Is argv[0] the codex binary itself (not codex-code-mode-host, etc.)? */
	public function isCodexCommand(string $cmd): bool {
		$first = preg_split('/\s+/', trim($cmd))[0] ?? '';
		return $first !== '' && basename($first) === 'codex';
	}

	/**
	 * PURE. The first argv word that looks like a subcommand rather than a flag or
	 * a flag's value — i.e. the first non-flag word not immediately preceded by a
	 * flag. Callers only ever REJECT known names with this (never require a match),
	 * because a value-carrying flag can leave arbitrary junk in the position:
	 * `--enable hooks` must not read as the `hooks` subcommand, while
	 * `-c features.x=true app-server` must still read as `app-server`.
	 */
	public function codexSubcommand(string $cmd): ?string {
		$words = preg_split('/\s+/', trim($cmd)) ?: [];
		array_shift($words); // argv[0]
		$prevWasFlag = false;
		foreach ($words as $w) {
			if ($w === '') { continue; }
			if ($w[0] === '-') { $prevWasFlag = true; continue; }
			if ($prevWasFlag) { $prevWasFlag = false; continue; } // a flag's value
			return $w;
		}
		return null;
	}

	/** PURE. Does this command line run a non-interactive codex subcommand? */
	public function isCodexNonTuiCommand(string $cmd): bool {
		$sub = $this->codexSubcommand($cmd);
		return $sub !== null && in_array($sub, self::CODEX_NON_TUI_SUBCOMMANDS, true);
	}

	/**
	 * PURE. Pids of interactive codex TUIs in a parseProcTable() table. Cheap
	 * pre-filter only — the caller still confirms each pid with an open rollout
	 * and a CMUX_SURFACE_ID before treating it as a backable session.
	 */
	public function codexProcPids(array $proc): array {
		$pids = [];
		foreach ($proc as $pid => $info) {
			$cmd = $info['cmd'] ?? '';
			if ($this->isCodexCommand($cmd) && !$this->isCodexNonTuiCommand($cmd)) {
				$pids[] = (int) $pid;
			}
		}
		return $pids;
	}

	/** PURE. The session id a claude process was resumed with, from its --resume arg. */
	public function claudeResumeArg(string $cmd): ?string {
		return preg_match('/--resume(?:=|\s+)([0-9a-fA-F-]{36})/', $cmd, $m) ? $m[1] : null;
	}

	/** PURE. Walk up from $pid; return the resume-script basename found in an ancestor's args, or null. */
	public function ancestorResumeScript(array $proc, int $pid): ?string {
		$guard = 0;
		while (isset($proc[$pid]) && $guard++ < 64) {
			if (preg_match('#cmux-(?:surface|agent)-resume/(claude-[A-Za-z0-9._-]+\.zsh)#', $proc[$pid]['cmd'], $m)) {
				return $m[1];
			}
			$pid = $proc[$pid]['ppid'];
			if ($pid <= 1) { break; }
		}
		return null;
	}

	/**
	 * PURE. Nearest ancestor of $pid (inclusive) running the claude binary, or null.
	 *
	 * The mirror of descendantClaudePid(), and what identifies the CALLER: graveyard
	 * runs as `php` under a shell under the agent that invoked it, so the agent's pid is
	 * a short walk up — and claudeSessionIdForPid() turns that into a session id under
	 * any multiplexer, with no surface env var involved.
	 *
	 * NEAREST, deliberately. An agent nested inside another agent's shell would
	 * otherwise be identified as its parent, and a wrong self-id is worse than none: it
	 * filters somebody else's session out of bury's view entirely.
	 */
	public function ancestorClaudePid(array $proc, int $pid): ?int {
		$guard = 0;
		$seen  = [];
		while (isset($proc[$pid]) && $guard++ < 64 && !isset($seen[$pid])) {
			$seen[$pid] = true;
			if ($this->isClaudeCommand($proc[$pid]['cmd'] ?? '')) { return $pid; }
			$pid = $proc[$pid]['ppid'];
			if ($pid <= 1) { break; }
		}
		return null;
	}

	/** PURE. First descendant pid running the claude binary, searching down from $root. */
	public function descendantClaudePid(array $proc, int $root): ?int {
		$kids = $this->proc->childIndex($proc);
		$stack = [$root]; $seen = [];
		while ($stack) {
			$cur = array_pop($stack);
			foreach ($kids[$cur] ?? [] as $c) {
				if (isset($seen[$c])) { continue; }
				$seen[$c] = true;
				if ($this->isClaudeCommand($proc[$c]['cmd'] ?? '')) { return $c; }
				$stack[] = $c;
			}
		}
		return null;
	}

	/**
	 * PURE. Does a command line carry the --dangerously-skip-permissions flag?
	 * Catches the yolo/yr/yc aliases too: zsh expands them to the literal flag
	 * before exec, so it's the flag (never the alias name) that lands in argv.
	 */
	public function cmdHasSkipPerms(string $cmd): bool {
		return (bool) preg_match('/(?:^|\s)--dangerously-skip-permissions(?![\w-])/', $cmd);
	}

	/** PURE. The value of a --model flag in a command line (--model=X or --model X), or null. */
	public function cmdModelArg(string $cmd): ?string {
		return preg_match('/--model(?:=|\s+)(\S+)/', $cmd, $m) ? $m[1] : null;
	}

	/**
	 * Resolve a session's model. As with skip_perms, the jsonl is the source of
	 * truth (the resolved model recorded on assistant turns); only when it's null
	 * (unreadable jsonl) do we fall back to the live process argv's --model launch
	 * flag. Null from both means no override was in play (default model).
	 */
	public function resolveModel(?string $jsonlModel, ?int $pid): ?string {
		if ($jsonlModel !== null) {
			return $jsonlModel;
		}
		return $pid !== null ? $this->cmdModelArg($this->proc->pidCommand($pid)) : null;
	}

	/**
	 * Resolve a session's skip_perms (yolo mode). The jsonl permission-mode is the
	 * source of truth — it reflects the mode at the END of the conversation, so it
	 * captures mid-session shift+tab toggles that a launch flag never would. Only
	 * when the jsonl can't be read (null: missing/unflushed transcript) do we fall
	 * back to the live process argv's launch flag, so a session buried without a
	 * readable jsonl no longer false-negatives to "not yolo" (graveyard dotfiles-yolo).
	 */
	public function resolveSkipPerms(?string $permissionMode, ?int $pid): bool {
		if ($permissionMode !== null) {
			return $permissionMode === 'bypassPermissions';
		}
		return $pid !== null ? $this->cmdHasSkipPerms($this->proc->pidCommand($pid)) : false;
	}

	/**
	 * The Claude session a live pid is running, from ~/.claude/sessions/<pid>.json.
	 * Null for a dead pid even when the file survives it: bury's GATE 3 kills on this
	 * answer, and pids are reused, so a stale file must never vouch for one.
	 */
	public function claudeSessionIdForPid(int $pid): ?string {
		if ($pid <= 0 || !$this->proc->pidIsAlive($pid)) { return null; }
		$file = $this->claudeSessionsDir() . "/{$pid}.json";
		if (!is_file($file)) { return null; }
		$data = json_decode((string) @file_get_contents($file), true);
		return $data['sessionId'] ?? null;
	}

	/**
	 * The codex session a pid is running — the counterpart of claudeSessionIdForPid().
	 * Codex publishes nothing, so this is derived from the rollouts the process holds
	 * open. Used by bury's GATE 3.
	 */
	public function codexSessionIdForPid(int $pid): ?string {
		return $this->ownSessionIdFromLsof($this->proc->lsofForPid($pid));
	}

	public function buildResumeCommand(string $sessionId, bool $skipPerms, ?string $model): string {
		$cmd = 'claude';
		if ($skipPerms) {
			$cmd .= ' --dangerously-skip-permissions';
		}
		$cmd .= " --resume {$sessionId}";
		if ($model) {
			$cmd .= " --model={$model}";
		}
		return $cmd;
	}

	/**
	 * `codex resume <uuid>` replaying the session's recorded context.
	 *
	 * The flags are NOT redundant with the rollout: resume re-reads
	 * ~/.codex/config.toml rather than rehydrating turn_context — measured, a
	 * read-only session resumed bare came back danger-full-access. Omitting a flag
	 * we don't know still falls back to config, which is the old behaviour; what we
	 * must never do is quietly widen a sandbox.
	 *
	 * Values originate in a file on disk and land on a shell command line, so each
	 * is whitelisted to a plain token and anything else is dropped, not quoted.
	 */
	public function buildCodexResumeCommand(string $sessionId, ?string $model, array $opts = []): string {
		$safe = fn($v) => is_string($v) && $v !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $v) ? $v : null;

		// Flags go BEFORE the session id, matching the documented
		// `codex resume [OPTIONS] [SESSION_ID] [PROMPT]` shape — the positional is
		// what the parser is lenient about, not the options.
		$flags = '';
		if ($m = $safe($model)) {
			$flags .= " --model={$m}";
		}
		if ($s = $safe($opts['sandbox'] ?? null)) {
			$flags .= " --sandbox={$s}";
		}
		if ($a = $safe($opts['approval'] ?? null)) {
			$flags .= " --ask-for-approval={$a}";
		}
		if ($e = $safe($opts['effort'] ?? null)) {
			// reasoning effort has no dedicated flag; it's a config override.
			$flags .= " -c model_reasoning_effort=\"{$e}\"";
		}
		return "codex resume{$flags} {$sessionId}";
	}

	/**
	 * The relaunch command for a session, dispatched on agent. Wraps rather than
	 * replaces buildResumeCommand() so graveyard's callers stay put.
	 *
	 * $opts carries agent-specific knobs with no Claude equivalent — for codex,
	 * sandbox/approval/effort. skip_perms is Claude-only and never reaches codex.
	 */
	public function buildAgentResumeCommand(string $agent, string $sessionId, bool $skipPerms = false, ?string $model = null, array $opts = []): string {
		if ($agent === 'codex') {
			return $this->buildCodexResumeCommand($sessionId, $model, $opts);
		}
		return $this->buildResumeCommand($sessionId, $skipPerms, $model);
	}

	/** Generate a v4 UUID (for graveyard group ids). */
	public function uuidv4(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}

	/**
	 * PURE. Normalize a workspace/tab title for equality comparison: strip the leading
	 * status-glyph / punctuation junk cmux tab titles carry (e.g. "⠂ ", "✳ ", quotes),
	 * collapse whitespace, and lowercase. Used so an exact title match can beat mere
	 * substring matches.
	 */
	public function normalizeTitle(string $title): string {
		$s = preg_replace('/^[^\p{L}\p{N}]+/u', '', $title); // leading glyphs / punctuation
		$s = preg_replace('/\s+/u', ' ', (string) $s);       // collapse internal whitespace
		return mb_strtolower(trim((string) $s));
	}
}
