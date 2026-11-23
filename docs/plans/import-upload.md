# Part 14a: Upload — records

The working brief for this part lived with the build session. Records below are the builder's report for review.

## Records (the builder's)

### Commits on `feat/1.4-upload`

1. `7c02241e` `feat(import): cap an export upload and keep it on the private disk`
2. `6ebb7a4a` `feat(import): upload an export file in pieces through Operations`
3. `c06c0f63` `feat(import): upload an export file in pieces from the browser`
4. `6df2a5eb` `feat(import): Add a site with an export-file upload on Tools › Import`
5. `09d89485` `fix(import): keep Add a site’s Close on the accent button`
6. (this) `docs(plans): import upload records`

### Tests seen red (then green)

- U1: `ImportFileStorageTest` — config key and private-disk path absent.
- U2: `ImportUploadTest` cases — Operations absent; concurrent pieces case first attempted a second PDO under RefreshDatabase (see B-?? 3), then asserted `FOR UPDATE` in the query log.
- U3: `upload-in-pieces.test.mjs` — client absent; Node path alias resolved with `npx tsx`.
- U4: HTTP / screen / Browser cases — controller, routes, ways, upload form and reading UI absent; AdminWords and design-system R14 (`animate-spin`) failed until `__()` and `Spinner`.
- U5 gate: `ToolsImportTest` axe (light and dark) — open way’s Close as outline/ghost failed colour contrast; fixed on the accent button (`09d89485`).

### Full gate (`export PATH=/opt/node24/bin:$PATH && composer check`)

- Pint: passed
- PHPStan: OK, no errors
- Architecture: **141 passed** (1473 assertions; 1 skipped)
- Unit+Feature (parallel): **13701 passed**, **27 skipped** (68720 assertions)
- Exit: **0**

### Browser (`composer check:browser`)

- First run after U4: **2 failed**, **975 passed** (9296 assertions) — both failures the Tools › Import axe case above.
- After `09d89485`: `tests/Browser/ToolsImportTest.php` **12 passed** (126 assertions).
- Full suite re-run: **977 passed** (9312 assertions). Exit **0**.

### B-?? (draft)

1. **Controller path.** The brief named `app/Http/Controllers/Admin/ImportFileController.php`. Import HTTP already lives under `app/Http/Admin/Tools/Import/`. Placed `ImportFileController` there with the other Import controllers.
2. **Finish state.** U2 said Finish sets `uploaded`; the 14a↔14b seam says set `reading` and dispatch `ReadImportFile` after commit. Followed the seam: peek and sha256 in the Operation, state `reading`, job after commit.
3. **Concurrent pieces.** A true two-connection lock wait does not fit RefreshDatabase’s outer transaction (second connection cannot see the uncommitted row). The case asserts the receive query uses `FOR UPDATE`; byte-identical retry covers write correctness.
4. **Plan open after upload.** The brief’s Browser case wants the plan open after read. `ReadImportFile` is still empty on `slice/1.4` (14b). The Browser case asserts the file reaches `reading` and stays there; opening the plan is 14b’s.

### Left open

- 14b fills `ReadImportFile`, moves the file to `ready`, attaches a source and opens the plan.
- 14d: changelog, roadmap, architecture docs, and any prune command the shared plan still wants.

### How to try it

On a local site: Tools › Import → Upload a file → drop a WordPress Tools › Export `.xml` (or choose it). Watch pieces and the reading state. A non-export `.xml` shows the refused banner. Connect a site is unchanged beside it.
