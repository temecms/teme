<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Contents\Models\Content;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\HasEditorPanels;
use Throwable;

/**
 * Which plugins' editor panels a person sees (docs/proposals/plugin-sockets-3.md,
 * B2): those of enabled plugins granted content.read and jobs, on an item of
 * a type the panel names, for a person who may update the item and holds the
 * panel's permission when it names one. Read from the records and manifests
 * each time; holds nothing itself.
 */
final readonly class EditorPanels
{
    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
        private LoadedPlugins $loaded,
        private Actors $actors,
    ) {}

    /**
     * The panels this person sees on this item, in the order the plugins
     * were enabled, then declared order; none on an item they can't update.
     *
     * @return list<PanelPlugin>
     */
    public function on(Content $content, string $personId): array
    {
        $person = $this->actors->find($personId);

        if ($person === null || ! Gate::forUser($person)->allows('update', $content)) {
            return [];
        }

        return array_values(array_filter(
            $this->all(),
            fn (PanelPlugin $panel): bool => in_array($content->type, $panel->panel->types, true) && $this->holds($person, $panel),
        ));
    }

    /**
     * The panels this person sees on this item, as the editor's bar draws
     * them: the tool's id, the plugin's code and label, and the panel's
     * words, icon and actions with their forms.
     *
     * @return list<array{tool: string, code: string, id: string, plugin: string, label: string, description: string, icon: string, actions: list<array<string, mixed>>}>
     */
    public function toolsOn(string $contentId, string $personId): array
    {
        $content = Content::query()->find($contentId);

        return $content === null ? [] : array_map(fn (PanelPlugin $panel): array => [
            'tool' => $panel->toolId(),
            'code' => $panel->code,
            'id' => $panel->panel->id,
            'plugin' => $panel->plugin,
            'label' => $panel->panel->label,
            'description' => $panel->panel->description,
            'icon' => $panel->panel->icon,
            'actions' => array_map(fn (array $action): array => [
                'id' => $action['id'],
                'label' => $action['label'],
                'confirm' => $action['confirm'],
                'fields' => array_map(fn (array $field): array => [
                    'key' => (string) ($field['key'] ?? ''),
                    'type' => (string) ($field['type'] ?? 'text'),
                    'label' => (string) ($field['label'] ?? ''),
                    'required' => ($field['required'] ?? false) === true,
                    'default' => $field['default'] ?? null,
                    'maxLength' => $field['maxLength'] ?? (PanelValidator::FIELD_TYPES[$field['type'] ?? ''] ?? null),
                    'options' => is_array($field['options'] ?? null) ? array_map(fn (int|string $value, mixed $label): array => ['value' => (string) $value, 'label' => (string) $label], array_keys($field['options']), $field['options']) : [],
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null,
                ], $action['fields']),
            ], $panel->panel->actions),
        ], $this->on($content, $personId));
    }

    /**
     * The one panel named, as this person sees it on this item, or null.
     */
    public function find(Content $content, string $personId, string $code, string $panel): ?PanelPlugin
    {
        foreach ($this->on($content, $personId) as $found) {
            if ($found->code === $code && $found->panel->id === $panel) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The tool ids of every panel this person may see on some item: what
     * the pinned tools may hold beside Teme's own.
     *
     * @return list<string>
     */
    public function toolIdsFor(?string $personId): array
    {
        $person = $this->actors->find($personId);
        $types = app(ContentTypeRegistry::class);

        if ($person === null) {
            return [];
        }

        $ids = [];

        foreach ($this->all() as $panel) {
            $editable = array_filter($panel->panel->types, fn (string $type): bool => $types->has($type) && ($person->can($types->ability($type, 'edit.any')) || $person->can($types->ability($type, 'edit.own'))));

            if ($editable !== [] && $this->holds($person, $panel)) {
                $ids[] = $panel->toolId();
            }
        }

        return $ids;
    }

    /**
     * Every panel of every enabled plugin granted what panels need, its
     * words in the admin's language.
     *
     * @return list<PanelPlugin>
     */
    public function all(): array
    {
        $records = $this->records->enabled();
        usort($records, fn ($a, $b): int => [$a->enabled_at?->getTimestamp() ?? 0, $a->name] <=> [$b->enabled_at?->getTimestamp() ?? 0, $b->name]);
        $locale = app()->getLocale();
        $panels = [];

        foreach ($records as $record) {
            $granted = (array) ($record->consent['capabilities'] ?? []);

            foreach (PanelValidator::NEEDS as $needed) {
                if (! in_array($needed->value, $granted, true)) {
                    continue 2;
                }
            }

            $found = $this->registry->find($record->name);
            $manifest = $found instanceof FoundPlugin ? $found->manifest : null;

            if ($manifest === null) {
                continue;
            }

            $declared = DeclaredPanel::all($manifest);

            if ($declared === []) {
                continue;
            }

            $words = ManifestText::for($record->code);
            $given = $this->given($record->name);

            foreach ($declared as $panel) {
                $panels[] = new PanelPlugin(
                    name: $record->name,
                    code: $record->code,
                    plugin: $words->text($manifest->label(), $locale),
                    panel: new DeclaredPanel(
                        id: $panel->id,
                        label: $words->text($panel->label, $locale),
                        description: $words->text($panel->description, $locale),
                        icon: $panel->icon,
                        types: $panel->types,
                        permission: $panel->permission,
                        actions: array_map(fn (array $action): array => [
                            ...$action,
                            'label' => $words->text($action['label'], $locale),
                            'confirm' => $action['confirm'] === null ? null : $words->text($action['confirm'], $locale),
                            'fields' => array_map(fn (array $field): array => $this->translated($field, $words, $locale), $action['fields']),
                        ], $panel->actions),
                    ),
                    data: ($given[$panel->id] ?? null) instanceof EditorPanel ? $given[$panel->id] : null,
                );
            }
        }

        return $panels;
    }

    /**
     * The panels the running plugin hands over; none when it isn't running
     * here or can't hand them over.
     *
     * @return array<array-key, mixed>
     */
    private function given(string $name): array
    {
        $running = $this->loaded->running()[$name] ?? null;

        if (! $running instanceof HasEditorPanels) {
            return [];
        }

        try {
            return $running->editorPanels();
        } catch (Throwable) {
            return [];
        }
    }

    private function holds(User $person, PanelPlugin $panel): bool
    {
        $ability = $panel->ability();

        return $ability === null || $person->can($ability);
    }

    /**
     * A field's label and its options' labels in the admin's language.
     *
     * @param  array<string, mixed>  $field
     *
     * @return array<string, mixed>
     */
    private function translated(array $field, ManifestText $words, string $locale): array
    {
        if (is_string($field['label'] ?? null)) {
            $field['label'] = $words->text($field['label'], $locale);
        }

        if (is_array($field['options'] ?? null)) {
            $field['options'] = array_map(fn (mixed $label): mixed => is_string($label) ? $words->text($label, $locale) : $label, $field['options']);
        }

        return $field;
    }
}
