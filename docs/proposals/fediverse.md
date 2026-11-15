# Proposal: the Fediverse (ActivityPub) in core, v1

**Status:** for building, 2026-11-15. Decisions I-058, I-077, I-485, I-3447 (all of the study's recommendations adopted). Design: `docs/studies/2026-11-15-activitypub-core.md` and `docs/studies/2026-11-15-activitypub-continuity.md` (architect's repository). Three parts: F1 `fediverse/actors`, F2 `fediverse/publishing`, F3 `fediverse/import`.

## What it is

A site can be followed from Mastodon and other fediverse software: its blog and, if chosen, its authors are accounts; each post published, changed or removed reaches followers; replies come back as comments through normal moderation. A site moving from WordPress's ActivityPub plugin keeps its followers on the same domain, with nothing for followers to do.

## Settled

1. **Core is the foundation; plugins extend.** Core: the blog, author and application actors; WebFinger and NodeInfo; actor, outbox, followers (count only) and following (empty) documents; Follow, Accept, Undo; Create, Update and Delete of posts to followers, signed and queued with retries; replies as moderated comments; the import of the WordPress plugin's keys, followers and settings; Settings › Fediverse with its switch; a minimal blocked-servers list; security limits. Plugins: likes and boosts shown or counted, quote posts, the site following others, extra moderation, bridges, other activity and object types.
2. **Likes and boosts are ignored by core** (answered 202, never stored) and passed to plugins through `ActivityReceived` and the handler registry.
3. **Identities never change.** An actor's or object's URL is stored verbatim. Imported URLs are the old site's (`/?author=N`, `/@name`, `/?p=N`, `/wp-json/activitypub/1.0/…`) and answer forever. New ones use the prefix **`/ap/`**: `/ap/blog`, `/ap/people/{ulid}`, `/ap/application`, `/ap/inbox`, `/ap/objects/{ulid}`.
4. **Off means paused** in v1: documents and WebFinger still answer, inboxes answer 503 with `Retry-After`, nothing is queued, keys and followers are kept. "Leave the fediverse" is after v1.
5. **Signatures** are written in Teme on PHP's `openssl`: incoming draft-cavage and RFC 9421 are verified; outgoing requests are signed draft-cavage. RFC 9421's header parsing uses one small package, **`gapple/structured-fields` 2.3.4** (MIT, PHP ≥ 8.1, released 2026-10-27). **Approved by the maintainers on 2026-11-15**: F1 adds it, and verifies both draft-cavage and RFC 9421.
6. **`FediverseSender` is offered to plugins in v1** (capability `fediverse.send`): core signs, stores and delivers; a plugin never holds a key.
7. **Keys leave WordPress through the companion's admin-only HTTPS export**, as two PEM strings per actor, never logged, never in the import map, encrypted at rest in Teme.
8. **A minimal blocked-servers list** is in core: refused at the inbox before any fetch, never delivered to.
9. **The import never federates**: content written in import mode fires no content hook. A staging copy never sends: delivery happens only when the site's host is the actors' host.
10. **Replies use the comments table unchanged**; the link to the remote actor lives in `fediverse_objects`. Remote HTML becomes the restricted comment document (rule 7).

## Provisional (built as written unless the maintainers change them)

- P1. Retries: at once, then 1 min, 5 min, 30 min, 2 h, 6 h, 12 h, 24 h, 24 h (about three days); a host failing for 7 days is skipped until it answers again; at most 4 deliveries to one host at a time.
- P2. Inbox limits: 120 requests a minute per address and per remote host; bodies over 256 KB refused; replies also 10 an hour per remote account.
- P3. The outbox shows the latest 20 activities; sent activities are kept 90 days (the latest 20 per actor always).
- P4. Settings › Fediverse is drawn from the settings-page pattern and shown to the maintainers before F1 builds it.

## After v1 (in core, small)

Admin replies federated back (first); leave the fediverse; sending RFC 9421; follower synchronization once the list is proven complete; outgoing `Move`; a privacy export for a remote account; a longer outbox.

## Not here

Anything a plugin extends (Settled 1). The site following other accounts.
