---
name: asr-model-picker
version: 1.0.0
description: |
  Recommends the best LOCAL transcription (ASR) model for a task on JT's Mac —
  WhisperKit, Parakeet, or whisper-cpp under MacWhisper — and derives every fact
  from the machine instead of guessing. Use whenever the question is which
  transcription model to use, or whether one is usable right now: "which whisper
  model", "best model for this recording", "fastest transcription", "most
  accurate transcription", "can I transcribe offline / with AI-LAB unplugged",
  "which model has diarization / speaker detection", "is this model
  multilingual", "why isn't <model> in the list", "transcribe this in another
  language", "Parakeet vs Whisper", "large-v3 vs small".
  ALSO use before benchmarking or comparing ASR models — it names the footguns
  that make naive comparisons wrong. For running a transcription once the model
  is chosen, hand off to the macwhisper-cli skill. For WHERE models live and
  drive/eject questions, that is local-model-stores.
allowed-tools:
  - Bash
  - Read
  - AskUserQuestion
---

# ASR model picker

Choose the right *local* transcription model for a task, from **measured facts
about this machine** — never from a model card or your priors.

## Prime directive: derive, don't guess

An ASR model's usability here depends on things no model card knows: whether its
bundle is on a drive that is currently plugged in, whether the app advertises it
at all, whether diarization support is present, whether it is English-only.
**Every one of those is queryable.** If you catch yourself about to say "large-v3
is more accurate so use it" — stop and check whether it is even reachable right
now.

## Step 1 — Inventory (two sources, they answer different questions)

### 1a. `mw models list` — what the app will actually accept, right now

```bash
mw models list
```

Authoritative for the **active store only**, and the source of the exact `--model`
IDs. `▸` marks the active default. Example shape:

```
▸ whisperkit:openai_whisper-small               Small           483 MB
  whisperkit:openai_whisper-large-v3-v20240930  Large v3 Turbo  -
  parakeet-pro:nvidia_parakeet-v3               Parakeet v3     1.24 GB
  whisper-cpp:ggml-model-whisper-small.en       Small (English Only)  500 MB
```

A model absent here is not available, full stop — no matter what is on disk.

### 1b. `aimodels status` — both stores, including the drive that is unplugged

```bash
aimodels status
```

`L` = in the local store, `X` = in the AI-LAB store. A `·X` model **vanishes when
AI-LAB is ejected**; an `L·` model is stranded local-only and needs
`aimodels whisper reconcile`. This is the only way to answer "will this still work
offline" — `mw models list` cannot, because it only ever sees the active store.

The two sources use different names. Map them by leaf name:

| `mw` ID prefix | where the bundle lives |
|---|---|
| `whisperkit:` | `whisperkit/models/argmaxinc/whisperkit-coreml/<name>` |
| `parakeet-pro:` | `whisperkitpro/models/argmaxinc/parakeetkit-pro/<name>` |
| `whisper-cpp:` | top-level `<name>.bin` |

Take **IDs from `mw`**, never synthesized from a path.

### 1c. `mw help transcribe` — the flags that matter

```bash
mw help transcribe          # --model, --speakers/--no-speakers, --language, formats
mw models select            # change the app's active default
```

## Step 2 — Classify what you found

- **English-only vs multilingual.** `*.en` (`ggml-model-whisper-*.en`) are
  English-only — a non-English clip through one produces confident garbage.
  WhisperKit `openai_whisper-*` are multilingual; Parakeet v3 covers a European
  language set, not all of Whisper's.
- **Offline-safe or drive-bound.** From `aimodels status`, not from size.
- **Runtime.** WhisperKit/Parakeet are CoreML (`*.mlmodelc`, Neural Engine);
  whisper-cpp `.bin` are CPU/GGML. Different performance regimes; do not compare
  their sizes as if they were the same thing.
- **Diarization.** `--speakers` needs the `speakerkit` support bundle. Check it is
  present (`aimodels status` lists it as `(support)`); it is kept in the local
  store precisely so `--speakers` survives an eject.

## Step 3 — Match & recommend

Anchor on the binding constraint — usually availability, then language, then
accuracy-vs-speed.

