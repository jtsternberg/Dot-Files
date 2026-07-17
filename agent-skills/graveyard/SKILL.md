---
name: graveyard
description: Find, browse, bury, and resurrect Claude Code sessions/workspaces via the `graveyard` CLI. Use when JT asks to look in the graveyard for a session/workspace on a topic, find good bury candidates, or resurrect/bury/list a session — including fuzzy names ("resurrect the tailscale workspace").
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

## The three things JT asks for

**Candidates worth burying** — `graveyard candidates` (live, sorted by idle
time). Present the most-idle ones (idle duration, workspace/tab, cwd) and let
him pick. Don't bury without a nod.

**Search buried sessions for a topic** — `graveyard search <term>` matches
workspace/tab/cwd/summary (case-insensitive, newest-first). Widen/split the
term if dry; add `--full-text` to also grep transcript bodies before concluding
nothing's there. If it still misses, say so plainly.

**Resurrect by fuzzy name** — `graveyard resurrect <name>` accepts a
workspace/tab title substring; a unique match resumes in place. If it's
ambiguous the CLI lists the candidates — pick with him (or narrow the phrase),
never guess. `graveyard resurrect --workspace <group>` for a whole buried
workspace; `graveyard ls` prints each group's exact `resurrect --workspace`
line. Resurrecting rebuilds a cmux workspace and relaunches Claude, so confirm
before running it.
