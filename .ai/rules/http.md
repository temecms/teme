---
paths:
  - app/Http/**
---

# HTTP layer

## Never touch a model

Controllers, form requests, middleware and resources call an **Operation** to change data and a **query class** to read it (idea I-098). They never import an Eloquent model, a query builder or the `DB` facade, and never call a model's write methods — not even through `$request->user()`, which a name-based test cannot see. `tests/Architecture/LayeringTest.php` and a static-analysis rule fail the build if they do (I-097, I-099; both land in slice 1.2).

The single exception is Laravel Fortify's own credential state, listed exactly in the static-analysis rule's allow-list (I-100). Do not widen that list; raise it with the maintainer.

The HTTP layer translates a request into an Operation input DTO, calls `execute()`, and translates the result or exception into a response. Nothing else.

## Why

`PublishRevision` must be the same code whether a button, a scheduled job, an API client or an AI agent triggered it. A controller doing its own Eloquent work is a second implementation of that behaviour, and the two will drift.
