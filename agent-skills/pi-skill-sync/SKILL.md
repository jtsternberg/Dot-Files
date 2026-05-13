---
name: pi-skill-sync
description: Registers a Claude Code skill (user or plugin-bundled) into Pi's settings.json `skills` array so Pi can discover it. Use when the user mentions a Claude skill missing in Pi, wants to transfer/sync/copy/share/make-available a Claude skill to Pi, asks how to expose a Claude plugin skill to Pi, or references the `pi-skill-sync` CLI.
---

# pi-skill-sync

Pi natively reads Claude-format skills by reference — you just add the skill's path to Pi's `skills` array. No copying. See https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/skills.md ("Using Skills from Other Harnesses") for the underlying mechanism.

## Workflow

Two-step pattern (run `pi-skill-sync --help` if usage looks off):

1. **Discover**: `pi-skill-sync <search-term>` — fuzzy/substring search across `~/.claude/skills`, `~/.claude/plugins/**/skills`, and `~/.codex/skills`. Prints matches with their absolute paths; exits without writing.
2. **Register**: `pi-skill-sync <absolute-path>` — copy a path from step 1 and re-run. Shows a preview, prompts, writes to `~/.pi/settings.json`.

## Key flags

- `--project` — write `./.pi/settings.json` instead of `~/.pi/settings.json`.
- `--parent` — register the parent `skills/` dir (pulls in siblings) instead of a single skill dir.
- `--desc` — also match against SKILL.md descriptions during search.
- `--dry-run` — preview the proposed JSON; don't write.
- `-y` / `--yes` — autoconfirm.

Idempotent: re-running on an already-registered path prints "already present" and exits 0. Symlink-equivalent paths dedupe via realpath.
