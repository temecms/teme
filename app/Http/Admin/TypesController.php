<?php

/**
 * Site › Types (I-3302): the types and a type's page, each change handed to
 * CreateType, UpdateType or DeleteType, and the types file. It changes
 * nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Cards\CardProse;
use App\Domain\Types\Operations\CreateType;
use App\Domain\Types\Operations\DeleteType;
use App\Domain\Types\Operations\Input\CreateTypeInput;
use App\Domain\Types\Operations\Input\DeleteTypeInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Domain\Types\Queries\TypesScreen;
use App\Domain\Types\TypeAccess;
use App\Domain\Types\TypeDefinition;
use App\Domain\Types\TypesFile;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class TypesController extends Controller
{
    public function index(TypesScreen $screen): Response
    {
        return Inertia::render('types/index', $screen->list());
    }

    public function create(TypesScreen $screen): Response|RedirectResponse
    {
        if (TypeAccess::locked()) {
            return to_route('types.index');
        }

        return Inertia::render('types/show', $screen->blank());
    }

    public function store(Request $request, CreateType $create): RedirectResponse
    {
        $definition = $this->definition($request);
        $result = $create->execute(new CreateTypeInput($definition, $this->actor($request)));

        return $this->told(__('Added :plural.', ['plural' => $definition->plural]), 'types.show', ['key' => $result['key']]);
    }

    public function show(TypesScreen $screen, string $key): Response
    {
        $page = $screen->type($key);

        if ($page === null) {
            abort(404);
        }

        return Inertia::render('types/show', $page);
    }

    public function update(Request $request, UpdateType $update, string $key): RedirectResponse
    {
        $definition = $this->definition($request, $key);
        $result = $update->execute(new UpdateTypeInput($definition, $this->actor($request)));

        $message = $result['changed']
            ? __('Saved :plural.', ['plural' => $definition->plural])
            : __('Nothing changed.');

        return $this->told($message, 'types.show', ['key' => $key]);
    }

    public function destroy(Request $request, DeleteType $delete, TypesScreen $screen, string $key): RedirectResponse
    {
        $plural = $screen->plural($key);
        $delete->execute(new DeleteTypeInput($key, $this->actor($request)));

        return $this->told(__('Deleted :plural.', ['plural' => $plural]), 'types.index');
    }

    public function download(): HttpResponse
    {
        return response(TypesFile::json(), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="teme-types.json"',
        ]);
    }

    /**
     * The form's fields as a definition. On update the key is the URL's and
     * the pattern is left out, so Settings › Addresses keeps it.
     */
    private function definition(Request $request, ?string $key = null): TypeDefinition
    {
        $comments = $request->string('comments')->trim()->toString();
        $pattern = $request->string('pattern')->trim()->toString();
        $heading = $request->string('archive_heading')->trim()->toString();
        $intro = $request->input('archive_intro');
        // The form sends the document as one JSON string.
        $intro = is_string($intro) && $intro !== '' ? json_decode($intro, true) : $intro;

        return new TypeDefinition(
            key: $key ?? $request->string('key')->trim()->toString(),
            singular: $request->string('singular')->trim()->toString(),
            plural: $request->string('plural')->trim()->toString(),
            segment: $request->string('segment')->trim()->toString(),
            icon: $request->string('icon')->trim()->toString(),
            public: $request->boolean('public'),
            archive: $request->boolean('archive'),
            searchable: $request->boolean('searchable'),
            taxonomies: $this->strings($request, 'taxonomies'),
            supports: $this->strings($request, 'supports'),
            comments: in_array($comments, ['open', 'closed'], true) ? $comments : null,
            pattern: $key === null && $pattern !== '' ? $pattern : null,
            single: $request->boolean('single'),
            permissions: $request->string('permissions')->trim()->toString() === TypeDefinition::OWN ? TypeDefinition::OWN : TypeDefinition::SHARED,
            archiveHeading: $heading === '' ? null : $heading,
            archiveIntro: is_array($intro) && CardProse::text($intro) !== '' ? $intro : null,
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
