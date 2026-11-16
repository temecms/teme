# Development ports from the environment

There is no plan file for this part: issue #341 and the bug brief were the plan. This file holds only the builder's records.

## Records (the builder's)

### Setup

- Branch `fix/dev-ports` from `origin/main` at `9b29abb`. PHP 8.4.27; `pg_isready` accepting on 5433; Valkey PONG on 6380. Gate: `export PATH=/opt/node24/bin:$PATH && composer check`.

### Commits

1. `3d41d22` `fix(dev): let the environment choose Compose and phpunit ports`
2. `fbd84b7` `docs(plans): dev-ports' records`

### Tests seen failing

- `DevPortsTest` "lets the environment choose the development database and Valkey ports": expected `${TEME_DB_PORT:-5432}:5432` (and the test/Valkey twins); got hardcoded `5432:5432` / `5433:5432` / `6380:6379`. Failed for that reason before the Compose change; green after.

### Full gate (`composer check` on `3d41d22`)

| Stage | Result |
|---|---|
| Pint | passed |
| PHPStan | OK (no errors) |
| `tsc --noEmit` | OK |
| `vp check` | 451 formatted, 435 lint-clean |
| Architecture | **139 passed** (1358 assertions) |
| Unit+Feature (`--parallel`) | **2 skipped**, **11712 passed** (60343 assertions) |

No Browser suite: no screen changed. No `B-?? (draft)`.
