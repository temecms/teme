---
title: Import from a live site
audience: editors
kind: task
who: Administrators
screens:
  - tools.importer.show
sources:
  - resources/js/pages/tools/import
  - resources/js/components/import
---

# Import from a live site

Teme can move a WordPress site across in two ways. Its export file brings the site's content, and needs nothing installed on the old site: see [Import from an export file](import-from-an-export-file.md). Connecting the live site brings everything: posts, pages and media, people with their roles and passwords, comments, menus in their places, redirects, each post's search and social fields, the site's settings and its languages. Use it while the old site is up and you can install a plugin on it. Open **Tools › Import**.

Every item keeps its address: each one answers at the same address on Teme, or through a permanent redirect to its new one. The old site stays live while Teme reads it, and Teme changes nothing there.

## What the old site needs

Three things, all done in WordPress:

1. *The Teme Companion plugin*, installed and active. It is how Teme reads the site: it adds a site report under Tools › Teme site report, and lets Teme read the content, people, menus and redirects with an application password. Teme only reads through it. Teme needs version 0.2.0 or later; a site with Polylang needs 0.3.0 or later for its languages to come across.
2. *The site report*, made once: open Tools › Teme site report and run it. Teme reads it to count what comes across before anything is imported.
3. *An application password*: open Users › Profile for an administrator's account, and add an application password named Teme. Copy it: WordPress shows it once. Teme reads with that account, so it must be an administrator's. WordPress offers application passwords only over HTTPS.

## To connect the site

1. On **Tools › Import**, under **Add a site**, choose **Connect a site**.
2. Enter the **Site address**, the address readers use, such as `https://example.com`. Typed without `https://`, Teme adds it.
3. Enter the **WordPress user name** of the account that made the application password, and the **Application password**.
4. Choose **Connect**. It can take up to two minutes if the site is slow to answer.

Teme checks, in order, that:

- the address answers, isn't one Teme won't reach, and doesn't send Teme on to another address;
- the Teme Companion plugin is active there, and new enough;
- application passwords are switched on;
- WordPress accepts the user name and the application password, and the account is an administrator's.

If one fails, the form says which, in words, and keeps what you typed. Two causes are outside WordPress: a firewall or a bot check in front of the site blocking Teme, and a server that removes the sign-in before WordPress sees it. Whoever looks after the old site's hosting can let Teme through.

Teme keeps the application password encrypted, and never shows it again. The site then has its own page, with five steps: **Connect**, **Analyze**, **Choose**, **Import** and **Report**.

## What the plan shows

Teme reads the site report and shows the plan.

- **What comes across** counts the posts, pages, media files, categories, tags, people, comments and menus, and the languages when the site has Polylang. When posts and pages have their own SEO fields, it names the plugin they come from.
- **Not imported** lists what stays behind, each with why: custom types and custom taxonomies, subscribers, revisions, widgets and theme settings, and comments that plugins kept as likes, mentions or notes.
- When custom types hold most of the site's published items, the plan warns you first: those addresses would answer "not found" after the switch.

The counts are as of the site report. If the site has changed since, run Tools › Teme site report in WordPress again, then choose **Analyze again**.

## To list the site's other addresses

Posts are often written under more than one address: a staging copy, an old domain the site once had, or a separate host that once served the site's uploads. Links and pictures on those addresses would otherwise come across as links to another site. Under **Other addresses of this site**, list them, and Teme treats them like the site's own: a link to a post there becomes a link to the post here, and a picture there from the site's uploads is matched to its file in the media library.

- **Suggested from this site’s links** lists the hosts under the site's own domain that its posts link to, such as `stage.example.com`, with how many links each has. Tick the ones that are this site.
- **Add a host** takes another one, typed as a host name only, such as `old-example.com`. An old domain or a storage service is never suggested, so type it here.
- **Saved addresses** lists those already kept. Untick one to drop it.

Choose **Save**; Teme asks for your password first. Up to ten can be listed. You don't need to list the address with or without `www.`: Teme treats both as the same.

The list can be changed from the report too, between imports. It can't while an import is running, and it applies from the next import or catch-up.

## To make your choices and start

Under **Your choices**, the plan asks what the site can't say. Teme uses these for this import and for each catch-up after it.

