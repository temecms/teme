# Screenshots

Pictures of Teme, made by the Screenshots workflow from demo content: a small publication called Northwind Journal, with invented writers and articles and public-domain photographs. Run it from **Actions › Screenshots**; it also runs on every release. A name ending `-dark` is the same picture in the dark color scheme.

| Picture | What it shows |
|---|---|
| `editor.png`, `editor-dark.png` | The editor, with an article open and its publish panel beside it |
| `posts.png`, `posts-dark.png` | The posts list, with published articles and a draft |
| `media.png`, `media-dark.png` | The media library, with the demo's photographs |
| `design.png`, `design-dark.png` | The Themes screen, with the default theme active |
| `plugins.png`, `plugins-dark.png` | The Plugins screen, with one plugin enabled |
| `plugin-area.png`, `plugin-area-dark.png` | A plugin's own area in the admin: a table of its records |
| `home.png`, `home-dark.png` | The public home page in the default theme, with its lead story |
| `article.png`, `article-dark.png` | An article on the public site: its title, standfirst and featured image |
| `article-phone.png`, `article-phone-dark.png` | The same article on a phone |
| `category.png` | A category's page on the public site |

## The guides' pictures

Every screen the editors' and operators' guides point at has two pictures in `docs/screenshots/guides`, 1440 × 900, taken as an administrator of the demo site: `<route name>.png` in the light scheme and `<route name>-dark.png` in the dark one. The route names are the ones each guide lists under `screens:` in its front matter, so a guide that points at a new screen gets its pictures on the next run, and one that points at a screen that no longer exists fails the tests. A screen that needs an item, such as `content.edit`, opens the demo's own.

`docs/screenshots/guides/index.json` lists them for whatever shows the guides:

```json
{
  "media.index": { "label": "Media", "light": "media.index.png", "dark": "media.index-dark.png" }
}
```

It is sorted by route name, and `label` is the screen's own heading as the admin shows it. A screen that can't be pictured, such as the page an invitation email links to, is left out of the index and named, with its reason, in `tests/Fixtures/Guides/GuideScreens.php`. `tests/Feature/Guides/GuideScreenshotsTest.php` fails when a guide's screen is in neither place.

## How they are made

The workflow builds the demo site through Teme's own operations (`tests/Support/DemoSite.php`), opens each screen in Chromium at a fixed size (1440 × 900, or 390 × 844 for the phone), waits for fonts and images, and saves the picture. The test is `tests/Browser/ScreenshotsTest.php`, in the `screenshots` group, which the normal test runs leave out. To make them on your own machine:

```bash
npm run build && npm run build:themes && php artisan teme:themes:link
vendor/bin/pest --group=screenshots tests/Browser/ScreenshotsTest.php
```

A picture whose pixels haven't changed is left as it is, so the same version always gives the same files. The photographs' sources and licences are in `tests/Fixtures/demo/images/CREDITS.md`.
