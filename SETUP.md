# Dotfiles Setup Guide

Personal dotfiles for [jtsternberg](https://github.com/jtsternberg). Originally built for macOS, with Linux/Ubuntu support added over time.

---

## Prerequisites

These are required for the dotfiles to install and load correctly.

| Tool | Why |
|---|---|
| `git` | Clone the repo and manage submodules |
| `zsh` | The shell everything is configured for |
| `php` | `symdotfiles` installer and all `bin/` scripts are PHP |
| Oh My Zsh | Hardcoded in `.zshrc` — https://ohmyz.sh/#install |

### macOS

```bash
brew install git php zsh
```

### Linux (Ubuntu/Debian)

```bash
sudo apt update && sudo apt install -y git php-cli zsh curl
```

> After installing Oh My Zsh, say **no** when it asks to change your default shell — do it manually after symlinking (step 5).

---

## Install

### 1. Clone the repo

```bash
git clone git@github.com:jtsternberg/Dot-Files.git ~/.dotfiles
cd ~/.dotfiles
```

### 2. Init submodules

```bash
git submodule update --init --recursive
```

### 3. Preview symlinks

```bash
php symdotfiles testrun
```

### 4. Create symlinks

```bash
php symdotfiles hard
```

This symlinks all dotfiles from `~/.dotfiles/` to `$HOME`.

### 5. Set zsh as default shell

```bash
chsh -s $(which zsh)
# Log out and back in, or: exec zsh
```

---

## Platform-Specific Tools

These tools are referenced in aliases and shell config. The shell will load fine without them — broken aliases will just fail when called.

### 1Password CLI (`op`)

https://developer.1password.com/docs/cli/get-started/

### drplr CLI

https://github.com/jtsternberg/drplr

### Composer (PHP)

https://getcomposer.org/download/

### lazygit (`lg` alias)

https://github.com/jesseduffield/lazygit?tab=readme-ov-file#installation

### fzf

https://github.com/junegunn/fzf?tab=readme-ov-file#installation

### tree (`structure` alias)

**macOS:** `brew install tree`
**Linux:** `sudo apt install tree`

### Linux clipboard + speech (`pbcopy`, `say` aliases)

```bash
sudo apt install xclip espeak
```

---

## Machine-Local Config

Private/machine-specific config (not synced to git) goes in:

```
~/.dotfiles/private/additonal_aliases.sh
```

This file is sourced automatically if it exists.

---

## Platform-Specific Config (version-controlled)

| File | Purpose |
|---|---|
| `.macoszshrc` | macOS-only aliases and OMZ plugins (`brew`, `macos`) |
| `.linuxzshrc` | Linux compat aliases (`pbcopy`→`xclip`, `say`→`espeak`, etc.) |

These are loaded automatically by `.zshrc` based on `$OSTYPE`. Edit them to add platform-specific customizations that should be tracked in git.

---

## Background Services (macOS LaunchAgents)

macOS-only background jobs live as git-tracked scripts + plists in the repo and
are installed as user LaunchAgents (not symlinked by `symdotfiles`).

### mempalace-watchdog

`bin/mempalace-watchdog.sh` (+ `bin/com.jt.mempalace-watchdog.plist`) is a daily
health watchdog for the local [mempalace](https://github.com/MemPalace/mempalace)
palace. It exists because on 2026-07-08 mempalace's destructive HNSW-quarantine
bug silently deleted ~47k vectors. Two upstream issues track the two guards that
can do this: [MemPalace/mempalace#1710](https://github.com/MemPalace/mempalace/issues/1710)
for `quarantine_invalid_hnsw_metadata` and
[MemPalace/mempalace#2510](https://github.com/MemPalace/mempalace/issues/2510) for
`quarantine_stale_hnsw`, the guard that actually destroyed this palace. Each morning
at 9:23 it checks drawers/HNSW divergence, watches for quarantine events (`.drift-*`
dirs + new `hook.log` quarantine lines), verifies the installed build carries the
filtered-search fallback (#2373 — hand-applied through 3.9.0, upstream since
3.10.0), keeps a weekly backup of the palace on `/Volumes/Secondary`, and reports
new releases / issue movement. A healthy day logs only; anything wrong posts a
macOS notification.

Two things it deliberately does not assume. A backup never file-copies
`chroma.sqlite3` — the live DB goes through `sqlite3 ".backup"` and a
`PRAGMA quick_check` gates the `.partial` → dated rename, because macOS
`/usr/bin/rsync` is openrsync and aborts on a database growing underneath it. And a
closed upstream issue is not a shipped fix: release-landed alerts require a release
published *after* the close, and self-retirement requires **both** #1710 and #2510
closed with the installed build postdating both. Alerts also fire once per release
rather than once per morning — upstream state changes once and then stays changed,
so alerting on the state instead of the change cried wolf daily. On retirement it writes a persistent
`retired` marker (checked first on every run), removes its LaunchAgent symlink, and
boots out — it never deletes the tracked script/plist.

The LaunchAgent runs `/bin/bash`, which needs Full Disk Access to reach
`/Volumes/Secondary` at all; grant it to `/bin/bash` specifically, not to Homebrew's
bash, whose Cellar path carries a version and changes on upgrade. Log:
`~/.local/state/mempalace-watchdog/watchdog.log`.

```bash
mempalace-watchdog.sh install     # symlink plist into ~/Library/LaunchAgents + bootstrap
mempalace-watchdog.sh status      # launchd + plist + retirement + log tail
mempalace-watchdog.sh uninstall   # bootout + remove symlink (log/state kept)
mempalace-watchdog.sh             # run once now (for testing)
```

---

## Notes

- `bin/` scripts are PHP — ensure `php` is in PATH
- `~/.composer/vendor/bin` is in PATH via `.zshenv` — install Composer if using those tools
- `nvm` is defined in `.zshenv` but not auto-loaded (use `loadnvm` alias to load on demand)
- `EDITOR` defaults to `cursor -w`; falls back to `vim` on machines without Cursor installed
