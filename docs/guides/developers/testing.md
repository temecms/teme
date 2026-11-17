---
title: Testing a plugin or theme
type: how-to
audience: both
summary: How to test a plugin or theme with Pest and Teme's testing kit, against a real Teme site, through the same checks the admin makes.
rests_on: [packages/teme-testing, phpunit.xml, phpstan-plugins.neon, .github/workflows/plugins.yml, tests/Feature/Testing, docs/guides/developers/examples/plugins/first-plugin/tests/FirstPluginTest.php, tests/Feature/Guides/Developers/ExamplesTest.php]
---

# Testing a plugin or theme

Teme's testing kit, `teme/testing`, lets you test a plugin or theme the way a site uses it: enabled by an administrator, hearing content being published, running its jobs, answering its addresses. Every step goes through the same Operations and doors as the admin and a running site, so a test meets each check a person would, and a test that passes means the plugin works on a real site.

## Where tests live and how they run

Keep your tests in your package's own `tests` folder, and run them from your development site, the Teme checkout described in [Set up a development site](start/development-site.md):

```sh
php artisan test ../my-plugin/tests
```

The kit boots that checkout for each test, with a fresh PostgreSQL database: start it with `docker compose up -d` in the checkout, as for the site itself. Nothing a test does is kept.

## The first line of every test file

Every test file starts by naming the kit's test case:

```php
uses(Teme\Testing\TemeTestCase::class);
```

If you keep a base test case of your own, use the `InteractsWithTeme` trait in it instead, with Laravel's RefreshDatabase trait; Laravel readies the kit before each test.

## What the kit gives a test

| Method | What it does |
|---|---|
| `person('editor')` | A new person with that role: administrator, editor, author or contributor. The first one made sets the site up. |
| `plugin($folderOrName)` | Your plugin, from its folder or its Composer name. The test fails, saying why, if Teme can't use it. |
| `->enable($person)` | Enables it as that person, a new administrator when you name none, agreeing to what its manifest asks for, and starts it. A refusal fails the test with its words. |
| `->disable($person)` | Disables it. |
| `->changeSettings([...])` | Saves its settings, secrets included, as the person who enabled it; `changeSettings('general', [...])` saves another group. |
| `->runJob('key', [...])` | Queues one of its jobs and answers the run: its `state` and `message`. |
| `->runSchedules($moment)` | One pass of the schedules at that moment, now when you give none; answers how many jobs it queued. |
| `draft('Title')`, `publish('Title')` | A draft, or a published item, written as an editor; each answers an `ItemRef`, as hooks carry it. |
| `unpublish($item)`, `trash($item)` | Takes an item back to a draft, or to the trash. |
| `outbound()` | The fake behind your plugin's outbound door, described below. |
| `fetch('/path/')` | The site's answer to one request for that exact path, with the method, headers and body you give. |
| `theme($folderOrCode)` | Your theme. `->check()` answers the problems `teme:theme:check` finds, empty when it passes; `->activate()` makes it the site's theme. |

Every method that acts takes the person to act as, last. Name a person to test what someone without the permission meets: an editor who enables a plugin is refused, as in the admin.

## A test, start to finish

The handbook's first plugin carries its own test:

```php
// docs/guides/developers/examples/plugins/first-plugin/tests/FirstPluginTest.php
beforeEach(function (): void {
    FirstPlugin::$heard = [];
});

it('hears an item being published', function (): void {
    $this->plugin(__DIR__.'/..')->enable();

    $item = $this->publish('Hello from a test');

    expect(FirstPlugin::$heard)->toHaveCount(1)
        ->and(FirstPlugin::$heard[0])->toContain($item->id);
});
```

What the plugin heard is kept in a static, which outlives a test, so `beforeEach` empties it: otherwise another test that published something first would be counted too. `plugin(__DIR__.'/..')` finds the plugin in the folder above the test, `enable()` enables and starts it as a new administrator, and `publish()` publishes an item, which the plugin hears.

## The outbound door

A test sends nothing out of the machine: any request a plugin sends fails the test, unless the test calls `outbound()` first. From then on every request is answered, 200 with an empty body unless you say otherwise, and recorded:

- `answer('api.example.com', 201, ['ok' => true])` sets what a host answers; an array is sent as JSON.
- `answerInTurn('api.example.com', [429, 200])` makes the next requests answer those statuses, one each.
- `sent()` lists what was sent, each with its `method`, `url`, `host`, `headers` and `body`, and `json()` and `form()` to read the body; `sent('api.example.com')` lists one host's.
- `assertSent(fn ($request) => ...)` and `assertNothingSent()` check them.

The fake sits behind the door, so the door's own checks still run: a host your manifest doesn't list is refused, as on a real site.

## Jobs

The test's queue is `sync`, so `runJob()` runs the job at once and answers how it ended: `finished`, or `failed` with the first line of what it threw, or `cancelled`. A failed run is not tried again in the same test: call `runJob()` again for another try. A job runs as the person who enabled the plugin, as on a site; `runSchedules()` queues what is due as the schedules runner would, and answers how many it queued.

A schedule is due at the first pass after the plugin is enabled, then each time its interval has passed since its last run was queued, as [Jobs and schedules](plugins/jobs.md#schedules) describes. To fire one twice, give the second pass a later moment. For an `hour` schedule, the first pass queues one run, a second pass at the same moment queues none, and a pass an hour later queues one again:

```php
expect($plugin->runSchedules())->toBe(1)->and($plugin->runSchedules())->toBe(0);
expect($plugin->runSchedules(now()->addHour()))->toBe(1);
```

A plugin enabled without an administrator, from the command line without `--as`, never runs its schedules, in a test as on a site.

### A first-party plugin's own tests

A plugin kept in Teme's repository, under `plugins/<code>/`, keeps its tests in `plugins/<code>/tests`. They run in a test suite of their own, named Plugins, apart from Teme's, with its analysis at level 6 over `plugins/` alone:

```sh
vendor/bin/pest --testsuite=Plugins
vendor/bin/phpstan analyse -c phpstan-plugins.neon --no-progress
```

`composer check:plugins` runs both. On GitHub, the plugins workflow runs them only when a plugin, the contracts or the core code the plugin sockets stand on changes, and only when some plugin has a `tests` folder. Teme's own suite and `composer check` never run them.

## Themes

`theme()` takes the folder that holds `teme-theme.json`, or the code of a theme Teme already knows. Check it, activate it, publish an item and `fetch()` the front page and the item's address to see your templates draw them.

## The Teme version

The kit sets the site to the version `TEME_VERSION` names when it names a release, and otherwise to the newest version the checkout's contracts offer, which the [contracts reference](../../reference/contracts.md) prints in its first lines. State that version in your manifest's `requires.teme`.

## What the kit promises

The kit is documented and tested, and the handbook's own examples are tested with it. Every change to it is written in the changelog.

## Guides stay honest

Pages under `docs/guides/developers/` are checked against the code: front matter, links, commands, class and hook names, and long example blocks. When you change a command or rename a hook, update the page in the same branch.
