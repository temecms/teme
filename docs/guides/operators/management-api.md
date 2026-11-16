---
title: The management API
audience: operators
proved: tests
written_for: the management API at Teme's own tag
---

# The management API

The management API lets a tool outside the site write to it: an importer, a script that posts a daily digest, a workflow that uploads photos. A tool signs in with a key. A key acts as one person of the site, with only the abilities you give it, so it can never do more than that person may do.

The API is off until you turn it on, and nothing changes on a site that never turns it on.

## Turning it on

Open **Settings › Management API** as an administrator and switch on **Turn on the management API**. With the switch off, every request answers `404` with the problem `api_off`, whatever key it carries.

The same page sets **Requests a minute per key**, 60 unless you change it, between 1 and 1000. A key that goes over it is answered `429` with the problem `rate_limited` and a `Retry-After` header. An address that fails to sign in 30 times in a minute waits until the minute ends.

## Making a key

Only an administrator can make or revoke a key, and Teme asks for their password first. Under **Keys**, choose **Create a key**, then give:

- a name, which every revision the key writes is marked with, so you can tell its work from a person's;
- the person it acts as: what that person may do still decides what the key may do, and a suspended person's key is refused;
- its abilities;
- an expiry, if it should stop working on a date.

Teme shows the key once. Copy it then: the site keeps only a fingerprint of it, and nobody can show it again. If a key is lost or leaks, revoke it with **Revoke** and make a new one. Tools using a revoked key are refused from the next request.

Making and revoking a key are written to the security log.

## Abilities

| Ability | What it allows |
|---|---|
| `site.read` | Reading the lists a tool needs to fill in a request: the terms of a taxonomy, and the people an item can be credited to. |
| `media.write` | Uploading media and changing its title, alt text, caption and credit. |
| `content.write` | Creating items as drafts. |
| `content.publish` | Publishing or scheduling the items it creates. It needs `content.write` too. |

A request that needs an ability the key lacks answers `403` with the problem `forbidden`, before anything is written.

## Making requests

Every request goes to `/api/manage/v1` on the site's address and carries the key as a bearer token:

```sh
KEY='teme_…'
curl -H "Authorization: Bearer $KEY" https://example.com/api/manage/v1/me
```

Answers are JSON. A refusal is a problem document (`application/problem+json`) with a `status`, a `code` and a `detail`, and, when fields were refused, an `errors` list naming each one. A missing, wrong, revoked or expired key all answer the same `401` with the problem `unauthorized`. No answer is ever cached.

## The endpoints

### Who the key is

`GET /me` answers the key's name and abilities, the person it acts as, and the site's name and address.

```sh
curl -H "Authorization: Bearer $KEY" https://example.com/api/manage/v1/me
```

### Terms

`GET /taxonomies/{taxonomy}/terms` answers the terms of a taxonomy, each with its ID, name, slug and parent. It needs `site.read`.

```sh
curl -H "Authorization: Bearer $KEY" https://example.com/api/manage/v1/taxonomies/category/terms
```

### People

`GET /people` answers the people an item can be credited to, each with an ID, a name and a slug. It needs `site.read`.

```sh
curl -H "Authorization: Bearer $KEY" https://example.com/api/manage/v1/people
```

### Uploading media

`POST /media` uploads a file, as multipart form data in `file`, or as the whole body with a `Content-Disposition` header naming it. The file is checked as an upload from the media library is: the same types, the same size limit. An upload the library already holds answers `409` with the problem `duplicate` and the existing item's ID, unless the request sends `allow_duplicate=1`. It needs `media.write`.

```sh
curl -H "Authorization: Bearer $KEY" -F file=@harbour.jpg https://example.com/api/manage/v1/media
```

### Changing media

`PATCH /media/{id}` changes a media item's `title`, `alt`, `caption` or `credit`. It needs `media.write`.

```sh
curl -X PATCH -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"alt": "Boats in the harbour at dusk"}' \
  https://example.com/api/manage/v1/media/01JD0000000000000000000000
```

### Creating an item

`POST /items` creates an item as the key's person. It takes:

- `type`, the content type, such as `post` or `page`, and a `title`;
- the body, as a Tiptap document in `body` or as HTML of up to 2 MB in `html`: HTML is converted to blocks as an import converts it, and the answer lists anything that changed on the way in `notes`;
- optionally `slug`, `terms` (term IDs by taxonomy), `featured_media` (a media ID), `author` (an ID from `/people`) and `fields` (values by field handle);
- `status`: `draft`, `published` or `scheduled`, with `publish_at` for a scheduled item, a date and time with its offset such as `2026-11-10T09:30:00Z`. Publishing or scheduling needs `content.publish`.

It needs `content.write`. A request that is refused writes nothing. If the draft is made but publishing it is then refused, the draft stays, and the answer is `202` with the draft and the problem that stopped it.

```sh
curl -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"type": "post", "title": "Harbour notes", "html": "<p>The boats came in late.</p>", "status": "draft"}' \
  https://example.com/api/manage/v1/items
```
