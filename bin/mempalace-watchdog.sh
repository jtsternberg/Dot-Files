#!/bin/bash
# =============================================================================
# mempalace-watchdog.sh -- daily health watchdog for the local mempalace palace.
# By Justin Sternberg <me@jtsternberg.com>
#
# WHY THIS EXISTS
#   On 2026-07-08 mempalace's quarantine_stale_hnsw renamed the palace's HNSW
#   vector segment away as "corrupt"; ChromaDB's auto-purged WAL could not
#   replay, permanently losing ~47k vectors (the palace's entire first month).
#   The palace was fully re-embedded 2026-08-28 (347k+ drawers, divergence 0).
#   The destructive-quarantine bug is STILL present as of mempalace 3.8.0 --
#   upstream https://github.com/MemPalace/mempalace/issues/1710 (open). This
#   watchdog guards against a repeat until that durable fix ships, then removes
#   itself (see SELF-RETIREMENT below).
#
# WHAT IT WATCHES (each check independent; one failure never stops the rest;
# read-only toward the palace except outward rsync copies):
#   1. drawers sqlite/HNSW divergence            (MemPalace/mempalace#1710)
#   2. quarantine event: *.drift-* dirs, or new hook.log quarantine lines
#   3. the hand-applied CLI search fallback patch reverted by an upgrade (#2373)
#   4. a stale weekly palace backup to the Secondary volume
#   5. a newer mempalace release / movement on #1710 / #2373 (network; quiet on
#      network failure -- never false-alerts over flaky wifi)
#   6. SELF-RETIREMENT once the durable fix is demonstrably installed
#
# INSTALL / SCHEDULE
#   This script + its LaunchAgent plist (com.jt.mempalace-watchdog.plist, beside
#   this file) live in the dotfiles repo and are git-tracked. Install with:
#       mempalace-watchdog.sh install
#   which symlinks the tracked plist into ~/Library/LaunchAgents and bootstraps
#   it. Scheduled daily at 9:23am (StartCalendarInterval). Uninstall with
#   'mempalace-watchdog.sh uninstall'; 'status' prints launchd + log state.
#
# SELF-RETIREMENT
#   When #1710 is closed AND the installed build postdates its closure, the
#   watchdog writes a persistent "retired" marker in the state dir, boots out
#   and removes its LaunchAgent *symlink*, and exits. Every later run sees the
#   marker and exits immediately. It never deletes the tracked script or plist
#   out of the git repo -- the marker, log, and state dir are the record.
#
# LOG:   ~/.local/state/mempalace-watchdog/watchdog.log
# STATE: ~/.local/state/mempalace-watchdog/   (offsets, raw check output, marker)
#
# Env overrides (testing):
#   WATCHDOG_MEMPALACE_ROOT   default ~/.mempalace
#   WATCHDOG_STATE_DIR        default ~/.local/state/mempalace-watchdog
#   WATCHDOG_SECONDARY        default /Volumes/Secondary
#   WATCHDOG_NO_NOTIFY=1      log notifications, don't post them
#   WATCHDOG_NO_RETIRE=1      report retirement eligibility, don't actually retire
#   WATCHDOG_SKIP_DIVERGENCE / _PATCH / _BACKUP / _NETWORK = 1
# =============================================================================

# launchd hands us a bare environment; everything below needs a real PATH.
export PATH="/opt/homebrew/bin:/usr/local/bin:$HOME/.local/bin:/usr/bin:/bin:/usr/sbin:/sbin"

set -uo pipefail

# Resolve our own directory so the tracked plist beside us is found regardless
# of clone path or how launchd invoked us.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
readonly LABEL="com.jt.mempalace-watchdog"
readonly REPO_PLIST="$SCRIPT_DIR/${LABEL}.plist"
readonly PLIST="$HOME/Library/LaunchAgents/${LABEL}.plist"

MP_ROOT="${WATCHDOG_MEMPALACE_ROOT:-$HOME/.mempalace}"
STATE_DIR="${WATCHDOG_STATE_DIR:-$HOME/.local/state/mempalace-watchdog}"
SECONDARY="${WATCHDOG_SECONDARY:-/Volumes/Secondary}"

