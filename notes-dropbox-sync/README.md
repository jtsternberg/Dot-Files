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

```bash
cp ~/.dotfiles/notes-dropbox-sync/notes-dropbox-sync.plist ~/Library/LaunchAgents/
launchctl load ~/Library/LaunchAgents/notes-dropbox-sync.plist
launchctl start notes-dropbox-sync
```

Check status:
```bash
launchctl list | grep notes-dropbox-sync
```

### Linux (systemd)

```bash
ln -s ~/.dotfiles/notes-dropbox-sync/notes-dropbox-sync.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now notes-dropbox-sync
```

Check status:
```bash
systemctl --user status notes-dropbox-sync
```

## Files

- `../bin/notes-dropbox-sync` — the watcher/rsync script (used by both services)
- `notes-dropbox-sync.service` — Linux systemd user service
- `notes-dropbox-sync.plist` — macOS launchd agent
