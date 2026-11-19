<?php

/**
 * Changes one live field of a type: its label, handle, help, settings and
 * boxes, never its field type, and never the values items hold for it
 * (docs/studies/content-and-fields.md §5.3).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Fields\Operations\Input\UpdateFieldInput;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\FieldRules;
use App\Domain\Fields\Stored\FieldValues;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class UpdateField extends BaseOperation
{
    use ManagesFields;
    use ManagesTypes;

    public function __construct(private readonly StoredFields $fields) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFields($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateFieldInput $input */
        $this->refuseIfPlugins('fields', $input->key);

        if (! app(ContentTypeRegistry::class)->has($input->type)) {
            throw ValidationException::withMessages(['type' => __('There is no type :type on this site.', ['type' => $input->type])]);
        }
    }

    /**
     * @return array{changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UpdateFieldInput $input */
        StoredTypes::lock();
        $this->refuseIfPlugins('fields', $input->key);

        $current = $this->storedField($input->type, $input->key, removed: false);
        $next = $input->definition->withKey($current->key)->withPosition($current->position);

        if ($next->type !== $current->type) {
            $this->refuse(['key' => __('There is no field :key.', ['key' => $input->key])]);
        }

        $holding = FieldValues::counts($current->type, [$current->key])[$current->key] ?? 0;
        $this->refuse(FieldRules::problems($next, $current, $holding));

        if (self::comparable($next) === self::comparable($current)) {
            return ['changed' => false];
        }

        $this->fields->update($next);

        if (self::searchChanges($current, $next)) {
            $this->reindexType($input->type);
            DB::afterCommit(fn () => $this->rebuildSearch($input->actorId()));
        }

        $this->purgeEverything();

        return ['changed' => true];
    }

    protected function invalidateCache(mixed $result): void
    {
        /** @var array{changed: bool} $result */
        if ($result['changed']) {
            $this->fieldsChanged();
        }
    }

    /**
     * The definition as it compares: a choice read back from JSONB has its
     * names in another order than the sheet sends them.
     *
     * @return array<string, mixed>
     */
    private static function comparable(FieldDefinition $field): array
    {
        $compared = $field->toArray();
        ksort($compared['settings']);

        if (is_array($compared['settings']['choices'] ?? null)) {
            $compared['settings']['choices'] = array_map(function (mixed $choice): mixed {
                if (is_array($choice)) {
                    ksort($choice);
                }

                return $choice;
            }, $compared['settings']['choices']);
        }

        return $compared;
    }
}
