# Proposals

A proposal says what TemeCMS will build and why, before it is built: the problem, the decisions with their reasons, and what is in and out. Anyone can read one and comment on it.

## How a proposal moves

1. **Proposed.** Someone opens a pull request that adds one file here, from [`_template.md`](_template.md). The pull request is where it is discussed.
2. **Accepted.** The maintainer merges the pull request. Accepted means agreed, not scheduled: [the roadmap](../roadmap.md) says when.
3. **Implemented.** The work is built from a step-by-step plan in [`docs/plans/`](../plans/), written from the proposal. The pull request that finishes it sets the status and the release.
4. **Superseded** or **withdrawn.** A later proposal replaces it, or it is dropped. The file stays, with a line saying why.

A proposal that isn't accepted is closed with its pull request and never enters this folder.

Small fixes and changes that need no decision skip all this: an issue and a pull request are enough. When in doubt, open an issue first and ask.

## Names

One file per proposal, named after its topic in lowercase with hyphens: `media-focal-points.md`. No numbers and no dates in the name; the date is in the file's header.

## Once it is built

The proposal keeps the reasons. How the built thing works is described in [`docs/architecture/`](../architecture/), which becomes the reference from then on.

## Index

| Proposal | Status | Issue | Release |
|---|---|---|---|
| [One searchable list: the Combobox](combobox.md) | accepted | — | — |
| [Settings pages on the new design system](settings-pages.md) | accepted | — | — |
| [People and your account on the new design system](people-and-account.md) | accepted | — | — |
| [The sign-in pages on one card](sign-in-pages.md) | accepted | — | — |
| [The remaining admin screens on the new design system](remaining-screens.md) | accepted | — | — |
| [The content lists on the new design system](content-lists.md) | accepted | — | — |
| [The editor on the new design system](editor.md) | accepted | — | — |
| [The media library and the one picker](media.md) | accepted | — | — |
| [The Tools screens on the new design system](tools-screens.md) | accepted | — | — |
| [Plugins on the new design system: the Plugins screen, plugin areas and their marks](plugin-areas.md) | accepted | — | — |
| [Dialogs and menus on phones](phone-overlays.md) | accepted | — | — |
