# The companion's extension route

**For:** the Teme Companion WordPress plugin, built separately. **Teme's side:** `app/Domain/Import/PluginData/`, tested against the test companion (`tests/Fixtures/Import/FakeCompanion.php`). **Why:** `docs/proposals/plugin-import-socket.md`.

A Teme plugin declares, in its manifest's `imports`, the WordPress data it brings across (`docs/architecture/plugins.md`, *Bringing a plugin's data from WordPress*). An import reads that data through one new route of the companion's export API.

## Version

The route arrives with the companion's export API **2**. `GET teme/v1/probe` answers `"api": 2`. Teme asks the route only when the source's probe answered 2 or more; otherwise its plugin steps report "The companion on this site is too old for plugin data" and skip, without stopping the run.

## The route

`GET teme/v1/extension`, behind the same guard as every other route of the export API (an administrator's application password, over HTTPS where the site requires it).

Every request names `plugin`, the code of the Teme plugin it is read for. The companion ignores it: it is there so that two plugins asking the same question are two lists in Teme's import map.

| `kind` | Other parameters | Answer |
|---|---|---|
| `options` | `names=a,b` | `{ "options": { "a": <json>, "b": <json> }, "objects": ["b"] }` |
| `posts` | `type`, `meta=k1,k2`, `after_id`, `limit` (default 200, at most 200) | a keyset page of `{ id, type, status, guid, title, content, date_gmt, author, parent, meta: { k1: [values] }, objects? }` |
| `usermeta` | `keys=k1`, `after_id`, `limit` (default 500, at most 500) | a keyset page of `{ id, meta: { k1: [values] }, objects? }` for each user with at least one of the keys |
| `termmeta` | `keys=k1`, optional `taxonomy`, `after_id`, `limit` (default 500, at most 500) | a keyset page of `{ id, taxonomy, meta: { k1: [values] }, objects? }` for each term with at least one of the keys, of every taxonomy unless `taxonomy` names one |

A keyset page is the envelope the other list routes use: `{ "api": 2, "items": [...], "next": { "after_id": <last id> } | null, "limit": <limit> }`, items in ID order, `next` null on the last page.

- **Posts** are every post of `type` in any status but `auto-draft` and `trash`. `date_gmt` is `Y-m-d H:i:s`; `author` and `parent` are WordPress IDs, 0 for none.
- **Meta** holds only the keys asked for, each with the list of its values in `meta_id` order (WordPress meta can hold one key several times). A key the record lacks is left out. An option WordPress doesn't have is `null`.

## What the companion refuses and decodes

- **Never sent, whatever is asked:** `user_pass`, `session_tokens`, `_application_passwords` and `wp_user_roles`. A request naming one is refused with `400` and the code `teme_never_sent`, and nothing is read. Teme's manifest validator refuses them first.
- **No serialized PHP crosses** (rule 4). Each value goes through `maybe_unserialize()` with objects not allowed, then JSON. A value that held a PHP object anywhere is sent as `null`, and its name is listed in `objects` (the option's name, or the meta key, once per record). Teme reports it in the run.
- **Through WordPress's own functions**, never a table read directly: `get_option()`, `WP_Query` with `get_post_meta()`, `get_user_meta()`, `get_term_meta()`. A plugin's own tables are not read in this version.
- Unknown `kind`: `400` with `rest_invalid_param`.

## How Teme uses it

1. `plugin-options` (order 540): for each plugin, one `kind=options` request, handed whole to its importer's `options()`.
2. `plugin-data` (550): each declared post type, then `usermeta`, then `termmeta`, a page at a time; each item to `record()` in its own transaction with its map row, `plugin:<code>:<kind>:<type>:<id>`. A catch-up skips an item whose hash is unchanged.
3. `plugin-data-gone` (560, catch-ups only): the same lists read again, compared with the map; a record WordPress no longer has is reported, nothing deleted.
