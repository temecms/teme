---
title: What visitors send from a public form
type: how-to
audience: plugin
summary: How a plugin with content.submissions keeps what a visitor sends from its public form as an item waiting for review, written as the site itself, and how Teme limits how many it takes.
rests_on: [packages/teme-contracts/src/Content/Submissions.php, packages/teme-contracts/src/Content/Submission.php, app/Domain/Plugins/Doors/PluginSubmissions.php, app/Domain/Contents/Operations/ReceiveSubmission.php, docs/architecture/plugins.md]
---

# What visitors send from a public form

A contact form, a tip line or a "suggest a story" page gets things from people who aren't signed in. `ContentWriter` can't keep those, because every write it makes is for a named person. The `Submissions` door can: it keeps each one as an item waiting for review, which a person then reads in the In review list and publishes, returns or trashes as any other item.

The contracts are in `teme/contracts` from the next release.

## Ask for the door

Add `content.submissions` to the manifest's `capabilities`, and ask for `Teme\Contracts\Content\Submissions` in your plugin's constructor. People enabling the plugin read that it will "Keep what visitors send from its public forms, as items waiting for someone to review them". Usually the form itself is a [public route](public-routes.md), so the plugin asks for `routes.public` too.

## Keep a submission

```php
$body = $this->html->convert('<p>'.htmlspecialchars($message).'</p>');
$item = $this->submissions->submit(new Submission(type: 'post', title: $subject, body: $body, from: $name.', '.$email));
```

A `Submission` has a type, a title and a body, the same Tiptap document `ContentWriter` takes; `HtmlToDocument` turns HTML into one. It may carry fields by handle and terms by taxonomy, as slugs or names of terms the site already has; a submission never makes a term. `from` is who sent it, as the visitor gave it, at most 255 characters, kept with the item for the person who reviews it.

Teme keeps it as the site itself, not as a person:

- the item has no author, and its status is `review`, with a review waiting that no person asked for;
- it is marked as a submission: the `ItemRef` answered, and every hook's `ItemRef` for it, has `submission` true;
- its revision says your plugin wrote it, as `ContentWriter`'s do;
- this door never publishes it, and has no way to. A person does that, through the editor.

## How many it takes

Teme counts every try, kept or refused, and takes at most ten an hour from one visitor address and two hundred an hour through one plugin. Past either, `submit()` throws `WriteRefused` and keeps nothing. Show the visitor its message: it says to try again later.

## When a submission is refused

As with `ContentWriter`, every refusal is a `WriteRefused` with one plain sentence, and nothing is written: too many this hour, a type the site doesn't list or that holds one item only, a term the site doesn't have, a field the type doesn't have, or a body or value that fails the type's checks. A plugin that isn't enabled, or wasn't granted `content.submissions`, is refused too.

## Test it

With the testing kit, post to your public route with `fetch()` and check the `ItemRef` your route answered, or what it shows the visitor. See [Testing a plugin or theme](../testing.md).

Next: [Public routes](public-routes.md), [Plugins writing content](content-write.md), and the generated [contracts reference](../../../reference/contracts.md).
