# Agent Instructions

This file provides guidance to Claude Code (claude.ai/code) and other AI agents when working with code in this repository.

## Repository Overview

This is a personal dotfiles repository that provides shell configuration, CLI utilities, and development tools. Files are symlinked to `$HOME` using `symdotfiles`.

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

## Shell Configuration

- `.zshrc` - Main shell config, loads Oh My Zsh with custom plugins
- `.zshenv` - Environment variables and PATH
- `.gitconfig` - Git aliases and configuration
- `.git-functions` - Shell functions for git operations

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

