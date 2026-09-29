---
name: setup-obsidian-vault
description: Use when JT wants a folder set up as an Obsidian vault with his shared dotfiles Obsidian config (theme, snippets, hotkeys, appearance) — "set up an obsidian vault at <path>", "link my obsidian config into <path>", "make <path> a vault". Runs `setup-obsidian-vault`, verifies the links, and opens the vault in Obsidian.
---

# Set Up an Obsidian Vault

Drives `~/.dotfiles/bin/setup-obsidian-vault`, which symlinks each top-level
entry of `~/.dotfiles/obsidian/` into the vault's `.obsidian/`. Read its header
(`setup-obsidian-vault --help`) for how it picks the `.obsidian/` dir.

Input: one path. Missing → ask for it. Resolve it to an absolute path (`VAULT`).
The dir doesn't exist → confirm with JT, `mkdir -p` it, and use `--new` below.

`obsidian-vault-chat <path>` starts a Sonnet session here that runs this skill.

## 1. Dry run

```bash
setup-obsidian-vault -n "$VAULT"
```

- `No .obsidian dir found` → not a vault yet. Confirm with JT that `$VAULT`
  itself should become the vault root, then use `--new` in both the dry run and
  the real run.
- `Multiple .obsidian dirs at the same depth` → show the list, ask which one.
- `Aborting; nothing changed` → the vault already has its own config there.
  **Stop and show JT the conflicts** (with contents — `{}` or a few default
  keys means Obsidian wrote them on first open). There is no force flag. Only on
  JT's OK, move them aside, never `rm`:
  `mkdir "$VAULT/.obsidian.bak-$(date +%Y%m%d%H%M%S)"` and `mv` the conflicting
  entries into it, then re-run.
- The printed `Vault config:` dir may be below `$VAULT`. From here on, `VAULT`
  is its parent (the real vault root).

## 2. Link

Same command without `-n` (keep `--new` if step 1 needed it). Every line should
be `linked` or `ok`.

## 3. Verify the links

```bash
ls -la "$VAULT/.obsidian"
```

Every entry of `~/.dotfiles/obsidian/` except dotfiles and OS junk must be a
symlink into it, and none may dangle (`test -e` on each). A vault's own extra
files (`workspace.json`, `plugins/`, ...) are expected and fine.

## 4. Open and verify in Obsidian

```bash
~/.dotfiles/obsidian/.claude/skills/setup-obsidian-vault/scripts/open-vault.sh "$VAULT"
```

It starts Obsidian if needed, opens the vault by path (registered or not),
reloads a window that predates the links, and checks the loaded theme and
snippets against the vault's `appearance.json`. Exit 0 prints `Verified:`.
On failure, report its message; if it says `vault-open` failed, tell JT to
use "Open folder as vault" in the vault switcher, then re-run the script.

Needs the Obsidian CLI: Settings → General → Command line interface.

Report: vault path, linked entries, and the `Verified:` line.

## Footguns

- **Link before opening.** Opening a folder in Obsidian writes default
  `app.json`, `appearance.json`, `core-plugins.json` at once, and those then
  conflict with the links (step 1).
- **`obsidian help`, not `obsidian --help`.** The CLI's commands are bare words.
- **The CLI exits 0 on failure** (`Vault not found.`) and blocks while the app
  is still booting. Wrap calls in `timeout`; check output, not exit code.
- **Target vaults by id, never by name**: `obsidian vault=<id> ...`, the id
  being the vault's key in `~/Library/Application Support/obsidian/obsidian.json`.
  A name is the folder's basename, two vaults can share it, and a name-targeted
  call then reads the wrong window. A call with no `vault=` hits whichever
  window was last active.
- **Renaming a vault renames its folder** (the switcher's rename moves it on
  disk). To fix a name collision, rename the folder with JT's OK.
