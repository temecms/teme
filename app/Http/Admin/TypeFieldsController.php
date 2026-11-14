<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Fields\FieldShow;
use App\Domain\Fields\Operations\AddField;
use App\Domain\Fields\Operations\Input\AddFieldInput;
use App\Domain\Fields\Operations\Input\MoveFieldInput;
use App\Domain\Fields\Operations\Input\RemoveFieldInput;
use App\Domain\Fields\Operations\Input\RestoreFieldInput;
use App\Domain\Fields\Operations\Input\UpdateFieldInput;
use App\Domain\Fields\Operations\MoveField;
use App\Domain\Fields\Operations\RemoveField;
use App\Domain\Fields\Operations\RestoreField;
use App\Domain\Fields\Operations\UpdateField;
use App\Domain\Fields\Queries\FieldsScreen;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site › Types › {type} › Fields (I-3320): the page, and each change to one
 * field handed to its Operation. It changes nothing itself.
 */
final class TypeFieldsController extends Controller
{
    public function index(FieldsScreen $screen, string $key): Response
    {
        $page = $screen->page($key);

        if ($page === null) {
            abort(404);
        }

        return Inertia::render('types/fields', $page);
    }

    public function store(Request $request, AddField $add, string $key): RedirectResponse
    {
        $definition = $this->definition($request, $key);
        $add->execute(new AddFieldInput($key, $definition, $this->actor($request)));

        return $this->told(__('Added :label.', ['label' => trim($definition->label)]), $key);
    }

    public function update(Request $request, UpdateField $update, string $key, string $field): RedirectResponse
    {
        $definition = $this->definition($request, $key);
        $result = $update->execute(new UpdateFieldInput($key, $field, $definition, $this->actor($request)));

        return $this->told($result['changed'] ? __('Saved :label.', ['label' => trim($definition->label)]) : __('Nothing changed.'), $key);
    }

    public function move(Request $request, MoveField $move, FieldsScreen $screen, string $key, string $field): RedirectResponse
    {
        $move->execute(new MoveFieldInput($key, $field, $request->integer('position'), $this->actor($request)));

        return $this->told(__('Moved :label.', ['label' => $screen->label($key, $field)]), $key);
    }

    public function destroy(Request $request, RemoveField $remove, FieldsScreen $screen, string $key, string $field): RedirectResponse
    {
        $remove->execute(new RemoveFieldInput($key, $field, $this->actor($request)));

        return $this->told(__('Removed :label.', ['label' => $screen->label($key, $field)]), $key);
    }

    public function restore(Request $request, RestoreField $restore, FieldsScreen $screen, string $key, string $field): RedirectResponse
    {
        $restore->execute(new RestoreFieldInput($key, $field, $this->actor($request)));

        return $this->told(__('Restored :label.', ['label' => $screen->label($key, $field)]), $key);
    }

    /**
     * The sheet's inputs as a field of this type, with no key yet: the
     * Operations judge it, and give or keep its key.
     */
    private function definition(Request $request, string $type): FieldDefinition
    {
        $fieldType = $request->string('fieldType')->trim()->toString();
        $settings = match ($fieldType) {
            'select' => ['choices' => $this->choices($request)],
            'datetime' => ['dateOnly' => $request->boolean('settings.dateOnly')],
            default => [],
        };

        return new FieldDefinition(
            key: '',
            type: $type,
            handle: $request->string('handle')->trim()->toString(),
            label: $request->string('label')->trim()->toString(),
            help: $request->string('help')->trim()->toString(),
            fieldType: $fieldType,
            settings: $settings,
            required: $request->boolean('required'),
            search: $request->boolean('search'),
            filterable: $request->boolean('filterable'),
            show: FieldShow::tryFrom($request->string('show')->toString()),
        );
    }

    /**
     * A Choice's choices as sent: a list of stored values and labels, each text.
     *
     * @return list<array{value: string, label: string}>
     */
    private function choices(Request $request): array
    {
        $sent = $request->input('settings.choices');
        $choices = [];

        foreach (is_array($sent) ? $sent : [] as $choice) {
            $value = is_array($choice) ? ($choice['value'] ?? null) : null;
            $label = is_array($choice) ? ($choice['label'] ?? null) : null;
            $choices[] = ['value' => is_string($value) ? trim($value) : '', 'label' => is_string($label) ? trim($label) : ''];
        }

        return $choices;
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    private function told(string $message, string $type): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('types.fields.index', ['key' => $type]);
    }
}
