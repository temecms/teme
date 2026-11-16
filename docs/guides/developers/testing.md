---
title: Testing a plugin or theme
type: how-to
audience: both
summary: How to test a plugin or theme with Pest and Teme’s fixture helpers.
rests_on: [tests/Feature/Guides/Developers, tests/Fixtures/Plugins, docs/guides/developers/examples, phpunit.xml, phpstan-plugins.neon, .github/workflows/plugins.yml]
---

# Testing a plugin or theme

Examples in this handbook are real packages under `docs/guides/developers/examples/`. Pest exercises them. Your plugin or theme should get the same treatment: a failing test first, then the code.

## Plugins

Point `teme.plugin_paths` at the plugin’s own folder (the one that holds `teme-plugin.json`). Set Teme’s version to one the manifest’s `requires.teme` accepts. Enable with `teme:plugins:enable` and `--agree` (the same command the handbook’s ExamplesTest runs), load plugins, then publish an item and assert your listener ran.

The handbook’s own check lives in `tests/Feature/Guides/Developers/ExamplesTest.php`. Fixture helpers under `tests/Fixtures/Plugins` show how discovery, consent, and loading work for the core suite.

Useful commands while you work:

```sh
php artisan teme:plugins:enable your/package --agree
php artisan test --compact tests/Feature/Guides/Developers/ExamplesTest.php
```

### A first-party plugin's own tests

A plugin kept in Teme's repository, under `plugins/<code>/`, keeps its tests in `plugins/<code>/tests`. They run in a test suite of their own, named Plugins, apart from Teme's, with its analysis at level 6 over `plugins/` alone:

```sh
vendor/bin/pest --testsuite=Plugins
vendor/bin/phpstan analyse -c phpstan-plugins.neon --no-progress
```

`composer check:plugins` runs both. On GitHub, the plugins workflow runs them only when a plugin, the contracts or the core code the plugin sockets stand on changes, and only when some plugin has a `tests` folder. Teme's own suite and `composer check` never run them.

## Themes

Point `teme.theme_paths` at the theme folder, flush the theme registry if you changed the config in a running test, then:

```sh
php artisan teme:theme:check your-theme-code
```

Activate the theme in the test, publish a post, and request the home address and the post’s address. Assert the templates you expect. The first-theme example follows that path in the same Examples test.

## Guides stay honest

Pages under `docs/guides/developers/` are checked against the code: front matter, links, commands, class and hook names, and long example blocks. When you change a command or rename a hook, update the page in the same branch.
