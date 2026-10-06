---
name: graveyard
description: |
  Find, browse, bury, and resurrect Claude Code and Codex sessions/workspaces
  via the `graveyard` CLI. Use when JT asks to look in the graveyard for a
  session/workspace on a topic, find good bury candidates, resurrect, bury, or
  list a session, including fuzzy names such as "resurrect the tailscale
  workspace". Also use for content questions about a buried plot, such as
  "check the Break Free plot — did we discuss adding lyrics?"; answer from the
  transcripts, then point at the member session to resume.
allowed-tools: [Bash, Read]
---

# graveyard

`graveyard` (`bin/graveyard` here) buries idle Claude Code and Codex sessions to
`~/.claude-graveyard/` — freeing RAM while keeping a rendered transcript +
metadata — then lists, searches, and resurrects them.

It drives **two multiplexers**: cmux and herdr. Either one being up is enough — every
verb except `page` and `serve` needs a live transport and exits
`No session transport is reachable. Is cmux or herdr running?` when neither answers.
That includes the browsing verbs: `ls`, `search` and `show` annotate each tombstone with
whether its session is running again, so they ask a transport too. Only `page` and
`serve` read the store alone (`$storeOnlyVerbs` in `bin/graveyard`), which is why the
served page renders with no transport behind it at all.

JT asks for this conversationally — *"any good candidates worth burying?"*,
*"look in the graveyard for the ollama session,"* *"resurrect the tailscale
workspace."* The CLI does the heavy lifting; the current help is the source of
truth (don't mirror the command list here — it bitrots). Run
`graveyard --help 2>&1` before operating it.

Every verb has a machine-readable mode (`--json` on `ls`, `candidates`,
`search`), so prefer flags over scraping human output when you need to filter
or rank.

## Both multiplexers, one list

Discovery is a **union**: `candidates`, `ls`, `search` and `page` see sessions
under cmux AND herdr in one list, and every row says which hosts it.

`candidates` carries a **kind column** between the verdict badge and the description,
naming only what departs from the default — blank for Claude-under-cmux,
otherwise `herdr`, `codex`, or `codex/herdr`. Agent and transport are two
separate axes sharing one column. The column is omitted entirely when every row
is the default pairing, so a cmux-only install's output is unchanged.
`candidates --json` carries `agent` and `transport` as fields; `--porcelain`
carries them as columns 8 and 9, appended so fields 1-7 never move.
`candidates --json` also carries the locators `surface_id`, `surface_ref`,
`pane_ref`, `workspace_ref`, `window_ref` and `tab_ref` on every row (null when
unknown). A herdr row's tab id is `tab_ref` and its `window_ref` is null; a cmux
row's `tab_ref` is null.

`bury` needs no flag: it drives whichever transport reported the session.

Only `resurrect` takes `--transport=<cmux|herdr>`, because a restore creates a
workspace that does not exist yet. Default: the only reachable transport, or
cmux when both are up. **herdr hosts terminals only**, so a grouped restore into
it drops browser/markdown surfaces entirely, splits a stacked cmux pane into
separate panes, and loses split ratios and nested orientation. It names exactly
what it will lose and asks first (`-y` accepts; with no tty and no `-y` it
refuses rather than dropping surfaces silently).

## The four things JT asks for

**Candidates worth burying** — `graveyard candidates` (live, sorted by idle
time). Each row carries a badge from a local model's read of the session's last
message: `done 93%`, `done? 42%` (done, but under 85% so not sorted first),
`waiting` (blocked on JT's reply), `working`, or `?` (unknown: codex, no
transcript, model down, or JT's last prompt never got a reply — the turn died on
a usage limit, API error, or Esc). Rows at p(done) >= 85% sort first.
`--json` carries it as `verdict`, `p_done`, `verdict_source`; `--porcelain` as
columns 10-12. It is
a ranking hint, wrong roughly 1 time in 20 ("nudge me when X and I'll do Y" reads
as done). Present the top rows (badge, idle duration, workspace/tab, cwd) and
let him pick. Don't bury without a nod, whatever the badge says. A cold cache
classifies every session (~1.5 s each); `--no-verdict` skips it.

**Bury the thing JT points at** — he can copy an id from cmux's ⌘P menu ("Copy
Surface Id" or "Copy Ids") and paste it; `graveyard bury <pasted>` takes it
verbatim. A `surface_id=`/`surface_ref=` line — or the whole multi-line "Copy
Ids" blob, whose surface line names the session — buries that one session. A
`pane_id=`/`pane_ref=` line buries the pane: one agent session is a plain bury,
several bury as a group that resurrects into a *new* workspace named at bury
time (a `<parent> (pane)` default under `-y`). A `workspace_id=`/`workspace_ref=`
line buries the whole workspace as a group, same as `--workspace`. A *bare*
`workspace:N` or bare UUID is left alone — only the labelled paste opts into a
pane/workspace group bury. Still get his nod first. A single-target bury then
offers to author that target's note (below) — not when you run it with `-y`.

**Search buried sessions for a topic** — `graveyard search <term>` matches
workspace/tab/cwd/summary, the custom name, the local-model description and the
session's `NOTES.md` (a plot's note matches the whole plot); case-insensitive,
newest-first. Widen/split the term if dry; add `--full-text` to also grep
transcript bodies before concluding nothing's there. If it still misses, say so
plainly. In `ls`/`search` text a `✎` marks a session with a note and its
description prints under the title; `--json` rows carry `description`,
`description_model` and `note`, and workspace entries `note`, only when present.

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
line. Resurrecting rebuilds a workspace in the target multiplexer and resumes
the recorded agent, so confirm before running it — and if the target is herdr,
read out the losses it prints before accepting them.

