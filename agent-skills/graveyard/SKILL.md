---
name: graveyard
description: Find, browse, bury, and resurrect Claude Code sessions/workspaces via the `graveyard` CLI. Use when JT asks to look in the graveyard for a session/workspace on a topic, find good bury candidates, resurrect/bury/list a session — including fuzzy names ("resurrect the tailscale workspace") — or asks a content question about a buried plot ("check the Break Free plot — did we discuss adding lyrics?"), which is really resurrection triage: answer from the transcripts, then point at the member session to resume.
allowed-tools: [Bash, Read]
---

# graveyard

`graveyard` (`bin/graveyard` here) buries idle Claude Code sessions to
`~/.claude-graveyard/` — freeing RAM while keeping a rendered transcript +
metadata — then lists, searches, and resurrects them. cmux must be running for
the live-session verbs (`bury`, `candidates`, `peek`); browsing/resurrecting
buried sessions works regardless.

JT asks for this conversationally — *"any good candidates worth burying?"*,
*"look in the graveyard for the ollama session,"* *"resurrect the tailscale
workspace."* The CLI does the heavy lifting; the current help is the source of
truth (don't mirror the command list here — it bitrots):

!`graveyard --help 2>&1`

Every verb has a machine-readable mode (`--json` on `ls`, `candidates`,
`search`), so prefer flags over scraping human output when you need to filter
or rank.

## The four things JT asks for

**Candidates worth burying** — `graveyard candidates` (live, sorted by idle
time). Present the most-idle ones (idle duration, workspace/tab, cwd) and let
him pick. Don't bury without a nod.

**Bury the thing JT points at** — he can copy an id from cmux's ⌘P menu ("Copy
Surface Id" or "Copy Ids") and paste it; `graveyard bury <pasted>` takes it
verbatim. A `surface_id=`/`surface_ref=` line — or the whole multi-line "Copy
Ids" blob, whose surface line names the session — buries that one session. A
`pane_id=`/`pane_ref=` line buries the pane: one agent session is a plain bury,
several bury as a group that resurrects into a *new* workspace named at bury
time (a `<parent> (pane)` default under `-y`). A `workspace_id=`/`workspace_ref=`
line buries the whole workspace as a group, same as `--workspace`. A *bare*
`workspace:N` or bare UUID is left alone — only the labelled paste opts into a
pane/workspace group bury. Still get his nod first.

**Search buried sessions for a topic** — `graveyard search <term>` matches
workspace/tab/cwd/summary (case-insensitive, newest-first). Widen/split the
term if dry; add `--full-text` to also grep transcript bodies before concluding
nothing's there. If it still misses, say so plainly.

**Answer a question about a plot (resurrection triage)** — *"check the Break
Free plot — did we discuss adding lyrics to all the songs?"* The real question
is: *should I resurrect at all, and if so, which conversation in the plot do I
resume to continue?* So answer from the buried transcripts first; resurrect
only if JT then wants to pick the work back up.

1. `graveyard search "<plot name>" --json` → the workspace group with its
   member sessions (`session_id`, `tab_title`, `summary`).
2. Read each promising member's rendered transcript directly:
   `$GRAVEYARD_ROOT/sessions/<session_id>/transcript.md` — or `transcript.txt`;
   which name exists depends on the exporter, so resolve either (default root
   `~/.claude-graveyard`). Grep across the members first when the plot is big.
   Never `graveyard show` for this — it opens an editor and hangs an agent.
3. Report what the transcripts actually say (quote them), then name **which
   member conversation** to resume if he wants to continue. Identify it by its
   **tab title** — that's what the resurrected pane is actually called in the
   UI; the session-id is only the command handle, meaningless on screen. Offer
   `graveyard resurrect <session-id-prefix>` for just that conversation, or
   `resurrect --workspace <group>` for the whole plot — and when offering the
   workspace form, say which tab to continue in: *"the conversation is in the
   tab titled '<tab_title>'"*. Resurrect only on his nod.

For "did we ever discuss X" questions *not* scoped to a plot, `mempalace
search "<query>"` (indexes all past conversations) is the better first stop;
`graveyard search --full-text` is the fallback within buried sessions.

**Resurrect by fuzzy name** — `graveyard resurrect <name>` accepts a
workspace/tab title substring; a unique match resumes in place. If it's
ambiguous the CLI lists the candidates — pick with him (or narrow the phrase),
never guess. `graveyard resurrect --workspace <group>` for a whole buried
workspace; `graveyard ls` prints each group's exact `resurrect --workspace`
line. Resurrecting rebuilds a cmux workspace and relaunches Claude, so confirm
before running it.