PALACE="$MP_ROOT/palace"
HOOK_LOG="$MP_ROOT/hook_state/hook.log"
LOG="$STATE_DIR/watchdog.log"
LOCK_DIR="$STATE_DIR/run.lock"
OFFSET_FILE="$STATE_DIR/hook.offset"
RETIRED_MARKER="$STATE_DIR/retired"
BACKUP_ROOT="$SECONDARY/mempalace-weekly-backup"
PRESERVE_ROOT="$SECONDARY/mempalace-drift-preserve"

readonly DIVERGENCE_THRESHOLD=2000
readonly BACKUP_MAX_AGE_DAYS=7
readonly BACKUP_KEEP=2
readonly MINE_WAIT_SECONDS=300
readonly PY=/usr/bin/python3          # stdlib only -- no third-party imports below

mkdir -p "$STATE_DIR" 2>/dev/null

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"; }

# Notification = osascript popup AND a log line. Healthy runs never call this.
notify() {
    local msg="$1"
    log "NOTIFY: $msg"
    if [[ -n "${WATCHDOG_NO_NOTIFY:-}" ]]; then
        log "  (notification suppressed: WATCHDOG_NO_NOTIFY)"
        return 0
    fi
    local esc="${msg//\\/\\\\}"; esc="${esc//\"/\\\"}"
    osascript -e "display notification \"$esc\" with title \"mempalace watchdog\"" \
        >/dev/null 2>&1 || log "  WARN: osascript notification failed"
}

# Volume-mounted test that a leftover /Volumes/<name> directory cannot fake.
# A stale empty /Volumes/Secondary dir shares the root filesystem's device, so
# `df` reports the root fs and falsely looks "mounted". A real mount has its own
# device number, distinct from its parent /Volumes.
volume_mounted() {
    local target="$1" dev parent
    [[ -d "$target" ]] || return 1
    dev="$(stat -f %d "$target" 2>/dev/null)"   || return 1
    parent="$(stat -f %d "$(dirname "$target")" 2>/dev/null)" || return 1
    [[ -n "$dev" && "$dev" != "$parent" ]]
}

# ---------------------------------------------------------------------------
# INSTALL / UNINSTALL / STATUS
# ---------------------------------------------------------------------------
cmd_install() {
    local uid; uid="$(id -u)"
    if [[ ! -f "$REPO_PLIST" ]]; then
        echo "error: tracked plist not found at $REPO_PLIST" >&2; exit 1
    fi
    if ! plutil -lint "$REPO_PLIST" >/dev/null; then
        echo "error: $REPO_PLIST failed plutil -lint" >&2; exit 1
    fi
    if [[ -f "$RETIRED_MARKER" ]]; then
        echo "note: clearing prior retirement marker ($RETIRED_MARKER) -- reactivating."
        rm -f "$RETIRED_MARKER"
        log "INSTALL: cleared retirement marker; watchdog reactivated."
    fi
    mkdir -p "$HOME/Library/LaunchAgents"
    launchctl bootout "gui/$uid/$LABEL" >/dev/null 2>&1   # ignore "not loaded"
    rm -f "$PLIST"
    ln -s "$REPO_PLIST" "$PLIST"
    launchctl bootstrap "gui/$uid" "$PLIST"
    launchctl enable "gui/$uid/$LABEL" >/dev/null 2>&1
    log "INSTALL: symlinked $PLIST -> $REPO_PLIST and bootstrapped gui/$uid/$LABEL."
    echo "installed: $PLIST -> $REPO_PLIST"
    echo "scheduled daily 9:23am. It does not run at load; run manually to test:"
    echo "    $SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")"
    launchctl print "gui/$uid/$LABEL" 2>/dev/null | grep -E "state|program|arguments" | head -8
}

cmd_uninstall() {
    local uid; uid="$(id -u)"
    launchctl bootout "gui/$uid/$LABEL" >/dev/null 2>&1
    rm -f "$PLIST"
    log "UNINSTALL: booted out and removed $PLIST (log and state dir kept)."
    echo "uninstalled: removed $PLIST and booted out gui/$uid/$LABEL (log/state kept)."
}

