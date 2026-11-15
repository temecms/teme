# Proposal: the management API, foundation (v1 wave 2)

**Status:** draft, 2026-11-15. Part of the v1 scope (I-3445). Roadmap slice 3.2 ("Management API with scoped tokens"), parked by I-3324 until a site was about to move; v1's site moves are that trigger.

## What it is

An authenticated API that lets an outside tool **write** to a Teme site: sign in with a key, look up what it needs (categories, tags, authors), upload media, and create posts and pages. It is general: any outside tool uses the same endpoints. Reading published content stays with the delivery API (`/api/v1`, 3.1).

## Settled

1. **Keys, not passwords.** A site's administrator creates an API key on Settings › Management API. A key is shown once, stored only as a hash, named, revocable, and records when it was last used. There is no password sign-in to this API.
2. **A key acts as one person, never as "the first administrator".** Each key names the person it acts for. Every write goes through the existing Operations (rule 1) with that person as the actor, so the person's role decides what is allowed, exactly as in the admin.
3. **A key is also limited to abilities** chosen when it is made, from a fixed list: `site.read` (who am I, categories, tags, authors), `media.write`, `content.write` (create drafts), `content.publish` (publish and schedule). A request needs both the ability and the person's permission.
4. **A key never satisfies elevation** (security.md, commitment 12). An Operation that asks for a fresh password is refused through the API.
5. **Off by default.** The API has its own switch at the top of its settings page (I-485), separate from the delivery API's. While it is off, every management address answers `api_off`.
6. **Its own path:** `/api/manage/v1/…`, never cached, every answer `Cache-Control: no-store`. Errors are the API's existing problem answers (RFC 9457, `App\Domain\Api\Problems`): a code, a plain sentence, never internals.
7. **Rate limited per key** (default 60 requests a minute, a setting), and per address before a key is read.
8. **What it records:** every revision a key writes records it (`via`, the column wave 1's plugin part adds) as `api:<key name>`; creating and revoking a key are security events.
9. **Additive by design.** Updating existing posts, listing and reading drafts, deleting, more abilities and OAuth-style app sign-in come later, as new endpoints and abilities; nothing in this release changes shape for them.

## The endpoints (first release)

| Method and path | Ability | Does |
|---|---|---|
| `GET /api/manage/v1/me` | any | The key's name, abilities, person (id, name) and the site's name and address |
| `GET /api/manage/v1/taxonomies/{taxonomy}/terms` | `site.read` | Terms: id, name, slug, parent |
| `GET /api/manage/v1/people` | `site.read` | People who may be credited: id, name, slug |
| `POST /api/manage/v1/media` | `media.write` | Upload a file (multipart, or a raw body with `Content-Disposition` filename) through `UploadMedia` |
| `PATCH /api/manage/v1/media/{id}` | `media.write` | Alt text, caption, description through `UpdateMediaDetails` |
| `POST /api/manage/v1/items` | `content.write` (+ `content.publish` to publish or schedule) | Create an item of a type: title, body, slug, terms, featured media, author, fields; status `draft`, `published` or `scheduled` with a date |

## Provisional (built as written unless the maintainers change it)

- **P1. Body formats:** `body` as a Tiptap document (rule 7), or `html`, converted by the import's HTML converter with links and images kept as given. If that converter cannot run outside an import, `html` is refused with a plain problem in this release and comes next.
- **P2. Fields by handle.** `fields` takes any registered field by its handle (for example `meta_description`, `seo_title`, `excerpt`), validated by the field registry. Teme has no "focus keyword" field; a tool's own extra values need a registered field (core or a plugin's).
- **P3. Create, then publish.** A create that asks for `published` or `scheduled` makes the draft, then publishes or schedules it. If publishing is refused, the draft is kept and the answer says so, with the draft's id.
- **P4. Key lifetime:** no expiry by default; an optional expiry date when the key is made.

## Out of this release

Updating or deleting items, reading drafts, comments, settings, users, translations linking (with multilingual, later), webhooks, OAuth, the MCP server (3.3).
