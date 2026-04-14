#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

usage() {
	cat <<'EOF'
Install JT Hacks into Cursor and/or VS Code by symlinking this directory into each
editor’s extensions folder. The link name is publisher.name-version from package.json.

Usage:
  ./install.sh              Install into Cursor and VS Code (default)
  ./install.sh --cursor     Cursor only
  ./install.sh --vscode     VS Code only
  ./install.sh --cursor --vscode   Same as default

Options:
  --force     Replace an existing symlink that points somewhere else
  --no-clean  Do not remove older symlinks that pointed at this folder (different version)

EOF
}

FORCE=0
CLEAN_STALE=1
INSTALL_CURSOR=0
INSTALL_VSCODE=0
EXPLICIT=0

while [[ $# -gt 0 ]]; do
	case "$1" in
		-h | --help)
			usage
			exit 0
			;;
		--force)
			FORCE=1
			shift
			;;
		--no-clean)
			CLEAN_STALE=0
			shift
			;;
		--cursor)
			INSTALL_CURSOR=1
			EXPLICIT=1
			shift
			;;
		--vscode)
			INSTALL_VSCODE=1
			EXPLICIT=1
			shift
			;;
		*)
			echo "Unknown option: $1" >&2
			usage >&2
			exit 1
			;;
	esac
done

if [[ "$EXPLICIT" -eq 0 ]]; then
	INSTALL_CURSOR=1
	INSTALL_VSCODE=1
fi

PUBLISHER="$(python3 -c "import json; print(json.load(open('package.json'))['publisher'])")"
NAME="$(python3 -c "import json; print(json.load(open('package.json'))['name'])")"
EXT_ID="$(python3 -c "import json; p=json.load(open('package.json')); print(p['publisher'] + '.' + p['name'] + '-' + p['version'])")"

remove_stale_in() {
	local ext_root="$1"
	[[ -d "$ext_root" ]] || return 0
	local f dest base
	shopt -s nullglob
	for f in "$ext_root/${PUBLISHER}.${NAME}-"*; do
		[[ -L "$f" ]] || continue
		dest="$(readlink "$f")"
		base="$(basename "$f")"
		if [[ "$dest" == "$SCRIPT_DIR" && "$base" != "$EXT_ID" ]]; then
			rm "$f"
			echo "Removed stale symlink: $f -> $dest"
		fi
	done
	shopt -u nullglob
}

install_one() {
	local ext_root="$1"
	local label="$2"
	local target="${ext_root}/${EXT_ID}"

	mkdir -p "$ext_root"

	if [[ -L "$target" ]]; then
		local cur
		cur="$(readlink "$target")"
		if [[ "$cur" == "$SCRIPT_DIR" ]]; then
			echo "${label}: OK (already linked) $target"
			return 0
		fi
		if [[ "$FORCE" -eq 1 ]]; then
			rm "$target"
			echo "${label}: Replaced wrong symlink at $target"
		else
			echo "${label}: Symlink exists with different target: $target -> $cur" >&2
			echo "  Remove it or run with --force" >&2
			exit 1
		fi
	elif [[ -e "$target" ]]; then
		echo "${label}: Path exists and is not a symlink: $target" >&2
		exit 1
	fi

	ln -s "$SCRIPT_DIR" "$target"
	echo "${label}: Created $target -> $SCRIPT_DIR"
}

if [[ "$CLEAN_STALE" -eq 1 ]]; then
	[[ "$INSTALL_CURSOR" -eq 1 ]] && remove_stale_in "${HOME}/.cursor/extensions"
	[[ "$INSTALL_VSCODE" -eq 1 ]] && remove_stale_in "${HOME}/.vscode/extensions"
fi

[[ "$INSTALL_CURSOR" -eq 1 ]] && install_one "${HOME}/.cursor/extensions" "Cursor"
[[ "$INSTALL_VSCODE" -eq 1 ]] && install_one "${HOME}/.vscode/extensions" "VS Code"

echo
echo "Reload the editor (Developer: Reload Window) so it picks up changes."
