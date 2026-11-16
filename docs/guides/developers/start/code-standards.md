---
title: Code standards for plugins and themes
type: concept
audience: both
summary: What Teme requires of a plugin's or theme's code, what it only recommends, and where Teme's own coding standards are.
rests_on: [app/Domain/Plugins/CodeScan.php, app/Domain/Plugins/ManifestValidator.php, packages/teme-contracts/src, docs/architecture/coding-standards.md]
---

# Code standards for plugins and themes

Teme's own coding standards, in [docs/architecture/coding-standards.md](../../../architecture/coding-standards.md), are for the code in this repository. Your plugin or theme is your code. Teme asks a few things of it so that it can load it safely and keep it working across releases; everything else is your choice.

## What Teme requires

- **Code only against the contracts.** A plugin uses what `teme/contracts` offers: the hooks, the interfaces Teme hands its constructor, and the value classes they carry. It never names a class in Teme's own App namespace, never reaches for the container, and its plugin class extends nothing outside the plugin's own code. Teme scans a plugin's code when it is enabled and refuses one that breaks these rules; see [Operations](../concepts/operations.md). A theme uses only the view data and components in the [theme reference](../../../reference/theme.md).
- **Declare it in the manifest.** Capabilities, hooks, settings, jobs, regions and the Teme versions you accept are data in `teme-plugin.json` or `teme-theme.json`, checked before your code runs. See [The manifest](../concepts/manifest.md).
- **Write only through the doors.** A plugin changes the site only through what its capabilities open, such as `ContentWriter`, or through its own Operations. It never writes Teme's tables directly, and never writes files or code into the running site.
- **Words through `__()`.** Every word a reader or an editor sees goes through the translator, with the English sentence as its key, so a site in another language can show it in that language. Teme loads the active theme's own `lang` folder, but not a plugin's, so a plugin's words show in English. Teme can't check this one for you.

## What Teme recommends

None of these is checked. They are what Teme's own code does, and what makes a package easy for the next person, or agent, to pick up:

- `declare(strict_types=1);` at the top of every PHP file;
- Pint for formatting, PHPStan for static analysis, and Pest for tests, with a failing test written first (see [Testing a plugin or theme](../testing.md));
- a docblock of one to three sentences on every class, saying what it is for;
- `@since` tags only if your package publishes an API of its own for other packages to build on. For the `@since` tags on Teme's contracts, and what they mean for `requires.teme`, see [Hooks](../concepts/hooks.md#since-and-the-teme-you-require).

If you want your code to read like Teme's, follow its [coding standards](../../../architecture/coding-standards.md) too. Nothing requires it.
