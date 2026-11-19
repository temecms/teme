# Coding standards

Most of this is enforced by tooling. Run `composer check` before every commit.

| Concern | Enforced by |
|---|---|
| Formatting, import order, strict types | `pint.json` |
| Type correctness | PHPStan, level 6 |
| Layering — the Operation rule | `tests/Architecture/LayeringTest.php` |
| Purpose docblocks, and document paths cited in comments | `tests/Architecture/DocumentationTest.php` |
| File headers, and no `@since next` at a release tag | `tests/Architecture/FileHeadersTest.php`, and the image workflow's `since` job |
| Behaviour | Pest |

What follows is only the part tools cannot check. Anything that *can* be moved from this document into a tool should be.

## PHPDoc

**Write a docblock only where PHP's type system cannot express the type.**

Required:

- Array shapes and collection contents — `@param list<string>`, `@return Collection<int, Content>`, `@param array<string, mixed> $fields`
- Generics — `@extends Factory<Content>`
- `@throws`, for exceptions a caller is expected to handle

Forbidden:

- Anything restating the signature. `@param string $name` above `f(string $name)` is noise, and it drifts the moment the signature changes. Pint's `no_superfluous_phpdoc_tags` deletes these automatically.
- `@author` — git knows.
- `@var` on a property that already declares its type.

### Every class says what it is for (I-133)

**Every class, interface, trait and enum in `app/` says what it is for in one to three sentences.** In a file that holds one class, those sentences are the file's header (see *File headers* below); the class's own docblock keeps only what follows them, such as its tags. Every public method of a query class (`App\Domain\*\Queries\*`) has one sentence saying what it returns. `tests/Architecture/DocumentationTest.php` enforces both.

The reason is the readers. Teme is read by its maintainers, by coding agents that arrive with no context, and later by extension authors. A good name tells a developer what a class *is*. It does not tell a reader what the class is *for*, or where it sits in the whole.

What a purpose docblock says:

- **What the class is for, in plain words.** For example: *"Changes a person's role. Only someone who may manage users can do it, and it asks for a fresh password confirmation."*
- **For an Operation:** what it changes, who may call it, whether it needs elevation, and what it refuses.
- **For an Operation input:** what the change is, in one line. The fields speak for themselves.
- The constraint a reader cannot see, if there is one. `BaseOperation`'s docblock records that the order of its steps is load-bearing.

What it never says:

- **A restatement of the signature.** The *PHPDoc* rules above still apply.
- **An author.** Git knows. The version is the header's `@since`; see *File headers* below.
- **A step-by-step account of the method bodies.** That is the code's job, and a narration drifts the moment the code changes.

If the purpose will not fit in three sentences, the class probably does two things.

## File headers

**Every PHP file in `app/`, `config/`, `database/`, `routes/`, `packages/teme-contracts/src` and `packages/teme-testing/src` opens with the same short header**, and so does every file in a first-party plugin's or theme's `src/`. Tests, generated files and vendor files have none. `tests/Architecture/FileHeadersTest.php` enforces it.

```php
<?php

/**
 * Changes a person's role. Only someone who may manage users can do it, and
 * it asks for a fresh password confirmation.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);
```

- **The sentences say what the file is for**, one to three of them. In a file that holds one class they are the class's purpose, moved up from its docblock rather than said twice; in a file with no class, such as a config file, a route file or a migration, they say what the file holds.
- **`@package`** is `Teme` for Teme's own files. The namespace already says where in Teme a file lives, so there is no `@subpackage`.
- **`@since`** is the first release that contained the file, and it is never edited afterwards.
- **`@license`** is `MIT`.
- Nothing else: no `@author`, no year, no `@copyright`. Git knows who and when; the licence file holds the copyright.

The tags come in that order, the header sits between `<?php` and `declare(strict_types=1);`, and the test reads exactly this shape.

In `packages/teme-contracts/src` the class docblock keeps its whole description, because editors and the generated contracts and hooks references read it; the header carries its first sentence, and the class's own `@since` with it.

**Plugins and themes use the same header**, with their own `@package` (the Composer package name, such as `teme/seo`), their own version in `@since`, and their own licence. They may put `@since` on every public member, with change notes, as the maintainers' own plugins do.

### Adding headers

`php scripts/file-headers.php apply` gives every covered file that has none its header. It reads `@since` from git: the earliest release tag whose tree held the file, following renames. A file no release holds yet gets `@since next`. It moves the class docblock's first paragraph into the header, and names a migration from its file name. A file it cannot describe, such as a new config file, it lists, for a header written by hand. `php scripts/file-headers.php check` lists files whose header is missing or out of order.

