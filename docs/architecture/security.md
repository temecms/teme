# Sign-in and admin security

How Teme authenticates people and protects the admin. The evidence and the rejected alternatives are in `docs/studies/admin-security.md` (study G); the promises this implements are `SECURITY.md` commitments 10 to 13. This document describes the system as built. The slice 1.2c code is checked against it.

## The pieces, and where each lives

| Concern | Where | Study G |
|---|---|---|
| Hashing | `App\Domain\Users\Security\PasswordHasher`, registered as the `teme` hashing driver | S2 |
| Hash upgrade | `App\Http\Admin\Auth\UpgradeHashAfterSignIn`, the last step of Fortify's sign-in pipeline, and `UpgradePasswordHash` | S2 |
| Password policy | `Password::defaults()`, read from the `security` settings group | S3 |
| Breached-password check | `App\Domain\Users\Security\BreachedPasswordVerifier` | S3 |
| Sign-in throttling | `App\Http\Admin\Auth\ThrottleSignIn`, the first step of Fortify's sign-in pipeline | S4 |
| Trusted device | `App\Http\Admin\Auth\DeviceCookie` | S4 |
| Trusted proxies | `config('app.trusted_proxies')`, resolved by `TrustedProxies::resolve()` and applied in `DeploymentServiceProvider::boot()` | S5, I-3001 |
| Response-time floor | `App\Http\Admin\Middleware\EnforceResponseTimeFloor` | S6 |
| Neutral reset answer | `App\Http\Admin\Auth\NeutralPasswordResetLinkResponse` | S6 |
| Reset mail | `App\Domain\Users\Notifications\ResetPasswordLink`, queued | S6, S7 |
| Elevation | `Teme\Contracts\Operations\RequiresElevation`, checked in `BaseOperation` | S8 |
| Session lifetime | `App\Http\Admin\Middleware\EnforceSessionLifetime` plus Laravel's `AuthenticateSession` | S9 |
| Response headers and CSP | `App\Http\Admin\Middleware\AdminSecurityHeaders` | S13 |

`App\Providers\SecurityServiceProvider` wires all of it.

**The `admin` middleware group, in order.** The order is load-bearing:

1. `EnforceResponseTimeFloor` — outermost, so everything inside it counts toward the floor
2. `ApplyRememberPolicy`
3. `BindSessionElevation`
4. `AdminSecurityHeaders` — outside the session checks, so their redirects carry the headers too
5. `EnforceSessionLifetime`
6. `AuthenticateSession`
7. `EnsureAccountIsActive`
8. `RequireTwoFactorEnrolment`
9. then the starter kit's `HandleAppearance`, `HandleInertiaRequests` and `AddLinkHeadersForPreloadedAssets`

## Hashing

New passwords are hashed with **Argon2id**, using Laravel's defaults of 64 MiB, t=4 and p=1. They are re-tuned on the target server in slice 1.13.

bcrypt is not used because it silently ignores everything after the 72nd byte. Two passwords that differ only in their 73rd byte verify as the same password. A test proves Teme does not do this.

The driver **dispatches on the stored hash's exact shape** (`PasswordHashFormat::detect()`), in WordPress's own order: length, then prefix.

| Format | Shape | Handling |
|---|---|---|
| Argon2id | `$argon2id$…` | Verified. The only format that is written |
| bcrypt | `$2y$`, cost 04 to 16 | Verified. From before 1.2c, or another Laravel application |
| WordPress bcrypt | `$wp$2y$`, cost 04 to 16 | Verified: bcrypt of the base 64 of the password's HMAC-SHA384 under `wp-sha384`. WordPress 6.8 and later |
| phpass | `$P$`, 2^7 to 2^16 rounds | Verified by Teme's own port of phpass's portable hash, compared with `hash_equals()`. WordPress 2.5 to 6.7 |
| MD5 | 32 lowercase hexadecimal characters | Verified with `hash_equals()`. WordPress before 2.5 |
| anything else | | Refused, even where PHP's `password_verify()` would accept it (DES, MD5-crypt, SHA-crypt, `$2a$`, `$2b$`, Argon2i) |

WordPress's three formats are checked against the password with the spaces around it trimmed, as WordPress did, and phpass and WordPress bcrypt refuse a password over 4,096 bytes. No format costs more than 2^16 rounds, so one imported row can't make every sign-in attempt slow.

**Rehash on sign-in stays off** (`hashing.rehash_on_login = false`), because Laravel's rehash writes the `password` column from vendor code. Instead the sign-in pipeline's last step, `UpgradeHashAfterSignIn`, calls the `UpgradePasswordHash` Operation whenever the signed-in person's hash isn't Argon2id at the configured costs (I-124). The Operation checks the password again under the row's lock, writes Argon2id of the password (a WordPress password without the spaces around it, as WordPress kept it; Teme's own as typed), and records `password.hash_upgraded` with the format it came from. Sessions, the remember token and a pending reset link are kept, and no mail is sent. A sign-in that fails, is throttled or waits for a second factor upgrades nothing.

## Passwords

