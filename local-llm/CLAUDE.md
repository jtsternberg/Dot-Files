# Local LLMs — Working Notes & Context

This directory is the **durable memory** for all ongoing work with Ollama, local
LLMs, and local-model tooling. It exists so we stop depending on JT's memory of
a dozen scattered Claude sessions. When you (an agent) are asked to do anything
with local models, **read this file first** — it captures hardware constraints,
what's been tested, decisions made, and the sharp edges already discovered.

Keep it current: when a session produces a new finding, benchmark, tool, or
decision, record it here (or in the sibling docs) before the context is lost.

> **⚠️ Machine-specific — verify first.** Everything here (benchmarks, MLX
> behavior, model picks) was measured on **one machine**. Before trusting any of
> it, run `bash local-llm/check-machine.sh`. If it reports a mismatch (a Linux
> box, a future new Mac), treat the findings as **unverified** for the current
> host and re-benchmark — do not repeat the numbers as fact.

_Last substantive update: 2026-07-07._

---

## The one-paragraph summary

On JT's **M2 Max (64 GB unified memory)**, the bottleneck for local-model coding
is **harness weight, not raw token speed**. Claude Code's agent loop is too heavy
for *today's* local models (~128s for a trivial turn) — **not a permanent verdict**;
revisit when a capable-enough model ships (e.g. the MTP-era Gemma or a future
coding model). Meanwhile **aider** is the primary offline coding tool; **pi** is
the secondary for ambiguous/exploratory work; plain `ollama run` for quick Q&A. **MLX acceleration is model-specific and hardware-specific**:
it helps some Gemma models but is a net loss for the qwen3.5 NVFP4
family on M2 (the advertised 2× is M5-only). Model bookkeeping lives in two
custom CLIs (`ollama-why`, `ollamodels`) plus `llmfit` for hardware-aware
benchmarking.

---

## Hardware & environment

- **Machine:** Apple M2 Max, 12 cores (8P + 4E), **64 GB unified memory**
  (VRAM == system RAM — the whole pool is shared).
- **Ollama:** was `0.21.2`. **Target: `0.31`** (needed for MTP / `gemma4:12b-mlx`).
  Not yet upgraded as of this writing — check `ollama --version` before assuming.
- **OS/shell:** macOS, zsh. Ollama runs as a **launchd/menubar service** — this
  matters for env vars (see Gotchas).
- **MLX eligibility:** Ollama's MLX backend needs 0.19+ and 32 GB+ RAM (both met).
  But MLX only engages for models tagged MLX/NVFP4/MXFP8 — plain GGUF (Q4_K_M)
  stays on the old llama.cpp backend.

---

## Decisions (the load-bearing conclusions)

1. **aider is the primary offline coding tool.** Diff-based, ~25–60s/turn, cheap
   to re-prompt, auto-runs flake8 and self-corrects. Not Claude Code, not pi.
2. **pi is secondary** — for ambiguous specs or "find where X happens" agentic
   exploration where one-shot correctness beats speed.
