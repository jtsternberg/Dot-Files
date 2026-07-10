# Claude Graveyard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `bin/graveyard`, a PHP CLI that demotes ("buries") idle Claude Code sessions to a durable, GC-proof, reviewable archive at `~/.claude-graveyard/`, then lists, shows, and resurrects them — freeing RAM from stale live sessions while preserving the cmux sidebar reminder.

**Architecture:** Extract the cmux-tree + Claude-session-discovery machinery currently living inside `bin/cmux-bak` into a shared `misc/helpers/cmux.php` module (a `Cmux` helper class), refactor `cmux-bak` onto it, then build `bin/graveyard` as a second consumer. Bury drives the live surface's interactive `/export` over `cmux send` (headless `/export` does not exist — proven). Resurrect recreates the cmux workspace and launches a fresh `claude` told to read the exported transcript.

**Tech Stack:** PHP 8+ (`JT` namespace), the repo's `misc/helpers.php` CLI framework, the `cmux` CLI, Claude Code session files (`~/.claude/sessions/<pid>.json`, `~/.claude/projects/<enc-cwd>/<id>.jsonl`).

**Design spec:** `docs/superpowers/specs/2026-07-09-claude-graveyard-design.md` (approved).
**bd issue:** `dotfiles-7ds`.

## Global Constraints

- PHP, `namespace JT;`, standard bin header block (see CLAUDE.md), tabs for indentation (size 3), PSR-12 naming, exit 0 success / 1 error.
- CLI bootstrap: `$cli = require_once dirname(__DIR__) . '/misc/helpers.php';` then `$cli->getHelp()`.
- Colors: red=errors, green=success, yellow=info/warning.
- Store root: `~/.claude-graveyard/` — created if missing, git-ignored, never externalized (may contain sensitive transcript content).
- `/export` is interactive-only and requires a real TTY → bury targets **live** sessions by sending `/export` to their cmux surface. Never parse JSONL to render transcripts.
- Resurrection model: **read-the-transcript restart** (fresh `claude` reads the export), NOT `claude --resume`.
- No new PHP test framework: pure-logic units get a plain-PHP assert script under `tests/graveyard/`; cmux/`/export` integration is verified manually against a throwaway session per the documented steps.
- `getCommandOutputAndExitCode($cmd)` returns `['exitCode'=>int,'error'=>string,'output'=>string]`.
- Never kill a process on a failed/incomplete export. Never bury the caller's own session.

---

## File Structure

- **Create `misc/helpers/cmux.php`** — `JT\Helpers\Cmux` class. Owns all cmux + Claude-session knowledge: `ping()`, `tree()`, `loadClaudeSessions()`, `sendToSurface()`, `createSurface()`, `buildResumeCommand()`, `newWorkspace()`, `findWorkspaceByTitle()`, tty/pid/cwd helpers, JSONL model/permission reader, `encodeProjectKey()`, `jsonlPathFor()`. Extracted verbatim-in-behavior from `bin/cmux-bak`.
- **Modify `bin/cmux-bak`** — delete the extracted methods; construct and delegate to `JT\Helpers\Cmux`. Behavior unchanged.
- **Create `bin/graveyard`** — the CLI: arg routing + the `Graveyard` orchestration class (bury/ls/show/resurrect + idle gate, self-bury guard, temp-rename export, index/meta IO).
- **Create `tests/graveyard/run.php`** — plain-PHP assert harness for pure logic (duration parse, idle-gate decision given injected inputs, index upsert, self-bury filter, project-key encoding).
- **Modify `.gitignore`** — ignore nothing new in-repo (store is in `$HOME`), but add a guard comment; no-op if already covered.

---

## Task 1: Extract shared `Cmux` helper from cmux-bak

**Files:**
- Create: `misc/helpers/cmux.php`
- Modify: `bin/cmux-bak` (replace inlined methods with delegation)
- Test: `tests/graveyard/run.php` (project-key encoding + resume-command build — the two pure pieces)

**Interfaces:**
- Produces: `JT\Helpers\Cmux` with:
  - `__construct(\JT\Helpers $cli)`
  - `ping(): bool` — true when `cmux ping` returns `PONG`.
  - `tree(): array` — decoded `cmux tree --all --json`; `exitErr` on failure.
  - `loadClaudeSessions(): array` — keyed by tty: `['session_id','cwd','status','pid','skip_perms','model']`.
  - `sendToSurface(string $surfRef, string $wsRef, string $text): void`
  - `createSurface(string $wsRef, ?string $paneRef, string $type, ?string $url): ?string`
  - `newWorkspace(string $title, ?string $cwd): array` — returns `['ref'=>string,'firstPaneRef'=>?string,'firstSurfRef'=>?string]` or throws.
  - `findWorkspaceByTitle(array $tree, string $title): ?array`
  - `findWorkspaceByRef(array $tree, string $ref): ?array`
  - `buildResumeCommand(string $sessionId, bool $skipPerms, ?string $model): string`
  - `pidIsAlive(int $pid): bool`, `getTtyForPid(int $pid): ?string`, `getCwdForTty(string $tty): ?string`
  - `encodeProjectKey(string $cwd): string` — `str_replace(['/','.'], '-', $cwd)`
  - `jsonlPathFor(string $sessionId, string $cwd): string`
  - `readSessionJsonl(string $sessionId, string $cwd): array` — `['permission_mode'=>?string,'model'=>?string]`

- [ ] **Step 1: Write failing tests for the two pure methods**

Create `tests/graveyard/run.php`:

