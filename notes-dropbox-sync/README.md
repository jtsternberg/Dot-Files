# notes-dropbox-sync

A cross-platform background service that acts as a helper to the [remotely-save](https://github.com/remotely-save/remotely-save) Obsidian plugin. When Obsidian isn't running, remotely-save can't sync — this fills the gap by watching `~/Documents/Notes` for changes and rsyncing to Dropbox, so the Dropbox daemon can push changes to the cloud.

## Prerequisites

| Platform | Tool | Install |
|----------|------|---------|
| macOS | `fswatch` | `brew install fswatch` |
| Linux | `inotify-tools` | `sudo apt install inotify-tools` |

Dropbox must be installed and running on the host machine.

## Setup

### macOS (launchd)

**1. Build the native launcher** (required for Full Disk Access — see [Why a launcher?](#why-a-launcher) below):

```bash
cd ~/.dotfiles/notes-dropbox-sync
cc -O2 -o notes-dropbox-sync-launcher notes-dropbox-sync-launcher.c
```

**2. Grant Full Disk Access** to the compiled launcher:

> System Settings → Privacy & Security → Full Disk Access → **+** → Cmd+Shift+G → `~/.dotfiles/notes-dropbox-sync/notes-dropbox-sync-launcher`

**3. Install and start the service:**

```bash
cp ~/.dotfiles/notes-dropbox-sync/notes-dropbox-sync.plist ~/Library/LaunchAgents/
launchctl load ~/Library/LaunchAgents/notes-dropbox-sync.plist
launchctl start notes-dropbox-sync
```

**Check status:**
```bash
launchctl list | grep notes-dropbox-sync
```

**View logs:**
```bash
tail -f /tmp/notes-dropbox-sync.log
tail -f /tmp/notes-dropbox-sync.error.log
```

### Linux (systemd)

**1. Install and start the service:**

```bash
ln -s ~/.dotfiles/notes-dropbox-sync/notes-dropbox-sync.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now notes-dropbox-sync
```

**Check status:**
```bash
systemctl --user status notes-dropbox-sync
```

**View logs:**
```bash
journalctl --user -u notes-dropbox-sync -f
```

**Stop / restart:**
```bash
systemctl --user stop notes-dropbox-sync
systemctl --user restart notes-dropbox-sync
```

**Troubleshooting:**

- If `inotifywait` is missing: `sudo apt install inotify-tools`
- The service auto-restarts on failure (`RestartSec=5`) — check logs if it keeps cycling
- Dropbox must be installed and running; the service watches `~/Dropbox/Apps/remotely-save/Notes`

## Files

- `../bin/notes-dropbox-sync` — the watcher/rsync script (used by both services)
- `notes-dropbox-sync-launcher.c` — native macOS launcher (source)
- `notes-dropbox-sync-launcher` — compiled launcher binary (not tracked in git — build locally)
- `notes-dropbox-sync.plist` — macOS launchd agent
- `notes-dropbox-sync.service` — Linux systemd user service

## Excluded from sync

The rsync excludes files that don't belong in the cloud copy:

- `.git`, `.claude`, `.vscode` — tooling metadata
- `.DS_Store` — macOS junk
- `*.orig` — merge/conflict artifacts
- `.fb-review-profile`, `.mcp.json` — local config

## How sync robustness works

The naïve version of this script (just `inotifywait` → `rsync --delete`) loses
data. The Dropbox daemon and the [remotely-save](https://github.com/remotely-save/remotely-save)
plugin both write files via "delete-then-recreate" or atomic-rename patterns,
which surface to inotify as a `MOVED_FROM` event followed (sometimes much
later) by a matching `MOVED_TO`. If `rsync --delete` runs in that gap, it
sees a "missing" file in Dropbox and faithfully deletes it from Notes.

This script defends against that with three layered checks before any
deletion is propagated:

### Layer 1 — Dropbox quiescent gate (Linux only)

Before any rsync runs (in either direction), the script polls
`dropbox status` until it reports `Up to date` or `Idle`. If Dropbox is
mid-sync, we wait. Hard-capped by `DROPBOX_QUIESCENT_TIMEOUT` (default 60s)
to avoid hanging if the CLI itself hangs or the daemon dies.

This alone catches the original bug: a missing file due to a mid-flight
write shows up as `Syncing 1 file...`, and we wait for the daemon to
finish before rsync ever inspects the folder.

macOS Dropbox doesn't ship a CLI, so on macOS this layer is a no-op (the
two layers below still apply). If `dropbox` isn't installed on Linux
either, the layer also no-ops.

### Layer 2 — Two-pass copy/delete with grace window

The Dropbox→Notes direction never deletes on its first rsync pass. It
runs in two phases:

1. **Copy/update pass** — picks up new files and edits. No `--delete`.
   Cannot lose data.
2. **Dry-run check** — does rsync want to delete anything?
3. If yes: **wait `DELETE_GRACE` seconds** (default 30s), then run a
   second dry-run. If the "missing" files reappeared in Dropbox during
   the grace window (the original bug pattern), no deletions happen.
   The cross-direction lock is refreshed every 5s during the wait so
   the Notes watcher correctly treats concurrent activity as echo.
4. **Delete pass** — only the files still missing after the grace
   window get deleted.

### Layer 3 — `MAX_DELETE` safety brake

If more than `MAX_DELETE` files (default 3) would be removed in a single
sync cycle, the deletion pass is **skipped entirely** — the copy/update
already happened, but no files are removed. A loud diagnostic line goes
to the journal/log so you can investigate.

Phone-driven deletions of 4+ files at once are essentially never
legitimate; a glitched Dropbox state, a bad cloud sync, or a runaway
plugin is far more likely. This layer accepts the trade-off of leaving
stale files behind in exchange for never losing notes en masse. Real
bulk deletions can be done by hand.

### Configuration

All three layers are tunable via `~/.config/notes-dropbox-sync/config`:

```sh
# Sync direction toggles (default: both true)
SYNC_NOTES_TO_DROPBOX=true
SYNC_DROPBOX_TO_NOTES=true

# Robustness knobs
DROPBOX_QUIESCENT_TIMEOUT=60   # Layer 1 (Linux): max poll wait, seconds
DELETE_GRACE=30                # Layer 2: grace window before propagating deletions
MAX_DELETE=3                   # Layer 3: refuse to delete more than this many files at once
```

Bump `DELETE_GRACE` if you have a slow network or large incoming syncs.
Raise `MAX_DELETE` if you genuinely want bulk mobile deletions to
propagate without manual intervention.

### Recovering from a past deletion

If an earlier version of the script (before these defenses landed) lost
a note, it's still in `~/Documents/Notes/.git`. Find the deleting
commit and the one immediately before it:

```bash
cd ~/Documents/Notes
git log --diff-filter=D -- 'path/to/lost-note.md'   # finds the deletion commit
git show <commit-before-deletion>:'path/to/lost-note.md' > 'path/to/lost-note.md'
```

## Why a launcher?

macOS requires Full Disk Access (FDA) to read/write Dropbox's `~/Library/CloudStorage/` path. FDA is granted per-binary, but shell scripts aren't binaries — they're interpreted by bash, so macOS sees bash as the process. Granting FDA to `/bin/bash` is too broad.

The launcher is a tiny compiled C binary that `exec`s the real script. macOS tracks it as its own process, so FDA granted to the launcher propagates to child processes (rsync, fswatch). This keeps permissions scoped to just this service.

Linux doesn't have this restriction, so the launcher is macOS-only.
