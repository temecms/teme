<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fields\FieldRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * teme:types:import — adds and updates the content types, taxonomies and
 * fields a teme-types.json file names; it never removes one (I-411, I-414).
 * Acts as the operator.
 */
final class ImportTypesFile extends Command
{
    /** @var string */
    protected $signature = 'teme:types:import
        {file : The teme-types.json file to bring in}';

    /** @var string */
    protected $description = 'Add and update the content types and taxonomies a teme-types.json file names; it never removes one';

    public function handle(ImportTypes $import): int
    {
        // The operator: docs/architecture/operations.md.
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error(__('There is no file at :path.', ['path' => $path]));

            return self::FAILURE;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            $this->error(__(':path isn’t JSON: :error', ['path' => $path, 'error' => $failure->getMessage()]));

            return self::FAILURE;
        }

        if (! is_array($decoded)) {
            $this->error(__(':path isn’t JSON: :error', ['path' => $path, 'error' => 'decoded value is not an object or list']));

            return self::FAILURE;
        }

        try {
            /** @var array{added: list<string>, updated: list<string>, unchanged: list<string>, onlyHere: list<string>, patternKept: list<string>, grantsKept: list<string>, taxonomies: array{added: list<string>, updated: list<string>, unchanged: list<string>, onlyHere: list<string>, baseKept: list<string>}, fieldsAdded: list<string>, fieldsUpdated: list<string>, fieldsUnchanged: list<string>, fieldsOnlyHere: list<string>} $report */
            $report = $import->execute(new ImportTypesInput($decoded));
        } catch (ValidationException $refusal) {
            foreach ($refusal->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $types = app(ContentTypeRegistry::class);
        $taxonomies = app(TaxonomyRegistry::class);

        foreach ($report['added'] as $key) {
            $this->info(__('Added :key (:plural).', ['key' => $key, 'plural' => $types->get($key)->plural]));
        }

        foreach ($report['updated'] as $key) {
            $this->info(__('Updated :key (:plural).', ['key' => $key, 'plural' => $types->get($key)->plural]));
        }

        if ($report['unchanged'] !== []) {
            $this->info(__('Unchanged: :keys.', ['keys' => implode(', ', $report['unchanged'])]));
        }

        foreach ($report['patternKept'] as $key) {
            $this->info(__('Kept this site’s address pattern for :key: change it in Settings › Addresses.', ['key' => $key]));
        }

        foreach ($report['grantsKept'] as $key) {
            $this->info(__('Kept this site’s permission grants for :key: the file’s differ.', ['key' => $key]));
        }

        if ($report['onlyHere'] !== []) {
            $this->info(__('Left in place, not in the file: :keys. An import never removes a type.', [
                'keys' => implode(', ', $report['onlyHere']),
            ]));
        }

        foreach ($report['taxonomies']['added'] as $key) {
            $this->info(__('Added the taxonomy :key (:plural).', ['key' => $key, 'plural' => $taxonomies->get($key)->plural]));
        }

        foreach ($report['taxonomies']['updated'] as $key) {
            $this->info(__('Updated the taxonomy :key (:plural).', ['key' => $key, 'plural' => $taxonomies->get($key)->plural]));
        }

        if ($report['taxonomies']['unchanged'] !== []) {
            $this->info(__('Unchanged taxonomies: :keys.', ['keys' => implode(', ', $report['taxonomies']['unchanged'])]));
        }

        foreach ($report['taxonomies']['baseKept'] as $key) {
            $this->info(__('Kept this site’s address base for :key: change it in Settings › Addresses.', ['key' => $key]));
        }

        if ($report['taxonomies']['onlyHere'] !== []) {
            $this->info(__('Left in place, not in the file: :keys. An import never removes a taxonomy.', [
                'keys' => implode(', ', $report['taxonomies']['onlyHere']),
            ]));
        }

        $fields = app(FieldRegistry::class);

        foreach ($report['fieldsAdded'] as $name) {
            $this->info(__('Added field :type.:handle (:label).', self::fieldWords($fields, $name)));
        }

        foreach ($report['fieldsUpdated'] as $name) {
            $this->info(__('Updated field :type.:handle (:label).', self::fieldWords($fields, $name)));
        }

        if ($report['fieldsUnchanged'] !== []) {
            $this->info(__('Fields unchanged: :keys.', ['keys' => implode(', ', $report['fieldsUnchanged'])]));
        }

        if ($report['fieldsOnlyHere'] !== []) {
            $this->info(__('Left in place, not in the file: :keys. An import never removes a field.', [
                'keys' => implode(', ', $report['fieldsOnlyHere']),
            ]));
        }

        return self::SUCCESS;
    }

    /**
     * The words a field's report line names it by: its type, its handle and
     * its label as the registry now has it.
     *
     * @return array{type: string, handle: string, label: string}
     */
    private static function fieldWords(FieldRegistry $fields, string $name): array
    {
        [$type, $handle] = explode('.', $name, 2);
        $label = $handle;

        foreach ($fields->stored($type) as $field) {
            if ($field->handle === $handle) {
                $label = $field->label;
            }
        }

        return ['type' => $type, 'handle' => $handle, 'label' => $label];
    }
}