```php
#!/usr/bin/env php
<?php
namespace JT;
# Plain-PHP assert harness for graveyard pure logic. Run: php tests/graveyard/run.php
$cli = require_once dirname(__DIR__, 2) . '/misc/helpers.php';
require_once dirname(__DIR__, 2) . '/misc/helpers/cmux.php';

$pass = 0; $fail = 0;
function ok($cond, $label) {
	global $pass, $fail;
	if ($cond) { $pass++; echo "ok - $label\n"; }
	else { $fail++; echo "NOT OK - $label\n"; }
}

$cmux = new Helpers\Cmux($cli);

// encodeProjectKey
ok($cmux->encodeProjectKey('/Users/JT/.dotfiles') === '-Users-JT--dotfiles', 'encodeProjectKey dotfiles');
ok($cmux->encodeProjectKey('/Users/JT/Code/claude-plugins') === '-Users-JT-Code-claude-plugins', 'encodeProjectKey plugins');

// buildResumeCommand
ok($cmux->buildResumeCommand('abc', false, null) === 'claude --resume abc', 'resume plain');
ok($cmux->buildResumeCommand('abc', true, 'opus') === 'claude --dangerously-skip-permissions --resume abc --model=opus', 'resume flags');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/graveyard/run.php`
Expected: FAIL — `misc/helpers/cmux.php` does not exist (`require_once` fatal).

- [ ] **Step 3: Create `misc/helpers/cmux.php` by lifting methods from cmux-bak**

Move these methods **verbatim** (behavior-preserving) out of `bin/cmux-bak`'s `CmuxBak` class into a new `JT\Helpers\Cmux` class, renaming `getCmuxTree`→`tree` and keeping the rest: `loadClaudeSessions`, `pidIsAlive`, `getTtyForPid`, `getCwdForTty`, `sendToSurface`, `createSurface`, `findWorkspaceByTitle`, `findWorkspaceByRef`, `buildResumeCommand`, `readSessionJsonl`. Add the small new pure helpers `encodeProjectKey`, `jsonlPathFor`, and a `ping()`. Header + skeleton:

```php
<?php
namespace JT\Helpers;

# =============================================================================
# Cmux — shared cmux + Claude-session machinery for bin/cmux-bak and bin/graveyard.
# Extracted from bin/cmux-bak (v0.1.0). Behavior-preserving.
# =============================================================================

class Cmux {

	const SESSIONS_DIR = '~/.claude/sessions';

	protected $cli;
	protected $dryRun;

	public function __construct($cli, bool $dryRun = false) {
		$this->cli    = $cli;
		$this->dryRun = $dryRun;
	}

	public function ping(): bool {
		$result = shell_exec('cmux ping 2>/dev/null');
		return trim((string) $result) === 'PONG';
	}

	public function tree(): array {
		// --id-format both so every node carries its stable UUID (`id`) alongside
		// the positional `ref`. cmux-bak matches by tty/title (unaffected); graveyard
		// needs the UUID to compare against CMUX_SURFACE_ID for the self-bury guard.
		$output = shell_exec('cmux tree --all --json --id-format both 2>/dev/null');
		if (!$output) {
			$this->cli->exitErr('cmux tree returned no output.');
		}
		$tree = json_decode($output, true);
		if (!$tree) {
			$this->cli->exitErr('Failed to parse cmux tree JSON.');
		}
		return $tree;
	}

	public function encodeProjectKey(string $cwd): string {
		return str_replace(['/', '.'], '-', $cwd);
	}

	public function jsonlPathFor(string $sessionId, string $cwd): string {
		$claudeDir = $this->cli->convertPathToAbsolute('~/.claude');
		return "{$claudeDir}/projects/{$this->encodeProjectKey($cwd)}/{$sessionId}.jsonl";
	}

	// ...loadClaudeSessions, pidIsAlive, getTtyForPid, getCwdForTty,
	//    sendToSurface, createSurface, findWorkspaceByTitle, findWorkspaceByRef,
	//    buildResumeCommand, readSessionJsonl — moved verbatim from cmux-bak,
	//    with readSessionJsonl reusing jsonlPathFor().
}
```

Note: `sendToSurface` in cmux-bak also printed a label + honored `$this->dryRun`. Keep the `$dryRun` guard (constructor arg) but drop the `$this->cli->msg` label printing from the shared method — callers that want a label print it themselves. cmux-bak's calls that passed a label must print it at the call site (adjust in Task 2).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/graveyard/run.php`
Expected: PASS — `4 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add misc/helpers/cmux.php tests/graveyard/run.php
git commit -m "feat(graveyard): extract shared Cmux helper from cmux-bak"
```

---

## Task 2: Refactor cmux-bak onto the shared Cmux helper

**Files:**
- Modify: `bin/cmux-bak` (remove moved methods, require + delegate)

**Interfaces:**
- Consumes: `JT\Helpers\Cmux` (Task 1).

- [ ] **Step 1: Capture the current cmux-bak behavior as a baseline**

Run and save output (backup is read-only, safe):

```bash
php bin/cmux-bak --dry-run --verbose > /tmp/cmux-bak-before.txt 2>&1 || true
php bin/cmux-bak --restore --dry-run --verbose > /tmp/cmux-bak-restore-before.txt 2>&1 || true
```

- [ ] **Step 2: Wire the helper into cmux-bak**

In `bin/cmux-bak`, after the help block, add:

```php
require_once dirname(__DIR__) . '/misc/helpers/cmux.php';
```

In `CmuxBak::__construct`, create the helper: `$this->cmux = new \JT\Helpers\Cmux($cli, $this->dryRun);`. Replace `$this->getCmuxTree()` → `$this->cmux->tree()`, `$this->loadClaudeSessions()` → `$this->cmux->loadClaudeSessions()`, `$this->findWorkspaceByTitle(...)` → `$this->cmux->findWorkspaceByTitle(...)`, `$this->buildResumeCommand(...)` → `$this->cmux->buildResumeCommand(...)`, `$this->createSurface(...)` → `$this->cmux->createSurface(...)`. For `sendToSurface`, print the label at the call site (where cmux-bak passed one) then call `$this->cmux->sendToSurface($surfRef, $wsRef, $text)`. Delete the now-unused moved methods from `CmuxBak`. Keep `CmuxBak`-only methods (`backup`, `restore`, `askSurfaceNotFound`, `openNewSurfaceInWorkspace`, `firstCwdFromBakWs`, `allSurfacesFromBakWs`, `normalizeTitle`).

