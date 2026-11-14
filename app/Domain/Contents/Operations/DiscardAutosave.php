<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Operations\Input\DiscardAutosaveInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Operations\OperationInput;

/**
 * Throws away one person's own unsaved work in an article. Never anyone
 * else's.
 */
final class DiscardAutosave extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var DiscardAutosaveInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var DiscardAutosaveInput $input */
        ContentAutosave::query()->where('content_id', $input->contentId)
            ->where('user_id', (int) $input->actorId())->delete();

        return null;
    }
}