## @since tags

**`@since` says which release a thing arrived in, and a change note says which release changed it.** Git records every commit, but a reader of an extension's code, or of Teme's, wants the release, and that is what git does not say on its face.

- **New files and new public members write `@since next`**, and a change to a public signature or behaviour adds a line under the original: `@since next Accepts a list of channels.` The original `@since` is never edited.
- **The release step replaces `next`.** When a release is prepared, `php scripts/file-headers.php release 1.2.0` writes the version into every `@since next`, in the same commit as the changelog's section. A release tag never holds `@since next`: the image workflow's `since` job checks it before anything is published, and `FileHeadersTest` checks it when the tests run on a tag.

In **`teme-contracts`**, the semver'd public surface extension authors code against, every public class, interface, method, constant and hook carries `@since <version>` from the release that introduced it, and a change note for each release that changed its signature or behaviour:

```php
/**
 * @since 0.5.0
 * @since 1.0.0 Allows 300 seconds and 16 MB for an answer inside a long job.
 */
```

**Elsewhere in `app/`**, a public method or constant added after its class's own release carries `@since` too. Change notes are not required there.

`@deprecated` in `teme-contracts` must name the replacement and the version in which the old form disappears:

```php
/**
 * @deprecated 2.3.0 Use Teme\Contracts\Content\Publisher::publish().
 *                   Removed in 3.0.0.
 */
```

A deprecation without a removal version is not a deprecation, it is a complaint.

## Comments

Comments explain **why**, never **what**. The code says what.

- A comment explaining what the code does is a signal to rename something, not to add a comment.
- Record non-obvious constraints, and cite the source — a PostgreSQL behaviour, a WordPress quirk, a spec section. These are the valuable comments, and they are the reason the next person does not "simplify" a guard back into a bug.
- **A comment may cite a repository document by path**, such as `docs/architecture/settings.md`, or an idea by number, such as `I-102`. `tests/Architecture/DocumentationTest.php` fails if a cited `docs/` or `.ai/` path does not exist, so a moved document cannot leave a dead pointer (I-132). When you move a document, fix its citations in the same commit.
- **Cite only what lasts, and sparingly** (I-797). Use at most one reference per comment, and only where the *why* isn't obvious from the sentence itself. **Never in code:**
  - plan-local decision tags ("1.8d's D3");
  - slice labels ("1.8c′");
  - prototype function names ("v9's settingsForm");
  - design-pass labels ("C4");
  - a study's letter without its path. Write `docs/studies/comments.md §3.4`, not "study S §3.4".

  These belong in plans, the roadmap, the changelog and git, where their history makes sense. In code they read as clutter to anyone arriving cold. A decision a plan made is cited by the I-number it was recorded as. The sweep of older code is I-798.
- No commented-out code. Git has it.
- **Deferred work is marked, not built** (I-169). When a parked idea will plug in at a particular place, leave one comment there, `// Later (I-NNN): what arrives here, and why not yet.`, citing the idea's row in `docs/ideas.md`. The row is the record; the comment is the signpost. Never leave placeholder code, an empty method or a stub for it.

Good:

```php
// withTrashed() is deliberate: the unique index does not know about soft
// deletes, so ignoring trashed rows produces a constraint violation
// rather than a second slug.
```

Noise:

```php
// Loop through the revisions
foreach ($revisions as $revision) {
```

## Conventions tools do not check

- `final` by default on classes. Open one for extension only when something actually extends it.
- `readonly` on DTOs and value objects.
- One class per file, named for the file.
- Operations are imperatives: `CreateDraft`, `PublishRevision` — never `DraftCreator` or `PublishingService`.
- Test names read as sentences: `it('refuses a save based on a revision that is no longer current')`.
- Migrations never edit a previous migration. Schema changes are additive; see the expand/contract rule in the spec. **A comment-only edit is allowed:** it changes no schema, and a migration that has already run never runs again. The first case was removing a vendor stub's pointer to MySQL-only advice (slice 1.2e).

## Registry entries carry their own documentation

Every registration — a hook, a field type, a card type, a settings key, a CLI command — carries a description and, on the public surface, a `since` version, as data rather than as prose in a separate file.

This is not a style preference. The public reference documentation is **generated** from these registries (see `docs/architecture/documentation.md`). A registration without its description is a hole in the published documentation, and it is cheaper to require the description at registration than to reconcile a hand-written reference later. Hand-written reference documentation drifts; generated reference documentation cannot.
