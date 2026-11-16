## Records (the builder's)

### Commits

- `5f6ce56` test(docs): expect insertions_off in fields reference and guide
- `dba54ef` docs(plugins): list insertions_off ID and regenerate fields reference

### Tests seen failing (expected)

- `GenerateFieldsReferenceTest` › keeps the committed fields reference in step with the command — generated output had `insertions_off` / `01K5Z0F1ED00000000000000XF`; committed `docs/reference/fields.md` did not.
- `DeveloperPagesTest` › names the No inserted content field by ID on the insertions page, and the contracts from 0.9.0 — page lacked the field ID and still said Teme 0.8.0.

### Fix

- Regenerated `docs/reference/fields.md` with `php artisan teme:docs:fields` (generator already listed feature fields; the committed file was stale).
- Updated `docs/guides/developers/plugins/insertions.md`: stored values use field ID `01K5Z0F1ED00000000000000XF`; contracts from Teme 0.9.0.

### Gate totals (`composer check`)

- Pint / PHPStan / types / npm check: passed
- Architecture: **139 passed** (1358 assertions)
- Unit+Feature parallel: **11712 passed**, 2 skipped, **1 failed** (`MediaFiltersTest` parallel flake — `media_live_created` plan assertion; **7 passed** alone)

### B-?? (draft)

- None.