cmd_status() {
    local uid; uid="$(id -u)"
    echo "== launchd =="
    launchctl print "gui/$uid/$LABEL" 2>/dev/null | grep -E "state|program|arguments|runs|last exit" | head -12 \
        || echo "  not loaded"
    echo "== plist =="
    if [[ -L "$PLIST" ]]; then echo "  $PLIST -> $(readlink "$PLIST")"
    elif [[ -e "$PLIST" ]]; then echo "  $PLIST (not a symlink)"
    else echo "  not installed"; fi
    echo "== retirement =="
    if [[ -f "$RETIRED_MARKER" ]]; then cat "$RETIRED_MARKER"; else echo "  active (not retired)"; fi
    echo "== log tail =="
    tail -n 15 "$LOG" 2>/dev/null || echo "  no log yet"
}

# ---------------------------------------------------------------------------
# Retirement lives in a function so its body is parsed and resident before we
# tear down the LaunchAgent. It writes a persistent marker (checked first on
# every future run), removes only the LaunchAgent *symlink*, and boots out --
# it never deletes the git-tracked script or plist.
# ---------------------------------------------------------------------------
retire() {
    local ver="$1"
    notify "mempalace watchdog retiring -- durable fix installed (v${ver})"
    log "RETIRE: #1710 closed and installed v${ver} postdates its closure."
    printf 'retired %s installed=v%s (#1710 durable fix landed)\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" "$ver" >"$RETIRED_MARKER"
    log "RETIRE: wrote marker $RETIRED_MARKER; future runs will exit immediately."
    log "RETIRE: removing LaunchAgent symlink ($PLIST) and booting out gui/$(id -u)/${LABEL}."
    rm -f "$PLIST"                       # symlink only; tracked repo plist untouched
    launchctl bootout "gui/$(id -u)/${LABEL}" >/dev/null 2>&1
    exit 0
}

# ===========================================================================
# CHECK 1 -- drawers sqlite vs HNSW divergence
# repair-status' own "OK / within flush-lag tolerance" verdict is NOT trusted
# (that tolerance hid the original loss); we read the two counts and apply our
# own threshold.
# ===========================================================================
check_divergence() {
    if [[ -n "${WATCHDOG_SKIP_DIVERGENCE:-}" ]]; then log "[1/6] divergence: skipped"; return; fi
    local raw="$STATE_DIR/repair-status.txt"
    if ! command -v mempalace >/dev/null 2>&1; then
        notify "CHECK FAILED: mempalace CLI not on PATH -- divergence unchecked"
        return
    fi
    if ! mempalace repair-status >"$raw" 2>&1; then
        log "[1/6] divergence: repair-status exited nonzero; raw output in $raw"
    fi
    local parsed
    parsed="$("$PY" - "$raw" <<'PYEOF'
import re, sys
try:
    text = open(sys.argv[1], errors="replace").read()
except OSError as e:
    print(f"STATUS=ERROR MSG={e!r}"); raise SystemExit(0)
m = re.search(r"\[drawers\](.*?)(?=\n\s*\[|\Z)", text, re.S)
if not m:
    print("STATUS=NOPARSE"); raise SystemExit(0)
block = m.group(1)
def num(label):
    g = re.search(rf"{label}\s*:\s*([\d,]+)\s*$", block, re.M)
    return int(g.group(1).replace(",", "")) if g else None
sq, hn = num("sqlite count"), num("hnsw count")
if sq is None or hn is None:
    print(f"STATUS=INCOMPLETE SQLITE={sq} HNSW={hn}"); raise SystemExit(0)
print(f"STATUS=OK SQLITE={sq} HNSW={hn} DIFF={abs(sq - hn)}")
PYEOF
)"
    log "[1/6] divergence: $parsed  (raw: $raw)"
    case "$parsed" in
        STATUS=OK*)
            local diff; diff="${parsed##*DIFF=}"
            if (( diff > DIVERGENCE_THRESHOLD )); then
                notify "DIVERGENCE ALERT: drawers sqlite/HNSW differ by ${diff} (>${DIVERGENCE_THRESHOLD}). Do not run repair blindly -- see #1710."
            fi ;;
        STATUS=INCOMPLETE*)
            log "[1/6] hnsw count not numeric (unflushed metadata?) -- treating as inconclusive, no alert" ;;
        *)
            notify "CHECK FAILED: could not parse repair-status drawers counts ($parsed)" ;;
    esac
}

