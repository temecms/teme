# Proposal: insertion points inside an article, and ads.txt

**Status:** draft for wave 2 of v1 (2026-11-15). **Decides:** I-064 (adopted 2026-10-28: core gives plugins insertion points inside an article's body, drawn so the page cache still holds; which ad, where and how often belongs to plugins). **Plan:** `docs/plans/insertion-points.md`.

## What it is

A socket in core: a plugin (for example an ad-placement plugin, built separately) gives markup to be drawn at named points inside a published article's body: before the content, after paragraph 3, every 4 paragraphs, before the second heading, near the end. Core decides where those points are in the rendered document, draws the markup there, keeps it out of feeds and the editor, keeps the page cacheable, and keeps the site's content security policy whole. Core also serves `/ads.txt` from a setting.

## Settled

1. **Points are between top-level blocks only.** A point never falls inside a card, a list, a table, a quote or a code block, because those are each one top-level block of the stored document. Positions are counted over the document's top-level nodes, never over the HTML.
2. **The positions core offers** (`Position`): `BeforeContent`, `AfterContent`, `AfterParagraph(n)`, `EveryParagraphs(n, max)`, `BeforeHeading(level, nth)`, `AfterHeading(level, nth)`, `NearEnd` (before the last paragraph). A position that the article does not reach (paragraph 9 in a 5-paragraph article) draws nothing. Two insertions at one point are drawn in the contributors' precedence order (plugin, site, core), each key once.
3. **Only public web pages draw insertions.** The renderer draws them only for the `web` target with an insertion plan given; feeds, the Markdown and API versions, previews of drafts, search excerpts, the import's fidelity check and the editor never see them.
4. **A contributor decides per article.** It receives the article's context: content ID, type, its terms by taxonomy, language, author IDs, word and paragraph counts, and whether the item opted out. Per-type and per-category rules are the plugin's; core supplies the facts.
5. **Each item can opt out.** A built-in field, "No inserted content" (boolean, in the item's settings rail), turns every insertion off on that item. Contributors are not asked when it is on.
6. **Markup is cleaned like site code.** Insertion HTML loses inline `<script>` bodies and `on*` attributes (I-697's rule for a plugin's fragments); `<script src>`, `<iframe>` and `<img>` stay, and their https origins are added to that page's content security policy, as site code's are (SiteCodePolicy). A plugin can't inject script text into a page.
7. **The page cache holds.** Insertions are part of the drawn page, so the cached page includes them. An article page that drew insertions carries the tag `insertions`. When a plugin's rules change, it calls the contract's `InsertionsChanged::notify()` inside its Operation, and core purges that tag after commit (rule 6). Saving a plugin's settings through core does the same.
8. **A plugin needs consent.** Contributing insertions is a declared capability, `content.insert`, granted with the plugin's other capabilities.
9. **ads.txt is core.** `/ads.txt` serves the text saved in Settings › Site code ("ads.txt"), as plain UTF-8, stored by the page cache like robots.txt, and answers 404 while the setting is empty. Only administrators edit it.

## Provisional (built as written unless the owner says otherwise)

- P1. `EveryParagraphs` has a ceiling of 10 insertions per article per contributor.
- P2. `NearEnd` counts as "before the last top-level paragraph"; an article with fewer than 3 paragraphs has no `NearEnd`.
- P3. The opt-out field is shown to anyone who may edit the item.

## Not in this part

Which ad, rotation, frequency, devices, lazy loading, AMP: the plugin's. Insertion in regions and widgets: regions already exist. Anything inside the editor.
