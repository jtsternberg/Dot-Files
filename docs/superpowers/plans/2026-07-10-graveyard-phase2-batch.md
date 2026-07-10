# Graveyard Phase-2 Batch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox syntax.

**Goal:** Make `bin/graveyard` idle-accurate, restore-faithful, and agent-drivable, per JT's reshaped phase-2 direction.

**Architecture:** Extend the existing `JT\Graveyard` (`bin/graveyard_lib.php`) and `JT\Helpers\Cmux` (`misc/helpers/cmux.php`); wire new subcommands into `bin/graveyard`. All destructive paths reuse the existing TOCTOU-safe `resolveLiveBySessionId`→`buryOne` machinery. Tests: plain-PHP asserts in `tests/graveyard/run.php` for pure logic; live throwaway verification for anything touching real sessions (JT go-ahead required).

**Branch:** `feat/graveyard-phase2`. **Epic:** `dotfiles-10x`.

## Global Constraints
- PHP `JT`/`JT\Helpers` namespaces; tabs (size 3); PSR-12; exit 0/1; colors red/green/yellow.
- Never bury the caller's own session; never kill on failed export; never merge/push without JT.
- Live/destructive tests: throwaways only, JT go-ahead first.
- `getCommandOutputAndExitCode` → `['exitCode','error','output']`.

## Task order (JT-approved)
1. Idle-truth + dedup (`dotfiles-dxg`)
2. resurrect `--resume`-first + `--from-transcript` (`dotfiles-jz3`, top priority)
3. `candidates --porcelain` + `bury` by session-id list (`dotfiles-3r2`)
4. Flexible `bury` identifiers + disambiguation (`dotfiles-6e1`)
5. Human picker (fzf + fallback) on top (`dotfiles-xn4`)

---

## Task 1 — Idle-truth + liveSessions dedup (`dotfiles-dxg`)

**Files:** `misc/helpers/cmux.php`, `bin/graveyard_lib.php`, `tests/graveyard/run.php`.

**Why:** JSONL file mtime is freshened by the `rename-claude-sessions` cron + Claude housekeeping, so mtime-idle is wrong (`--idle=2d` matched 13, should be 23). Compute idle from the last real user/assistant turn. Also `liveSessions()` returns duplicate rows per session (44→37 unique).

**Interfaces produced:**
- `Cmux::lastRealActivity(string $sessionId, string $cwd): ?int` — unix ts of the last JSONL entry whose `type` is `user` or `assistant` and that has a `timestamp`; null if file missing or no such entry.
- `Graveyard::dedupBySessionId(array $rows): array` — pure; keep first row per `session_id`, preserve order.
- `liveSessions()` now sets `idle_seconds` from `lastRealActivity` (`now - ts`); when null → `PHP_INT_MAX` (unmeasurable → excluded from sweeps, as today). Result deduped.

- [ ] Step 1 (test): in `tests/graveyard/run.php`, add a `lastRealActivity` test — write a temp JSONL with lines: a `user` (ts T1), an `assistant` (ts T2>T1), then a `system` (ts T3>T2, no user/assistant) — assert `lastRealActivity` returns strtotime(T2), and returns null for a missing path. Add a `dedupBySessionId` test: feed 3 rows (ids a,a,b) → assert 2 rows, first-of-each kept.
- [ ] Step 2: run `php tests/graveyard/run.php` → new asserts FAIL (undefined methods).
- [ ] Step 3: implement `Cmux::lastRealActivity` (stream the file line-by-line like `readSessionJsonl`, track last user/assistant ts via `strtotime`). Add `Graveyard::dedupBySessionId`. In `liveSessions`, replace the mtime idle calc:
  ```php
  $ts   = $this->cmux->lastRealActivity($sess['session_id'], $sess['cwd'] ?? '');
  $idle = $ts !== null ? ($now - $ts) : PHP_INT_MAX;
  ```
  and `return $this->dedupBySessionId($out);`
