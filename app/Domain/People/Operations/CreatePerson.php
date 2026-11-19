<?php

/**
 * Adds a person with no account, whom an item can credit (I-3366): a name,
 * an address slug and a biography, and their author page. The operator's or
 * an administrator's, as managing accounts is.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\People\Operations\Concerns\ValidatesPersonDetails;
use App\Domain\People\Operations\Input\CreatePersonInput;
use App\Domain\People\PersonSlugs;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class CreatePerson extends BaseOperation
{
    use ManagesUsers;
    use ValidatesPersonDetails;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreatePersonInput $input */
        $this->checkDetails($input->name, $input->slug, $input->bio ?? '');

        // A slug made from the name is free among accounts and people; its address must be free too.
        if ($input->slug === null) {
            $this->checkSlug(PersonSlugs::unique(trim($input->name)), null);
        }
    }

    /**
     * @return string the person's ID
     */
    protected function perform(OperationInput $input): string
    {
        /** @var CreatePersonInput $input */
        $id = strtolower((string) Str::ulid());
        $bio = trim((string) $input->bio);

        DB::table('people')->insert([
            'id' => $id,
            'name' => trim($input->name),
            'slug' => $input->slug ?? PersonSlugs::unique(trim($input->name)),
            'bio' => $bio === '' ? null : $bio,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PathProjection::class)->syncPerson($id);

        // Their page's address: a stored 404 there goes.
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, $id, Footprints::guest($id)));

        return $id;
    }
}
