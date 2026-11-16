---
title: The plugin import socket
type: how-to
audience: plugin
summary: How a plugin brings its own data across when a site moves from WordPress, declaring exactly what it reads and receiving it from Teme's import.
rests_on: [packages/teme-contracts/src/Import, app/Domain/Import, docs/architecture/plugins.md, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# The plugin import socket

A site moving from WordPress carries data that belongs to its plugins: a social plugin's keys and followers, a form plugin's entries, a plugin's own settings. A Teme plugin that replaces one of those brings its share across through Teme's import. It never reads the WordPress site itself: it declares what it needs, the site's owner agrees, and the import hands it exactly that.

## Declare what you read

Ask for the `import.wordpress` capability, and add an `imports` block to `teme-plugin.json`:

- `importer`, the class that receives the data;
- `options`, the WordPress option names to read;
- `postTypes`, each WordPress post type to read, with the meta keys of its posts to bring;
- `userMeta` and `termMeta`, the meta keys of people and of terms to bring.

Names are as WordPress spells them: letters, digits, underscores, hyphens, colons and dots, up to 191 characters, and at most 50 names in all. Names that hold passwords, sessions, application passwords and roles (`user_pass`, `session_tokens`, `_application_passwords`, `mailserver_pass`, and any name ending in `user_roles` or `capabilities`) are refused, whatever the plugin.

## The owner agrees name by name

The consent screen shows each declared name, and enabling the plugin records them with what was agreed to. An update that declares a new name waits until someone agrees to it, with the name marked new. An import hands your plugin only the names both declared and agreed.

## Receive the data

The importer implements `WordPressImporter`. Teme builds it as it builds your plugin's class, with only what your granted capabilities offer. During an import, for each enabled plugin holding the capability, in the order of their codes:

- `options()` receives the declared options once a run, with the run's `ImportScope`;
- `record()` receives each declared record as a `WordPressRecord`: a post of a declared type with its fields and declared meta, a person's declared meta, or a term's. Its `kind` says which. It answers an `ImportOutcome`: imported, skipped or failed, with a sentence for the run's report.

Each record is handed over inside its own transaction, after Teme has imported the site's people, terms and content, so `ImportScope` can tell you what a WordPress post became (`itemFor()`, answering its `ItemRef`) and which person a WordPress user became (`personFor()`). `isCatchUp()` says whether the run is a catch-up after an earlier import; a catch-up hands over only the records that changed since, and lists the ones WordPress no longer has, deleting nothing.

A record your importer fails, or one where it throws, is reported in the run with its sentence, and the run goes on. A value WordPress stored serialized arrives decoded; one that held a PHP object arrives as null, and is reported.

## Keep what you bring safe

Store imported keys and tokens as `secret` settings, never in a plain column of your own tables. Keep the rest in your plugin's settings or [its own tables](tables.md).

## What isn't read

Your plugin's own WordPress tables are not read in this version: only options, the declared post types, and people's and terms' meta. The import needs the WordPress companion plugin's export API 2; an older companion is reported, and the plugins' share is skipped.

Next: the generated [contracts reference](../../../reference/contracts.md), and [Plugins writing content](content-write.md).
