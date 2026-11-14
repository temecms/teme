<?php

declare(strict_types=1);

namespace App\Domain\People\Operations;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\People\Operations\Concerns\ValidatesPersonDetails;
use App\Domain\People\Operations\Input\UpdatePersonInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Changes a person with no account's name, address slug or biography
 * (I-3366). A new slug moves their page and keeps the old address as a
 * redirect, and moves the items whose address holds {author}, in the
 * background. An account's person is changed through the account.
 */
final class UpdatePerson extends BaseOperation
{
    use ManagesUsers;
    use ValidatesPersonDetails;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdatePersonInput $input */
        self::refuseAccount($input->personId);
        $this->checkDetails($input->name, $input->slug, $input->bio, $input->personId);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var UpdatePersonInput $input */
        $person = DB::table('people')->where('id', $input->personId)->lockForUpdate()->first(['id', 'user_id', 'slug']);
        self::refuseAccount($input->personId, $person);
        $shown = Footprints::guest($input->personId);
        $moves = $input->slug !== null && $input->slug !== (string) $person->slug;
        $changes = array_filter([
            'name' => $input->name === null ? null : trim($input->name),
            'slug' => $input->slug,
        ], fn (?string $value): bool => $value !== null);

        if ($input->bio !== null) {
            $changes['bio'] = trim($input->bio) === '' ? null : trim($input->bio);
        }

        DB::table('people')->where('id', $input->personId)->update([...$changes, 'updated_at' => now()]);

        if ($moves) {
            app(PathProjection::class)->syncPerson($input->personId);
        }

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, $input->personId, [...$shown, ...Footprints::guest($input->personId)]));

        // The items credited to them whose address holds {author} move with it.
        if ($moves && AddressSettings::current()->anyPatternUses('author')) {
            RecomputeAddresses::dispatch(JobActor::of($input->actorId()))->afterCommit();
        }

        return null;
    }
}
