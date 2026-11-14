# External references

The documentation Teme is built against, at the versions we use. Versions verified 18 September 2026.

**How agents should look things up**, in order:

1. **Boost's `search-docs` MCP tool** for Laravel and its ecosystem. It returns documentation matched to the exact package versions in `composer.lock`, which the web cannot guarantee.
2. **Context7** for libraries Boost does not cover — Tiptap, React, Tailwind.
3. The links below.

Never rely on memory for an API signature. Training data lags releases, and several packages here moved a major version recently.

## Backend

| Technology | Version | Documentation |
|---|---|---|
| Laravel | 13.x | https://laravel.com/docs/13.x |
| Laravel Boost | 2.x | https://laravel.com/docs/13.x/boost |
| PHP | 8.4+ | https://www.php.net/manual/en/ |
| PostgreSQL | 18 | https://www.postgresql.org/docs/18/ |
| Pest | 5.x | https://pestphp.com/docs |
| spatie/laravel-permission | 8.x | https://spatie.be/docs/laravel-permission |
| Larastan / PHPStan | 3.x | https://github.com/larastan/larastan · https://phpstan.org/user-guide |
| Laravel Pint | — | https://laravel.com/docs/13.x/pint |
| Laravel Herd | — | https://herd.laravel.com/docs |
| tiptap-php (server-side rendering of Tiptap JSON) | 2.x | https://github.com/ueberdosis/tiptap-php |

## Admin and editor

| Technology | Version | Documentation |
|---|---|---|
| Inertia (Laravel adapter and React) | 3.x | https://inertiajs.com |
| React | 19 | https://react.dev |
| TypeScript | 7 | https://www.typescriptlang.org/docs/ |
| Tailwind CSS | 4 | https://tailwindcss.com/docs |
| Tiptap | 3.x | https://tiptap.dev/docs |
