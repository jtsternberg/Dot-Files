# src/ PSR-4 Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Consolidate all repo PHP classes into `src/` under a single PSR-4 `JT\` root with one runtime bootstrap idiom, replacing the ad-hoc requires (`misc/helpers.php`, `misc/helpers/*`, `misc/RepoConfigTrait.php`, `bin/*_lib.php`).

**Architecture:** A hand-rolled PSR-4 autoloader in `src/bootstrap.php` (maps `JT\` → `src/`, no composer required) keeps every `bin/` script working on machines without `composer install`; it also loads `vendor/autoload.php` opportunistically for the scripts that use vendor packages. Composer's own autoloader gets the same `JT\` → `src/` root for tests and the composer-pattern scripts. **Zero class or namespace renames** — this is pure location normalization; the existing PHPUnit suite (95 tests as of execution start) is the regression net.

**Tech Stack:** PHP 8 (CLI), Composer PSR-4, PHPUnit 11, zsh.

## Global Constraints

- **No class or namespace renames.** One class per file after the move (the `JT\CLI\Exception` class splits out of `helpers.php`).
- `bin/` scripts must run without `composer install`. The only runtime loader they may rely on is `src/bootstrap.php`.
- Do not reformat moved code — `git mv` and leave contents untouched except where a step explicitly says otherwise.
- Test baseline: `composer test` currently has **7 pre-existing failures, all in `JT\Tests\Graveyard\GraveyardPageTest`** — the parallel session's v3 TDD RED (2 undefined-method errors + 5 assertion failures; it turns green when their GREEN phase lands). Every task's expected result is "green **except those 7**" (i.e. 88 green). Do not fix or touch them.
- A parallel session is actively modifying `bin/graveyard`, `bin/graveyard_lib.php`, `tests/Graveyard/*`. **No task before Task 4 may modify `bin/graveyard`** — Task 3 leaves transitional shims (`misc/helpers.php`, `misc/helpers/cmux.php`) so the untouched `bin/graveyard` keeps running; Task 4 removes the shims and rewires `bin/graveyard`. **Task 4 has a coordination gate** — do not start it while those paths are dirty.
- Commit directly to `master` (repo convention), one commit per task.
- `symdotfiles` symlinks every top-level repo entry into `$HOME` — `src` must be in its `$ignore` list (Task 1) or the next run creates `~/src`.
- Never run `git stash`/`git stash pop` — parallel session keeps WIP there.

Current inventory (verified 2026-07-17):

| File | Class/NS | PSR-4 today? |
|---|---|---|
| `misc/helpers.php` | `JT\CLI\Helpers` + `JT\CLI\Exception` | no (hand-required) |
| `misc/helpers/help.php` | `JT\CLI\Helpers\Help` | no |
| `misc/helpers/git.php` | `JT\CLI\Helpers\Git` | no |
| `misc/helpers/cmux.php` | `JT\Helpers\Cmux` | no |
| `misc/RepoConfigTrait.php` | `JT\RepoConfigTrait` | no (hand-required by bin/gituserlog:25, bin/ghissuecounts:29) |
| `misc/commands/*.php` (3) | `JT\CLI\Commands\*` | yes (`JT\CLI\Commands\` → `misc/commands/`) |
| `misc/traits/*.php` (1) | `JT\CLI\Traits\*` | yes |
| `bin/graveyard_lib.php` | `JT\Graveyard` | no (hand-required) |
| `bin/cmux-bak_lib.php` | `JT\CmuxBak` | no (hand-required) |
| `misc/bootstrap.php` | `getCli()` via composer "files" | n/a |

Two bootstrap idioms exist today: 20 scripts do `$cli = require_once dirname(__DIR__) . '/misc/helpers.php';` and 6 (`hisi-*`, `jt-blog-*`) do `require_once dirname(__DIR__) . '/vendor/autoload.php'; $cli = getCli($argv);`. Both converge on one line: `$cli = require_once dirname(__DIR__) . '/src/bootstrap.php';`.

---

### Task 1: src/ foundation (autoloader + bootstrap idiom)

**Files:**
- Create: `src/bootstrap.php`
- Modify: `composer.json` (add `JT\` root, keep legacy mappings)
- Modify: `symdotfiles` (ignore `src`)
- Test: `tests/Cli/BootstrapTest.php` (create)

**Interfaces:**
- Consumes: `JT\CLI\Helpers` (still at `misc/helpers.php` in this task — autoloaded via the legacy `JT\CLI\` → `misc/` mapping... **not** — it is hand-required; `src/bootstrap.php` must not depend on it existing at autoload time. The test requires it explicitly, see below).
- Produces: `src/bootstrap.php` returning a `JT\CLI\Helpers` instance; `getCli(array $args = []): \JT\CLI\Helpers`; `JT_DOTFILES_DIR` constant; the `JT\` → `src/` autoloader all later tasks rely on.

- [x] **Step 1: Create `src/bootstrap.php`**

```php
<?php
/**
 * Runtime bootstrap for JT CLI scripts — no composer install required.
 *
 * Registers a PSR-4 autoloader for the JT\ namespace rooted at src/, loads
 * composer's autoloader too when vendor/ exists (some scripts use vendor
 * packages), defines JT_DOTFILES_DIR + getCli(), and returns the CLI
 * Helpers instance so entry scripts keep the one-line idiom:
 *
 *   $cli = require_once dirname(__DIR__) . '/src/bootstrap.php';
 */

if ( ! defined( 'JT_DOTFILES_DIR' ) ) {
	define( 'JT_DOTFILES_DIR', dirname( __DIR__ ) );
}

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'JT\\' ) !== 0 ) {
		return;
	}
	$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, 3 ) ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

