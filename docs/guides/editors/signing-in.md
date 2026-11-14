---
title: Signing in
audience: editors
kind: task
who: Everyone who has an account
screens:
  - login
  - invitation.show
  - security.edit
sources:
  - resources/js/pages/auth
  - resources/js/pages/account/security.tsx
  - resources/js/components/manage-two-factor.tsx
  - resources/js/components/two-factor-setup-modal.tsx
  - resources/js/components/two-factor-recovery-codes.tsx
---

# Signing in

## To accept an invitation

An administrator invites you by email. The email holds a link to a page headed **Choose your password**.

1. Open the link in the email.
2. Type a password, and type it again in **Confirm password**.
3. Finish. You are signed in.

The link works for a limited time. If it has run out, ask the administrator to send a new one.

## To sign in

1. Open the admin's address in your browser.
2. Type your **Email address** and your **Password**.
3. Choose **Sign in**.

Tick **Remember me** on a computer only you use. It keeps you signed in for a number of days, which the site's settings decide. If the box is missing, the site has turned it off.

A session ends by itself after a stretch without activity, and after a longest time however busy you are. When it ends, sign in again; your unsaved work in the editor is kept in the browser while you do.

## To sign in with a second step

If you have turned on two-factor sign-in, the page headed **Two-factor sign-in** asks for an **Authentication code** after your password.

1. Open the authenticator app on your phone.
2. Type the six-digit code it shows for this site.
3. Continue.

## To set up two-factor sign-in

Two-factor sign-in means a stolen password is not enough to get in. An administrator may require it for your role; if so, the admin asks you to set it up before it lets you in.

1. Open your account menu, then **Security**.
2. Under **Two-factor sign-in**, choose **Enable two-factor sign-in**.
3. In your authenticator app, scan the QR code. If you cannot scan it, type the setup key shown beside it instead.
4. Type the first six-digit code the app shows, in **Authentication code**.
5. Look at **Recovery codes**. Save them now, in a password manager. Each signs you in once if you lose your phone.

*Warning:* if you lose both your phone and your recovery codes, only an administrator can get you back in.

To turn it off, choose **Disable two-factor sign-in** and confirm. To replace your recovery codes, choose **Regenerate codes**; the old ones stop working.

## To use a recovery code

1. On the **Two-factor sign-in** page, choose **Use a recovery code instead**.
2. Type one of your codes in **Recovery code**.
3. Continue.

That code will not work again. Set up two-factor sign-in again soon, so you have a full set.

To go back to the app, choose **Use your authenticator app instead**.

## To reset a forgotten password

1. On the sign-in page, choose **Forgot your password?**.
2. Type your **Email address** and ask for the link.
3. Open the email, follow its link, and choose a new password.
4. Choose **Back to sign in** and sign in.

For your safety, the page says the same thing whether or not the address has an account.

## To confirm your password when the admin asks

Some changes are sensitive: they can lock people out or move data. Before one, the admin asks you to type your password again on a page headed **Confirm password**.

Once you have, it does not ask again for a few minutes. How many is `security.elevation_minutes`, an administrator's setting; see the [settings reference](../../reference/settings.md).

To change your password itself, see [your account](your-account.md).
