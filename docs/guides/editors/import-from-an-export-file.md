---
title: Import from an export file
audience: editors
kind: task
who: Administrators
screens:
  - tools.importer.show
sources:
  - resources/js/pages/tools/import
  - resources/js/components/import
---

# Import from an export file

Teme can move a WordPress site across in two ways. Connecting the live site with the Teme Companion plugin brings everything. Its export file brings the site's content, and needs nothing installed on the old site. Use the file when the plugin can't be installed there, or the old site is gone. Open **Tools › Import**.

The file brings posts, pages and custom types with their categories, tags and authors, comments, menus and featured images. Every item keeps its address: each one answers at the same address on Teme.

## To export the file from WordPress

In WordPress, open Tools › Export, choose All content and download the file. It is an `.xml` file. Nothing needs installing on the old site.

## To upload it

1. On **Tools › Import**, under **Add a site**, choose **Upload a file**.
2. Drop the file where the form asks, or choose it. Only the `.xml` file from WordPress's Tools › Export will do; Teme says so if it is another file.
3. Wait while it goes up. It goes up in pieces, so a dropped connection carries on where it was. If you close the page, open **Import** again and choose the same file: the upload picks up where it stopped. **Cancel the upload** keeps nothing.

When the file is up, Teme reads it and counts its posts, pages, media and authors. Then the site's plan opens.

## What the file can't bring

The plan lists what isn't in an export file, under **Not in an export file**:

- **Passwords and roles**: people you add get the role you choose, and set a password with "Forgotten your password?" the first time they sign in.
- **Where menus sit**: the menus come across, and you place them on the plan.
- **Redirects made by plugins**: each item's own address comes across. Redirects kept by a plugin such as Redirection don't.
- **Custom types Teme doesn’t have**: their items stay behind until the site has such a type. Add one with a plugin or a types file, then import again.
- **Site settings and widgets**: set the title, front page, time zone and widgets in Teme's settings and theme.
- **Spam comments**: WordPress leaves them out of the export.

To bring these too, connect the live site with Teme Companion instead.

If the plan has the wrong file, choose **Use another file**.

## To make your choices and start

Under **Your choices**, the plan asks what the file can't say. Teme uses these for this import, and again if you import a newer file of the same site.

- **Authors**: each author in the file is matched by email to a person on this site. Choose for each one: the person here, adding them with a role, or crediting their posts and pages to someone. The import emails no one.
- **What to bring**: posts and pages always come. Choose each custom type Teme has, and whether drafts, scheduled and private items and comments come too.
- **Media files**: the file lists the media, but the files themselves are on the old site. Download them from it while it answers, or keep them there as links. Links break when the old site closes. The plan says whether the old site answers now.
- **Menu locations**: choose where each menu goes in this site's theme, or **Don’t place it**.
- **Category and tag addresses**: the first part of the old address of the category and tag pages. Teme fills them from the file's links; change them if the old site used others.

Choose **Start the import**. Teme asks for your password first. The import runs in the background: you can leave the page, and the result reaches your inbox.

## To read the report

The report says what came across, with each warning and error. **Download the full report** gives you every item as a spreadsheet; **Download what needs attention**, only the ones with a problem. Fix them on WordPress and upload a newer file, or fix them here.

## To bring what changed

Under **Bring what changed**, choose **Upload a newer file**, after exporting the site again in WordPress. Teme brings what's new or edited since the last import, and leaves alone what you've changed here. Anything missing from the new file is listed on the report, never deleted.

## To delete the uploaded file

The export file holds drafts, private posts and people's email addresses. When the import is done, choose **Delete the file…** under **Delete the uploaded file** on the report. What was imported stays, and so does the report. Teme deletes the file by itself 30 days after the last import from it ended; the report gives the date.
