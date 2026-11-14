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
2. Give it a **Name**, then choose **Create menu**.
3. Choose **Add an item** and pick a page, post, category, tag or address. An item that points at one of those takes its title unless you give it a **Label**.
4. Put the items in order. Move an item with its buttons, or with Alt and the arrow keys:
   - **Move up** and **Move down** reorder it;
   - **Move under the item above** makes it a child of the item above;
   - **Move out a level** brings it back out.
   Menus go three levels deep.
5. Tick **Open in new tab** on an item that should leave the page, or choose **Remove** to take an item out. Removing an item removes the items under it too.
6. Choose **Save menu**. If you have changes not yet saved, the page says **Unsaved changes**.

To rename a menu, choose **Rename**; only the name changes, and themes that show it keep showing it. To delete one, choose **Delete…** and confirm. A place that showed it then shows no menu until you choose another.

## To choose where a menu shows

Each place in the theme, such as the header or the footer, shows one menu. On **Navigation**, a panel lists the places the theme shows a menu; choose the menu for each. On a menu's own page, **Show in** does the same from the other side.

If a place has no menu, a banner at the top of the admin tells you; its button is **Choose menus**. See [status banners](status-banners.md).

## To edit a region

A region is a spot the active theme sets aside for your own content, such as a box after each article. Open **Regions** to see them.

Regions come from whichever theme is active, not only the default theme. Switch themes and the list changes to match.

1. Choose **Edit** on a region (or **Create** if it has no content yet).
2. Write in the editor as you would for any item, using only the cards that region takes.
3. Publish it as you would an item.

The regions of themes you aren't using are kept, and listed under **From other themes**. They can be read but not changed. Each shows again if you switch back to its theme.
