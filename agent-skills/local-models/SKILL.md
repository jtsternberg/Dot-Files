---
name: local-models
description: |
  Chooses and operates JT's LOCAL AI models across Ollama LLMs and MacWhisper
  transcription, using live machine facts instead of generic model lore. Use
  whenever the user asks which/what model, wants to run something locally,
  compares or benchmarks local models, wants multiple model outputs judged,
  asks which Whisper/ASR model to use,
  needs offline transcription or diarization, or asks where models live,
  whether AI-LAB is required, why a model is missing, how to reconcile stores,
  or why AI-LAB will not eject. Includes "run this locally", "can I transcribe
  offline", "Parakeet vs Whisper", Ollama fit/speed/context, aimodels watcher,
  and safe eject questions. Not for cloud-only model IDs or pricing; after
  deciding local is the wrong path, use an available cloud-model skill.
allowed-tools:
  - Bash
  - Read
  - AskUserQuestion
  - WebFetch
---

# Local models

Use one machine-derived decision process for every local model task. A model on
an ejected AI-LAB drive is not a candidate, however good its model card looks.

## Start with reality

Run `aimodels status` before recommending or operating on a model. It reports
both the internal and AI-LAB stores even when the external drive is absent.
Treat recorded inventory and benchmark numbers as dated evidence to re-check,
not permanent facts.

## Route by scenario

Read only the reference needed for the request; read more than one when the
request crosses domains.

- **Local text, reasoning, code, summarization, fit, context, or Ollama
  benchmarking:** read [references/ollama.md](references/ollama.md). It owns live
  capability checks, `ollama-why`, `llmfit`, and clean performance measurement.
- **Transcription, language coverage, diarization, WhisperKit, Parakeet, ASR
  benchmarking, or comparing transcript quality:** read
  [references/asr.md](references/asr.md). It owns exact `mw` model IDs,
  offline-safe choices, clean realtime-factor measurement, and controlled
  output comparison.
- **Availability, offline use, model paths, missing models, store reconciliation,
  watcher behavior, AI-LAB, or eject failures:** read
  [references/stores.md](references/stores.md). It owns `aimodels` operations and
  the safety invariants for both engines.
- **Local versus cloud:** first check local availability and task constraints in
  the relevant reference. Recommend cloud when no installed local model fits the
  context, language, correctness, or latency requirement. If `claude-api` is
  available, use it only for the cloud-model choice itself; otherwise use the
  current harness's cloud-model guidance. Do not duplicate IDs or pricing here.

If the user supplied a concrete task or file, make the grounded choice and carry
out the local operation when authorized. Do not stop at a generic comparison.
