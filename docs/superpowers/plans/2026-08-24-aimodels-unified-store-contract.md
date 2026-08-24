# aimodels — unified local-model store contract

Working contract between the repo side (`aimodels` CLI + generalized watcher) and the
live-machine side (building the two MacWhisper stores on disk). **Contract first: build
the disk structure against §2/§3, not against `macwhisper-relocate` as shipped.**

`bin/macwhisper-relocate` (commit 6bc7ec1) is **superseded** — per-bundle in-place
symlinking makes a relocated model invisible to `mw models list`. It gets rewritten as a
front-end onto the whole-dir flip.

## 1. Investigation writeup — how the Ollama side actually works

**`bin/ollamodels`** (PHP, `JT` namespace, procedural + `helpyHelperton`). Three real
paths:

| role | path |
|---|---|
| indirection symlink | `~/.ollama-models` |
| local store | `~/.ollama-local-models` |
| external store | `/Volumes/AI-LAB/ollama/models` |

Verbs: `local` / `sd` / `auto` (default) / `reconcile` / `installwatcher` /
`removewatcher`.

`switchTo()` is the flip: validate target `is_dir`; `unlink` the existing symlink (or, if
the path is a real dir, refuse — *except* the one special case where it detects an
orphaned `*-partial`-only Ollama download dir and offers to delete it); `symlink(target,
symlink)`; then `launchctl setenv OLLAMA_MODELS <symlink>` so `Ollama.app` (launchd-
spawned) inherits it next launch; then warn if `Ollama.app` is currently running, because
setenv only affects new launches.

`auto` = `is_dir($sdPath) && is_readable($sdPath) ? sd : local`.

`reconcile` symlinks local→SD (manifests + blobs, dedup-aware, `--dry-run`, `-y`), so the
SD store "sees" all local models; direction is strictly local→SD to avoid dangling on
eject. It also promotes `location: local → both` in `~/.ollama-why.json`. Carries a loud
unverified warning: after reconcile, run `ollama rm` **only** while on local, or it may
follow symlinks and delete local originals.

**The watcher.** `installwatcher` writes
`~/Library/LaunchAgents/com.jt.ollamodels-watcher.plist` and `launchctl load`s it.
Verified present and loaded (`launchctl list` → `com.jt.ollamodels-watcher`). Contents:

```
Label            com.jt.ollamodels-watcher
WatchPaths       [ /Volumes ]
ProgramArguments [ /opt/homebrew/Cellar/php@8.3/8.3.14/bin/php,
                   /Users/JT/.dotfiles/bin/ollamodels, auto, --silent ]
```

So: launchd fires on **any** `/Volumes` mutation (mount or eject — not a mount-specific
API), and the job just re-runs `auto`, which recomputes the correct target and re-points
the one symlink. Idempotent by construction. Currently
`~/.ollama-models → /Volumes/AI-LAB/ollama/models` (AI-LAB mounted).

Two weaknesses worth fixing while generalizing:
- **Non-atomic flip** — `unlink` then `symlink` leaves a window with no symlink at all. A
  consumer reading in that window sees a missing store. Fix: `symlink(target, tmp)` +
  `rename(tmp, path)` (atomic replace on a symlink).
- **No mount settle, no lock** — `WatchPaths` can fire before a volume is fully readable,
  and every `/Volumes` change spawns a run with no mutual exclusion.

**Hard-coded PHP binary** in the plist (`php@8.3/8.3.14`) is a brew-upgrade landmine;
the new watcher should use a stable `php` path.

## 2. Observed MacWhisper disk state (read-only)

`~/Library/Application Support/MacWhisper/models` is currently a **real directory**
(so the symlinked-root test was reverted), containing:

```
argmaxinc/{parakeetkit-pro, whisperkit-coreml, whisperkit-pro}   # each has config.json + .cache/
whisperkit/models/argmaxinc/whisperkit-coreml/{openai_whisper-large-v3-v20240930, openai_whisper-small}
whisperkit/models/openai/{whisper-small.en, whisper-large-v3, whisper-base, whisper-small}
whisperkitpro/models/argmaxinc/...
speakerkit/{speaker_embedder/pyannote-v3-pro, speaker_clusterer/pyannote-v4, speaker_segmenter/pyannote-v3-pro}
ggml-model-whisper-{tiny,base,small}.en.bin        # ~713 MB total
.DS_Store
```

**Exactly one per-bundle symlink exists** — my superseded script's work:

```
models/whisperkit/models/argmaxinc/whisperkit-coreml/openai_whisper-large-v3-v20240930
  -> /Volumes/AI-LAB/macwhisper-models/openai_whisper-large-v3-v20240930
```

That is the invisible model from finding #2. `/Volumes/AI-LAB/macwhisper-models/` is flat
and holds only that one bundle.

