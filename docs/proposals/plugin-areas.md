# Plugins on the new design system: the Plugins screen, plugin areas and their marks

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/plugin-areas.md |
| Release | — |
| Replaces | — |

## The problem

The Plugins screen has its old layout in the new colors: a hand-built table and tab row, badges where the design system uses status pills, and no picture of the plugin. The design system gives every plugin its own icon (section 4.47, I-3405); the component is built, but no screen uses it.

What a plugin adds to the admin is not marked. Its links in the Tools hub sit among Teme's own cards, and its pages under Tools and Settings in the menu look like Teme's. The design system asks for a plug mark on everything a plugin adds to a shared screen (section 4.40).

The site status panel shows a pill and a sentence per problem, but not what the problem is about. Its row is meant to start with a label: "Backups", or the plugin's name with its mark (section 4.33).

And the page pattern for a plugin's own area, `PluginAreaPage` (section 5), part of the kit plugins will build with (section 6.2), does not exist.

## What we will build

**The Plugins screen** on the list pattern: the four views (All, Enabled, Disabled, Needs attention) with their counts as the shared Tabs; one row per plugin in a panel, with its icon at 32px, its name and version, its description, who made it, its package name, its state as a status pill, the reason when there is one, and the row's one action (Enable, Review access, or Disable) beside its "…" menu. On a phone each row stacks.

**The consent and details dialogs** show the plugin's icon at 56px beside the title. What they say, and how enabling works (the password screen and back, I-3342), does not change.

**Plugin entries with their mark.** In the Tools hub, plugins' links move under "From plugins", each card with its plugin's mark. In the menu, a plugin's page under Tools or Settings shows a small plug after its name, with the plugin named for screen readers.

**Labelled status rows.** Each site status row starts with what it is about ("Backups", "Updates", "Scheduled tasks"), then its pill, its sentence and its action. A plugin's row is labelled with the plugin's name and carries its mark. The phone's status sheet shows the same rows.

**`PluginAreaPage`**: the plugin's icon at 64px, the area's title with the plugin's mark, one line, the actions, then the area's pages as in-page navigation (five shown, the rest under More). What the plugin draws inside sits in an error boundary that shows one sentence naming the plugin, with "Try again", while the rest of the admin keeps working. The design catalogue gains a section showing it, a failing area included.

## The decisions

1. **The design system's icon sizes:** 32 in the Plugins rows, 56 in a plugin's dialogs, 64 at the head of its area; 96 waits for a plugin's detail page.
2. **Initials until plugins can ship a picture.** The plugin contract has no icon field yet, so every plugin shows its initials on a neutral square, never the accent. When the socket exists, the same component shows the picture.
3. **The rows are a list in a panel, not a table.** A row holds a description, a reason and actions; columns add nothing for a few dozen plugins, and a list stacks naturally on a phone.
4. **The views are Tabs**: they filter one page without changing its address, the design system's meaning for Tabs (section 4.27).
5. **The state is a status pill in Teme's words**, its tone following the state: ok when enabled, neutral when disabled, warn when waiting or partly stopped, danger when stopped or unusable. The word is always there.
6. **Every status row has a label.** Teme's checks each name their area. A plugin's check is labelled with the plugin's name, which Teme already knows, so plugins need no new field.
7. **A plugin's links and settings page carry the plugin's name from the server**, so the menu, the hub and later the command palette mark them the same way.
8. **`PluginAreaPage` is built ahead of plugins' own pages**, as part of the kit, and proved in the catalogue until a plugin can register an area.
9. **No new operation, permission or route.** Enabling and disabling keep their operations, checks and password confirmation; nothing stored changes, and nothing shows to anyone who cannot see it today.

## In and not in

**In:** the Plugins screen with icons, status pills and Tabs; the icon in both dialogs; the plugin mark on plugins' Tools cards and menu pages; labels on every site status row; `PluginAreaPage` with its error boundary and catalogue section; every new word in American English through the translation helper.

**Not in**, each waiting for a socket the plugin contract (`teme/contracts`) does not have:
- **A plugin's own picture**: an `icon` field in the manifest, and an install step that sanitizes or rasterizes an SVG and serves it from core (I-3405).
- **A plugin's own area as a real screen**: a manifest surface for admin pages, and the way a plugin ships its own interface code (I-212).
- **A plugin's top-level menu entry**: a menu surface with its section, order, badge and the site-wide cap (I-216, I-220).
- **A plugin's own label on a status row**, for a plugin with several checks, and a row action other than "open its settings".

**Not in, for other reasons:** bulk actions on plugins (enabling several needs one consent dialog listing each plugin's access, and one password confirmation; filed for later); install requests, a directory, update notices and removal requests, which come with the installer; the plug mark in the command palette, which comes when the palette is rebuilt; the icon on a plugin's settings page, which belongs to the settings layout; phone sheets for the dialogs, which come with the shared overlay change.

## How it fits

- **Components:** `patterns/plugin-area-page.tsx` (new); the Plugins page and its dialogs rebuilt; one catalogue section. It uses the existing PluginIcon, PluginMark, Tabs, StatusPill, Panel, ErrorState, InPageNav, PageHeader and ListPage; no token or existing shared component changes.
- **Server:** each status check names its area; the shared banner gains its label and, for a plugin's, the plugin's name; a plugin's links and settings page carry its name. No migration.
- **Accessibility:** the icon is decorative and the name beside it is the label; the menu's plug is named in hidden text; Tabs keep the arrow keys; the error boundary's sentence is an alert; focus returns to the button that opened a dialog; axe clean in light and dark at desktop and phone widths, every target at least 24px.
- **Tests:** browser tests for the Plugins screen, the Tools hub's plugin cards and the menu's mark, the labelled status rows, and `PluginAreaPage` in the catalogue; feature tests for the shared labels and plugin names, including that nothing shows to someone without plugin access. The design-system and admin-words baselines only shrink.
- **Dependencies:** none.

## Settled

The questions this proposal raised, as decided:

1. The plugin rows are a list in a panel, not a table. (provisional: confirm)
2. The four views stay Tabs on one address rather than in-page navigation with an address each. (provisional: confirm)
3. Teme's own status checks are labelled by area: Backups, Updates, Scheduled tasks, Background jobs, Theme, Navigation, Page cache, Plugins, Search, Types, Taxonomies, Languages, Search and social, Management API. (provisional: confirm)
4. Bulk actions on plugins wait for their own part. (provisional: confirm)
