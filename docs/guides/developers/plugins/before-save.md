---
title: Check an item before it is saved
type: how-to
audience: plugin
summary: How a plugin is asked before an item is saved or published, how it refuses a save with a reason, and what happens when its check fails or runs too long.
rests_on: [packages/teme-contracts/src/Content/BeforeSave.php, app/Domain/Contents/BeforeSaveCheck.php, app/Domain/Hooks/HookDispatcher.php]
---

# Check an item before it is saved

A plugin can look at an item just before Teme saves it, and refuse the save with one sentence the person will read. Use it for a rule that must hold before anything is kept: a required summary, a check for banned words, a check against another system.

Teme asks before it writes anything, so a refused save changes nothing: no new revision, no new address, no publish.

## Listen for BeforeSave

Listen for `Teme\Contracts\Content\BeforeSave`. Your listener receives the item as it would be saved:

- `itemId`: the item's ID, or null for a new item.
- `type`: its content type, such as `post`.
- `language`: its language, or null for the site's default.
- `title`: the title as it would be saved.
- `document`: the document as it would be saved, as Tiptap JSON.
- `fields`: its fields as they would be saved.
- `actorId`: the account ID of the person saving it, or null when none.

Listening needs the `content.read` capability, which the person sees when they enable your plugin.

To refuse the save, return a `Veto` with one sentence saying why. To let it through, return null.

```php
$hooks->on(BeforeSave::class, fn (BeforeSave $save): ?Veto => $save->type === 'post' && mb_strlen($save->title) < 10
    ? new Veto('Give the post a title of at least ten characters.') : null);
```

The first plugin to refuse stops the save, and its reason is shown on the screen the person saved from. Other plugins are not asked after a refusal.

## When you are asked

You are asked for a save of an item by a person, when they create a draft, save a draft, publish a revision or schedule a publish. You are not asked for:

- an autosave or a checkpoint of one,
- a suggestion from the AI assistant that a person used,
- an import run.

A plugin's own writes through its content writer are not asked either: they are a write the plugin made itself, and a check on them could call back into the plugin. A scheduled publish that runs at its time is not asked again, because the schedule was asked when it was made.

A bulk publish, or a publish through the management API, is asked once for each item, as each item is its own save.

Each check runs before the save's transaction opens. When one Operation runs inside another's transaction, your check runs inside that transaction, so anything you do there is rolled back with it.

## When your check fails or runs too long

Teme gives each check a short time. A check that fails, answers with something that is not a refusal, or takes longer than its time, is recorded as a fault for your plugin and passed over: the save goes ahead. Refusing a save is the only way a check stops one, so a slow or broken check never blocks someone's work by accident.

Keep the check fast. Read what you need from your own data, and do not call a slow outside service on every save.
