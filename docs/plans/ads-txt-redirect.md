# Plan: ads.txt can redirect

Issue #329. Branch `site/ads-txt-redirect`.

## Records (the builder's)

### Commits

- `9c02782` feat(site-code): let ads.txt redirect to another host — steps 1 and 4 (cache store rule)
- `36c396f` test(site-code): refuse bad ads.txt redirect addresses — step 2
- `bafa92f` test(site-code): keep ads.txt text mode answering as before — step 3
- `c34f72b` test(site-code): cache and purge ads.txt redirect answers — step 4
- `79aaffa` feat(site-code): choose text or redirect for ads.txt — step 5
- `96ae033` style(site-code): format the ads.txt panel
- `ba24c36` fix(site-code): drop the ads.txt redirect placeholder — AdminWords
- `c4eed3f` fix(site-code): tie the ads.txt mode error to its radios — AccessibilityTest

### Each test seen failing

1. Step 1: `it saves a redirect mode…` — first `Unknown named parameter $mode`, then after Input gained mode: Expected 301 but received 404.
2. Step 2: refusals implemented with step 1; new tests passed on first run (validation already present).
3. Step 3: text-mode regression passed on first run.
4. Step 4: cache/purge test passed on first run (StoreRules change landed in step 1).
5. Step 5: browser test extended after UI; passed after `npm run build`. Gate also failed once on AdminWords (`https://` placeholder) and AccessibilityTest (InputError without `id=` / missing `aria-describedby`); both fixed.

### Gate totals

`export PATH=/opt/node24/bin:$PATH && composer check`

- Pint: passed
- PHPStan: OK, no errors
- types:check / npm run check: passed (after format fix)
- Architecture: **139 passed** (1363 assertions)
- Unit+Feature (parallel): **11585 passed**, 2 skipped, **1 failed** — `Plugins\DiscoveryTest` “it finds the configured folders…” saw leftover `tests/jobs` from another parallel worker. Same file **8 passed** alone; unrelated to ads.txt. An earlier full run also flaked once on `Media\MediaFiltersTest` EXPLAIN (`media_live_created`); that file passed alone too. Focused re-run: AdsTxtTest + NeverStoredTest + Architecture **171 passed**.
- `composer check:browser`: **883 passed**, **1 failed** — `MultilingualScreensTest` “it moves a language up and down…” timed out (5000ms); unrelated to ads.txt. This part’s ads.txt browser case in `InsertionPointsTest` **1 passed** after `npm run build`.

### B-?? (draft)

1. **B-?? (draft)** Brief said the page cache stores the redirect like the text; core’s StoreRules refused every redirect. Allowed a **marked** route’s **301** only (ads.txt keeps `MarkCacheable`), so ordinary address redirects stay `BYPASS (redirect)`.
2. **B-?? (draft)** Stored ads.txt as three settings keys (`ads_txt`, `ads_txt_mode`, `ads_txt_redirect`) in the existing `site_code` group — no free-form JSON setting type — written in one `UpdateAdsTxt` / `SettingsWrite`.
3. **B-?? (draft)** Redirect address checks (https only, other host, max 2000) live in `UpdateAdsTxt`, not `Setting::url`’s generic http/https max 2048 rules.

### STOP

None.

### Try it

On Settings › Site code, choose **Send to another address**, enter `https://ads.example/ads.txt`, save. `/ads.txt` answers 301 to that address; the page cache HIT keeps the Location. Switch back to **Text** and save a line of ads.txt; `/ads.txt` serves plain UTF-8 again.
