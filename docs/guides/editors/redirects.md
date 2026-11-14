---
title: Redirects
audience: editors
kind: task
who: Editors and administrators
screens:
  - tools.redirects.index
  - tools.redirects.not-found
sources:
  - resources/js/pages/tools/redirects.tsx
  - resources/js/pages/tools/not-found.tsx
  - resources/js/components/tools
  - app/Domain/Redirects
---

# Redirects

A redirect sends readers on from an address the site no longer has. Open **Tools › Redirects**.

You rarely need one for a post or page you moved inside Teme. When an item gets a new address, its old address keeps sending readers to it by itself. A redirect is for an address Teme never had, such as one from the old site.

A redirect answers only where no page does, so it can never hide a live page.

## To send an old address to a new one

1. Choose **Add a redirect**.
2. In **From**, type an address on this site that no page answers. Two marks help with many addresses at once: `[name]` stands for one part of an address and `[...rest]` for everything after it, as in `/2019/05/[name]`. **To** can use them again.
3. Choose where it goes, under **To**:
   - **A post or page on this site**: search with **Find the post or page**. The redirect follows the item if its address changes.
   - **A file in the media library**: choose it with **Choose a file**.
   - **An address**: a path on this site, starting with `/`, or a full address on another site, starting with `https://`.
4. Choose a **Status**. **Moved permanently** is right for most. **Found (temporary)**, **Temporary redirect** and **Permanent redirect** are the other ways to send readers on. Use a **Note** to say why it is there, for whoever looks next.
5. Choose **Save redirect**.

The list shows each redirect with its **Hits** and **Last hit**. Hits are counted every minute, so the newest take a moment to appear. Choose **Switch off** to stop one without deleting it, and **Switch on** to bring it back.

## To find addresses readers could not reach

Open **The 404 log**. It lists the addresses readers asked for that don't exist, each with **Times**, **First seen**, **Last seen** and **Came from**. Only the site a reader came from is kept; no IP address and no browser.

The log keeps a limited number of addresses. When it is full, the address seen least recently makes room. Bots probing for WordPress and server files are ignored.

On a row, choose **Add a redirect** to send that address on, or **Mark as gone**. An address that already has a redirect is marked **Redirected**. **Change log settings** is for how much the log keeps.

## To tell search engines an address is gone for good

Use the status **Gone**. It answers that the address was removed on purpose, and search engines drop it. You can set it from a redirect's form, or by choosing **Mark as gone** on a row of the 404 log. **Unavailable for legal reasons** is for an address you must not serve.

## To bring redirects in, or take them out

- **Import from CSV** brings in a list you have made, for example from the old site.
- **Export as CSV** gives you the redirects as a file, to keep or to edit.
- **Export for nginx** gives you the same redirects as rules for a web server that wants them. Ask whoever runs the server whether they use it.
