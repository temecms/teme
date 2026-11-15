# Proposal: importing a Polylang site (v1, part ML-2)

**Status:** decided 2026-11-15 (I-3446). **Plan:** `docs/plans/polylang-import.md`. **Builds on:** multilingual's foundation (6.1a, on `main`), the import, and ML-1's first commit (menus carry a language).

## What it gives a site

A WordPress site that uses Polylang moves to Teme with every language, every post and term in its language, at the address it had, and with its translations linked, its menus per language (switcher included) and its translated site title and tagline.

## Settled

1. **Language is set during the import, not linked afterward.** The site's languages are added first, before any content. Each item and term is created in its language at its old address. Translation groups are joined at the end.
2. **The default language comes from Polylang's own default (`default_lang`), not WordPress's locale.** A site whose WordPress locale differs from its Polylang default keeps its real default, so its unprefixed addresses stay unprefixed.
3. **Each language's address prefix is its Polylang slug** (for example Polylang's `pt` stays `/pt/` even when its locale is Brazilian Portuguese). Slugs are kept exactly as they were, including Polylang's `-xx` suffixes on terms. Each item's old address becomes its real address and answers directly, not through a redirect.
4. **Every imported item and term gets its language, whether or not it is linked to a translation.** Sites often have many posts in a language with no sibling.
5. **Groups are joined by union:** a map that lost a member joins rather than splits. A group with two items in one language, or of mixed types, is skipped and reported, never half-linked.
6. **Menus come over per language,** read from Polylang's own menu settings (not the theme's), and each Polylang switcher item becomes a `languages` menu item; options the item kind lacks are dropped and reported.
7. **String translations:** the site title, tagline and title templates become that language's word settings. Other plugins' and the theme's strings are reported, not imported.
8. **Unsupported, said plainly in the analyze step:** a language per subdomain or domain (addresses become `/xx/`, with redirects from the old hosts); language-from-content mode (the analyze step stops); MultilingualPress and TranslatePress.
9. **Not imported, by design:** flags (a flag is a country, not a language), browser-language redirects, the language cookie, Polylang's copied values (each post arrives with its own), media translations (off on the sites seen).
10. **The companion sends what Teme needs as JSON** (the language list, the default, the URL options, translated types and taxonomies, menu assignments, string translations). Teme never unserializes PHP (rule 4).

## Not in this part

What readers see (ML-1); the admin screens (ML-3); other multilingual plugins.
