# Retired: notes-dropbox-sync

`bin/notes-dropbox-sync` mirrored `~/Documents/Notes` into the Dropbox
`Apps/remotely-save/Notes` folder with rsync, as a helper for the Obsidian
remotely-save plugin: remotely-save is a plugin, so it only syncs while Obsidian
is open, and the script covered vault writes made when it wasn't — git pulls,
CLI edits, agent sessions over ssh on the Linux box, the Claude Telegram bot.

Retired 2026-08-24. Code and units are in git history; the final version is the
parent of the commit that removed them.

## Why

**rsync was the wrong tool for this boundary.** It makes one directory look like
another. It has no concept of who changed a file or when, so it cannot tell a
file that is new *here* from one that was deleted *there* — the two are the same
observation. Every safety property had to be bolted on from outside:
`--delete` guarded by a dry run, a 30-second grace window, a `MAX_DELETE=3`
brake, a Dropbox-daemon quiescence gate, three separate exclusion lists.

That accumulation was the signal. The guards worked and the tool was still
unsafe: no invocation passed `--update`, and rsync's default quick-check
transfers whenever size or mtime differs *in either direction*. So a push
overwrote **newer** content in Dropbox with an **older** local copy. That failure
is invisible where anyone would look for it — a deletion appears in Dropbox's
deleted-files list, but a stale overwrite appears only in that one file's
revision history, which nothing enumerates.

remotely-save (and its BYOC fork) keeps a per-file baseline and does real
reconciliation. It is the right owner of the Mac ↔ Dropbox ↔ phone boundary, and
it needs no guards because it has the information rsync structurally lacks.

## What it actually did, in the end

Worth recording, because the failure was silent for sixteen weeks and nothing
alarmed:

- The design (b23773a) elected the Linux box as the single git-writer: the Mac
  ran push-only, the Linux box ran both directions. Coherent, and correct.
- The Linux box's Dropbox daemon stopped on **2026-05-03** (`~/.dropbox/apex.sqlite3`
  last written 17:01 that day; no autostart entry, no unit). Its Dropbox folder
  became an orphaned local directory.
- Vault commits carrying the pull direction's marker message stop the same day:
  11 in March, 587 in April, 115 in May, then none.
- `wait_for_dropbox_quiescent()` is documented to *proceed* when the daemon
  reports not running, so the push half ran on undisturbed — 671 pushes and 0
  pulls in the last month of journal, into a folder nothing was reading.
- With no machine pulling, the Mac's `--delete` had no counterparty: a note
  created on the phone reached Dropbox, was never copied into any vault, and was
  removed from Dropbox on the next push. Never in git, so invisible to every
  recovery path except Dropbox's 30-day window.

The lesson that generalises is in `CLAUDE.md` § *If Two Mechanisms Sync One Tree,
They Share One Exclusion List*. The second one is here: **a scheduled job whose
precondition can silently become false needs a dead-man's switch.** Refusing to
run and saying so would have turned this into one loud failure on 2026-05-03.

## The accepted trade

Vault writes made while Obsidian is closed reach the phone when Obsidian is next
opened. That is the cost of retiring the script, and it was accepted knowingly.

## Don't rebuild this

One tool per boundary:

- machine ↔ machine — **git** (`~/Documents/Notes` is a repo with a remote)
- Mac ↔ Dropbox ↔ phone — **remotely-save / BYOC**

Nothing spans both with a file-level mirror. If the "Obsidian is closed" gap
needs closing again, close it with something that has a per-file baseline, or by
opening Obsidian — not by copying a tree. The phone has no git, so it is the one
device where a wrong resolution is unrecoverable after 30 days; it gets the tool
that reconciles, not the tool that mirrors.
