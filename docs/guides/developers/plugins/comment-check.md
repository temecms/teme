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

The candidate also has `signals`, the hidden form fields a comment form carried. This release's comment form sends none, so `signals` is always empty here. The hidden field that fills it is not in this release; it comes later (issue 805).

## Fediverse replies

A reply that comes in from the fediverse, or an edit to one, is asked of the check the same way, before it is saved.

## How verdicts combine

The strictest verdict wins: spam beats pending, and pending beats approved. Core's moderator is asked after the plugin's verdict is recorded, so a comment the plugin approves can still be held or marked spam by core's lists.