$vendorAutoload = JT_DOTFILES_DIR . '/vendor/autoload.php';
if ( is_file( $vendorAutoload ) ) {
	require_once $vendorAutoload;
}

if ( ! function_exists( 'getCli' ) ) {
	function getCli( array $args = [] ) {
		return \JT\CLI\Helpers::getInstance()->setArgs( $args );
	}
}

return getCli( isset( $argv ) ? $argv : [] );
```

- [x] **Step 2: Add the `JT\` root to composer.json (keep legacy mappings)**

Edit `autoload.psr-4` to:

```json
"psr-4": {
    "JT\\": "src/",
    "JT\\CLI\\": "misc/",
    "JT\\CLI\\Commands\\": "misc/commands/",
    "JT\\CLI\\Traits\\": "misc/traits/"
},
```

Composer's longest-prefix rule keeps `JT\CLI\*` resolving from `misc/` until those files move; the new root only catches classes nothing else claims (none exist in `src/` yet — this is dormant infrastructure).

- [x] **Step 3: Ignore `src` in symdotfiles**

In `symdotfiles`' `$ignore` array, after the `'aider'` line add:

```php
	'src',                // PSR-4 class tree; loaded via src/bootstrap.php, not $HOME symlinks
```

- [x] **Step 4: Dump the autoloader**

Run: `composer dump-autoload`
Expected: `Generating autoload files` with no errors.

- [x] **Step 5: Write the bootstrap smoke test**

Create `tests/Cli/BootstrapTest.php`:

```php
<?php
namespace JT\Tests\Cli;

use JT\Tests\TestCase;
use JT\CLI\Helpers;

