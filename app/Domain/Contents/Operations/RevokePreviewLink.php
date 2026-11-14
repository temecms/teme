<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Models\PreviewLink;
use App\Domain\Contents\Operations\Input\RevokePreviewLinkInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Operations\OperationInput;

/**
 * Stops a preview link working, at once and for good. Anyone who may edit
 * its article may revoke it, the same rule as making one. The link stays
 * listed, marked revoked, so the writer can see what they shared.
 */
final class RevokePreviewLink extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var RevokePreviewLinkInput $input */
        $user = User::find($input->actorId());
        $link = PreviewLink::query()->findOrFail($input->linkId);
        $content = $link->content()->firstOrFail();

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to revoke this link.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RevokePreviewLinkInput $input */
        if ($input->contentId === null) {
            return;
        }

        // From a URL that names an article, the link must be one of its own:
        // /content/{a}/preview-links/{a link of b} revokes nothing.
        $belongs = PreviewLink::query()->whereKey($input->linkId)->where('content_id', $input->contentId)->exists();

        if (! $belongs) {
            throw (new ModelNotFoundException)->setModel(PreviewLink::class, [$input->linkId]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var RevokePreviewLinkInput $input */
        PreviewLink::query()->whereKey($input->linkId)->whereNull('revoked_at')->update(['revoked_at' => Date::now()]);

        return null;
    }
}
