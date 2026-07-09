# Claude Graveyard — Design Spec

**Date:** 2026-07-09
**Tool:** `bin/graveyard` (PHP, `JT` namespace)
**Status:** Approved (2026-07-09, after review feedback #1–4); ready for implementation plan.

## Problem

Idle Claude Code CLI sessions accumulate as live processes (57 observed, ~5.7 GB
RSS, driving swap to 97% full on a 64 GB Mac). JT keeps them alive not for their
context but to preserve **the place in the cmux sidebar** — the workspace/tab
name that reminds him the work exists and lets him pull it back up. Killing a
process feels like losing that reminder.

Two independent facts make the fear mostly unfounded, but expose a real gap:

- Killing a `claude` process does **not** delete its transcript; the JSONL is
  written continuously and is resumable with `claude --resume`.
- Claude Code GCs transcripts after `cleanupPeriodDays` (default **30**) of
  inactivity — and that clock runs whether the process is alive or not. So
  keeping processes alive does not protect them from GC; it only costs RAM.

**The gap:** there is no durable, reviewable, exportable archive of retired
sessions that (a) survives independently of cmux/app state, (b) is immune to the
30-day GC, and (c) lets JT browse retired sessions in one place and resurrect on
demand. That archive is the "graveyard."

## Key constraint (verified)

`/export` is **interactive-only — requires a real TTY.** There is no
`claude export <session-id>` command and no `--export` flag. Verified
empirically (2026-07-09, v2.1.204), both headless paths fail identically with
`/export isn't available in this environment`:

- `claude --resume <id> -p "/export <file>"` (print mode)
- `printf '/export <file>\n' | claude --resume <id>` (piped stdin — still
  treated as non-interactive)

Parsing JSONL directly is officially unsupported (format changes between
versions). A dead session can only be exported by relaunching it in a real pty
(throwaway cmux surface, or `script`/expect) and sending `/export` — deferred to
phase 2 (cold tombstone).

**Consequence:** the graveyard buries **live** sessions by driving their cmux
surface's `/export <path>` (which writes rendered text straight to a file, no
clipboard dialog). This fits the use case — stale sessions are *alive* (holding
RAM), just idle. Already-dead sessions cannot be rendered; the fallback is a
"cold tombstone" (copy raw JSONL if it still exists, flagged un-rendered) —
**phase 2**.

## What is preserved (decided)

The `/export`-style **rendered text transcript** plus metadata. Not raw JSONL.
Rationale: human-readable, self-contained, GC-proof, and ~1/20th the size
(measured: 15 KB vs 338 KB, 22.7× smaller, on a real session; the gap widens on
long sessions because JSONL stores every thinking block and tool payload).

**Caveat (calibrates resurrection expectations):** `/export` captures the
*current conversation view*, i.e. what remains in the context window — it does
**not** include history that was dropped by compaction earlier in the session.
So a resurrected session re-orients from the post-compaction view, not the full
lifetime of the conversation. This matches how Claude itself would have
continued (it only "remembered" the compacted summary), so it's acceptable, but
the tombstone should not be presented as a complete verbatim archive of
everything ever said.

## Resurrection model (decided)

**Read-the-transcript restart**, not `--resume`. Resurrect = recreate the cmux
workspace (same name + cwd), launch a **fresh** `claude`, and instruct it to read
the exported transcript and pick up where the work left off. Depends only on the
export `.txt` — works forever, GC-proof, no JSONL. Claude re-orients from the
text rather than restoring exact internal state.

## Relationship to `bin/cmux-bak`

Sibling tool. `cmux-bak` snapshots/restores *all* cmux state; `graveyard`
*demotes* individual sessions. The shared machinery is **extracted out of
`cmux-bak` into a reusable module** under `misc/helpers/` (e.g.
`misc/helpers/cmux.php` — a `Cmux`/`ClaudeSessions` helper), and **`cmux-bak` is
refactored to consume it too**, so there is one implementation, not a fork.
Graveyard is the second consumer. Shared surface:

- `getCmuxTree()` — parse `cmux tree --all --json`.
- `loadClaudeSessions()` — map tty → `{session_id, cwd, model, skip_perms,
  status, pid}` via `~/.claude/sessions/<pid>.json` (no screen-scraping).
- `sendToSurface()`, `createSurface()`, `buildResumeCommand()`, workspace
  lookup/creation-by-title.

Last-activity for idle detection = mtime of the session's JSONL
(`~/.claude/projects/<encoded-cwd>/<session-id>.jsonl`).

## Store layout — `~/.claude-graveyard/`

Local only (transcripts may contain sensitive content; git-ignored, not synced
by dotfiles). Sibling to `~/.claude`.

```
~/.claude-graveyard/
  index.json                 # roll-up: array of tombstone summaries for fast ls + HTML
  sessions/<buried-id>/
    transcript.txt           # the /export output — the artifact
    meta.json                # workspace_title, tab_title, cwd, session_id,
                             #   model, skip_perms, buried_at, last_active, summary
  graveyard.html             # regenerated on demand (phase 2)
```

`<buried-id>` = the Claude session-id (stable, unique). `buried_at` and
`last_active` are ISO-8601 UTC.

## Commands

### Phase 1 (core — ship first)

- `graveyard bury <ref>` — bury one live session by cmux ref/index.
- `graveyard bury --idle <dur>` — sweep all live claude sessions idle longer than
  `<dur>` (e.g. `2d`, `48h`); preview the list and confirm before acting
  (`-y`/`--yes` to skip). Primary path for clearing the backlog.
- `graveyard ls` — table: buried-id (short), workspace title, cwd, buried date,
  summary. Source of truth (reads `index.json`).
- `graveyard show <id>` — open `transcript.txt` in editor (`code -r`, fallback
  `$EDITOR`/`vi`). `<id>` accepts unambiguous prefixes.
