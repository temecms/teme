<?php

/**
 * One item's actions from its list row — quick edit, trash, restore,
 * publish, revert to draft, delete permanently — each handed to its Operation.
 * After trashing a published item, it offers a redirect for its address (I-856);
 * after publishing or trashing otherwise, an Undo by the same route (I-3398).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Comments\Operations\Input\SetDiscussionInput;
use App\Domain\Comments\Operations\SetDiscussion;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\ContentPointers;
use App\Domain\Contents\RestoredContent;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Queries\MissingAltText;
use App\Domain\Redirects\Queries\TrashedAddress;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Teme\Contracts\Operations\OperationInput;

final class ContentActionController extends Controller
{
    public function details(Request $request, string $type, string $content, UpdateContentDetails $update, ContentPointers $pointers, SetDiscussion $setDiscussion): RedirectResponse
    {
        $this->belongs($type, $content, $pointers);

        // A lone empty value means every term was removed.
        $terms = $request->has('terms')
            ? array_values(array_filter((array) $request->input('terms', []), fn (mixed $id): bool => is_string($id) && $id !== ''))
            : null;

        $fields = null;

        if ($request->has('featured_image_id')) {
            $id = $request->string('featured_image_id')->toString();
            $fields = [BuiltInFields::FEATURED_IMAGE => $id === '' ? null : [
                'id' => $id,
                'alt' => $request->string('featured_image_alt')->toString(),
                'decorative' => false, // I-237: the featured image is never decorative.
            ]];
        }

        // First: refused, it leaves the details unsaved, so the same form can
        // be sent again; saved, sending it again changes nothing.
        if ($request->filled('discussion')) {
            $setDiscussion->execute(new SetDiscussionInput($content, $request->string('discussion')->toString(), $this->actor($request)));
        }

        try {
            $result = $update->execute(new UpdateContentDetailsInput(
                contentId: $content,
                expectedRevisionId: $request->string('expected_revision_id')->toString(),
                actorId: $this->actor($request),
                title: $request->filled('title') ? $request->string('title')->toString() : null,
                slug: $request->filled('slug') ? $request->string('slug')->toString() : null,
                authorId: $request->filled('author_id') ? $request->integer('author_id') : null,
                terms: $terms,
                publishedAt: $request->filled('published_at') ? $request->string('published_at')->toString() : null,
                fields: $fields,
            ));
        } catch (StaleRevisionException) {
            throw ValidationException::withMessages([
                'expected_revision_id' => __('Someone changed this item while you were editing. Reload the list and try again.'),
            ]);
        }

        $message = match (true) {
            $result->publishedNow => __('Saved and published.'),
            $result->draftPending => __('Saved to the draft. The live page is unchanged, because other unpublished changes are waiting.'),
            default => __('Saved.'),
        };

        return $this->done($type, $message);
    }

    public function action(Request $request, string $type, string $content, string $action, ContentPointers $pointers, MissingAltText $missingAlt, TrashedAddress $trashed): RedirectResponse
    {
        $this->belongs($type, $content, $pointers);
        $input = new ContentActionInput($content, $this->actor($request));

        if ($action === 'trash') {
            // Read before the trash takes it: only a published item had an address readers may still follow (I-856).
            $wasPublished = ($pointers->forIds([$content])[$content]['status'] ?? null) === 'published';
            app(TrashContent::class)->execute($input);
            $offer = $wasPublished && $request->user()?->can('redirects.manage') === true ? $trashed->of($content) : null;
            $toast = self::trashed($offer);

            // The toast takes one action: a redirect offer keeps its link, and Restore in the trash is the way back.
            if ($offer === null && ! $request->boolean('undo')) {
                $toast['undo'] = self::undo($type, $content, 'restore');
            }

            Inertia::flash('toast', $toast);

            return back(fallback: route('content.index', ['type' => $type]));
        }

        // Undo reverts to a draft, so it is offered only when the item was one: a scheduled or private item would lose its earlier state.
        $wasDraft = ($pointers->forIds([$content])[$content]['status'] ?? null) === 'draft';

        $message = match ($action) {
            'unpublish' => $this->run(app(UnpublishContent::class), $input, __('Reverted to a draft.')),
            'restore' => $this->restoreMessage(app(RestoreContent::class)->execute($input)),
            'publish' => $this->publishMessage(
                $this->run(app(PublishRevision::class), new PublishRevisionInput(
                    $content,
                    (string) ($pointers->forIds([$content])[$content]['workingRevisionId'] ?? ''),
                    $this->actor($request),
                ), __('Published.')),
                $missingAlt->inPublished($content),
            ),
            default => abort(404),
        };

        if ($action === 'publish' && $wasDraft && ! $request->boolean('undo')) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message, 'undo' => self::undo($type, $content, 'unpublish')]);

            return back(fallback: route('content.index', ['type' => $type]));
        }

        return $this->done($type, $message);
    }

    /**
     * A toast's Undo: the same route with the opposite action, marked as an
     * Undo so its own toast offers none (I-3398). The operation behind it
     * decides as it always does.
     *
     * @return array{href: string, data: array{undo: true}}
     */
    private static function undo(string $type, string $content, string $action): array
    {
        return [
            'href' => route('content.action', ['type' => $type, 'content' => $content, 'action' => $action], false),
            'data' => ['undo' => true],
        ];
    }

    public function destroy(Request $request, string $type, string $content, DeleteContentPermanently $delete, ContentPointers $pointers): RedirectResponse
    {
        $this->belongs($type, $content, $pointers);
        $delete->execute(new ContentActionInput($content, $this->actor($request)));

        return $this->done($type, __('Deleted permanently.'));
    }

    private function run(BaseOperation $operation, OperationInput $input, string $message): string
    {
        $operation->execute($input);

        return $message;
    }

    /**
     * @param  list<string>  $missing
     */
    private function publishMessage(string $message, array $missing): string
    {
        return $missing === []
            ? $message
            : $message.' '.trans_choice('This image has no alt text: :titles.|These images have no alt text: :titles.', count($missing), ['titles' => implode(', ', $missing)]);
    }

    private function restoreMessage(RestoredContent $result): string
    {
        if ($result->leftGroup) {
            return __('Restored. Another :language version exists, so this one is no longer linked to its translations.', ['language' => $result->languageLabel]);
        }

        return $result->previousSlug === null
            ? __('Restored.')
            : __('Restored. Its address was taken while it was in the trash, so it is now :slug.', ['slug' => $result->slug]);
    }

    /**
     * The toast after trashing: just after a published item goes, someone
     * who manages redirects is offered one from the address it had, or told
     * one is there already (I-856).
     *
     * @param  array{address: string, redirectId: string|null}|null  $offer
     *
     * @return array{type: string, message: string, link?: array{label: string, href: string}, undo?: array{href: string, data: array{undo: true}}}
     */
    private static function trashed(?array $offer): array
    {
        if ($offer === null) {
            return ['type' => 'success', 'message' => __('Moved to the trash.')];
        }

        if ($offer['redirectId'] !== null) {
            return [
                'type' => 'success',
                'message' => __('Moved to the trash. Its address, :address, has a redirect already.', ['address' => $offer['address']]),
                'link' => ['label' => __('See the redirect'), 'href' => route('tools.redirects.index', ['search' => rtrim($offer['address'], '/')], false)],
            ];
        }

        return [
            'type' => 'success',
            'message' => __('Moved to the trash. Links to :address no longer reach it.', ['address' => $offer['address']]),
            'link' => ['label' => __('Add a redirect for its address'), 'href' => route('tools.redirects.index', ['from' => $offer['address']], false)],
        ];
    }

    /**
     * Answers 404 unless the item exists and is of the type the URL names,
     * so a page can't be changed through the posts address.
     */
    private function belongs(string $segment, string $content, ContentPointers $pointers): void
    {
        $type = app(ContentTypeRegistry::class)->bySegment($segment);

        abort_unless($type !== null && ($pointers->forIds([$content])[$content]['type'] ?? null) === $type->key, 404);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    private function done(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back(fallback: route('content.index', ['type' => $type]));
    }
}
