# Agent Instructions

This file provides guidance to LLM Agents like Claude Code (claude.ai/code) and other AI agents when working with code in this repository.

## Repository Overview

This is a personal dotfiles repository that provides shell configuration, CLI utilities, and development tools. Files are symlinked to `$HOME` using `symdotfiles`.

**⚠️ This repo is PUBLIC on GitHub.** Everything committed here is world-readable. Never commit secrets, tokens, API keys, private keys, internal hostnames/URLs, or anything sensitive — that material belongs in `private/` (not synced) or outside the repo entirely. Also think twice before committing detailed security-posture notes (threat-model reasoning, key locations, auth workarounds): even without secrets, they can be useful to someone casing the owner. When in doubt, put it in `private/` and reference it from the public file.

**This repo is used on both macOS and Linux/Ubuntu.** Any change to shared config files (`.zshrc`, `.gitconfig`, paths, aliases, etc.) must work on both platforms — or be gated behind a platform check. Never hardcode macOS-only paths (e.g. `/Users/JT/`, `/opt/homebrew/`) or Linux-only paths in shared files.

## Key Directories

- `bin/` - CLI scripts (mostly PHP, some shell scripts)
- `misc/` - PHP helper libraries for CLI scripts
- `zsh-custom/` - Oh My Zsh custom plugins and themes
- `private/` - Private configuration (not synced)
- `agent-skills/` - Agent skills and slash commands, installed into `~/.claude/` by `symdotfiles`
- `claude/` - Claude Code hooks and configuration

## CLI Script Development

**For any work on a PHP CLI command, first read and follow
`.claude/skills/author-cli-commands/SKILL.md`.** That skill is the canonical
detailed authoring workflow for attributed handlers, direct reflection-based
dispatch, testing, help, and completion. Put new command-authoring guidance
there instead of expanding this file; keep this section to repository-wide
invariants.

### Creating New CLI Scripts

New CLI scripts should be placed in `bin/` and written in PHP with the `JT` namespace.

Required header format:
```php
#!/usr/bin/env php
<?php
namespace JT;
# =============================================================================
# {{description}}
# By Justin Sternberg <me@jtsternberg.com>
#
# Version {{version}}
#
# {{detailed_description}}
#
# Usage:
# {{command}} {{usage_example}}
# =============================================================================
```

### Legacy CLI Helpers Setup

```php
$cli = require_once dirname(__DIR__) . '/src/bootstrap.php';
$helpyHelperton = $cli->getHelp();
```

New and migrated commands use the attributed handler/dispatcher pattern from
the command-authoring skill instead of constructing help procedurally.

### Zsh Autocomplete

**Every new CLI command, and every CLI whose subcommands, flags, or positional
arguments change, must ship with a matching Zsh completion update in the same
change.** Completion is part of the command's public interface, not deferred
follow-up work.

Keep dotfiles-owned command completions together in
`zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh`.
It is loaded by the `.zshrc` `plugins` array. Attributed commands generate
their completion with `<command> completion zsh`; check in only a lazy loader
which invokes that generator on the first completion request in each shell.
Never duplicate attributed command names or documentation in the plugin.
Existing command-specific plugins remain valid while they are migrated, but do
not add new ones. The exact loader and test contract lives in the
command-authoring skill.

The generated completion must reflect `--help` and every supported
command/subcommand, flag, and argument shape. Update it alongside the CLI, then
verify the lazy loader and generated function in a clean Zsh process by loading
`compinit` and the plugin.

```zsh
zsh -fc 'autoload -Uz compinit; compinit -C; source zsh-custom/plugins/godo-completions/godo-completions.plugin.zsh; [[ ${_comps[godo]} == _godo_lazy ]]'
```

### Skills And Docs Drift With The Command

