# Vendored front-end assets

Inlined into generated HTML (e.g. `graveyard page`) so pages stay self-contained
(no external requests — required for `file://` and strict CSP).

- **alpine.min.js** — Alpine.js v3.14.9, from
  https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js
  Re-vendor: `curl -sL <url> -o src/assets/alpine.min.js`