- [ ] **Step 3: Verify behavior is unchanged**

Run and diff against the baseline:

```bash
php bin/cmux-bak --dry-run --verbose > /tmp/cmux-bak-after.txt 2>&1 || true
php bin/cmux-bak --restore --dry-run --verbose > /tmp/cmux-bak-restore-after.txt 2>&1 || true
diff /tmp/cmux-bak-before.txt /tmp/cmux-bak-after.txt && echo "BACKUP UNCHANGED"
diff /tmp/cmux-bak-restore-before.txt /tmp/cmux-bak-restore-after.txt && echo "RESTORE UNCHANGED"
```

Expected: both diffs empty; `BACKUP UNCHANGED` and `RESTORE UNCHANGED` printed. (Cosmetic-only diffs from moved label prints are acceptable if the actions listed are identical — inspect if non-empty.)

- [ ] **Step 4: Commit**

```bash
git add bin/cmux-bak
git commit -m "refactor(cmux-bak): consume shared Cmux helper (no behavior change)"
```

---

## Task 3: Graveyard store + pure logic (duration, index, session model)

**Files:**
- Create: `bin/graveyard` (header, bootstrap, `Graveyard` class scaffold with pure methods only)
- Test: `tests/graveyard/run.php` (extend)

**Interfaces:**
- Produces (methods on `JT\Graveyard`):
  - `parseDuration(string $s): int` — `"2d"`→172800, `"48h"`→172800, `"90m"`→5400, `"30"`→30 (bare = seconds). Invalid → throws `\InvalidArgumentException`.
  - `storeRoot(): string` — abs path to `~/.claude-graveyard`.
  - `sessionDir(string $id): string`, `transcriptPath(string $id): string`, `metaPath(string $id): string`, `indexPath(): string`.
  - `readIndex(): array`, `upsertIndex(array $entry): void` — dedupe by `session_id`, newest write wins, persisted as pretty JSON `{"version":1,"tombstones":[...]}`.
  - `buildTombstone(array $session, array $surface, string $summary, string $buriedAt): array` — assembles the meta record.

- [ ] **Step 1: Write failing tests**

Append to `tests/graveyard/run.php` (before the summary/exit lines):

```php
require_once dirname(__DIR__, 2) . '/bin/graveyard_lib.php'; // pure class, see Step 3
$gy = new Graveyard($cli, $cmux);

// parseDuration
ok($gy->parseDuration('2d') === 172800, 'dur 2d');
ok($gy->parseDuration('48h') === 172800, 'dur 48h');
ok($gy->parseDuration('90m') === 5400, 'dur 90m');
ok($gy->parseDuration('30') === 30, 'dur bare seconds');
$threw = false; try { $gy->parseDuration('nope'); } catch (\InvalidArgumentException $e) { $threw = true; }
ok($threw, 'dur invalid throws');

// upsertIndex dedupes by session_id (use a temp store root via env override)
putenv('GRAVEYARD_ROOT=' . sys_get_temp_dir() . '/gy-test-' . getmypid());
@mkdir(getenv('GRAVEYARD_ROOT'), 0755, true);
$gy2 = new Graveyard($cli, $cmux);
$gy2->upsertIndex(['session_id' => 'x', 'summary' => 'first']);
$gy2->upsertIndex(['session_id' => 'x', 'summary' => 'second']);
$gy2->upsertIndex(['session_id' => 'y', 'summary' => 'other']);
$idx = $gy2->readIndex();
ok(count($idx['tombstones']) === 2, 'upsert dedupes to 2');
$xs = array_values(array_filter($idx['tombstones'], fn($t) => $t['session_id'] === 'x'));
ok($xs[0]['summary'] === 'second', 'upsert newest wins');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/graveyard/run.php`
Expected: FAIL — `bin/graveyard_lib.php` missing.

- [ ] **Step 3: Create the pure library `bin/graveyard_lib.php`**

Put the testable class in a `require`-able lib so both the CLI and the test harness load it (the executable `bin/graveyard` will `require` it). Store root honors `GRAVEYARD_ROOT` env for tests, else `~/.claude-graveyard`.

