# Kimi (Moonshot AI) Coding Helpers

Shell functions in `.misc-functions` that run existing coding-agent harnesses
(Claude Code, Pi, Aider) against Moonshot AI's Kimi models instead of their
default backends. Added in commits `1ad9043` (`yolo-kimi`) and `69391b8`
(`kimi-pi`, `kimi-aider`).

Why: Kimi is a cheap, capable coding model — useful as an alternate backend
for personal projects where Anthropic/OpenAI spend isn't justified.

## ⚠️ Data boundary

Prompts go to **Moonshot AI (China)**. Personal/public work ONLY — no work
code, secrets, or customer data. Every helper prints a yellow banner on
invocation so this is impossible to forget. Keep that banner in any future
helper that sends prompts outside trusted providers.

## Setup

1. **API key** — get one at <https://platform.moonshot.ai>, then:
   ```sh
   # private/additonal_aliases.sh (sourced by .zshrc, NOT synced to git)
   export MOONSHOT_API_KEY="sk-..."
   ```
   (Yes, the filename is really misspelled `additonal`.)
2. **`kimi` CLI** — installed to `~/.kimi-code/bin`, added to PATH in `.zshrc`.
3. **`kimi-pi` only** — requires the `moonshot` provider in
   `~/.pi/agent/models.json` (user-level config, NOT managed by this repo —
   fresh machines need this added manually):
   ```json
   {
     "providers": {
       "moonshot": {
         "api": "openai-completions",
         "apiKey": "$MOONSHOT_API_KEY",
         "baseUrl": "https://api.moonshot.ai/v1",
         "models": [
           { "id": "kimi-k3", "contextWindow": 1000000, "input": ["text", "image"], "reasoning": true },
           { "id": "kimi-k2.7-code", "contextWindow": 262144, "input": ["text"] }
         ]
       }
     }
   }
   ```

## The helpers

| Function    | Harness     | Endpoint style       | Runs                                                            |
| ----------- | ----------- | -------------------- | --------------------------------------------------------------- |
| `yolo-kimi` | Claude Code | Anthropic-compatible | `claude --dangerously-skip-permissions "$@"`                    |
| `kimi-pi`   | Pi          | `--provider` flag    | `pi --provider moonshot --model "${KIMI_MODEL:-kimi-k3}" "$@"`  |
| `kimi-aider`| Aider       | OpenAI-compatible    | `aider --model "openai/${KIMI_MODEL:-kimi-k3}" --yes-always "$@"` |

### `yolo-kimi`

Claude Code supports any Anthropic-compatible endpoint via env vars:

```sh
ANTHROPIC_BASE_URL="https://api.moonshot.ai/anthropic" \
ANTHROPIC_AUTH_TOKEN="$MOONSHOT_API_KEY" \
ANTHROPIC_MODEL="${KIMI_MODEL:-kimi-k3}" \
ANTHROPIC_SMALL_FAST_MODEL="${KIMI_SMALL_MODEL:-kimi-k2.7-code}" \
claude --dangerously-skip-permissions "$@"
```

`ANTHROPIC_SMALL_FAST_MODEL` is Claude Code's background-task slot (session
titles, compaction, etc.) — routed to the cheaper/faster K2.7 Code model.
Named after the existing `yolo` convention: same harness + skip-permissions,
different backend.

### `kimi-aider`

Aider (via litellm) speaks OpenAI-compatible APIs:

```sh
OPENAI_API_BASE="https://api.moonshot.ai/v1" \
OPENAI_API_KEY="$MOONSHOT_API_KEY" \
aider --model "openai/${KIMI_MODEL:-kimi-k3}" --yes-always "$@"
```

The `openai/` model prefix tells litellm to use the OpenAI-compatible path
with `OPENAI_API_BASE` rather than a native provider integration.

## Models & overrides

Defaults are overridable via env vars — no need to edit the functions to try
a new model:

```sh
KIMI_MODEL=kimi-k2.7-code-highspeed yolo-kimi
```

