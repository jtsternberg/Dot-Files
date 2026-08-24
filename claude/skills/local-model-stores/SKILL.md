---
name: local-model-stores
version: 1.0.0
description: |
  Manages WHERE local AI models live on JT's Mac — the local disk vs the AI-LAB
  external drive — and how to remove that drive safely. Use for: "which store am
  I on", "is X available offline", "why is my model missing", "AI-LAB won't
  eject", "eject the drive", "move models to the SSD", "reconcile the stores",
  "the watcher", "aimodels", "ollamodels", "I unplugged the drive and models
  vanished", or any disk/availability question about Ollama LLMs or MacWhisper
  ASR models. ALSO the routing point for WHICH model to use: LLM choice goes to
  ollama-model-picker, transcription choice to asr-model-picker.
  Not for picking a model yourself — this skill owns storage, not selection.
allowed-tools:
  - Bash
  - Read
  - AskUserQuestion
---

# Local model stores

Two model engines on this Mac keep their models on either the internal disk or
the AI-LAB external SSD. `aimodels` (in `~/.dotfiles/bin`) manages both.

## The mechanism, in one paragraph

Each engine's app-facing models path is a **symlink** to one of two real stores.
A single LaunchAgent watches `/Volumes` and re-points every engine's symlink when
AI-LAB is mounted or ejected: mounted → the external superset, ejected → the
minimal offline-safe local store. So the apps always find models where they
expect, and the big bytes live on the SSD.

| | app-facing symlink | local store | external store |
|---|---|---|---|
| Ollama | `~/.ollama-models` (via `OLLAMA_MODELS`) | `~/.ollama-local-models` | `/Volumes/AI-LAB/ollama/models` |
| MacWhisper | `~/Library/Application Support/MacWhisper/models` | `~/.macwhisper-local-models` | `/Volumes/AI-LAB/macwhisper/models` |

Watcher: `com.jt.aimodels-watcher` (`WatchPaths: /Volumes` → `aimodels watch
apply --silent`). It is idempotent — most firings are a no-op.

## Commands

```bash
aimodels status                # every engine + every model, per store, with sizes
aimodels where                 # terse: active store per engine, is AI-LAB mounted
aimodels eject                 # SAFE drive removal — see below
aimodels ollama  local|sd|auto|reconcile
aimodels whisper local|external|auto|reconcile
aimodels watch   status|install|remove
```

In `aimodels status`, `L` = present in the local store, `X` = present in the
AI-LAB store. A model with `·X` **disappears from its app when AI-LAB is
ejected** — that is the offline-availability answer, and it is the question worth
asking before anyone unplugs anything.

## Always eject with `aimodels eject`

Finder and `diskutil eject` report "Resource busy" on AI-LAB because at eject
time the model symlinks still resolve into the volume — and the watcher only
flips them back to local *after* the eject event fires, far too late to help.
`aimodels eject` inverts the order: release every engine to its local store,
then unmount.

If the volume is still busy after that, the holder is a loaded **model**, not the
app. Observed live: `Ollama.app -> ollama serve -> llama-server`, where the
grandchild runner holds a file descriptor on a model blob. So `eject` asks each
engine to release its own holds — `ollama stop` for every loaded model, nothing
for MacWhisper, which holds nothing between transcriptions — and retries once.
The app keeps running throughout; nothing is closed, so nothing needs reopening.

**Do not reach for quitting the app.** It was tried: Ollama's menubar app refuses
AppleScript quit with `-128 "User canceled"`, so the quit silently no-ops. And
signalling an app mid-write to a model file is how those get truncated. Unloading
the model is both gentler and the thing that actually works.

A holder no engine claims is reported and left alone; `--force` exists but can
truncate a file mid-write. `--no-release` skips the unload and just reports.

**fail ⇒ restore** is the principle throughout, and it explains something that
otherwise looks like a bug: when an eject *fails*, the failed `diskutil` still
bumps `/Volumes`, the watcher fires, sees AI-LAB still mounted, and puts both
stores back on external. That is intended — a failed eject leaves everything as
it was. (A manual `aimodels whisper local` while the drive stays mounted
persists, because nothing bumps `/Volumes`.)

## The watcher log — read this before diagnosing anything

```bash
aimodels watch status        # state + the last few log lines
tail -f ~/Library/Logs/aimodels-watcher.log
```

One line per engine per firing: timestamp, `mounted=yes|no`, `engine=`,
`status=`, `location=`, and the message or skip reason. A launchd job's stdout
goes nowhere, so this file is the only record of what any flip decided. Start
here — "why is my store back on external" is one line, not an experiment.

## Invariants — do not break these

- **A MacWhisper store contains no symlinks, ever.** MacWhisper will not *list* a
  model whose bundle is a symlink; it becomes loadable only by explicit
  `--model <id>` and invisible everywhere else. This is why relocation is a
  whole-directory store flip and why MacWhisper's `reconcile` **copies**. Ollama
  has no such problem, and its `reconcile` symlinks local→SD as it always has.
- **Never point a store symlink at a real directory that holds data, and never
  delete one.** `aimodels` refuses by design; keep it that way.
- **`reconcile` is additive and one-way (local → external).** The external store
  is the authoritative superset; nothing it already holds is overwritten and
  nothing is ever deleted.
- **Never quit or kill an app to win an eject.** Release the model instead
  (`releaseHolds`), or report the holder and let JT decide. The one place an app
  IS restarted is MacWhisper after a real store switch — see below.
- **After `ollama rm` while on SD:** don't. Ollama's reconcile leaves symlinks,
  and `ollama rm` may follow them and delete local originals. Switch to local
  first (`aimodels ollama local`), then remove.

## Footguns

1. **A model downloaded while on the local store is stranded there.** MacWhisper
   writes into its models root, so a download during an ejected spell lands in
   the local store and vanishes when the symlink flips back to external. Fix:
   `aimodels whisper reconcile` after remount. `aimodels status` shows it as
   `L·` — local-only — which is the tell.
2. **`.cache/huggingface/download/<model>/` mirrors a real bundle's shape**,
   `*.mlmodelc` entries included. It is download scaffolding, not the model. The
   local store holds one for large-v3 while holding none of its weights, so
   anything treating the cache as the model reports an external-only model as
   offline-safe. `aimodels` excludes it; hand-rolled `find` commands must too.
3. **A tokenizer/config stub is not a model.** `whisperkit/models/openai/*` holds
   json-only stubs (~3 MB) whose weights may live nowhere on this machine.
   Verified: MacWhisper does not advertise a model on the strength of a stub.
4. **`ollama ps` / `/api/tags` only ever describe the store Ollama is pointed at
   now.** They cannot tell you what is on an ejected drive. `aimodels status`
   reads both stores off disk, so it can.
5. **MacWhisper caches its model list at launch**, and `mw` has no reload verb, so
   a flipped store shows the OLD set until it restarts. `aimodels` restarts it
   automatically on a real switch — but only on a real switch, never on the
   watcher's idempotent firings, and never when it looks busy (CPU above 5%, or
   holding an audio file open, or any signal it cannot read — all of which veto
   the restart and warn instead, because quitting mid-transcription kills the
   job). `AIMODELS_NO_RESTART=1` disables it. Ollama needs no equivalent: it
   resolves new loads through the symlink, and `eject` unloads what is cached.

## Which model should I use?

This skill does not choose models. Route:

- **LLM / Ollama** → `ollama-model-picker`
- **Transcription / ASR** → `asr-model-picker`

Both need storage facts from here first: a model on an ejected drive is not a
candidate, however good it is. Lead with `aimodels status`.
