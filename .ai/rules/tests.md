---
paths:
  - tests/**
---

# Tests

- Tests run on **PostgreSQL**, never SQLite. SQLite hides every JSONB and index behaviour the design depends on.
- Test names read as sentences: `it('refuses a save based on a revision that is no longer current')`.
- An architecture test must be shown to fail before it is trusted: plant a violation, watch it go red, remove it.
- Write the failing test first and confirm it fails for the reason you expect, not a typo.
