---
name: battery-watchdog
description: Triage the battery-watchdog's latest alert — find the current power/CPU offender, classify it, and propose (and on confirmation, execute) a fix.
allowed-tools: [Bash, Read]
---

# Battery Watchdog — Triage

The `battery-watchdog` LaunchAgent just (probably) sent a notification, or JT
wants to know what's draining the battery / pinning the CPU **right now**. Your
job: figure out what's actually happening *at this moment*, decide whether it's
a real problem or benign background work, and propose a concrete fix — then run
it if JT confirms.

The notification is stale by the time JT reads it. **Do not trust the log
alone — always re-sample live.**

## 1. Gather state (run these, read the output)

```bash
# What the watchdog last saw + its persisted hog/throttle state
cat ~/.cache/battery-watchdog/state.json 2>/dev/null
# Recent history — is this offender recurring, or a one-off spike?
tail -25 ~/.cache/battery-watchdog/watchdog.log 2>/dev/null
# LIVE re-sample — is it STILL hot right now?
pmset -g batt
ps -arcwwwxo pid,%cpu,rss,etime,command | head -12
```

If the top live process from `ps` matches a PID in `state.json`'s `hogPids` and
also shows up across several recent `watchdog.log` lines, it's a genuine
**sustained** hog — not a transient spike. Say so explicitly.

For the prime offender, dig one level deeper so your advice is specific:

```bash
ps -p <PID> -o pid,ppid,%cpu,%mem,etime,command   # full command + how long it's run
ps -p <PPID> -o pid,command                        # who launched it
```

## 2. Classify the offender

**Benign / expected background work** — usually *leave it alone* (it finishes on
its own); only suggest deferring if JT is on battery and low:
- `mds`, `mds_stores`, `mdworker*`, `spotlightknowledged*` → Spotlight indexing
- `backupd`, `backupd-helper` → Time Machine backup
- `fileproviderd`, `bird`, `cloudd` → iCloud / file-provider sync
- `photoanalysisd`, `mediaanalysisd` → Photos analysis
- `XProtect*`, `mds_stores` after an OS update → security/index refresh

**Likely runaway — good kill/renice candidates:**
- Language servers: `intelephense`, `phpactor`, `PHP Language Server`, `gopls`,
  `rust-analyzer`, `pyright`, `typescript-language-server`, Node helpers spawned
  by VS Code / Cursor (`Code Helper (Plugin)`, `extensionHost`)
- Stuck `node`, `php`, `ruby`, `python` processes pinned at ~100% for many minutes
- A browser/renderer tab gone wild (`* Helper (Renderer)`)

**NEVER suggest killing** (will hurt the system): `kernel_task`, `WindowServer`,
`launchd` (pid 1), `loginwindow`, `coreaudiod`, `hidd`. For these, only
`renice`/investigate, never `kill`.

## 3. Propose ranked actions (with the real PID filled in)

Lead with a one-line verdict: *"`<proc>` (pid N) is pinned at X% for Y min —
this is a real runaway / this is normal indexing."* Then offer the smallest
effective action first:

- **Kill** (for a confirmed runaway you can safely restart):
  `kill <PID>` (try this first; `kill -9 <PID>` only if it ignores SIGTERM).
  Note the side effect — e.g. "VS Code will respawn the language server clean."
- **Throttle instead of kill** (keep it running but stop the battery bleed):
  `renice +20 -p <PID>` — drops it to lowest CPU priority. Reversible.
- **Defer** (for benign-but-costly background work on battery):
  Time Machine → `tmutil stopbackup`; Spotlight (chronic) → `sudo mdutil -i off /`
  (note it needs sudo and disables search indexing until re-enabled).
- **Just wait** — if it's normal indexing and JT is on AC, say so and stop.

If on battery: report `pmset` remaining time + the watchdog's drain rate, and
name the 1–2 things worth closing to extend runtime.

## 4. Execute on confirmation

This session runs with permissions skipped, so after JT says yes (e.g. "kill
it", "renice it", "stop the backup"), **run the command** and confirm the result
by re-sampling (`ps -p <PID>` — gone? `%cpu` dropped?). Report what changed.

Never act without an explicit go-ahead. If JT only asked "what's going on?",
stop after the verdict + options.

## Notes
- macOS only. If `pmset` isn't found, say this machine isn't supported and stop.
- Be concise and decisive — JT ran this *because* something is wrong and his
  battery is draining. Lead with the verdict and the fix, not a wall of data.
