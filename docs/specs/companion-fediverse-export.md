# The companion's fediverse route

**For:** the Teme Companion WordPress plugin, built separately (`github.com/temecms/teme-companion`). **Teme's side:** `app/Domain/Fediverse/Import/`, tested against the test companion (`tests/Fixtures/Import/FakeCompanion.php`). **Why:** `docs/proposals/fediverse.md` and `docs/plans/fediverse-import.md`.

A site moving from WordPress's ActivityPub plugin keeps its fediverse followers on the same domain, with nothing for the followers to do. For that, Teme needs what the plugin keeps: its settings, each account's addresses and key pair, the accounts that follow them, and the fediverse addresses of the replies it received. One new route of the companion's export API gives them.

## Version

The route arrives with the companion's export API **3**. `GET teme/v1/probe` answers `"api": 3`. Teme asks the route only when the source's probe answered 3 or more. Otherwise its fediverse step reports "The companion on this site is too old to move fediverse followers. To bring them across, update the companion on WordPress, then catch up.", and the run finishes without it.

## The guard

`GET teme/v1/fediverse`, behind the same guard as every other route of the export API: an administrator's application password, over HTTPS. This route alone carries private keys, so:

- it answers only over HTTPS, even where the site's other routes don't require it, and refuses plain HTTP with `403` and the code `teme_https_required`;
- the private keys never appear in the companion's logs, in its error answers, in any other route, or in the debug output WordPress writes when `WP_DEBUG` is on;
- the answer carries `Cache-Control: no-store`.

## The whole answer

Without `kind`, the route answers one JSON object:

```json
{
  "api": 3,
  "active": true,
  "home": "https://example.com",
  "settings": { … },
  "actors": [ … ],
  "followed_by": 0
}
```

- `active`: whether the ActivityPub plugin is active. Teme switches the fediverse on only when it is.
- `home`: `home_url()`, exactly as WordPress gives it. Teme builds each post's old address from it.
- `followed_by`: how many `ap_actor` posts carry `_activitypub_followed_by`, the accounts the site itself followed. Counted only: core Teme doesn't follow other accounts, and the run says how many weren't brought.

### `settings`

Each option as `get_option()` returns it, after `maybe_unserialize()`:

| Key | What Teme does with it |
|---|---|
| `activitypub_actor_mode` | `actor`, `blog` or `actor_blog`: Teme's **Who can be followed** becomes each author, the blog, or both. |
| `activitypub_blog_identifier` | The blog account's name, also sent as its `preferred_username`. |
| `activitypub_use_permalink_as_id_for_blog` | Sent for the record. The blog account's `id` below already follows it. |
| `activitypub_last_post_with_permalink_as_id` | Posts with this ID or a lower one were federated with their permalink as their address. Every later post used `home` + `/?p=<ID>`. |
| `activitypub_object_type` | How posts were sent, as a note or by post format. Reported when Teme sends them differently. |
| `activitypub_support_post_types` | The federated post types. Teme gives addresses to imported posts of these types, and reports the types it doesn't federate. |
| `activitypub_blog_user_also_known_as`, `activitypub_blog_user_moved_to` | The blog account's moves. Reported, because Teme doesn't keep them yet. |

### `actors`

One entry for the blog (`user_id: 0`), when the mode includes it, and one for each user who has an account in the plugin. The companion takes each address from the plugin's own actor object (`Activitypub\Collection\Actors::get_by_id()`), never building one itself, so a site with a custom REST prefix or plain permalinks sends its real addresses:

| Key | Value |
|---|---|
| `user_id` | WordPress's user ID, `0` for the blog. Teme finds the person through its import map. |
| `user_login` | The user's login, empty for the blog. |
| `preferred_username` | The name WebFinger answers for, as the actor document's `preferredUsername`. |
| `id` | The actor's `id`, verbatim. Teme keeps it for ever. |
| `url` | The actor's `url`. |
| `inbox`, `outbox`, `followers`, `following` | The actor's collections, verbatim. |
| `shared_inbox` | `endpoints.sharedInbox`, or `null`. |
| `addresses` | Every address of its own the plugin answers for this actor: its `id`, `/?author=<user_id>`, its author permalink and `/@<name>`. Teme answers at each of them. |
| `public_key_pem`, `private_key_pem` | The key pair, from the option `activitypub_keypair_for_0` for the blog or `activitypub_keypair_for_<sanitize_title(user_login)>` for a user, unserialized with `maybe_unserialize`. Legacy fallbacks are the options `activitypub_blog_user_public_key` and `activitypub_blog_user_private_key`, and the user meta `magic_sig_public_key` and `magic_sig_private_key`. Both are an empty string when the plugin has none. |
| `use_permalink_as_id` | Whether the account's `id` is its permalink. |
| `also_known_as` | The accounts it moved from, a list. |
| `moved_to` | The account it moved to, or `null`. |

Teme creates each account once, with its exact addresses and key pair, and never replaces it. The private key is encrypted as Teme stores it, and never put in Teme's import map, its messages or its logs. An account whose key pair is missing or doesn't match gets a new pair, and the run says so.

## The pages

With `kind`, the route answers a keyset page in the envelope the other list routes use: `{ "api": 3, "items": [...], "next": { "after_id": <last id> } | null, "limit": <limit> }`, items in ID order, `next` null on the last page.

| `kind` | Parameters | Each item |
|---|---|---|
| `followers` | `after_id`, `limit` (default 200, at most 200) | `{ id, guid, actor, following, inbox, acct, since }` for each `ap_actor` post that follows one of the site's accounts |
| `reply_sources` | `after_id`, `limit` (default 500, at most 500) | `{ id, source_id }` for each comment whose `protocol` meta is `activitypub` |

- **Followers:** `id` is the `ap_actor` post's ID; `guid` the remote account's address; `actor` the remote account's document, the post's `post_content` decoded from JSON; `following` the list of user IDs it follows, from `_activitypub_following`, or legacy `_activitypub_user_id`, with `0` for the blog; `inbox` from `_activitypub_inbox`; `acct` from `_activitypub_acct`; `since` the post's `post_date_gmt`. Teme keeps a follower only when the document's `id` is its `guid`, and its key and inboxes are on its own server.
- **Reply sources:** `id` is the comment's ID; `source_id` its `source_id` meta, the reply's fediverse address. Teme links it to the comment it imported, so a later Update or Delete from the fediverse finds that comment.

## Older posts' permalinks

`kind=permalinks&ids=12,20`, at most 200 IDs, answers `{ "permalinks": { "12": "https://example.com/2019/05/harbour-news/" } }`: `get_permalink()` for each published post named. Teme asks only for the posts at or below `activitypub_last_post_with_permalink_as_id`.

An unknown `kind` is refused with `400` and `rest_invalid_param`.

## How Teme uses it

1. `fediverse-replies` (order 710, after the comments): reads `reply_sources` a page a job, and links each source to the comment the import made.
2. `fediverse` (1010, after the settings): reads the whole answer once and writes the accounts, keys, settings and switch; then reads `followers` a page a job, and on the last page removes the followers WordPress no longer has, except one that followed again through Teme since; then gives each imported post of a federated type its old address, asking `permalinks` for the older ones.

A catch-up runs both again: the followers are replaced, and the accounts and their keys are left as they are.
