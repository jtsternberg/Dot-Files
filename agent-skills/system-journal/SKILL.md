---
name: system-journal
description: Machine-specific journal of past infrastructure and config incidents on THIS Mac (jt-mbp14) — NAS/QNAP, SSH, network, DNS, tailnet/VPN, auth, firmware, mounts, shares, printers, launchd. READ IT FIRST when something infrastructural is broken, and RECORD an entry after resolving one. Triggers on symptoms as first stated — "can't ssh", "Permission denied (publickey)", "too many authentication failures", "git push to the NAS fails", "can't mount", "can't reach the NAS", "share isn't showing up", DNS/tailnet weirdness, auth failing for no reason — and especially on temporal tells — "this worked yesterday", "broke after a reboot", "stopped working after an update", "it just suddenly stopped". Also fires when a hard-won infra fix, a standing fact about this machine's setup, or a deliberate config change is worth writing down so the next investigation is fast. NOT for live performance alerts (CPU/memory/battery/kernel/tmp) — that is the /system-watchdog command.
allowed-tools: [Bash, Read, Write, Edit, ScheduleWakeup]
---

# System Journal — jt-mbp14

A curated log of **non-obvious infrastructure / config incidents on this
machine**, plus the standing facts and deliberate changes that make the next
investigation fast.

**Boundary — two journals, one each:** live perf telemetry (CPU / memory /
battery / kernel zones / /tmp) → the `/system-watchdog` command's
`~/.cache/system-watchdog/journal.jsonl`. Durable config / infra findings (NAS,
SSH, network, auth, firmware) → **here**. If you're holding a finding and can't
tell which it is: would it still be true after a reboot? Yes → here.

```
~/.dotfiles/private/system-journal/
├── index.md      # GENERATED from entry frontmatter — read this first, never hand-edit
└── entries/      # one file per entry, the source of truth
```

`private/` is its own local-only git repo (no remote), so entries are
version-controlled and never leave this machine. That matters: these entries name
internal hosts, SSH paths and auth workarounds, and must never reach the public
dotfiles repo or any external service. Never copy an entry into a repo, a PR, an
issue, or a paste service.

## 1. Read before diagnosing

Read the index — it is one line per entry, cheap, and lets you reject the whole
journal in seconds when nothing fits:

```bash
cat ~/.dotfiles/private/system-journal/index.md
```

Each line is `date · kind · symptom-as-observed · [component tags] · [pattern
tags] · → path`. Match on the **symptom**, since that's all you know at lookup
time. Then open only the leaf that fits:

```bash
cat ~/.dotfiles/private/system-journal/entries/<slug>.md
# to hand it to JT instead: code -r ~/.dotfiles/private/system-journal/entries/<slug>.md
```

Match on the **pattern** tags too, not just the component. A NAS incident tagged
`silent-config-reset` is a live lead for a printer or router that silently
reverted — the transferable lesson is usually the pattern, not the box.

If an entry fits, say so up front and lead with its prevention/early-warning
line — that's the part that collapses the investigation. If nothing fits, say
that too, in one line, and move on.

**Journal vs mempalace:** this journal is the *settled verdict* plus verbatim
reusable commands, and it's **push** — the skill surfaces it when you didn't know
to look. `mempalace search` is *raw transcript recall* and it's **pull** — it
returns what was said mid-investigation, wrong hypotheses included. Read the
journal first; reach for mempalace when the journal has no entry and you need the
original conversation.

## 2. Write an entry when you resolve one — this is the half that gets skipped

The journal only compounds if entries get written, and the write moment is
exactly when attention snaps elsewhere because the thing finally works. **Write
the entry as part of finishing the fix, not after.**

Write one when:

- **`kind: incident`** — you diagnosed a non-obvious infra/config breakage. Worth
  it if the root cause surprised you, or if the diagnosis took real time.
- **`kind: fact`** — you learned a standing truth about this machine's setup that
  will save a future investigation ("auto-update is on and has reverted settings",
  "Tailscale SSH doesn't work on QNAP"). Facts are how you stop re-deriving.
- **`kind: change`** — JT deliberately changed an infra/config setting. Cheap now,
  and it's the best defense against self-inflicted mysteries later: an
  intentional change from a year ago reads exactly like a lead when it's noise.

