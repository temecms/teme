# Import: what the fresh site reports asked for (v1, wave 1, part C)

**Status:** proposal, 2026-11-15. **Plan:** [`docs/plans/import-v1-fixes.md`](../plans/import-v1-fixes.md). **Issue:** #305.

## Why

Five fresh site reports (2026-11-15) were read against the import. Most of what they hold the import already does: links and images by ID (I-1702), Yoast and Rank Math FAQ blocks as headings and paragraphs, tables of contents dropped (the theme draws its own), Canvas blocks flattened, WPBakery and tagDiv rows unwrapped, brackets that aren't shortcodes kept as text, an active plugin's shortcode output kept as Imported content (I-1704). Two gaps are left.

1. **A site's other old addresses.** One site has 143 links to its staging host (`stage.<site>`). The import treats only the home host (with or without `www.`) as the old site, so those links stay as links to a host that no longer serves it.
2. **Two tagDiv families the import doesn't know.** One site's posts use `tdm_*` and `tdc_zone` from the tagDiv theme, beside the `td_*` and `tdb_*` the import already reads. Today their brackets would stay in the text.

## Settled

1. **Other addresses are a property of the site, kept on its import source** (`import_sources.other_hosts`, a list of host names). They apply to every run of that site, full or catch-up. Teme compares them as it compares the home host: lower case, without `www.`.
2. **A link or image on another address converts exactly as one on the home host:** a link becomes a link by ID to what the import made of it, a file in the uploads becomes the library's file. Never the old address (rule 9).
3. **Teme suggests, the person decides.** From the companion's analysis, the import offers each linked host that is a subdomain of the home's domain (for example `stage.example.com` for `example.com`) with its link count. Nothing is added until someone ticks it. Another domain the site once used is typed in by hand.
4. **Set before a run, on the site's import page,** through one Operation that requires the importer's permission and the password, like starting a run. At most 10 hosts; the home host itself is refused.
5. **The run report counts what each other address gave:** links and images converted per host.
6. **`tdm_` and `tdc_` join the builder families** (`ShortcodePass::FAMILIES`), read as the existing tagDiv rows are.
7. **Real markup proves it:** a fixture of anonymized samples taken from the reports' markup examples (FAQ blocks, tables of contents, Canvas blocks, WPBakery and tagDiv rows, bracketed words such as `[State]` and `[mg]`, an app-store shortcode), each with the outcome the import must give, run in one test.

## Not in this part

Polylang import (its own part), importing custom types (after v1: the plugin that needs them stays on WordPress for now), and a rewrite of links that point at a *different* site (those stay outside links).
