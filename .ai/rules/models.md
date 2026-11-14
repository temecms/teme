---
paths:
  - app/Domain/**/Models/**
---

# Models

- Primary keys are ULIDs (`HasUlids`).
- `ContentRevision` sets `UPDATED_AT = null`. A revision is never edited in place — that would defeat having revisions.
- JSONB columns are cast to `array`. JSONB object keys inside `fields` are **immutable ULIDs, not field handles** — renaming a field must never orphan data (study A).
- **An empty PHP array encodes as `[]`, a JSON list.** A JSONB column that must hold an object — especially one with a `CHECK (jsonb_typeof(...) = 'object')` — needs an `Attribute` that writes `{}` for an empty array, as `SettingsGroupRecord::overrides()` does. The plain `array` cast breaks the check the moment the last key is removed (backlog B-033).
- Models never reach templates. Themes receive view models; a template bound to Eloquent breaks every theme when a relationship changes.
