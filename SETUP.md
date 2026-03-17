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

## Notes

- `bin/` scripts are PHP — ensure `php` is in PATH
- `~/.composer/vendor/bin` is in PATH via `.zshenv` — install Composer if using those tools
- `nvm` is defined in `.zshenv` but not auto-loaded (use `loadnvm` alias to load on demand)
- `EDITOR` defaults to `cursor -w`; falls back to `vim` on machines without Cursor installed
