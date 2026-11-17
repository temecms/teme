---
title: Your first plugin
type: tutorial
audience: plugin
summary: From an empty folder to an enabled plugin that listens to a hook.
rests_on: [docs/guides/developers/examples/plugins/first-plugin, packages/teme-contracts/src/Hooks, app/Domain/Plugins]
---

# Your first plugin

This tutorial takes you from a copy of the handbook’s example to an enabled plugin that hears when an item is published. There is no scaffold command yet: start from the example.

## 1. Copy the example

Copy `docs/guides/developers/examples/plugins/first-plugin` into your site’s plugin path (or keep working in the copy under this repository while you learn).

The package name is `teme-examples/first-plugin`. Its manifest asks for `content.read` and listens for `ContentPublished`.

```json
// docs/guides/developers/examples/plugins/first-plugin/teme-plugin.json
{
    "manifest": 1,
    "name": "teme-examples/first-plugin",
    "code": "first-plugin",
    "label": "First plugin",
    "description": "The handbook's first plugin: listens when a post is published.",
    "version": "1.0.0",
    "requires": {
        "teme": "^1.0"
    },
    "plugin": "TemeExamples\\FirstPlugin\\FirstPlugin",
    "capabilities": ["content.read"],
    "hooks": ["ContentPublished"],
    "author": "Teme Examples",
    "author_url": "https://example.com/author",
    "homepage": "https://example.com/first-plugin"
}
```

## 2. Read the plugin class

Teme builds the class named under `plugin` with only the contracts it offers plugins. This example asks for the settings reader and subscribes in `register()`:

```php
// docs/guides/developers/examples/plugins/first-plugin/src/FirstPlugin.php
    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentPublished::class, function (ContentPublished $published): void {
            $site = $this->settings->string('general', 'site_title');
            self::$heard[] = $site.' published '.$published->item->id;
        });
    }
```

Subscribe only inside `register()`. Do nothing slow there.

A constructor may ask only for the contracts Teme offers plugins: the settings and secret readers always, and each door when the plugin was granted its capability, such as `OutboundHttp` for `http`. The [contracts reference](../../../reference/contracts.md) lists them all, with the capability each needs; a constructor that asks for anything else is refused when the plugin is enabled.

## 3. Enable it

Agree to what the manifest asks for, then enable:

```sh
php artisan teme:plugins:enable teme-examples/first-plugin --agree
```

Teme validates the manifest, checks capabilities against the hooks you named, and starts the plugin on the next request.

## 4. What you should see

When an item is published, the listener runs after the change is saved. The example records one line that includes the site title and the item’s id. Point Teme at the example folder with `teme.plugin_paths` in local work, or install the package with Composer on a real site; [Set up a development site](development-site.md#your-plugin-through-composer) shows how to install one you are still writing.

Next: [The manifest](../concepts/manifest.md) and [Hooks](../concepts/hooks.md).
