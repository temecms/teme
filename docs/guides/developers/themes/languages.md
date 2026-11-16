---
title: Languages in a theme
type: how-to
audience: theme
summary: What a multilingual site gives a theme, from the page's language and direction, its other language versions, the language switcher, and the theme's own words.
rests_on: [app/Domain/Site/LanguageComposer.php, app/Domain/Site/ViewModels, app/View/Components/Teme/Languages.php, app/Domain/Themes/ThemeWords.php, docs/reference/theme.md]
---

# Languages in a theme

A site can publish in several languages. Core works out which language each page is in, which other versions of it exist, and how readers move between them. A theme only draws what it is given, and words its own text through the translator.

## What core writes for you

`<x-teme::document>` writes `lang` and `dir` on the page's `<html>` element. Core also writes, in the head, an `hreflang` link for each language version of the page, itself included, with `x-default` pointing at the default language's version, and `og:locale` with an `og:locale:alternate` for each other version. The sitemaps, feeds and structured data carry the languages too. Your theme adds none of this.

## What every template gets

Three values join the ones every template already gets:

- `$language`, the page's language: its tag, such as `fr` or `pt-BR`, its PHP locale, its own name, such as Français, whether it is the site's default, and that language's home address. See [PageLanguageView](../../../reference/theme.md#pagelanguageview).
- `$direction`, `ltr`, or `rtl` for a language written right to left. Use it for any layout that must flip; the document's `dir` is already set.
- `$alternates`, every language version of this page a reader can open, this page included, the default language first. Each has its tag, its language's own name, its absolute address, and whether it is this page or in the default language. It is empty when the page has no other version. See [Alternate](../../../reference/theme.md#alternate).

On the home page and the first page of a type's archive, `$alternates` leaves out languages with nothing published.

## The language switcher

Readers find the other versions through a switcher, which can come from either place:

- **A menu item.** Editors can add a language switcher to any menu, beside links and headings. Your `<x-teme::navigation>` draws it with nothing more from you: it becomes one link per version, the current one marked, or, when the editor chose a dropdown, a heading that opens them. Its options say whether it is a dropdown, whether it shows the languages' names or short codes, and whether it leaves out the page's own language. On a page with no other version it draws nothing.
- **The component.** To place a switcher yourself, use `<x-teme::languages />`. It draws the same links as a plain list, with `lang` and `hreflang` on each link and `aria-current` on the page's own; style it as you like. `showNames` (true by default) chooses the languages' own names over short codes such as FR, and `hideCurrent` (false by default) leaves out the page's own language. See [the languages component](../../../reference/theme.md#x-temelanguages).

Menus and regions can differ by language as well. Editors choose a menu for each slot in each language, and write a region's content in each language; a language with none of its own shows the default language's. Your theme names the slot and the region once.

## The theme's words

Every word your templates print goes through `__()`, so a reader sees it in the page's language: `{{ __('Next') }}`, never the bare word. Dates and numbers follow the page's locale too.

Put the translations in your theme's own `lang` folder, one JSON file per locale named as the locale is, such as `lang/fr.json` or `lang/pt_BR.json`, mapping each English text to its translation. Core reads the active theme's files after its own, only on the public site, so your file may also word one of core's texts your own way. A text with no translation shows in English.

Core ships its own reader words, and the default theme's, in Spanish, French, Brazilian Portuguese, German, Italian, Hindi and Japanese. The default theme's `lang` folder is the model to copy.

Next: [Your first theme](../start/first-theme.md) and the generated [Theme view data](../../../reference/theme.md).