```php
<?php
namespace JT;

class Graveyard {

	protected $cli;
	protected $cmux;

	public function __construct($cli, $cmux) {
		$this->cli  = $cli;
		$this->cmux = $cmux;
	}

	public function storeRoot(): string {
		$env = getenv('GRAVEYARD_ROOT');
		return $env ?: $this->cli->convertPathToAbsolute('~/.claude-graveyard');
	}

	public function sessionDir(string $id): string { return $this->storeRoot() . "/sessions/{$id}"; }
	public function transcriptPath(string $id): string { return $this->sessionDir($id) . '/transcript.txt'; }
	public function metaPath(string $id): string { return $this->sessionDir($id) . '/meta.json'; }
	public function indexPath(): string { return $this->storeRoot() . '/index.json'; }

	public function parseDuration(string $s): int {
		if (!preg_match('/^(\d+)([smhd]?)$/', trim($s), $m)) {
			throw new \InvalidArgumentException("Invalid duration: {$s}");
		}
		$n = (int) $m[1];
		switch ($m[2]) {
			case 'd': return $n * 86400;
			case 'h': return $n * 3600;
			case 'm': return $n * 60;
			default:  return $n; // 's' or bare
		}
	}

	public function readIndex(): array {
		$path = $this->indexPath();
		if (!file_exists($path)) {
			return ['version' => 1, 'tombstones' => []];
		}
		$data = json_decode((string) file_get_contents($path), true);
		return $data ?: ['version' => 1, 'tombstones' => []];
	}

	public function upsertIndex(array $entry): void {
		$idx = $this->readIndex();
		$idx['tombstones'] = array_values(array_filter(
			$idx['tombstones'],
			fn($t) => ($t['session_id'] ?? null) !== ($entry['session_id'] ?? null)
		));
		$idx['tombstones'][] = $entry;
		$dir = dirname($this->indexPath());
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }
		file_put_contents($this->indexPath(), json_encode($idx, JSON_PRETTY_PRINT));
	}

	public function buildTombstone(array $session, array $surface, string $summary, string $buriedAt): array {
		$sessionId = $session['session_id'];
		$cwd       = $session['cwd'] ?? '';
		$lastActive = null;
		$jsonl = $this->cmux->jsonlPathFor($sessionId, $cwd);
		if (is_file($jsonl)) {
			$lastActive = gmdate('Y-m-d\TH:i:s\Z', filemtime($jsonl));
		}
		return [
			'session_id'      => $sessionId,
			'workspace_title' => $surface['workspace_title'] ?? '',
			'tab_title'       => $surface['tab_title'] ?? '',
			'cwd'             => $cwd,
			'model'           => $session['model'] ?? null,
			'skip_perms'      => (bool) ($session['skip_perms'] ?? false),
			'summary'         => $summary,
			'buried_at'       => $buriedAt,
			'last_active'     => $lastActive,
		];
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/graveyard/run.php`
Expected: PASS — all assertions ok.

- [ ] **Step 5: Commit**

```bash
git add bin/graveyard_lib.php tests/graveyard/run.php
git commit -m "feat(graveyard): store paths, duration parse, index upsert, tombstone builder"
```

---

## Task 4: Live-session discovery + self-bury guard + idle gate

**Files:**
- Modify: `bin/graveyard_lib.php` (add discovery/selection methods)
- Test: `tests/graveyard/run.php` (extend — idle gate + self-bury filter with injected inputs)

**Interfaces:**
- Produces:
  - `liveSessions(): array` — list of `['session_id','cwd','model','skip_perms','pid','tty','surface_ref','workspace_ref','workspace_title','tab_title','idle_seconds']`, built by joining `Cmux::loadClaudeSessions()` (keyed by tty) with `Cmux::tree()` surfaces (matched by tty). `idle_seconds` = now − JSONL mtime.
  - `selfSurfaceId(): ?string` — `getenv('CMUX_SURFACE_ID') ?: null`.
  - `filterSelf(array $sessions, ?string $selfSurfaceId, ?string $selfSessionId): array` — drops any whose `surface_ref`/surface UUID equals self surface OR `session_id` equals self session.
  - `isBusy(int $idleSeconds, int $idleFloor, string $lastScreen): bool` — busy if `idleSeconds < idleFloor` OR `$lastScreen` contains an active-turn marker. Marker regex constant `ACTIVE_TURN_RE` (see below).
  - `readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string` — `cmux read-screen ... --lines N` output (empty string on error).

- [ ] **Step 1: Write failing tests for the pure decisions**

Append to `tests/graveyard/run.php`:

```php
// isBusy: idle floor
ok($gy->isBusy(5, 15, '')  === true,  'busy when idle<floor');
ok($gy->isBusy(30, 15, '') === false, 'idle enough, quiet screen');
// isBusy: active-turn markers on screen
ok($gy->isBusy(30, 15, '... (esc to interrupt)') === true,  'busy: esc-to-interrupt');
ok($gy->isBusy(30, 15, '✳ Cogitating… (1.2k tokens)') === true, 'busy: token counter');
ok($gy->isBusy(30, 15, 'justin@mac ~/.dotfiles %') === false, 'quiet prompt not busy');

// filterSelf
$sessions = [
	['surface_ref' => 'surface:1', 'session_id' => 'self-sess'],
	['surface_ref' => 'surface:2', 'session_id' => 'other'],
];
$kept = $gy->filterSelf($sessions, 'surface:1', 'self-sess');
ok(count($kept) === 1 && $kept[0]['session_id'] === 'other', 'filterSelf drops self by surface+session');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/graveyard/run.php`
Expected: FAIL — `isBusy`/`filterSelf` undefined.

- [ ] **Step 3: Implement discovery + gate**

Add to `Graveyard` (in `bin/graveyard_lib.php`):

