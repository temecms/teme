<?php

/**
 * Writes the settings one step of an import brings (I-342), and that step's
 * map row, in one transaction: each value goes in unless a person changed it
 * since the import last wrote it, which the row's fingerprints tell. The
 * address settings go through ImportAddresses, the rest as the import's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Addresses\Operations\ImportAddresses;
use App\Domain\Addresses\Operations\Input\ImportAddressesInput;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Input\ImportSettingsInput;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ImportSettings extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;

    /** The group whose values make addresses: ImportAddresses checks and writes it. */
    private const string ADDRESSES = 'addresses';

    /** The map row's Teme type: the row stands for a step's settings, and its Teme ID is the step's key. */
    private const string TEME_TYPE = 'setting';

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsWriter $writer,
        private readonly ImportMap $map,
        private readonly Container $container,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportSettingsInput $input */
        if (preg_match('/^[a-z]+(-[a-z]+)*$/', $input->step) !== 1) {
            throw ValidationException::withMessages(['step' => 'A step’s settings need its key.']);
        }

        foreach ($input->values as $group => $values) {
            $definition = $this->registry->find($group)
                ?? throw ValidationException::withMessages(['values' => "Teme has no settings group [{$group}]."]);

            if ($group === self::ADDRESSES) {
                // Their checks are ImportAddresses', made as it runs; here, only that each exists.
                foreach (array_keys($values) as $key) {
                    if ($definition->setting($key) === null) {
                        throw ValidationException::withMessages(["values.{$key}" => 'This setting does not exist.']);
                    }
                }

                continue;
            }

            $problems = [];

            foreach ($this->container->tagged(SettingsCheck::TAG) as $check) {
                if ($check instanceof SettingsCheck && $check->group() === $group) {
                    $problems += $check->problems($values);
                }
            }

            SettingRules::validateValues($definition, $values, $problems);
        }
    }

    /**
     * @return array{kept: list<string>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportSettingsInput $input */
        $ref = new SourceRef('setting', $input->step);
        $this->lockObject($input->sourceId, $ref);
        $entry = $this->map->entry($input->sourceId, $ref);
        $facts = $entry === null ? [] : $entry->facts;
        /** @var array<string, string> $written */
        $written = is_array($facts['written'] ?? null) ? $facts['written'] : [];
        $prints = $written;
        $kept = [];
        $changes = [];
        // Groups in one order, so two writers never wait on each other.
        $groups = $input->values;
        ksort($groups);

        foreach ($groups as $group => $values) {
            $definition = $this->registry->find($group);

            if ($definition === null) {
                continue;
            }

            // Read under the group's lock, never from the settings cache, so a person saving now is seen.
            SettingsGroupRecord::query()->insertOrIgnore(['group_key' => $group, 'overrides' => '{}', 'version' => 0]);
            $overrides = SettingsGroupRecord::query()->lockForUpdate()->findOrFail($group)->overrides;

            foreach ($values as $key => $value) {
                $setting = $definition->setting($key);
                $name = "{$group}.{$key}";

                if ($setting === null) {
                    continue;
                }

                $current = array_key_exists($key, $overrides) ? $overrides[$key] : $setting->default;

                // Changed here since the import wrote it: a person's, kept.
                if (isset($written[$name]) && $written[$name] !== self::print($current)) {
                    $kept[] = $name;

                    continue;
                }

                $stored = SettingRules::normalise($setting, $value);
                $changes[$group][$key] = $stored;
                $prints[$name] = self::print($stored);
            }
        }

        foreach ($changes as $group => $values) {
            if ($group === self::ADDRESSES) {
                app(ImportAddresses::class)->execute(self::addresses($values, $input->actorId()));
            } else {
                $this->writer->write(new SettingsWrite(group: $group, values: $values, actorId: $input->actorId(), origin: 'import'));
            }
        }

        $outcome = $entry === null ? ImportOutcome::Created : ImportOutcome::Updated;
        $entry ??= new ImportMapEntry(['source_id' => $input->sourceId, 'object_type' => $ref->type, 'object_id' => $ref->id]);
        $entry->fill([
            'teme_type' => self::TEME_TYPE,
            'teme_id' => $input->step,
            'status' => $outcome->status(),
            'outcome' => $outcome,
            'source_hash' => null,
            'facts' => [...$facts, 'written' => $prints],
            'run_id' => $input->runId,
            'imported_at' => now(),
        ])->save();

        return ['kept' => $kept];
    }

    /**
     * The addresses group's values as ImportAddresses takes them: each
     * type's pattern, each taxonomy's base, the rest by name.
     *
     * @param  array<string, mixed>  $values
     */
    private static function addresses(array $values, ?string $actorId): ImportAddressesInput
    {
        $patterns = [];
        $bases = [];

        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'pattern_')) {
                $patterns[substr($key, strlen('pattern_'))] = (string) $value;
            } elseif (str_starts_with($key, 'base_')) {
                $bases[substr($key, strlen('base_'))] = (string) $value;
            }
        }

        $named = fn (string $key): ?string => array_key_exists($key, $values) ? (string) $values[$key] : null;

        return new ImportAddressesInput($patterns, $bases, $named('author_base'), $named('front_shows'), $named('front_page_id'), $named('posts_page_id'), $actorId);
    }

    /**
     * A value's fingerprint, as the map keeps it: the SHA-1 of its JSON.
     */
    private static function print(mixed $value): string
    {
        return sha1(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