class BootstrapTest extends TestCase
{
	public function testSrcBootstrapProvidesCliAndGetCli()
	{
		$cli = require dirname(__DIR__, 2) . '/src/bootstrap.php';
		$this->assertInstanceOf(Helpers::class, $cli);
		$this->assertTrue(function_exists('getCli'));
		$this->assertInstanceOf(Helpers::class, getCli([]));
	}
}
```

(`JT\CLI\Helpers` is already loaded by `tests/bootstrap.php`'s explicit require — this test only proves the new glue works, not the autoloader, which has nothing to load yet.)

- [x] **Step 6: Run the suite**

Run: `composer test`
Expected: new test passes; **7 pre-existing GraveyardPageTest failures**; all else green.

- [x] **Step 7: Commit**

```bash
git add src/bootstrap.php composer.json symdotfiles tests/Cli/BootstrapTest.php
git commit -m "add src/ PSR-4 bootstrap foundation with composer-independent autoloader"
```

---

### Task 2: Move the already-PSR-4 files (pure `git mv`)

**Files:**
- Move: `misc/commands/{SiteCommand,FetchFromSiteCommand,PublishToSiteCommand}.php` → `src/CLI/Commands/`
- Move: `misc/traits/CategoryTaxonomyTrait.php` → `src/CLI/Traits/`
- Move: `misc/RepoConfigTrait.php` → `src/RepoConfigTrait.php`
- Modify: `bin/gituserlog:25`, `bin/ghissuecounts:29` (delete the hand-require of `misc/RepoConfigTrait.php`)

**Interfaces:**
- Consumes: the dormant `JT\` → `src/` mappings from Task 1 (both the composer root and `src/bootstrap.php`'s autoloader).
- Produces: `JT\CLI\Commands\*`, `JT\CLI\Traits\*`, `JT\RepoConfigTrait` loadable from `src/` by both loaders. Note `bin/gituserlog`/`bin/ghissuecounts` keep their `vendor/autoload.php` + `misc/helpers.php` requires (later tasks); `RepoConfigTrait` uses `adhocore/json-comment` from vendor, which both loaders still provide.

- [x] **Step 1: Move the files**

```bash
mkdir -p src/CLI/Commands src/CLI/Traits
git mv misc/commands/SiteCommand.php misc/commands/FetchFromSiteCommand.php misc/commands/PublishToSiteCommand.php src/CLI/Commands/
git mv misc/traits/CategoryTaxonomyTrait.php src/CLI/Traits/
git mv misc/RepoConfigTrait.php src/RepoConfigTrait.php
```

- [x] **Step 2: Delete the two hand-requires**

In `bin/gituserlog` delete line 25:
```php
require_once dirname(__DIR__) . '/misc/RepoConfigTrait.php';
```
In `bin/ghissuecounts` delete line 29 (same content). (`JT\RepoConfigTrait` now autoloads: composer's root catches it for these vendor-requiring scripts.)

- [x] **Step 3: Regenerate + verify class resolution**

```bash
composer dump-autoload
php -r 'require "vendor/autoload.php"; var_dump(class_exists("JT\\CLI\\Commands\\SiteCommand"), trait_exists("JT\\CLI\\Traits\\CategoryTaxonomyTrait"), trait_exists("JT\\RepoConfigTrait"));'
```
Expected: `bool(true)` ×3.

- [x] **Step 4: Run the suite**

Run: `composer test`
Expected: green except the 7 pre-existing GraveyardPageTest failures.

- [x] **Step 5: Smoke the consumers**

Run: `php bin/ghissuecounts --help | head -3 && php bin/hisi-fetch --help | head -3`
Expected: each prints its help header (no "class not found").

- [x] **Step 6: Commit**

```bash
git add -A src misc bin/gituserlog bin/ghissuecounts
git commit -m "move PSR-4-ready commands/traits/RepoConfigTrait into src/"
```

---

### Task 3: Split helpers.php, move helpers/, rewire the plain scripts

**Files:**
- Create: `src/CLI/Exception.php`
- Create: `src/CLI/Helpers.php` (from `misc/helpers.php`, four edits)
- Move: `misc/helpers/help.php` → `src/CLI/Helpers/Help.php`; `misc/helpers/git.php` → `src/CLI/Helpers/Git.php`; `misc/helpers/cmux.php` → `src/Helpers/Cmux.php`
- Modify: the plain-pattern `bin/` scripts (bootstrap line swap) — **except `bin/graveyard`** (parallel session owns it until Task 4)
- Modify: `bin/cmux-bak` (delete the `misc/helpers/cmux.php` require)
- Modify: `misc/helpers.php` → forwarding shim; create `misc/helpers/cmux.php` forwarding shim (both keep the untouched `bin/graveyard` running until Task 4)
- Modify: `misc/bootstrap.php` (`getCli()` returns the singleton via autoload — breaks a require cycle with the shim)
- Modify: `tests/bootstrap.php` (slim)
- Delete: nothing in this task — shim removal happens in Task 4

**Interfaces:**
- Consumes: Task 1's `src/bootstrap.php`; Task 2's layout.
- Produces: `JT\CLI\Exception` → `src/CLI/Exception.php`; `JT\CLI\Helpers` → `src/CLI/Helpers.php`; `JT\CLI\Helpers\{Help,Git}` → `src/CLI/Helpers/`; `JT\Helpers\Cmux` → `src/Helpers/Cmux.php`; the universal bootstrap line `$cli = require_once dirname(__DIR__) . '/src/bootstrap.php';` in all 20 plain scripts.

- [x] **Step 1: Create `src/CLI/Exception.php`**

```php
<?php

