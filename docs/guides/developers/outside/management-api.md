---
title: The management API
type: how-to
audience: both
summary: For a service outside the site, not a plugin, with keys and abilities, the endpoints, the problems it answers, and its limits.
rests_on: [routes/api/manage.php, app/Domain/ManageApi, app/Http/Api/Manage, docs/guides/operators/management-api.md]
---

# The management API

The management API is for code that runs outside the site: an importer, a script that posts a daily digest, a workflow that uploads photos. It speaks HTTP and JSON from anywhere. A plugin doesn't use it: a plugin runs inside Teme and writes through the doors its capabilities open, as [The manifest](../concepts/manifest.md) describes.

The site's administrators have their own page about it, [The management API](../../operators/management-api.md) in the operators' guide.

## Keys and abilities

An administrator turns the API on under **Settings › Management API** and makes a key there. Teme shows the key once, starting `teme_`; keep it in your service's secret store, never in its code. The site keeps only a fingerprint, so a lost key is revoked and replaced, never shown again.

A key acts as one person of the site. Everything it does is checked twice: the key must have the ability the address needs, and the person must be allowed to do it, exactly as if they did it in the admin. Each revision a key writes is marked with the key's name.

| Ability | What it allows |
|---|---|
| `site.read` | Reading the terms of a taxonomy and the people an item can be credited to |
| `media.write` | Uploading media, and changing its title, alt text, caption and credit |
| `content.write` | Creating items as drafts |
| `content.publish` | Publishing or scheduling the items it creates, with `content.write` |

Send the key as a bearer token on every request, to `/api/manage/v1` on the site's address:

```sh
curl -H "Authorization: Bearer $KEY" https://example.com/api/manage/v1/me
```

## The endpoints

| Method and address | Ability | What it does |
|---|---|---|
| `GET /me` | none | Answers the key's ID, name and abilities, the person it acts as, and the site's name and address |
| `GET /taxonomies/{taxonomy}/terms` | `site.read` | Answers the taxonomy's terms, each with its ID, name, slug and parent |
| `GET /people` | `site.read` | Answers the people an item can be credited to, each with an ID, a name and a slug |
| `POST /media` | `media.write` | Uploads a file, as multipart form data in `file`, or as the whole body with a `Content-Disposition` header naming it |
| `PATCH /media/{id}` | `media.write` | Changes a media item's `title`, `alt`, `caption` or `credit` |
| `POST /items` | `content.write` | Creates an item as the key's person |

An upload is checked as the media library checks one: the same types and the same size limit. A file the library already holds answers `duplicate` with the existing item's ID, unless the request sends `allow_duplicate=1`.

`POST /items` takes a JSON body:

- `type`, a content type of the site such as `post` or `page`, and a `title`;
- the body, either as a Tiptap document in `body`, or as HTML of up to 2 MB in `html`, which Teme converts to blocks as an import does, listing anything it changed on the way in `notes`;
- optionally `slug`, `terms` (term IDs by taxonomy, from the terms endpoint), `featured_media` (a media ID), `author` (an ID from `/people`) and `fields` (values by field handle);
- `status`: `draft`, `published` or `scheduled`, with `publish_at` for a scheduled item, a date and time with its offset such as `2026-11-10T09:30:00Z`. Publishing or scheduling needs `content.publish`.

An answer wraps its data in `data`. A created item answers `201` with its `id`, `type`, `status`, `slug` and `title`, and its public `url` once it is published. If the draft was made but publishing it was then refused, the draft stays: the answer is `202`, with the draft and, under `problem`, what stopped it.

## Problems

A refusal is a problem document, `application/problem+json`, with a `status`, a `detail` for people and a `code` for your code. When fields were refused, `errors` lists each one with its `parameter` and `detail`. A refused request writes nothing.

| Status | Code | When |
|---|---|---|
| 401 | `unauthorized` | No key, or one that is wrong, revoked or expired: all alike |
| 403 | `forbidden` | The key lacks the ability, or its person may not do this |
| 404 | `api_off` | The API is switched off, whatever the key |
| 404 | `not_found` | Nothing the key may see has that ID or name |
| 409 | `duplicate` | The file is already in the media library; `existing_id` names it |
| 422 | `invalid_parameter` | A field was refused; see `errors` |
| 422 | `not_supported_yet` | Something this release doesn't do yet |
| 429 | `rate_limited` | Too many requests; wait as `Retry-After` says |

Branch on `code`, not on `detail`, whose words may change.

## Limits

- Each key may make 60 requests a minute unless the administrator sets another number, from 1 to 1000. Every answer carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; over the limit, the answer is `429` with `Retry-After`.
- An address that fails to sign in 30 times in a minute is refused until the minute ends, before any key is read.
- An HTML body is at most 2 MB.
- No answer is ever cached, by the site or anything between: each carries `Cache-Control: no-store`, and none carries an ETag or sets a cookie.
- A key can't reach anything that asks for a fresh password in the admin, such as making another key.

Next: [For agents](../for-agents.md), if an agent is building your service.
