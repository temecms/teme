---
paths:
  - packages/teme-contracts/**
---

# Public contracts

This package is the semver'd surface extension authors code against.

- **Imports nothing from `App\` and nothing from `Illuminate\`.** A framework type here means one Laravel upgrade breaks every extension. Enforced by architecture test.
- Every public interface, method and constant carries `@since <version>`, set once and never edited.
- `@deprecated` names the replacement **and** the removal version: `@deprecated 2.3.0 Use X::y(). Removed in 3.0.0.`
- A value class or a hook grows only at the end: a new property is a new LAST constructor parameter, with a default, so code that builds one keeps working; nothing is inserted, reordered or removed (I-214; the owner, 2026-11-11).
- Prefer composition. Never require an extension to extend a framework class — Winter's `PluginBase extends ServiceProvider` put the whole Laravel API into its public contract permanently (study C).
