## Records (the builder's)

### Commits

1. `73543f8` `test(plugins): fail when use() mangles Windows absolute paths`
2. `22c8edd` `fix(plugins): treat Windows drive and UNC paths as absolute in fixtures`
3. (this) `docs(plans): fixtures-windows-path's records`

### Tests seen failing (expected)

- `PluginFixturesAbsolutePathTest` "keeps a Windows drive-letter path absolute…" (backslash) — config got `tests/Fixtures/plugins/C:\Users\…` instead of the given path
- same (forward slash) — config got `tests/Fixtures/plugins/C:/Users/…`
- "keeps a UNC path absolute" — config got `tests/Fixtures/plugins/\\fileserver\…`

### Full gate (`export PATH=/opt/node24/bin:$PATH && composer check`)

- Pint: passed
- PHPStan: OK, no errors
- types / lint / theme build: clean
- Architecture: **139 passed** (1358 assertions)
- Unit+Feature (parallel): **11715 passed**, **2 skipped** (60337 assertions). Exit **0**.
- No PR opened (assignment).

### B-?? (draft)

None.
