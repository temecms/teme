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

A card is a block that is more than text. Insert one with **Insert** on the toolbar, or from the slash menu. Each card has its own settings when you select it. There are nine kinds; the [cards reference](../../reference/cards.md) lists exactly what each holds.

- **Image**: one picture, with a caption and credit, and alt text for this use.
- **Gallery**: two to twenty-four images shown together, with one caption.
- **Embed**: a video, a track, a post or a link from another site, framed safely or shown as a bookmark.
- **Callout**: a short note, tip or warning set apart from the text.
- **Code**: a block of code, coloured for its language, with an optional caption.
- **Button**: a call to action that leads to another item or to an address elsewhere.
- **Audio**: a sound file from the library, played in the page, with a caption and a transcript.
- **Video**: a video file from the library, played in the page, with a poster, a caption and caption tracks.
- **Imported content**: markup the importer could not turn into cards, kept as it came. Only an import adds one; you can read it, move it or delete it.

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

Under the text is **Search and social**. It is closed. Its summary line says how the item will appear in search results and when it is shared.

1. Choose **Edit** to open it.
2. Set the title and the description for search results, then the title, description and image for the card that social sites show.
3. Choose **Done** to close it again.

Open **Advanced** for **Hide from search engines**, the **Canonical address**, the page and article type, and the snippet controls. It stays closed until you open it.

The summary ends "Every check is in the side panel." That takes you to **Checks**.

## To work without distraction

Choose **Focus** in the header. The editor hides everything but the writing. Choose **Leave focus** to bring it back. In **Preferences** you can make the editor open this way every time; see [your account](your-account.md).

## To know whether your work is saved

The editor saves as you go. The state sits at the top.

- **Saving…** means a save is on its way.
- **Saved** means everything here is saved.
- **Not saved** means your changes are only on this page. Hover it for the reason: you are offline, or someone else saved a new version first.

If you are offline, keep the tab open. Your changes are saved when the connection is back.

## To use the side panels

The editor has a side panel for the item's settings. Choose **Hide settings** to give the text more room, and **Show settings** to bring it back. The panel is headed **Post settings**, **Page settings** and so on, by the kind of item.

In the header you also find:

- **Document overview**: a count of words, headings and images, and the outline.
- **Checks**: what to put right before you publish.
- **History**: every saved version; see [revisions and preview](revisions-and-preview.md).
- **Share**: preview links for people without an account.
- **Assistant**: help with the text, if the site has turned it on.

## To check an item before you publish

Open **Checks**. It reads the item and lists what it finds: a missing title, a description, a featured image, images with no alt text, links to items readers can't reach, and what search engines will be told. Each check says whether it passed. Nothing here stops you from publishing; it tells you what you may want to fix first.

## If someone else is editing the item

Under the title you see who else has the item open. If they save first, and then you try to save, the editor stops with a message that someone else changed the item. Your work is still on the page. You can copy your text with **Copy my text**, keep it as its own draft with **Save mine as a new draft**, or **Open the current version** and lose the changes you have not saved.