**Any change to a command's behavior, flags, subcommands, arguments, or output
must also update every skill or doc that teaches that command, in the same
change.** A skill in `agent-skills/` or `.claude/skills/` that documents how to
drive a command is part of that command's public interface, exactly like its
`--help` and its Zsh completion — an agent reads the skill to decide how to use
the command, so a skill describing behavior the command no longer has silently
sends the next agent down the wrong path. This applies to slash commands and
any other tool a skill documents, not just PHP CLIs. Before calling the change
done, grep `agent-skills/` and `.claude/skills/` for the command name and
reconcile what you find. This is the same invariant as the completion rule
above: coupled interface surfaces move together or not at all.

### Available `$cli` Methods

**Arguments/Flags:**
- `getArg($index)`, `hasArg($arg)` - Positional arguments
- `getFlag($name)`, `hasFlag($name)`, `hasFlags($flags, $shortFlags)` - `--flag=value` style
- `hasShortFlag($flag)` - `-f` style flags
- `isSilent()` - Check `--silent`/`--porcelain`/`-shh`
- `isVerbose()` - Check `--verbose`/`-v`
- `isAutoconfirm()` - Check `--yes`/`-y`

**User Interaction:**
- `ask($question, $emptyError)` - Prompt with optional required response
- `confirm($question)` - Yes/no confirmation (respects `--yes`)
- `requestAnswer($question)`, `requestYesAnswer()`, `requestNoAnswer()`
- `isYes($answer)`, `isNo($answer)`

**Output:**
- `msg($text, $color, $lineBreak)` - Colored output (suppressed in silent mode)
- `err($text)`, `exitErr($text, $code)` - Error output
- `successMsg($text)` - Green success message
- `color($name)` - Get color code (red, green, yellow, blue, magenta, cyan, white)

**Files:**
- `convertPathToAbsolute($path)` - Resolve relative/tilde paths
- `writeToFile($file, $contents, $args)` - Write with options
- `getDirFiles($dir, $type, $sort)` - Get filtered directory contents
- `filteredFileContentRows($file, $callback)` - Process file line by line

**Commands:**
- `runCommand($cmd)` - Execute shell command
- `runCommandWithExitCode($cmd)` - Execute and return exit code
- `getCommandOutputAndExitCode($cmd)` - Get stdout, stderr, and exit code

**Git operations:** Available via `$cli->git` (see `src/CLI/Helpers/Git.php`)

### Legacy Help Documentation Patterns

These builders remain compatible for existing procedural commands. New and
migrated commands derive help from PHP attributes through the generic
dispatcher.

**Single command:**
```php
$helpyHelperton
    ->setScriptName('example')
    ->setDescription('Description')
    ->setSampleUsage('<arg1> [--flag]')
    ->buildDocs(['<arg1>' => 'Explanation']);
```

**With subcommands:** Use `->setup()` method (see `.cursorrules` for full examples)

### Style Guidelines

- Tabs for indentation (size: 3)
- PSR-12 naming conventions
- Exit 0 for success, 1 for error
- Colors: red=errors, green=success, yellow=info/warning

### Multiple Views of One Dataset Stay in Lock-Step

**When several verbs present the same records — `graveyard ls` / `search` / `page`,
or any future equivalent — they must read ONE shared accessor that has already
assembled and annotated the data. Adding a field to what a record shows means
adding it in that one place, so every view gains it at once.**

A shared *renderer* is not enough and is the trap that has caught this repo. `ls`
and `search` both rendered through `lsEntryLines()`/`printLsEntry()` and still
diverged, because each read the index itself and only `ls` annotated the new
`live` flag on the way through — so the `↑` marker appeared in `ls` and silently
not in `search`. The renderer was DRY; the input wasn't. Share the source, not
just the formatting.

Practically:

- Read through the annotated accessor (`Graveyard::tombstones()`), never
  `readIndex()['tombstones']` directly, in any user-facing view.
- Memoise anything expensive in that accessor. Liveness shells out to cmux, `lsof`
  and `ps`, and one `search` can render many rows.
- Pin it with a test that asserts the views' sources agree, not merely that each
  one works alone. `tests/Graveyard/GraveyardLaunchSafetyTest.php` has the
  reference case.
