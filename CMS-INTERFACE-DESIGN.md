# Modern CMS — Interface Design

**Status:** Proposed design for review  
**Prepared for:** Krafty Sprouts  
**Date:** 15 September 2026  
**Deliverable:** Interface specification with text wireframes; no running prototype yet.

## 1. Product direction

A complete, independently installable CMS that replaces WordPress. It owns articles, pages, media, content types, themes, plugins, permissions, publishing, and the public website. AI is available throughout those operations.

The product should feel familiar to an experienced WordPress user while offering a calmer, more consistent interface. Its first-run experience ends with a working website and a default theme.

This document defines one interface for two possible implementations:

- **Laravel:** PHP + Laravel + PostgreSQL + React/TypeScript administration, with Blade theme rendering.
- **Node.js:** TypeScript + Node.js + Fastify + PostgreSQL + React administration, with an included server-side theme renderer.

The CMS engine does not determine the visual design. Backend selection changes implementation responsibilities, not where users find Posts, Media, or Settings. Payload is not assumed in the Node.js option.

### Working assumptions

- Initial development is by the founder working through prompts with AI coding agents; there is no existing engineering team.
- Later contributors may be hired. Framework conventions, straightforward onboarding, and affordable maintenance are explicit selection criteria; no developer-rate advantage is assumed without actual hiring evidence.
- One publication per installation for the initial release.
- The interface uses “Your publication” until a site name is configured; a final product name can be applied later.
- Articles are the primary workflow, with pages and custom content types supported by the same core editor.
- The default installation runs on a VPS or managed hosting service.
- AI suggestions require review before they change published content. Explicitly configured automation policies can grant narrower publishing permissions later.
- The first release uses English, while layouts and components accommodate localization.

### Experience principles

1. Familiar names: Posts, Pages, Media, Appearance, Plugins, Users, Tools, Settings.
2. Content receives the most screen space; controls appear where they are useful.
3. Every save, publish, import, update, and AI operation has a visible state and recovery path.
4. Forms, direct editing, keyboard commands, and AI all operate on the same content and permissions.
5. Installation, themes, and public rendering form one complete product experience.
6. WordPress content can be imported; WordPress plugins and themes are not presented as automatically compatible.

## 2. Visual direction

### Character

An editorial workspace: light neutral surfaces, dark readable text, restrained violet accents, clear grouping, and comfortable spacing. Avoid decorative gradients, large dashboard hero panels, and persistent upgrade advertisements.

### Initial design tokens

| Token | Value | Use |
|---|---|---|
| Canvas | `#F6F7F9` | Application background |
| Surface | `#FFFFFF` | Tables, forms, drawers, cards |
| Primary text | `#182230` | Titles and body text |
| Secondary text | `#526071` | Descriptions and supporting labels |
| Border | `#D9DEE7` | Separators and control boundaries |
| Accent | `#5B3FD6` | Primary actions, links, selected controls |
| Accent background | `#F0EDFF` | Active navigation and selected sections |
| Success | `#176B46` | Saved and published status |
| Warning | `#865000` | Attention and pending decisions |
| Danger | `#B42318` | Errors and destructive actions |
| Focus | `#2563EB` | Visible keyboard focus ring |

Use system UI fonts initially to avoid a font download dependency. Body text: 14–16 px. Page titles: 28 px. Editor body: 18 px with 1.7 line height. Editor title: 36–40 px on desktop, 28 px on mobile. Technical identifiers use a monospace font.

- Spacing scale: 4, 8, 12, 16, 24, 32, 48 px.
- Controls: 40 px minimum desktop height; touch targets at least 44 × 44 px.
- Corner radius: 8 px for controls, 12 px for cards and drawers.
- Shadows: reserved for floating menus, dialogs, and overlays.
- Dark mode uses separately tested semantic colors rather than mechanically inverted values.
- Colors are initial targets; validate contrast in the implemented components before release.

## 3. Application shell and navigation

### Desktop layout

At 1440 px width: 232 px sidebar, 64 px top bar, flexible main area with 32 px page padding. A contextual inspector is 320 px wide when open. Content lists can use the full main width; settings forms stop at approximately 960 px.

