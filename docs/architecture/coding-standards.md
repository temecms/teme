# Coding standards

Most of this is enforced by tooling. Run `composer check` before every commit.

| Concern | Enforced by |
|---|---|
| Formatting, import order, strict types | `pint.json` |
| Type correctness | PHPStan, level 6 |
| Layering — the Operation rule | `tests/Architecture/LayeringTest.php` |
| Purpose docblocks, and document paths cited in comments | `tests/Architecture/DocumentationTest.php` |
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

**Every class, interface, trait and enum in `app/` opens with a docblock of one to three sentences saying what it is for.** Every public method of a query class (`App\Domain\*\Queries\*`) has one sentence saying what it returns. `tests/Architecture/DocumentationTest.php` enforces both.

The reason is the readers. Teme is read by its maintainers, by coding agents that arrive with no context, and later by extension authors. A good name tells a developer what a class *is*. It does not tell a reader what the class is *for*, or where it sits in the whole.

What a purpose docblock says:

- **What the class is for, in plain words.** For example: *"Changes a person's role. Only someone who may manage users can do it, and it asks for a fresh password confirmation."*
- **For an Operation:** what it changes, who may call it, whether it needs elevation, and what it refuses.
- **For an Operation input:** what the change is, in one line. The fields speak for themselves.
- The constraint a reader cannot see, if there is one. `BaseOperation`'s docblock records that the order of its steps is load-bearing.

What it never says:

- **A restatement of the signature.** The *PHPDoc* rules above still apply.
- **A version or an author.** See *@since tags* below.
- **A step-by-step account of the method bodies.** That is the code's job, and a narration drifts the moment the code changes.

If the purpose will not fit in three sentences, the class probably does two things.

## @since tags

**Only in `packages/teme-contracts`. Nowhere else.** Decided on 2026-09-19 (I-134): file headers are not wanted. They exist in other systems because that code has no namespaces, no Composer and no history, and none of that is true here.

That package is the semver'd public surface extension authors code against, so "which version introduced this" is a question somebody will genuinely ask. Everywhere else, `@since` is a tag that drifts out of date and tells nobody anything — internal code changes constantly, and git already records when.

In `teme-contracts`, every public interface, method and constant carries `@since <version>` from the release that introduced it. The tag is never edited afterwards; that is the whole point of it.

`@deprecated` in that package must name the replacement and the version in which the old form disappears:

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
