# Contributing to TemeCMS

Thanks for helping. This page says how to report a problem, ask for a feature and send a change.

## Reporting a bug

Open an issue with the **Bug report** form. One bug per report: each one is looked at and given a priority on its own. Say which version you run and the steps that make it happen.

A security problem never goes in an issue. Report it privately, as [`SECURITY.md`](SECURITY.md) describes.

## Asking for a feature

Open an issue with the **Feature request** form, and start with the problem rather than the solution. Teme's core holds what every site needs and the places plugins plug into; most other features belong in a plugin or a theme. If a plugin can't be built because Teme lacks a hook or a place to plug in, that missing piece is exactly the kind of request Teme wants.

## Sending a change

1. **For anything bigger than a small fix, open an issue first**, so the approach can be agreed before you write it.
2. **Set up** as the [readme](README.md#developing-teme) describes: PHP 8.4, Node 24, and Docker for PostgreSQL and Valkey.
3. **Read [`AGENTS.md`](AGENTS.md).** It holds the rules every change keeps. The ones most often missed:
   - every change to stored data goes through an Operation;
   - PostgreSQL only, in the tests too;
   - content is stored as editor JSON, never HTML;
   - write the failing test first.
4. **Keep each pull request to one thing.** Fill in the template: what changes, why, and how it was tested.
5. **Run `composer check`** before you push. It runs the formatter, static analysis, the type checks and the tests.
6. **Write commits as [Conventional Commits](https://www.conventionalcommits.org/)**: `fix(editor): …`, `feat(media): …`, `docs(guides): …`.

A check comments on issues and pull requests that are missing what the forms ask for. Fill in the missing parts and it clears itself.

## Licence

By sending a change you agree that it is released under the project's [MIT licence](LICENSE).
