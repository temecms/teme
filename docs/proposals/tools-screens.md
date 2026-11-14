# The Tools screens on the new design system

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/tools-screens.md |
| Release | — |
| Replaces | — |

## The problem

The Tools hub and Jobs moved onto the design system with the foundation. The screens behind the hub did not. Import, Backups, Page cache, Redirects, the 404 log, Updates and AI activity each still draw their own frame: their own padding and width, a heading from the old list header, bordered boxes for sections, hand-made notes, and badges where the design system has a status pill.

That costs people in small ways:

- **Every screen sits differently.** Some are 768 pixels wide, others run the full width, and headings and actions are spaced differently on each.
- **Steps look like plain text.** Import's five steps and Backups setup's three are a line of small numbered words. On Import, the plan step marks two steps as current at once.
- **Notes and states are hand-made.** Seven "note" boxes and a dozen badges each pick their own look.
- **Some controls are raw.** Radio buttons, text areas, a select and a fold-out are plain HTML, without the shared focus ring or touch sizes. Four boxes scroll with the browser's own scrollbar.
- **Two password prompts are hand-made**, in Backups, while the rest of the admin uses one shared prompt.
- **Most words can't be translated.** About 660 lines of words skip the translation helper.

The design check lists about 300 places on these screens as known debt.

## What we will build

Each screen keeps what it does, its addresses, its server code and its words. What changes is the frame and the parts.

**Import.** The sites list and the connect form sit on the list page pattern. A site's page shows its five steps (Connect, Analyze, Choose, Import, Report) on the shared step bar, with exactly one current step: Analyze while the plan is read, then Choose. Each section sits in a panel, with the shared notice and status pill.

**Backups.** The screen, setup, the SFTP step, a new recovery key and the key page each render one page pattern, and setup's three steps use the shared step bar. When a password check lapses, both prompts use the shared password prompt: over the key page, so the key stays on screen, and inside the key dialog, so nothing typed is lost. The place form, already on the form pattern, stays as it is.

**Page cache.** The "Now" facts sit in a panel with a status pill, and the note becomes a notice. Clearing stays one button in the header.

**Updates.** Each section sits in a panel. Older releases fold with the shared collapsible, the update's progress uses the shared progress bar, and security fixes are a status pill.

**Redirects and the 404 log.** The two views become the shared in-page navigation, with counts. The redirect form's radios, select and scrolling box become the shared parts.

**AI activity.** The month summary sits in a panel, the budget note becomes a notice, and a request's details keep their side sheet.

Every word on these screens passes through the translation helper. No design-check line names these screens when the work is done.

## The decisions

1. **Restyle, don't redesign.** No feature is added and none is removed. Routes, permissions, props, test hooks and words stay. A screen that works today works the same way after.
2. **One page pattern per screen.** Lists use the list pattern (full width). The single-column screens use the form pattern's 760-pixel column, the width they nearly have today. There is no separate pattern for a status screen, and adding one would widen the design check.
3. **The shared step bar for both tasks in steps.** The import keeps its five steps. "Analyze" and "Choose" share one server step, so the page picks one of the two by whether the plan has arrived.
4. **Tables stay on today's table.** The content-lists work is building the full table (pinned columns, phone cards, the column chooser) at the same time. These screens keep the existing table, pager, empty state and bulk bar as they are, and only stop using the old list header and tab strip. When the full table lands, these lists move onto it in a follow-up of their own.
5. **The shared password prompt.** It is the one prompt for a lapsed password check (design system 4.41). The two hand-made prompts in Backups go.
6. **Words move as they are.** A word that moves into the translation helper keeps its spelling. Any new word is in American English (I-3439).

## In and not in

**In:** Import (both pages and the step bar); Backups (the screen, setup, the SFTP step, a new key, the key page, and the components they draw); Page cache; Updates with its update progress; Redirects, the 404 log, the redirect form and the redirect import dialog; AI activity and its request details; every word on these screens through the translation helper; the design check's lines for these files, down to none.

**Not in:**
- **Tools › Status.** No such screen exists, and this work doesn't create it. The site-status control in the top bar keeps its panel. A status page needs its own proposal.
- **Jobs and the Tools hub,** done with the foundation.
- **What the reference design draws beyond today's product:** warming the page cache from the screen, clearing one address, the counts of saved pages, a confirmation before clearing, filter chips on the import report, rolling an update back from the screen, and figure tiles on the import's progress and report.
- **The full table** (decision 4), and phone cards for these lists.
- **Phone overlays** (dialogs as full-screen sheets, menus from the foot of the screen). These come with their own part, and these screens inherit them.
- **Any change to the server, to permissions, or to what an operation does.**

## How it fits

- **Components:** the existing page patterns (list and form), panel, notice, status pill, step bar, progress bar, collapsible, radio group, select, text area, field, scroll area, the shared password prompt, and in-page navigation. No token changes, no existing shared component changes, and no new dependency.
- **Server:** unchanged. Every action keeps its route, its permission check and its operation.
- **Accessibility:** one `h1` per screen; exactly one current step, announced as such; every field's label, help and error tied to it; nothing scrolls sideways at 375 pixels; every target at least 24 pixels, and 44 on touch for the shared controls; axe clean in light and dark.
- **Tests:** an architecture test that no design-check line or untranslated word remains on these screens; a browser test that each renders its pattern with one heading and nothing sideways on a phone; cases for the two step bars and the password prompt. Existing tests keep what they prove, changing a selector only where markup changes.

## Settled

1. Tools › Status is not built in this work. It waits for its own proposal.
2. The lists stay on today's table until the full table merges, then move in a follow-up.
3. Single-column Tools screens use the form pattern's 760-pixel column, and lists and a site's import page use the full-width list pattern. (provisional: confirm)
4. On an import's plan step, the step bar shows Analyze until the plan arrives, then Choose. (provisional: confirm)
5. Clearing the page cache stays one click with no confirmation. It changes no content, readers' visits redraw the pages, and it is recorded with the person's name. (provisional: confirm)
6. On the key page, the shared password prompt opens as a small dialog over the page. In the key dialog, it opens inside that dialog. (provisional: confirm)