- `graveyard resurrect <id>` — recreate workspace + launch fresh claude that
  reads the transcript (see below).

### Phase 2 (follow-up)

- `graveyard bury` (no args) — interactive picker: list live sessions (name,
  cwd, idle time), drill into a session to preview its last bits via
  `cmux read-screen --surface <ref> --scrollback --lines N`, multi-select.
- `graveyard page` — regenerate `graveyard.html` (card per tombstone, summary +
  dates + cwd, click-to-expand full transcript), open in browser.
- `graveyard rm <id>` — permanently delete a tombstone.
- Cold-tombstone fallback for already-dead sessions (copy raw JSONL if present,
  flag as un-rendered).

## Flows

### Bury (phase 1)

1. Resolve target → surface ref, tty, `session_id`, `cwd`, `model`,
   `skip_perms` (shared helpers).
2. **Idle gate (concrete two-part check; do not interrupt an in-flight turn):**
   a session is safe to bury only if **both** hold —
   - **(a)** its JSONL mtime is older than `N` seconds (default 15) — no writes
     land mid-turn; AND
   - **(b)** the last ~5 lines of `cmux read-screen` show **no active-turn
     indicator** — i.e. none of Claude Code's running-turn markers (the
     `esc to interrupt` hint / an animated spinner glyph line / a `(… tokens)`
     running counter).

   Rely on the *absence of activity markers*, **not** on positive prompt
   detection — prompt-regex matching is fragile (ghost autosuggest, powerline/
   NerdFont glyphs). If either check says "busy," skip the session (in `--idle`
   sweeps) or require `--force` (for an explicit single `bury <ref>`).
   *(The exact marker set is a plan-level detail to pin against a live session;
   the two-part structure is the requirement.)*
3. `mkdir -p ~/.claude-graveyard/sessions/<id>/`.
4. Export to a **temp filename, then atomically rename into place:**
   `cmux send --surface <ref> "/export <abs-path>/.transcript.<pid>.tmp\n"`,
   poll only for the temp file to *appear*, then `rename()` it to
   `transcript.txt`. Exporting to a fresh temp name avoids `/export` prompting
   or misbehaving when overwriting an existing `transcript.txt` on a **re-bury**,
   and the atomic rename removes the need for a size-stable poll. On timeout
   (temp never appears), abort the bury (do **not** kill the process), leave any
   existing tombstone untouched, and report.
5. Derive a one-line `summary` (first user prompt, else tab title). Write
   `meta.json`; upsert `index.json` (re-bury of the same `session_id` overwrites
   its tombstone in place).
6. Teardown: close the cmux surface (and its workspace if now empty) →
   process exits → RAM freed. Confirm before killing unless `-y`.

**Self-bury guard (`--idle` and any bulk selection):** always exclude the
caller's own session from the candidate set — filter out both the current
`CMUX_SURFACE_ID` and the caller's own `session_id`. A sweep must never bury the
session that is running the sweep. (An explicit `bury <ref>` that names the
caller's own surface is also refused with a clear error.)

### Resurrect (phase 1)

1. Read `meta.json`.
2. `cmux new-workspace --name <workspace_title> --cwd <cwd>`; locate the new
   workspace/surface (as cmux-bak does).
3. Launch fresh `claude` (apply `--dangerously-skip-permissions` /
   `--model` from meta) via `sendToSurface`.
4. `sendToSurface` a preamble instructing Claude to read the transcript:
   *"Resuming a buried session. Read `<abs transcript path>` — that's where we
   left off. Re-orient, then continue."*

## Error handling / risks

- **`/export` reliability over `cmux send`:** the ⏎ must submit the slash
  command; the path must be writable so no dialog opens. Verify with a real
  session during implementation; treat a missing/empty temp file as a failed
  bury (never kill on failure).
- **Busy session:** never `/export` into a mid-task session — gate on the
  two-part idle check (JSONL mtime + absence of active-turn markers) in the Bury
  flow.
- **Self-bury:** a sweep must never bury the caller's own session — enforced by
  the self-bury guard (exclude `CMUX_SURFACE_ID` + caller `session_id`).
- **Re-bury:** exporting over an existing `transcript.txt` may prompt/misbehave —
  handled by export-to-temp + atomic rename.
- **Ambiguous `<id>` prefix:** error and list matches.
- **cmux unreachable:** `cmux ping` guard (as cmux-bak does) → clear error.
- **Sensitive content:** store is local + git-ignored; never externalize.

## Testing

- Bury a throwaway live session → assert `transcript.txt` non-empty,
  `meta.json`/`index.json` correct, surface closed, process gone.
- `ls` renders the tombstone; `show` opens the file.
- Resurrect → new workspace with correct name/cwd, fresh claude launched, read
  preamble sent.
- `--idle` selects only sessions past the threshold; dry-run/preview matches
  what gets buried.
- Self-bury guard: `--idle` run from within a cmux claude session never lists or
  buries the caller's own surface/session; explicit `bury <own-ref>` is refused.
- Re-bury: burying an already-buried `session_id` overwrites its tombstone via
  temp+rename without a leftover `.tmp` and without corrupting `index.json`.
- Idle gate: a session mid-turn (recent JSONL mtime or active-turn marker on
  screen) is skipped by a sweep and requires `--force` for explicit bury.
- Failure path: unwritable export path → bury aborts, process left alive.
- **Regression:** after extracting the shared module, `cmux-bak` (backup +
  `--restore --dry-run`) still behaves identically — no fork, no behavior change.

## Out of scope (v1)

Rendering dead-session JSONL, HTML page, interactive picker, `rm`, MemPalace
integration (a MemPalace `mine` can be pointed at the graveyard later without
coupling the two systems).
