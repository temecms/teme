---
title: History and preview
audience: editors
kind: task
who: Everyone who can edit an item
screens:
  - content.edit
  - content.compare
  - content.revisions.index
sources:
  - resources/js/components/editor/editor-header.tsx
  - resources/js/components/editor/editor-rail.tsx
  - resources/js/components/editor/rail/history-panel.tsx
  - resources/js/components/editor/restore-button.tsx
  - resources/js/components/editor/share-panel.tsx
  - resources/js/components/editor/diff-view.tsx
  - resources/js/pages/content/compare.tsx
---

# History and preview

Teme keeps every version of an item. You can read the history, compare two versions, go back to an old one, and show a draft to someone who has no account.

## To see an item's history

Choose **History** in the editor's bar. The panel lists the versions, newest first. Each entry says how it came about.

- **Saved**: you or someone else saved it.
- **Autosaved**: the editor saved it on its own while you wrote.
- **Published**: this is what went live. The entry marked **Live** is what readers see now.
- **Restored**: someone went back to an older version.
- **Imported**: it came in with an import.
- **Assistant**: the writing assistant changed it.

The entry marked **Current** is the version you are working on. Choose **Load older** for more.

## What the history's entries mean

There is no button to save a version by hand. The editor adds an **Autosaved** entry on its own after you have written for a while, so any entry in the list is a version you can return to. **Published** marks what went live, so you can always tell what readers saw and when.

## To compare two versions

Open an entry in **History** and choose **Compare with the version before**. The page shows what changed: how many blocks changed and how many words were **Added** and **Removed**, then each changed block with **Before** and **After** side by side. The blocks that did not change are folded away. Beside it, **Versions** lists every saved version; choose **Before** or **After** on one to compare it. Nothing on this page changes the item. Choose **Back to the editor** when you are done.

## To go back to an older version

1. In **History**, open the older entry.
2. Choose **Restore**.
3. Read the question, **Restore this version?**, and confirm.

Restoring adds a new version with the older text. Nothing is published and nothing is deleted, so you can change your mind: your latest text is still in the history.

## To preview an item

Choose **Preview** in the editor. It shows the latest saved version through the theme, as a reader would see it, in a page only you can open. Save first if you have just typed.

## To show a draft to someone without an account

A preview link shows one version to anyone who has the link, without an account, for two days. It opens nothing else on the site.

1. Choose **Share** in the editor's bar, or find it in **Tools**.
2. Under **Preview links**, choose **Make a preview link**. Making one saves your changes first.
3. Choose **Copy** and send it. Copy it now: it will not be shown again.

Each link in the list shows who made it, when it works until, and whether it was opened. A link nobody has used says **Not opened yet**. To stop a link working before it runs out, choose **Revoke**.