```php
	// Active-turn markers Claude Code prints while a turn is running. Absence-based
	// detection (NOT prompt matching, which is fragile with custom/powerline prompts).
	const ACTIVE_TURN_RE = '/(esc to interrupt|\(\s*[\d.]+k?\s+tokens\s*\)|Cogitating|Thinking…|Pondering|Deciphering)/i';

	const IDLE_FLOOR_DEFAULT = 15; // seconds; JSONL must be quiet at least this long

	public function selfSurfaceId(): ?string {
		return getenv('CMUX_SURFACE_ID') ?: null;
	}

	public function filterSelf(array $sessions, ?string $selfSurfaceId, ?string $selfSessionId): array {
		return array_values(array_filter($sessions, function ($s) use ($selfSurfaceId, $selfSessionId) {
			if ($selfSurfaceId && ($s['surface_ref'] ?? null) === $selfSurfaceId) { return false; }
			if ($selfSurfaceId && ($s['surface_id'] ?? null) === $selfSurfaceId) { return false; }
			if ($selfSessionId && ($s['session_id'] ?? null) === $selfSessionId) { return false; }
			return true;
		}));
	}

	public function isBusy(int $idleSeconds, int $idleFloor, string $lastScreen): bool {
		if ($idleSeconds < $idleFloor) { return true; }
		return (bool) preg_match(self::ACTIVE_TURN_RE, $lastScreen);
	}

	public function readLastScreen(string $surfaceRef, string $workspaceRef, int $lines = 6): string {
		$cmd = 'cmux read-screen --surface ' . escapeshellarg($surfaceRef)
			 . ' --workspace ' . escapeshellarg($workspaceRef)
			 . ' --lines ' . (int) $lines . ' 2>/dev/null';
		return (string) shell_exec($cmd);
	}

	public function liveSessions(): array {
		$byTty = $this->cmux->loadClaudeSessions();
		$tree  = $this->cmux->tree();
		$now   = time();
		$out   = [];

		foreach ($tree['windows'] ?? [] as $window) {
			foreach ($window['workspaces'] ?? [] as $ws) {
				foreach ($ws['panes'] ?? [] as $pane) {
					foreach ($pane['surfaces'] ?? [] as $surf) {
						$tty = $surf['tty'] ?? '';
						if (!$tty || empty($byTty[$tty])) { continue; }
						$sess = $byTty[$tty];
						$jsonl = $this->cmux->jsonlPathFor($sess['session_id'], $sess['cwd'] ?? '');
						$idle = is_file($jsonl) ? ($now - filemtime($jsonl)) : PHP_INT_MAX;
						$out[] = [
							'session_id'      => $sess['session_id'],
							'cwd'             => $sess['cwd'] ?? '',
							'model'           => $sess['model'] ?? null,
							'skip_perms'      => (bool) ($sess['skip_perms'] ?? false),
							'pid'             => $sess['pid'] ?? null,
							'tty'             => $tty,
							'surface_ref'     => $surf['ref'] ?? '',
							'surface_id'      => $surf['id'] ?? ($surf['ref'] ?? ''),
							'workspace_ref'   => $ws['ref'] ?? '',
							'workspace_title' => $ws['title'] ?? '',
							'tab_title'       => $surf['title'] ?? '',
							'idle_seconds'    => $idle,
						];
					}
				}
			}
		}
		return $out;
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/graveyard/run.php`
Expected: PASS — all assertions ok.

- [ ] **Step 5: Manual smoke — discovery against the real machine**

Add a temporary debug route or run inline:

```bash
php -r '$cli=require "misc/helpers.php"; require "misc/helpers/cmux.php"; require "bin/graveyard_lib.php";
$g=new JT\Graveyard($cli,new JT\Helpers\Cmux($cli));
foreach($g->liveSessions() as $s){ printf("%s idle=%ds ws=%s tab=%.30s\n",substr($s["session_id"],0,8),$s["idle_seconds"],$s["workspace_ref"],$s["tab_title"]); }'
```

Expected: a line per live claude session with a plausible idle time; the current session appears (it will be filtered later by `filterSelf`).

- [ ] **Step 6: Commit**

```bash
git add bin/graveyard_lib.php tests/graveyard/run.php
git commit -m "feat(graveyard): live-session discovery, self-bury guard, idle gate"
```

---

## Task 5: Bury one session (export→temp→rename, meta, teardown)

**Files:**
- Modify: `bin/graveyard_lib.php` (add `bury`, `exportTranscript`, `deriveSummary`, `teardown`)
- Test: manual integration against a throwaway session (no unit test — drives cmux + `/export`).

**Interfaces:**
- Consumes: `liveSessions`, `isBusy`, `readLastScreen`, `buildTombstone`, `upsertIndex`, `Cmux::sendToSurface`.
- Produces:
  - `exportTranscript(array $sess, int $timeoutSecs = 30): bool` — sends `/export <tmp>`, polls for the temp file to appear, renames to `transcript.txt`, returns success. Never throws on timeout; returns false.
  - `deriveSummary(array $sess): string` — first user prompt from the JSONL (best-effort), else `tab_title`, truncated to 100 chars.
  - `buryOne(array $sess, bool $force, bool $autoConfirm): bool` — full flow incl. idle gate + teardown.
  - `teardown(array $sess): void` — `cmux close-surface --surface <ref>` (workspace auto-collapses when empty).

- [ ] **Step 1: Implement `exportTranscript` (temp + atomic rename)**

```php
	public function exportTranscript(array $sess, int $timeoutSecs = 30): bool {
		$id  = $sess['session_id'];
		$dir = $this->sessionDir($id);
		if (!is_dir($dir)) { mkdir($dir, 0755, true); }

		$tmp = $dir . '/.transcript.' . ($sess['pid'] ?? getmypid()) . '.tmp';
		if (file_exists($tmp)) { @unlink($tmp); }

		// /export <path> writes rendered text straight to the file (no dialog when path is writable).
		$this->cmux->sendToSurface($sess['surface_ref'], $sess['workspace_ref'], '/export ' . $tmp . "\n");

		$deadline = time() + $timeoutSecs;
		while (time() < $deadline) {
			clearstatcache(true, $tmp);
			if (is_file($tmp) && filesize($tmp) > 0) {
				// give the write a beat to finish, then rename atomically
				usleep(300000);
				return @rename($tmp, $this->transcriptPath($id));
			}
			usleep(500000);
		}
		@unlink($tmp);
		return false;
	}
```

- [ ] **Step 2: Implement `deriveSummary`, `teardown`, `buryOne`**