```text
┌──────────────────────┬──────────────────────────────────────────────────────┐
│ Publication name  ▾  │ Search or run a command…       Jobs  Help  Avatar    │
├──────────────────────┼──────────────────────────────────────────────────────┤
│ Dashboard            │ Posts                             [New post]        │
│ Posts                │ Manage the articles on your publication.            │
│ Pages                │                                                      │
│ Media                │ All  Drafts  In review  Scheduled  Published  Trash  │
│ Custom content…      │                                                      │
│ Comments             │ [Search posts…] [Author ▾] [Category ▾] [View ▾]    │
│                      │ ┌──────────────────────────────────────────────────┐ │
│ Appearance           │ │ Content table                                    │ │
│ Plugins              │ │                                                  │ │
│ Users                │ └──────────────────────────────────────────────────┘ │
│ Tools                │                                                      │
│ Settings             │ Selected items → contextual bulk actions             │
│                      │                                                      │
│ View website ↗       │                                                      │
└──────────────────────┴──────────────────────────────────────────────────────┘
```

The publication menu offers site information and “View website”; it is not a network operations dashboard. The sidebar remains a single-site navigation system.

### Navigation hierarchy

| Main item | Children / destinations |
|---|---|
| Dashboard | Welcome, drafts, reviews, scheduled content, relevant issues |
| Posts | All posts, Add new, Categories, Tags |
| Pages | All pages, Add new |
| Media | Library, Upload |
| Custom content | One entry per registered type, using its configured name |
| Comments | Pending, Approved, Spam, Trash; hidden when comments are disabled |
| Appearance | Themes, Customize, Navigation, Templates, Reusable patterns |
| Plugins | Installed, Install plugin |
| Users | All users, Invite user, Roles |
| Tools | Content types, Import/export, Redirects, AI activity, Site health |
| Settings | General, Publishing, URLs & SEO, Media, Discussion, AI, Integrations, Advanced |

Categories and Tags also have direct links from the post editor. “Content types” belongs under Tools because it changes the site structure, rather than daily article content.

### Shared controls

- **Command search:** opens with Ctrl/Cmd+K; searches accessible content, settings, and actions. Clearly separates navigation commands from AI requests.
- **Jobs:** shows running imports, exports, AI tasks, and updates with progress and results.
- **Help:** documentation for the current screen and searchable general documentation.
- **Account menu:** profile, interface appearance, keyboard shortcuts, sign out.
- **Notifications:** concise, dismissible, and linked to the affected item. Important job outcomes also persist in activity history.

## 4. Dashboard

The first screen answers: What needs my attention, and what can I continue?

### Layout

1. Header: “Dashboard” with “New post” as the primary action.
2. A narrow attention area, shown only for actionable issues such as failed scheduled publishing.
3. Two-column body:
   - **Continue editing:** the current user's five most recent drafts, with author and last-saved time.
   - **Needs review:** submissions the current user can review.
   - **Scheduled next:** upcoming publication dates, with timezone shown.
   - **Recent activity:** publishing, revisions, and relevant AI changes.
4. A compact site-status link for administrators.

Avoid empty charts or invented readership statistics. Audience data appears only when an analytics integration supplies it. New installations show a short checklist: site details, first post, navigation, theme, optional AI connection.

## 5. Content lists: Posts, Pages, and custom types

### Table design

Default columns: selection, title, status, author, category, updated date. Offer optional published date, scheduled date, content type, and assigned reviewer columns.

- Title is the strongest visual element and links to editing.
- Status uses both text and color: Draft, In review, Scheduled, Published.
- Row actions remain reachable without hover, through a labeled menu button.
- View controls support saved filters and comfortable/compact density.
- Filters and pagination persist when returning from an editor.
- Empty state explains whether there is no content or no match for the current filters.

### Bulk actions

Selecting rows reveals: change author, change category, move to trash, and permitted workflow actions. AI bulk actions first show scope, proposed task, and any available cost estimate. If an estimate cannot be calculated, say so rather than presenting a precise number.

Bulk publishing requires a summary and checks for every item. Partial failures report which items succeeded and which need attention; retrying must not repeat successful changes.

### Quick edit

Use a side drawer for title, slug, author, categories, and publication schedule. Full content editing remains in the editor. Saving quick edits must detect an intervening change by another editor.

