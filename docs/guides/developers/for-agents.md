---
title: For agents
type: how-to
audience: both
summary: How agents should read this handbook, llms.txt, and an AGENTS.md to put in a plugin.
rests_on: [docs/guides/developers, docs/architecture/documentation.md]
---

# For agents

This handbook is written for people and for agents. Prefer the Markdown pages in this folder over scraping the admin. A generated index lists every page in one place.

## llms.txt

`docs/guides/developers/llms.txt` lists each page with its one-line summary, in the README’s order. Read that file first when you need a map, then open the linked `.md` pages. Regenerate it after you add or rename a page:

```sh
php artisan teme:docs:handbook-index
```

## What to trust

- Guides under `docs/guides/developers/` are written and tested against the code.
- Files under `docs/reference/` are generated. Never edit them by hand; run the docs command that owns the file (for example `php artisan teme:docs:hooks`).
- Architecture notes under `docs/architecture/` explain how this repository works for contributors. Prefer the handbook when you are building a plugin or theme as an outsider would.

## An AGENTS.md for your plugin

Put a short `AGENTS.md` at the root of your plugin package. Keep it specific to that package:

1. What the plugin does in one paragraph.
2. The capabilities and hooks in its `teme-plugin.json`.
3. How to run its tests.
4. A link to this handbook’s [Your first plugin](start/first-plugin.md) and [Hooks](concepts/hooks.md).

Do not paste internal labels from Teme’s own tracker into that file. Write for the next agent that opens the package cold.
