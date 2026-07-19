# Handoff — graveyard page "flourishes" + serve

> Resume: `Read HANDOFF-master-graveyard-page.md and continue where we left off`
> Branch: `master` (this repo commits directly to master — never branch).
> `HANDOFF-master.md` is a DIFFERENT session's file — do not touch it.

## Goal

Polish the `graveyard page` graveyard-themed interactive overview — JT keeps
requesting visual/UX "flourishes." All page markup/CSS/JS lives in
`src/templates/graveyard-page.html` (+ partials `src/templates/partials/{stone,plot}.html`),
interpolated by `Graveyard::pageHtml()` in `bin/graveyard_lib.php`.

## ✅ ARCHITECTURE — serve-only (epic `dotfiles-06t`, shipped)

The page is **serve-only**: there is no `file://` mode, no static `index.html`
snapshot, no dual-mode feature detection. A localhost-only `php -S` server
renders the page FRESH from the store on every request.

- **Verb model:** `graveyard page` ensures the loopback server is up (spawns it
  detached, or reuses the one already listening on the persisted port) and opens
  the URL. `graveyard page --no-open` ensures the server and just prints the URL.
  `graveyard serve` is a quiet alias for `page --no-open`. The server runs in the
  background and is reused across invocations; the chosen port/pid persist in
  `~/.claude-graveyard/.serve.json` so bookmarks survive re-runs.
- **Router** (`bin/graveyard_router.php`): `GET /` and `/index.html` →
  `Graveyard::renderStorePageHtml()`; `GET /page-data/<id>.js` →
  `Graveyard::renderTranscriptJs()` (read fresh from the archived transcript);
  `POST /api/*` → `Graveyard::handleApi()` (same rename/delete/purge core as the
  CLI verbs). Nothing is written to the store to render — `page()` no longer
  writes `index.html`/`page-data/*.js`, and `ensureServer()` deletes those stale
  artifacts on spawn.
- **Rationale / the bug this fixed:** the old router fell through to `php -S`
  serving a last-written static `index.html`, so a workspace buried after that
  write never appeared on refresh. Fresh-per-request rendering fixes it (see
  `GraveyardRouterTest::testFreshRenderShowsSessionsBuriedAfterFirstRender`).
- **Template:** the `live` feature-detection and all static-mode branches
  (copy-command boxes, confirm-reveal step) are gone. Rename auto-saves via the
  API; delete is one-tap → API + sink animation. The resurrection ritual modal
  stays (resurrection is inherently a CLI act).
- Shipped commits: `3d918ed` (fresh-render router + helpers), `e31034a` (merged
  serve-only `page` verb), `26fba19` (template de-branding), plus docs.

## ✅ DONE — double scrollbar fix (shipped `d791b53`)

The tombstone modal's double vertical scrollbar was fixed: the card is now a
flex column with a single scroller (the transcript `<pre>` fills remaining
space; rename/delete actions stay pinned). No pending scrollbar work.

## ✅ DONE — level-5 "flourish round" (epic `dotfiles-rqj`, all shipped + pushed, 130 tests green)

Eight features from the `claude-graveyard-level5` prototypes (interaction-lab.html
+ index.html), all committed to master with per-feature commits and browser-verified:

- `603ecb9` **hover polish** — stone hover now lifts + tilts (-1.3deg) + brightens
  + deeper shadow; hover transform reset under `prefers-reduced-motion`.
- `363b3dd` **film-grain overlay** — fixed SVG fractalNoise `.grain`, opacity .13,
  z-index -1 (background texture only; never over stones/modals, preserves carving).
- `ad2b679` **cracked failed stones** — `Graveyard::stoneCracked($title)` (pure,
  regex `/bug|fix|fail|error|broken/i`) appends a `cracked` class after the crown
  token in `stoneHtml`; `.stone.cracked` chips the top via clip-path. 2 new tests.