```
AI-LAB unplugged (or might be, mid-task)?
  → only L models are candidates. Today that is small + the .en whisper-cpp set.
NON-ENGLISH audio?
  → a multilingual WhisperKit model; never a .en bin. Check Parakeet's language
    list before offering it.
NEEDS speaker labels?
  → any model + --speakers, provided speakerkit is present.
LONG recording, throughput matters?
  → Parakeet v3 is the throughput-oriented CoreML option; large-v3 turbo is the
    accuracy-oriented one. MEASURE before claiming which is faster here.
HIGHEST accuracy, drive plugged in, English or not?
  → whisperkit large-v3-v20240930.
SHORT English clip, want it now?
  → the active default (small) is almost always the right answer. Don't upsell.
```

**Tie-breakers:** availability beats accuracy; the active default beats a flip
that requires quitting the app; English-only beats multilingual *only* when the
audio is certainly English and speed matters.

### When NOT to use a local model
Audio in a language none of the installed multilingual models covers well; a
correctness bar where a human check is required anyway; or the file is enormous
and JT needs it now — say so rather than starting a 40-minute local run.

## Verified machine facts (2026-08-24 — re-verify, don't trust)

Installed, and which store holds them:

| model | store | notes |
|---|---|---|
| `whisperkit:openai_whisper-small` | local + AI-LAB | **active default**, offline-safe, 464 MB |
| `whisper-cpp:ggml-model-whisper-{tiny,base,small}.en` | local + AI-LAB | English-only, offline-safe |
| `whisperkit:openai_whisper-large-v3-v20240930` | **AI-LAB only** | 1.5 GB, gone when ejected |
| `parakeet-pro:nvidia_parakeet-v3` | **AI-LAB only** | 1.2 GB, gone when ejected |
| `speakerkit` | local + AI-LAB | diarization support; verified working offline |

Verified by live test: with the local store active, `mw models list` shows only
small + the three `.en` models — large-v3 and Parakeet are cleanly *not*
advertised, and diarization still detected 2 speakers on a 120 s clip.

**No throughput numbers are recorded yet.** Do not invent any. If speed drives the
decision, measure (below) and add the result here.

## Footguns

1. **A symlinked bundle is invisible.** MacWhisper will not list a model whose
   bundle directory is a symlink — it loads only via explicit `--model` and
   appears nowhere else. Never "relocate" a single bundle; storage moves are
   whole-store flips (`local-model-stores`).
2. **A tokenizer stub is not a model.** `whisperkit/models/openai/*` is json-only
   (~3 MB). Verified: the app does not advertise a model from a stub alone.
   Neither should you.
3. **`.cache/huggingface/download/<model>/` looks exactly like a bundle**,
   `*.mlmodelc` and all. It is download scaffolding. The local store has one for
   large-v3 while holding none of its weights.
4. **`mw models list` describes one store.** Answering "is it available offline"
   from it is wrong by construction. Use `aimodels status`.
5. **`.en` models on non-English audio fail silently** — fluent, wrong English.
6. **A running MacWhisper caches its list at launch.** After a store flip,
   relaunch before concluding a model is missing.
7. **Size is not speed across runtimes.** A 1.2 GB CoreML bundle can beat a 500 MB
   GGML one on this hardware. Only a measurement settles it.

## Measuring cleanly (when speed drives the choice)

1. Use a real clip of representative length — not a 5-second sample; ASR cost is
   roughly linear in audio duration and startup dominates short clips.
2. Warm up once with a throwaway run, discard that timing (first CoreML run
   compiles/loads).
3. Time each model on the **same** file, one at a time:
   `time mw transcribe <file> --model <id> --no-speakers`
4. Turn diarization off while measuring transcription throughput; `--speakers`
   adds its own pass.
5. Report as `audio-seconds / wall-seconds` (a realtime factor), not raw seconds —
   it transfers to other files.
6. Write the result into the table above so the next session inherits it.

## Output format

```
**Recommendation:** `mw transcribe <file> --model <id> [flags]`

<one sentence citing a checked fact: "small is the active default and the only
multilingual model that survives an AI-LAB eject — 464 MB, offline-verified.">

<if relevant: the better-but-drive-bound alternative, with its trade-off.>
```

If JT gave a concrete file, run it with the pick — don't just advise. Hand the
actual invocation details to the `macwhisper-cli` skill if you need its flags.