3. **Avoid Claude Code with local models *for now*.** Its harness makes ~3
   redundant tool calls per turn; ~128s for a trivial task, and `/effort` is a
   no-op locally (qwen3-coder doesn't use the extended-thinking path). This is a
   "current models aren't good enough" verdict, **not** a permanent one —
   re-test Claude Code locally whenever a materially stronger local coding model
   lands (track this in the roadmap / `bd`).
4. **MLX matters but is not free.** GGUF models leave ~2× on the table *where MLX
   works*, but MLX is broken for qwen3.5-NVFP4 on M2 (see below). Validate per
   model; never trust a blog's headline number for your hardware.
5. **`gemma4:31b` GGUF is a trap** — a thinking model at 6.5 tok/s. Avoid in any
   harness. (Already removed; archived in `ollama-why` graveyard.)
6. **Rejected tools:** `crush` (charmbracelet — tool-calling bugs with local
   Ollama, issue #1828), `cline` (heaviest harness), and the cloud-tuned
   `hermes`/`openclaw`/`codex`/`kimi`/`droid`. `qwen3-coder-next` (52 GB) skipped
   as too big for a daily driver.

---

## Model inventory & when to use each

Current install (per `ollama list`; locations tracked by `ollama-why`):

| Model | Size | Format | Role / when to use | Speed (M2) |
|-------|------|--------|--------------------|------------|
| `qwen3-coder:latest` (=30b, Q4_K_M) | 18 GB | GGUF | **Primary coding agent** (aider/pi) | 72 tok/s decode, 110 prompt-eval |
| `gemma4:26b-nvfp4` | 16 GB | MLX | Fast-context **chat** (NOT coding — weak tool-calling) | 41 decode, **558 prompt-eval (5×)** |
| `qwen3.5:9b` | 6.6 GB | GGUF | General | — |
| `qwen2.5-coder:7b` | 4.7 GB | GGUF | Coding **fallback** (no MLX equivalent) | 58 tok/s |
| `qwen2.5-coder:1.5b` | 986 MB | GGUF | **Cron title generator** (rename-claude-sessions); fast/small | 152 tok/s |
| `gemma4:e4b` | 9.6 GB | — | Lightweight/battery chat | — |
| `llama3.1:8b` | 4.9 GB | GGUF | Aging general fallback (no MLX equiv) | 42 tok/s |

**Benchmark prompt used as the standard raw-speed probe:**
`ollama run --verbose <model> "Write a Python function that reverses a string. Just the function, no explanation."`

### MLX findings (hard-won — the headline is a lie on M2)

- **Works well:** `gemma4:26b-nvfp4` — 558 tok/s prompt eval (5× the GGUF gemma).
  A genuine win for *chat / big-context ingestion*, not coding.
- **Broken on M2:** the **qwen3.5-NVFP4 family** hits an unoptimized MLX slow
  path — `qwen3.5:35b-a3b-coding-nvfp4` measured **~3 tok/s prompt eval**;
  `qwen3.5:0.8b-nvfp4` got 100 tok/s vs GGUF's 807. The Ollama blog's 2× speedup
  is tied to **M5's GPU Neural Accelerators** — M2 doesn't have them.
- **Ollama 0.31 / MTP (July 2026, not yet installed):** `gemma4:12b-mlx` claims
  ~90% faster via multi-token prediction (on by default). New 12b size class.
  Untested — needs the 0.31 upgrade first.

---

## Tooling we've built / adopted

### `check-machine.sh` — host verification (`local-llm/check-machine.sh`)
Computes a stable fingerprint (OS + CPU + core count + RAM tier) and compares it
to the machine these docs describe. Run it before trusting any benchmark here.
`bash local-llm/check-machine.sh` (status), `--hash` (print current fingerprint,
used to adopt a new reference machine), `--quiet` (exit code only). Cross-platform
(macOS + Linux).


### `ollama-why` — model annotation + graveyard (`~/.dotfiles/bin/ollama-why`)
PHP (JT namespace). Annotates `ollama list` with when/why/speed/location notes,
and keeps a **graveyard** of removed models with test results so we never re-pull
a known-bad model. Data: `~/.ollama-why.json`.
- `ollama-why` — annotated list (WHEN column, location badges).
- `ollama-why set <model> --when=… --speed=… --tested=… --location=local|sd|both`
- `ollama-why rm <model> --delete-model [--tested=…]` — archive note + `ollama rm`.
- `ollama-why history` — the graveyard. `ollama-why locate <model> <loc>`.

### `ollamodels` — dual-store reconcile (`~/.dotfiles/bin/ollamodels`)
Manages models split across the internal SSD and a Thunderbolt SD card.
- `ollamodels reconcile [--dry-run] [-y]` — symlinks local-primary models into
  the SD tree (**strictly local → SD**; reverse would dangle on eject).
- ⚠️ **UNVERIFIED DANGER:** it is *not yet tested* whether `ollama rm` on the SD
  store follows symlinks and deletes the local originals. Until verified, do
  `ollamodels local && ollama rm <model>`. Loud warnings ship in the tool.

**Storage layout:**
- Local store: `~/.ollama-local-models/`
- SD store: `/Volumes/AI-LAB/ollama/models/`
- Active store: `~/.ollama-models` → symlink (managed by `ollamodels`), currently
  pointing at the SD store.

### `llmfit` — hardware-aware model picker + real benchmarker (installed via brew)
`brew install AlexsJones/llmfit/llmfit` (v0.9.38). Rust TUI/CLI. Native Ollama +
MLX + llama.cpp integration.
- **Best use: `llmfit bench --all --quality`** — runs *real* inference against
  running providers (TTFT, tok/s, latency) + role-based quality scoring. This
  automates the manual cmux benchmark dance.
- **Community leaderboard** (`b` in TUI, from localmaxxing.com) — real-world
  numbers from other users on the same hardware; a safeguard against the next
  "the internet says pull this 20 GB model" mistake.
- **Caveat:** its *default* scoring is a bandwidth-based **estimate** — that's the
  same theoretical reasoning that mispredicted MLX-on-M2. Trust `bench` (real
  runs) and the leaderboard over the estimated `recommend`/TUI scores.

---

## Gotchas (things that already bit us)

- **`OLLAMA_CONTEXT_LENGTH` is a server-side var**, read by `ollama serve` at
  startup. With the launchd/menubar Ollama already running, setting it in your
  client shell is a **no-op**. For aider, set per-model in
  `~/.aider.model.settings.yml` (`extra_params: { num_ctx: 32768 }`).
- **aider install on macOS:** the default can grab x86_64 wheels or Python 3.14
  (too new → scipy Fortran build fail; PIL arch mismatch). Force it:
  `uv tool install --python 3.12 --reinstall aider-chat`.
- **Never run two agents in the same directory in parallel** — they clobber each
  other's edits mid-run and produce misleading benchmark data. Always isolate
  (`/tmp/aider-test` vs `/tmp/pi-test`).
- **Thinking models burn tokens** before answering (gemma4:31b generated 146
  tokens for a 3-line function). Watch for this tax in any "slow" model.
- **Ollama is reportedly 3–5× slower than llama.cpp/MLX on Qwen3 MoE**
  (ollama issue #14579). If a harness swap still feels slow, a backend swap is
  the escalation path.

---

## Standard test harness

Use the `bench-local-model` skill in `.claude/skills/` (this directory) for the
repeatable methodology — raw-speed probe, harness comparison, and `llmfit bench`.
The canonical coding-task fixtures are a small Python module with `paginate`
(off-by-one), `word_count` (whitespace split), and a `slugify` feature-add.

Ready-to-use aliases (draft — update model tags after benchmarks confirm swaps):
```sh
alias code-local='aider --model ollama_chat/qwen3-coder:latest --edit-format diff --yes-always'
alias agent-local='ollama launch pi --model qwen3-coder:latest --yes'
```

---

## Task tracking — use beads (bd)

This repo tracks **all** work with **beads (`bd`)**, not markdown TODOs or task
lists. See `~/.dotfiles/CLAUDE.md` for the full workflow. For local-LLM work:

- `bd ready` — find unblocked work. `bd create --title=… --description=… -t task|feature|bug -p 0-4` — file new work.
- `bd update <id> --claim` to start, `bd close <id> --reason=…` when done.
- Link discovered work: `bd create … --deps discovered-from:<parent-id>`.
- The "Open items / roadmap" below should become `bd` issues as they're picked up
  — this list is the durable narrative; `bd` is the live tracker.

## Open items / roadmap

1. **Upgrade Ollama 0.21.2 → 0.31** (unlocks MTP + `gemma4:12b-mlx`).
2. Benchmark `gemma4:12b-mlx` (MTP) vs `qwen3-coder:latest` for coding once on 0.31.
3. Run `llmfit bench --all` and compare its measured numbers against our by-hand
   benchmarks; reconcile any gaps.
4. **Verify the `ollama rm`-follows-symlinks danger** in `ollamodels` before
   trusting SD-side deletes.
5. Auto-reconcile on SD mount via LaunchAgent watcher (`ollamodels installwatcher`
   exists; deferred — reconcile is opt-in for now).
6. Keep the external Notes docs in sync (they predate some MLX findings).

---

## Related docs & references

**External Notes vault** (`~/Documents/Notes/AI/Local AI/`):
- `Local LLM Coding CLI Guide.md` — the aider/pi/Claude Code comparison writeup.
- `Local LLM Stack Migration Plan.md` — the MLX migration plan.
- `Local Models.md`, `Local AI External Drive Setup Guide.md` — inventory + SD setup.

**Upstream:**
- Ollama MLX blog: https://ollama.com/blog/mlx (2026-03-30)
- Ollama Claude Code integration: https://docs.ollama.com/integrations/claude-code
- aider + Ollama: https://aider.chat/docs/llms/ollama.html · edit formats: https://aider.chat/docs/more/edit-formats.html
- Ollama + OpenCode: https://docs.ollama.com/integrations/opencode · pi: https://docs.ollama.com/integrations/pi
- llmfit: https://github.com/AlexsJones/llmfit
- Issues: `charmbracelet/crush#1828`, `ollama/ollama#14579`