- **Custom roles**: WordPress roles Teme doesn't have. Choose the Teme role people with each one get, or to leave them behind. Shown only when the site has such roles.
- **Items whose author is gone**: posts and pages whose WordPress author was deleted belong to the person you choose.
- **Images from other sites**: posts that show images kept on other sites. Tick a site to bring its images into the media library. The rest become links, with each image's description as the words. Shown only when posts have such images.
- **Media files**: how the files come across.
  - **Download them with the articles** copies each file as the import reaches it.
  - **Bring the articles first, then the pictures** gets the posts and pages up sooner. Until each picture is copied here, posts show it from the old site, so keep that site up. When the import finishes, a picture pass starts by itself: it copies the pictures posts use first, then the rest, and puts each one in place as it arrives.
- **Pause between requests**: how long Teme waits between its requests to the old site, from none to five seconds. The old site stays live while Teme reads it, and a pause keeps it quick for its readers.

People come across with their WordPress roles and their passwords.

Choose **Start the import**. Teme asks for your password first.

## To bring a copy of the uploads folder

When the old site is closed or only on a local address, or when many pictures would load a live site, make a copy of its `wp-content/uploads` folder and zip it. Under **Copy of the uploads folder** on the plan, choose the zip. It uploads in pieces, and a dropped connection carries on. Teme then takes each picture from the copy and asks the old site only for pictures the copy lacks. The copy is kept for later catch-ups. The report says how many pictures came from the copy and how many from the old site. A copy of up to 10 GB can be uploaded here; a larger one is attached by an operator. The copy never brings the files in the folders of protected plugins, such as order forms and logs, and the report says how many were left out. To delete the copy, choose **Delete the copy now** on the plan; it can't be deleted while an import from the site is running.

## To follow, stop and carry on

The import runs in the background. You can leave the page: it carries on, and the result reaches your inbox. Open the site on **Tools › Import** to see how far it has got: its counts so far and each step of the import.

**Stop the import** stops it where it is. **Carry on** picks up where it stopped; Teme asks for your password first. Carrying on never brings anything twice.

An import also stops by itself when the old site refuses in a way trying again won't help, or stops answering for about an hour. The page says why. Put that right, then choose **Carry on**.

## To follow the picture pass

When you chose **Bring the articles first, then the pictures**, the report has a **Pictures** section: how the picture pass stands, how many files it has brought, and how many posts still show pictures from the old site. **Show the picture pass** opens its own progress.

A post you change before its pictures arrive keeps showing them from the old site: replace them in the editor, where they're marked *From the old site, being copied*. If some couldn't be copied, **Bring the pictures again** tries them once more.

## To read the report

The **Report** says what came across, by kind, with each warning and error. It ends with a verdict: ready to switch when nothing failed and every old address answers on Teme, or how many problems need attention. Warnings never hold it up.

**Download the full report** gives you every item as a spreadsheet; **Download what needs attention**, only the ones that failed or have a warning. Fix them on WordPress or here, then catch up.

Each import of the site is listed on the **History** tab, newest first, with its own report.

## To bring what changed

Import early, check the site on Teme, and bring what changed on WordPress just before you switch. On the latest report, choose **Catch up**. Teme brings the posts and pages that are new or edited since the last import, new comments and new redirects, with the choices you made then; Teme asks for your password first.

Anything deleted on WordPress since is listed on the report, never deleted here. A catch-up reads only what changed, so an item that failed before and hasn't changed isn't tried again: the report says how many. Run a full import to retry them.

## To forget the site

When you've switched to Teme, choose **Forget this site** on the report, then revoke the application password in WordPress, under Users › Profile. Teme deletes its copy of the password. What was imported stays, and so does the report. To import from the site again, choose **Connect again** and enter a new application password.

## What isn't brought

Some things stay behind, and the plan or the report says so:

- *Custom types and custom taxonomies*: Teme can't import them from a live site yet. Their addresses would answer "not found" after the switch.
- *Layouts made by other plugins*: blocks from a page builder or a blocks plugin that Teme can't turn into its own come across as an *Imported content* card holding their markup as it was. You can read it, move it or delete it. WordPress's own layout blocks, such as columns and groups, keep what they held, in order, without their layout: Teme's theme owns the layout. Blocks WordPress drew when the page loaded, such as the latest posts, are left out, since the theme does that job.
- *Data that plugins kept for themselves*: it comes across only when a plugin installed on Teme says it imports that plugin's data. Otherwise it stays behind.
- *Widgets and theme settings*: set up in Teme's theme.
- *Subscribers*: readers can't have accounts on Teme yet. Their comments come across, and those who have published posts come as contributors.
- *Revisions*: earlier versions stay in WordPress.
