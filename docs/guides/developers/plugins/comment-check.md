---
title: A comment check
type: how-to
audience: plugin
summary: How a plugin judges each comment before it is saved, as a spam check does, with core's moderator still applying its own lists after the plugin's verdict.
rests_on: [app/Domain/Comments/Checks, packages/teme-contracts/src/Comments/HasCommentCheck.php, packages/teme-contracts/src/Comments/CommentCandidate.php, app/Http/Site/CommentController.php]
---

# A comment check

A spam plugin adds checks to core's comment moderation. Core asks the plugin that fills the `comment-check` slot for a verdict on each comment before it is saved. The plugin's verdict is one input. Core's own word lists, link limit and returning-commenter rule still apply afterwards.

## Declaring it

The plugin asks for `comments.check`, which the person agrees to at enable time, and lists the slot under `provides`:

```json
"capabilities": ["comments.check"],
"provides": ["comment-check"]
```

The plugin's class implements `HasCommentCheck`, whose `check()` takes a `CommentCandidate` and returns a `ModerationVerdict`, or null for no opinion.

## What the check gets

The text, the name, the email address, the address and the IP address of the commenter, and whether they are returning. A check that answers late, or fails, gives no opinion and the comment goes on to core's moderator.

The candidate also has `signals`, the values of the hidden field the plugin declares (below). They are empty when the plugin declares none.

## A hidden field

The plugin may declare one hidden field in its manifest, under `contributes`:

```json
"commentField": { "name": "hp-stamp", "make": "static", "value": "lively" }
```

`name` is the field's name, 3 to 40 lowercase letters, digits or hyphens. It may not be a name core's comment form uses, such as `text`, `email` or `subject`. `make` says how its value is made:

- `static`: a fixed `value` of up to 200 characters, sent with every comment form.
- `time`: the browser's time in milliseconds, filled in by core's comments script the first time a reader touches the form. The server never stamps it, so a cached page still gives a fresh time. The clock is the reader's browser's, not the server's.
- `trap`: an off-screen field with no value, which no person sees and a bot tends to fill, as core's own honeypot works. Core's honeypot still runs first; a trap filled in reaches the check and nothing more.

The field is drawn only for the plugin the administrator chose for the comment-check slot, and only where core draws its comment form, including a theme's own form that includes the comment form's fields. A field needs the `comment-check` slot in `provides` and `comments.check`.

Its value reaches the check in `signals`, keyed by the field's name. A form that arrives without the field, such as one a bot stripped, gives the check a signal `missing-field` with the field's name instead. A check should treat that as a signal of its own, not as a value.

## What the plugin may keep

A check is given the commenter's words, name, email address, website, IP address and browser so it can judge spam, and nothing else. Keep nothing it sends you beyond the answer. If your check keeps a copy, a spam model's training set or a log, your site's privacy policy must say so, and the copy must be deleted when you delete the site's record of the comment.

If your check keeps anything, hear about erasure the way any plugin does: contribute a personal data holder (`Teme\Contracts\Privacy\CollectPersonalDataHolders`, whose `PersonalDataHolder` is asked to export and to erase what you keep for a person). Keeping nothing is still the simplest way. The site's own privacy policy must name the service that receives comments, whether or not it keeps them.

## Fediverse replies

A reply that comes in from the fediverse, or an edit to one, is asked of the check the same way, before it is saved.

## How verdicts combine

The strictest verdict wins: spam beats pending, and pending beats approved. Core's moderator is asked after the plugin's verdict is recorded, so a comment the plugin approves can still be held or marked spam by core's lists.
