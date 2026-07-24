# gitpub — Design Spec

**Date:** 2026-07-24
**Tool:** `bin/gitpub` (thin entry) + `src/GitPub.php` (class `JT\GitPub`, PHP)
**Status:** Approved (2026-07-24); ready for implementation plan.

## Problem

JT has git repos on other machines (notably a personal-stuff repo on one of his
laptops) with **no remote**. He wants to use one of his own tailnet hosts as the remote —
push/pull over Tailscale/SSH, so the code never touches a third-party host like
GitHub. The plumbing already exists: both machines are on the tailnet, sshd is up
(pubkey-only), git is installed, and MagicDNS resolves the host by name.

Doing it by hand is three commands run in a specific order with a hand-derived
name and a hand-built remote URL:

```bash
ssh <host> 'git init --bare -- git-remotes/<name>.git'
git remote add origin <host>:git-remotes/<name>.git
git push -u origin --all && git push origin --tags
```

`gitpub` wraps exactly that, with sane defaults, safety prompts, and no
per-machine memorization of hostnames or paths. (Name is a deliberate play on
"GitHub" — publishing to your own host instead.)

## Scope (YAGNI)

**Publish the current repo only.** One command, run inside a git working tree:
create the bare repo on the host, wire up `origin`, push all branches + tags.

Explicitly **out of scope** (may come later, not now): `list`, `clone`-from-host,
`rm`, and any multi-subcommand lifecycle management.

## Configuration

Settings are read via `git config` (standard git plumbing). JT stores them under
a `[gitpub]` section in `~/.dotfiles/private/additional_config` — a file that is
**not synced** and is already pulled into git config via a `path =` include in
`.gitconfig`. This keeps the internal hostname out of the **public** dotfiles
repo while making it transparently readable by the tool.

```ini
[gitpub]
	host = my-tailnet-host   # or an SSH alias, or user@host
	user = jtsternberg       # optional; see resolution below
```

- **`gitpub.host`** — target host. Required. If unset **and** no `--host` flag is
  given, the tool errors with a hint showing exactly what to add.
- **`gitpub.user`** — optional. User resolution order: explicit `--user` →
  `user@` embedded in the host value → `gitpub.user` → `$USER`.
- **Base dir is NOT configurable.** All bare repos land in `git-remotes/`
  (relative to the remote user's home). Fixed, hardcoded. `git-remotes` is a
  generic directory name, safe to commit in the public repo.

## Name derivation

Default remote repo name = **lowercased-kebab of the repo root directory name**
(from `git rev-parse --show-toplevel`, not cwd):

- `My Cool Repo`  → `my-cool-repo`
- `Some_Repo.v2`  → `some-repo-v2`
- ` --Weird  Name-- ` → `weird-name`

Algorithm: lowercase; replace every run of non-`[a-z0-9]` characters with a
single `-`; trim leading/trailing `-`. A positional argument overrides:
`gitpub myname` (the given name is used **as-is**, not re-kebabed — JT's explicit
choice wins).

## Remote URL

scp-style, home-relative: `<user>@<host>:git-remotes/<name>.git` (user omitted if
it resolves to the default). Home-relative avoids hardcoding `/home/jtsternberg`
and stays portable across the tailnet. `git init --bare` creates the parent
`git-remotes/` directory automatically, so no separate `mkdir` step is needed.

## Flags

- `[name]` — positional, overrides the derived name (used as-is).
- `--host=<host>` — overrides `gitpub.host`.
- `--user=<user>` — overrides user resolution.
- `--yes` / `-y` — autoconfirm; skips both confirmation prompts.
- `--silent` / `--porcelain` / `-shh` — suppress non-error output (framework
  default behavior).

## Flow

1. **Verify git repo.** `git rev-parse --show-toplevel`; error if not inside a
   working tree. Warn (non-fatal) if the repo has **no commits** — the push will
   be a no-op but wiring up origin is still valid.
2. **Guard existing origin.** If a local `origin` remote already exists, error
   and print its current URL plus a hint (pass a different `[name]`, or
   `git remote remove origin` first). We never silently repoint an existing
   remote.
3. **Resolve + confirm.** Resolve host / user / name, build the remote URL, print
   a one-screen summary, and prompt to confirm. Skipped with `-y`.
4. **Create bare repo.** First check whether the target path already exists on
   the host (`ssh <host> 'test -d git-remotes/<name>.git'`). If it exists, prompt
   to confirm reuse (skipped with `-y`) — this catches accidental attachment to
   an unrelated existing repo. Then
   `ssh <host> 'git init --bare -- git-remotes/<name>.git'` (harmless/idempotent
   if it already existed).
5. **Wire origin.** `git remote add origin <url>`.
6. **Push.** `git push -u origin --all`, then `git push origin --tags`.
7. **Report.** Success message with the remote URL and a clone-elsewhere hint
   (`git clone <url>`).

## Architecture & testability

Thin `bin/gitpub` entry: nothing but `require_once .../src/bootstrap.php`,
instantiate `JT\GitPub`, call `run()`, `exit()` with its code. All real logic
lives in `src/GitPub.php` so PHPUnit can drive it (per the repo's bin+src TDD
convention).

**Pure units, tested directly (no network):**

- `kebabName(string $raw): string` — the derivation above.
- `buildRemoteUrl(string $host, ?string $user, string $name): string`
- config resolution (host/user, with the documented precedence) — reads through
  an injected git-config reader so tests supply values.
- origin-exists check.

**Shelling seams** (`ssh`, `git init/remote/push`) go through an injectable
runner, mirroring the `Godo::resolvePath` / `GODO_DIRMAP_BIN` stub-binary pattern
already in the repo (`tests/Godo/GodoTest.php`). Tests stub the runner so the
suite never opens an SSH connection or mutates a real repo. The thin bin entry
and the live `passthru`/prompt plumbing are the untestable carve-out.

**Cross-platform:** lives in shared dotfiles; must run on both macOS and Linux.
PHP CLI + scp-style home-relative URLs + `$USER` fallback are all portable; no
platform-specific paths are hardcoded.

## Success criteria

- From inside JT's remote-less repo on the Mac: `gitpub` (zero args) creates
  `git-remotes/<kebab-name>.git` on the configured host, adds `origin`, and
  pushes all branches + tags — after one confirmation.
- Re-running against an existing bare repo prompts before reusing it.
- Running with an existing local `origin` errors cleanly without mutating
  anything.
- Missing `gitpub.host` (and no `--host`) errors with an actionable hint.
- Full unit coverage of the pure units and the command sequence via the stubbed
  runner; `composer test` green.
