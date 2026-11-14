<?php

declare(strict_types=1);

namespace App\Domain\People\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\People\Operations\Concerns\ValidatesPersonDetails;
use App\Domain\People\Operations\Input\DeletePersonInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Removes a person with no account, and their page, once nothing credits
 * them (I-3366); the database refuses a credit added in between (I-102).
 */
final class DeletePerson extends BaseOperation
{
    use ManagesUsers;
    use ValidatesPersonDetails;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DeletePersonInput $input */
        self::refuseAccount($input->personId);
        self::refuseCredited($input->personId);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var DeletePersonInput $input */
        $person = DB::table('people')->where('id', $input->personId)->lockForUpdate()->first(['id', 'user_id']);
        self::refuseAccount($input->personId, $person);
        self::refuseCredited($input->personId);
        $shown = Footprints::guest($input->personId);

        app(PathProjection::class)->forget('person', $input->personId);
        DB::table('people')->where('id', $input->personId)->delete();

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, $input->personId, $shown));

        return null;
    }

    /**
     * Refuses while an item's bylines name them, counting the items.
     */
    private static function refuseCredited(string $personId): void
    {
        $count = DB::table('content_bylines')->where('person_id', $personId)->count();

        if ($count > 0) {
            throw ValidationException::withMessages(['person' => trans_choice(':name is credited on :count item. Take them off it first.|:name is credited on :count items. Take them off them first.', $count, [
                'name' => (string) DB::table('people')->where('id', $personId)->value('name'),
            ])]);
        }
    }
}
