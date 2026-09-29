#!/usr/bin/env bash
# =============================================================================
# open-vault.sh — open VAULT in Obsidian and verify it loaded the linked config.
#
# Opens by path through the `vault-open` IPC call behind Obsidian's own "Open
# folder as vault". That call registers an unknown folder, opens a closed
# vault, and focuses an open one, so there is one path for every case. It is an
# internal API (checked against Obsidian 1.14.2); if it stops returning `true`,
# this fails loudly rather than guessing.
#
# Every CLI call after opening targets the vault by its obsidian.json id, never
# by name: names are folder basenames, two vaults can share one, and a
# name-targeted call then silently reads the wrong window. A call with no
# `vault=` at all hits whichever window was last active.
#
# Usage:
#   open-vault.sh VAULT_ROOT
# =============================================================================
set -euo pipefail

VAULTS_JSON="$HOME/Library/Application Support/obsidian/obsidian.json"
CLI_TIMEOUT=10

die() { echo "open-vault: $*" >&2; exit 1; }

[ $# -eq 1 ] || die "usage: open-vault.sh VAULT_ROOT"
[ "$(uname -s)" = Darwin ] || die "macOS only"
[ -d "$1/.obsidian" ] || die "no .obsidian dir in $1; run setup-obsidian-vault first"
VAULT="$(cd "$1" && pwd -P)"
command -v obsidian >/dev/null || die "obsidian CLI not on PATH (Settings → General → Command line interface)"

# The CLI exits 0 even for "Vault not found.", so callers check the output.
# eval results come back prefixed with "=> ".
cli() { timeout "$CLI_TIMEOUT" obsidian "$@" 2>&1 || true; }
eval_in() {
	local out
	out="$(cli vault="$1" eval code="$2")"
	[ "${out#=> }" != "$out" ] || return 1
	printf '%s\n' "${out#=> }"
}

vault_id() {
	python3 - "$VAULTS_JSON" "$VAULT" <<-'EOF'
	import json, os, sys
	try:
	    vaults = json.load(open(sys.argv[1]))["vaults"]
	except (OSError, ValueError, KeyError):
	    sys.exit(0)
	for vid, v in vaults.items():
	    if os.path.realpath(v.get("path", "")) == sys.argv[2]:
	        print(vid)
	        break
	EOF
}

# The CLI blocks while the app is still booting, so wait for it to answer.
pgrep -xq Obsidian || open -a Obsidian
for _ in $(seq 30); do
	[ -n "$(cli version)" ] && break
	sleep 1
done
[ -n "$(cli version)" ] || die "Obsidian CLI never answered; is the app running?"

path_js="$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$VAULT")"
opened="$(cli eval code="window.electron.ipcRenderer.sendSync('vault-open', $path_js, false)")"
if [ "$opened" != "=> true" ]; then
	die "vault-open returned: ${opened:-nothing}
  No vault window may be open for the CLI to run in. Open $VAULT with
  \"Open folder as vault\" in Obsidian's vault switcher, then re-run."
fi

ID=
for _ in $(seq 20); do
	ID="$(vault_id)"
	if [ -n "$ID" ] && [ "$(eval_in "$ID" 'app.vault.adapter.basePath' || true)" = "$VAULT" ]; then
		break
	fi
	ID=
	sleep 1
done
[ -n "$ID" ] || die "$VAULT never showed up as a loaded vault"
echo "Opened: $VAULT (vault id $ID)"

# Expected values come from the vault's own (linked) appearance.json, so this
# checks what Obsidian loaded against what is on disk.
expected="$(python3 -c '
import json, sys
a = json.load(open(sys.argv[1]))
print(json.dumps({"theme": a.get("cssTheme", ""), "snippets": sorted(a.get("enabledCssSnippets", []))}, separators=(",", ":")))
' "$VAULT/.obsidian/appearance.json")"

loaded() {
	eval_in "$ID" 'JSON.stringify({theme: app.customCss.theme, snippets: [...app.customCss.enabledSnippets].sort()})'
}

actual="$(loaded || true)"
if [ "$actual" != "$expected" ]; then
	# A window that was open before the links existed still holds the old config.
	cli vault="$ID" command id=app:reload >/dev/null
	for _ in $(seq 15); do
		sleep 1
		actual="$(loaded || true)"
		[ "$actual" = "$expected" ] && break
	done
fi

if [ "$actual" != "$expected" ]; then
	die "config mismatch after reload
  expected: $expected
  loaded:   ${actual:-nothing}"
fi
echo "Verified: $actual"
