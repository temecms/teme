<?php

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

use App\Domain\Cards\CardMedia;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Queries\AuthorOptions;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingType;

/**
 * Everything the generated settings page needs, and nothing it must not have:
 * secret values never leave the server — only whether each is set.
 */
final class SettingsForm
{
    /** The most terms a term setting offers. */
    private const int MOST_TERMS = 500;

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsReader $settings,
        private readonly SecretStore $secrets,
        private readonly CardMedia $media,
        private readonly AuthorOptions $authors,
        private readonly ContentTypeRegistry $types,
    ) {}

    /**
     * One settings group as its page needs it: the group, its fields, the
     * current values, whether each secret is set, each media setting's chosen
     * image, and the version to save against. Null for a group that does not exist. `screen` is the route
     * of the group's own screen, for a group the generic form never edits.
     * `media` is each media setting's chosen image, as the editor's cards get
     * it, or null when none is chosen or it is gone. A person setting's
     * field lists `people`, `list<array{value: string, label: string}>`:
     * who may write, by name, and the chosen person while they have an
     * account. A list, not a map, so the browser keeps its order. Each
     * field carries its `visibleWhen`: the other settings' values it shows for.
     * A plugin's group carries its words in the person's language.
     *
     * @return array{
     *     group: array{key: string, label: string, description: string, sensitive: bool},
     *     ability: string,
     *     screen: string|null,
     *     fields: list<array<string, mixed>>,
     *     values: array<string, mixed>,
     *     secrets: array<string, array{isSet: bool, source: 'database'|'environment'|null}>,
     *     media: array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int}|null>,
     *     version: int
     * }|null
     */
    public function find(string $group): ?array
    {
        $definition = $this->registry->find($group);

        if ($definition === null) {
            return null;
        }

        $secrets = [];

        foreach ($definition->settings as $setting) {
            if ($setting->type === SettingType::Secret) {
                $secrets[$setting->key] = $this->secrets->status($group, $setting->key);
            }
        }

        $values = $this->settings->group($group);
        $chosen = [];

        foreach ($definition->settings as $setting) {
            if ($setting->type === SettingType::Media) {
                $chosen[$setting->key] = (string) ($values[$setting->key] ?? '');
            }
        }

        $images = $this->media->forIds(array_values($chosen));
        $media = array_map(fn (string $id): ?array => $images[$id] ?? null, $chosen);

        $form = [
            'group' => [
                'key' => $definition->key,
                'label' => $definition->label,
                'description' => $definition->description,
                'sensitive' => $definition->sensitive,
            ],
            'ability' => $definition->ability,
            'screen' => $definition->screen,
            'fields' => $this->withPeople(array_map($this->field(...), $definition->settings), $values),
            'values' => $values,
            'secrets' => $secrets,
            'media' => $media,
            'version' => (int) (SettingsGroupRecord::query()->whereKey($group)->value('version') ?? 0),
        ];

        // A plugin's words in the person's language, from its own lang folder (docs/plans/plugin-sockets-2.md).
        return str_starts_with($group, 'plugin.') ? DeclaredSettings::translated($form, app()->getLocale()) : $form;
    }

    /**
     * Each person setting's field with the people it offers: who may write,
     * by name, then the chosen person when they have an account but aren't
     * among them (suspended, or no longer writing). Who may write is read
     * once a form, and only when the group has a person setting.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     *
     * @return list<array<string, mixed>>
     */
    private function withPeople(array $fields, array $values): array
    {
        $writers = null;

        foreach ($fields as $index => $field) {
            if ($field['type'] !== SettingType::Person->value) {
                continue;
            }

            $writers ??= array_map(fn (array $author): array => ['value' => (string) $author['value'], 'label' => $author['label']], $this->authors->all());
            $people = $writers;
            $chosen = $values[$field['key']] ?? '';

            if (is_string($chosen) && preg_match('/^[1-9][0-9]{0,18}$/', $chosen) === 1 && ! in_array($chosen, array_column($people, 'value'), true)) {
                $name = DB::table('users')->where('id', (int) $chosen)->value('name');

                if (is_string($name)) {
                    $people[] = ['value' => $chosen, 'label' => $name];
                }
            }

            $fields[$index]['people'] = $people;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(Setting $setting): array
    {
        $options = match ($setting->type) {
            SettingType::Timezone => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()),
            SettingType::Term => $this->terms((string) $setting->taxonomy),
            SettingType::ContentType => $this->publicTypes(),
            default => $setting->options,
        };

        return [
            'key' => $setting->key,
            'type' => $setting->type->value,
            'label' => $setting->label,
            'description' => $setting->description,
            'options' => $options,
            'min' => $setting->min,
            'max' => $setting->max,
            'maxLength' => $setting->maxLength,
            'required' => $setting->required,
            'example' => $setting->example,
            'unit' => $setting->unit,
            'visibleWhen' => $setting->visibleWhen,
            ...($setting->type === SettingType::Term ? ['multiple' => $setting->multiple] : []),
        ];
    }

    /**
     * A term setting's choices: the taxonomy's terms, slug => name, by name.
     * A taxonomy can be large, so at most the first 500.
     *
     * @return array<string, string>
     */
    private function terms(string $taxonomy): array
    {
        /** @var array<string, string> */
        return DB::table('terms')->where('taxonomy', $taxonomy)
            ->orderBy('name')->orderBy('id')->limit(self::MOST_TERMS)
            ->pluck('name', 'slug')->map(strval(...))->all();
    }

    /**
     * A type setting's choices: the content types with public addresses,
     * key => plural name, in the registry's order.
     *
     * @return array<string, string>
     */
    private function publicTypes(): array
    {
        $types = [];

        foreach ($this->types->all() as $type) {
            if ($type->public) {
                $types[$type->key] = $type->plural;
            }
        }

        return $types;
    }
}
