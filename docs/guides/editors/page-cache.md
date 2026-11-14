---
title: The page cache
audience: editors
kind: task
who: Administrators clear the page cache
screens:
  - tools.page-cache.show
  - tools.index
sources:
  - resources/js/pages/tools/page-cache.tsx
  - resources/js/pages/tools/index.tsx
  - app/Domain/Navigation
---

# The page cache

The site keeps saved copies of its public pages. Readers who aren't signed in get these, so each page is drawn once rather than on every visit. That is why the site is quick.

Only an administrator can clear the page cache. The [roles table](README.md#what-you-can-do) shows who can.

## To clear the page cache

1. Open **Tools › Page cache**.
2. Choose **Clear the page cache**.

Every saved copy is dropped, and each page is drawn again the next time a reader asks for it. The first readers after a clear may wait a little longer.

The screen also shows the state of the cache under **Now**: whether it is on or off, where it is **Kept in**, **How long pages live**, and **Last cleared here**, by whom and when.

## What to know before you clear it

Almost never. When something is published, changed or taken down, Teme clears the pages that show it straight away. Clear everything only if a page still looks out of date, for example after a theme's files change.

While you're signed in you always see pages drawn afresh, never the saved copies. To see what readers get, open the page in a private window.

## What decides how long a change takes to show

Up to an hour, unless a change purges it sooner. A saved page lives only so long: the screen says how long under **How long pages live**, with a line each for a page, a search's page and a "not found" page. When something that a page shows changes, Teme removes that page earlier.

## If a change does not show

1. Check it in a private window, so you aren't looking at the page drawn afresh for you.
2. If the old page is still there, clear the page cache as above.
3. If it still does not change, tell whoever runs the server. Some sites keep a second copy in front of Teme, and the screen says so under **In front of it**.

If the screen says "The page cache is off.", this server doesn't save pages, so each is drawn on every visit and there is nothing to clear.
