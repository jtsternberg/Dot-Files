# Local LLM and Ollama models

Help JT choose the right *local* model for a task — and do it from **measured facts
about this machine**, never from generic specs or memory.

## Prime directive: derive, don't guess

Local-model behavior on this Mac diverges hard from what a model card or your priors
would suggest. Quantization/runtime (MLX vs GGUF) can swing prompt-eval throughput by
100×; a "9B" can be slower than a "26B"; the same model silently truncates a long file
at one context setting and ingests it whole at another; a "reasoning" model burns a
minute of thinking on a one-line ask. **Every one of these is queryable.** If you catch
yourself about to say "qwen is probably…", "gemma should be fast…", "it's a 9B so…" —
stop and run the command that tells you. The whole reason this skill exists is to end
the cycle of re-tripping the same footguns (see **Footguns** below — read it before
benchmarking anything).

## Step 1 — Empirical inventory (run these; don't assume)

Gather from all four sources. They answer different questions; use them together.

### 1a. `ollama ps` — runtime state (CHECK THIS FIRST)

```bash
ollama ps
```

Shows what's loaded **right now**, the **CONTEXT** window actually allocated (this is the
truncation limit — *not* the model's max context), and GPU/CPU placement. A model that's
already warm answers fast; one that must cold-load pays a load + prefill penalty. The
CONTEXT column is the single most-skipped fact and the cause of silent truncation.

### 1b. Installed models + capabilities (max context, thinking, tools, quant)

```bash
for m in $(curl -s http://localhost:11434/api/tags | python3 -c "import json,sys;[print(x['name']) for x in json.load(sys.stdin).get('models',[])]"); do
  echo "=== $m ==="
  curl -s http://localhost:11434/api/show -d "{\"name\":\"$m\"}" | python3 -c "
import json,sys; d=json.load(sys.stdin); mi=d.get('model_info',{})
det=d.get('details',{})
ctx=next((v for k,v in mi.items() if 'context_length' in k and v),'?')
exp=next((v for k,v in mi.items() if 'expert' in k.lower() and v),None)
print(json.dumps({'params':det.get('parameter_size','?'),'family':det.get('family','?'),
 'quant':det.get('quantization_level','?'),'max_context':ctx,'experts':exp,
 'capabilities':d.get('capabilities')}))"
done
```

- **capabilities** — the authoritative answer to "is this a thinking model / tool-caller /
  multimodal?". If `thinking` is present, it *can* reason internally; disable it for
  simple tasks (see Footguns). `tools` = agent-capable. No more guessing from the name.
- **quant** — `Q*_K_M`/`Q4_0` etc = GGUF; `nvfp4`/`mlx*` = MLX. On M2 this dominates
  prompt-eval throughput far more than param count.
- **max_context** — the ceiling you can raise `num_ctx` to (bounded by RAM). Distinct
  from the runtime CONTEXT in `ollama ps`.
- **experts** — present ⇒ MoE; effective speed tracks *active* params, not total.

`ollama show <model>` gives the same in human form (Capabilities / Parameters / context).

### 1c. `ollama-why` — the compounding log of PRIOR measurements + graveyard

```bash
ollama-why            # annotated `ollama list`: when / speed / tags / location per model
ollama-why history    # the graveyard: models already tested & removed, with the reason
ollama-why notes      # raw JSON (for parsing)
```

This is a persistent, machine-specific record of what past sessions found: tok/s numbers,
what each model was used for, and a **graveyard** of models tried and removed. It is how
learnings compound across sessions — read it first so you build on prior work instead of
restarting cold.

**But treat it as prior results to verify, not gospel.** These notes were written by past
agent sessions (you, effectively), using the same fallible harness you have now — some
numbers are cache-contaminated, measured on short prompts, or otherwise wrong. Concrete
example: a note may claim gemma4:26b does "558 tok/s prompt eval" while a clean full-file
bench measures ~314. When a recorded number conflicts with fresh clean data, or looks too
good to be true (see Footgun #9), **re-measure and correct the note** — don't defer to it
and don't cite it as authority. The graveyard is more durably useful (a model that failed
a role probably still fails it), but even its numbers deserve the same skepticism.

After any clean benchmark, **write the result back** with
`ollama-why set <model> --speed="…" --tested="…" --when="…"` (date the `--tested` note),
so the log improves rather than ossifies.

### 1d. `aimodels status` — storage location (affects availability + load time)

