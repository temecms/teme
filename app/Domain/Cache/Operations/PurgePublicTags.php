<?php

/**
 * Purges the page-cache tags a plugin's job named, for the changes its
 * insertions and cards made, once the window a job's notice waits for is
 * over. Only the tags a plugin's insertions and cards use may be purged:
 * core's own tags, such as every page, are refused (rule 1: this is the one
 * place a deferred notice reaches the public cache).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache\Operations;

use App\Domain\Cache\Operations\Input\PurgePublicTagsInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class PurgePublicTags extends BaseOperation
{
    /** The tags a plugin's insertions use, as core's insertions purge them. */
    private const array INSERTION_TAGS = ['insertions', 'page-insertions'];

    protected function authorize(OperationInput $input): void
    {
        assert($input instanceof PurgePublicTagsInput);

        foreach ($input->tags as $tag) {
            if (! in_array($tag, self::INSERTION_TAGS, true) && ! str_starts_with($tag, 'plugin:')) {
                throw new AuthorizationException('Only a plugin\'s own tags may be purged this way.');
            }
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var PurgePublicTagsInput $input */
        if ($input->tags === []) {
            throw ValidationException::withMessages(['purge' => __('Name what to purge.')]);
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var PurgePublicTagsInput $input */
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SETTINGS, 'plugin-pages', $input->tags));

        return true;
    }
}
