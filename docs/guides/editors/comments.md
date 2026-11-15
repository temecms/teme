---
title: Comments
audience: editors
kind: task
who: Editors and administrators moderate; administrators change the site's comment settings
screens:
  - comments.index
  - settings.discussion
sources:
  - resources/js/pages/comments
  - resources/js/components/comments
  - resources/js/pages/settings/discussion.tsx
  - app/Domain/Comments
---

# Comments

Readers can comment on items when the site has comments switched on. **Comments** is where you read them and decide what to do with each.

Comments are an optional part of Teme. If the site has them off, there is nothing to moderate and the screen says so.

## To read and decide on comments

Open **Comments**. The newest are first. The views across the top group them by status, each with a count.

- **Pending**: waiting for you to decide. Nothing here is public.
- **Approved**: readers can see it.
- **Spam**: marked as spam. It waits here until you empty spam.
- **Trash**: moved to the trash. It waits here for the number of days the site keeps trash.

Search with **Search comments** for text, a name or an email address. Choose a comment's name to open its details: where it was written, who wrote it and when.

Approving, marking as spam and moving to the trash can each be undone.

## To approve, mark as spam or move to the trash

Use the buttons on a comment's row, or choose several rows and use the bar that appears.

- **Approve** makes a pending comment public. **Unapprove** takes an approved one back to pending.
- **Mark as spam** sends it to spam.
- **Move to trash** sends it to the trash.
- **Mark as not spam** puts a spam comment back where it was.
- **Restore** brings a comment back from the trash.

## To reply

Choose **Reply** on a comment. A box opens under it. Write your answer and choose **Reply**. If the comment was pending, the button reads **Reply and approve**, and replying also approves it.

## To fix a comment's text

Choose **Quick edit**. You can change the **Name**, **Email**, **Website** and the **Comment** itself, then choose **Save changes**. The text is saved as plain text. If the comment had formatting, such as bold or a quote, the box warns you that changing its text loses it.

## To delete for good and to empty the trash

- **Delete permanently** on a comment removes it with nothing kept. This can't be undone. The question tells you if the comment has replies: such a comment stays in the thread, with its name, email address, address and text erased, and the thread shows that the comment was removed.
- **Empty spam** and **Empty trash** delete everything in that view. Read the question first: it counts the comments, and says which have replies. Spam that arrived since you opened the list stays, for you to check first.

## To stop comments on one item

Open the item and use the **Discussion** row in its panel. See [publishing](publishing.md).

## To turn comments off for the whole site, or change how they are held

This is an administrator's job, under **Settings › Discussion**.

1. At the top, the switch **Comments on this site** turns comments on or off for the whole site. It acts at once, with no button. While the rules below have unsaved changes, save or discard them first.
2. Below it, the rules say who may comment, how comments are held and what emails are sent. Choose **Save discussion settings** to save them.

When comments are off, the rules are hidden but kept, so they are there when you switch comments back on. For every setting and its default, see the [settings reference](../../reference/settings.md).
