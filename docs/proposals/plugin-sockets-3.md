# Proposal: plugin sockets, round 3

**Status:** draft (2026-11-16). **Decides:** the sockets for what an outside developer porting a WordPress AI-writing plugin (Writura) to Teme from the handbook alone could not build, after rounds 1 and 2: AI through the site's one connection, a plugin's panel in the editor, reading content, slugs, translations and bylines through the write door, the person a job acts for, hooks a plugin declares for other plugins, and notices from plugins (Writura's `FINDINGS.md` W-01 to W-16, F-03, F-10; `FEATURES.md` 2.2, 2.8, 4.5, 4.11 to 4.14, 5.4 to 5.10, 6.3, 6.4, 7; issue #121). **Plan:** `docs/plans/plugin-sockets-3.md`. **Branches:** `sockets3/plugin-ai` (A), `sockets3/editor-panels` (B), `sockets3/content-doors` (C), `sockets3/hooks-notices` (D).

## What it is

- **A. Plugins use the site's AI connection.** A plugin declares its AI tasks in its manifest; the administrator agrees to them at consent. The plugin asks through a door (`SiteAi`) for the person its work acts for; core sends it through the model chosen for that task in Settings › AI, with the fallback once, and records every request under the plugin (figures only, never the words). Each plugin can be switched off, given its own monthly budget and hourly limit, and its spending is shown on Settings › AI, Tools › AI activity and the Plugins screen ("Writura: $4.20 this month"). Requests inside a long job may take up to 10 minutes.
- **B. A plugin's panel in the editor.** A plugin declares up to two panels; core draws each as a tool in the editor's bar (Tools from plugins, pinnable) that opens in the rail's pane, marked as the plugin's. A panel shows a status line, the plugin's work on this item with its progress, and suggestions the person uses or discards: a title, a field's value, terms, an internal link on a phrase, or a whole article shown as a proposal with a word diff. A panel's actions (a small declared form and a button) queue the plugin's job on the current draft, as the person. No plugin JavaScript runs.
- **C. Content doors and the acting person.** A `ContentReader` lists published, public items with titles and addresses a page at a time, and reads a draft for a person who may edit it. The write door can set a slug, credit people other than the actor (within the actor's rights), and link language versions. A running job says whom it acts for. Answers inside long jobs may be 16 MB. A plugin setting can be a person. The jobs page states every limit.
- **D. Hooks between plugins, dependencies, notices.** A plugin declares its own `Notification`, `Transform` and `Collect` hooks, sends them through a door, and another plugin that requires it listens (a free plugin and its paid add-on). Plugins can require other plugins; a required plugin can't be disabled while one that needs it is enabled. Plugins send notices of kinds they declare, by email and to the inbox, to the person their work acts for or to the holders of a permission; people choose in Account › Notifications.

## Why

I-3396: a wall an outside developer hits is a missing socket, fixed in core. The Writura port works by squeezing: it asks for its own Anthropic key while core holds one with a budget (W-04), writes through Message Batches because a call can last only 10 seconds and answer 1 MB (W-01, W-02), keeps its own pool of posts because nothing lists the archive (W-06), starts work from a draft titled `writura: …` because there is no editor panel (W-11, W-14), asks for an account ID as text to "write as" (W-15), can't set a slug, a byline or a translation link (W-07, W-08), can't offer its filters to a paid add-on (W-09), and can't tell anyone a job failed (W-05). Issue #121 already lists what a shared AI connection must offer; I-3434 adopted it.

## Settled

1. **One AI connection, used by plugins through core.** Capability `ai`; tasks declared in `contributes.aiTasks` (id, label, description, `needs`, `sends`, a token ceiling), listed in the consent; an added task or a wider `sends` waits for a new yes. A plugin never receives a provider key; it asks `SiteAi::ask()` for a declared task, for the person its work acts for, and core does the rest.
2. **Core fences, records and counts.** The plugin's inputs are fenced as core's own (`AiPrompt::messages()`, the fence rule). Each request is recorded in `ai_activity` under the plugin, with the person, task, model, tokens, cost and outcome, never the prompt or the answer (I-318); its cost adds to the site's month and the plugin's month in one transaction. A request refused before sending costs nothing and is not recorded.
3. **Per task models; per plugin switch, budget and hourly limit.** Settings › AI gains a Plugins section: each plugin's tasks as model and fallback rows (marked with the plugin, as v10 already draws one), and per plugin an on switch (on after consent), a monthly budget (none, or at most the site's), and requests an hour (default 120). A task with no model is off, as core's are; the plugin can ask `standing()` why, in words, to say so on its own pages.
4. **Long AI requests.** Up to 55 seconds anywhere, up to 600 inside a long job (whose worker already allows 1,800). No streaming reaches a plugin.
5. **Editor panels are declared and drawn by core** (I-3467, I-234): up to 2 a plugin, for named content types, shown to people who may edit the item and hold the panel's permission when it names one; a tool in the bar within the existing 3 pins; the rail's one pane with the plugin's mark. `view()` answers within 250 ms or the panel says it couldn't be shown.
6. **Panel actions are Operations that queue the plugin's job as the person,** once while one waits (round 1's `QueueOptions`), with the item, its revision and the form's values. While the job runs, the pane shows its progress.
7. **Suggestions core can check and apply:** title, a scalar field, terms, an internal link on a phrase (stored as `teme:content/{id}`, rule 9), a whole document as a proposal. Each is checked against the item before it is shown; using one is a single undoable change in the editor, saved when the person saves; the plugin hears whether it was used or discarded, inside core's transaction.
8. **A content reader.** `published()` lists only what an anonymous visitor can open, newest first, by cursor, up to 200 a page, filtered by type, language, terms and date; addresses are made when read. `draft()` gives the working revision (body, fields by handle, terms, slug, credits, translations) only for a person who may edit the item. `ContentSaved` stays small; a listener reads the draft.
9. **Slug, credits and translations through the write door,** each through core's own Operation (`UpdateContentDetails`, `SetBylines`, `LinkTranslations`) inside the same transaction as the create or change; any refusal writes nothing. A byline other than the actor needs the actor's own right to credit others.
10. **The person a job acts for** is a method on the job's context (`personId()`): the person who queued it, or for a schedule the administrator who enabled the plugin (round 1). Never the operator.
11. **Limits written down:** 75 s a run (1,800 long), 10 s an outbound call (300 long), 1 MB an answer (16 MB long), 64 KB a payload, 55 s an AI request (600 long), one minute the smallest schedule.
12. **A plugin's own hooks,** `Notification`, `Transform` or `Collect`, classes in the plugin's own namespace with `#[HookContract]`, named `{code}:{Name}`, sent through `HookSender`; listeners must require the declaring plugin. Faults, order, failure rules and after-commit delivery as for core's hooks.
13. **Dependencies.** `requires.plugins` with Composer constraints; enable refused until each is enabled at a matching version; disable refused while a dependent is enabled; an update that breaks a constraint puts the dependent in a fault state, unheard, until fixed.
14. **Notices from plugins** through the existing `Notices` contract, with capability `notices.send`: up to 5 declared kinds (never required), audiences `starter` (only the person the work acts for), `plugins.manage` or a plugin permission; email and inbox; at most 100 deliveries a plugin an hour; links only to the plugin's settings, its area or an item's editor; people choose in Account › Notifications.
15. **Contracts follow the contracts rule.** `@since` the next release, nothing from `App\` or `Illuminate\`, value classes grow only at the end; `ContentWriter` and `JobContext` grow only while 0.9.0 is untagged, else through `TranslationLinker` and `JobPerson`. Every write is an Operation with a test that a refusal writes nothing. Every word through `__()`, in American English.
16. **Screens are drawn and shown before they are built.** The builders STOP before A-T6, B-T5 and D-T6 until the maintainers approve the drawings below.

## Provisional (built as written unless the maintainers say otherwise)

- P1. The numbers: 8 AI tasks and 120 requests an hour per plugin by default; 2 editor panels, 3 actions, 4 fields an action, 20 suggestions; a 5-second refresh for at most 15 minutes; 200 items a page; 16 MB long answers; 10 declared hooks; 5 required plugins; 5 notice kinds and 100 deliveries an hour.
- P2. The words quoted in the plan, among them the `ai` and `notices.send` consent sentences, `A request longer than 55 seconds runs only inside a long job.`, `This panel couldn’t be shown this time.`, `:dependents needs it. Disable :dependents first.`, `A plugin sends notices only to people with an account.`
- P3. Keys made from a plugin's code begin `plugin_` (`plugin_writura_article_model`, `plugin_writura.job_failed`), because a code may start with a digit or hold hyphens.
- P4. Route paths `admin/content/{content}/panels/{code}/{panel}…`.

## Open questions for the maintainers (clearly open)

1. **A plugin's own provider key.** The brief proposes letting an administrator give one plugin its own key, off by default, for separation on the provider's side; I-3434 says no plugin ships its own key. Recommended: allowed, off by default, set on Settings › AI › Plugins by an administrator, **stored in core's `ai` group and never readable by the plugin** (not as the plugin's own secret, which `SecretReader` would hand it); requests still go through core, are recorded and count against the budgets; only the key sent differs. The alternative (no own key at all) is simpler and keeps I-3434 as written; the brief's variant (the plugin's own secret) lets a plugin bypass the record, and is not recommended.
2. **Which hourly limit a plugin's requests count against.** Recommended: the plugin's own (default 120), not the person's 60, because a scheduled run of 50 articles acts for one administrator. The alternative counts them against the person, which stops bulk work.
3. **What happens at a budget** (issue #121 lists three choices). Recommended for this round: the site's budget stops everything (as today); a plugin's budget stops only that plugin. "Warn and carry on to a hard ceiling" is later.
4. **Message Batches and provider tools.** Writura used Anthropic's batches (half price) and its web search tool. Recommended: later, each through core (`SiteAi::batch()` and declared provider tools) when a second plugin needs them; this round's long requests remove the need to batch for time.
5. **A plugin writing the whole article.** Recommended: both ways allowed, as the panel's design shows: a suggestion the person uses (`body`, shown with a word diff), or a new revision written in the background through `ContentWriter::change()` (the editor's handling of a draft changed elsewhere applies; B's Task 0 confirms how it tells the person). The alternative allows only suggestions while the item is open.
6. **Plugin hook verbs.** Recommended: `Notification`, `Transform`, `Collect` now; `Gate`, `Veto` and `Resolve` (which let one plugin stop another's work) later, with a case.
7. **Disabling a plugin others need.** Recommended: refused, naming the dependents (Settled 13). The alternative disables the dependents with it, after a confirmation that lists them; quicker, but switches off things the person didn't name.
8. **The focus keyword (W-12).** Recommended: not core's. I-3413 gives the focus keyphrase to the SEO plugin; Writura hands its keyword to the SEO plugin through that plugin's own declared hook (D), or a field the SEO plugin declares when plugin fields exist.
9. **Ship order.** Recommended: A and C now (they wait for nothing), D now for its first four tasks, B after round 1's `sockets/plugin-admin` and round 2's `sockets2/plugin-area`; all screens after the drawings; all in the release after rounds 1 and 2 if 0.9.0 is untagged by then.

## The screens to draw (shown to the maintainers before they are built)

Each extends v10's own screens, never a fresh design.

1. **Settings › AI, Plugins section** (from `docs/demos/v10/settings-ai.html`): per plugin, its name and mark, the on switch, monthly budget, requests an hour, this month's cost and requests; its tasks' model and fallback rows; a task with no model ("off"); the separate-key choice if open question 1 is taken.
2. **Tools › AI activity** (from `tools-ai-activity.html`): the Plugin filter, the plugin's name in each row, this month's totals by plugin.
3. **The Plugins screen and the consent dialog** (from `plugins.html`): the row's AI line; the consent's AI tasks with what each sends, editor panels, notice kinds, required plugins and provided hooks; a disable refused because another plugin needs it.
4. **The editor panel** (from `editor.html`'s Tools from plugins and Postlinkly's pane): the bar with a pinned plugin tool; the Tools menu; the pane with the plugin's mark; the status line; an action's form, confirmation and button; a job running with its progress; suggestions (a title, a field, a link) with Use and Discard; a whole article as a proposal with the word diff; "This panel couldn't be shown this time."; AI off for the plugin; phone width.
5. **Account › Notifications** (from `account-notifications.html`): a plugin's kinds under its name with its mark; and one inbox item from a plugin (from `inbox.html`).

## Writura's walls, and where each is met

| Wall | What Writura needed | Met by |
|---|---|---|
| W-01 | A streamed article call of up to 900 s | **this round** (A: AI requests up to 600 s in a long job, through core); already today: 300 s outbound in a long job; generic outbound streaming **later** |
| W-02 | Batch results of ~15 MB | **this round** (C5: 16 MB answers in a long job; A removes the need to batch for time); batches through core **later** (open question 4) |
| W-03 | Admin screens, forms, action buttons | **round 1** (actions, lists on the settings page) and **round 2** (a plugin's own area: pages, tables, forms); the title picker and "write now" from the editor **this round** (B) |
| W-04 | Using the site's AI key, budget and limits | **this round** (A) |
| W-05 | "A job needs you" and "a job failed" notices | **this round** (D3) |
| W-06 | Listing published items with titles and addresses | **this round** (C1 `published()`) |
| W-07 | A slug in `NewItem`/`ItemChanges` | **this round** (C2); a file name for `MediaWriter::fromAddress()` **later** |
| W-08 | Linking language versions; a byline other than the actor | **this round** (C2, C3) |
| W-09 | Hooks a plugin declares for others | **this round** (D1, D2) |
| W-10 | Plugin permissions; per-user job visibility | **round 2** (A1 permissions); a person's tracked jobs: **today** (the header's jobs button); a plugin's own records per person **this round** (C8) |
| W-11 | An editor panel | **this round** (B) |
| W-12 | A focus-keyword field | **later/plugin**: the SEO plugin's (I-3413, open question 8) |
| W-13 | The site's address | **round 1** (`SiteInfo`) |
| W-14 | Starting work from the editor with the draft's content | **this round** (B's actions; C1's `draft()`) |
| W-15 | A person shared between a schedule and a hook | **round 1** (B5 `--as`: schedules run as the enabling administrator) and **this round** (C4 `personId()`, C6 `person` setting) |
| W-16 | A stated per-run budget | **round 2** (C3: when a schedule is due) and **this round** (C7: every limit in one table) |

Writura's handbook findings (F-01 to F-13) that are not walls belong to the handbook pass, except F-03 (`Notices` refused: D3) and F-10 (`person` setting: C6). F-08's `teme/testing` package of fakes and F-12's way to try a write door without the admin are worth their own plan; they are listed under Later.

## References checked

Teme's `Reference/` folder holds 40 systems; these bear on this proposal (paths relative to `Reference/`):

- **WordPress 7** (`wordpress-develop-trunk/src/wp-includes/ai-client.php`, `ai-client/class-wp-ai-client-prompt-builder.php` (filters `wp_ai_client_prevent_prompt`, `wp_ai_client_default_request_timeout`), `connectors.php`, `abilities-api.php`): one site-wide AI client and connector registry that plugins call instead of shipping keys; a site switch (`WP_AI_SUPPORT`). Taken: one connection, plugins never hold keys. Not there, and added here: per-plugin records, budgets and limits.
- **WordPress hooks and notices** (`src/wp-includes/plugin.php:175` `apply_filters`, `:492` `do_action`; `src/wp-admin/admin-header.php:344` `admin_notices`; `src/wp-includes/functions.php:9377` `wp_admin_notice`): any plugin may fire and hear any hook by string name; notices are HTML a plugin prints. Taken: plugins publishing hooks for others. Not taken: unnamed, undeclared string hooks, and plugin HTML.
- **WordPress block editor plugin sidebars** (`PluginSidebar`, `PluginDocumentSettingPanel`; Gutenberg is not in the local copy, see Missing): a plugin's own React panel in the editor, pinnable in the top bar. Taken: pinned plugin panels in the bar (I-234). Not taken: plugin React code (I-3467).
- **Emdash** (`emdash/packages/blocks/src/types.ts` lines 472 to 536, `ContentEditorPanelInteraction`, `EditorDraftSnapshot`, `EditorDraftPatchEffect`; `emdash/packages/plugin-types/src/capability-descriptions.ts` `admin.editor-draft:read`, `admin.editor-draft:patch` "Propose changes to selected editor fields for you to review"; `manifest-schema.ts:149` `dependencies`): the closest model. A plugin's editor panel is declared blocks; it reads a snapshot of the unsaved draft and returns a patch the person reviews; capabilities gate both; plugins declare dependencies. Taken: panels as data, suggestions as typed patches the person applies, dependencies in the manifest.
- **Strapi** (`strapi-develop/packages/core/content-manager/admin/src/components/InjectionZone.tsx`: `editView.informations`, `editView.right-links`): named injection zones in the edit view filled by plugin components. Taken: fixed places, core-owned. Not taken: plugin components.
- **Payload** (`payload-main/packages/payload/src/collections/config/types.ts:442` `admin.components.edit`): custom components in the edit view. Not taken (plugin code in the admin).
- **Craft CMS 5** (`Craft CMS 5/cms-5.x/src/base/Plugin.php:50` `EVENT_BEFORE_SAVE_SETTINGS`, `trigger()`): plugins define their own events as constants any other plugin can handle (Yii events). Taken: a plugin's own named events for others.
- **Statamic** (`Statamic CMS/src/Providers/AddonServiceProvider.php:41` `$listen`, `$subscribe`; `src/Events/Event.php`; `src/CP/Navigation/Nav.php`): addons fire and hear Laravel events, their own included. Taken: hook classes owned by the add-on that declares them.
- **Drupal** (`drupal-main/core/lib/Drupal/Core/Extension/ModuleHandler.php:327` `invokeAll`, `:376` `alter`): any module defines hooks (`hook_*`, documented in its `*.api.php`) that other modules implement; dependencies in `.info.yml`. Taken: a module's own hooks, documented; dependencies refuse uninstall while needed.
- **pursae** (`pursae-main/docs/developer/plugins/hooks.md`, `admin-ui.md` (meta boxes with declared fields), `host-api.md:42` `QueryPosts` with `posts:read`, `limits-and-sandbox.md` (8 s, 1 MiB outbound)): a host API that lists posts behind a permission; meta boxes declared as fields; outbound limits like Teme's. Taken: a content-listing door behind `content.read`. Not taken: plugin HTML in boxes.
- **laravel/ai 1.3** (`vendor/laravel/ai/src/Contracts/Providers/TextProvider.php` `stream()`, `src/PendingResponses/*::timeout()`, README): the library under core's providers; streaming and per-request timeouts exist, used inside core's provider classes only (I-314).
- Checked and not relevant here: Ghost, Joomla, Wagtail, TypiCMS, Pubvana, webblocks, phprs, basehim, laradashboard, the artisanpack packages, acorn and sage, millicache, Sharp, Filament, Twill, October, Winter, Capell, the multilingual and SEO plugins (Polylang, MultilingualPress, TranslatePress, Rank Math, Yoast; read only for the focus keyphrase's owner, I-3413), react-starter-kit, emdash-exporter.
- **Missing from the folder:** Gutenberg's `@wordpress/edit-post` and `@wordpress/plugins` packages (`PluginSidebar` is known from WordPress's public handbook, outside the folder and labelled so here); Anthropic's Message Batches documentation (open question 4, not needed for this round's decisions).

## Not in this round (Later, each with what brings it back)

1. **AI batches and provider tools** (web search) through core. Back with a second plugin that needs them.
2. **Images from AI** (`images-out`) through core. Back with a provider that core ships for it; Writura keeps fal.ai through `OutboundHttp`.
3. **Declared places inside core boxes** (a suggestion beside a field, a row in a core tab, I-3418). Back with the SEO plugin.
4. **Outbound streaming** for any service. Back with a plugin whose service only streams.
5. **`Gate`, `Veto` and `Resolve` hooks from plugins.** Back with a case where one plugin must stop another's work.
6. **"What may be sent" as a site setting** that refuses a plugin task by its `sends` (issue #121). Back when the privacy screens are next extended.
7. **A file name for media fetched by a plugin.** Back with the next media-write change.
8. **A `teme/testing` package of fakes, and a command to try a write door without the admin** (F-08, F-12). Back with the handbook's testing page.

## Decided (the maintainers, 2026-11-16)

All nine open questions as recommended: (1) a plugin's own provider key allowed, off by default, stored in core's `ai` group where the plugin can't read it; (2) each plugin's own hourly limit, default 120; (3) the site budget stops everything, a plugin budget stops only that plugin; (4) Message Batches and provider tools later; (5) a plugin may write a whole article as a suggestion (word diff) or a new revision; (6) plugin hook verbs Notification, Transform, Collect now, Gate/Veto/Resolve later; (7) disabling a plugin others need is refused, naming the dependents; (8) the focus keyword stays with the SEO plugin (I-3413); (9) A and C now, D's first four tasks now, B after round 1's `sockets/plugin-admin` and round 2's `sockets2/plugin-area`; every screen after its drawing is approved.

Added by the maintainers: D-L1, a plugin may set attributes on an article's outbound links (Part D).
