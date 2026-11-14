<p align="center"><img src="resources/brand/teme-icon.svg" width="96" height="96" alt="TemeCMS"></p>

<h1 align="center">TemeCMS</h1>

<p align="center"><em>Made to create.</em></p>

---

Teme (Ijaw for *create*) is an open-source content management system for publishers, built on Laravel and PostgreSQL.

It holds everything a publishing site needs in one place: the editor, media, people and permissions, publishing, the public website, themes, plugins and backups. Content is stored as structured documents, every item keeps its fields together in one row, and a site moves to a new domain by changing one setting.

## Status

Teme is in **alpha**. The latest release is `v0.7.0-alpha.1`; see the [changelog](CHANGELOG.md) for what each release added and [the roadmap](docs/roadmap.md) for what comes next. It is developed in the open: features arrive in small releases, and the interface is being redesigned now. Try it, but don't run a site you can't afford to lose on it yet.

## What it does

- **Write and publish:** a block editor, revisions and autosave, scheduling, previews, and review before publishing.
- **People and credits:** roles, invitations, two-factor sign-in, and bylines that can credit several people, with or without an account.
- **Organise:** categories and tags, pages under pages, menus, redirects, and addresses that follow a pattern you choose.
- **Media:** a library with image sizes made for you, alt text and captions.
- **Comments:** threads, moderation, spam checks and their emails.
- **Search and social:** titles, descriptions, sitemaps, feeds, structured data, and checks as you write.
- **Ready for AI and tools:** with one switch, `llms.txt` and a plain Markdown copy of every published item at its address ending `.md`.
- **A fast public site:** a page cache that clears itself when content changes.
- **A read API:** published content as JSON at `/api/v1`, for apps and other sites.
- **Themes and plugins:** themes for the look; plugins as Composer packages, each asking for what it needs, with hooks that fire after every change is saved.
- **Several languages:** every item carries a language, and translations are linked as versions of one item.
- **Import:** bring an existing WordPress site across, with every old address still answering.
- **Backups:** nightly, encrypted, checked and test-restored, to a folder, S3-compatible storage or an SFTP server.
- **Deploy:** one Docker image and a Compose recipe, ready for Dokploy, Coolify or a plain server.

## Guides

- [**The operators' guide**](docs/guides/operators/README.md): installing, configuring, HTTPS, updating, backing up and restoring, and moving an existing site onto Teme.
- [**The editors' guide**](docs/guides/editors/README.md): writing, publishing, media, comments and settings, in the words the admin uses.

## Developing Teme

You need PHP 8.4, Composer 2, Node 24 (see `.nvmrc`), and Docker for PostgreSQL 18 and Valkey.

```bash
docker compose up -d      # PostgreSQL, a second PostgreSQL for the tests, and Valkey
composer setup            # dependencies, .env, the key, the database and the front end
composer dev              # the app, the queue, the logs and Vite together
```

`composer check` is the gate: Pint, PHPStan, the type checks, the architecture tests, then the whole suite. `composer check:browser` runs the browser tests. Tests run against PostgreSQL.

Before changing anything, read [`AGENTS.md`](AGENTS.md): the rules every change keeps.

## Documentation

| Where | What |
|---|---|
| [`docs/superpowers/specs/2026-09-17-cms-design.md`](docs/superpowers/specs/2026-09-17-cms-design.md) | The architecture |
| [`docs/architecture/`](docs/architecture/) | How each part works: operations, content, addresses, plugins, themes, security and more |
| [`docs/reference/`](docs/reference/) | Generated from the code: settings, fields, cards, the theme view data and the export format |
| [`docs/roadmap.md`](docs/roadmap.md) | What is built and what comes next |
| [`CHANGELOG.md`](CHANGELOG.md) | What changed in each release |

## Security

Please report vulnerabilities privately, as [`SECURITY.md`](SECURITY.md) describes, never in a public issue.

## License

TemeCMS is open source under the [MIT license](LICENSE), © Krafty Sprouts Media LLC.