```php
	public function deriveSummary(array $sess): string {
		$jsonl = $this->cmux->jsonlPathFor($sess['session_id'], $sess['cwd'] ?? '');
		if (is_file($jsonl)) {
			$fh = fopen($jsonl, 'r');
			while (($line = fgets($fh)) !== false) {
				$e = json_decode($line, true);
				if (($e['type'] ?? '') === 'user') {
					$content = $e['message']['content'] ?? '';
					if (is_array($content)) {
						foreach ($content as $c) { if (($c['type'] ?? '') === 'text') { $content = $c['text']; break; } }
					}
					if (is_string($content) && trim($content) !== '') {
						fclose($fh);
						return trim(mb_substr(preg_replace('/\s+/', ' ', $content), 0, 100));
					}
				}
			}
			fclose($fh);
		}
		$title = $sess['tab_title'] ?? '';
		// strip Claude Code's leading status glyph
		return trim(preg_replace('/^[^\x00-\x7F]+\s*/u', '', $title)) ?: '(no summary)';
	}

	public function teardown(array $sess): void {
		shell_exec('cmux close-surface --surface ' . escapeshellarg($sess['surface_ref']) . ' 2>/dev/null');
	}

	public function buryOne(array $sess, bool $force, bool $autoConfirm): bool {
		$idFloor = self::IDLE_FLOOR_DEFAULT;
		$screen  = $this->readLastScreen($sess['surface_ref'], $sess['workspace_ref']);
		if ($this->isBusy((int) $sess['idle_seconds'], $idFloor, $screen)) {
			if (!$force) {
				$this->cli->msg("  Skipping {$sess['tab_title']} — session looks busy (use --force to override).", 'yellow');
				return false;
			}
			$this->cli->msg('  Session looks busy but --force given; proceeding.', 'yellow');
		}

		$this->cli->msg("  Exporting transcript for " . substr($sess['session_id'], 0, 8) . '…', 'cyan');
		if (!$this->exportTranscript($sess)) {
			$this->cli->err("  Export failed (no transcript written) — leaving session ALIVE.");
			return false;
		}

		$summary = $this->deriveSummary($sess);
		$tomb = $this->buildTombstone($sess, [
			'workspace_title' => $sess['workspace_title'],
			'tab_title'       => $sess['tab_title'],
		], $summary, gmdate('Y-m-d\TH:i:s\Z'));
		file_put_contents($this->metaPath($sess['session_id']), json_encode($tomb, JSON_PRETTY_PRINT));
		$this->upsertIndex($tomb);
		$this->cli->successMsg("  Buried: {$summary}");

		if (!$autoConfirm && !$this->cli->confirm("  Close the cmux tab and kill this session now?")) {
			$this->cli->msg('  Left the tab open; transcript is archived.', 'yellow');
			return true;
		}
		$this->teardown($sess);
		$this->cli->msg('  Tab closed; RAM freed.', 'green');
		return true;
	}
```

- [ ] **Step 3: Manual integration test (throwaway session)**

In a **separate** cmux workspace, start a throwaway session: open a terminal, run `claude` in some dir, ask it one trivial thing, let it go idle ~20s. Note its surface via `cmux tree --all`. Then from the graveyard workspace, once Task 6 wiring exists you'll use `graveyard bury <ref>`; for now test the method directly:

```bash
php -r '$cli=require "misc/helpers.php"; require "misc/helpers/cmux.php"; require "bin/graveyard_lib.php";
$cmux=new JT\Helpers\Cmux($cli); $g=new JT\Graveyard($cli,$cmux);
foreach($g->liveSessions() as $s){ if($s["surface_ref"]==="surface:REPLACE"){ var_dump($g->buryOne($s,false,false)); } }'
```

Expected: `transcript.txt` appears under `~/.claude-graveyard/sessions/<id>/`, non-empty and human-readable; `meta.json` + `index.json` written; on confirm, the throwaway tab closes and its `claude` process exits (`ps -p <pid>` gone). If `/export` does NOT write the file within 30s, the method returns false and the session stays alive — verify that failure path by pointing at a busy session.

- [ ] **Step 4: Commit**

```bash
git add bin/graveyard_lib.php
git commit -m "feat(graveyard): bury one session via /export temp+rename with idle gate and teardown"
```

---

## Task 6: CLI wiring — `bury <ref>` / `bury --idle` / `ls` / `show` / `resurrect`

**Files:**
- Create: `bin/graveyard` (executable; header, bootstrap, help, arg routing; `require` the lib)
- Modify: `bin/graveyard_lib.php` (add `resurrect`, `listTombstones` render, `showTombstone`, `buryByRef`, `buryIdle`)

**Interfaces:**
- Consumes: everything above.
- Produces subcommands routed in `bin/graveyard`:
  - `graveyard bury <ref>` — resolve `<ref>` in `liveSessions`, refuse if it's self, `buryOne(..., force=hasFlag('force'), autoConfirm=isAutoconfirm())`.
  - `graveyard bury --idle <dur>` — sweep: `filterSelf` then keep `idle_seconds >= parseDuration`, preview list, confirm (unless `-y`), bury each.
  - `graveyard ls` — table from `readIndex`.
  - `graveyard show <id>` — open `transcriptPath` in editor.
  - `graveyard resurrect <id>` — recreate workspace + fresh claude + read preamble.

- [ ] **Step 1: Implement `resurrect`, `buryByRef`, `buryIdle`, `listTombstones`, `showTombstone` in the lib**

