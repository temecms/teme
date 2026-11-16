---
title: Your first theme
type: tutorial
audience: theme
summary: A theme with an index template and a single template, from the handbook example.
rests_on: [docs/guides/developers/examples/themes/first-theme, docs/reference/theme.md, docs/architecture/themes.md]
---

# Your first theme

This tutorial builds a theme from the handbook’s example: a manifest, an index template, and a single template. Core chooses which template wins; your theme only draws it.

## 1. Copy the example

Copy `docs/guides/developers/examples/themes/first-theme`. The folder name must match the manifest’s `code`.

```json
// docs/guides/developers/examples/themes/first-theme/teme-theme.json
{
    "manifest": 1,
    "code": "first-theme",
    "name": "First theme",
    "version": "1.0.0",
    "description": "The handbook's first theme: an index and a single template.",
    "requires": {
        "teme": "^0.8"
    }
}
```

## 2. The templates

Every theme needs `views/templates/index.blade.php`. A single item uses `single.blade.php` unless a more specific template wins. Use only the view data documented in the [theme reference](../../../reference/theme.md): `$site`, `$page`, `$isFront`, and the rest.

```blade
// docs/guides/developers/examples/themes/first-theme/views/templates/index.blade.php
<x-teme::document>
<main id="main" data-template="index" data-site-name="{{ $site->name }}" data-front="{{ $isFront ? 'yes' : 'no' }}"><h1>{{ $site->name }}</h1></main>
</x-teme::document>
```

```blade
// docs/guides/developers/examples/themes/first-theme/views/templates/single.blade.php
<x-teme::document>
<main id="main" data-template="single"><h1>{{ $page->title }}</h1></main>
</x-teme::document>
```

## 3. Check it

Point `teme.theme_paths` at the theme folder in local work, then:

```sh
php artisan teme:theme:check first-theme
```

The command fails when the manifest is wrong, a declared template file is missing, or the home page or a published item's page has no `<h1>` or more than one. Fix every error before you activate the theme in Design settings.

## 4. What you should see

On the site’s home address the index template draws. On a published post’s address the single template draws the title. A missing or broken theme never takes the site down: core’s own views draw it instead.

Next: [The manifest](../concepts/manifest.md).
