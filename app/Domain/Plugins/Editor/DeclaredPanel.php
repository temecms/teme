<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\PluginWords;
use App\Domain\Types\ContentTypeRegistry;

/**
 * One editor panel as a plugin's manifest declares it: its id, words,
 * icon, the content types it shows on, the permission it may need and its
 * actions, each with its job and form fields. Read from the manifest each
 * time; the validator refused anything malformed first.
 */
final readonly class DeclaredPanel
{
    /**
     * @param  list<string>  $types
     * @param  list<array{id: string, label: string, job: string, confirm: string|null, fields: list<array<string, mixed>>}>  $actions
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $icon,
        public array $types,
        public ?string $permission,
        public array $actions,
    ) {}

    /**
     * The panels a manifest declares, in declared order; an entry that
     * isn't well formed is left out.
     *
     * @return list<self>
     */
    public static function all(Manifest $manifest): array
    {
        $entries = $manifest->contributes()['editorPanels'] ?? null;
        $panels = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['label'] ?? null)) {
                continue;
            }

            $actions = [];

            foreach (is_array($entry['actions'] ?? null) ? $entry['actions'] : [] as $action) {
                if (! is_array($action) || ! is_string($action['id'] ?? null) || ! is_string($action['label'] ?? null) || ! is_string($action['job'] ?? null)) {
                    continue;
                }

                $actions[] = [
                    'id' => $action['id'],
                    'label' => $action['label'],
                    'job' => $action['job'],
                    'confirm' => is_string($action['confirm'] ?? null) ? $action['confirm'] : null,
                    'fields' => array_values(array_filter(is_array($action['fields'] ?? null) ? $action['fields'] : [], is_array(...))),
                ];
            }

            $panels[] = new self(
                id: $entry['id'],
                label: $entry['label'],
                description: is_string($entry['description'] ?? null) ? $entry['description'] : '',
                icon: is_string($entry['icon'] ?? null) ? $entry['icon'] : 'plug',
                types: array_values(array_filter(is_array($entry['types'] ?? null) ? $entry['types'] : [], is_string(...))),
                permission: is_string($entry['permission'] ?? null) ? $entry['permission'] : null,
                actions: $actions,
            );
        }

        return $panels;
    }

    /**
     * The panel this manifest declares with this id, or null.
     */
    public static function find(Manifest $manifest, string $id): ?self
    {
        foreach (self::all($manifest) as $panel) {
            if ($panel->id === $id) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * The consent's line for each panel the manifest declares, its label in
     * the admin's language and its types by their plural names.
     *
     * @return list<string>
     */
    public static function consentWords(Manifest $manifest): array
    {
        $types = app(ContentTypeRegistry::class);
        $words = ManifestText::for($manifest->code());
        $locale = app()->getLocale();

        return array_map(fn (self $panel): string => PluginWords::editorPanel(
            $words->text($panel->label, $locale),
            array_map(fn (string $type): string => $types->has($type) ? __($types->get($type)->plural) : $type, $panel->types),
        ), self::all($manifest));
    }

    /**
     * The declared action with this id, or null.
     *
     * @return array{id: string, label: string, job: string, confirm: string|null, fields: list<array<string, mixed>>}|null
     */
    public function action(string $id): ?array
    {
        foreach ($this->actions as $action) {
            if ($action['id'] === $id) {
                return $action;
            }
        }

        return null;
    }

    /**
     * The tool's id in the editor's bar and in the pinned-tools preference.
     */
    public function toolId(string $code): string
    {
        return "plugin.{$code}.{$this->id}";
    }
}
