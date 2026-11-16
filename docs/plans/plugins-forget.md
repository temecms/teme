## Records (the builder's)

### Commits on `fix/plugins-forget`

- `ff7e51e` test(plugins): ForgetPlugin frees a gone package’s code
- `f3cf255` feat(plugins): forget a disabled plugin whose package is gone
- `74f3819` docs(plans): plugins-forget's records
- `e921812` test(plugins): ForgetPlugin refuses while tables remain
- `e34bf4a` fix(plugins): forget only after owned tables are gone
- `a303d9b3` docs(plans): plugins-forget records, follow-up R1

### Tests seen red (first line of the failure)

| Case | First failure |
|---|---|
| forgetting frees the code so a renamed package enables | `Target class [App\Domain\Plugins\Operations\ForgetPlugin] does not exist.` (after the enable-collision assertion held) |
| refuses while enabled | `BindingResolutionException` … `ForgetPlugin` does not exist (expected `ValidationException`) |
| refuses while package present | same `ForgetPlugin` missing |
| writes nothing without plugins.manage (×3) | same `ForgetPlugin` missing (expected `AuthorizationException`) |
| `teme:plugins:forget` asks unless `--force` | `The command "teme:plugins:forget" does not exist.` |
| offers Forget only when disabled and package gone | `Undefined array key "canForget"` |

### Gate

`export PATH=/opt/node24/bin:$PATH COMPOSER_ALLOW_SUPERUSER=1 && composer check` — exit 0.

- Pint: passed
- PHPStan: No errors
- `npm run types:check` / `npm run check`: pass (452 formatted, 436 lint-clean)
- Architecture: 139 passed (1359 assertions)
- Feature/Unit: 2 skipped, 11719 passed (60383 assertions)

### Follow-up R1

#### Commits

- `e921812` test(plugins): ForgetPlugin refuses while tables remain
- `e34bf4a` fix(plugins): forget only after owned tables are gone
- `a303d9b3` docs(plans): plugins-forget records, follow-up R1

#### Tests seen red (first line of the failure)

| Case | First failure |
|---|---|
| refuses ForgetPlugin while the plugin still owns tables | `Exception [Illuminate\Validation\ValidationException] not thrown.` |
| offers Forget only when disabled, package gone, and no tables | `Failed asserting that true is false.` (canForget still true with tables) |

#### Gate

`export PATH=/opt/node24/bin:$PATH COMPOSER_ALLOW_SUPERUSER=1 && composer check` — exit 0.

- Pint: passed
- PHPStan: No errors
- `npm run types:check` / `npm run check`: pass (452 formatted, 436 lint-clean)
- Architecture: 139 passed (1359 assertions)
- Feature/Unit: 2 skipped, 11720 passed (60392 assertions)

### B-?? (draft)

- none
