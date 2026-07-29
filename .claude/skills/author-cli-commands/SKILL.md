---
name: author-cli-commands
description: Build, change, or review PHP CLI commands in this dotfiles repository. Use for work on bin/ entrypoints, command handlers under src/, command arguments or flags, help output, Zsh completion, command dispatch, CLI tests, or migrations away from procedural command files.
---

# Author CLI commands

Keep each command's behavior, help, and completion driven by one attributed
handler contract. Keep `bin/<command>` thin, domain logic testable, and every
interface change covered by tests and runtime-generated completion.

## Start from the reference implementation

Read these files before changing the command framework or authoring a new
multi-command CLI:

- `bin/godo`: thin bootstrap and generic dispatch.
- `src/GodoCommand.php`: attributed CLI handler.
- `src/Godo.php`: domain/store service with no CLI metadata.
- `src/CLI/Attributes/`: program, command, argument, and option attributes.
- `src/CLI/Command/`: reflection registry, dispatcher, definitions, and Zsh
  renderer.
- `tests/Cli/CommandFrameworkTest.php`: framework contract.
- `tests/Godo/GodoCommandTest.php`: handler dispatch and lazy completion
  loading.

Read `.claude/skills/verify/SKILL.md` before live verification.

## Author the command

1. Create or retain a domain service under `src/` for storage, transformations,
   network calls, or other reusable behavior.
2. Create a `<Name>Command` handler under `src/`. Put the `#[Program]` attribute
   on the class, `#[Command]` on public handler methods, and `#[Argument]` or
   `#[Option]` on every handler parameter.
3. Let `JT\CLI\Command\Dispatcher` bind parsed inputs and invoke the reflected
   method directly. Do not rebuild a procedural `switch` in `bin/`.
4. Keep `bin/<command>` to the required header, bootstrap, construction, and
   dispatch:

```php
use JT\CLI\Command\Dispatcher;

$cli = require_once dirname( __DIR__ ) . '/src/bootstrap.php';

exit( ( new Dispatcher(
	$cli,
	new ExampleCommand( $cli )
) )->run() );
```

5. Return integer exit codes from handler methods. Use `0` for success and `1`
   for a normal command or usage failure. Do not call `exit()` inside testable
   classes.
6. Keep handler construction side-effect-free. Lazily construct stores, network
   clients, and other operational dependencies so `help` and `completion zsh`
   cannot create files or contact external systems merely to reflect metadata.

## Define the interface with attributes

Treat attributes as the canonical interface—not decorative documentation.

```php
#[Program(
	name: 'example',
	description: 'Operate on examples.',
)]
final class ExampleCommand {

	#[Command(
		name: 'add',
		description: 'Add an example.',
	)]
	public function add(
		#[Argument(
			description: 'Example name.',
			completionCommand: 'example list --keys',
		)]
		string $name,
		#[Option(
			aliases: [ 'f' ],
			description: 'Replace an existing example.',
		)]
		bool $force = false
	): int {
		// ...
	}
}
```

- Use `#[Command(default: true)]` for the no-subcommand path.
- Set `hidden: true` only for compatibility or machine-facing commands that
  must dispatch but should not appear in help or completion.
- Use `completionCommand` only for read-only, quick, stable value providers.
- Use explicit option names when the PHP parameter name is not the CLI spelling.
- Long options with values use `--name=<value>`. The shared argument parser
  does not generally bind space-separated long-option values.
- Do not duplicate command lists in the bin, help builder, or completion file.

## Keep help and completion synchronized

Attributed commands automatically gain:

```bash
example help
example help <command>
example completion zsh
```

The Help renderer and Zsh renderer must consume the same reflected
`ProgramDefinition` used by dispatch.

Do not check generated command names, descriptions, arguments, or options into
a completion plugin. That creates a second interface definition which can
drift from the attributed handler. Instead, bind a small loader at shell startup
which invokes `<command> completion zsh` only on the first completion request
in that shell, evaluates the generated function, and delegates to it:

```zsh
_<command>_lazy() {
	local generated

	generated="$(command <command> completion zsh 2>/dev/null)" || {
		_message 'unable to generate <command> completion'
		return 1
	}

	eval "$generated" || {
		_message 'unable to load <command> completion'
		return 1
	}

	if (( ! $+functions[_<command>] )); then
		_message '<command> completion did not define _<command>'
		return 1
	fi

	_<command> "$@"
}

compdef _<command>_lazy <command>
```

New dotfiles-owned loaders go in
`zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh`.
Existing command-specific plugins may remain while they are migrated;
`zsh-custom/plugins/godo-completions/godo-completions.plugin.zsh` is the
reference implementation.

Test that sourcing the plugin performs no command invocation, the first loader
call invokes exactly `<command> completion zsh`, successful generation rebinds
`_comps[<command>]` to the generated function, and generation failure returns
nonzero without recursion. Also assert that the checked-in plugin contains no
enumerated command metadata.

Do not add persistent disk caching to this loader unless measured latency shows
the once-per-shell generation is materially slow. The proposed
content-addressed XDG cache, automatic invalidation requirements, and cache
tests are tracked in Beads task `dotfiles-kmc`.

Verify syntax and registration in a clean process:

```bash
zsh -n <completion-plugin>
zsh -fc 'autoload -Uz compinit; compinit -C; source <completion-plugin>; [[ ${_comps[example]} == _example_lazy ]]'
```

## Keep logic and output testable

- Follow TDD for every class behavior change: write the failing test, observe
  the failure, then implement.
- Put real behavior under `src/`; PHPUnit does not target procedural entry
  scripts.
- Inject command runners or stub binaries at shell boundaries. Follow
  `Godo::resolvePath()` and `GODO_DIRMAP_BIN`.
- Use `JT\Tests\TestCase`; keep namespace and directory case aligned for Linux.
- Run focused tests while iterating, then `composer test`.
- Use `$cli->output()` for machine-consumable stdout, `msg()` for optional human
  chatter, and error methods for failures.
- If text, JSON, HTML, or several verbs expose the same records, assemble and
  annotate those records in one shared accessor. Sharing only a renderer does
  not prevent view drift.

## Preserve repository constraints

- Use the `JT` namespace and PSR-4 filenames under `src/`.
- Never hand-`require` a class. Bootstrap registers the autoloader.
- Keep the command portable across macOS and Linux, or explicitly gate
  platform-only behavior.
- Keep public files free of secrets, private hostnames, and machine-specific
  absolute paths.
- Preserve the standard executable PHP header in `bin/<command>`.

## Finish

1. Compare the attributed contract with runtime parsing and every documented
   invocation.
2. Verify `--help`, subcommand help, `completion zsh`, and read-only live paths.
3. Run lazy-loader integration checks, Zsh syntax/registration, focused PHPUnit
   tests, and the full suite.
4. Update this skill when the command framework gains a durable authoring rule;
   keep AGENTS.md to the short routing rule and repository-wide invariants.
