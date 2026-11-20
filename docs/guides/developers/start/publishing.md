---
title: Listing your plugin or theme in Teme's directory
type: how-to
audience: both
summary: How a plugin or theme gets into Teme's free directory, so every Teme site can find and install it from Add plugin or Add theme.
rests_on: [docs/guides/developers/rules/plugins-and-themes.md, docs/guides/developers/start/first-plugin.md, docs/guides/operators/extensions.md, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Listing your plugin or theme in Teme's directory

Teme's directory is free. A package listed there appears on temecms.com and in every Teme site's **Plugins › Add plugin** and **Themes › Add theme**, and installs from there. Your code stays in your own GitHub repository: the directory only holds a small file that points at it, and keeps a checked copy of each release's checksum and pictures.

The directory's own rules, every check it runs and the exact fields are in its repository, [temecms/directory](https://github.com/temecms/directory). This page is the short path.

## Before you list

- The package is in a **public GitHub repository**, as a Composer package of type `teme-plugin` or `teme-theme`.
- Its manifest says which Teme versions it works with, in `requires.teme`, and a plugin lists every capability it asks for. A plugin that codes against Teme's contracts requires `teme/contracts` in `composer.json`. Never require `teme/teme`: a site's own Teme provides it.
- `README.md` at the root describes the package honestly; it becomes the package's Overview. A `CHANGELOG.md` with one heading per version becomes its Changelog.
- The manifest may name an `icon` and up to eight `screenshots`, which the directory shows. See [The manifest](../concepts/manifest.md).
- A **release is tagged** that Composer reads as a version, such as `v1.0.0`.

## List it

Open a pull request on [temecms/directory](https://github.com/temecms/directory) that adds one file, `listings/<vendor>/<name>.json`, named after your package's Composer name, with your repository's address, `plugin` or `theme`, and its categories. The Submit page on temecms.com opens the same pull request for you.

The directory's checks run on your newest release and comment on the pull request: the manifest, the README, the licence and pictures, an install on a fresh Teme of each version you claim, and your own tests if you ship them. Fix what they report by pushing to the pull request or tagging a new release. Your first listing is reviewed by a maintainer; once the pull request is merged, the package is listed.

## After it is listed

Tag a release and the directory picks it up on its own, runs the same checks and lists it. A release that asks for more than the listed one did, a new capability or a new host to reach, waits for a maintainer before sites are offered it, as does a release that fails a check. Never change a release after tagging it: the directory records each release's checksum once.

To take a package out of the directory, open a pull request that deletes its listing file.

Next: [Rules for plugins and themes](../rules/plugins-and-themes.md).
