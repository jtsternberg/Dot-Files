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
  **Stop and show JT the conflicts.** Never delete or move them to make room;
  that config may be the only copy.
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

## 4. Open in Obsidian

Needs the Obsidian CLI (`obsidian`, Settings → General → Command line interface)
and the app running (`open -a Obsidian`, then wait a few seconds).

Registered vaults are listed by `obsidian vaults verbose` (name, tab, path).

- **Already registered** → `open "obsidian://open?path=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$VAULT")"`.
  If that vault window was already open, reload it so it rereads the config:
  `obsidian vault="<name>" command id=app:reload`.
- **Not registered** → the `obsidian://` URI can't open it. Use the IPC call
  behind Obsidian's own "Open folder as vault" (internal API; `true` = opened):

  ```bash
  obsidian eval code="window.electron.ipcRenderer.sendSync('vault-open', '$VAULT', false)"
  ```

  If it returns anything but `true`, or `eval` isn't available, tell JT to use
  "Open folder as vault" in Obsidian's vault switcher and pick `$VAULT`.

## 5. Verify Obsidian loaded the config

`<name>` = the vault's name in `obsidian vaults verbose`.

```bash
obsidian vault="<name>" theme
obsidian vault="<name>" snippets:enabled
```

Theme must equal `cssTheme` in `~/.dotfiles/obsidian/appearance.json`, and the
enabled snippets must match its `enabledCssSnippets`. Mismatch → reload
(step 4) and check again; still wrong → report both values.

Report: vault path, linked entries, and the theme/snippet check result.
