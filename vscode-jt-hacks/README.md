# JT Hacks

Personal VS Code / Cursor utilities (not published). Source lives in this repo; editors load it via symlinks.

## Install

From this directory:

```bash
./install.sh
```

- **Default:** symlinks into **both** Cursor and VS Code.
- **Flags:** `./install.sh --cursor` (Cursor only), `./install.sh --vscode` (VS Code only). Passing both matches the default.
- **`--force`:** replace a symlink that already exists but points at a different path.
- **`--no-clean`:** skip removing older `publisher.name-<oldversion>` symlinks that still pointed at this folder (after a version bump in `package.json`).

The install script reads `publisher`, `name`, and `version` from `package.json` so the extension folder name stays correct.

### One-liners (manual)

Equivalent to what the script does (requires `python3` for the id):

```bash
cd /path/to/vscode-jt-hacks
ID="$(python3 -c "import json; p=json.load(open('package.json')); print(p['publisher']+'.'+p['name']+'-'+p['version'])")"
ln -sfn "$(pwd)" "${HOME}/.cursor/extensions/${ID}"
ln -sfn "$(pwd)" "${HOME}/.vscode/extensions/${ID}"
```

Use `ln -sfn` only for the editor(s) you want; omit one `ln` line if you only use Cursor or only VS Code.

After installing or changing the symlink, **Developer: Reload Window** (or restart the app).

## Version bumps

Bump `version` in `package.json`, then run `./install.sh` again. The script creates the new `publisher.name-version` name and, unless you passed `--no-clean`, removes stale symlinks under the same `publisher.name-*` prefix that still pointed at this directory.

## Try changes (Extension Development Host)

Open this folder in Cursor/VS Code, run **Run Extension** (F5). Use the **Extension Development Host** window for commands; the debugger window does not register the dev extension in the palette the same way.
