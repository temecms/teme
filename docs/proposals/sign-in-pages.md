# The sign-in pages on one card

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/sign-in-pages.md |
| Release | — |
| Replaces | — |

## The problem

The pages a person meets before they sign in share one simple layout, but they predate the design system. The card is 384px wide, headings and text use fixed pixel sizes, the links under the card are small touch targets (20px tall), and the "Powered by Teme" line is styled on its own. The pages use none of the foundation's fields, buttons or notices, so they look different from the admin the moment someone signs in.

The design system draws one card: centred, at most 420px, with the brand mark, a title, a lead line, fields and one primary button. This proposal puts every sign-in page on it.

## What we will build

One `AuthPage` pattern holds the card. Every page below renders it, so none writes its own width, padding or heading.

- **Sign in.** Email and password. The password field has a labelled show/hide button. "Remember me" and "Forgotten your password?". A wrong email or password is named on the field; too many tries shows a notice with the wait. Notices at the top say when a reset link was sent or a password was changed. When the site has no accounts, the page says so and links to set-up.
- **Set up the site.** The setup code from the deploy log, the site's name and the first administrator, with the wrong-code and expired-code states.
- **Forgot password.** Ask for a link, then "check your email".
- **Reset password.** A new password twice, and a clear state for an expired link.
- **Accept an invitation.** Who invited you and as what, then your name and password.
- **Verify email.** Ask the person to follow the link, and send it again.
- **Two-factor challenge.** The six-digit code, or a recovery code on the same card.
- **Confirm password.** The fresh-password prompt as a page, for when someone arrives without a dialog.

**On a phone** the card starts at the top of the screen with 20px padding, inputs use 16px text so the phone does not zoom, and the links under the card are 44px tall. It works at 320px wide.

## The decisions

1. **420px, not 384.** The design system and the demo both say 420.
2. **One card for every page,** including the short ones (verify email). Alternative: a smaller card for short pages. One card is simpler and keeps the brand mark in the same place at every step.
3. **Show/hide on every password field,** labelled for screen readers, rather than the browser's own reveal control, which differs by browser.
4. **The credit line.** "Powered by Teme" stays below the card, in the small-text style the design system gives.
5. **No behaviour change.** The routes, throttling and messages stay as they are. The pages only change how they are drawn.

## In, and not in

**In:** `AuthPage`; the eight pages above rebuilt on `Field`, `Button` and `Notice`; the phone layout; the show/hide button; 44px links.

**Not in** (the demo draws them, the product does not do them yet, or they belong elsewhere):
- **Passkey sign-in:** the demo draws a button marked Soon. There is no server support, so the page shows nothing for it.
- **The error pages** (403, 404, 419, 500, 503) and the holding page: they are served from the site, not the admin, and are a separate decision.
- **Preview bars** a signed-in person sees on the public site: they belong to the site's front end.
- A "sign in with…" provider. None is planned.
- The live countdown the demo draws for a throttled sign-in, if the server only sends a message (see the open questions).

## How it fits

- **Patterns used:** `AuthPage` (built here), `Field`, `Button`, `Notice`, and the password field with its eye button.
- **Files:** `pages/auth/**` and `layouts/auth*`.
- **Server work:** none. The pages keep their routes and props.
- **Tests that change:** the sign-in, first-run and two-factor browser tests keep their `data-test` hooks, so only tests that assert the old width or link sizes change. A new accessibility case checks each page at 320px for sideways scrolling.
- **Depends on:** the foundation part (Field and the tokens) and nothing else. It can start the day that merges.

## Settled

The questions this proposal raised, as decided:

1. The public error pages and the holding page join this part.
2. "Powered by Teme" shows on the sign-in pages only.
3. When sign-in is paused after too many tries, the page shows a live countdown.
4. The mark above the card is the site's own, and Teme's only when the site has none.
