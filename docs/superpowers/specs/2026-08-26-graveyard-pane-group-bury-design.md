# Graveyard pane-scoped group bury — design

**Date:** 2026-08-26
**Status:** approved (design), pending implementation plan
**Depends on:** commit 7f8bec7 (cmux ⌘P `key=value` id parsing in `bury`)

## Problem

cmux's ⌘P "Copy Ids" hands over six `key=value` ids for a surface, including
`pane_ref=pane:N` and `pane_id=<uuid>`. As of 7f8bec7, a pasted pane id resolves
to the session(s) in that pane and is **ambiguity-rejected when the pane holds
more than one agent session** — the generic "narrow it or pass a full
session-id" path.

That reject is the wrong answer when a pane genuinely stacks several agent
sessions: the user wants to bury the whole pane and get those sessions back
together later. A pane is a natural unit to demote and resurrect as a set.

## Goal

`graveyard bury pane_ref=<ref>` / `pane_id=<uuid>` buries the pane's agent
sessions. A multi-session pane is buried **as a group** (the same mechanism
`--workspace` uses) and resurrects into a **new workspace** with a name the user
is prompted for at bury time.

## Behaviour

`classifyBuryIdentifier` gains a distinct `pane` kind for `pane_ref=`/`pane_id=`
(today both fold into `single`). Surface forms stay `single`; workspace forms
stay `workspace`; bare/unlabelled input stays `raw`.

For a `pane` paste, `buryByRef` locates the pane in the cmux tree and counts its
**agent sessions** (Claude + codex — *not* total surfaces):

- **0 agents** → error: "no agent sessions in that pane."
- **1 agent** → plain single bury of that one session. Identical to today's
  `bury <session>` semantics: sibling non-agent surfaces in the pane are left
  untouched. The heavier group machinery only engages when there are ≥2 agents
  to actually preserve together.
- **≥2 agents** → **pane group bury** (below).

### Pane group bury

Reuses the workspace group machinery, scoped to a single pane:

1. **Prompt for the new workspace name.** Under `-y`/non-interactive, fall back
   to a derived default: `"<parent-workspace-title> (pane)"`.
2. Build a one-pane node and run the **same** pipeline `buryWorkspace` runs:
   classify surfaces → guard → confirm → stamp a stable group id → write the
   layout manifest → bury each member (per-member gates) → close.
3. **Two deliberate differences from a workspace bury:**
   - **No full-workspace split-geometry capture.** `layout_tree` stays null, so
     resurrect rebuilds the pane's stacked surfaces manually — all a single pane
     needs.
   - **Close only the pane's surfaces**, never the whole workspace. The rest of
     the parent workspace stays alive.

### The untargetable guard is kept — it is load-bearing

The pane group bury inherits `buryWorkspace`'s refusal to proceed when the pane
contains an **untargetable** agent surface (one the join can't confidently bind
to a live session), unless `--force` is passed. This is not incidental caution:
it prevents a real, recorded data-loss class (dotfiles-5p5 / dotfiles-c8a,
commit 81659bb) where an unrecognised agent surface is misclassified as a plain
shell and **closed without archiving — permanent session loss**. `--force` does
not mean "guess and destroy"; it means "bury what I can prove, leave the
ambiguous ones alive." The pane close-loop therefore leaves untargetable
surfaces running, exactly as the workspace path does.

### Resurrect — unchanged

Resurrect is already manifest-driven and builds a fresh workspace titled by the
group name. The prompted name is the workspace you land in. No resurrect change
is required.

## Factoring

Extract the post-resolve body of `buryWorkspace()` (`src/Graveyard.php:2225`–
2448) into a shared:

```
buryLayoutAsGroup(array $node, string $wsRef, string $title, string $windowRef, array $opts): void
```

`$opts` controls the two differences:

- `captureLayoutTree` (bool) — workspace: true; pane: false.
- `close` — `'workspace'` (close the whole workspace when clean) vs `'surfaces'`
  (always close only the given node's surfaces).

`buryWorkspace` becomes a thin resolver (workspace node + title + window_ref)
that calls `buryLayoutAsGroup` with workspace `$opts`. The new `buryPane`
resolves the pane, builds a one-pane node, counts agents to branch, prompts for
the title, and calls `buryLayoutAsGroup` with pane `$opts`. One bury pipeline,
no duplication.

A small tree helper — `findPaneNode($tree, $paneRefOrId)` returning the pane
node plus its parent workspace ref/title and window ref — backs the pane
resolution.

## Testing

Pure/unit tests (stub `liveSessions()` and the cmux tree, following the
anonymous-subclass pattern already in `tests/Graveyard/GraveyardTest.php`):

- `classifyBuryIdentifier` returns `pane` for `pane_ref=`/`pane_id=` (update the
  existing assertion that currently expects `single`).
- `buryByRef` branch on agent count: 0 → error; 1 → single bury of that session;
  ≥2 → pane group bury.
- `findPaneNode` resolves a pane by ref and by UUID, and returns the right parent
  workspace/window.
- Pane group manifest: `group_title` comes from the prompt (and from the
  `"<parent> (pane)"` default under `-y`); `layout_tree` is absent; the close
  step targets only the pane's surfaces, never `closeWorkspace`.
- Untargetable agent in the pane → abort unless `--force`; under `--force` the
  untargetable surface is left alive and only bound members are buried. (Mirror
  the existing workspace untargetable tests.)

## Out of scope

- Any resurrect change (manifest-driven path already handles a one-pane group).
- Window-level or split-geometry ids (cmux ⌘P does not copy those as a unit).
- Changing bare-`workspace:N` / bare-uuid behaviour (still `raw`).