## 6. The publishing editor

### Desktop wireframe

```text
┌────────────────────────────────────────────────────────────────────────────┐
│ ← Posts   Draft · Saved 10:42          History  Preview  [Publish…]        │
├───────────────┬──────────────────────────────────────┬─────────────────────┤
│ Outline       │                                      │ Document  Block  AI │
│               │ Article title                        │                     │
│ Introduction  │ Add a short description…             │ Status: Draft       │
│ Section one   │                                      │ Author              │
│ Section two   │ Start writing or type / for a block. │ Categories / Tags   │
│               │                                      │ Featured image      │
│ + Add block   │ [Paragraph content…]                 │ URL                 │
│               │                                      │ Publish date        │
│               │ [Image with caption…]                │ SEO preview         │
│               │                                      │ Custom fields       │
└───────────────┴──────────────────────────────────────┴─────────────────────┘
```

The editor replaces the full application sidebar with a slim contextual shell. The outline can collapse. The writing column targets approximately 720 px, with a wider canvas available for page layout blocks.

### Writing behavior

- Autosave after a short idle period; show Saving, Saved, Offline, or Save failed.
- Ctrl/Cmd+S performs an explicit save and reports its result.
- Slash menu inserts blocks; selection toolbar offers formatting and relevant AI actions.
- Initial blocks: paragraph, headings, image, gallery, list, quote, table, embed, button, separator, and reusable pattern.
- Drag handles also provide “Move up” and “Move down” actions for keyboard access.
- Pasting text preserves useful structure; external HTML is sanitized.
- The document inspector exposes publishing controls; block inspector shows only the selected block's controls.
- Custom content fields use predictable sections with descriptions and validation messages.
- Page templates constrain layout options where appropriate, so authors can create consistent pages.

### Save and publish model

For a published item, autosaving an edit creates a working revision. The public page keeps its currently published revision until the user selects “Update…” and confirms the change.

Primary action labels reflect the user's capabilities and item state:

- Contributor: **Submit for review**.
- Publisher editing a draft: **Publish…**.
- Publisher selecting a future date: **Schedule…**.
- Publisher editing published content: **Update…**.

The confirmation drawer shows title, destination URL, visibility, date/time/timezone, and a preview link. Hard validation failures block publication. Advisory checks are distinguished from required fields.

### History and conflict handling

- History shows author or AI attribution, timestamp, and a change summary.
- Compare versions with text and block differences.
- “Restore as draft” creates a new working revision; it does not silently replace the live page.
- If the saved version changed elsewhere, show “A newer version is available” and offer compare/reload or recovery of local edits. Never overwrite silently.
- Initial release uses revision checks and edit-presence notices. Simultaneous collaborative editing is a later enhancement.

## 7. AI interaction design

### Entry points

1. **Selection action:** rewrite or explain selected text.
2. **Editor AI tab:** work on the current document.
3. **Context action:** generate image metadata, suggest taxonomy, or draft a page from an existing template.
4. **Tools → AI activity:** inspect jobs and their outcomes.

The assistant panel is 360 px wide where space permits and uses the inspector area rather than adding another permanent sidebar.

```text
┌──────────────────────────────────────┐
│ AI assistant                    ×    │
│ Working on: “Example article”        │
│ Context: selection + style guide     │
│                                      │
│ [Summarize] [Suggest links]           │
│                                      │
│ Request                              │
│ Make this introduction clearer.      │
│                                      │
│ Proposed change · 1 paragraph        │
│ − Previous wording…                  │
│ + Suggested wording…                 │
│                                      │
│ [Apply to draft] [Edit] [Discard]     │
│                                      │
│ Describe a change…                   │
│                            [Send]    │
└──────────────────────────────────────┘
```

### Behavior contract

