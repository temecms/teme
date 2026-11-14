# Documentation and process

## The governing rule

> **Reference is generated. Guides are written.**

Hand-written reference documentation drifts within one release. WordPress's hook documentation is generated from source, and it is the most reliable part of its handbook precisely because nobody maintains it by hand.

**Generated from the registries:** hooks, field types, card types, settings keys, permissions, CLI commands, API endpoints, theme regions and template resolution order.

**Written by people:** concepts, tutorials, how-to guides, upgrade notes, architectural decisions.

### What this demands of phase 1

Every registry entry carries its documentation as data from the first registration: a description, an example where the shape is not obvious, and — on the published surface — a `since` version.

This is not decoration. Retrofitting documentation metadata onto registrations means revisiting every call site in the codebase, and the reference cannot be generated until it is done. Requiring it at registration costs one line; adding it later costs a migration of the whole codebase. See `docs/architecture/coding-standards.md`.

## Audiences

| Audience | What they get | When |
|---|---|---|
| **Contributors** | `AGENTS.md`, coding standards, `operations.md`, the spec and studies | Now |
| **Editors and writers** | Task-shaped user guide — write, publish, schedule, media, settings | Phase 1, minimal; grows with the product |
| **Site operators** | Install, configure, deploy, back up, restore, upgrade | Phase 1 for our own use; hardened at phase 6 |
| **API consumers** | Generated OpenAPI document plus a written authentication guide | Phase 3 |
| **Theme developers** | Theme handbook, plus generated template, region and settings reference | Phase 4 |
| **Extension developers** | Plugin handbook, plus generated hook, field and card reference | Phase 5 |

Documentation is phase-gated exactly like the code, and for the same reason: a plugin handbook published before the hook API has stopped moving is a promise we cannot keep.

## Structure

```
docs/
  ideas.md          every idea raised, with its status — the intake
  roadmap.md        phases, slices, status, change log — the tracker
  architecture/     living reference for contributors — how this codebase works
  studies/          evidence behind decisions; read before revisiting one
  superpowers/
    specs/          design documents, dated
    plans/          one plan per slice: <date>-phase<N>-slice<NN>-<name>.md
  guides/           written documentation, by audience (added as phases land)
  reference/        GENERATED — never edited by hand, never reviewed line by line
```

Anything under `reference/` that someone has hand-edited is a bug in the generator, not a fix to the document.

**The guides are tested against the code** (slice 1.13e and 1.13f; `tests/Feature/Guides`). Each page names, in its front matter, the screens and the source folders it rests on; the tests then hold every bold name to words those sources really say, every command and option to one Teme registers, every variable to one the configuration reads, every link to a file that exists, and every page to the audience's index. A page that names a button the admin no longer has fails the gate, so a change to the admin's words changes the guide in the same branch.

## Versioning

Semantic versioning, applied to a deliberately narrow public surface.

**Public** — covered by semver, breaking changes only in a major:

- `packages/teme-contracts`
- Documented hooks
- The HTTP API
- The theme contract — manifest shape, template resolution, view model shape
- Database contents as reachable through the export format

**Internal** — may change in any release: everything in `app/`, the schema itself, admin routes, and any class not exported through the contracts package.

The value of this split is that it is small. WordPress's inability to modernise comes from having promised, implicitly, that everything is public forever.

## Deprecation policy

1. Deprecated in a minor release, with `@deprecated` naming both the replacement and the version in which it will be removed.
2. It keeps working, unchanged, for at least one full minor release.
3. Removed only in a major, and listed in that major's upgrade guide.

A deprecation without a removal version is not a deprecation, it is a complaint.

## Changelog

`CHANGELOG.md`, Keep a Changelog format, **written by hand as part of the change**.

Not generated from commit messages. Commit messages are written for developers reading a diff; a changelog is written for someone deciding whether to upgrade. They are different documents with different audiences, and generating one from the other produces something useful to neither.

Every entry that changes public behaviour links to its upgrade note.

## Security

`SECURITY.md` exists before the first release outside our own infrastructure, carrying a disclosure address, the versions receiving security fixes, and a response-time commitment we can actually meet.

Security advisories are published separately from the changelog and carry the affected version range.

## Architectural decisions

The dated specs in `docs/superpowers/specs/` and the studies in `docs/studies/` serve the purpose ADRs usually serve, and they carry more evidence than an ADR template would.

When a settled decision changes, it is recorded as a **dated amendment** in the spec, never as a silent edit. The reasoning that produced the original decision is the most valuable thing in the document — deleting it means relitigating the same question in a year with none of the evidence.