# ===========================================================================
# CHECK 2 -- quarantine events
# (a) any *.drift-* dir under the palace root  (b) new hook.log quarantine lines
# ===========================================================================
check_drift_dirs() {
    local found=0 d base
    while IFS= read -r d; do
        [[ -z "$d" ]] && continue
        found=1
        base="$(basename "$d")"
        notify "QUARANTINE EVENT: HNSW segment quarantined -- found $d . Drawers may be unsearchable. DO NOT let the WAL purge."
        log "[2a] drift dir: $d"
        if volume_mounted "$SECONDARY"; then
            if mkdir -p "$PRESERVE_ROOT/$base" 2>/dev/null &&
               rsync -a "$d/" "$PRESERVE_ROOT/$base/" >>"$LOG" 2>&1; then
                log "[2a] preserved -> $PRESERVE_ROOT/$base/ (original left in place)"
            else
                notify "QUARANTINE: failed to preserve $base to $PRESERVE_ROOT -- original left in place, copy it by hand"
            fi
        else
            notify "QUARANTINE: $SECONDARY not mounted -- cannot preserve $base. Mount it and preserve by hand; original left in place."
        fi
    done < <(find "$MP_ROOT" -maxdepth 8 -name '*.drift-*' -type d -print 2>/dev/null)
    (( found == 0 )) && log "[2a] drift dirs: none under $MP_ROOT"
}

check_hook_log() {
    local marker='Quarantined corrupt HNSW segment'
    if [[ ! -f "$HOOK_LOG" ]]; then
        log "[2b] hook.log: $HOOK_LOG not present -- nothing to scan"
        return
    fi
    local size offset hits=0
    size="$(stat -f %z "$HOOK_LOG" 2>/dev/null)" || { log "[2b] hook.log: stat failed"; return; }
    offset="$(cat "$OFFSET_FILE" 2>/dev/null)"
    [[ "$offset" =~ ^[0-9]+$ ]] || offset=""

    if [[ -z "$offset" ]]; then
        # First ever run: baseline at current size. Historical events are noted
        # but not alerted -- an alert on day one is noise about known history.
        local historical
        historical="$(grep -c "$marker" "$HOOK_LOG" 2>/dev/null)" || historical=0
        printf '%s\n' "$size" >"$OFFSET_FILE"
        log "[2b] hook.log: first run, baselined offset=$size (${historical} historical quarantine line(s) noted, not alerted)"
        return
    fi

    if (( size < offset )); then
        log "[2b] hook.log shrank ($size < $offset) -- rotated; rescanning from 0"
        offset=0
    fi

    if (( size == offset )); then
        log "[2b] hook.log: no new bytes since offset $offset"
        return
    fi

    local newbytes="$STATE_DIR/hook.new"
    if tail -c "+$((offset + 1))" "$HOOK_LOG" >"$newbytes" 2>/dev/null; then
        hits="$(grep -c "$marker" "$newbytes" 2>/dev/null)" || hits=0
        if (( hits > 0 )); then
            notify "QUARANTINE EVENT: ${hits} new 'Quarantined corrupt HNSW segment' line(s) in hook.log -- palace index may be destroyed (#1710)"
            grep "$marker" "$newbytes" >>"$LOG" 2>&1
        fi
        log "[2b] hook.log: scanned $((size - offset)) new byte(s), $hits quarantine line(s); offset -> $size"
    else
        log "[2b] hook.log: could not read new bytes; offset left at $offset"
        rm -f "$newbytes"
        return
    fi
    rm -f "$newbytes"
    printf '%s\n' "$size" >"$OFFSET_FILE"
}

# ===========================================================================
# CHECK 3 -- has `uv tool upgrade` silently reverted the CLI search patch?
# Resolved dynamically: the python3.X component moves on interpreter bumps.
# ===========================================================================
resolve_searcher() {
    local p
    for p in "$HOME"/.local/share/uv/tools/mempalace/lib/python*/site-packages/mempalace/searcher.py; do
        [[ -f "$p" ]] && { printf '%s\n' "$p"; return 0; }
    done
    p="$(find "$HOME/.local/share/uv/tools/mempalace" -name searcher.py -path '*/mempalace/*' -print -quit 2>/dev/null)"
    [[ -n "$p" ]] && { printf '%s\n' "$p"; return 0; }
    return 1
}

