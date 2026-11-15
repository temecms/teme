# Proposal: the developer handbook, first pages

**Status:** for building, v1 wave 2. **Decided by:** the v1 scope (item 15) and the maintainers on 2026-11-15. **Evidence:** `docs/studies/2026-11-15-developer-docs-references.md` (WordPress, Laravel, Filament, Statamic, Craft, EmDash, Payload, Wagtail).

## Why

People and AI agents will build Teme plugins and themes from documentation, the way an outside developer would. Today Teme has guides for editors and operators, an internal plugin design (`docs/architecture/plugins.md`) and test fixtures, but nothing written for someone building a plugin or a theme.

## Settled

1. **The handbook is Markdown in this repository, beside the code**, at `docs/guides/developers/`. A change to the code and its page land in the same pull request. A docs site that runs TemeCMS will publish these files later, through a separate docs-sync plugin; this part does not build it.
2. **Reference is generated, guides are written** (`docs/architecture/documentation.md`). Generated pages live in `docs/reference/` and are never edited by hand.
3. **Every page has a type** in its front matter: `tutorial`, `how-to`, `concept` or `reference`, with its title, audience (`plugin`, `theme` or `both`) and the source folders it rests on.
4. **Examples are real, tested code.** The first plugin and the first theme are example packages under `docs/guides/developers/examples/`, enabled and exercised by Pest. A page shows code from those files; it never carries a code sample that no test runs, except one-line commands.
5. **A build check fails when the docs and the code disagree:** every generated reference must equal what its generator writes now, and every hook, manifest key, command or class a page names must exist.
6. **The hook reference is generated now** from the hook classes in `packages/teme-contracts/src` (each implements one of the five verbs, carries `#[HookContract]` and `@since`). This closes the generated-reference half of #363.
7. **For agents:** a generated `docs/guides/developers/llms.txt` lists every page with its one-line summary, and the pages themselves are the `.md` versions agents read.
8. **American English, plain words, no screenshots yet.** Screenshots come later, produced by the browser tests on each release; until then pages describe screens in words, as the operator guide does.
9. **Pages for sockets still being built are stubs** that say what lands and with which part: plugins writing content, jobs and schedules, public routes, the plugin import socket. Each stub is filled by the part that builds the socket.

## The first pages

| Page | Type | Notes |
|---|---|---|
| `README.md` — the handbook's index | — | sections and the order to read them |
| `start/plugin-or-theme.md` | concept | which one you need, with two short examples |
| `start/first-plugin.md` | tutorial | from an empty folder to an enabled plugin that listens to a hook; code from `examples/plugins/first-plugin` |
| `start/first-theme.md` | tutorial | a theme with an index and a single template; code from `examples/themes/first-theme` |
| `concepts/manifest.md` | how-to | writing `teme-plugin.json` and `teme-theme.json`; links to the generated key tables |
| `concepts/hooks.md` | concept | the five verbs, after-commit rule, capabilities, ordering; links to the generated hook reference |
| `concepts/operations.md` | concept | every change goes through an Operation; what a plugin may and may not touch |
| `testing.md` | how-to | testing a plugin or theme with Pest and the fixtures' helpers |
| `for-agents.md` | how-to | `llms.txt`, the `.md` pages, an `AGENTS.md` to put in a plugin |
| `plugins/{content-write,jobs,public-routes,import}.md` | stub | "lands with <part>" |
| `docs/reference/hooks.md` | reference | generated |
| `docs/reference/plugin-manifest.md`, `theme-manifest.md` | reference | generated from the two JSON schemas |

## Provisional (built as written unless the maintainers say otherwise)

- **P1.** Examples live under `docs/guides/developers/examples/` rather than a new top-level `examples/` folder (no new base folder).
- **P2.** No scaffold command yet (`teme:make:plugin`); the tutorial starts from a copy of the example. A scaffold comes after v1.
- **P3.** "Coming from WordPress" (a mapping of WordPress nouns and hooks to Teme's) is written after the first ten pages, not in this part.

## Out of this part

The docs site and its docs-sync plugin; the content-write, jobs, public-routes and import pages beyond their stubs; agent skills (I-3431) and a docs MCP; screenshots.