- The minimum length is `security.password_min_length`, default **15**. The maximum is 256. There are no composition rules and no expiry (NIST SP 800-63B §3.1.1.2).
- **Breached-password check.** When `security.password_check_breached` is on, a new password is checked against Have I Been Pwned by k-anonymity range query.
  - Only the first five characters of the SHA-1 hash leave the server.
  - The request times out after **3 seconds**.
  - If the service fails or times out, the check **fails open** and writes a warning to the log. An outage must never stop an editor setting a password.
- The policy applies when a password is **set**, never at sign-in. Accounts that predate a stricter policy keep working until their owner next changes the password.

## Throttling (commitment 11)

Three counters, all checked **before** any password hash is computed:

| Counter | Key | Default | Setting |
|---|---|---|---|
| Account and IP | lower-cased email + IP | 5 per 15 minutes | `security.login_attempts_per_account_ip` |
| IP | IP | 50 per hour | `security.login_attempts_per_ip` |
| Account, untrusted clients | lower-cased email | 10 per hour | `security.login_untrusted_attempts_per_account` |

The third counter does not apply to a client carrying a valid **device cookie** for that account. The cookie is issued after every successful sign-in and lasts one year. It is encrypted by Laravel's cookie encryption, which also authenticates it, and it carries only a hash of the email address.

An attacker working through stolen passwords from unknown machines exhausts the third counter. The account's owner, on a browser they have used before, is not stopped by it. That is commitment 11.

A counter is keyed on what was submitted, never on whether an account exists. The throttled answer is therefore the same for real and invented addresses.

A successful sign-in clears the account-and-IP counter. A successful password reset clears the untrusted-account counter.

