---
title: The banners at the top of the admin
audience: editors
kind: task
who: Whoever the banner is for; most are for administrators
screens:
  - dashboard
  - types.index
sources:
  - resources/js/components/status-banner.tsx
  - app/Domain/Status
  - app/Domain/Seo
  - app/Domain/Addresses/AddressSettingsGroup.php
  - resources/js/pages/types
---

# The banners at the top of the admin

A banner under the header tells you about something wrong or in progress that you can fix. This page says what each means and what to do.

## To act on a banner

- One banner shows at a time: the most serious. When you have dealt with it, the next one, if any, takes its place.
- Only people who can fix the problem see it. An editor sees the menu banner; an administrator sees all of them.
- A banner has no dismiss. It goes when the problem does.
- Some banners have a button that takes you where you can fix it. Others have none, because the fix is on the server: tell whoever runs the server what the banner says.

## What to do when the banner says the theme can't be used

<!-- banner: ThemeUsable -->

The banner says the site's theme isn't installed, or can't be used, so visitors see a plain fallback. It is the most urgent of all, and administrators see it.

Choose **Choose a theme** and pick one that works. If the theme is yours and was working, tell whoever runs the server: its files may have been removed.

## What to do when the banner says scheduled posts aren't publishing

<!-- banner: SchedulerRunning -->

The banner says the scheduler hasn't run, or when it last did. Scheduled posts, backups and other timed work wait until it does. This banner has no button. Tell whoever runs the server what the banner says, so they can start it.

## What to do when the banner says background work is waiting

<!-- banner: QueueWorkerRunning -->

The banner says that uploaded images' sizes and other background work are waiting, because no worker is taking jobs. It has no button. Tell whoever runs the server, and give them the banner's words: it names the jobs that are waiting.

## What to do when the banner says some changes can show late

<!-- banner: PageCacheCdnPurges -->

One of two banners says that some changes can show up to a while late. This one says the service in front of the site refused the last request to clear changed pages. Readers may see old pages until the saved copies run out. It has no button; tell whoever runs the server. Until it is fixed, you can clear the copies Teme holds; see [the page cache](page-cache.md).

<!-- banner: PageCacheFolderShared -->

The other banner has the same lead: some changes can show late. It says that a part of Teme on the server clears saved pages from a folder the web server doesn't read. It has no button either. Tell whoever runs the server, and give them the banner's words, which name the server.

## What to do when the banner says the backups need a look

<!-- banner: BackupsSafe -->

The banner repeats the first thing wrong with the backups, in the words of the backups screen. Choose **See the backups** and read the verdict; the page [backups](backups.md) explains it. An administrator sees this banner.

## What to do when the banner says no menu is chosen

<!-- banner: NavigationSlotsBound -->

The banner says that no menu is chosen for a place in the theme, so visitors see nothing there. Choose **Choose menus**, pick a menu for each place, and save. See [categories, tags, menus and regions](organising.md).

## What to do when the banner says the API now answers an address

<!-- banner: ApiAddressTaken -->

The banner says that every address starting /api/ now belongs to Teme’s API, so something on your site that used one can no longer be reached there. It names what: a post, page or other item; a category, tag or author; a setting in **Settings › Addresses**; or an old address. For an item, a category, a tag or an author, choose **Edit it** and give it another address. For a setting, choose **Open Addresses** and choose another. For an old address, choose **Open Redirects**, then point it at the new address or remove it. Whoever manages the site’s addresses sees this banner.

## What to do when the banner says a redirect no longer answers because of a language

<!-- banner: LanguageHomeShadowsRedirect -->

The banner says that a redirect from an old address, such as /fr/, no longer answers. That address is now the home page for one of the site’s languages, and a home page comes before a redirect. Choose **See the redirect**. It opens **Tools › Redirects** at that redirect. Point the old address somewhere else, or remove the redirect. Whoever manages redirects sees this banner.

## What to do when the banner says the person the site represents has no account

<!-- banner: RepresentedPersonGone -->

The banner says the person the site represents no longer has an account, so search engines are told nobody in particular publishes it. Choose **Choose who publishes the site**. It takes you to **Settings › Search and social**. Pick who publishes the site there. See [the site's settings](settings.md).

## What to do when the banner says search is being rebuilt

<!-- banner: SearchLanguageRebuilding -->

The banner says it is rebuilding search for a language, with the number of items to go. This follows a change of the site's language in **Settings › General**. The banner has no button and goes by itself when the rebuild is done.

## What to do when the banner says a saved type can't be used

<!-- banner: StoredTypesRefused -->

The banner says a content type saved on this site can't be used: its key or admin address now clashes with code, or it names a taxonomy or feature that is gone. One saved type is named with the reason; several are listed together. Administrators who may manage types see it. Choose **Open Types**: the list shows each saved type that can't be used, with the reason. Choose **Edit to fix** to change what the reason names, or delete the type if it has no items. You can also bring a corrected file with `php artisan teme:types:import`.

<!-- banner: StoredTaxonomiesRefused -->

The banner says a taxonomy saved on this site can't be used: its key or admin address now clashes with code, or it names a content type that is gone. One saved taxonomy is named with the reason; several are listed together. Administrators who may manage types see it. Until it is put right, its term screen, its pickers in the editor and its public pages are missing. Choose **Open Types** to see which types use it, or bring a corrected file with `php artisan teme:types:import`. A Taxonomies screen to edit it directly comes in a later update.

## What to do when the banner says a Teme release is available

<!-- banner: UpdateAvailable -->

The banner says a newer Teme release exists and which version the site runs. It says so with security fixes when any newer release carries them. Choose **See the update** to read what changed and how to update the server. The link is not shown on **Tools › Updates** itself, since you are already there. Administrators see it. It is the least urgent banner, unless it mentions security fixes.

## What to do when the banner says a plugin can't run

<!-- banner: PluginsRunning -->

The banner says an enabled plugin can't run, and why, or that Teme disabled a plugin after it failed to start five times, or that a plugin has stopped part of its work after repeated errors. Administrators see it. Tell whoever runs the server what the banner says: the fix is in the plugin's code or in the site's build. Choose **Open Plugins** to see each plugin's state and why, under **Site › Plugins**; the link is not shown on that page itself. If a plugin's new version asks for more access, review it there.
