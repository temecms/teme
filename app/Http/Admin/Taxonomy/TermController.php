<?php

declare(strict_types=1);

namespace App\Http\Admin\Taxonomy;

use App\Domain\Languages\Queries\TermVersions;
use App\Domain\Support\Queries\ListPages;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Domain\Taxonomy\Queries\TermList;
use App\Domain\Taxonomy\Queries\TermPicker;
use App\Domain\Taxonomy\Queries\TermScreen;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen of every taxonomy, such as Categories and Tags. It lists terms
 * and hands each change to its Operation; it changes nothing itself. The
 * taxonomy comes from the URL's segment. A nesting taxonomy is shown as whole
 * branches.
 */
final class TermController extends Controller
{
    public function index(Request $request, string $taxonomy, TermList $terms, TermScreen $screen, TermPicker $picker): Response
    {
        $declared = $this->declared($taxonomy);
        $search = $request->string('search')->toString();
        $after = $request->string('after')->toString();
        $before = $request->string('before')->toString();

        // A nesting taxonomy's whole tree: its pages of branches are cut from
        // it, and its Parent choices are drawn from it.
        $tree = $declared->hierarchical ? $picker->tree($declared->key) : [];
        $number = ListPages::requested($request->query('page'));
        $page = $declared->hierarchical && $search === ''
            ? $terms->branches($declared->key, $tree, $after, $before, $number)
            : $terms->page($declared->key, $after, $search, $before, $number);

        return Inertia::render('terms/index', [
            'taxonomy' => $taxonomy,
            'declared' => [
                'key' => $declared->key,
                'singular' => $declared->singular,
                'plural' => $declared->plural,
                'hierarchical' => $declared->hierarchical,
            ],
            'setup' => $request->user()?->can(TypeAccess::MANAGE) === true ? route('taxonomies.show', ['key' => $declared->key], false) : null,
            'tabs' => $screen->tabs($declared->key, $request->user()),
            'types' => $screen->countedTypes($declared->key),
            'rows' => $page['rows'],
            'paging' => $page['paging'],
            'search' => $search,
            'tree' => $tree,
            // The language marks (docs/proposals/multilingual-screens.md): none on a site with one language.
            'languages' => TermVersions::languages($declared->key),
            'versions' => TermVersions::of($declared->key, array_map(fn (array $row): string => (string) $row['id'], $page['rows'])),
        ]);
    }

    public function store(Request $request, string $taxonomy, CreateTerm $createTerm): RedirectResponse|JsonResponse
    {
        $term = $createTerm->execute($this->input($request, $taxonomy));

        // The editor's tree picker adds a term and chooses it at once.
        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $term->id,
                'name' => (string) $term->name,
                'parentId' => $term->parent_id === null ? null : (string) $term->parent_id,
            ], 201);
        }

        // It may land on another page of the list, so the toast names it.
        return $this->done($taxonomy, __('Added “:name”.', ['name' => (string) $term->name]));
    }

    public function update(Request $request, string $taxonomy, string $term, UpdateTerm $updateTerm): RedirectResponse
    {
        $updateTerm->execute($this->input($request, $taxonomy, $term));

        return $this->done($taxonomy, __('Saved.'));
    }

    public function destroy(Request $request, string $taxonomy, string $term, DeleteTerm $deleteTerm): RedirectResponse
    {
        $this->taxonomy($taxonomy);
        $deleteTerm->execute(new DeleteTermInput($term, (string) $request->user()?->getAuthIdentifier()));

        return $this->done($taxonomy, __('Deleted.'));
    }

    private function input(Request $request, string $taxonomy, ?string $termId = null): SaveTermInput
    {
        $slug = $request->string('slug')->trim()->toString();
        $parent = $request->string('parent_id')->trim()->toString();
        $description = $request->string('description')->trim()->toString();

        return new SaveTermInput(
            taxonomy: $this->taxonomy($taxonomy),
            name: $request->string('name')->toString(),
            slug: $slug === '' ? null : $slug,
            parentId: $parent === '' ? null : $parent,
            description: $description === '' ? null : $description,
            actorId: (string) $request->user()?->getAuthIdentifier(),
            termId: $termId,
            // An edit that doesn't send a field leaves it as it is; one sent empty clears it.
            keepParent: $termId !== null && ! $request->has('parent_id'),
            keepDescription: $termId !== null && ! $request->has('description'),
        );
    }

    private function taxonomy(string $segment): string
    {
        return $this->declared($segment)->key;
    }

    private function declared(string $segment): Taxonomy
    {
        return app(TaxonomyRegistry::class)->bySegment($segment) ?? abort(404);
    }

    private function done(string $taxonomy, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('terms.index', ['taxonomy' => $taxonomy]);
    }
}