namespace JT\CLI;

/**
 * Namespaced exception.
 */
class Exception extends \Exception {
	public $cli  = true;
	public $data = [];
}
```

- [x] **Step 2: Create `src/CLI/Helpers.php` from `misc/helpers.php`**

Copy `misc/helpers.php` to `src/CLI/Helpers.php`, then make exactly four edits:
1. Delete the `class Exception extends \Exception {...}` block (lines 15–18, including its docblock) — it now lives in `src/CLI/Exception.php`.
2. Delete the file's last line, `return Helpers::getInstance()->setArgs( $argv );` — a class file must have no side effects.
3. In `getHelp()`, delete the `require_once __DIR__ . '/helpers/help.php';` line so it reads:

```php
	public function getHelp( string $scriptName = '', array $commands = [] ) {
		return new Helpers\Help( $this, $scriptName, $commands );
	}
```

4. In `__construct()`, delete the `require_once __DIR__ . '/helpers/git.php';` line (line ~155) — `Helpers\Git` autoloads from `src/CLI/Helpers/Git.php`; the require would fatal on the old relative path after the move.

Keep the file-level docblock; update it to note the move if you like, but do not reformat anything else.

- [x] **Step 3: Move the helper libs**

```bash
mkdir -p src/CLI/Helpers src/Helpers
git mv misc/helpers/help.php src/CLI/Helpers/Help.php
git mv misc/helpers/git.php src/CLI/Helpers/Git.php
git mv misc/helpers/cmux.php src/Helpers/Cmux.php
```

- [x] **Step 4: Swap the bootstrap line in the plain scripts (except `bin/graveyard`)**

Two spacing variants exist in the repo (`dirname(__DIR__)` ×16, `dirname( __DIR__ )` ×8 — 24 plain scripts total). One extended-regex sed catches both:

```bash
grep -rlE "dirname\( ?__DIR__ ?\) . '/misc/helpers\.php'" bin/ | grep -v phploy-source | grep -v '^bin/graveyard$' | while read f; do
  sed -i '' -E "s|dirname\( ?__DIR__ ?\) \. '/misc/helpers\.php'|dirname(__DIR__) . '/src/bootstrap.php'|g" "$f"
