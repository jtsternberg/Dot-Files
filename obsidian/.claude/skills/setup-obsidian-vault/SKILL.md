---
name: setup-obsidian-vault
description: Use when JT wants a folder set up as an Obsidian vault with his shared dotfiles Obsidian config (theme, snippets, hotkeys, appearance) — "set up an obsidian vault at <path>", "link my obsidian config into <path>", "make <path> a vault", "give this vault a better name". Runs `setup-obsidian-vault`, verifies the links, and opens the vault in Obsidian.
---

# Set Up an Obsidian Vault

Drives `~/.dotfiles/bin/setup-obsidian-vault`, which symlinks each top-level
entry of `~/.dotfiles/obsidian/` into the vault's `.obsidian/`. Read its header
(`setup-obsidian-vault --help`) for how it picks the `.obsidian/` dir.

Input: one path, or the name of a vault Obsidian already knows (its path is in
`obsidian-cli vaults verbose`, name TAB path). Missing → ask for it. Resolve it to
an absolute path (`VAULT`).
The dir doesn't exist → confirm with JT, `mkdir -p` it, and use `--new` below.

`obsidian-vault-chat <path>` starts a Sonnet session here that runs this skill.
`init-obsidian-vault [DIR]` is the non-interactive version (steps 2 and 4 in one
command); this skill exists for the judgment calls around conflicts.

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
open-obsidian-vault "$VAULT"                      # or: --name "<display name>" "$VAULT"
```

Offer `--name` when the folder's basename is generic (`docs`, `notes`) or
another known vault already has it; see Footguns for what it does.

It starts Obsidian if needed, opens the vault by path (registered or not),
reloads a window that predates the links, and checks the loaded theme and
snippets against the vault's `appearance.json`. Exit 0 prints `Verified:`.
On failure, report its message; if it says `vault-open` failed, tell JT to
use "Open folder as vault" in the vault switcher, then re-run it.

Needs the Obsidian CLI (`obsidian-cli`): Settings → General → Command line
interface.

Report: vault path, linked entries, and the `Verified:` line.

## Footguns

- **Link before opening.** Opening a folder in Obsidian writes default
  `app.json`, `appearance.json`, `core-plugins.json` at once, and those then
  conflict with the links (step 1).
- **Call `obsidian-cli`, never `obsidian`.** `obsidian` on PATH is the app
  binary itself: it launches Obsidian when it isn't running and hangs while it
  boots. `obsidian-cli` talks to the running app and exits 1 at once if it
  can't reach it.
- **`obsidian-cli help`, not `--help`.** The CLI's commands are bare words.
- **It still exits 0 on failure** (`Vault not found.`, `Error:` from `eval`).
  Check the output, not the exit code.
- **Target vaults by id, never by name**: `obsidian-cli vault=<id> ...`, the id
  being the vault's key in `~/Library/Application Support/obsidian/obsidian.json`.
  A name is the folder's basename, two vaults can share it, and a name-targeted
  call then reads the wrong window. A call with no `vault=` hits whichever
  window was last active, and `vault=<id>` of a closed vault opens a window
  for it.
- **Renaming a vault in Obsidian renames its folder.** For a different display
  name (or to fix two vaults sharing one), use
  `open-obsidian-vault --name "<name>" "$VAULT"`: it opens the vault through a
  `~/.obsidian-vaults/<name>` symlink and drops the old vault-list entry. Ask JT
  for the name; it closes that vault's open window.