check_patch() {
    if [[ -n "${WATCHDOG_SKIP_PATCH:-}" ]]; then log "[3/6] patch: skipped"; return; fi
    local searcher
    if ! searcher="$(resolve_searcher)"; then
        notify "CHECK FAILED: cannot locate installed mempalace searcher.py -- CLI fallback patch unverified"
        return
    fi
    local verdict
    verdict="$("$PY" - "$searcher" <<'PYEOF'
import re, sys
src = open(sys.argv[1], errors="replace").read().split("\n")
start = next((i for i, l in enumerate(src) if re.match(r"^def search\(", l)), None)
if start is None:
    print("NOSEARCH"); raise SystemExit(0)
end = next((j for j in range(start + 1, len(src))
            if re.match(r"^(def |class |@)", src[j])), len(src))
body = "\n".join(src[start:end])
if "_query_drawers_with_filter_fallback(" in body:
    print("PATCHED")
elif re.search(r"=\s*col\.query\(", body):
    print("REVERTED")
else:
    print("UNKNOWN")
PYEOF
)"
    log "[3/6] patch: $verdict ($searcher)"
    case "$verdict" in
        PATCHED)  ;;
        REVERTED) notify "CLI fallback patch reverted by upgrade -- re-apply (upstream #2373 still open)" ;;
        *)        notify "CLI fallback patch UNVERIFIABLE ($verdict) -- search() no longer matches either shape; inspect searcher.py by hand (#2373)" ;;
    esac
}

# ===========================================================================
# CHECK 4 -- weekly backup of the palace to the Secondary volume
# ===========================================================================
mine_running() { pgrep -f "mempalace (mine|repair)" >/dev/null 2>&1; }

