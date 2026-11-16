# Plan: plugin sockets, round 3

**Four parts, four branches, built side by side where the work is separate:** A `sockets3/plugin-ai` (plugins use the site's one AI connection, counted, limited and shown by plugin), B `sockets3/editor-panels` (a plugin's panel in the editor, drawn by core from data, with actions that queue the plugin's work and suggestions the person applies), C `sockets3/content-doors` (a content reader, slug, translations and bylines through the write door, the person a job acts for, bigger answers in long jobs, a `person` setting), D `sockets3/hooks-notices` (hooks a plugin declares for other plugins, plugin dependencies, and notices from plugins). Proposal: `docs/proposals/plugin-sockets-3.md` (its Settled section is binding). Evidence: the Writura port's `FINDINGS.md` (W-01 to W-16, F-03, F-10, F-11) and `FEATURES.md` (2.2, 4.5, 4.11 to 4.14, 5.5 to 5.10, 6.3, 6.4, 7), issue #121 (plugins use the shared AI connection: the full list of settings).

**When each part starts.**

| Part | Branches from | Waits for |
|---|---|---|
| A `sockets3/plugin-ai` | `origin/main` | nothing from rounds 1 and 2. A-T6 (the screens) waits for the maintainers' approval of the drawings. |
| B `sockets3/editor-panels` | `origin/main` | round 1's `sockets/plugin-admin` (B4 `QueueOptions`) and round 2's `sockets2/plugin-area` (A1 plugin permissions, A7 `AreaReads` budgets) merged. B-T5 (the screens) waits for the drawings' approval. |
| C `sockets3/content-doors` | `origin/main` | nothing for C-T1 to C-T6. C-T7 waits for round 2's `sockets2/plugin-area` merged (it grows `AreaContext`); C-T5's one line in `WritePluginSettings` waits for round 1's `sockets/plugin-admin` (if it isn't merged, skip that line and record it). |
| D `sockets3/hooks-notices` | `origin/main` | nothing for D-T1 to D-T4. D-T5 (the `plugin permission` audience and the area link) waits for round 2's `sockets2/plugin-area` merged. D-T6 (Account › Notifications' plugin mark) waits for the drawing's approval. |

**Bound by:** I-3396 (a wall is a missing socket, fixed in core), I-3434 and issue #121 (one AI connection that plugins share; spending shown by plugin; AI switchable per plugin), I-3467 (plugin screens follow the iPhone model: declared data, drawn by core, no plugin JavaScript), I-3418 (a plugin adds; it never replaces core's), I-234 and I-200 (plugin panels open from the editor's bar into the rail's one pane; declared entries core draws, with a budget), I-3413 (the focus keyphrase is the SEO plugin's, not core's), I-3353 (a plugin gets only what its capabilities open; faults recorded under the plugin), I-3364 (notifications are a core socket), I-392 and the maintainers' rule for doors that write (a plugin acts only for the person who started the work; scheduled work runs as the administrator who enabled it; never with the operator's elevation), I-316 to I-321 (core's AI rules: fenced inputs, figures recorded never words, budgets), I-214 and `.ai/rules/contracts.md`, rules 1, 4, 5, 6, 7 and 9, I-087 (no new singleton), I-569 (lighter plan), I-3439 (American English), I-3373 (every word through `__()`), I-797, I-3317.

This plan is lighter (I-569): decisions, names, JSON shapes, contracts, permissions, tests and the delicate code are written out; every other class is described by its file, its job and its public signature, and is the builder's to write in the style of its siblings.

## What exists (read on `public/main` at `df2f85db`, 2026-11-16)

