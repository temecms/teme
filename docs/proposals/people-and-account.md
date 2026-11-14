# People and your account on the new design system

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/people-and-account.md |
| Release | — |
| Replaces | — |

## The problem

People and the account pages work, but they were built before the design system. People is one 800-line page with its dialogs inside it. Your account has four pages, with two-factor sign-in tucked into Security. A person has no picture anywhere. The password prompts on these screens are each their own.

The design system names an account layout (an in-page list of account pages headed by the signed-in person) and a person's picture (I-3410). This proposal builds both and moves People onto the page patterns.

## What we will build

**People.** A list page with views (All, each role, Invited, Suspended), each with a count. Each row shows the person's picture, name and email, role, status and last sign-in. Row actions are "Change role…", Suspend or Reactivate, and a "…" menu with Delete. Invited people show "Send again" and "Revoke invitation". "Invite someone", "Change the role of…" and "Delete {name}" are dialogs. Each asks for the person's password through the shared prompt, shown inside the open dialog so nothing typed is lost. Deleting asks who receives the person's content. On a phone the rows are cards (picture, name, role, status) with the actions under them, and the dialogs are full screen.

**Your account layout.** One layout for every account page: the signed-in person (large picture, name, role), the in-page list of account pages, and a main column of form panels. On a phone the list becomes one button that opens it. Each page has one save bar, except Preferences, which saves each choice as it is made.

**Account pages.**
- *Profile:* name, email, biography and a picture (choose from Media, or remove it to fall back to the site's default).
- *Preferences* and *Notifications:* the same fields, in panels.
- *Security:* change password. Two-factor moves to its own page.
- *Two-factor sign-in:* turn on with the QR code and a code, see and renew recovery codes, turn off. The existing setup dialog and recovery-code components are reused.

**A person's picture.** A circle in three sizes (24, 40, 96) on People, the profile head and the account list. It is not used in dense content lists. With no upload it shows initials. An invited person who has not joined gets a dashed ring and no initials.

## The decisions

1. **Where the picture comes from.** The picture is an item in Media, chosen on the profile. The account stores only its ID, never a URL, so a domain change costs nothing. Alternatives: a separate upload store (a second place for files, with a second set of rules); Gravatar only (sends every address to a third party). We choose Media.
2. **The default picture.** Initials always. Gravatar is not built now, so the "People's pictures" setting is not shown yet.
3. **Two-factor on its own page.** Alternative: keep it inside Security. A page of its own matches the demo and the account list, and Security stays short.
4. **Dialogs, not pages,** for invite, change role and delete, as today. All three are short, and the list stays in view behind them.

## In, and not in

**In:** People on `ListPage` with `PersonPicture` and the fresh-password prompt; the account layout; Profile with picture; Preferences, Notifications, Security and a separate Two-factor page; phone layouts.

**Not in** (the demo draws them, the product does not do them yet):
- **What each role may do:** the permission matrix page and the link to it. It was drawn as a proposal and never built.
- **Passkeys** and **Sessions** pages (sign in with a fingerprint; sign out everywhere else): there is no server support. The account list shows them only once they exist.
- **Gravatar** and the "People's pictures" setting.
- A person's picture in posts lists or comments.

## How it fits

- **Patterns used:** `ListPage`, `FormPage`, `InPageNav`, `PersonPicture`, `FreshPassword`, `SaveBar`, `Dialog`, `Notice`.
- **Files:** `pages/users/**`, `pages/account/**`, `layouts/account/**` and the two-factor components.
- **Server work:** the picture needs a field on the person (a media ID), an Operation to set and clear it, a rule for who may change it, and the picture in what the admin reads. The upload reuses Media. This is the only part of this proposal with new server code, and it goes first.
- **Tests that change:** the People screen test, the account notifications test and the unlock-form test. New cases cover the picture (set, removed, refused when not allowed) and that nothing is written when authorization fails.
- **Depends on:** the foundation part (`PersonPicture`, `FreshPassword`, `InPageNav`, `FormPage`) and the picture server work.

## Settled

The questions this proposal raised, as decided:

1. A person's picture is stored in its own column on the person, not as a field: it is identity, not content.
2. An administrator may remove another person's picture, but not change it.
3. Passkeys and Sessions join the account pages when the server supports them, without a new decision.
4. Suspended keeps its own empty text, saying what suspended means.
