<?php

/**
 * Applies a plugin's teme-types file (docs/architecture/plugins.md, *Content
 * types from a plugin*): a person who holds plugins.manage, after their
 * password, or the operator, as EnablePlugin and MigratePlugin run it. Adds and
 * updates its types, taxonomies and fields as an import does, takes as the
 * plugin's those the site already has under the same keys, keeping their
 * items, and gives the site those its file no longer names; refused, writing
 * nothing, when the file can't be read, another plugin brought one of them,
 * or a field's kind would change under values items hold. Returns what changed.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\FieldValues;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\ApplyPluginTypesInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Types\PluginTypesFile;
use App\Domain\Plugins\Types\PluginTypesUnreadable;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\Concerns\AppliesTypesFile;
use App\Domain\Types\Operations\Concerns\ManagesTaxonomies;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TypeOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class ApplyPluginTypes extends BaseOperation implements RequiresElevation
{
    use AppliesTypesFile;
    use AuthorizesPluginChanges;
    use ManagesFields;
    use ManagesTaxonomies;
    use ManagesTypes;

    public function __construct(private readonly PluginRegistry $registry) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ApplyPluginTypesInput $input */
        $plugin = $this->plugin($input->name);

        if ($plugin->manifest !== null && PluginTypesFile::declared($plugin->manifest) !== null) {
            $this->read($plugin);
        }
    }

    /**
     * The import's report, with what the plugin took from the site
     * (`adopted`) and gave back to it (`released`), each by kind.
     *
     * @return array<string, mixed>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ApplyPluginTypesInput $input */
        StoredTypes::lock();

        $plugin = $this->plugin($input->name);

        // A version that no longer declares types gives the site every one it brought.
        if (PluginTypesFile::declared($plugin->manifest ?? throw new PluginTypesUnreadable) === null) {
            $report = ['released' => TypeOwners::release($plugin->name, ['types' => [], 'taxonomies' => [], 'fields' => []])];
            PluginRecord::query()->where('name', $plugin->name)->update(['types' => '{}']);
            $this->purgeEverything();

            return $report;
        }

        ['file' => $file, 'version' => $version] = $this->read($plugin);
        $file = self::matchFields($file, $plugin->name);

        $keys = self::keys($file);
        $adopted = $this->siteOwn($keys);

        try {
            $problems = [...array_merge(...array_values($this->typesFileProblems($file, $plugin->name))), ...$this->kindProblems($file)];

            if ($problems !== []) {
                throw ValidationException::withMessages(['plugin' => $problems]);
            }

            $report = $this->applyTypesFile($file, $input->actorId(), kindsFollowFile: true);
        } catch (ValidationException $refused) {
            // Said as one refusal of the plugin's, each problem after the other.
            throw ValidationException::withMessages(['plugin' => array_map(
                fn (string $problem): string => __('Its content types can’t be added: :problem', ['problem' => $problem]),
                array_values(array_merge(...array_values($refused->errors()))),
            )]);
        }

        TypeOwners::mark($plugin->name, $keys);
        $released = TypeOwners::release($plugin->name, $keys);
        unset($report['onlyHere'], $report['taxonomies']['onlyHere'], $report['fieldsOnlyHere']);
        $report = [...$report, 'adopted' => $adopted, 'released' => $released];

        PluginRecord::query()->where('name', $plugin->name)->update(['types' => json_encode([
            'version' => $version,
            'applied_at' => CarbonImmutable::now()->toIso8601String(),
            'report' => $report,
        ], JSON_THROW_ON_ERROR)]);

        // Its types reach lists, addresses and feeds on every page (I-263).
        $this->purgeEverything();

        return $report;
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->typesChanged();
        $this->fieldsChanged();
    }

    /**
     * The plugin, read again from its folder, never from the cache.
     *
     * @throws ValidationException
     */
    private function plugin(string $name): FoundPlugin
    {
        $found = $this->registry->find($name);
        $plugin = $found === null ? null : $this->registry->fresh($found);

        if ($plugin === null || $plugin->manifest === null) {
            throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$name}\"."]]);
        }

        return $plugin;
    }

    /**
     * @return array{file: array<mixed>, version: string}
     *
     * @throws ValidationException
     */
    private function read(FoundPlugin $plugin): array
    {
        try {
            return PluginTypesFile::read($plugin->path, $plugin->manifest ?? throw new PluginTypesUnreadable(__('The plugin has no teme-plugin.json.')));
        } catch (PluginTypesUnreadable $unreadable) {
            throw ValidationException::withMessages(['plugin' => [$unreadable->getMessage()]]);
        }
    }

    /**
     * The fields whose kind the file changes while items hold values in
     * them: those values would be read as another kind, so the site and the
     * file can't be reconciled.
     *
     * @param  array<mixed>  $file
     *
     * @return list<string>
     */
    private function kindProblems(array $file): array
    {
        $problems = [];
        $types = app(ContentTypeRegistry::class);

        foreach (is_array($file['fields'] ?? null) ? $file['fields'] : [] as $raw) {
            try {
                $next = FieldDefinition::fromArray(is_array($raw) ? $raw : []);
                $current = app(StoredFields::class)->find($next->key);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($current === null || $current->fieldType === $next->fieldType) {
                continue;
            }

            $holding = FieldValues::counts($current->type, [$current->key])[$current->key] ?? 0;

            if ($holding > 0) {
                $problems[] = __('The field :handle on :plural is :current here and holds values in :n items, but the plugin makes it :next, which would read those values as another kind.', [
                    'handle' => $current->handle,
                    'plural' => $types->has($current->type) ? $types->get($current->type)->plural : $current->type,
                    'current' => $current->fieldType,
                    'n' => (string) $holding,
                    'next' => $next->fieldType,
                ]);
            }
        }

        return $problems;
    }

    /**
     * Of the file's keys, those the site has now as its own, by kind;
     * fields named type.handle.
     *
     * @param  array{types: list<string>, taxonomies: list<string>, fields: list<string>}  $keys
     *
     * @return array{types: list<string>, taxonomies: list<string>, fields: list<string>}
     */
    private function siteOwn(array $keys): array
    {
        $own = [];

        foreach ($keys as $kind => $list) {
            $query = DB::table(TypeOwners::TABLES[$kind])->whereIn('key', $list === [] ? [''] : $list)->whereNull('plugin');
            $own[$kind] = $kind === 'fields'
                ? $query->orderBy('content_type')->orderBy('position')->get(['content_type', 'handle'])->map(fn (object $row): string => $row->content_type.'.'.$row->handle)->all()
                : $query->orderBy('key')->pluck('key')->map(fn (mixed $key): string => (string) $key)->all();
        }

        /** @var array{types: list<string>, taxonomies: list<string>, fields: list<string>} */
        return $own;
    }

    /**
     * $file with each field this site already has under another key, on the
     * same type with the same handle, given that key: a field made here
     * before the plugin had one of its own, whose items' values are kept
     * under it. Only the site's own fields and $plugin's are matched.
     *
     * @param  array<mixed>  $file
     *
     * @return array<mixed>
     */
    private static function matchFields(array $file, string $plugin): array
    {
        if (! is_array($file['fields'] ?? null)) {
            return $file;
        }

        $known = DB::table('field_definitions')->pluck('key')->map(fn (mixed $key): string => (string) $key)->all();

        foreach ($file['fields'] as $i => $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null) || ! is_string($field['type'] ?? null) || ! is_string($field['handle'] ?? null) || in_array($field['key'], $known, true)) {
                continue;
            }

            $existing = DB::table('field_definitions')
                ->where('content_type', $field['type'])
                ->where('handle', $field['handle'])
                ->where(fn ($query) => $query->whereNull('plugin')->orWhere('plugin', $plugin))
                ->orderByRaw('removed_at IS NOT NULL')
                ->value('key');

            if (is_string($existing)) {
                $file['fields'][$i]['key'] = $existing;
            }
        }

        return $file;
    }

    /**
     * The keys the file names, by kind.
     *
     * @param  array<mixed>  $file
     *
     * @return array{types: list<string>, taxonomies: list<string>, fields: list<string>}
     */
    private static function keys(array $file): array
    {
        $keys = [];

        foreach (['types', 'taxonomies', 'fields'] as $kind) {
            $keys[$kind] = [];

            foreach (is_array($file[$kind] ?? null) ? $file[$kind] : [] as $entry) {
                if (is_array($entry) && is_string($entry['key'] ?? null)) {
                    $keys[$kind][] = $entry['key'];
                }
            }
        }

        /** @var array{types: list<string>, taxonomies: list<string>, fields: list<string>} */
        return $keys;
    }
}