```php
	public function listTombstones(): void {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		if (!$tombs) { $this->cli->msg('Graveyard is empty.', 'yellow'); return; }
		usort($tombs, fn($a, $b) => strcmp($b['buried_at'] ?? '', $a['buried_at'] ?? ''));
		foreach ($tombs as $t) {
			$id = substr($t['session_id'], 0, 8);
			$this->cli->msg(sprintf(
				"%s  %-24.24s  %-10.10s  %s",
				$id, $t['workspace_title'] ?: '(untitled)', substr($t['buried_at'] ?? '', 0, 10), $t['summary'] ?? ''
			));
			$this->cli->msg("          cwd: " . ($t['cwd'] ?? ''), 'cyan');
		}
	}

	public function resolveTombstone(string $prefix): ?array {
		$tombs = $this->readIndex()['tombstones'] ?? [];
		$matches = array_values(array_filter($tombs, fn($t) => str_starts_with($t['session_id'], $prefix)));
		if (count($matches) === 1) { return $matches[0]; }
		if (count($matches) > 1) {
			$this->cli->err("Ambiguous id '{$prefix}' — matches " . count($matches) . " tombstones:");
			foreach ($matches as $m) { $this->cli->msg('  ' . substr($m['session_id'], 0, 12) . '  ' . $m['summary']); }
		}
		return null;
	}

	public function showTombstone(string $prefix): void {
		$t = $this->resolveTombstone($prefix);
		if (!$t) { $this->cli->exitErr("No single tombstone matches '{$prefix}'."); }
		$path = $this->transcriptPath($t['session_id']);
		if (!is_file($path)) { $this->cli->exitErr("Transcript missing: {$path}"); }
		$editor = trim((string) shell_exec('command -v code 2>/dev/null'));
		if ($editor) { shell_exec('code -r ' . escapeshellarg($path)); }
		else { $ed = getenv('EDITOR') ?: 'vi'; $this->cli->runCommand($ed . ' ' . escapeshellarg($path)); }
		$this->cli->successMsg("Opened {$path}");
	}

	public function resurrect(string $prefix): void {
		$t = $this->resolveTombstone($prefix);
		if (!$t) { $this->cli->exitErr("No single tombstone matches '{$prefix}'."); }
		$path = $this->transcriptPath($t['session_id']);
		if (!is_file($path)) { $this->cli->exitErr("Transcript missing: {$path}"); }

		$title = $t['workspace_title'] ?: 'resurrected';
		$cwd   = $t['cwd'] ?? '';
		$ws = $this->cmux->newWorkspace($title, $cwd ?: null);
		$surfRef = $ws['firstSurfRef'];
		$wsRef   = $ws['ref'];

		// Launch a FRESH claude (no --resume), then tell it to read the transcript.
		$launch = 'claude';
		if (!empty($t['skip_perms'])) { $launch .= ' --dangerously-skip-permissions'; }
		if (!empty($t['model']))      { $launch .= ' --model=' . $t['model']; }
		$this->cmux->sendToSurface($surfRef, $wsRef, $launch . "\n");
		sleep(3); // let the REPL come up

		$preamble = 'Resuming a buried session. Read ' . $path
			. ' — that is a transcript of where we left off. Re-orient from it, then continue.';
		$this->cmux->sendToSurface($surfRef, $wsRef, $preamble . "\n");
		$this->cli->successMsg("Resurrected '{$title}' in {$wsRef} — Claude is reading the transcript.");
	}

	public function buryByRef(string $ref, bool $force, bool $autoConfirm): void {
		$self = $this->selfSurfaceId();
		foreach ($this->liveSessions() as $s) {
			if ($s['surface_ref'] === $ref || ($s['surface_id'] ?? '') === $ref) {
				if ($self && ($s['surface_ref'] === $self || ($s['surface_id'] ?? '') === $self)) {
					$this->cli->exitErr('Refusing to bury the caller\'s own session.');
				}
				$this->buryOne($s, $force, $autoConfirm);
				return;
			}
		}
		$this->cli->exitErr("No live claude session found at surface '{$ref}'.");
	}

	public function buryIdle(int $thresholdSecs, bool $autoConfirm): void {
		$self = $this->selfSurfaceId();
		$sessions = $this->filterSelf($this->liveSessions(), $self, null);
		$stale = array_values(array_filter($sessions, fn($s) => $s['idle_seconds'] >= $thresholdSecs));
		if (!$stale) { $this->cli->msg('No sessions idle past the threshold.', 'yellow'); return; }

		$this->cli->msg('Sessions to bury (idle >= ' . $thresholdSecs . 's):', 'yellow');
		foreach ($stale as $s) {
			$this->cli->msg(sprintf('  %s  idle=%ds  %-20.20s  %.40s',
				substr($s['session_id'], 0, 8), $s['idle_seconds'], $s['workspace_title'], $s['tab_title']));
		}
		if (!$autoConfirm && !$this->cli->confirm('Bury these ' . count($stale) . ' session(s)?')) {
			$this->cli->msg('Aborted.', 'yellow'); return;
		}
		$n = 0;
		foreach ($stale as $s) { if ($this->buryOne($s, false, true)) { $n++; } }
		$this->cli->successMsg("Buried {$n} of " . count($stale) . ' session(s).');
	}
```

`Cmux::newWorkspace()` was added in Task 1 (interface completeness) — `resurrect()` consumes it; nothing to add here.

- [ ] **Step 2: Create the executable `bin/graveyard`**

