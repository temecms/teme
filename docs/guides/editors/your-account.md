---
title: Your account
audience: editors
kind: task
who: Everyone who has an account
screens:
  - profile.edit
  - security.edit
  - preferences.edit
  - notifications.edit
  - my-jobs.index
  - inbox.index
sources:
  - resources/js/pages/account
  - resources/js/layouts/account
  - resources/js/components/header
  - resources/js/pages/inbox
  - resources/js/components/inbox
  - app/Domain/Preferences
  - app/Domain/Notices/Queries/NoticeChoices.php
---

# Your account

Your account has four pages: **Profile**, **Preferences**, **Notifications** and **Security**. Open your account menu at the right edge of the header and choose one. The pages sit side by side under **Account**.

## To change your name or email address

1. Open **Profile**.
2. Change **Full name** or **Email address**. **Bio** is shown on your author page and under your posts.
3. Choose **Save**.

If you change your email address, the admin may say it isn't confirmed yet. Choose **Resend confirmation email** and follow the link it sends.

## To change your password

1. Open **Security**.
2. Under **Update password**, type your **Current password**, then a **New password**, then again in **Confirm password**.
3. Choose **Save**.

Use a long, random password; a password manager makes that easy. For two-factor sign-in, see [signing in](signing-in.md).

## To choose how the admin behaves

Open **Preferences**. What you choose here is yours; nobody else sees it.

- **Appearance** sets the look: **Match the system**, **Light** or **Dark**, and a choice of **High contrast**. A change applies at once.
- Further down are the things that change how the editor and the lists work for you, such as whether the editor opens in focus mode and how many rows a list shows.

Choose **Save preferences** when you have changed any.

## To choose what you are told about

Open **Notifications**. The page lists the emails the site can send you, each with a box. Untick one to stop it for yourself.

An email may be greyed out. That means an administrator has turned it off for everyone, or comments are switched off for the whole site. Only an administrator can change that, under `Settings › Discussion`.

Choose **Save notifications**. Results of background work you start, such as an import, always arrive in your inbox, not by email.

## To see your running jobs

Some tasks take a while and run in the background. When you have started one, a **Jobs** button appears in the header. It counts the jobs that are running, or else those that failed.

1. Choose **Jobs** to open **Your jobs**.
2. Each row shows what it is doing. A failed job offers **Retry**; one that is still waiting or running offers **Cancel**.

If you have no jobs, the button is not there. An administrator who wants to see everyone's jobs finds a link at the bottom of the panel to **Tools › Jobs**.

## To read your inbox

The bell in the header counts the notices you haven't read.

1. Choose **Inbox** (the bell) to see the latest few.
2. Choose **Mark all as read** to clear the count, or **Open your inbox** to see all of them.

The full page, **Inbox**, keeps each notice for 90 days. The **Notifications** page and the Jobs page link to it as **Open inbox**.
