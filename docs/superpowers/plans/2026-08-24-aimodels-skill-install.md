# Installing the aimodels skills + cross-refs

Both skills live in this repo at `agent-skills/<name>/SKILL.md`, which is the home
for user-level agent skills — see the "Agent Skills and Slash Commands" section of
CLAUDE.md.

## 1. Install the two skills

```bash
php symdotfiles
```

That is the whole install. `symdotfiles` symlinks every `agent-skills/<name>/`
directory containing a `SKILL.md` into `~/.claude/skills/<name>`, so both skills
become available in every project:

```
~/.claude/skills/local-model-stores -> ~/.dotfiles/agent-skills/local-model-stores
~/.claude/skills/asr-model-picker   -> ~/.dotfiles/agent-skills/asr-model-picker
```

It is idempotent and scoped: anything already linked reports "exists, skipping",
so running it creates only what is missing. Use `php symdotfiles testrun` to see
exactly what it would touch first, and `hard` only when deliberately overwriting.

Everything below this point touches **other** repos — `~/.claude/skills` is a
symlink into `~/Library/CloudStorage/Dropbox/Prefs/Claude/skills` (its own git
repo, with its own Claude workspace), and `work-with-media` lives in
`~/Code/claude-plugins`. Those are hand-offs, not steps to run here.

## 2. Cross-ref patch: `ollama-model-picker`

**File:** `~/.claude/skills/ollama-model-picker/SKILL.md`

**2a.** Insert after the `## Prime directive: derive, don't guess` section (before
`## Step 1 — Empirical inventory`):

```markdown
## Not this skill

- **Transcription / ASR models** (WhisperKit, Parakeet, whisper-cpp under
  MacWhisper) → `asr-model-picker`.
- **Where models live** — local disk vs the AI-LAB drive, what survives an eject,
  safe drive removal → `local-model-stores`. Check availability there FIRST: a
  model sitting on an ejected drive is not a candidate however fast it is.
```

**2b.** Replace the body of `### 1d. ollamodels — storage location` with:

```markdown
### 1d. `aimodels` — storage location across BOTH stores

```bash
aimodels status       # every engine + model, per store (L = local, X = AI-LAB)
aimodels where        # terse: active store per engine, is AI-LAB mounted
```

Models on the SD drive are unavailable when it is unmounted and cold-load slower
than local-disk models. Unlike `ollama ps` / `/api/tags`, which only ever describe
the store Ollama is pointed at right now, `aimodels status` reads both stores off
disk — so it can tell you what is on a drive that is currently unplugged.

`ollamodels local|sd|auto|reconcile` still flips the Ollama store. It no longer
manages the drive watcher: one LaunchAgent (`aimodels watch`) now follows AI-LAB
for every engine. Remove the drive with `aimodels eject`, never Finder — see
`local-model-stores`.
```

## 3. Cross-ref patch: `macwhisper-cli`

**File:** `~/Code/claude-plugins/plugins/work-with-media/skills/macwhisper-cli/SKILL.md`

Insert near the top, after the overview:

```markdown
## Choosing the model, and whether it is even available

- **Which model for this task** → `asr-model-picker` (derives from `mw models
  list` + `aimodels status` rather than guessing).
- **Where models live / offline availability / safe drive removal** →
  `local-model-stores`.

Two facts that bite here: `mw models list` describes only the *currently active*
store, so it cannot answer "will this work with AI-LAB unplugged" — `aimodels
status` can. And MacWhisper will not list a model whose bundle directory is a
symlink, so never relocate an individual bundle; storage moves are whole-store
flips.
```

## 4. Verify after installing

```bash
# skills resolve into this repo
ls -l ~/.claude/skills/local-model-stores ~/.claude/skills/asr-model-picker
head -3 ~/.claude/skills/asr-model-picker/SKILL.md

# the facts the skills assert
aimodels status
mw models list
```
