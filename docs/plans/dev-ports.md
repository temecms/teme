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

### Follow-up R1

IMPORTANT only: keep `DB_PORT`/`REDIS_PORT` `force="true"`; map `TEME_TEST_DB_PORT` / `TEME_VALKEY_TEST_PORT` in `tests/bootstrap.php` after phpunit applies env; point docs at those TEME_* names. Finding 3 (tautological force=false assertion) left as LATER. Stayed on `fix/dev-ports`; no merge of `origin/main`; no PR.

#### Commits

1. `e2f3525` `fix(dev): map TEME_* test ports in bootstrap; keep phpunit forced`
2. (this) `docs(plans): dev-ports' records, follow-up R1`

#### Tests seen failing

- `DevPortsTest` "keeps phpunit's DB_PORT and REDIS_PORT forced at today's defaults": expected `bootstrap="tests/bootstrap.php"` and `force` true on both ports; got `vendor/autoload.php` and `force="false"`. Failed for that reason before the phpunit + bootstrap change; green after.
- `DevPortsTest` "maps TEME_TEST_DB_PORT and TEME_VALKEY_TEST_PORT onto DB_PORT and REDIS_PORT in the bootstrap": child process `require 'tests/bootstrap.php'` failed (file missing) / would not rewrite ports. Failed for that reason before adding the bootstrap map; green after (`15433,16380`).

#### Full gate (`composer check` on `e2f3525`)

| Stage | Result |
|---|---|
| Pint | passed |
| PHPStan | OK (no errors) |
| `tsc --noEmit` | OK |
| `vp check` | 451 formatted, 435 lint-clean |
| Architecture | **139 passed** (1358 assertions) |
| Unit+Feature (`--parallel`) | **2 skipped**, **11714 passed** (60343 assertions) |

No Browser suite: no screen changed. No `B-?? (draft)`.
