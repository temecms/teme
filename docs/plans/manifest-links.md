# Manifest links (homepage and author_url)

**One part, one branch: `themes/manifest-links`.** Optional `author_url` on plugin and theme manifests; optional `homepage` on themes; https validation; Plugins and Themes screens show the links.

## Records (the builder's)

### Commits on `themes/manifest-links`

- `1684f78` feat(contracts): optional author_url and theme homepage in manifests
- `65fc003` feat(plugins): validate author_url and homepage as https
- `f2aa9f8` feat(themes): validate author_url and homepage as https
- `9484bbd` feat(plugins): link the author to author_url on the Plugins screen
- `c3a5308` feat(themes): show author and homepage links on the Themes screen
- `a7b7a00` docs(examples): add author_url and homepage to example manifests
- `2c70d4c` docs: sync example blocks and theme reference with author_url

### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 2 | `tests/Feature/Plugins/ManifestTest.php` | Failed asserting that an array contains 'author_url must be an https address of at most 2000 characters.' |
| 2 | `tests/Feature/Plugins/ManifestTest.php` | Call to undefined method App\Domain\Plugins\Manifest::authorUrl() |
| 3 | `tests/Feature/Themes/ManifestPresentationTest.php` | Failed asserting that an array contains 'author_url must be an https address of at most 2000 characters.' |
| 3 | `tests/Feature/Themes/ManifestPresentationTest.php` | Call to undefined method App\Domain\Themes\Manifest::authorUrl() |
| 4 | `tests/Feature/Plugins/PluginsScreenTest.php` | Failed asserting that an array has the key 'authorUrl' |
| 5 | `tests/Feature/Themes/ThemesScreenTest.php` | Failed asserting that an array has the key 'author' |
| gate | `tests/Feature/Guides/Developers/DeveloperPagesTest.php` | The block for docs/guides/developers/examples/plugins/first-plugin/teme-plugin.json is not in that file. |
| gate | `tests/Feature/Guides/Developers/DeveloperPagesTest.php` | The block for docs/guides/developers/examples/themes/first-theme/teme-theme.json is not in that file. |
| gate | `tests/Feature/Themes/ThemeReferenceTest.php` | committed docs/reference/theme.md out of step with teme:docs:theme (missing author_url / homepage rows) |

### Gate

`export PATH=/opt/node24/bin:$PATH COMPOSER_ALLOW_SUPERUSER=1 && composer check` — exit 0

- PHPStan: No errors
- Pint / vp check: pass
- types:check: pass
- Architecture: 139 passed (1357 assertions)
- Unit + Feature: 2 skipped, 11596 passed (60007 assertions)

`composer check:browser` — exit 1

- Browser: 1 failed, 883 passed (8256 assertions)
- Failure: `Tests\Browser\MultilingualScreensTest > it moves a language up and down…` — Timeout 5000ms (unrelated to this part; no multilingual files touched). Retried alone: same timeout.
- This part’s screens: `tests/Browser/Plugins/PluginsScreenTest.php` and `tests/Browser/ThemesScreenTest.php` — 29 passed (305 assertions) / Themes alone 10 passed (95 assertions)

### Drafts

- **B-01 (draft):** `composer check:browser` fails on `MultilingualScreensTest` (“moves a language up and down”) with a 5s script timeout. Outside this part’s files; left as found. Plugins and Themes browser suites for this part pass.

### Contract changes

- `packages/teme-contracts/schema/teme-plugin.schema.json`: optional `author_url` (string, format uri)
- `packages/teme-contracts/schema/teme-theme.schema.json`: optional `author_url` and `homepage` (string, format uri)
- Regenerated: `docs/reference/plugin-manifest.md`, `docs/reference/theme-manifest.md`, `docs/reference/theme.md`

### Try it

1. Put `author`, `author_url`, and `homepage` (https) on a plugin or theme manifest.
2. Open Site › Plugins or Site › Themes: the author links out when `author_url` is set; a theme also shows Homepage when set.