- Structured output (`--json`) counts as a view. A field visible in the text output
  and missing from the JSON is the same bug.

### If Two Mechanisms Sync One Tree, They Share One Exclusion List

**When more than one mechanism moves the same directory tree — git and an rsync,
a watcher's ignore pattern and the copy it triggers, a plugin's ignore list and a
script's — every one of them reads the SAME exclusion list. Not equivalent lists;
one list, in one place, that each mechanism loads.**

Divergent lists do not degrade gracefully; they manufacture a permanent
divergence. A file excluded from git but not from the copy can never be
reconciled between two machines, yet keeps being presented to the copy as
"present here, absent there" — so whatever the copy does to resolve that
difference, it does forever, on every run. The same file. The same wrong
resolution. Nothing in either mechanism's own logs looks abnormal, because each
is behaving exactly as configured.

The trap is that the lists start out matching. They are written minutes apart by
someone holding both in their head, and they drift later — one gains an entry for
a new tool's cache, the other doesn't. So:

- Keep the patterns in one file that every mechanism reads. A shared constant, a
  generated `--exclude-from` file, a single ignore file both tools honour.
- Count the watcher's own ignore pattern as one of the lists. A tree filtered
  differently for *deciding to act* than for *acting* is the same defect.
- Pin it with a test that asserts the lists are the same object or derived from
  it — not one test per mechanism confirming each works alone.
- A high-churn directory that is never anyone's content (`.beads`, `.git`, a
  database or index a tool maintains) belongs in the shared list explicitly. Live
  databases in particular must never be copied by a file-level tool: a store
  copied mid-write is a corrupt store.

### Testing

**Follow TDD.** For any behavior change to a class under `src/` — new method,
bugfix, changed contract — write the failing test first, watch it fail, then
make it pass. Every logic change lands with the test that pins it in the same
commit; a `fix(...)`/`feat(...)` commit that touches logic with no test change
is a red flag, not a shortcut. The one carve-out is the thin `bin/<tool>` entry
seam — `passthru`, editor launches, `exit()`/prompt plumbing — which isn't
unit-testable. That carve-out is *exactly why* real logic belongs in the `src/`
class (see below), where it can be driven by tests: if you catch yourself
wanting to skip a test because "it's in the bin script", the logic is in the
wrong place — move it to the class and test it there. Shelling seams (e.g. a
class method that calls out to another bin) are testable via an injected stub
binary — see `Godo::resolvePath` and its `GODO_DIRMAP_BIN` hook in
`tests/Godo/GodoTest.php`.

Tests use PHPUnit (`composer require --dev phpunit/phpunit`). Run the suite with:

```bash
composer test
```