- `029d5f1` **live delete sink + toast** — live-mode delete closes the modal, plays
  `.burying` sink (~0.8s) then removes + shows `#gy-toast` ("returned to the earth").
  `buryThenRemove()`/`toast()`/`reduceMotion()` helpers; reduced-motion keeps toast,
  skips animation. Static (file://) path unchanged.
- `f0227d3` + `40a434e` **resurrection ritual modal** — `<dialog id="ritual">`
  opened by "prepare resurrection" buttons in BOTH modals (session modal gained
  one; plot modal's static resurrect copy-box replaced). `openRitual()`/`copyRitual()`
  auto-copy with confirm text + clipboard-blocked fallback; works static + live.
  `40a434e` adds the interaction-lab shovel `dig` animation (`digShovel()`, remove+
  reflow+re-add so it replays on open and every copy trigger).
- `63b9f8e` **ambient sky** — fixed `.moon` 🌕 with 6s `moonpulse` glow + rare `.bat`
  🦇 flitting across on a 48s cycle (mostly unseen). Both behind content (z-index 0);
  existing `.fog` kept. All off under reduced-motion (bat hidden). NOTE: `main#yard`
  got `position: relative; z-index: 1` so stones sit above fog/moon.
- `876ec98` **flavor copy pass** — richer delete confirmations ("…gone for good")
  and missing-transcript notice ("no transcript lies here").
- `f121200` **coffin ghost easter egg** — hovering the footer `.coffin` ⚰️ jiggles it
  while a translucent 👻 rises out and fades (~2.6s); re-hover re-spawns (hover-only
  animation). Off under reduced-motion.

Verification notes: live-mode features (delete sink/toast, live-mode of ritual) were
verified against a `cp -R` fixture served via `GRAVEYARD_ROOT=<fixture> php -S
127.0.0.1:8799 -t <fixture> bin/graveyard_router.php` — NEVER against the real store.
Clipboard is blocked in headless agent-browser, so the ritual's fallback message is
what shows there (the success path needs a focused real browser).

## Current progress (all shipped, pushed to master, 128 tests green)

Recent commits (newest first):
- `5356d32` tombstone modal echoes the grave's corner-shape at SMALL scale
  (16px top corners, square bottom). Reads clicked stone's computed
  `corner-shape`, applies top shape to card via `item.crownShape` inline.
- `0029207`→reverted-then-redone: first tried full crown on the modal card
  (`0029207`) — it CLIPPED the title in the curved corners (bad); reverted, then
  redid small-scale as `5356d32`. Don't reintroduce a full-arch modal.
- `0a54be2` square-based headstones + 6 randomized crown silhouettes
  (`stoneCrown($sid)` crc32%6 → `.crown-0..5` CSS, corner-shape round/gothic/
  squircle/scoop/superellipse/notch; bottom always square = buried).
- `1914cb2` `corner-shape` carved-stone look (plots beveled fences).
- `ff774e6` header watermark + bigger 🥀/☠ glyphs + RESTORED `%%SUMMARY%%`
  (JT had accidentally hardcoded the count/timestamp in the header rework).
- `ee446f5` enlarged decorative emojis (crest/skull/casket/webs/epitaph).
- `85568a2` + `fd34e19` + `83665ba` + `4fe148f` = `graveyard serve` epic
  (dotfiles-vn5): loopback PHP server + `bin/graveyard_router.php` +
  `Graveyard::handleApi()` JSON API; page feature-detects http vs file://
  (`live`); live rename auto-saves debounced, live delete is one button →
  native `confirm()` → API; `.cmd` copy-command boxes are static-only. Serves
  `index.html` at `http://graveyard.localhost:<port>/`.

Earlier arc (also shipped): Alpine.js inlined foundation, in-page search,
plot-details modal, rename/delete UI + CLI verbs, template extraction, JS
masonry, plot backlink, distinct plot modal. See `git log` + closed beads
under epics `dotfiles-55n`, `dotfiles-bgs`, `dotfiles-vn5`.

## Files (this whole effort)

- `bin/graveyard` — verb dispatch (bury/ls/search/show/rename/delete/resurrect/page/serve).
- `bin/graveyard_lib.php` — class `JT\Graveyard`. Key: `pageHtml` (pure render),
  `renderStorePageHtml`/`renderTranscriptJs` (fresh per-request render the router
  calls), `page`/`serve`/`ensureServer` (serve-only verb: spawn-or-reuse the
  loopback server, persisted in `.serve.json`), `pageTemplate`/`renderPartial`,
  `stoneHtml`, `stoneCrown`, `stoneCracked`, `plotHue`, `plotColumns`, `pageUnits`,
  rename/delete core (`setSessionName`/`setGroupName`/`purgeSession`/`purgeGroup`)
  + verb wrappers + `handleApi` (CLI and REST share the SAME core — no drift).
- `bin/graveyard_router.php` — `php -S` router; renders `/`, `/index.html`, and
  `/page-data/<id>.js` fresh from the store per request, plus `POST /api/*`.
- `src/templates/graveyard-page.html` — the page shell (CSS + Alpine component + markup).
- `src/templates/partials/{stone,plot}.html` — per-item markup (`%%KEY%%` placeholders).
- `src/assets/alpine.min.js` — vendored Alpine v3.14.9 (inlined).
- `tests/Graveyard/GraveyardPageTest.php`, `GraveyardRenameDeleteTest.php`,
  `GraveyardServeApiTest.php`.

## What worked

- Deterministic seeding (crc32) for per-item variety that's stable across
  regenerations: `plotHue`, `plotColumns`, `stoneCrown`.
- `corner-shape` as progressive enhancement (supported in JT's browser; degrades
  to `border-radius`). Separate SHAPE from SIZE: modal reuses the stone's
  computed corner-shape but at its own small radius.
- Template + partials extraction keeps output byte-preserving → tests stay green.
- Verifying live/visual changes with `agent-browser` (see gotchas).

## What didn't work (don't repeat)

- Applying a full crown class (big arch, %/60px radius) to the tombstone modal
  card: the arch clips the title in the curved corners and fights the header/
  close button on a tall scrolling card. Only do SMALL-radius corner accents on
  the modal; the bottom must always be square (tombstone is buried).
- Hardcoding `%%SUMMARY%%` in the header (freezes the count/timestamp) — keep the
  placeholder; `pageHtml` fills it.

## Gotchas

- `agent-browser` evals that read Alpine-bound text right after dispatching an
  `input`/`click` read PRE-flush values — add ~250ms `setTimeout` before asserting.
  Check true visibility with `offsetParent !== null` (getComputedStyle ignores a
  hidden ancestor).
- `.git/index.lock` "another git process" errors recur (a background read-only
  `git log` viewer). If `pgrep -fl 'git '` shows only `git log`, `rm -f
  .git/index.lock` and retry the commit.
- `page`/`serve` are store-only (exempt from the `cmux ping` gate). To view the
  page: `php bin/graveyard page --no-open` (ensures the server, prints the URL);
  it renders fresh, so no "regenerate" step exists anymore.
- To verify serve-only behavior WITHOUT touching the real store, serve a `cp -R`
  fixture: `GRAVEYARD_ROOT=<fixture> php -S 127.0.0.1:<port> -t <fixture> bin/graveyard_router.php`,
  then bury into the fixture and refresh. NEVER run destructive `graveyard delete`
  / the delete API against the real `~/.claude-graveyard`.
- The template uses TABS for CSS/JS indentation and SPACES for HTML — match
  exactly or Edit won't find the string (verify with `sed -n l`).
- Every commit: end body with the `Co-Authored-By: Claude Opus 4.8` +
  `Claude-Session:` trailers. Commit + push per change; sync beads with `bd dolt push`.

## Next steps

1. Expect more "flourish" requests — keep the per-bead commit + agent-browser
   visual-verify rhythm (epic `dotfiles-rqj` closed; open a new epic for the
   next round).
2. Gotcha for the next agent: commits must be signed. If signing errors,
   stop and ask JT — never disable `commit.gpgsign` or work around it.
