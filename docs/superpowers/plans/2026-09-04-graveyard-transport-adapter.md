# Graveyard Transport Adapter (cmux + herdr) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `graveyard` discover, bury, and resurrect agent sessions running under herdr as well as cmux, behind one `SessionTransport` seam.

**Architecture:** `Graveyard` currently depends on the concrete `Helpers\Cmux`, which is simultaneously the cmux client, the OS process-table library, and the Claude/Codex on-disk artifact library. Split those three responsibilities apart (`Proc`, `AgentArtifacts`, cmux), define `Transport\SessionTransport` whose primary method is the already-normalized `liveSessions()` row shape, reimplement it for herdr off a single `herdr api snapshot` call, and select the transport at the `bin/graveyard` entry seam.

**Tech Stack:** PHP 8.x, PSR-4 (`JT\` → `src/`), PHPUnit 9/10, cmux CLI, herdr CLI ≥ 0.8.2 (protocol 20).

## Global Constraints

- Branch: `graveyard-transport-adapter`. Commit directly to it; no worktree. Merge to `master` at the end.
- Baseline to preserve: `composer test` = **938 tests, 2545 assertions, OK** (recorded 2026-09-04, master @ a82dfad). Every task ends green at ≥ this count.
- Tasks 1–3 are **strictly non-functional**. No behavior change, no new verbs, no output change. Task 1–3 completion is proven by the unchanged suite passing, not by new features.
- `Helpers\Cmux` keeps its **entire current public API**. `src/CmuxBak.php` / `bin/cmux-bak` call 22 of its methods and are out of scope for this work — they must not be touched. Where a method's body moves out, `Cmux` keeps a one-line forwarder.
- Shelling seams get env overrides so no test reaches a real binary: `CMUX_BIN` (exists), `HERDR_BIN` (new), mirroring `GODO_DIRMAP_BIN`. See CLAUDE.md's shelling-seam rule.
- Liveness is annotated in exactly one place — `Graveyard::tombstones()` via `liveSessions()`. Two transports means `liveSessions()` returns the **union**. Nothing may annotate liveness at a call site (see the docblock at `src/Graveyard.php:4020-4040` for the bug this prevents).
- Manual verification runs against the real store (`~/.claude-graveyard`) per `.claude/skills/verify`. Invoke as `./bin/graveyard` from the repo root — bare `graveyard` on `$PATH` resolves to `/Users/JT/.dotfiles/bin/graveyard`, which is this same checkout, so on this branch it *is* the code under test. Confirm with `git branch --show-current` before trusting a manual run.
- Zsh completion (`zsh-custom/plugins/dotfiles-completions/`) and the `agent-skills/graveyard/SKILL.md` skill ship in the same task as any user-visible flag change (CLAUDE.md: coupled interface surfaces move together).

---

### Task 1: Extract `Helpers\Proc` — OS process/tty/lsof primitives

Nothing in these methods knows what cmux is. They are `ps`, `lsof`, tty and pid plumbing that both transports need.

**Files:**
- Create: `src/Helpers/Proc.php`
- Modify: `src/Helpers/Cmux.php` (move bodies out, leave forwarders)
- Create: `tests/Helpers/ProcTest.php`
- Modify: `tests/Helpers/CmuxTest.php` (only if a test names a moved method directly)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `JT\Helpers\Proc`, constructed as `new Proc($cli)`, with these 11 public methods moved verbatim from `Cmux` (same signatures, same behavior):
  `pidIsAlive(int $pid)`, `getTtyForPid(int $pid)`, `getCwdForTty(string $tty)`, `psProcTable(): string`, `parseProcTable(string $raw): array`, `childIndex(array $proc): array`, `descendantPids(array $proc, int $root): array`, `pidCommand(int $pid): string`, `pidEnv(int $pid): string`, `lsofForPid(int $pid): string`, `parseLsofCwd(string $raw): ?string`.

**The dividing rule:** no method in `Proc` may mention cmux, claude or codex. That puts three would-be candidates elsewhere — `descendantClaudePid`, `isClaudeCommand` and `ancestorResumeScript` recognise an agent, so they go to `AgentArtifacts` in Task 2 (calling `Proc::childIndex`); the lsof *rollout* parsers know codex, same destination; and `parseSurfaceIdFromEnv` reads `CMUX_SURFACE_ID`, so it stays in `Cmux`.

`parseProcTable` returns rows keyed `['ppid' => int, 'cmd' => string]` — `cmd`, not `command`.

- [ ] **Step 1: Write the failing test**

`tests/Helpers/ProcTest.php`:

```php
<?php
namespace JT\Tests\Helpers;

use JT\Helpers\Proc;
use JT\Tests\TestCase;

final class ProcTest extends TestCase
{
	private function proc(): Proc { return new Proc($this->cli); }

	public function test_parseProcTable_indexes_by_pid_with_ppid_and_command(): void
	{
		$raw = "  PID  PPID COMMAND\n"
			 . "  100     1 /bin/zsh\n"
			 . "  200   100 claude --session-id abc --model opus\n";
		$out = $this->proc()->parseProcTable($raw);

		$this->assertArrayHasKey(200, $out);
		$this->assertSame(100, $out[200]['ppid']);
		$this->assertSame('claude --session-id abc --model opus', $out[200]['cmd']);
	}

	public function test_descendantPids_walks_the_tree_including_root(): void
	{
		$proc = [
			100 => ['ppid' => 1,   'command' => 'zsh'],
			200 => ['ppid' => 100, 'command' => 'claude'],
			300 => ['ppid' => 200, 'command' => 'node mcp'],
		];
		$out = $this->proc()->descendantPids($proc, 100);
		sort($out);
		$this->assertSame([100, 200, 300], $out);
	}

	public function test_parseSurfaceIdFromEnv_reads_CMUX_SURFACE_ID(): void
	{
		$raw = "PATH=/usr/bin\0CMUX_SURFACE_ID=surf-uuid-1\0TERM=xterm\0";
		$this->assertSame('surf-uuid-1', $this->proc()->parseSurfaceIdFromEnv($raw));
	}
}
```

Assertions must match the real bodies. Before writing the test, read each method being moved in `src/Helpers/Cmux.php` (`parseProcTable` at :199, `descendantPids` at :241, `parseSurfaceIdFromEnv` at :565) and copy the exact shape it returns — do not guess key names. Lift the corresponding existing assertions out of `tests/Helpers/CmuxTest.php` where they already cover these.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Helpers/ProcTest.php`
Expected: FAIL — `Class "JT\Helpers\Proc" not found`.

- [ ] **Step 3: Create `Proc` and move the bodies**

Create `src/Helpers/Proc.php`:

```php
<?php
namespace JT\Helpers;

/**
 * OS-level process, tty and lsof primitives. Deliberately knows nothing about any
 * terminal multiplexer: cmux and herdr both need to ask "is this pid alive", "what
 * is its argv", "what rollout file does it hold open". Extracted from Cmux so a
 * second transport does not inherit a cmux dependency to reach `ps`.
 */
class Proc
{
	protected $cli;

	public function __construct($cli) { $this->cli = $cli; }

	// ... the 18 methods listed in Interfaces, moved verbatim from Cmux ...
}
```

Then in `src/Helpers/Cmux.php`, add a `Proc` to the constructor and replace each moved body with a forwarder. `Cmux::__construct` becomes:

```php
	protected Proc $proc;

	public function __construct($cli, bool $dryRun = false, ?Proc $proc = null) {
		$this->cli    = $cli;
		$this->dryRun = $dryRun;
		$this->proc   = $proc ?: new Proc($cli);
	}

	/** @deprecated Forwarder for cmux-bak; new code takes Proc directly. */
	public function psProcTable(): string { return $this->proc->psProcTable(); }
	public function parseProcTable(string $raw): array { return $this->proc->parseProcTable($raw); }
	// ... one forwarder per moved method ...
```

The optional third constructor arg keeps every existing `new Helpers\Cmux($cli)` call site (`bin/graveyard:84`, `bin/cmux-bak`, `NullCmux`) working untouched.

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: `OK (938+ tests, ...)`. Any failure here is a bad move, not a bad test — the suite did not change behavior.

- [ ] **Step 5: Commit**

```bash
git add src/Helpers/Proc.php src/Helpers/Cmux.php tests/Helpers/ProcTest.php
git commit -m "refactor(cmux): extract OS process primitives into Helpers\\Proc

Cmux was three libraries in one trench coat: a cmux client, a ps/lsof
wrapper, and a Claude/Codex artifact reader. A second transport needs the
middle one without inheriting the first. Cmux keeps forwarders so cmux-bak
is untouched.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011a4MeZhQZGv4kapaPDfJVj"
```

---

### Task 2: Extract `Helpers\AgentArtifacts` — Claude/Codex on-disk + argv semantics

Session JSONL paths, codex rollouts, resume-command construction, argv sniffing. Also multiplexer-agnostic, and the reason `graveyard`'s archive is portable across transports.

**Files:**
- Create: `src/Helpers/AgentArtifacts.php`
- Modify: `src/Helpers/Cmux.php` (forwarders)
- Create: `tests/Helpers/AgentArtifactsTest.php`

**Interfaces:**
- Consumes: `JT\Helpers\Proc` from Task 1 (for `pidCommand`, `pidEnv`, `lsofForPid`).
- Produces: `JT\Helpers\AgentArtifacts`, constructed as `new AgentArtifacts($cli, ?Proc $proc = null)`, with these moved verbatim from `Cmux`:
  `encodeProjectKey(string $cwd): string`, `claudeSessionsDir(): string`, `jsonlPathFor(string $sessionId, string $cwd): string`, `loadClaudeSessions(): array`, `codexSessionsDir(): string`, `codexRolloutPathFor(string $sessionId): ?string`, `codexRolloutContext(string $rolloutPath): array`, `codexSessionCwd(string $rolloutPath): ?string`, `rolloutUuidFromPath(string $path): ?string`, `readSessionJsonl(?string $sessionId, ?string $cwd): array`, `isSyntheticEntry(array $entry): bool`, `lastRealActivity(string $sessionId, string $cwd): ?int`, `transcriptPathFor(string $agent, string $sessionId, string $cwd): ?string`, `isClaudeCommand(string $cmd): bool`, `isCodexCommand(string $cmd): bool`, `codexSubcommand(string $cmd): ?string`, `isCodexNonTuiCommand(string $cmd): bool`, `codexProcPids(array $proc): array`, `claudeResumeArg(string $cmd): ?string`, `ancestorResumeScript(array $proc, int $pid): ?string`, `cmdHasSkipPerms(string $cmd): bool`, `cmdModelArg(string $cmd): ?string`, `resolveModel(?string $jsonlModel, ?int $pid): ?string`, `resolveSkipPerms(?string $permissionMode, ?int $pid): bool`, `buildResumeCommand(string $sessionId, bool $skipPerms, ?string $model): string`, `buildCodexResumeCommand(string $sessionId, ?string $model, array $opts = []): string`, `buildAgentResumeCommand(string $agent, string $sessionId, bool $skipPerms = false, ?string $model = null, array $opts = []): string`, `uuidv4(): string`, `normalizeTitle(string $title): string`.
  Also add, moved from `Graveyard`: `dedupBySessionId(array $rows): array` and `codexLastActivity(string $rolloutPath): ?int`.

- [ ] **Step 1: Write the failing test**

`tests/Helpers/AgentArtifactsTest.php`:

```php
<?php
namespace JT\Tests\Helpers;

use JT\Helpers\AgentArtifacts;
use JT\Tests\TestCase;

final class AgentArtifactsTest extends TestCase
{
	private function art(): AgentArtifacts { return new AgentArtifacts($this->cli); }

	public function test_encodeProjectKey_slugs_the_cwd(): void
	{
		$this->assertSame(
			'-Users-JT--dotfiles',
			$this->art()->encodeProjectKey('/Users/JT/.dotfiles')
		);
	}

	public function test_buildAgentResumeCommand_claude_carries_model_and_skip_perms(): void
	{
		$cmd = $this->art()->buildAgentResumeCommand('claude', 'abc-123', true, 'opus');
		$this->assertStringContainsString('--resume abc-123', $cmd);
		$this->assertStringContainsString('--model opus', $cmd);
		$this->assertStringContainsString('--dangerously-skip-permissions', $cmd);
	}

	public function test_dedupBySessionId_keeps_the_first_row_per_session(): void
	{
		$rows = [
			['session_id' => 'a', 'surface_ref' => 's1'],
			['session_id' => 'a', 'surface_ref' => 's2'],
			['session_id' => 'b', 'surface_ref' => 's3'],
		];
		$out = $this->art()->dedupBySessionId($rows);
		$this->assertCount(2, $out);
		$this->assertSame('s1', $out[0]['surface_ref']);
	}
}
```

Read `Cmux::encodeProjectKey` (:76) and `Cmux::buildAgentResumeCommand` (:796) first and match their exact output; `-Users-JT--dotfiles` above is the expected Claude project-key encoding but must be confirmed against the real body, and `dedupBySessionId`'s tie-break must be confirmed against `Graveyard`'s current implementation before asserting "first wins".

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Helpers/AgentArtifactsTest.php`
Expected: FAIL — `Class "JT\Helpers\AgentArtifacts" not found`.

- [ ] **Step 3: Create `AgentArtifacts` and move the bodies**

Same pattern as Task 1: move verbatim, add `?AgentArtifacts $artifacts = null` as a fourth optional `Cmux` constructor arg, leave a forwarder for every moved method. In `Graveyard`, replace `dedupBySessionId`/`codexLastActivity` bodies with calls into the injected artifacts instance (added in Task 3) — until Task 3 lands, `Graveyard` reaches them through `$this->cmux->` forwarders so nothing breaks mid-refactor.

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: `OK (941+ tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Helpers/AgentArtifacts.php src/Helpers/Cmux.php src/Graveyard.php tests/Helpers/AgentArtifactsTest.php
git commit -m "refactor(cmux): extract Claude/Codex artifact reads into AgentArtifacts

Session JSONL paths, codex rollouts, resume-command construction and argv
sniffing have nothing to do with cmux — they are why a graveyard tombstone
is portable across transports in the first place.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011a4MeZhQZGv4kapaPDfJVj"
```

---

### Task 3: Define `Transport\SessionTransport` and move the cmux join into `CmuxTransport`

> **REVISED 2026-09-04, after Tasks 1–2 landed.** The original sizing assumed the
> cmux join was confined to `liveSessions()`. It is not. Measured: **681 lines
> across 12 `Graveyard` methods** reason directly in cmux shapes — `liveSessions`
> (78), `treeIndex` (41), `bindUnresolvedByContentProbe` (45),
> `liveCodexBySurfaceRef` (14), `liveCodexSurfaceRefs` (15),
> `diagnoseUntargetableSurface` (66), `buryPane` (34), `buryWorkspace` (48),
> `buildBuryClassification` (97), `buryClassifiedAsGroup` (161), `resurrect` (69),
> `resolveTargetWindow` (13). `tree()` alone is called from eight of them.
>
> That kills the "just wrap `Cmux`" version of this task. Exposing `tree()`,
> `debugTerminals()` and the `join*` primitives on the interface would force
> `HerdrTransport` to fake a cmux debug-terminals dump — the seam would leak the
> incumbent's data model into the abstraction and herdr would implement cmux, not
> the contract.
>
> **The interface rises an altitude instead.** Out: `tree`, `debugTerminals`,
> `parseDebugTerminals`, `mapSurfaceUuids`, `joinSessionsToSurfaces`,
> `joinCodexToSurfaces`, `sessionIdForPid`, `codexSurfaceIdsByPid`. In: one method
> that answers the question all 12 call sites are actually asking —
>
> ```php
> /**
>  * Every surface in a workspace, ordered, with whatever agent session is bound
>  * to each. The raw material for bury classification and layout capture.
>  *
>  * @return list<array{position:int, surface_ref:string, surface_id:string,
>  *   type:string, title:string, session_id:?string, agent:?string,
>  *   cwd:?string, pid:?int, targetable:bool, reason:?string}>
>  */
> public function workspaceSurfaces(string $workspaceRef): array;
> ```
>
> cmux answers it from `tree` + `debug-terminals` + the ancestry/env joins; herdr
> answers it from one `api snapshot` (`panes[]` filtered by `workspace_id`, with
> `agents[]` supplying the binding). The *policy* — which surfaces are members,
> which are untargetable, what a group manifest records — stays in `Graveyard`,
> transport-free. `type` is where F5 shows up: cmux emits `terminal`/`browser`/
> `markdown`, herdr only ever `terminal`.
>
> **Split into three commits**, each green on its own:
>
> - **3a** — interface + `CmuxTransport` + `NullTransport`, with `liveSessions`,
>   `treeIndex` and `bindUnresolvedByContentProbe` moved in and the drive/create
>   verbs repointed. Leaves `workspaceSurfaces()` unimplemented and the bury
>   classification untouched, still reaching cmux through a temporary
>   `CmuxTransport::cmux()` escape hatch.
> - **3b** — define and implement `workspaceSurfaces()` on `CmuxTransport`, then
>   re-seat `buildBuryClassification`, `buryClassifiedAsGroup`, `buryWorkspace`,
>   `buryPane` and `diagnoseUntargetableSurface` onto it. Pin with the existing
>   `GraveyardBuryGroupTargetTest` / `GraveyardLaunchSafetyTest` fixtures.
> - **3c** — re-seat `resurrect` + `resolveTargetWindow`, delete the escape hatch,
>   and assert in a test that `SessionTransport` has no cmux-shaped method left
>   (no `tree`, no `debugTerminals`, no `join*`) so the leak cannot come back.
>
> Task 4 (`Helpers\Herdr`) has no dependency on any of 3a–3c and can be built in
> parallel or first; Task 5 needs 3c.


The seam. `liveSessions()` already returns a normalized row shape — that row shape *is* the interface contract, and the entire ps/lsof/debug-terminals/content-probe join that produces it is cmux-specific, so it moves out of `Graveyard` and into the cmux implementation.

**Files:**
- Create: `src/Transport/SessionTransport.php` (interface)
- Create: `src/Transport/CmuxTransport.php`
- Create: `src/Transport/NullTransport.php`
- Modify: `src/Graveyard.php` (constructor + all 96 `$this->cmux->` call sites)
- Modify: `bin/graveyard` (:84-89), `bin/graveyard_router.php` (:23)
- Delete: `src/Helpers/NullCmux.php`, `tests/Helpers/NullCmuxTest.php` (superseded by `NullTransport`)
- Create: `tests/Transport/SessionTransportContractTest.php`
- Modify: `tests/Graveyard/*` where a test injects a cmux double

**Interfaces:**
- Consumes: `Proc` (Task 1), `AgentArtifacts` (Task 2).
- Produces:

```php
<?php
namespace JT\Transport;

/**
 * One live-session transport (a terminal multiplexer graveyard can drive).
 *
 * liveSessions() is the contract: its row shape is what every graveyard verb
 * consumes, and it is already transport-neutral. `surface_ref`/`workspace_ref`
 * are opaque handles — cmux positional refs, herdr `wF:p3` pane ids — and no
 * caller may parse them. Each row carries `transport` so a caller holding a row
 * knows which implementation to send it back to.
 */
interface SessionTransport
{
	/** Stable short name: 'cmux' | 'herdr' | 'null'. Stamped into every row. */
	public function name(): string;

	/** Is this transport running and reachable right now? Must not throw. */
	public function available(): bool;

	/**
	 * Live agent sessions this transport hosts.
	 *
	 * @return list<array{
	 *   transport:string, session_id:string, agent:string, cwd:string,
	 *   model:?string, skip_perms:bool, opts:array, pid:?int, tty:?string,
	 *   surface_ref:string, surface_id:string, workspace_ref:string,
	 *   window_ref:?string, pane_ref:?string, home_workspace_id:?string,
	 *   home_pane_id:?string, home_index_in_pane:?int, workspace_title:string,
	 *   tab_title:string, idle_seconds:int, targetable:bool, reason:?string,
	 *   no_bridge:bool
	 * }>
	 */
	public function liveSessions(): array;

	// --- drive an existing surface (bury: /export, /status, screen probes) ---
	public function sendText(string $surfaceRef, string $workspaceRef, string $text): void;
	public function sendKey(string $surfaceRef, string $workspaceRef, string $key): void;
	public function readScreen(string $surfaceRef, string $workspaceRef): string;

	// --- describe / resolve, for confirmation prompts and fuzzy targeting ---
	public function describeWorkspace(string $handle, string $fallbackTitle = ''): string;
	public function resolveWorkspace(string $nameOrRef): ?array;
	public function workspaceSurfaceCount(string $workspaceRef): int;

	// --- create, for resurrect ---
	public function newWorkspace(string $title, ?string $cwd, ?string $windowRef = null): ?array;
	public function newSurface(string $workspaceRef, ?string $paneRef, string $type, ?string $command): ?string;
	public function newSplit(string $workspaceRef, string $fromSurfaceRef, string $direction): ?string;
	public function selectSurface(string $workspaceRef, string $surfaceRef): bool;
	public function paneRefForSurface(string $workspaceRef, string $surfaceRef): ?string;

	// --- teardown ---
	public function closeWorkspace(string $workspaceRef): array;

	/**
	 * Can this transport host non-terminal surfaces (browser, markdown viewers)?
	 * cmux: true. herdr: false — panes are terminals only, so a grouped restore
	 * under herdr drops them and must confirm first (see Task 6).
	 */
	public function supportsNonTerminalSurfaces(): bool;

	/** Captured layout tree for a grouped bury, or null if unsupported. */
	public function captureLayoutTree(string $workspaceRef, string $namePrefix = 'gy-capture'): ?array;
	public function newWorkspaceWithLayout(string $title, ?string $cwd, array $layoutTree, ?string $windowRef = null): ?array;
	public function sanitizeLayoutTree(array $node, array $dropSurfaceKeys = ['command']): array;
	public function layoutTreeSurfaceCount(array $node): int;
}
```

`Graveyard::__construct` becomes `__construct($cli, Transport\SessionTransport $transport, ?Helpers\AgentArtifacts $artifacts = null)`, and `$this->cmux` is renamed `$this->transport` throughout. Methods that move from `Graveyard` into `CmuxTransport`: `liveSessions()` (:611), `treeIndex()` (:687), `bindUnresolvedByContentProbe()`.

Note: `Cmux` has both `newWorkspace(): array` (throws on failure) and `newWorkspaceOrNull(): ?array`. The interface exposes **only** the nullable form — `CmuxTransport::newWorkspace()` wraps `Cmux::newWorkspaceOrNull()`, and the two `Graveyard` call sites that used the throwing variant must handle `null` explicitly at the call site instead.

- [ ] **Step 1: Write the failing contract test**

`tests/Transport/SessionTransportContractTest.php` — one test class both implementations will be run through, so herdr inherits cmux's contract in Task 5:

```php
<?php
namespace JT\Tests\Transport;

use JT\Transport\CmuxTransport;
use JT\Transport\NullTransport;
use JT\Transport\SessionTransport;
use JT\Tests\TestCase;

final class SessionTransportContractTest extends TestCase
{
	/** @return list<array{0:SessionTransport}> */
	public static function transports(): array
	{
		return [
			'null' => [new NullTransport(new \JT\CLI\CLI())],
		];
	}

	public function test_cmux_transport_reports_its_name(): void
	{
		$t = new CmuxTransport($this->cli, $this->cmux);
		$this->assertSame('cmux', $t->name());
	}

	public function test_cmux_transport_supports_non_terminal_surfaces(): void
	{
		$t = new CmuxTransport($this->cli, $this->cmux);
		$this->assertTrue($t->supportsNonTerminalSurfaces());
	}

	public function test_live_session_rows_are_stamped_with_their_transport(): void
	{
		// CMUX_BIN points at the stub tree fixture; see tests/Helpers/CmuxTest.php
		// for how the stub is written and exported.
		$t    = new CmuxTransport($this->cli, $this->cmux);
		$rows = $t->liveSessions();
		foreach ($rows as $row) {
			$this->assertSame('cmux', $row['transport']);
		}
	}

	public function test_null_transport_reports_nothing_live_and_refuses_to_drive(): void
	{
		$t = new NullTransport($this->cli);
		$this->assertSame([], $t->liveSessions());
		$this->expectException(\LogicException::class);
		$t->sendText('s1', 'w1', 'hello');
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Transport/SessionTransportContractTest.php`
Expected: FAIL — `Class "JT\Transport\CmuxTransport" not found`.

- [ ] **Step 3: Implement the interface, `CmuxTransport`, `NullTransport`**

`CmuxTransport` wraps `Helpers\Cmux` (composition, not inheritance) and holds the join code moved out of `Graveyard`. Every `liveSessions()` row gains `'transport' => 'cmux'`. `NullTransport` replaces `NullCmux`: read-only methods answer empty, every drive/create method throws `LogicException` with the message `"{$method} is unavailable in the transport-free page server."`.

Then rename `$this->cmux` → `$this->transport` across `src/Graveyard.php` and repoint each call site at its interface method (`sendToSurface` → `sendText`, `createSurface` → `newSurface`, `resolveWorkspaceNode` → `resolveWorkspace`). Artifact calls (`jsonlPathFor`, `codexRolloutPathFor`, `readSessionJsonl`, `lastRealActivity`, `buildAgentResumeCommand`, `isSyntheticEntry`, `normalizeTitle`, `uuidv4`) go to `$this->artifacts->` instead — they are not transport concerns and must not appear on the interface.

`bin/graveyard:84-89` becomes:

```php
$cmux      = new Helpers\Cmux($cli);
$transport = new Transport\CmuxTransport($cli, $cmux);
if (!in_array($sub, $storeOnlyVerbs, true) && !$transport->available()) {
	$cli->exitErr('cmux is not reachable. Is cmux running?');
}
$gy = new Graveyard($cli, $transport);
```

`bin/graveyard_router.php:23` becomes `new Graveyard($cli, new Transport\NullTransport($cli))`.

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: `OK`, count ≥ 941. This is the highest-risk task in the plan; a red suite here means a mis-repointed call site. Diff review before commit: `git diff --stat` should show `src/Graveyard.php` with ~96 changed lines and no logic changes.

- [ ] **Step 5: Manual smoke against the real store**

```bash
git branch --show-current   # must print graveyard-transport-adapter
./bin/graveyard ls | head -20
./bin/graveyard live
```

Expected: identical output to `master`. Capture both and diff:

```bash
git stash && ./bin/graveyard ls > /tmp/gy-master.txt; git stash pop
./bin/graveyard ls > /tmp/gy-branch.txt
diff /tmp/gy-master.txt /tmp/gy-branch.txt   # expected: no output
```

- [ ] **Step 6: Commit**

```bash
git add src/Transport tests/Transport src/Graveyard.php bin/graveyard bin/graveyard_router.php
git rm src/Helpers/NullCmux.php tests/Helpers/NullCmuxTest.php
git commit -m "refactor(graveyard): introduce SessionTransport, cmux behind it

liveSessions()'s row shape was already transport-neutral; the ps/lsof/
debug-terminals join that builds it was not, so it moves into the cmux
implementation. No behavior change — same 938-test suite, and \`ls\` output
diffs clean against master.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011a4MeZhQZGv4kapaPDfJVj"
```

---

### Task 4: `Helpers\Herdr` — a thin, testable herdr CLI client

**Files:**
- Create: `src/Helpers/Herdr.php`
- Create: `tests/Helpers/HerdrTest.php`
- Create: `tests/fixtures/herdr/snapshot-two-claude-one-codex.json`
- Create: `tests/fixtures/herdr/stub-herdr` (executable stub, mirrors the `CMUX_BIN` stub pattern)

**Interfaces:**
- Consumes: `Proc` (Task 1).
- Produces: `JT\Helpers\Herdr`, `new Herdr($cli)`, with:
  `herdrBin(): string` (returns `getenv('HERDR_BIN') ?: 'herdr'`),
  `available(): bool` (`herdr status` reports a running, compatible server),
  `snapshot(): array` (decoded `.result.snapshot` from `herdr api snapshot`; throws `RuntimeException` on unreachable/unparseable, never `exit()` — the shelling-seam rule),
  `paneProcessInfo(string $paneId): array` (decoded `.result.process_info` from `herdr pane process-info --pane <id>`),
  `paneRead(string $paneId, string $source = 'recent', ?int $lines = null): string`,
  `paneSendText(string $paneId, string $text): void`,
  `paneSendKeys(string $paneId, string $key): void`,
  `paneRun(string $paneId, string $command): void`,
  `paneSplit(string $paneId, string $direction): ?string`,
  `paneClose(string $paneId): void`,
  `workspaceCreate(string $label, ?string $cwd, array $env = []): ?array` (returns the decoded workspace incl. `root_pane.pane_id`),
  `workspaceClose(string $workspaceId): array`,
  `workspaceFocus(string $workspaceId): bool`.

Every method shells out through `herdrBin()` and `escapeshellarg`, decodes JSON, and returns arrays. It does no joining and no interpretation — that is Task 5.

- [ ] **Step 1: Write the fixture**

`tests/fixtures/herdr/snapshot-two-claude-one-codex.json` — a trimmed real snapshot. Capture the live shape first with `herdr api snapshot > /tmp/snap.json`, then hand-edit it down to three agents (two claude, one codex), two workspaces, and one non-agent shell pane. It must retain, per agent: `agent`, `agent_session.{agent,kind,source,value}`, `agent_status`, `cwd`, `foreground_cwd`, `pane_id`, `tab_id`, `workspace_id`, `terminal_title`, `terminal_title_stripped`, `interactive_ready`; plus the top-level `panes[]`, `layouts[]` (with `splits[]` carrying `direction`/`ratio`), `workspaces[]`, and `focused_pane_id`.

- [ ] **Step 2: Write the failing test**

`tests/Helpers/HerdrTest.php`:

```php
<?php
namespace JT\Tests\Helpers;

use JT\Helpers\Herdr;
use JT\Tests\TestCase;

final class HerdrTest extends TestCase
{
	private string $stub;

	protected function setUp(): void
	{
		parent::setUp();
		$fixture    = __DIR__ . '/../fixtures/herdr/snapshot-two-claude-one-codex.json';
		$this->stub = sys_get_temp_dir() . '/stub-herdr-' . getmypid();
		file_put_contents($this->stub, "#!/bin/sh\ncat " . escapeshellarg($fixture) . "\n");
		chmod($this->stub, 0755);
		putenv('HERDR_BIN=' . $this->stub);
	}

	protected function tearDown(): void
	{
		putenv('HERDR_BIN');
		@unlink($this->stub);
		parent::tearDown();
	}

	public function test_herdrBin_honours_the_env_override(): void
	{
		$this->assertSame($this->stub, (new Herdr($this->cli))->herdrBin());
	}

	public function test_snapshot_unwraps_the_result_envelope(): void
	{
		$snap = (new Herdr($this->cli))->snapshot();
		$this->assertArrayHasKey('agents', $snap);
		$this->assertArrayNotHasKey('result', $snap);
		$this->assertCount(3, $snap['agents']);
	}

	public function test_snapshot_throws_rather_than_exiting_when_unparseable(): void
	{
		$bad = sys_get_temp_dir() . '/stub-herdr-bad-' . getmypid();
		file_put_contents($bad, "#!/bin/sh\necho 'not json'\n");
		chmod($bad, 0755);
		putenv('HERDR_BIN=' . $bad);

		$this->expectException(\RuntimeException::class);
		try {
			(new Herdr($this->cli))->snapshot();
		} finally {
			@unlink($bad);
		}
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Helpers/HerdrTest.php`
Expected: FAIL — `Class "JT\Helpers\Herdr" not found`.

- [ ] **Step 4: Implement `Herdr`**

```php
<?php
namespace JT\Helpers;

use RuntimeException;

/**
 * Thin herdr CLI client. One shell-out per method, JSON in, arrays out, no
 * interpretation — HerdrTransport does the joining.
 *
 * HERDR_BIN overrides the binary so no test reaches a real herdr server
 * (mirrors CMUX_BIN and Godo's GODO_DIRMAP_BIN; CLAUDE.md's shelling-seam rule).
 * Failures are RuntimeException, never exit() — process-exit plumbing belongs at
 * the bin/ entry seam (dotfiles-3qa).
 */
class Herdr
{
	protected $cli;
	protected Proc $proc;

	public function __construct($cli, ?Proc $proc = null)
	{
		$this->cli  = $cli;
		$this->proc = $proc ?: new Proc($cli);
	}

	public function herdrBin(): string { return getenv('HERDR_BIN') ?: 'herdr'; }

	public function snapshot(): array
	{
		return $this->call(['api', 'snapshot'], 'snapshot');
	}

	public function paneProcessInfo(string $paneId): array
	{
		return $this->call(['pane', 'process-info', '--pane', $paneId], 'process_info');
	}

	/** Shell out, decode, unwrap `.result.<key>`. */
	protected function call(array $argv, string $resultKey): array
	{
		$cmd = escapeshellcmd($this->herdrBin());
		foreach ($argv as $a) { $cmd .= ' ' . escapeshellarg($a); }

		$res = $this->cli->getCommandOutputAndExitCode($cmd . ' 2>/dev/null');
		$raw = is_array($res) ? ($res['stdout'] ?? '') : (string) $res;

		$data = json_decode(trim((string) $raw), true);
		if (!is_array($data) || !isset($data['result'][$resultKey])) {
			throw new RuntimeException("herdr: could not read '{$resultKey}' from `{$argv[0]} {$argv[1]}`.");
		}
		return $data['result'][$resultKey];
	}

	// ... remaining methods from the Interfaces block, each one `call()` ...
}
```

**Corrected against the real code and the live binary while Task 4 was built — take these as given:**

- `$cli->getCommandOutputAndExitCode()` returns `['exitCode' => int, 'error' => string, 'output' => string]` (`src/CLI/Helpers.php:1064`). There is **no `stdout` key**; the sample above originally read one and would have seen `''` on every call. `output`/`error` arrive already `trim()`ed.
- **Keep stderr.** herdr writes `{"error":{"code":…,"message":…}}` to stderr with exit 1, and the CLI helper separates the streams, so `2>/dev/null` would discard the only diagnostic. `Cmux` discards stderr only because `shell_exec` cannot separate it.
- **`herdr api schema --json` is the authority on result-key names** — use it instead of parsing `--help`. It gives `api snapshot`→`snapshot`, `pane process-info`→`process_info`, `pane split`→`pane`, `workspace focus`→`workspace`, and `workspace create`→`workspace`/`tab`/`root_pane` as **siblings** under `result`.
- `pane read` takes a **positional** pane id (not `--pane`), emits **plain text, not JSON**, and its sources are `visible|recent|recent-unwrapped` — `detection` is `agent read` only.
- `pane split --direction` accepts only `right|down`.
- `pane send-text`, `send-keys` and `run` print **nothing** on success — check the exit code, do not require JSON.
- `Herdr` takes **no `Proc`**: every method is a herdr shell-out and nothing in it touches ps/lsof/tty. Constructor is `new Herdr($cli)`.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/Helpers/HerdrTest.php` then `composer test`
Expected: both PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Helpers/Herdr.php tests/Helpers/HerdrTest.php tests/fixtures/herdr
git commit -m "feat(herdr): add thin herdr CLI client behind HERDR_BIN

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011a4MeZhQZGv4kapaPDfJVj"
```

---

### Task 5: `Transport\HerdrTransport` — the adapter

The payoff task, and the small one. cmux forces graveyard to *reconstruct* which session sits in which surface from `ps`, `lsof`, `CMUX_SURFACE_ID` and an on-screen cwd probe. herdr reports it: `agent_session.value` is the session id, sourced from the `herdr:claude` integration hook, alongside `cwd`, `pane_id`, `tab_id`, `workspace_id` and the title — in one snapshot call. `model` and `skip_perms` come from structured `argv` in `pane process-info`, not ps-text scraping.

**Files:**
- Create: `src/Transport/HerdrTransport.php`
- Create: `tests/Transport/HerdrTransportTest.php`
- Modify: `tests/Transport/SessionTransportContractTest.php` (add herdr to the shared contract cases)

**Interfaces:**
- Consumes: `SessionTransport` (Task 3), `Helpers\Herdr` (Task 4), `AgentArtifacts` (Task 2), `Proc` (Task 1).
- Produces: `JT\Transport\HerdrTransport implements SessionTransport`, `new HerdrTransport($cli, Helpers\Herdr $herdr, ?AgentArtifacts $artifacts = null, ?Proc $proc = null)`.

Field mapping, snapshot → `liveSessions()` row:

| row key | herdr source |
|---|---|
| `transport` | literal `'herdr'` |
| `session_id` | `agents[].agent_session.value` (skip the agent when `kind !== 'id'` or the value is empty) |
| `agent` | `agents[].agent` (`claude`, `codex`; skip any other kind) |
| `cwd` | `agents[].cwd` |
| `surface_ref` / `surface_id` | `agents[].pane_id` (herdr pane ids are already stable — no ref/uuid split, so both keys carry it) |
| `workspace_ref` / `home_workspace_id` | `agents[].workspace_id` |
| `window_ref` | `agents[].tab_id` |
| `pane_ref` / `home_pane_id` | `agents[].pane_id` |
| `home_index_in_pane` | `null` (herdr panes hold one terminal) |
| `workspace_title` | matching `workspaces[].label`, else `agents[].workspace_id` |
| `tab_title` | `agents[].terminal_title_stripped` |
| `pid` | `paneProcessInfo(pane_id).foreground_processes[]` — the entry whose `argv0` is `claude`/`codex`; `null` if absent |
| `tty` | `null` (herdr owns the PTY; never join by tty — CLAUDE.md's tty-recycling rule) |
| `model` | `--model <v>` parsed from that process's `argv`, else `artifacts->resolveModel()` |
| `skip_perms` | `--dangerously-skip-permissions` present in `argv` |
| `opts` | codex sandbox/approval/effort flags parsed from `argv`; `[]` for claude |
| `idle_seconds` | `now - artifacts->lastRealActivity()` (claude) / `now - artifacts->codexLastActivity()` (codex) — same source as cmux, so idle is comparable across transports |
| `targetable` | `true` when `session_id`, `cwd` and `pane_id` are all non-empty |
| `reason` | `null` when targetable, else why not (mirrors cmux's strings) |
| `no_bridge` | `false` — herdr never needs the resume-script bridge |

`supportsNonTerminalSurfaces()` returns `false`. `captureLayoutTree()` maps `layouts[]` `splits[]` (`direction` + `ratio`) into the same tree shape cmux emits so `Graveyard::planLayoutRestore` needs no change.

**Deliberately not carried over:** the content-probe fallback (`bindUnresolvedByContentProbe`) has no herdr counterpart and must not be ported. It exists because cmux can leave a session unbound; herdr's hook cannot. Do not add a screen-scraping fallback here.

- [ ] **Step 1: Write the failing test**

`tests/Transport/HerdrTransportTest.php`:

```php
<?php
namespace JT\Tests\Transport;

use JT\Helpers\Herdr;
use JT\Transport\HerdrTransport;
use JT\Tests\TestCase;

final class HerdrTransportTest extends TestCase
{
	private function transport(): HerdrTransport
	{
		// Reuse HerdrTest's stub-binary setup; see that file for the HERDR_BIN pattern.
		return new HerdrTransport($this->cli, new Herdr($this->cli));
	}

	public function test_name_is_herdr(): void
	{
		$this->assertSame('herdr', $this->transport()->name());
	}

	public function test_liveSessions_maps_agent_session_value_to_session_id(): void
	{
		$rows = $this->transport()->liveSessions();
		$ids  = array_column($rows, 'session_id');
		$this->assertContains('df8529bd-8e10-423c-983d-f17356dd706c', $ids);
	}

	public function test_liveSessions_carries_pane_and_workspace_handles(): void
	{
		$rows = $this->transport()->liveSessions();
		$row  = $this->rowFor($rows, 'df8529bd-8e10-423c-983d-f17356dd706c');

		$this->assertSame('herdr', $row['transport']);
		$this->assertSame('wC:p2', $row['surface_ref']);
		$this->assertSame('wC', $row['workspace_ref']);
		$this->assertSame('wC:t1', $row['window_ref']);
	}

	public function test_liveSessions_skips_panes_with_no_agent_session(): void
	{
		$rows = $this->transport()->liveSessions();
		foreach ($rows as $row) {
			$this->assertNotSame('', $row['session_id']);
		}
		// The fixture's bare shell pane (wF:p1) must not appear.
		$this->assertNotContains('wF:p1', array_column($rows, 'surface_ref'));
	}

	public function test_liveSessions_never_joins_by_tty(): void
	{
		foreach ($this->transport()->liveSessions() as $row) {
			$this->assertNull($row['tty']);
		}
	}

	public function test_does_not_support_non_terminal_surfaces(): void
	{
		$this->assertFalse($this->transport()->supportsNonTerminalSurfaces());
	}

	private function rowFor(array $rows, string $sid): array
	{
		foreach ($rows as $r) { if ($r['session_id'] === $sid) { return $r; } }
		$this->fail("no row for {$sid}");
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Transport/HerdrTransportTest.php`
Expected: FAIL — `Class "JT\Transport\HerdrTransport" not found`.

- [ ] **Step 3: Implement `HerdrTransport`** per the mapping table.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Transport` then `composer test`
Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Transport/HerdrTransport.php tests/Transport
git commit -m "feat(graveyard): add herdr SessionTransport

herdr reports agent session identity natively via its claude integration
hook, so this adapter is a snapshot field-mapping where cmux needs a
ps/lsof/env join plus an on-screen cwd probe. No content-probe fallback
here on purpose — herdr cannot leave a session unbound.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011a4MeZhQZGv4kapaPDfJVj"
```

---

### Task 6: Wire transport selection, the union, and the herdr-restore confirm

**Files:**
- Create: `src/Transport/TransportRegistry.php`
- Modify: `src/Graveyard.php` (`liveSessions()` union; resurrect target selection)
- Modify: `bin/graveyard` (:84-89 selection + `--transport` flag), help docs (:40-72)
- Modify: `zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh`
- Modify: `agent-skills/graveyard/SKILL.md`
- Create: `tests/Transport/TransportRegistryTest.php`
- Modify: `tests/Graveyard/GraveyardTest.php`

**Interfaces:**
- Consumes: `CmuxTransport` (Task 3), `HerdrTransport` (Task 5).
- Produces: `JT\Transport\TransportRegistry`, `new TransportRegistry($cli, array $transports)`, with
  `available(): list<SessionTransport>` (only reachable ones, cmux first),
  `byName(string $name): ?SessionTransport`,
  `forRow(array $row): SessionTransport` (throws `RuntimeException` if `$row['transport']` is unregistered),
  `liveSessions(): array` (concatenation across `available()`, then `AgentArtifacts::dedupBySessionId`).

**Selection policy** (decided, not open):
- Live-facing verbs (`ls`, `live`, `search`, `page`, `bury`) read the **union** of available transports. A row carries its own transport, so bury drives the right one with no flag and no ambiguity.
- `resurrect` needs a target that does not exist yet, so: `--transport=<cmux|herdr>` if given; else the only available transport; else cmux when both are up (incumbent, and the only one with full surface fidelity).
- `graveyard` no longer hard-fails when cmux is down — it fails only when *no* transport is reachable. Error text becomes `'No session transport is reachable. Is cmux or herdr running?'`

- [ ] **Step 1: Write the failing tests**

```php
	public function test_registry_unions_live_sessions_across_transports(): void
	{
		$reg  = new TransportRegistry($this->cli, [$this->fakeTransport('cmux', ['a']), $this->fakeTransport('herdr', ['b'])]);
		$ids  = array_column($reg->liveSessions(), 'session_id');
		sort($ids);
		$this->assertSame(['a', 'b'], $ids);
	}

	public function test_registry_dedupes_a_session_reported_by_both(): void
	{
		$reg = new TransportRegistry($this->cli, [$this->fakeTransport('cmux', ['a']), $this->fakeTransport('herdr', ['a'])]);
		$this->assertCount(1, $reg->liveSessions());
	}

	public function test_registry_skips_unavailable_transports(): void
	{
		$reg = new TransportRegistry($this->cli, [$this->fakeTransport('cmux', ['a'], false), $this->fakeTransport('herdr', ['b'])]);
		$this->assertSame(['b'], array_column($reg->liveSessions(), 'session_id'));
	}

	public function test_forRow_routes_a_row_back_to_its_own_transport(): void
	{
		$cmux = $this->fakeTransport('cmux', ['a']);
		$reg  = new TransportRegistry($this->cli, [$cmux, $this->fakeTransport('herdr', ['b'])]);
		$this->assertSame($cmux, $reg->forRow(['transport' => 'cmux', 'session_id' => 'a']));
	}

	public function test_tombstone_liveness_sees_a_session_live_under_either_transport(): void
	{
		// The R2 regression guard: `↑` must appear in ls AND search for a herdr-hosted
		// session, because both read Graveyard::tombstones(), which reads the union.
		// Mirrors tests/Graveyard/GraveyardLaunchSafetyTest.php's source-agreement case.
	}

	public function test_herdr_restore_of_a_mixed_workspace_confirms_before_dropping_surfaces(): void
	{
		// manifest layout: 2 terminals + 1 browser surface; transport = herdr.
		// Expect a confirm() naming the dropped surfaces by type and title,
		// and a declined confirm to abort without creating a workspace.
	}
```

Fill the last two bodies against the real fixtures in `GraveyardLaunchSafetyTest` and `GraveyardBuryGroupTargetTest` rather than inventing new ones.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Transport/TransportRegistryTest.php`
Expected: FAIL — `Class "JT\Transport\TransportRegistry" not found`.

- [ ] **Step 3: Implement registry, union, selection and the confirm**

`Graveyard::liveSessions()` delegates to the registry. The F5 confirm, in the grouped-restore path, when the chosen transport returns `supportsNonTerminalSurfaces() === false` and the manifest holds non-terminal entries:

```php
if (!$transport->supportsNonTerminalSurfaces() && $dropped) {
	$this->cli->msg("  {$transport->name()} hosts terminals only. These surfaces will NOT be restored:", 'yellow');
	foreach ($dropped as $d) {
		$this->cli->msg("    - [{$d['type']}] " . ($d['title'] ?: '(untitled)'), 'yellow');
	}
	if (!$autoConfirm && !$this->cli->confirm('  Restore the terminals anyway?')) {
		return false;
	}
}
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: `OK`, count ≥ 955.

- [ ] **Step 5: Update completion and skill in the same commit**

Add `--transport` (with `cmux herdr` values) to the `graveyard` completion. Verify in a clean Zsh:

```zsh
zsh -fc 'autoload -Uz compinit; compinit -C; source zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh; print -r -- ${_comps[graveyard]}'
```

Then reconcile the docs the CLAUDE.md drift rule names:

```bash
grep -rn "graveyard" agent-skills/ .claude/skills/ | grep -v Binary
```

`agent-skills/graveyard/SKILL.md` gains: graveyard now sees sessions under cmux *and* herdr; rows say which; `resurrect --transport=herdr` restores terminals only.

- [ ] **Step 6: Manual end-to-end verification**

Against the real store and a real herdr session (there are live ones — `herdr api snapshot` currently reports three claude agents):

```bash
./bin/graveyard live                     # herdr-hosted sessions appear, tagged herdr
./bin/graveyard ls | grep '↑'            # a live herdr session shows the live marker
./bin/graveyard search <term> | grep '↑' # same marker in search — the R2 guard, by hand
```

Bury/resurrect of a real herdr session is the last check and needs a throwaway session, not a working one: start one with `herdr workspace create --cwd /tmp --no-focus --label gy-test` then `herdr agent start gy-test --kind claude --pane <id>`, bury it, resurrect it, confirm the transcript archived and the session resumed.

- [ ] **Step 7: Commit**

```bash
git add src/Transport/TransportRegistry.php src/Graveyard.php bin/graveyard \
        zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh \
        agent-skills/graveyard/SKILL.md tests/
git commit -m "feat(graveyard): select transport, union liveness across cmux + herdr

Rows carry their own transport, so bury needs no flag; resurrect takes
--transport and defaults to cmux when both are up. Liveness unions inside
liveSessions() so every view inherits it from tombstones() — the one place
that annotates. Grouped restore under herdr names the surfaces it must drop
and confirms first.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011a4MeZhQZGv4kapaPDfJVj"
```

---

## Out of scope (file as follow-up beads, do not build here)

- **Remote transport.** herdr's socket is per-machine, but `herdr --remote <ssh-target>` and running the CLI over ssh both work, so a `RemoteHerdrTransport` could bury/resurrect jtbot sessions from the Mac. Real, and a separate plan — it drags in cross-machine store sync.
- **`cmux-bak` on the new seam.** It calls 22 `Cmux` methods and is cmux-specific by definition. Untouched deliberately.
- **Retiring the `Cmux` forwarders** added in Tasks 1–2. They exist for `cmux-bak`; removing them means migrating it first.
- **Non-terminal surface restore under herdr.** Accepted loss (F5), gated by a confirm.