## PHP coding standards and inline documentation

**Teme follows PSR-12 through Pint's Laravel preset, not WordPress's PHP coding standards.** Those conventions are coherent for a codebase that must stay consistent with its own history, and do not fit this one:

| WordPress convention | Why it isn't used here |
|---|---|
| Yoda conditions — `if ( true === $x )` | A guard against `=` / `==` typos that PHPStan catches properly, at the cost of every condition reading backwards |
| `snake_case` functions and variables | Conflicts with PSR-12 and with Laravel, which a hired developer already knows |
| Spaces inside parentheses — `if ( $x )` | Non-standard anywhere outside WordPress |
| Tabs, alignment-based array formatting | Pint's Laravel preset settles this; having an opinion here costs more than it returns |
| Prefix-based naming instead of namespaces | Namespaces make the prefixes unnecessary |

Adopting them would work against the requirement that a competent Laravel developer recognise this codebase immediately.

**We do adopt WordPress's inline documentation discipline for the public extension surface.** Their convention — every hook documented where it fires, carrying a description, `@since`, and a documented parameter per argument — is the reason their hook reference can be generated at all, and it is where our `@since` policy comes from.

We apply the same discipline through the registry: the description, example and `since` version live in the **registry entry as data**, not in a docblock that a parser has to recover from source. Same guarantee, no parsing step, and a missing description is a visible hole in a registration rather than an invisible gap in a generated page.

This is the guiding reframe applied to documentation: WordPress tells us *what* must be documented and *how rigorously*. It does not tell us how to write it down.

## Agent skills as a documentation format

Teme is an AI-native CMS, so a portion of its documentation should be executable by an agent rather than only readable by a person. Skills are how that is packaged.

EmDash demonstrates the pattern — alongside its handbook it ships skills for creating plugins, building a site, writing its docs, and converting a WordPress theme or plugin across. Those are not developer conveniences for the core team; they are product surface for the people building on the CMS.

Our position:

- **Not now.** There is no CMS to write skills against, and a skill written against an API that has not stopped moving is worse than none.
- **Phase 4 and 5**, alongside the theme and extension handbooks, a skill is a deliverable of the same task as the handbook it accompanies. A handbook explains; a skill executes.
- **Highest value first:** `wordpress-theme-to-teme` and `wordpress-plugin-to-teme`. Our entire positioning is replacing WordPress, and the largest cost of switching falls on whoever has to port the theme. A skill that does most of that work is worth more to adoption than another page of prose.
- Skills live in `skills/<name>/SKILL.md` at the repository root and are versioned and deprecated under the same policy as the API they describe. A skill is a promise about an interface exactly as a handbook page is.

### Three kinds of skill, three homes

| Kind | For | Lives in | Source |
|---|---|---|---|
| **Laravel skills** | Anyone writing Laravel code in this repo | Wherever Boost installs them | **Generated** by Boost for the exact package versions in `composer.lock`; refreshed by `boost:update` on every `composer update`. Committed, so fresh worktrees have them |
| **Contributor skills** | Agents building Teme itself | `.ai/skills/<name>/SKILL.md` | Written by us, installed by Boost alongside its own |
| **Product skills** | People building sites, themes and extensions *on* Teme | `skills/<name>/SKILL.md` | Written by us, versioned with the API they describe — phases 4 and 5 |

General-purpose skills — marketing, design, workflow — belong at user level (`~/.agents/`), not in this repository. Anything committed here ships with Teme.

**A distribution channel worth using in phases 4 and 5:** Boost loads guidelines and skills from any installed Composer package that ships them under `resources/boost/`. If `teme/contracts` ships Teme's product skills there, every Teme installation with Boost gets them automatically, version-matched to the Teme release it runs. No separate download, and no drift between the skill and the API it describes.

## Reusing other projects' documentation

**WordPress is the exception.** Its documentation policy places prose under CC0 and code snippets under GPLv2-or-later, covering `developer.wordpress.org`. That prose may be adapted directly, with examples rewritten against Teme's real APIs and screenshots replaced.

**Everything else is not reusable.** A project being open source does not place its *documentation* under a reuse licence; several have no documentation licence at all, which means ordinary copyright. Read Statamic, Craft, October, Ghost and Payload freely, learn from all of them, and write our own prose.

Check each project's licence before copying a sentence. This is cheap to get right now and expensive to unwind after a documentation site is published.