check_backup() {
    if [[ -n "${WATCHDOG_SKIP_BACKUP:-}" ]]; then log "[4/6] backup: skipped"; return; fi
    if ! volume_mounted "$SECONDARY"; then
        notify "BACKUP: $SECONDARY not mounted -- weekly palace backup skipped"
        return
    fi

    local newest="" age="never"
    if [[ -d "$BACKUP_ROOT" ]]; then
        newest="$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]' \
                  -exec basename {} \; 2>/dev/null | sort | tail -1)"
    fi
    if [[ -n "$newest" ]]; then
        age="$("$PY" -c 'import sys,datetime; d=datetime.date(int(sys.argv[1][:4]),int(sys.argv[1][4:6]),int(sys.argv[1][6:8])); print((datetime.date.today()-d).days)' "$newest" 2>/dev/null)"
        [[ "$age" =~ ^-?[0-9]+$ ]] || age=9999
    fi
    log "[4/6] backup: newest=${newest:-none} age_days=$age (threshold ${BACKUP_MAX_AGE_DAYS})"

    if [[ "$age" != "never" ]] && (( age < BACKUP_MAX_AGE_DAYS )); then
        log "[4/6] backup: current, nothing to do"
        return
    fi

    # A mine writes to the palace; copying underneath it yields a torn backup.
    # Mines take 60-90s, so wait a few minutes rather than losing the week.
    local waited=0
    while mine_running; do
        if (( waited >= MINE_WAIT_SECONDS )); then
            log "[4/6] backup: DEFERRED -- a mempalace mine/repair is still running after ${waited}s; will retry tomorrow"
            return
        fi
        sleep 15; waited=$((waited + 15))
    done
    (( waited > 0 )) && log "[4/6] backup: waited ${waited}s for a mine to clear"

    local stamp dest partial
    stamp="$(date '+%Y%m%d')"
    dest="$BACKUP_ROOT/$stamp"
    partial="$dest.partial"

    if [[ -d "$dest" ]]; then
        log "[4/6] backup: $dest already exists -- refreshing in place"
        partial="$dest"
    fi
    if ! mkdir -p "$partial"; then
        notify "BACKUP FAILED: cannot create $partial"
        return
    fi
    log "[4/6] backup: rsync $PALACE/ -> $partial/ (started $(date '+%H:%M:%S'))"
    if rsync -a --delete "$PALACE/" "$partial/" >>"$LOG" 2>&1; then
        [[ "$partial" != "$dest" ]] && mv "$partial" "$dest"
        log "[4/6] backup: complete -> $dest ($(du -sh "$dest" 2>/dev/null | awk '{print $1}')) at $(date '+%H:%M:%S')"
    else
        notify "BACKUP FAILED: rsync of palace to $SECONDARY errored -- see watchdog.log"
        log "[4/6] backup: leaving $partial for inspection"
        return
    fi

    # Prune to the newest BACKUP_KEEP dated dirs.
    local -a all=()
    while IFS= read -r line; do [[ -n "$line" ]] && all+=("$line"); done < <(
        find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]' \
             -exec basename {} \; 2>/dev/null | sort)
    local total=${#all[@]} i victim
    if (( total > BACKUP_KEEP )); then
        for (( i = 0; i < total - BACKUP_KEEP; i++ )); do
            # Belt-and-suspenders: both halves must be non-empty and the dir an
            # 8-digit stamp, so this can never expand toward / (shellcheck SC2115).
            [[ -n "$BACKUP_ROOT" && "${all[i]}" =~ ^[0-9]{8}$ ]] || continue
            victim="$BACKUP_ROOT/${all[i]}"
            log "[4/6] backup: pruning old backup ${all[i]}"
            rm -rf "${victim:?}"
        done
    fi
    log "[4/6] backup: retained $(( total > BACKUP_KEEP ? BACKUP_KEEP : total )) of $total dated backup(s)"
}

# ===========================================================================
# CHECKS 5 & 6 -- upstream release / issue state, and self-retirement.
# Network failures are logged and stay silent: no alert fatigue over wifi.
# ===========================================================================
check_upstream() {
    if [[ -n "${WATCHDOG_SKIP_NETWORK:-}" ]]; then log "[5/6] upstream: skipped"; return; fi
    local repo="MemPalace/mempalace"      # verified from dist-info METADATA Project-URL
    local pypi="$STATE_DIR/pypi.json" i1="$STATE_DIR/issue-1710.json" i2="$STATE_DIR/issue-2373.json"
    local ok=1

    curl -sSfL --max-time 25 "https://pypi.org/pypi/mempalace/json" -o "$pypi" 2>>"$LOG" \
        || { log "[5/6] upstream: PyPI fetch failed (network) -- staying quiet"; ok=0; }
    curl -sSfL --max-time 25 -H "Accept: application/vnd.github+json" \
        "https://api.github.com/repos/$repo/issues/1710" -o "$i1" 2>>"$LOG" \
        || { log "[5/6] upstream: GitHub #1710 fetch failed -- staying quiet"; ok=0; }
    curl -sSfL --max-time 25 -H "Accept: application/vnd.github+json" \
        "https://api.github.com/repos/$repo/issues/2373" -o "$i2" 2>>"$LOG" \
        || { log "[5/6] upstream: GitHub #2373 fetch failed -- staying quiet"; ok=0; }
    (( ok == 0 )) && return

    local summary
    summary="$("$PY" - "$pypi" "$i1" "$i2" "$INSTALLED_VER" <<'PYEOF'
import json, re, sys

def load(p):
    try:
        return json.load(open(p))
    except Exception as e:
        print(f"ERROR=1 MSG={type(e).__name__}")
        raise SystemExit(0)

pypi, i1, i2, installed = load(sys.argv[1]), load(sys.argv[2]), load(sys.argv[3]), sys.argv[4]

def key(v):
    return tuple(int(x) for x in re.findall(r"\d+", v or "")) or (0,)

latest = pypi.get("info", {}).get("version", "")
# Release date of the *installed* version -- the retirement test needs it.
files = pypi.get("releases", {}).get(installed) or []
inst_date = files[0].get("upload_time_iso_8601", "") if files else ""

print("ERROR=0")
print(f"LATEST={latest}")
print(f"NEWER={'1' if key(latest) > key(installed) else '0'}")
print(f"INST_DATE={inst_date}")
print(f"S1710={i1.get('state','?')}")
print(f"C1710={i1.get('closed_at') or ''}")
print(f"S2373={i2.get('state','?')}")
print(f"C2373={i2.get('closed_at') or ''}")
# Retire only when the installed build postdates the fix landing upstream.
c1 = i1.get("closed_at") or ""
retire = i1.get("state") == "closed" and c1 and inst_date and inst_date > c1
print(f"RETIRE={'1' if retire else '0'}")
PYEOF
)"
    local ERROR=1 LATEST="" NEWER=0 INST_DATE="" S1710="?" C1710="" S2373="?" C2373="" RETIRE=0 MSG=""
    # shellcheck disable=SC2034  # v is consumed by the eval below; shellcheck can't see through it
    while IFS='=' read -r k v; do
        [[ -z "$k" ]] && continue
        case "$k" in
            ERROR|LATEST|NEWER|INST_DATE|S1710|C1710|S2373|C2373|RETIRE|MSG) eval "$k=\$v" ;;
        esac
    done <<<"$summary"

    if [[ "$ERROR" != "0" ]]; then
        log "[5/6] upstream: could not parse fetched JSON ($MSG) -- staying quiet"
        return
    fi
    log "[5/6] upstream: installed=$INSTALLED_VER (released ${INST_DATE:-unknown}) latest=$LATEST newer=$NEWER | #1710=$S1710${C1710:+ closed_at=$C1710} | #2373=$S2373${C2373:+ closed_at=$C2373}"

    [[ "$NEWER" == "1" ]] && \
        notify "mempalace $LATEST is available (installed $INSTALLED_VER). Note: upgrading reverts the hand-applied CLI search patch (#2373)."

    if [[ "$S1710" == "closed" ]]; then
        notify "durable fix may have landed -- #1710 is CLOSED. Check release notes, upgrade mempalace."
    fi
    [[ "$S2373" == "closed" ]] && \
        notify "upstream #2373 (CLI search crash) is CLOSED -- an upgrade may make the hand patch unnecessary."

    # --- CHECK 6: self-retirement -----------------------------------------
    if [[ "$RETIRE" == "1" ]]; then
        if [[ -n "${WATCHDOG_NO_RETIRE:-}" ]]; then
            log "[6/6] RETIREMENT ELIGIBLE (suppressed by WATCHDOG_NO_RETIRE): #1710 closed $C1710, installed $INSTALLED_VER released $INST_DATE"
            return
        fi
        retire "$INSTALLED_VER"
    fi
    log "[6/6] retirement: not eligible (#1710=$S1710, installed $INSTALLED_VER released ${INST_DATE:-unknown})"
}

