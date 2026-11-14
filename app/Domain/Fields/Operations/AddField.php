<?php

declare(strict_types=1);

namespace App\Domain\Fields\Operations;

use App\Domain\Fields\Operations\Concerns\ManagesFields;
use App\Domain\Fields\Operations\Input\AddFieldInput;
use App\Domain\Fields\Stored\FieldRules;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\Concerns\ManagesTypes;
use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Adds one field to a type, last among its fields, under a new key its
 * values will be stored under; it works at once (I-3320).
 */
final class AddField extends BaseOperation
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
        /** @var AddFieldInput $input */
        if ($input->definition->type !== $input->type) {
            throw new InvalidArgumentException("The field names the type [{$input->definition->type}], not [{$input->type}].");
        }

        if (! app(ContentTypeRegistry::class)->has($input->type)) {
            throw ValidationException::withMessages(['type' => __('There is no type :type on this site.', ['type' => $input->type])]);
        }
    }

    /**
     * @return string the new field's key
     */
    protected function perform(OperationInput $input): string
    {
        /** @var AddFieldInput $input */
        StoredTypes::lock();

        $last = (int) DB::table(StoredFields::TABLE)->where('content_type', $input->type)->whereNull('removed_at')->max('position');
        $next = $input->definition->withKey((string) Str::ulid())->withPosition($last + 1);
        $this->refuse(FieldRules::problems($next, null, null));

        $this->fields->insert($next);
        $this->fields->renumber($input->type);

        if ($next->search) {
            $this->reindexType($input->type);
            DB::afterCommit(fn () => $this->rebuildSearch($input->actorId()));
        }

        $this->purgeEverything();

        return $next->key;
    }

    protected function invalidateCache(mixed $result): void
    {
        $this->fieldsChanged();
    }
}
