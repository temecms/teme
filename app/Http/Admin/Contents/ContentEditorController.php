<?php

/**
 * The editor screen: it opens an article on the person's own autosave or the
 * working revision, and turns their autosave into a revision when they save
 * explicitly. It changes nothing itself; the Operations do.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Ai\Asking\Queries\EditorAssistant;
use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Comments\Operations\Input\SetDiscussionInput;
use App\Domain\Comments\Operations\SetDiscussion;
use App\Domain\Comments\Queries\ItemDiscussion;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\CheckpointDraft;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\CheckpointDraftInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\EditorPresence;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Contents\Queries\RevisionAuthor;
use App\Domain\Fields\FieldManifest;
use App\Domain\Media\Queries\MissingAltText;
use App\Domain\Plugins\Editor\EditorPanels;
use App\Domain\Plugins\Seo\PluginSearchFields;
use App\Domain\Regions\Queries\RegionScreens;
use App\Domain\Seo\Queries\EditorSearchAndSocial;
use App\Domain\SiteCode\RegionCode;
use App\Domain\SiteCode\SiteCode;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Queries\AuthorOptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

final class ContentEditorController extends Controller
{
    public function edit(Request $request, string $content, SettingsReader $settings, RegionScreens $regions, ItemDiscussion $discussion, EditorSearchAndSocial $seo, EditorAssistant $assistant, AuthorOptions $authorOptions, ContentTypeRegistry $types, EditorPanels $panels): Response
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $session = EditorSession::for($content, $userId);

        abort_if($session === null, 404);
        abort_unless($session->can['edit'], 403, __("You can't edit this item."));

        // A theme's region opens in the editor's region mode: no title, address
        // or fields, and a card menu of the region's cards only (study B §3e).
        $region = $regions->editorMode($content);

        return Inertia::render('content/edit', [
            'session' => $session,
            'manifest' => CardManifest::forBrowser(app(CardRegistry::class)),
            'fields' => FieldManifest::forEditor($session->type),
            'can' => $session->can,
            // The people an item may be credited to, for those who may change who wrote it (UpdateContentDetails); null for anyone else.
            'authors' => $request->user()?->can($types->ability($session->type, 'edit.any')) === true ? $authorOptions->all() : null,
            'kind' => $region === null ? 'content' : 'region',
            'allowedCards' => $region['cards'] ?? null,
            'themeRegion' => $region === null ? null : ['label' => $region['label'], 'themeName' => $region['themeName'], 'inUse' => $region['inUse'], 'cardLabels' => $region['cardLabels'], 'max' => $region['max'], 'size' => $region['size'], 'takes' => $region['takes']],
            // Whether this person may read and change a Custom HTML card's code, and the host's switch; null outside region mode.
            'canManageSiteCode' => $region === null ? null : RegionCode::manages($request->user()),
            'siteCodeLocked' => $region === null ? null : SiteCode::disallowed(),
            'siteUrl' => url('/'),
            // Every time the editor shows or asks for is on this clock, named.
            'siteTimezone' => $settings->string('general', 'timezone'),
            // The Discussion row: null while comments are off or for a type that takes none.
            'discussion' => $discussion->of($content),
            // The Search and social section's rules beside the fields (I-290).
            'seo' => $seo->for($session),
            // The assistant pane's offer, or null for none (I-321).
            'ai' => $assistant->for($request->user()),
            // The plugins' panels this person sees on this item, as tools in the bar (docs/proposals/plugin-sockets-3.md).
            'panels' => $panels->toolsOn($content, $userId),
            // The plugins' tabs in Search and social for this type (docs/guides/developers/plugins/seo.md).
            'searchTabs' => $region === null ? app(PluginSearchFields::class)->tabsFor($session->type) : [],
            // Asked for on its own, every half presence window, so someone
            // reading without typing still learns who else is in the article.
            'otherEditors' => Inertia::optional(fn (): array => EditorPresence::othersIn($content, $userId)),
            'editor' => [
                'presenceWindow' => (int) config('editor.presence_window', 120),
                'autosaveIdleMs' => (int) config('editor.autosave_idle_ms', 2000),
                'autosaveMaxMs' => (int) config('editor.autosave_max_ms', 10000),
                'checkpointAfterMinutes' => (int) config('editor.checkpoint_after_minutes', 5),
            ],
        ]);
    }

    /**
     * The person's autosave, gathered into a revision. A stale one is 409,
     * which the editor treats exactly like a stale autosave.
     */
    public function checkpoint(Request $request, string $content, CheckpointDraft $checkpoint): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        try {
            $revisionId = $checkpoint->execute(new CheckpointDraftInput($content, $actorId));
        } catch (StaleRevisionException) {
            $current = EditorSession::for($content, $actorId)?->workingRevisionId;
            $author = RevisionAuthor::of($current);

            return response()->json([
                'stale' => true,
                'workingRevisionId' => $current,
                'changedBy' => $author['name'] ?? null,
                'changedAt' => $author['at'] ?? null,
            ], 409);
        }

        // The address too: a draft's first title may have given it one (I-425).
        return response()->json(['revisionId' => $revisionId, 'slug' => EditorSession::for($content, $actorId)?->slug]);
    }

    /**
     * Publishes the revision the rail names (the one the explicit save before
     * it returned), and answers with the new state and any images that went
     * live without alt text.
     */
    public function publish(Request $request, string $content, PublishRevision $publish, MissingAltText $missingAlt): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        $publish->execute(new PublishRevisionInput($content, $request->string('revision_id')->toString(), $actorId));

        $session = EditorSession::for($content, $actorId);

        return response()->json([
            'status' => $session?->status,
            'publishedAt' => $session?->publishedAt,
            'unpublishedChanges' => $session?->unpublishedChanges,
            'missingAlt' => $missingAlt->inPublished($content),
        ]);
    }

    /**
     * Changes the article's address. It writes no revision, so autosaving
     * carries on as it was; a stale expected revision is 409, as a save is.
     */
    public function address(Request $request, string $content, UpdateContentDetails $update): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        try {
            $update->execute(new UpdateContentDetailsInput(
                contentId: $content,
                expectedRevisionId: $request->string('expected_revision_id')->toString(),
                actorId: $actorId,
                slug: $request->string('slug')->toString(),
            ));
        } catch (StaleRevisionException) {
            return response()->json(['stale' => true], 409);
        }

        return response()->json(['slug' => EditorSession::for($content, $actorId)?->slug]);
    }

    /**
     * Sets the item's Discussion from the Publish panel (I-571): live at once
     * and outside any revision, so autosaving carries on as it was, as the
     * address does.
     */
    public function discussion(Request $request, string $content, SetDiscussion $set): JsonResponse
    {
        $wanted = $request->string('discussion')->toString();

        $set->execute(new SetDiscussionInput($content, $wanted, (string) $request->user()?->getAuthIdentifier()));

        return response()->json(['discussion' => $wanted]);
    }

    /**
     * Changes who may read the item, from the Publish panel: at once and
     * outside any revision, as Discussion is. Refusals answer as Laravel
     * answers them: 403, or 422 with the field.
     */
    public function visibility(Request $request, string $content, ChangeVisibility $change): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        abort_if(EditorSession::for($content, $actorId) === null, 404);

        $change->execute(new ChangeVisibilityInput(
            $content,
            $request->string('visibility')->toString(),
            $request->has('password') ? (string) $request->input('password') : null,
            $actorId,
        ));

        $session = EditorSession::for($content, $actorId);

        return response()->json([
            'visibility' => $session->visibility,
            'readerPassword' => $session->readerPassword,
            'passwordUnreadable' => $session->passwordUnreadable,
            'url' => $session->url,
        ]);
    }

    /**
     * Changes who wrote the article, from the Publish panel: at once and
     * outside any revision, as the address is. Only someone the Author
     * select offers, or the current author, may be chosen (422 otherwise);
     * UpdateContentDetails refuses anyone who may not edit everyone's items
     * of the type, and a stale expected revision is 409, as a save is. The
     * answer carries the address too, which a pattern may build from the
     * author.
     */
    public function author(Request $request, string $content, UpdateContentDetails $update, AuthorOptions $authorOptions): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();
        $session = EditorSession::for($content, $actorId);

        abort_if($session === null, 404);
        abort_unless($session->can['edit'], 403, __("You can't edit this item."));

        $wanted = $request->integer('author_id');

        if ($wanted !== ($session->author['id'] ?? null) && ! in_array($wanted, array_column($authorOptions->all(), 'value'), true)) {
            return response()->json(['message' => __('Choose someone who may write on this site.')], 422);
        }

        try {
            $update->execute(new UpdateContentDetailsInput(
                contentId: $content,
                expectedRevisionId: $request->string('expected_revision_id')->toString(),
                actorId: $actorId,
                authorId: $wanted,
            ));
        } catch (StaleRevisionException) {
            return response()->json(['stale' => true], 409);
        }

        $after = EditorSession::for($content, $actorId);

        return response()->json([
            'author' => $after?->author,
            'addressPattern' => $after?->addressPattern,
            'url' => $after?->url,
        ]);
    }

    /**
     * Someone's refused work, kept as a new draft of the same type, so
     * nobody's text is lost to a stale save. The article is not touched.
     */
    public function saveAsDraft(Request $request, string $content, CreateDraft $create): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();
        $type = EditorSession::for($content, $actorId)?->type;

        abort_if($type === null, 404);

        $draft = $create->execute(new CreateDraftInput(
            type: $type,
            title: $request->string('title')->toString(),
            document: (array) $request->input('document', []),
            fields: (array) $request->input('fields', []),
            actorId: $actorId,
            terms: array_values(array_map('strval', (array) $request->input('terms', []))),
        ));

        return response()->json([
            'id' => $draft->id,
            'editUrl' => route('content.edit', ['content' => $draft->id]),
        ], 201);
    }
}
