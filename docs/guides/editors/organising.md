---
title: Categories, tags, menus and regions
audience: editors
kind: task
who: Editors and administrators
screens:
  - terms.index
  - navigation.index
  - navigation.edit
  - regions.index
sources:
  - resources/js/pages/terms
  - resources/js/pages/navigation
  - resources/js/pages/regions
  - resources/js/components/regions
  - resources/js/components/themes/theme-regions.tsx
  - resources/js/components/navigation
  - resources/js/components/patterns/save-bar.tsx
  - resources/js/components/term-picker.tsx
  - resources/js/components/term-tree-picker.tsx
  - app/Domain/Fields
  - app/Domain/Status/Checks
---

# Categories, tags, menus and regions

These are the ways you group items and shape how readers find their way round the site. Editors and administrators can do all of it; the [roles table](README.md#what-you-can-do) shows who can.

## To add, rename or remove a category or a tag

Open **Categories and tags** and choose the one you want. The list shows each with a count; choose a count to list its items. Counts leave out the trash.

1. Choose the button that adds one, and fill in the form.
   - **Name** is what readers see.
   - **Address** is the last part of its address. Leave it empty to make one from the name.
   - **Parent** puts a category under another. A category can sit under another; a tag can't.
   - **Description** is shown by some themes on the category's page, and search engines may show it in results.
2. Choose **Save**.

To rename one, choose **Edit** on its row, change **Name** and **Save**.

To remove one, choose **Delete** on its row and read the question. For a category with others under it, the question says they move up a level, and that nothing under it is deleted. This cannot be undone.

The field and the column are called **Address**, not "slug".

## To choose an item's categories and tags and its primary category

In the editor's side panel, tick the categories and add tags. When an item has several categories, the **Primary category** says which one stands for it, for example in search results. Left empty, it uses the first one ticked.

## To make a menu

A menu is a list of links to pages, posts, categories, tags, the home page or any address.

1. Open **Navigation** and choose **New menu**.
2. Give it a **Name**, then choose **Create menu**. Its page opens.
3. Choose **Add an item** and pick a page, post, category, tag or address. The category and tag tab lists the most used ones as it opens; type to narrow the list. An item that points at one of those takes its title unless you give it a **Label**.
4. Put the items in order. Drag an item by its handle, or move it with its arrows (on a phone, its "…" menu), or with Alt and the arrow keys:
   - **Move up** and **Move down** reorder it;
   - **Move under the item above** makes it a child of the item above;
   - **Move out a level** brings it back out.
   Menus go three levels deep. An item with items under it has an arrow that folds them away.
5. Tick **Open in new tab** on an item that should leave the page, or choose **Remove** to take an item out. Removing an item removes the items under it too.
6. Choose **Save menu**. If you have changes not yet saved, the page says **Unsaved changes**.

To rename a menu, choose **Rename…**; only the name changes, and themes that show it keep showing it. To delete one, choose **Delete menu…** under **Delete this menu** at the foot of its page, or in the "…" menu on its row in **Navigation**, and confirm. A place that showed it then shows no menu until you choose another.

## To choose where a menu shows

Each place in the theme, such as the header or the footer, shows one menu. On **Navigation**, a panel lists the places the theme shows a menu; choose the menu for each. On a menu's own page, **Show in** does the same from the other side: tick each place that should show it. Ticking a place changes nothing yet: it joins the menu's items, and **Save menu** writes both together. A place another menu holds says so, and ticking it takes that menu's place once you save.

If a place has no menu, a banner at the top of the admin tells you; its button is **Choose menus**. See [status banners](status-banners.md).

## To edit a region

A region is a spot the active theme sets aside for your own content, such as a box after each article. Open **Regions** to see them.

Regions come from whichever theme is active, not only the default theme. Switch themes and the list changes to match.

Every region is on one screen, listed under the groups the theme gives them, each with how many blocks it holds of the most it takes. A dot beside a region means it has changes not published yet.

1. Choose the region in the list. If it has no content yet, choose **Fill this region**.
2. Choose **Add card** and pick a card; the list shows only the cards that region takes, grouped by where they come from: Teme, the theme, or a plugin. The card opens on its form: fill it in and choose **Done**.
3. Choose **Edit** on a row to change that card in place, the arrows to move it up or down (or drag the row), and the bin to remove it.
4. Your changes are saved as you go. **Save draft** keeps a version in the region's history; **Publish region** puts it on the site. **Discard changes** goes back to what readers see now, or to empty for a region never published.

For the region's history, or to compare versions, choose **Open in the full editor**.

The regions of themes you aren't using are kept, and listed under **Other themes**, in the section **From other themes**. They can be read but not changed. Each shows again if you switch back to its theme.
