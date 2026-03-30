# Agent Instructions

This file provides guidance to LLM Agents like Claude Code (claude.ai/code) and other AI agents when working with code in this repository.

## Repository Overview

This is a personal dotfiles repository that provides shell configuration, CLI utilities, and development tools. Files are symlinked to `$HOME` using `symdotfiles`.

**This repo is used on both macOS and Linux/Ubuntu.** Any change to shared config files (`.zshrc`, `.gitconfig`, paths, aliases, etc.) must work on both platforms — or be gated behind a platform check. Never hardcode macOS-only paths (e.g. `/Users/JT/`, `/opt/homebrew/`) or Linux-only paths in shared files.

## Key Directories

- `bin/` - CLI scripts (mostly PHP, some shell scripts)
- `misc/` - PHP helper libraries for CLI scripts
- `zsh-custom/` - Oh My Zsh custom plugins and themes
- `private/` - Private configuration (not synced)
- `claude/` - Claude Code hooks and configuration

## CLI Script Development

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

### CLI Helpers Setup

```php
$cli = require_once dirname(__DIR__) . '/misc/helpers.php';
$helpyHelperton = $cli->getHelp();
```

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

**Git operations:** Available via `$cli->git` (see `misc/helpers/git.php`)

### Help Documentation Patterns

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

## Content Conversion Tools

- `html-to-markdown <file.html>` - Convert HTML to Markdown (outputs to stdout)
- `marked <file.md>` - Convert Markdown to HTML (outputs to stdout)

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
bd sync               # Sync with git
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
   bd sync
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


<!-- BEGIN BEADS INTEGRATION v:1 profile:full hash:d4f96305 -->
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

<!-- END BEADS INTEGRATION -->
