---
paths:
  - database/migrations/**
---

# Migrations

## Never

- **Never create a key-value / EAV meta table.** One row per content item; typed values in a single JSONB column governed by the field registry. This is the most consequential rule in the project.
- Never `serialize()` into a column. JSONB.
- Never edit a migration that has already run anywhere. Add a new one.

## Traps

- **`CREATE INDEX CONCURRENTLY` cannot run inside a transaction**, and Laravel wraps PostgreSQL migrations in one. A migration using it must set `public $withinTransaction = false;` and contain nothing else.
- **Do not add a generated column to index a JSONB field.** PostgreSQL generated columns are `STORED`, so adding one rewrites the table under `ACCESS EXCLUSIVE`. Use an expression index built concurrently.
- Circular foreign keys (`contents` ⇄ `content_revisions`) are added in a separate, later migration once both tables exist.
- Schema changes are additive (expand/contract). Removing or renaming a column that running code reads is a two-release change.