# ---------------------------------------------------------------------------
# Entry: subcommands, then the daily run.
# ---------------------------------------------------------------------------
case "${1:-run}" in
    install)   cmd_install;   exit 0 ;;
    uninstall) cmd_uninstall; exit 0 ;;
    status)    cmd_status;    exit 0 ;;
    run|"")    ;;
    *) echo "usage: $(basename "${BASH_SOURCE[0]}") [run|install|uninstall|status]" >&2; exit 2 ;;
esac

# Retired? A durable fix has landed and been installed; exit before doing
# anything. Reactivate by removing the marker or running 'install'.
if [[ -f "$RETIRED_MARKER" ]]; then
    log "SKIP: retired marker present ($RETIRED_MARKER) -- watchdog has retired, not running."
    exit 0
fi

# --- concurrency guard ------------------------------------------------------
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    log "SKIP: another run holds $LOCK_DIR (pid $(cat "$LOCK_DIR/pid" 2>/dev/null || echo '?'))"
    exit 0
fi
printf '%s\n' "$$" >"$LOCK_DIR/pid"
trap 'rm -rf "$LOCK_DIR"' EXIT INT TERM

log "=== run start (root=$MP_ROOT) ==="

INSTALLED_VER="$(mempalace --version 2>/dev/null | awk '{print $NF}')"
[[ -z "$INSTALLED_VER" ]] && INSTALLED_VER="unknown"
log "installed mempalace: $INSTALLED_VER"

# --- run every check, independently ----------------------------------------
check_divergence
check_drift_dirs
check_hook_log
check_patch
check_backup
check_upstream

log "=== run end ==="
exit 0