| Env var           | Default         | Used by                        |
| ----------------- | --------------- | ------------------------------ |
| `KIMI_MODEL`      | `kimi-k3`       | all three helpers (main model) |
| `KIMI_SMALL_MODEL`| `kimi-k2.7-code`| `yolo-kimi` (background slot)  |

- `kimi-k2.7-code-highspeed` — sameish model, faster (~180–260 tok/s output),
  separately priced, pricier.
- Models list: <https://platform.kimi.ai/docs/models>
- K2.7 Code quickstart: <https://platform.kimi.ai/docs/guide/kimi-k2-7-code-quickstart>

Naming: **Moonshot AI** is the company — API endpoints and keys live at
`api.moonshot.ai` / `platform.moonshot.ai`. **Kimi** is the product brand —
docs live at `platform.kimi.ai`. Same company, two domains; don't let the
mismatch fool you.

## Adding helpers for other models (the pattern)

Every helper is the same five lines of anatomy:

```sh
# <Tool> powered by <Provider> (<endpoint style>).
<helper-name>() {
	if [[ -z "$PROVIDER_API_KEY" ]]; then
		echo "PROVIDER_API_KEY not set — get a key at <url> and export it in private/additonal_aliases.sh" >&2
		return 1
	fi
	echo "\033[1;33m⚠️  <PROVIDER> MODE — prompts go to <who/where>. Personal/public work ONLY.\033[0m"
	BASE_URL_ENV="..." \
	KEY_ENV="$PROVIDER_API_KEY" \
	some-harness --model "${PROVIDER_MODEL:-default-model}" --auto-accept-flag "$@"
}
```

Tips for future implementations:

1. **Find the harness's endpoint override first.** Most coding agents support
   one of three styles:
   - *Anthropic-compatible env vars* (Claude Code): `ANTHROPIC_BASE_URL`,
     `ANTHROPIC_AUTH_TOKEN`, `ANTHROPIC_MODEL`, `ANTHROPIC_SMALL_FAST_MODEL`
   - *OpenAI-compatible env vars* (Aider/litellm, many others):
     `OPENAI_API_BASE` / `OPENAI_BASE_URL`, `OPENAI_API_KEY`, and an
     `openai/`-prefixed model name
   - *Native provider config + flags* (Pi): define the provider in the tool's
     config file, select it with `--provider`/`--model`
   Check the tool's docs/changelog, not just `--help` — these overrides are
   often undocumented in help output.
2. **Scope overrides with env-prefix assignments** (`VAR=x cmd`), never
   `export` inside the function. The override applies to that one invocation
   only, so your default (Anthropic/OpenAI) setup is never polluted.
3. **Guard the key.** Fail fast: message to stderr + `return 1`, telling the
   user exactly where to set it. Keys live in `private/additonal_aliases.sh`
   (git-ignored, unsynced) — never in the function itself.
4. **Make the model an overridable default**: `${<PROVIDER>_MODEL:-default}`.
   Trying a new model should be an env var, not an edit.
5. **Keep the data-boundary warning** whenever prompts leave a trusted
   provider. Yellow banner, every invocation, no exceptions.
6. **Use the small-model slot when the harness has one.** Route background
   tasks to a cheaper/faster variant (`${<PROVIDER>_SMALL_MODEL:-...}`).
7. **Follow the naming conventions**:
   - `yolo-<backend>` = Claude Code harness with `--dangerously-skip-permissions`
   - `<backend>-<tool>` = any other harness (`kimi-pi`, `kimi-aider`)
8. **Always pass `"$@"`** so the helper behaves exactly like the wrapped tool.
9. **Include the harness's auto-accept flag** (`--dangerously-skip-permissions`,
   `--yes-always`, …). These helpers exist for fast, unattended personal
   iteration — say so in the comment above the function.
10. **Stay cross-platform.** `.misc-functions` is shared macOS/Linux: env vars
    and `$HOME`-relative paths only, no `/Users/JT/` hardcodes. Platform-only
    PATH additions belong in `.macoszshrc` / `.linuxzshrc`.