- [ ] Step 4: run tests → all pass. Then a read-only diagnostic: print the by-real idle distribution (reuse the audit one-liner) and confirm `--idle=2d` candidate count ≈ 23 and unique count 37 (no dupes).
- [ ] Step 5: commit `fix(graveyard): idle from last real turn (not mtime) + dedup liveSessions`.

---

## Task 2 — resurrect `--resume`-first + `--from-transcript` (`dotfiles-jz3`, top priority)

**Files:** `bin/graveyard_lib.php` (`resurrect`), `bin/graveyard` (flag), `tests/graveyard/run.php` optional.

**Behavior:** In `resurrect`, after resolving the tombstone and recreating the workspace, decide launch mode:
- If NOT `--from-transcript` AND the original JSONL still exists (`$this->cmux->jsonlPathFor($t['session_id'], $t['cwd'])` is a file) → launch `claude --resume <session_id>` (apply `--dangerously-skip-permissions`/`--model` from meta) via `sendToSurface` + `sendKeyToSurface enter`; report "restored via --resume". This restores the session as-was.
- Else (JSONL gone, or `--from-transcript`) → the existing read-the-transcript restart (requires `transcript.txt`).
- Guard: if JSONL gone AND transcript missing → `exitErr`.
- `bin/graveyard`: parse `--from-transcript` (`$cli->hasFlag('from-transcript')`) and pass to `resurrect(string $prefix, bool $fromTranscript=false)`. Update help + header usage.

- [ ] Step 1: refactor `resurrect` signature to accept `$fromTranscript`; branch as above (build the `claude --resume <id>` command reusing the same launch-flags logic).
- [ ] Step 2: `php -l` both files; `php tests/graveyard/run.php` still passes.
- [ ] Step 3: help/usage updated for `--from-transcript`.
- [ ] Step 4: commit `feat(graveyard): resurrect resumes live JSONL when present, --from-transcript to force`.
- [ ] Step 5 (LIVE, JT go-ahead): create a throwaway, bury it (JSONL still present), `resurrect <id>` → confirm it launches `claude --resume <id>` and the *actual prior conversation* is restored (not a fresh read). Then move/rename the JSONL and `resurrect` again → confirm fallback to transcript read. Then `--from-transcript` forces read even with JSONL present.

---

## Task 3 — `candidates --porcelain` + `bury` by session-id list (`dotfiles-3r2`)

**Files:** `bin/graveyard_lib.php`, `bin/graveyard`, `tests/graveyard/run.php`.

**Interfaces:**
- `Graveyard::candidates(): array` — `filterSelf(dedup liveSessions)`, drop `idle===PHP_INT_MAX`, sort by `idle_seconds` desc. Each row: session_id, idle_seconds, cwd, workspace_title, tab_title, busy (bool via `isBusy` using a fresh `readLastScreen`).
- `Graveyard::printCandidates(bool $porcelain): void` — human table by default; `--porcelain` = tab-separated stable columns: `session_id\tidle_seconds\tbusy\tworkspace_title\tcwd` (one per line, no color, no header) for agent parsing.
- `Graveyard::buryIds(array $sessionIds, bool $autoConfirm): void` — reuse the TOCTOU-safe loop: for each id `resolveLiveBySessionId`→`buryOne`, self-guard, preview + confirm unless `-y`.
- `bin/graveyard`: `candidates [--porcelain]` subcommand; `bury <id> [<id>...]` — when 1+ positional args and they look like session-ids (not `surface:` refs), route to `buryIds`. (`bury <ref>` single + `--idle` still work.)

- [ ] Step 1 (test): `candidates` sort/shape is hard to unit-test (live); unit-test the porcelain FORMATTER as a pure helper `formatCandidatePorcelain(array $row): string` → assert exact tab layout. Unit-test that `buryIds` with `[]` is a no-op/clean message (guard).
- [ ] Step 2: run → fail.
- [ ] Step 3: implement `candidates`, `formatCandidatePorcelain`, `printCandidates`, `buryIds`; wire subcommands + help.
- [ ] Step 4: tests pass; `php bin/graveyard candidates --porcelain` (read-only) prints sorted tab-separated rows; verify sorted by idle desc and self absent.
- [ ] Step 5: commit `feat(graveyard): candidates --porcelain + bury by session-id list`.
- [ ] Step 6 (LIVE, JT go-ahead): two throwaways → `candidates --porcelain` lists them by idle → `bury <id1> <id2> -y` buries exactly those two, zero collateral (baseline workspace count unchanged).

