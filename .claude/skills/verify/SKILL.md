---
name: verify
description: How to verify changes to this dotfiles repo's PHP CLI scripts (bin/) end-to-end — drive the real verb against its live target, not just PHPUnit.
---

# Verifying dotfiles CLI changes

- **Test suite**: `composer test` (PHPUnit, config `phpunit.xml.dist`). Pure-logic
  methods get direct unit tests; I/O methods get fixture roots via env override
  (e.g. `GRAVEYARD_ROOT` for `bin/graveyard` — pattern in `tests/Graveyard/`).
- **Acid test**: run the changed verb against the LIVE target (real
  `~/.claude-graveyard`, live cmux) before closing work. Read-only verbs
  (`ls`, `search`, `page`, `candidates`) are safe to run anytime; destructive
  verbs (`bury`, `resurrect`) need JT's explicit go-ahead first.
- **HTML output** (e.g. `graveyard page`): screenshot with
  `agent-browser --allow-file-access open file:///path/to.html` then
  `agent-browser screenshot /tmp/out.png` and Read the png. The
  `--allow-file-access` flag is required for file:// URLs. `agent-browser close`
  when done — the daemon lingers otherwise.
- `bin/graveyard` needs a session transport for every verb except the store-only
  ones (`page`, `serve`): it exits with `No session transport is reachable. Is cmux
  or herdr running?` when NEITHER cmux nor herdr answers. Either one alone is
  enough, and discovery reads both.
