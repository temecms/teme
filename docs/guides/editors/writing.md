---
title: Writing
audience: editors
kind: task
who: Contributors, authors, editors and administrators
screens:
  - content.edit
  - content.index
  - dashboard
sources:
  - resources/js/pages/content/edit.tsx
  - resources/js/components/editor
  - resources/js/components/dashboard
  - app/Domain/Cards
  - app/Domain/Fields
  - app/Domain/Types
---

# Writing

Everything you write is kept as structured text, not as HTML. You never see or edit markup; you write, and the theme decides how it looks.

## To start a post or a page

There are two ways in.

- On **Home**, use **Quick draft**. Type a **Title**, say what it's about, and choose **Save draft**. The draft opens in the editor when you want it.
- On a list, such as **Posts** or **Pages**, use the add button at the top. It opens an empty item.

A new item is a draft. Nobody sees it until it is published; see [publishing](publishing.md).

## To write and format

Type in the body. Three things help you format.

- *The toolbar* sits above the text. It has **Undo**, **Redo**, a block type menu (**Paragraph**, **Heading 2**, **Heading 3**, **Heading 4**, **Quote**, **Code block**), **Bold**, **Italic**, **Link**, lists (**Bulleted list**, **Numbered list**, **Task list**), a **Table**, and more under **More**. In a narrow window the toolbar folds some controls into **More**.
- *The selection menu* appears when you select text: **Bold**, **Italic**, **Underline**, **Strikethrough**, **Inline code**, **Highlight** and **Link**.
- *The slash menu* opens when you type `/` on an empty line. Keep typing to narrow it, then press Enter to insert: a heading, a list, a **Quote**, a **Horizontal rule**, a **Table** or a card.

Use headings in order: **Heading 2** for sections, **Heading 3** inside them. The **Document overview** panel shows the outline you have made.

## To add a card

A card is a block that is more than text. Insert one with **Insert** on the toolbar, or from the slash menu. Each card has its own settings when you select it. There are eleven kinds, and two more for a theme's regions; the [cards reference](../../reference/cards.md) lists exactly what each holds.

- **Image**: one picture, with a caption and credit, and alt text for this use.
- **Gallery**: two to twenty-four images shown together, with one caption.
- **Embed**: a video, a track, a post or a link from another site, framed safely or shown as a bookmark.
- **Callout**: a short note, tip or warning set apart from the text.
- **Code**: a block of code, coloured for its language, with an optional caption.
- **Button**: a call to action that leads to another item or to an address elsewhere.
- **Audio**: a sound file from the library, played in the page, with a caption and a transcript.
- **Video**: a video file from the library, played in the page, with a poster, a caption and caption tracks.
- **FAQ**: questions and their answers. On the page each question opens its answer; its questions take the heading level below the heading before the card.
- **Steps**: how to do something, as numbered steps, each with a title, its text and an optional image, after an optional summary, the total time and what it needs.
- **Imported content**: markup the importer could not turn into cards, kept as it came. Only an import adds one; you can read it, move it or delete it.

These cards go only in a theme's region, under **Content › Regions**:

- **Custom HTML**: code an administrator keeps in Settings › Site code and marks as placed with a card. You choose it by name; you never see or change the code, and the site draws it exactly as written.
- **Featured posts**: a short list of posts, either the latest of a type, optionally only those filed under one category or tag, or posts you choose by hand, up to 12, in the order you set.
- **Plugin card**: a card a plugin offers, such as an ad slot or a sign-up form, listed in Add card under the plugin's own name. You fill in the fields the plugin asks for, and the plugin draws it on the site. While its plugin is disabled the card is kept, greyed, and shows nothing.
- **Theme card**: a card the site's theme brings for its own regions, such as an author bio, listed in Add card under the theme's name. You fill in its fields, and the theme draws it. While another theme is in use the card is kept with its region and shows nothing; it comes back with its theme.

## To link to another item

