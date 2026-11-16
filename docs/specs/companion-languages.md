# The companion's `languages` route

**For:** the Teme Companion plugin's maintainers (`github.com/temecms/teme-companion`).
**Read by:** Teme's import of a Polylang site (`docs/proposals/polylang-import.md`, I-3446).

Teme imports a site that uses Polylang with every language, every post and term in its language, at the address it had, its translations linked, its menus per language and its translated site title and tagline. Polylang keeps most of what Teme needs in one option, `polylang`, stored as serialized PHP. Teme never unserializes PHP (rule 4 of `AGENTS.md`), so the companion reads it with WordPress's own functions and sends it as JSON.

## Route

`GET /wp-json/teme/v1/languages` (or `?rest_route=/teme/v1/languages` on a site with plain permalinks).

- Administrators only, over HTTPS only, with the same application-password authentication and the same refusals as the other export routes (`teme_forbidden`, `teme_https_required`).
- A whole answer, not paged: a site has a few languages.
- Adding it bumps the companion's API version, so Teme can tell an older companion (which answers `404 rest_no_route`) from a site without Polylang.

## Answer

JSON, never serialized PHP. Every key is always present when Polylang is active.

```json
{
  "api": 1,
  "active": true,
  "plugin_version": "3.7.4",
  "default": "en",
  "languages": [
    { "slug": "en", "locale": "en_US", "name": "English", "rtl": false, "order": 0 },
    { "slug": "pt", "locale": "pt_BR", "name": "Português", "rtl": false, "order": 1 }
  ],
  "options": {
    "force_lang": 1,
    "hide_default": true,
    "rewrite": true,
    "redirect_lang": false,
    "browser": false,
    "media_support": false
  },
  "post_types": ["post", "page"],
  "taxonomies": ["category", "post_tag"],
  "menus": {
    "harbour": { "primary": { "en": 3, "pt": 4 } }
  },
  "strings": {
    "pt": [
      { "name": "Site Title", "translation": "O Diário do Porto" },
      { "name": "Tagline", "translation": "Notícias do cais" }
    ]
  },
  "others": {}
}
```

| Key | What it holds | Where Polylang keeps it |
|---|---|---|
| `active` | Whether Polylang is active | `function_exists('pll_languages_list')` |
| `plugin_version` | Polylang's version, as text | `POLYLANG_VERSION` |
| `default` | The default language's slug | `pll_default_language('slug')` |
| `languages` | Every language, in Polylang's order: its slug, its WordPress locale, its name, whether it is written right to left, its order | `PLL()->model->get_languages_list()` (`slug`, `locale`, `name`, `is_rtl`, `term_group`) |
| `options.force_lang` | How the language is set: `0` from the content, `1` from the directory, `2` from a subdomain, `3` per domain | the `polylang` option |
| `options.hide_default`, `rewrite`, `redirect_lang`, `browser`, `media_support` | Booleans, as Polylang's settings screen shows them | the `polylang` option |
| `post_types` | Every post type Polylang translates, **`post` and `page` included** | `PLL()->model->get_translated_post_types()` |
| `taxonomies` | Every taxonomy Polylang translates, **`category` and `post_tag` included** | `PLL()->model->get_translated_taxonomies()` |
| `menus` | Theme → menu location → language slug → menu ID, from Polylang's own menu settings, not the theme's | the `polylang` option's `nav_menus` |
| `strings` | Per language slug, the string translations: `{ "name", "translation" }` pairs, the name as Polylang registers it (`Site Title`, `Tagline`, a theme's or plugin's string) | `PLL_MO` for each language, with the names from `PLL_Admin_Strings::get_strings()` |
| `others` | The other translation plugins active on the site (below) | each plugin's own constants and options |

**When Polylang isn't active:** `{ "api": 1, "active": false, "others": {} }`.

### Other translation plugins

`others` is always present, whether Polylang is active or not: every other translation plugin active on the site, which Teme doesn't import in v1, so the analyze step can say so before an import starts. Keyed by the plugin's slug, each with its name and how many languages it has:

```json
"others": {
  "translatepress-multilingual": { "name": "TranslatePress", "languages": 3 },
  "multilingualpress": { "name": "MultilingualPress", "languages": 2 }
}
```

| Plugin | Active when | Its languages |
|---|---|---|
| TranslatePress (`translatepress-multilingual`) | `defined('TRP_PLUGIN_VERSION')` | `count(get_option('trp_settings')['translation-languages'])` |
| MultilingualPress (`multilingualpress`) | `class_exists('Inpsyde\\MultilingualPress\\MultilingualPress')` | the number of sites connected to this one, itself included |

An empty object when there are none.

## Menus: the language switcher

Polylang's language switcher is a menu item whose URL is `#pll_switcher`, with its options in the item's `_pll_menu_item` meta. The `menus` route adds them to that item as `pll_switcher`, so Teme reads them without unserializing anything:

```json
{ "id": 210, "url": "#pll_switcher", "label": "Languages", "pll_switcher": {
  "hide_if_no_translation": 0, "hide_current": 1, "force_home": 0,
  "show_flags": 1, "show_names": 1, "dropdown": 0 } }
```

Every option is sent as Polylang stores it (`0` or `1`).

## Per item

Teme reads each post's and term's `language` (its Polylang slug) and `translations` (slug → WordPress ID) from the existing `content` and `terms` answers, which already carry them.

## Not sent

Flags, the language cookie, browser-language redirects, and Polylang's copied values: Teme imports none of them (`docs/proposals/polylang-import.md`, item 9).
