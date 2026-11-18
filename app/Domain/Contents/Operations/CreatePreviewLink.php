<?php

/**
 * Makes a link that shows one revision of one article to someone with no
 * account, for `editor.preview_hours`. It returns the plain token, the only
 * copy there will ever be: only its hash is stored, as with an API key.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\PreviewLink;
use App\Domain\Contents\Operations\Input\CreatePreviewLinkInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class CreatePreviewLink extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var CreatePreviewLinkInput $input */
        $user = User::find($input->actorId());

        // A trashed article is not found, so it cannot be shared.
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to share this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreatePreviewLinkInput $input */
        // A preview draws the item through the theme as a page, which an item
        // of a type that isn't public, such as a region, never is.
        $types = app(ContentTypeRegistry::class);
        $type = Content::query()->whereKey($input->contentId)->value('type');

        if (is_string($type) && $types->has($type) && ! $types->get($type)->public) {
            throw ValidationException::withMessages([
                'contentId' => __(':plural have no page of their own, so they can\'t be previewed.', ['plural' => $types->get($type)->plural]),
            ]);
        }

        $ownsRevision = ContentRevision::query()
            ->where('content_id', $input->contentId)
            ->whereKey($input->revisionId)
            ->exists();

        if (! $ownsRevision) {
            throw ValidationException::withMessages([
                'revisionId' => __('That revision belongs to another article.'),
            ]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var CreatePreviewLinkInput $input */
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        PreviewLink::query()->create([
            'content_id' => $input->contentId,
            'revision_id' => $input->revisionId,
            'token_hash' => hash('sha256', $token),
            'created_by' => (int) $input->actorId(),
            'expires_at' => Date::now()->addHours((int) config('editor.preview_hours', 48)),
        ]);

        return $token;
    }
}
