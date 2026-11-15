---
title: Plugin or theme?
type: concept
audience: both
summary: Which package you need — a plugin that adds behavior, or a theme that draws the public site.
rests_on: [docs/architecture/plugins.md, docs/architecture/themes.md, docs/guides/developers/examples]
---

# Plugin or theme?

Teme splits “add behavior” from “draw the public site”. Pick the package that matches the job.

## A plugin

A plugin is a Composer package of type `teme-plugin` with a `teme-plugin.json` manifest. It listens to hooks, asks for capabilities, and may add settings or Tools entries. It never draws the site’s layout.

Build a plugin when you need to react to publishing, send data elsewhere, or add an admin surface. Start with [Your first plugin](first-plugin.md).

## A theme

A theme is a package (or folder) with a `teme-theme.json` manifest and Blade templates. Core decides what a page is; the theme decides how it looks. Themes carry no business logic: if a theme needs a feature, it declares plugins under `requires`.

Build a theme when you need the public site’s look. Start with [Your first theme](first-theme.md).

## Two short examples

The handbook ships both:

- `docs/guides/developers/examples/plugins/first-plugin` — listens for `ContentPublished` and records one line through the settings reader.
- `docs/guides/developers/examples/themes/first-theme` — an index template and a single template using documented view data.

Copy the example that matches your job, then change the copy.
