# Proposal: the plugin import socket (v1, wave 2)

**Status:** draft, 2026-11-15, for v1 (I-3445). Plan: `docs/plans/plugin-import-socket.md`.

## Why

A site moving from WordPress carries data that belongs to its plugins, not to WordPress itself: a fediverse plugin's keys and followers, a form plugin's entries, a plugin's own settings. Today Teme's import brings WordPress's own things (posts, pages, terms, people, media, comments, menus, redirects, settings), and the companion plugin answers only fixed lists. A plugin built for Teme has no way to say "bring my data across too".

This part adds that socket. Core's own ActivityPub (I-058) uses the same route for its keys and followers (`docs/studies/2026-11-15-activitypub-continuity.md`).

## Settled

1. **A plugin declares what it brings, in its manifest** (rule: declared before coded): WordPress options by name; post types with the meta keys it needs; user meta keys; term meta keys. Nothing undeclared is ever read.
2. **Consent covers it.** The declaration is a new capability, `import.wordpress`, shown on the consent screen with its list ("reads from the WordPress site you import: options `x`, `y`; the post type `z` with its meta `a`, `b`"). A plugin without it gets nothing.
3. **Some keys are never sent, whatever a plugin declares:** `user_pass`, `session_tokens`, `_application_passwords`, and core's own `wp_user_roles`. The manifest validator refuses them, and the companion refuses them again.
4. **No serialized PHP crosses over** (rule 4). The companion decodes serialized values to JSON before answering; a value holding a PHP object is sent as `null` with a flag, and reported.
5. **Core runs it; the plugin receives it.** One core import phase, `plugin-data`, runs after core's own content, terms and people (so a plugin can look up what they became) and before the second pass. For each enabled plugin with the capability, it reads the declared data a page at a time and hands each record to the plugin's importer contract, inside that record's own transaction with its map row, as every list phase does (I-332).
6. **Catch-up and re-runs behave like core's lists:** a record unchanged since the last run is skipped; a changed one is handed over again; one gone from WordPress is reported. One record failing is reported and the run carries on.
7. **The run report gets a section per plugin:** how many records of each kind were brought, skipped and failed, with the plugin's own failure sentences.
8. **Secrets a plugin imports stay secret:** a plugin stores keys it imports (for example fediverse key pairs) through the `secret` setting type, encrypted, never in plain columns.
9. **The companion change is specified here and built separately** (the companion is its own WordPress plugin). Teme's side is built and tested against the test companion (`FakeCompanion`), and checks the companion's API version before asking.

## Provisional (built as written unless the maintainers say otherwise)

- **P1. A plugin's own WordPress tables are later.** v1 brings options, post types with meta, user meta and term meta. Reading a plugin's custom tables waits for a real need (most such data is recomputed on Teme anyway).
- **P2. The phase's order is 850** (after core content, terms and people; before the second pass), and every plugin's records run in that one phase, plugin by plugin, in the order of their codes.
- **P3. Records arrive one at a time.** No bulk hand-over in v1.

## Not here

- A Markdown converter: inside the docs-sync plugin (another agent); moves to core only if a second real need appears.
- Importing from systems other than WordPress.
- Bringing a plugin's custom tables (P1).
- The core ActivityPub feature itself (its own part); it only uses this route.