## Adding human context — `graveyard note`

A buried session/plot carries only its transcript and machine metadata.
`graveyard note <id>` attaches a free-form markdown `NOTES.md` to a buried
**session**, and `graveyard note -ws <group>` (or `--workspace`) to a whole
**plot** — the place for out-of-band context the transcript can't hold: the
Slack thread where follow-up landed, issues spun out of the session, a "resume
here next" pointer. The note is rendered as HTML in that target's modal on the
`graveyard page` overview (a 📝-style note pane above the transcript), strictly
1:1 — a session's modal shows only its own note, a plot's only the plot note.

**A session modal's 🔮 summarize button** asks a local model
(`local-llm-summarize --session` under the hood) for two sentences, which are
saved on the tombstone as `description` and shown under the title as an
epitaph. The model's short title is only *suggested* next to the rename field;
nothing is renamed until JT clicks "use this name". A cold model can take ~30s,
and the page server runs worker processes so the rest of the page stays live
meanwhile.

**`bury` offers the note at burial time.** After burying a *single* target — one
session, or a workspace/pane group — `graveyard bury` asks *"Add a note to this
buried session/plot?"* (default no) and on yes opens the same `NOTES.md`, seeded
the same way, in the same editor. It never asks after a multi-session bury
(several ids, `--idle`, a multi-pick), and `bury <id> --group <gid>` never
re-offers a plot note the group's original bury already offered. The offer is
skipped outright under `-y`, `--silent`/`--porcelain`, or with no tty — which is
how an agent runs bury, so it can't hang you. Don't drop `-y` to reach the
offer: point JT at `graveyard note <id>` afterwards instead.

`note` **opens an editor** (`code -r`, else `$EDITOR`) exactly like `show`, so
like `show` it will hang an agent — it is a *human* action. Don't run it to
author a note yourself. Point JT at the command instead: *"run `graveyard note
<id>`"*. You can, however, read a note straight off disk the same way you read a
transcript: `$GRAVEYARD_ROOT/sessions/<session_id>/NOTES.md` (session) or
`$GRAVEYARD_ROOT/workspaces/<group_id>/NOTES.md` (plot), default root
`~/.claude-graveyard`. Deleting the session/plot removes its note with it.