- Show scope before execution: selection, current document, or explicitly selected items.
- The default context contains only content the current user can access.
- Suggestions produce reviewable changes, not a replacement document hidden in chat.
- Applying suggestions modifies a draft and creates an attributable revision.
- Before applying, check whether the document has changed since generation; require a new comparison when it has.
- Brand rules and selected sources appear as context references. Source-backed factual suggestions link to their sources; a model response is not represented as fact verification.
- Publication, deletion, plugin installation, and site-wide changes use the relevant permission and confirmation flows.
- Job states: Queued, Running, Awaiting review, Completed, Failed, Cancelled.
- Cancellation stops further work where supported and explains any step that already completed.
- Report actual usage after completion when the provider supplies it; distinguish estimates from billed amounts.
- Provider failure leaves the current draft intact and offers a retry.
- AI unavailability does not prevent manual editing or publishing.

### AI-assisted development

Theme and plugin generation begins in the relevant Appearance or Plugins screen. Generated code becomes a candidate package with build results, preview, and change summary. It is not evaluated inside the live CMS process. Installation follows the normal package workflow.

## 8. Media library

Grid/list toggle, searchable filenames and metadata, filters for file type and usage. Upload progress appears per file, with retry and validation errors beside the affected asset.

Selecting an asset opens a drawer containing preview, filename, dimensions, file size, alt text, caption, credit, and “Used in” references. AI-generated alt text appears as a suggestion that the user can edit before saving.

Image replacement shows affected references and preserves or explicitly changes the asset identity. Deleting an asset used in published content shows those references before confirmation. Decorative-image status is a deliberate option, separate from missing alt text.

## 9. Content types, fields, and taxonomies

### Content type builder

Tools → Content types contains built-in Posts/Pages and user-defined types. Administrators can create a type through these steps:

1. Name and identifier: “Reviews” / `review`.
2. Behavior: public URL, archive, hierarchy, revisions, featured image, comments.
3. Fields: text, rich text, number, date, boolean, select, media, relationship, repeatable group, blocks.
4. Taxonomies and permissions.
5. Preview of the generated editing form and planned schema changes.
6. Apply and add the type to navigation.

Fields have stable identifiers, labels, descriptions, defaults, validation, and optional conditions. Renaming a label must not rename stored data silently. Removing or changing a populated field displays the data impact and a migration/export option.

The same registered schema drives admin forms, API validation, and AI tool definitions. Developer-supplied schemas use the same registry; locked fields show their source and explain where they can be changed.

## 10. Appearance and themes

### Themes screen

Show the active theme first, followed by installed themes. Cards contain screenshot, name, version, compatibility, and actions: Customize, Preview, Activate, Details.

### Customizer

Left: sections for typography, colors, header, footer, layout, and templates. Center: an actual website preview. Top: desktop/tablet/mobile viewport controls and “Save draft” / “Publish design”.

Design changes remain a draft until published. Theme activation checks required blocks, template support, and settings compatibility, then shows any missing mappings. Content survives theme changes; unsupported blocks remain stored and are identified for correction.

Navigation supports drag ordering and keyboard move controls, links to content, external links, nesting, and live preview. Templates distinguish single content pages, archives, search, and not-found pages. Reusable patterns can be inserted into the block editor.

Themes and plugins use separate extension interfaces: a visual theme change should not erase content fields owned by the CMS or a plugin.

## 11. Plugins and hooks

### Installed plugins

Each row shows name, purpose, version, enabled state, compatibility, update availability, and Settings / Disable / Details. Settings integrate with the same form components as core settings.

### Installation

Support a packaged upload and configured registry. Before installation, show publisher, source, declared capabilities, compatibility, dependencies, and whether a build or restart is required. Verify the package before activation; record installation and activation separately.

Do not label an in-process plugin “sandboxed” simply because it declares permissions. Show its actual execution model in plugin details. An isolated plugin can only claim restrictions enforced by its runtime.

### Updates and removal

An update presents the change summary, required migrations, and recovery options. Package rollback and database recovery are separate operations; an older package cannot be promised to work after an incompatible migration.

Disabling retains data. Uninstalling asks separately whether to retain or remove plugin-owned data and explains affected content or blocks.

### Developer-facing hooks

Actions and filters remain distinct public APIs. Plugin details can link to documentation of the hooks it registers. Tools → Site health may expose an advanced extension diagnostic view; hook internals do not appear in ordinary writing screens.

## 12. Settings and users

### Settings organization