done
grep -rn "misc/helpers\.php" bin/ | grep -v phploy-source
```

Expected: exactly one hit — `bin/graveyard`, deliberately left on the shim until Task 4. (The 6 composer-pattern scripts don't contain this string; they're Task 5. `sed -i ''` is macOS syntax — this is a one-time local migration command, not committed code.)

- [x] **Step 5: Delete the cmux.php require in cmux-bak only**

In `bin/cmux-bak` delete:
```php
require_once dirname(__DIR__) . '/misc/helpers/cmux.php';
```
(Find it with `grep -n "misc/helpers/cmux.php" bin/cmux-bak`.) Leave its `*_lib.php` require in place — Task 4 handles those. **Do not touch `bin/graveyard`'s identical require** — the shim in Step 6 keeps it working until Task 4.

- [x] **Step 6: Convert `misc/helpers.php` to a shim; shim `misc/helpers/cmux.php`**

After the `git mv` in Step 3, `misc/helpers/cmux.php` no longer exists — recreate it as a forwarding shim, and replace `misc/helpers.php`'s contents with a forwarding shim, so the still-unrewired `bin/graveyard` keeps working:

`misc/helpers.php`:
```php
<?php
// Transitional shim (src/ migration): forwards to src/bootstrap.php.
// Deleted in Task 4 once bin/graveyard is rewired.
return require_once dirname(__DIR__) . '/src/bootstrap.php';
```

`misc/helpers/cmux.php`:
```php
<?php
// Transitional shim (src/ migration): real class lives at src/Helpers/Cmux.php.
// Deleted in Task 4 once bin/graveyard is rewired.
require_once dirname(__DIR__, 2) . '/src/Helpers/Cmux.php';
```

(`src/bootstrap.php`'s autoloader makes the second shim redundant for autoload-aware callers, but `bin/graveyard` `require_once`s this exact path — it must exist and load the class.)

- [x] **Step 6b: Make `misc/bootstrap.php`'s `getCli()` return the singleton (not require a file)** *(done early, in Task 1 — pi applied it when its BootstrapTest exposed the redeclare bug)*

Change its body to:
```php
	function getCli( $argv = [] ) {
		return \JT\CLI\Helpers::getInstance()->setArgs( $argv );
	}
