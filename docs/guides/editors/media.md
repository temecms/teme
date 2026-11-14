---
title: Media
audience: editors
kind: task
who: Authors, editors and administrators upload; editors and administrators edit any file
screens:
  - media.index
sources:
  - resources/js/pages/media
  - resources/js/components/media
  - resources/js/components/media-drawer.tsx
  - app/Domain/Media
---

# Media

**Media** holds every file the site uses: images, video, audio and documents. You add a file once and place it in as many items as you like.

## To upload

1. Open **Media**.
2. Choose **Upload files**, or drop files anywhere on the page.
3. Watch the upload list. Each file shows **Preparing…** while it is made ready, and **Failed** if it didn't work. Choose **Retry** on a failed one.

If a file is already in the library, the upload list says so: "Already in the library as" and the name. Choose **Use the existing one** to keep a single copy, or **Upload anyway** to add it again.

You can also upload from inside the editor, when you add an image to an item. The file lands in the same library.

## To find a file

- Search by file name, title or alt text with **Search media**.
- Filter by type (**Images**, **Video**, **Audio**, **Documents**), by date, by **Mine** or **Unused**, and by **Missing alt text**.
- Switch between **Grid** and **List** to suit the job. The grid shows pictures; the list shows details.
- **Clear filters** brings everything back.

## To describe a file

Open a file to see its details in a drawer. Describe it while you are there.

- **Alt text** says what an image shows, for someone who cannot see it, such as a person using a screen reader. It is read aloud in place of the picture, so write what matters, not "image of". It is used wherever you place the image without alt text of its own. Where you place it you choose whether it is decorative.
- **Caption** and **Credit** are shown with the image, if the place you use it shows them.
- **Title** and **Licence** keep your own records.

Choose **Save**. The drawer may offer **Suggest alt text**, if the site has the assistant on; read the suggestion and save it only if it is right.

The drawer also shows **Uploaded for**: the item the file was first uploaded for. Use it to show every file uploaded for one item.

## To replace a file

You replace the file but not its place in the library. Every item that uses it follows.

1. Open the file.
2. Choose **Replace…** and pick the new file. It must be of the same kind.

The file keeps its title, description and every use. For an image, its sizes are made again in the background.

## To see where a file is used

Open the file. Under **Used in** it lists the items that use it, or says "Not used anywhere." Check this before you delete.

## To move a file to the trash, restore it, or delete it for good

1. Open the file and choose **Move to trash**. If live items use it, the drawer tells you what they will show instead. Items that use it show nothing in its place until you restore it.
2. To bring it back, open the **Trash** view, open the file and choose **Restore**. Every item that used it shows it again.
3. To remove it for good, choose **Delete permanently**. This deletes the file and every size made from it, and it can't be undone.

## If an image's sizes failed

The drawer says "Teme couldn't make this image's sizes." Choose **Try again**. The sizes are made in the background, so a worker must be running. If **Try again** does not help, tell whoever runs the server.

The upload list has its own **Retry**. That is for a file that failed to upload, not for sizes.
