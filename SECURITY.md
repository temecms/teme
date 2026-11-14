# Security policy

## Status

Teme is in **alpha** and developed in the open. Fixes go into the latest release only. This policy is in place early so that the process exists before it is needed, rather than being written during an incident.

## Reporting a vulnerability

Report privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. The report and every reply stay private between you and the maintainer.

If you can't use GitHub's reporting, email **security@temecms.com** instead.

Do not open a public issue, pull request or discussion for a suspected vulnerability. Do not disclose it publicly until a fix is released or 90 days have passed, whichever comes first.

Include whatever you have:

- The type of issue and the component affected
- Steps to reproduce, or a proof of concept
- The version or commit you tested
- Any configuration needed to trigger it
- What an attacker could achieve

## What to expect

| Stage | Commitment |
|---|---|
| Acknowledgement | Within 3 working days |
| Initial assessment, with severity and whether we accept it | Within 10 working days |
| Fix for a critical or high severity issue | Within 30 days of acceptance |
| Public advisory | On release of the fix |

If we cannot meet one of these, we will say so and give a revised date rather than going quiet.

There is no bug bounty. Reporters are credited in the advisory unless they ask not to be.

## Supported versions

| Version | Security fixes |
|---|---|
| 0.x (alpha) | The latest release only |

While Teme is below 1.0, every fix ships in a new release and older releases get none: update to the latest. From 1.0 the intended policy is security fixes for the current major and the previous major for twelve months after the current major's release.

## Scope

**In scope** — anything in this repository and in the published `teme/*` packages:

- Authentication and session handling
- The permission model, including role and capability checks
- Any path by which a request reaches data it should not, including through the API, the export format, or an AI operation
- The importer, including data taken from a source WordPress site
- File upload and media handling
- Template rendering and the theme contract
- SQL, command and template injection
- Anything that lets code execute that was not deployed with the application

**Out of scope:**

- Vulnerabilities in third-party dependencies with no Teme-specific exploit path — report those upstream
- Attacks requiring administrator credentials that the attacker already legitimately holds. An administrator is trusted by design; escalation *to* administrator is in scope
- Findings from automated scanners with no demonstrated exploit
- Missing hardening headers with no demonstrated impact
- Social engineering, physical access, and denial of service through raw volume

## Architectural commitments

These are properties the design is meant to guarantee. A report demonstrating that one does not hold is a valid vulnerability, whatever its other impact.

1. **Authorization happens before any read or write.** Every state mutation runs through an Operation whose `authorize()` step precedes validation and persistence.
2. **Deployed code is read-only at runtime.** No request path writes executable code. Themes and extensions arrive at deploy time as Composer packages; the admin interface never writes PHP.
3. **Uploads are never executable** and are served from a path that cannot execute code.
4. **A token's scope is its whole authority.** API tokens and AI tokens carry their own scopes and never inherit administrator capability.
5. **AI operations use the same permission checks as human ones**, and an AI's retrieval context contains only content the acting user can already reach.
6. **Secrets are never returned as ordinary form or API values.**
7. **Uploaded files are never executed** — not as PHP, and not as SQL. Restoring from an upload goes through Teme's own parser, never through `psql`.
8. **The database role Teme runs as is not a superuser**, so no path through the application can reach `COPY … FROM PROGRAM` or other server-side execution.
9. **An uploaded backup is accepted only if it authenticates under the site's recovery key.** A restore can only ever apply a backup Teme itself produced.
10. **Sign-in and recovery never reveal whether an account exists** — not by message, status code, throttling behaviour or response time.
11. **No one can lock another person out of their account.** Throttling falls on unrecognised clients; a device the account holder has used before, and the password-reset path, remain available.
12. **Sensitive changes require a fresh proof of identity.** Changes to roles, permissions, credentials, second factors and security settings — and restore through the browser — are declared sensitive by their Operation and require confirmation within the configured window. An API or AI token can never perform them, whatever its scope.
13. **Passwords are stored only as Argon2id hashes.** Hashes imported from another system are verified once and replaced at the next successful sign-in; they are never stored in any recoverable form.