```php
#!/usr/bin/env php
<?php
namespace JT;
# =============================================================================
# graveyard — demote, browse, and resurrect Claude Code sessions via cmux.
# By Justin Sternberg <me@jtsternberg.com>
#
# Version 0.1.0
#
# Buries idle Claude sessions to ~/.claude-graveyard/ (rendered transcript +
# metadata) and frees their RAM, then lists / opens / resurrects them.
#
# Usage:
# graveyard bury <surface-ref> [--force] [-y]
# graveyard bury --idle <dur> [-y]
# graveyard ls
# graveyard show <id>
# graveyard resurrect <id>
# =============================================================================

$cli = require_once dirname(__DIR__) . '/misc/helpers.php';
require_once dirname(__DIR__) . '/misc/helpers/cmux.php';
require_once __DIR__ . '/graveyard_lib.php';

$helpyHelperton = $cli->getHelp();
$helpyHelperton
	->setScriptName('graveyard')
	->setDescription('Demote, browse, and resurrect Claude Code sessions via cmux.')
	->setSampleUsage('<bury|ls|show|resurrect> [args]')
	->buildDocs([
		'bury <ref>'      => 'Bury a live session by its cmux surface ref',
		'bury --idle <d>' => 'Bury all sessions idle longer than <d> (e.g. 2d, 48h, 90m)',
		'--force'         => 'Bury even if the session looks busy (bury <ref> only)',
		'-y'              => 'Skip confirmations',
		'ls'              => 'List buried sessions',
		'show <id>'       => 'Open a buried transcript in your editor',
		'resurrect <id>'  => 'Recreate the workspace and restart Claude on the transcript',
	]);

if ($helpyHelperton->batSignal) {
	$cli->msg($helpyHelperton->getHelp());
	exit(0);
}

$cmux = new Helpers\Cmux($cli);
if (!$cmux->ping()) {
	$cli->exitErr('cmux is not reachable. Is cmux running?');
}

$gy  = new Graveyard($cli, $cmux);
$sub = $cli->getArg(1);

switch ($sub) {
	case 'bury':
		if ($cli->hasFlag('idle')) {
			$gy->buryIdle($gy->parseDuration((string) $cli->getFlag('idle')), $cli->isAutoconfirm());
		} else {
			$ref = $cli->getArg(2);
			if (!$ref) { $cli->exitErr('Usage: graveyard bury <surface-ref> | --idle <dur>'); }
			$gy->buryByRef($ref, $cli->hasFlag('force'), $cli->isAutoconfirm());
		}
		break;
	case 'ls':        $gy->listTombstones(); break;
	case 'show':      $gy->showTombstone((string) $cli->getArg(2)); break;
	case 'resurrect': $gy->resurrect((string) $cli->getArg(2)); break;
	default:
		$cli->msg($helpyHelperton->getHelp());
		exit($sub ? 1 : 0);
}

exit(0);
```

Make executable:

```bash
chmod +x bin/graveyard
```

- [ ] **Step 3: Verify help + routing**

Run: `php bin/graveyard --help` and `php bin/graveyard ls`
Expected: help renders with all subcommands; `ls` prints `Graveyard is empty.` (fresh) or the table.

- [ ] **Step 4: End-to-end manual test**

With a throwaway idle session open (see Task 5 Step 3):

```bash
php bin/graveyard bury --idle 15s     # preview + confirm; buries the throwaway (not self)
php bin/graveyard ls                   # shows the new tombstone
php bin/graveyard show <id-prefix>     # opens transcript.txt in VS Code
php bin/graveyard resurrect <id-prefix># new "…" workspace, fresh claude reads the transcript
```

Expected: self session never appears in the `--idle` preview; throwaway is buried, its tab closes, RAM drops; `ls`/`show` work; resurrect opens a new workspace with the right cwd and a fresh claude that reads the transcript file.

- [ ] **Step 5: Commit**

```bash
git add bin/graveyard bin/graveyard_lib.php misc/helpers/cmux.php
git commit -m "feat(graveyard): CLI wiring for bury/ls/show/resurrect"
```

---

## Task 7: Symlink, gitignore guard, and bd close

**Files:**
- Modify: `.gitignore` (comment noting the store lives in `$HOME`, not the repo)
- Run: `php symdotfiles` is not needed (bin/ is already on PATH via the repo); confirm `graveyard` resolves.

- [ ] **Step 1: Confirm `graveyard` is invocable on PATH**

Run: `which graveyard || echo 'not on PATH'`
Expected: resolves to `bin/graveyard` (bin/ is symlinked/PATH-managed by this repo). If not, note the existing mechanism the repo uses for `cmux-bak` and match it.

- [ ] **Step 2: Add a gitignore guard comment**

The store is `~/.claude-graveyard/` (outside the repo), so nothing to ignore in-repo. Add a comment so future contributors don't add the store to the repo:

```bash
printf '\n# graveyard store lives in ~/.claude-graveyard (never commit transcripts)\n' >> .gitignore
```

- [ ] **Step 3: Run the full pure-logic suite once more**

Run: `php tests/graveyard/run.php`
Expected: all assertions pass.

- [ ] **Step 4: Commit + close bd**

```bash
git add .gitignore
git commit -m "chore(graveyard): gitignore guard for local store"
bd close dotfiles-7ds --reason "Phase 1 shipped: bury/ls/show/resurrect + shared Cmux helper"
```

---

## Deferred to Phase 2 (out of scope here)

Interactive picker with drill-in `read-screen` preview; `graveyard page` (HTML overview); `graveyard rm`; cold-tombstone rendering for already-dead sessions (relaunch in a pty → `/export`); MemPalace mining of the store.

---

## Self-Review Notes

- **Spec coverage:** store layout (Task 3), preserve `/export` text (Task 5), read-the-transcript resurrect (Task 6), `/export` live-only via cmux send (Task 5), shared-module extraction + cmux-bak refactor (Tasks 1–2), idle gate two-part check (Task 4/5), self-bury guard (Tasks 4/6), re-bury temp+rename (Task 5), compaction caveat (surfaced to user; behavioral, no code), phase-2 items explicitly deferred. All present.
- **Idle-gate marker set** (`ACTIVE_TURN_RE`) is a best-effort starting list; Task 5 Step 3 manual test is where it gets validated/tuned against a real running turn — adjust the regex there if a live turn slips through.
- **Types:** `liveSessions()` rows carry `surface_ref`/`surface_id`/`workspace_ref` consumed identically by `filterSelf`, `buryByRef`, `buryIdle`, `buryOne`. `resolveTombstone` returns a tombstone array used by `show`/`resurrect`. Consistent.
