---
title: The site's settings
audience: editors
kind: task
who: Administrators change settings; other roles can read this page to know what exists
screens:
  - settings.show
  - settings.discussion
  - settings.seo
  - settings.addresses.show
  - settings.ai
  - settings.site-code
  - settings.fediverse
  - themes.index
sources:
  - resources/js/pages/settings
  - resources/js/components/fields
  - resources/js/layouts/settings
  - resources/js/pages/themes
  - resources/js/components/themes
  - app/Domain/Settings
  - app/Domain/Seo
  - app/Domain/Addresses
  - app/Domain/SiteCode
  - app/Domain/Ai
  - app/Providers/SettingsServiceProvider.php
---

# The site's settings

**Settings** is for the things that apply to the whole site. Changing most of them takes the administrator role; the [roles table](README.md#what-you-can-do) says who can. Every setting and its default is listed in the [settings reference](../../reference/settings.md), which this page does not copy.

## To change the site's name, tagline, time zone and language

1. Open **Settings › General**.
2. Under **The site**, change the **Site title**, the **Tagline** and the administration email.
3. Under **Time and language**, choose the **Time zone** and the **Site language**.
4. Choose **Save general settings**.

The time zone decides how dates are shown and when scheduled items go live. Changing the language rebuilds search in the background, and a banner at the top of the admin says so until it is done; see [status banners](status-banners.md).

## To choose the site's colour

The brand colour is on **Settings › General** too, in its own panel, and **Save general settings** saves it with the rest of the page. It is used by the theme, so it can look different from theme to theme. The site's name, tagline and brand colour stay when you change theme.

## To choose the home page and how many items a list shows

Open **Settings › Reading**. Choose what the home page shows: your latest posts, or a page you choose. Then set how many items each page of a list shows. Leaving it at 0 uses the theme's own number. Under **Types on the home page and in the main feed**, tick the kinds of item the latest list and the site's feed carry: only Posts are ticked until you change it, and a new kind of item is never added on its own. A category's or a tag's page always lists everything filed under it. Choose **Save reading settings**. That saves the home page too.

## To change how items are addressed

Open **Settings › Addresses**. It shows the shape of the addresses of every public kind of item, category, tag and author, with examples of how a real one will read. Each kind of item with an archive page, other than posts, also has the address of that page here, one word: it lists every published one, newest first, and its feed is that address followed by `feed/`.

1. Choose a form for items, such as **Post name** or **Day and name**, or **Custom**.
2. Check the sentence under it: "Old addresses keep working: visitors and search engines are sent to the new one."
3. Choose **Save address settings**.

The page then shows progress while the addresses are updated: "Every address is up to date." means it has finished. If two kinds would share one address, the page says so and does not save.

## To control what search engines and social sites see

Open **Settings › Search and social**. It sets the titles search engines show, what they may list, the cards social sites show, the feeds, the sitemap and what robots.txt says. Choose **Save search and social settings**.

A copy of the site that isn't the live one asks search engines to stay away, whatever these settings say.

To change these for one item, see [writing](writing.md).

## To add code to every page

Open **Settings › Site code**. Use it for code that belongs on every public page, such as analytics. Code here runs in every reader's browser, so add only code you trust.

1. Choose **Add code** and name it, so everyone can tell what it is.
2. Choose where it goes, then paste the code.
3. Leave **Switched on** ticked to add it to every page, and choose **Save code**.

Saving may ask for your password. Every change is logged, with what it was before. If the host has switched site code off, the page says so; nothing is added to any page and nothing here can be changed.

## To choose the theme

Open **Themes**. A theme draws the site; it adds no features of its own.

1. Pick a theme to see its settings, and **Preview** it.
2. Choose where each menu shows under **Menus**.
3. When you are ready, choose **Publish**. **Discard** drops the changes you haven't published.

## What each Settings page is for

### General

The site's name, tagline, administration email, time zone, language and brand colour. Administrators change it.

### Writing

How long the trash keeps items and comments. Administrators change it.

### Reading

What the home page shows, and how many items each page of a list holds. Administrators change it.

### Discussion

Whether the site has comments, who can comment, which comments wait for approval, and the emails they send. Administrators change it; see [comments](comments.md).

### Search and social

Titles in search results, what search engines may list, social cards, feeds, robots.txt and the 404 log. Administrators change it.

### Addresses

The public addresses of every public kind of item, category, tag and author, and of each archive page. Administrators change it.

### Media

Limits on the files people can upload, and the image widths Teme makes. Administrators change it.

### People

How everyone with an account signs in: password rules, sign-in limits and session lengths. Changing any of these asks you to confirm your password. Administrators change it.

### Site code

Code added to every public page. Administrators change it.

### AI

The AI services Teme may use, the model each task uses, and how much people may ask. Administrators change it.

### Languages

The language your site is written in, and the other languages it publishes in, in one table: each language's address prefix, whether readers see it, and how much is written in it. The default language comes first; change it on **General**. **Add a language** adds another, and **Edit** changes its prefix or hides it from readers while it is translated. The menu at the end of a row moves it up or down, which is the order the language switcher lists, or removes it once nothing is written in it. Under the table, **Translated content** says which content types and taxonomies have versions in other languages. Administrators change it.

### Management API

Whether outside tools, such as an importer or a script, may write to the site, and the keys they sign in with. Each key acts as one person, with only the abilities chosen for it. Making a key asks you to confirm your password. Administrators change it.

### Fediverse

Whether people on Mastodon and other fediverse servers can follow the site, and who they can follow: the blog, its authors, or both. Switching it off pauses it and keeps the followers. It also lists the site's accounts, the servers not answering, and the blocked servers. Administrators change it.

### Updates

The Dokploy API key Teme uses to update the site from Tools › Updates, on a site that runs on Dokploy. It is kept encrypted and never shown again, and saving it asks you to confirm your password. When Teme can't use the key, or has none, the page says why in one sentence. Those who may update Teme change it.

## To find a setting

Type in **Find a setting** at the top of **Settings**, for example "time zone". It lists the settings that match and takes you to their page.

## To save your changes

Each page is a set of titled panels. Once you change something, a bar appears that says what it saves. Its button is named after the page: **Save general settings**, **Save reading settings** and so on. Pages with their own forms say **Save address settings**, **Save AI settings**, **Save discussion settings** and **Save search and social settings**. On **Discussion**, the switch at the top acts at once, with no button; while the rules below have unsaved changes it waits until you save or discard them.

If you leave a page with changes unsaved, the admin asks first.
