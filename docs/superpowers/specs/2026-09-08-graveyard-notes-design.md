# graveyard `note` — human context on a buried session/plot

**Date:** 2026-09-08
**Branch:** `graveyard-notes` (worktree off `graveyard-transport-adapter`)
**Status:** design, pending review

## Problem

A buried session or plot carries only its transcript and machine metadata. There's
no place for the human to add out-of-band context — the Slack thread where
follow-up questions landed, the issues spun out of the session, a "resume here
next" pointer. Right now that context lives in JT's head and is gone by the time
a plot is resurrected weeks later.

## Goal (MVP)

`graveyard note <target>` lets JT attach a free-form, markdown `NOTES.md` to a
buried **session** or **plot** (workspace group), edited in his editor. The
graveyard web page renders that note as HTML inside the modal that already shows
that session's transcript / that plot's detail.

**Explicitly out of scope (future phase):** feeding `NOTES.md` to the consuming
agent on resurrect. MVP only authors and displays.

## Decisions (settled)

- **Authoring:** editor only. `graveyard note <target>` creates the file if absent
  and opens it (`code -r`, non-blocking, same as `show`; `$EDITOR` fallback). No
  inline-text or `--file` import in MVP.
- **Targets:** both a single session and a whole plot, mirroring `rename`'s two
  forms exactly.
- **Rendering:** add `league/commonmark` (composer dep) and render server-side.
  Portable Mac+Linux, no shell-out, safe HTML, autolinks bare URLs.
- **Visibility:** strict 1:1. A session's modal shows only its own note; a plot's
  modal shows only the plot note. A member session does **not** surface its parent
  plot's note.

## Storage

`NOTES.md` lives inside the target's existing directory — nothing new in the index:

- Session: `sessions/<id>/NOTES.md`  (alongside `transcript.md`, `meta.json`)
- Plot:    `workspaces/<gid>/NOTES.md`  (alongside `manifest.json`)

Because the note sits inside the dir that `delete`/`purge` already `rmrf`s
(`purgeSession` removes `sessionDir(<id>)`; `deleteGroup` removes
`workspaceGroupDir(<gid>)`), deletion needs **no new cleanup code** — to be
verified by a test, not assumed.

New path helpers on `Graveyard` (beside the existing `:66-183` block):

```php
public function noteSessionPath(string $id): string   // sessionDir($id) . '/NOTES.md'
public function noteGroupPath(string $gid): string     // workspaceGroupDir($gid) . '/NOTES.md'
```

## CLI verb

graveyard is a procedural `switch ($sub)` CLI (`bin/graveyard`), **not** the
attributed-handler pattern. The new verb matches the file it lives in; it does
**not** introduce a lone attributed handler. It mirrors `rename` — the closest
existing analog (fuzzy session resolve + `--workspace` group variant).

- `graveyard note <target>` → `resolveTombstoneFuzzy(<target>)` →
  `noteSessionPath(session_id)`
- `graveyard note -ws|--workspace <group>` → `resolveGroup(<group>)` →
  `noteGroupPath(group_id)`