Test files live in `tests/`, are named `*Test.php`, and extend `JT\Tests\TestCase`
(base class in `tests/TestCase.php`, which hands each test a fresh `$this->cli`,
`$this->cmux`, and `$this->gy`). Config is `phpunit.xml.dist`; bootstrap is
`tests/bootstrap.php`. Test classes autoload via the `JT\Tests\` PSR-4 map
(`autoload-dev` in `composer.json`), so the directory case must match the
namespace (`tests/Graveyard/` for `JT\Tests\Graveyard`) — this matters on Linux.

**Write CLI scripts to be testable.** PHPUnit tests classes and methods, not
procedural entry scripts. So keep a `bin/<tool>` entry script thin and put its
real logic in a companion class **under `src/`**, PSR-4-autoloaded via the
`JT\` → `src/` map in `composer.json`. Name the file to match the class so
PSR-4 resolves it: `JT\Godo` → `src/Godo.php`, `JT\Helpers\Cmux` →
`src/Helpers/Cmux.php`. The bin entry then needs nothing but
`require_once '.../src/bootstrap.php'` (which registers the autoloader) — no
per-file `require` — and tests target the class directly with zero setup.
`bin/godo` + `src/Godo.php`, `bin/linux-catchup` + `src/LinuxCatchup.php`, and
`src/Helpers/Cmux.php` (covered by `tests/Godo/`, `tests/LinuxCatchup/`,
`tests/Helpers/`) are the reference pattern. New scripts are born this way.

Directory/file case must match the namespace (`tests/Graveyard/` for
`JT\Tests\Graveyard`; `src/Godo.php` for `JT\Godo`) — this matters on Linux.

No lib lives in `bin/` any more. The last two holdouts (`graveyard_lib.php` →
`src/Graveyard.php`, `cmux-bak_lib.php` → `src/CmuxBak.php`) moved in Task 4 of
the src/ migration, so **no PHP class is hand-`require`d anywhere**:
`tests/bootstrap.php` is one line (composer's autoloader) and every bin entry
just does `require_once '.../src/bootstrap.php'`. If you find yourself adding a
`require_once` for a class, the file is in the wrong place — put it in `src/`
named to match its namespace.

> **Beads: `dotfiles-206`** — "Execute src/ PSR-4 migration plan". Tasks 1-4
> done; Plan: `docs/superpowers/plans/2026-07-17-src-psr4-migration.md`.

## Content Conversion Tools

- `html-to-markdown <file.html>` - Convert HTML to Markdown (outputs to stdout)
- `marked <file.md>` - Convert Markdown to HTML (outputs to stdout)
- `md-atx <path> [--write]` - Rewrite setext headings (underlined with `=`/`-`) as
  ATX (`#`/`##`) in a file or directory tree. Dry run by default.

## Shell Configuration

- `.zshrc` - Main shell config, loads Oh My Zsh with custom plugins
- `.zshenv` - Environment variables and PATH
- `.gitconfig` - Git aliases and configuration
- `.git-functions` - Shell functions for git operations

### Platform-Specific Git Config

`.gitconfig` includes `~/.gitconfig-local` unconditionally. `symdotfiles` automatically symlinks it to the right platform file based on OS:

- `.gitconfig-macos` → `~/.gitconfig-local` on macOS (Kaleidoscope, osxkeychain, macOS safe dirs)
- `.gitconfig-linux` → `~/.gitconfig-local` on Linux (credential helper, Linux-specific settings)

**Rule:** macOS-only git settings (Kaleidoscope, osxkeychain, `/private/` safe dirs) belong in `.gitconfig-macos`. Linux-only settings belong in `.gitconfig-linux`. Shared settings go in `.gitconfig`.

### Platform-Specific ZSH Config

`.zshrc` detects the OS and sources a platform-specific file before loading Oh My Zsh:

```zsh
if [[ "$OSTYPE" == "darwin"* ]]; then
  source ~/.dotfiles/.macoszshrc   # macOS-only config
else
  source ~/.dotfiles/.linuxzshrc   # Linux/Ubuntu-only config
fi
```

- `.macoszshrc` - macOS-specific aliases, paths (Homebrew, `/Users/JT/`), and tools
- `.linuxzshrc` - Linux/Ubuntu-specific aliases, paths, and tools

**Rule:** macOS-only tools (e.g. `pbcopy`, `open`, `say`, `osxkeychain`, Homebrew paths) belong in `.macoszshrc`. Linux-only tools belong in `.linuxzshrc`. Shared config goes in `.zshrc`.

## Agent Skills and Slash Commands

