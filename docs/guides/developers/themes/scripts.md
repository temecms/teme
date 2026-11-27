---
title: Scripts in a theme under the page policy
type: how-to
audience: theme
summary: What a theme's JavaScript may do under Teme's content security policy, the compressed script budget, and how the default theme stays small.
rests_on: [app/Console/Commands/CheckTheme.php, app/Domain/Site/Policy/PagePolicy.php, app/Domain/Themes/ThemeScripts.php, docs/guides/developers/rules/plugins-and-themes.md, themes/default]
---

# Scripts in a theme under the page policy

Teme's public pages send a content security policy that keeps a theme's scripts on a short leash. Build for that policy, not for a loose browser default.

## What the policy allows

On a public page, `script-src` is `'self'`, the asset host when `ASSET_URL` points elsewhere, and hashes for the small inline scripts core itself writes (the signed-in session marker, comments, regions). There is no `unsafe-eval` and no `unsafe-inline` for scripts. A theme therefore:

- Loads its built JavaScript as a file from its assets (`'self'` or that asset host), never as an inline `<script>` block in a Blade view (`php artisan teme:theme:check` refuses those).
- Cannot use a library that evaluates strings as code. Alpine's normal build needs `unsafe-eval`; only its [CSP build](https://alpinejs.dev/advanced/csp) works here, and that build cannot evaluate expressions such as `!x`, ternaries or object literals in attributes—keep those in your script file.
- Must not expect `eval`, `new Function`, or similar.

## The compressed script budget

`php artisan teme:theme:check` measures each built JavaScript file gzipped at level 9 and warns when the theme's scripts together go past **20 KB** compressed. That warning never blocks activation or the check's exit code; it is there so a theme author notices weight before readers do.

Aim lower when you can. The default theme's target is about **5 KB** compressed of its own script, loaded only where a page needs it. Prefer one small file for progressive enhancement over shipping a full framework on every page.

## Load a script only where it is needed

Declare the script in the theme's asset entries and let `<x-teme::document>` write the tags for the active theme. Do not paste a `<script src>` into every template. If only a handful of templates need behaviour (a search form, a gallery), gate it with a dynamic `import()` or a check in the script itself so idle pages pay almost nothing — not a separate entry per template.

## Checking your work

```bash
php artisan teme:theme:check your-theme
```

The report lists each built file's compressed size, the CSS and JavaScript totals against their budgets (15 KB CSS, 20 KB JavaScript), and any warning when a total is over. Errors (a missing build, an inline script, a broken manifest) fail the check; an over-budget warning does not.
