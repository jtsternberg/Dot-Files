---
name: local-review
description: Review code changes with two local models and merge their findings into one verified list. Use when asked to review a diff, the uncommitted changes, a branch, or a PR's changes locally, or for a second opinion on changes before committing.
---

# Local review

`local-review` runs two local Ollama models one after the other, never side by
side. A fast primary reviews the diff and re-checks its own list until it says
it is confident. Then a reasoning advisor reviews the same diff once. Each one
catches bugs the other misses, so your job is to merge the two lists and check
every finding against the code.

This is a review, not a fix. Do not edit, create, or delete files, and do not
write or run test code. You verify findings by reading code.

Start it with `/skill:local-review`. Local models often skip reading a skill
that is only listed, and improvise instead.

## 1. Run it

Pick the diff source:

- Uncommitted changes: `local-review`
- This branch: `local-review --base=<base-branch>`
- A diff file: `local-review <file>`
- Anything else: pipe it in with `local-review --stdin`

The advisor reasons for minutes, so give the command a timeout of at least 30
minutes. Progress lines go to stderr. The findings go to stdout under
`## Primary review: …` and `## Advisor review: …`.

There is no advisor section when the external model drive is not mounted: only
the primary ran. A section that ends in `_Failed: …_` produced no usable review,
so say so in your report.

## 2. Merge and verify

1. Merge the two lists into one. A bug both models found is one finding. Keep
   every finding that only one model raised, and note which model raised it.
2. Check each finding against the actual code: read the changed file, not only
   the diff. When the file is not on disk, check against the diff alone. Drop
   a finding the code disproves, and say why in one line. Both models state
   false facts with confidence, for example that Python's `Lock.acquire()`
   takes no timeout, or an operator-precedence bug in `a * 2 ** n`. Check the
   stated failure itself, not only that the line exists.
3. Drop findings about changes that keep the old behavior, and style comments.

## 3. Report

List the findings that held up, most severe first. Give each its file and line,
the concrete failure, and which model(s) found it. Then list what you dropped
and why, in one line each. Say which models ran and whether the primary reached
`confident`.
