<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Comments\Models\Comment;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Operations\Input\ForgetRemoteActorInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Forgets a remote account: its follows end, its kept profile is removed,
 * and its replies keep their words but lose its name and address. For
 * whoever holds settings.fediverse.manage, or the operator; returns whether
 * the site knew the account.
 */
final class ForgetRemoteActor extends BaseOperation
{
    use AuthorizesFediverseWork;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFediverseWork($input, 'Not permitted to forget fediverse accounts.');
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var ForgetRemoteActorInput $input */
        $remote = RemoteActor::query()->where('uri', $input->remoteActorUri)->lockForUpdate()->first();

        if ($remote === null) {
            return false;
        }

        $replies = FediverseObject::query()->where('remote_actor_id', $remote->id)->whereNotNull('comment_id')->pluck('comment_id')->all();
        $items = Comment::query()->whereKey($replies)->distinct()->pluck('content_id')->all();

        Comment::query()->whereKey($replies)->update(['author_name' => null, 'author_url' => null, 'author_email' => null]);
        Follower::query()->where('remote_actor_id', $remote->id)->delete();
        // Its objects keep their addresses, which answer for ever, without it.
        $remote->delete();

        foreach ($items as $item) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $item, [PageTags::content((string) $item)]));
        }

        return true;
    }
}
