<?php

/**
 * Brings a removed field back, last among its type's fields, with every
 * value items kept for it, unless a live field has taken its handle since.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Fields\Operations\Input\RestoreFieldInput;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RestoreField extends BaseOperation
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
        /** @var RestoreFieldInput $input */
        $this->refuseIfPlugins('fields', $input->key);

        if (! app(ContentTypeRegistry::class)->has($input->type)) {
            throw ValidationException::withMessages(['type' => __('There is no type :type on this site.', ['type' => $input->type])]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RestoreFieldInput $input */
        StoredTypes::lock();
        $this->refuseIfPlugins('fields', $input->key);

        $current = $this->storedField($input->type, $input->key, removed: true);

        foreach ($this->fields->handles($input->type) as $other) {
            if (! $other['removed'] && $other['handle'] === $current->handle) {
                $this->refuse(['handle' => __('A field called :handle is on :Plural now. Rename one of them first.', [
                    'handle' => $current->handle,
                    'plural' => app(ContentTypeRegistry::class)->get($input->type)->plural,
                ])]);
            }
        }

        $last = (int) DB::table(StoredFields::TABLE)->where('content_type', $input->type)->whereNull('removed_at')->max('position');
        $this->fields->update($current->withRemoval(null, null)->withPosition($last + 1));
        $this->fields->renumber($input->type);

        if ($current->search) {
            $this->reindexType($input->type);
            DB::afterCommit(fn () => $this->rebuildSearch($input->actorId()));
        }

        $this->purgeEverything();

        return $current->key;
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->fieldsChanged();
    }
}
