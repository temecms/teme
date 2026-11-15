<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Operations\Concerns\MakesLocalActors;
use App\Domain\Fediverse\Operations\Input\AuthorActorInput;
use App\Domain\Fediverse\Queries\FediverseAuthors;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Lets people follow one author: their account answers again, or is made
 * at /ap/people/{person} under their author slug, with keys of its own.
 * For whoever holds settings.fediverse.manage, while authors can be
 * followed; returns the account's user name.
 */
final class EnableAuthorActor extends BaseOperation
{
    use AuthorizesFediverseWork;
    use MakesLocalActors;

    public function __construct(private readonly FediverseAuthors $authors) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFediverseWork($input, 'Not permitted to change the fediverse accounts.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AuthorActorInput $input */
        $author = $this->authors->find($input->personId);

        if ($author === null) {
            throw ValidationException::withMessages(['person' => __('This author no longer exists.')]);
        }

        if (! FediverseSettingsGroup::authorsFollowable()) {
            throw ValidationException::withMessages(['person' => __('Only the blog can be followed. Change who can be followed first.')]);
        }

        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/i', $author->slug) !== 1) {
            throw ValidationException::withMessages(['person' => __('This author’s address can’t be a fediverse name.')]);
        }

        $taken = FediverseActor::query()
            ->whereRaw('lower(username) = ?', [strtolower($author->slug)])
            ->where(fn ($query) => $query->whereNull('person_id')->orWhere('person_id', '!=', $input->personId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['person' => __('Another account is called @:name.', ['name' => $author->slug])]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var AuthorActorInput $input */
        $actor = FediverseActor::query()->where('person_id', $input->personId)->lockForUpdate()->first();

        if ($actor !== null) {
            $actor->update(['enabled' => true]);

            return $actor->username;
        }

        $author = $this->authors->find($input->personId);
        $uri = rtrim(url('/'), '/').'/'.ActivityPub::PREFIX.'/people/'.$input->personId;

        return $this->makeLocalActor(FediverseActor::PERSON, (string) $author?->slug, $uri, personId: $input->personId)->username;
    }
}
