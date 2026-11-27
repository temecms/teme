---
title: Rules for plugins and themes
type: concept
audience: both
summary: The rules every plugin and theme lives by, both the ones Teme enforces and the ones authors must keep because Teme cannot check them.
rests_on: [app/Domain/Plugins, app/Console/Commands/CheckTheme.php, packages/teme-contracts/schema, docs/architecture/plugins.md]
---

# Rules for plugins and themes

These are the base rules for every Teme plugin and theme. A future Teme plugin directory may add rules for being listed there; it will start from these.

The rules come in two parts. Teme enforces the first: an author can't break them, and the page says what happens if one tries. Teme can't check the second, so they rest on the author.

## What Teme enforces

### The manifest

- **Every package declares itself.** Teme reads `teme-plugin.json` or `teme-theme.json` before loading any code, and refuses a package whose manifest is wrong: a missing key, a `code` that isn't 2 to 40 lower-case letters, digits and hyphens, a `name` other than the Composer package's name, or a `requires.teme` the running Teme doesn't meet. See [The manifest](../concepts/manifest.md).
- **Hooks match capabilities.** A plugin lists the hooks it listens to, and each hook that needs a capability must have it. Teme refuses to enable a plugin whose `hooks` and `capabilities` don't match, and refuses a subscription to a hook whose capability the plugin wasn't granted. See [Hooks](../concepts/hooks.md).
- **Themes are checked too.** `php artisan teme:theme:check` lists every manifest error, refuses an inline script in a theme's views, and warns when its built CSS or JavaScript goes past its budget. A theme with errors can't be activated, and a broken one never takes the site down: core's own views draw the page instead. See [Your first theme](../start/first-theme.md).

### Code only against the contracts

- **No reaching into Teme.** Teme scans a plugin's code when it is enabled, without running it, and refuses a plugin that names a class in Teme's own App namespace, whose class doesn't implement `Teme\Contracts\Plugins\Plugin`, or that extends a class outside its own code. It also refuses a package that has Composer load files on every request. See [Operations](../concepts/operations.md).
- **Only the doors Teme offers.** A plugin's constructor may ask only for what Teme hands plugins, such as `SettingsReader`, `SecretReader` or `OutboundHttp`, and only for the doors its capabilities open. Anything else is refused at enable. A public route's handler is built the same way, by the same rules.

### Agreement

- **An administrator agrees first.** Capabilities and the hosts a plugin will reach are shown, by name, to the administrator who enables it, and nothing starts until they agree. An update that asks for nothing more starts at once; one that asks for more waits until someone agrees again. See [Trust](../../../architecture/plugins.md#trust).

### Data and writes

- **Tables carry the plugin's code.** Every table a plugin's migrations create starts with its `code`, hyphens turned to underscores, and is listed under `owns` in the manifest. A migration that creates or drops any other table is refused, and its whole run is undone.
- **Writes go through the doors.** A plugin changes content and media only through the doors its capabilities open, which run Teme's own Operations, with the same permission checks, revisions and hooks as the admin. See [Operations](../concepts/operations.md).
- **Secrets only through `SecretReader`.** A secret setting is encrypted, never cached and never sent to a browser. The reader a plugin is given answers only its own group, and the settings reader never answers a secret. See [Plugin settings and secrets](../plugins/settings.md).

### Outside the site

- **Outbound calls only to agreed hosts.** `OutboundHttp` speaks only `https` to port 443, only to hosts a person agreed to, the manifest's `hosts` or those typed into the settings its `hostsFrom` names, and only to public addresses: a host that resolves to a private or reserved address is refused, redirects are never followed, and each call, JSON or a form, is limited to 10 seconds and 1 MB each way. See [Hosts from your settings](../plugins/settings.md#hosts-from-your-settings).
- **Insertions are cleaned.** Markup a plugin places inside articles loses its inline scripts, its `on` attributes and any address that runs script, before it is drawn. See [Content inside articles](../plugins/insertions.md).
- **Public routes are fenced.** A plugin's public addresses live under its own `code`, or under `.well-known`, unless a condition narrows them, and never on an address core reserves or that another route could also answer. A POST must name how it is verified, a body over its limit (1 MB unless the plugin declares up to 10 MB) is refused before it is read, a handler runs for at most 30 seconds, and one that throws is recorded against its plugin. See [Public routes](../plugins/public-routes.md).

### Switching plugins off

- **Safe mode.** No plugin starts on the pages that let people recover: signing in, the Plugins page, Tools › Updates, the health check, and the console commands that build, migrate or take Teme down. A plugin can't opt out, so a broken one can always be disabled.
- **The server's switch.** The host can stop any plugin, or all of them, with `TEME_DISABLED_PLUGINS`, whatever the admin says, without writing anything.

## What authors must follow

### Security

- Escape everything a template prints: Blade's `{{ }}`, never `{!! !!}` for anything that came from people or other sites.
- Never build SQL from input. Use the query builder and its bindings.
- Never keep a password, token or key anywhere but a secret setting, read through `SecretReader`.
- Never log secrets or personal data.
- Validate every input a route or a job receives before using it.

### Privacy

- No tracking and no calls home without the administrator's agreement. Say so in the manifest's `description` and in the plugin's settings, and let them turn it off.
- Keep only the personal data the feature needs, and remove it when the plugin's data is removed.

### Naming

- The Composer name is `vendor/package`, and the manifest's `name` is the same.
- The plugin's `code` is the prefix of everything it adds to a shared space: its tables, its settings group, the routes it declares, its CSS classes and its JavaScript globals.
- Hook listeners and every other class live under the author's own PHP namespace, never Teme's.

### Honesty

- The description says what the plugin does.
- No hidden features, no obfuscated or minified PHP, and no code downloaded and run while the site is running.
- No ads or upsells in the admin, except on the plugin's own screens.

### Words

- Every word a person reads goes through `__()`, with the English sentence as its key, so a site in another language can translate it.
- A theme keeps its translations in its own `lang` folder, and Teme loads the active theme's; see [Languages in a theme](../themes/languages.md).
- A plugin keeps its translations in its package's `lang` folder, one JSON file per language, such as `lang/es.json`, in the same format as core's: `{"Hub sent the ping.": "El hub envió el ping."}`. Teme reads them while the plugin is enabled, in the reader's or the person's language as core chooses it. A plugin's file only adds sentences: Teme keeps a line only for a sentence the plugin's own code passes to `__()` as a plain string, that none of core's language files holds and that no plugin started before it claimed, and uses it only where core has no line of its own. The consent, password and refusal screens always use core's words. A sentence with no translation shows in English.
- The words people read from your manifest translate from the same files: its `label` and `description`, your settings' labels, descriptions, option labels and units, your Tools links, your actions, lists and their columns, and your list columns. Teme looks each up by its English text in your plugin's own `lang/<locale>.json`, never in core's or another plugin's, in the person's admin language, and shows the English text when your file has no line for it. What people typed into your settings is never translated.

### License

- Choose any license you like, and say which in the manifest's `license`.

### Themes

- A theme is looks only. A feature that would keep working after the site switches themes belongs in a plugin.
- One `h1` per page, for the page's own title.
- Accessible by default, as the default theme is: real landmarks and headings, visible focus, enough contrast, and links that say where they go.
- Offer every feature a plugin may also draw—share buttons, a table of contents, breadcrumbs, related posts, an author box—as an on/off setting of your own. Never try to detect which plugins are enabled; the site chooses what you draw.
