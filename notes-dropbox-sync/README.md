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

## Why a launcher?

macOS requires Full Disk Access (FDA) to read/write Dropbox's `~/Library/CloudStorage/` path. FDA is granted per-binary, but shell scripts aren't binaries — they're interpreted by bash, so macOS sees bash as the process. Granting FDA to `/bin/bash` is too broad.

The launcher is a tiny compiled C binary that `exec`s the real script. macOS tracks it as its own process, so FDA granted to the launcher propagates to child processes (rsync, fswatch). This keeps permissions scoped to just this service.

Linux doesn't have this restriction, so the launcher is macOS-only.