1. Select the words, then choose **Link**.
2. Under **Link to something on this site**, search by title or name and choose a post, page, category, tag, author or file. The link keeps working if that item's address changes.
3. Or type an address under **Or an address**: a page on another site, an email or phone link, or a place on this page. An address is stored as you type it.
4. Choose **Apply**.

To take a link off, open it again and choose **Remove link**.

## To add an image

Insert an **Image** card, then choose one from the library, or upload a file. You can also paste or drop an image into the text.

Fill in these on the card:

- **Alt text** says what the image shows, for someone who cannot see it. Write it for every image that carries meaning.
- Tick **Decorative: it adds nothing a reader would miss** only for an image that is purely for show; the alt text is then left out on purpose.
- **Caption** and **Credit** are shown with the image.
- **Width** sets how wide it stands in the column.

If you paste an article from another site, its images are not yours yet. They wait under **Pasted images**, marked **Pasted image, not imported**. Choose **Import** for one image, or **Import all**, but only for images you have the right to use. Choose **Remove** to drop one instead. If an import fails, choose **Try again**.

For uploading and describing files in the library, see [media](media.md).

## To say what search engines and social sites see

Under the text is **Search and social**. It is folded. Its header shows the title the item has in search results and how many things there are to check.

1. Choose the header to unfold it.
2. On **Search**, the **Result preview** shows the item as a search result may show it, on a desktop or a phone. Under it, set the **SEO title** and the **Meta description**.
3. On **Social**, set the title, description and image for the card that social sites show.
4. Choose the header again to fold it.

**Advanced** holds **Hide from search engines**, the **Canonical address**, the page and article type, and the snippet controls.

After the fields, **Checks** lists the search checks. A check that wants a look has **Fix**, which takes you to its field. A warning never stops you publishing.

## To work without distraction

Choose **Focus mode** in the editor's bar, the icon beside the panel's toggle. The editor hides everything but the writing. Press Esc, or choose it again, to bring the rest back. In **Preferences** you can make the editor open this way every time; see [your account](your-account.md).

## To know whether your work is saved

The editor saves as you go. The state sits in the editor's bar, after the item's status.

- **Saving…** means a save is on its way.
- **Saved** with a time, such as "Saved 2 min ago", means everything here is saved.
- **Not saved** means your changes are only on this page. Hover it for the reason: you are offline, or someone else saved a new version first.

If you are offline, keep the tab open. Your changes are saved when the connection is back.

## To use the side panels

The editor has a side panel for the item's settings, named after the kind of item, such as **Post settings**. The last button in the editor's bar hides it to give the text more room, and shows it again.

At the top of the panel are **Preview**, **Save draft** and **Publish**. Under them come the item's settings, then sections you can fold: the categories and tags, **Outline** (the headings; choose one to go to it), and **Checks**. Each section's header says what it holds, such as "6 headings" or "1 to look at".

In the editor's bar you also find:

- **Outline**: a count of words, headings and images, and the outline.
- **History**: every saved version; see [revisions and preview](revisions-and-preview.md).
- **Tools**: every tool, Teme's and the plugins'. Pin a tool there to keep it in the bar, such as **Share** for preview links or **Assistant** for help with the text, if the site has turned it on.

Under the text, the count line gives the words, the characters and the minutes it takes to read.

## To check an item before you publish

Open **Checks** in the side panel. It reads the writing and lists what it finds: the title's length, the excerpt, the featured image's alt text, images in the text with no alt text, headings that skip a level, and links to items readers can't reach. Choose **Show** to go to a problem. Then it says how many search checks want a look; choose that line to open **Search and social**. Nothing here stops you from publishing; it tells you what you may want to fix first.

## If someone else is editing the item

Under the title you see who else has the item open. If they save first, and then you try to save, the editor stops with a message that someone else changed the item. Your work is still on the page. You can copy your text with **Copy my text**, keep it as its own draft with **Save mine as a new draft**, or **Open the current version** and lose the changes you have not saved.
