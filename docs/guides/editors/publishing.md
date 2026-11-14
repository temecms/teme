---
title: Publishing and scheduling
audience: editors
kind: task
who: Authors, editors and administrators publish; contributors submit for review
screens:
  - content.edit
  - content.index
sources:
  - resources/js/components/editor
  - resources/js/pages/content/index.tsx
  - resources/js/components/list
  - resources/js/pages/content/edit.tsx
  - app/Domain/Contents
  - app/Domain/Types
---

# Publishing and scheduling

An item has a status, shown in the header as a chip. It moves from draft, through review if your role needs it, to published. Contributors cannot publish; every other role can. The [roles table](README.md#what-you-can-do) shows who can do what.

## To save a draft

The editor saves your work as you go, and **Save draft** saves it at once. A draft is seen only by people who can edit it. Nothing on the site changes.

## To submit an item for review

A contributor cannot publish. Where an author or editor would see **Publish**, a contributor sees **Submit for review**.

1. Check the item with **Checks**.
2. Choose **Submit for review**.
3. Confirm. The chip changes to **In review**.

The item waits. If it comes back with **Returned for changes**, read the note, fix the item and submit it again.

On an item that is already published, the same step is **Submit update for review**: the live version stays as it is while the update waits.

## To review someone's work

You can review if you can publish. Open the item that is **In review**. You can read the text, **Preview** it, or use **Compare with the published version**. Then decide.

- Choose **Approve** to publish it now.
- Choose **Approve and schedule…** to publish it at a time you choose. Pick the **Date and time**, then **Approve and schedule**.
- Choose **Return**, write what needs to change, and choose **Return to the writer**. The writer sees your note. Choose **Not now** to close the box without sending.

## To publish now

1. Open the item and look at **Checks**. Warnings don't stop you.
2. Choose **Publish**.
3. In the confirmation, check what it says, then choose **Publish**.

The chip changes to **Published**. If you open the **Publish** panel, you can see the address, and **View on site**.

## To schedule

1. In the publish panel, open **Publish** and choose when: **Immediately**, **At a set time…**, or **After review** (the item waits for someone to approve it).
2. For **At a set time…**, set the **Date and time**, or pick one of the **Quick choices**: **Tomorrow morning**, **This evening** or **Next Monday**.
3. The times are in the site's time zone, which the panel names.
4. Choose **Schedule** and confirm.

The chip changes to **Scheduled**. The site publishes the item at that time; nobody has to be signed in, and no visit to the site is needed. A time must be in the future: the panel says "Choose a time in the future." if it isn't.

## To change or cancel a schedule

Open the item. The panel says *Scheduled for* and the time. Choose **Reschedule…** to pick another time, or **Cancel** to take the schedule off.

If a scheduled publication fails, the panel says so and why. If it says the item is held, nothing publishes until someone who may publish schedules it again.

## To publish changes to a published item

You edit a published item without changing the site. The chip reads **Published, with unpublished changes** until you act.

1. Make your changes. They are saved but not live.
2. Choose **Update**, and confirm.

Readers see the new version at once. To go live at a set time instead, choose **Schedule update**.

## To take an item off the site

The editor has no button for this. Use the list.

1. Open **Posts**, **Pages** or the list for your kind of item.
2. Point at the item's row, open **More** under its title, and choose **Revert to draft**.

The item goes back to a draft. Its address then answers "not found" for readers.

## To move an item to the trash

At the bottom of the side panel is **Move to trash**. In the list, choose **Trash** under the item's title, or **Move to trash** for a selection. A trashed item can be brought back; see [lists](lists.md).

## To make an item private

In the publish panel, **Visibility** has three choices.

- **Public**: anyone can read it once it's published.
- **Private**: only people who can edit it can read it, here in the admin. On the site its address answers "not found". Private items never take comments.
- **Password protected**: readers need the password to read it. Lists on the site show its title, without its excerpt or image. Items protected by a password take no new comments.

## To protect an item with a password

1. In **Visibility**, choose **Password protected**.
2. Type the password in **Protect with this password**.
3. Give the password to the people who should read it. Changing it asks everyone for the new one.

If the panel says the password can't be read on this server, nobody can open the item. Set a new one with **Change password**.

## To change an item's address

In the panel, **Address** shows where the item lives. Choose **Change address**, edit it, and save. Changing it changes the published item at once. To send the old address to the new one, see [redirects](redirects.md).

## To change the author

Choose a name in **Author**. Changing it changes the published item at once.

## To choose a template

If the theme offers more than one layout for this kind of item, pick one in **Template**. **Default** uses the theme's own.

## To decide who may comment on one item

Use **Discussion** in the panel to allow or stop comments on this item alone. Whoever publishes it sets this. For the whole site, see [comments](comments.md).

## What the status labels mean

- **Draft**: written but not published. Only people who can edit it see it.
- **In review**: a contributor has submitted it, and someone who can publish has not yet decided.
- **Scheduled**: it will publish at the time shown.
- **Published**: it is on the site.
- **Private**: it is published but hidden from readers.
- **Published, with unpublished changes**: the live version is as it was; you have saved newer changes.
- **Published, with an update in review**: the live version is as it was; an update waits for a decision.
- **Published, with an update scheduled**: an update will go live at the time shown.
- **Private, with unpublished changes**, **Private, with an update in review** and **Private, with an update scheduled**: the same, for an item that is hidden.