```bash
aimodels status       # both local and AI-LAB stores, even when the drive is absent
```

Models shown only in the `X` store are unavailable when AI-LAB is unmounted
(`ollama-why` flags these "Offline") and cold-load slower than local-disk models.
`location` in `ollama-why` notes records where each lives. Read
[stores.md](stores.md) before changing stores or ejecting the drive.

### 1e. `llmfit` — hardware fit + candidates you haven't installed + real bench

```bash
llmfit recommend --json          # top models for THIS hardware (incl. not-installed), with fit_level, memory_required_gb, estimated_tps
llmfit fit                       # table of models that fit
llmfit bench <model> --json      # MEASURE real decode/prompt-eval against running Ollama (use for fresh data)
```

Use llmfit to answer "will X even fit in RAM?" and "what should I *pull* for this?" — it
sees models beyond what's installed. **But its `estimated_tps` is a generic estimate** and
this machine diverges from generic (a prior note has `qwen3.5:35b-nvfp4` at "3 tok/s
prompt eval, catastrophic" — no DB predicts that; verify it if it matters). Trust order
for speed on this box:
**fresh clean measurement (a `llmfit bench` or `/api/generate` run you just did, following
the method below) > a dated prior `ollama-why` measurement (verify if it drives the
decision) > `llmfit` estimate > guessing (never)**.
Note llmfit's model names are HuggingFace-style, not Ollama tags — map via `llmfit search`.

## Step 2 — Classify what you found

- **Specialization** — code (`basename`/name has Coder/Code/Starcoder) vs general vs
  embedding. Code models summarize prose worse and can hallucinate framing (observed:
  `qwen2.5-coder:1.5b` inverted a doc's emphasis).
- **Thinking** — from `capabilities`, not the name. Thinking-capable ⇒ plan to pass
  `think:false` unless the task genuinely needs reasoning.
- **Quant / runtime** — GGUF vs MLX/nvfp4, from `quant`. The prime lever on prefill speed.
- **Effective size** — MoE (has `experts`) runs at active-param speed, not total.
- **Context headroom** — max_context vs the input you'll feed it. If input > runtime
  CONTEXT, you MUST raise `num_ctx` or it truncates (Footgun #1).

## Footguns — banked learnings (read before ANY benchmark or comparison)

These are the mistakes we keep re-making. Each has a one-line detector.

1. **Silent context truncation.** Default runtime context is capped (32768 here; some
   models lower — `gemma4:e4b` self-capped at 16384). Feed a longer file and the model
   summarizes only the head, silently — a *correctness* bug, not just speed.
   **Detect:** `ollama ps` CONTEXT column; compare to input tokens (`prompt_eval_count`
   in the response). **Fix:** set `options.num_ctx` ≥ input tokens (≤ max_context, watch RAM).

2. **Prompt/KV caching fakes "fast".** Re-running the *same* prompt hits the KV cache →
   near-instant (measured: 47s cold → **0.04s** warm). This silently contaminated an
   earlier benchmark and produced a bogus "6× faster" claim. **Detect:** suspiciously low
   prefill on a repeat; `prompt_eval_duration` near zero. **Fix:** unload between cold
   runs (`curl … -d '{"model":"M","keep_alive":0}'`); never compare warm-vs-cold.

3. **Thinking mode burns time.** A reasoning model can spend 80s of internal monologue on
   a 3-sentence answer. **Detect:** `capabilities` includes `thinking`. **Fix:**
   `think:false` (API) / `--think=false` (CLI).

4. **MLX/nvfp4 vs GGUF prompt-eval.** On M2 this swings prefill 100×+ (gemma nvfp4 fast;
   some qwen3.5 nvfp4 catastrophic — see graveyard). Param count is a poor speed proxy;
   **prompt-eval tok/s** is the real driver for big inputs. **Detect:** `quant` field +
   `ollama-why` measured tok/s.

5. **First-run kernel compile.** The first inference after the server starts compiles
   Metal shaders and skews that one timing. **Fix:** one throwaway `generate` before
   measuring.

6. **`ollama run` hides the controls.** The CLI exposes no system prompt, `num_ctx`,
   `num_predict`, `temperature`, or `think`. **Fix:** use `/api/generate` for anything
   controlled or repeatable.

7. **Judging a summary from memory.** Don't rate output quality against what you *think*
   the file says — read/grep the source first. (Opus once called a correct local summary
   "hallucinated garbage"; the source contained every disputed term.)

8. **Cold big-file summarization is just slow locally (~2 min for ~35K tokens),**
   regardless of model — because prefill is O(input). If the user needs a fast summary of
   a large *new* file, the honest answer is often a cloud model, not a local one.

9. **Recorded numbers can carry the same contamination.** A prior `ollama-why` / graveyard
   tok/s figure was measured by a past session with this same harness — it may be a cache
   hit, a short-prompt result, or a different quant path. A prompt-eval number that seems
   implausibly high is the tell (caching inflates it). **Detect:** it conflicts with a
   fresh clean run, or it's undated/unexplained. **Fix:** re-measure with the clean method,
   then correct the note. Compounding learnings means *improving* the record, not trusting
   it blindly — past-you was as fallible as present-you.

## Step 3 — Match & recommend

Anchor on the constraint that actually binds (usually speed on the real input size, or
fit), then:

```
CODE task?
  → prefer a code-specialized model; size to complexity; check it fits.
LONG input (say >8–10K tokens) and speed matters?
  → prefer highest measured PROMPT-EVAL tok/s (ollama-why), not smallest params.
    Confirm num_ctx will hold the input. If cold latency is unacceptable, say so and
    offer a cloud path — don't pretend a 2-min local run is "fast".
NEEDS reasoning (multi-step logic, math, design trade-offs)?
  → thinking-capable model, thinking ON.
ELSE (short general task, speed matters)?
  → smallest capable general model that follows instructions.
```

**Tie-breakers**, in order:
1. Long input + speed → higher measured prompt-eval tok/s wins, even if larger/MoE.
2. Anything in the graveyard for this role → skip it.
3. Code task → code model over general of similar size.
4. Same role → newer arch (Gemma 4 > Gemma 2; Qwen 3.5 > 2.5).
5. Quality bar high → larger/higher-quant if latency allows; note the trade.

### When NOT to use a local model
Input exceeds every installed model's usable context; needs post-cutoff knowledge;
correctness bar is high (legal/medical/production); no installed model has the right
specialization; or the user needs a *fast* summary of a large new file (see Footgun #8).
"Use Claude / the cloud path for this one" is a valid, often-correct recommendation.

## Getting good output from small local models

Small models obey poorly, especially negative/leading instructions. Drive them via
`/api/generate` and: put the instruction **after** the content (recency beats a leading
"no markdown", which they mirror from a markdown-heavy input); phrase **positively**
("write one prose paragraph of 2–4 sentences"); set a `system` role; `temperature:0`;
cap with `num_predict`; `think:false` on reasoning models. Working reference:
`~/.dotfiles/bin/llmsummarize` (the `ollama` fork) — it also does size-based
routing across local tiers + a cloud fallback, and sizes `num_ctx` to the file.

## Clean benchmarking method (when measured data is missing/stale)

Either use `llmfit bench <model> --json`, or roll a clean `/api/generate` run:

1. **Warm up kernels** once (throwaway `generate`, `num_predict:1`), then discard.
2. **Set `num_ctx`** ≥ your input tokens so nothing truncates; **verify** the response's
   `prompt_eval_count` matches the expected input size.
3. **Unload before each cold run** (`keep_alive:0`) so you measure cold prefill, not cache.
4. Read **server-side timings** from the response (nanoseconds):
   `prompt_eval_count/prompt_eval_duration` → prefill tok/s (the big-input driver);
   `eval_count/eval_duration` → decode tok/s; `load_duration` → load cost.
5. For quality, **read the source** and check the summary against it.
6. **Write results back to `ollama-why`** (`set … --speed --tested --when`) so the next
   session inherits the learning instead of re-measuring.

Estimate cold latency as: `input_tok / prompt_eval_tok_s + output_tok / decode_tok_s + load_s`.

## Output format

Keep it tight and grounded in the numbers you pulled:

```
**Recommendation:** `<how to run it>`

<one sentence citing a MEASURED fact: "gemma4:26b-nvfp4 — 314 tok/s prompt-eval measured
on the full file, holds it at num_ctx=40960; ~2 min cold for a 35K-token doc.">

<if relevant: a faster/leaner or higher-quality alternative, with its trade-off.>
```

Include a small table only when several options are genuinely in play (columns:
Model · Params/MoE · Quant · Measured prompt-eval tok/s · Fits input? · Est. cold time).
If the user gave a concrete task, run it with the pick — don't just advise.
