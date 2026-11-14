---
title: Lists of items
audience: editors
kind: task
who: Everyone who can see a list of items
screens:
  - content.index
sources:
  - resources/js/pages/content
  - resources/js/components/list
  - app/Domain/Contents/Queries
  - app/Domain/Types
---

# Lists of items

Posts, pages and every other kind of item has a list: **Posts**, **Pages** and so on. The list is where you find an item, change a few things about it without opening it, and move it to the trash.

## To find an item

- The tabs across the top group the items by status, each with a count.
- **Search titles** looks in titles. Use **Search in** to choose **In titles** or **In text**.
- The filters sit on one line. Choosing one applies it at once; there is no Apply button. **Clear filters** appears once a filter narrows the list.
- The **Date** control lists the months that hold the tab's items, with counts. Choose **Choose dates…** for an exact range: set **From** and **To**, then **Apply dates**. Leave **To** empty for "since".
- The pager at the foot of the list moves between pages.

A row shows the title, which opens the item. Under it is a line of what you can do to the item: **Edit**, **Quick edit**, **View** for a published item or **Preview** for a draft, and **Trash**. The rest, such as **Revert to draft**, wait under **More** at the end of the line. The line shows when you point at the row or move to it, and always on a touch screen. Which actions you see depends on your role and on the item's status.

## To choose what the list shows

Choose **Screen options**. A panel opens, and it says it holds what this list shows for you. Other people keep their own choices.

1. Tick the **Columns** you want.
2. Set **Rows per page**.
3. Choose **Apply**.

A **Featured image** column is offered here. It is off until you turn it on.

## To change an item's details without opening it

Choose **Quick edit** under the item's title. A panel opens beside the list with the title, the **Address**, the author, the date it was **Published**, the **Discussion** setting, the categories and tags, and the **Featured image**. Choose **Save**, or **Cancel** to leave it alone.

Changing a published address takes effect at once, and the old one stops working. See [redirects](redirects.md) to send it somewhere.

## To change several items at once

1. Tick the boxes at the left of the rows. **Select all on this page** ticks every row you can see.
2. The **Bulk actions** bar shows how many you chose. Choose **Edit…** to change a detail on all of them, or **Move to trash**.
3. To act on every match, not only this page, choose **Select all matching**. The bar then says "All matching" and the kind of item, and the action runs in the background, one item at a time, so you can keep working.

Any item you may not change is skipped, and the bar tells you which.

## To move items to the trash and bring them back

Choose **Move to trash** on a row, or on a selection. The item leaves its tab and appears under the **Trash** tab.

To bring it back, open the **Trash** tab and choose **Restore** on the row or on a selection. It returns as it was.

## To remove items for good

In the **Trash** tab, choose **Delete permanently** on a row or a selection. Read the question first: all the item's revisions go with it, and it can't be undone.

You rarely need to. A trashed item is deleted by itself after a number of days, `content.trash_days`; see the [settings reference](../../reference/settings.md).
