---
title: Signing in
audience: editors
kind: task
who: Everyone who has an account
screens:
  - login
  - invitation.show
  - security.edit
  - two-factor.edit
sources:
  - resources/js/pages/auth
  - resources/js/pages/account/security.tsx
  - resources/js/pages/account/two-factor.tsx
  - resources/js/layouts/account
  - resources/js/components/account/two-factor-status.tsx
---

# Signing in

## To accept an invitation

An administrator invites you by email. The email holds a link to a page headed **You’re invited**, which says who invited you, your role and your email address.

1. Open the link in the email.
2. Check **Your name**: it is how your name appears on the posts you write.
3. Type a password in **Choose a password**, and type it again in **Type it again**. The hint under the box says how long it must be.
4. Choose **Join** (the button names the site). Then sign in with your new password.

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
2. Type the six-digit code it shows for this site, one digit to a box.
3. Choose **Continue**.

To start again with another account, choose **Back to sign in**.

## To set up two-factor sign-in

Two-factor sign-in means a stolen password is not enough to get in. An administrator may require it for your role; if so, the admin asks you to set it up before it lets you in.

1. Open your account menu, then **Two-factor sign-in** in your account's list, and type your password if asked. The page sets it up in three steps.
2. **Scan the code**: in your authenticator app, scan the square. If you can't scan it, type the key shown beside it instead (**Copy key** copies it). Choose **Next: type a code**.
3. **Type a code**: type the six-digit code the app shows now, in **Six-digit code**, and choose **Check the code**. Two-factor sign-in is now on.
4. **Keep your recovery codes**: save them now, in a password manager; **Copy** and **Download** help. Each signs you in once if you lose your phone. Tick **I’ve saved these codes**, then choose **Turn on two-factor sign-in** to finish.

*Warning:* if you lose both your phone and your recovery codes, only an administrator can get you back in.

Once it is on, **Security** and **Two-factor sign-in** show it as **On**. To see your codes again, choose **Show recovery codes**. To turn it off, choose **Turn off…**, then **Turn off**.

## To use a recovery code

1. On the **Two-factor sign-in** page, choose **Use a recovery code instead**.
2. Type one of your codes in **Recovery code**.
3. Continue.

That code will not work again. Set up two-factor sign-in again soon, so you have a full set.

To go back to the app, choose **Use your authenticator app instead**.

## To reset a forgotten password

1. On the sign-in page, choose **Forgotten your password?**, beside **Password**.
2. Type your **Email address** and choose **Email me a reset link**.
3. The page turns to **Check your email** and says how long the link works. If nothing arrives, choose **Nothing arrived? Send it again**.
4. Open the email, follow its link, and choose a new password.
5. Sign in with it.

For your safety, the page says the same thing whether or not the address has an account.

## To confirm your password when the admin asks

Some changes are sensitive: they can lock people out or move data. Before one, the admin asks you to type your password again on a page headed **Confirm your password**, which names the page you are about to open. Type it and choose **Confirm password**, or choose **Cancel** to go back.

Once you have, it does not ask again for a few minutes. How many is `security.elevation_minutes`, an administrator's setting; see the [settings reference](../../reference/settings.md).

To change your password itself, see [your account](your-account.md).
