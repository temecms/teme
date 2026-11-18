<?php

/**
 * One item's translations (I-3360): translate it into a new draft, link
 * another item as a version of it, unlink a version, move it to another
 * language, and mark it up to date. Each hands its Operation the request
 * and answers with a toast, as ContentActionController does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Operations\ChangeContentLanguage;
use App\Domain\Contents\Operations\Input\ChangeContentLanguageInput;
use App\Domain\Contents\Operations\Input\LinkTranslationsInput;
use App\Domain\Contents\Operations\Input\MarkTranslationUpToDateInput;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\Input\UnlinkTranslationInput;
use App\Domain\Contents\Operations\LinkTranslations;
use App\Domain\Contents\Operations\MarkTranslationUpToDate;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\Operations\UnlinkTranslation;
use App\Domain\Contents\Queries\ContentPointers;
use App\Domain\Contents\Queries\TranslationCandidates;
use App\Domain\Contents\Queries\TranslationMessages;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class TranslationsController extends Controller
{
    public function __construct(private readonly ContentPointers $pointers, private readonly TranslationMessages $messages) {}

    public function store(Request $request, string $type, string $content): RedirectResponse
    {
        $this->belongs($type, $content);

        $made = app(TranslateContent::class)->execute(new TranslateContentInput($content, $request->string('language')->toString(), $this->actor($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->messages->translated($made, $content)]);

        return redirect()->route('content.edit', ['content' => $made->content->id]);
    }

    public function link(Request $request, string $type, string $content): RedirectResponse
    {
        $this->belongs($type, $content);
        $with = $request->string('with')->toString();

        app(LinkTranslations::class)->execute(new LinkTranslationsInput([$content, $with], $this->actor($request)));

        return $this->done($type, $this->messages->linked($with));
    }

    /**
     * The items "Link a different post…" offers in one language, by title:
     * only for someone who may change this item, as linking asks.
     *
     * @since 1.0.0
     */
    public function candidates(Request $request, string $type, string $content): JsonResponse
    {
        $this->belongs($type, $content);

        $found = TranslationCandidates::search(
            $content,
            $request->string('language')->toString(),
            $request->string('q')->limit(200, '')->toString(),
            $this->actor($request),
        );

        abort_if($found === null, 403);

        return response()->json($found);
    }

    public function unlink(Request $request, string $type, string $content, string $other): RedirectResponse
    {
        $this->belongs($type, $content);

        // A version of this item's group only, so an address can't name any item at all.
        $title = $this->messages->versionTitle($content, $other);
        abort_if($title === null, 404);

        app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($other, $this->actor($request)));

        return $this->done($type, $this->messages->unlinked($title));
    }

    public function language(Request $request, string $type, string $content): RedirectResponse
    {
        $contentType = $this->belongs($type, $content);

        try {
            $changed = app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput(
                $content,
                $request->string('language')->toString(),
                $request->string('expected_revision_id')->toString(),
                $this->actor($request),
            ));
        } catch (StaleRevisionException) {
            throw $this->stale($contentType);
        }

        return $this->done($type, $changed->message());
    }

    public function upToDate(Request $request, string $type, string $content): RedirectResponse
    {
        $contentType = $this->belongs($type, $content);

        try {
            $marked = app(MarkTranslationUpToDate::class)->execute(new MarkTranslationUpToDateInput(
                $content,
                $request->string('expected_revision_id')->toString(),
                $this->actor($request),
            ));
        } catch (StaleRevisionException) {
            throw $this->stale($contentType);
        }

        return $this->done($type, $this->messages->upToDate($marked));
    }

    private function stale(ContentType $type): ValidationException
    {
        return ValidationException::withMessages([
            'expected_revision_id' => __('Someone changed this :singular while you were editing. Reload and try again.', ['singular' => mb_strtolower($type->singular)]),
        ]);
    }

    /**
     * The type the address names, when the item is one of it; 404 otherwise.
     */
    private function belongs(string $segment, string $content): ContentType
    {
        $type = app(ContentTypeRegistry::class)->bySegment($segment);

        abort_unless($type !== null && ($this->pointers->forIds([$content])[$content]['type'] ?? null) === $type->key, 404);

        return $type;
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
