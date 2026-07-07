---
name: bench-local-model
description: Use when benchmarking a local Ollama model or comparing coding harnesses (aider / pi / Claude Code) on JT's M2 Max — encodes the raw-speed probe, isolated-dir harness comparison, and llmfit bench methodology, plus the gotchas that produce misleading numbers.
---

# Benchmark a Local Model / Harness

Repeatable methodology for testing local models on JT's M2 Max. Read
`../../CLAUDE.md` (the `local-llm/` context file) first for hardware constraints,
prior findings, and the decisions this builds on. **Record new results back into
that file** (and update `ollama-why`) when you finish — that's the whole point.

## Three levels of test

Pick the shallowest level that answers the question.

### Level 1 — raw speed probe (seconds)

Separates model/runtime speed from harness overhead. Use the standard prompt so
numbers are comparable across sessions:

```sh
ollama run --verbose <model> "Write a Python function that reverses a string. Just the function, no explanation."
```

Read from `--verbose`: **eval rate** (decode tok/s), **prompt eval rate**
(prefill tok/s — the number MLX-on-M2 quietly destroys), **load duration**.

- Run **cold then warm** (immediately re-run). First run pays load + a possible
  cold-start race — `qwen3-coder` once crashed with `llama runner process has
  terminated` after weeks unused; a retry fixed it. Report warm numbers as the
  baseline, note load separately.
- Watch for **thinking-model tax**: token count wildly exceeding the answer
  length (e.g. 146 tokens for a 3-line function) means the model burns
  chain-of-thought first. Flag it — that model is a poor agent fit.

### Level 2 — llmfit bench (real inference, automated)

`llmfit` (installed via brew) turns the manual dance into one command:

```sh
llmfit bench --all              # every discovered model across running providers
llmfit bench --provider ollama <model>
llmfit bench --all --quality --routing   # role-based quality + routing matrix
llmfit bench --json             # machine-readable
```

Trust `bench` (real runs) over `llmfit recommend`/TUI scores (bandwidth
**estimates** — the same theoretical reasoning that mispredicted MLX-on-M2).

### Level 3 — harness comparison (the real "is it usable" test)

Compare aider vs pi (vs Claude Code) on an identical coding task.

**Drive the parallel panes with cmux — invoke the `cmux-cli:using-cmux-cli`
skill first.** These are long-running interactive REPLs that must run side by
side (one tool per pane) while you watch and time them; that's exactly what cmux
is for, and the skill documents the workspace/surface/tty model, the `cmux send`
escape rules (`\n` = Enter), the focus-required-to-spawn-tty quirk, and how to
`read-screen` for completion — all of which this test depends on. Do not hand-roll
raw `cmux` commands here; load that skill and follow it.

**CRITICAL — isolate directories.** Never run two agents in the same dir; they
clobber each other's edits mid-run and produce garbage data. One temp dir per
tool:

```sh
for d in /tmp/aider-test /tmp/pi-test; do rm -rf "$d"; mkdir -p "$d"; done
# copy identical fixtures into each
```

**Standard fixtures** (write into each dir): a `utils.py` with two seeded bugs —
`paginate` (off-by-one on 1-indexed input) and `word_count` (splits on literal
`" "`, fails on multiple spaces) — plus a `test_utils.py`. Round 2 is a feature
add: `slugify(text)` + tests (a good ambiguity probe — models disagree on
collapsing consecutive separators).

Launch commands:
```sh
# aider (diff-based; /add files, then prompt)
cd /tmp/aider-test && aider --model ollama_chat/<model> --edit-format diff --no-git --yes-always

# pi (agentic loop)
cd /tmp/pi-test && ollama launch pi --model <model> --yes
```

Fire the **same prompt** to both, time to completion, then verify by running the
tests. Record: wall-clock, tests passing, tokens sent, and whether it needed a
follow-up nudge.

## Measuring reliably

- Timestamp with `date +%s` into a temp file before sending; diff after the tool
  reports done. Don't trust a polling loop's first match — model output scrolls
  and stale "eval rate:" lines from a prior run cause false positives.
- When driving panes with cmux (see the `cmux-cli:using-cmux-cli` skill for
  send/read semantics), wait on a **specific** completion marker, not a generic
  prompt pattern — stale output from a prior run causes false positives.
- `OLLAMA_CONTEXT_LENGTH` in your shell is a no-op against the launchd Ollama.
  For aider, pin `num_ctx` in `~/.aider.model.settings.yml` instead.

## After the run

1. Update the table in `../../CLAUDE.md` (model inventory / MLX findings).
2. `ollama-why set <model> --tested="<result>" --speed="<numbers>"` — or, if the
   model is bad, `ollama-why rm <model> --delete-model --tested="<why>"` to
   archive it in the graveyard so it's never re-pulled blindly.
