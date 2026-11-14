# Governance

TemeCMS is an open-source project published under the MIT licence by Krafty Sprouts Media LLC.

## Who decides

The project has one maintainer, who makes the final decision on what TemeCMS includes, accepts proposals, merges pull requests and makes releases. Decisions are made in the open: on issues, on pull requests, and in [proposals](docs/proposals/README.md), each with its reasons written down.

## How decisions are made

- **What belongs in TemeCMS itself.** The core holds what every site needs, and the places plugins and themes plug into. A feature that only some sites need belongs in a plugin. When a plugin can't be built because the core lacks a hook or a place to plug in, adding that piece is core work.
- **Bigger changes** start as a proposal and are built only once it is accepted.
- **Small fixes** need only an issue and a pull request.
- **Security reports** are handled privately, as [`SECURITY.md`](SECURITY.md) describes.

## Releases

Releases are tagged from `main`, follow the version scheme in [the roadmap](docs/roadmap.md), and are listed in [the changelog](CHANGELOG.md). Each release publishes the Docker image.

## Changes to this document

As the project grows, more maintainers may be added. Any change to how decisions are made is published here first.