Ambiguity / no-match errors copy the exact block used by `rename`/`show`
("… is ambiguous — narrow it or pass a full session-id" / "No buried session
matches …").

**TDD split.** All logic goes in a testable class method; `bin/graveyard` keeps
only the untestable editor-launch seam:

- `Graveyard::ensureSessionNote(string $ref): string` — resolve target, create the
  parent dir if needed, create `NOTES.md` seeded with a single `# <title>\n\n`
  heading if absent, return the absolute path. Pure of HTTP/editor I/O; unit-tested.
- `Graveyard::ensureGroupNote(string $prefix): string` — same for a plot.
- `bin/graveyard case 'note':` — call the right ensure-method based on
  `--workspace|-ws`, then launch the editor exactly as `showTombstone` does
  (`code -r` if `code` on PATH, else `$EDITOR`), and print `Opened <path>`.

The seed heading uses the target's display title so a brand-new note isn't empty
in the modal. It is plain markdown (`#`), not raw HTML, so the renderer treats it
normally.

## Rendering (web server)

Parallel to how transcripts already reach the modal (`/page-data/<id>.js` →
`renderTranscriptJs` → `window.GYT[id]` → `textContent`). Notes get their own
channel so the transcript path is untouched:

1. **`Graveyard::renderNoteHtml(string $md): string`** — PURE. `league/commonmark`
   configured safe:
   - `html_input => 'strip'` (raw HTML in the note is dropped, not rendered)
   - `allow_unsafe_links => false`
   - Autolink extension on, so a bare `https://…slack…` / issue URL becomes a link.
   Returns sanitized HTML. This is the single place markdown becomes HTML.

2. **`Graveyard::renderNoteJs(string $key): ?string`** — I/O read-only. The route
   key is a session_id for a stone and a group_id for a plot; both are UUIDs and
   indistinguishable by shape, so the method checks `noteSessionPath($key)` first,
   then `noteGroupPath($key)`, and reads whichever exists (a session dir and a
   workspace dir can never collide on the same UUID). Returns `null` when neither
   holds a `NOTES.md` (router → 404), else
   `window.GYN[<key>] = "<html>";` (`json_encode` with `JSON_HEX_TAG`, same escaping
   the transcript payload uses).

3. **Router** (`bin/graveyard_router.php`): new route above the static fallthrough,
   mirroring the `.js` route —
   `GET /page-data/<key>.note.js` → `renderNoteJs($key)` → 404 or
   `Content-Type: application/javascript`.

4. **`data-has-note`** — at page render, `stoneHtml()` emits
   `data-has-note="1"` when `noteSessionPath(id)` exists; the plot partial emits it
   when `noteGroupPath(gid)` exists. The template uses it to (a) show a 📝 marker on
   the headstone/plot and (b) decide whether to fetch the note and show the note
   pane in the modal.

5. **Modal (`src/templates/graveyard-page.html`).** The note pane sits above the
   transcript in `#plot` (session) and in the detail area of `#plotmodal` (plot).
   When a modal opens for a target with `data-has-note`, JIT-fetch
   `/page-data/<key>.note.js` and set the pane via **`innerHTML`** from `window.GYN`.

### The one deliberate breach of the textContent invariant

The modal renders *everything* via `textContent` today; `TuiTranscript.php:5-21`
records that a markdown→HTML pass was **rejected** to keep the modal free of any
`innerHTML` injection sink. The note pane is the first `innerHTML` sink, opened
deliberately and only here:

- the note is authored by JT himself, not untrusted input;
- the page is localhost-only (`127.0.0.1`);
- CommonMark strips raw HTML server-side (`html_input => 'strip'`) before the
  string ever reaches the page, so the value assigned to `innerHTML` contains only
  the tags CommonMark itself emits.

The sink gets a comment stating this, so the next reader sees the fence was opened
on purpose and why it stays shut for transcripts.

## Tests (TDD — write first, watch fail, then implement)

Mirror `tests/Graveyard/GraveyardRenameDeleteTest.php` (verb + resolution +
`--workspace` variant) and `GraveyardPageTest.php` (modal markup / escaping /
JIT payloads). Fresh throwaway store per test via `TestCase::setUp` (`$this->gy`,
`GRAVEYARD_ROOT` redirected).

1. `ensureSessionNote` — creates seeded `NOTES.md` at `sessions/<id>/NOTES.md`,
   returns its path; ambiguous ref → `exitErr`; unknown ref → `exitErr`.
2. `ensureGroupNote` — creates seeded `NOTES.md` at `workspaces/<gid>/NOTES.md`;
   unknown/ambiguous group handled.
3. `renderNoteHtml` — a bare URL autolinks to `<a href>`; a `<script>` in the
   source does **not** survive into the output.
4. `renderNoteJs` — `null` when no note; `window.GYN[...]=...` payload when present,
   with `</script>` in the note not breaking out of the block.
5. `pageHtml`/`stoneHtml` — `data-has-note="1"` present iff the note file exists;
   plot partial likewise.
6. Router — `/page-data/<id>.note.js` returns the payload / 404
   (extend `GraveyardPageServerContractTest`).
7. Deletion — `purgeSession`/`deleteGroup` remove the dir *including* `NOTES.md`
   (pins the "no new cleanup code" claim).

## Coupled interfaces updated in the same change

Per the repo's "interface surfaces move together" rules:

- `bin/graveyard` `buildDocs` — a `note <id>` and `note -ws <group>` help entry.
- `zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh` — a
  `note:` command entry in `_graveyard` and a `note)` arguments case carrying
  `-ws/--workspace` and a positional target.
- `agent-skills/graveyard/SKILL.md` — a short section on adding human context via
  `note`, since the skill is graveyard's agent-facing interface.

## Non-goals / YAGNI

- No inline-text or `--file` authoring (editor only).
- No feeding the note to the resurrected agent (future phase).
- No per-note history/versioning — `NOTES.md` is a plain file JT overwrites.
- No parent-plot note bleed into member modals (strict 1:1).
