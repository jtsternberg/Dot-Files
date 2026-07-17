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
workspace."* The current help is the source of truth (don't mirror the command
list here — it bitrots):

!`graveyard --help 2>&1`

The three cases below cover almost everything he asks for.

## 1. Find good bury candidates ("what's worth burying?")

`graveyard candidates` lists live buryable sessions sorted by idle time. Run it,
then surface the most-idle ones — idle duration, workspace/tab, cwd — and let
him pick. Don't bury anything without a nod. `--porcelain` gives tab-separated
rows if you want to filter/rank programmatically.

## 2. Resurrect a workspace by fuzzy name ("resurrect the tailscale one")

Buried workspaces are keyed by an opaque group id, but JT names them loosely.
Map his phrase to a group by scanning `graveyard ls` — each buried workspace
prints its title and the exact `graveyard resurrect --workspace <group>` line.
Fuzzy-match his phrase against the titles, confirm the one you landed on
(*"the 'tailscale setup' workspace — 3 sessions, buried 2026-07-17?"*), then run
that printed command. If two titles are plausibly what he meant, ask which
rather than guessing. Single buried session instead of a workspace? Same idea,
but `graveyard resurrect <id>` with the 8-char session id.

## 3. Search buried sessions for a topic ("anything about ollama?")

There's no `search` subcommand, so search the index directly instead of
rediscovering where things live. The index is `~/.claude-graveyard/index.json`;
each tombstone carries
`session_id`, `workspace_title`, `tab_title`, `cwd`, `model`, `summary`,
`buried_at`, `last_active`.

```bash
~/.venvs/genai/bin/python3 - "ollama" <<'PY'
import json, sys, pathlib
q = sys.argv[1].lower()
idx = json.loads(pathlib.Path("~/.claude-graveyard/index.json").expanduser().read_text())
for t in idx["tombstones"]:
    hay = " ".join(str(t.get(k, "")) for k in
                    ("workspace_title", "tab_title", "cwd", "summary")).lower()
    if q in hay:
        print(f"{t['session_id'][:8]}  [{t.get('workspace_title','')}]  "
              f"{t.get('tab_title','')}  — {t.get('cwd','')}  ({t.get('buried_at','')})")
PY
```

Swap in the real topic; widen/split the term if dry (try `router` *and*
`linksys`). Metadata summaries are short — if the index misses, full-text grep
the rendered transcripts under `~/.claude-graveyard/sessions/` before giving up.
Report matches newest-first (8-char id, workspace/tab, cwd, date); if nothing
hits, say so plainly. Then offer the next step — `graveyard show <id>` to read
it, or resurrect (never resurrect unprompted; it rebuilds a cmux workspace and
relaunches Claude).