**Trusted proxies** come from `TRUSTED_PROXIES`, a comma-separated list of addresses, ranges and two names: `private` (10.0.0.0/8, 172.16.0.0/12 and 192.168.0.0/16, the ranges Docker networks use) and `cloudflare` (Cloudflare's published ranges). **Unset**, production trusts `private` and every other environment trusts nothing; **set**, even to empty, it means exactly what it says (I-3002). `*`, `/0`, `REMOTE_ADDR`, `PRIVATE_SUBNETS` and anything that isn't an address or a range are refused at boot. Trusting every proxy would let any client set `X-Forwarded-For` and walk past every per-IP counter. Behind Cloudflare and Traefik, set `TRUSTED_PROXIES=private,cloudflare`: Traefik appends Cloudflare's address, so Laravel must trust Cloudflare too, or every reader looks like Cloudflare.

## No enumeration (commitment 10)

- **Sign-in:** every failure has one message. Throttling does not depend on whether the account exists (above).
- **Reset request:** success, unknown address and throttled all get the same answer: *"If an account exists for that address, we have sent a password reset link."* Fortify's two response contracts are both bound to `NeutralPasswordResetLinkResponse`.
- **Timing:** Laravel's guard timebox covers only its own password check. Fortify's two-factor step checks the password outside it, so it answers an unknown address before it would finish hashing. `EnforceResponseTimeFloor` therefore holds these routes to at least `auth.timebox_duration`:
  - `login.store`
  - `password.email`
  - `password.update`
  - `two-factor.login.store`

  The value is set above the measured Argon2id time on the machine in question, and re-measured in 1.13.
- **Reset mail is queued**, so sending it adds no time that depends on whether the account exists.

## Reset links

- Laravel's broker issues them: they are valid for 60 minutes and single use.
- The link is built from `APP_URL`, **never from the request's `Host` header**. Otherwise a forged header puts an attacker's domain into a genuine reset email.
- The reset page sends `Referrer-Policy: no-referrer`, so the token never leaks to a linked site.
- There is no automatic sign-in after a reset.
- A reset **ends every session** (below) and queues a notice to the account's address.

## Elevation (commitment 12)

An Operation that changes something sensitive implements `Teme\Contracts\Operations\RequiresElevation`:

```php
public function requiresElevation(OperationInput $input): bool;
```

`BaseOperation` asks this after `authorize()` and before `validate()`. When the answer is yes, the bound `App\Domain\Support\Security\Elevation` must confirm a fresh proof of identity. If it cannot, `ElevationRequiredException` is thrown and nothing is written.

| Binding | Where it applies | Satisfied by |
|---|---|---|
| `DeniedElevation` | **Default** — queue jobs, API, AI and anything else | Never |
| `SessionElevation` | Bound per request by the `admin` middleware group | A password confirmation within `security.elevation_minutes` (default 15), by the same user who is signed in |
| `OperatorElevation` | Bound explicitly by `teme:*` console commands (slice 1.2d) | Always: the operator has a shell |

A token can never satisfy elevation, because nothing binds a token-aware elevation. Adding one would be a change to this document and to commitment 12.

**Sensitive in 1.2:**

- `UpdateProfile`, when the email address changes.
- `UpdateSettings` on any group declared `sensitive` (the `security` group).
- The 1.2d user Operations: `InviteUser`, `ChangeUserRole`, `SuspendUser`, `DeleteUser`.
- Site code's three Operations, `SaveSiteCode`, `SwitchSiteCode` and `DeleteSiteCode`, always (I-864, I-1086): the code runs in every reader's browser, and in every signed-in editor's.
- Two-factor changes and the Security page are guarded by Fortify's own password-confirmation middleware. `auth.password_timeout` is set to 900 seconds so that window matches.

`ChangePassword` is not marked. It demands the current password, which is itself the fresh proof.

In the browser, `ElevationRequiredException` redirects to the password confirmation page. It remembers the page the form was on, not the form's own URL, and returns there afterwards. For JSON requests it answers `423`.

## Sessions

| Rule | How |
|---|---|
| Idle limit, `security.session_idle_minutes` (120) | `EnforceSessionLifetime` compares the session's last-seen time |
| Absolute limit, `security.session_absolute_hours` (24, at most 24) | `EnforceSessionLifetime` compares the sign-in time, which is recorded on Laravel's `Login` event |
| Remember-me, `security.session_remember_days` (14; 0 turns it off) | The `ApplyRememberPolicy` middleware sets the guard's remember duration on every admin request, so the two-factor challenge, which completes sign-in on a later request, gets the same duration. When the setting is 0, it ignores the checkbox |
| A password change or reset ends every other session | Laravel's `AuthenticateSession` compares each session's stored password fingerprint. The session that made the change is refreshed and survives |
| Cookies are `HttpOnly` and `SameSite=Lax` | `config/session.php` |
| The `teme_auth` marker, which sends a browser past the page cache (1.7a, I-465) | `SignedInMarker`: `HttpOnly`, `SameSite=Lax`, for the browser session, encrypted like the other cookies; set on every `Login` event and cleared on `Logout`. Only its presence is read (`docs/architecture/page-cache.md`) |
| `Secure` and the `__Host-` name | In production at an https `APP_URL`, every cookie is `Secure` and the session cookie is named `__Host-…` (I-3007). The `teme_auth` marker is `Secure` but keeps its name, which nginx, Caddy and the page cache read |

`session.lifetime` is set to 1440 minutes, the ceiling. The idle setting is enforced by Teme itself, below that ceiling. Settings never feed `config()`.

## Admin response headers (S13)

Every response in the `admin` middleware group carries:

- `Content-Security-Policy`, with directives:
  - `default-src 'self'`
  - `script-src 'self' 'nonce-…'`
  - `style-src 'self' 'unsafe-inline'`
  - `img-src 'self' data:`
  - `font-src 'self'`
  - `connect-src 'self'`
  - `object-src 'none'`
  - `base-uri 'self'`
  - `form-action 'self'`
  - `frame-ancestors 'none'`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`, or `no-referrer` on the reset page

**Scripts are strict.** Every script is either served from the site or carries the per-request nonce, which `Vite::useCspNonce()` stamps on Vite's tags. The blade layout's inline script carries it too.

**Styles allow inline.** Components inject `<style>` elements at run time, and style injection is a far smaller risk than script injection. This is revisited when the editor arrives in 1.5. That editor must fit the script policy, never loosen it.

While `npm run dev` is running, the Vite dev server's origin is added to `script-src` and `connect-src`. This happens only in the local environment.

The public site has its own policy. The `admin` group never runs on it.

### Public response headers

Every public page carries a content security policy (I-3375), built in `App\Domain\Site\Policy\PagePolicy` and sent by `PublicPage`. Feeds, sitemaps, text answers, the `.md` copies and the read API carry none: they are not documents a browser runs.

- **Hashes, never nonces** (I-498). A nonce changes on every request, and a stored page can't hold one. Core's own inline scripts go through the `<x-teme::inline-script>` component, which hashes exactly what it prints. Site code is hashed as it was saved. Nothing is ever hashed by reading the finished page, since that would approve an injected script too.
- **Scripts:** `'self'`, those hashes, and the hosts that were declared. No `'unsafe-inline'`, no `'unsafe-eval'`, no `*`. `'unsafe-hashes'` appears only when site code has `on…` handlers, each by its own hash.
- **Styles** keep `'unsafe-inline'`: images set their focal point in a `style` attribute.
- **Also sent:** `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`.
- **Who may add a host:** a theme, in the `csp` member of its manifest; a site-code entry, by someone who may manage site code, with the password asked again and the change in the security log. Hosts found in an entry's own code are allowed without being declared. Every host is checked as a plain host name before it reaches the header, and again as the header is written: one that could change the policy is dropped and reported, never repaired. A plugin's `contributes.csp` is reserved and not yet open.
- **The mode** is `TEME_PUBLIC_CSP`: `report-only` (the default: nothing is blocked), `enforce`, or `off`. A value Teme doesn't know reads as `report-only`, and the doctor names it.
- **The page cache** stores the header with the page, so a stored page sends the same policy as a fresh one. A file the web server serves itself can't carry a header from PHP: under `enforce` it carries the policy in a `<meta>` element, which can't hold `frame-ancestors` or a report address; nginx's `X-Frame-Options: SAMEORIGIN` covers framing there.
- **Reports** go to `POST /_teme/csp`, which anyone can call. It answers the same bare 204 whatever it was sent, is rate limited, reads at most 16 KB, keeps no address or browser of the sender, drops any report holding a control character, and writes to a spool that a scheduled Operation records into a capped table. `teme:csp:reports` lists them; they never enter the security log, which strangers could otherwise fill.

## Settings

Group `security`, which is `sensitive`, so every change needs elevation. Its ability is `settings.security.manage`, held by administrators only.

| Key | Type | Default | Range |
|---|---|---|---|
| `password_min_length` | number | 15 | 8–64 |
| `password_check_breached` | boolean | true | |
| `login_attempts_per_account_ip` | number | 5 | 1–100 |
| `login_attempts_per_ip` | number | 50 | 1–10000 |
| `login_untrusted_attempts_per_account` | number | 10 | 1–1000 |
| `session_idle_minutes` | number | 120 | 5–1440 |
| `session_absolute_hours` | number | 24 | 1–24 |
| `session_remember_days` | number | 14 | 0–30 |
| `elevation_minutes` | number | 15 | 1–60 |
| `two_factor_required_roles` | multiselect of roles | none | 1.2d |

Study G's dotted names, such as `security.password.min_length`, are flattened because setting keys have no dots. The group's key supplies the `security.` prefix.

**Deployment configuration, never settings:** trusted proxies, the hashing driver and its cost, the breached-check timeout, the response-time floor, cookie flags and the CSP's base policy and mode. A compromised administrator must not be able to weaken them from a browser. The origins a page's policy lists come from the theme's manifest, plugins' manifests and each site-code entry, which an administrator who may manage site code already controls.

## Users (slice 1.2d)

Accounts are **invited**; nobody signs themselves up (I-101), and an administrator never sets or sees another person's password (S11).

| Operation | Elevation | Guards |
|---|---|---|
| `InviteUser` | yes | Creates the account with an unusable password and `invited_at` set. It queues an invitation link, which is valid for 7 days, through the `invitations` password broker |
| `ResendInvitation` | no | Only while the invitation is pending |
| `AcceptInvitation` | none; the token authorises it | Sets the password, marks the email verified, and clears `invited_at` |
| `ChangeUserRole` | yes | Not your own role; never the last active administrator. Ends the person's sessions |
| `SuspendUser` | yes | Not yourself; never the last active administrator. Ends their sessions. A suspended person who signs in is signed straight out, with a message |
| `DeleteUser` | yes | Not yourself; never the last active administrator. Content and revisions are **reassigned** to a named person first, and author foreign keys are restrict-on-delete, so nothing is silently orphaned (I-102) |
| `IssuePasswordResetLink` | the operator only | For `teme:user:reset-link` |

A completed password reset also counts as accepting an invitation. The person has proved they hold the mailbox.

*Ending sessions* means two things:

- rotating `remember_token`;
- deleting the person's rows from `sessions` when the session driver is `database`.

A production site must use the `database` driver, so ending sessions always works there: the start rules refuse any other (I-3010).

**Operator recovery (S12):**

| Command | What it does |
|---|---|
| `php artisan teme:user:create` | Invites a person, the first administrator included, and prints their set-password link. `--send` also emails it |
| `php artisan teme:user:reset-link <email>` | Prints a one-time reset link, valid for 60 minutes |

Both commands act as **the operator** (`docs/architecture/operations.md`). They work when mail does not.

## Two-factor by role (I-115)

`security.two_factor_required_roles` lists roles whose members must have confirmed two-factor authentication. It is empty on every install.

When a member of a listed role has not set it up, every admin page sends them to the Security page to do so. Only these routes stay reachable:

- the Security page itself;
- the two-factor setup routes;
- password confirmation;
- email verification;
- sign-out.

A grace period is study G's 6.4 item, so it is parked.

## Security event log (I-118)

`security_events` is a **telemetry sink** (`docs/architecture/operations.md`). It is written only by `App\Domain\Security\SecurityLog`, and nothing decides anything from it.

- **Counted events** are aggregated into one row per type, subject, address and hour. There is never one row per attempt, because bots probe constantly. These are:
  - `signin.failed`
  - `signin.throttled`
  - `two_factor.failed`
- **Every other event** is its own row:
  - sign-ins;
  - password changes and resets;
  - two-factor enabled, disabled, and recovery codes;
  - invitations, role changes, suspensions and deletions;
  - security-settings changes;
  - redirects added, changed, switched and deleted (`redirect.created`, `redirect.changed`, `redirect.switched`, `redirect.deleted`), and a CSV file imported, one event for the file with how many lines it added and how many it left out (`redirect.imported`); I-694, I-1053;
  - site code added, changed, switched on, switched off and deleted (`site_code.*`; *Site code*, below);
  - backups set up, places added, changed and removed, and recovery keys added, replaced and checked (`backup.*`), with labels and counts, never a key (`docs/architecture/backups.md`, *Keys*);
  - a site export made at the command line (`export.made`), one event for the whole export with its folder and counts, never its contents (`docs/reference/export.md`; I-2407);
  - the page cache cleared by hand from the admin (`page_cache.cleared`; *Permissions for search, redirects, the page cache and site code*, below);
  - mail that failed to send.
- The subject is either `user:<id>` or, for an address with no account, a keyed hash of the address. **Typed addresses are never stored.**
- Rows older than 365 days are pruned daily by `teme:security-log:prune`.
- There is no screen until 6.4. The one exception is the users list, which shows *Invitation email failed* on the person's row (D4, in place of the jobs indicator).

## Commenters (slice 1.8a)

Readers comment without accounts (I-268), so Teme keeps a little about people who aren't its users (`docs/architecture/comments.md`):

- **What they give:** a name, an email address, stored lower-cased, and a website. A signed-in writer's name and email address are their account's, whatever the input says.
- **Their browser's token, as its SHA-256, never the token.** It is how a returning guest is recognised (I-275); a name and email address alone, which anyone can type, never are.
- **The IP address and the browser's user agent**, for judging spam. From 1.8c only `comments.moderate` holders see them, and from 1.8d they are erased `keep_ip_days` after the comment arrived, imported ones too, by `teme:comments:erase-addresses`, daily, as the operator (I-270, 1.8d's D13). Neither comment email carries an IP address, a browser or an email address (E5, E6).
- **A guest can't write under an account's name or email address:** `SubmitComment` refuses either, and asks them to sign in (Drupal's rule), so nobody signs a comment as one of the site's own people.
- **The rate limit:** five comments in ten minutes from one IP address, and five from one email address, counted in the cache store as sign-in's are (`CommentThrottle`), the email address hashed in its key. Whoever looks after the item's comments passes it by (D13).
- **Deleting a comment for good erases its writer:** a comment with replies leaves a tombstone that keeps its place in the thread and nothing that says who wrote it or from where (I-278).

## Permissions for search, redirects, the page cache and site code

Slice 1.9 added four permissions, named as the built ones are, `{area}.{verb}` or `settings.{group}.manage`, not by the `tools.*` names the page cache's and redirects' permissions were first given (1.9a's plan, `docs/superpowers/plans/2026-10-07-phase1-slice09a-foundation.md`, D15 is I-1024; I-850):

| Permission | Held by | Changes |
|---|---|---|
| `settings.seo.manage` | administrators | Settings › Search and social, the `seo` group. It can hide the whole site, so editors don't hold it |
| `redirects.manage` | editors and administrators (I-694) | the redirects: `CreateRedirect`, `EditRedirect`, `SwitchRedirects`, `DeleteRedirects` and `ImportRedirects`, and with them Tools › Redirects and the 404 log's actions |
| `page-cache.clear` | administrators (I-467) | clearing the page cache from the admin: `ClearPageCache`, behind Tools › Page cache (below) |
| `site-code.manage` | administrators (I-288) | site code (below) |

They come to a fresh site through the migration `2027_01_26_100100_add_roles_to_new_sites.php` (slice 1.13c), which on a database with no role makes the four roles from `RoleSeeder`’s lists, each with those of its permissions the earlier migrations have made; `RoleSeeder` stays for tests and development. They come to an existing site through one add-only migration, `2026_10_25_100000_add_seo_permissions.php`, never by running the seeder again, which resets every role it names (I-449). The migration creates each permission and gives it to the roles that hold it where each exists; a grant an administrator made is kept, no role is created, and a second run changes nothing.

**`ClearPageCache`** (1.9h, `docs/superpowers/plans/2026-10-08-phase1-slice09h-tools-screens.md`, D1 to D16 are I-1140 to I-1155; D5 is I-1144) is not elevated: clearing costs only speed, so it does not ask for the password again. It records `page_cache.cleared` in the security log, with the person, so Tools › Page cache can say who cleared the cache last here, and it is refused while the page cache is off, when a record would describe a change that never happened. The operator's `teme:cache:clear` is the shell's and records nothing.

## Redirects

Slice 1.9c built redirects (`docs/superpowers/plans/2026-10-07-phase1-slice09c-redirects-and-404-log.md`, D1 to D20 are I-1043 to I-1062), and their screens are 1.9h's. A redirect can send readers off the site, so what an editor may point one at is limited when it is saved (I-694, I-1048): an address on this site, another site's `http` or `https` address, an item by ID or a media file by ID. A target starting `//` or `/\` is refused, as is one with a control character (browsers drop a tab or line break inside an address, so `/<tab>/evil.example` would otherwise read as `//evil.example`) or any scheme but `http` and `https`. Editors may send readers to another site, not only administrators (I-694); such a redirect is stored as leaving the site (`target_kind` `outside`), so a list can mark it. Every change goes through an Operation holding `redirects.manage`, takes one advisory lock so two saves cannot together make a chain, and is recorded in the security log with the redirect's ID, source, target in words and status, and, for a change, what each was before (I-1053).

- **Import and export** (I-872, I-1060): a CSV file of at most 5,000 lines and 2 MB, UTF-8, each line checked as one added by hand, and one that fails is left out and reported with its line number. Export writes a cell that starts `=`, `+`, `-`, `@`, a tab or a carriage return with a `'` in front, so a spreadsheet never runs a note as a formula, and the import takes the `'` off again. The nginx export quotes every address and expression.
- **The 404 log keeps nothing about a reader** (I-298): an address, how often, when first and last, and the host of the `Referer` alone, lower-cased and only when it is a host; never its path or query, an IP address or a browser. An address that is not UTF-8 or holds a control character is never kept.
- **The counts are the operator's.** A reader's request writes no row: it appends a line to a file, and `RecordAddressCounts` writes the tables once a minute as the operator, which no one else can (`docs/architecture/operations.md`, *The operator*).

## Importing a WordPress site

Slice 1.11's importer connects to a site an administrator names and reads it with that site's application password, so it is held to three rules (`docs/architecture/import.md`):

- **Who:** holders of `imports.run`, administrators, or the operator at the shell; starting or carrying on a run asks a person's password again.
- **The public-address check:** `PublicAddresses::check()`, before every connection the importer and the downloader make: http or https only, no credentials in the address, every resolved address public unless `teme.import.local_sources` is on (only where `APP_ENV` is `local`), plain http to a public host refused to the importer (the downloader, which sends no credential, may fetch a file over it), and the first address pinned; the client follows no redirect and uses no proxy (`docs/architecture/import.md`, *Where Teme connects: the public-address check*).
- **The log:** `import.source_connected`, `import.started`, `import.ended` and `import.source_forgotten`, never one per object.

## Site code

Administrators add code to every public page: analytics, a tag manager, a chat widget. It runs in every reader's browser and in every signed-in editor's, so it is held to the strictest rules in this document (I-288). Slice 1.9e built it (`docs/superpowers/plans/2026-10-07-phase1-slice09e-site-code.md`, D1 to D11 are I-1083 to I-1093).

- **Who:** holders of `site-code.manage`, administrators (I-850). There is no per-item code: an embed in one article is a card.
- **What:** `site_code_entries`, one row an entry (rule 2): a ULID, a name of at most 120 characters, a placement (`head`, `body-start` or `body-end`, held by a `CHECK`), the code as `text`, whether it is on, who changed it last, and timestamps. Entries are drawn and listed in the order they were added (I-1085).
- **Three Operations,** `SaveSiteCode`, `SwitchSiteCode` and `DeleteSiteCode`, each `RequiresElevation`, so each asks for the password again unless it was confirmed within `security.elevation_minutes` (I-864, I-1086). A switch carries no code, so it can never overwrite an edit someone saved meanwhile. Each locks the entry before it reads it; a save or a switch that changes nothing writes nothing, logs nothing and purges nothing.
- **Kept exactly as written** (I-865, I-1088): no stripping, escaping or normalising, `on*` attributes and JSON-LD scripts included; only the name is trimmed. The code must be UTF-8 without a NUL and at most 65,535 characters. An entry holding a `<script>` of type `application/ld+json` is flagged on the screen, never refused, since it would make a second graph beside Teme's one.
- **Every change is in the security log,** five types, none counted: `site_code.added`, `site_code.changed`, `site_code.switched_on`, `site_code.switched_off` and `site_code.deleted`. The user is the person who made the change. The context is flat, scalars only: `entry`, and `before_` and `after_` each followed by `name`, `placement`, `on` and `code`, null where there was nothing (I-1087). The screen lists the eight newest for display only; the log keeps each for 365 days.
- **The host's switch.** `TEME_DISALLOW_SITE_CODE` (`config('teme.disallow_site_code')`, false by default, read at each use; I-1089) refuses every change **in authorization**, before any password is asked, as WordPress maps `unfiltered_html` to `do_not_allow` under `DISALLOW_UNFILTERED_HTML`; and it stops drawing, where WordPress's constant leaves saved HTML on the page: `SiteCodeFragments` gives nothing, so no page draws an entry. The screen shows every entry, read-only, with the host's notice. **Pages already stored keep the code they were drawn with until the page cache is cleared:** after setting or unsetting it, run `php artisan teme:cache:clear` (`docs/architecture/page-cache.md`, *The commands*), until 1.13's `teme:deploy` does.
- **How it reaches a page.** Through the head, body-start and body-end placements, at `Precedence::Site` (`docs/architecture/themes.md`, *Placements*; `docs/architecture/plugins.md`, *Plugins and themes*). `SiteCodeEntries::on()` is one cached read of the entries that are on (`Cache::memo()`, kept until an Operation forgets it after its commit), plain arrays of text only, so the `cache` table holds nothing I-261 doesn't allow; one read a page drawn uncached (I-1092). A change purges every stored page only when pages draw it: when the entry was on before or is on after (I-1093).
- **The screen** is Settings › Site code, `/admin/settings/site-code`, the last tab among the settings (I-863, I-1090), behind `can:site-code.manage`: an editor gets 403. Looking at it asks for no password, since looking changes nothing and the host's read-only mode must open without one.

### Code in a region's Custom HTML card

A theme region's Custom HTML card (`site-code`) holds its own code, such as one ad unit, in its attributes: `code`, and the `origins` (hosts) it connects to. It has no on or off: removing the card takes the code off the site, and the region's history brings it back. Revisions therefore hold it, and the same rules as site code's apply to it on every path, not only in the editor. `App\Domain\SiteCode\RegionCode` reads, compares, hides and carries it.

- **Who:** only someone who may manage site code (`RegionCode::manages`: `site-code.manage`, and the host hasn't switched it off) adds a card's code, changes it or changes its hosts. Anyone else who may edit the region sees the card, and may move or remove it, but never its code: `EditorSession` sends them the document with each card's `code` null and `origins` empty. What they save is carried: `SaveDraft` and `SaveAutosave` give each card the code it had in the working revision, by its ID, and a card new to the region none; any other value is refused in authorization, so nothing is written.
- **The password.** `SaveDraft` and `PublishRevision` are `RequiresElevation` when a region's code changes: a save whose cards differ from the working revision's, a removal included, and a publish whose cards differ from the published revision's. One confirmation covers a save and the publish after it. The editor asks in place on a 423 and sends the request again (`use-elevated.tsx`). An autosave holds code without the password, since it is never live; turning it into a revision asks.
- **Publishing.** A publish that adds or changes code needs `site-code.manage`, so an editor can't put live an administrator's unpublished change; a removal is published by whoever may publish the region. A change to code is never scheduled (`SchedulePublication` refuses it).
- **The log.** Each saved change is logged in the same transaction: `site_code.card_added`, `site_code.card_changed` or `site_code.card_removed`, and `site_code.card_published` for each card a publish changes. The context is flat: `region` (its address), `content`, `card` (the node's ID), `revision`, `change`, and `before_` and `after_` each followed by `code` and `origins` (space-joined). A card carries no `on`: `carry()` drops one that is sent, and refuses a document in which two cards share an ID.
- **Kept from everyone else.** Plugins' snapshots (`ItemSnapshots`) and translation copies (`TranslationCopy`) have each card's code hidden, so a copy never moves code. An export is the operator's; an import, the management API and the assistant never write a region.
- **The host's switch.** While `TEME_DISALLOW_SITE_CODE` is set the cards draw nothing and nobody adds or changes their code; a card may still be removed.
- **On a page** the card draws its code exactly as written, never cleaned, and gives the page's policy its script and handler hashes and hosts, as `SiteCodeFragments` does; the policy is cached by the code and hosts themselves (`region-code:` and their SHA-256) for 30 days, so it needs no forgetting.
- **The upgrade.** Before 1.5, a card held only the ID of a Site code entry "placed with a card". `2027_10_15_100000_move_placed_site_code_into_cards` copies each placed entry's code, hosts and on or off into every region revision and autosave that named it, history included, deletes those entries, each logged as `site_code.deleted` with its code, narrows the placement check back to three places, and records one `site_code.moved_to_cards` with the counts it also prints.
- **Cards switched off.** `2027_11_15_100000_remove_switched_off_site_code_cards` takes each card that was `on: false` out of its region's published and working revisions, in place and with no new revision, logs one `site_code.removed_off` per card with `region`, `content`, `card`, `before_code` and `before_origins`, and purges the changed regions' pages. Older revisions keep the card. A rollback throws.

## Content types (slice 2.1)

Who may change the site's own content types, and what they may do once made (`docs/architecture/content-types.md`, *Types made on the site*).

- **`types.manage`** (administrators; I-411) opens Site › Types and authorizes `CreateType`, `UpdateType`, `DeleteType` and `ImportTypes`. It reached existing sites through the add-only migration `2027_01_27_100100_add_types_manage_permission.php` and not by re-running `RoleSeeder` (I-449). The operator at the server (`teme:types:import`) may make the same changes with no user.
- **The host's lock.** `TEME_TYPES_LOCKED` (`config('teme.types_locked')`, false by default) refuses every person's change in authorization, before any form is validated, beside `TEME_DISALLOW_SITE_CODE`. The builder shows every type read-only with the lock's notice, and the operator's `teme:types:import` still works, since the lock exists so types change where they are made and arrive by file. A site that holds a staging site's types only by file sets it on the live site.
- **A type's own six permissions** (`{key}.create` and the rest) are made, granted from each role's current `content.*` set, and removed only inside a type Operation that holds the types' lock. They are written with the query builder and Spatie's cached list is forgotten after the commit.
- **Pages** answer to `page.create`, `page.edit.own`, `page.edit.any`, `page.publish`, `page.delete.own` and `page.delete.any` (I-3313). Administrators hold all six and editors all but `page.delete.own`. They reached existing sites through `2027_01_31_100000_add_page_permissions.php`, and `RoleSeeder` lists them for new ones. **This is a breaking change for a site whose authors or contributors wrote pages**: they no longer start, edit, publish or delete any, those they wrote earlier included. Such pages keep their author's name and editors look after them.
- **A role plugin's `edit_pages`** on an imported WordPress author is not carried over; the importer places that person as a Teme author (`app/Domain/Import/Phases/Users/WordPressRoles.php`).

## Deployment (slice 1.13a)

- **The site's own host only.** `TrustSiteHost`, in place of Laravel's `TrustHosts`, accepts only `APP_URL`'s host, with no subdomains (I-3004). A connection from loopback (a health check inside the container) may also name `localhost`. The trusted-host list is cleared once each request is answered, so no later code in the process inherits it.
- **HSTS** comes from one variable, `TEME_HSTS`, sent as written (I-3005). It defaults to `max-age=31536000`: a year, this host only, never preloaded, so a test subdomain never binds its parent. Empty sends nothing. It is sent only in production, at an https `APP_URL`, on a request that arrived over https, by the outermost global middleware, so errors carry it too (I-3006). Pages served as static files never reach PHP, so the image's nginx sends the same value under the same three conditions. It's configuration, never a setting (see *Settings*).
- **Secrets from files.** Each of `SecretFiles::NAMES` (APP_KEY, APP_PREVIOUS_KEYS, DB_PASSWORD, REDIS_PASSWORD, MAIL_PASSWORD, CLOUDFLARE_API_TOKEN, the four AI keys, TEME_BACKUP_MACHINE_KEY, TEME_VERIFY_DB_PASSWORD) may instead be given as `<NAME>_FILE`, a path (I-3012). The file is read in every process, cached configuration or not. One trailing newline is stripped. The value goes into `$_SERVER` only, so programs Teme starts never inherit it. Setting a variable and its `_FILE` both, or naming a file that can't be read, stops the process with one line that names the variable, never the value.
- **The start rules** (I-3008 to I-3011) check a production site's web requests and the commands that start a role (`config:cache`, `optimize`, `queue:work`, `queue:listen`, `schedule:work`, `schedule:run`), never build steps such as `composer install`. One refusal lists every problem: `APP_KEY` missing or the wrong size for the cipher (Teme never makes one: generate it with openssl), `APP_URL` without a host, or sessions not in the database. Start each role as `php artisan <command>`, the command name first and never abbreviated, or the rules can't see it.
- **Cloudflare's ranges** are kept in code (`TrustedProxies::CLOUDFLARE`), read on 2026-10-20 from `https://www.cloudflare.com/ips-v4` and `/ips-v6`; a watch keeps them current (I-3131, draft).

## Password-protected items

An item can be protected by a password readers give at its address (I-1700; `docs/architecture/content-management.md`, *Lifecycle*). Public requests have no session (I-262), so the reader's proof is a key in a cookie.

- **The password** is 1 to 255 characters, at least one not a space, kept and compared exactly as typed, in constant time (I-2773). It is stored encrypted with the application key, never hashed, so whoever may publish the item can read it back to share it. Only `ReaderPasswords` seals and opens it: it is never in the model's array, a revision, a log line, an exception or the security log. A password that can't be opened, after a restore under another `APP_KEY`, opens nothing, and the editor asks for a new one.
- **The reader's key** is the first 16 bytes of an HMAC-SHA256 of the item's ID and its password under the application key, in base64url, 22 characters (I-2774). It says nothing of the password and opens one item. Changing the password, or the application key, locks every reader out.
- **The `teme_unlock` cookie** holds up to 20 keys, newest first, for ten days from the last unlock: `Path=/`, `HttpOnly`, `SameSite=Lax`, and `Secure` when the request is HTTPS. It is set without Laravel's cookie encryption, which the public site's requests don't have, and needs none. `ReaderKeys` reads it and ignores anything that isn't a key.
- **`POST /_teme/unlock`** takes no CSRF token (I-2775). A form another site sent (`Sec-Fetch-Site: cross-site`) is turned away first, unread and uncounted, with a 303 to the site and no cookie: under `SameSite=Lax` it arrives without the reader's cookie, so its answer would replace the keys they hold, and its tries would use up their address's. Forging the form otherwise gives the forger nothing: it unlocks one page with a password they already know. The answer's `Location` is always this site's address.
- **Tries are counted per address,** 10 a minute and 60 an hour, in the cache store, as sign-in's are. An IPv6 address counts by its /64, which one device may roam within; an IPv4 address carried in IPv6 counts as itself. An address with nothing to unlock costs no try. Past either limit the form answers 429 with `Retry-After`, and the right password is refused too. Nothing is written to the security log (I-2786).
- **`TRUSTED_PROXIES` must be set behind a proxy.** Unset, every reader's address is the proxy's, so every reader shares one bucket and ten wrong guesses anywhere lock everyone out for a minute; and every request looks like plain HTTP, so the cookie is set without `Secure`. 1.13's runbook sets it.
- **An import keeps no unkeyed hash of a password.** `import_map.source_hash` hashes the WordPress item's JSON; a post's password in it is replaced first by its HMAC-SHA256 under the application key, so a changed password still changes the hash, but a copy of the database gives no way to check guesses offline.

## Not built yet

Each has a placement in study G, Decision 2:

| Item | Slice |
|---|---|
| The deployment recipe (HSTS, proxies, hosts and cookies are built: *Deployment*) | 1.13 |
| Passkeys, new-device notices, session lists, IP allow-list, the security log viewer | 6.4 |

Also not yet built:

- a grace period before two-factor enforcement applies (I-126);
- a viewer for the security event log (6.4).
