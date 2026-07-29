---
name: author-cli-commands
description: Build, change, or review PHP CLI commands in this dotfiles repository. Use for work on bin/ entrypoints, command handlers under src/, command arguments or flags, help output, Zsh completion, command dispatch, CLI tests, or migrations away from procedural command files.
---

# Author CLI commands

Keep each command's behavior, help, and completion driven by one attributed
handler contract. Keep `bin/<command>` thin, domain logic testable, and every
interface change covered by tests and regenerated completion.

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
- `tests/Godo/GodoCommandTest.php`: handler dispatch and generated-completion
  parity.

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

Check generated completion into the repository so shell startup never invokes
PHP merely to construct completion metadata. New dotfiles-owned completions go
in:

```text
zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh
```

Existing command-specific plugins may remain while they are migrated. Add an
exact parity test between `completion zsh` and the checked-in completion text;
`tests/Godo/GodoCommandTest.php` is the reference. Generated output carries
`BEGIN/END GENERATED COMPLETION` markers. When several commands share
`dotfiles-completions.plugin.zsh`, extract the marked command block and compare
that exact block instead of testing only that the shared file contains a few
expected strings.

Verify syntax and registration in a clean process:

```bash
zsh -n zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh
zsh -fc 'autoload -Uz compinit; compinit -C; source zsh-custom/plugins/dotfiles-completions/dotfiles-completions.plugin.zsh; [[ ${_comps[example]} == _example ]]'
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
3. Run completion parity, Zsh syntax/registration, focused PHPUnit tests, and
   the full suite.
4. Update this skill when the command framework gains a durable authoring rule;
   keep AGENTS.md to the short routing rule and repository-wide invariants.