Do **not** journal routine work, anything already covered by an existing entry
(update that one instead), or perf telemetry (that's the watchdog).

**Split rather than bury.** If an incident writeup contains a standing fact or a
past change, pull it into its own `fact`/`change` entry and link to it. A fact
buried inside an incident is only findable by someone who already matched the
incident's symptom — which defeats the point.

### Entry format

Slug: `<component>-<symptom>.md` — component first, **no date prefix**. Component
first means `ls entries/` clusters everything about one box together; the date is
in frontmatter where it's actually useful. e.g.
`qnap-ssh-pubkey-auth-failed.md`.

```markdown
---
date: YYYY-MM-DD          # when it happened (for a `change`, when the change was made)
kind: incident            # incident | fact | change
status: active            # active | superseded | stale
updated: YYYY-MM-DD       # last time this entry's understanding changed
title: One-line human title
symptom: What you would OBSERVE, phrased as you'd first describe it — this is the index's lookup key, so no cause-language here
component: nas, ssh, auth # what it's about
pattern: silent-config-reset, auto-update-regression   # HOW it failed — the transferable axis
---

# Title

**Symptom.** What broke, as observed.
**Root cause.** The actual cause, once found.
**Why it happened.** (optional) Best evidence for how it got into that state. Say
plainly when it's inference rather than proof.
**Fix.** The exact steps that resolved it.

## Reusable diagnostics
Verbatim commands, in a fenced block. This is the most reusable part of any
entry — never paraphrase a command you actually ran.

## Dead ends — don't re-chase
(optional) What looked relevant and wasn't, and how it was ruled out.

## Prevention / early-warning
What to check FIRST if this smells familiar again. Be specific enough to be
actionable in under a minute.
```

`symptom:` carries the observable for every kind, not just incidents — for a
`fact`, it's what would confuse you; for a `change`, what changed observably.
That keeps the whole index scannable on one axis. Keep it under ~120 chars so
index lines stay one line.

### Tags — reuse before you invent

Two axes, both small and lowercase-hyphenated. `component:` = what it's about
(nas, ssh, auth, network, dns, tailnet, firmware, smb, storage). `pattern:` = how
it failed (silent-config-reset, auto-update-regression, missing-home-dir,
red-herring, unsupported-feature).

Before inventing a tag, list what already exists and reuse anything that fits:

```bash
cd ~/.dotfiles/private/system-journal/entries && grep -h '^component:' *.md | sed 's/^component: *//' | tr ',' '\n' | sed 's/^ *//' | sort -u
cd ~/.dotfiles/private/system-journal/entries && grep -h '^pattern:' *.md | sed 's/^pattern: *//' | tr ',' '\n' | sed 's/^ *//' | sort -u
```

Freeform tags rot into `ssh` / `ssh-auth` / `sshd` and the axis stops being
useful. If `component:` grows past ~10 values it has become freeform — consolidate.

### Superseding, not rewriting

Understanding goes stale: a fix gets undone by a later update, a prevention
recommendation gets implemented, a root cause turns out to be half the story.
When that happens, **append** to the existing entry rather than rewriting it:

- add a `## Update YYYY-MM-DD` section stating what changed and why,
- bump `updated:`,
- set `status: superseded` (with a link to the entry that replaces it) or
  `status: stale` if the entry's advice no longer applies.

Non-`active` status shows in the index automatically. Keeping the original claim
next to its correction is the point — a reader mid-investigation needs to know
the old advice existed and why it's wrong now.

## 3. Regenerate the index, then commit

The index is a **cache of entry frontmatter**. Never hand-edit it — regenerate it
after any entry write, so it cannot drift out of sync with the entries:

```bash
cd ~/.dotfiles/private/system-journal && { printf '# System Journal — index (jt-mbp14)\n\n<!-- GENERATED from entry frontmatter — do not hand-edit. Regen one-liner lives in the system-journal skill. -->\n\n'; for f in entries/*.md; do awk -v F="$f" '/^---[[:space:]]*$/{if(++n==2)exit;next} n==1{k=$1;sub(/:$/,"",k);a[k]=substr($0,index($0,": ")+2)} END{printf "- %s · %s%s · %s · [%s] · [%s] · → %s\n", a["date"], a["kind"], (a["status"]=="active"?"":" ("toupper(a["status"])")"), a["symptom"], a["component"], a["pattern"], F}' "$f"; done | sort -r; } > index.md
```

Then commit — **scoped to this journal only**, never `-A` and never `commit -a`.
`private/` routinely holds unrelated uncommitted work, and a global PreToolUse
hook blocks blanket staging for exactly that reason:

```bash
git -C ~/.dotfiles/private add system-journal && \
git -C ~/.dotfiles/private commit -m "journal: <slug> — <one-line what>"
```

That gives `git log -p` history on the journal for free. If the commit fails,
the entry file still exists — say so and move on rather than retrying blindly.

## 4. Don't lose a finding to idle time

A resolved infra problem is journalable *right then*, and JT often doesn't reply
to the last message of a session. **If you have journalable material and haven't
written it yet** when a turn ends, schedule one self-wakeup to write it yourself:

```
ScheduleWakeup(delaySeconds: 60,
  prompt: "Revisit this session: was an infra/config incident, standing fact, or
           deliberate change resolved that isn't yet in
           ~/.dotfiles/private/system-journal/entries/? If so, write the entry
           (§2), regenerate the index and commit (§3). If it's already there or
           nothing qualifies, stop.",
  reason: "so a journalable infra finding isn't lost to idle time")
```

Rules: only schedule it if something journalable actually exists. On wake, write
the entry then **stop the loop** (`ScheduleWakeup(stop: true)`). Never busy-loop.
Journaling your own best understanding beats losing it — and if you can't
schedule a wakeup at all, write the entry before ending the turn instead of
deferring.

## Notes

- Specific to this Mac (jt-mbp14). Entries about other machines don't belong here.
- Be terse in entries but never paraphrase a command — verbatim commands are the
  most reused part of the whole journal.
- Lead with the verdict. JT reads this while something is broken and he's annoyed.
