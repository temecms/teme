---
title: An image service
type: how-to
audience: plugin
summary: How a plugin gives the address of each image size from an outside image service, one that resizes or converts images when they are asked for, in place of the sizes core made at upload.
rests_on: [app/Domain/Plugins/Images, app/Domain/Media/MediaUrls.php, app/Domain/Cards/CardMedia.php, packages/teme-contracts/src/Media, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# An image service

Core makes each image's sizes once, at upload, and serves them from the media disk. Some sites would rather have images resized or converted when they are asked for, by address, from a service such as imgproxy, Cloudflare Images or Cloudinary. A plugin connects such a service through one socket: when a page or a screen needs an image's address, core asks the plugin's driver for it.

Media is referenced by ID and every address is made when the page is drawn, so nothing stored changes. Turn the plugin off and core's own addresses come back.

## Declaring it

The plugin asks for `media.read` and declares the service under `imageService`, with a label and the one host its addresses are on:

```json
"imageService": { "label": "Imagery CDN", "host": "images.example.com" }
```

The `label` is at most 40 characters. The `host` is a host name, or `*.` and a domain. When the plugin is turned on, the person agrees to its images' addresses coming from that service, at that host.

## The driver

The plugin's class implements `HasImageService`, whose `imageService()` returns an `ImageService`. Core asks its `imageAddress()` for each size of each image it draws, with the image's ID, the size's name and the file format core would serve:

```php
// In imageAddress(string $mediaId, string $variant, string $format): ?string
return "https://images.example.com/{$mediaId}/{$variant}.{$format}";
```

The sizes are `thumb`, the widths `w320` to `w2560`, and, for an image used as a featured or social image, `social`, `crop-16x9`, `crop-4x3` and `crop-1x1`. The format is a lower-case extension, `webp` today.

Return one of these, or null to leave the address to core:

- an `https://` address on the declared host, with no user, password or port;
- a path on this site, starting with a single `/`, which core joins to the site's address where an absolute one is needed.

Anything else is refused, recorded once a request as the plugin's fault, and core's address is drawn. A driver that throws is treated the same way.

The driver only gives addresses. It is handed nothing that changes the site, and it is asked while pages are drawn, so keep it to string work: no network calls.

## Where the addresses go

The driver's addresses are used wherever core draws an image: image and gallery cards, featured images, the social image and the structured data's images, the images in the sitemap, and the thumbnails in the media library and lists. The declared host joins the `img-src` of the page's content security policy, and of the admin's, so the browser loads from it.

Pages are cached with the driver's addresses in them, and purged when the plugin is turned on or off, or its settings are saved.

## One at a time

One image service is active at a time. While several enabled plugins declare one, the one enabled first is used.