---

## Task 4 — Flexible `bury` identifiers + disambiguation (`dotfiles-6e1`)

**Files:** `bin/graveyard_lib.php`, `bin/graveyard`.

**Behavior:** `bury <identifier>` resolves against `dedup liveSessions` by, in order: exact `surface_ref`, exact `surface_id` (UUID), `session_id` exact-or-prefix, then `workspace_title`/`tab_title` substring (case-insensitive). Add `Graveyard::resolveLiveByIdentifier(string $id): array` returning `['matches'=>[...rows], 'how'=>string]`. In `buryByRef`:
- 0 matches → `exitErr`.
- 1 match → self-guard, then `buryOne`.
- >1 match → print the matches (session_id short, idle, workspace/tab, cwd) and `exitErr("ambiguous — narrow it or pass a session-id")` (no `-y` auto-pick; explicit is safer for a destructive op).
- Keep session-id-LIST bury (Task 3) working: if multiple positional args, that's the list path; a single arg goes through `resolveLiveByIdentifier`.

- [ ] Step 1 (test): unit-test `resolveLiveByIdentifier`'s matching precedence with an injected rows array (refactor the pure matching into a testable `matchIdentifier(array $rows, string $id): array`): assert surface_ref beats session prefix, name substring returns multiple, etc.
- [ ] Step 2: fail → implement → pass.
- [ ] Step 3: wire into `buryByRef`; update help to document accepted identifier forms.
- [ ] Step 4: commit `feat(graveyard): bury accepts surface UUID / session-id / workspace-tab name with disambiguation`.
- [ ] Step 5 (LIVE, JT go-ahead): throwaway named distinctively → bury by its workspace name, by session-id prefix, by UUID; create two same-named throwaways → confirm ambiguity is reported and nothing buried.

---

## Task 5 — Human picker (fzf + numbered fallback) (`dotfiles-xn4`)

**Files:** `bin/graveyard_lib.php`, `bin/graveyard`.

**Behavior:** `bury` with NO args → `pickAndBury()`:
- Build candidates (Task 3). If none → message + exit.
- If `fzf` on PATH: pipe one line per candidate (`session_id \t idle \t workspace/tab \t cwd`) to `fzf --multi --with-nth=2.. --preview '<self> _preview {1}'` where `_preview <session_id>` is a hidden subcommand printing that session's `readLastScreen` + meta. Selected lines → session_ids.
- Else: numbered-list REPL — print candidates numbered; read a line; parse tokens `1 3 5`, ranges `2-4`, `a`=all, `p<n>`=preview #n (print readLastScreen), `q`=quit; collect ids.
- Hand the chosen ids to `buryIds` (Task 3) → summary + confirm + TOCTOU-safe bury.
- Add hidden `_preview <session_id>` subcommand in `bin/graveyard` for fzf's preview call.

- [ ] Step 1: implement `pickWithFzf(array $candidates): array`, `pickWithRepl(array $candidates): array`, `pickAndBury()`; add `_preview` subcommand.
- [ ] Step 2: `php -l`; tests still pass; `graveyard candidates` unaffected.
- [ ] Step 3: commit `feat(graveyard): interactive bury picker (fzf + numbered fallback)`.
- [ ] Step 4 (LIVE, JT go-ahead): with throwaways present, run `graveyard bury` (no args), exercise fzf preview + multi-select, bury the selected throwaways; then temporarily hide fzf (PATH) to exercise the numbered fallback.

---

## Self-review notes
- Idle-truth (Task 1) is the foundation; every later task's ranking/candidate logic depends on it — do first.
- All destructive live steps gated on JT go-ahead, throwaways only, per phase-1 protocol.
- `buryIds` (Task 3) is the single safe bury entrypoint reused by Tasks 4 and 5.