```
Rationale: with `misc/helpers.php` reduced to a shim forwarding to `src/bootstrap.php`, the old `require_once misc/helpers.php` body creates a cycle (getCli → shim → src/bootstrap → getCli → already-in-progress require → `true`) that leaves the 6 composer-pattern scripts with `$cli === true`. Returning the singleton via autoload breaks the cycle for the whole T3→T5 window. (`Helpers` autoloads via composer's `JT\` → `src/` fall-through once `src/CLI/Helpers.php` exists — i.e. from this task on.)

- [x] **Step 7: Slim tests/bootstrap.php (transitional form)**

Replace its contents with:

```php
<?php
/**
 * PHPUnit bootstrap for the dotfiles CLI test suite.
 * Composer's autoloader covers JT\ → src/ and JT\Tests\ → tests/.
 * The tool libs are hand-required until Task 4 moves them into src/.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bin/graveyard_lib.php';   // JT\Graveyard (until Task 4)
require_once dirname(__DIR__) . '/bin/cmux-bak_lib.php';    // JT\CmuxBak (until Task 4)
```

- [x] **Step 8: Drop the legacy psr-4 mappings NOW (not Task 5)**

Remove `"JT\\CLI\\": "misc/"`, `"JT\\CLI\\Commands\\": "misc/commands/"`, `"JT\\CLI\\Traits\\": "misc/traits/"` from `composer.json`, leaving `"JT\\": "src/"` + the `"files"` entry (Task 5 removes that). Rationale — **case-insensitive-FS collision**: composer's PSR-4 probe for `JT\CLI\Helpers` tries `misc/Helpers.php` first; on macOS APFS that case-insensitively matches the `misc/helpers.php` *shim*, which forwards to `src/bootstrap.php` → legacy `getCli()` → re-requests the same class mid-autoload → "Class not found". All `JT\CLI\*` classes live under `src/` after this task, so the legacy mappings are dead weight with a live footgun. (Discovered executing T3: only `BootstrapTest` errored, but the trace `misc/Helpers.php:4 → src/bootstrap.php → misc/bootstrap.php` proves the shim was being autoload-included.)

- [x] **Step 9: Regenerate + run the suite**

```bash
composer dump-autoload && composer test
```
Expected: green except the 7 pre-existing GraveyardPageTest failures.

- [x] **Step 10: Smoke the rewired scripts**

```bash
php bin/cmux-bak --help | head -3
php bin/graveyard --help | head -3
php bin/dayssince --help | head -3
```
Expected: each prints its help header, no PHP errors.

- [x] **Step 11: Commit**

```bash
git add -A src misc bin tests/bootstrap.php
git commit -m "split helpers into src/CLI and move helper libs to src/, one bootstrap idiom"
```

---

### Task 4: Move the tool libs (COORDINATION GATE)

**Gate — run before anything else:**

```bash
git status --short bin/graveyard bin/graveyard_lib.php tests/Graveyard/
```

Expected: **empty output**. If anything prints, the parallel graveyard session has uncommitted work — STOP; do not start this task until their changes are committed and pushed (`git log --oneline -3 -- bin/graveyard_lib.php` shows their latest).

**Files:**
- Move: `bin/graveyard_lib.php` → `src/Graveyard.php`
- Move: `bin/cmux-bak_lib.php` → `src/CmuxBak.php`
- Modify: `bin/graveyard` (bootstrap line swap + delete both requires), `bin/cmux-bak` (delete lib require)
- Delete: `misc/helpers.php` and `misc/helpers/cmux.php` (Task 3's transitional shims), then `misc/helpers/` and `misc/` if empty
- Modify: `tests/bootstrap.php` (final form)

**Interfaces:**
- Consumes: Task 3's autoloading for `JT\Helpers\Cmux`.
- Produces: `JT\Graveyard` → `src/Graveyard.php`; `JT\CmuxBak` → `src/CmuxBak.php`; entry scripts with zero class requires; `tests/bootstrap.php` reduced to one line; `misc/` free of PHP class files.

- [ ] **Step 1: Move the libs**

```bash
git mv bin/graveyard_lib.php src/Graveyard.php
git mv bin/cmux-bak_lib.php src/CmuxBak.php
```

- [ ] **Step 2: Rewire `bin/graveyard` (three deletions/swaps)**

1. Swap the bootstrap line: `dirname(__DIR__) . '/misc/helpers.php'` → `dirname(__DIR__) . '/src/bootstrap.php'`
2. Delete: `require_once dirname(__DIR__) . '/misc/helpers/cmux.php';`
3. Delete: `require_once __DIR__ . '/graveyard_lib.php';`

- [ ] **Step 3: Delete the lib require in `bin/cmux-bak`**

```php
require_once __DIR__ . '/cmux-bak_lib.php';
```

- [ ] **Step 4: Delete the shims**

```bash
git rm misc/helpers.php misc/helpers/cmux.php
rmdir misc/helpers 2>/dev/null; rmdir misc 2>/dev/null; true
```

(`misc/` also holds shell assets — `bootstrap`, `bootstrap-linux`, `gtag` — so it likely survives; only the PHP class files leave.)

- [ ] **Step 5: Final tests/bootstrap.php**

```php
<?php
/**
 * PHPUnit bootstrap for the dotfiles CLI test suite.
 * Composer's autoloader covers everything: JT\ → src/, JT\Tests\ → tests/.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
```

- [ ] **Step 6: Regenerate + run the suite**

```bash
composer dump-autoload && composer test
```
Expected: green except the 7 pre-existing GraveyardPageTest failures.

- [ ] **Step 7: Smoke both tools**

```bash
php bin/graveyard --help | head -3 && php bin/cmux-bak --help | head -3
```
Expected: help headers, no errors.

- [ ] **Step 8: Commit**

```bash
git add -A src bin misc tests/bootstrap.php
git commit -m "move graveyard/cmux-bak libs into src/ as PSR-4 classes, drop transitional shims"
```

---

### Task 5: Unify the composer-pattern scripts, drop legacy autoload, docs

**Files:**
- Modify: `bin/hisi-fetch`, `bin/hisi-publish`, `bin/jt-blog-fetch`, `bin/jt-blog-publish`, `bin/jt-blog-delete`, `bin/jt-blog-media` (bootstrap convergence)
- Modify: `composer.json` (remove legacy mappings + `files`)
- Delete: `misc/bootstrap.php`
- Modify: `AGENTS.md` (CLI Helpers Setup + Testing sections)

**Interfaces:**
- Consumes: everything prior.
- Produces: a single bootstrap idiom across all 26 scripts; composer autoload reduced to `"JT\\": "src/"` + `"JT\\Tests\\": "tests/"` (dev); updated contributor docs.

- [ ] **Step 1: Converge the 6 composer-pattern scripts**

In each of the 6 files, find:
```php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$cli = getCli( $argv );
```
and replace with:
```php
$cli = require_once dirname(__DIR__) . '/src/bootstrap.php';
```
Keep their `namespace JT\CLI;` and `use` statements untouched. (`src/bootstrap.php` loads vendor when present, so vendor-package usage in these scripts keeps working.) Verify with:
```bash
grep -l "getCli( \$argv )" bin/hisi-* bin/jt-blog-* | wc -l   # 6 before
grep -l "getCli( \$argv )" bin/hisi-* bin/jt-blog-* | wc -l   # 0 after
```

- [ ] **Step 2: Drop legacy autoload config**

In `composer.json`, replace the whole `autoload` block with:

```json
"autoload": {
    "psr-4": {
        "JT\\": "src/"
    }
},
```

Then: `git rm misc/bootstrap.php && composer dump-autoload`

- [ ] **Step 3: Update AGENTS.md**

Two edits:
1. "CLI Helpers Setup" section — replace:
   ```php
   $cli = require_once dirname(__DIR__) . '/misc/helpers.php';
   $helpyHelperton = $cli->getHelp();
   ```
   with:
   ```php
   $cli = require_once dirname(__DIR__) . '/src/bootstrap.php';
   $helpyHelperton = $cli->getHelp();
   ```
2. "Testing" section — replace the paragraph about `bin/<tool>_lib.php` companion libs and the snake-case/`tests/bootstrap.php` caveat with the new convention:
   ```markdown
   **Write CLI scripts to be testable.** Keep `bin/<tool>` entry scripts thin and
   put the real logic in a PSR-4 class under `src/` (e.g. `src/Graveyard.php` →
   `JT\Graveyard`, `src/Helpers/Cmux.php` → `JT\Helpers\Cmux`). `src/bootstrap.php`
   registers the `JT\` → `src/` autoloader at runtime (no composer install needed);
   composer maps the same root for tests, so no hand-requires anywhere.
   ```

- [ ] **Step 4: Final sweep**

```bash
grep -rn "misc/helpers\|misc/bootstrap\|_lib\.php" bin tests src AGENTS.md composer.json | grep -v phploy-source
```
Expected: no hits (or only intentional historical mentions in comments).

- [ ] **Step 5: Full verification**

```bash
composer dump-autoload && composer test
php bin/hisi-fetch --help | head -3
php bin/html-to-markdown --help 2>&1 | head -3 || echo '<b>x</b>' | php bin/html-to-markdown
```
Expected: suite green except the 7 pre-existing failures; help/markdown output, no PHP errors.

- [ ] **Step 6: Commit**

```bash
git add -A bin composer.json misc AGENTS.md
git commit -m "unify all bin scripts on src/bootstrap.php and drop legacy autoload config"
```

---

## What stays behind (intentionally out of scope)

- `misc/bootstrap`, `misc/bootstrap-linux`, `misc/gtag` — shell/script assets, not PHP classes; unreferenced by the autoload system. Leave in `misc/`.
- `bin/phploy-source/` — self-contained vendored app with its own vendor/; never touch.
- The 7 pre-existing `GraveyardPageTest` failures — owned by the parallel session.

## Self-Review Notes

- **Spec coverage:** every file in the inventory table has a task: helpers.php → T3 (split), help/git/cmux → T3, commands/traits/RepoConfigTrait → T2, graveyard_lib/cmux-bak_lib → T4, misc/bootstrap.php → T5, composer.json → T1+T5, symdotfiles → T1, tests/bootstrap → T3+T4, AGENTS.md → T5.
- **Ordering hazards checked:** T3 slims tests/bootstrap but keeps lib requires (libs move in T4); `JT\` root added in T1 is harmless because longest-prefix keeps legacy mappings winning until files actually move; `getCli` defined in both `src/bootstrap.php` and `misc/bootstrap.php` during transition is safe (both guard with `function_exists`).
- **Type consistency:** `getCli(array $args = []): \JT\CLI\Helpers` matches `Helpers::getInstance()->setArgs(...)`; bootstrap return value matches the `$cli = require_once ...` idiom in all 26 scripts.
