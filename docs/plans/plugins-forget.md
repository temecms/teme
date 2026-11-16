## Records (the builder's)

### Commits on `fix/plugins-forget`

- `ff7e51e` test(plugins): ForgetPlugin frees a gone package’s code
- `f3cf255` feat(plugins): forget a disabled plugin whose package is gone
- `7b5dc45` docs(plans): plugins-forget's records

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

### B-?? (draft)

- none
