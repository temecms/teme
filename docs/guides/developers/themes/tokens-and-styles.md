---
title: Tokens, styles and fonts
type: how-to
audience: theme
summary: How a theme declares core tokens, its own custom tokens, named style variations, fonts with face and unicode_range, and how the site brand colour becomes --teme-brand.
rests_on: [app/View/Components/Teme/Document.php, app/Domain/Themes/Manifest.php, app/Domain/Themes/ManifestValidator.php, app/Domain/Design/SiteDesign.php, packages/teme-contracts/schema/teme-theme.schema.json, docs/reference/theme-manifest.md, docs/architecture/themes.md, themes/default]
---

# Tokens, styles and fonts

The document shell writes your theme's colours, type and faces into every public page. This page covers the manifest keys that drive that, and the site's brand colour.

## Core tokens

Under `tokens` and `tokens_dark`, name only the core list: `base`, `contrast`, `accent`, `on-accent`, `muted`, `rule`, `font-body`, `font-heading`, `font-mono`, `step--1` to `step-5`, `space-1` to `space-8`. Each value is CSS with no `; { } <` or `>`. `base` and `accent` are hex colours. Core writes each as `--teme-<name>` on `:root` (and on the dark scheme for `tokens_dark`).

Do not set `brand` in the manifest: it is read-only. Core writes `--teme-brand` from the site's `design.brand` setting.

## Custom tokens

`custom_tokens` and `custom_tokens_dark` hold names beyond that list. Names are lower-case letters, digits and hyphens, starting with a letter, and must not equal a core token. They are written as `--teme-<name>` after the core tokens in each scheme.

## Styles

`styles` maps each look's name to a `label` and optional `tokens`, `tokens_dark`, `custom_tokens`, and `fonts` (one declared font file to preload). Core adds a **Style** select on Settings › Design (fieldset `site`), defaulting to the first style.

A chosen style **overlays** the base: `[...base, ...style]`. A style that sets only `accent` keeps the theme's other tokens and custom tokens. Document and design previews follow the chosen style's preload when it names a font file.

## Fonts

`fonts` is a list of `{family, file, weight?, style?, preload?, face?, unicode_range?}`. Each `file` is relative to the theme's `public/` folder and must exist there. At most one font may set `preload` true.

- **`face`** defaults to `true`. When true, core writes an `@font-face` with a quoted `src` url and a `format()` from the file extension (`woff2`, `woff`, `truetype`, `opentype`). When `false`, core still preloads if asked, but writes no face — use this when your stylesheet already declares the faces (the default theme does).
- **`unicode_range`** is optional. When core writes a face, it adds `unicode-range` with that value.

Leave `fonts` out entirely if you never want core to preload or write faces.

## Brand colour and `use_brand_colour`

The site's identity colour is `design.brand` on Settings › General. Core always writes it as `--teme-brand` (and the browser `theme-color` meta) when set. Themes keep their own `accent` unless they opt in.

To offer a switch that uses the brand as the theme accent, declare a boolean setting with the **exact** key `use_brand_colour`. Core reads that name only; any other key is ignored for this purpose. When it is on and a brand is set, Document replaces `accent` and `on-accent` from the brand (lightened for the dark scheme). See [The theme manifest](../concepts/manifest.md) and `docs/architecture/themes.md`.
