<?php

/**
 * Puts one live field of a type at another place among its fields, and
 * numbers them all again, so the editor and the site show the new order at
 * once (I-3320).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Fields\Operations\Input\MoveFieldInput;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class MoveField extends BaseOperation
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
        /** @var MoveFieldInput $input */
        $this->refuseIfPlugins('fields', $input->key);

        if (! app(ContentTypeRegistry::class)->has($input->type)) {
            throw ValidationException::withMessages(['type' => __('There is no type :type on this site.', ['type' => $input->type])]);
        }
    }

    /**
     * A place under 1 is the first, one past the last is the last.
     *
     * @return list<string> the type's live keys, in their new order
     */
    protected function perform(OperationInput $input): array
    {
        /** @var MoveFieldInput $input */
        StoredTypes::lock();
        $this->refuseIfPlugins('fields', $input->key);

        $this->storedField($input->type, $input->key, removed: false);

        $before = DB::table(StoredFields::TABLE)
            ->where('content_type', $input->type)
            ->whereNull('removed_at')
            ->orderBy('position')
            ->orderBy('key')
            ->pluck('key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all();

        $order = array_values(array_diff($before, [$input->key]));
        $at = max(0, min(count($order), $input->position - 1));
        array_splice($order, $at, 0, [$input->key]);

        $this->fields->renumber($input->type, $order);

        if ($order !== $before) {
            $this->purgeEverything();
        }

        return $order;
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->fieldsChanged();
    }
}