**Skills and commands for JT's agents live in `agent-skills/<name>/`.** `php
symdotfiles` installs them; nothing else in this repo is wired to `~/.claude`, so
a skill written anywhere else is inert no matter how correct its contents.

- `agent-skills/<name>/SKILL.md` — the whole directory is symlinked to
  `~/.claude/skills/<name>`, making it a **user-level skill** available in every
  project. `graveyard`, `system-journal`, `pi-skill-sync` are the reference.
- `agent-skills/<name>/*.md` with no `SKILL.md` — each markdown file is symlinked
  into `~/.claude/commands/`, making it a **slash command**. `system-watchdog` is
  the reference. One directory can hold both kinds.

**Repo-scoped guidance goes in `.claude/skills/<name>/SKILL.md` instead** — Claude
Code reads that directly, no symlink, and it loads only while working in this
repository. `author-cli-commands` and `verify` are the reference, and a
subdirectory may carry its own (`local-llm/.claude/skills/`).

Pick by scope: available everywhere → `agent-skills/`; only meaningful inside this
repo → `.claude/skills/`. Read the `agent-skills` block in `symdotfiles` before
inventing a third location.

## Symlink Management

Run `php symdotfiles` from this directory to create symlinks in `$HOME`. Options:
- `testrun` - Preview without creating symlinks
- `hard` - Overwrite existing files
- `--dirsonly` - Only symlink directories


## Issue Tracking & Task Management

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

## Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --status in_progress  # Claim work
bd close <id>         # Complete work
bd dolt push          # Sync issues to the Dolt remote (bd sync is deprecated)
```

## Landing the Plane (Session Completion)

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd dolt push
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds


<!-- BEGIN BEADS INTEGRATION v:1 profile:full hash:f65d5d33 -->
## Issue Tracking with bd (beads)

**IMPORTANT**: This project uses **bd (beads)** for ALL issue tracking. Do NOT use markdown TODOs, task lists, or other tracking methods.

### Why bd?

- Dependency-aware: Track blockers and relationships between issues
- Git-friendly: Dolt-powered version control with native sync
- Agent-optimized: JSON output, ready work detection, discovered-from links
- Prevents duplicate tracking systems and confusion

### Quick Start

**Check for ready work:**

```bash
bd ready --json
```

**Create new issues:**

```bash
bd create "Issue title" --description="Detailed context" -t bug|feature|task -p 0-4 --json
bd create "Issue title" --description="What this issue is about" -p 1 --deps discovered-from:bd-123 --json
```

**Claim and update:**

```bash
bd update <id> --claim --json
bd update bd-42 --priority 1 --json
```

**Complete work:**

```bash
bd close bd-42 --reason "Completed" --json
```

### Issue Types

- `bug` - Something broken
- `feature` - New functionality
- `task` - Work item (tests, docs, refactoring)
- `epic` - Large feature with subtasks
- `chore` - Maintenance (dependencies, tooling)

### Priorities

- `0` - Critical (security, data loss, broken builds)
- `1` - High (major features, important bugs)
- `2` - Medium (default, nice-to-have)
- `3` - Low (polish, optimization)
- `4` - Backlog (future ideas)

### Workflow for AI Agents

1. **Check ready work**: `bd ready` shows unblocked issues
2. **Claim your task atomically**: `bd update <id> --claim`
3. **Work on it**: Implement, test, document
4. **Discover new work?** Create linked issue:
   - `bd create "Found bug" --description="Details about what was found" -p 1 --deps discovered-from:<parent-id>`
5. **Complete**: `bd close <id> --reason "Done"`

### Quality
- Use `--acceptance` and `--design` fields when creating issues
- Use `--validate` to check description completeness

### Lifecycle
- `bd defer <id>` / `bd supersede <id>` for issue management
- `bd stale` / `bd orphans` / `bd lint` for hygiene
- `bd human <id>` to flag for human decisions
- `bd formula list` / `bd mol pour <name>` for structured workflows

### Auto-Sync

bd automatically syncs via Dolt:

- Each write auto-commits to Dolt history
- Use `bd dolt push`/`bd dolt pull` for remote sync
- No manual export/import needed!

### Important Rules

- ✅ Use bd for ALL task tracking
- ✅ Always use `--json` flag for programmatic use
- ✅ Link discovered work with `discovered-from` dependencies
- ✅ Check `bd ready` before asking "what should I work on?"
- ❌ Do NOT create markdown TODO lists
- ❌ Do NOT use external issue trackers
- ❌ Do NOT duplicate tracking systems

For more details, see README.md and docs/QUICKSTART.md.

## Session Completion

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd dolt push
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds

<!-- END BEADS INTEGRATION -->
