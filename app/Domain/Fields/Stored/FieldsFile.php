<?php

declare(strict_types=1);

namespace App\Domain\Fields\Stored;

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The fields list of teme-types.json: every live field of the site's own,
 * each naming its type, in type order then position; a list's order is its
 * fields' order (I-3320).
 */
final class FieldsFile
{
    private const string ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * The site's live stored fields as file entries, in type order then
     * position. A row that doesn't read is left out; it was reported when read.
     *
     * @return list<array<string, mixed>>
     */
    public static function export(): array
    {
        $entries = [];

        foreach (app(StoredFields::class)->definitions() as $row) {
            try {
                $definition = FieldDefinition::fromRow($row);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($definition->removedAt === null) {
                $entries[] = $definition->toArray();
            }
        }

        return $entries;
    }

    /**
     * What is wrong with the file's fields list, by the file's place: its
     * entries' shape, the rules that need no database, keys and handles
     * named twice, and types neither the file nor the site has.
     *
     * @param  array<mixed>  $file
     * @param  list<string>  $typeKeys  the types the file defines
     *
     * @return array<string, list<string>>
     */
    public static function problems(array $file, array $typeKeys): array
    {
        return self::read($file, $typeKeys)[1];
    }

    /**
     * The file's fields, each at position 0: an import numbers each type's
     * from 1 in the order the file lists them. A file with problems throws.
     *
     * @param  array<mixed>  $file
     * @param  list<string>  $typeKeys  the types the file defines
     *
     * @return list<FieldDefinition>
     *
     * @throws ValidationException
     */
    public static function definitions(array $file, array $typeKeys): array
    {
        [$definitions, $problems] = self::read($file, $typeKeys);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $definitions;
    }

    /**
     * @param  array<mixed>  $file
     * @param  list<string>  $typeKeys
     *
     * @return array{0: list<FieldDefinition>, 1: array<string, list<string>>}
     */
    private static function read(array $file, array $typeKeys): array
    {
        $entries = $file['fields'] ?? [];

        if (! is_array($entries) || ! array_is_list($entries)) {
            return [[], []];
        }

        $definitions = [];
        $problems = [];
        $keys = [];
        $handles = [];
        $types = app(ContentTypeRegistry::class);

        foreach ($entries as $i => $raw) {
            $n = (string) ($i + 1);

            try {
                $definition = FieldDefinition::fromArray(is_array($raw) ? $raw : [0]);
            } catch (InvalidArgumentException $failure) {
                $problems["fields.{$i}"][] = __('Field :n: :problems', ['n' => $n, 'problems' => $failure->getMessage()]);

                continue;
            }

            if ($definition->key === '') {
                $problems["fields.{$i}.key"][] = __('Field :n: :problems', ['n' => $n, 'problems' => '[key] is missing.']);

                continue;
            }

            if (preg_match(self::ULID, $definition->key) !== 1) {
                $problems["fields.{$i}.key"][] = __('Field :n: :problems', ['n' => $n, 'problems' => '[key] must be a ULID.']);

                continue;
            }

            if (isset($keys[$definition->key])) {
                $problems["fields.{$i}.key"][] = __('Field :n: the file has the key :key twice.', ['n' => $n, 'key' => $definition->key]);

                continue;
            }

            $keys[$definition->key] = true;

            if (! in_array($definition->type, $typeKeys, true) && ! $types->has($definition->type) && ! self::storedType($definition->type)) {
                $problems["fields.{$i}.type"][] = __('Field :n: the file has no type :type, and this site hasn’t either.', ['n' => $n, 'type' => $definition->type]);

                continue;
            }

            $pair = $definition->type."\0".$definition->handle;

            if (isset($handles[$pair])) {
                $problems["fields.{$i}.handle"][] = __('Field :n: :type has two fields called :handle in the file.', ['n' => $n, 'type' => $definition->type, 'handle' => $definition->handle]);

                continue;
            }

            $handles[$pair] = true;
            $rules = FieldRules::fileProblems($definition);

            if ($rules !== []) {
                $problems["fields.{$i}"][] = __('Field :n: :problems', ['n' => $n, 'problems' => implode(' ', $rules)]);

                continue;
            }

            $definitions[] = $definition;
        }

        return [$definitions, $problems];
    }

    private static function storedType(string $key): bool
    {
        return DB::table(StoredTypes::TABLE)->where('key', $key)->exists();
    }
}
