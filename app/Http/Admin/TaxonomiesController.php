<?php

/**
 * Site › Taxonomies (I-3319): the taxonomies and a taxonomy's page, each
 * change handed to CreateTaxonomy, UpdateTaxonomy or DeleteTaxonomy. It
 * changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Types\Operations\CreateTaxonomy;
use App\Domain\Types\Operations\DeleteTaxonomy;
use App\Domain\Types\Operations\Input\CreateTaxonomyInput;
use App\Domain\Types\Operations\Input\DeleteTaxonomyInput;
use App\Domain\Types\Operations\Input\UpdateTaxonomyInput;
use App\Domain\Types\Operations\UpdateTaxonomy;
use App\Domain\Types\Queries\TaxonomiesScreen;
use App\Domain\Types\TaxonomyDefinition;
use App\Domain\Types\TypeAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TaxonomiesController extends Controller
{
    public function index(TaxonomiesScreen $screen): Response
    {
        return Inertia::render('taxonomies/index', $screen->list());
    }

    public function create(TaxonomiesScreen $screen): Response|RedirectResponse
    {
        if (TypeAccess::locked()) {
            return to_route('taxonomies.index');
        }

        return Inertia::render('taxonomies/show', $screen->blank());
    }

    public function store(Request $request, CreateTaxonomy $create): RedirectResponse
    {
        $definition = $this->definition($request, $request->string('key')->trim()->toString());
        $result = $create->execute(new CreateTaxonomyInput($definition, $this->strings($request, 'types'), $this->actor($request)));

        return $this->told(__('Added :plural.', ['plural' => $definition->plural]), 'taxonomies.show', ['key' => $result['key']]);
    }

    public function show(TaxonomiesScreen $screen, string $key): Response
    {
        $page = $screen->taxonomy($key);

        if ($page === null) {
            abort(404);
        }

        return Inertia::render('taxonomies/show', $page);
    }

    public function update(Request $request, UpdateTaxonomy $update, TaxonomiesScreen $screen, string $key): RedirectResponse
    {
        if ($screen->plural($key) === null) {
            abort(404);
        }

        $definition = $this->definition($request, $key);
        $result = $update->execute(new UpdateTaxonomyInput($definition, $this->strings($request, 'types'), $this->actor($request)));

        $message = $result['changed']
            ? __('Saved :plural.', ['plural' => $definition->plural])
            : __('Nothing changed.');

        return $this->told($message, 'taxonomies.show', ['key' => $key]);
    }

    public function destroy(Request $request, DeleteTaxonomy $delete, TaxonomiesScreen $screen, string $key): RedirectResponse
    {
        $plural = $screen->plural($key);

        if ($plural === null) {
            abort(404);
        }

        $delete->execute(new DeleteTaxonomyInput($key, $this->actor($request)));

        return $this->told(__('Deleted :plural.', ['plural' => $plural]), 'taxonomies.index');
    }

    /**
     * The form's fields as a definition. The key is the URL's on update, and
     * the base is the form's: only a taxonomy made, or made public, takes it.
     */
    private function definition(Request $request, string $key): TaxonomyDefinition
    {
        $base = $request->string('base')->trim()->toString();

        return new TaxonomyDefinition(
            key: $key,
            singular: $request->string('singular')->trim()->toString(),
            plural: $request->string('plural')->trim()->toString(),
            segment: $request->string('segment')->trim()->toString(),
            icon: $request->string('icon')->trim()->toString(),
            hierarchical: $request->boolean('hierarchical'),
            public: $request->boolean('public'),
            base: $request->boolean('public') && $base !== '' ? $base : null,
        );
    }

    /**
     * @return list<string>
     */
    private function strings(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = trim($item);
            }
        }

        return $strings;
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function told(string $message, string $route, array $parameters = []): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route($route, $parameters);
    }
}