| Section | Main controls |
|---|---|
| General | Site name, description, language, timezone, icon |
| Publishing | Homepage, default content options, workflow and scheduling |
| URLs & SEO | Permalinks, indexing preference, metadata defaults, sitemap status |
| Media | Upload limits, image sizes, storage destination |
| Discussion | Comments, moderation, notification preferences |
| AI | Providers, credentials, model defaults, context rules, usage limits |
| Integrations | API credentials, MCP access, webhooks, connected services |
| Advanced | Diagnostics, maintenance, update channel, backup configuration |

Settings pages use labeled sections and a sticky save bar only when changes exist. Dangerous or expansive changes show a specific impact summary. Secrets are masked and never returned as ordinary form values. AI provider setup includes connection testing and an explanation of what selected content is sent externally.

### Users and permissions

Initial roles: Administrator, Editor, Author, Contributor. Content ownership and publication capabilities are explicit. Administrators can inspect role permissions by content type and operation. Custom roles can extend this foundation.

Hide inaccessible navigation for clarity and enforce permissions on the backend. A stale open screen must handle permission loss without losing unsaved text. AI tokens and integrations receive their own scoped capabilities rather than automatically inheriting administrator access.

## 13. Installation and WordPress migration

### Browser installation

1. Environment check with actionable errors.
2. Database connection.
3. Site details and timezone.
4. Administrator account.
5. Default theme preview.
6. Optional AI connection; skip is available.
7. Finish with “Visit website” and “Open dashboard”.

Packages include built administration assets. Installation instructions are tailored to the chosen engine, while the browser steps remain equivalent. Setup cannot be reopened publicly after completion.

### WordPress import

Tools → Import → WordPress accepts the supported export or connection method. Before import, show the discovered content and mappings for types, authors, categories, tags, and URLs.

- Run a preflight and display unsupported blocks, shortcodes, and unavailable media.
- Offer a small sample import before the full job.
- Track progress, resume safely, and prevent duplicate items on retry.
- Produce an import report with errors, converted content, unresolved content, and redirect suggestions.
- Preserve originals needed for reviewing conversion problems.
- Do not claim that WordPress plugin or theme code has been imported as functioning extensions.

## 14. Responsive and accessible behavior

| Width | Behavior |
|---|---|
| 1280 px and above | Expanded sidebar; editor outline and inspector available side by side |
| 1024–1279 px | Compact sidebar; editor shows at most one auxiliary panel alongside content |
| 768–1023 px | Sidebar becomes a drawer; inspector opens as an overlay |
| Below 768 px | Single column; list rows become compact cards; editor panels use full-screen sheets |

On mobile, keep Save/Publish reachable without obscuring the last content block. AI and document settings open one at a time, preserving editor selection and scroll position.

- Target WCAG 2.2 AA, with implementation verification before claiming compliance.
- Provide semantic landmarks, a skip link, visible focus, and descriptive control labels.
- Dialogs trap focus appropriately and return it to their trigger on close.
- Status updates use restrained live announcements; routine autosaves must not constantly interrupt screen-reader users.
- Support reduced motion, browser zoom, and long localized labels.
- Never rely on color, dragging, hover, or icons alone.

## 15. Engine implementation mapping

| Interface responsibility | Laravel implementation | Node.js implementation |
|---|---|---|
| Administration | React/TypeScript with Inertia for ordinary screens; dedicated APIs for editor and integrations | React/TypeScript with Fastify APIs |
| Public website | Included Blade theme renderer | Included server-side theme renderer selected before implementation |
| CMS operations | Application services, validation, policies, transactions | Application services, runtime schemas, authorization, transactions |
| Persistence | PostgreSQL through Eloquent/query builder and migrations | PostgreSQL through Drizzle and migrations |
| Jobs | Laravel queue workers | PostgreSQL-backed queue and Node.js workers |
| AI | Provider adapter, potentially using Laravel AI SDK | Provider adapter, potentially using TypeScript AI SDK |
| Hooks | Versioned CMS action/filter contracts above framework internals | Versioned CMS action/filter contracts above Fastify internals |
| Distribution | Packaged PHP dependencies and built UI; VPS/container release | Compiled application and built UI; VPS/container release |

For the Node.js prototype, evaluate the renderer with the same theme tasks defined below before locking it into the SDK. Public themes need server rendering, previews, archives, and reusable block renderers; React administration does not require React public themes.

