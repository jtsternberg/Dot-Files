---
name: system-watchdog
description: Triage the system-watchdog's latest alert — find the current CPU / memory / battery offender, classify it, and propose (and on confirmation, execute) a fix.
allowed-tools: [Bash, Read, ScheduleWakeup]
---

# System Watchdog — Triage

The `system-watchdog` LaunchAgent just (probably) sent a notification, or JT
wants to know what's **pinning the CPU, thrashing memory, or draining the
battery right now**. Your job: figure out what's actually happening *at this
moment*, decide whether it's a real problem or benign background work, and
propose a concrete fix — then run it if JT confirms.

The notification is stale by the time JT reads it. **Do not trust the log
alone — always re-sample live.**

## 0. Read the journal first (compounded findings)

Before anything else, read the journal — it's your memory across runs. Past
investigations, confirmed causes, and known-benign baselines live here so you
don't re-derive them every time.

```bash
cat ~/.cache/system-watchdog/journal.jsonl 2>/dev/null
```

Each line is one JSON entry. Three `kind`s:
- **`investigation`** — a hypothesis under test. Has a `status` (`open` |
  `confirmed` | `refuted` | `resolved`), a `watch_for` (what evidence would
  confirm/refute it), and often an `action_taken`.
- **`baseline`** — a standing fact about a process (e.g. "Dropbox Helper
  normally runs 50–100%, benign"). Use these to *avoid re-flagging* known-OK
  work and to *skip the never-kill traps*.
- **`resolution`** — a closed-out finding worth remembering.

**How to use it this run:** if today's offender matches an entry, lead with
that context. If it matches an **`open` investigation**, this run is a data
point for it — explicitly check the `watch_for` condition and say whether
today's evidence confirms, refutes, or is neutral. If it matches a `baseline`,
weigh that before classifying (a known-benign process shouldn't be treated as a
fresh runaway). Surface the 1–2 relevant entries to JT in your verdict.

## 1. Gather state (run these, read the output)

First, figure out **which axis tripped** — the notification title and the log's
`ALERT{...}` tell you: 🔥 CPU hog, 🧠 memory pressure, or 🔋 battery. Then
re-sample everything live; a memory alert can coincide with a CPU hog.

```bash
# What the watchdog last saw + its persisted hog/throttle/mem state
cat ~/.cache/system-watchdog/state.json 2>/dev/null
# Recent history — is this offender recurring, or a one-off spike?
tail -25 ~/.cache/system-watchdog/watchdog.log 2>/dev/null

# LIVE re-sample --------------------------------------------------------------
pmset -g batt                                          # battery / power source
ps -arcwwwxo pid,%cpu,rss,etime,command | head -12     # top by CPU
ps -amcwwwxo pid,rss,%mem,etime,command | head -12     # top by RAM (rss in KB)

# Memory pressure — is the system genuinely starved, or just using RAM well?
sysctl -n kern.memorystatus_vm_pressure_level          # 1 normal, 2 warn, 4 critical
memory_pressure -Q 2>/dev/null | tail -1               # "free percentage: NN%"
sysctl -n vm.swapusage                                 # swap total/used/free
vm_stat | grep -iE 'swapin|swapout|occupied'           # cumulative swap + compressor
```

If the top live process matches a PID in `state.json`'s `hogPids` and also
shows up across several recent `watchdog.log` lines, it's a genuine
**sustained** CPU hog — not a transient spike. Say so explicitly. For memory,
compare `state.json`'s `swapouts` to the live `vm_stat` Swapouts: a large,
growing delta = **active thrashing** (the real problem); high swap *used* that
isn't growing is just stuff parked in swap, usually benign.

For the prime offender, dig one level deeper so your advice is specific:

```bash
ps -p <PID> -o pid,ppid,%cpu,%mem,rss,etime,command   # full command + how long it's run
ps -p <PPID> -o pid,command                            # who launched it
```

## 2. Classify the offender

**Benign / expected background work** — usually *leave it alone* (it finishes on
its own); only suggest deferring if JT is on battery and low:
- `mds`, `mds_stores`, `mdworker*`, `spotlightknowledged*` → Spotlight indexing
- `backupd`, `backupd-helper` → Time Machine backup
- `fileproviderd`, `bird`, `cloudd` → iCloud / file-provider sync
- `photoanalysisd`, `mediaanalysisd` → Photos analysis
- `XProtect*`, `mds_stores` after an OS update → security/index refresh

**Likely CPU runaway — good kill/renice candidates:**
- Language servers: `intelephense`, `phpactor`, `PHP Language Server`, `gopls`,
  `rust-analyzer`, `pyright`, `typescript-language-server`, Node helpers spawned
  by VS Code / Cursor (`Code Helper (Plugin)`, `extensionHost`)
- Stuck `node`, `php`, `ruby`, `python` processes pinned at ~100% for many minutes
- A browser/renderer tab gone wild (`* Helper (Renderer)`)

**Memory offenders — how to read them:**
- **Big RSS ≠ leak.** A model server (`llama-server`, `ollama`), a VM manager
  (`OrbStack`), or a database holding a large working set legitimately owns
  gigabytes. Check the journal baselines before flagging. The alert only fires
  on *pressure + active swapping*, so trust that signal over raw RSS.
- **Real memory problems:** an Electron/`node`/browser-helper process whose RSS
  climbs steadily over many checks (leak), or the machine in warn/critical
  pressure and actively swapping (`swapping N pages/min` in the alert) → the
  system is thrashing and everything feels slow. The fix is to free RAM: kill or
  restart the biggest non-baseline consumer, or close browser tabs.
- **`kernel_task` high RSS / wired memory** is the OS, not a leak — never kill.

**NEVER suggest killing** (will hurt the system): `kernel_task`, `WindowServer`,
`launchd` (pid 1), `loginwindow`, `coreaudiod`, `hidd`. For these, only
`renice`/investigate, never `kill`.

## 3. Propose ranked actions (with the real PID filled in)

Lead with a one-line verdict: *"`<proc>` (pid N) is pinned at X% CPU / holding Y
GB and the system is thrashing / draining the battery — this is a real runaway /
this is normal work."* Then offer the smallest effective action first:

- **Kill** (for a confirmed runaway you can safely restart):
  `kill <PID>` (try this first; `kill -9 <PID>` only if it ignores SIGTERM).
  Note the side effect — e.g. "VS Code will respawn the language server clean."
- **Throttle instead of kill** (keep a CPU hog running but stop the bleed):
  `renice +20 -p <PID>` — drops it to lowest CPU priority. Reversible. (Doesn't
  help memory — renice is CPU-only.)
- **Free memory** (thrashing): quit/restart the biggest non-baseline consumer,
  close heavy browser tabs, or restart a leaking Electron app. As a last resort
  `sudo purge` flushes disk caches (needs sudo; only a temporary reprieve — it
  won't fix a genuine leak).
- **Defer** (for benign-but-costly background work on battery):
  Time Machine → `tmutil stopbackup`; Spotlight (chronic) → `sudo mdutil -i off /`
  (note it needs sudo and disables search indexing until re-enabled).
- **Just wait** — if it's normal indexing/model work and JT is on AC with no
  memory pressure, say so and stop.

If on battery: report `pmset` remaining time + the watchdog's drain rate, and
name the 1–2 things worth closing to extend runtime.

## 4. Execute on confirmation

This session runs with permissions skipped, so after JT says yes (e.g. "kill
it", "renice it", "free the memory", "stop the backup"), **run the command** and
confirm the result by re-sampling (`ps -p <PID>` — gone? `%cpu`/`rss` dropped?
pressure level back to 1?). Report what changed.

Never act without an explicit go-ahead. If JT only asked "what's going on?",
stop after the verdict + options.

## 5. Journal what compounds (write it down)

The point of the journal is that findings accumulate — so the *next* run is
smarter. Append an entry when you learn something worth carrying forward. Do
**not** journal one-off noise or things already captured.

Journal when:
- **You open a line of investigation** — a hypothesis + a test in flight (e.g.
  "JT disabled X to see if hog Y stops"). Record `hypothesis`, `action_taken`,
  and `watch_for` so a future run knows what to check.
- **This run confirms or refutes an open investigation** — update by appending
  a new entry with the same `offender` and `status: confirmed|refuted` (append,
  don't rewrite — the file is a log). Say what evidence settled it.
- **You learn a new benign baseline** — a process that looks scary but is
  normal on this Mac (a CPU spiker OR a big-RSS process), so future runs stop
  re-flagging it.
- **You confirm a recurring offender or a never-kill trap** worth remembering.

Append with a single JSON line (this is the only write the skill makes; `>>`
never clobbers the file):

```bash
printf '%s\n' '{"date":"YYYY-MM-DD","kind":"investigation","status":"open","offender":"<proc>","hypothesis":"...","action_taken":"...","watch_for":"..."}' \
  >> ~/.cache/system-watchdog/journal.jsonl
```

Keep entries terse but self-contained — a future run reads them cold with no
other context. Use the real date (macOS: `date +%F`). Tell JT in one line what
you journaled (or that nothing was worth journaling).

## 6. Don't rot in waiting-for-answer purgatory

JT doesn't always reply to a question or follow up on a verdict — and a finding
that was worth journaling can be lost when the session goes idle. So: **at the
end of any turn where journalable material exists but you have NOT yet written
it** (e.g. you proposed a hypothesis, ran a test, or reached a verdict but are
waiting on JT to confirm before recording it), schedule a short self-wakeup to
revisit and journal it yourself:

```
ScheduleWakeup(delaySeconds: 60,
  prompt: "Revisit this system-watchdog session: is there a finding, hypothesis,
           or test-in-flight worth appending to ~/.cache/system-watchdog/journal.jsonl
           that isn't already there? If yes, append it (step 5) and note it. If it's
           already journaled or nothing is worth it, stop.",
  reason: "self-follow-up so a journalable finding isn't lost to idle time")
```

Rules:
- **Only schedule the wakeup if there is something potentially journalable so
  far** — no open finding, no pending verdict → don't schedule, just stop.
- On wake: re-read the conversation, append to the journal if warranted, then
  **stop the loop** (`ScheduleWakeup(stop: true)`). Reschedule once more only if
  JT is clearly mid-decision and the finding still isn't safe to record.
- Never let this become a busy-loop. One follow-up to capture the finding, then
  done. Journaling your own best-understanding is better than losing it.

> Note: `ScheduleWakeup` is the self-resume primitive. If a given run can't
> schedule one (unavailable in the current mode), fall back to journaling your
> current best understanding *before* ending the turn rather than deferring.

## Notes
- macOS only. If `pmset`/`vm_stat` aren't found, say this machine isn't
  supported and stop.
- Be concise and decisive — JT ran this *because* something is wrong (CPU pinned,
  RAM thrashing, or battery draining). Lead with the verdict and the fix, not a
  wall of data.
