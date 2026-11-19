<?php

/**
 * Takes one item out of its translation group (I-3360, D10); the others
 * stay linked, and a group left with one member keeps it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\GroupLock;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Concerns\MovesVersionAddresses;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\UnlinkTranslationInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Operations\OperationInput;

final class UnlinkTranslation extends BaseOperation
{
    use MovesVersionAddresses;
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var UnlinkTranslationInput $input */
        $actor = User::query()->find($input->actorId());
        $item = Content::withTrashed()->findOrFail($input->contentId);

        if ($actor === null || ! Gate::forUser($actor)->allows('update', $item)) {
            throw new AuthorizationException('Not permitted to unlink this translation.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * The group it left, or null when it was in none.
     */
    protected function perform(OperationInput $input): ?string
    {
        /** @var UnlinkTranslationInput $input */
        $this->takeTrees([$input->contentId]);
        $rows = GroupLock::lock([$input->contentId]);
        $group = $rows->get($input->contentId)?->translation_group;

        if ($group === null) {
            return null;
        }

        DB::table('contents')->where('id', $input->contentId)->update(['translation_group' => null]);
        // Leaving the home page's group moves the item, and the home page leaving it moves the others.
        $this->moveVersionAddresses($rows, 'content');

        $this->purgeAfterCommit(...self::translationKeys($rows, [$group]));

        return $group;
    }
}
