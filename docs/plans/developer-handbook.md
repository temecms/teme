# Plan: the developer handbook, first pages (v1 wave 2)

**One part, one branch: `docs/handbook`, from `origin/main`.** Built side by side with the other wave-2 parts (plugin public routes, the plugin import socket, the management API foundation, insertion points, multilingual). Proposal: `docs/proposals/developer-handbook.md` (its Settled section is binding).

**Read first:** `AGENTS.md`; `.ai/rules/index.md`, `.ai/rules/builders.md`; `docs/architecture/documentation.md`; `docs/architecture/plugins.md`; `docs/architecture/themes.md`; `docs/guides/operators/README.md` and one operator page (the house style); `tests/Feature/Guides/Operators/OperatorPagesTest.php` and `tests/Fixtures/Guides/GuideFile.php` (how guides are tested); `tests/Feature/Console/GenerateFieldsReferenceTest.php` and `app/Console/Commands/GenerateFieldsReference.php` (how a reference is generated and kept current); `tests/Fixtures/plugins/hello/` and `tests/Fixtures/themes/good/` (what a working plugin and theme look like); `packages/teme-contracts/src/Hooks/` and `packages/teme-contracts/schema/`.

## Owns

- `docs/guides/developers/**` (pages, `llms.txt`, `examples/plugins/first-plugin/**`, `examples/themes/first-theme/**`)
- `docs/guides/README.md`: one added row for the developers' section, nothing else changed
- `docs/reference/hooks.md`, `docs/reference/plugin-manifest.md`, `docs/reference/theme-manifest.md`
- `app/Console/Commands/GenerateHooksReference.php` (`teme:docs:hooks`), `GenerateManifestReference.php` (`teme:docs:manifest`), `GenerateHandbookIndex.php` (`teme:docs:handbook-index`)
- `tests/Feature/Guides/Developers/**`, `tests/Feature/Console/GenerateHooksReferenceTest.php`, `GenerateManifestReferenceTest.php`, `GenerateHandbookIndexTest.php`

Never changed: anything in `app/Domain/**`, `packages/teme-contracts/src/**` (read only; a missing `@since` or docblock found there is recorded as a B-?? and filed, not fixed here), `components/ui/**`, the tokens, other parts' files.

## Decisions written out

- **Front matter** of every page under `docs/guides/developers/` (except `examples/` and `llms.txt`):
  ```yaml
  ---
  title: Your first plugin
  type: tutorial          # tutorial | how-to | concept | reference | stub
  audience: plugin        # plugin | theme | both
  summary: From an empty folder to an enabled plugin that listens to a hook.
  rests_on: [packages/teme-contracts/src/Hooks, app/Domain/Plugins]
  ---
  ```
  A `stub` page also has `lands_with:` naming the part (`plugins/content-write`, `plugins/jobs`, `plugin public routes`, `plugin import socket`).
- **Including example code:** a page shows code with a fenced block whose first line is a comment naming its file, e.g. `// docs/guides/developers/examples/plugins/first-plugin/src/FirstPlugin.php`. The test checks the block's text appears verbatim in that file. No other code blocks longer than three lines.
- **`teme:docs:hooks`** writes `docs/reference/hooks.md`: one section per hook class found in `packages/teme-contracts/src` that implements `Notification`, `Transform`, `Gate`, `Resolve` or `Collect`: its name, verb, the capability from `#[HookContract(capability: …)]`, its class docblock's first paragraph, its constructor parameters with their `@param` text, and `@since`. Sorted by namespace then name. A hook without `@since` fails the command (it names the class).
- **`teme:docs:manifest`** writes `plugin-manifest.md` and `theme-manifest.md` from `packages/teme-contracts/schema/teme-plugin.schema.json` and `teme-theme.schema.json`: one row per key (path, type, required, description, allowed values).
- **`teme:docs:handbook-index`** writes `docs/guides/developers/llms.txt` in the llms.txt shape: a `# Teme developer handbook` heading, a one-paragraph summary, then one `- [title](relative path): summary` line per page, in the README's section order.
- **The examples:**
  - `first-plugin`: `composer.json` (name `teme-examples/first-plugin`, PSR-4 `TemeExamples\FirstPlugin\`), `teme-plugin.json` (`code: first-plugin`, `capabilities: ["content.read"]`, `hooks: ["ContentPublished"]`), `src/FirstPlugin.php` listening to `ContentPublished` and recording one line through a contract it is granted (match what `hello` can do today; no capability that wave 1 is still building).
  - `first-theme`: `teme-theme.json`, `views/templates/index.blade.php` and `single.blade.php`, using only the documented theme view data (`docs/reference/theme.md`).

## Tasks

### Task 1 — the hook reference, generated, with its drift check
- [ ] Tests first, `tests/Feature/Console/GenerateHooksReferenceTest.php`: the command lists every hook class (count equals the classes implementing a verb in the contracts source); a fixture hook without `@since` makes it fail naming the class; the committed `docs/reference/hooks.md` equals what the command writes now (the drift check). See them red.
- [ ] Build `GenerateHooksReference`; run it; commit the output.
- [ ] Quick check: `php artisan test --compact tests/Feature/Console/GenerateHooksReferenceTest.php`.

### Task 2 — the manifest references
- [ ] Tests first: each schema key appears once in its page; the committed pages equal the command's output. Red, then build `GenerateManifestReference`, run, commit.

### Task 3 — the example plugin and theme, tested
- [ ] Tests first, `tests/Feature/Guides/Developers/ExamplesTest.php`: with `teme.plugin_paths` pointing at `docs/guides/developers/examples/plugins`, the first plugin is found, passes manifest validation, enables, and its listener runs on a published item; with `teme.theme_paths` at `examples/themes`, the first theme passes `teme:theme:check` and draws the home and a single post. Red first.
- [ ] Write the two examples until green.

### Task 4 — the pages
- [ ] Tests first, `tests/Feature/Guides/Developers/DeveloperPagesTest.php`, mirroring `OperatorPagesTest`: every page the README lists exists and no other; front matter has title, type, audience, summary, rests_on (and lands_with on stubs); every `rests_on` path exists; links go to files and headings that exist; every command named exists with the options used; every class or hook named in backticks exists in the code; every code block over three lines names its file and its text is in that file; no internal labels (I-numbers, B-??) in what a reader sees; no images (until screenshots are generated).
- [ ] Write `README.md` and the pages in this order: `concepts/hooks.md`, `concepts/manifest.md`, `start/first-plugin.md`, `start/plugin-or-theme.md`, `start/first-theme.md`, `concepts/operations.md`, `testing.md`, `for-agents.md`, then the four stubs. Add the developers row to `docs/guides/README.md`.

### Task 5 — llms.txt
- [ ] Tests first: `llms.txt` lists every page once with its summary, in README order; the committed file equals the command's output. Red, build `GenerateHandbookIndex`, run, commit.

### Task 6 — records and the gate
- [ ] `composer check` once. Fill the Records below.

## Quick check

`php artisan test --compact tests/Feature/Guides/Developers tests/Feature/Console/GenerateHooksReferenceTest.php tests/Feature/Console/GenerateManifestReferenceTest.php tests/Feature/Console/GenerateHandbookIndexTest.php`

## Records

(The builder fills this: commits; each test seen red; B-?? decisions with options; hooks found without `@since` or docblocks; the changelog lines.)
