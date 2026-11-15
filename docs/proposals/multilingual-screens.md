# Proposal: the multilingual admin screens (v1, part ML-3)

**Status:** settled 2026-11-15. Decisions: I-3446, I-3449 (the pictures approved). Pictures: approved by the maintainers (five screens; Settings › Fediverse belongs to the fediverse part). Based on how other multilingual systems lay these screens out.

## What it is

The admin screens a site owner needs to run a site in several languages: the site's languages, a post's translations in the editor, languages in the content lists, menus per language, and a setting's words in other languages. ML-1 (`i18n/readers`) gives readers the languages; this part gives the people who write them a way to manage them. Every write goes through Operations that already exist on `main` (6.1a) or that ML-1 adds; this part adds one.

## Settled

1. **One place per job, one row per thing.** Every screen follows the reference systems' common pattern: a language list lives in one place; each language is one row; each row has one status and one worded action; rare actions sit behind "⋯". No icon-only rows.
2. **Settings › Languages** is one table. The default language is the first row, marked **Default**, with no Edit or "⋯". Each other row: language (with its own name under it), its address prefix (`/ig/…`), **Readers** (Shown or Hidden), what content it has, **Edit**, and "⋯" (Move up, Move down, Remove). Add a language under the table. The order is the order the language switcher lists. Below it, **Translated content**: which types and taxonomies have versions per language (`ChangeTranslatedTypes`).
3. **The editor's Translations section** sits in the right-hand rail **directly under Publish** (picture tab 2, option A). Every language is one row, this post included ("Editing · source"). Other rows: one status pill (Published, Draft, Scheduled), with "· out of date" inside it, in the warning tone, when the source changed since; one worded action, **Open**, or **Translate** when there is no version. "⋯" holds **Mark up to date** (only when out of date), **Link a different post…** and **Unlink…**. The section's summary line reads like "3 of 4 · 1 out of date". A small language chip (**EN**) by the status in the top bar opens a menu that only switches between versions.
4. **Posts and other translated lists:** each row shows one mark per site language (EN IG HA FR), in the same order on every row, on the line under the title: a filled mark for a version that exists, an outlined one for a missing one, a warning mark for out of date. Not a separate column by default (a column pushed the table into sideways scrolling at 1440); a **Languages** column is available in the column chooser. A **Language** filter sits with the other filters.
5. **Menus:** a menu's language, **For readers of**, sits inside its **Show in** card, beside the places, because a menu belongs to a place per language. The **language switcher** is an ordinary item kind in the item picker (ML-1 builds the kind).
6. **Settings › General** and any settings group with words: an **In other languages** table under the words, one row per language and every language visible at once; an empty field shows the default language's words in grey as its placeholder, meaning "falls back to the default".
7. **Every write is an Operation** with its "writes nothing when refused" test: `AddSiteLanguage`, `ChangeSiteLanguage`, `RemoveSiteLanguage`, `ChangeTranslatedTypes`, `TranslateContent`, `LinkTranslations`, `UnlinkTranslation`, `MarkTranslationUpToDate` (all on `main`), ML-1's word-settings writer, and one new: `ReorderSiteLanguages`.
8. **Words** through `__()`, American English. Language names in their own script come from `config/languages.php`.

## Provisional (built as written unless the maintainers change them)

- P1. **Open** goes to the version's editor in the same tab (the browser's back returns); the chip's menu does the same.
- P2. **Translate** asks once in a confirmation ("Make the Igbo draft") and then opens the new draft.
- P3. On phones the Translations section stays in the rail sheet the editor already uses; the language marks wrap under the title.

## Not in this part

Settings › Fediverse (the fediverse actors part); machine translation; translating the admin itself; side-by-side translation editing.
