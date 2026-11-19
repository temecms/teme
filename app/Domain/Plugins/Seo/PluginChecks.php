<?php

/**
 * Asks plugins' editor checks about one version of an item
 * (docs/guides/developers/plugins/seo.md): each enabled plugin granted
 * content.read that declares checks for the item's type, within 250 ms,
 * reading only. A plugin that throws, runs over or answers wrongly is
 * recorded as a fault and its group says it couldn't check this time;
 * nothing it does holds up typing or saving, which never wait for this.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Cards\PlainText;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Columns\CellText;
use App\Domain\Plugins\ManifestText;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Editor\CheckedItem;
use Teme\Contracts\Editor\CheckResult;
use Teme\Contracts\Editor\HasEditorChecks;
use Teme\Contracts\Plugins\Capability;

final readonly class PluginChecks
{
    public const int CALL_BUDGET_MS = 250;

    /** The most results one plugin's group shows. */
    public const int MOST_RESULTS = 40;

    public function __construct(
        private SeoPlugins $plugins,
        private FieldRegistry $fields,
        private SiteLanguages $languages,
    ) {}

    /**
     * Each plugin's checks on this revision of the item, under its name, in
     * the order the plugins were enabled; null when the viewer may not edit
     * the item or the revision isn't one of its own.
     *
     * @return list<array{code: string, plugin: string, checks: list<array{id: string, label: string, state: string, message: string, field: string|null, ownField: string|null}>, failed: string|null}>|null
     */
    public function for(string $contentId, User $viewer, string $revisionId): ?array
    {
        $content = Content::query()->find($contentId);

        if ($content === null || ! Gate::forUser($viewer)->allows('update', $content)) {
            return null;
        }

        $revision = ContentRevision::query()->where('content_id', $content->id)->whereKey($revisionId)->first();

        if ($revision === null) {
            return null;
        }

        $groups = [];
        $item = null;

        foreach ($this->plugins->all() as $plugin) {
            $declared = array_values(array_filter(SeoDeclarations::checks($plugin->manifest), fn (array $check): bool => in_array($content->type, $check['types'], true)));

            if ($declared === [] || ! $plugin->grants(Capability::ContentRead->value)) {
                continue;
            }

            $item ??= $this->item($content, $revision);
            $groups[] = $this->group($plugin, $declared, $item($plugin, (string) $viewer->getAuthIdentifier()));
        }

        return $groups;
    }

    /**
     * One plugin's group: its checked results, or the line saying it
     * couldn't check this time.
     *
     * @param  list<array{id: string, label: string, types: list<string>}>  $declared  its checks for this type
     *
     * @return array{code: string, plugin: string, checks: list<array{id: string, label: string, state: string, message: string, field: string|null, ownField: string|null}>, failed: string|null}
     */
    private function group(SeoPlugin $plugin, array $declared, CheckedItem $item): array
    {
        $failed = ['code' => $plugin->code, 'plugin' => $plugin->label, 'checks' => [], 'failed' => __('These checks couldn’t run this time.')];
        $running = $plugin->running;

        if (! $running instanceof HasEditorChecks) {
            $this->plugins->fault($plugin, 'editor-checks', HasEditorChecks::class, new SocketAnswerRefused("{$plugin->name} has no editor checks while it runs."));

            return $failed;
        }

        $answer = $this->plugins->ask($plugin, 'editor-checks', HasEditorChecks::class, self::CALL_BUDGET_MS, fn (): array => $running->checkItem($item));

        if (! is_array($answer)) {
            return $failed;
        }

        $labels = array_column($declared, 'label', 'id');
        $words = ManifestText::for($plugin->code);
        $locale = app()->getLocale();
        $own = array_column(SeoDeclarations::section($plugin->manifest)['fields'] ?? [], null, 'key');
        $checks = [];

        foreach (array_slice($answer, 0, self::MOST_RESULTS) as $result) {
            if (! $result instanceof CheckResult || ! isset($labels[$result->check])) {
                $this->plugins->fault($plugin, 'editor-checks', HasEditorChecks::class, new SocketAnswerRefused($result instanceof CheckResult ? "A result names the check [{$result->check}], which isn't declared for this type." : 'A result isn’t a CheckResult.'));

                continue;
            }

            $checks[] = [
                'id' => $result->check,
                'label' => (string) CellText::clean($result->label ?? $words->text($labels[$result->check], $locale), 60),
                'state' => $result->state,
                'message' => (string) CellText::clean($result->message, CheckResult::MOST_MESSAGE),
                'field' => $result->field,
                'ownField' => $result->ownField !== null && isset($own[$result->ownField]) ? $result->ownField : null,
            ];
        }

        return ['code' => $plugin->code, 'plugin' => $plugin->label, 'checks' => $checks, 'failed' => null];
    }

    /**
     * What every plugin is told about this version of the item; read once,
     * then given each plugin's own values and permissions.
     *
     * @return Closure(SeoPlugin, string): CheckedItem
     */
    private function item(Content $content, ContentRevision $revision): Closure
    {
        $stored = (array) $revision->fields;
        $document = (array) $revision->document;
        $fields = [];

        foreach ($this->fields->forType($content->type) as $field) {
            if (array_key_exists($field->key, $stored)) {
                $fields[$field->handle] = $stored[$field->key];
            }
        }

        $terms = [];

        foreach (Term::query()->whereIn('id', array_map('strval', (array) $revision->terms))->orderBy('name')->get(['taxonomy', 'name']) as $term) {
            $terms[$term->taxonomy][] = $term->name;
        }

        $text = PlainText::of($document);
        $language = $content->language ?? $this->languages->defaultTag();

        return fn (SeoPlugin $plugin, string $personId): CheckedItem => new CheckedItem(
            itemId: $content->id,
            revisionId: $revision->id,
            type: $content->type,
            language: $language,
            title: (string) $revision->title,
            slug: (string) $content->slug,
            document: $document,
            text: $text,
            fields: $fields,
            terms: $terms,
            own: PluginSearchFields::valuesOf($plugin->code, $stored),
            personId: $personId,
            locale: app()->getLocale(),
            permissions: AreaContexts::for($plugin->code, $personId)->permissions,
        );
    }
}
