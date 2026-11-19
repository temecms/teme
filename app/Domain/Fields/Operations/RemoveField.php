<?php

/**
 * Hides one field of a type from the editor, the site and search; every
 * value items hold for it stays stored, so Restore brings it back with them
 * (docs/studies/content-and-fields.md §5.3).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Fields\Operations\Input\RemoveFieldInput;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\StoredTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RemoveField extends BaseOperation
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
        /** @var RemoveFieldInput $input */
        if (! app(ContentTypeRegistry::class)->has($input->type)) {
            throw ValidationException::withMessages(['type' => __('There is no type :type on this site.', ['type' => $input->type])]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RemoveFieldInput $input */
        StoredTypes::lock();

        $current = $this->storedField($input->type, $input->key, removed: false);
        $actorId = $input->actorId();

        $this->fields->update($current->withRemoval(CarbonImmutable::now(), $actorId === null ? null : (int) $actorId));
        $this->fields->renumber($input->type);

        if ($current->search) {
            $this->reindexType($input->type);
            DB::afterCommit(fn () => $this->rebuildSearch($actorId));
        }

        $this->purgeEverything();

        return $current->key;
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->fieldsChanged();
    }
}