**Two consequences for the disk build:**

1. **Undo the per-bundle relocation first.** If that symlink is still a symlink when you
   replicate the tree into a store, it replicates as a symlink and the model stays
   invisible. Copy the AI-LAB bundle back to a real dir at that path (or place a real copy
   directly into the external store's replica), then retire the flat
   `/Volumes/AI-LAB/macwhisper-models/` directory.
2. **A store is a full replica of the `models` root tree, not a flat bundle list.** Bundles
   sit 2–5 levels deep across four vendor trees (`argmaxinc/`, `whisperkit/`,
   `whisperkitpro/`, `speakerkit/`) plus top-level `.bin` files. Both stores need that
   whole shape; the flip only ever swaps the root.

## 3. Store paths (build to these)

| engine | symlink (app-facing) | local store | external store |
|---|---|---|---|
| ollama | `~/.ollama-models` | `~/.ollama-local-models` | `/Volumes/AI-LAB/ollama/models` |
| macwhisper | `~/Library/Application Support/MacWhisper/models` | `~/.macwhisper-local-models` | `/Volumes/AI-LAB/macwhisper/models` |

Rationale:
- Local store lives **outside** `Application Support/MacWhisper/` so that directory keeps
  exactly the one entry MacWhisper expects (`models`) and never enumerates a sibling store
  as junk. Also mirrors the `~/.ollama-local-models` convention.
- External is `/Volumes/AI-LAB/<engine>/models` for symmetry with the existing
  `ollama/models`, which is what lets one generic adapter compute both. This means
  **migrating** the current flat `macwhisper-models/` into `macwhisper/models/`.

**Store contents:**
- **local** = minimal offline-safe set: `openai_whisper-small` + whatever support models it
  genuinely needs (speakerkit/diarization, MelSpectrogram, the whisper-cpp `.en` bins) —
  you determine the exact set empirically and feed it back.
- **external** = full superset: everything in local **as real copies** + large-v3 +
  parakeet-v3 + future experimental models.

**Invariant — no symlinks inside a MacWhisper store, ever.** Finding #2 makes symlinked
bundles invisible, so the small model and support models exist as **duplicate real copies**
in both stores. Accepted cost: roughly the `.bin` files (~713 MB) + small + speakerkit,
duplicated. This is the one place the MacWhisper engine cannot mirror Ollama's
symlink-based `reconcile`.

## 4. Flip contract

`apply(location)` where `location ∈ {local, external}`:

1. **Preflight** (never mutates; failure ⇒ engine reported unmanageable, watcher skips it):
   - target store exists and is a real, readable directory;
   - symlink path is a symlink **or** missing. **If it is a real directory: refuse.** Print
     migration instructions, exit non-zero, delete nothing. (No MacWhisper analogue of
     ollamodels' orphaned-partial-dir deletion — never auto-delete a real models root.)
2. **Idempotence** — already pointing at the target ⇒ silent no-op, exit 0. The watcher
   fires on every `/Volumes` change, so this is the common path.
3. **Atomic swap** — `symlink(target, <path>.aimodels-tmp.<pid>)` then `rename(tmp, path)`.
   No window where the app sees no models root. Backported to the Ollama engine too.
4. **Verify** — re-`readlink` and confirm it equals the target; on mismatch, restore the
   previous target and fail loudly.
5. **Post-apply hooks** (engine-specific):
   - ollama: `launchctl setenv OLLAMA_MODELS ~/.ollama-models`; warn if `Ollama.app` is
     running (env applies to next launch only).
   - macwhisper: no env var (the path is hard-coded, which is *why* the symlink is the
     mechanism). Warn if MacWhisper is running — it caches the model list at launch, so a
     flip under a running app shows a stale list until relaunch. **Never auto-quit the
     app.** Active-model-vanishes-on-eject is an accepted tradeoff, same as Ollama.
6. **Mount settle** — on the mount edge, `WatchPaths` can fire before the volume is
   readable. Bounded retry (≈5 × 600 ms) on store readability before concluding "not
   mounted". Eject needs no settle.
7. **`reconcile` is engine-specific, not shared.** Ollama = symlink local→SD (existing
   semantics, existing `ollama rm` warning). MacWhisper = **copy** (`ditto` + file-count
   parity verify, the one good part of the superseded script), never symlink.

## 5. Adapter interface

```php
namespace JT\LocalModels;

interface StoreEngine {
    public function name(): string;                 // 'ollama' | 'macwhisper'
    public function label(): string;                // human-facing
    public function symlinkPath(): string;
    public function storePath(string $location): string;   // 'local' | 'external'
    public function preflight(): Preflight;         // ->ok, ->manageable, ->reason
    public function currentLocation(): ?string;     // resolved from the symlink
    public function apply(string $location, bool $dryRun = false): ApplyResult;
    public function reconcile(array $opts): ApplyResult;   // engine-specific semantics
    public function residency(): array;             // rows for `aimodels status`
    public function postApply(string $location): void;     // setenv / running-app warnings
}
```

Watcher loop:

```php
$mounted  = Drive::isMounted('AI-LAB');          // + settle
$location = $mounted ? 'external' : 'local';
foreach (Registry::engines() as $engine) {
    $pre = $engine->preflight();
    if (! $pre->manageable) { $log->skip($engine->name(), $pre->reason); continue; }
    $engine->apply($location);
}
```

Always non-interactive (never prompts, never deletes), `flock`-guarded against concurrent
`/Volumes` firings, and appends structured lines to a watcher log.

**Self-gating — this is how we avoid both mutating the same path.** `MacWhisperEngine`
reports `manageable = false` until both stores exist *and* the symlink path is not a real
directory. So I can build and even install the unified watcher while your disk work is in
flight: it manages Ollama and **skips MacWhisper entirely** until your structure lands.
`.../MacWhisper/models` stays yours to mutate; mine only via the shipped CLI once you
confirm.

## 6. Command surface

```
aimodels status                     # unified residency: engine, model, size, location, offline-safe
aimodels where                      # active store per engine + is AI-LAB mounted
aimodels ollama <local|sd|auto|reconcile> [--dry-run] [-y]
aimodels whisper <local|external|auto|reconcile|status> [--dry-run] [-y]
aimodels watch <install|remove|status>
aimodels completion zsh
```

Back-compat shims: `bin/ollamodels` → `aimodels ollama …`; `bin/macwhisper-relocate` →
`aimodels whisper …` (and it refuses the old per-bundle mode, pointing at the migration).

## 7. Build plan — files and phase order

**P1 — contract (this doc).** You build the disk structure to §2/§3.

**P2 — repo skeleton, in parallel, watcher NOT installed:**
```
src/LocalModels/StoreEngine.php        # interface
src/LocalModels/Preflight.php          # + ApplyResult value objects
src/LocalModels/OllamaEngine.php       # wraps today's ollamodels logic
src/LocalModels/MacWhisperEngine.php   # whole-dir flip + copy-reconcile, self-gating
src/LocalModels/Registry.php
src/LocalModels/Drive.php              # mount detection + settle
src/LocalModels/Flip.php               # atomic symlink swap + verify
src/LocalModels/Watcher.php            # plist gen, install/remove/status, flock, log
src/AiModelsCommand.php                # attributed dispatcher
bin/aimodels                           # thin entry
tests/LocalModels/*Test.php            # TDD, fake roots injected via env
```
Per repo rules: read `.claude/skills/author-cli-commands/SKILL.md` before writing the
dispatcher; failing tests first; completion updated in the same change.

**P3 — verify against your disk:** `aimodels whisper status`, then `--dry-run` flips both
directions, then real flips with MacWhisper quit. You confirm: full list when pointed at
external, clean degrade to small when pointed at local, diarization still works offline.

**P4 — watcher cutover:** install `com.jt.aimodels-watcher`, then unload + remove
`com.jt.ollamodels-watcher`, then re-assert `launchctl setenv OLLAMA_MODELS`. Verify both
directions on a real eject/mount.

**P5 — shims + rewrite `macwhisper-relocate`** onto the flip; migrate the one existing
per-bundle symlink.

**P6 — skills:** parent skill (shared brain: introspection stance, storage layout,
relocation + watching), ASR picker leaf (introspect WhisperKit/Parakeet/whisper-cpp),
cross-refs from `ollama-model-picker` and `work-with-media:macwhisper-cli`.

## 8. Blast radius

- **Watcher cutover is the sharp edge.** `com.jt.ollamodels-watcher` is loaded and
  load-bearing right now (Ollama is on SD). Install-new-then-remove-old; both are
  idempotent so a brief overlap just runs the Ollama flip twice. Losing the
  `OLLAMA_MODELS` launchd env is the silent failure to watch for — re-assert it after
  cutover and verify with `launchctl getenv OLLAMA_MODELS`.
- **Ollama side is live** — changes go behind the adapter; `reconcile` semantics and the
  `ollama rm`-only-on-local warning carry forward verbatim.
- **MacWhisper side is nearly greenfield** (one relocated bundle) — cheapest moment to
  redesign, but the models root is real user data measured in tens of GB: preflight
  refuses on a real directory and never deletes.
- **Disk cost:** duplicated small + support models across both stores (see §3).
- **PHP path in the plist** — use a stable `php` (not the versioned Cellar path) so a brew
  upgrade doesn't silently break the watcher. Worth checking whether the current watcher
  is already broken by an upgrade since March.
