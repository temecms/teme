# Proposal: multilingual for readers (v1, part ML-1)

**Status:** decided 2026-11-15 (I-3446). **Plan:** `docs/plans/multilingual-readers.md`. **Builds on:** multilingual's foundation, 6.1a, on `main` (`docs/plans/multilingual-first-release.md`).

## What it gives a site

A site with several languages shows each reader the page in its language at its own address, tells search engines about the other versions, and lets a theme draw a language switcher. Teme's own reader-facing words appear in the page's language.

## Settled

1. **The model stays as built (I-3360):** each translation is its own item in a translation group. Terms the same. Menus gain a `language`. Settings that are words (site title, tagline, title templates) are stored per language in `settings.translations`, with the default language's value as the fallback.
2. **Addresses stay as built:** other languages under a `/xx/` prefix, the default with none. Never chosen by cookie or the browser's language. The page cache key is unchanged: the prefix keeps languages apart.
3. **What themes receive:** `$language`, `$direction` and `$alternates` (every shown version of this page a reader can open, this page included). On the home page and a type archive's first page, only languages that have something published. `[]` when there is only the page itself.
4. **The switcher (approved 2026-11-15):** core gives the data (this page's other languages and their addresses) and **a "language switcher" menu item kind** (`languages`) that expands into one link per alternate, plus `<x-teme::languages>` for themes that place it themselves. **How it looks is the theme's.**
5. **Head:** `<html lang dir>` from the page's language, written by core only. `hreflang` for every alternate when there are two or more, **plus `x-default` on every page of a group** (pointing at the default-language version). Never on `noindex` pages. `og:locale` and `og:locale:alternate`.
6. **Sitemaps, feeds, the read API:** every shown language's items, with alternates in the sitemap; `/xx/feed/` per language; `?language=` and `translations` in the read API, lists still default-language unless asked.
7. **Reader words:** Teme's own reader-facing words go through `__()` in the page's locale. Files ship for English, Spanish, French, Brazilian Portuguese and German, **plus Italian, Hindi and Japanese**, translated by AI and checked, marked as AI-checked in the changelog. **Laravel's own framework messages come from the maintained `laravel-lang/lang` package** (a development dependency; its files are published into `lang/` and committed), not translated by hand (I-3448). Dates and numbers come from PHP's intl and Carbon, so they need no files.
8. **Japanese:** added to `config/languages.php` with PostgreSQL's `simple` text search. Proper Japanese search (an extension) is later.

## Not in this part

The Polylang import (ML-2, `docs/plans/polylang-import.md`); the admin screens (ML-3, after their pictures are approved); machine translation; a domain per language; shared fields; per-language permissions; the admin in other languages.
