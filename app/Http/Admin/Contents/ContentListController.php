<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListFilters;
use App\Domain\Contents\Queries\HeldSchedules;
use App\Domain\Contents\Queries\SingleItem;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Settings\Modules;
use App\Domain\Taxonomy\Queries\TermPicker;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Users\Queries\AuthorOptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The list screen of every listed content type, such as Posts and Pages: one
 * list per type, read through ContentList, and "new draft from a title"
 * through CreateDraft. The type comes from the URL's segment. A type with
 * one item only opens that item in the editor (I-415).
 */
final class ContentListController extends Controller
{
    public function index(Request $request, string $type, ContentList $list, AuthorOptions $authors, TaxonomyRegistry $taxonomies, SettingsReader $settings, TermPicker $termPicker): Response|RedirectResponse
    {
        $contentType = $this->type($type);
        $singleItem = $contentType->single ? SingleItem::of($contentType->key) : null;
        $viewer = $request->user();

        $mayEditTheOne = $viewer !== null && $singleItem !== null && ($viewer->can($contentType->ability('edit.any'))
            || ($viewer->can($contentType->ability('edit.own')) && (int) $viewer->getAuthIdentifier() === $singleItem['authorId']));

        if ($contentType->single && $request->query() === [] && $singleItem !== null && ! $singleItem['trashed'] && $mayEditTheOne) {
            return to_route('content.edit', ['content' => $singleItem['id']]);
        }

        $typeTaxonomies = $taxonomies->forType($contentType->key);
        // The Comments column: a type that takes comments, while they're on (1.8c's D21).
        $takesComments = Modules::on(Modules::COMMENTS) && $contentType->comments !== null;
        $filters = ContentListFilters::read($request->query(), $contentType, $settings->string('general', 'timezone'));
        $listRequest = $filters->request(
            $contentType,
            (int) $viewer?->getAuthIdentifier(),
            $viewer === null ? 50 : (int) PreferencesFor::user($viewer)['lists.per_page'],
            after: $this->optional($request, 'after'),
            before: $this->optional($request, 'before'),
            withComments: $takesComments,
        );
        $page = $list->page($listRequest);

        return Inertia::render('content/index', [
            'type' => $type,
            ...$page,
            'filters' => $filters->values,
            // The Date control's months, their counts hidden while anything else narrows the list (I-3131).
            'dates' => $this->dates($list->months($contentType->key, $filters->tab(), (int) $viewer?->getAuthIdentifier()), ! $filters->narrowsBesideDate()),
            'authors' => $authors->all(),
            'can' => [
                'changeAuthor' => (bool) $viewer?->can($contentType->ability('edit.any')),
                'publish' => (bool) $viewer?->can($contentType->ability('publish')),
            ],
            'bulk' => $request->session()->get('bulk'),
            'takesComments' => $takesComments,
            // Not 'taxonomies': that is the shared prop the sidebar draws, and
            // a page prop of the same name would replace it.
            'typeTaxonomies' => array_map(fn (Taxonomy $taxonomy): array => [
                'key' => $taxonomy->key,
                'singular' => $taxonomy->singular,
                'plural' => $taxonomy->plural,
                'segment' => $taxonomy->segment,
                'hierarchical' => $taxonomy->hierarchical,
            ], $typeTaxonomies),
            'labels' => ['singular' => $contentType->singular, 'plural' => $contentType->plural],
            'canAdd' => ! $contentType->single || $singleItem === null,
            // Later (I-221): this becomes a StatusCheck banner when StatusChecks
            // exists (1.6b), so the warning shows on every screen, not only here.
            'scheduleWarning' => $filters->tab() === 'scheduled' ? $this->scheduleWarning($contentType->key) : [],
            // Scheduled times are shown on the site's clock, and named.
            'siteTimezone' => $settings->string('general', 'timezone'),
            // Every nesting taxonomy's tree, with every list: the filter's
            // select draws it on the first paint, and Bulk Edit reads it too.
            'termTrees' => collect($typeTaxonomies)
                ->filter(fn (Taxonomy $taxonomy): bool => $taxonomy->hierarchical)
                ->mapWithKeys(fn (Taxonomy $taxonomy): array => [$taxonomy->key => $termPicker->tree($taxonomy->key)])
                ->all(),
            // A flat taxonomy's filter has no select (I-1452), so the list
            // names its term, as a count on the term's screen opens it.
            'chosenTerms' => $this->chosenTerms($typeTaxonomies, $listRequest->terms, $termPicker),
        ]);
    }

    public function store(Request $request, string $type, CreateDraft $createDraft): RedirectResponse
    {
        $draft = $createDraft->execute(new CreateDraftInput(
            type: $this->type($type)->key,
            title: $request->string('title')->toString(),
            document: ['type' => 'doc', 'content' => []],
            fields: [],
            actorId: (string) $request->user()?->getAuthIdentifier(),
        ));

        // The editor opening is the confirmation (I-425).
        return to_route('content.edit', ['content' => $draft->id]);
    }

    /**
     * The Date control's months, each count kept only while the list is
     * narrowed by nothing but the Date (I-140).
     *
     * @param  array{by: 'updated'|'published', months: list<array{month: string, count: int}>}  $months
     *
     * @return array{by: 'updated'|'published', months: list<array{month: string, count: int|null}>}
     */
    private function dates(array $months, bool $counted): array
    {
        return [
            'by' => $months['by'],
            'months' => array_map(
                fn (array $row): array => ['month' => $row['month'], 'count' => $counted ? $row['count'] : null],
                $months['months'],
            ),
        ];
    }

    /**
     * One line per reason the type's held schedules cannot run, or none.
     *
     * @return list<string>
     */
    private function scheduleWarning(string $type): array
    {
        $held = HeldSchedules::forType($type);
        $lines = [];

        foreach ([
            'removed' => 'the account of the person who scheduled them was removed',
            'cannotPublish' => 'the person who scheduled them may no longer publish',
        ] as $reason => $why) {
            $count = $held[$reason];

            if ($count > 0) {
                $items = $count === 1 ? '1 scheduled item' : "{$count} scheduled items";
                $lines[] = __(":items can't publish: :why. :again", ['items' => $items, 'why' => $why, 'again' => $count === 1 ? __('Reschedule it.') : __('Reschedule them.')]);
            }
        }

        return $lines;
    }

    private function type(string $segment): ContentType
    {
        return app(ContentTypeRegistry::class)->bySegment($segment) ?? abort(404);
    }

    private function optional(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value === '' ? null : $value;
    }

    /**
     * The name of the term each flat taxonomy's filter names, by the
     * taxonomy's key, leaving out a term that is gone or isn't of that
     * taxonomy. A nesting taxonomy's select shows its own.
     *
     * @param  list<Taxonomy>  $typeTaxonomies
     * @param  array<string, string>  $terms  the term filters in force, by taxonomy key
     *
     * @return array<string, string>
     */
    private function chosenTerms(array $typeTaxonomies, array $terms, TermPicker $termPicker): array
    {
        $chosen = [];

        foreach ($typeTaxonomies as $taxonomy) {
            if ($taxonomy->hierarchical || ! isset($terms[$taxonomy->key])) {
                continue;
            }

            $name = $termPicker->name($taxonomy->key, $terms[$taxonomy->key]);

            if ($name !== null) {
                $chosen[$taxonomy->key] = $name;
            }
        }

        return $chosen;
    }
}