### Common backend contract

Every mutation carries an actor, target, operation, and applicable revision identifier. Long-running work returns a persistent job identifier. Requests can report field validation errors, permission errors, version conflicts, or retryable failures distinctly.

Human UI actions, API clients, MCP tools, and jobs call the same permission-aware CMS operations. Code sharing is optional; behavior sharing is mandatory. Editor save endpoints never bypass the publishing workflow.

## 16. Initial release and later enhancements

### Initial complete CMS

- Installation and a working default public theme.
- Posts, pages, custom types, taxonomies, media, and navigation.
- Editor, autosave, revisions, previews, scheduling, and review permissions.
- Theme settings, basic template support, and plugin installation lifecycle.
- Contextual AI draft operations, activity history, and provider configuration.
- Essential settings, users, import/export, redirects, and job status.

### Later enhancements

- Simultaneous collaborative editing.
- Full freeform visual template construction.
- Public marketplace discovery, payments, and publisher verification.
- Advanced automation policies and analytics-driven suggestions.
- Multiple publications managed within a single installation.

These later features must not delay a coherent single-publication CMS experience.

## 17. Prototype and acceptance scenarios

The first interactive prototype should cover these six connected screens: Dashboard, Posts, Editor, Media drawer, Theme customizer, and AI change review. It should use consistent fictional article data and represent success, loading, empty, and error states.

Before selecting or extending an existing CMS foundation, test the same scenarios against it:

1. A WordPress user can find Posts, create an article, add an image, preview it, and publish without developer instructions.
2. An author edits a published article; autosave leaves the live version unchanged until an authorized update.
3. An administrator registers a Review type with rating and product fields; its form, permissions, API validation, and AI inputs agree.
4. A theme displays that type and its archive; switching themes preserves the content and reports unsupported blocks.
5. A plugin adds a setting and a publish action; its compatibility and execution model are visible.
6. AI proposes a targeted rewrite, the author compares it, applies it to a draft, and can restore the prior revision.
7. A failed AI job does not erase text or block manual publication.
8. A concurrent save is detected and never silently overwrites another editor's work.
9. A WordPress sample import preserves expected URLs and reports unsupported content accurately.
10. Keyboard and mobile users can complete the writing and AI-review flows.

## 18. Decisions proposed for review

- Keep the familiar WordPress navigation vocabulary.
- Use one interface specification for both backend options.
- Use a React block editor with a focused document inspector and contextual AI tab.
- Keep working revisions separate from published revisions.
- Include a default theme and public rendering in every installation.
- Treat custom types, settings, permissions, and AI operations as parts of a shared CMS registry.
- Select the backend and any existing CMS foundation after validating the key extension and publishing scenarios.

The next deliverable after review is an interactive visual prototype based on this specification.

## 19. Development approach: founder and AI agents

The founder supplies product direction and reviews behavior; coding agents implement and verify small, complete increments. The specification must not assume an existing Laravel or TypeScript team.

Laravel remains the current recommendation because its integrated application services and conventions can reduce project-specific decisions. This is an engineering judgment, not evidence that PHP developers always cost less or that AI agents always generate better PHP. Both backend options remain documented until the founder selects one.

### Requirements for either implementation

- Keep one repository and a modular application, with background workers where needed.
- Document the architecture, CMS extension contracts, and exact setup and verification commands in version control.
- Prefer existing framework mechanisms over overlapping libraries or custom infrastructure.
- Deliver features as complete flows with their UI, permissions, persistence, and relevant checks.
- Use type checking/static analysis and meaningful integration tests, especially for publishing, authorization, revisions, plugin lifecycle, and migrations.
- Give each coding task explicit acceptance criteria; verify them using tool output and observable behavior.
- Keep deployment, backup, and restore reproducible so future developers can operate the application.
- Review actual candidate capability and total maintenance effort when hiring; language alone does not establish cost.

Laravel Boost is a candidate development aid for the Laravel route: its documented tools expose application context and version-specific documentation to compatible coding agents. It is distinct from the AI features shipped to CMS users. See [Laravel Boost documentation](https://laravel.com/docs/13.x/boost).
