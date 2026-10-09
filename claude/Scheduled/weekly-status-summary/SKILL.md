---
name: weekly-status-summary
description: Every Friday at 4pm, summarize JT's week (accomplishments, decisions, open items) as a status update.
---

Write a short weekly status update summarizing what Justin "JT" Sternberg (jsternberg@awesomemotive.com, me@jtsternberg.com, justin@dsgnwrks.pro, GitHub @jtsternberg) worked on this week, Monday through today (Friday). Address him as "you", never in the third person.

## Gather evidence (use whatever of these is connected; skip quietly if one is unavailable or needs auth)
1. **Claude/Cowork sessions**: use `list_sessions` and `read_transcript` (session_info tools) to review sessions from this week. Pull out what was built, fixed, decided, or left unfinished.
2. **Asana**: `get_me`, then `get_my_tasks` / `search_tasks` for tasks assigned to him that were completed, updated, or commented on this week, plus open tasks due soon or overdue.
3. **Gmail**: `search_threads` for threads he sent or replied to this week (e.g. `from:me newer_than:7d`) to capture decisions, commitments, and pending replies. Ignore newsletters/automated mail.
4. **Google Calendar**: `list_events` for this week to note key meetings (and what they were about); also glance at next week for upcoming deadlines.
5. **Slack / Linear / Notion / Atlassian / ClickUp**: if their tools are available and authorized, search for his messages, issues, and pages from this week. If they aren't authorized, don't stop — note at the bottom which sources were unavailable.

Only report things supported by the evidence. Do not invent work. Merge duplicates across sources (the same project in Asana, email, and a session is one item).

## Output format
Keep it tight — roughly 150–300 words, skimmable, suitable to paste into Slack or an email to a manager/team:

**Week of <Mon date> – <Fri date>**

**Key accomplishments** — 3–6 bullets, outcome-focused (what shipped/moved, not just "worked on"). Group by project when it helps.

**Decisions made** — bullets with the decision and a few words of why/context. Write "None notable" if empty.

**Still open / next up** — bullets for in-progress work, blockers, pending replies, and anything due next week, with owners or dates where known.

Tone: clear and professional enough for a status update, with a light touch of wit allowed (one well-placed joke max — JT likes funny, his manager likes clarity).

## Delivery
- Save the summary as a Markdown file named `weekly-status-YYYY-MM-DD.md` (using today's date) in the outputs folder and present it with `present_files`.
- Also show the full summary text in your final reply so it can be copied directly.
- End with a one-line "Sources checked:" list, and a "Couldn't access:" list if any connectors were unavailable.
- Do NOT send any email or post any message on his behalf.