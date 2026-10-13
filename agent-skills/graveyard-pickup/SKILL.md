---
name: graveyard-pickup
description: |
  Resume a buried graveyard session that could not be natively resumed, from its
  archived transcript. `graveyard resurrect` invokes this itself as the first
  prompt of the fresh agent (`/graveyard-pickup <path> --buried-at <iso>`, or
  `$graveyard-pickup ...` under Codex), so use it whenever a session opens with
  either, or with "Resuming a buried session. Read <transcript>".
allowed-tools: [Bash, Read, Grep]
argument-hint: "<archive-path> [--buried-at <iso-timestamp>]"
---

# graveyard-pickup

You are a fresh agent standing in for a session that died. Its conversation is
gone from the harness; what's left is an archive on disk. **JT has not reread
it.** He resurrected the session to get back to the work, and he expects you to
tell *him* where it stood, not to quietly carry on as though nothing happened.

Arguments arrive after the invocation (`$ARGUMENTS` under Claude Code; under
Codex, the text following `$graveyard-pickup`):

- `<archive-path>` — the archive. `graveyard` renders every buried session to
  `~/.claude-graveyard/sessions/<session-id>/transcript.md` (older ones:
  `transcript.txt`).
- `--buried-at <iso>` — when the session was buried. Anything in the repo newer
  than this happened after the transcript ends.

**The archive is a boot artifact, read-only.** Never edit it, append to it, or
"keep it current" — it is the permanent record of a dead session.

## Steps

1. **Read the archive, end first.** A transcript can run to megabytes. The last
   few exchanges say where the session stopped; the first prompt says what it
   was for. Read the tail, then the head, then `Grep` the middle for what you
   need (decisions, file names, commands, errors) rather than reading it whole.

2. **Reconcile against the present.** The transcript describes the past. In a git
   repo:
   - `git log --no-color --oneline --since=<buried-at>` — commits after burial,
     which the transcript knows nothing about.
   - `git status`, and spot-check files the transcript says it changed: are the
     changes there, committed, reverted?
   - If the transcript names beads issues, `bd show <id>` for their current state.

   Outside a repo, check the files the transcript names for existence and mtime.

3. **Brief JT**, briefly:
   - **Where it stood** — the goal, and what was done, in a few sentences.
   - **In flight at death** — the last thing being attempted or asked, and
     whether it finished.
   - **Drift** — what changed since burial, and whether it matters.
   - **Next** — the concrete step you'd take, as one question: continue with it?

4. **Wait for his answer before acting.** A dead session's last intent may be
   hours or weeks stale, and the resurrected agent may be running with
   permissions off. The briefing is the whole first turn.

## If the archive is a condensed brief

A condensed archive (one that says so in its header, or arrives with
`--condensed`) is **lossy by design**: a model kept what it judged important and
dropped the rest, including failed attempts and most of the reasoning.

- Treat each claim in it as a lead. Check it against the repo before acting on it.
- When you need a detail it doesn't carry, look for the raw archive kept beside
  it (`transcript.md` or its `.gz`) and grep that. With no raw archive, **ask
  JT**. Never fill the gap with a plausible guess: the brief's silence means
  "not recorded", not "didn't happen".
- In the briefing, say that you are working from a condensed brief, so JT knows
  your picture may be incomplete.
