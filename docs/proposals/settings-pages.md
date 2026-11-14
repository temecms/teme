# Settings pages on the new design system

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/settings-pages.md |
| Release | — |
| Replaces | — |

## The problem

Every Settings page is built on its own frame today. Some have two Save buttons (General has one for the form and one for Brand colour; Reading has one for the form and one for Home page). A note on the page explains which Save saves what. Fields, panels and the save bar use the old look, and a note on People is a hand-made box.

The foundation part gives us one `SettingsPage`, one `SaveBar` with a leave guard, `Field`, `Switch`, `RadioGroup`, `Textarea` and `Notice`. This proposal moves every Settings page onto them, so a person learns one page and finds the same page everywhere.

## What we will build

**General, Reading, Writing, Media, People, Addresses, Discussion, Site code, Search and social, AI.** Each page becomes a `SettingsPage`: a header, then sections, each a panel with a title, a one-line description and its fields. "Find a setting" stays at the top and highlights what it finds. One save bar serves the whole page. It appears on the first change, names what is unsaved, and asks before the person leaves. Nothing else on the page saves.

- **General and Reading** lose their second Save. Brand colour and Home page become ordinary sections of the page form and are saved with it. A bad value stops the save and is named next to its field. The brand colour is the site's identity colour, separate from a theme's accent (I-3438, temecms/teme#261): it sits with the site's logo and icon, and no longer replaces the theme's accent.
- **People** keeps "a password confirmation is asked first". The hand-made note becomes a `Notice`, and the confirmation is the shared fresh-password prompt, asked once and remembered for 15 minutes.
- **Site code** and **AI** keep their own lists and dialogs (a code entry, an AI key). Those dialogs and sheets have their own footers, which is the one other place Save may live. A page with nothing to save shows no save bar.
- **Search and social** is the largest page. It keeps its sections and previews and gains the one save bar. Fields with a live preview keep it.
- **Languages** stays the placeholder it is today, inside the new frame, and says what is coming.

**On a phone** the form is one column with labels above and 16px input text. The save bar is pinned to the foot, above the keyboard, with a message, Discard and Save. The list of settings groups and "Find a setting" sit at the top; the groups open from one button.

## The decisions

1. **One save bar per page.** Alternatives: a Save per panel (clear about scope, but easy to miss one and lose a change); one bar with a "saves these sections" note (what we do now, and it needs explaining). One bar removes the note. The two server routes for Brand colour and Home page stay, and the page sends them together with the group so a failure is reported as one result.
2. **Keep the side list of settings groups** (settled with the design system's open points). The design system's general rule is no second side menu; settings are the exception, as the demo and today's page draw. Alternatives: the crumb switcher alone (fewer pixels, but it hides sections, "Soon" tags and plugin marks); keep the list. We keep the list on wide screens and ask the maintainers to confirm.
3. **The save bar's look.** The design system draws a footer bar; the demo draws a floating dark bar. We build one component, so the look can change in one file.
4. **No new settings behaviour.** The pages are reframed, not redesigned. A setting that does not exist today is not added because the demo draws it.

**The save bar looks as the demo draws it** (settled with the design system's open points): a floating bar that appears once something changes.

## In, and not in

**In:** the ten pages above on `SettingsPage`; one save bar and leave guard each; merging the second Save on General and Reading; notices as `Notice`; the fresh-password prompt on People; phone layouts.

**Not in** (drawn in the demo, not done by the product yet):
- **Management API** settings (tokens, "using a token"): the demo marks it Soon; no page or route exists.
- **Languages** form (default language, other languages, translated content): a later release.
- **People's pictures** (initials or Gravatar) on Settings › People: it needs the picture work in the people-and-account proposal first.
- Plugin settings pages beyond what plugins already declare: plugin surfaces are a separate part.
- Sections the demo draws for plugins that are not installed.

## How it fits

- **Patterns used:** `SettingsPage`, `SaveBar` with the leave guard, `FormPanel`, `Field`, `Switch`, `RadioGroup`, `Textarea`, `Notice`, `FreshPassword`.
- **Files:** `pages/settings/**`, `components/fields/**`, `layouts/settings/**`.
- **Tests that change:** every browser test that presses "Save {page} settings" uses the one save bar's label (settings controls, placements and panels; Discussion, Site code, AI, Search and social, Who publishes). Tests that assert "Brand colour has its own Save" go with the behaviour.
- **Depends on:** the foundation part only. It can start as soon as that merges.
- **Server work:** merging the extra Saves means one controller path handles the page and its added section. No new tables.

## Settled

The questions this proposal raised, as decided:

1. Brand colour and Home page save with their page's one save bar; a failed colour check shows next to its field.
2. Plugin sections on a core settings page share the page's save bar.