- **Core AI.** `App\Domain\Ai`: providers through laravel/ai 1.3 (`LibraryProvider`, Anthropic, OpenAI, DeepSeek, OpenRouter), keys in the `ai` group or `TEME_AI_*_KEY`. `AiTasks` (container tag `teme.ai.tasks`) of `AiTask`s, each with a pinned prompt hash (`AiPrompt::hashOf`, `FENCE_RULE`, `messages()` fencing each input in `<input name>`), a subject (`content` or `media`, by ULID) and an applying Operation. `ModelChoices` stores `{task}_model` and `{task}_fallback` (`provider:model`) in the `ai` group; empty means the task is off. `AiAvailability` (TEME_AI switch, ready provider, model with the task's `AiCapability`s, `ai.use`). `AiRequest` caps the timeout at 55 s (`MAX_TIMEOUT`, under the worker's 60) and output at 64,000 tokens. `AnswerAiRequest` asks the model and its fallback once within one 55 s deadline, then `RecordAiActivity` (Operation; acts for the person who asked; writes `ai_activity` and adds to `ai_monthly_spend` in one transaction). `UsageLimits`: requests an hour per person (default 60) and a soft monthly budget. `ai_activity` checks `subject_type IN ('content','media')`, a non-null ULID `subject_id`, outcomes `suggested|applied|discarded|failed|refused|stale`. Tools › AI activity (`ai-activity.view`) lists rows with filters and a month summary. `settings/ai.tsx` draws the sections; v10's `settings-ai.html` already shows a plugin's model row marked with its plugin.
- **Plugin doors.** `PluginDoors::for(name, code, capabilities, hosts, httpSeconds)` and `PluginServices::OFFERED`: `SettingsReader`, `SecretReader`, `ContentWriter`, `MediaWriter`, `OutboundHttp`, `Jobs`, `InsertionsChanged`, `FediverseFollowers`, `FediverseSender`. `Notices` and `SiteSearch` exist in the contracts but are not offered, so a plugin asking for them is refused at enable (F-03).
- **Outbound.** `PluginHttp`: 10 s (300 inside a `long` job), 1 MB each way (`MOST_BYTES`), no redirects, no streaming.
- **Jobs.** `RunPluginJob`: worker timeout 75 s, 1,800 s for a `long` job. `PluginJobs` queues as the signed-in person, the job's own actor, or the operator. `JobContext` has `payload()`, `attempt()`, `progress()`; it has no way to say whom the run acts for. `ActingPerson::require()` accepts only the job's person, else the signed-in person. Tracked jobs a person started already show in the header's jobs button (`MyJobsController`, `TrackedJobList::startedBy`).
- **Content write.** `PluginContentWriter` creates through `CreateDraft` (no slug, no credits, no translation link), changes through `SaveDraft`, publishes, schedules. Core already has the Operations the door lacks: `UpdateContentDetails` (slug, author; slug rule `^[\p{Ll}\p{Lo}\p{N}]+(?:-[\p{Ll}\p{Lo}\p{N}]+)*$`, max 200, `Slugs::isAvailable`), `SetBylines` (up to 20 credits; crediting another account needs the type's `edit.any`), `LinkTranslations` (refuses two in one language, different types, trashed items). `NewItem` and `ItemChanges` are `@since 0.9.0`.
- **Content read.** Hooks carry `ItemRef(id, type, title, status, url, language)` (round 1 C adds `indexable`). `ContentSaved(item, revisionId, created, actorId)` carries no body, fields or terms. No door lists items.
- **Hooks.** Every hook is a class in `teme/contracts` with `#[HookContract(capability, failure)]` and one verb (`Notification`, `Transform`, `Collect`, `Gate`, `Veto`, `Resolve`). The manifest's `hooks` lists short names (`^[A-Z][A-Za-z]+$`). `HookDispatcher::subscriber(owner, capabilities, declared)`; `PendingNotifications` holds notifications until the outermost commit. `requires` holds only `teme` (checked with `Composer\Semver\Semver`, already a dependency).
- **Notices.** `Teme\Contracts\Notices\{Notices, NoticeKind, NoticeMessage, NoticeRegistrar}` (`@since 0.5.0`, a preview). `NoticeRegistry` (key `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`, no key the prefix of another, channels `mail` and `inbox`), `NoticeRules` (offered, sends, labelFor), `NoticeSender` (after commit; an action is a route name checked with `Route::has`). Account › Notifications lists `registry->listed()`.
- **Editor.** The bar's tools (`components/editor/tools/editor-tools.ts`, `pins.ts`: `MAX_PINS = 3`; `PINNABLE_TOOLS` `assistant`, `share`), the preference `editor.pinned_tools` (`BuiltInPreferences`, `allowed` closure, `maxItems: 3`), the rail's one pane (`editor-rail.tsx`: "later a plugin's own (I-234)"), `PluginMark`, the assistant's proposal and word diff (`components/ai/proposal.tsx`, `word-diff.ts`). v10's `editor.html` draws "Tools from plugins" and a plugin's pane (Link suggestions, Postlinkly) with its plugin mark.
- **Plugin codes** match `^[a-z0-9][a-z0-9-]{1,39}$` (a code may start with a digit or hold hyphens, which neither `AiKeys::PATTERN` nor notice keys accept as they stand).

## V, the release in every new `@since` (all parts)

As rounds 1 and 2: in Task 0 run `git tag -l 'v0.9.0*'`. **None:** `V = 0.9.0`. **Any:** `V = 0.10.0`. Written once, never edited. Growing an interface marked `@since 0.9.0` that only core implements (`ContentWriter`, `JobContext`) is allowed only while V is `0.9.0`; otherwise follow the same design through the new interface named beside each decision and record a `B-?? (draft)`. Value classes (`NewItem`, `ItemChanges`, `AreaContext`) grow only at the end, with defaults, whatever V is.

## Keys made from a plugin's code

One helper, `App\Domain\Plugins\PluginKeys`, owned by A and created first by whichever part reaches it first (identical content; the join keeps one):

```php
/** A plugin code as a lower-case word that starts with a letter: "plugin_" and the code with hyphens as underscores. */
public static function stem(string $code): string
{
    return 'plugin_'.str_replace('-', '_', $code);       // "9-writer" → "plugin_9_writer"
}
```

A uses it for the `ai` group's setting keys, D for notice kinds. Nothing core declares starts with `plugin_`.

## Shared files (additions only; never edit or remove another part's or an earlier round's lines)

| File | A adds | B adds | C adds | D adds |
|---|---|---|---|---|
| `packages/teme-contracts/src/Plugins/Capability.php` | case `Ai = 'ai'` | none | none | case `NoticesSend = 'notices.send'` |
| `packages/teme-contracts/schema/teme-plugin.schema.json` | `contributes.aiTasks` | `contributes.editorPanels` | settings type `person` | `contributes.hooks`, `contributes.notices`, `requires.plugins`, the wider `hooks` item pattern |
| `app/Domain/Plugins/Contributions/ContributionsValidator.php`, `Contributions.php` | `aiTasks` | `editorPanels` | `person` settings | `hooks`, `notices` |
| `app/Domain/Plugins/ManifestValidator.php` | none | none | none | `requires.plugins`, `hooks` names |
| `app/Domain/Plugins/Doors/PluginDoors.php`, `Seams/PluginServices.php` (`OFFERED`, and `for()`'s last parameter) | `SiteAi`; last param `bool $long = false` | none | `ContentReader`; answer size in long jobs (C5) | `PluginHookSender`, `Notices` |
| `app/Domain/Plugins/Consent.php`, `PluginWords.php`, `app/Console/Commands/EnablePluginCommand.php`, `resources/js/pages/plugins/index.tsx` | AI task lines | editor panel lines | none | notice kinds, required plugins, provided hooks |
| `app/Domain/Plugins/Operations/EnablePlugin.php`, `DisablePlugin.php` | none | none | none | dependency checks (D2) |
| `app/Domain/Plugins/Queries/PluginRow.php`, `PluginList.php` | last param `?array $ai = null` | none | none | last param `list<string> $requiredBy = []` |
| `app/Domain/Plugins/PluginKeys.php` | the helper | none | none | the same helper |
| `tests/Unit/Contracts/PluginContractsTest.php` | dataset rows | dataset rows | dataset rows | dataset rows |

Where two parts add a last parameter to one class, every caller passes it by name and the maintainer orders them at the join. **Do not regenerate** `docs/reference/plugin-manifest.md`, `docs/reference/hooks.md` or `docs/reference/fields.md`; the join does, once.

---

## Part A: plugins use the site's AI connection (`sockets3/plugin-ai`)

Walls W-04, W-01 and W-02 for AI work; FEATURES 2.2, 2.8, 5.4, 7 (cost display).

### Owns

- **New:** `packages/teme-contracts/src/Ai/` (`SiteAi`, `AiAsk`, `AiAnswer`, `AiStanding`, `AiRefused`); `app/Domain/Ai/Plugins/**` (`PluginAiTasks`, `DeclaredAiTask`, `PluginAiSection` (an `AiSettingsSection`), `PluginAiStanding`, `PluginAiSpend`, `PluginSiteAi` (the door)); `app/Domain/Ai/ModelCall.php` (the model-and-fallback call, moved out of `AnswerAiRequest`); `app/Domain/Plugins/PluginKeys.php`; migrations `add_plugin_to_ai_activity` and `create_ai_plugin_monthly_spend_table`; the fixture plugin `tests/Fixtures/plugins/scribe/` (two AI tasks, a long job that asks); tests `tests/Feature/Ai/Plugins/**`, `tests/Browser/Ai/PluginAiSettingsTest.php`; `docs/guides/developers/plugins/ai.md`.
- **Changed:** `app/Domain/Ai/AiRequest.php` (last param `bool $long = false`), `AiOutcome.php` (case `Answered`), `AiSubjectType.php` (case `None`), `Asking/Jobs/AnswerAiRequest.php` (uses `ModelCall`), `ModelChoices.php` (`forStem()`), `Operations/RecordAiActivity.php` and its input (last params `?string $plugin = null`; nullable subject), `Models/AiActivity.php`, `Queries/AiActivityFilters.php`, `AiActivityList.php`, `AiMonthSummary.php` (by plugin), `app/Http/Admin/Tools/AiActivityController.php`, `app/Http/Admin/Settings/AiSettingsController.php` (props), `resources/js/pages/settings/ai.tsx`, `pages/tools/ai-activity.tsx`, `types/ai-settings.ts`; the shared files above.
- **Not owned:** B, C, D; the editor's assistant; core's tasks.

### Decisions

**A1. Declared tasks.** Capability `ai` (`Capability::Ai`). Manifest `contributes.aiTasks`, at most 8:

```json
"aiTasks": [
  { "id": "article", "label": "Write an article", "description": "Writes a full article from a researched brief.",
    "needs": ["text"], "sends": "brief", "maxOutputTokens": 32000 },
  { "id": "titles", "label": "Suggest titles", "needs": ["text", "structured-output"], "sends": "draft", "maxOutputTokens": 2000 }
]
```

- `id` matches `AiKeys::PATTERN` and is at most 24 characters; `label` 1 to 40; `description` up to 160; `needs` a non-empty subset of `text`, `images-in`, `structured-output` (core's `AiCapability` values that have models today); `sends` one of `brief` (only what the plugin writes itself), `draft` (an item's unpublished text), `published` (published text only), shown in the consent and on Settings › AI; `maxOutputTokens` 1 to 64,000.
- Refused without `ai`: `It declares AI tasks, but the plugin doesn’t ask for ai.`
- **Consent** (`PluginWords`, under `ai`): `Use your site’s AI connection (Settings › AI) for: :tasks. What it sends goes to the AI service chosen there, and it spends your site’s AI budget.`, then each task as `:label: sends :what`. An update that adds a task, or widens a task's `sends` or `needs`, waits for consent (`Consent::covers()` false).

**A2. The door.** Offered to a plugin granted `ai` that declares at least one task. In `Teme\Contracts\Ai`, `@since V`, no framework type:

```php
/** Asks the site's AI connection for one of the calling plugin's declared tasks, for a named person. @since V */
interface SiteAi
{
    /**
     * Whether this task can be asked now, and this plugin's AI spending this month.
     *
     * @since V
     */
    public function standing(string $task): AiStanding;

    /**
     * Sends one request through the model chosen for this task in Settings › AI, its fallback once if
     * the first can't be reached, and records it under this plugin for $actingPersonId.
     *
     * @throws AiRefused  when it may not be asked, or the model fails; the reason says which
     *
     * @since V
     */
    public function ask(AiAsk $ask, string $actingPersonId): AiAnswer;
}

/** One request: the plugin's instructions, its named inputs (each fenced by core), an optional JSON schema. @since V */
final readonly class AiAsk
{
    /**
     * @param  string  $task  a task id the plugin declares
     * @param  string  $instructions  the system message, at most 100,000 characters
     * @param  array<string, string>  $inputs  by lower-case name with underscores; each sent inside its own <input> element, never as instructions
     * @param  array<string, mixed>|null  $schema  a JSON schema describing an object, for a task that needs structured-output
     * @param  int  $maxOutputTokens  1 to the task's declared maximum
     * @param  int  $timeoutSeconds  1 to 55, or up to 600 inside a long job
     * @param  string|null  $itemId  the item this request is about, recorded with it
     *
     * @throws \InvalidArgumentException  for a value outside these bounds
     *
     * @since V
     */
    public function __construct(
        public string $task, public string $instructions, public array $inputs,
        public ?array $schema = null, public int $maxOutputTokens = 4096, public int $timeoutSeconds = 55,
        public ?string $itemId = null,
    ) {}
}

/** The answer: text, or data when a schema was sent; the model; the tokens; the cost when known. @since V */
final readonly class AiAnswer
{
    /** @param array<string, mixed>|null $data  @since V */
    public function __construct(
        public string $model, public ?string $text, public ?array $data,
        public int $inputTokens, public int $outputTokens, public ?int $costMicrodollars, public string $requestId,
    ) {}
}

/** Whether a task can be asked now, why not in plain words, and this plugin's month. @since V */
final readonly class AiStanding
{
    /** Reasons, one of: ready, ai-off, plugin-off, no-model, model-lacks, site-budget, plugin-budget, hourly-limit. @since V */
    public function __construct(
        public bool $available, public string $reason, public string $sentence,
        public int $monthCostMicrodollars, public int $monthRequests, public ?int $monthBudgetMicrodollars,
    ) {}
}

/** Refused before sending (reason as AiStanding's, or too-long), or failed after (unavailable, rate-limited, invalid-output, timeout, refused). @since V */
final class AiRefused extends \RuntimeException
{
    /** @since V */
    public function __construct(public readonly string $reason, string $sentence) { parent::__construct($sentence); }
}
```

**A3. What `ask()` checks, in order** (the first that fails throws `AiRefused`; nothing is sent and nothing is recorded):
1. `ActingPerson::require($actingPersonId)`: only the person the work acts for; never the operator (its sentence becomes the refusal's).
2. The task is declared by this plugin; `$ask->maxOutputTokens` within its declaration; a `$schema` only for a task that needs `structured-output`; `$timeoutSeconds` over 55 only when the door was built for a long job's run (`PluginDoors::for(..., long: true)`, as `httpSeconds` is today), else `too-long`: `A request longer than 55 seconds runs only inside a long job.`
3. `PluginAiStanding::of(code, task)`: AI on (`TEME_AI`); AI on for this plugin (A5); a model chosen for the task (A4), its provider ready, able to do all of `needs`; the site's budget not reached (`AiSpend::budgetReached()`); the plugin's budget not reached (A5); the plugin's requests in the last hour under its hourly limit (A5). Budgets and the hourly limit are soft, as the site's: requests already running may pass them.

Then `ModelCall` asks the model, and the fallback once (as `AnswerAiRequest` does today, within `$timeoutSeconds`), and `RecordAiActivity` records it under the plugin with the person as actor: outcome `answered`, or `failed`/`refused` with the failure's type and any billed tokens (I-1322). The answer's text or data is returned; it is never stored by core (I-318).

**A4. Models per task.** Each enabled plugin's declared tasks appear on Settings › AI in a section **Plugins** (`PluginAiSection`, order 250, after Models): per plugin, its tasks as rows `:task: model` and `:task: fallback` (selects of the ready models able to do the task's `needs`, as core's rows), marked with the plugin (as v10's `settings-ai.html` draws Smart Image Matcher's row). Keys: `PluginKeys::stem(code).'_'.str_replace('-', '_', task).'_model'` and `…_fallback`, read by `ModelChoices::forStem(string $stem): ?ModelChoice`. **An unchosen model means the task is off**, as for core's tasks; `standing()` says `no-model` with `:task is off until an administrator chooses its model in Settings › AI.`

**A5. Per plugin: on, budget, hourly limit.** In the same section, for each plugin granted `ai`: `stem_on` (boolean, default on: consent was the yes), `stem_budget` (whole US dollars a month, 0 = only the site's budget applies, at most the site's budget when the site has one), `stem_hourly` (requests an hour, default 120, 1 to 10,000). Saving goes through `UpdateSettings` (the `ai` group: `settings.ai.manage`, password asked again). The section shows each plugin's month so far (cost, requests) from `ai_plugin_monthly_spend`. Disabling a plugin hides its rows; stored values stay; `remove-data` leaves AI records (they are the site's record of spending).

**A6. Recorded and shown by plugin.**
- Migration `add_plugin_to_ai_activity`: `plugin varchar(64) null`; `subject_id` nullable; the subject check becomes `subject_type IN ('content','media','none') AND ((subject_type = 'none') = (subject_id IS NULL))`; the outcome check gains `answered`; the error-type check accepts `answered` with a null type; index `ai_activity_plugin_index ON ai_activity (plugin, created_at DESC) WHERE plugin IS NOT NULL`.
- Migration `create_ai_plugin_monthly_spend_table`: `plugin varchar(64)`, `month date`, `cost_microunits bigint`, `requests int`, `unpriced_requests int`, primary key `(plugin, month)`; `RecordAiActivity` adds to it in the same transaction as `ai_monthly_spend` when `plugin` is set (the same `ON CONFLICT` addition).
- `RecordAiActivity` accepts `answered` only with a plugin, and a null subject only with a plugin; the task of a plugin row is the declared task id.
- Tools › AI activity: a **Plugin** filter (Teme, or each plugin that has rows), the plugin's name beside the task in each row, and this month's totals by plugin under the month figures. The Plugins screen's row for a plugin granted `ai` shows `AI this month: :cost` (and `of :budget` when it has one) from `PluginRow`'s new `ai` value.

**A7. Long requests (W-01 for AI).** `AiRequest` gains `bool $long = false` (last): with it, the timeout may be 1 to 600 seconds. `ModelCall` passes the timeout to laravel/ai's request; Task 0 records how `LibraryProvider` sets it and whether a 600-second, 64,000-token request is accepted unstreamed by each provider; if one needs streaming for that, the provider class streams internally and returns the whole answer (no streaming reaches the plugin). A long plugin job's worker timeout is already 1,800 s.

### The delicate code: `PluginSiteAi::ask()`

```php
public function ask(AiAsk $ask, string $actingPersonId): AiAnswer
{
    try {
        ActingPerson::require($actingPersonId);                 // the person of this work; never the operator
    } catch (WriteRefused $refused) {
        throw new AiRefused('no-person', $refused->getMessage());
    }

    $task = $this->tasks->declared($this->code, $ask->task)
        ?? throw new AiRefused('no-task', __('This plugin doesn’t declare the AI task :task.', ['task' => $ask->task]));
    $this->checkBounds($ask, $task);                            // tokens, schema, too-long (A3.2)

    $standing = $this->standing->of($this->code, $task);
    if (! $standing->available) {
        throw new AiRefused($standing->reason, $standing->sentence);   // nothing sent, nothing recorded
    }

    $stem = PluginAiTasks::stem($this->code, $task->id);
    $choice = $this->choices->forStem($stem);                   // non-null: standing checked it
    $prompt = new AiPrompt($task->id, 1, $ask->instructions, AiPrompt::hashOf($ask->instructions));
    $request = fn (ModelRef $ref, int $seconds): AiRequest => new AiRequest(
        $ref->model, $prompt->messages($ask->inputs), $ask->maxOutputTokens, $seconds,
        schema: $ask->schema, long: $ask->timeoutSeconds > AiRequest::MAX_TIMEOUT,
    );

    $id = strtolower((string) Str::ulid());
    $call = $this->call->ask($choice, $request, $ask->timeoutSeconds);   // primary, then the fallback once (I-1321)

    $this->record->execute(new RecordAiActivityInput(
        id: $id, actorId: $actingPersonId, task: $task->id, prompt: $prompt,
        subjectType: $ask->itemId === null ? AiSubjectType::None : AiSubjectType::Content, subjectId: $ask->itemId,
        outcome: $call->failure === null ? AiOutcome::Answered : ($call->failure === AiFailureType::Refused ? AiOutcome::Refused : AiOutcome::Failed),
        failure: $call->failure, provider: $call->ref?->provider, model: $call->model, usage: $call->usage,
        costMicrounits: $call->cost, latencyMs: $call->latencyMs, plugin: $this->code,
    ));

    if ($call->failure !== null) {
        throw new AiRefused($call->failure->value, AiFailureWords::sentence($call->failure));   // core's words, never the service's
    }

    return new AiAnswer($call->model, $call->result->text, $call->result->data, $call->usage->input + $call->usage->cacheRead + $call->usage->cacheWrite, $call->usage->output, $call->cost, $id);
}
```

`ModelCall::ask(ModelChoice, Closure(ModelRef, int): AiRequest, int $deadlineSeconds): ModelCallResult` is `AnswerAiRequest`'s current ask-and-fall-back logic, moved unchanged; `AnswerAiRequest` calls it with its 55-second deadline and its own floor. `AiFailureWords` is whatever `AnswerAiRequest`'s page uses today for each failure type (Task 0 names it; reuse it).

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; how `LibraryProvider` passes a timeout to laravel/ai and whether a long unstreamed request is accepted (A7); the words used today for each `AiFailureType`; the settings key pattern (does `plugin_9_writer_article_model` pass?); how `PluginLoader` builds a long job's doors (the `httpSeconds` path); that `ai_activity`'s checks are named as the migration names them. A difference that changes a decision is a STOP.
1. **Declared tasks and consent.** `PluginAiTasksTest` with `scribe`: the manifest refusals (more than 8, a bad id, an unknown need, `sends` unknown, tokens out of range, no `ai`); the consent lines; an update adding a task or widening `sends` waits for consent. Red.
2. **Recording by plugin.** `PluginAiActivityTest`: the migrations; `RecordAiActivity` with a plugin and no subject; `answered` refused without a plugin; the plugin month row added in the same transaction and not on rollback. Red.
3. **The door.** `SiteAiTest`, with a fake provider that records calls: a job running as the enabling administrator asks and gets text, and a schema task gets data; one activity row under the plugin, cost added to both month tables; the fallback is asked once when the first is busy; **refused with nothing sent and nothing recorded**: no person, another person, the operator's job, an undeclared task, tokens over the declaration, a schema for a task without `structured-output`, more than 55 s outside a long job, AI off, the plugin switched off, no model, a model without a need, the site's budget reached, the plugin's budget reached, the hourly limit reached; a failed call is recorded with its type and billed tokens, and throws. Red: contract missing.
4. **Long requests.** `LongAiRequestTest`: inside a long job a 600-second request reaches the provider with that timeout; `AiRequest` refuses 601, and 56 without `long`. Red.
5. **Settings and figures.** `PluginAiSettingsTest` (feature): the Plugins section's props per plugin (tasks, models, on, budget, hourly, month); saving a plugin budget above the site's is refused and writes nothing; a person without `settings.ai.manage` can't save; Tools › AI activity filters by plugin and totals by plugin; the Plugins screen's row carries the AI line. Red.
6. **The screens.** Only after the maintainers approve the drawings (proposal, screens 1 to 3); until then, STOP before this task. `settings/ai.tsx` (the Plugins section), `tools/ai-activity.tsx` (filter, name, totals), the Plugins screen's line. `PluginAiSettingsTest` (browser, 1280 and 375): rows marked with the plugin; switching a plugin off and saving after the password; the filter; axe in light and dark.
7. **Handbook and records.** `plugins/ai.md` (declaring tasks, `SiteAi`, what core records and never records, limits, long requests, `standing()` to say why on the plugin's own pages); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Ai`, `tests/Feature/Plugins` and `tests/Unit/Contracts`. Once at the end: `composer check`, and `composer check:browser` for `PluginAiSettingsTest`, `AiSettingsScreenTest` and `AiActivityScreenTest`.

### Records (the builder's)

#### Commits on `sockets3/plugin-ai`

-

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

#### Task 0

-

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | | |
| admin-words-baseline.txt | | |

#### Gate

-

#### B-?? (draft)

-

#### Contract changes

-

#### STOP

-

#### Changelog lines for the maintainers

-

#### Try it

-

---

## Part B: a plugin's panel in the editor (`sockets3/editor-panels`)

Walls W-11, W-14 (the editor half), W-03 (the title picker and "write now" from the editor); FEATURES 4.5, 5.7, 5.8, 7 (editor integration).

### Owns

- **New:** `packages/teme-contracts/src/Editor/` (`HasEditorPanels`, `EditorPanel`, `EditorPanelContext`, `PanelView`, `Suggestion`, `SuggestedChange`); `app/Domain/Plugins/Editor/**` (`DeclaredPanel`, `PanelValidator`, `EditorPanels` (which panels a person sees on an item), `PanelReads` (budgeted `view()`), `SuggestionCheck` (each change checked against the item), `PanelJobs` (the panel's waiting and running jobs on this item)); `app/Domain/Plugins/Operations/RunEditorPanelAction.php`, `SettleEditorSuggestion.php` and their inputs; `app/Http/Admin/Contents/EditorPanelController.php`; `resources/js/components/editor/plugin-panels/` (`plugin-panel.tsx`, `panel-action-form.tsx`, `panel-suggestions.tsx`, `apply-change.ts`); the fixture plugin `tests/Fixtures/plugins/drafter/` (one panel, two actions, every suggestion kind); tests `tests/Feature/Plugins/Editor/**`, `tests/Browser/Editor/PluginPanelTest.php`; `docs/guides/developers/plugins/editor-panels.md`.
- **Changed:** `app/Domain/Preferences/BuiltInPreferences.php` (`editor.pinned_tools`' allowed list gains the person's plugin panels); the editor's props builder (Task 0 names it; the panels a person sees on this item); `resources/js/components/editor/tools/editor-tools.ts` and `pins.ts` (plugin tools, pinnable), `editor-header.tsx` and `editor-rail.tsx` (a plugin tool opens its pane with `PluginMark`); `routes/admin.php` (three routes in the content block); `resources/js/types/*` for the props; the shared files above.
- **Not owned:** A, C, D; core's assistant; declared places inside core boxes (later, with the SEO plugin, I-3418).

### Decisions

**B1. Declared panels.** Manifest `contributes.editorPanels`, at most 2, needs `content.read` and `jobs`:

```json
"editorPanels": [
  { "id": "writer", "label": "Writura", "description": "Write this draft from its title, and pick a title.", "icon": "sparkles",
    "types": ["post"], "permission": "write",
    "actions": [
      { "id": "write", "label": "Write this article", "job": "write-draft", "confirm": "This replaces nothing yet: the article arrives as a suggestion you can use or discard.",
        "fields": [
          { "key": "keyword", "type": "text", "label": "Main keyword", "required": true, "maxLength": 120 },
          { "key": "length", "type": "select", "label": "Length", "options": { "standard": "Standard", "in-depth": "In depth" } } ] },
      { "id": "titles", "label": "Suggest titles", "job": "suggest-titles" } ] }
]
```

- `id` `^[a-z][a-z0-9-]{1,23}$`; `label` 1 to 40; `description` up to 160; `icon` from the Tools links' closed list plus `sparkles`, `pen-line`, `list-checks`; `types` content-type keys (at least one; refused when a type doesn't exist at enable: `Its editor panel names :type, which isn’t a content type here.`); `permission` optional, a permission the plugin declares (round 2 A1).
- `actions` 0 to 3, each `id` as the panel's, `label` 1 to 40, `job` a declared job, `confirm` optional (up to 200 characters, shown before queueing), `fields` 0 to 4 of `text` (up to 500), `textarea` (up to 2,000), `select`, `boolean`, `number` (with `min`, `max`), each with `key`, `label`, `required`, `default`.
- Refused without `content.read` or `jobs`, naming which.
- **Consent:** `Add a panel to the editor for :types: “:label”, which can start its work on the item you’re editing and suggest changes you choose to use.`

**B2. Who sees it.** A panel shows in an item's editor when the plugin is enabled and granted both capabilities, the item's type is listed, the person may update the item (`Gate::allows('update')`), and, when the panel names a permission, the person holds `plugin.{code}.{permission}`. It is a tool in the editor's bar, id `plugin.{code}.{panel}`, owner the plugin's label, under **Tools from plugins** as v10 draws it, pinnable within the existing 3 pins (`BuiltInPreferences`' allowed list = core's plus the panels this person may see anywhere). It opens in the rail's one pane with the plugin's mark in its head (v10's `paneHead(title, plug)`).

**B3. The contract.** In `Teme\Contracts\Editor`, `@since V`, no framework type:

```php
/** A plugin whose manifest declares editor panels. @since V */
interface HasEditorPanels
{
    /** @return array<string, EditorPanel> by declared panel id  @since V */
    public function editorPanels(): array;
}

/** What one panel shows for one item, and what happens when the person uses or discards a suggestion. Core draws it. @since V */
interface EditorPanel
{
    /** @since V */
    public function view(EditorPanelContext $context): PanelView;

    /**
     * The person used or discarded one of this panel's suggestions. Runs inside core's transaction.
     *
     * @throws \Teme\Contracts\Content\WriteRefused  with one plain sentence; nothing is kept
     *
     * @since V
     */
    public function settled(string $suggestionId, bool $used, EditorPanelContext $context): void;
}

/** The item the editor shows, and who is looking. @since V */
final readonly class EditorPanelContext
{
    /** @param list<string> $permissions  this plugin's permission ids the person holds  @since V */
    public function __construct(
        public string $itemId, public string $revisionId, public string $type, public string $language,
        public string $personId, public string $locale, public array $permissions,
    ) {}
}

/** A short status line and the suggestions waiting for the person. @since V */
final readonly class PanelView
{
    /** @param list<Suggestion> $suggestions  at most 20  @since V */
    public function __construct(public ?string $status = null, public string $tone = 'neutral', public array $suggestions = []) {}
}

/** One change the person may use, with a label and a short explanation. @since V */
final readonly class Suggestion
{
    /** @since V */
    public function __construct(public string $id, public string $label, public string $detail, public SuggestedChange $change) {}
}

/** The changes core knows how to show and apply in the editor. @since V */
final class SuggestedChange
{
    public static function title(string $title): self;                                       // @since V
    public static function field(string $handle, string|int|float|bool|null $value): self;   // a scalar field, by handle
    public static function terms(string $taxonomy, array $slugsOrNames): self;               // added to the item's terms
    public static function link(string $phrase, string $itemId, int $occurrence = 1): self;  // an internal link on a phrase, stored by ID (rule 9)
    public static function body(array $document): self;                                      // a whole Tiptap document, shown as a proposal
}
```

Every constructor checks its bounds (`id` `^[a-z0-9][a-z0-9:._-]{0,63}$`, `label` up to 120, `detail` up to 500, `status` up to 200, `tone` from `Cell::TONES`, at most 20 suggestions, `occurrence` 1 to 50) and throws `InvalidArgumentException`.

**B4. Reads, under a budget.** `GET admin/content/{content}/panels/{code}/{panel}` (`content.panels.show`, JSON as the editor's other calls): authorize as B2; `PanelReads` calls `view()` with `ColumnClock` (250 ms); over it, or a throw: `This panel couldn’t be shown this time.`, fault through `ListenerFaults` (`{name}#editor-panel`). Each suggestion goes through `SuggestionCheck` against the item as the editor holds it: a `field` must exist on the type with a matching scalar type; `terms` names an existing taxonomy of the type (a missing term is created on save by the person's own rights, as the editor does today); `link` must name a published item the person may see, never the item itself; `body` must pass the document validator `SaveDraft` uses (Task 0 names it). A suggestion that fails its check is dropped and recorded once as a fault. The answer also carries the panel's jobs on this item (B5): label, state, progress `done/total` and message from `tracked_jobs`. Every value reaches the page as text; never `dangerouslySetInnerHTML`.

**B5. Actions queue the plugin's work, as Operations (rule 1).** `POST admin/content/{content}/panels/{code}/{panel}/actions/{action}` runs `RunEditorPanelAction` (input: code, panel, action, contentId, revisionId, values, actorId):
- **authorize:** as B2, and the plugin's `jobs` grant.
- **validate:** the action is declared; `values` against its `fields` (types, required, bounds, options); `revisionId` is this item's working revision.
- **perform:** queue the action's job through `PluginJobs` **as this person**, payload `{"panel", "action", "itemId", "revisionId", "values"}`, label `:action: :title`, `QueueOptions(uniqueKey: "panel:{panel}:{action}:{itemId}")`, so a second click while one waits queues nothing.
The answer is the panel's view again. The job runs as the person, so it may read the draft (C1's `ContentReader::draft()`), ask AI (A), and either leave suggestions for `view()` to return or write a new revision through `ContentWriter::change()`; the panel says nothing core can't check. While one of the panel's jobs on this item waits or runs, the page asks again every 5 seconds, for at most 15 minutes, and when the pane closes it stops.

**B6. Applying a suggestion.** In the browser, as the assistant's Use (one change the person can undo; nothing is saved until the person saves): `title` sets the title; `field` sets the field; `terms` adds the terms; `link` wraps the `occurrence`th match of `phrase` in text outside existing links and code with the link mark `href: "teme:content/{itemId}"` (or says `The phrase isn’t in the article any more.`); `body` opens the assistant's proposal view with the word diff, and Use replaces the document. Then the page posts `POST …/panels/{code}/{panel}/suggestions/{suggestion}` with `used: true|false`, which runs `SettleEditorSuggestion` (authorize as B2; call `settled()` inside the transaction, 2,000 ms, rolled back with any plugin write on a throw or the budget, recorded through `ListenerFaults`; `WriteRefused` shows its sentence). Discard posts `used: false` and removes the suggestion from the pane.

### The delicate code: `link` in the browser (`apply-change.ts`)

```ts
/** Wraps the nth plain-text match of `phrase` in an internal link; false when it isn't there. */
export function applyLink(editor: Editor, phrase: string, itemId: string, occurrence: number): boolean {
    const wanted = phrase.toLocaleLowerCase();
    let seen = 0;
    let found: { from: number; to: number } | null = null;

    editor.state.doc.descendants((node, pos) => {
        if (found !== null || !node.isText || node.marks.some((mark) => ['link', 'code'].includes(mark.type.name))) {
            return found === null;
        }
        const text = (node.text ?? '').toLocaleLowerCase();
        for (let at = text.indexOf(wanted); at !== -1; at = text.indexOf(wanted, at + wanted.length)) {
            if (++seen === occurrence) {
                found = { from: pos + at, to: pos + at + phrase.length };
                return false;
            }
        }
        return true;
    });

    if (found === null) {
        return false;
    }

    // One step, so Undo takes it back whole; the address is made on render (rule 9).
    return editor.chain().focus().setTextSelection(found).setLink({ href: `teme:content/${itemId}` }).run();
}
```

A phrase that spans two text nodes (part bold) is not matched; the handbook says so.

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; that round 1's `sockets/plugin-admin` and round 2's `sockets2/plugin-area` are on `main` (`RunPluginAction.php`, `PluginPermissions.php`; **if not, STOP**); the class building the editor's props; the document validator `SaveDraft` uses; how the assistant's Use makes one undoable change; the editor's link mark name and its `teme:content/` form; how the editor tells the person when the draft was saved elsewhere while open (a job's `ContentWriter::change()`). A difference that changes a decision is a STOP.
1. **Declared panels.** `DeclaredPanelTest` with `drafter`: every refusal in B1, each one plain sentence; the consent line; the schema accepts a valid panel. Red.
2. **Who sees it.** `EditorPanelsTest`: on a listed type, for a person who may update the item and holds the permission, the editor's props carry the tool; not on another type, not for a person who can't update it, not without the permission, not when disabled or not granted; the pinned-tools preference accepts the panel's id for that person and refuses it for another. Red.
3. **Reads.** `PanelReadsTest`: the view's status and suggestions reach the JSON; a slow or throwing `view()` gives the failure line and a recorded fault; each `SuggestionCheck` refusal drops the suggestion (unknown field, wrong scalar type, unknown taxonomy, a link to a draft, to an item the person can't see, to itself, a body the validator refuses); markup arrives as text; the panel's jobs on this item with progress; 403 and no plugin call for a person who may not see it. Red.
4. **Actions and settling.** `RunEditorPanelActionTest`: queues the job as the person with the payload and unique key; a second call returns the same job; refused **writing nothing** (no tracked job row): a person who can't update the item, no permission, an undeclared action, a value the fields refuse, a stale revision, a disabled plugin. `SettleEditorSuggestionTest`: `settled()` is called with `used`; its own table write rolls back on `WriteRefused` and on the budget; refused for a person who may not see the panel, writing nothing. Red.
5. **The screens.** Only after the maintainers approve the drawings (proposal, screen 4); until then, STOP before this task. The tool in the bar and the Tools menu, the pane with the plugin's mark, the action form and confirmation, the job's progress, the suggestions with Use and Discard, the `body` proposal, the failure line. `PluginPanelTest` (browser, 1280 and 375): pin the panel; run an action and see its progress; use a title, a field, a link (and Undo it), a body; discard one; axe in light and dark.
6. **Handbook and records.** `plugins/editor-panels.md` (declaring, `view()` and its budget, suggestion kinds and their checks, actions and the job's payload, reading the draft with `ContentReader`, the person the job runs as); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Editor`, `tests/Feature/Plugins`, `tests/Feature/Contents` (the editor's props) and `tests/Unit/Contracts`. Once at the end: `composer check`, and `composer check:browser` for `PluginPanelTest` and the editor's existing browser tests that touch the bar and the assistant.

### Records (the builder's)

#### Commits on `sockets3/editor-panels`

-

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

#### Task 0

-

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | | |
| admin-words-baseline.txt | | |

#### Gate

-

#### B-?? (draft)

-

#### Contract changes

-

#### STOP

-

#### Changelog lines for the maintainers

-

#### Try it

-

---

## Part C: content doors and the acting person (`sockets3/content-doors`)

Walls W-06, W-07, W-08, W-14 (the reading half), W-15, W-02 (outside AI), W-16, W-10 (its second half); findings F-10.

### Owns

- **New:** `packages/teme-contracts/src/Content/ContentReader.php`, `ItemQuery.php`, `ItemPage.php`, `ItemSnapshot.php`, `ReadRefused.php`; `app/Domain/Plugins/Doors/PluginContentReader.php`; `app/Domain/Contents/Queries/PublishedItems.php` (the keyset list), `ItemSnapshots.php`; the fixture plugin `tests/Fixtures/plugins/librarian/` (reads, writes with slug, credits and a translation, a `person` setting); tests `tests/Feature/Plugins/Sockets/ContentReaderTest.php`, `ContentWriterDetailsTest.php`, `JobPersonTest.php`, `PersonSettingTest.php`, `LongAnswersTest.php`; `docs/guides/developers/plugins/content-read.md`.
- **Changed:** `packages/teme-contracts/src/Content/NewItem.php`, `ItemChanges.php` (last params), `ContentWriter.php` (one method, or C3's new interface), `src/Jobs/JobContext.php` (one method, or C4's new interface), `src/Http/OutboundHttp.php` (its docblock's limits sentence); `app/Domain/Plugins/Doors/PluginContentWriter.php`, `PluginHttp.php` (answer size), `app/Domain/Plugins/Jobs/PluginJobContext.php`, `RunPluginJob.php` (passes the long answer size); `app/Domain/Plugins/Contributions/DeclaredSettings.php` (`person`); round 1's `WritePluginSettings` (refuses `person`, one line); after round 2 A, `packages/teme-contracts/src/Admin/AreaContext.php` and round 2's `PluginAreaScreen`/`SavePluginRecord` context builders (C-T7); `docs/guides/developers/plugins/content-write.md`, `jobs.md` (the limits table), `settings.md` (`person`); the shared files above.
- **Not owned:** A, B, D; core's Operations (called, not changed, unless Task 0 finds a missing parameter, which is a STOP).

### Decisions

**C1. A content reader (W-06, W-14).** Offered to a plugin granted `content.read`. `@since V`:

```php
/** Reads the site's content for a plugin: published items for anyone, and a draft for a person who may edit it. @since V */
interface ContentReader
{
    /**
     * Published, public items, newest first, one page at a time: what a visitor can open.
     *
     * @since V
     */
    public function published(ItemQuery $query): ItemPage;

    /**
     * The item's working revision, as the editor holds it, for a person who may edit it.
     *
     * @throws ReadRefused  when the person isn't the one this work acts for, or may not edit it
     *
     * @since V
     */
    public function draft(string $itemId, string $actingPersonId): ItemSnapshot;
}

/** @since V */
final readonly class ItemQuery
{
    /**
     * @param  list<string>  $types  content-type keys, at most 10; empty for every public type
     * @param  string|null  $language  a site language's BCP 47 tag; null for every language
     * @param  array<string, list<string>>  $terms  term slugs by taxonomy, at most 10 in all; an item matches any term of each taxonomy
     * @param  string|null  $after  the cursor ItemPage gave; null for the first page
     * @param  int  $limit  1 to 200
     *
     * @since V
     */
    public function __construct(
        public array $types = [], public ?string $language = null, public array $terms = [],
        public ?\DateTimeImmutable $publishedSince = null, public ?string $after = null, public int $limit = 100,
    ) {}
}

/** @since V */
final readonly class ItemPage
{
    /** @param list<ItemRef> $items  @param string|null $next  null on the last page  @since V */
    public function __construct(public array $items, public ?string $next) {}
}

/** One item's working revision: its parts by handle and slug, never a model. @since V */
final readonly class ItemSnapshot
{
    /**
     * @param  array<string, mixed>  $body  the Tiptap document
     * @param  array<string, mixed>  $fields  by field handle
     * @param  array<string, list<string>>  $terms  term slugs by taxonomy
     * @param  list<string>  $credits  account IDs the item credits, in order; empty when it credits its owner
     * @param  list<ItemRef>  $translations  the item's other language versions
     *
     * @since V
     */
    public function __construct(
        public ItemRef $item, public string $revisionId, public string $slug, public array $body,
        public array $fields, public array $terms, public array $credits, public array $translations,
    ) {}
}
```

- `published()` lists only what an anonymous visitor may open: status published, public visibility (not private, not password-protected), a type with public addresses, not trashed; `ItemRef`s built by `Payloads::item()` (so `url` is absolute, made now, rule 9, and `indexable` is filled once round 1 C is merged). Order `published_at DESC, id DESC`; the cursor is opaque (base64url of both); a malformed cursor throws `InvalidArgumentException`. One query per page with the terms as `EXISTS`; Task 0 records the index it uses.
- `draft()` requires `ActingPerson` (its sentence) and `Gate::allows('update', $item)` (`You can’t edit this item, so its draft can’t be read for you.`), both as `ReadRefused`. `ContentSaved` stays as it is: a listener reads the draft with the hook's `actorId`, which is the signed-in person of that request.

**C2. Slug, translation and bylines when writing (W-07, W-08).** `NewItem` gains, last: `?string $slug = null`, `?string $translationOf = null` (an existing item's ID in another language), `array $credits = []` (`list<string>` account IDs; empty credits the acting person). `ItemChanges` gains, last: `?string $slug = null`, `?array $credits = null`. In `PluginContentWriter`, **one transaction**, as the acting person:
- `create()`: `CreateDraft`, then, when given, `UpdateContentDetails(slug)` on the new working revision, `SetBylines(credits)`, `LinkTranslations([translationOf, new id])`. Any refusal rolls back the whole item and throws `WriteRefused` with the Operation's sentence (the slug's own, "Another post already uses this address.", "Only someone who may edit anyone's posts may credit another person with an account.", "two versions in one language").
- `change()`: `SaveDraft` as today, then `UpdateContentDetails(slug)` and `SetBylines(credits)` when given.
- A credit is never the operator, and a byline different from the actor is allowed only within the actor's own rights (`SetBylines`' rule); scheduled work runs as the enabling administrator, who usually holds `edit.any`.

**C3. Linking existing versions.** `ContentWriter::linkTranslations(array $itemIds, string $actingPersonId): void` (`@param list<string> $itemIds`, 2 to the site's languages, `@since V`) through `LinkTranslations`. When V is not `0.9.0`, it is a new interface `TranslationLinker` with the same method, offered with `content.write`.

**C4. The person a job acts for (W-15).** `JobContext::personId(): string` (`@since V`): the account ID the run acts for (the person who queued it, or for a schedule the administrator who enabled the plugin, round 1 B5), so a handler passes it to every door instead of reading a "write as" setting. When V is not `0.9.0`, a new interface `JobPerson` with `personId()`, implemented by `PluginJobContext`, and the handbook shows `$context instanceof JobPerson`. An operator's run has no person: `personId()` throws `JobStopped` with `This run has no person to act for.` (Task 0 records whether a plugin job can be the operator's today.)

**C5. Bigger answers inside long jobs (W-02).** Inside a `long` job's run, `OutboundHttp` answers may be up to 16 MB (requests stay at 1 MB); outside, 1 MB as today. `PluginDoors::for()` passes the size beside `httpSeconds`; `PluginHttp` reads the body in chunks and throws `AnswerTooLarge` the moment it passes the limit, never holding more. The contract's class docblock sentence becomes `… at most 10 seconds and 1 MB, or 300 seconds and 16 MB for an answer inside a long job.` (wording only; nothing else in the contract changes).

**C6. A `person` setting (F-10).** The manifest's settings type `person` (the PHP `Setting::person()` already exists): an account ID chosen with core's person picker (the one core groups use; Task 0 names it), validated as an existing account; `SettingsReader` returns the ID as a string. Round 1's `WritePluginSettings` refuses it (`A plugin can’t choose a person for itself.`). For a byline the plugin credits by default.

**C7. Limits written down (W-16).** `jobs.md` gains one table, kept by tests that already exist: a job's run (75 s; 1,800 s for `long`), an outbound call (10 s, 300 s in a long job), an answer (1 MB, 16 MB in a long job), a payload (64 KB), an AI request (55 s, 600 s in a long job, from A), a schedule's smallest interval (one minute, round 2 C3), with a paragraph on cutting work into runs: one step per run, each step idempotent, `QueueOptions` delay to space them.

**C8. A plugin's own records per person (W-10's second half).** Tracked jobs a person started already show in the header's jobs button; nothing to build there. For a plugin's own records in its area (round 2 A), `AreaContext` gains, last, `array $permissions = []` (`list<string>`: this plugin's permission ids the person holds), so a plugin can show "my jobs" to most and "everyone's" to holders of its own `view-all` permission; core fills it in every `AreaContext` it builds.

### The delicate code: the keyset page

```php
public function page(ItemQuery $query): ItemPage
{
    [$afterAt, $afterId] = $query->after === null ? [null, null] : Cursor::decode($query->after);   // throws on a bad cursor

    $rows = Content::query()->publicPublished()                     // Task 0: the scope the site's own lists use
        ->when($query->types !== [], fn ($q) => $q->whereIn('type', $query->types))
        ->when($query->language !== null, fn ($q) => $q->where('language', app(SiteLanguages::class)->stored($query->language)))
        ->when($query->publishedSince !== null, fn ($q) => $q->where('published_at', '>=', $query->publishedSince))
        ->tap(fn ($q) => TermFilter::any($q, $query->terms))         // one EXISTS per taxonomy
        ->when($afterAt !== null, fn ($q) => $q->whereRaw('(published_at, id) < (?, ?)', [$afterAt, $afterId]))
        ->orderByDesc('published_at')->orderByDesc('id')
        ->limit($query->limit + 1)
        ->get();

    $more = $rows->count() > $query->limit;
    $items = $rows->take($query->limit);

    return new ItemPage(
        $items->map(fn (Content $item): ItemRef => Payloads::item($item))->values()->all(),
        $more ? Cursor::encode($items->last()->published_at, $items->last()->id) : null,
    );
}
```

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; the scope the site's lists use for public published items, and the index a page uses (`EXPLAIN`); `SetBylines`' input format for an account credit; whether `UpdateContentDetails` needs `expectedRevisionId` right after `CreateDraft` (the new working revision's ID); the person picker core groups use; whether a plugin job can run as the operator; whether round 1's `sockets/plugin-admin` is on `main` (for C6's line).
1. **Reading published items.** `ContentReaderTest` with `librarian`: pages of 2 across 5 items, newest first, cursors chain to the end; types, language, terms and `publishedSince` filter; a draft, a private item, a password-protected item, a trashed item and a non-public type never appear; `url` is absolute and follows a changed site address; a bad cursor throws; refused without `content.read` (the door isn't offered). Red.
2. **Reading a draft.** In the same file: a job running as an editor reads the working revision's body, fields by handle, terms, slug, credits and translations; refused for another person, the operator, and a person who can't edit the item. Red.
3. **Writing details.** `ContentWriterDetailsTest`: create with a slug, credits and `translationOf`; change a slug and credits; `linkTranslations`; refused **with nothing written** (no item, no revision, no group change): a taken slug, a bad slug, crediting another account without `edit.any`, a translation in the same language, of another type, of a trashed item. Red.
4. **The job's person.** `JobPersonTest`: a queued run says its person; a scheduled run says the enabling administrator; a door given that ID accepts it. Red.
5. **`person` setting.** `PersonSettingTest`: declared, drawn with the picker, a missing account refused and nothing written; the reader returns the ID; `WritePluginSettings` refuses it. Red.
6. **Long answers.** `LongAnswersTest` with the fake transport: a 5 MB answer inside a long job arrives; 17 MB throws `AnswerTooLarge` without reading past the limit; 2 MB outside a long job throws. Red.
7. **Area context** (after round 2's `sockets2/plugin-area` is merged; rebase first). `AreaContextPermissionsTest`: the context carries only this plugin's permissions the person holds, in every read and write. Red.
8. **Handbook and records.** `plugins/content-read.md`; `content-write.md` (slug, credits, translations, `linkTranslations`); `jobs.md` (C4, C7); `settings.md` (`person`); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Sockets`, `tests/Feature/Plugins`, `tests/Feature/Contents` and `tests/Unit/Contracts`. Once at the end: `composer check`. No browser test (the person picker is core's, already tested).

### Records (the builder's)

#### Commits on `sockets3/content-doors`

-

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

#### Task 0

-

#### Gate

-

#### B-?? (draft)

-

#### Contract changes

-

#### STOP

-

#### Changelog lines for the maintainers

-

#### Try it

-

---

## Part D: hooks a plugin declares, plugin dependencies, notices from plugins (`sockets3/hooks-notices`)

Walls W-09, W-05; findings F-03 (`Notices` refused); FEATURES 4.7, 4.14, 7 (email notifications).

### Owns

- **New:** `packages/teme-contracts/src/Hooks/HookSender.php`; `app/Domain/Plugins/Hooks/**` (`DeclaredHooks`, `PluginHookCatalogue`, `PluginHookSender`); `app/Domain/Plugins/Dependencies.php`; `app/Domain/Plugins/Notices/**` (`DeclaredNotices`, `PluginNotices` (the door), `PluginNoticeRules`, `NoticeRate`); `app/Domain/Plugins/PluginKeys.php` (as A's); the fixture plugins `tests/Fixtures/plugins/relay/` (declares a `Collect` hook, a `Transform` hook and a `Notification` hook, two notice kinds) and `tests/Fixtures/plugins/relay-pro/` (requires `relay`, listens to all three); tests `tests/Feature/Plugins/Hooks/**`, `tests/Feature/Plugins/Notices/**`, `tests/Browser/Account/PluginNoticeChoicesTest.php`; `docs/guides/developers/plugins/your-own-hooks.md`, `notices.md`.
- **Changed:** `app/Domain/Hooks/HookDispatcher.php` and `OwnedHooks.php` (plugin hook names), `Reference/HookCatalogue.php` (plugin hooks listed under their plugin); `app/Domain/Plugins/PluginLoader.php` (checks a declared hook's class when it loads the plugin); `app/Providers/NoticeServiceProvider.php` or the plugin boot (registers enabled plugins' kinds); `app/Domain/Notices/Queries/NoticeChoices.php` (the plugin's label beside its kinds) and `resources/js/pages/account/notifications.tsx` (Task 0 names it; `PluginMark`); `app/Console/Commands/ListPlugins.php` (Requires and Required by); the shared files above.
- **Not owned:** A, B, C; core's own hooks and notice kinds.

### Decisions

**D1. A plugin declares hooks (W-09).** Manifest `contributes.hooks`, at most 10:

```json
"hooks": [
  { "name": "PromptModules", "class": "KraftySprouts\\Writura\\Hooks\\PromptModules", "description": "Collects extra prompt modules for an article." }
]
```

- `name` `^[A-Z][A-Za-z]{2,47}$`; its full name is `{code}:{name}` (`writura:PromptModules`). `class` is a class in the plugin's own autoloaded namespace (Task 0 records where the loader reads it), implements exactly one of `Notification`, `Transform` or `Collect` (`Gate`, `Veto` and `Resolve` stay core's in this round), and carries `#[HookContract]` whose `capability` is null or a Teme capability the declaring plugin was granted. Checked when the plugin is loaded at enable, each failure one sentence: `Its hook :name names :class, which isn’t a Notification, Transform or Collect hook.`, `… which isn’t in the plugin’s own namespace.`, `… which needs :capability, which the plugin doesn’t ask for.`
- **Sending.** Door `Teme\Contracts\Hooks\HookSender` (`@since V`), offered to a plugin that declares hooks:

```php
/** Sends the calling plugin's own declared hooks to the plugins that listen. @since V */
interface HookSender
{
    /** Waits for the outermost commit when a transaction is open, as core's notifications do. @throws HookRefused  @since V */
    public function notify(Notification $hook): void;

    /** @throws HookRefused  for a hook this plugin doesn't declare  @since V */
    public function transform(Transform $hook): mixed;

    /** @return list<mixed>  @throws HookRefused  @since V */
    public function collect(Collect $hook): array;
}
```

  `PluginHookSender` refuses a hook class this plugin doesn't declare, then hands it to `HookDispatcher` unchanged: listeners' faults, `Failure`, ordering, depth and quarantine work as for core's hooks. A notification sent inside an open transaction joins `PendingNotifications` (rule 6).
- **Listening.** A plugin lists `writura:PromptModules` in its `hooks` (the item pattern becomes `^([a-z0-9][a-z0-9-]{1,39}:)?[A-Z][A-Za-z]+$`) and subscribes with `$hooks->on(PromptModules::class, …)`, as for core's hooks. `OwnedHooks` maps the class to its full name through `PluginHookCatalogue` and applies the hook's `capability` to the listener. A listener must require the declaring plugin (D2): `It listens to :hook, but doesn’t list :plugin under requires.plugins.`
- **Reference.** `HookCatalogue` lists plugin hooks under each enabled plugin (name, verb, description, capability) for `teme:hooks` and the reference generator.

**D2. Dependencies.** `requires.plugins`: an object of Composer package names to constraints (at most 5), e.g. `{ "krafty-sprouts/writura": "^0.1" }`.
- **Enable** is refused, writing nothing, unless each is enabled at a version that satisfies its constraint (`Semver::satisfies`, as `requires.teme`): `It needs :plugin :constraint enabled first.`
- **Disable** is refused, writing nothing, while an enabled plugin requires it: `:dependents needs it. Disable :dependents first.` Safe mode is unaffected (it switches everything off).
- **An update** of a required plugin to a version a dependent doesn't accept: the dependent is switched to `needs-update` state as a fault (`It needs :plugin :constraint; :version is installed.`), its listeners unheard, until either changes; the Plugins screen and `teme:plugins` say so. (Task 0 records where an update's validation runs; if no fault state fits, STOP.)
- `teme:plugins` and the Plugins screen show **Requires** and **Required by**; the consent lists `Needs :plugin, which must stay enabled.`

**D3. Notices from plugins (W-05).** Capability `notices.send` (`Capability::NoticesSend`), consent words `Send notices to people on this site, by email or in their inbox: :kinds`. Manifest `contributes.notices`, at most 5:

```json
"notices": [
  { "id": "job_failed", "label": "Email me when an article I started fails", "audience": "starter", "channels": ["mail", "inbox"], "defaults": ["inbox"] },
  { "id": "queue_stalled", "label": "Email me when the article queue stops", "audience": "plugins.manage", "channels": ["mail"], "defaults": ["mail"] }
]
```

- `id` `^[a-z][a-z0-9_]{1,31}$`; the kind's key is `PluginKeys::stem(code).'.'.id` (`plugin_writura.job_failed`). `label` 1 to 120, as Account › Notifications words a choice. `audience`: `starter` (the person the current work acts for, and only them), `plugins.manage`, or a permission the plugin declares (round 2 A1, D-T5). `channels` and `defaults` as `NoticeKind`; never `required`.
- **Registered** in `NoticeRegistry` at boot for each enabled plugin granted `notices.send`, with `NoticeRules(offered: plugin enabled)`, so a disabled plugin's kinds are neither sent nor listed; Account › Notifications lists them with the plugin's mark and name.
- **The door** is the existing `Teme\Contracts\Notices\Notices`, added to `OFFERED` for plugins granted `notices.send` (F-03), implemented by `PluginNotices`:
  - `send(kind, accountIds, message)`: `kind` is the plugin's declared `id` (the door adds the stem; a full key of its own is accepted too); an undeclared kind throws `InvalidArgumentException`. For `starter`, `accountIds` must be exactly the current acting person (`ActingPerson`), else `InvalidArgumentException`; for the others, core's choices drop anyone without the audience's ability, as today.
  - The message: `subject` up to 120, at most 5 `lines` of up to 500, no `quote`; `actionRoute` only `settings.show` (the plugin's own group), `content.edit` (an item ID), or `plugins.area.show` (its own code, D-T5); anything else throws.
  - At most 100 deliveries per plugin per hour (`NoticeRate`, counted per recipient); beyond it they are dropped and one fault an hour is recorded (`{name}#notices`).
  - `sendToAddress()` always throws for a plugin: `A plugin sends notices only to people with an account.`
  - Delivered after commit, as `NoticeSender` already does.

### The delicate code: refusing a disable that others need

```php
protected function validate(OperationInput $input): void
{
    /** @var DisablePluginInput $input */
    // Inside the transaction, under the plugins rows' lock, so an enable racing this disable is seen (Task 0: the lock EnablePlugin takes).
    $dependents = $this->dependencies->enabledDependents($input->name);   // names of enabled plugins whose requires.plugins lists it

    if ($dependents !== []) {
        $names = Lists::and(array_map(fn (string $name): string => $this->records->label($name), $dependents));

        throw ValidationException::withMessages(['plugin' => __(':dependents needs it. Disable :dependents first.', ['dependents' => $names])]);
    }
}
```

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; where the loader reads a plugin's autoloaded namespace; how `OwnedHooks` checks a declared short name; the lock `EnablePlugin` and `DisablePlugin` take; where an update's manifest is validated and what state a fault puts a plugin in; the Account › Notifications page file; whether round 2's `sockets2/plugin-area` is on `main` (D-T5).
1. **Declaring and sending hooks.** `PluginHooksTest` with `relay` and `relay-pro`: `relay-pro` hears each verb; a `Collect` returns both lists; a `Transform`'s listener changes the value; a `Notification` sent inside an Operation arrives after commit and not on rollback; a listener's fault is recorded under `relay-pro` and the rest go on; sending an undeclared class is refused; every refusal in D1, one sentence each; a hook needing `content.read` is refused to a listener without it. Red.
2. **Dependencies.** `PluginDependenciesTest`: enabling `relay-pro` before `relay`, or with `relay` at a version outside the constraint, is refused and writes nothing; disabling `relay` while `relay-pro` is enabled is refused and writes nothing; after disabling `relay-pro`, `relay` disables; listening without requiring is refused; an update of `relay` outside the constraint puts `relay-pro` in its fault state; `teme:plugins` shows Requires and Required by. Red.
3. **Notices registered.** `PluginNoticeKindsTest`: an enabled plugin's kinds are in the registry with the stemmed key, listed for a person with the audience, not listed when the plugin is disabled; the manifest refusals (more than 5, a bad id, an unknown audience, a default not among channels, no `notices.send`); the consent line. Red.
4. **Sending.** `PluginNoticesTest`: a job running as the person who started it sends `job_failed` to them by inbox and mail (`Notification::fake()`), after commit and not on rollback; `queue_stalled` reaches holders of `plugins.manage` only; refused: an undeclared kind, `starter` to someone else, a quote, an action route not allowed, `sendToAddress`; the 101st in an hour is dropped and one fault recorded. Red.
5. **Permission audience and area link** (after round 2's `sockets2/plugin-area` is merged; rebase first). An audience naming the plugin's own permission reaches its holders only; `plugins.area.show` with the plugin's own code is accepted, another code refused. Red.
6. **The screen.** Only after the maintainers approve the drawing (proposal, screen 5); until then, STOP before this task. Account › Notifications: a plugin's kinds under its name with `PluginMark`. `PluginNoticeChoicesTest` (browser, 1280 and 375): turn one channel off and save; axe in light and dark.
7. **Handbook and records.** `plugins/your-own-hooks.md` (declaring, sending, listening from another plugin, `requires.plugins`, a free and paid add-on example); `plugins/notices.md` (kinds, audiences, the door, limits); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Hooks`, `tests/Feature/Plugins/Notices`, `tests/Feature/Plugins`, `tests/Feature/Hooks`, `tests/Feature/Notices` and `tests/Unit/Contracts`. Once at the end: `composer check`, and `composer check:browser` for `PluginNoticeChoicesTest` and Account › Notifications' existing browser test.

### Records (the builder's)

#### Setup

- `cat /tmp/teme-cloud-setup.log`: "2026-11-16T10:34:06Z ran in /home/user/teme as root", "2026-11-16T10:48:44Z finished". `pwd`: `/home/user/teme`. PHP 8.4.27; PostgreSQL on 5433 and Valkey on 6380 answered.
- Before the first task, `composer check` on `origin/main` (`11813b3`): Pint and PHPStan clean; Architecture 139 passed (1384 assertions); Unit and Feature 11875 passed, 2 skipped (61099 assertions), 1973 s.

#### Commits on `sockets3/hooks-notices`

- `38bb2d6` feat(plugins): plugins declare hooks, require other plugins and send notices (Tasks 1 to 4; see B-?? on one commit)
- `599bb8f` feat(site): plugins set attributes on the links an article draws (D-L1)
- `965b4e7` docs(guides): your own hooks, and notices from plugins (Task 7)
- this commit: docs(plans): Part D's records

#### Tests seen red (first line of the failure)

Each run with `app/` and `packages/` stashed, the tests and fixtures in place.

| Task | File | First failure |
|---|---|---|
| 1 | `tests/Feature/Plugins/Hooks/PluginHooksTest.php` | Class or interface "Tests\Fixtures\Plugins\Relay\RelayPlugin" does not exist (no `HookSender` door, so Relay never starts) |
| 2 | `tests/Feature/Plugins/Hooks/PluginDependenciesTest.php` | Expected: requires may name only teme: a plugin can’t depend on another plugin yet. To contain: It needs tests/relay ^1.0 enabled first. |
| 3 | `tests/Feature/Plugins/Notices/PluginNoticeKindsTest.php` | Failed asserting that null is identical to 'Email me when an article I started fails'. |
| 4 | `tests/Feature/Plugins/Notices/PluginNoticesTest.php` | Class or interface "Tests\Fixtures\Plugins\Relay\RelayPlugin" does not exist (no `Notices` door) |
| D-L1 | `tests/Feature/Plugins/Hooks/ArticleLinksTest.php` | content.links isn’t something a plugin can ask for. |

#### Task 0

- V = 0.9.0: `git tag -l 'v0.9.0*'` lists nothing.
- `git log --oneline -1`: `11813b3 Merge pull request #352 from temecms/docs/plan-sockets-3`.
- The loader reads a plugin's namespace from its own `composer.json` psr-4 (`PluginLoader::autoload()`); `namespaces()` now reads the same, falling back to Composer's installed entry.
- `OwnedHooks::on()` checked a declared hook by its short name (`HookDispatcher::shortName()`) against the manifest's `hooks` with `in_array`; a plugin's own hook is now named `{code}:{name}` there.
- `EnablePlugin` takes its own row (`insertOrIgnore`, then `lockForUpdate`); `DisablePlugin` locks its row in `perform()`. Enabling now also locks the required plugins' rows, and disabling re-reads its dependents under its lock.
- An update's manifest is validated on every read (`PluginRegistry`, `ManifestValidator::liveErrors()`); a fault puts the plugin in `CantBeUsed`.
- Account › Notifications is `resources/js/pages/account/notifications.tsx`.
- `sockets2/plugin-area` is not on `main` (checked at the start and again before these records).

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | 222 | 222 |
| admin-words-baseline.txt | 876 | 876 |

#### Gate

- Quick check after the tasks: Pint, PHPStan, `types:check`, `npm run check` clean; Architecture 139 passed; `tests/Feature/Plugins` 657 passed; `tests/Feature/Hooks` 78 passed; `tests/Feature/Notices` 100 passed, 1 skipped; `tests/Unit/Contracts` 136 passed; also `tests/Feature/Feeds` 93, `tests/Feature/Site` 283, `tests/Feature/Api` 334, `tests/Feature/Guides` 514 passed.
- End of part, `composer check`: Pint, PHPStan, `types:check`, `npm run check`, `build:themes` clean; Architecture 139 passed (1384 assertions); Unit and Feature 11945 passed, 2 skipped, 2 failed (61327 assertions). The two failures are `GenerateManifestReferenceTest` and `GenerateHooksReferenceTest`, the committed references this plan says not to regenerate (see B-?? below); nothing else failed.
- Browser, one file at a time after `npm run build`: `Account/PluginNoticeChoicesTest` 4 passed, `AccountNotificationsTest` 3 passed, `InboxScreenTest` 4 passed. The quick check after Task 6: Architecture 139; `Feature/Plugins` 664, `Notices` 100 (1 skipped), `Inbox` 23, `Admin` 910, `Comments` 411, `Fediverse` 175, `Jobs` 81, `Guides` 514, `Languages` 373 passed; baselines unchanged.

#### B-?? (draft)

- **The requires message.** `requires` now takes `plugins`, so "requires may name only teme: a plugin can’t depend on another plugin yet." became false. Options: keep it, or say what is true. Chose `requires may name only teme and plugins.`, and `ManifestTest`'s row says the same; its meaning (an unknown key under `requires` is refused) is unchanged. `38bb2d6`.
- **The fault state of an unmet dependency.** The plan names "its fault state". Options: `Waiting` (offers Migrate on the Plugins screen, which is wrong and not owned) or `CantBeUsed`, computed live as `requires.teme` is. Chose `CantBeUsed`, with the reason on the row. `38bb2d6`.
- **When a plugin's notification hook arrives.** Core's notifications wait in `PendingNotifications`. A plugin's go through `DB::afterCommit()`, which has the same meaning (after the outermost commit, never after a rollback) without a second queue. `38bb2d6`.
- **A hook class without `#[HookContract]`.** Refused with the same sentence as a class that is no hook, since every Teme hook carries one. `38bb2d6`.
- **Registering kinds and listing plugin hooks read no rows with no plugin installed.** `DeclaredNotices::register()` and `PluginHookCatalogue::all()` return early unless `PluginRegistry::anyInstalled()`, as the loader does; otherwise the cached enabled rows were primed at boot and existing loader tests saw none. `38bb2d6`.
- **`OwnedHooks` names hooks for plugins only.** Core owners keep the short name; only a plugin's subscription asks the catalogue for `{code}:{name}`, so a core listener never reads plugin rows. A first version refused any non-contracts class for plugins; it broke `SubscriptionTest` and `JoinTest`, whose test hooks live under `Tests\`, so it was dropped. `38bb2d6`.
- **`teme:plugins` columns.** Requires and Required by come after Why, so `PluginCommandsTest`'s patterns still match unchanged. `38bb2d6`.
- **Counts that grow with this part.** `HookContractsTest` counted 18 hooks; ArticleLinks makes 19. `ContributionsSchemaTest` lists the `contributes` keys; `hooks` and `notices` join them. Both keep their meaning (every hook listed once; the schema states what the validator takes). `38bb2d6`.
- **The committed references drift, by the plan's rule.** `GenerateHooksReferenceTest` and `GenerateManifestReferenceTest` compare `docs/reference/hooks.md` and `plugin-manifest.md` with the generators; the plan says not to regenerate them and that the join does. They fail on this branch only for this part's additions (ArticleLinks; `requires.plugins`, `contributes.hooks`, `contributes.notices`, the two capabilities). Not regenerated.
- **Tasks 1 to 4 in one commit.** The four tasks share `ManifestValidator`, `PluginDoors`, the schema and `Capability`; staging them task by task would have meant commits that don't build. One commit, with the tasks named in its body.
- **`PluginNoticesTest` builds fresh registries after enabling.** `NoticeRegistry` is scoped and `PreferenceRegistry` a singleton, so a plugin enabled mid-request shows its kinds from the next request, as `PluginNoticeKindsTest`'s helper already assumes. The test forgets both before loading, as a new request would.
- **D-L1: what a listener gets and changes.** `ArticleLinks` hands each link once (absolute address, host, outside or not, its words, at most 200 characters) and takes `LinkAttributes` by place. A value outside the list throws in `LinkAttributes`' constructor, so the listener fails, is recorded under its plugin and skipped, and the links stay as they were. A link to the site is changed only when the plugin names its host. `599bb8f`.
- **D-L1: classes.** Themes have no place yet to declare link classes, so every class a plugin asks for is left off; `ArticleLinkAttributes::themeClasses()` is the one place to read them when a theme can. `599bb8f`.
- **D-L1: cache and outputs.** The page is tagged `insertions` before any plugin is asked, and enabling or disabling a plugin with `content.links` purges those pages, as `content.insert` does. Feeds and the API draw bodies through `FeedBody`, which asks the same hook. Previews get the attributes too, since the hook is asked "per drawn article". `599bb8f`.
- **Comments.** Early drafts cited D-numbers; every comment now cites only the plan's path.
- **Plugin groups beside core's rows, not inside them.** `NoticeChoices::forScreen()` keeps its rows' shape, which `AccountNotificationsTest` and `CommentEmailPreferencesTest` pin; a new `grouped()` splits them, and the page gets core's under `notices` and the plugins' under `pluginNotices`. `FormPanel`'s title takes only words, so a plugin group's heading (its name and plug) is drawn inside the panel instead of changing the shared pattern.
- **An inbox item's plugin.** `InboxChannel` files a plugin's kind under the plugin's code (the inbox's `owner`), and `InboxFor` gives each row `plugin`, the plugin's label, or null for core. A code starting with a digit, which the inbox's owner rule refuses, is filed under core rather than failing the delivery.

#### Contract changes

- New, `@since 0.9.0`: `Teme\Contracts\Hooks\HookSender`; `Teme\Contracts\Content\ArticleLinks`, `LinkAttributes`, `OutboundLink`; `Capability::NoticesSend` (`notices.send`) and `Capability::ContentLinks` (`content.links`).
- Schema: `requires.plugins`, `hooks` items may be `{code}:{Name}`, `contributes.hooks`, `contributes.notices`, the two capabilities.

#### STOP

- Task 5 (permission audience and area link) waits: `sockets2/plugin-area` is not on `main`.
- Screens built from the approved drawings, with the two rules. The maintainers approved five round 3 drawings on 2026-11-16; this part's one among them is the refusal when disabling a plugin others need. It needs no new screen code: the Plugins page shows the refusal as its error toast, naming the dependents once ("Relay Pro needs it. Disable Relay Pro first."), and no heading repeats. `PluginDependenciesTest` now proves that through the page's own route; it passed at once, since Task 2 already sends the refusal there.
- Screens 2 and 5 built from the approved drawings, with the two rules (approved later on 2026-11-16; screen 2 is Part A's). Task 6, screen 5: Account › Notifications lists each enabled plugin's kinds after core's, in a group headed once by the plugin's own name with the plug mark, with the same switches; a disabled plugin's group goes and its stored choices stay. An inbox item a plugin sent shows "From <plugin's name>" with the plug mark and opens the admin link the plugin gave. Tests: `tests/Feature/Plugins/Notices/PluginNoticeScreenTest.php` (seen red: "Property [notices] was marked as invalid using a closure." with plugin rows mixed into core's, and no `pluginNotices`), `tests/Browser/Account/PluginNoticeChoicesTest.php` (1280 and 375, axe light and dark, 4 passed); `AccountNotificationsTest` and `InboxScreenTest` (browser) still pass. `NoticeChoices` (the plugin's label beside its kinds) waits with it, since its shape follows the drawing.
- Left open for the screens: the Plugins screen's consent lines for notice kinds, required plugins and declared hooks are in `PluginWords`, `EnablePluginCommand` and `PluginRow::$requiredBy`, but `PluginsScreen` and `plugins/index.tsx` are not this part's files.

#### Changelog lines for the maintainers

- Plugins can declare hooks of their own for other plugins, and send them through `HookSender`.
- A plugin can require other plugins under `requires.plugins`; Teme enables, disables and starts them in order, and `teme:plugins` shows Requires and Required by.
- Plugins with `notices.send` declare kinds of notice and send them by email or inbox, to the person their work acts for or to plugin managers, at most 100 an hour.
- Plugins with `content.links` can mark the links in articles nofollow, sponsored or ugc and open them in a new tab, on the site, in feeds and in the API.

#### Try it

- `php artisan test --parallel --compact tests/Feature/Plugins/Hooks`, then the same for `tests/Feature/Plugins/Notices`.
- With the fixtures in `teme.plugin_paths`: enable `tests/relay-pro` before `tests/relay` and read the refusal; enable both and run `php artisan teme:plugins`; try `teme:plugins:disable tests/relay`.
- Enable `tests/linker`, publish an article with an outside link, and see `rel="noopener nofollow"` and `target="_blank"` on the page and in `/feed/`.

---

### Added by the maintainers: outbound links on the public site (for a link-management plugin)

- [ ] **D-L1. A plugin may set attributes on the links an article draws.** A new transform hook, asked once per drawn article with the article's outbound links (absolute address, host, whether it points outside the site, the link text), returns per link only: `rel` values from a closed list (`nofollow`, `sponsored`, `ugc`, `noopener`, `noreferrer`), whether it opens in a new tab, and at most two class names from the theme's declared link classes. Core writes the attributes; a plugin never writes HTML. Capability `content.links` (consent: "Change how links in your articles behave: nofollow, new tab"). Same budget and fault rules as insertion points; the result is part of the cached page and purged with it; feeds and the API get the same attributes. Tests: a rule marks outside links nofollow and new tab; a value outside the list is ignored and recorded; a throwing plugin leaves links as they were; internal links are never touched unless the plugin asks for them by host.

## What must not be done (every part)

- Hand a plugin a provider key, the container, a model, a query builder on Teme's tables, or a framework type; let a plugin read core's `ai` group's secrets.
- Store a prompt or an answer from a plugin's AI request; record a request that was refused before sending.
- Let a plugin write, read a draft, ask AI or send a notice as the operator or with elevation, or for anyone but the person its work acts for.
- Run a plugin's JavaScript or CSS in the editor, or accept markup from a plugin: panel status, suggestions and notice lines are plain values drawn by core.
- Store an absolute site address: links from suggestions are `teme:content/{id}` (rule 9).
- Add a singleton, a dependency, a core permission or an `.ai/` edit; widen an Architecture allow-list; raise a baseline.
- Change `composer.json`, `composer.lock` or `package.json`; regenerate `docs/reference/*.md`.
- Edit another part's, round 1's or round 2's lines in a shared file.

## STOP conditions

Stop, write why under that part's Records, commit and push, then stop, when: a Task 0 line differs in a way that changes a decision; a branch this part or task waits for isn't on `main`; a contract named here doesn't fit the code; making it work needs a singleton, a dependency, an `.ai/` edit or a wider allow-list; the words people read would have to change; a screen task (A-T6, B-T5, D-T6) is reached before its drawing is approved.

## Joining

Merge A, B, C and D into one join branch in any order; keep one copy of `PluginKeys`; order the trailing parameters of `PluginRow` (A's `ai`, then D's `requiredBy`) and of `PluginDoors::for()` (A's `long`, then C's answer size); join the schema's, validators' and consent words' additions; regenerate `docs/reference/plugin-manifest.md` and `docs/reference/hooks.md`; run the Architecture suite and `tests/Feature/Plugins`, `tests/Feature/Ai`, `tests/Unit/Contracts`; let GitHub run the rest. Writura then moves onto the sockets in its own part (not this plan): its article, titles and image-brief calls through `SiteAi` (its own Anthropic key and Message Batches removed), an editor panel "Write this article" and "Suggest titles" on drafts in place of the `writura:` title trigger, its link pool from `ContentReader::published()` (the whole archive, not only items published after enabling), slugs, credits and linked language versions through `NewItem`, `JobContext::personId()` in place of its "Write as" setting, notices for a failed job and a stalled queue, and `writura:PromptModules` with its other filters as declared hooks for a paid add-on.
